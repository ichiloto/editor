<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Closure;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\CommandGroup;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Field\MapTerrainKinds;
use Ichiloto\Engine\Rendering\Tilesets\TerrainKind;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilesetStack;
use InvalidArgumentException;
use RuntimeException;

/**
 * Paints a map's terrain kinds ({@see TerrainKind}) the one way every
 * interface does. A kind is placed through its one-cell piece
 * ({@see PiecePlacer::stampArea()}), so painting writes its terminal glyph in
 * its authored style and its autotile (with a feature's ground) together,
 * source-preserving and as one undo step. The cell's terrain becomes the
 * kind's alone: what the tile layers its gameplay layer owns held there is
 * cleared first, except the layers the kind keeps.
 *
 * Passage follows from the kind. On a map without declared occupancy the
 * kind's glyph is its collision ({@see MapTerrainKinds}); on a map with
 * declared occupancy the same undo step writes the kind's collision into the
 * cells where its layer is the top gameplay layer, so collision and art come
 * from one decision there too. Cells under a building or object keep theirs.
 *
 * Only a map painted in terrain kinds (`'terrainKinds' => true`) is painted
 * by kind; the conversion ({@see \Ichiloto\Editor\Maps\TerrainKindConversion})
 * is how a map opts in. Refusals are thrown, never shown.
 */
final class TerrainKindPainter
{
    private function __construct()
    {
    }

    /**
     * The kinds a map can be painted with, by id in authored order.
     *
     * @return array<string, TerrainKind>
     * @throws MapSourceRefusal When the map has no tileset, its tileset declares no kinds, or the map is not painted in them.
     */
    public static function loadKinds(ProjectMap $map): array
    {
        $kinds = self::readTilesetKinds($map);
        try {
            $declared = MapTerrainKinds::isDeclared($map->getEditableData());
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal($error->getMessage(), previous: $error);
        }
        if (! $declared) {
            throw new MapSourceRefusal(sprintf('%s is not painted in terrain kinds yet. Convert it first: ichiloto map:convert-kinds %s, '
                . 'mapping its old glyphs to kinds.', $map->mapId, $map->mapId));
        }

        return $kinds;
    }

    /**
     * The kinds a map's tileset declares, whether or not the map is painted in them yet.
     *
     * @return array<string, TerrainKind>
     * @throws MapSourceRefusal When the map has no tileset, it cannot be read, or it declares no kinds.
     */
    public static function readTilesetKinds(ProjectMap $map): array
    {
        try {
            $tileset = $map->loadTileset();
        } catch (InvalidArgumentException | RuntimeException $error) {
            throw new MapSourceRefusal('Terrain kinds are unavailable: ' . $error->getMessage(), previous: $error);
        }
        if ($tileset === null) {
            throw new MapSourceRefusal('This map has no kind yet, so it has no terrain kinds. Set its Kind in the Inspector.');
        }
        if ($tileset->kinds === []) {
            throw new MapSourceRefusal(sprintf('Tileset %s declares no terrain kinds.', $tileset->id));
        }

        return $tileset->kinds;
    }

    /** @throws MapSourceRefusal When the map cannot be painted in kinds, or has no kind with the id. */
    public static function requireKind(ProjectMap $map, string $kindId): TerrainKind
    {
        return self::loadKinds($map)[$kindId]
            ?? throw new MapSourceRefusal(sprintf("%s is no longer one of the map's terrain kinds. Choose a kind again.", $kindId));
    }

    /**
     * Paints a kind over every cell of the area between two corners, as one undo step.
     *
     * @param array{x: int, y: int} $from
     * @param array{x: int, y: int} $to
     * @return array{command: ?Command, cells: int} The applied, unrecorded edit, and the cells painted.
     * @throws MapSourceRefusal When the map is not painted in kinds, the kind's layer is missing or the area falls off the map.
     */
    public static function paintArea(ProjectMap $map, TerrainKind $kind, array $from, array $to, string $label = 'Paint terrain'): array
    {
        $cells = PiecePlacer::getAreaOrigins($kind->piece, $from, $to);

        return ['command' => self::paintCells($map, $kind, $cells, $label), 'cells' => count($cells)];
    }

    /**
     * Paints a kind on the given cells, as one undo step.
     *
     * @param list<array{x: int, y: int}> $cells
     * @return ?Command The applied, unrecorded edit, or null when it changed nothing.
     * @throws MapSourceRefusal When the map is not painted in kinds, the kind's layer is missing or a cell is off the map.
     */
    public static function paintCells(ProjectMap $map, TerrainKind $kind, array $cells, string $label = 'Paint terrain'): ?Command
    {
        self::loadKinds($map);
        PiecePlacer::findLayer($map, $kind->piece);
        $path = [MapPhysicalOccupancy::DATA_KEY];
        $declared = $map->hasMapDataField($path);
        $occupancyBefore = $declared ? $map->getMapDataField($path) : null;
        $cleared = self::clearOwnedTiles($map, $kind, $cells, $label);
        try {
            $paint = PiecePlacer::stampOrigins($map, $kind->piece, $cells, null, $label);
        } catch (MapSourceRefusal $refusal) {
            $cleared?->undo();
            throw $refusal;
        }
        $commands = array_values(array_filter([$cleared, $paint]));
        if ($declared) {
            $writes = [];
            $set = $map->getLayerSet();
            foreach ($cells as ['x' => $x, 'y' => $y]) {
                if (self::isTopLayer($set, $kind->layer, $x, $y)) {
                    $writes[] = [$x, $y, $kind->collision];
                }
            }
            if ($writes !== [] && $map->writePhysicalOccupancy($writes) > 0) {
                $occupancyAfter = $map->getMapDataField($path);
                $commands[] = new GenericCommand($label,
                    static fn() => $map->setMapDataField($path, $occupancyAfter),
                    static fn() => $map->setMapDataField($path, $occupancyBefore),
                );
            }
        }

        return match (count($commands)) {
            0 => null,
            1 => $commands[0],
            default => new CommandGroup($label, $commands),
        };
    }

    /**
     * Clears, at the cells, what the tile layers the kind's gameplay layer
     * owns hold, except the layers the kind keeps: a kind cell's terrain is
     * the kind's alone, whatever stood there before, such as a tree an old
     * glyph drew or a kind's own tiles. Its tiles are painted next.
     *
     * A stack holds whole objects ({@see TilesetStack}) that stand over the
     * terrain, whichever gameplay layer each belongs to, so it is cleared by
     * object, never by cell: an object goes, every tile of it, when the paint
     * covers every cell it shows on, and stays whole when it also shows on a
     * cell the paint leaves, such as a peak overhanging the road beside its
     * range. Painting beside an object never crops it. An object that goes
     * takes its footprint's glyphs with it where they are still its own, so a
     * hill crest painted over leaves no solid cell behind.
     *
     * @param list<array{x: int, y: int}> $cells
     * @return ?Command The applied, unrecorded edit, or null when nothing was there.
     */
    private static function clearOwnedTiles(ProjectMap $map, TerrainKind $kind, array $cells, string $label): ?Command
    {
        try {
            $owners = $map->loadGraphics()?->owners ?? [];
            $tileset = $map->loadTileset();
        } catch (InvalidArgumentException | RuntimeException $error) {
            throw new MapSourceRefusal('The map\'s tile layers cannot be read, so the cells\' old tiles cannot be cleared: ' . $error->getMessage()
                . ' Nothing was changed.', previous: $error);
        }
        $tiles = CanvasEditor::createTileReader($map);
        $painted = [];
        foreach ($cells as ['x' => $x, 'y' => $y]) {
            $painted["{$x},{$y}"] = true;
        }
        $writes = $objects = [];
        foreach ($owners as $tileLayer => $owner) {
            if (in_array($tileLayer, $kind->piece->keeps, true)) {
                continue;
            }
            $stack = $tileset?->findStackHolding((string) $tileLayer);
            if ($owner !== $kind->layer && $stack === null) {
                continue;
            }
            foreach ($cells as ['x' => $x, 'y' => $y]) {
                $entry = $tiles((string) $tileLayer, $x, $y);
                if ($entry === (string) TileId::EMPTY) {
                    continue;
                }
                $object = $stack?->locate((int) $entry);
                // A tile no stacked piece draws is the cell's alone, and goes only from a layer the kind's layer owns.
                if ($object === null && $owner !== $kind->layer) {
                    continue;
                }
                if ($object !== null) {
                    if (self::showsBeyond($stack, $map, $tiles, (int) $entry, $x, $y, $painted)) {
                        continue;
                    }
                    $left = $x - $object['column'];
                    $top = $y - $object['row'];
                    $objects["{$object['piece']->id}@{$left},{$top}"] = [$object['piece'], $left, $top];
                }
                $writes[$tileLayer][] = ['x' => $x, 'y' => $y, 'entry' => (string) TileId::EMPTY];
            }
        }
        if ($writes === []) {
            return null;
        }
        $before = $map->getTileLayerSources();
        $map->writeTileCells($writes);
        $after = $map->getTileLayerSources();
        $commands = [new GenericCommand($label,
            static fn() => $map->restoreTileLayerSources($after),
            static fn() => $map->restoreTileLayerSources($before),
        )];
        foreach (self::findFootprintGlyphs($map, $objects) as $layerId => $erased) {
            [$stroke] = CanvasEditor::writeCells($map, $layerId, $erased, $label);
            if ($stroke->hasChanges()) {
                $commands[] = $stroke;
            }
        }

        return count($commands) === 1 ? $commands[0] : new CommandGroup($label, $commands);
    }

    /**
     * The footprint glyphs of cleared stacked objects that still stand where
     * each put them, as erasing writes, by gameplay layer id. A glyph since
     * replaced by another stays.
     *
     * @param array<string, array{0: \Ichiloto\Engine\Rendering\Tilesets\TilesetPiece, 1: int, 2: int}> $objects
     * @return array<string, list<array{x: int, y: int, symbol: string, color: null}>>
     */
    private static function findFootprintGlyphs(ProjectMap $map, array $objects): array
    {
        $erased = [];
        foreach ($objects as [$piece, $left, $top]) {
            if (! array_any($piece->glyphs, static fn(array $row): bool => array_any($row, static fn(string $glyph): bool => $glyph !== ' '))) {
                continue;
            }
            $layerId = PiecePlacer::findLayer($map, $piece);
            foreach ($piece->glyphs as $row => $symbols) {
                foreach ($symbols as $column => $symbol) {
                    [$x, $y] = [$left + $column, $top + $row];
                    if ($symbol !== ' ' && $map->hasLayerCell($layerId, $x, $y) && $map->getLayerSymbol($layerId, $x, $y) === $symbol) {
                        $erased[$layerId]["{$x},{$y}"] = ['x' => $x, 'y' => $y, 'symbol' => ' ', 'color' => null];
                    }
                }
            }
        }

        return array_map(array_values(...), $erased);
    }

    /**
     * Whether the stacked object a cell's tile draws also shows on a cell
     * outside the paint: one of its other cells still holds its tile there on
     * one of the stack's layers. A tile no stacked piece draws belongs to no
     * object, and is the cell's alone.
     *
     * @param Closure(string, int, int): string $tiles
     * @param array<string, true> $painted
     */
    private static function showsBeyond(TilesetStack $stack, ProjectMap $map, Closure $tiles, int $tileId, int $x, int $y, array $painted): bool
    {
        $cell = $stack->locate($tileId);
        if ($cell === null) {
            return false;
        }
        ['piece' => $piece, 'column' => $column, 'row' => $row] = $cell;
        $layers = array_values(array_intersect($stack->layers, $map->getTileLayerNames()));
        foreach ($piece->tiles[$stack->getBaseLayer()] as $pieceRow => $entries) {
            foreach ($entries as $pieceColumn => $entry) {
                [$cellX, $cellY] = [$x - $column + $pieceColumn, $y - $row + $pieceRow];
                if ((int) $entry === TileId::EMPTY || isset($painted["{$cellX},{$cellY}"])) {
                    continue;
                }
                foreach ($layers as $layer) {
                    if ($tiles($layer, $cellX, $cellY) === (string) $entry) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The kind a cell of the map shows in both presentations, or null: a
     * glyph and tiles of different kinds, or none ({@see MapTerrainKinds::findKindAt()}).
     */
    public static function findKindAt(ProjectMap $map, string $layerId, int $x, int $y): ?TerrainKind
    {
        try {
            $kinds = $map->loadTerrainKinds();
        } catch (InvalidArgumentException) {
            return null;
        }
        $layer = array_find($map->getLayers(), static fn(array $candidate): bool => $candidate['id'] === $layerId);
        if ($kinds === null || $layer === null || ! $map->hasLayerCell($layerId, $x, $y)) {
            return null;
        }
        $tiles = CanvasEditor::createTileReader($map);

        return $kinds->findKindAt($layer['name'], $map->getLayerSymbol($layerId, $x, $y),
            static fn(string $tileLayer): int => (int) $tiles($tileLayer, $x, $y));
    }

    /** Whether no gameplay layer above the named layer holds a glyph at the cell, so the cell's collision is that layer's. */
    public static function isTopLayer(MapLayerSet $set, string $layerName, int $x, int $y): bool
    {
        $above = false;
        foreach ($set->layers as $layer) {
            if ($layer->name === $layerName) {
                $above = true;
            } elseif ($above && ! $layer->decoration && ($layer->getGlyphAt($x, $y) ?? ' ') !== ' ') {
                return false;
            }
        }

        return true;
    }
}
