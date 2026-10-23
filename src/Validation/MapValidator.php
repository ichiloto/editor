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
use InvalidArgumentException;
use Throwable;

/**
 * Runs the pre-save checks over a map.
 *
 * A save is never blocked: the author's map saves as a draft and is told
 * what is wrong. An error is a defect the player would meet (a raw glyph in
 * the graphical field, a chest that gives nothing, a door to nowhere); a
 * warning is something that works differently than written. Validation
 * ({@see ProjectValidator}, `ichiloto validate`) fails on the errors until
 * they are fixed.
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
     * @return list<Issue> What is wrong, each naming the map; empty when the map is clean.
     */
    public static function validate(ProjectMap $map, array $mapsById, ?InventoryCatalog $inventory): array
    {
        return Issue::attributeToMap([
            ...MapCompletenessCheck::validate($map, $inventory),
            ...self::findUncheckedChests($map, $inventory),
            ...self::findDanglingDestinations($map, $mapsById),
            ...self::findOutOfRangeSpawnPoints($map, $mapsById),
            ...self::findRetiredData($map),
            ...self::findLandmarkProblems($map),
            ...self::findAtlasProblems($map, $mapsById),
            ...MapGraphicsValidator::validate($map),
            ...MapGraphicsValidator::validateCoverage($map),
            ...PassageAgreementCheck::validate($map),
        ], $map->mapId);
    }

    /**
     * Describes issues as one line each, the hint after the message.
     *
     * @param list<Issue> $issues
     * @return list<string>
     */
    public static function describeIssues(array $issues): array
    {
        return array_map(static fn(Issue $issue): string => $issue->hint === '' ? $issue->message : "{$issue->message} {$issue->hint}",
            array_values($issues));
    }

    /**
     * Selects the issues of one severity.
     *
     * @param list<Issue> $issues
     * @return list<Issue>
     */
    public static function selectSeverity(array $issues, Severity $severity): array
    {
        return array_values(array_filter($issues, static fn(Issue $issue): bool => $issue->severity === $severity));
    }


    /**
     * Finds an atlas scope or parent chain the engine would refuse, by its own check over the project's maps.
     *
     * @param array<string, ProjectMap> $mapsById
     * @return list<Issue>
     */
    private static function findAtlasProblems(ProjectMap $map, array $mapsById): array
    {
        try {
            $block = MapAtlas::readBlock($map);
            MapAtlas::resolveGlobal($map, $block, static fn(string $id): ?ProjectMap => $mapsById[$id] ?? null,
                $map->loadMapCategories());
        } catch (InvalidArgumentException $error) {
            return [Issue::warning($map->mapId, 'Atlas: ' . $error->getMessage())];
        }
        // Terrain names the map's own source layers, which the parent chain does not read.
        $terrain = MapTerrainFields::findProblem($map, $block);

        // The game refuses to prepare a map whose atlas terrain it cannot read, so the player would meet it.
        return $terrain === null ? [] : [Issue::error($map->mapId, 'Atlas terrain: ' . $terrain)];
    }

    /**
     * Finds minimap landmarks the engine would refuse, by its own check.
     *
     * @return list<Issue>
     */
    private static function findLandmarkProblems(ProjectMap $map): array
    {
        try {
            $problem = MapLandmarkFields::findProblem($map, MapLandmarks::readMap($map)->entries,
                $map->loadMapCategories());
        } catch (InvalidArgumentException $error) {
            $problem = $error->getMessage();
        }

        return $problem === null ? [] : [Issue::warning($map->mapId, 'Minimap landmarks: ' . $problem)];
    }

    /**
     * Finds map data the engine no longer reads.
     *
     * @return list<Issue>
     */
    private static function findRetiredData(ProjectMap $map): array
    {
        return array_key_exists('tiles2d', $map->getEditableData())
            ? [Issue::warning($map->mapId, 'tiles2d is no longer read; the map shows its terminal glyphs until it has a tileset.')]
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
     * Says so when the map has chests and the inventory could not be read,
     * so what they give was not checked.
     *
     * @return list<Issue>
     */
    private static function findUncheckedChests(ProjectMap $map, ?InventoryCatalog $inventory): array
    {
        return $inventory === null && array_filter(self::getEventDefinitions($map), ChestLoot::isChest(...)) !== []
            ? [Issue::warning($map->mapId, 'The inventory could not be read, so what the chests give was not checked.')]
            : [];
    }

    /**
     * Finds transfer events pointing at maps that no longer exist.
     *
     * @param ProjectMap $map The map being saved.
     * @param array<string, ProjectMap> $mapsById Every workspace map keyed by map id.
     * @return list<Issue>
     */
    private static function findDanglingDestinations(ProjectMap $map, array $mapsById): array
    {
        $issues = [];

        foreach (self::getEventDefinitions($map) as $marker => $definition) {
            $destination = $definition['data']['destinationMap'] ?? null;

            if (! is_string($destination) || trim($destination) === '') {
                continue;
            }

            if (! isset($mapsById[$destination])) {
                $issues[] = Issue::error($map->mapId, sprintf('%s: destination map %s does not exist.', $marker, $destination),
                    'Walking through it crashes the game. Choose a map the project has.');
            }
        }

        return $issues;
    }

    /**
     * Finds spawn points that land outside their destination map bounds.
     *
     * @param ProjectMap $map The map being saved.
     * @param array<string, ProjectMap> $mapsById Every workspace map keyed by map id.
     * @return list<Issue>
     */
    private static function findOutOfRangeSpawnPoints(ProjectMap $map, array $mapsById): array
    {
        $issues = [];

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
                $issues[] = Issue::error($map->mapId, sprintf(
                    '%s: spawn point (%d, %d) is outside %s (%d x %d).',
                    $marker,
                    $x,
                    $y,
                    $targetMap === $map ? 'this map' : $targetMap->mapId,
                    $targetMap->getWidth(),
                    $targetMap->getHeight(),
                ), 'The player would arrive off the map. Choose a cell inside it.');
            }
        }

        return $issues;
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
