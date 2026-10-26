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
    /**
     * The source layer's name (terrain, buildings, fixtures): what the block is to any map, since a layer's id
     * is its own map's and its tile layers follow it by name. Empty for a block stored without one.
     */
    public private(set) string $layerName = '';
    /** @var array<string, list<list<string>>> Tile entries by row, keyed by tile layer name. */
    private array $tiles = [];
    /**
     * @var array<string, list<list<list<int>>>>|null The layer's stacked objects' tiles, cell by cell, by row, keyed by stack id
     *   ({@see StackedObjects}); null for a block lifted without its tiles.
     */
    private ?array $stacked = null;

    /**
     * Stores a block of symbols.
     *
     * @param array<int, array<int, string>> $rows Rows of symbols, top-left first.
     * @param string $layer The source layer.
     * @param array<int, array<int, array{prefix: string, suffix: string}>> $styles Optional tile styles, aligned with rows.
     * @param array<string, list<list<string>>> $tiles The tile entries that move with the block, by row, keyed by tile layer name.
     * @param string $layerName The source layer's name, which a paste on another map matches.
     * @param array<string, list<list<list<int>>>>|null $stacked The layer's stacked objects' tiles in the block ({@see StackedObjects::read()}),
     *   or null for a block lifted without its tiles.
     * @return void
     */
    public function store(array $rows, string $layer, array $styles = [], array $tiles = [], string $layerName = '',
        ?array $stacked = null): void
    {
        $this->stacked = $stacked;
        $this->rows = array_values(array_map(static fn(array $row): array => array_values($row), $rows));
        $this->styles = array_values(array_map(static fn(array $row): array => array_values($row), $styles));
        $this->layer = $layer;
        $this->layerName = $layerName;
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
        $this->layerName = '';
        $this->tiles = [];
        $this->stacked = null;
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

    /**
     * Projects the block's stacked objects onto a map at the given top-left
     * origin: every cell of the block on the map, whose objects of the
     * block's layer a paste replaces, and the tiles it lays in each. A block
     * lifted without its tiles replaces none.
     *
     * @return array{cells: list<array{x: int, y: int}>, laid: array<string, array<string, list<int>>>} Tiles by stack id, then `x,y` cell key.
     */
    public function projectStacked(int $originX, int $originY, int $width, int $height): array
    {
        $cells = $laid = [];
        if ($this->stacked === null) {
            return ['cells' => [], 'laid' => []];
        }
        foreach ($this->rows as $rowIndex => $row) {
            foreach (array_keys($row) as $columnIndex) {
                [$x, $y] = [$originX + $columnIndex, $originY + $rowIndex];
                if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
                    continue;
                }
                $cells[] = ['x' => $x, 'y' => $y];
                foreach ($this->stacked as $stack => $rows) {
                    if (($rows[$rowIndex][$columnIndex] ?? []) !== []) {
                        $laid[$stack]["{$x},{$y}"] = $rows[$rowIndex][$columnIndex];
                    }
                }
            }
        }

        return ['cells' => $cells, 'laid' => $laid];
    }
}
