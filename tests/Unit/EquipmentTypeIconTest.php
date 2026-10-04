<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;

/**
 * Equipment is shown by the one icon of its type, from the theme. An
 * equipment entry's own icon is legacy compatibility data: shown and kept
 * exactly as written, never offered for editing. A consumable keeps its own.
 */
function iconRow(ProjectRecordDatabase $database, int $index): ?array
{
    return array_find($database->getSettingsFields($index), static fn(array $field): bool => ($field['field'] ?? null) === 'icon');
}

it('shows an armor or accessory\'s own icon as read-only legacy data', function () {
    $database = ProjectRecordDatabase::fromProject(makeTemporaryProject(), RecordSchemaCatalog::forKey('armors'));
    $row = iconRow($database, $database->addRecord());

    expect($row)->not->toBeNull()
        ->and($row['editable'] ?? true)->toBeFalse()
        ->and($row['label'])->toContain('Legacy Icon');
});

it('shows a weapon\'s own icon as read-only legacy data and keeps it through an edit', function () {
    $root = makeTemporaryProject();
    $database = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('weapons'));
    $before = $database->getRecords()[0]->get('icon');
    $row = iconRow($database, 0);

    expect($row)->not->toBeNull()
        ->and($row['editable'] ?? true)->toBeFalse()
        ->and($row['label'])->toContain('Legacy Icon');

    $database->setField(0, 'description', 'Edited beside a legacy icon.');
    $database->save();
    $saved = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('weapons'))->getRecords()[0];

    expect($before)->not->toBeNull()
        ->and($saved->get('description'))->toBe('Edited beside a legacy icon.')
        ->and($saved->get('icon'))->toBe($before);
});

it('keeps a consumable\'s own icon editable', function () {
    $database = ProjectRecordDatabase::fromProject(makeTemporaryProject(), RecordSchemaCatalog::forKey('items'));
    $row = iconRow($database, 0);

    expect($row)->not->toBeNull()
        ->and($row['editable'] ?? true)->toBeTrue()
        ->and($row['label'])->toBe('Icon');
});
