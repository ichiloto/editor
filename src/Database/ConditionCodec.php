<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

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
 * switch `false`, item quantity, variable `op:value`, or a map event's marker
 * after its map id). A leading `!` negates. A name or value escapes its `\`,
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
        'item' => ['quantity'],
        'key_item' => ['quantity'],
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
            if ($type === 'quest' && ! in_array($condition['status'] ?? 'completed', ['completed', 'active'], true)) {
                return sprintf('condition %d has a quest status the condition editor would rewrite', $index + 1);
            }

            if (self::decode(self::encode($condition)) === null) {
                return sprintf('condition %d holds a shape the condition editor cannot carry', $index + 1);
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
            case 'variable':
                $parts[] = strval($condition['op'] ?? '==');
                $parts[] = strval($condition['value'] ?? 0);
                break;
            case 'map_event':
                $parts[] = strval($condition['marker'] ?? '');
                break;
        }

        $negate = ($condition['negate'] ?? false) === true ? '!' : '';

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

        foreach (WorldStateLine::splitEntries($value) as $segment) {
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

        foreach (WorldStateLine::splitEntries($value) as $segment) {
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
        try {
            $parts = WorldStateLine::splitParts($segment);
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        }
        $type = strtolower($parts[0]);
        $name = $parts[1] ?? '';

        if (! in_array($type, self::types(), true)) {
            return sprintf('write [!]type:name[:extras] with a type of %s.', implode(', ', self::types()));
        }
        if ($name === '') {
            return sprintf('the %s condition needs a name.', $type);
        }

        [$extras, $allowed] = [array_slice($parts, 2), match ($type) {
            'quest', 'switch', 'item', 'key_item' => 1,
            'map_event' => 1,
            'variable' => 2,
            default => 0,
        }];
        if (count($extras) > $allowed) {
            return sprintf('the %s condition takes %s after its name, not "%s"; write a colon or semicolon inside a name as \\: or \\;.',
                $type, match ($type) {
                    'quest' => 'at most a status (completed or active)',
                    'switch' => 'at most true or false',
                    'item', 'key_item' => 'at most a quantity',
                    'map_event' => 'only its event marker',
                    'variable' => 'at most an operator and a value',
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
}
