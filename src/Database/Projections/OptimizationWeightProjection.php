<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;

/**
 * The four scopes of Optimize stat weight, edited as one list of vectors.
 *
 * The runtime reads weights from four nested maps -- a base vector, one per
 * role, one per kind of slot, and one per role and slot together -- and
 * applies them in that order, each replacing the last. An author thinks in
 * vectors rather than in nesting, so each vector is one record that knows
 * which scope it belongs to, and the nesting is rebuilt on the way out.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class OptimizationWeightProjection implements RecordProjection
{
    /**
     * The scopes a vector can be declared at, in the order the runtime
     * applies them.
     */
    public const array SCOPES = ['base', 'role', 'slot', 'role+slot'];

    /**
     * @inheritDoc
     */
    public function read(array $whole): array
    {
        $rows = [];

        if (array_key_exists('statWeights', $whole)) {
            $rows[] = ['scope' => 'base', 'weights' => self::weights($whole['statWeights'])];
        }

        foreach (self::mapOf($whole, 'roleStatWeights') as $role => $weights) {
            $rows[] = ['scope' => 'role', 'role' => strval($role), 'weights' => self::weights($weights)];
        }

        foreach (self::mapOf($whole, 'slotStatWeights') as $slot => $weights) {
            $rows[] = ['scope' => 'slot', 'slot' => strval($slot), 'weights' => self::weights($weights)];
        }

        foreach (self::mapOf($whole, 'roleSlotStatWeights') as $role => $slots) {
            foreach (is_array($slots) ? $slots : [] as $slot => $weights) {
                $rows[] = [
                    'scope' => 'role+slot',
                    'role' => strval($role),
                    'slot' => strval($slot),
                    'weights' => self::weights($weights),
                ];
            }
        }

        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function write(array $whole, array $rows): array
    {
        $base = null;
        $byRole = [];
        $bySlot = [];
        $byRoleAndSlot = [];

        foreach ($rows as $row) {
            $weights = self::weights($row['weights'] ?? null);
            $role = trim(strval($row['role'] ?? ''));
            $slot = trim(strval($row['slot'] ?? ''));

            $scope = strval($row['scope'] ?? 'base');

            if ($scope === 'role' && $role !== '') {
                $byRole[$role] = $weights;
            } elseif ($scope === 'slot' && $slot !== '') {
                $bySlot[$slot] = $weights;
            } elseif ($scope === 'role+slot' && $role !== '' && $slot !== '') {
                $byRoleAndSlot[$role][$slot] = $weights;
            } elseif ($scope === 'base') {
                $base = $weights;
            }
        }

        foreach ([
            'statWeights' => $base,
            'roleStatWeights' => $byRole === [] ? null : $byRole,
            'slotStatWeights' => $bySlot === [] ? null : $bySlot,
            'roleSlotStatWeights' => $byRoleAndSlot === [] ? null : $byRoleAndSlot,
        ] as $key => $value) {
            if ($value === null) {
                unset($whole[$key]);

                continue;
            }

            $whole[$key] = $value;
        }

        return $whole;
    }

    /**
     * Reads one vector, keeping only the integers a weight can be.
     *
     * @param mixed $weights The authored vector.
     * @return array<string, int> The vector.
     */
    private static function weights(mixed $weights): array
    {
        $vector = [];

        foreach (is_array($weights) ? $weights : [] as $stat => $weight) {
            if (is_numeric($weight)) {
                $vector[strval($stat)] = intval($weight);
            }
        }

        return $vector;
    }

    /**
     * Reads one nested map out of the file.
     *
     * @param array<string, mixed> $whole The payload.
     * @param string $key The key.
     * @return array<string, mixed> The map.
     */
    private static function mapOf(array $whole, string $key): array
    {
        return is_array($whole[$key] ?? null) ? $whole[$key] : [];
    }
}
