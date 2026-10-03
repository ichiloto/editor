<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;

/**
 * The tiles an author picks from, laid out as RPG Maker MZ's tile palette:
 * eight columns per tab, the A tab holding one entry per autotile kind of
 * A1 to A4 and then A5 tile by tile, and B to E tile by tile in identity
 * order. Only sheets the tileset names appear. B's first tile is the empty
 * tile, which erases.
 */
final class TilePalette
{
    public const int COLUMNS = 8;
    private const string LAYER = 'palette';

    /**
     * @return list<array{name: string, ids: list<list<int>>}> Tabs in RPG Maker order, each a grid of tile identities.
     */
    public static function getTabs(Tileset $tileset): array
    {
        $tabs = [];
        foreach (TilesetSheet::cases() as $sheet) {
            if (! isset($tileset->sheets[$sheet->value])) {
                continue;
            }
            $tab = str_starts_with($sheet->value, 'A') ? 'A' : $sheet->value;
            foreach (array_chunk(self::getSheetIds($sheet), self::COLUMNS) as $row) {
                $tabs[$tab][] = $row;
            }
        }
        $ordered = [];
        foreach ($tabs as $name => $ids) {
            $ordered[] = ['name' => (string) $name, 'ids' => $ids];
        }

        return $ordered;
    }

    /**
     * A tab drawn as the game draws tiles: a world of the tab's grid, one
     * tile per cell, with blank glyphs.
     *
     * @param list<list<int>> $ids
     */
    public static function buildWorld(Tileset $tileset, array $ids, string $assetRoot, string $id): PresentationWorld
    {
        $rows = array_map(static fn(array $row): array => array_pad($row, self::COLUMNS, TileId::EMPTY), $ids);
        $layers = new MapLayerSet([new MapLayer(self::LAYER, 1, false, self::LAYER,
            implode("\n", array_fill(0, count($rows), str_repeat(' ', self::COLUMNS))))]);
        $tiles = new MapTileLayer(self::LAYER, 1, self::LAYER,
            implode("\n", array_map(static fn(array $row): string => implode(' ', $row), $rows)));

        return PresentationWorld::getFromLayers($layers, $id, new MapGraphics($tileset, [$tiles]), $assetRoot);
    }

    /** @return list<int> */
    private static function getSheetIds(TilesetSheet $sheet): array
    {
        [$first, $count, $step] = match ($sheet) {
            TilesetSheet::A1 => [TileId::A1, 16, TileId::SHAPES],
            TilesetSheet::A2 => [TileId::A2, 32, TileId::SHAPES],
            TilesetSheet::A3 => [TileId::A3, 32, TileId::SHAPES],
            TilesetSheet::A4 => [TileId::A4, 48, TileId::SHAPES],
            TilesetSheet::A5 => [TileId::A5, 128, 1],
            TilesetSheet::B => [TileId::B, 256, 1],
            TilesetSheet::C => [TileId::C, 256, 1],
            TilesetSheet::D => [TileId::D, 256, 1],
            TilesetSheet::E => [TileId::E, 256, 1],
        };

        return array_map(static fn(int $index): int => $first + $index * $step, range(0, $count - 1));
    }
}
