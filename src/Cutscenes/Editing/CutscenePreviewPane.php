<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Editing;

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneLaneOverview;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Editor\Cutscenes\Preview\PreviewSnapshot;
use Ichiloto\Editor\EditorWindow;
use Ichiloto\Editor\Playtest\PlaytestLauncher;
use Ichiloto\Editor\Playtest\PlaytestOverlay;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\CutscenesScreen;
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
    /** One of: stage, lanes, log, compare, overview. */
    private string $cutscenePreviewView = 'stage';
    private float $cutscenePreviewLastTickAt = 0.0;
    /** @var array<int, array{label: string, left: string, right: string}> */
    private array $cutscenePreviewComparison = [];
    private string $cutscenePreviewComparisonSummary = '';
    private int $cutscenePreviewScroll = 0;
    /** The asset payload the running preview was built from. */
    private string $cinematicPreviewFingerprint = '';

    /**
     * Whether the preview pane is taller than its resting strip: while a
     * session exists or the pane has focus.
     */
    private function isCutscenePreviewExpanded(): bool
    {
        return $this->cinematicPreview !== null
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
            $this->setStatus('Summon preview lives on the Timeline pane (Space plays).', StatusLevel::INFO);

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
            $failure !== null ? StatusLevel::ERROR : StatusLevel::SUCCESS,
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
            $this->setStatus('Skip accepted: the finalizer is running.', StatusLevel::SUCCESS);
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
        $preview = $this->cinematicPreview;

        if ($preview === null || ! $this->isCutscenesOpen || ! $preview->isPlaying()) {
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
                $failure !== null ? StatusLevel::ERROR : StatusLevel::SUCCESS,
            );
        }
    }

    private function disposeCinematicPreview(): void
    {
        $this->cinematicPreview?->dispose();
        $this->cinematicPreview = null;
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

            $this->setStatus(sprintf('Playtest finished (%s on %s at %d,%d).', $asset->id, $origin['mapId'], $origin['x'], $origin['y']), StatusLevel::SUCCESS);
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

                    $bounds = $map->getEventBounds((string) $marker);

                    if ($bounds !== null) {
                        return ['mapId' => $map->mapId, 'x' => (int) $bounds['x'], 'y' => (int) $bounds['y'], 'marker' => (string) $marker];
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
        } elseif ($this->cutscenePreviewView !== 'stage') {
            $title = 'Preview · ' . $this->cutscenePreviewView;
        }

        $help = $asset?->type === CutsceneType::SUMMON
            ? ''
            : $this->fitHelp(
                $layout['previewWidth'],
                'Space:Play/Pause  .:Step  K:Skip  R:Restart  X:Stop  J:Jump  C:Compare  V:Overview  L:View  Ctrl+T:Playtest',
                'Space:Play  .:Step  K:Skip  R:Restart  J:Jump  C:Compare  L:View',
                'Space:Play  .:Step  K:Skip  L:View',
            );

        $lines = match (true) {
            $asset === null => ['  Select a cutscene to preview it.'],
            $asset->type === CutsceneType::SUMMON => $this->summonPreviewLines($asset, $contentWidth, $contentHeight),
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
     * The duration overview: every lane and block with its authored time.
     *
     * @return string[]
     */
    private function cutsceneOverviewLines(CutsceneAsset $asset, int $contentWidth): array
    {
        $payload = $asset->payload();
        $commands = is_array($payload[CutsceneAsset::COMMANDS_KEY] ?? null) ? $payload[CutsceneAsset::COMMANDS_KEY] : [];
        $overview = CutsceneLaneOverview::of($commands);
        $lines = [sprintf('  Authored duration ≈ %s (Engine defaults where unset; dialogue, choices, battles and common events wait on play).', CutsceneLaneOverview::describe($overview->totalSeconds, $overview->totalMarks))];
        $timeWidth = 14;
        $labelWidth = max(10, $contentWidth - $timeWidth - 4);

        foreach ($overview->rows as $row) {
            $label = str_repeat('  ', $row['depth']) . $row['label'];
            $lines[] = sprintf(
                '  %s  %s',
                $this->padToWidth(mb_strimwidth($label, 0, $labelWidth, '…'), $labelWidth),
                CutsceneLaneOverview::describe($row['seconds'], $row['marks']),
            );
        }

        $finalizer = is_array($payload['finalizer'] ?? null) ? $payload['finalizer'] : [];

        if ($finalizer !== []) {
            $finalizerOverview = CutsceneLaneOverview::of($finalizer, 'finalizer');
            $lines[] = sprintf('  Finalizer ≈ %s', CutsceneLaneOverview::describe($finalizerOverview->totalSeconds, $finalizerOverview->totalMarks));
        }

        return $lines;
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
     * Placeholder until the summon preview lands on this pane.
     *
     * @return string[]
     */
    private function summonPreviewLines(CutsceneAsset $asset, int $contentWidth, int $contentHeight): array
    {
        unset($contentWidth, $contentHeight);

        return $this->describeCutsceneStanding($asset);
    }
}
