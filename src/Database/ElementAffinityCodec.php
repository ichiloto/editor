<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Encodes and decodes elemental affinity maps as a single editable line.
 *
 * The engine stores `element => multiplier` with one vocabulary everywhere:
 * 2.0 weak, 0.5 resist, 0.0 null, negative absorbs. This is the editor's one
 * representation of that map, so gear and enemies read and write it
 * identically.
 *
 * Wire form: entries separated by `;`, each `Element: multiplier`. The named
 * effects have canonical multipliers, and a hand-authored value between them
 * survives untouched.
 */
final class ElementAffinityCodec
{
    /**
     * The named effects, in cycling order, with their multipliers.
     */
    public const array EFFECTS = [
        'Weak' => 2.0,
        'Resist' => 0.5,
        'Null' => 0.0,
        'Absorb' => -1.0,
    ];

    /**
     * Encodes an affinity map into the one-line editable form.
     *
     * @param array<string, mixed> $affinities The map.
     * @return string The line.
     */
    public static function encodeAll(array $affinities): string
    {
        $parts = [];

        foreach ($affinities as $element => $multiplier) {
            $parts[] = sprintf('%s: %s', strval($element), self::formatMultiplier(floatval($multiplier)));
        }

        return implode('; ', $parts);
    }

    /**
     * Decodes the one-line form back into an affinity map.
     *
     * Unparseable segments are dropped rather than written back as garbage.
     *
     * @param string $value The encoded line.
     * @return array<string, float> The map.
     */
    public static function decodeAll(string $value): array
    {
        $affinities = [];

        foreach (explode(';', $value) as $segment) {
            $segment = trim($segment);

            if ($segment === '' || ! str_contains($segment, ':')) {
                continue;
            }

            [$element, $multiplier] = array_map(trim(...), explode(':', $segment, 2));

            if ($element === '' || ! is_numeric($multiplier)) {
                continue;
            }

            $affinities[$element] = floatval($multiplier);
        }

        return $affinities;
    }

    /**
     * Describes a multiplier in words where it has a name.
     *
     * @param float $multiplier The multiplier.
     * @return string The description.
     */
    public static function describe(float $multiplier): string
    {
        foreach (self::EFFECTS as $name => $named) {
            if (abs($multiplier - $named) < 0.0001) {
                return sprintf('%s ×%s', $name, self::formatMultiplier($multiplier));
            }
        }

        return sprintf('×%s', self::formatMultiplier($multiplier));
    }

    /**
     * Formats a multiplier without trailing noise.
     *
     * @param float $multiplier The multiplier.
     * @return string The formatted value.
     */
    public static function formatMultiplier(float $multiplier): string
    {
        $formatted = rtrim(rtrim(number_format($multiplier, 2, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * Returns the named effect after the given multiplier, cycling.
     *
     * A multiplier between the named ones steps onto the cycle at Weak.
     *
     * @param float $multiplier The current multiplier.
     * @param int $step Which way to cycle.
     * @return float The next named multiplier.
     */
    public static function cycle(float $multiplier, int $step): float
    {
        $values = array_values(self::EFFECTS);
        $index = null;

        foreach ($values as $position => $named) {
            if (abs($multiplier - $named) < 0.0001) {
                $index = $position;
                break;
            }
        }

        if ($index === null) {
            return $values[0];
        }

        $count = count($values);

        return $values[(($index + $step) % $count + $count) % $count];
    }
}
