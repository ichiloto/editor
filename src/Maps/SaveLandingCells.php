<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\NpcPlacement;
use Ichiloto\Engine\Field\Reachability\MapReachabilityReport;
use Ichiloto\Engine\Field\Validation\MapEventResolver;

/**
 * Where a migrated save may stand on one map, as the map holds it: the one
 * rule every save landing the Editor writes into the manifest follows
 * (relocations, map retirements). A save lands on plain floor the player can
 * reach: never off the map, on a cell that blocks movement, on grass, an
 * exit or a save point, on a cell an NPC starts on, or inside an event its
 * own cell was not already in, painted or bound to an object or area, so
 * loading it starts nothing new.
 *
 * @package Ichiloto\Editor\Maps
 */
final class SaveLandingCells
{
    /**
     * @param array<int, array<int, int>> $grid Resolved collision by row then column.
     * @param array<string, true> $npcs NPC start cells by `x,y`.
     * @param array<string, true> $events Event marker cells by `x,y`.
     * @param list<CellArea> $areas Every event's cells as the Engine resolves them.
     */
    private function __construct(
        public readonly string $mapId,
        private readonly array $grid,
        private readonly array $npcs,
        private readonly array $events,
        private readonly array $areas,
        private readonly MapReachabilityReport $report,
    ) {
    }

    /**
     * Reads the map as it is held, unsaved edits included.
     *
     * @param MapReachabilityReport $report Where the player can reach on this map.
     */
    public static function readMap(ProjectMap $map, MapReachabilityReport $report): self
    {
        $events = [];
        for ($y = 0, $height = $map->getHeight(); $y < $height; $y++) {
            for ($x = 0, $width = $map->getWidth(); $x < $width; $x++) {
                if ($map->hasLayerCell(MapLayers::EVENT, $x, $y) && $map->getEventMarkerAt($x, $y) !== null) {
                    $events["{$x},{$y}"] = true;
                }
            }
        }
        $resolved = MapEventResolver::inspect($map->getEditableData(), $map->getEventLayerCells(), $map->mapId)->events;
        $areas = array_values(array_map(static fn(array $event): CellArea => CellArea::fromArray($event['area'], 'Event area'),
            array_filter($resolved, static fn(array $event): bool => is_array($event['area'] ?? null))));

        return new self($map->mapId, $map->getResolvedCollisionMap(), self::readNpcCells((array) ($map->getEditableData()['npcs'] ?? [])),
            $events, $areas, $report);
    }

    /**
     * Where NPCs stand when the map loads. Every NPC starts on its authored
     * cell and a wanderer never steps onto the player, so these are the only
     * cells an NPC can hold under a loaded save.
     *
     * @param array<mixed> $entries The map's `npcs` entries.
     * @return array<string, true> By `x,y`.
     */
    public static function readNpcCells(array $entries): array
    {
        $npcs = [];
        foreach ($entries as $entry) {
            $npc = NpcPlacement::fromArray($entry);
            if ($npc !== null) {
                $npcs["{$npc->x},{$npc->y}"] = true;
            }
        }

        return $npcs;
    }

    /** Whether the player walks through the cell: on the map, not blocking movement, no NPC on it. */
    public function isPassable(int $x, int $y): bool
    {
        return isset($this->grid[$y][$x]) && ! isset($this->npcs["{$x},{$y}"])
            && ! CollisionType::from($this->grid[$y][$x])->blocksMovement();
    }

    /**
     * Whether a save may land on the cell.
     *
     * @param array{int, int}|null $from The cell on this map the save moves off, whose own events it may stay
     *     inside; null for a save arriving from another map.
     */
    public function canLand(int $x, int $y, ?array $from = null): bool
    {
        return $this->findLandingRefusal($x, $y, $from) === null;
    }

    /**
     * Says why a save may not land on the cell, or null when it may.
     *
     * @param array{int, int}|null $from As for {@see canLand()}.
     */
    public function findLandingRefusal(int $x, int $y, ?array $from = null): ?string
    {
        $type = $this->grid[$y][$x] ?? null;
        if ($type === null) {
            return 'it is off the map';
        }
        $collision = CollisionType::from($type);
        if ($collision->blocksMovement()) {
            return 'it is solid';
        }
        if (isset($this->npcs["{$x},{$y}"])) {
            return 'an NPC stands there';
        }
        if ($collision !== CollisionType::NONE) {
            return sprintf('it is not plain floor (%s)', strtolower(str_replace('_', ' ', $collision->name)));
        }
        if (isset($this->events["{$x},{$y}"]) || array_any($this->areas, static fn(CellArea $area): bool => $area->containsCell($x, $y)
            && ($from === null || ! $area->containsCell(...$from)))) {
            return 'an event is there';
        }
        if (! $this->report->isReachable($x, $y)) {
            return 'the player cannot reach it';
        }

        return null;
    }
}
