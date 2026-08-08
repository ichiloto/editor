<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Closure;

/**
 * The pure cell-set geometry behind every canvas tool.
 *
 * Each helper answers the same question — *which cells does this gesture
 * touch?* — and never mutates a map. The editor feeds the answer into one
 * PaintStrokeCommand, which is what makes a filled rectangle or a flood fill
 * undo as a single step.
 */
final class ToolGeometry
{
    /**
     * Returns every integer point on the line between two cells (Bresenham).
     *
     * This is the same interpolation the mouse-drag stroke has always used
     * to fill gaps between reported drag points; the Line tool and the drag
     * path now share this one implementation.
     *
     * @param int $startX The starting x coordinate.
     * @param int $startY The starting y coordinate.
     * @param int $endX The ending x coordinate.
     * @param int $endY The ending y coordinate.
     * @return array<int, array{x: int, y: int}>
     */
    public static function line(int $startX, int $startY, int $endX, int $endY): array
    {
        $points = [];
        $deltaX = abs($endX - $startX);
        $stepX = $startX < $endX ? 1 : -1;
        $deltaY = -abs($endY - $startY);
        $stepY = $startY < $endY ? 1 : -1;
        $error = $deltaX + $deltaY;

        while (true) {
            $points[] = ['x' => $startX, 'y' => $startY];

            if ($startX === $endX && $startY === $endY) {
                break;
            }

            $doubleError = $error * 2;

            if ($doubleError >= $deltaY) {
                $error += $deltaY;
                $startX += $stepX;
            }

            if ($doubleError <= $deltaX) {
                $error += $deltaX;
                $startY += $stepY;
            }
        }

        return $points;
    }

    /**
     * Returns the perimeter cells of the rectangle spanned by two corners.
     *
     * @param int $startX The anchor x coordinate.
     * @param int $startY The anchor y coordinate.
     * @param int $endX The cursor x coordinate.
     * @param int $endY The cursor y coordinate.
     * @return array<int, array{x: int, y: int}>
     */
    public static function rectangleOutline(int $startX, int $startY, int $endX, int $endY): array
    {
        [$left, $top, $right, $bottom] = self::normalizeBounds($startX, $startY, $endX, $endY);
        $cells = [];

        for ($x = $left; $x <= $right; $x++) {
            $cells[] = ['x' => $x, 'y' => $top];
            $cells[] = ['x' => $x, 'y' => $bottom];
        }

        for ($y = $top; $y <= $bottom; $y++) {
            $cells[] = ['x' => $left, 'y' => $y];
            $cells[] = ['x' => $right, 'y' => $y];
        }

        return self::unique($cells);
    }

    /**
     * Returns every cell of the rectangle spanned by two corners.
     *
     * @param int $startX The anchor x coordinate.
     * @param int $startY The anchor y coordinate.
     * @param int $endX The cursor x coordinate.
     * @param int $endY The cursor y coordinate.
     * @return array<int, array{x: int, y: int}>
     */
    public static function rectangleFilled(int $startX, int $startY, int $endX, int $endY): array
    {
        [$left, $top, $right, $bottom] = self::normalizeBounds($startX, $startY, $endX, $endY);
        $cells = [];

        for ($y = $top; $y <= $bottom; $y++) {
            for ($x = $left; $x <= $right; $x++) {
                $cells[] = ['x' => $x, 'y' => $y];
            }
        }

        return $cells;
    }

    /**
     * Returns the cells a square brush of the given size covers.
     *
     * Size 1 is the historic single cell, so the default brush behaves
     * exactly as it always has.
     *
     * @param int $x The brush centre x coordinate.
     * @param int $y The brush centre y coordinate.
     * @param int $size The brush edge length in cells.
     * @return array<int, array{x: int, y: int}>
     */
    public static function brush(int $x, int $y, int $size): array
    {
        $size = max(1, $size);

        if ($size === 1) {
            return [['x' => $x, 'y' => $y]];
        }

        $before = intdiv($size - 1, 2);
        $after = intdiv($size, 2);
        $cells = [];

        for ($offsetY = -$before; $offsetY <= $after; $offsetY++) {
            for ($offsetX = -$before; $offsetX <= $after; $offsetX++) {
                $cells[] = ['x' => $x + $offsetX, 'y' => $y + $offsetY];
            }
        }

        return $cells;
    }

    /**
     * Expands a cell list by the brush footprint, dropping duplicates.
     *
     * @param array<int, array{x: int, y: int}> $cells The source cells.
     * @param int $size The brush edge length in cells.
     * @return array<int, array{x: int, y: int}>
     */
    public static function expandByBrush(array $cells, int $size): array
    {
        if (max(1, $size) === 1) {
            return self::unique($cells);
        }

        $expanded = [];

        foreach ($cells as $cell) {
            foreach (self::brush($cell['x'], $cell['y'], $size) as $brushCell) {
                $expanded[] = $brushCell;
            }
        }

        return self::unique($expanded);
    }

    /**
     * Returns the contiguous region of same-symbol cells reachable from a
     * starting cell (4-connected scanline-free flood fill).
     *
     * @param Closure(int, int): string $symbolAt Reads the symbol at a cell.
     * @param int $width The map width.
     * @param int $height The map height.
     * @param int $startX The seed x coordinate.
     * @param int $startY The seed y coordinate.
     * @return array<int, array{x: int, y: int}>
     */
    public static function floodFill(Closure $symbolAt, int $width, int $height, int $startX, int $startY): array
    {
        if ($startX < 0 || $startY < 0 || $startX >= $width || $startY >= $height) {
            return [];
        }

        $target = $symbolAt($startX, $startY);
        $seen = [];
        $queue = [['x' => $startX, 'y' => $startY]];
        $cells = [];

        while ($queue !== []) {
            $cell = array_pop($queue);
            $key = $cell['x'] . ':' . $cell['y'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if ($cell['x'] < 0 || $cell['y'] < 0 || $cell['x'] >= $width || $cell['y'] >= $height) {
                continue;
            }

            if ($symbolAt($cell['x'], $cell['y']) !== $target) {
                continue;
            }

            $cells[] = $cell;
            $queue[] = ['x' => $cell['x'] + 1, 'y' => $cell['y']];
            $queue[] = ['x' => $cell['x'] - 1, 'y' => $cell['y']];
            $queue[] = ['x' => $cell['x'], 'y' => $cell['y'] + 1];
            $queue[] = ['x' => $cell['x'], 'y' => $cell['y'] - 1];
        }

        return $cells;
    }

    /**
     * Normalizes two corners into left/top/right/bottom bounds.
     *
     * @param int $startX The first x coordinate.
     * @param int $startY The first y coordinate.
     * @param int $endX The second x coordinate.
     * @param int $endY The second y coordinate.
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function normalizeBounds(int $startX, int $startY, int $endX, int $endY): array
    {
        return [
            min($startX, $endX),
            min($startY, $endY),
            max($startX, $endX),
            max($startY, $endY),
        ];
    }

    /**
     * Drops duplicate cells while preserving first-seen order.
     *
     * @param array<int, array{x: int, y: int}> $cells The source cells.
     * @return array<int, array{x: int, y: int}>
     */
    public static function unique(array $cells): array
    {
        $seen = [];
        $unique = [];

        foreach ($cells as $cell) {
            $key = $cell['x'] . ':' . $cell['y'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $cell;
        }

        return $unique;
    }
}
