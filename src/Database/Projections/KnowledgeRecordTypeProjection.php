<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;

/**
 * The kinds of record a project's knowledge catalogue declares.
 *
 * The runtime reads them as an ordered list, each an id alone or an id with
 * the label players see it called, which is not a shape an editor can put
 * rows on, so each becomes a record of its own and the list is rebuilt on
 * the way out: a type without a label stays its bare id. Every subject's
 * Record Type is picked from what this declares, so a project that cannot
 * author them cannot introduce a kind of record at all.
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
                $rows[] = ['type' => strval($type), 'label' => ''];
            } elseif (is_array($type)) {
                $rows[] = ['type' => strval($type['id'] ?? ''), 'label' => strval($type['label'] ?? '')];
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

        $ids = [];

        foreach ($rows as $row) {
            $type = trim(strval($row['type'] ?? ''));
            $label = trim(strval($row['label'] ?? ''));

            if ($type !== '' && ! in_array($type, $ids, true)) {
                $ids[] = $type;
                $types[] = $label === '' ? $type : ['id' => $type, 'label' => $label];
            }
        }

        if ($types === []) {
            unset($whole['recordTypes']);

            return $whole;
        }

        $whole['recordTypes'] = $types;

        return $whole;
    }

    /** @inheritDoc */
    public function preservationIssue(mixed $whole): ?string
    {
        if (! is_array($whole)) {
            return sprintf('returns %s, not an array', get_debug_type($whole));
        }

        if (! array_key_exists('recordTypes', $whole)) {
            return null;
        }

        $types = $whole['recordTypes'];

        if (! is_array($types) || ! array_is_list($types)) {
            return sprintf(
                'field "recordTypes" is %s, not an ordered list',
                is_array($types) ? 'a keyed array' : get_debug_type($types),
            );
        }

        foreach ($types as $index => $type) {
            // An id with a label is written back as it was given.
            $labelled = is_array($type) && array_diff(array_keys($type), ['id', 'label']) === []
                && is_scalar($type['id'] ?? null) && is_scalar($type['label'] ?? '');

            if (! is_scalar($type) && ! $labelled) {
                return sprintf(
                    'field "recordTypes" entry %d is %s, which the editor cannot preserve',
                    $index + 1,
                    get_debug_type($type),
                );
            }
        }

        return null;
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
