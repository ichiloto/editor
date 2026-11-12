<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\MapDoorReference;
use Ichiloto\Engine\Field\Validation\MapEventResolver;
use InvalidArgumentException;

/**
 * The doors a `transfer_through_door` command may leave through, read as the
 * Engine reads them ({@see MapDoorReference}) from the workspace's maps,
 * unsaved edits included: a door is an event the Engine's resolver turns into
 * a destination, which the door owns with its arrival and facing.
 *
 * @package Ichiloto\Editor\Maps
 */
final readonly class MapDoors
{
    public function __construct(private ProjectWorkspace $workspace)
    {
    }

    /**
     * A map's doors, by marker, each with the destination it owns.
     *
     * @return array<string, Location>
     */
    public function listDoors(string $mapId): array
    {
        $map = $this->findMap($mapId);
        if ($map === null) {
            return [];
        }
        $events = self::resolveEvents($map);
        $doors = [];
        foreach ($events as $event) {
            $marker = $event['marker'] ?? null;
            if (! is_string($marker)) {
                continue;
            }
            try {
                $doors[$marker] = MapDoorReference::fromCommand(['type' => MapDoorReference::COMMAND, 'map' => $mapId, 'event' => $marker])
                    ->resolve($events);
            } catch (InvalidArgumentException) {
                // Not a door the Engine would leave through.
            }
        }

        return $doors;
    }

    /**
     * Where a command leaves for, or why the Engine would refuse it.
     *
     * @param array<string, mixed> $command The `transfer_through_door` command.
     */
    public function resolve(array $command): Location|string
    {
        try {
            $reference = MapDoorReference::fromCommand($command);
            $map = $this->findMap($reference->map);
            if ($map === null) {
                return sprintf("Door source map '%s' was not found in the project.", $reference->map);
            }

            return $reference->resolve(self::resolveEvents($map));
        } catch (InvalidArgumentException $refusal) {
            return $refusal->getMessage();
        }
    }

    /**
     * A door command's destination map, for following a script across it
     * ({@see \Ichiloto\Editor\Events\CommandMapContext::findDestinationMap()}).
     *
     * @return \Closure(array<string, mixed>): ?string
     */
    public function createDestinationResolver(): \Closure
    {
        return fn(array $command): ?string => ($door = $this->resolve($command)) instanceof Location ? $door->mapFilename : null;
    }

    private function findMap(string $mapId): ?ProjectMap
    {
        return array_find($this->workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === $mapId);
    }

    /** @return list<array<string, mixed>> */
    private static function resolveEvents(ProjectMap $map): array
    {
        return array_values(MapEventResolver::inspect($map->getEditableData(), $map->getEventLayerCells(), $map->mapId)->events);
    }
}
