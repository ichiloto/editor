<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Core\WorldConditionType;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats\StatKey;
use InvalidArgumentException;

/**
 * The editor's bounded structural mirror of the engine's battle-entry rule
 * contract.
 *
 * The accepted engine hydrates `assets/Data/battle-entry-rules.php` through
 * `BattleEntryRuleCatalog`, which fails closed on the first malformed field
 * with a diagnostic naming the file, the rule, and the field. The editor's
 * committed engine dependency does not yet expose those classes, so this
 * mirror validates the same shapes with the same diagnostic wording — the
 * parity suite proves each message against the accepted engine head — while
 * collecting every finding instead of stopping at the first, which is what
 * an author fixing a file wants. The first collected finding is always the
 * one the engine would throw.
 *
 * The stat, world-condition, and stage vocabularies come from the vendored
 * engine classes, which are byte-identical to the accepted head, so this
 * mirror cannot drift from them. It validates only; it never applies,
 * matches, or rolls back a rule — that behaviour stays in the runtime.
 *
 * @package Ichiloto\Editor\Database
 */
final class BattleEntryRuleContract
{
    /** The classifications the engine's `BattleClassification` accepts. */
    public const array CLASSIFICATIONS = ['ordinary', 'boss'];

    /** The write types the engine's transactional boundary accepts. */
    public const array TRANSACTIONAL_WRITE_TYPES = ['switch', 'event', 'variable'];

    /**
     * Returns every problem the engine's catalog hydration would find, in
     * the order it would find them.
     *
     * @param mixed $data What the rule file returned.
     * @param string $source How to name the file in diagnostics.
     * @param BattleEntryActorResolver|null $actors The project's durable
     * actor identities, when they resolved; null validates identity shape
     * only, exactly as the engine does without an actor store.
     * @return string[] The diagnostics, engine-worded, empty when the file
     * would hydrate.
     */
    public static function problems(mixed $data, string $source, ?BattleEntryActorResolver $actors = null): array
    {
        if (! is_array($data)) {
            return [sprintf('Battle-entry rule file %s must return an array.', $source)];
        }

        $entries = array_key_exists('rules', $data) ? $data['rules'] : $data;

        if (! is_array($entries) || ! array_is_list($entries)) {
            return [sprintf('%s field "rules" must be a list.', $source)];
        }

        $problems = [];
        $seenIds = [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                $problems[] = sprintf('%s rule at position %d must be an array.', $source, $index);

                continue;
            }

            [$id, $ruleProblems] = self::ruleProblems($entry, $index, $source, $actors);
            $problems = [...$problems, ...$ruleProblems];

            if ($id === null) {
                continue;
            }

            if (isset($seenIds[$id])) {
                $problems[] = sprintf('%s rule "%s" duplicates a stable rule ID.', $source, $id);

                continue;
            }

            $seenIds[$id] = true;
        }

        return $problems;
    }

    /**
     * Returns the problem with an authored battle classification, or null
     * when the engine's `BattleClassification::resolve` would accept it.
     *
     * Shared by rules and troops: both author the same vocabulary.
     *
     * @param mixed $value The authored value, null when omitted.
     * @param string $source How to name the field's owner in diagnostics.
     * @return string|null The diagnostic, engine-worded.
     */
    public static function classificationProblem(mixed $value, string $source): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            return sprintf(
                '%s field "classification" must be one of: %s.',
                $source,
                implode(', ', self::CLASSIFICATIONS),
            );
        }

        if (! in_array(trim($value), self::CLASSIFICATIONS, true)) {
            return sprintf(
                '%s field "classification" has unsupported value "%s"; expected one of: %s.',
                $source,
                $value,
                implode(', ', self::CLASSIFICATIONS),
            );
        }

        return null;
    }

    /**
     * Validates one rule the way the engine's `hydrateRule` does, collecting
     * instead of throwing.
     *
     * @param array<string, mixed> $entry The rule payload.
     * @param int $index The rule's declaration position.
     * @param string $source How to name the file.
     * @param BattleEntryActorResolver|null $actors The durable identities.
     * @return array{string|null, string[]} The rule id when one exists, and
     * the diagnostics.
     */
    private static function ruleProblems(array $entry, int $index, string $source, ?BattleEntryActorResolver $actors): array
    {
        $problems = [];
        $positionSource = sprintf('%s rule at position %d', $source, $index);
        $id = trim(strval($entry['id'] ?? ''));

        if ($id === '') {
            return [null, [sprintf('%s field "id" must be a non-empty stable identity.', $positionSource)]];
        }

        $ruleSource = sprintf('%s rule "%s"', $source, $id);
        $priority = $entry['priority'] ?? 0;

        if (! is_int($priority)) {
            $problems[] = sprintf('%s field "priority" must be an integer.', $ruleSource);
        }

        $classificationProblem = self::classificationProblem($entry['classification'] ?? null, $ruleSource);

        if ($classificationProblem !== null) {
            $problems[] = $classificationProblem;
        }

        $conditions = $entry['conditions'] ?? [];

        if (! is_array($conditions) || ! array_is_list($conditions)) {
            $problems[] = sprintf('%s field "conditions" must be a list.', $ruleSource);
        } else {
            $problems = [...$problems, ...self::conditionProblems($conditions, $ruleSource . ' field "conditions"')];
        }

        $actorEntries = $entry['actors'] ?? null;

        if (! is_array($actorEntries) || ! array_is_list($actorEntries) || $actorEntries === []) {
            $problems[] = sprintf('%s field "actors" must be a non-empty list.', $ruleSource);
        } else {
            foreach ($actorEntries as $actorIndex => $actorEntry) {
                $actorSource = sprintf('%s field "actors[%d]"', $ruleSource, $actorIndex);

                if (! is_array($actorEntry)) {
                    $problems[] = sprintf('%s must be an array.', $actorSource);

                    continue;
                }

                $problems = [...$problems, ...self::actorProblems($actorEntry['actor'] ?? null, $actorSource, $actors)];
                $problems = [...$problems, ...self::presenceProblems($actorEntry['presence'] ?? null, $actorSource)];
            }
        }

        $effectEntries = $entry['effects'] ?? null;

        if (! is_array($effectEntries) || ! array_is_list($effectEntries) || $effectEntries === []) {
            $problems[] = sprintf('%s field "effects" must be a non-empty list.', $ruleSource);
        } else {
            foreach ($effectEntries as $effectIndex => $effectEntry) {
                $effectSource = sprintf('%s field "effects[%d]"', $ruleSource, $effectIndex);

                if (! is_array($effectEntry)) {
                    $problems[] = sprintf('%s must be an array.', $effectSource);

                    continue;
                }

                $problems = [...$problems, ...self::effectProblems($effectEntry, $effectSource, $actors)];
            }
        }

        $writes = $entry['writes'] ?? [];

        if (! is_array($writes) || ! array_is_list($writes)) {
            $problems[] = sprintf('%s field "writes" must be a list.', $ruleSource);
        } else {
            $problems = [...$problems, ...self::writeProblems($writes, $ruleSource . ' field "writes"')];
        }

        return [$id, $problems];
    }

    /**
     * Validates one typed effect the way the engine does.
     *
     * @param array<string, mixed> $effectEntry The effect payload.
     * @param string $effectSource How to name it.
     * @param BattleEntryActorResolver|null $actors The durable identities.
     * @return string[] The diagnostics.
     */
    private static function effectProblems(array $effectEntry, string $effectSource, ?BattleEntryActorResolver $actors): array
    {
        $problems = [];
        $type = $effectEntry['type'] ?? null;

        if ($type !== 'stat_stage') {
            return [sprintf(
                '%s field "type" has unsupported effect "%s"; expected "stat_stage".',
                $effectSource,
                is_scalar($type) || $type === null ? strval($type ?? '') : get_debug_type($type),
            )];
        }

        $problems = [...$problems, ...self::actorProblems($effectEntry['actor'] ?? null, $effectSource, $actors)];

        $statValue = $effectEntry['stat'] ?? '';
        $stat = null;

        try {
            $stat = StatKey::require(is_scalar($statValue) ? strval($statValue) : '');
        } catch (InvalidArgumentException $exception) {
            $problems[] = sprintf('%s field "stat" is invalid: %s', $effectSource, $exception->getMessage());
        }

        if ($stat instanceof StatKey && ! in_array($stat->value, Character::buffableStats(), true)) {
            $problems[] = sprintf(
                '%s field "stat" references "%s", which does not support temporary battle stages.',
                $effectSource,
                $stat->value,
            );
        }

        $delta = $effectEntry['delta'] ?? null;

        if (! is_int($delta)) {
            $problems[] = sprintf('%s field "delta" must be a signed integer.', $effectSource);
        }

        return $problems;
    }

    /**
     * Validates an actor identity the way the engine's catalog does.
     *
     * @param mixed $value The authored actor reference.
     * @param string $source How to name the field.
     * @param BattleEntryActorResolver|null $actors The durable identities.
     * @return string[] The diagnostics.
     */
    private static function actorProblems(mixed $value, string $source, ?BattleEntryActorResolver $actors): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [sprintf('%s field "actor" must be a non-empty stable actor identity.', $source)];
        }

        if ($actors !== null && $actors->canonicalId(trim($value)) === null) {
            return [sprintf('%s field "actor" references unknown actor "%s".', $source, trim($value))];
        }

        return [];
    }

    /**
     * Validates a presence the way the engine's `BattleEntryActorPresence`
     * does.
     *
     * @param mixed $value The authored presence.
     * @param string $source How to name the field.
     * @return string[] The diagnostics.
     */
    private static function presenceProblems(mixed $value, string $source): array
    {
        if (! is_string($value)) {
            return [sprintf(
                '%s field "presence" must be one of: %s.',
                $source,
                implode(', ', BattleEntryPredicateCodec::PRESENCES),
            )];
        }

        if (! in_array(trim($value), BattleEntryPredicateCodec::PRESENCES, true)) {
            return [sprintf(
                '%s field "presence" has unsupported value "%s"; expected one of: %s.',
                $source,
                $value,
                implode(', ', BattleEntryPredicateCodec::PRESENCES),
            )];
        }

        return [];
    }

    /**
     * Validates a condition list the way the engine's
     * `WorldConditionEvaluator::validateAll` does.
     *
     * @param array<int, mixed> $conditions The condition entries.
     * @param string $source How to name the list.
     * @return string[] The diagnostics.
     */
    private static function conditionProblems(array $conditions, string $source): array
    {
        $problems = [];

        foreach ($conditions as $index => $condition) {
            $conditionSource = sprintf('%s[%d]', $source, $index);

            if (! is_array($condition)) {
                $problems[] = sprintf('%s must be an array.', $conditionSource);

                continue;
            }

            $typeValue = $condition['type'] ?? null;
            $type = is_string($typeValue) ? WorldConditionType::tryFrom(trim($typeValue)) : null;

            if (! $type instanceof WorldConditionType) {
                $problems[] = sprintf(
                    '%s field "type" has unsupported world condition "%s".',
                    $conditionSource,
                    is_scalar($typeValue) ? strval($typeValue) : get_debug_type($typeValue),
                );

                continue;
            }

            if (! is_string($condition['name'] ?? null) || trim($condition['name']) === '') {
                $problems[] = sprintf('%s field "name" must be non-empty.', $conditionSource);
            }

            if (isset($condition['negate']) && ! is_bool($condition['negate'])) {
                $problems[] = sprintf('%s field "negate" must be boolean.', $conditionSource);
            }

            if ($type === WorldConditionType::VARIABLE) {
                $operator = strval($condition['op'] ?? '==');

                if (! in_array($operator, ['==', '!=', '>', '>=', '<', '<='], true)) {
                    $problems[] = sprintf(
                        '%s field "op" has unsupported variable operator "%s".',
                        $conditionSource,
                        $operator,
                    );
                }
            }

            if ($type === WorldConditionType::ITEM
                && isset($condition['quantity'])
                && (! is_int($condition['quantity']) || $condition['quantity'] < 1)) {
                $problems[] = sprintf('%s field "quantity" must be a positive integer.', $conditionSource);
            }
        }

        return $problems;
    }

    /**
     * Validates a write list the way the engine's
     * `WorldStateWriter::validateAll` does at a transactional boundary.
     *
     * @param array<int, mixed> $sets The write entries.
     * @param string $source How to name the list.
     * @return string[] The diagnostics.
     */
    private static function writeProblems(array $sets, string $source): array
    {
        $problems = [];

        foreach ($sets as $index => $set) {
            $writeSource = sprintf('%s[%d]', $source, $index);

            if (! is_array($set)) {
                $problems[] = sprintf('%s must be an array.', $writeSource);

                continue;
            }

            $type = $set['type'] ?? null;

            if (! is_string($type) || ! in_array($type, ['switch', 'event', 'variable', 'quest'], true)) {
                $problems[] = sprintf(
                    '%s field "type" has unsupported world write "%s".',
                    $writeSource,
                    is_scalar($type) ? strval($type) : get_debug_type($type),
                );

                continue;
            }

            if (! is_string($set['name'] ?? null) || trim($set['name']) === '') {
                $problems[] = sprintf('%s field "name" must be non-empty.', $writeSource);
            }

            if ($type === 'quest') {
                $problems[] = sprintf(
                    '%s field "type" cannot use quest acceptance in an atomic transaction; write a reversible switch, event, or variable instead.',
                    $writeSource,
                );
            }

            if ($type === 'switch' && isset($set['value']) && ! is_bool($set['value'])) {
                $problems[] = sprintf('%s field "value" must be boolean for a switch write.', $writeSource);
            }

            if ($type === 'variable') {
                $operation = $set['op'] ?? 'set';

                if (! is_string($operation) || ! in_array($operation, ['set', 'add'], true)) {
                    $problems[] = sprintf('%s field "op" must be "set" or "add".', $writeSource);

                    continue;
                }

                $value = $set['value'] ?? ($operation === 'add' ? 1 : 0);

                if ($operation === 'add' && ! is_int($value) && ! is_float($value)) {
                    $problems[] = sprintf('%s field "value" must be numeric for an add operation.', $writeSource);
                }

                if ($operation === 'set' && ! is_int($value) && ! is_float($value) && ! is_string($value)) {
                    $problems[] = sprintf('%s field "value" must be an integer, float, or string.', $writeSource);
                }
            }
        }

        return $problems;
    }
}
