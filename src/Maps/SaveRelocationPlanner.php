<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\Reachability\ProjectReachability;
use RuntimeException;
use Throwable;

/**
 * Plans the save relocations a map save needs: when the unsaved map makes a
 * cell solid that the player can reach in the saved map (new furniture, a
 * wall, painted collision), a save standing there would load inside it. The
 * plan moves saves on each such cell to the nearest open floor the player can
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
        return ProjectDirectoryContext::run($projectRoot, static fn(string $root): ?SaveRelocationPlan => self::buildPlan($map, $root));
    }

    private static function buildPlan(ProjectMap $map, string $root): ?SaveRelocationPlan
    {
        $manifestPath = $root . '/' . SaveManifestStep::PATH;
        if (! is_file($manifestPath) || ! $map->isDirty()) {
            return null;
        }
        $report = ProjectReachability::analyze($root . '/assets')->reports[$map->mapId] ?? null;
        if ($report === null) {
            return null;
        }

        $grid = $map->getResolvedCollisionMap();
        $open = static fn(int $x, int $y): bool => ($grid[$y][$x] ?? null) === CollisionType::NONE->value;
        $blocked = [];
        foreach ($grid as $y => $row) {
            foreach ($row as $x => $value) {
                if ($value !== CollisionType::NONE->value && $report->isReachable($x, $y)) {
                    $blocked["{$x},{$y}"] = [$x, $y];
                }
            }
        }
        if ($blocked === []) {
            return null;
        }

        $claimed = self::readClaimedCells($map);
        $canLand = static fn(int $x, int $y): bool => $open($x, $y) && $report->isReachable($x, $y) && ! isset($claimed["{$x},{$y}"]);
        $groups = [];
        foreach ($blocked as [$x, $y]) {
            $landing = self::findLanding($x, $y, $open, $canLand, $grid)
                ?? throw new RuntimeException(sprintf('Saves standing on x %d, y %d of %s would have no open floor to move to. Open a path to it, or undo the change, then save.',
                    $x, $y, $map->mapId));
            $groups[implode(',', $landing)]['to'] = $landing;
            $groups[implode(',', $landing)]['cells'][] = [$x, $y];
        }
        $relocations = array_map(static fn(array $group): array => ['map' => $map->mapId, 'cells' => $group['cells'], 'to' => $group['to']], array_values($groups));

        $source = (string) file_get_contents($manifestPath);
        try {
            $manifest = PhpDataFile::evaluateIsolated($manifestPath, $root);
        } catch (Throwable $failure) {
            throw new RuntimeException(sprintf('%s could not be read, so the saves standing where %s is now solid cannot be moved: %s',
                SaveManifestStep::PATH, $map->mapId, $failure->getMessage()), previous: $failure);
        }
        $next = SaveManifestStep::append($source, $manifest, ['relocations' => $relocations]);
        if (is_string($next)) {
            throw new RuntimeException(sprintf('%s cannot take the save relocations %s needs: %s. Add the step %s by hand.',
                SaveManifestStep::PATH, $map->mapId, $next, var_export(SaveManifestStep::describe($manifest, ['relocations' => $relocations]), true)));
        }

        $sources = new SourceSetPlan($root, [$manifestPath => $source], [$manifestPath => $source], [$manifestPath => $next['source']],
            [$manifestPath => PhpDataFile::getComparableValue($manifest)], [$manifestPath => PhpDataFile::getComparableValue($next['value'])],
            static fn(): array => [$manifestPath],
            static fn(string $path, string $staged): mixed => PhpDataFile::getComparableValue(PhpDataFile::evaluateIsolated($staged, $root)),
            'save relocations', 'Save compatibility manifest');

        return new SaveRelocationPlan($map->mapId, $relocations, $next['value']['contentVersion'], $sources);
    }

    /**
     * The nearest open floor a save can land on: first by walking from the
     * cell's open neighbours, so the landing is on the same side of whatever
     * was built, else the closest such cell anywhere.
     *
     * @param callable(int, int): bool $open
     * @param callable(int, int): bool $canLand
     * @return array{int, int}|null
     */
    private static function findLanding(int $x, int $y, callable $open, callable $canLand, array $grid): ?array
    {
        $seen = ["{$x},{$y}" => true];
        $queue = [[$x, $y]];
        while ($queue !== []) {
            [$cx, $cy] = array_shift($queue);
            foreach ([[0, 1], [1, 0], [-1, 0], [0, -1]] as [$dx, $dy]) {
                [$nx, $ny] = [$cx + $dx, $cy + $dy];
                if (isset($seen["{$nx},{$ny}"]) || ! $open($nx, $ny)) {
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

    /** @return array<string, true> Event markers and NPCs, where a loaded save must not stand. */
    private static function readClaimedCells(ProjectMap $map): array
    {
        $claimed = [];
        for ($y = 0, $height = $map->getHeight(); $y < $height; $y++) {
            for ($x = 0, $width = $map->getWidth(); $x < $width; $x++) {
                if ($map->hasLayerCell(MapLayers::EVENT, $x, $y) && $map->getEventMarkerAt($x, $y) !== null) {
                    $claimed["{$x},{$y}"] = true;
                }
            }
        }
        foreach ((array) ($map->getEditableData()['npcs'] ?? []) as $npc) {
            if (is_int($npc['x'] ?? null) && is_int($npc['y'] ?? null)) {
                $claimed["{$npc['x']},{$npc['y']}"] = true;
            }
        }

        return $claimed;
    }
}
