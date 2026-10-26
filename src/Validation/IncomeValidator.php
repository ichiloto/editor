<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Income\IncomeCatalog;
use Ichiloto\Engine\Income\IncomeSchedule;
use InvalidArgumentException;

/**
 * Checks the project's income schedules, unsaved edits included, by the
 * Engine's own reading: a schedule the Engine would refuse stops the game
 * from starting, so it is an error. Each schedule is checked on its own so
 * every broken one is named at once.
 */
final class IncomeValidator
{
    private const string WHERE = 'assets/' . IncomeCatalog::FILE;

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $database = $workspace->getRecordDatabase('income_schedules');
        if ($database === null || (! is_file($database->backingFilePath()) && ! $database->isDirty())) {
            return [];
        }

        $issues = [];
        $ids = [];
        foreach (array_values($database->getRecords()) as $index => $record) {
            try {
                $schedule = IncomeSchedule::fromArray($record->toArray(), sprintf('Schedule [%d]', $index));
            } catch (InvalidArgumentException $invalid) {
                $issues[] = Issue::error(self::WHERE, 'The game cannot read an income schedule: ' . $invalid->getMessage(),
                    'Correct it in Database > Income; the game will not start until it is fixed.');
                continue;
            }
            if (isset($ids[$schedule->id])) {
                $issues[] = Issue::error(self::WHERE, sprintf('Two income schedules share the id "%s".', $schedule->id),
                    'Scripts and saves name a schedule by its id, so each needs its own.');
            }
            $ids[$schedule->id] = true;
        }

        return $issues;
    }
}
