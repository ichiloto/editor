<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\Events\ChestLoot;
use Ichiloto\Editor\Field\MapAtlas;
use Ichiloto\Editor\Field\MapLandmarks;
use Ichiloto\Editor\Inspector\MapLandmarkFields;
use Ichiloto\Editor\Inspector\MapTerrainFields;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Field\MapCategoryCatalog;
use InvalidArgumentException;
use Throwable;

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
     * @param InventoryCatalog|null $inventory The project's inventory, for what its chests give; null when it
     *     could not be read ({@see readInventoryCatalog()}), which is itself reported.
     * @return string[] Human-readable warnings; empty when the map is clean.
     */
    public static function validate(ProjectMap $map, array $mapsById, ?InventoryCatalog $inventory): array
    {
        return [
            ...self::findMarkersWithoutDefinitions($map),
            ...self::findChestRewardProblems($map, $inventory),
            ...self::findDanglingDestinations($map, $mapsById),
            ...self::findOutOfRangeSpawnPoints($map, $mapsById),
            ...self::findRetiredData($map),
            ...self::findLandmarkProblems($map),
            ...self::findAtlasProblems($map, $mapsById),
            ...array_map(static fn(Issue $issue): string => $issue->message, MapGraphicsValidator::validate($map)),
        ];
    }


    /**
     * Finds an atlas scope or parent chain the engine would refuse, by its own check over the project's maps.
     *
     * @param array<string, ProjectMap> $mapsById
     * @return string[]
     */
    private static function findAtlasProblems(ProjectMap $map, array $mapsById): array
    {
        try {
            $block = MapAtlas::readBlock($map);
            MapAtlas::resolveGlobal($map, $block, static fn(string $id): ?ProjectMap => $mapsById[$id] ?? null,
                MapCategoryCatalog::load($map->getAssetRoot()));
        } catch (InvalidArgumentException $error) {
            return ['Atlas: ' . $error->getMessage()];
        }
        // Terrain names the map's own source layers, which the parent chain does not read.
        $terrain = MapTerrainFields::findProblem($map, $block);

        return $terrain === null ? [] : ['Atlas terrain: ' . $terrain];
    }

    /**
     * Finds minimap landmarks the engine would refuse, by its own check.
     *
     * @return string[]
     */
    private static function findLandmarkProblems(ProjectMap $map): array
    {
        try {
            $problem = MapLandmarkFields::findProblem($map, MapLandmarks::readMap($map)->entries,
                MapCategoryCatalog::load($map->getAssetRoot()));
        } catch (InvalidArgumentException $error) {
            $problem = $error->getMessage();
        }

        return $problem === null ? [] : ['Minimap landmarks: ' . $problem];
    }

    /**
     * Finds map data the engine no longer reads.
     *
     * @return string[]
     */
    private static function findRetiredData(ProjectMap $map): array
    {
        return array_key_exists('tiles2d', $map->getEditableData())
            ? ['tiles2d is no longer read; the map shows its terminal glyphs until it has a tileset.']
            : [];
    }

    /**
     * Reads the project's inventory for {@see validate()}, or null when it
     * cannot be read: a save is never blocked by it.
     */
    public static function readInventoryCatalog(ProjectWorkspace $workspace): ?InventoryCatalog
    {
        try {
            return InventoryCatalog::fromWorkspace($workspace);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Finds chests whose reward the Engine cannot grant, by the Engine's chest
     * rule the inspectors and the project report also read.
     *
     * @return string[]
     */
    private static function findChestRewardProblems(ProjectMap $map, ?InventoryCatalog $inventory): array
    {
        $chests = array_filter(self::getEventDefinitions($map), ChestLoot::isChest(...));

        if ($chests === []) {
            return [];
        }

        if (! $inventory instanceof InventoryCatalog) {
            return ['The inventory could not be read, so what the chests give was not checked.'];
        }

        $validator = new ChestRewardValidator($inventory);
        $warnings = [];

        foreach ($chests as $marker => $definition) {
            foreach ($validator->validate($map->mapId, strval($marker), (array) ($definition['data'] ?? [])) as $issue) {
                $warnings[] = sprintf('Chest %s: %s', $marker, $issue->message);
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
