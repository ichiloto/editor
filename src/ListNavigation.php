<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

/**
 * The one stepping rule for every selection list in the editor.
 *
 * Pressing past an edge wraps — Down on the last row selects the first,
 * Up on the first selects the last — while a jump from the middle that
 * overshoots still lands on the edge, so a page-sized step never leaps
 * invisibly past the end; the next press wraps from there. Cursors,
 * scroll offsets and text columns are not selection lists and keep their
 * own clamping.
 *
 * @package Ichiloto\Editor
 */
final class ListNavigation
{
    /**
     * Returns the next selected index.
     *
     * @param int $index The current index.
     * @param int $step The requested movement.
     * @param int $count How many entries the list holds.
     * @return int The next index.
     */
    public static function step(int $index, int $step, int $count): int
    {
        if ($count <= 0) {
            return 0;
        }

        $last = $count - 1;
        $index = max(0, min($last, $index));

        if ($step > 0 && $index === $last) {
            return 0;
        }

        if ($step < 0 && $index === 0) {
            return $last;
        }

        return max(0, min($last, $index + $step));
    }
}
