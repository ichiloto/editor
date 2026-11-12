<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Closure;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
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
 *
 * Placing art alone is refused unless it fits ({@see assertFits()}), and a
 * copy of a piece's art found on a map stands as a graphical placement while
 * it still fits ({@see findMisfits()}), so authoring and validation hold art
 * to the one rule.
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
        $misfits = self::findMisfits($map, $piece, $origins);
        if ($misfits !== []) {
            throw new MapSourceRefusal($misfits[array_key_first($misfits)]);
        }
    }

    /**
     * Why each copy's art does not fit, by the rule of {@see assertFits()},
     * changing nothing: one reason for each copy that does not, keyed by its
     * place among the origins. Empty when every copy fits.
     *
     * @param list<array{x: int, y: int}> $origins Each copy's top-left, overhang included ({@see PiecePlacer::getOriginAt()}).
     * @return array<int, string>
     * @throws MapSourceRefusal When the map's tileset cannot be read.
     */
    public static function findMisfits(ProjectMap $map, TilesetPiece $piece, array $origins): array
    {
        if ($piece->connects !== null) {
            return array_fill_keys(array_keys($origins),
                sprintf('%s is drawn as connected cells; its art cannot be laid alone. Nothing was changed.', $piece->name));
        }
        if (! self::hasTiles($piece)) {
            return array_fill_keys(array_keys($origins), sprintf('%s has no tiles to lay. Nothing was changed.', $piece->name));
        }
        $collision = $map->getResolvedCollisionMap();
        try {
            $tileset = $map->loadTileset();
        } catch (\Throwable $error) {
            throw new MapSourceRefusal('Pieces are unavailable: ' . $error->getMessage(), previous: $error);
        }
        $resolveGlyph = $map->createGlyphCollisionResolver();
        $misfits = [];
        foreach ($origins as $index => $origin) {
            $misfit = self::findMisfit($piece, $origin, $collision, $tileset, $resolveGlyph);
            if ($misfit !== null) {
                $misfits[$index] = $misfit;
            }
        }

        return $misfits;
    }

    /**
     * Why one copy's art does not fit, or null when it does.
     *
     * @param array{x: int, y: int} $origin
     * @param array<int, array<int, int>> $collision The map's resolved collision, by row and column.
     * @param Closure(string, string): CollisionType $resolveGlyph
     */
    private static function findMisfit(TilesetPiece $piece, array $origin, array $collision, ?Tileset $tileset, Closure $resolveGlyph): ?string
    {
        ['x' => $left, 'y' => $top] = $origin;
        for ($row = 0; $row < $piece->height; $row++) {
            for ($column = 0; $column < $piece->width; $column++) {
                [$x, $y] = [$left + $column, $top + $row];
                $type = $collision[$y][$x] ?? null;
                if ($type === null) {
                    return sprintf('%s (%d x %d) does not fit at (%d, %d): the map has no cell at (%d, %d). Nothing was changed.',
                        $piece->name, $piece->width, $piece->height, $left, $top + $piece->overhang, $x, $y);
                }
                $current = CollisionType::from($type);
                if ($row < $piece->overhang) {
                    $drawnBelow = array_any($piece->tiles, static fn(array $rows): bool => ($tile = (int) $rows[$row][$column]) !== 0
                        && ! ($tileset?->isAbove($tile) ?? false));
                    if ($drawnBelow && ! $current->blocksMovement()) {
                        return sprintf('%s at (%d, %d) would rise over walkable floor at (%d, %d); its art needs a wall or other blocking cell behind it. Nothing was changed.',
                            $piece->name, $left, $top + $piece->overhang, $x, $y);
                    }
                    continue;
                }
                $expected = self::findGroundCollision($piece, $row, $column, $resolveGlyph);
                if ($expected !== null && $expected !== $current) {
                    return sprintf('%s at (%d, %d) needs %s collision at (%d, %d), where the map has %s; place the piece whole to change its footprint. Nothing was changed.',
                        $piece->name, $left, $top + $piece->overhang, self::describe($expected), $x, $y, self::describe($current));
                }
            }
        }

        return null;
    }

    /**
     * The collision a ground cell of the piece gives, or null when the piece leaves that cell's collision alone.
     *
     * @param Closure(string, string): CollisionType $resolveGlyph
     */
    private static function findGroundCollision(TilesetPiece $piece, int $row, int $column, Closure $resolveGlyph): ?CollisionType
    {
        if ($piece->occupancy !== null) {
            return $piece->occupancy[$row][$column];
        }
        $glyph = $piece->glyphs[$row][$column];
        if ($glyph === ' ') {
            return null;
        }
        $type = $resolveGlyph($piece->layer, $glyph);

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
