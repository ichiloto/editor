<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

use Atatusoft\Termutil\IO\Console\Console;
use Closure;

/**
 * The Database screen: owns the screen's pane registry, per-pane dirty
 * state, and the fixed paint order (root frame, categories, list, settings,
 * cue, frames, preview, edit cursor).
 *
 * Like the main-shell panels, pane content builders remain on the Editor
 * coordinator and register here as painters keyed by pane id — the screen
 * decides *what* repaints and *when*; the painters decide *how*.
 */
final class DatabaseScreen
{
    public const string PANE_CATEGORIES = 'categories';
    public const string PANE_LIST = 'list';
    public const string PANE_SETTINGS = 'settings';
    public const string PANE_CUE = 'cue';
    public const string PANE_FRAMES = 'frames';
    public const string PANE_PREVIEW = 'preview';

    /**
     * The screen's panes in their fixed paint order.
     */
    public const array PANES = [
        self::PANE_CATEGORIES,
        self::PANE_LIST,
        self::PANE_SETTINGS,
        self::PANE_CUE,
        self::PANE_FRAMES,
        self::PANE_PREVIEW,
    ];

    /**
     * @var string[] The panes queued for repainting.
     */
    private array $dirtyPanes = [];
    /**
     * Whether the outer Database frame is queued for repainting.
     */
    private bool $isRootDirty = false;

    /**
     * @param Closure(): array<string, int> $layoutResolver Resolves the current Database layout.
     * @param Closure(array<string, int>): void $rootPainter Paints the outer Database frame.
     * @param array<string, Closure(array<string, int>): void> $panePainters Painters keyed by pane id.
     * @param Closure(array<string, int>): void $editCursorPainter Paints the live settings edit cursor.
     * @param Closure(): bool $isEditingResolver Reports whether a settings edit is in progress.
     */
    public function __construct(
        private readonly Closure $layoutResolver,
        private readonly Closure $rootPainter,
        private readonly array $panePainters,
        private readonly Closure $editCursorPainter,
        private readonly Closure $isEditingResolver,
    ) {
    }

    /**
     * Queues panes for repainting on the next flush.
     *
     * @param string[] $panes The pane identifiers to queue.
     * @param bool $includeRoot Whether to queue the outer Database frame.
     * @return void
     */
    public function markDirty(array $panes, bool $includeRoot = false): void
    {
        $this->dirtyPanes = array_values(array_unique([...$this->dirtyPanes, ...$panes]));
        $this->isRootDirty = $this->isRootDirty || $includeRoot;
    }

    /**
     * Queues the whole screen for repainting on the next flush.
     *
     * @param bool $includeRoot Whether to queue the outer Database frame.
     * @return void
     */
    public function markAllDirty(bool $includeRoot = false): void
    {
        $this->markDirty(self::PANES, $includeRoot);
    }

    /**
     * Returns whether any pane (or the root frame) awaits repainting.
     *
     * @return bool
     */
    public function isDirty(): bool
    {
        return $this->dirtyPanes !== [] || $this->isRootDirty;
    }

    /**
     * Discards the queued repaints (a full-screen redraw supersedes them).
     *
     * @return void
     */
    public function clearDirty(): void
    {
        $this->dirtyPanes = [];
        $this->isRootDirty = false;
    }

    /**
     * Paints the queued panes once, then clears the queue.
     *
     * @return void
     */
    public function flush(): void
    {
        if (! $this->isDirty()) {
            return;
        }

        $this->draw($this->dirtyPanes, null, $this->isRootDirty);
        $this->clearDirty();
    }

    /**
     * Paints the given panes immediately in the screen's fixed paint order.
     *
     * @param string[] $panes The pane identifiers to paint.
     * @param array<string, int>|null $layout The optional precomputed Database layout.
     * @param bool $includeRoot Whether to paint the outer Database frame.
     * @return void
     */
    public function draw(array $panes, ?array $layout = null, bool $includeRoot = false): void
    {
        $layout ??= ($this->layoutResolver)();
        $uniquePanes = array_values(array_unique($panes));
        Console::cursor()->hide();

        if ($includeRoot) {
            ($this->rootPainter)($layout);
        }

        foreach (self::PANES as $pane) {
            if (in_array($pane, $uniquePanes, true)) {
                ($this->panePainters[$pane])($layout);
            }
        }

        if (($this->isEditingResolver)() && in_array(self::PANE_SETTINGS, $uniquePanes, true)) {
            ($this->editCursorPainter)($layout);
            return;
        }

        Console::cursor()->hide();
    }

    /**
     * Paints the whole screen immediately.
     *
     * @param array<string, int>|null $layout The optional precomputed Database layout.
     * @param bool $includeRoot Whether to paint the outer Database frame.
     * @return void
     */
    public function drawAll(?array $layout = null, bool $includeRoot = false): void
    {
        $this->draw(self::PANES, $layout, $includeRoot);
    }
}
