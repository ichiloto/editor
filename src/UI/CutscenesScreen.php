<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

use Atatusoft\Termutil\IO\Console\Console;
use Closure;

/**
 * The Cutscenes screen: its pane registry, per-pane dirty state, and the
 * fixed paint order.
 *
 * Mirrors the Database screen's mechanics -- a root frame painted first,
 * then each dirty pane in order, then the edit caret when a field is being
 * typed into -- so an idle frame writes nothing and a change repaints only
 * the panes that read it.
 *
 * @package Ichiloto\Editor\UI
 */
final class CutscenesScreen
{
    public const string PANE_TYPES = 'types';
    public const string PANE_LIST = 'list';
    public const string PANE_SETTINGS = 'settings';
    public const string PANE_TREE = 'tree';
    public const string PANE_PREVIEW = 'preview';

    public const array PANES = [
        self::PANE_TYPES,
        self::PANE_LIST,
        self::PANE_SETTINGS,
        self::PANE_TREE,
        self::PANE_PREVIEW,
    ];

    /** @var string[] */
    private array $dirtyPanes = [];
    private bool $isRootDirty = false;

    /**
     * @param Closure(): array<string, int> $layoutResolver Computes the current layout.
     * @param Closure(array<string, int>): void $rootPainter Paints the root frame.
     * @param array<string, Closure(array<string, int>): void> $panePainters Paints one pane each.
     * @param Closure(array<string, int>): void $editCursorPainter Places the caret while editing.
     * @param Closure(): bool $isEditingResolver Whether a field is being typed into.
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
     * @param string[] $panes
     */
    public function markDirty(array $panes, bool $includeRoot = false): void
    {
        $this->dirtyPanes = array_values(array_unique([...$this->dirtyPanes, ...$panes]));
        $this->isRootDirty = $this->isRootDirty || $includeRoot;
    }

    public function markAllDirty(bool $includeRoot = false): void
    {
        $this->markDirty(self::PANES, $includeRoot);
    }

    public function isDirty(): bool
    {
        return $this->dirtyPanes !== [] || $this->isRootDirty;
    }

    public function clearDirty(): void
    {
        $this->dirtyPanes = [];
        $this->isRootDirty = false;
    }

    public function flush(): void
    {
        if (! $this->isDirty()) {
            return;
        }

        $this->draw($this->dirtyPanes, null, $this->isRootDirty);
        $this->clearDirty();
    }

    /**
     * @param string[] $panes
     * @param array<string, int>|null $layout
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
            if (in_array($pane, $uniquePanes, true) && isset($this->panePainters[$pane])) {
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
     * @param array<string, int>|null $layout
     */
    public function drawAll(?array $layout = null, bool $includeRoot = false): void
    {
        $this->draw(self::PANES, $layout, $includeRoot);
    }
}
