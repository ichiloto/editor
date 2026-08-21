<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectMap;

/**
 * Runs the advisory pre-save checks over a map.
 *
 * Findings are warnings only — a save is never blocked, the author is just
 * told what will misbehave in the engine.
 */
final class MapValidator
{
    private function __construct()
    {
    }

    /**
     * Validates a map against the rest of the workspace.
     *
     * @param ProjectMap $map The map being saved.
     * @param array<string, ProjectMap> $mapsById Every workspace map keyed by map id.
     * @return string[] Human-readable warnings; empty when the map is clean.
     */
    public static function validate(ProjectMap $map, array $mapsById): array
    {
        return [
            ...self::findMarkersWithoutDefinitions($map),
            ...self::findNonRectangularMarkers($map),
            ...self::findDanglingDestinations($map, $mapsById),
            ...self::findOutOfRangeSpawnPoints($map, $mapsById),
        ];
    }

    /**
     * Finds marker shapes the runtime cannot turn into one trigger area.
     *
     * @return string[]
     */
    private static function findNonRectangularMarkers(ProjectMap $map): array
    {
        $warnings = [];

        foreach ($map->getPlacedEventMarkers() as $marker) {
            if (! $map->isEventMarkerSolidRectangle($marker)) {
                $warnings[] = sprintf('Event marker %s must occupy one solid rectangle.', $marker);
            }
        }

        return $warnings;
    }

    /**
     * Finds event markers painted on the grid with no event definition.
     *
     * @param ProjectMap $map The map being saved.
     * @return string[]
     */
    private static function findMarkersWithoutDefinitions(ProjectMap $map): array
    {
        $warnings = [];

        foreach ($map->getPlacedEventMarkers() as $marker) {
            if ($map->getEventDefinition($marker) === null) {
                $warnings[] = sprintf('Event marker %s has no definition.', $marker);
            }
        }

        return $warnings;
    }

    /**
     * Finds transfer events pointing at maps that no longer exist.
     *
     * @param ProjectMap $map The map being saved.
     * @param array<string, ProjectMap> $mapsById Every workspace map keyed by map id.
     * @return string[]
     */
    private static function findDanglingDestinations(ProjectMap $map, array $mapsById): array
    {
        $warnings = [];

        foreach (self::getEventDefinitions($map) as $marker => $definition) {
            $destination = $definition['data']['destinationMap'] ?? null;

            if (! is_string($destination) || trim($destination) === '') {
                continue;
            }

            if (! isset($mapsById[$destination])) {
                $warnings[] = sprintf('%s: destination map %s does not exist.', $marker, $destination);
            }
        }

        return $warnings;
    }

    /**
     * Finds spawn points that land outside their destination map bounds.
     *
     * @param ProjectMap $map The map being saved.
     * @param array<string, ProjectMap> $mapsById Every workspace map keyed by map id.
     * @return string[]
     */
    private static function findOutOfRangeSpawnPoints(ProjectMap $map, array $mapsById): array
    {
        $warnings = [];

        foreach (self::getEventDefinitions($map) as $marker => $definition) {
            $spawnPoint = $definition['data']['spawnPoint'] ?? null;

            if (! is_array($spawnPoint) || ! is_numeric($spawnPoint['x'] ?? null) || ! is_numeric($spawnPoint['y'] ?? null)) {
                continue;
            }

            $x = (int) $spawnPoint['x'];
            $y = (int) $spawnPoint['y'];

            // The spawn point lives in the destination map's coordinate space
            // when one is configured; otherwise it refers to this map.
            $destination = $definition['data']['destinationMap'] ?? null;
            $targetMap = is_string($destination) && isset($mapsById[$destination])
                ? $mapsById[$destination]
                : $map;

            if ($x < 0 || $y < 0 || $x >= $targetMap->getWidth() || $y >= $targetMap->getHeight()) {
                $warnings[] = sprintf(
                    '%s: spawn point (%d, %d) is outside %s (%d x %d).',
                    $marker,
                    $x,
                    $y,
                    $targetMap === $map ? 'this map' : $targetMap->mapId,
                    $targetMap->getWidth(),
                    $targetMap->getHeight(),
                );
            }
        }

        return $warnings;
    }

    /**
     * Returns the map's event definitions keyed by marker.
     *
     * @param ProjectMap $map The map being saved.
     * @return array<string, array<string, mixed>>
     */
    private static function getEventDefinitions(ProjectMap $map): array
    {
        $events = $map->getEditableData()['events'] ?? [];

        if (! is_array($events)) {
            return [];
        }

        $definitions = [];

        foreach ($events as $marker => $definition) {
            if (is_array($definition)) {
                $definitions[(string) $marker] = $definition;
            }
        }

        return $definitions;
    }
}
