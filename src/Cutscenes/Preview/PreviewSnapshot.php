<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

/**
 * The observable end state of a preview run: what a player would carry out
 * of the cinematic.
 *
 * Two snapshots compare field by field, which is how the skip/finalizer
 * equivalence display shows an author exactly where a skipped run and a
 * watched run part ways.
 */
final class PreviewSnapshot
{
    /**
     * @param array<string, mixed> $values Flat, sorted, label => value.
     */
    public function __construct(public readonly array $values)
    {
    }

    /**
     * Lists the labels whose values differ, with both sides.
     *
     * @return array<int, array{label: string, left: string, right: string}>
     */
    public function diff(self $other): array
    {
        $labels = array_unique([...array_keys($this->values), ...array_keys($other->values)]);
        sort($labels);
        $differences = [];

        foreach ($labels as $label) {
            $left = array_key_exists($label, $this->values) ? $this->values[$label] : null;
            $right = array_key_exists($label, $other->values) ? $other->values[$label] : null;

            if ($left === $right) {
                continue;
            }

            $differences[] = [
                'label' => $label,
                'left' => self::describe($left, array_key_exists($label, $this->values)),
                'right' => self::describe($right, array_key_exists($label, $other->values)),
            ];
        }

        return $differences;
    }

    /**
     * Renders one value for display.
     */
    public static function describe(mixed $value, bool $present = true): string
    {
        if (! $present) {
            return '(absent)';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded === false ? '(array)' : $encoded;
        }

        return (string) $value;
    }
}
