<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;
use Ichiloto\Editor\EquipmentOptimizationPolicy;

/**
 * What an elemental outcome or a special property is worth to Optimize.
 *
 * The runtime looks these up by composed name: what a weapon's own element is
 * worth as offence, what a piece of armour being weak, resistant, immune or
 * absorbent to an element is worth as defence, a wildcard for any element at
 * a given outcome, and a weight per kind of special property. Composing those
 * names by hand is how a weight ends up matching nothing, so each part is
 * picked and the name is composed here.
 *
 * A name that matches neither shape is carried through exactly as authored
 * rather than dropped -- the file belongs to the project, not to the editor --
 * and validation says that the runtime will never look it up.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class OptimizationOutcomeProjection implements RecordProjection
{
    /**
     * The kinds of thing a weight can be declared for.
     */
    public const array KINDS = ['offence', 'defence', 'special', 'other'];

    /**
     * @inheritDoc
     */
    public function read(array $whole): array
    {
        $rows = [];

        foreach (self::mapOf($whole, 'elementOutcomeWeights') as $name => $weight) {
            $rows[] = [...self::decompose(strval($name)), 'weight' => intval(is_numeric($weight) ? $weight : 0)];
        }

        foreach (self::mapOf($whole, 'specialPropertyWeights') as $property => $weight) {
            $rows[] = [
                'kind' => 'special',
                'property' => strval($property),
                'weight' => intval(is_numeric($weight) ? $weight : 0),
            ];
        }

        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function write(array $whole, array $rows): array
    {
        $outcomes = [];
        $properties = [];

        foreach ($rows as $row) {
            $weight = intval($row['weight'] ?? 0);
            $kind = strval($row['kind'] ?? 'other');

            if ($kind === 'special') {
                $property = trim(strval($row['property'] ?? ''));

                if ($property !== '') {
                    $properties[$property] = $weight;
                }

                continue;
            }

            $name = self::compose($kind, $row);

            if ($name !== '') {
                $outcomes[$name] = $weight;
            }
        }

        foreach (['elementOutcomeWeights' => $outcomes, 'specialPropertyWeights' => $properties] as $key => $value) {
            if ($value === []) {
                unset($whole[$key]);

                continue;
            }

            $whole[$key] = $value;
        }

        return $whole;
    }

    /**
     * Splits a composed lookup name into the parts an author picks.
     *
     * @param string $name The authored name.
     * @return array<string, string> The parts.
     */
    private static function decompose(string $name): array
    {
        $parts = explode(':', $name);

        if (count($parts) === 2 && $parts[0] === 'offence') {
            return ['kind' => 'offence', 'element' => $parts[1]];
        }

        if (count($parts) === 3 && $parts[0] === 'defence' && in_array($parts[2], EquipmentOptimizationPolicy::OUTCOMES, true)) {
            return ['kind' => 'defence', 'element' => $parts[1], 'outcome' => $parts[2]];
        }

        return ['kind' => 'other', 'name' => $name];
    }

    /**
     * Composes the lookup name the runtime will search for.
     *
     * @param string $kind The kind of weight.
     * @param array<string, mixed> $row The record.
     * @return string The name, or empty when the record does not name one.
     */
    private static function compose(string $kind, array $row): string
    {
        $element = trim(strval($row['element'] ?? ''));

        return match ($kind) {
            'offence' => $element === '' ? '' : sprintf('offence:%s', $element),
            'defence' => $element === '' ? '' : sprintf(
                'defence:%s:%s',
                $element,
                trim(strval($row['outcome'] ?? EquipmentOptimizationPolicy::OUTCOMES[0])),
            ),
            default => trim(strval($row['name'] ?? '')),
        };
    }

    /**
     * Reads one map out of the file.
     *
     * @param array<string, mixed> $whole The payload.
     * @param string $key The key.
     * @return array<string, mixed> The map.
     */
    private static function mapOf(array $whole, string $key): array
    {
        return is_array($whole[$key] ?? null) ? $whole[$key] : [];
    }

    /**
     * @inheritDoc
     *
     * Rows regroup into outcome and property maps; their order is not stored.
     */
    public function ordersRecords(): bool
    {
        return false;
    }
}
