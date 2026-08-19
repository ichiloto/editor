<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Events\Interpreter\EventExecutionLane;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use Throwable;

/**
 * One cinematic played by the Engine inside the editor.
 *
 * The session owns nothing the game does not: the Engine's interpreter runs
 * the asset's command tree, its controller owns skip and cleanup, its stage
 * and presentation managers hold the cast and overlays. What the editor adds
 * is a clock it can pause, a camera that draws into a buffer, dialogue that
 * waits for the author, and read-only views of the lanes, checkpoints and
 * final state — so what the pane shows is what the game would do, command
 * for command, with the author's project untouched.
 */
final class CinematicPreviewSession
{
    /** The granularity of one Step. */
    public const float TICK_SECONDS = 0.1;

    public const string STATUS_RUNNING = 'running';
    public const string STATUS_WAITING = 'waiting';
    public const string STATUS_PAUSED = 'paused';
    public const string STATUS_BATTLE = 'battle';
    public const string STATUS_FINALIZING = 'finalizing';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_STOPPED = 'stopped';
    public const string STATUS_REFUSED = 'refused';

    private PreviewGameScene $scene;
    private EventInterpreter $interpreter;
    private PreviewPresentation $presentation;
    private ?EventExecutionSession $session = null;
    private bool $playing = false;
    private bool $stopped = false;
    private float $elapsed = 0.0;
    private ?string $launchFailure = null;
    private ?string $battleOutcome = null;
    /** @var array<int, array{time: float, text: string}> */
    private array $log = [];
    /** @var array<string, ConfigInterface> */
    private array $previousConfigs = [];
    private bool $configured = false;
    /** @var array<int, string> Row keys executing when the session last ticked. */
    private array $lastActiveKeys = [];
    /** @var array<int, array{key: string, command: array<string, mixed>|null}> The same, with the commands. */
    private array $lastActiveLanes = [];
    private ?string $failedKey = null;
    private bool $outcomeAnnounced = false;

    /**
     * @param array{mapId?: string|null, x?: int, y?: int, width?: int, height?: int, autoAdvance?: bool, battleOutcome?: string} $options
     */
    private function __construct(
        private readonly string $projectRoot,
        private readonly CinematicDefinition $definition,
        private readonly array $options,
    ) {
    }

    /**
     * Starts a cinematic in an isolated scene.
     *
     * The launch itself may be refused by the Engine (for instance a cast
     * entry the stage cannot place); that is reported through `status()`
     * and `failure()` rather than thrown, so the pane can show it.
     *
     * @param array{mapId?: string|null, x?: int, y?: int, width?: int, height?: int, autoAdvance?: bool, battleOutcome?: string} $options
     */
    public static function start(string $projectRoot, CinematicDefinition $definition, array $options = []): self
    {
        $preview = new self($projectRoot, $definition, $options);
        $preview->boot();

        return $preview;
    }

    /**
     * Restarts the same cinematic from the beginning with the same options.
     */
    public function restart(): self
    {
        $this->dispose();

        return self::start($this->projectRoot, $this->definition, $this->options);
    }

    private function boot(): void
    {
        $width = max(20, intval($this->options['width'] ?? 60));
        $height = max(8, intval($this->options['height'] ?? 18));
        $mapId = $this->options['mapId'] ?? $this->definition->startMap;
        $mapId = is_string($mapId) && trim($mapId) !== '' ? trim($mapId) : '';

        $this->run(function () use ($width, $height, $mapId): void {
            $this->scene = new PreviewGameScene(new PreviewSceneManager(), $width, $height, $mapId);
            $camera = $this->scene->previewCamera();
            $this->presentation = new PreviewPresentation($camera);
            $this->presentation->autoAdvance = (bool) ($this->options['autoAdvance'] ?? false);
            $this->interpreter = new EventInterpreter($this->scene, $this->presentation);
            $this->scene->installInterpreter($this->interpreter);
            $player = new PreviewPlayer(new Vector2(intval($this->options['x'] ?? 0), intval($this->options['y'] ?? 0)));
            $this->scene->installPlayer($player);

            if ($mapId !== '') {
                try {
                    $this->scene->previewMap->loadForPreview($mapId);
                    $this->note(sprintf('Map %s loaded.', $mapId));
                } catch (Throwable $throwable) {
                    $this->scene->previewMap->unload();
                    $this->note(sprintf('Map %s could not be loaded: %s', $mapId, $throwable->getMessage()));
                }
            } else {
                $this->note('No start map: the cinematic plays over undefined terrain.');
            }

            $camera->attach($player);

            try {
                $this->session = $this->scene->cinematicController?->start($this->definition);
            } catch (Throwable $throwable) {
                $this->launchFailure = $throwable->getMessage();
                $this->note('Launch refused: ' . $throwable->getMessage());

                return;
            }

            if ($this->session === null) {
                $this->launchFailure = 'The Engine refused to start the cinematic.';
                $this->note($this->launchFailure);

                return;
            }

            $this->note(sprintf('Cinematic %s started.', $this->definition->id));
            $this->rememberActiveKeys();
            $this->recordTerminalOutcome();
        });
    }

    // ------------------------------------------------------------------
    // Controls
    // ------------------------------------------------------------------

    public function play(): void
    {
        if (! $this->isFinished()) {
            $this->playing = true;
        }
    }

    public function pause(): void
    {
        $this->playing = false;
    }

    public function isPlaying(): bool
    {
        return $this->playing && ! $this->isFinished();
    }

    /**
     * Advances the clock by one step regardless of play state.
     */
    public function step(float $seconds = self::TICK_SECONDS): void
    {
        $this->advance($seconds);
    }

    /**
     * Advances the clock while playing; called from the editor's idle loop.
     */
    public function tick(float $seconds): void
    {
        if ($this->isPlaying()) {
            $this->advance($seconds);
        }
    }

    /**
     * Requests the authored skip, exactly as the game would.
     *
     * @return bool Whether the Engine accepted the skip.
     */
    public function skip(): bool
    {
        if ($this->isFinished() || $this->session === null) {
            return false;
        }

        $reason = $this->skipRefusalReason();

        if ($reason !== null) {
            $this->note('Skip refused: ' . $reason);

            return false;
        }

        $accepted = false;
        $this->run(function () use (&$accepted): void {
            $accepted = $this->scene->skipCinematic();
            $this->note($accepted ? 'Skip accepted: finalizer started.' : 'Skip refused by the Engine.');
            $this->rememberActiveKeys();
            $this->recordTerminalOutcome();
        });

        return $accepted;
    }

    /**
     * Abandons the run. The Engine performs its controlled-failure cleanup.
     */
    public function stop(): void
    {
        if ($this->isFinished() || $this->session === null) {
            return;
        }

        $this->run(function (): void {
            $this->interpreter->failActiveSession('Stopped from the editor preview.');
            $this->stopped = true;
            $this->playing = false;
            $this->note('Stopped.');
        });
    }

    /**
     * Confirms waiting dialogue or the highlighted choice.
     */
    public function confirm(): bool
    {
        return $this->presentation->confirm();
    }

    public function moveChoice(int $delta): bool
    {
        return $this->presentation->moveHighlight($delta);
    }

    /**
     * Plays to the end without waiting on dialogue, choosing the first option
     * of every choice and resolving battles as configured.
     *
     * @return bool Whether the session reached a terminal state in time.
     */
    public function runToCompletion(float $maxSeconds = 600.0): bool
    {
        $this->presentation->autoAdvance = true;
        $deadline = $this->elapsed + $maxSeconds;

        while (! $this->isFinished() && $this->elapsed < $deadline) {
            $this->advance(self::TICK_SECONDS);
        }

        if (! $this->isFinished()) {
            $this->note(sprintf('Gave up after %.1f seconds of preview time.', $maxSeconds));
        }

        return $this->isFinished();
    }

    /**
     * Releases the Engine configuration the session installed.
     */
    public function dispose(): void
    {
        if (! $this->configured) {
            return;
        }

        foreach ([ProjectConfig::class, PlaySettings::class] as $class) {
            if (isset($this->previousConfigs[$class])) {
                ConfigStore::put($class, $this->previousConfigs[$class]);
            } else {
                ConfigStore::remove($class);
            }
        }

        $this->configured = false;
    }

    // ------------------------------------------------------------------
    // Inspection
    // ------------------------------------------------------------------

    public function definition(): CinematicDefinition
    {
        return $this->definition;
    }

    public function elapsed(): float
    {
        return $this->elapsed;
    }

    public function status(): string
    {
        if ($this->launchFailure !== null) {
            return self::STATUS_REFUSED;
        }

        if ($this->stopped) {
            return self::STATUS_STOPPED;
        }

        $session = $this->session;

        if ($session === null) {
            return self::STATUS_REFUSED;
        }

        if ($session->status === EventExecutionStatus::COMPLETED) {
            return self::STATUS_COMPLETED;
        }

        if ($session->status === EventExecutionStatus::FAILED) {
            return self::STATUS_FAILED;
        }

        if ($session->status === EventExecutionStatus::SUSPENDED) {
            return self::STATUS_BATTLE;
        }

        if ($this->presentation->isWaiting()) {
            return self::STATUS_WAITING;
        }

        if ($session->isFinalizing) {
            return self::STATUS_FINALIZING;
        }

        return $this->playing ? self::STATUS_RUNNING : self::STATUS_PAUSED;
    }

    public function isFinished(): bool
    {
        return in_array($this->status(), [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_STOPPED, self::STATUS_REFUSED], true);
    }

    /**
     * The failure, when the run ended in one.
     *
     * @return array{message: string, key: string|null}|null
     */
    public function failure(): ?array
    {
        if ($this->launchFailure !== null) {
            return ['message' => $this->launchFailure, 'key' => null];
        }

        if ($this->session?->status === EventExecutionStatus::FAILED) {
            return [
                'message' => $this->session->failureMessage ?? 'The cinematic failed.',
                'key' => $this->failedKey,
            ];
        }

        return null;
    }

    /**
     * What a skip would be refused for right now, or null when it would be
     * accepted. The wording follows the Engine's own refusals.
     */
    public function skipRefusalReason(): ?string
    {
        $session = $this->session;

        if ($session === null || $this->isFinished()) {
            return 'no cinematic is active';
        }

        if ($this->definition->skipPolicy !== 'authored') {
            return sprintf('skip policy is "%s" (only "authored" with a finalizer can be skipped)', $this->definition->skipPolicy);
        }

        if ($this->definition->finalizer === []) {
            return 'the finalizer is empty';
        }

        if ($session->isFinalizing) {
            return 'the finalizer is already running';
        }

        if ($session->status === EventExecutionStatus::SUSPENDED && strval($session->pendingCommand['type'] ?? '') === 'start_battle') {
            return 'a battle boundary is active';
        }

        return null;
    }

    /**
     * A line for the controls strip: what the run is waiting on.
     */
    public function waitDescription(): ?string
    {
        if ($this->status() === self::STATUS_BATTLE) {
            return sprintf('Battle in progress — resolved as %s on the next step (preview assumption)', $this->battleOutcomeLabel());
        }

        return $this->presentation->describeWait();
    }

    /**
     * The checkpoints recorded so far.
     *
     * @return string[]
     */
    public function checkpoints(): array
    {
        return $this->session?->checkpoints ?? [];
    }

    /**
     * The lanes of the session, root first, with what each is doing.
     *
     * @return array<int, array{path: string, depth: int, status: string, command: string|null, commandData: array<string, mixed>|null, key: string|null}>
     */
    public function lanes(): array
    {
        $session = $this->session;

        if ($session === null) {
            return [];
        }

        $lanes = [];
        $this->collectLanes($session->rootLane(), 0, $lanes);

        return $lanes;
    }

    /**
     * The outline keys of the commands executing right now.
     *
     * @return string[]
     */
    public function activeKeys(): array
    {
        return $this->lastActiveKeys;
    }

    /**
     * The composed picture of the scene at this moment.
     *
     * @return string[]
     */
    public function frame(): array
    {
        $rows = [];
        $this->run(function () use (&$rows): void {
            $camera = $this->scene->previewCamera();
            $camera->clearFrame();
            $this->scene->previewMap->render();
            $this->scene->npcManager?->render();
            $this->scene->player?->render();
            $this->scene->cinematicStage?->render();
            $this->scene->cinematicPresentation?->render();
            $this->presentation->render();
            $rows = $camera->frame();
        });

        return $rows;
    }

    /**
     * Resizes the captured screen.
     */
    public function resize(int $width, int $height): void
    {
        $this->scene->previewCamera()->resize(max(20, $width), max(8, $height));
    }

    /**
     * The observable state right now.
     */
    public function snapshot(): PreviewSnapshot
    {
        $values = [];
        $state = $this->scene->gameState->toArray();

        foreach ((array) ($state['switches'] ?? []) as $name => $value) {
            $values['switch ' . $name] = $value;
        }

        foreach ((array) ($state['variables'] ?? []) as $name => $value) {
            $values['variable ' . $name] = $value;
        }

        $events = array_values(array_unique(array_map(strval(...), $this->scene->gameState->storyEvents)));
        sort($events);
        $values['story events'] = $events;
        $values['map'] = $this->scene->currentMapId;
        $player = $this->scene->player;
        $values['player position'] = $player === null ? null : [intval($player->position->x), intval($player->position->y)];
        $values['player facing'] = $player instanceof PreviewPlayer ? $player->facing : null;
        $camera = $this->scene->camera;
        $values['camera'] = ['x' => intval($camera->position->x), 'y' => intval($camera->position->y), 'followsPlayer' => $camera->followsPlayer];
        $staged = [];

        foreach ($this->scene->cinematicStage?->all() ?? [] as $actor) {
            $staged[$actor->id] = ['x' => intval($actor->position->x), 'y' => intval($actor->position->y), 'visible' => $actor->isVisible];
        }

        ksort($staged);
        $values['staged actors'] = $staged;
        $values['transfers'] = count($this->scene->transfers);
        $values['battles'] = count($this->scene->previewSceneManager->battles);
        $values['checkpoints'] = $this->checkpoints();
        $values['status'] = $this->status();
        ksort($values);

        return new PreviewSnapshot($values);
    }

    /**
     * The running commentary: launches, transfers, battles, skips, failures.
     *
     * @return array<int, array{time: float, text: string}>
     */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * Dialogue shown so far.
     *
     * @return array<int, array{kind: string, text: string, speaker: string}>
     */
    public function dialogueLog(): array
    {
        return $this->presentation->log;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function advance(float $seconds): void
    {
        if ($this->isFinished() || $this->session === null) {
            $this->playing = false;

            return;
        }

        $this->run(function () use ($seconds): void {
            $session = $this->session;

            if ($session !== null
                && $session->status === EventExecutionStatus::SUSPENDED
                && strval($session->pendingCommand['type'] ?? '') === 'start_battle'
            ) {
                $this->resolveBattle();
            }

            $this->presentation->update();
            $this->interpreter->update($seconds);
            $this->elapsed += $seconds;
            $this->rememberActiveKeys();
            $this->recordTerminalOutcome();
        });
    }

    private function resolveBattle(): void
    {
        $battles = $this->scene->previewSceneManager->battles;
        $last = $battles === [] ? null : $battles[array_key_last($battles)];
        $this->note(sprintf(
            'Battle%s resolved as %s (preview assumption).',
            $last === null ? '' : sprintf(' against %s', $last['troop']),
            $this->battleOutcomeLabel(),
        ));
        $this->scene->resumeEventAfterBattle(new BattleResult($this->battleOutcomeLabel(), []));
    }

    private function battleOutcomeLabel(): string
    {
        $outcome = $this->battleOutcome ?? strval($this->options['battleOutcome'] ?? 'Victory');

        return in_array($outcome, ['Victory', 'Defeat', 'Retreat'], true) ? $outcome : 'Victory';
    }

    private function recordTerminalOutcome(): void
    {
        $session = $this->session;

        if ($session === null) {
            return;
        }

        if ($this->outcomeAnnounced) {
            return;
        }

        if ($session->status === EventExecutionStatus::COMPLETED) {
            $this->outcomeAnnounced = true;
            $this->playing = false;
            $this->note(sprintf('Completed%s.', $session->isFinalizing ? ' through the finalizer' : ''));
        } elseif ($session->status === EventExecutionStatus::FAILED) {
            $this->outcomeAnnounced = true;
            $this->playing = false;
            $this->failedKey = $this->resolveFailedKey();
            $this->note('Failed: ' . ($session->failureMessage ?? 'unknown reason'));
        }
    }

    /**
     * The outline key the failure points at, read from the Engine's own
     * diagnostic (`... at command path "root/dawn[3]/sequence[2]" ...`),
     * falling back to the deepest command that was executing before the
     * failing tick.
     */
    private function resolveFailedKey(): ?string
    {
        $message = $this->session?->failureMessage ?? '';

        if (preg_match('/ at command path "([^"]+)"/', $message, $match)) {
            $key = $this->keyForEnginePath($match[1]);

            if ($key !== null) {
                return $key;
            }
        }

        // A failure raised while a pending operation advanced (a route step
        // into a missing actor, say) carries no path. The command whose own
        // identifiers the message quotes is the one that failed.
        if ($message !== '') {
            foreach (array_reverse($this->lastActiveLanes) as $lane) {
                foreach ($lane['command'] ?? [] as $value) {
                    if (is_string($value) && $value !== '' && str_contains($message, '"' . $value . '"')) {
                        return $lane['key'];
                    }
                }
            }
        }

        return $this->lastActiveKeys === [] ? null : $this->lastActiveKeys[array_key_last($this->lastActiveKeys)];
    }

    private function rememberActiveKeys(): void
    {
        $keys = [];
        $lanes = [];

        foreach ($this->lanes() as $lane) {
            if ($lane['key'] !== null) {
                $keys[] = $lane['key'];
                $lanes[] = ['key' => $lane['key'], 'command' => $lane['commandData']];
            }
        }

        if ($keys !== []) {
            $this->lastActiveKeys = $keys;
            $this->lastActiveLanes = $lanes;
        }
    }

    /**
     * @param array<int, array{path: string, depth: int, status: string, command: string|null, commandData: array<string, mixed>|null, key: string|null}> $lanes
     */
    private function collectLanes(EventExecutionLane $lane, int $depth, array &$lanes): void
    {
        $pending = $lane->pendingCommand;
        $frames = $lane->frames();
        $current = null;

        if ($frames !== []) {
            $frame = $frames[array_key_last($frames)];
            $candidate = $frame->commands[$frame->commandIndex] ?? null;
            $current = is_array($candidate) ? $candidate : null;
        }

        $command = $pending ?? $current;
        $lanes[] = [
            'path' => (string) $lane->path,
            'depth' => $depth,
            'status' => strtolower($lane->status->name),
            'command' => $command === null ? null : strval($command['type'] ?? '?'),
            'commandData' => $command,
            'key' => $frames === [] ? null : $this->keyForEnginePath($lane->commandPath()),
        ];

        foreach ($lane->parallelGroup?->lanes() ?? [] as $child) {
            $this->collectLanes($child, $depth + 1, $lanes);
        }
    }

    /**
     * Converts an Engine command path into the outline row key of the
     * command it names.
     *
     * The Engine describes a position as the lane path followed by one
     * `label[index]` segment per frame, one-based: `root/dawn[3]/sequence[2]`
     * is the second command of the sequence opened by the third root
     * command. Two Engine habits shape the walk: a parent frame has already
     * advanced past the block that opened the frame above it (so its index
     * points one past the block, unless the block is a parallel command,
     * whose parent stays yielded on it), and a parent frame that finished
     * its list is discarded entirely, in which case the block was that
     * list's last command.
     */
    public function keyForEnginePath(string $path): ?string
    {
        $segments = explode('/', trim($path));

        if (($segments[0] ?? '') !== 'root') {
            return null;
        }

        array_shift($segments);
        $finalizer = ($segments[0] ?? '') === 'finalizer';

        if ($finalizer) {
            array_shift($segments);
        }

        $key = $finalizer ? 'finalizer' : 'commands';
        $list = $finalizer ? $this->definition->finalizer : $this->definition->commands;
        /** @var array<string, mixed>|null $command The command the walk is on. */
        $command = null;
        $count = count($segments);

        foreach ($segments as $position => $segment) {
            if (! preg_match('/^(.*)\\[([^\\]]*)\\]$/', $segment, $match)) {
                return $key;
            }

            [, $label, $argument] = $match;
            $next = $segments[$position + 1] ?? null;
            $nextIsParallel = $next !== null && str_starts_with($next, 'parallel[');

            if ($label === 'parallel') {
                // Into a lane of the parallel command the walk is on.
                $entries = is_array($command['lanes'] ?? null) ? array_values($command['lanes']) : [];
                $laneIndex = $this->laneIndexFor($entries, $argument);

                if ($laneIndex === null) {
                    return $key;
                }

                $entry = $entries[$laneIndex];
                $key .= '.lanes.' . $laneIndex;
                $list = is_array($entry) && array_is_list($entry)
                    ? $entry
                    : (is_array($entry) && is_array($entry['commands'] ?? null) ? array_values($entry['commands']) : []);
                $command = null;

                continue;
            }

            $oneBased = (int) $argument;
            $suffix = match (true) {
                $label === 'sequence' => 'commands',
                $label === 'branch:then' => 'then',
                $label === 'branch:else' => 'else',
                $label === 'choice:cancel' => 'cancel',
                str_starts_with($label, 'choice:') => 'options.' . substr($label, strlen('choice:')),
                str_starts_with($label, 'common_event:') => '?',
                default => null,
            };

            if ($suffix === '?') {
                // The commands live in another asset; stop at the caller.
                return $key;
            }

            if ($suffix !== null) {
                // A block frame. Its opener is the command the walk is on —
                // or, when that frame was discarded, the last of the list.
                if ($command === null) {
                    $lastIndex = count($list) - 1;

                    if ($lastIndex < 0) {
                        return $key;
                    }

                    $key .= '.' . $lastIndex;
                    $opener = $list[$lastIndex];
                    $command = is_array($opener) ? $opener : null;
                }

                $key .= '.' . $suffix;
                $list = $this->blockList($command, $suffix);
            }

            $index = $oneBased - 1;

            if ($next !== null && ! $nextIsParallel) {
                // This frame already advanced past the block it opened.
                $index--;
            }

            $index = max(0, $index);
            $key .= '.' . $index;
            $candidate = $list[$index] ?? null;
            $command = is_array($candidate) && ! array_is_list($candidate) ? $candidate : null;
        }

        return $key;
    }

    /**
     * The child command list a block suffix names on a command.
     *
     * @param array<string, mixed>|null $command
     * @return array<int, mixed>
     */
    private function blockList(?array $command, string $suffix): array
    {
        if ($command === null) {
            return [];
        }

        if (str_starts_with($suffix, 'options.')) {
            $optionIndex = (int) substr($suffix, strlen('options.'));
            $options = is_array($command['options'] ?? null) ? array_values($command['options']) : [];
            $option = $options[$optionIndex] ?? null;

            return is_array($option) && is_array($option['then'] ?? null) ? array_values($option['then']) : [];
        }

        return is_array($command[$suffix] ?? null) ? array_values($command[$suffix]) : [];
    }

    /**
     * @param array<int, mixed> $entries The parallel command's lane entries.
     */
    private function laneIndexFor(array $entries, string $laneId): ?int
    {
        foreach ($entries as $index => $lane) {
            $id = is_array($lane) && ! array_is_list($lane) ? trim(strval($lane['id'] ?? $index)) : (string) $index;

            if ($id === $laneId) {
                return $index;
            }
        }

        return null;
    }

    private function note(string $text): void
    {
        $this->log[] = ['time' => $this->elapsed, 'text' => $text];
    }

    /**
     * Runs Engine code inside the project root with the preview
     * configuration installed and any stray terminal output discarded.
     */
    private function run(callable $operation): void
    {
        $this->installConfiguration();

        ProjectDirectoryContext::run($this->projectRoot, function () use ($operation): void {
            ob_start();

            try {
                $operation();
            } finally {
                ob_end_clean();
            }
        });
    }

    private function installConfiguration(): void
    {
        if ($this->configured) {
            return;
        }

        foreach ([ProjectConfig::class, PlaySettings::class] as $class) {
            if (ConfigStore::has($class)) {
                $this->previousConfigs[$class] = ConfigStore::get($class);
            }
        }

        $width = max(20, intval($this->options['width'] ?? 60));
        $height = max(8, intval($this->options['height'] ?? 18));
        ConfigStore::put(ProjectConfig::class, new PreviewConfig([
            'accessibility' => ['reducedMotion' => false],
            'save' => ['autosave' => false],
            'ui' => ['hud' => ['location' => false]],
        ]));
        ConfigStore::put(PlaySettings::class, new PreviewConfig([
            'screen' => ['width' => $width, 'height' => $height],
            'width' => $width,
            'height' => $height,
        ]));

        $logDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ichiloto-editor-preview-logs';
        Debug::configure(['log_level' => Debug::ERROR, 'log_directory' => $logDirectory]);
        $this->configured = true;
    }
}
