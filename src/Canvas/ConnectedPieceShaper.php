<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Closure;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/**
 * Which cells a draw or erase of a connected piece (a wall, a fence)
 * changes, and the shape each one takes. Like {@see ToolGeometry} it only
 * answers the question and never touches a map.
 *
 * A cell belongs to the piece when its glyph is one of the piece's shape
 * glyphs. Drawn cells join the piece and erased cells leave it; then every
 * drawn cell and every member beside a drawn or erased cell takes the shape
 * its member neighbours give it, so a wall that meets another turns the
 * meeting cell into a corner and an erased cell reshapes the cells it left.
 */
final class ConnectedPieceShaper
{
    /** @var array<string, array{int, int}> Neighbour offsets, in the order {@see TilesetPiece::getLineShape()} takes them. */
    private const array NEIGHBOURS = ['north' => [0, -1], 'east' => [1, 0], 'south' => [0, 1], 'west' => [-1, 0]];

    private function __construct()
    {
    }

    /**
     * @param list<array{x: int, y: int}> $drawn The cells that join the piece.
     * @param list<array{x: int, y: int}> $erased The cells that leave it.
     * @param Closure(int, int): bool $isMemberCell Whether a cell belongs to the piece before the change; false beyond the map.
     * @return list<array{x: int, y: int, shape: string|null}> Each cell to write with the shape it takes, or null for an erased
     *     cell: the drawn cells first, in order, then the erased cells, then the neighbours they reshape.
     */
    public static function reshapeCells(TilesetPiece $piece, array $drawn, array $erased, Closure $isMemberCell): array
    {
        $overrides = [];
        foreach ($erased as $cell) {
            $overrides[self::getCellKey($cell['x'], $cell['y'])] = false;
        }
        foreach ($drawn as $cell) {
            $overrides[self::getCellKey($cell['x'], $cell['y'])] = true;
        }
        $isMember = static fn(int $x, int $y): bool => $overrides[self::getCellKey($x, $y)] ?? $isMemberCell($x, $y);

        $cells = [];
        foreach ([...$drawn, ...$erased] as $cell) {
            $cells[self::getCellKey($cell['x'], $cell['y'])] ??= ['x' => $cell['x'], 'y' => $cell['y']];
        }
        foreach ([...$drawn, ...$erased] as $cell) {
            foreach (self::NEIGHBOURS as [$deltaX, $deltaY]) {
                $x = $cell['x'] + $deltaX;
                $y = $cell['y'] + $deltaY;
                if (! isset($cells[self::getCellKey($x, $y)]) && $isMember($x, $y)) {
                    $cells[self::getCellKey($x, $y)] = ['x' => $x, 'y' => $y];
                }
            }
        }

        return array_values(array_map(static function (array $cell) use ($piece, $isMember): array {
            if (! $isMember($cell['x'], $cell['y'])) {
                return $cell + ['shape' => null];
            }
            $joined = array_map(static fn(array $offset): bool => $isMember($cell['x'] + $offset[0], $cell['y'] + $offset[1]), self::NEIGHBOURS);

            return $cell + ['shape' => $piece->getLineShape(...$joined)];
        }, $cells));
    }

    private static function getCellKey(int $x, int $y): string
    {
        return "{$x},{$y}";
    }
}
