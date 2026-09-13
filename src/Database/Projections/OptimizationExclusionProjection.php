<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;

/**
 * What Optimize is not allowed to choose on its own.
 *
 * A project keeps three lists: definitions excluded outright, whole
 * availabilities excluded, and whole acquisition policies excluded. They are
 * three lists because they are looked up three ways, but they are one thing
 * to an author -- what automatic selection may not take -- so they are edited
 * as one list of exclusions that each know their own kind.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class OptimizationExclusionProjection implements RecordProjection
{
    /**
     * Each kind of exclusion, and the key holding it.
     */
    public const array KEYS = [
        'definition' => 'excludedDefinitionIds',
        'availability' => 'excludedAvailabilities',
        'acquisition' => 'excludedAcquisitionPolicies',
    ];

    /**
     * @inheritDoc
     */
    public function read(array $whole): array
    {
        $rows = [];

        foreach (self::KEYS as $kind => $key) {
            foreach (is_array($whole[$key] ?? null) ? $whole[$key] : [] as $value) {
                if (is_scalar($value)) {
                    $rows[] = ['kind' => $kind, 'value' => strval($value)];
                }
            }
        }

        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function write(array $whole, array $rows): array
    {
        $lists = array_map(static fn(): array => [], self::KEYS);

        foreach ($rows as $row) {
            $kind = strval($row['kind'] ?? '');

            if (isset($lists[$kind])) {
                // An exclusion an author has not finished naming is kept as
                // they left it. The runtime discards a blank name when it
                // normalises the set, so an unfinished row excludes nothing
                // rather than something unintended, and validation says so.
                $lists[$kind][] = trim(strval($row['value'] ?? ''));
            }
        }

        foreach (self::KEYS as $kind => $key) {
            if ($lists[$kind] === []) {
                unset($whole[$key]);

                continue;
            }

            $whole[$key] = $lists[$kind];
        }

        return $whole;
    }

    /** @inheritDoc */
    public function preservationIssue(mixed $whole): ?string
    {
        if (! is_array($whole)) {
            return sprintf('returns %s, not an array', get_debug_type($whole));
        }

        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $whole)) {
                continue;
            }

            $values = $whole[$key];

            if (! is_array($values) || ! array_is_list($values)) {
                return sprintf(
                    'field "%s" is %s, not an ordered list',
                    $key,
                    is_array($values) ? 'a keyed array' : get_debug_type($values),
                );
            }

            foreach ($values as $index => $value) {
                if (! is_scalar($value)) {
                    return sprintf(
                        'field "%s" entry %d is %s, which the editor cannot preserve',
                        $key,
                        $index + 1,
                        get_debug_type($value),
                    );
                }
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     *
     * Rows regroup into per-kind lists; their order across kinds is not stored.
     */
    public function ordersRecords(): bool
    {
        return false;
    }
}
