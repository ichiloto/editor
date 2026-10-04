<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Editing;

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneLaneOverview;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Editor\Cutscenes\Preview\EffectPreviewStage;
use Ichiloto\Editor\Cutscenes\Preview\PreviewSnapshot;
use Ichiloto\Editor\Cutscenes\Preview\TimelinePreviewSession;
use Ichiloto\Editor\EditorWindow;
use Ichiloto\Editor\Playtest\PlaytestLauncher;
use Ichiloto\Editor\Playtest\PlaytestOverlay;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\CutscenesScreen;
use Ichiloto\Editor\Validation\EffectValidator;
use Ichiloto\Engine\Animations\Timelines\EffectCadence;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Enumerations\BattleActionCategory;
use Ichiloto\Engine\Battle\Enumerations\BattlePace;
use Throwable;

/**
 * The preview pane of the Cutscenes screen.
 *
 * For a cinematic it hosts the Engine's own playback (see
 * `CinematicPreviewSession`): a stage drawn by the Engine's camera, the
 * lanes the interpreter is running, the checkpoints it recorded, and the
 * failure it stopped on — with Play, Pause, Step, Skip, Restart and Stop,
 * a jump from a failure to the command that failed, a watched-versus-skipped
 * comparison, a duration overview, and a route into the real game through a
 * playtest overlay. The command tree stays the one thing being edited; the
 * pane only reads it.
 *
 * @package Ichiloto\Editor\Cutscenes\Editing
 */
trait CutscenePreviewPane
{
    private ?CinematicPreviewSession $cinematicPreview = null;
    private ?TimelinePreviewSession $timelinePreview = null;
    /** One of: stage, lanes, log, compare, overview. */
    private string $cutscenePreviewView = 'stage';
    private float $cutscenePreviewLastTickAt = 0.0;
    /** @var array<int, array{label: string, left: string, right: string}> */
    private array $cutscenePreviewComparison = [];
    private string $cutscenePreviewComparisonSummary = '';
    private int $cutscenePreviewScroll = 0;
    /** The row under the cursor in the duration overview. */
    private int $cutsceneOverviewCursor = 0;
    /** The open project's effect timelines, read once, so the overview can time field effects. */
    private ?EffectTimelineLibrary $cutsceneEffectLibrary = null;
    /** The asset payload the running preview was built from. */
    private string $cinematicPreviewFingerprint = '';
    /** @var array<string, bool> Effect id => previewed as battle plays it (else as the field does), once the author chose. */
    private array $effectPreviewInBattle = [];
    /** Which side the caster stands on in an effect's battle preview. */
    private bool $isEffectPreviewCasterOnLeft = true;
    /** The kind of command a battle-paced effect is previewed inside, which sets how long its phase lasts. */
    private BattleActionCategory $effectPreviewAction = BattleActionCategory::PHYSICAL_ATTACK;
    /** The battle pace a battle-paced effect is previewed at; the project's own until the author changes it. */
    private ?BattlePace $effectPreviewPace = null;
    /** @var array<string, bool> Effect id => previewed as a command's source stage (else its target), once the author chose. */
    private array $effectPreviewAsSource = [];

    /**
     * Whether the preview pane is taller than its resting strip: while a
     * session exists or the pane has focus.
     */
    private function isCutscenePreviewExpanded(): bool
    {
        return $this->cinematicPreview !== null
            || $this->timelinePreview !== null
            || $this->cutsceneFocus === CutscenesScreen::PANE_PREVIEW
            || $this->cutscenePreviewView !== 'stage';
    }

    /**
     * Moves inside the preview pane: scrolls its lines.
     */
    private function moveCutscenePreview(int $deltaX, int $deltaY): void
    {
        if ($this->cinematicPreview !== null && $this->cutscenePreviewView === 'stage' && $deltaY !== 0 && $this->cinematicPreview->moveChoice($deltaY)) {
            $this->renderDatabasePanes(['preview']);

            return;
        }

        if ($this->cutscenePreviewView === 'overview' && $deltaY !== 0) {
            $rows = $this->cutsceneOverviewRows();
            $this->cutsceneOverviewCursor = max(0, min(max(0, count($rows) - 1), $this->cutsceneOverviewCursor + $deltaY));
            $this->renderDatabasePanes(['preview']);

            return;
        }

        $this->cutscenePreviewScroll = max(0, $this->cutscenePreviewScroll + $deltaY + $deltaX);
        $this->renderDatabasePanes(['preview']);
    }

    // -- Control -------------------------------------------------------------

    /**
     * Handles the preview pane's own keys. Returns whether the input was
     * consumed.
     */
    private function handleCutscenePreviewInput(string $input): bool
    {
        if ($this->selectedCutscene()?->type !== null && $this->selectedCutscene()?->type !== CutsceneType::CINEMATIC) {
            return $this->handleTimelinePreviewInput($input);
        }

        $preview = $this->cinematicPreview;
        $lower = strtolower($input);

        if ($input === ' ') {
            $this->toggleCinematicPreviewPlayback();

            return true;
        }

        if ($input === '.') {
            $this->stepCinematicPreview();

            return true;
        }

        if ($lower === 'r') {
            $this->restartCinematicPreview();

            return true;
        }

        if ($lower === 'k') {
            $this->skipCinematicPreview();

            return true;
        }

        if ($lower === 'x') {
            $this->stopCinematicPreview();

            return true;
        }

        if ($lower === 'j') {
            $this->jumpToCinematicPreviewFailure();

            return true;
        }

        if ($lower === 'c') {
            $this->compareCinematicPreview();

            return true;
        }

        if ($lower === 'l') {
            $this->cycleCutscenePreviewView();

            return true;
        }

        if ($lower === 'v') {
            $this->setCutscenePreviewView('overview');

            return true;
        }

        if (($input === "\n" || $input === "\r") && $this->cutscenePreviewView === 'overview') {
            $rows = $this->cutsceneOverviewRows();
            $row = $rows[$this->cutsceneOverviewCursor] ?? null;

            if ($row !== null && $this->jumpToCutsceneOutlineKey($row['key'])) {
                $this->setStatus(sprintf('Opened %s.', $row['label']));
            }

            return true;
        }

        if (($input === "\n" || $input === "\r") && $preview !== null) {
            if ($preview->confirm()) {
                $this->renderDatabasePanes(['preview']);
            } elseif ($preview->isFinished()) {
                $this->restartCinematicPreview();
            } else {
                $this->toggleCinematicPreviewPlayback();
            }

            return true;
        }

        if (($input === "\n" || $input === "\r") && $preview === null) {
            $this->startCinematicPreview(play: true);

            return true;
        }

        return false;
    }

    /**
     * Starts (or restarts) the Engine preview of the selected cinematic.
     */
    private function startCinematicPreview(bool $play): void
    {
        $asset = $this->selectedCutscene();

        if (! $asset instanceof CutsceneAsset || ! $this->workspace instanceof ProjectWorkspace) {
            $this->setStatus('Select a cutscene to preview it.', StatusLevel::WARN);

            return;
        }

        if ($asset->type !== CutsceneType::CINEMATIC) {
            $this->startTimelinePreview(play: $play);

            return;
        }

        $this->disposeCinematicPreview();

        try {
            $definition = $asset->cinematicDefinition();
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Cinematic preview');
            $this->renderDatabasePanes(['preview']);

            return;
        }

        $origin = $this->cinematicPreviewOrigin($asset, $definition->startMap);
        [$width, $height] = $this->cinematicStageSize();

        try {
            $this->cinematicPreview = CinematicPreviewSession::start($this->workspace->projectRoot, $definition, [
                'mapId' => $origin['mapId'],
                'x' => $origin['x'],
                'y' => $origin['y'],
                'width' => $width,
                'height' => $height,
            ]);
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Cinematic preview');
            $this->renderDatabasePanes(['preview']);

            return;
        }

        $this->cutscenePreviewView = 'stage';
        $this->cutscenePreviewScroll = 0;
        $this->cutscenePreviewLastTickAt = microtime(true);
        $this->cinematicPreviewFingerprint = $this->cutscenePayloadFingerprint($asset);

        if ($play) {
            $this->cinematicPreview->play();
        }

        $failure = $this->cinematicPreview->failure();
        $this->setStatus(
            $failure !== null
                ? sprintf('Preview refused: %s', $failure['message'])
                : sprintf(
                    'Previewing %s in the Engine%s (%s).',
                    $asset->id,
                    $origin['mapId'] !== null ? sprintf(' on %s at %d,%d', $origin['mapId'], $origin['x'], $origin['y']) : ' without a map',
                    $play ? 'playing' : 'paused',
                ),
            $failure !== null ? StatusLevel::ERROR : StatusLevel::INFO,
        );
        $this->renderDatabasePanes(['preview', 'tree']);
    }

    private function toggleCinematicPreviewPlayback(): void
    {
        $preview = $this->cinematicPreview;

        if ($preview === null || $preview->isFinished()) {
            $this->startCinematicPreview(play: true);

            return;
        }

        if ($preview->isPlaying()) {
            $preview->pause();
            $this->setStatus('Preview paused.');
        } else {
            $preview->play();
            $this->cutscenePreviewLastTickAt = microtime(true);
            $this->setStatus('Preview playing.');
        }

        $this->renderDatabasePanes(['preview']);
    }

    private function stepCinematicPreview(): void
    {
        $preview = $this->cinematicPreview;

        if ($preview === null) {
            $this->startCinematicPreview(play: false);

            return;
        }

        if ($preview->isFinished()) {
            $this->setStatus(sprintf('Preview %s; R restarts.', $preview->status()), StatusLevel::INFO);

            return;
        }

        $preview->pause();
        $preview->step();
        $this->setStatus(sprintf('Stepped to %.1fs.', $preview->elapsed()));
        $this->renderDatabasePanes(['preview', 'tree']);
    }

    private function restartCinematicPreview(): void
    {
        // A restart plays unless the author had paused a run in progress.
        $preview = $this->cinematicPreview;
        $wasPlaying = $preview === null || $preview->isFinished() || $preview->isPlaying();
        $this->startCinematicPreview(play: $wasPlaying);
    }

    private function skipCinematicPreview(): void
    {
        $preview = $this->cinematicPreview;

        if ($preview === null) {
            $this->setStatus('Start the preview first (Space).', StatusLevel::INFO);

            return;
        }

        $reason = $preview->skipRefusalReason();

        if ($reason !== null) {
            $this->setStatus(sprintf('Skip refused: %s.', $reason), StatusLevel::WARN);
            $this->renderDatabasePanes(['preview']);

            return;
        }

        if ($preview->skip()) {
            $this->setStatus('Skip accepted: the finalizer is running.', StatusLevel::INFO);
            $preview->play();
            $this->cutscenePreviewLastTickAt = microtime(true);
        } else {
            $this->setStatus('The Engine refused the skip.', StatusLevel::WARN);
        }

        $this->renderDatabasePanes(['preview', 'tree']);
    }

    private function stopCinematicPreview(): void
    {
        $preview = $this->cinematicPreview;

        if ($preview === null) {
            return;
        }

        if ($preview->isFinished()) {
            $this->disposeCinematicPreview();
            $this->setStatus('Preview closed.');
        } else {
            $preview->stop();
            $this->setStatus('Preview stopped; the Engine ran its failure cleanup.', StatusLevel::INFO);
        }

        $this->renderDatabasePanes(['preview', 'tree']);
    }

    /**
     * Puts the outline cursor, and the record pane, on the failed command.
     */
    private function jumpToCinematicPreviewFailure(): void
    {
        $failure = $this->cinematicPreview?->failure();

        if ($failure === null) {
            $this->setStatus('Nothing failed.', StatusLevel::INFO);

            return;
        }

        if ($failure['key'] === null || ! $this->jumpToCutsceneOutlineKey($failure['key'])) {
            $this->setStatus($failure['message'], StatusLevel::WARN);

            return;
        }

        $this->setStatus(sprintf('Failed here: %s', $failure['message']), StatusLevel::WARN);
    }

    /**
     * Selects an outline row by key, unfolding what hides it, and opens it
     * in the record pane.
     */
    private function jumpToCutsceneOutlineKey(string $key): bool
    {
        $outline = $this->cutsceneOutline();

        if ($outline === null) {
            return false;
        }

        foreach ($this->cutsceneTreeCollapsed as $collapsed => $_) {
            if (str_starts_with($key, $collapsed . '.')) {
                unset($this->cutsceneTreeCollapsed[$collapsed]);
            }
        }

        foreach ($this->visibleCutsceneTreeRows() as $position => $row) {
            if ($row['key'] === $key) {
                $this->cutsceneTreeCursor = $position;
                $this->cutsceneFocus = CutscenesScreen::PANE_TREE;
                $this->openCutsceneTreeRow();
                $this->cutsceneFocus = CutscenesScreen::PANE_TREE;
                $this->renderCutscenesArea();

                return true;
            }
        }

        return false;
    }

    /**
     * Runs the cinematic twice offline — watched to the end, and skipped at
     * once — and shows where their final states differ.
     */
    private function compareCinematicPreview(): void
    {
        $asset = $this->selectedCutscene();

        if (! $asset instanceof CutsceneAsset || $asset->type !== CutsceneType::CINEMATIC || ! $this->workspace instanceof ProjectWorkspace) {
            $this->setStatus('Select a cinematic to compare.', StatusLevel::WARN);

            return;
        }

        try {
            $definition = $asset->cinematicDefinition();
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Skip comparison');

            return;
        }

        $origin = $this->cinematicPreviewOrigin($asset, $definition->startMap);
        $options = ['mapId' => $origin['mapId'], 'x' => $origin['x'], 'y' => $origin['y'], 'autoAdvance' => true];
        $watched = null;
        $skipped = null;

        try {
            $watched = CinematicPreviewSession::start($this->workspace->projectRoot, $definition, $options);
            $watched->runToCompletion();
            $skipped = CinematicPreviewSession::start($this->workspace->projectRoot, $definition, $options);
            $refusal = $skipped->skipRefusalReason();
            $accepted = $refusal === null && $skipped->skip();
            $skipped->runToCompletion();
            $this->cutscenePreviewComparison = $watched->snapshot()->diff($skipped->snapshot());
            $this->cutscenePreviewComparisonSummary = sprintf(
                'Watched: %s in %.1fs. Skipped at start: %s%s. %d difference%s.',
                $watched->status(),
                $watched->elapsed(),
                $accepted ? 'finalizer ' . $skipped->status() : 'skip refused',
                $accepted ? '' : ($refusal !== null ? ' (' . $refusal . ')' : ''),
                count($this->cutscenePreviewComparison),
                count($this->cutscenePreviewComparison) === 1 ? '' : 's',
            );
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Skip comparison');

            return;
        } finally {
            $watched?->dispose();
            $skipped?->dispose();
            // A comparison borrows the Engine configuration; the live preview
            // reinstalls its own on its next tick.
        }

        $this->setCutscenePreviewView('compare');
        $this->setStatus($this->cutscenePreviewComparisonSummary, $this->cutscenePreviewComparison === [] ? StatusLevel::SUCCESS : StatusLevel::INFO);
    }

    private function cycleCutscenePreviewView(): void
    {
        $views = ['stage', 'lanes', 'log', 'overview'];

        if ($this->cutscenePreviewComparison !== [] || $this->cutscenePreviewComparisonSummary !== '') {
            $views[] = 'compare';
        }

        $position = array_search($this->cutscenePreviewView, $views, true);
        $this->setCutscenePreviewView($views[($position === false ? 0 : $position + 1) % count($views)]);
    }

    private function setCutscenePreviewView(string $view): void
    {
        $this->cutscenePreviewView = $view;
        $this->cutscenePreviewScroll = 0;
        $this->setStatus(sprintf('Preview view: %s.', $view));
        $this->renderCutscenesArea();
    }

    /**
     * Advances a playing preview by the real time since the last tick.
     */
    private function tickCutscenePreview(): void
    {
        if (! $this->isCutscenesOpen) {
            return;
        }

        $this->tickTimelinePreview();
        $preview = $this->cinematicPreview;

        if ($preview === null || ! $preview->isPlaying()) {
            return;
        }

        $now = microtime(true);
        $elapsed = $this->cutscenePreviewLastTickAt > 0.0 ? $now - $this->cutscenePreviewLastTickAt : 0.0;

        if ($elapsed < CinematicPreviewSession::TICK_SECONDS / 2) {
            return;
        }

        $this->cutscenePreviewLastTickAt = $now;
        $preview->tick(min(0.5, $elapsed));
        $this->renderDatabasePanes(['preview', 'tree']);

        if ($preview->isFinished()) {
            $failure = $preview->failure();
            $this->setStatus(
                $failure !== null
                    ? sprintf('Preview failed: %s (J jumps to it)', $failure['message'])
                    : sprintf('Preview %s after %.1fs.', $preview->status(), $preview->elapsed()),
                $failure !== null ? StatusLevel::ERROR : StatusLevel::INFO,
            );
        }
    }

    private function disposeCinematicPreview(): void
    {
        $this->cinematicPreview?->dispose();
        $this->cinematicPreview = null;
        $this->timelinePreview = null;
    }

    // -- Summons and effects -------------------------------------------------

    /**
     * Handles the preview keys while a summon or an effect is selected. An
     * effect also takes B, playing it as battle or as the field compiles it,
     * and D, putting its battle caster on the other side.
     */
    private function handleTimelinePreviewInput(string $input): bool
    {
        $preview = $this->timelinePreview;
        $lower = strtolower($input);
        $asset = $this->selectedCutscene();

        if ($asset?->type === CutsceneType::EFFECT && in_array($lower, ['a', 'p', 's'], true) && $this->isEffectPreviewInBattle($asset)) {
            // The battle command a battle-paced effect plays inside: its kind,
            // the battle's pace and the stage, which together set its phase.
            match ($lower) {
                'a' => $this->effectPreviewAction = self::cycleCase(BattleActionCategory::cases(), $this->effectPreviewAction),
                'p' => $this->effectPreviewPace = self::cycleCase(BattlePace::cases(), $this->getEffectPreviewPace()),
                's' => $this->effectPreviewAsSource[$asset->id] = ! $this->isEffectPreviewSource($asset),
            };
            $this->startTimelinePreview(play: $preview?->isPlaying() ?? false);

            return true;
        }

        if ($asset?->type === CutsceneType::EFFECT && ($input === 'b' || $input === 'd')) {
            if ($input === 'b') {
                $this->effectPreviewInBattle[$asset->id] = ! $this->isEffectPreviewInBattle($asset);
            } elseif ($this->isEffectPreviewInBattle($asset)) {
                $this->isEffectPreviewCasterOnLeft = ! $this->isEffectPreviewCasterOnLeft;
            }

            if ($input === 'b') {
                // Battle and the field compile the timeline differently, and
                // either may refuse it: compile now and say so.
                $this->startTimelinePreview(play: $preview?->isPlaying() ?? false);

                return true;
            }

            $this->setStatus($this->isEffectPreviewInBattle($asset)
                ? sprintf('Caster on the %s.', $this->isEffectPreviewCasterOnLeft ? 'left' : 'right')
                : 'The field has no caster; B previews battle.', StatusLevel::INFO);
            $this->renderDatabasePanes(['preview', 'tree']);

            return true;
        }

        if ($input === ' ' || $input === "\n" || $input === "\r") {
            if ($preview === null) {
                $this->startTimelinePreview(play: true);
            } elseif ($preview->isPlaying()) {
                $preview->pause();
                $this->setStatus(sprintf('Paused at frame %d.', $preview->currentFrame()));
            } else {
                $preview->play();
                $this->cutscenePreviewLastTickAt = microtime(true);
                $this->setStatus('Playing.');
            }

            $this->renderDatabasePanes(['preview', 'tree']);

            return true;
        }

        $isHome = str_contains($input, "\033[H") || str_contains($input, "\033[1~") || str_contains($input, "\033OH");
        $isEnd = str_contains($input, "\033[F") || str_contains($input, "\033[4~") || str_contains($input, "\033OF");

        if ($input === '+' || $input === '-' || $lower === 'o' || $isHome || $isEnd) {
            if ($preview === null) {
                $this->startTimelinePreview(play: false);
                $preview = $this->timelinePreview;
            }

            if ($preview === null) {
                return true;
            }

            if ($input === '+' || $input === '-') {
                $speed = $preview->changeSpeed($input === '+' ? 1 : -1);
                $this->setStatus(sprintf('Speed %gx.', $speed));
            } elseif ($lower === 'o') {
                $this->setStatus($preview->toggleLoop() ? 'Looping.' : 'Playing once.');
            } elseif ($isHome) {
                $preview->seek(0);
                $this->setStatus('Frame 0.');
            } else {
                $preview->seek($preview->totalFrames() - 1);
                $this->setStatus(sprintf('Frame %d.', $preview->totalFrames() - 1));
            }

            $this->renderDatabasePanes(['preview', 'tree']);

            return true;
        }

        if ($input === '.' || $input === ',' || $input === '>' || $input === '<' || $lower === 'r' || $lower === 'l' || $lower === 'x') {
            if ($preview === null) {
                $this->startTimelinePreview(play: false);
                $preview = $this->timelinePreview;
            }

            if ($preview === null) {
                return true;
            }

            match (true) {
                $input === '.' => $preview->stepForward(),
                $input === ',' => $preview->stepBackward(),
                $input === '>' => $preview->seekBoundary(1),
                $input === '<' => $preview->seekBoundary(-1),
                $lower === 'r' => $preview->restart(),
                $lower === 'l' => $this->cutscenePreviewView = $this->cutscenePreviewView === 'timeline' ? 'stage' : 'timeline',
                default => $this->timelinePreview = null,
            };

            if ($lower === 'x') {
                $this->setStatus('Preview closed.');
            } elseif ($lower === 'l') {
                $this->setStatus(sprintf('Preview view: %s.', $this->cutscenePreviewView));
            } else {
                $this->setStatus(sprintf('Frame %d of %d.', $preview->currentFrame(), $preview->totalFrames()));
            }

            $this->renderDatabasePanes(['preview', 'tree']);

            return true;
        }

        return false;
    }

    /**
     * Compiles the selected summon, or the effect's sequence being edited
     * for battle or the field, as it stands, and opens the Engine playback
     * session over it. An effect plays as its timeline says, once or
     * looping; a summon once until O loops it.
     */
    private function startTimelinePreview(bool $play): void
    {
        $asset = $this->selectedCutscene();

        if (! $asset instanceof CutsceneAsset || $asset->type === CutsceneType::CINEMATIC) {
            return;
        }

        try {
            $compiled = $asset->type === CutsceneType::EFFECT
                ? $asset->compiledEffect($asset->getPresentationView() ?? EffectPresentation::TERMINAL, $this->isEffectPreviewInBattle($asset))
                : $asset->compiledSummon();
        } catch (Throwable $throwable) {
            $this->timelinePreview = null;
            $this->setErrorStatus($throwable, ucfirst($asset->type->noun()) . ' preview');
            $this->renderDatabasePanes(['preview']);

            return;
        }

        // A battle-paced sequence spreads its frames over the phase it plays in.
        $phaseSeconds = $asset->type === CutsceneType::EFFECT && $compiled->cadence === EffectCadence::BATTLE_PHASE
            ? $this->getEffectPreviewPhaseSeconds($asset)
            : null;
        $this->timelinePreview = new TimelinePreviewSession($compiled, $asset->type === CutsceneType::EFFECT ? null : false, $phaseSeconds);
        $this->cinematicPreviewFingerprint = $this->cutscenePayloadFingerprint($asset);
        $this->cutscenePreviewLastTickAt = microtime(true);
        $this->cutscenePreviewScroll = 0;

        if ($play) {
            $this->timelinePreview->play();
        }

        $this->setStatus(sprintf(
            'Previewing %s: %d frames %s (%s)%s.',
            $asset->id,
            $this->timelinePreview->totalFrames(),
            $phaseSeconds === null
                ? sprintf('at %d fps', $this->timelinePreview->fps())
                : sprintf('over %.2fs, %s (A, P, S change it)', $phaseSeconds, $this->describeEffectPreviewPacing($asset)),
            $play ? 'playing' : 'paused',
            $asset->type === CutsceneType::EFFECT ? sprintf(', as %s plays it', $this->isEffectPreviewInBattle($asset) ? 'battle' : 'the field') : '',
        ), StatusLevel::INFO);
        $this->renderDatabasePanes(['preview', 'tree']);
    }

    private function tickTimelinePreview(): void
    {
        $preview = $this->timelinePreview;

        if ($preview === null || ! $preview->isPlaying()) {
            return;
        }

        $now = microtime(true);
        $elapsed = $this->cutscenePreviewLastTickAt > 0.0 ? $now - $this->cutscenePreviewLastTickAt : 0.0;

        if ($elapsed < $preview->secondsPerFrame() / 2) {
            return;
        }

        $this->cutscenePreviewLastTickAt = $now;
        $preview->tick(min(0.5, $elapsed));
        $this->renderDatabasePanes(['preview', 'tree']);

        if ($preview->isCompleted()) {
            $this->setStatus(sprintf('Preview finished: %d frames, %d cue%s fired.', $preview->totalFrames(), count($preview->cueLog()), count($preview->cueLog()) === 1 ? '' : 's'), StatusLevel::INFO);
        }
    }

    /**
     * Whether the selected effect is previewed as battle plays it: as the
     * author last chose, else battle when a battle animation uses it or
     * nothing on the field does.
     */
    private function isEffectPreviewInBattle(CutsceneAsset $asset): bool
    {
        if (isset($this->effectPreviewInBattle[$asset->id])) {
            return $this->effectPreviewInBattle[$asset->id];
        }

        $uses = $this->workspace instanceof ProjectWorkspace ? (EffectValidator::findUses($this->workspace)[$asset->id] ?? []) : [];
        $fieldScripts = $this->workspace instanceof ProjectWorkspace
            ? (EffectValidator::findScriptUses($this->workspace, $this->cutsceneLibrary()?->assets(CutsceneType::CINEMATIC) ?? [])[$asset->id] ?? [])
            : [];

        return $this->effectPreviewInBattle[$asset->id] = isset($uses['battle']) || (! isset($uses['field']) && $fieldScripts === []);
    }

    /**
     * Whether the selected effect is previewed as a command's source stage:
     * as the author last chose, else when every battle animation using it
     * plays it as its source effect.
     */
    private function isEffectPreviewSource(CutsceneAsset $asset): bool
    {
        if (isset($this->effectPreviewAsSource[$asset->id])) {
            return $this->effectPreviewAsSource[$asset->id];
        }

        $battleUses = $this->workspace instanceof ProjectWorkspace ? (EffectValidator::findUses($this->workspace)[$asset->id]['battle'] ?? []) : [];

        return $this->effectPreviewAsSource[$asset->id] = $battleUses !== []
            && array_all($battleUses, static fn(string $use): bool => str_ends_with($use, ' sourceEffect'));
    }

    /** The battle pace a paced effect is previewed at: the author's choice, else the project's. */
    private function getEffectPreviewPace(): BattlePace
    {
        $battleUi = $this->workspace?->config?->getRecord('ui.battle')->get('value');

        return $this->effectPreviewPace ??= BattlePacing::fromBattleUiConfig(is_array($battleUi) ? $battleUi : [])->getAnimationPace();
    }

    /**
     * The seconds the battle phase lasts that a paced effect plays in, from
     * the Engine's own turn timings: the action animation for a source
     * stage, the effect animation for a target stage.
     */
    private function getEffectPreviewPhaseSeconds(CutsceneAsset $asset): float
    {
        $timings = BattleTurnTimings::fromTotalDuration($this->effectPreviewAction->totalDurationSeconds($this->getEffectPreviewPace()));

        return $this->isEffectPreviewSource($asset) ? $timings->actionAnimation : $timings->effectAnimation;
    }

    /** The battle command a paced effect is previewed inside, as the author reads it. */
    private function describeEffectPreviewPacing(CutsceneAsset $asset): string
    {
        return sprintf(
            'the %s of a %s at %s pace',
            $this->isEffectPreviewSource($asset) ? 'source' : 'target',
            str_replace('_', ' ', $this->effectPreviewAction->value),
            $this->getEffectPreviewPace()->value,
        );
    }

    /**
     * The case after the given one, wrapping to the first.
     *
     * @template T of \UnitEnum
     * @param list<T> $cases
     * @param T $current
     * @return T
     */
    private static function cycleCase(array $cases, \UnitEnum $current): \UnitEnum
    {
        $index = array_search($current, $cases, true);

        return $cases[(($index === false ? -1 : $index) + 1) % count($cases)];
    }

    /**
     * The stage an effect's frame is drawn on: its sequence being edited,
     * battle or the field, and the caster's side.
     */
    private function createEffectPreviewStage(CutsceneAsset $asset): EffectPreviewStage
    {
        return new EffectPreviewStage($asset->getPresentationView() ?? EffectPresentation::TERMINAL, $this->isEffectPreviewInBattle($asset), $this->isEffectPreviewCasterOnLeft);
    }

    /**
     * The keyframe rows active at the playhead, by outline key.
     *
     * @return string[]
     */
    private function findActiveTimelineKeys(CutsceneAsset $asset): array
    {
        $preview = $this->timelinePreview;

        if ($preview === null) {
            return [];
        }

        $frame = $preview->currentFrame();
        $keys = [];
        $payload = $asset->payload();

        foreach (array_values(is_array($payload['tracks'] ?? null) ? $payload['tracks'] : []) as $trackIndex => $track) {
            if (! is_array($track)) {
                continue;
            }

            foreach (array_values(is_array($track['keyframes'] ?? null) ? $track['keyframes'] : []) as $keyframeIndex => $keyframe) {
                if (! is_array($keyframe)) {
                    continue;
                }

                $start = intval($keyframe['frame'] ?? 0);
                $end = $start + max(1, intval($keyframe['duration'] ?? 1)) - 1;

                if ($frame >= $start && $frame <= $end) {
                    $keys[] = sprintf('tracks.%d.keyframes.%d', $trackIndex, $keyframeIndex);
                }
            }
        }

        foreach (array_values(is_array($payload['cues'] ?? null) ? $payload['cues'] : []) as $cueIndex => $cue) {
            if (is_array($cue) && intval($cue['frame'] ?? -1) === $frame) {
                $keys[] = 'cues.' . $cueIndex;
            }
        }

        return $keys;
    }

    /**
     * Plays the cinematic in the real game: a playtest overlay whose start
     * map carries an automatic trigger for it on the spawn tile.
     */
    private function playtestSelectedCinematic(): void
    {
        $asset = $this->selectedCutscene();

        if (! $asset instanceof CutsceneAsset || ! $this->workspace instanceof ProjectWorkspace) {
            $this->setStatus('Select a cinematic to playtest.', StatusLevel::WARN);

            return;
        }

        if ($asset->type !== CutsceneType::CINEMATIC) {
            $this->setStatus('Only cinematics playtest from here; summons play inside battle.', StatusLevel::INFO);

            return;
        }

        if ($asset->isDirty() || $asset->isNew()) {
            $this->setStatus('Save this cinematic (Ctrl+S) before playtesting — the game reads the files on disk.', StatusLevel::WARN);

            return;
        }

        try {
            $definition = $asset->cinematicDefinition();
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Cinematic playtest');

            return;
        }

        $origin = $this->cinematicPreviewOrigin($asset, $definition->startMap);

        if ($origin['mapId'] === null) {
            $this->setStatus('Set startMap on the cinematic, or select a map, before playtesting.', StatusLevel::WARN);

            return;
        }

        $overlay = null;

        try {
            $overlay = PlaytestOverlay::createForCinematic(
                $this->workspace->projectRoot,
                $origin['mapId'],
                $origin['x'],
                $origin['y'],
                $asset->id,
            );
            $launcher = PlaytestLauncher::discover(projectRoot: $this->workspace->projectRoot);
            $this->terminal->suspendForChildProcess();

            try {
                $launcher->run($overlay);
            } finally {
                $this->terminal->resumeAfterChildProcess($this->lastTerminalSize);
            }

            $this->setStatus(sprintf('Playtest finished (%s on %s at %d,%d).', $asset->id, $origin['mapId'], $origin['x'], $origin['y']), StatusLevel::INFO);
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Cinematic playtest');
        } finally {
            $overlay?->destroy();
        }

        $this->requestFullRender();
    }

    /**
     * Where a preview or playtest starts: the map event that triggers the
     * cinematic when one exists, otherwise the cinematic's start map (or the
     * selected map) at its first open tile.
     *
     * @return array{mapId: string|null, x: int, y: int, marker: string|null}
     */
    private function cinematicPreviewOrigin(CutsceneAsset $asset, ?string $startMap): array
    {
        $workspace = $this->workspace;

        if ($workspace instanceof ProjectWorkspace) {
            foreach ($workspace->maps as $map) {
                foreach ($map->getEventDefinitions() as $marker => $definition) {
                    if (! is_array($definition) || ! str_contains(strval($definition['class'] ?? ''), 'CinematicEventTrigger')) {
                        continue;
                    }

                    $data = is_array($definition['data'] ?? null) ? $definition['data'] : [];

                    if (trim(strval($data['cinematicId'] ?? '')) !== $asset->id) {
                        continue;
                    }

                    // Where the runtime places the marker: its first cell.
                    $first = $map->getEventArea((string) $marker)?->firstCell;

                    if ($first !== null) {
                        return ['mapId' => $map->mapId, 'x' => (int) $first->x, 'y' => (int) $first->y, 'marker' => (string) $marker];
                    }
                }
            }
        }

        $map = null;

        if ($startMap !== null && $workspace instanceof ProjectWorkspace) {
            foreach ($workspace->maps as $candidate) {
                if ($candidate->mapId === $startMap) {
                    $map = $candidate;
                }
            }
        }

        $map ??= $this->getSelectedMap();

        if (! $map instanceof ProjectMap) {
            return ['mapId' => $startMap, 'x' => 1, 'y' => 1, 'marker' => null];
        }

        [$x, $y] = $this->firstOpenTile($map);

        return ['mapId' => $map->mapId, 'x' => $x, 'y' => $y, 'marker' => null];
    }

    /**
     * The first tile that is not a wall-like glyph, row by row.
     *
     * @return array{0: int, 1: int}
     */
    private function firstOpenTile(ProjectMap $map): array
    {
        for ($y = 0; $y < $map->getHeight(); $y++) {
            for ($x = 0; $x < $map->getWidth(); $x++) {
                $symbol = $map->getTileSymbol($x, $y);

                if ($symbol === ' ') {
                    return [$x, $y];
                }
            }
        }

        return [0, 0];
    }

    /**
     * The stage size the preview draws at: the pane's content area minus
     * the lanes column.
     *
     * @return array{0: int, 1: int}
     */
    private function cinematicStageSize(): array
    {
        $layout = $this->resolveCutscenesLayout(['width' => $this->lastTerminalSize['width'] ?? 120, 'height' => $this->lastTerminalSize['height'] ?? 40]);
        $contentWidth = $this->getWindowContentWidth($layout['previewWidth']);
        $contentHeight = max(1, $layout['previewHeight'] - 2);
        $infoWidth = $contentWidth >= 90 ? 36 : ($contentWidth >= 70 ? 30 : 0);
        $stageWidth = max(20, $contentWidth - ($infoWidth > 0 ? $infoWidth + 1 : 0));
        $stageHeight = max(8, $contentHeight - 1);

        return [$stageWidth, $stageHeight];
    }

    // -- Rendering -----------------------------------------------------------

    /**
     * @param array<string, int> $layout
     */
    private function createCutscenePreviewWindow(array $layout): EditorWindow
    {
        $contentWidth = $this->getWindowContentWidth($layout['previewWidth']);
        $contentHeight = max(1, $layout['previewHeight'] - 2);
        $asset = $this->selectedCutscene();
        $preview = $this->cinematicPreview;
        $title = 'Preview';

        if ($preview !== null) {
            $stale = $asset !== null && $this->cutscenePayloadFingerprint($asset) !== $this->cinematicPreviewFingerprint;
            $title = sprintf('Preview · %s · %.1fs%s', $preview->status(), $preview->elapsed(), $stale ? ' · edited since start (R restarts)' : '');
        } elseif ($this->timelinePreview !== null) {
            $stale = $asset !== null && $this->cutscenePayloadFingerprint($asset) !== $this->cinematicPreviewFingerprint;
            $title = sprintf('Preview · frame %d/%d%s', $this->timelinePreview->currentFrame(), $this->timelinePreview->totalFrames(), $stale ? ' · edited since start (R restarts)' : '');
        } elseif ($this->cutscenePreviewView !== 'stage') {
            $title = 'Preview · ' . $this->cutscenePreviewView;
        }

        $help = $asset?->type === CutsceneType::EFFECT
            ? $this->fitHelp(
                $layout['previewWidth'],
                'Space:Play/Pause  . ,:Step  < >:Keyframes  Home/End  +/-:Speed  O:Loop  B:Battle/Field  D:Caster side  R:Restart  L:Timeline  X:Close',
                'Space:Play  . ,:Step  < >:Keyframes  +/-:Speed  O:Loop  B:Battle/Field  D:Side',
                'Space:Play  . ,:Step  B:Battle/Field',
            )
            : ($asset?->type === CutsceneType::SUMMON
            ? $this->fitHelp(
                $layout['previewWidth'],
                'Space:Play/Pause  . ,:Step  < >:Keyframes  Home/End  +/-:Speed  O:Loop  R:Restart  L:Timeline  X:Close',
                'Space:Play  . ,:Step  < >:Keyframes  +/-:Speed  O:Loop  R:Restart',
                'Space:Play  . ,:Step  +/-:Speed',
            )
            : $this->fitHelp(
                $layout['previewWidth'],
                'Space:Play/Pause  .:Step  K:Skip  R:Restart  X:Stop  J:Jump  C:Compare  V:Overview  L:View  Ctrl+T:Playtest',
                'Space:Play  .:Step  K:Skip  R:Restart  J:Jump  C:Compare  L:View',
                'Space:Play  .:Step  K:Skip  L:View',
            ));

        $lines = match (true) {
            $asset === null => ['  Select a cutscene to preview it.'],
            $asset->type !== CutsceneType::CINEMATIC => $this->timelinePreviewLines($asset, $contentWidth, $contentHeight),
            $this->cutscenePreviewView === 'compare' => $this->cutsceneComparisonLines($contentWidth),
            $this->cutscenePreviewView === 'overview' => $this->cutsceneOverviewLines($asset, $contentWidth),
            $this->cutscenePreviewView === 'log' => $this->cutscenePreviewLogLines(),
            $this->cutscenePreviewView === 'lanes' => $this->cutscenePreviewLaneLines(),
            default => $this->cutsceneStageLines($asset, $contentWidth, $contentHeight),
        };

        return new EditorWindow(
            title: $title,
            help: $help,
            position: ['x' => $layout['previewX'], 'y' => $layout['previewY']],
            width: $layout['previewWidth'],
            height: $layout['previewHeight'],
            foregroundColor: $this->resolveCutscenePaneColor(CutscenesScreen::PANE_PREVIEW),
            content: $this->fitLines(array_slice($lines, $this->cutscenePreviewScroll), $contentWidth, $contentHeight),
        );
    }

    /**
     * The stage view: the Engine's frame beside the session's lanes and
     * state, or, before a session exists, the asset's standing and the
     * staging picture of its cast.
     *
     * @return string[]
     */
    private function cutsceneStageLines(CutsceneAsset $asset, int $contentWidth, int $contentHeight): array
    {
        $preview = $this->cinematicPreview;
        $infoWidth = $contentWidth >= 90 ? 36 : ($contentWidth >= 70 ? 30 : 0);
        $stageWidth = max(20, $contentWidth - ($infoWidth > 0 ? $infoWidth + 1 : 0));
        $stageHeight = max(8, $contentHeight - 1);

        if ($preview === null) {
            $standing = $this->describeCutsceneStanding($asset);
            $lines = [...$standing, '', '  Space: play in the Engine  ·  .: step  ·  C: compare watched/skipped  ·  V: duration overview  ·  Ctrl+T: playtest'];
            $origin = $this->cinematicPreviewOrigin($asset, is_string($asset->data()['startMap'] ?? null) ? $asset->data()['startMap'] : null);
            $lines[] = $origin['marker'] !== null
                ? sprintf('  Starts from map event %s on %s at %d,%d.', $origin['marker'], $origin['mapId'], $origin['x'], $origin['y'])
                : ($origin['mapId'] !== null
                    ? sprintf('  Starts on %s at %d,%d (no map trigger names this cinematic yet).', $origin['mapId'], $origin['x'], $origin['y'])
                    : '  No start map: set startMap, or select a map, to stage the cast.');

            return $lines;
        }

        $frame = $preview->frame();
        $info = $infoWidth > 0 ? $this->cutscenePreviewInfoLines($preview, $infoWidth) : [];
        $lines = [];
        $rows = max($stageHeight, count($info));

        for ($row = 0; $row < $rows; $row++) {
            $line = $this->padToWidth(mb_strimwidth($frame[$row] ?? '', 0, $stageWidth, ''), $stageWidth);

            if ($infoWidth > 0) {
                $line .= '│' . ($info[$row] ?? '');
            }

            $lines[] = $line;
        }

        $wait = $preview->waitDescription();
        $lines[] = $wait !== null ? '  ' . $wait : '';

        return $lines;
    }

    /**
     * A fingerprint of what the asset holds right now.
     */
    private function cutscenePayloadFingerprint(CutsceneAsset $asset): string
    {
        return md5(serialize($asset->payload()));
    }

    /**
     * Pads a string to a display width.
     */
    private function padToWidth(string $text, int $width): string
    {
        $current = mb_strwidth($text);

        return $current >= $width ? $text : $text . str_repeat(' ', $width - $current);
    }

    /**
     * The right-hand column of the stage view.
     *
     * @return string[]
     */
    private function cutscenePreviewInfoLines(CinematicPreviewSession $preview, int $width): array
    {
        $lines = [];
        $lines[] = sprintf(' %s · %.1fs', $preview->status(), $preview->elapsed());
        $failure = $preview->failure();

        if ($failure !== null) {
            foreach ($this->wrapPreviewText('✗ ' . $failure['message'], $width - 1) as $part) {
                $lines[] = ' ' . $part;
            }

            $lines[] = $failure['key'] !== null ? ' J: jump to ' . $failure['key'] : '';
        }

        $lines[] = ' Lanes';

        foreach ($preview->lanes() as $lane) {
            $label = $lane['path'] === 'root' || $lane['path'] === 'root/finalizer'
                ? ($lane['path'] === 'root' ? 'root' : 'finalizer')
                : (preg_match('/parallel\[([^\]]*)\]$/', $lane['path'], $m) === 1 ? 'lane ' . $m[1] : $lane['path']);
            $lines[] = sprintf(
                ' %s%s: %s%s',
                str_repeat('  ', $lane['depth']),
                $label,
                $lane['command'] ?? '—',
                $lane['status'] === 'yielded' || $lane['status'] === 'running' ? '' : ' (' . $lane['status'] . ')',
            );
        }

        $checkpoints = $preview->checkpoints();
        $lines[] = ' Checkpoints: ' . ($checkpoints === [] ? '—' : implode(', ', $checkpoints));
        $audio = $preview->audioState();
        $lines[] = sprintf(
            ' Music: %s%s',
            $audio['track'] === null ? 'silent' : basename($audio['track']),
            $audio['restored'] ? ' (field track restored)' : '',
        );
        $lines[] = ' Field input: ' . $preview->fieldInputOwner();
        $refusal = $preview->skipRefusalReason();
        $lines[] = ' Skip: ' . ($refusal === null ? 'authored finalizer (K)' : $refusal);
        $recent = array_slice($preview->log(), -3);

        foreach ($recent as $entry) {
            foreach ($this->wrapPreviewText(sprintf('%.1fs %s', $entry['time'], $entry['text']), $width - 1) as $part) {
                $lines[] = ' ' . $part;
            }
        }

        return array_map(fn(string $line): string => mb_strimwidth($line, 0, $width, ''), $lines);
    }

    /**
     * @return string[]
     */
    private function wrapPreviewText(string $text, int $width): array
    {
        $width = max(8, $width);
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if (mb_strwidth($candidate) > $width && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * @return string[]
     */
    private function cutscenePreviewLaneLines(): array
    {
        $preview = $this->cinematicPreview;

        if ($preview === null) {
            return ['  Start the preview (Space) to inspect its lanes.'];
        }

        $lines = [sprintf('  %s · %.1fs · %d lane%s', $preview->status(), $preview->elapsed(), count($preview->lanes()), count($preview->lanes()) === 1 ? '' : 's')];

        foreach ($preview->lanes() as $lane) {
            $lines[] = sprintf(
                '  %s%s  [%s]  %s%s',
                str_repeat('  ', $lane['depth']),
                $lane['path'],
                $lane['status'],
                $lane['command'] ?? '—',
                $lane['key'] !== null ? '  → ' . $lane['key'] : '',
            );
        }

        $checkpoints = $preview->checkpoints();
        $lines[] = '  Checkpoints: ' . ($checkpoints === [] ? '—' : implode(', ', $checkpoints));

        foreach ($preview->dialogueLog() as $entry) {
            $lines[] = sprintf('  %s %s%s', $entry['kind'] === 'choice' ? '?' : '"', $entry['speaker'] !== '' ? $entry['speaker'] . ': ' : '', $entry['text']);
        }

        return $lines;
    }

    /**
     * @return string[]
     */
    private function cutscenePreviewLogLines(): array
    {
        $preview = $this->cinematicPreview;

        if ($preview === null) {
            return ['  Start the preview (Space) to see its log.'];
        }

        $lines = [];

        foreach ($preview->log() as $entry) {
            $lines[] = sprintf('  %6.1fs  %s', $entry['time'], $entry['text']);
        }

        return $lines === [] ? ['  (nothing yet)'] : $lines;
    }

    /**
     * @return string[]
     */
    private function cutsceneComparisonLines(int $contentWidth): array
    {
        $lines = ['  ' . ($this->cutscenePreviewComparisonSummary !== '' ? $this->cutscenePreviewComparisonSummary : 'Press C to compare a watched run with a skipped one.')];

        if ($this->cutscenePreviewComparison === []) {
            if ($this->cutscenePreviewComparisonSummary !== '') {
                $lines[] = '  The watched run and the skipped run end in the same observable state.';
            }

            return $lines;
        }

        $labelWidth = 0;

        foreach ($this->cutscenePreviewComparison as $difference) {
            $labelWidth = max($labelWidth, mb_strwidth($difference['label']));
        }

        $labelWidth = min($labelWidth, max(12, intdiv($contentWidth, 3)));
        $lines[] = sprintf('  %s  %s', $this->padToWidth('state', $labelWidth), 'watched → skipped');

        foreach ($this->cutscenePreviewComparison as $difference) {
            $lines[] = sprintf(
                '  %s  %s → %s',
                $this->padToWidth(mb_strimwidth($difference['label'], 0, $labelWidth, '…'), $labelWidth),
                $difference['left'],
                $difference['right'],
            );
        }

        return $lines;
    }

    /**
     * Returns the open project's effect timelines, or null with no project.
     */
    private function getEffectLibrary(): ?EffectTimelineLibrary
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return null;
        }

        $assetRoot = $this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets';

        if ($this->cutsceneEffectLibrary?->assetRoot !== $assetRoot) {
            $this->cutsceneEffectLibrary = new EffectTimelineLibrary($assetRoot);
        }

        return $this->cutsceneEffectLibrary;
    }

    /**
     * The overview rows of the selected cinematic: commands, then finalizer.
     *
     * @return array<int, array{depth: int, key: string, label: string, seconds: float, marks: string[], kind: string}>
     */
    private function cutsceneOverviewRows(): array
    {
        $asset = $this->selectedCutscene();

        if ($asset === null || $asset->type !== CutsceneType::CINEMATIC) {
            return [];
        }

        $payload = $asset->payload();
        $commands = is_array($payload[CutsceneAsset::COMMANDS_KEY] ?? null) ? $payload[CutsceneAsset::COMMANDS_KEY] : [];
        $finalizer = is_array($payload['finalizer'] ?? null) ? $payload['finalizer'] : [];

        return [
            ...CutsceneLaneOverview::of($commands, effects: $this->getEffectLibrary())->rows,
            ...CutsceneLaneOverview::of($finalizer, 'finalizer', $this->getEffectLibrary())->rows,
        ];
    }

    /**
     * The duration overview: every lane and block with its authored time,
     * the commands the running preview is on, and a cursor that opens a
     * row in the tree.
     *
     * @return string[]
     */
    private function cutsceneOverviewLines(CutsceneAsset $asset, int $contentWidth): array
    {
        $payload = $asset->payload();
        $commands = is_array($payload[CutsceneAsset::COMMANDS_KEY] ?? null) ? $payload[CutsceneAsset::COMMANDS_KEY] : [];
        $overview = CutsceneLaneOverview::of($commands, effects: $this->getEffectLibrary());
        $finalizer = is_array($payload['finalizer'] ?? null) ? $payload['finalizer'] : [];
        $finalizerOverview = CutsceneLaneOverview::of($finalizer, 'finalizer', $this->getEffectLibrary());
        $preview = $this->cinematicPreview;
        $activeKeys = $preview !== null && ! $preview->isFinished() ? $preview->activeKeys() : [];
        $lines = [sprintf(
            '  Commands ≈ %s · Finalizer ≈ %s (Engine defaults where unset; +input, +battle and +? wait on play). Enter opens a row in the tree.',
            CutsceneLaneOverview::describe($overview->totalSeconds, $overview->totalMarks),
            CutsceneLaneOverview::describe($finalizerOverview->totalSeconds, $finalizerOverview->totalMarks),
        )];
        $timeWidth = 16;
        $labelWidth = max(10, $contentWidth - $timeWidth - 6);
        $rows = [...$overview->rows, ...$finalizerOverview->rows];
        $this->cutsceneOverviewCursor = max(0, min(max(0, count($rows) - 1), $this->cutsceneOverviewCursor));

        foreach ($rows as $position => $row) {
            $label = str_repeat('  ', $row['depth']) . $row['label'];
            $cursor = $position === $this->cutsceneOverviewCursor && $this->cutsceneFocus === CutscenesScreen::PANE_PREVIEW ? '>' : ' ';
            $mark = in_array($row['key'], $activeKeys, true) ? '▶' : ' ';
            $lines[] = sprintf(
                '%s%s %s  %s',
                $cursor,
                $mark,
                $this->padToWidth(mb_strimwidth($label, 0, $labelWidth, '…'), $labelWidth),
                CutsceneLaneOverview::describe($row['seconds'], $row['marks']),
            );
        }

        // Keep the cursor on screen.
        $visible = max(1, $this->cutscenePreviewRowsVisible());

        if ($this->cutsceneOverviewCursor + 1 >= $this->cutscenePreviewScroll + $visible) {
            $this->cutscenePreviewScroll = $this->cutsceneOverviewCursor + 2 - $visible;
        } elseif ($this->cutsceneOverviewCursor + 1 < $this->cutscenePreviewScroll) {
            $this->cutscenePreviewScroll = max(0, $this->cutsceneOverviewCursor);
        }

        return $lines;
    }

    /**
     * How many preview rows fit right now.
     */
    private function cutscenePreviewRowsVisible(): int
    {
        $layout = $this->resolveCutscenesLayout(['width' => $this->lastTerminalSize['width'] ?? 120, 'height' => $this->lastTerminalSize['height'] ?? 40]);

        return max(1, $layout['previewHeight'] - 2);
    }

    /**
     * Returns what the engine makes of the asset as it stands: hydrated, or
     * refused with the engine's reason.
     *
     * @return string[]
     */
    private function describeCutsceneStanding(CutsceneAsset $asset): array
    {
        try {
            $asset->hydrate();
            $standing = sprintf('  ✓ The engine reads this %s as it stands.', $asset->type->noun());
        } catch (Throwable $throwable) {
            $standing = '  ✗ ' . $throwable->getMessage();
        }

        return [
            sprintf('  %s · %s%s', $asset->name(), $asset->id, $asset->isDirty() ? ' *' : ''),
            $standing,
        ];
    }

    /**
     * The summon and effect view: the Engine's playhead over the compiled
     * timeline, a ruler with the keyframe bars and cues, the frame as the
     * battle field would compose it (an effect's anchored to its caster and
     * target), and the cues the playhead crossed.
     *
     * @return string[]
     */
    private function timelinePreviewLines(CutsceneAsset $asset, int $contentWidth, int $contentHeight): array
    {
        $preview = $this->timelinePreview;
        $stage = $asset->type === CutsceneType::EFFECT ? $this->createEffectPreviewStage($asset) : null;

        if ($preview === null) {
            return [
                ...$this->describeCutsceneStanding($asset),
                ...($stage === null ? [] : [sprintf('  Plays as %s, the %s sequence.%s',
                    $stage->forBattle ? 'battle' : 'the field', $stage->presentation->value,
                    $stage->forBattle ? sprintf(' %s is the caster, %s the target.', EffectPreviewStage::CASTER_MARKER, EffectPreviewStage::TARGET_MARKER) : sprintf(' %s is the target.', EffectPreviewStage::TARGET_MARKER))]),
                '',
                '  Space: play through the Engine session  ·  . , : step  ·  < > : keyframe boundaries  ·  R: restart  ·  L: timeline/stage'
                    . ($stage === null ? '' : '  ·  B: battle/field  ·  D: caster side'),
            ];
        }

        if ($this->cutscenePreviewView === 'timeline') {
            $lines = $preview->rulerLines($contentWidth - 2);
            $lines = array_map(static fn(string $line): string => '  ' . $line, $lines);
            $lines[] = '';

            foreach ($preview->activeSegments() as $segment) {
                $draw = (array) (((array) ($segment['drawCommands'] ?? []))[0] ?? []);
                $content = strval($draw['content'] ?? '');
                $first = trim((string) (preg_split('/\r?\n/', trim($content, "\r\n")) ?: [''])[0]);
                $lines[] = sprintf('  %s  f%d–%d  %s', strval($draw['trackId'] ?? $segment['layer'] ?? 'track'), intval($segment['startFrame'] ?? 0), intval($segment['endFrame'] ?? 0), $first !== '' ? $first : ('[' . strtoupper(strval($draw['assetId'] ?? '')) . ']'));
            }

            return $lines;
        }

        $infoWidth = $contentWidth >= 80 ? 32 : 0;
        $stageWidth = max(20, $contentWidth - ($infoWidth > 0 ? $infoWidth + 1 : 0));
        $rulerLines = $preview->rulerLines($stageWidth);
        $stageHeight = max(4, $contentHeight - count($rulerLines) - 1);
        $frame = $stage?->drawFrame($preview->activeSegments(), $stageWidth, $stageHeight) ?? $preview->frame($stageWidth, $stageHeight);
        $info = [];

        if ($infoWidth > 0) {
            $info[] = sprintf(' %s · frame %d/%d · %.1fs', $preview->isPlaying() ? 'playing' : ($preview->isCompleted() ? 'completed' : 'paused'), $preview->currentFrame(), $preview->totalFrames(), $preview->elapsed());
            $info[] = sprintf(' %gx speed · %s', $preview->speed(), $preview->isLooping() ? 'looping' : 'once');

            if ($stage !== null) {
                $info[] = sprintf(' %s · %s sequence', $stage->forBattle ? sprintf('battle, caster %s', $stage->isCasterOnLeft ? 'left' : 'right') : 'field', $stage->presentation->value);

                if ($preview->phaseDurationSeconds !== null) {
                    $info[] = sprintf(' paced over %.2fs:', $preview->phaseDurationSeconds);
                    $info[] = '   ' . $this->describeEffectPreviewPacing($asset);
                    $info[] = '   A action · P pace · S stage';
                }
                $info[] = ' Not drawn here:';

                foreach ($stage->describeUndrawn($preview->activeSegments()) ?: ['(nothing)'] as $undrawn) {
                    $info[] = '   ' . $undrawn;
                }
            }
            $info[] = ' Cues here: ' . (implode(', ', array_map(static fn(array $cue): string => strval($cue['id'] ?? '?'), $preview->cuesAt())) ?: '—');
            $info[] = ' Fired:';

            foreach (array_slice($preview->cueLog(), -6) as $entry) {
                $info[] = sprintf('   f%d %s (%s)', $entry['frame'], $entry['id'], $entry['type']);
            }

            if ($preview->cueLog() === []) {
                $info[] = '   (none yet)';
            }

            $info[] = ' Drawn now:';

            foreach ($preview->activeSegments() as $segment) {
                $draw = (array) (((array) ($segment['drawCommands'] ?? []))[0] ?? []);
                $info[] = sprintf('   %s%s', strval($draw['trackId'] ?? '?'), ($draw['visible'] ?? true) === false ? ' (hidden)' : '');
            }
        }

        $lines = [];

        foreach ($rulerLines as $rulerLine) {
            $lines[] = $this->padToWidth(mb_strimwidth($rulerLine, 0, $stageWidth, ''), $stageWidth);
        }

        $rows = max($stageHeight, count($info) - count($rulerLines));

        for ($row = 0; $row < $rows; $row++) {
            $line = $this->padToWidth(mb_strimwidth($frame[$row] ?? '', 0, $stageWidth, ''), $stageWidth);
            $lines[] = $line;
        }

        foreach ($lines as $index => $line) {
            if ($infoWidth > 0) {
                $lines[$index] = $line . '│' . ($info[$index] ?? '');
            }
        }

        return $lines;
    }
}
