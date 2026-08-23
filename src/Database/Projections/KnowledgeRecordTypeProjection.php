<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;

/**
 * The kinds of record a project's knowledge catalogue declares.
 *
 * The runtime reads them as a flat list of names, which is not a shape an
 * editor can put rows on, so each name becomes a record of its own and the
 * flat list is rebuilt on the way out. Every subject's Record Type is picked
 * from what this declares, so a project that cannot author them cannot
 * introduce a kind of record at all.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class KnowledgeRecordTypeProjection implements RecordProjection
{
    /**
     * @inheritDoc
     */
    public function read(array $whole): array
    {
        $rows = [];

        foreach (is_array($whole['recordTypes'] ?? null) ? $whole['recordTypes'] : [] as $type) {
            if (is_scalar($type)) {
                $rows[] = ['type' => strval($type)];
            }
        }

        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function write(array $whole, array $rows): array
    {
        $types = [];

        foreach ($rows as $row) {
            $type = trim(strval($row['type'] ?? ''));

            if ($type !== '' && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        if ($types === []) {
            unset($whole['recordTypes']);

            return $whole;
        }

        $whole['recordTypes'] = $types;

        return $whole;
    }

    /**
     * @inheritDoc
     *
     * Types are written as the ordered list they were given.
     */
    public function ordersRecords(): bool
    {
        return true;
    }
}
