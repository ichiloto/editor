<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\WorldConditionType;
use InvalidArgumentException;

/**
 * Encodes and decodes the engine's world-condition arrays as a single
 * editable line.
 *
 * The engine uses one condition grammar in four places — quest
 * prerequisites, skit availability, event `branch` arms, and NPC visibility
 * (`WorldConditionEvaluator::allHold()`). This codec is the editor's single
 * representation of it, so every surface that edits conditions behaves
 * identically.
 *
 * Wire form ({@see WorldStateLine}): entries separated by `;`, each
 * `[!]type:name[:extras]`, where extras depend on the type (quest status,
 * switch `false`, item quantity, variable `op:value`, a map event's marker
 * after its map id, or the least number of summons assigned within one of the
 * Engine's {@see \Ichiloto\Engine\Core\AssignedSummonScope} scopes, which an
 * assigned summons condition always writes: `assigned_summons:party:2`, or the
 * exact number of members a present party size condition requires:
 * `present_party_size:party:4`; a present actor condition names an actor id;
 * a play statistic condition names one of the Engine's
 * {@see \Ichiloto\Engine\Core\PlayStatistic} counters and the whole count it
 * compares with, after an operator when one is authored:
 * `play_statistic:battles_won:>=:1`). A leading `!` negates. A name or value escapes its `\`,
 * `:` and `;` with a backslash, so any name the Engine accepts round-trips.
 * A line is read only as written: a part the type does not take, or a value
 * the Engine would refuse, makes the entry unreadable, never rewritten.
 */
final class ConditionCodec
{
    /** The keys the line carries per type, beyond type, name and negate. */
    private const array CARRIED_KEYS = [
        'quest' => ['status'],
        'switch' => ['value'],
        'event' => [],
        'map_event' => ['marker'],
        'variable' => ['op', 'value'],
        'play_statistic' => ['op', 'value'],
        'item' => ['quantity'],
        'key_item' => ['quantity'],
        'assigned_summons' => ['quantity'],
        'present_actor' => [],
        'present_party_size' => ['quantity'],
    ];

    /**
     * The types whose quantity the Engine requires, so the line always writes it and never reads a default:
     * what each counts, as the refusal for a missing one says it.
     */
    private const array REQUIRED_QUANTITIES = [
        'assigned_summons' => 'the least number of assigned summons: assigned_summons:party:2',
        'present_party_size' => 'the exact number of members present: present_party_size:party:4',
    ];


    /**
     * Why an authored condition list cannot go through the condition line,
     * or null when the line carries it faithfully.
     *
     * Committing the line writes only what it carries, so a list that is not
     * a list, a condition type the line cannot express, a quest status it
     * would rewrite, or an authored key outside its vocabulary would be lost
     * on commit. Such a list is refused by name instead.
     *
     * @param mixed $conditions The list as authored.
     */
    public static function findCarryIssue(mixed $conditions): ?string
    {
        if (! is_array($conditions) || ! array_is_list($conditions)) {
            return sprintf('the conditions are %s, not a list', is_array($conditions) ? 'a keyed array' : get_debug_type($conditions));
        }

        foreach ($conditions as $index => $condition) {
            if (! is_array($condition)) {
                return sprintf('condition %d is %s, not an array', $index + 1, get_debug_type($condition));
            }

            $type = strval($condition['type'] ?? '');
            if ($type === self::GROUP_TYPE) {
                // A group carries only its members and negation; each member is judged as any condition is.
                $extra = array_diff(array_keys($condition), ['type', 'conditions', 'negate']);
                if ($extra !== []) {
                    return sprintf('condition %d carries "%s", which the condition editor would drop', $index + 1, implode('", "', array_map(strval(...), $extra)));
                }
                $memberIssue = self::findCarryIssue($condition['conditions'] ?? null);
                if ($memberIssue !== null) {
                    return sprintf('condition %d (any of) has a member that cannot be carried: %s', $index + 1, $memberIssue);
                }
                if (self::decode(self::encode($condition)) === null) {
                    return sprintf('condition %d holds a shape the condition editor cannot carry', $index + 1);
                }
                continue;
            }
            if ($type === 'quest' && ! in_array($condition['status'] ?? 'completed', ['completed', 'active'], true)) {
                return sprintf('condition %d has a quest status the condition editor would rewrite', $index + 1);
            }

            if (self::decode(self::encode($condition)) === null) {
                return sprintf('condition %d holds a shape the condition editor cannot carry', $index + 1);
            }
            // The line writes a count as a whole number, so a count the Engine refuses would be rewritten, not carried.
            if ($type === WorldConditionType::PLAY_STATISTIC->value && ! WorldConditionEvaluator::isPlayStatisticConditionValid($condition)) {
                return sprintf('condition %d holds a play statistic the Engine refuses', $index + 1);
            }

            $extra = array_diff(array_keys($condition), ['type', 'name', 'negate', ...(self::CARRIED_KEYS[$type] ?? [])]);
            if ($extra !== []) {
                return sprintf('condition %d carries "%s", which the condition editor would drop', $index + 1, implode('", "', array_map(strval(...), $extra)));
            }
        }

        return null;
    }

    /**
     * Returns the condition types the engine's evaluator understands.
     *
     * @return string[]
     */
    public static function types(): array
    {
        return WorldConditionType::values();
    }

    /** The Engine's any-of group: `[!]any(member; member)`, its members written as any condition line is. */
    public const string GROUP_TYPE = 'any';

    /**
     * The types a single condition takes: every Engine type but the group,
     * which holds conditions rather than naming something. An editor that
     * builds one condition at a time offers these.
     *
     * @return list<string>
     */
    public static function getLeafTypes(): array
    {
        return array_values(array_filter(self::types(), static fn(string $type): bool => $type !== self::GROUP_TYPE));
    }

    /**
     * Encodes a list of conditions into the one-line editable form.
     *
     * @param array<int, mixed> $conditions The condition payloads.
     * @return string
     */
    public static function encodeAll(array $conditions): string
    {
        return implode('; ', array_map(
            static fn(array $condition): string => self::encode($condition),
            array_values(array_filter($conditions, is_array(...))),
        ));
    }

    /** The operators a variable condition compares with, as the Engine's evaluator accepts them. */
    private const array VARIABLE_OPERATORS = ['==', '!=', '>', '>=', '<', '<='];

    /**
     * Encodes one condition as `[!]type:name[:extras]`.
     *
     * @param array<string, mixed> $condition The condition payload.
     * @return string
     */
    public static function encode(array $condition): string
    {
        $type = strval($condition['type'] ?? '');
        $negate = ($condition['negate'] ?? false) === true ? '!' : '';
        if ($type === self::GROUP_TYPE) {
            $members = is_array($condition['conditions'] ?? null) ? $condition['conditions'] : [];

            return sprintf('%s%s(%s)', $negate, self::GROUP_TYPE, self::encodeAll($members));
        }
        $parts = [$type, strval($condition['name'] ?? '')];

        switch ($type) {
            case 'quest':
                $parts[] = strval($condition['status'] ?? 'completed');
                break;
            case 'switch':
                if (($condition['value'] ?? true) === false) {
                    $parts[] = 'false';
                }

                break;
            case 'item':
            case 'key_item':
                if (intval($condition['quantity'] ?? 1) > 1) {
                    $parts[] = strval(intval($condition['quantity']));
                }

                break;
            case 'assigned_summons':
            case 'present_party_size':
                // Required, so it is always written: a line without it is refused, never read as a default.
                if (array_key_exists('quantity', $condition)) {
                    $parts[] = is_scalar($condition['quantity']) ? strval($condition['quantity']) : '';
                }

                break;
            case 'variable':
                $parts[] = strval($condition['op'] ?? '==');
                $parts[] = strval($condition['value'] ?? 0);
                break;
            case 'play_statistic':
                // The operator only where it is authored (the Engine reads its absence as ==); the count always.
                if (array_key_exists('op', $condition)) {
                    $parts[] = is_scalar($condition['op']) ? strval($condition['op']) : '';
                }
                if (array_key_exists('value', $condition)) {
                    $parts[] = is_scalar($condition['value']) ? strval($condition['value']) : '';
                }

                break;
            case 'map_event':
                $parts[] = strval($condition['marker'] ?? '');
                break;
        }

        return $negate . WorldStateLine::joinParts($parts);
    }

    /**
     * Decodes the one-line form back into condition arrays, leaving out an
     * entry it cannot read: for showing a line this codec wrote. A line an
     * author or a client submits is read by {@see decodeAllStrictly()}.
     *
     * @param string $value The encoded conditions.
     * @return array<int, array<string, mixed>>
     */
    public static function decodeAll(string $value): array
    {
        $conditions = [];

        foreach (WorldStateLine::splitEntries($value, self::GROUP_TYPE) as $segment) {
            $condition = self::decode($segment);

            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    /**
     * Decodes the one-line form, refusing it whole when any entry cannot be
     * read as written: for an interface that submits the line as typed, where
     * a dropped or reinterpreted part would be a condition lost without a word.
     *
     * @param string $value The encoded conditions.
     * @return array<int, array<string, mixed>>
     * @throws InvalidArgumentException Naming the first unreadable entry and why.
     */
    public static function decodeAllStrictly(string $value): array
    {
        $conditions = [];

        foreach (WorldStateLine::splitEntries($value, self::GROUP_TYPE) as $segment) {
            if (trim($segment) === '') {
                continue;
            }

            $condition = self::parse($segment);
            if (is_string($condition)) {
                throw new InvalidArgumentException(sprintf('Condition "%s" cannot be read: %s Nothing was changed.', trim($segment), $condition));
            }
            $conditions[] = $condition;
        }

        return $conditions;
    }

    /**
     * Decodes one `[!]type:name[:extras]` entry.
     *
     * @param string $segment The encoded entry.
     * @return array<string, mixed>|null The condition, or null when it cannot be read as written.
     */
    public static function decode(string $segment): ?array
    {
        $condition = self::parse($segment);

        return is_array($condition) ? $condition : null;
    }

    /**
     * Reads one entry exactly as written.
     *
     * @return array<string, mixed>|string The condition, or why it cannot be read.
     */
    private static function parse(string $segment): array|string
    {
        $segment = trim($segment);
        if ($segment === '') {
            return 'it is empty.';
        }

        $negate = str_starts_with($segment, '!');
        if ($negate) {
            $segment = ltrim(substr($segment, 1));
        }
        if (preg_match('/^' . self::GROUP_TYPE . '\s*\((.*)\)$/is', $segment, $group) === 1) {
            return self::parseGroup($group[1], $negate);
        }
        try {
            $parts = WorldStateLine::splitParts($segment);
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        }
        $type = strtolower($parts[0]);
        $name = $parts[1] ?? '';

        if ($type === self::GROUP_TYPE) {
            return 'an any-of group is written any(condition; condition), its members inside the parentheses.';
        }
        if (! in_array($type, self::getLeafTypes(), true)) {
            return sprintf('write [!]type:name[:extras] with a type of %s, or any(condition; condition) for a group.', implode(', ', self::getLeafTypes()));
        }
        if ($name === '') {
            return sprintf('the %s condition needs a name.', $type);
        }

        [$extras, $allowed] = [array_slice($parts, 2), match ($type) {
            'quest', 'switch', 'item', 'key_item', 'assigned_summons', 'present_party_size' => 1,
            'map_event' => 1,
            'variable', 'play_statistic' => 2,
            default => 0,
        }];
        if (count($extras) > $allowed) {
            return sprintf('the %s condition takes %s after its name, not "%s"; write a colon or semicolon inside a name as \\: or \\;.',
                $type, match ($type) {
                    'quest' => 'at most a status (completed or active)',
                    'switch' => 'at most true or false',
                    'item', 'key_item' => 'at most a quantity',
                    'assigned_summons', 'present_party_size' => 'only its quantity',
                    'map_event' => 'only its event marker',
                    'variable' => 'at most an operator and a value',
                    'play_statistic' => 'an operator and a count',
                    default => 'nothing',
                }, implode(':', array_slice($extras, $allowed)));
        }

        $condition = ['type' => $type, 'name' => $name];

        switch ($type) {
            case 'quest':
                $status = strtolower($extras[0] ?? 'completed');
                if (! in_array($status, ['completed', 'active'], true)) {
                    return sprintf('a quest status is completed or active, not "%s".', $extras[0]);
                }
                $condition['status'] = $status;
                break;
            case 'switch':
                $state = strtolower($extras[0] ?? 'true');
                if (! in_array($state, ['true', 'false'], true)) {
                    return sprintf('a switch is true or false, not "%s".', $extras[0]);
                }
                if ($state === 'false') {
                    $condition['value'] = false;
                }

                break;
            case 'item':
            case 'key_item':
                if (isset($extras[0])) {
                    if (filter_var($extras[0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                        return sprintf('a quantity is a whole number above zero, not "%s".', $extras[0]);
                    }
                    if ((int) $extras[0] > 1) {
                        $condition['quantity'] = (int) $extras[0];
                    }
                }

                break;
            case 'variable':
                $operator = $extras[0] ?? '==';
                if (! in_array($operator, self::VARIABLE_OPERATORS, true)) {
                    return sprintf('a variable compares with %s, not "%s".', implode(' ', self::VARIABLE_OPERATORS), $operator);
                }
                $condition['op'] = $operator;
                $rawValue = $extras[1] ?? '0';
                $condition['value'] = is_numeric($rawValue) ? $rawValue + 0 : $rawValue;
                break;
            case 'play_statistic':
                // One extra is the count alone; two are the operator and the count.
                [$operator, $count] = count($extras) === 2 ? $extras : [null, $extras[0] ?? null];
                if ($count === null) {
                    return 'a play statistic condition needs a count to compare with: play_statistic:battles_won:>=:1.';
                }
                if ($operator !== null) {
                    if (! in_array($operator, self::VARIABLE_OPERATORS, true)) {
                        return sprintf('a play statistic compares with %s, not "%s".', implode(' ', self::VARIABLE_OPERATORS), $operator);
                    }
                    $condition['op'] = $operator;
                }
                if (filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                    return sprintf('a count is a whole number from zero up, not "%s".', $count);
                }
                $condition['value'] = (int) $count;
                // The Engine owns its counters: its own validation judges the name.
                try {
                    WorldConditionEvaluator::validateAll([$condition], 'condition');
                } catch (InvalidArgumentException $refusal) {
                    return sprintf('the Engine refuses it: %s', $refusal->getMessage());
                }

                break;
            case 'assigned_summons':
            case 'present_party_size':
                if (! isset($extras[0])) {
                    return sprintf('a %s condition needs %s.', str_replace('_', ' ', $type), self::REQUIRED_QUANTITIES[$type]);
                }
                if (filter_var($extras[0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                    return sprintf('a quantity is a whole number above zero, not "%s".', $extras[0]);
                }
                $condition['quantity'] = (int) $extras[0];
                // The Engine owns what it counts: its own validation judges the name.
                try {
                    WorldConditionEvaluator::validateAll([$condition], 'condition');
                } catch (InvalidArgumentException $refusal) {
                    return sprintf('the Engine refuses it: %s', $refusal->getMessage());
                }

                break;
            case 'present_actor':
                try {
                    WorldConditionEvaluator::validateAll([$condition], 'condition');
                } catch (InvalidArgumentException $refusal) {
                    return sprintf('the Engine refuses it: %s', $refusal->getMessage());
                }

                break;
            case 'map_event':
                // A map event is named by its map and its one-shot event marker.
                $marker = $extras[0] ?? '';
                if ($marker === '') {
                    return 'a map event condition needs its map and its event marker: map_event:map:marker.';
                }
                $condition['marker'] = $marker;
                break;
        }

        if ($negate) {
            $condition['negate'] = true;
        }

        return $condition;
    }

    /**
     * Reads the members inside `any(...)` exactly as written, each as a whole
     * condition entry, then lets the Engine judge the group's size and depth.
     *
     * @return array<string, mixed>|string The group, or why it cannot be read.
     */
    private static function parseGroup(string $inside, bool $negate): array|string
    {
        $members = [];
        foreach (WorldStateLine::splitEntries($inside, self::GROUP_TYPE) as $number => $segment) {
            if (trim($segment) === '') {
                continue;
            }
            $member = self::parse($segment);
            if (is_string($member)) {
                return sprintf('member %d of the any-of group: %s', $number + 1, $member);
            }
            $members[] = $member;
        }
        if ($members === []) {
            return 'an any-of group needs at least one condition inside any(...).';
        }

        $group = ['type' => self::GROUP_TYPE, 'conditions' => $members];
        if ($negate) {
            $group['negate'] = true;
        }
        try {
            WorldConditionEvaluator::validateAll([$group], 'condition');
        } catch (InvalidArgumentException $refusal) {
            return sprintf('the Engine refuses it: %s', $refusal->getMessage());
        }

        return $group;
    }
}
