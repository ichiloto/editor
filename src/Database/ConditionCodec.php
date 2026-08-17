<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Core\WorldConditionType;

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
 * extras depend on the type (quest status, switch `false`, item quantity, or
 * variable `op:value`). A leading `!` negates.
 */
final class ConditionCodec
{
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
        }

        if ($negate) {
            $condition['negate'] = true;
        }

        return $condition;
    }
}
