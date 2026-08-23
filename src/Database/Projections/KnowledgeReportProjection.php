<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;

/**
 * The catalogue's reports, with their disagreements made pickable.
 *
 * A report says what it disagrees with as a flat list of report ids, which
 * an editor can only offer as typed text -- and a disagreement spelled by
 * hand is a disagreement with nothing. Each id is presented as a row of its
 * own so it can be picked from the reports the project actually has, and the
 * flat list the runtime reads is rebuilt on the way out.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class KnowledgeReportProjection implements RecordProjection
{
    /**
     * @inheritDoc
     */
    public function read(array $whole): array
    {
        $rows = [];

        foreach (is_array($whole['reports'] ?? null) ? $whole['reports'] : [] as $report) {
            if (! is_array($report)) {
                continue;
            }

            if (array_key_exists('disagreesWith', $report)) {
                $report['disagreesWith'] = array_values(array_map(
                    static fn(mixed $id): array => ['report' => is_scalar($id) ? strval($id) : ''],
                    is_array($report['disagreesWith']) ? $report['disagreesWith'] : [],
                ));
            }

            $rows[] = $report;
        }

        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function write(array $whole, array $rows): array
    {
        $reports = [];

        foreach ($rows as $report) {
            if (array_key_exists('disagreesWith', $report)) {
                $ids = array_values(array_filter(array_map(
                    static fn(mixed $entry): string => is_array($entry)
                        ? trim(strval($entry['report'] ?? ''))
                        : trim(strval(is_scalar($entry) ? $entry : '')),
                    is_array($report['disagreesWith']) ? $report['disagreesWith'] : [],
                ), static fn(string $id): bool => $id !== ''));

                // A report that disagrees with nothing does not say so.
                if ($ids === []) {
                    unset($report['disagreesWith']);
                } else {
                    $report['disagreesWith'] = $ids;
                }
            }

            $reports[] = $report;
        }

        $whole['reports'] = $reports;

        return $whole;
    }

    /**
     * @inheritDoc
     *
     * Reports are written as the ordered list they were given.
     */
    public function ordersRecords(): bool
    {
        return true;
    }
}
