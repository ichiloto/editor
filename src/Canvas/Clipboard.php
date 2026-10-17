<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

/**
 * The canvas clipboard: one rectangular block of symbols lifted off a map
 * layer, ready to be stamped anywhere (repeatedly) as a single undoable
 * paste per stamp.
 *
 * The clipboard is session-scoped and layer-tagged so a block copied from
 * the event layer never lands silently on the tile layer. A block lifted
 * off a gameplay layer carries the tiles that move with it, cell for cell.
 */
final class Clipboard
{
    /**
     * @var array<int, array<int, string>> Rows of symbols, top-left first.
     */
    private array $rows = [];
    /** @var array<int, array<int, array{prefix: string, suffix: string}>> */
    private array $styles = [];
    /**
     * The layer the block was lifted from (a PaintStrokeCommand LAYER_* value).
     */
    public private(set) string $layer = '';
    /** @var array<string, list<list<string>>> Tile entries by row, keyed by tile layer name. */
    private array $tiles = [];

    /**
     * Stores a block of symbols.
     *
     * @param array<int, array<int, string>> $rows Rows of symbols, top-left first.
     * @param string $layer The source layer.
     * @param array<int, array<int, array{prefix: string, suffix: string}>> $styles Optional tile styles, aligned with rows.
     * @param array<string, list<list<string>>> $tiles The tile entries that move with the block, by row, keyed by tile layer name.
     * @return void
     */
    public function store(array $rows, string $layer, array $styles = [], array $tiles = []): void
    {
        $this->rows = array_values(array_map(static fn(array $row): array => array_values($row), $rows));
        $this->styles = array_values(array_map(static fn(array $row): array => array_values($row), $styles));
        $this->layer = $layer;
        $this->tiles = $tiles;
    }

    /**
     * Empties the clipboard.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->rows = [];
        $this->styles = [];
        $this->layer = '';
        $this->tiles = [];
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
     * @return array<int, array{x: int, y: int, symbol: string, style?: array{prefix: string, suffix: string}}> The clipped placements.
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

                $placement = ['x' => $x, 'y' => $y, 'symbol' => $symbol];

                if (isset($this->styles[$rowIndex][$columnIndex])) {
                    $placement['style'] = $this->styles[$rowIndex][$columnIndex];
                }

                $placements[] = $placement;
            }
        }

        return $placements;
    }

    /**
     * Projects the block's tiles onto a map at the given top-left origin,
     * every cell included, so a paste replaces the tiles under the block as
     * it replaces the glyphs.
     *
     * @return array<string, list<array{x: int, y: int, entry: string}>> The clipped cells, keyed by tile layer name.
     */
    public function projectTiles(int $originX, int $originY, int $width, int $height): array
    {
        $cells = [];
        foreach ($this->tiles as $name => $rows) {
            $cells[$name] = [];
            foreach ($rows as $rowIndex => $row) {
                foreach ($row as $columnIndex => $entry) {
                    $x = $originX + $columnIndex;
                    $y = $originY + $rowIndex;
                    if ($x >= 0 && $y >= 0 && $x < $width && $y < $height) {
                        $cells[$name][] = ['x' => $x, 'y' => $y, 'entry' => $entry];
                    }
                }
            }
        }

        return $cells;
    }
}
