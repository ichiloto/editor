<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/**
 * The part one glyph cell plays in a tileset piece, such as the left cell of
 * a dining table: the tiles that cell draws and the tiles of the piece's
 * blank cells nearest it, such as a sofa back over its seat. Tiles follow a
 * glyph through its role.
 */
final readonly class PieceRole
{
    /** Stable within the tileset: the piece id and the cell or shape. */
    public string $key;

    /**
     * @param string $pieceId The piece the role belongs to.
     * @param string $label What an author calls it, such as `Dining table (left)`.
     * @param array<string, list<array{dx: int, dy: int, entry: string}>> $tiles Tile entries relative to the glyph cell, keyed by tile layer name; never `0`.
     * @param array{int, int}|null $cell An item piece's cell, as row and column; null for a connected piece's shape.
     * @param array<string, list<array{dx: int, dy: int}>> $keeps Cells whose existing tiles stay, keyed by tile layer; these are not owned tiles to remove.
     */
    public function __construct(public string $pieceId, string $part, public string $label, public array $tiles, public ?array $cell = null,
        public array $keeps = [])
    {
        $this->key = "{$pieceId}:{$part}";
    }

    /**
     * The roles a piece's glyphs play, keyed by glyph. A connected piece has
     * one per shape; an item piece one per glyph cell, each blank cell with
     * tiles going to the glyph cell nearest it (the first in reading order
     * on a tie). A piece with no glyphs, such as a rug, has none.
     *
     * @param list<string> $excludedLayers Tile layers left out of every role.
     * @return array<string, list<self>>
     */
    public static function readPiece(TilesetPiece $piece, array $excludedLayers = []): array
    {
        $excluded = array_flip($excludedLayers);
        if ($piece->connects !== null) {
            $roles = [];
            foreach ($piece->shapes as $shape => $glyph) {
                $tiles = [];
                foreach (array_diff_key($piece->shapeTiles, $excluded) as $layer => $entries) {
                    if ($entries[$shape] !== (string) TileId::EMPTY) {
                        $tiles[$layer] = [['dx' => 0, 'dy' => 0, 'entry' => $entries[$shape]]];
                    }
                }
                $roles[$glyph][] = new self($piece->id, $shape, $piece->name, $tiles);
            }

            return $roles;
        }
        $glyphCells = $blankCells = [];
        foreach ($piece->glyphs as $row => $symbols) {
            foreach ($symbols as $column => $symbol) {
                if ($symbol === ' ') {
                    $blankCells[] = [$row, $column];
                } else {
                    $glyphCells[] = [$row, $column];
                }
            }
        }
        if ($glyphCells === []) {
            return [];
        }
        $attached = [];
        foreach ($blankCells as [$row, $column]) {
            $nearest = null;
            foreach ($glyphCells as $index => [$glyphRow, $glyphColumn]) {
                $distance = abs($glyphRow - $row) + abs($glyphColumn - $column);
                if ($nearest === null || $distance < $nearest[1]) {
                    $nearest = [$index, $distance];
                }
            }
            $attached[$nearest[0]][] = [$row, $column];
        }
        $roles = [];
        foreach ($glyphCells as $index => [$row, $column]) {
            $tiles = [];
            $ownedCells = [[$row, $column], ...($attached[$index] ?? [])];
            foreach (array_diff_key($piece->tiles, $excluded) as $layer => $entries) {
                foreach ($ownedCells as [$cellRow, $cellColumn]) {
                    if ($entries[$cellRow][$cellColumn] !== (string) TileId::EMPTY) {
                        $tiles[$layer][] = ['dx' => $cellColumn - $column, 'dy' => $cellRow - $row, 'entry' => $entries[$cellRow][$cellColumn]];
                    }
                }
            }
            $keeps = [];
            foreach (array_diff($piece->keeps, $excludedLayers) as $layer) {
                foreach ($ownedCells as [$cellRow, $cellColumn]) {
                    $keeps[$layer][] = ['dx' => $cellColumn - $column, 'dy' => $cellRow - $row];
                }
            }
            $roles[$piece->glyphs[$row][$column]][] = new self($piece->id, "{$row}:{$column}",
                self::describeCell($piece->name, $glyphCells, $row, $column), $tiles, [$row, $column], $keeps);
        }

        return $roles;
    }

    /** The tile entries of this role at the glyph cell itself, keyed by tile layer name. */
    public function getOwnEntries(): array
    {
        $own = [];
        foreach ($this->tiles as $layer => $cells) {
            foreach ($cells as $cell) {
                if ($cell['dx'] === 0 && $cell['dy'] === 0) {
                    $own[$layer] = $cell['entry'];
                }
            }
        }

        return $own;
    }

    /**
     * A piece's name, with the cell's place among the piece's glyph cells
     * when it has more than one, such as `Large Bed (top left)`.
     *
     * @param list<array{int, int}> $glyphCells The piece's glyph cells, as row and column.
     */
    private static function describeCell(string $name, array $glyphCells, int $row, int $column): string
    {
        if (count($glyphCells) === 1) {
            return $name;
        }
        $place = static function (int $index, array $indexes, string $first, string $last): ?string {
            [$low, $high] = [min($indexes), max($indexes)];
            $size = $high - $low + 1;
            return match (true) {
                $size === 1 => null,
                $index === $low => $first,
                $index === $high => $last,
                $size === 3 => 'middle',
                default => sprintf('%d of %d', $index - $low + 1, $size),
            };
        };
        $vertical = $place($row, array_column($glyphCells, 0), 'top', 'bottom');
        $horizontal = $place($column, array_column($glyphCells, 1), 'left', 'right');

        return sprintf('%s (%s)', $name, implode(' ', array_filter([$vertical, $horizontal])));
    }
}
