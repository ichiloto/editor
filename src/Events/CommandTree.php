<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use Closure;

/**
 * A script's commands as one tree: every command, with those its blocks,
 * lanes, arms and options hold.
 *
 * @package Ichiloto\Editor\Events
 */
final class CommandTree
{
    private function __construct()
    {
    }

    /**
     * Visits every command of a command tree, nested blocks, lanes, arms and
     * options included, rewriting each with what the visitor returns.
     *
     * @param array<int|string, mixed> $commands
     * @param Closure(array<string, mixed>): array<string, mixed> $visit
     * @return array<int|string, mixed>
     */
    public static function rewrite(array $commands, Closure $visit): array
    {
        foreach ($commands as $index => $command) {
            if (! is_array($command)) {
                continue;
            }
            if (is_string($command['type'] ?? null)) {
                $command = $visit($command);
            }
            foreach ($command as $key => $value) {
                if (is_array($value)) {
                    $command[$key] = self::rewrite($value, $visit);
                }
            }
            $commands[$index] = $command;
        }

        return $commands;
    }
}
