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

    /** @inheritDoc */
    public function preservationIssue(mixed $whole): ?string
    {
        if (! is_array($whole)) {
            return sprintf('returns %s, not an array', get_debug_type($whole));
        }

        if (array_key_exists('statWeights', $whole)) {
            $issue = self::weightMapIssue($whole['statWeights'], 'statWeights');

            if ($issue !== null) {
                return $issue;
            }
        }

        foreach (['roleStatWeights', 'slotStatWeights'] as $key) {
            if (! array_key_exists($key, $whole)) {
                continue;
            }

            $groups = $whole[$key];

            if (! is_array($groups)) {
                return sprintf('field "%s" is %s, not a map', $key, get_debug_type($groups));
            }

            foreach ($groups as $name => $weights) {
                $issue = self::weightMapIssue($weights, sprintf('%s.%s', $key, strval($name)));

                if ($issue !== null) {
                    return $issue;
                }
            }
        }

        if (! array_key_exists('roleSlotStatWeights', $whole)) {
            return null;
        }

        $roles = $whole['roleSlotStatWeights'];

        if (! is_array($roles)) {
            return sprintf('field "roleSlotStatWeights" is %s, not a map', get_debug_type($roles));
        }

        foreach ($roles as $role => $slots) {
            if (! is_array($slots)) {
                return sprintf(
                    'field "roleSlotStatWeights.%s" is %s, not a map',
                    strval($role),
                    get_debug_type($slots),
                );
            }

            foreach ($slots as $slot => $weights) {
                $issue = self::weightMapIssue(
                    $weights,
                    sprintf('roleSlotStatWeights.%s.%s', strval($role), strval($slot)),
                );

                if ($issue !== null) {
                    return $issue;
                }
            }
        }

        return null;
    }

    /**
     * Checks one authored stat-to-weight map before projection.
     */
    private static function weightMapIssue(mixed $weights, string $path): ?string
    {
        if (! is_array($weights)) {
            return sprintf('field "%s" is %s, not a weight map', $path, get_debug_type($weights));
        }

        foreach ($weights as $stat => $weight) {
            if (! is_numeric($weight)) {
                return sprintf(
                    'field "%s.%s" is %s, not a numeric weight',
                    $path,
                    strval($stat),
                    get_debug_type($weight),
                );
            }
        }

        return null;
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

    /**
     * @inheritDoc
     *
     * Rows regroup into base, role and slot maps; their order is not stored.
     */
    public function ordersRecords(): bool
    {
        return false;
    }
}
