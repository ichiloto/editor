<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\Events\EventAuthoring;
use Ichiloto\Editor\Events\EventRefusal;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\CommandGroup;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Field\Validation\MapEventResolver;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use InvalidArgumentException;
use Throwable;

/**
 * The stamped pieces standing on a map, such as buildings, each handled as
 * the one thing an author placed: found under a cell, moved, removed, and
 * given an entrance at a door its art paints.
 *
 * A map keeps no list of what was stamped. A copy is wherever all of a
 * piece's tiles still lie on their tile layers ({@see \Ichiloto\Engine\Field\MapGraphics::findPieceStamps()}),
 * the rule doorway validation already reads, so a copy painted over is no
 * longer one and nothing can disagree with the map. Removing a copy takes
 * away what it wrote: its glyphs where they are still its own, with the tiles
 * those glyphs carry, its remaining tiles, and, when asked, the collision its
 * physical footprint gave, which goes back to what the terminal layers left
 * there now say ({@see ProjectMap::resolveGlyphCollision()}). Moving is that
 * removal and a stamp, with the entrances at its doors moved along. Every edit
 * is one undo step, applied and unrecorded, for the caller's history.
 */
final class PlacedPieces
{
    private function __construct()
    {
    }

    /**
     * The stamped copies covering a cell, the smallest first, each with its
     * piece and top-left cell.
     *
     * @return list<array{piece: TilesetPiece, x: int, y: int}>
     * @throws MapSourceRefusal When the map's graphics cannot be read.
     */
    public static function findAt(ProjectMap $map, int $x, int $y): array
    {
        $graphics = self::loadGraphics($map);
        $found = [];
        foreach ($graphics?->tileset->pieces ?? [] as $piece) {
            // A stacked piece, such as a peak, is one of a cell's overlapping objects, not a thing placed on its own.
            if ($piece->connects !== null || $graphics->tileset->getStackOf($piece) !== null) {
                continue;
            }
            foreach ($graphics->findPieceStampsCovering($piece, $x, $y) as [$left, $top]) {
                $found[] = ['piece' => $piece, 'x' => $left, 'y' => $top];
            }
        }
        usort($found, static fn(array $a, array $b): int => $a['piece']->width * $a['piece']->height <=> $b['piece']->width * $b['piece']->height);

        return $found;
    }

    /**
     * Whether a stamped copy of the piece stands whole with its top-left at a cell.
     *
     * @throws MapSourceRefusal When the map's graphics cannot be read.
     */
    public static function isPlacedAt(ProjectMap $map, TilesetPiece $piece, int $x, int $y): bool
    {
        return in_array([$x, $y], self::loadGraphics($map)?->findPieceStampsCovering($piece, $x, $y) ?? [], true);
    }

    /** @throws MapSourceRefusal When the map's graphics cannot be read. */
    private static function loadGraphics(ProjectMap $map): ?\Ichiloto\Engine\Field\MapGraphics
    {
        try {
            return $map->loadGraphics();
        } catch (Throwable $error) {
            throw new MapSourceRefusal('The map\'s tiles cannot be read, so its pieces cannot be found: ' . $error->getMessage(), previous: $error);
        }
    }

    /**
     * A placed copy as an author sees it: where it stands, the cells it
     * covers, its doors with the cell each is entered from and the entrance
     * that meets it there, if any.
     *
     * @return array{id: string, name: string, x: int, y: int, width: int, height: int, footprint: bool,
     *     doors: list<array{x: int, y: int, entrance: string, entranceCell: array{0: int, 1: int}, event: ?string}>}
     */
    public static function describe(ProjectMap $map, TilesetPiece $piece, int $x, int $y): array
    {
        $doors = [];
        foreach ($piece->doorway as $door) {
            [$doorX, $doorY] = [$x + $door['x'], $y + $door['y']];
            $entranceCell = $door['entrance']->getEntranceCell($doorX, $doorY);
            $doors[] = ['x' => $doorX, 'y' => $doorY, 'entrance' => $door['entrance']->value, 'entranceCell' => $entranceCell,
                'event' => self::findEntranceAt($map, $entranceCell[0], $entranceCell[1])];
        }

        return ['id' => $piece->id, 'name' => $piece->name, 'x' => $x, 'y' => $y, 'width' => $piece->width, 'height' => $piece->height,
            'footprint' => $piece->occupancy !== null, 'doors' => $doors];
    }

    /**
     * Stamps a piece at its origins with the collision its physical footprint
     * gives at each, as one undo step: what placing a building with its
     * footprint does. A map whose collision still follows its glyphs takes
     * the glyphs' collision, so the footprint is not stamped there.
     *
     * @param Command|null $stamp The applied glyph and tile stamp ({@see PiecePlacer::stampOrigins()}).
     * @param list<array{x: int, y: int}> $origins
     * @return array{command: ?Command, cells: int} The step, and how many cells' collision the footprint changed.
     * @throws MapSourceRefusal When the footprint does not fit; the stamp is taken back first.
     */
    public static function addFootprint(ProjectMap $map, TilesetPiece $piece, ?Command $stamp, array $origins, string $label): array
    {
        if ($piece->occupancy === null || ! $map->hasMapDataField([MapPhysicalOccupancy::DATA_KEY])) {
            return ['command' => $stamp, 'cells' => 0];
        }
        $path = [MapPhysicalOccupancy::DATA_KEY];
        $before = $map->getMapDataField($path);
        $changed = 0;
        try {
            $writes = [];
            foreach ($origins as ['x' => $x, 'y' => $y]) {
                foreach (PhysicalOccupancyEditor::getPieceWrites($map, $piece, $x, $y) as $write) {
                    $writes["{$write[0]},{$write[1]}"] = $write;
                }
            }
            $changed = $map->writePhysicalOccupancy(array_values($writes));
        } catch (MapSourceRefusal $refusal) {
            $stamp?->undo();
            throw $refusal;
        }
        $after = $map->getMapDataField($path);
        $commands = array_values(array_filter([$stamp, $changed === 0 ? null : new GenericCommand('Stamp physical footprint',
            static fn() => $map->setMapDataField($path, $after), static fn() => $map->setMapDataField($path, $before))]));

        return ['command' => $commands === [] ? null : new CommandGroup($label, $commands), 'cells' => $changed];
    }

    /**
     * Removes a placed copy as one undo step: its glyphs where the map still
     * shows them, with the tiles they carry, every tile of it still in place,
     * and with `$footprint` the collision its physical footprint set, given
     * back to what the terminal layers now say there.
     *
     * @return array{command: Command, cells: int} The step, and how many cells' collision went back.
     * @throws MapSourceRefusal When no copy of the piece stands there, or what it leaves cannot be worked out.
     */
    public static function remove(ProjectMap $map, TilesetPiece $piece, int $x, int $y, bool $footprint, string $label = 'Remove piece'): array
    {
        if (! self::isPlacedAt($map, $piece, $x, $y)) {
            throw new MapSourceRefusal(sprintf('No whole %s stands at %d, %d any more. Nothing was changed.', $piece->name, $x, $y));
        }
        $layer = PiecePlacer::findLayer($map, $piece);
        $writes = [];
        foreach ($piece->glyphs as $row => $symbols) {
            foreach ($symbols as $column => $symbol) {
                [$cellX, $cellY] = [$x + $column, $y + $row];
                if ($symbol !== ' ' && $map->hasLayerCell($layer, $cellX, $cellY)
                    && TerminalText::stripAnsi($map->getLayerSymbol($layer, $cellX, $cellY)) === $symbol) {
                    $writes[] = ['x' => $cellX, 'y' => $cellY, 'symbol' => ' '];
                }
            }
        }
        $tilesBefore = $map->getTileLayerSources();
        $plan = CanvasEditor::plan($map, $layer, $writes);
        if ($plan !== null && $plan['unresolved'] !== []) {
            throw new MapSourceRefusal(sprintf('Removing %s leaves cells whose pieces beneath could be several. Erase its glyphs on the canvas instead. Nothing was changed.',
                $piece->name));
        }
        $map->writeTileCells($plan['tiles'] ?? []);
        $writes = $plan['writes'] ?? $writes;
        // Its tiles over blank glyph cells, such as a roof, go too, wherever they are still its own.
        $left = [];
        $current = $map->readTileEntries(array_keys($piece->tiles), $x, $y, $piece->width, $piece->height);
        foreach ($piece->tiles as $name => $rows) {
            foreach ($rows as $row => $entries) {
                foreach ($entries as $column => $entry) {
                    if ($entry !== '0' && ($current[$name][$row][$column] ?? null) === $entry) {
                        $left[$name][] = ['x' => $x + $column, 'y' => $y + $row, 'entry' => '0'];
                    }
                }
            }
        }
        $map->writeTileCells($left);
        $tilesAfter = $map->getTileLayerSources();
        [$stroke] = CanvasEditor::writeCells($map, $layer, $writes, $label);
        $commands = [CanvasEditor::combineStrokeWithTiles($label, $map, $stroke, $tilesBefore, $tilesAfter)];
        $freed = 0;
        if ($footprint && $piece->occupancy !== null && $map->hasMapDataField([MapPhysicalOccupancy::DATA_KEY])) {
            $cells = [];
            foreach ($piece->occupancy as $row => $types) {
                foreach ($types as $column => $type) {
                    if ($type !== null) {
                        $cells[] = [$x + $column, $y + $row];
                    }
                }
            }
            $path = [MapPhysicalOccupancy::DATA_KEY];
            $before = $map->getMapDataField($path);
            try {
                $freed = $map->writePhysicalOccupancy($map->resolveGlyphCollision($cells));
            } catch (MapSourceRefusal|InvalidArgumentException $refusal) {
                $commands[0]->undo();
                throw new MapSourceRefusal(rtrim($refusal->getMessage(), '.') . '. Nothing was changed.', previous: $refusal);
            }
            $after = $map->getMapDataField($path);
            if ($freed > 0) {
                $commands[] = new GenericCommand('Clear physical footprint', static fn() => $map->setMapDataField($path, $after),
                    static fn() => $map->setMapDataField($path, $before));
            }
        }

        return ['command' => new CommandGroup($label, $commands), 'cells' => $freed];
    }

    /**
     * Moves a placed copy so its top-left is at another cell, as one undo
     * step: removed where it stood and stamped where it goes, its footprint
     * with it when asked, and the entrances meeting its doors moved by the
     * same offset, so each door keeps its entrance.
     *
     * @param string|null $color The colour of the piece's cells that have none of their own, as a stamp takes it.
     * @return array{command: Command, entrances: list<string>} The step, and the entrances that moved.
     * @throws MapSourceRefusal|EventRefusal When it cannot be moved whole; nothing is changed then.
     */
    public static function move(ProjectMap $map, TilesetPiece $piece, int $x, int $y, int $toX, int $toY, bool $footprint, ?string $color): array
    {
        if ($x === $toX && $y === $toY) {
            throw new MapSourceRefusal(sprintf('%s is already there.', $piece->name));
        }
        $label = sprintf('Move %s', $piece->name);
        $entrances = array_values(array_unique(array_filter(array_column(self::describe($map, $piece, $x, $y)['doors'], 'event'))));
        $made = [];
        try {
            $made[] = self::remove($map, $piece, $x, $y, $footprint, $label)['command'];
            $stamp = PiecePlacer::stampOrigins($map, $piece, [['x' => $toX, 'y' => $toY]], $color, $label);
            if ($stamp !== null) {
                $made[] = $stamp;
            }
            if ($footprint) {
                $placed = self::addFootprint($map, $piece, null, [['x' => $toX, 'y' => $toY]], $label)['command'];
                if ($placed !== null) {
                    $made[] = $placed;
                }
            }
            foreach ($entrances as $marker) {
                $moved = EventAuthoring::moveEvent($map, $marker, $toX - $x, $toY - $y, $label);
                if ($moved !== null) {
                    $made[] = $moved;
                }
            }
        } catch (MapSourceRefusal|EventRefusal $refusal) {
            foreach (array_reverse($made) as $command) {
                $command->undo();
            }
            throw $refusal;
        }

        return ['command' => new CommandGroup($label, $made), 'entrances' => $entrances];
    }

    /**
     * Places a new entrance, a transfer event, on the cell a placed copy's
     * door is entered from, as one undo step; its destination is then chosen
     * like any transfer's.
     *
     * @return array{marker: string, command: Command, cell: array{0: int, 1: int}}
     * @throws MapSourceRefusal|EventRefusal When there is no such door, it has an entrance already, or the cell cannot take one.
     */
    public static function addEntrance(ProjectMap $map, TilesetPiece $piece, int $x, int $y, int $door): array
    {
        if (! self::isPlacedAt($map, $piece, $x, $y)) {
            throw new MapSourceRefusal(sprintf('No whole %s stands at %d, %d any more. Nothing was changed.', $piece->name, $x, $y));
        }
        $doors = self::describe($map, $piece, $x, $y)['doors'];
        $chosen = $doors[$door] ?? throw new MapSourceRefusal(sprintf('%s has no door %d.', $piece->name, $door + 1));
        if ($chosen['event'] !== null) {
            throw new MapSourceRefusal(sprintf('Entrance %s already meets that door.', $chosen['event']));
        }
        $type = EventTypeCatalog::findByClass(TransferPlayerTrigger::class)
            ?? throw new MapSourceRefusal('The Editor has no transfer event type.');
        $created = EventAuthoring::createEvent($map, [$chosen['entranceCell']], $type);

        return ['marker' => $created['marker'], 'command' => $created['command'], 'cell' => $chosen['entranceCell']];
    }

    /** The transfer event whose cells take in a cell, or null when none does. */
    private static function findEntranceAt(ProjectMap $map, int $x, int $y): ?string
    {
        foreach (MapEventResolver::inspect($map->getEditableData(), $map->getEventLayerCells(), $map->mapId)->events as $event) {
            $class = $event['class'] ?? null;
            if (! is_string($class) || ! is_a($class, TransferPlayerTrigger::class, true) || ! is_array($event['area'] ?? null)) {
                continue;
            }
            try {
                if (CellArea::fromArray($event['area'], 'Entrance')->containsCell($x, $y)) {
                    return is_string($event['marker'] ?? null) ? $event['marker'] : null;
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }
}
