<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

/**
 * The canvas clipboard: one rectangular block of symbols lifted off a map
 * layer, ready to be stamped anywhere (repeatedly) as a single undoable
 * paste per stamp.
 *
 * The clipboard is session-scoped and layer-tagged so a block copied from
 * the event layer never lands silently on the tile layer.
 */
final class Clipboard
{
    /**
     * @var array<int, array<int, string>> Rows of symbols, top-left first.
     */
    private array $rows = [];
    /**
     * The layer the block was lifted from (a PaintStrokeCommand LAYER_* value).
     */
    public private(set) string $layer = '';

    /**
     * Stores a block of symbols.
     *
     * @param array<int, array<int, string>> $rows Rows of symbols, top-left first.
     * @param string $layer The source layer.
     * @return void
     */
    public function store(array $rows, string $layer): void
    {
        $this->rows = array_values(array_map(static fn(array $row): array => array_values($row), $rows));
        $this->layer = $layer;
    }

    /**
     * Empties the clipboard.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->rows = [];
        $this->layer = '';
    }

    /**
     * Returns whether the clipboard holds a block.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Returns the block width in cells.
     *
     * @return int
     */
    public function getWidth(): int
    {
        $width = 0;

        foreach ($this->rows as $row) {
            $width = max($width, count($row));
        }

        return $width;
    }

    /**
     * Returns the block height in cells.
     *
     * @return int
     */
    public function getHeight(): int
    {
        return count($this->rows);
    }

    /**
     * Returns the stored rows.
     *
     * @return array<int, array<int, string>>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * Projects the block onto a map at the given top-left origin.
     *
     * @param int $originX The paste origin x coordinate.
     * @param int $originY The paste origin y coordinate.
     * @param int $width The target map width.
     * @param int $height The target map height.
     * @return array<int, array{x: int, y: int, symbol: string}> The clipped placements.
     */
    public function project(int $originX, int $originY, int $width, int $height): array
    {
        $placements = [];

        foreach ($this->rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $symbol) {
                $x = $originX + $columnIndex;
                $y = $originY + $rowIndex;

                if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
                    continue;
                }

                $placements[] = ['x' => $x, 'y' => $y, 'symbol' => $symbol];
            }
        }

        return $placements;
    }
}
