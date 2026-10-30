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
 * (relocations, map retirements). The Engine's save compatibility contract
 * freezes each landing in the manifest and requires open floor, free of
 * events and NPCs and reachable, because loading a save never consults the
 * mutable map. So a save lands on plain floor the player can reach: never
 * off the map, on a cell that blocks movement, on grass, an exit or a save
 * point, on a cell an NPC starts on, or inside any event, painted or bound
 * to an object or area, so loading it starts nothing.
 *
 * It also says which cells an event now covers that its saved counterpart
 * did not ({@see getNewEventCells()}): a save standing on such a cell would
 * load inside something new.
 *
 * @package Ichiloto\Editor\Maps
 */
final class SaveLandingCells
{
    /**
     * @param array<int, array<int, int>> $grid Resolved collision by row then column.
     * @param array<string, true> $npcs NPC start cells by `x,y`.
     * @param array<string, true> $events Event marker cells by `x,y`.
     * @param list<array{area: CellArea, previous: list<CellArea>}> $areas Current event areas and the matching saved event's
     *     areas, read only to find newly covered cells.
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
    public static function readMap(ProjectMap $map, MapReachabilityReport $report, array $savedEvents = []): self
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
        $areas = [];
        foreach ($resolved as $event) {
            if (!is_array($event['area'] ?? null)) { continue; }
            $previous = [];
            foreach ($savedEvents as $saved) {
                if (!is_array($saved) || !is_array($saved['area'] ?? null) || !self::isSameEvent($event, $saved)) { continue; }
                $previous[] = CellArea::fromArray($saved['area'], 'Saved event area');
            }
            $areas[] = ['area' => CellArea::fromArray($event['area'], 'Event area'), 'previous' => $previous];
        }

        return new self($map->mapId, $map->getResolvedCollisionMap(), self::readNpcCells((array) ($map->getEditableData()['npcs'] ?? [])),
            $events, $areas, $report);
    }

    /** A marker identifies its event; its behavior and subject must also remain the same.
     * Legacy markerless entries qualify only when their exact territory remains unchanged. */
    private static function isSameEvent(array $current, array $saved): bool
    {
        if (!isset($current['marker']) && ($current['area'] ?? null) != ($saved['area'] ?? null)) { return false; }
        unset($current['area'], $saved['area']);
        return $current == $saved;
    }

    /** Cells newly covered by each event, preserving event ownership when areas overlap. @return array<string, true> */
    public function getNewEventCells(): array
    {
        $cells = [];
        foreach ($this->areas as $entry) {
            foreach ($entry['area']->cells as [$x, $y]) {
                if (!array_any($entry['previous'], static fn(CellArea $area): bool => $area->containsCell($x, $y))) {
                    $cells["{$x},{$y}"] = true;
                }
            }
        }
        return $cells;
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

    /** Whether a save may land on the cell. */
    public function canLand(int $x, int $y): bool
    {
        return $this->findLandingRefusal($x, $y) === null;
    }

    /** Says why a save may not land on the cell, or null when it may. */
    public function findLandingRefusal(int $x, int $y): ?string
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
        if (isset($this->events["{$x},{$y}"]) || array_any($this->areas, static fn(array $entry): bool => $entry['area']->containsCell($x, $y))) {
            return 'an event is there';
        }
        if (! $this->report->isReachable($x, $y)) {
            return 'the player cannot reach it';
        }

        return null;
    }
}
