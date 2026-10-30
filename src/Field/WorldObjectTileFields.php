<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use InvalidArgumentException;

/**
 * A world object's map-owned graphical tiles (`tiles2d`, the Engine's
 * {@see \Ichiloto\Engine\Field\WorldObjectTiles}): on a named tile layer of the
 * map, the tile identity each cell the object owns shows while the object is
 * available, before neighbour shaping and shadows, so a concealed wall reads
 * as the wall beside it. Its source shape, `layer => list of {x, y, tile}`, is
 * edited as one list of rows, each naming its layer, and written back in the
 * same order, so an unchanged declaration keeps its bytes. The Engine decides
 * what a declaration may hold; nothing here repeats its rules.
 */
final class WorldObjectTileFields
{
    /** The key a world object declares its tiles under. */
    public const string DATA_KEY = 'tiles2d';

    /**
     * A row's fields: the tile layer, chosen among the map's own, the cell, and the tile identity from the map's
     * tileset, `0` showing none.
     *
     * @return list<RecordField>
     */
    public static function getFields(ProjectMap $map): array
    {
        return [
            new RecordField('layer', 'Tile Layer', options: $map->getTileLayerNames()),
            new RecordField('x', 'X', InputControlType::INTEGER),
            new RecordField('y', 'Y', InputControlType::INTEGER),
            new RecordField('tile', 'Tile (0 shows none)', InputControlType::INTEGER),
        ];
    }

    /** An entry as its record shows it: its tiles as one list of rows. A declaration of another shape is left as written. */
    public static function readEntry(array $entry): array
    {
        $declared = $entry[self::DATA_KEY] ?? null;
        if (!is_array($declared) || ($declared !== [] && array_is_list($declared))) { return $entry; }
        $rows = [];
        foreach ($declared as $layer => $cells) {
            if (!is_array($cells) || !array_is_list($cells)) { return $entry; }
            foreach ($cells as $cell) {
                if (!is_array($cell)) { return $entry; }
                $rows[] = ['layer' => (string) $layer, ...$cell];
            }
        }
        $entry[self::DATA_KEY] = $rows;
        return $entry;
    }

    /** An entry as the map stores it: its rows grouped by layer again, in the order the rows hold them. */
    public static function writeEntry(array $entry): array
    {
        $rows = $entry[self::DATA_KEY] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) { return $entry; }
        $declared = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists('layer', $row)) { return $entry; }
            $layer = (string) $row['layer'];
            unset($row['layer']);
            $declared[$layer][] = $row;
        }
        if ($declared === []) { unset($entry[self::DATA_KEY]); }
        else { $entry[self::DATA_KEY] = $declared; }
        return $entry;
    }

    /**
     * Sets cells of one layer, each `[x, y, tile]`, changing a cell already declared in place and adding the rest.
     *
     * @param list<mixed> $cells
     * @throws InvalidArgumentException When the layer is not one of the map's, or a cell is not three integers.
     */
    public static function setTiles(ProjectMap $map, array $entry, string $layer, array $cells): array
    {
        self::assertLayer($map, $layer);
        if ($cells === []) { throw new InvalidArgumentException('Name at least one cell and its tile.'); }
        $declared = $entry[self::DATA_KEY] ?? [];
        if (!is_array($declared)) { throw new InvalidArgumentException('World object tiles2d is not a map of tile layers; it cannot be edited.'); }
        $list = $declared[$layer] ?? [];
        foreach ($cells as $cell) {
            if (!is_array($cell) || !array_is_list($cell) || count($cell) !== 3 || array_any($cell, static fn($value): bool => !is_int($value))) {
                throw new InvalidArgumentException('Each tile is [x, y, tile] in whole numbers.');
            }
            [$x, $y, $tile] = $cell;
            $at = array_find_key($list, static fn($row): bool => is_array($row) && ($row['x'] ?? null) === $x && ($row['y'] ?? null) === $y);
            if ($at === null) { $list[] = ['x' => $x, 'y' => $y, 'tile' => $tile]; }
            else { $list[$at]['tile'] = $tile; }
        }
        $declared[$layer] = $list;
        $entry[self::DATA_KEY] = $declared;
        return $entry;
    }

    /**
     * Removes cells of one layer, each `[x, y]`; a layer left without cells, and tiles left without layers, go.
     *
     * @param list<mixed> $cells
     * @throws InvalidArgumentException When a cell is not declared on that layer.
     */
    public static function unsetTiles(array $entry, string $layer, array $cells): array
    {
        $list = $entry[self::DATA_KEY][$layer] ?? null;
        if (!is_array($list)) { throw new InvalidArgumentException(sprintf('The object presents no tiles on the %s layer.', $layer)); }
        if ($cells === []) { throw new InvalidArgumentException('Name at least one cell.'); }
        foreach ($cells as $cell) {
            if (!is_array($cell) || !array_is_list($cell) || count($cell) !== 2 || !is_int($cell[0]) || !is_int($cell[1])) {
                throw new InvalidArgumentException('Each cell is [x, y] in whole numbers.');
            }
            $at = array_find_key($list, static fn($row): bool => is_array($row) && ($row['x'] ?? null) === $cell[0] && ($row['y'] ?? null) === $cell[1]);
            if ($at === null) {
                throw new InvalidArgumentException(sprintf('The object presents no tile at %d, %d on the %s layer.', $cell[0], $cell[1], $layer));
            }
            unset($list[$at]);
        }
        if ($list === []) { unset($entry[self::DATA_KEY][$layer]); }
        else { $entry[self::DATA_KEY][$layer] = array_values($list); }
        if ($entry[self::DATA_KEY] === []) { unset($entry[self::DATA_KEY]); }
        return $entry;
    }

    /** An entry whose tiles stand a step away, with the cells the object owns. */
    public static function moveTiles(array $entry, int $dx, int $dy): array
    {
        if (!is_array($entry[self::DATA_KEY] ?? null)) { return $entry; }
        foreach ($entry[self::DATA_KEY] as $layer => $cells) {
            foreach ((array) $cells as $at => $cell) {
                if (is_int($cell['x'] ?? null) && is_int($cell['y'] ?? null)) {
                    $entry[self::DATA_KEY][$layer][$at]['x'] = $cell['x'] + $dx;
                    $entry[self::DATA_KEY][$layer][$at]['y'] = $cell['y'] + $dy;
                }
            }
        }
        return $entry;
    }

    /**
     * An object of its own art keeps a presentation when its last tile goes: its explicit absence of art, as a new
     * object starts with, since the Engine requires one or the other.
     */
    public static function keepPresentation(array $entry): array
    {
        if (!array_key_exists('resource', $entry) && !array_key_exists('sprites2d', $entry) && ($entry[self::DATA_KEY] ?? []) === []) {
            $entry['sprites2d'] = null;
        }
        return $entry;
    }

    /**
     * Where a new row starts: the first of the map's tile layers and the object's owned cells (its covered cells, or
     * its anchor) that no object presents a tile on yet, showing the tile the layer has there, so adding a row changes
     * nothing until the author chooses its tile.
     *
     * @param list<array<string, mixed>> $entries
     * @return array{0: string, 1: int, 2: int, 3: int}
     * @throws InvalidArgumentException When the map has no tile layer, or every owned cell of every layer is taken.
     */
    public static function findUnclaimedTile(ProjectMap $map, array $entries, int $index): array
    {
        $layers = $map->getTileLayerNames();
        if ($layers === []) { throw new InvalidArgumentException('The map has no tile layer to present tiles on.'); }
        $entry = $entries[$index];
        $owned = isset($entry['covers']['cells'])
            ? array_map(static fn($cell): array => [(int) $cell[0], (int) $cell[1]], $entry['covers']['cells'])
            : [[(int) ($entry['anchor']['x'] ?? 0), (int) ($entry['anchor']['y'] ?? 0)]];
        foreach ($layers as $layer) {
            foreach ($owned as [$x, $y]) {
                $taken = array_any($entries, static fn($other): bool => array_any((array) ($other[self::DATA_KEY][$layer] ?? []),
                    static fn($cell): bool => is_array($cell) && ($cell['x'] ?? null) === $x && ($cell['y'] ?? null) === $y));
                if ($taken) { continue; }
                $shown = (int) ($map->readTileEntries([$layer], $x, $y, 1, 1)[$layer][0][0] ?? TileId::EMPTY);
                return [$layer, $x, $y, TileId::isValid($shown) ? $shown : TileId::EMPTY];
            }
        }
        throw new InvalidArgumentException('Every cell the object owns already presents a tile on every tile layer.');
    }

    private static function assertLayer(ProjectMap $map, string $layer): void
    {
        if (!in_array($layer, $map->getTileLayerNames(), true)) {
            throw new InvalidArgumentException(sprintf('The map has no tile layer %s; choose one of: %s.', $layer,
                implode(', ', $map->getTileLayerNames()) ?: 'none'));
        }
    }
}
