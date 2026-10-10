<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

/**
 * How an event script's alternative arms share the map it runs on.
 *
 * Validation follows the current map to check the NPCs a command names, and
 * a map edit follows it to know which coordinates are in that map's space.
 * Both walk a command's arms the same way: every arm of a choice or branch
 * starts on the map the command runs on, not where a sibling arm ended, and
 * a command that can run none of its arms leaves the map unchanged.
 *
 * @package Ichiloto\Editor\Events
 */
final class CommandMapContext
{
    private function __construct()
    {
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

        return $arms;
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
            || ($type === 'choice' && ! isset($command['cancel']));
    }
}
