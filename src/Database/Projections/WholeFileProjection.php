<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;

/**
 * A file that is one record: `system.php` returns the project's system
 * settings as a single map, edited as the category's only record. It is
 * never created, duplicated, moved or deleted, only edited.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class WholeFileProjection implements RecordProjection
{
    /**
     * @inheritDoc
     */
    public function read(array $whole): array
    {
        return [$whole];
    }

    /**
     * @inheritDoc
     */
    public function write(array $whole, array $rows): array
    {
        $record = $rows[0] ?? $whole;

        return is_array($record) ? $record : $whole;
    }

    /**
     * @inheritDoc
     */
    public function preservationIssue(mixed $whole): ?string
    {
        return is_array($whole) && ! array_is_list($whole) || $whole === []
            ? null
            : sprintf('returns %s, not one map of settings', is_array($whole) ? 'a list' : get_debug_type($whole));
    }

    /**
     * @inheritDoc
     */
    public function ordersRecords(): bool
    {
        return false;
    }
}
