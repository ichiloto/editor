<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\ProjectWorkspace;

it('lists entries and builds settings rows for a schema-driven category', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'states');

        expect(callEditorMethod($editor, 'getDatabaseEntryLabels'))->toBe(['Poison', 'Stun']);

        $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');

        expect(array_column($fields, 'field'))
            ->toContain('id', 'name', 'icon', 'durationTurns', 'preventsAction');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('moves the selection independently per category', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);

        openDatabaseCategory($editor, 'states');
        callEditorMethod($editor, 'moveDatabaseSelection', 0, 1);
        expect(callEditorMethod($editor, 'getSelectedDatabaseEntryIndex'))->toBe(1);

        openDatabaseCategory($editor, 'troops');
        expect(callEditorMethod($editor, 'getSelectedDatabaseEntryIndex'))->toBe(0);

        openDatabaseCategory($editor, 'states');
        expect(callEditorMethod($editor, 'getSelectedDatabaseEntryIndex'))->toBe(1);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('records an undoable command for a record field edit', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'states');

        $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
        $nameIndex = array_search('name', array_column($fields, 'field'), true);

        setEditorProperty($editor, 'databaseSelectedSettingIndex', $nameIndex);
        callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields[$nameIndex], 'Venom');

        $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('states');
        expect($database->getEntryLabels()[0])->toBe('Venom');

        callEditorMethod($editor, 'dispatchInput', "\x1a");

        expect($database->getEntryLabels()[0])->toBe('Poison');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('pins undo to the edited entry after the selection moves', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'states');

        $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
        $nameIndex = array_search('name', array_column($fields, 'field'), true);
        callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields[$nameIndex], 'Venom');

        // Move to the other entry before undoing.
        callEditorMethod($editor, 'moveDatabaseSelection', 0, 1);
        expect(callEditorMethod($editor, 'getSelectedDatabaseEntryIndex'))->toBe(1);

        callEditorMethod($editor, 'dispatchInput', "\x1a");

        $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('states');
        expect($database->getEntryLabels())->toBe(['Poison', 'Stun']);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('adds and removes a skit beat with Shift+O and Shift+X, both undoable', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'skits');

        $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('skits');
        expect($database->countSubItems(0))->toBe(2);

        callEditorMethod($editor, 'dispatchInput', 'O');
        expect($database->countSubItems(0))->toBe(3);
        expect(getEditorProperty($editor, 'referencePicker')->isOpen())->toBeTrue();
        callEditorMethod($editor, 'dispatchInput', "\x1b");

        callEditorMethod($editor, 'dispatchInput', "\x1a");
        expect($database->countSubItems(0))->toBe(2);

        callEditorMethod($editor, 'dispatchInput', 'X');
        expect($database->countSubItems(0))->toBe(1);

        callEditorMethod($editor, 'dispatchInput', "\x1a");
        expect($database->countSubItems(0))->toBe(2);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('warns instead of editing when the category is read-only', function (): void {
    $root = makeTemporaryProject();
    $category = makeUnwritableCategory($root);
    $typesPath = $root . '/assets/Data';
    $before = md5(implode('', array_map(
        static fn(string $file): string => (string) file_get_contents($file),
        glob($typesPath . '/system.php') ?: []
    )));

    try {
        $editor = deletionEditor($root);

        openDatabaseCategory($editor, $category);

        callEditorMethod($editor, 'saveActiveDatabase');

        $after = md5(implode('', array_map(
            static fn(string $file): string => (string) file_get_contents($file),
            glob($typesPath . '/system.php') ?: []
        )));

        expect(getEditorProperty($editor, 'statusMessage'))->toContain('read-only')
            ->and($after)->toBe($before);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('enrols editable record categories in Save All and leaves read-only ones out', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        $saveable = getEditorProperty($editor, 'workspace')->listSaveableDatabases();

        expect(array_keys($saveable))->toContain('States', 'Troops', 'Skits', 'Common Events', 'Terms');
        expect(array_keys($saveable))->not->toContain('Items', 'Weapons', 'Armors', 'Enemies', 'Types', 'Tilesets');

        foreach (['States', 'Troops', 'Skits'] as $label) {
            expect($saveable[$label])->toBeInstanceOf(ProjectRecordDatabase::class);
        }
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('reports record-category edits as unsaved workspace changes', function (): void {
    $root = makeTemporaryProject();

    try {
        $workspace = ProjectWorkspace::fromProject($root);
        expect($workspace->hasUnsavedChanges())->toBeFalse();

        $workspace->getRecordDatabase('states')->setField(0, 'name', 'Venom');

        expect($workspace->hasUnsavedChanges())->toBeTrue();
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('saves a record category through the editor and backs the file up first', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'troops');

        $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('troops');
        $database->setField(0, 'name', 'Bat Swarm');

        expect($database->getBackupPaths())
            ->toBe([$root . '/assets/Data/troops.php']);

        callEditorMethod($editor, 'saveActiveDatabase');

        expect(getEditorProperty($editor, 'statusMessage'))->toContain('saved');

        $payload = require $root . '/assets/Data/troops.php';
        expect($payload[0]['name'])->toBe('Bat Swarm');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it("chooses a state's disposition from the Engine's, and writes nothing while it is the default", function (): void {
    $root = makeTemporaryProject();

    try {
        $database = ProjectWorkspace::fromProject($root)->getRecordDatabase('states');
        $field = array_values(array_filter($database->schema->fields,
            static fn($field): bool => $field->key === 'disposition'))[0] ?? null;

        expect($field?->options)->toBe(['harmful', 'beneficial', 'neutral'])
            ->and($field?->displayDefault)->toBe('harmful');

        $database->setField(1, 'disposition', 'neutral');
        $database->save();
        $saved = require $root . '/assets/Data/states.php';

        expect($saved[1]['disposition'])->toBe('neutral')
            ->and($saved[0])->not->toHaveKey('disposition')
            ->and(\Ichiloto\Engine\Entities\States\State::fromArray($saved[1])->disposition)
            ->toBe(\Ichiloto\Engine\Entities\States\StateDisposition::NEUTRAL);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('creates a record through the shared record rules as one undo step', function (): void {
    $editor = deletionEditor(makeTemporaryProject());
    openDatabaseCategory($editor, 'states');
    $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('states');
    $before = $database->getEntryLabels();

    callEditorMethod($editor, 'createDatabaseRecord');
    // The new record opens its first row for editing; leave it unedited.
    setEditorProperty($editor, 'isDatabaseEditing', false);

    expect($database->getEntryLabels())->toHaveCount(count($before) + 1)
        ->and(callEditorMethod($editor, 'getSelectedRecordIndex'))->toBe(count($before));

    callEditorMethod($editor, 'performUndo');

    expect($database->getEntryLabels())->toBe($before)
        ->and($database->isDirty())->toBeFalse();
});

it('reports a parameter line it cannot read and leaves the record and history as they were', function (): void {
    $root = makeTemporaryProject();
    file_put_contents($root . '/' . \Ichiloto\Editor\PermanentGrowthCatalog::RELATIVE_PATH, <<<'SOURCE'
    <?php

    return [
      ['id' => 'growth.synthetic', 'stat' => 'maxHp', 'amount' => 1, 'sourceType' => 'event', 'sourceId' => 'test', 'metadata' => ['label' => 'Synthetic']],
    ];
    SOURCE);
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'permanent_growth');
    $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('permanent_growth');
    $before = $database->getRecordByIndex(0)?->toArray();
    $rows = array_column(callEditorMethod($editor, 'getDatabaseSettingsFields'), 'field');
    setEditorProperty($editor, 'databaseSelectedSettingIndex', array_search('metadata', $rows, true));
    setEditorProperty($editor, 'isDatabaseEditing', true);
    setEditorProperty($editor, 'databaseEditBuffer', 'tier=1, tier=2');
    getEditorProperty($editor, 'toasts')->clear();

    callEditorMethod($editor, 'commitDatabaseEdit');

    // The refusal is what the author reads, not an edit reported as made.
    expect(getEditorProperty($editor, 'statusMessage'))->toContain('named twice')
        ->and(getEditorProperty($editor, 'statusMessage'))->not->toContain('updated')
        ->and($database->getRecordByIndex(0)?->toArray())->toBe($before)
        ->and(getEditorProperty($editor, 'history')->canUndo())->toBeFalse();
});
