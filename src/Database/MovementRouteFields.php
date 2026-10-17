<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Events\Interpreter\MovementRouteRunner;
use Ichiloto\Engine\Exceptions\MovementRouteException;
use InvalidArgumentException;

/** Author the runtime's three route shapes, not a second movement planner. */
final class MovementRouteFields
{
    public const string MODE_FIELD = '_routeMode';
    private const array MODES = ['steps', 'waypoints', 'retrace'];

    /** The same root-key convention used by the record layer's command frames. */
    public static function getFrameOwnerPath(RecordSchema $schema, array $frame): array
    {
        if ($frame === []) { return []; }
        return is_string($frame[0]) && isset($schema->commandLists[$frame[0]])
            ? $frame : [$schema->subList?->key, ...$frame];
    }

    /** Resolve the invocation's outer command list, not sibling scripts or a nested branch alone. */
    public static function getOwnerCommands(array $payload, array $path = []): array
    {
        if ($path === []) {
            return (array) ($payload['commands'] ?? $payload['script'] ?? []);
        }
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) { return []; }
            $current = $current[$segment];
            if (in_array($segment, ['commands', 'script'], true)) {
                return is_array($current) ? $current : [];
            }
        }
        return [];
    }

    /** Only executable arms may declare a route; preserved annotations are not scripts. */
    public static function getChildCommandLists(array $command): array
    {
        return match ($command['type'] ?? '') {
            'sequence' => [(array) ($command['commands'] ?? [])],
            'branch' => [(array) ($command['then'] ?? []), (array) ($command['else'] ?? [])],
            'choice' => [...array_map(static fn(array $option): array => (array) ($option['then'] ?? []),
                array_filter((array) ($command['options'] ?? []), is_array(...))), (array) ($command['cancel'] ?? [])],
            'parallel' => array_map(static fn(array $lane): array => array_is_list($lane) ? $lane : (array) ($lane['commands'] ?? []),
                array_filter((array) ($command['lanes'] ?? []), is_array(...))),
            default => [],
        };
    }

    public static function getMode(array $entry): ?string
    {
        $modes = array_values(array_intersect(self::MODES, array_keys($entry)));
        // New commands retain the established Add Step workflow; no mode is stored until authored.
        if ($modes === []) { return 'steps'; }
        return count($modes) === 1 ? $modes[0] : null;
    }

    /** @return list<RecordField> */
    public static function getFields(array $entry, bool $cinematic = false): array
    {
        $mode = self::getMode($entry);
        return [
            new RecordField('subject', 'Subject', options: $cinematic ? ['player', 'npc', 'staged_actor'] : ['player', 'npc']),
            new RecordField('npcId', 'NPC Id', reference: 'map_npcs', removeWhenEmpty: true, allowsNone: true),
            ...($cinematic ? [new RecordField('actorId', 'Staged Actor', reference: 'cinematic_cast', removeWhenEmpty: true, allowsNone: true)] : []),
            new RecordField(self::MODE_FIELD, 'Route Mode', options: self::MODES, displayDefault: $mode ?? '(Choose one route mode)'),
            new RecordField('secondsPerStep', 'Seconds Per Step', InputControlType::FLOAT, removeWhenEmpty: true),
            new RecordField('speed', 'Steps Per Second', InputControlType::FLOAT, removeWhenEmpty: true),
            new RecordField('wait', 'Wait For Completion', InputControlType::BOOLEAN, ['true'], removeWhenEmpty: false),
            ...($mode === 'retrace'
                ? [RecordField::reference('retrace', 'Recorded Route', 'cinematic_movement_routes')]
                : [new RecordField('remember', 'Remember As', removeWhenEmpty: true)]),
        ];
    }

    public static function getPointList(array $entry): ?RecordSubList
    {
        return match (self::getMode($entry)) {
            'steps' => RecordSchemaCatalog::routeStepList(),
            'waypoints' => new RecordSubList(
                key: 'waypoints', prefix: 'waypoint', singular: 'waypoint',
                fields: [
                    new RecordField('x', 'X', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '(Keep current X)'),
                    new RecordField('y', 'Y', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '(Keep current Y)'),
                ],
                blank: [],
                prepareEdit: static function (array $point, string $field): array {
                    self::assertPathOptions(['waypoints' => [$point]]);
                    return $point;
                },
            ),
            default => null,
        };
    }

    public static function prepareEdit(array $entry, string $field): array
    {
        if (($entry['type'] ?? '') !== 'move_route') { return $entry; }
        if ($field === self::MODE_FIELD) {
            $mode = $entry[self::MODE_FIELD] ?? null;
            if (!in_array($mode, self::MODES, true)) {
                throw new InvalidArgumentException('Choose steps, waypoints or retrace.');
            }
            unset($entry[self::MODE_FIELD]);
            foreach (self::MODES as $other) {
                if ($other !== $mode) { unset($entry[$other]); }
            }
            // Empty drafts ask the author for points/a reference, never invent coordinates or ids.
            $entry[$mode] ??= $mode === 'retrace' ? '' : [];
            if ($mode === 'retrace') { unset($entry['remember']); }
        }
        if (in_array($field, ['remember', 'retrace'], true) && array_key_exists($field, $entry)) {
            $binding = [$field === 'retrace' ? 'retrace' : 'steps' => $field === 'retrace' ? $entry[$field] : [],
                'subject' => $entry['subject'] ?? 'player'];
            if (isset($entry['remember'])) { $binding['remember'] = $entry['remember']; }
            self::assertPathOptions($binding);
        }
        if (in_array($field, [self::MODE_FIELD, 'subject', 'remember', 'retrace'], true)
            && strtolower(trim(strval($entry['subject'] ?? 'player'))) === 'staged_actor'
            && (isset($entry['waypoints']) || isset($entry['remember']) || isset($entry['retrace']))) {
            throw new InvalidArgumentException('Waypoints and recorded routes require a real player or NPC subject.');
        }
        return $entry;
    }

    /** Complete changed path options at the real owner's save boundary, not while authoring drafts. */
    public static function assertChangedCommandsValid(array $old, array $new): void
    {
        if ($old === $new) { return; }
        $loadedPaths = array_map(self::getPathOptions(...), self::getRouteCommands($old));
        foreach (self::getRouteCommands($new) as $command) {
            $unchanged = array_search(self::getPathOptions($command), $loadedPaths, true);
            // Reordering unrelated commands preserves legacy paths, but adding another does not.
            if ($unchanged !== false) { unset($loadedPaths[$unchanged]); continue; }
            self::assertPathOptions($command);
            if (array_key_exists('steps', $command)) { self::assertStepsComplete($command['steps']); }
        }
    }

    /** @return list<array> */
    private static function getRouteCommands(array $payload): array
    {
        $commands = ($payload['type'] ?? '') === 'move_route' ? [$payload] : [];
        foreach ($payload as $child) {
            if (is_array($child)) { array_push($commands, ...self::getRouteCommands($child)); }
        }
        return $commands;
    }

    private static function getPathOptions(array $command): array
    {
        $path = [];
        foreach (['steps', 'waypoints', 'retrace', 'remember', 'subject'] as $key) {
            if (array_key_exists($key, $command)) { $path[$key] = $command[$key]; }
        }
        return $path;
    }

    /** The Engine extension validator handles waypoints/retrace, not cardinal draft completeness. */
    private static function assertStepsComplete(mixed $steps): void
    {
        if (!is_array($steps) || $steps === []) {
            throw new InvalidArgumentException('A movement route requires at least one step.');
        }
        foreach ($steps as $step) {
            if (!is_array($step) || !is_string($step['direction'] ?? null)
                || !in_array(strtolower(trim($step['direction'])), MovementRouteRunner::DIRECTIONS, true)) {
                throw new InvalidArgumentException('Each movement-route step requires a supported direction.');
            }
            $count = $step['count'] ?? 1;
            if (!is_numeric($count) || floatval($count) !== floatval(intval($count)) || intval($count) < 0) {
                throw new InvalidArgumentException('Movement-route step count must be a non-negative integer.');
            }
            if (array_key_exists('faceOnly', $step) && !is_bool($step['faceOnly'])) {
                throw new InvalidArgumentException('Movement-route step faceOnly must be a boolean.');
            }
        }
    }

    private static function assertPathOptions(array $entry): void
    {
        try {
            MovementRouteRunner::validatePathOptions($entry);
        } catch (MovementRouteException $error) {
            throw new InvalidArgumentException($error->getMessage(), previous: $error);
        }
    }
}
