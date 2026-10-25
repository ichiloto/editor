<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapTerrainKinds;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Field\NpcPlacement;
use Ichiloto\Engine\Field\Reachability\MapReachabilityReport;
use Ichiloto\Engine\Field\Reachability\ProjectReachability;
use Ichiloto\Engine\Field\Validation\MapEventResolver;
use RuntimeException;
use Throwable;

/**
 * Plans the save relocations a map save needs: when the unsaved map takes a
 * cell the player could stand on in the saved map, reachable today or not, a
 * save standing there would load inside it. A cell is taken when its new type blocks movement (new
 * furniture, a wall, painted collision; walkable grass, exits and save points
 * do not), when an authored NPC now stands on it, added or moved, or when an
 * event now covers it that covered nothing there before, such as a moved door. Floor
 * the player reaches today that the change walls off, such as the inside of
 * a building moved onto open ground, moves its saves too. The plan
 * moves saves on each such cell to the nearest plain floor the player can
 * reach once the change is saved, never a pocket the change itself walls
 * off, as one declared `relocations` step in the project's save
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
        // Where saves land is judged on the maps as the change leaves them: a pocket it walls off is reachable today.
        $unsaved = [];
        foreach ($maps as $map) {
            $unsaved[$map->mapId] = ['data' => $map->getEditableData(), 'layers' => $map->getLayerSet(), 'eventLayer' => $map->getEventLayerCells()];
        }
        $changed = ProjectReachability::analyze($root . '/assets', $unsaved);
        $relocations = [];
        $moving = [];
        foreach ($maps as $map) {
            $report = $reachability->reports[$map->mapId] ?? null;
            $found = $report === null ? [] : self::planMap($map, $root, $report, $changed->reports[$map->mapId] ?? null);
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
     * One map's relocations: saves may stand where the player reaches today,
     * and land where the player reaches once the map is saved.
     *
     * @return list<array{map: string, cells: list<array{int, int}>, to: array{int, int}}>
     */
    private static function planMap(ProjectMap $map, string $root, MapReachabilityReport $report, ?MapReachabilityReport $changed): array
    {
        $grid = $map->getResolvedCollisionMap();
        $npcs = self::readNpcCells((array) ($map->getEditableData()['npcs'] ?? []));
        $passable = static fn(int $x, int $y): bool => isset($grid[$y][$x]) && ! isset($npcs["{$x},{$y}"])
            && ! CollisionType::from($grid[$y][$x])->blocksMovement();
        // An NPC already standing there on disk is no change: reachability lets saves stand where a wanderer starts.
        $arrived = array_diff_key($npcs, self::readNpcCells(self::readSavedNpcs($root, $map->mapId)));
        // Any cell walkable on disk may hold a save, even one no route reaches today: an older layout may have
        // reached it, and a save made there must never load inside what now takes it.
        $walkable = self::readSavedWalkableCells($root, $map->mapId);
        // An event now covering a cell no event covered on disk, such as a door moved there, would start under a save.
        $saved = self::readSavedEventCells($root, $map->mapId);
        $covered = [];
        foreach (self::readEventAreas($map) as $area) {
            foreach ($area->cells as [$x, $y]) {
                if (! isset($saved["{$x},{$y}"])) {
                    $covered["{$x},{$y}"] = true;
                }
            }
        }
        $blocked = [];
        foreach ($grid as $y => $row) {
            foreach (array_keys($row) as $x) {
                $taken = CollisionType::from($grid[$y][$x])->blocksMovement() || isset($arrived["{$x},{$y}"]) || isset($covered["{$x},{$y}"]);
                // Floor the player reaches today and the change walls off: a save there would load with no way out.
                $cutOff = $changed !== null && $report->isReachable($x, $y) && ! $changed->isReachable($x, $y);
                if (($report->isReachable($x, $y) || isset($walkable["{$x},{$y}"])) && ($taken || $cutOff)) {
                    $blocked["{$x},{$y}"] = [$x, $y];
                }
            }
        }
        if ($blocked === []) {
            return [];
        }

        // A save lands on plain floor: never on grass, an exit, a save point or an NPC, and never inside an event
        // its own cell was not already in, painted or bound to an object or area, so loading it starts nothing new.
        $events = self::readEventCells($map);
        $areas = self::readEventAreas($map);
        $canLandFrom = static fn(int $fromX, int $fromY): \Closure => static fn(int $x, int $y): bool
            => ($grid[$y][$x] ?? null) === CollisionType::NONE->value
            && $passable($x, $y) && ($changed?->isReachable($x, $y) ?? false) && ! isset($events["{$x},{$y}"])
            && ! array_any($areas, static fn(CellArea $area): bool => $area->containsCell($x, $y) && ! $area->containsCell($fromX, $fromY));
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

    /** @return list<CellArea> Every event's cells as the Engine resolves them, bound events included. */
    private static function readEventAreas(ProjectMap $map): array
    {
        $events = MapEventResolver::inspect($map->getEditableData(), $map->getEventLayerCells(), $map->mapId)->events;

        return array_values(array_map(static fn(array $event): CellArea => CellArea::fromArray($event['area'], 'Event area'),
            array_filter($events, static fn(array $event): bool => is_array($event['area'] ?? null))));
    }

    /** @return array<string, true> Event markers, where a loaded save must not stand. */
    private static function readEventCells(ProjectMap $map): array
    {
        $events = [];
        for ($y = 0, $height = $map->getHeight(); $y < $height; $y++) {
            for ($x = 0, $width = $map->getWidth(); $x < $width; $x++) {
                if ($map->hasLayerCell(MapLayers::EVENT, $x, $y) && $map->getEventMarkerAt($x, $y) !== null) {
                    $events["{$x},{$y}"] = true;
                }
            }
        }

        return $events;
    }

    /**
     * Where NPCs stand when the map loads. Every NPC starts on its authored
     * cell and a wanderer never steps onto the player, so these are the only
     * cells an NPC can hold under a loaded save.
     *
     * @param array<mixed> $entries The map's `npcs` entries.
     * @return array<string, true>
     */
    private static function readNpcCells(array $entries): array
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

    /** @return array<mixed> The map's `npcs` entries as saved on disk. */
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

    /** @return array<string, true> The cells the map's events cover on disk, bound events included. */
    private static function readSavedEventCells(string $root, string $mapId): array
    {
        $source = MapSourceReader::readFiles(MapSourceReader::resolvePaths($root . '/assets/Maps', $mapId));
        $cells = [];
        foreach ((array) ($source['data']['events'] ?? []) as $event) {
            if (is_array($event) && is_array($event['area'] ?? null)) {
                foreach (CellArea::fromArray($event['area'], 'Event area')->cells as [$x, $y]) {
                    $cells["{$x},{$y}"] = true;
                }
            }
        }

        return $cells;
    }

    private static function readSavedNpcs(string $root, string $mapId): array
    {
        $source = MapSourceReader::readFiles(MapSourceReader::resolvePaths($root . '/assets/Maps', $mapId));

        return (array) ($source['data']['npcs'] ?? []);
    }
}
