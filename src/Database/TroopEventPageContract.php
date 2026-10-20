<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Closure;
use Ichiloto\Engine\Battle\Events\BattleEventPage;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandField;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use InvalidArgumentException;

/**
 * Why the Engine would refuse a troop's event pages or knockout floors, read
 * through the Engine's own BattleEventPage, so the Editor's save and its
 * validation say what a battle would.
 */
final class TroopEventPageContract
{
    /**
     * @param array<array-key, mixed> $troop The troop as it would be saved.
     * @return string|null Why the Engine would refuse it, or null when it reads.
     */
    public static function findProblem(array $troop): ?string
    {
        $where = sprintf('Data/troops.php troop "%s"', is_scalar($troop['name'] ?? null) ? strval($troop['name']) : '(unnamed)');
        $members = is_array($troop['enemies'] ?? null) ? array_values($troop['enemies']) : [];

        foreach ($members as $index => $member) {
            $floor = is_array($member) ? ($member['knockoutFloorPercent'] ?? 0) : 0;
            if (! is_int($floor) || $floor < 0 || $floor > 99) {
                return sprintf('%s.enemies[%d].knockoutFloorPercent must be a whole number from 0 to 99.', $where, $index);
            }
        }

        try {
            BattleEventPage::readPages($troop['events'] ?? [], $where, count($members), ScriptCommandRegistry::getCatalog());
        } catch (InvalidArgumentException $problem) {
            return $problem->getMessage();
        }

        return null;
    }

    /**
     * Why the Engine would refuse the troops file as it would be saved.
     *
     * @param array<array-key, mixed> $whole The troops, as they would be saved.
     */
    public static function checkTroops(array $whole, string $projectRoot = ''): ?string
    {
        foreach ($whole as $troop) {
            if (is_array($troop) && ($problem = self::findProblem($troop)) !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * Rewrites what a troop's event pages name its members by when members
     * move: a page's enemy condition and every battle command naming a
     * member, in every arm. A member a page still names cannot be removed.
     *
     * @param array<string, mixed> $troop The troop.
     * @param Closure(int): ?int $map The member's new index, or null when it is removed.
     * @return array<string, mixed> The troop with its pages renumbered.
     * @throws RecordRefusal When a page names a removed member.
     */
    public static function remapMembers(array $troop, Closure $map): array
    {
        if (! is_array($troop['events'] ?? null)) {
            return $troop;
        }

        foreach ($troop['events'] as $page => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $where = sprintf('Event page %d', intval($page) + 1);
            if (is_array($entry['conditions'] ?? null) && is_int($entry['conditions']['enemy'] ?? null)) {
                $entry['conditions']['enemy'] = self::mapMember($entry['conditions']['enemy'], $map, $where);
            }
            if (is_array($entry['commands'] ?? null)) {
                $entry['commands'] = self::remapCommands($entry['commands'], $map, $where);
            }
            $troop['events'][$page] = $entry;
        }

        return $troop;
    }

    /** @param array<array-key, mixed> $commands */
    private static function remapCommands(array $commands, Closure $map, string $where): array
    {
        $definitions = ScriptCommandRegistry::getCatalog()->definitions;

        foreach ($commands as $index => $command) {
            if (! is_array($command)) {
                continue;
            }
            foreach ($definitions[strval($command['type'] ?? '')]->fields ?? [] as $field) {
                $member = $field->reference === ScriptCommandReference::TROOP_MEMBER ? ScriptCommandField::readPath($command, $field->key) : null;
                if (is_int($member)) {
                    $target = &$command;
                    foreach (explode('.', $field->key) as $segment) { $target = &$target[$segment]; }
                    $target = self::mapMember($member, $map, $where);
                    unset($target);
                }
            }
            foreach (['then', 'else', 'commands', 'cancel'] as $arm) {
                if (is_array($command[$arm] ?? null)) {
                    $command[$arm] = self::remapCommands($command[$arm], $map, $where);
                }
            }
            foreach (is_array($command['options'] ?? null) ? $command['options'] : [] as $option => $choice) {
                if (is_array($choice) && is_array($choice['then'] ?? null)) {
                    $command['options'][$option]['then'] = self::remapCommands($choice['then'], $map, $where);
                }
            }
            $commands[$index] = $command;
        }

        return $commands;
    }

    private static function mapMember(int $member, Closure $map, string $where): int
    {
        return $map($member) ?? throw new RecordRefusal(sprintf(
            '%s names member %d, so it cannot be removed. Change that page first.', $where, $member + 1));
    }
}
