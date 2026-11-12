<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Battle\Presentation\BattleConditionEffects;

/**
 * The groups of effects a project binds to battle conditions under `ui.battle.conditions`, as
 * {@see BattleConditionEffects} reads them: the aura a state shows while it lasts, the aura a stat stage shows
 * while it is raised or lowered, and the contact effect a state plays when it intercepts an attack.
 */
enum BattleConditionGroup: string
{
    case STATES = 'states';
    case STAT_STAGES = 'statStages';
    case INTERCEPTIONS = 'interceptions';

    /** Where the groups are in the project's config.php. */
    public const string ROOT = 'ui.battle.conditions';

    /** The roles a stat stage binds an effect to. */
    public const array STAT_ROLES = ['positive' => 'Raised', 'negative' => 'Lowered'];

    /** The group whose bindings a config.php path holds, or null for any other path. */
    public static function findByConfigPath(string $path): ?self
    {
        return array_find(self::cases(), static fn(self $group): bool => $group->getConfigPath() === $path);
    }

    public function getConfigPath(): string
    {
        return self::ROOT . '.' . $this->value;
    }

    /** Whether the group's effects loop for as long as their condition lasts, rather than playing once. */
    public function isLooping(): bool
    {
        return $this !== self::INTERCEPTIONS;
    }

    /** The binding list a group's setting is edited through, every reference chosen from a picker. */
    public function getBindingList(string $key): RecordSubList
    {
        return match ($this) {
            self::STATES => new RecordSubList(
                key: $key, prefix: 'conditionState', singular: 'state effect',
                fields: [RecordField::reference('state', 'State', 'states'), RecordField::reference('effect', 'Effect', 'effects')],
                blank: ['state' => '', 'effect' => ''], heading: 'State Effects', removeWhenEmpty: true, keyField: 'state', valueField: 'effect',
            ),
            self::STAT_STAGES => new RecordSubList(
                key: $key, prefix: 'conditionStat', singular: 'stat stage effect',
                fields: [RecordField::reference('stat', 'Stat', ReferenceCatalog::STAGED_STATS),
                    ...array_map(static fn(string $role, string $label): RecordField => RecordField::reference($role, $label . ' Effect', 'effects',
                        allowsNone: true, noneLabel: 'None'), array_keys(self::STAT_ROLES), self::STAT_ROLES)],
                blank: ['stat' => ''], heading: 'Stat Stage Effects', removeWhenEmpty: true, keyField: 'stat',
            ),
            self::INTERCEPTIONS => new RecordSubList(
                key: $key, prefix: 'interception', singular: 'interception effect',
                fields: [RecordField::reference('state', 'State', 'states'), RecordField::reference('effect', 'Effect', 'effects')],
                blank: ['state' => '', 'effect' => ''], heading: 'Interception Effects', removeWhenEmpty: true, keyField: 'state', valueField: 'effect',
            ),
        };
    }

    /**
     * The group's bindings one effect each: the state or stat it is for, the stat's role, and the effect named.
     * Anything that is not a binding map names nothing; the shape check reports it.
     *
     * @return list<array{subject: string, role: ?string, effect: mixed}>
     */
    public function listBindings(mixed $value): array
    {
        $bindings = [];
        foreach (is_array($value) ? $value : [] as $subject => $effect) {
            if ($this !== self::STAT_STAGES) {
                $bindings[] = ['subject' => (string) $subject, 'role' => null, 'effect' => $effect];
                continue;
            }
            foreach (is_array($effect) ? $effect : [] as $role => $roleEffect) {
                $bindings[] = ['subject' => (string) $subject, 'role' => (string) $role, 'effect' => $roleEffect];
            }
        }

        return $bindings;
    }

    /** The effect a binding names in a group's value, by its state or stat and role, or null where none is bound. */
    public function getBoundEffect(mixed $value, string $subject, ?string $role): mixed
    {
        $bound = is_array($value) ? ($value[$subject] ?? null) : null;

        return $role === null ? $bound : (is_array($bound) ? ($bound[$role] ?? null) : null);
    }

    /** Where a binding is, as validation names it: the group's path, then its state or stat and role. */
    public function describeBinding(string $subject, ?string $role): string
    {
        return 'config.php: ' . $this->getConfigPath() . '.' . $subject . ($role === null ? '' : '.' . $role);
    }

    /**
     * Refuses a battle-compiled timeline the group cannot play, by the Engine's own condition rule.
     *
     * @throws \InvalidArgumentException With the Engine's reason.
     */
    public function validateTimeline(CompiledEffectTimeline $timeline): void
    {
        if ($this->isLooping()) {
            BattleConditionEffects::validateLoopingTimeline($timeline);
        } else {
            BattleConditionEffects::validateInterceptionTimeline($timeline);
        }
    }
}
