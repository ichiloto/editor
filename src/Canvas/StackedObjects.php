<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilesetStack;
use InvalidArgumentException;
use RuntimeException;

/**
 * The objects a map's tileset stacks hold ({@see TilesetStack}), read and
 * written by the gameplay layer each belongs to. A stack's tiles are whole
 * objects of their pieces, and each belongs to its piece's gameplay layer:
 * a peak to the terrain, a hill crest whose mound blocks the ground to the
 * layer its footprint's glyphs are on. So a stack moves with a gameplay layer
 * object by object, never as whole tile layers: lifting a layer takes its
 * own objects' tiles from each cell and leaves the others', and laying them
 * puts each tile into the cell's stack in depth order
 * ({@see TilesetStack::layTile()}), as stamping does.
 */
final class StackedObjects
{
    private function __construct()
    {
    }

    /**
     * Each stack's tiles of the gameplay layer's objects over a rectangle,
     * cell by cell, bottom to top.
     *
     * @return array<string, list<list<list<int>>>> Rows of cells by stack id; a stack none of whose objects are there is left out.
     * @throws MapSourceRefusal When the map's tileset or its stack layers cannot be read.
     */
    public static function read(ProjectMap $map, string $layerName, int $x, int $y, int $width, int $height): array
    {
        $read = [];
        foreach (self::loadStacks($map) as $stack) {
            $entries = self::readEntries($map, $stack, $x, $y, $width, $height);
            $rows = [];
            $found = false;
            for ($row = 0; $row < $height; $row++) {
                for ($column = 0; $column < $width; $column++) {
                    $own = array_values(array_filter(self::readCell($entries, $stack, $column, $row, $x + $column, $y + $row),
                        static fn(int $tile): bool => self::belongsTo($stack, $tile, $layerName)));
                    $found = $found || $own !== [];
                    $rows[$row][$column] = $own;
                }
            }
            if ($found) {
                $read[$stack->id] = $rows;
            }
        }

        return $read;
    }

    /**
     * The stack entries that take the gameplay layer's objects out of the
     * cells and then lay the given tiles there in depth order, keyed by tile
     * layer: a cut lays nothing, a paste lays what it carries. Every stack
     * layer of a changed cell is written, an emptied one included.
     *
     * @param list<array{x: int, y: int}> $cells
     * @param array<string, array<string, list<int>>> $laid Tiles to lay by stack id, then `x,y` cell key.
     * @return array<string, list<array{x: int, y: int, entry: string}>>
     * @throws MapSourceRefusal When a cell would hold more tiles than its stack has layers, or the stack cannot be read.
     */
    public static function replace(ProjectMap $map, string $layerName, array $cells, array $laid = []): array
    {
        if ($cells === []) {
            return [];
        }
        $xs = array_column($cells, 'x');
        $ys = array_column($cells, 'y');
        [$left, $top] = [min($xs), min($ys)];
        [$width, $height] = [max($xs) - $left + 1, max($ys) - $top + 1];
        $writes = [];
        foreach (self::loadStacks($map) as $stack) {
            $entries = self::readEntries($map, $stack, $left, $top, $width, $height);
            foreach ($cells as ['x' => $x, 'y' => $y]) {
                $held = self::readCell($entries, $stack, $x - $left, $y - $top, $x, $y);
                $kept = array_values(array_filter($held, static fn(int $tile): bool => ! self::belongsTo($stack, $tile, $layerName)));
                try {
                    foreach ($laid[$stack->id]["{$x},{$y}"] ?? [] as $tile) {
                        $kept = $stack->layTile($kept, $tile, $x, $y);
                    }
                } catch (InvalidArgumentException $error) {
                    throw new MapSourceRefusal(sprintf('(%d, %d) cannot take these objects: %s Nothing was changed.', $x, $y, $error->getMessage()),
                        previous: $error);
                }
                if ($kept === $held) {
                    continue;
                }
                foreach ($stack->layers as $index => $tileLayer) {
                    $writes[$tileLayer][] = ['x' => $x, 'y' => $y, 'entry' => (string) ($kept[$index] ?? TileId::EMPTY)];
                }
            }
        }

        return $writes;
    }

    /** Whether a stacked tile draws an object of the gameplay layer; a tile no stacked piece draws belongs to none. */
    private static function belongsTo(TilesetStack $stack, int $tile, string $layerName): bool
    {
        return ($stack->locate($tile)['piece'] ?? null)?->layer === $layerName;
    }

    /**
     * @return array<string, TilesetStack>
     * @throws MapSourceRefusal
     */
    private static function loadStacks(ProjectMap $map): array
    {
        try {
            return $map->loadTileset()?->stacks ?? [];
        } catch (InvalidArgumentException | RuntimeException $error) {
            throw new MapSourceRefusal('The map\'s tileset cannot be read, so its stacked objects cannot be moved: ' . $error->getMessage()
                . ' Nothing was changed.', previous: $error);
        }
    }

    /** @return array<string, list<list<string>>> The stack layers the map has, over the rectangle. */
    private static function readEntries(ProjectMap $map, TilesetStack $stack, int $x, int $y, int $width, int $height): array
    {
        return $map->readTileEntries(array_values(array_intersect($stack->layers, $map->getTileLayerNames())), $x, $y, $width, $height);
    }

    /**
     * A cell's tiles on a stack's layers, bottom to top, empty ones left out.
     *
     * @param array<string, list<list<string>>> $entries
     * @return list<int>
     * @throws MapSourceRefusal When an entry is not a plain tile.
     */
    private static function readCell(array $entries, TilesetStack $stack, int $column, int $row, int $x, int $y): array
    {
        $tiles = [];
        foreach ($stack->layers as $tileLayer) {
            $entry = (string) ($entries[$tileLayer][$row][$column] ?? TileId::EMPTY);
            if (! ctype_digit($entry)) {
                throw new MapSourceRefusal(sprintf('Tile layer %s holds %s at (%d, %d), which stack %s cannot order. Nothing was changed.',
                    $tileLayer, $entry, $x, $y, $stack->id));
            }
            if ((int) $entry !== TileId::EMPTY) {
                $tiles[] = (int) $entry;
            }
        }

        return $tiles;
    }
}
