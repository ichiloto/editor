<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordCategory;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\Inspector\InputControlType;

/** A whole-number field removed when empty reads its absence as its shown default; zero is unset only where absence means zero. */

function integerAbsenceRecords(): RecordCategory
{
    $schema = new RecordSchema('integers', 'entry', RecordStorage::LIST_FILE, 'assets/Data/integers.php', [
        new RecordField('id', 'Id'),
        new RecordField('count', 'Count', InputControlType::INTEGER, removeWhenEmpty: true),
        new RecordField('chance', 'Chance %', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '100'),
        new RecordField('rest', 'Rest Frame', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: 'Last frame'),
    ]);

    return new RecordCategory(ProjectRecordDatabase::overOwnedList($schema, sys_get_temp_dir(), [['id' => 'one']], static function (): void {
    }));
}

function readIntegerAbsence(RecordCategory $records): array
{
    return array_column($records->getRecordRows(0, []), 'value', 'label');
}

it('keeps zero where absence means something else, and clears a field given its shown default', function () {
    $records = integerAbsenceRecords();
    foreach (['count' => '0', 'chance' => '0', 'rest' => '0'] as $field => $value) {
        $records->applyField(0, [], $field, $value, 'Set');
    }

    expect(readIntegerAbsence($records))->toMatchArray(['Count' => '', 'Chance %' => '0', 'Rest Frame' => '0']);

    $records->applyField(0, [], 'chance', '100', 'Set');
    $records->applyField(0, [], 'rest', 'last frame', 'Set');
    expect(readIntegerAbsence($records))->toMatchArray(['Chance %' => '100', 'Rest Frame' => 'Last frame']);

    $records->applyField(0, [], 'rest', '', 'Set');
    expect(readIntegerAbsence($records)['Rest Frame'])->toBe('Last frame');
});
