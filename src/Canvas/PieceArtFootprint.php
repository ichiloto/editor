<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/**
 * Whether a piece's art may be laid on a map without its glyphs: the art
 * claims a physical footprint, so the map must already have it. Each ground
 * cell the piece makes physical keeps the collision the piece would give it,
 * from its declared occupancy, or, for a piece that declares none, from its
 * glyph through the project's collision dictionary. Each overhang cell the
 * art draws below characters stands over movement-blocking collision, as the
 * Engine's overhang rule requires. Cells the piece leaves alone (a space
 * glyph, a null occupancy) ask for nothing.
 */
final class PieceArtFootprint
{
    private function __construct()
    {
    }

    /**
     * @param list<array{x: int, y: int}> $origins Each copy's top-left, overhang included ({@see PiecePlacer::getOriginAt()}).
     * @throws MapSourceRefusal When a copy falls off the map, a ground cell's collision is not the piece's, or the art
     *     rises over walkable floor; nothing is changed.
     */
    public static function assertFits(ProjectMap $map, TilesetPiece $piece, array $origins): void
    {
        if ($piece->connects !== null) {
            throw new MapSourceRefusal(sprintf('%s is drawn as connected cells; its art cannot be laid alone. Nothing was changed.', $piece->name));
        }
        if (! self::hasTiles($piece)) {
            throw new MapSourceRefusal(sprintf('%s has no tiles to lay. Nothing was changed.', $piece->name));
        }
        $collision = $map->getResolvedCollisionMap();
        try {
            $tileset = $map->loadTileset();
        } catch (\Throwable $error) {
            throw new MapSourceRefusal('Pieces are unavailable: ' . $error->getMessage(), previous: $error);
        }
        foreach ($origins as ['x' => $left, 'y' => $top]) {
            for ($row = 0; $row < $piece->height; $row++) {
                for ($column = 0; $column < $piece->width; $column++) {
                    [$x, $y] = [$left + $column, $top + $row];
                    $type = $collision[$y][$x] ?? null;
                    if ($type === null) {
                        throw new MapSourceRefusal(sprintf('%s (%d x %d) does not fit at (%d, %d): the map has no cell at (%d, %d). Nothing was changed.',
                            $piece->name, $piece->width, $piece->height, $left, $top + $piece->overhang, $x, $y));
                    }
                    $current = CollisionType::from($type);
                    if ($row < $piece->overhang) {
                        $drawnBelow = array_any($piece->tiles, static fn(array $rows): bool => ($tile = (int) $rows[$row][$column]) !== 0
                            && ! ($tileset?->isAbove($tile) ?? false));
                        if ($drawnBelow && ! $current->blocksMovement()) {
                            throw new MapSourceRefusal(sprintf('%s at (%d, %d) would rise over walkable floor at (%d, %d); its art needs a wall or other blocking cell behind it. Nothing was changed.',
                                $piece->name, $left, $top + $piece->overhang, $x, $y));
                        }
                        continue;
                    }
                    $expected = self::findGroundCollision($map, $piece, $row, $column);
                    if ($expected !== null && $expected !== $current) {
                        throw new MapSourceRefusal(sprintf('%s at (%d, %d) needs %s collision at (%d, %d), where the map has %s; place the piece whole to change its footprint. Nothing was changed.',
                            $piece->name, $left, $top + $piece->overhang, self::describe($expected), $x, $y, self::describe($current)));
                    }
                }
            }
        }
    }

    /** The collision a ground cell of the piece gives, or null when the piece leaves that cell's collision alone. */
    private static function findGroundCollision(ProjectMap $map, TilesetPiece $piece, int $row, int $column): ?CollisionType
    {
        if ($piece->occupancy !== null) {
            return $piece->occupancy[$row][$column];
        }
        $glyph = $piece->glyphs[$row][$column];
        if ($glyph === ' ') {
            return null;
        }
        $type = $map->lookUpGlyphCollision($piece->layer, $glyph);

        return $type === CollisionType::PASS_THROUGH ? null : $type;
    }

    private static function hasTiles(TilesetPiece $piece): bool
    {
        return array_any($piece->tiles, static fn(array $rows): bool => array_any($rows,
            static fn(array $entries): bool => array_any($entries, static fn(string $entry): bool => $entry !== '0')));
    }

    private static function describe(CollisionType $type): string
    {
        return strtolower(str_replace('_', ' ', $type->name));
    }
}
