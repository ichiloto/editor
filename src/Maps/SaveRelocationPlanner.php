<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapTerrainKinds;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Field\Reachability\MapReachabilityReport;
use Ichiloto\Engine\Field\Reachability\ProjectReachability;
use RuntimeException;
use Throwable;

/**
 * Plans the save relocations a map save needs: when the unsaved map takes a
 * cell the player could stand on in the saved map, reachable today or not, a
 * save standing there would load inside it. A cell is taken when its new type blocks movement (new
 * furniture, a wall, painted collision; walkable grass, exits and save points
 * do not) or when an authored NPC now stands on it, added or moved. The plan
 * moves saves on each such cell to the nearest plain floor the player can
 * reach today, as one declared `relocations` step in the project's save
 * compatibility manifest, for the author to review before the save writes
 * both. A project without a manifest, or a map not yet on disk, needs none.
 *
 * Planning reads the project from disk and writes nothing.
 *
 * @package Ichiloto\Editor\Maps
 */
final class SaveRelocationPlanner
{
    private function __construct()
    {
    }

    /**
     * @throws RuntimeException When the manifest cannot be read or rewritten; nothing is written.
     */
    public static function plan(ProjectMap $map, string $projectRoot): ?SaveRelocationPlan
    {
        return self::planAll([$map], $projectRoot);
    }

    /**
     * Plans the relocations of every given map as one manifest step, the
     * step a change set writing those maps together adds: the manifest is
     * written once, whichever of its maps move saves.
     *
     * @param list<ProjectMap> $maps
     * @throws RuntimeException When the manifest cannot be read or rewritten; nothing is written.
     */
    public static function planAll(array $maps, string $projectRoot): ?SaveRelocationPlan
    {
        return ProjectDirectoryContext::run($projectRoot, static fn(string $root): ?SaveRelocationPlan => self::buildPlan($maps, $root));
    }

    /** @param list<ProjectMap> $maps */
    private static function buildPlan(array $maps, string $root): ?SaveRelocationPlan
    {
        $manifestPath = $root . '/' . SaveManifestStep::PATH;
        $maps = array_values(array_filter($maps, static fn(ProjectMap $map): bool => $map->isDirty()));
        if (! is_file($manifestPath) || $maps === []) {
            return null;
        }
        $reachability = ProjectReachability::analyze($root . '/assets');
        $relocations = [];
        $moving = [];
        foreach ($maps as $map) {
            $report = $reachability->reports[$map->mapId] ?? null;
            $found = $report === null ? [] : self::planMap($map, $root, $report);
            if ($found !== []) {
                $relocations = [...$relocations, ...$found];
                $moving[] = $map->mapId;
            }
        }
        if ($relocations === []) {
            return null;
        }
        $label = implode(', ', $moving);

        $source = (string) file_get_contents($manifestPath);
        try {
            $manifest = PhpDataFile::evaluateIsolated($manifestPath, $root);
        } catch (Throwable $failure) {
            throw new RuntimeException(sprintf('%s could not be read, so the saves standing where %s is now taken cannot be moved: %s',
                SaveManifestStep::PATH, $label, $failure->getMessage()), previous: $failure);
        }
        $next = SaveManifestStep::append($source, $manifest, ['relocations' => $relocations]);
        if (is_string($next)) {
            throw new RuntimeException(sprintf('%s cannot take the save relocations %s needs: %s. Add the step %s by hand.',
                SaveManifestStep::PATH, $label, $next, var_export(SaveManifestStep::describe($manifest, ['relocations' => $relocations]), true)));
        }

        $sources = new SourceSetPlan($root, [$manifestPath => $source], [$manifestPath => $source], [$manifestPath => $next['source']],
            [$manifestPath => PhpDataFile::getComparableValue($manifest)], [$manifestPath => PhpDataFile::getComparableValue($next['value'])],
            static fn(): array => [$manifestPath],
            static fn(string $path, string $staged): mixed => PhpDataFile::getComparableValue(PhpDataFile::evaluateIsolated($staged, $root)),
            'save relocations', 'Save compatibility manifest');

        return new SaveRelocationPlan($label, $relocations, $next['value']['contentVersion'], $sources);
    }

    /**
     * One map's relocations, against today's reachability.
     *
     * @return list<array{map: string, cells: list<array{int, int}>, to: array{int, int}}>
     */
    private static function planMap(ProjectMap $map, string $root, MapReachabilityReport $report): array
    {
        $grid = $map->getResolvedCollisionMap();
        $cells = SaveLandingCells::readMap($map, $report);
        $passable = $cells->isPassable(...);
        // An NPC already standing there on disk is no change: reachability lets saves stand where a wanderer starts.
        $arrived = array_diff_key(SaveLandingCells::readNpcCells((array) ($map->getEditableData()['npcs'] ?? [])),
            SaveLandingCells::readNpcCells(self::readSavedNpcs($root, $map->mapId)));
        // Any cell walkable on disk may hold a save, even one no route reaches today: an older layout may have
        // reached it, and a save made there must never load inside what now takes it.
        $walkable = self::readSavedWalkableCells($root, $map->mapId);
        $blocked = [];
        foreach ($grid as $y => $row) {
            foreach (array_keys($row) as $x) {
                if (($report->isReachable($x, $y) || isset($walkable["{$x},{$y}"]))
                    && (CollisionType::from($grid[$y][$x])->blocksMovement() || isset($arrived["{$x},{$y}"]))) {
                    $blocked["{$x},{$y}"] = [$x, $y];
                }
            }
        }
        if ($blocked === []) {
            return [];
        }

        // A save lands where SaveLandingCells allows, inside only the events its own cell was already in.
        $canLandFrom = static fn(int $fromX, int $fromY): \Closure => static fn(int $x, int $y): bool => $cells->canLand($x, $y, [$fromX, $fromY]);
        $groups = [];
        foreach ($blocked as [$x, $y]) {
            $landing = self::findLanding($x, $y, $passable, $canLandFrom($x, $y), $grid);
            if ($landing === null && ! $report->isReachable($x, $y)) {
                // A map the player reaches nowhere today holds no save a relocation could place.
                continue;
            }
            $landing ?? throw new RuntimeException(sprintf('Saves standing on x %d, y %d of %s would have no open floor to move to. Open a path to it, or undo the change, then save.',
                $x, $y, $map->mapId));
            $groups[implode(',', $landing)]['to'] = $landing;
            $groups[implode(',', $landing)]['cells'][] = [$x, $y];
        }

        return array_map(static fn(array $group): array => ['map' => $map->mapId, 'cells' => $group['cells'], 'to' => $group['to']], array_values($groups));
    }

    /**
     * The nearest floor a save can land on: first by walking from the cell's
     * passable neighbours, so the landing is on the same side of whatever was
     * built, else the closest such cell anywhere.
     *
     * @param callable(int, int): bool $passable
     * @param callable(int, int): bool $canLand
     * @return array{int, int}|null
     */
    private static function findLanding(int $x, int $y, callable $passable, callable $canLand, array $grid): ?array
    {
        $seen = ["{$x},{$y}" => true];
        $queue = [[$x, $y]];
        while ($queue !== []) {
            [$cx, $cy] = array_shift($queue);
            foreach ([[0, 1], [1, 0], [-1, 0], [0, -1]] as [$dx, $dy]) {
                [$nx, $ny] = [$cx + $dx, $cy + $dy];
                if (isset($seen["{$nx},{$ny}"]) || ! $passable($nx, $ny)) {
                    continue;
                }
                if ($canLand($nx, $ny)) {
                    return [$nx, $ny];
                }
                $seen["{$nx},{$ny}"] = true;
                $queue[] = [$nx, $ny];
            }
        }
        $best = null;
        foreach ($grid as $cy => $row) {
            foreach (array_keys($row) as $cx) {
                if ($canLand($cx, $cy) && ($best === null || abs($cx - $x) + abs($cy - $y) < $best[2])) {
                    $best = [$cx, $cy, abs($cx - $x) + abs($cy - $y)];
                }
            }
        }

        return $best === null ? null : [$best[0], $best[1]];
    }

    /**
     * The cells of the map on disk that a player could stand on, by `x,y`:
     * walkable by its saved collision, reachable or not. None when the
     * project has no readable collision dictionary.
     *
     * @return array<string, true>
     */
    private static function readSavedWalkableCells(string $root, string $mapId): array
    {
        try {
            $dictionary = MapSourceReader::loadCollisionDictionary($root . '/assets/Maps/collisions.php');
        } catch (Throwable) {
            $dictionary = [];
        }
        $source = MapSourceReader::readFiles(MapSourceReader::resolvePaths($root . '/assets/Maps', $mapId));
        $walkable = [];
        $kinds = MapTerrainKinds::loadForMap($root . '/assets', $source['data']);
        foreach (MapCollisionResolver::resolveMap($source['layers'], $source['data'], $dictionary, $kinds)->collisionGrid as $y => $row) {
            foreach ($row as $x => $value) {
                if (! CollisionType::from($value)->blocksMovement()) {
                    $walkable["{$x},{$y}"] = true;
                }
            }
        }

        return $walkable;
    }

    /** @return array<mixed> The map's `npcs` entries as saved on disk. */
    private static function readSavedNpcs(string $root, string $mapId): array
    {
        $source = MapSourceReader::readFiles(MapSourceReader::resolvePaths($root . '/assets/Maps', $mapId));

        return (array) ($source['data']['npcs'] ?? []);
    }
}
