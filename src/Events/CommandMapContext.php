<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use Closure;
use Ichiloto\Editor\Database\StorybookPlaybackFields;
use Ichiloto\Engine\Field\MapDoorReference;

/**
 * How an event script's alternative arms and parallel lanes share the map
 * it runs on.
 *
 * Validation follows the current map to check the NPCs a command names, and
 * a map edit follows it to know which coordinates are in that map's space.
 * Both walk a command's arms the same way: every arm of a choice or branch
 * starts on the map the command runs on, not where a sibling arm ended, and
 * a command that can run none of its arms leaves the map unchanged.
 *
 * Parallel lanes run together on the game's one current map. The Engine
 * ticks every lane in turn, and a transfer in any lane suspends the session
 * and moves all of them, so a lane can be on the map the block started on or
 * on any map a sibling lane transfers to, whatever the lanes' file order.
 *
 * @package Ichiloto\Editor\Events
 */
final class CommandMapContext
{
    private function __construct()
    {
    }

    /**
     * The map a command sends the player to, or null when it sends them
     * nowhere: a `transfer` names its map, and a `transfer_through_door`
     * arrives where its door leads, as the resolver given reads the door.
     * An empty id is a transfer whose destination is unknown: its map, or
     * its door, names nothing the resolver can read.
     *
     * @param array<string, mixed> $command
     * @param (Closure(array<string, mixed>): ?string)|null $resolveDoor A door command's destination map, or null when it leads nowhere.
     */
    public static function findDestinationMap(array $command, ?Closure $resolveDoor = null): ?string
    {
        return match ($command['type'] ?? null) {
            'transfer' => trim(strval($command['map'] ?? '')),
            MapDoorReference::COMMAND => $resolveDoor?->__invoke($command) ?? '',
            default => null,
        };
    }

    /**
     * The alternative arms a command branches into, in order: each choice
     * option's `then`, then `then`, `else` and `cancel`.
     *
     * @param array<string, mixed> $command The command.
     * @return list<array{path: list<int|string>, commands: array<int, mixed>}> Each arm and its path from the command.
     */
    public static function getArms(array $command): array
    {
        $arms = [];
        foreach ((array) ($command['options'] ?? []) as $index => $option) {
            if (is_array($option)) {
                $arms[] = ['path' => ['options', $index, 'then'], 'commands' => (array) ($option['then'] ?? [])];
            }
        }
        foreach (['then', 'else', 'cancel'] as $arm) {
            if (array_key_exists($arm, $command)) {
                $arms[] = ['path' => [$arm], 'commands' => (array) ($command[$arm] ?? [])];
            }
        }

        $finish = StorybookPlaybackFields::getFinishField();
        $commands = StorybookPlaybackFields::getFinishCommands($command);
        if ($finish !== null && $commands !== null) {
            $arms[] = ['path' => [$finish], 'commands' => $commands];
        }

        return $arms;
    }

    /**
     * The lanes of a parallel command, in order. A lane is a command list or
     * a keyed lane holding `commands`.
     *
     * @param array<string, mixed> $command The command.
     * @return list<array{path: list<int|string>, commands: array<int, mixed>}> Each lane and its path from the command.
     */
    public static function getLanes(array $command): array
    {
        if (($command['type'] ?? null) !== 'parallel') {
            return [];
        }

        $lanes = [];
        foreach ((array) ($command['lanes'] ?? []) as $index => $lane) {
            if (! is_array($lane)) {
                continue;
            }
            $lanes[] = array_is_list($lane)
                ? ['path' => ['lanes', $index], 'commands' => $lane]
                : ['path' => ['lanes', $index, 'commands'], 'commands' => (array) ($lane['commands'] ?? [])];
        }

        return $lanes;
    }

    /**
     * Every map a command list can transfer to, through its blocks, arms,
     * lanes and the reusable event scripts it runs.
     *
     * @param array<int, mixed> $commands The command list.
     * @param callable(string): ?array<int, mixed> $findCommonEvent The commands of a reusable event script, or null when it is unknown.
     * @param list<string> $stack Reusable event scripts already being followed.
     * @param (Closure(array<string, mixed>): ?string)|null $resolveDoor A door command's destination map ({@see findDestinationMap()}).
     * @return list<string> Each destination map id once; an empty id for a transfer that names no map.
     */
    public static function listTransferDestinations(array $commands, callable $findCommonEvent, array $stack = [], ?Closure $resolveDoor = null): array
    {
        $destinations = [];
        foreach ($commands as $command) {
            if (! is_array($command)) {
                continue;
            }
            $type = strval($command['type'] ?? '');
            $nested = [];
            $destination = self::findDestinationMap($command, $resolveDoor);
            if ($destination !== null) {
                $destinations[] = $destination;
            }
            if ($type === 'sequence') {
                $nested[] = [(array) ($command['commands'] ?? []), $stack];
            }
            if ($type === 'common_event') {
                $id = trim(strval($command['id'] ?? ''));
                $script = in_array($id, $stack, true) ? null : $findCommonEvent($id);
                if ($script !== null) {
                    $nested[] = [$script, [...$stack, $id]];
                }
            }
            foreach ([...self::getLanes($command), ...self::getArms($command)] as $block) {
                $nested[] = [$block['commands'], $stack];
            }
            foreach ($nested as [$list, $listStack]) {
                $destinations = [...$destinations, ...self::listTransferDestinations($list, $findCommonEvent, $listStack, $resolveDoor)];
            }
        }

        return array_values(array_unique($destinations));
    }

    /**
     * Whether the command can finish without running any of its arms: a
     * branch missing `then` or `else`, or a choice without `cancel`.
     *
     * @param array<string, mixed> $command The command.
     */
    public static function canSkipArms(array $command): bool
    {
        $type = strval($command['type'] ?? '');

        return ($type === 'branch' && (! isset($command['then']) || ! isset($command['else'])))
            || ($type === 'choice' && ! isset($command['cancel']))
            || $type === StorybookPlaybackFields::getCommandType();
    }
}
