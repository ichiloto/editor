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
    $typesPath = $root . '/assets/Data/Types';
    $before = md5(implode('', array_map(
        static fn(string $file): string => (string) file_get_contents($file),
        glob($typesPath . '/*.php') ?: []
    )));

    try {
        $editor = deletionEditor($root);

        // Element and weapon types are PHP enum declarations, not data.
        openDatabaseCategory($editor, 'types');

        callEditorMethod($editor, 'saveActiveDatabase');

        $after = md5(implode('', array_map(
            static fn(string $file): string => (string) file_get_contents($file),
            glob($typesPath . '/*.php') ?: []
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
        $saveable = callEditorMethod($editor, 'getSaveableDatabases');

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

        expect(callEditorMethod($editor, 'getDatabaseBackupPaths', $database))
            ->toBe([$root . '/assets/Data/troops.php']);

        callEditorMethod($editor, 'saveActiveDatabase');

        expect(getEditorProperty($editor, 'statusMessage'))->toContain('saved');

        $payload = require $root . '/assets/Data/troops.php';
        expect($payload[0]['name'])->toBe('Bat Swarm');
    } finally {
        removeDirectoryRecursively($root);
    }
});
