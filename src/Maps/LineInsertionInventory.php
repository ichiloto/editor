<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Events\CommandMapContext;
use Ichiloto\Engine\Events\Triggers\SleepEventTrigger;

/**
 * Finds every coordinate a row or column insertion moves, across the
 * project's evaluated data files.
 *
 * A coordinate moves when it is in the edited map's space and at or beyond
 * the insertion line. Some places are always in a known map's space: the
 * map's own NPCs, event areas and bed spawn points, a transfer's spawn point
 * into the map from any file, a starting position. Script coordinates are in
 * the space of the map the script is on at that command, which changes with
 * every transfer. Scripts are followed the way validation follows them
 * ({@see CommandMapContext}): a map's scripts start on that map, a cutscene
 * starts on its start map, and a reusable event script starts on every map
 * that runs it. A coordinate that may be on the edited map or another one
 * is never guessed: it is reported for a hand edit.
 *
 * A context is the list of maps a command may run on; an empty list means
 * nothing known runs it, and null means the map is not known at all.
 *
 * @package Ichiloto\Editor\Maps
 */
final class LineInsertionInventory
{
    /** @var array<string, array<string, array{path: list<int|string>, value: int}>> Moves by file, keyed by path. */
    private array $shifts = [];

    /** @var array<string, array<string, array{path: list<int|string>, reason: string}>> Hand edits by file, keyed by path. */
    private array $handEdits = [];

    /** @var array<string, list<list<string>|null>> The contexts each reusable event script is started from. */
    private array $commonEventStarts = [];

    /** @var array<string, list<string>> The maps whose events start each cutscene. */
    private array $cinematicTriggerMaps = [];

    /** @var list<list<string>|null> Every context a walk reached through a transfer. */
    private array $transferContexts = [];

    /**
     * @param LineInsertion $insertion The insertion.
     * @param array<string, array{file: string, commands: mixed}> $commonEvents Reusable event scripts by id.
     */
    public function __construct(
        private readonly LineInsertion $insertion,
        private readonly array $commonEvents,
    ) {
    }

    /**
     * Moves by file, in the order found.
     *
     * @return array<string, list<array{path: list<int|string>, value: int}>>
     */
    public function getShifts(): array
    {
        return array_map(array_values(...), $this->shifts);
    }

    /**
     * Coordinates that must be moved by hand, by file.
     *
     * @return array<string, list<array{path: list<int|string>, reason: string}>>
     */
    public function getHandEdits(): array
    {
        return array_map(array_values(...), $this->handEdits);
    }

    /**
     * Adds one map's data file: its own coordinates when it is the edited
     * map, and everything in it that leads into the edited map.
     *
     * @param array<string, mixed> $data The evaluated data file.
     */
    public function addMapData(string $file, string $mapId, array $data): void
    {
        $isTarget = $mapId === $this->insertion->mapId;
        $here = [$mapId];

        if ($isTarget) {
            foreach (($data['worldObjects'] ?? []) as $index => $object) {
                $this->shiftPoint($file, ['worldObjects', $index, 'anchor'], $object['anchor']);
                foreach (($object['covers']['cells'] ?? []) as $cellIndex => $cell) {
                    $axis = $this->insertion->axis === 'x' ? 0 : 1;
                    $coordinate = $cell[$axis];
                    if (is_int($coordinate) && $coordinate >= $this->insertion->at) {
                        $path = ['worldObjects', $index, 'covers', 'cells', $cellIndex, $axis];
                        $this->shifts[$file][self::formatKey($path)] = ['path' => $path, 'value' => $this->insertion->getShiftedCoordinate($coordinate)];
                    }
                }
            }
        }

        foreach ((array) ($data['npcs'] ?? []) as $index => $npc) {
            if (! is_array($npc)) {
                continue;
            }
            if ($isTarget) {
                $this->shiftPoint($file, ['npcs', $index], $npc);
                if (is_array($npc['wanderArea'] ?? null)) {
                    $this->shiftArea($file, ['npcs', $index, 'wanderArea'], $npc['wanderArea']);
                }
            }
            $this->walkCommands($npc['script'] ?? [], ['npcs', $index, 'script'], $here, $file);
            foreach ((array) ($npc['dialogue'] ?? []) as $variantIndex => $variant) {
                if (is_array($variant) && is_array($variant['script'] ?? null)) {
                    $this->walkCommands($variant['script'], ['npcs', $index, 'dialogue', $variantIndex, 'script'], $here, $file);
                }
            }
        }

        foreach ((array) ($data['events'] ?? []) as $marker => $event) {
            if (! is_array($event)) {
                continue;
            }
            if ($isTarget && is_array($event['area'] ?? null)) {
                $this->shiftArea($file, ['events', $marker, 'area'], $event['area']);
            }
            $eventData = is_array($event['data'] ?? null) ? $event['data'] : [];
            $destination = trim(strval($eventData['destinationMap'] ?? ''));
            $isBed = $destination === '' && $isTarget
                && ltrim(strval($event['class'] ?? ''), '\\') === SleepEventTrigger::class;
            if (is_array($eventData['spawnPoint'] ?? null) && ($destination === $this->insertion->mapId || $isBed)) {
                $this->shiftPoint($file, ['events', $marker, 'data', 'spawnPoint'], $eventData['spawnPoint']);
            }
            if (is_array($eventData['script'] ?? null) && $eventData['script'] !== []) {
                $this->walkCommands($eventData['script'], ['events', $marker, 'data', 'script'], $here, $file);
            } elseif (trim(strval($eventData['scriptId'] ?? '')) !== '') {
                $this->commonEventStarts[trim(strval($eventData['scriptId']))][] = $here;
            }
            $cinematicId = trim(strval($eventData['cinematicId'] ?? ''));
            if ($cinematicId !== '') {
                $this->cinematicTriggerMaps[$cinematicId][] = $mapId;
            }
        }

        foreach ((array) ($data['triggers'] ?? []) as $index => $trigger) {
            if (! is_array($trigger)) {
                continue;
            }
            if ($isTarget && is_array($trigger['trigger_area'] ?? null)) {
                $this->shiftArea($file, ['triggers', $index, 'trigger_area'], $trigger['trigger_area']);
            }
            if (trim(strval($trigger['destinationMap'] ?? '')) === $this->insertion->mapId && is_array($trigger['spawn_point'] ?? null)) {
                $this->shiftPoint($file, ['triggers', $index, 'spawn_point'], $trigger['spawn_point']);
            }
        }
    }

    /**
     * Adds the system data file's starting positions on the edited map.
     *
     * @param array<string, mixed> $system The evaluated system data.
     */
    public function addSystemData(string $file, array $system): void
    {
        foreach ((array) ($system['startingPositions'] ?? []) as $key => $position) {
            if (is_array($position) && trim(strval($position['destinationMap'] ?? '')) === $this->insertion->mapId
                && is_array($position['spawnPoint'] ?? null)) {
                $this->shiftPoint($file, ['startingPositions', $key, 'spawnPoint'], $position['spawnPoint']);
            }
        }
    }

    /**
     * Adds one cutscene: its cast and script start on its start map (or on
     * the maps whose events start it), and its finalizer may run from any
     * map the script reaches, since a skip can end the script anywhere.
     * Add every map first, so the maps that start it are known.
     *
     * @param array<string, mixed> $data The evaluated data file.
     */
    public function addCutscene(string $dataFile, string $scriptFile, string $id, array $data, mixed $commands): void
    {
        $startMap = trim(strval($data['startMap'] ?? ''));
        $start = $startMap !== ''
            ? [$startMap]
            : self::mergeContexts(array_map(static fn(string $map): array => [$map], $this->cinematicTriggerMaps[$id] ?? []));
        foreach ((array) ($data['cast'] ?? []) as $index => $entry) {
            if (is_array($entry)) {
                $this->shiftPointIn($dataFile, ['cast', $index], $entry, $start);
            }
        }
        $this->transferContexts = [];
        $end = $this->walkCommands($commands, [], $start, $scriptFile);
        $this->walkCommands($data['finalizer'] ?? [], ['finalizer'], self::mergeContexts([$start, $end, ...$this->transferContexts]), $dataFile);
    }

    /**
     * Adds every reusable event script, each starting on every map that
     * runs it. Add every map and cutscene first, so those starts are known.
     */
    public function addCommonEvents(): void
    {
        $starts = $this->commonEventStarts;
        foreach ($this->commonEvents as $id => $event) {
            $start = isset($starts[$id]) ? self::mergeContexts($starts[$id]) : [];
            $this->walkCommands($event['commands'], [], $start, $event['file'], [$id]);
        }
    }

    /**
     * Walks a command list from a context, moving coordinates in the edited
     * map's space when `$file` is given, and returns the context it ends in.
     * A reusable event script it runs is followed inline, without moving
     * anything, for the map it leaves the caller on and the scripts it runs.
     *
     * @param list<int|string> $path The list's path in its file.
     * @param list<string>|null $context
     * @param list<string> $stack Reusable event scripts being followed.
     * @return list<string>|null
     */
    private function walkCommands(mixed $commands, array $path, ?array $context, ?string $file, array $stack = []): ?array
    {
        if (! is_array($commands)) {
            return $context;
        }

        foreach ($commands as $index => $command) {
            if (! is_array($command)) {
                continue;
            }
            $at = [...$path, $index];
            $type = strval($command['type'] ?? '');
            if ($file !== null) {
                $this->shiftCommand($file, $type, $command, $at, $context);
            }

            if ($type === 'sequence') {
                $context = $this->walkCommands($command['commands'] ?? [], [...$at, 'commands'], $context, $file, $stack);
            }

            if ($type === 'parallel') {
                $ends = [];
                foreach ((array) ($command['lanes'] ?? []) as $laneIndex => $lane) {
                    if (! is_array($lane)) {
                        continue;
                    }
                    [$laneCommands, $lanePath] = array_is_list($lane)
                        ? [$lane, [...$at, 'lanes', $laneIndex]]
                        : [$lane['commands'] ?? [], [...$at, 'lanes', $laneIndex, 'commands']];
                    $ends[] = $this->walkCommands($laneCommands, $lanePath, $context, $file, $stack);
                }
                if ($ends !== []) {
                    $context = self::mergeContexts($ends);
                }
            }

            if ($type === 'common_event') {
                $id = trim(strval($command['id'] ?? ''));
                $this->commonEventStarts[$id][] = $context;
                if (isset($this->commonEvents[$id]) && ! in_array($id, $stack, true)) {
                    $context = $this->walkCommands($this->commonEvents[$id]['commands'], [], $context, null, [...$stack, $id]);
                }
            }

            $arms = CommandMapContext::getArms($command);
            if ($arms !== []) {
                $ends = [];
                foreach ($arms as $arm) {
                    $ends[] = $this->walkCommands($arm['commands'], [...$at, ...$arm['path']], $context, $file, $stack);
                }
                if (CommandMapContext::canSkipArms($command)) {
                    $ends[] = $context;
                }
                $context = self::mergeContexts($ends);
            }

            if ($type === 'transfer') {
                $destination = trim(strval($command['map'] ?? ''));
                if ($file !== null && $destination === $this->insertion->mapId) {
                    $this->shiftPoint($file, $at, $command);
                }
                $context = $destination !== '' ? [$destination] : null;
                $this->transferContexts[] = $context;
            }
        }

        return $context;
    }

    /**
     * Moves the map-space coordinates one command names. Relative movement
     * (route steps, retracing) and screen positions are not map cells.
     *
     * @param array<string, mixed> $command
     * @param list<int|string> $at The command's path.
     * @param list<string>|null $context
     */
    private function shiftCommand(string $file, string $type, array $command, array $at, ?array $context): void
    {
        switch ($type) {
            case 'move_player':
                $this->shiftPointIn($file, $at, $command, $context);
                break;
            case 'move_route':
                foreach ((array) ($command['waypoints'] ?? []) as $index => $waypoint) {
                    if (is_array($waypoint)) {
                        $this->shiftPointIn($file, [...$at, 'waypoints', $index], $waypoint, $context);
                    }
                }
                break;
            case 'camera':
                $this->shiftTargetIn($file, $at, $command, $context);
                foreach ((array) ($command['points'] ?? []) as $index => $point) {
                    if (is_array($point)) {
                        $this->shiftTargetIn($file, [...$at, 'points', $index], $point, $context);
                    }
                }
                break;
            case 'field_animation':
                $this->shiftTargetIn($file, $at, $command, $context);
                break;
            case 'stage_actor':
                is_array($command['actor'] ?? null)
                    ? $this->shiftPointIn($file, [...$at, 'actor'], $command['actor'], $context)
                    : $this->shiftPointIn($file, $at, $command, $context);
                break;
        }
    }

    /**
     * Moves a subject reference that names a map position, as the Engine
     * resolves one: the source's `target`, or the source itself.
     *
     * @param list<int|string> $path
     * @param array<string, mixed> $source
     * @param list<string>|null $context
     */
    private function shiftTargetIn(string $file, array $path, array $source, ?array $context): void
    {
        [$reference, $referencePath] = is_array($source['target'] ?? null)
            ? [$source['target'], [...$path, 'target']]
            : [$source, $path];
        $kind = strtolower(trim(strval($reference['kind'] ?? $reference['subject'] ?? '')));
        if ($kind === 'position') {
            $this->shiftPointIn($file, $referencePath, $reference, $context);
        }
    }

    /**
     * Moves a point whose map is the script's current map: surely when that
     * is the edited map, never when it is surely another, and otherwise
     * reports it for a hand edit when it would move.
     *
     * @param list<int|string> $path
     * @param array<string, mixed> $point
     * @param list<string>|null $context
     */
    private function shiftPointIn(string $file, array $path, array $point, ?array $context): void
    {
        if ($context === [$this->insertion->mapId]) {
            $this->shiftPoint($file, $path, $point);
            return;
        }
        if ($context !== null && ! in_array($this->insertion->mapId, $context, true)) {
            return;
        }
        $key = $this->insertion->axis;
        if (array_key_exists($key, $point) && (! is_int($point[$key]) || $point[$key] >= $this->insertion->at)) {
            $this->reportHandEdit($file, [...$path, $key], $context === null
                ? 'it runs on a map that is not known here'
                : sprintf('it may run on %s', implode(' or ', $context)));
        }
    }

    /**
     * Moves a point surely in the edited map's space. Only the insertion
     * axis moves; a point naming only the other axis stays.
     *
     * @param list<int|string> $path
     * @param array<string, mixed> $point
     */
    private function shiftPoint(string $file, array $path, array $point): void
    {
        $key = $this->insertion->axis;
        if (! array_key_exists($key, $point)) {
            return;
        }
        $value = $point[$key];
        if (is_int($value)) {
            if ($value >= $this->insertion->at) {
                $this->shifts[$file][self::formatKey([...$path, $key])] = [
                    'path' => [...$path, $key], 'value' => $this->insertion->getShiftedCoordinate($value),
                ];
            }
            return;
        }
        $this->reportHandEdit($file, [...$path, $key], 'it is not a whole number');
    }

    /**
     * Moves an area surely in the edited map's space: one starting at or
     * beyond the line moves, one straddling it stretches.
     *
     * @param list<int|string> $path
     * @param array<string, mixed> $area
     */
    private function shiftArea(string $file, array $path, array $area): void
    {
        $startKey = $this->insertion->axis;
        $sizeKey = $startKey === 'x' ? 'width' : 'height';
        $start = $area[$startKey] ?? 0;
        $size = $area[$sizeKey] ?? 1;
        if (! is_int($start) || ! is_int($size)) {
            $this->reportHandEdit($file, $path, 'its position or size is not a whole number');
            return;
        }
        [$newStart, $newSize] = $this->insertion->getShiftedSpan($start, $size);
        if ($newStart !== $start) {
            $this->shiftPoint($file, $path, $area);
        }
        if ($newSize !== $size) {
            $this->shifts[$file][self::formatKey([...$path, $sizeKey])] = ['path' => [...$path, $sizeKey], 'value' => $newSize];
        }
    }

    /** @param list<int|string> $path */
    private function reportHandEdit(string $file, array $path, string $reason): void
    {
        $this->handEdits[$file][self::formatKey($path)] = ['path' => $path, 'reason' => $reason];
    }

    /** @param list<int|string> $path */
    private static function formatKey(array $path): string
    {
        return implode("\0", $path);
    }

    /**
     * The maps any of several contexts may be on; unknown when any is.
     *
     * @param list<list<string>|null> $contexts
     * @return list<string>|null
     */
    private static function mergeContexts(array $contexts): ?array
    {
        $maps = [];
        foreach ($contexts as $context) {
            if ($context === null) {
                return null;
            }
            array_push($maps, ...$context);
        }
        $maps = array_values(array_unique($maps));
        sort($maps, SORT_STRING);

        return $maps;
    }
}
