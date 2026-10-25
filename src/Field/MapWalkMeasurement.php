<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\EncounterTable;
use Ichiloto\Engine\Field\MapAreaCatalog;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Field\Reachability\MapReachability;
use Ichiloto\Engine\Rendering\FieldMetric;
use Ichiloto\Engine\Rendering\FieldViewport;
use InvalidArgumentException;

/**
 * How far a player walks between cells of a map, measured on request: the
 * shortest walk from a start through any waypoints to an end, its steps, the
 * seconds it takes walking and dashing, the field screens it crosses and the
 * encounters expected on the way. Nothing is written and nothing is judged:
 * the author reads the numbers and decides.
 *
 * Every rule is the Engine's own, read from the map as it stands in the
 * session (unsaved edits included): the walk is {@see MapReachability}'s,
 * over the collision the map resolves to, so walls, fixed NPCs and cells
 * that take the player off the map stand where the game puts them; a step
 * lasts what {@see FieldMetric} says walking takes; and each step counts
 * toward an encounter as the map's {@see EncounterTable} weighs it.
 * Expected encounters are the counted steps over the map's average steps
 * between encounters; a repel or lure the player may carry is not assumed.
 *
 * Screens crossed count the walk's steps across in field view widths and its
 * steps down in field view heights, for the field view the caller names.
 *
 * @package Ichiloto\Editor\Field
 */
final readonly class MapWalkMeasurement
{
    /** The most points one measurement takes: a start, an end and the waypoints between. */
    public const int MAX_POINTS = 64;

    /** Seconds rounded for the wire, finer than any author reads. */
    private const int SECONDS_PRECISION = 2;

    /**
     * @param FieldViewport $view The field view screens are counted in.
     * @param float|null $dashSpeedMultiplier How many times faster dashing is than walking, as the game authors it; null when the player cannot dash here.
     * @param string|null $dashUnavailable Why the player cannot dash here, when the multiplier is null; the game authoring no dash otherwise.
     */
    public function __construct(
        private ProjectMap $map,
        private FieldViewport $view,
        private FieldMetric $metric = new FieldMetric(),
        private ?float $dashSpeedMultiplier = null,
        private ?string $dashUnavailable = null,
    ) {
        if ($dashSpeedMultiplier !== null && (! is_finite($dashSpeedMultiplier) || $dashSpeedMultiplier <= 0.0)) {
            throw new InvalidArgumentException('A dash speed must be a positive, finite multiple of walking speed.');
        }
    }

    /**
     * Measures the walk through the points in order.
     *
     * @param list<array{0: int, 1: int}> $points The start, any waypoints, then the end, as [x, y].
     * @return array{
     *     points: list<array{0: int, 1: int}>, reachable: bool, problem: ?string, path: list<array{0: int, 1: int}>,
     *     steps: int, across: int, down: int, walkSeconds: float, dashSeconds: ?float, dashSpeed: ?float, dashUnavailable: ?string,
     *     stepSeconds: float, view: array{columns: int, rows: int, zoom: float}, screens: float,
     *     encounters: ?array{rate: int, everyTile: bool, countedSteps: float, expected: float, troops: list<string>},
     *     legs: list<array{from: array{0: int, 1: int}, to: array{0: int, 1: int}, steps: int, walkSeconds: float, dashSeconds: ?float, expectedEncounters: ?float}>
     * }
     * @throws InvalidArgumentException When fewer than two points or more than {@see MAX_POINTS} are named.
     */
    public function measure(array $points): array
    {
        if (count($points) < 2 || count($points) > self::MAX_POINTS) {
            throw new InvalidArgumentException(sprintf('A walk is measured between 2 and %d points.', self::MAX_POINTS));
        }
        $source = MapSourceReader::readDeclarations($this->map->mapId, $this->map->getEditableData(), $this->map->getLayerSet(),
            $this->map->getEventLayerCells());
        $collisions = $this->map->getResolvedCollisionMap();
        $walking = MapReachability::readMap($this->map->mapId, $source['data'], $collisions);
        $encounters = EncounterTable::readBlock(is_array($source['data']['encounters'] ?? null) ? $source['data']['encounters'] : null,
            MapAreaCatalog::readMap($source['data'], $source['tiles']), $this->map->resolveEncounterTerrain());
        $stepSeconds = $this->metric->getWalkSeconds(new Vector2(1, 0));

        $problem = $this->findPointProblem($walking, $points);
        $path = [$points[0]];
        $legs = [];
        $troops = [];
        $counted = 0.0;
        for ($index = 1; $problem === null && $index < count($points); $index++) {
            $route = $walking->findRoute($points[$index - 1], $points[$index]);
            if ($route === null) {
                $problem = sprintf('No walk leads from (%d, %d) to (%d, %d).', ...[...$points[$index - 1], ...$points[$index]]);
                break;
            }
            $legCounted = 0.0;
            foreach (array_slice($route, 1) as [$x, $y]) {
                $position = new Vector2($x, $y);
                $weight = $encounters->getStepWeight(CollisionType::tryFrom($collisions[$y][$x] ?? -1), $position);
                if ($weight > 0.0) {
                    $legCounted += $weight;
                    $troops += $encounters->getTroopWeightsAt($position);
                }
            }
            $counted += $legCounted;
            $steps = count($route) - 1;
            $legs[] = ['from' => $points[$index - 1], 'to' => $points[$index], 'steps' => $steps,
                'walkSeconds' => $this->roundSeconds($steps * $stepSeconds), 'dashSeconds' => $this->measureDashSeconds($steps * $stepSeconds),
                'expectedEncounters' => $encounters->hasEncounters ? round($legCounted / $encounters->averageStepsBetween, 2) : null];
            array_push($path, ...array_slice($route, 1));
        }
        if ($problem !== null) {
            $path = [];
            $legs = [];
        }

        [$across, $down] = self::countHeadings($path);
        $steps = $across + $down;

        return [
            'points' => $points,
            'reachable' => $problem === null,
            'problem' => $problem,
            'path' => $path,
            'steps' => $steps,
            'across' => $across,
            'down' => $down,
            'walkSeconds' => $this->roundSeconds($steps * $stepSeconds),
            'dashSeconds' => $this->measureDashSeconds($steps * $stepSeconds),
            'dashSpeed' => $this->dashSpeedMultiplier,
            'dashUnavailable' => $this->dashSpeedMultiplier === null
                ? $this->dashUnavailable ?? 'This game authors no dash speed, so only walking time is shown.' : null,
            'stepSeconds' => round($stepSeconds, 4),
            'view' => ['columns' => $this->view->columns, 'rows' => $this->view->rows, 'zoom' => $this->view->zoom],
            'screens' => round($across / $this->view->columns + $down / $this->view->rows, 2),
            'encounters' => $encounters->hasEncounters ? [
                'rate' => $encounters->averageStepsBetween,
                'everyTile' => $encounters->countsEveryTile,
                'countedSteps' => $counted,
                'expected' => round($counted / $encounters->averageStepsBetween, 2),
                'troops' => array_map(strval(...), array_keys($troops)),
            ] : null,
            'legs' => $legs,
        ];
    }

    /**
     * Why a point cannot be on a walk: outside the map, solid, under a fixed
     * NPC, or a waypoint that would take the player off the map before the end.
     *
     * @param list<array{0: int, 1: int}> $points
     */
    private function findPointProblem(MapReachability $walking, array $points): ?string
    {
        foreach ($points as $index => [$x, $y]) {
            $standing = $walking->describeStandingProblem($x, $y);
            if ($standing !== null) {
                return sprintf('The player cannot stand at (%d, %d): it is %s.', $x, $y, $standing);
            }
            if ($index > 0 && $index < count($points) - 1 && $walking->isLeavingMapAt($x, $y)) {
                return sprintf('Stepping onto (%d, %d) takes the player off the map, so a walk cannot go on from it.', $x, $y);
            }
        }

        return null;
    }

    private function measureDashSeconds(float $walkSeconds): ?float
    {
        return $this->dashSpeedMultiplier === null ? null : $this->roundSeconds($walkSeconds / $this->dashSpeedMultiplier);
    }

    private function roundSeconds(float $seconds): float
    {
        return round($seconds, self::SECONDS_PRECISION);
    }

    /**
     * The steps a walk takes across and down.
     *
     * @param list<array{0: int, 1: int}> $path
     * @return array{0: int, 1: int}
     */
    private static function countHeadings(array $path): array
    {
        $across = 0;
        $down = 0;
        for ($index = 1; $index < count($path); $index++) {
            $across += abs($path[$index][0] - $path[$index - 1][0]);
            $down += abs($path[$index][1] - $path[$index - 1][1]);
        }

        return [$across, $down];
    }
}
