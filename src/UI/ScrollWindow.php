<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

/**
 * The one list-scrolling algorithm shared by every selection-driven pane.
 *
 * Generalized from the Database settings pane (the Phase 3/quests work): the
 * window slides only when the selected row would fall past the last visible
 * line, so the list stays put while the selection walks, and the selected row
 * lands at `min(selectedRow, visibleRows - 1)` — the same row formula the
 * live edit cursors already assume.
 */
final class ScrollWindow
{
    /**
     * Returns the scroll offset that keeps the selected row visible.
     *
     * @param int $selectedRow The zero-based selected row index.
     * @param int $visibleRows The number of rows the pane can show.
     * @return int
     */
    public static function offset(int $selectedRow, int $visibleRows): int
    {
        return max(0, $selectedRow - max(1, $visibleRows) + 1);
    }

    /**
     * Slices a line list so the selected row stays inside the visible window.
     *
     * @param string[] $lines The full line list.
     * @param int $selectedRow The zero-based row index that must stay visible.
     * @param int $visibleRows The number of rows the pane can show.
     * @return string[]
     */
    public static function slice(array $lines, int $selectedRow, int $visibleRows): array
    {
        return array_slice($lines, self::offset($selectedRow, $visibleRows));
    }
}
