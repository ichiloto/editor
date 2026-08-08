<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

/**
 * The canvas drawing tools.
 *
 * Every tool ultimately produces a set of cells that the editor applies as
 * ONE PaintStrokeCommand, so a filled rectangle, a flood fill, or a paste
 * undoes in a single Ctrl+Z — the same guarantee a mouse drag already had.
 *
 * Declaration order is the Ctrl+N cycle order.
 */
enum CanvasTool: string
{
    /**
     * The historic single-cell (or brush-sized) painter.
     */
    case BRUSH = 'brush';
    /**
     * Anchor, then a Bresenham line to the cursor.
     */
    case LINE = 'line';
    /**
     * Anchor, then the outline of the spanned rectangle.
     */
    case RECTANGLE = 'rectangle';
    /**
     * Anchor, then every cell of the spanned rectangle.
     */
    case FILLED_RECTANGLE = 'filled_rectangle';
    /**
     * Anchor, then a rectangular selection for copy/cut/paste.
     */
    case SELECT = 'select';

    /**
     * Returns the short user-facing tool label.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::BRUSH => 'Brush',
            self::LINE => 'Line',
            self::RECTANGLE => 'Rect',
            self::FILLED_RECTANGLE => 'Rect Fill',
            self::SELECT => 'Select',
        };
    }

    /**
     * Returns the undo-history label for a stroke this tool produced.
     *
     * Lowercase on purpose: the status line renders it as
     * "Undid filled rectangle." via lcfirst().
     *
     * @return string
     */
    public function commandLabel(): string
    {
        return match ($this) {
            self::BRUSH => 'brush stroke',
            self::LINE => 'line',
            self::RECTANGLE => 'rectangle',
            self::FILLED_RECTANGLE => 'filled rectangle',
            self::SELECT => 'selection',
        };
    }

    /**
     * Returns whether the tool needs an anchor before it can be applied.
     *
     * @return bool
     */
    public function needsAnchor(): bool
    {
        return $this !== self::BRUSH;
    }

    /**
     * Returns the next tool in the Ctrl+N cycle.
     *
     * @param int $step The cycle direction.
     * @return self
     */
    public function cycle(int $step = 1): self
    {
        $cases = self::cases();
        $index = (int) array_search($this, $cases, true);
        $count = count($cases);

        return $cases[(($index + $step) % $count + $count) % $count];
    }
}
