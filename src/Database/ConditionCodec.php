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
 * Wire form: entries separated by `;`, each `[!]type:name[:extras]`, where
 * extras depend on the type (quest status, switch `false`, item quantity,
 * variable `op:value`, or a map event's marker after its map id). A leading
 * `!` negates.
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

            if (self::decode(self::encode($condition)) === null) {
                return sprintf('condition %d holds a shape the condition editor cannot carry', $index + 1);
            }

            $type = strval($condition['type'] ?? '');
            if ($type === 'quest' && ! in_array($condition['status'] ?? 'completed', ['completed', 'active'], true)) {
                return sprintf('condition %d has a quest status the condition editor would rewrite', $index + 1);
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

    /**
     * Encodes one condition as `[!]type:name[:extras]`.
     *
     * @param array<string, mixed> $condition The condition payload.
     * @return string
     */
    public static function encode(array $condition): string
    {
        $type = strval($condition['type'] ?? '');
        $name = strval($condition['name'] ?? '');
        $parts = [$type, $name];

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

        return $negate . implode(':', $parts);
    }

    /**
     * Decodes the one-line form back into condition arrays.
     *
     * Unparseable segments are dropped rather than written back as garbage:
     * a typo costs the author the condition, never the file.
     *
     * @param string $value The encoded conditions.
     * @return array<int, array<string, mixed>>
     */
    public static function decodeAll(string $value): array
    {
        $conditions = [];

        foreach (explode(';', $value) as $segment) {
            $condition = self::decode($segment);

            if ($condition !== null) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    /**
     * Decodes the one-line form, refusing it whole when any segment cannot
     * be read: for an interface that submits the line as typed, where a
     * dropped segment would be a condition lost without a word.
     *
     * @param string $value The encoded conditions.
     * @return array<int, array<string, mixed>>
     * @throws InvalidArgumentException Naming the first unreadable segment.
     */
    public static function decodeAllStrictly(string $value): array
    {
        $conditions = [];

        foreach (explode(';', $value) as $segment) {
            if (trim($segment) === '') {
                continue;
            }

            $conditions[] = self::decode($segment) ?? throw new InvalidArgumentException(sprintf(
                'Condition "%s" cannot be read: write [!]type:name[:extras] with a type of %s.',
                trim($segment),
                implode(', ', self::types()),
            ));
        }

        return $conditions;
    }

    /**
     * Decodes one `[!]type:name[:extras]` segment.
     *
     * @param string $segment The encoded segment.
     * @return array<string, mixed>|null The condition, or null when unparseable.
     */
    public static function decode(string $segment): ?array
    {
        $segment = trim($segment);

        if ($segment === '') {
            return null;
        }

        $negate = str_starts_with($segment, '!');
        $segment = ltrim($segment, '!');
        $parts = array_map(trim(...), explode(':', $segment));
        $type = strtolower($parts[0] ?? '');
        $name = $parts[1] ?? '';

        if ($name === '' || ! in_array($type, self::types(), true)) {
            return null;
        }

        $condition = ['type' => $type, 'name' => $name];

        switch ($type) {
            case 'quest':
                $status = strtolower($parts[2] ?? 'completed');
                $condition['status'] = in_array($status, ['completed', 'active'], true) ? $status : 'completed';
                break;
            case 'switch':
                if (strtolower($parts[2] ?? 'true') === 'false') {
                    $condition['value'] = false;
                }

                break;
            case 'item':
            case 'key_item':
                if (intval($parts[2] ?? 1) > 1) {
                    $condition['quantity'] = intval($parts[2]);
                }

                break;
            case 'variable':
                $condition['op'] = $parts[2] ?? '==';
                $rawValue = $parts[3] ?? '0';
                $condition['value'] = is_numeric($rawValue) ? intval($rawValue) : $rawValue;
                break;
            case 'map_event':
                // A map event is named by its map and its one-shot event marker; without a marker it is unreadable.
                $marker = $parts[2] ?? '';
                if ($marker === '') {
                    return null;
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
