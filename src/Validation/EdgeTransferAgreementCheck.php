<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Throwable;

/**
 * Reports walk-through transfers whose two maps disagree about where they meet.
 *
 * A transfer on a map's edge carries the player across that edge, so the
 * player must come out on the facing edge of the next map, still heading the
 * same way, and the way back must cross the matching edges. Leaving west and
 * arriving from the south puts the two maps in different places on each side.
 *
 * An edge exit is a transfer the player walks onto at the outermost ground
 * toward one edge: no open ground lies between it and that edge. A
 * building door near an edge has its doorstep beyond it, so it is not one.
 * Only links that are edge exits at both ends are judged; a one-way exit onto
 * a world map or into a room has its own checks. Unknown destinations and
 * unreadable maps are left to the checks that own them.
 */
final class EdgeTransferAgreementCheck
{
    /** How far from a map's edge, in cells, an exit or an arrival may lie and still be on that edge. */
    public const int EDGE_BAND = 2;

    private const array OPPOSITE = ['west' => 'east', 'east' => 'west', 'north' => 'south', 'south' => 'north'];

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $maps = [];
        $ground = [];
        foreach ($workspace->maps as $map) {
            $maps[$map->mapId] = $map;
        }
        $links = [];
        foreach ($maps as $map) {
            $ground[$map->mapId] = self::readOpenGround($map);
            if ($ground[$map->mapId] === null) {
                continue;
            }
            foreach (self::readEdgeExits($map, $ground[$map->mapId]) as $exit) {
                $links[$map->mapId][] = $exit;
            }
        }

        $issues = [];
        foreach ($links as $mapId => $exits) {
            foreach ($exits as $exit) {
                $destination = $maps[$exit['destination']] ?? null;
                if ($destination === null) {
                    continue;
                }
                $back = array_values(array_filter($links[$destination->mapId] ?? [],
                    static fn(array $return): bool => $return['destination'] === $mapId));
                if ($back === []) {
                    continue;
                }
                $issue = self::checkExit($maps[$mapId], $exit, $destination, $ground[$destination->mapId], $back);
                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    /**
     * The transfers on a map's edges: each with the edge it crosses, its cells, where it lands and the heading it
     * sets, when it sets one.
     *
     * @return list<array{marker: string, side: string, cells: list<array{0: int, 1: int}>, destination: string,
     *   arrival: array{0: int, 1: int}, heading: string|null}>
     */
    public static function readEdgeExits(ProjectMap $map, array $ground): array
    {
        $exits = [];
        try {
            [$width, $height] = [$map->getWidth(), $map->getHeight()];
            foreach ($map->getEventDefinitions() as $marker => $definition) {
                if (! is_array($definition) || ! is_a(strval($definition['class'] ?? ''), TransferPlayerTrigger::class, true)) {
                    continue;
                }
                $data = (array) ($definition['data'] ?? []);
                $spawn = $data['spawnPoint'] ?? null;
                if (! is_string($data['destinationMap'] ?? null) || ! is_numeric($spawn['x'] ?? null) || ! is_numeric($spawn['y'] ?? null)) {
                    continue;
                }
                $cells = array_map(static fn($cell): array => [(int) $cell[0], (int) $cell[1]],
                    $map->getEventTriggerArea((string) $marker)?->cells ?? []);
                $side = self::findSide($cells, $width, $height);
                if ($side === null || ! self::isOutermost($cells, $side, $width, $height, $ground)) {
                    continue;
                }
                $sprite = $data['spawnSprite'] ?? null;
                $heading = is_array($sprite) && is_string($sprite[0] ?? null) ? strtolower($sprite[0]) : null;
                $exits[] = ['marker' => (string) $marker, 'side' => $side, 'cells' => $cells, 'destination' => $data['destinationMap'],
                    'arrival' => [(int) $spawn['x'], (int) $spawn['y']], 'heading' => $heading];
            }
        } catch (Throwable) {
            return [];
        }

        return $exits;
    }

    /**
     * Whether a straight walk from the cell toward the side crosses only open ground before it steps onto one of
     * these exits.
     *
     * @param array{0: int, 1: int} $cell
     * @param array<string, true> $ground
     * @param list<array{cells: list<array{0: int, 1: int}>}> $exits
     */
    private static function leadsBackThrough(array $cell, string $side, int $width, int $height, array $ground, array $exits): bool
    {
        $targets = [];
        foreach ($exits as $exit) {
            foreach ($exit['cells'] as [$x, $y]) {
                $targets["{$x},{$y}"] = true;
            }
        }
        [$dx, $dy] = ['west' => [-1, 0], 'east' => [1, 0], 'north' => [0, -1], 'south' => [0, 1]][$side];
        for ([$x, $y] = [$cell[0] + $dx, $cell[1] + $dy]; $x >= 0 && $y >= 0 && $x < $width && $y < $height; $x += $dx, $y += $dy) {
            if (isset($targets["{$x},{$y}"])) {
                return true;
            }
            if (! isset($ground["{$x},{$y}"])) {
                return false;
            }
        }
        return false;
    }

    /**
     * The cells a player may stand on as the map's physical occupancy resolves them, keyed "x,y"; null for a map
     * whose occupancy cannot be read, which its own checks report.
     *
     * @return array<string, true>|null
     */
    private static function readOpenGround(ProjectMap $map): ?array
    {
        try {
            $grid = $map->getResolvedCollisionMap();
        } catch (Throwable) {
            return null;
        }
        $ground = [];
        foreach ($grid as $y => $row) {
            foreach ($row as $x => $collision) {
                if (! (CollisionType::tryFrom((int) $collision) ?? CollisionType::SOLID)->blocksMovement()) {
                    $ground["{$x},{$y}"] = true;
                }
            }
        }
        return $ground;
    }

    /**
     * The one edge every cell lies on, within the edge band; null for cells in from every edge or near two edges
     * with no nearest one.
     *
     * @param list<array{0: int, 1: int}> $cells
     */
    public static function findSide(array $cells, int $width, int $height): ?string
    {
        if ($cells === [] || $width < 1 || $height < 1) {
            return null;
        }
        $reach = [
            'west' => max(array_map(static fn(array $cell): int => $cell[0], $cells)),
            'east' => max(array_map(static fn(array $cell): int => $width - 1 - $cell[0], $cells)),
            'north' => max(array_map(static fn(array $cell): int => $cell[1], $cells)),
            'south' => max(array_map(static fn(array $cell): int => $height - 1 - $cell[1], $cells)),
        ];
        $reach = array_filter($reach, static fn(int $distance): bool => $distance <= self::EDGE_BAND);
        if ($reach === []) {
            return null;
        }
        asort($reach);
        $nearest = array_keys($reach);
        return count($nearest) > 1 && $reach[$nearest[0]] === $reach[$nearest[1]] ? null : $nearest[0];
    }

    /**
     * Whether no open ground lies between these cells and the edge.
     *
     * @param list<array{0: int, 1: int}> $cells
     * @param array<string, true> $ground
     */
    private static function isOutermost(array $cells, string $side, int $width, int $height, array $ground): bool
    {
        [$dx, $dy] = ['west' => [-1, 0], 'east' => [1, 0], 'north' => [0, -1], 'south' => [0, 1]][$side];
        foreach ($cells as [$x, $y]) {
            for ($x += $dx, $y += $dy; $x >= 0 && $y >= 0 && $x < $width && $y < $height; $x += $dx, $y += $dy) {
                if (isset($ground["{$x},{$y}"]) && ! in_array([$x, $y], $cells, true)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param array{marker: string, side: string, cells: list<array{0: int, 1: int}>, destination: string,
     *   arrival: array{0: int, 1: int}, heading: string|null} $exit
     * @param list<array{marker: string, side: string, cells: list<array{0: int, 1: int}>, destination: string,
     *   arrival: array{0: int, 1: int}, heading: string|null}> $destinationExits
     */
    private static function checkExit(ProjectMap $map, array $exit, ProjectMap $destination, array $ground,
        array $destinationExits): ?Issue
    {
        $facing = self::OPPOSITE[$exit['side']];
        [$width, $height] = [$destination->getWidth(), $destination->getHeight()];
        // The player arrives facing away from the way back: walking from the arrival toward the facing edge over
        // open ground reaches it, however deep the doorway or cave mouth they came through.
        $arrivesOnEdge = self::leadsBackThrough($exit['arrival'], $facing, $width, $height, $ground, $destinationExits);
        $where = sprintf('%s event %s', $map->mapId, $exit['marker']);
        $problems = [];
        if (! $arrivesOnEdge) {
            $problems[] = sprintf('it arrives at (%d, %d) on %s, where walking %s does not lead back to it',
                $exit['arrival'][0], $exit['arrival'][1], $destination->mapId, $facing);
        }
        if ($exit['heading'] !== null && $exit['heading'] !== $exit['side']) {
            $problems[] = sprintf('it leaves heading %s but turns the player %s on arrival', $exit['side'], $exit['heading']);
        }
        foreach ($destinationExits as $back) {
            if ($back['side'] !== $facing) {
                $problems[] = sprintf('the way back, %s event %s, leaves by its %s edge, not its %s edge',
                    $destination->mapId, $back['marker'], $back['side'], $facing);
            }
        }
        if ($problems === []) {
            return null;
        }

        return new Issue(Severity::ERROR, $where, sprintf('The player crosses the %s edge here: %s.', $exit['side'], implode('; ', $problems)),
            sprintf('Arrive on the %s edge of %s heading %s, and return through that edge.', $facing, $destination->mapId,
                $exit['side']),
            map: $map->mapId, cells: $exit['cells']);
    }
}
