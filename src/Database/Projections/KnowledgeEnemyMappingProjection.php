<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;

/**
 * Which knowledge subject an enemy is a record of.
 *
 * The runtime reads a map from enemy name to subject id. A map is not a list
 * of records either, so each pair becomes a record with the enemy on one side
 * and the subject on the other -- both picked, because a mapping that names
 * an enemy the project does not have, or a subject it does not declare, is a
 * mapping that never fires and never says so.
 *
 * Knowledge does not require an enemy: a subject the party never fights is an
 * ordinary record. This is only for the ones that are.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class KnowledgeEnemyMappingProjection implements RecordProjection
{
    /**
     * @inheritDoc
     */
    public function read(array $whole): array
    {
        $rows = [];

        foreach (is_array($whole['enemyMappings'] ?? null) ? $whole['enemyMappings'] : [] as $enemy => $subject) {
            if (is_scalar($subject)) {
                $rows[] = ['enemy' => strval($enemy), 'subject' => strval($subject)];
            }
        }

        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function write(array $whole, array $rows): array
    {
        $mappings = [];

        foreach ($rows as $row) {
            $enemy = trim(strval($row['enemy'] ?? ''));
            $subject = trim(strval($row['subject'] ?? ''));

            // The runtime requires a stable subject id and refuses the whole
            // catalogue without one, so a half-named mapping is kept out of
            // the file rather than written into a catalogue that would then
            // fail to load. Validation reports it.
            if ($enemy !== '' && $subject !== '') {
                $mappings[$enemy] = $subject;
            }
        }

        if ($mappings === []) {
            unset($whole['enemyMappings']);

            return $whole;
        }

        $whole['enemyMappings'] = $mappings;

        return $whole;
    }

    /**
     * @inheritDoc
     *
     * The mapping is written in row order, which the map keeps.
     */
    public function ordersRecords(): bool
    {
        return true;
    }
}
