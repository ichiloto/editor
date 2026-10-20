<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Income\IncomeCatalog;

/**
 * Income schedules through the editor session the GUI editor drives: the
 * category is listed, a schedule is created and edited by row, saved through
 * the category's own file, and offered to the income commands' pickers.
 * Synthetic fixtures only.
 */

function incomeSessionRow(array $read, string $fieldId): array
{
    return array_find($read['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $fieldId)
        ?? throw new RuntimeException("No record row {$fieldId}.");
}

it('authors a schedule the GUI lists, edits, saves and offers to the income commands', function () {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);

    expect(array_column($session->describeProject()['databases'], 'label', 'key'))->toHaveKey('income_schedules', 'Income');

    $index = $session->createDatabaseRecord('income_schedules')['index'];
    $session->applyDatabaseRecord('income_schedules', $index, incomeSessionRow($session->readDatabaseRecord('income_schedules', $index), 'name')['key'], 'Rent');
    $cadence = incomeSessionRow($session->readDatabaseRecord('income_schedules', $index), 'cadence');
    $session->applyDatabaseRecord('income_schedules', $index, $cadence['key'], 'event');
    $session->applyDatabaseRecord('income_schedules', $index, incomeSessionRow($session->readDatabaseRecord('income_schedules', $index), 'amount')['key'], '75');
    $session->saveDatabase('income_schedules');

    $rows = array_map(static fn(array $row): ?string => $row['key']['field'] ?? null, $session->readDatabaseRecord('income_schedules', $index)['rows']);
    $schedule = IncomeCatalog::fromProject($root . '/assets/' . IncomeCatalog::FILE)->findSchedule('rent');

    expect($rows)->not->toContain('interval', 'idleTimeout')
        ->and($schedule?->cadence->value)->toBe('event')
        ->and($schedule?->amount)->toBe(75)
        ->and($session->listReferences(null, 'income_schedules'))->toBe([['value' => 'rent', 'label' => 'Rent (rent)']]);
});
