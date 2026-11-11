<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectConfig;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use InvalidArgumentException;

/**
 * Reports a map that holds more save points than the project allows
 * (`save.points_per_map`, one unless the project says otherwise).
 *
 * A save point is a group of side-by-side cells that save, so a piece wider
 * than one cell counts once. The map still plays, so the save is never
 * blocked, but validation fails until the extra points are removed.
 */
final class SavePointLimitCheck
{
    public const string TOO_MANY = 'map.save-point.too-many';

    private function __construct()
    {
    }

    /**
     * @param ProjectConfig|null $config The project's settings; null where it has no config file, which keeps the default.
     * @return list<Issue> One error when the map holds too many save points.
     */
    public static function validate(ProjectMap $map, ?ProjectConfig $config): array
    {
        $limit = self::readLimit($config);
        if ($limit === null) {
            return [];
        }
        try {
            $points = self::findSavePoints($map->getResolvedCollisionMap());
        } catch (MapSourceRefusal|InvalidArgumentException) {
            // The map's own source problem is reported by the checks that read it.
            return [];
        }
        if (count($points) <= $limit) {
            return [];
        }

        $named = implode(', ', array_map(static fn(array $cell): string => "({$cell[0]}, {$cell[1]})", $points));

        return [new Issue(Severity::ERROR, $map->mapId,
            sprintf('This map has %d save points; a map holds at most %d. At %s.', count($points), $limit, $named),
            sprintf('Remove save points until %d remain%s, or change %s in Configuration.', $limit, $limit === 1 ? 's' : '',
                ProjectConfig::SAVE_POINTS_PER_MAP),
            self::TOO_MANY, $map->mapId, $points)];
    }

    /** The most save points one map may hold, or null for no limit. */
    public static function readLimit(?ProjectConfig $config): ?int
    {
        $value = $config?->getRecord(ProjectConfig::SAVE_POINTS_PER_MAP)->get('value') ?? ProjectConfig::DEFAULT_SAVE_POINTS_PER_MAP;
        if (! is_int($value) || $value < 0) {
            $value = ProjectConfig::DEFAULT_SAVE_POINTS_PER_MAP;
        }

        return $value === 0 ? null : $value;
    }

    /**
     * The first cell of each save point, in reading order.
     *
     * @param array<int, array<int, int>> $rows The map's resolved collision.
     * @return list<array{0: int, 1: int}>
     */
    private static function findSavePoints(array $rows): array
    {
        $save = CollisionType::SAVE_POINT->value;
        $seen = [];
        $points = [];
        foreach ($rows as $y => $row) {
            foreach ($row as $x => $collision) {
                if ($collision !== $save || isset($seen[$y][$x])) {
                    continue;
                }
                $points[] = [$x, $y];
                $queue = [[$x, $y]];
                $seen[$y][$x] = true;
                while ($queue !== []) {
                    [$cx, $cy] = array_pop($queue);
                    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                        [$nx, $ny] = [$cx + $dx, $cy + $dy];
                        if (($rows[$ny][$nx] ?? null) === $save && ! isset($seen[$ny][$nx])) {
                            $seen[$ny][$nx] = true;
                            $queue[] = [$nx, $ny];
                        }
                    }
                }
            }
        }

        return $points;
    }
}
