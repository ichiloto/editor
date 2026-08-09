<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectActorDatabase;

it('defaults the entry delete confirmation to Cancel: Enter does not delete', function () {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'classes');
        callEditorMethod($editor, 'dispatchInput', "\033[3~");

        expect(getEditorProperty($editor, 'isDatabaseEntryDeleteConfirmationOpen'))->toBeTrue();

        callEditorMethod($editor, 'dispatchInput', "\n");

        /** @var ProjectWorkspace $workspace */
        $workspace = getEditorProperty($editor, 'workspace');

        expect(getEditorProperty($editor, 'isDatabaseEntryDeleteConfirmationOpen'))->toBeFalse()
            ->and($workspace->classDatabase->getClasses())->toHaveCount(2)
            ->and($workspace->classDatabase->isDirty())->toBeFalse()
            ->and(getEditorProperty($editor, 'pendingDatabaseDeletion'))->toBeNull();
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('cancels the entry delete on Esc and on an explicit n', function () {
    $root = makeTemporaryProject();

    try {
        foreach (["\033", 'n'] as $cancelKey) {
            $editor = deletionEditor($root);
            openDatabaseCategory($editor, 'classes');
            callEditorMethod($editor, 'dispatchInput', "\033[3~");
            callEditorMethod($editor, 'dispatchInput', $cancelKey);

            /** @var ProjectWorkspace $workspace */
            $workspace = getEditorProperty($editor, 'workspace');

            expect(getEditorProperty($editor, 'isDatabaseEntryDeleteConfirmationOpen'))->toBeFalse()
                ->and($workspace->classDatabase->getClasses())->toHaveCount(2);
        }
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('deletes a class on an explicit y and restores it with one undo', function () {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'classes');
        setEditorProperty($editor, 'databaseSelectedClassIndex', 1);
        callEditorMethod($editor, 'dispatchInput', "\033[3~");
        callEditorMethod($editor, 'dispatchInput', 'y');

        /** @var ProjectWorkspace $workspace */
        $workspace = getEditorProperty($editor, 'workspace');
        $names = array_map(static fn(object $class): string => $class->getName(), $workspace->classDatabase->getClasses());

        expect($names)->toBe(['Vanguard'])
            ->and($workspace->classDatabase->isDirty())->toBeTrue()
            ->and(getEditorProperty($editor, 'databaseSelectedClassIndex'))->toBe(0);

        callEditorMethod($editor, 'dispatchInput', "\x1a");

        $restored = array_map(static fn(object $class): string => $class->getName(), $workspace->classDatabase->getClasses());

        expect($restored)->toBe(['Vanguard', 'Oracle']);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('deletes quests, animations, and actors through the same confirmation', function () {
    $root = makeTemporaryProject();

    try {
        foreach (['quests', 'animations', 'actors'] as $category) {
            $editor = deletionEditor($root);
            openDatabaseCategory($editor, $category);

            /** @var ProjectWorkspace $workspace */
            $workspace = getEditorProperty($editor, 'workspace');
            $before = count(callEditorMethod($editor, 'getDatabaseEntryLabels'));

            expect($before)->toBeGreaterThan(0);

            callEditorMethod($editor, 'dispatchInput', "\033[3~");
            callEditorMethod($editor, 'dispatchInput', 'y');

            expect(callEditorMethod($editor, 'getDatabaseEntryLabels'))->toHaveCount($before - 1);

            callEditorMethod($editor, 'dispatchInput', "\x1a");

            expect(callEditorMethod($editor, 'getDatabaseEntryLabels'))->toHaveCount($before);
        }
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('refuses deletion in categories that have no entries', function () {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        // The engine has no tileset system, so the category is genuinely empty.
        openDatabaseCategory($editor, 'tilesets');
        callEditorMethod($editor, 'dispatchInput', "\033[3~");

        expect(getEditorProperty($editor, 'isDatabaseEntryDeleteConfirmationOpen'))->toBeFalse()
            ->and(getEditorProperty($editor, 'statusMessage'))->toContain('does not support entry deletion');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('refuses deletion in a read-only category before prompting', function () {
    $root = makeTemporaryProject();
    $typesPath = $root . '/assets/Data/Types';
    $hash = static fn(): string => md5(implode('', array_map(
        static fn(string $file): string => (string) file_get_contents($file),
        glob($typesPath . '/*.php') ?: []
    )));
    $before = $hash();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'types');

        // The category lists real entries, so this is not an "empty" refusal.
        expect(callEditorMethod($editor, 'getDatabaseEntryLabels'))->not->toBeEmpty();

        callEditorMethod($editor, 'dispatchInput', "\033[3~");

        expect(getEditorProperty($editor, 'isDatabaseEntryDeleteConfirmationOpen'))->toBeFalse()
            ->and(getEditorProperty($editor, 'statusMessage'))->toContain('read-only')
            ->and($hash())->toBe($before);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('removes a deleted actor file only when the database is saved', function () {
    $root = makeTemporaryProject();

    try {
        $actorPath = $root . '/assets/Data/Actors/Kaelion.php';

        expect(is_file($actorPath))->toBeTrue();

        $database = ProjectActorDatabase::fromProject($root);
        $removed = $database->removeActor(0);

        expect($removed)->not->toBeNull()
            ->and($database->getActors())->toHaveCount(0)
            ->and($database->getPendingDeletions())->toBe([$actorPath])
            // Nothing on disk has changed yet — that is what makes undo honest.
            ->and(is_file($actorPath))->toBeTrue();

        // Undo before saving takes the staged deletion back with it.
        $database->insertActor(0, $removed);

        expect($database->getPendingDeletions())->toBe([]);

        $database->removeActor(0);
        $database->save();

        expect(is_file($actorPath))->toBeFalse();
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('consumes every keystroke while the delete confirmation is open', function () {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'classes');
        callEditorMethod($editor, 'dispatchInput', "\033[3~");

        // The Database key and the palette must not fire underneath it.
        callEditorMethod($editor, 'dispatchInput', "\x04");
        callEditorMethod($editor, 'dispatchInput', "\x10");

        expect(getEditorProperty($editor, 'isDatabaseEntryDeleteConfirmationOpen'))->toBeTrue()
            ->and(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue()
            ->and(getEditorProperty($editor, 'isCommandPaletteOpen'))->toBeFalse();
    } finally {
        removeDirectoryRecursively($root);
    }
});
