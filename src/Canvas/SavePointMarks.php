<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Closure;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\CommandGroup;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use InvalidArgumentException;

/**
 * Keeps a stamped save point saving on a map that declares its collision.
 *
 * Declared occupancy is the map's only collision, so a save point's glyph
 * says nothing to the game there. A save point is a behaviour the author asked
 * for by placing the piece, not a physical footprint, so the piece carries it
 * as it would on a map whose glyphs give collision. Which glyphs mark a save
 * point is the project's collision dictionary's to say. A map without declared
 * occupancy reads the glyph itself and needs nothing here.
 */
final class SavePointMarks
{
    private function __construct()
    {
    }

    /**
     * Gives the stamped copies' save point cells their collision, in one undo
     * step with the stamp.
     *
     * @param Command|null $stamp The applied stamp ({@see PiecePlacer::stampOrigins()}).
     * @param list<array{x: int, y: int}> $origins
     * @return Command|null The stamp with the collision, or the stamp alone when none changed.
     * @throws MapSourceRefusal When a cell has no collision to write; the stamp is taken back first.
     */
    public static function addCollision(ProjectMap $map, TilesetPiece $piece, ?Command $stamp, array $origins, string $label): ?Command
    {
        if ($origins === [] || ! $map->hasMapDataField([MapPhysicalOccupancy::DATA_KEY])) {
            return $stamp;
        }
        $pieceCells = self::listPieceCells($map, $piece);
        $writes = [];
        foreach ($origins as ['x' => $x, 'y' => $y]) {
            foreach ($pieceCells as [$column, $row]) {
                $writes[] = [$x + $column, $y + $row, CollisionType::SAVE_POINT];
            }
        }
        if ($writes === []) {
            return $stamp;
        }
        try {
            $collision = self::writeCollision($map, $writes, 'Mark save point');
        } catch (MapSourceRefusal $refusal) {
            $stamp?->undo();
            throw $refusal;
        }
        $commands = array_values(array_filter([$stamp, $collision]));

        return $commands === [] ? null : new CommandGroup($label, $commands);
    }

    /**
     * The cells of a stamped copy that hold a save point's collision and are
     * losing their mark, read before the mark is erased.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function listMarkedCells(ProjectMap $map, TilesetPiece $piece, int $x, int $y): array
    {
        if (! $map->hasMapDataField([MapPhysicalOccupancy::DATA_KEY])) {
            return [];
        }
        $layer = PiecePlacer::findLayer($map, $piece);
        $rows = $map->getResolvedCollisionMap();
        $cells = [];
        foreach (self::listPieceCells($map, $piece) as [$column, $row]) {
            [$cellX, $cellY] = [$x + $column, $y + $row];
            if (($rows[$cellY][$cellX] ?? null) === CollisionType::SAVE_POINT->value && $map->hasLayerCell($layer, $cellX, $cellY)
                && TerminalText::stripAnsi($map->getLayerSymbol($layer, $cellX, $cellY)) === $piece->glyphs[$row][$column]) {
                $cells[] = [$cellX, $cellY];
            }
        }

        return $cells;
    }

    /**
     * Gives cells back to what the terminal layers now say there
     * ({@see ProjectMap::resolveGlyphCollision()}).
     *
     * @param list<array{0: int, 1: int}> $cells
     * @return array{command: ?Command, cells: int} The unrecorded step, and how many cells changed.
     * @throws MapSourceRefusal When the layers' collision cannot be resolved.
     */
    public static function clearCollision(ProjectMap $map, array $cells): array
    {
        if ($cells === []) {
            return ['command' => null, 'cells' => 0];
        }
        try {
            $writes = $map->resolveGlyphCollision($cells);
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal($error->getMessage(), previous: $error);
        }
        $before = $map->getResolvedCollisionMap();
        $command = self::writeCollision($map, $writes, 'Clear save point');
        $changed = count(array_filter($writes, static fn(array $write): bool => $before[$write[1]][$write[0]] !== $write[2]->value));

        return ['command' => $command, 'cells' => $changed];
    }

    /**
     * Where a piece marks a save point: each [column, row] whose glyph the
     * project's collision dictionary reads as one on the piece's layer.
     *
     * @param (Closure(string, string): CollisionType)|null $resolveGlyph
     * @return list<array{0: int, 1: int}>
     */
    public static function listPieceCells(ProjectMap $map, TilesetPiece $piece, ?Closure $resolveGlyph = null): array
    {
        if ($piece->connects !== null) {
            return [];
        }
        $resolveGlyph ??= $map->createGlyphCollisionResolver();
        $cells = [];
        foreach ($piece->glyphs as $row => $symbols) {
            foreach ($symbols as $column => $symbol) {
                if ($symbol !== ' ' && $resolveGlyph($piece->layer, $symbol) === CollisionType::SAVE_POINT) {
                    $cells[] = [$column, $row];
                }
            }
        }

        return $cells;
    }

    /** @param list<array{0: int, 1: int, 2: CollisionType}> $writes */
    private static function writeCollision(ProjectMap $map, array $writes, string $label): ?Command
    {
        $path = [MapPhysicalOccupancy::DATA_KEY];
        $before = $map->getMapDataField($path);
        if ($map->writePhysicalOccupancy($writes) === 0) {
            return null;
        }
        $after = $map->getMapDataField($path);

        return new GenericCommand($label, static fn() => $map->setMapDataField($path, $after),
            static fn() => $map->setMapDataField($path, $before));
    }
}
