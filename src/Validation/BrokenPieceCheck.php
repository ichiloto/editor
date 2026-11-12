<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/**
 * Warns where a map shows part of a tileset piece without the rest of it: one
 * cell of a two-cell table beside a chair draws as half a table, and nothing
 * else would notice, because a tile on its own is a legal tile.
 *
 * Which tiles belong together is the tileset's own data. A tile is judged only
 * when every piece drawing it spans several cells: a one-cell piece makes the
 * tile legal alone. It is fine when any of those pieces stands whole around
 * it, on whatever tile layers, so repeated copies and pieces laid on another
 * layer are whole. Connected pieces join by neighbour rather than arrangement,
 * ground sheets tile as surfaces, and a tileset's stacks overlap their pieces
 * on purpose, so none of those is judged.
 *
 * The game plays the same, so this never fails validation.
 */
final class BrokenPieceCheck
{
    public const string CODE = 'map.broken-piece';
    /** How many cells a warning names before summing up the rest. */
    private const int CELLS_NAMED = 3;

    private function __construct()
    {
    }

    /** @return list<Issue> One warning for each set of pieces left incomplete. */
    public static function validate(ProjectMap $map): array
    {
        $graphics = MapGraphicsValidator::readGraphics($map);
        if ($graphics === null) {
            return []; // The checks that read the map's graphics say why they cannot be read.
        }
        [$parts, $names] = self::readParts($graphics->tileset->getPlacements($map->isPaintedInKinds()));
        if ($parts === []) {
            return [];
        }

        $shown = [];
        $stacked = $graphics->tileset->getStackLayers();
        foreach ($graphics->layers as $layer) {
            if (in_array($layer->name, $stacked, true)) {
                continue;
            }
            for ($y = 0; $y < $layer->height; $y++) {
                foreach ($layer->getRowTiles($y) as $x => $tile) {
                    if ($tile !== TileId::EMPTY) {
                        $shown[$y][$x][$tile] = true;
                    }
                }
            }
        }

        $broken = [];
        foreach ($shown as $y => $row) {
            foreach ($row as $x => $tiles) {
                foreach (array_keys($tiles) as $tile) {
                    if (isset($parts[$tile]) && ! self::standsWhole($parts[$tile], $shown, $x, $y)) {
                        $broken[implode(' or ', array_unique(array_map(static fn(array $part): string => $names[$part['piece']->id], $parts[$tile])))][] = [$x, $y];
                    }
                }
            }
        }

        $issues = [];
        foreach ($broken as $pieces => $cells) {
            $named = array_map(static fn(array $cell): string => "({$cell[0]}, {$cell[1]})", array_slice($cells, 0, self::CELLS_NAMED));
            $rest = count($cells) - count($named);
            $issues[] = new Issue(Severity::WARNING, $map->mapId,
                sprintf('Part of %s stands at %s%s without the rest of the piece, so it draws cut off.', $pieces, implode(', ', $named),
                    $rest > 0 ? " and {$rest} more" : ''),
                'Place the whole piece, or make a piece for the size that fits and place that.', self::CODE, $map->mapId, $cells);
        }

        return $issues;
    }

    /**
     * Each tile that belongs only to pieces of several cells, with where it sits in each.
     *
     * @param array<string, TilesetPiece> $pieces
     * @return array{0: array<int, list<array{piece: TilesetPiece, dx: int, dy: int, cells: list<array{0: int, 1: int, 2: int}>}>>, 1: array<string, string>}
     */
    private static function readParts(array $pieces): array
    {
        $parts = [];
        $alone = [];
        $names = [];
        foreach ($pieces as $piece) {
            if ($piece->connects !== null) {
                continue;
            }
            $cells = [];
            foreach ($piece->tiles as $rows) {
                foreach ($rows as $dy => $entries) {
                    foreach ($entries as $dx => $entry) {
                        if (self::isItemTile((int) $entry)) {
                            $cells[] = [(int) $dx, (int) $dy, (int) $entry];
                        }
                    }
                }
            }
            if (count($cells) === 1) {
                $alone[$cells[0][2]] = true;
            } elseif ($cells !== []) {
                $names[$piece->id] = $piece->name;
                foreach ($cells as [$dx, $dy, $tile]) {
                    $parts[$tile][] = ['piece' => $piece, 'dx' => $dx, 'dy' => $dy, 'cells' => $cells];
                }
            }
        }

        return [array_diff_key($parts, $alone), $names];
    }

    private static function isItemTile(int $tile): bool
    {
        return $tile !== TileId::EMPTY && $tile < TileId::A5;
    }

    /**
     * @param list<array{piece: TilesetPiece, dx: int, dy: int, cells: list<array{0: int, 1: int, 2: int}>}> $parts
     * @param array<int, array<int, array<int, true>>> $shown Tiles on each cell, by row and column.
     */
    private static function standsWhole(array $parts, array $shown, int $x, int $y): bool
    {
        foreach ($parts as $part) {
            [$left, $top] = [$x - $part['dx'], $y - $part['dy']];
            if (array_all($part['cells'], static fn(array $cell): bool => isset($shown[$top + $cell[1]][$left + $cell[0]][$cell[2]]))) {
                return true;
            }
        }

        return false;
    }
}
