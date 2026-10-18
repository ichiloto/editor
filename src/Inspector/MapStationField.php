<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\ProjectMap;
use InvalidArgumentException;

/**
 * The map's station: the cell of its region's map it is pinned to, as the
 * Engine's RegionMap reads it, `['x' => int, 'y' => int]`, both or neither.
 * A map without one is placed from the direction its doors lead. The row is
 * one `x, y` pair so the two are always written together, and an empty value
 * takes the pin away.
 */
final class MapStationField
{
    public const string TARGET = 'map-station';

    /** The map data key RegionMap reads. */
    public const string KEY = 'station';

    private function __construct() {}

    /** @return array<string, mixed> */
    public static function describeField(ProjectMap $map): array
    {
        $station = $map->getMapDataField([self::KEY]);
        $pair = self::readPair($station);
        $raw = $pair === null ? '' : sprintf('%d, %d', $pair[0], $pair[1]);

        return [
            'label' => 'Region Map Station',
            'value' => match (true) {
                $station === null => '(placed from its doors)',
                $pair === null => '! not an x/y pair the region map reads · ' . get_debug_type($station),
                default => $raw,
            },
            'control' => new InputControl(InputControlType::TEXT, $raw),
            'target' => self::TARGET,
            'field' => self::KEY,
        ];
    }

    /**
     * The station an edit leaves: null to take it away, or the pair with any
     * other authored keys kept.
     *
     * @return array<array-key, mixed>|null
     * @throws InvalidArgumentException When the value is not two whole numbers.
     */
    public static function applyValue(ProjectMap $map, string $value): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $parts = array_map(trim(...), explode(',', $value));

        if (count($parts) !== 2 || ! array_all($parts, static fn(string $part): bool => preg_match('/^-?\d+$/', $part) === 1)) {
            throw new InvalidArgumentException(sprintf('A station is two whole numbers, x and y, separated by a comma; "%s" is not.', $value));
        }

        $current = $map->getMapDataField([self::KEY]);

        return [...(is_array($current) ? $current : []), 'x' => (int) $parts[0], 'y' => (int) $parts[1]];
    }

    /**
     * The cell a station names, as RegionMap::stationOf reads it: both
     * coordinates set, each read as a whole number, or null.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function readPair(mixed $station): ?array
    {
        if (! is_array($station) || ! isset($station['x'], $station['y'])) {
            return null;
        }

        return [intval($station['x']), intval($station['y'])];
    }
}
