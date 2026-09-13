<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;

/**
 * Seeds two battle-entry rules through the record database and saves them.
 */
function seedTwoBattleEntryRules(string $root): void
{
    $database = loadRecordDatabase($root, 'battle_entry_rules');

    foreach ([['alpha', 'speed'], ['beta', 'grace']] as [$id, $stat]) {
        $index = $database->addRecord();
        $database->setField($index, 'id', $id);
        $database->setField($index, 'actors', 'Kaelion:any');
        $database->setField($index, 'effect0Actor', 'Kaelion');
        $database->setField($index, 'effect0Stat', $stat);
    }

    $database->save();
}

it('persists a Battle Entry reorder through save, reopen and undo', function (): void {
    $root = makeTemporaryProject();

    try {
        seedTwoBattleEntryRules($root);

        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'battle_entry_rules');
        $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('battle_entry_rules');

        expect($database->supportsDurableReorder())->toBeTrue();

        callEditorMethod($editor, 'dispatchInput', ']');
        expect($database->getEntryLabels())->toBe(['beta', 'alpha']);

        callEditorMethod($editor, 'saveActiveDatabase');

        // The reopened project holds the authored order — the whole point.
        expect(array_column((require battleEntryRulesPath($root))['rules'], 'id'))->toBe(['beta', 'alpha'])
            ->and(loadRecordDatabase($root, 'battle_entry_rules')->getEntryLabels())->toBe(['beta', 'alpha']);

        // Undo is still a real edit: it dirties, saves, and reopens reverted.
        callEditorMethod($editor, 'dispatchInput', "\x1a");
        expect($database->getEntryLabels())->toBe(['alpha', 'beta'])
            ->and($database->isDirty())->toBeTrue();

        callEditorMethod($editor, 'dispatchInput', "\x19");
        expect($database->getEntryLabels())->toBe(['beta', 'alpha']);

        callEditorMethod($editor, 'dispatchInput', "\x1a");
        callEditorMethod($editor, 'saveActiveDatabase');
        expect(array_column((require battleEntryRulesPath($root))['rules'], 'id'))->toBe(['alpha', 'beta']);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('persists a knowledge subject reorder through save and reopen', function (): void {
    $root = makeTemporaryProject();

    try {
        $database = loadRecordDatabase($root, 'knowledge_subjects');

        foreach (['wolf' => 'Wolf', 'bear' => 'Bear'] as $id => $name) {
            $index = $database->addRecord();
            $database->setField($index, 'id', $id);
            $database->setField($index, 'displayName', $name);
        }

        $database->save();

        $database = loadRecordDatabase($root, 'knowledge_subjects');

        expect($database->supportsDurableReorder())->toBeTrue()
            ->and($database->moveRecord(0, 1))->toBeTrue();

        $database->save();

        expect(loadRecordDatabase($root, 'knowledge_subjects')->getEntryLabels())->toBe(['Bear', 'Wolf']);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('refuses to reorder where the file cannot keep the order, changing nothing', function (): void {
    $root = makeTemporaryProject();

    try {
        // A second skit, so the directory category has an order to lose.
        $seed = loadRecordDatabase($root, 'skits');
        $seed->addRecord();
        $seed->save();

        $cases = [
            // A constructor-authored list: entries are new Item(...) calls.
            'items' => 'assets/Data/items.php',
            // A plain authored list, written surgically by identity.
            'states' => 'assets/Data/states.php',
            // One file per record: the list is file order.
            'skits' => null,
        ];

        foreach ($cases as $category => $watchedFile) {
            // A fresh editor per case: the open helper toggles the screen,
            // so reusing one would route keys to the map on every second
            // category.
            $editor = deletionEditor($root);
            openDatabaseCategory($editor, $category);
            $database = getEditorProperty($editor, 'workspace')->getRecordDatabase($category);
            $labels = $database->getEntryLabels();
            $path = $watchedFile === null ? null : $root . '/' . $watchedFile;
            $before = $path === null ? null : [md5((string) file_get_contents($path)), filemtime($path)];

            expect($database->supportsDurableReorder())->toBeFalse($category)
                ->and($database->reorderRefusalReason())->not->toBeNull();

            callEditorMethod($editor, 'dispatchInput', ']');

            clearstatcache();

            // No order change, no dirt, no history entry, no byte, no mtime.
            expect($database->getEntryLabels())->toBe($labels, $category)
                ->and($database->isDirty())->toBeFalse($category)
                ->and(getEditorProperty($editor, 'statusMessage'))
                    ->toBe((string) $database->reorderRefusalReason(), $category);

            if ($before !== null && $path !== null) {
                expect(md5((string) file_get_contents($path)))->toBe($before[0], $category)
                    ->and(filemtime($path))->toBe($before[1], $category);
            }

            // No history entry was recorded: the refusal left nothing to
            // undo. (The warning status deliberately holds, so the empty
            // history is asserted directly.)
            expect(getEditorProperty($editor, 'history')->canUndo())->toBeFalse($category);
        }
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('answers false for every shape whose save stores no list order', function (): void {
    $root = makeTemporaryProject();

    try {
        foreach (['terms', 'types', 'optimize_weights', 'optimize_outcomes', 'optimize_exclusions'] as $category) {
            $database = loadRecordDatabase($root, $category);

            expect($database->supportsDurableReorder())->toBeFalse($category)
                ->and($database->moveRecord(0, 1))->toBeFalse($category);
        }

        // Map-owned records are written back through their owner, which
        // stores no list order of its own.
        $owned = ProjectRecordDatabase::overOwnedList(
            RecordSchemaCatalog::mapNpcs(),
            $root . '/assets/Maps/test-map',
            [['id' => 'npc-a', 'name' => 'A'], ['id' => 'npc-b', 'name' => 'B']],
            static function (): void {
            },
        );

        expect($owned->supportsDurableReorder())->toBeFalse()
            ->and($owned->moveRecord(0, 1))->toBeFalse()
            ->and($owned->getEntryLabels())->toBe(['A', 'B']);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('keeps Save All honest after a refused reorder', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'states');

        $watched = [$root . '/assets/Data/states.php', $root . '/assets/Data/troops.php'];
        $backdated = strtotime('2020-01-01 00:00:00');

        foreach ($watched as $file) {
            touch($file, $backdated);
        }

        clearstatcache();
        $before = [];

        foreach ($watched as $file) {
            $before[$file] = [md5((string) file_get_contents($file)), filemtime($file)];
        }

        callEditorMethod($editor, 'dispatchInput', ']');
        callEditorMethod($editor, 'saveAllAssets');

        clearstatcache();

        foreach ($before as $file => [$hash, $mtime]) {
            expect(md5((string) file_get_contents($file)))->toBe($hash)
                ->and(filemtime($file))->toBe($mtime);
        }
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('describes the record keys in help only where they actually work', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);

        openDatabaseCategory($editor, 'battle_entry_rules');
        $help = implode("\n", callEditorMethod($editor, 'getHelpLines'));
        expect($help)->toContain('[ / ]              move the entry')
            ->and($help)->toContain('Shift+D            duplicate the entry');

        openDatabaseCategory($editor, 'items');
        $help = implode("\n", callEditorMethod($editor, 'getHelpLines'));
        expect($help)->not->toContain('move the entry up or down')
            ->and($help)->not->toContain('Shift+D            duplicate the entry');

        openDatabaseCategory($editor, 'states');
        $help = implode("\n", callEditorMethod($editor, 'getHelpLines'));
        // A plain list duplicates but cannot durably reorder.
        expect($help)->toContain('Shift+D            duplicate the entry')
            ->and($help)->not->toContain('move the entry up or down');
    } finally {
        removeDirectoryRecursively($root);
    }
});
