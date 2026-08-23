<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\ListNavigation;
use Ichiloto\Editor\UI\CommandPalette;
use Ichiloto\Editor\UI\PaletteItem;

it('wraps at the edges and clamps overshoots from the middle', function (): void {
    // Down on the last row selects the first; Up on the first selects the last.
    expect(ListNavigation::step(4, 1, 5))->toBe(0)
        ->and(ListNavigation::step(0, -1, 5))->toBe(4)
        // An ordinary step moves normally.
        ->and(ListNavigation::step(2, 1, 5))->toBe(3)
        ->and(ListNavigation::step(2, -1, 5))->toBe(1)
        // A page-sized jump from the middle lands on the edge instead of
        // leaping invisibly past it; the next press wraps from there.
        ->and(ListNavigation::step(2, 10, 5))->toBe(4)
        ->and(ListNavigation::step(2, -10, 5))->toBe(0)
        // Degenerate lists stay put.
        ->and(ListNavigation::step(0, 1, 1))->toBe(0)
        ->and(ListNavigation::step(0, -1, 1))->toBe(0)
        ->and(ListNavigation::step(3, 1, 0))->toBe(0)
        // An out-of-range index is normalized before stepping.
        ->and(ListNavigation::step(9, 1, 5))->toBe(0)
        ->and(ListNavigation::step(-3, -1, 5))->toBe(4);
});

it('wraps the Database category list in both directions', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'terms');
        $last = count(DatabaseCatalog::all()) - 1;

        expect(getEditorProperty($editor, 'databaseCategoryIndex'))->toBe($last);

        callEditorMethod($editor, 'moveDatabaseCategorySelection', 1);
        expect(getEditorProperty($editor, 'databaseCategoryIndex'))->toBe(0);

        callEditorMethod($editor, 'moveDatabaseCategorySelection', -1);
        expect(getEditorProperty($editor, 'databaseCategoryIndex'))->toBe($last);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('wraps the Database entry list, honouring the filtered view', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'states');

        // Two entries: Poison, Stun.
        callEditorMethod($editor, 'moveDatabaseSelection', 0, 1);
        expect(callEditorMethod($editor, 'getSelectedDatabaseEntryIndex'))->toBe(1);

        callEditorMethod($editor, 'moveDatabaseSelection', 0, 1);
        expect(callEditorMethod($editor, 'getSelectedDatabaseEntryIndex'))->toBe(0);

        callEditorMethod($editor, 'moveDatabaseSelection', 0, -1);
        expect(callEditorMethod($editor, 'getSelectedDatabaseEntryIndex'))->toBe(1);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('wraps the Database settings rows', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'states');
        setEditorProperty($editor, 'databaseFocus', 'database_settings');

        $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
        $last = count($fields) - 1;

        expect($last)->toBeGreaterThan(0);

        callEditorMethod($editor, 'moveDatabaseSettingsSelection', -1);
        expect(getEditorProperty($editor, 'databaseSelectedSettingIndex'))->toBe($last);

        callEditorMethod($editor, 'moveDatabaseSettingsSelection', 1);
        expect(getEditorProperty($editor, 'databaseSelectedSettingIndex'))->toBe(0);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('wraps the assets list through the visible entries', function (): void {
    $root = makeTemporaryProject();

    try {
        Ichiloto\Editor\ProjectWorkspace::fromProject($root)->createMap();

        $editor = deletionEditor($root);
        $workspace = getEditorProperty($editor, 'workspace');
        expect(count($workspace->mapIds))->toBe(2)
            ->and(getEditorProperty($editor, 'selectedAssetIndex'))->toBe(0);

        callEditorMethod($editor, 'moveSelection', -1);
        expect(getEditorProperty($editor, 'selectedAssetIndex'))->toBe(1);

        callEditorMethod($editor, 'moveSelection', 1);
        expect(getEditorProperty($editor, 'selectedAssetIndex'))->toBe(0);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('wraps the animation frame list', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'animations');
        $animation = callEditorMethod($editor, 'getSelectedAnimation');
        $maxFrames = $animation->maxFrames;

        expect($maxFrames)->toBeGreaterThan(1)
            ->and(getEditorProperty($editor, 'databaseSelectedFrameIndex'))->toBe(1);

        callEditorMethod($editor, 'moveDatabaseFrameSelection', -1);
        expect(getEditorProperty($editor, 'databaseSelectedFrameIndex'))->toBe($maxFrames);

        callEditorMethod($editor, 'moveDatabaseFrameSelection', 1);
        expect(getEditorProperty($editor, 'databaseSelectedFrameIndex'))->toBe(1);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('wraps option values when cycling past either end', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'troops');

        $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
        $policyIndex = array_search('escapePolicy', array_column($fields, 'field'), true);
        setEditorProperty($editor, 'databaseSelectedSettingIndex', $policyIndex);

        $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('troops');

        // Unset reads as the first option; cycling left wraps to the last.
        callEditorMethod($editor, 'adjustDatabaseOptionField', -1);
        expect($database->getRecordByIndex(0)?->get('escapePolicy'))->toBe('forbidden');

        // And cycling right from the last wraps back to the first.
        callEditorMethod($editor, 'adjustDatabaseOptionField', 1);
        expect($database->getRecordByIndex(0)?->get('escapePolicy'))->toBe('allowed');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('wraps the command palette selection', function (): void {
    $palette = new CommandPalette();
    $noop = static function (): void {
    };
    $palette->open([
        new PaletteItem('One', '', $noop),
        new PaletteItem('Two', '', $noop),
        new PaletteItem('Three', '', $noop),
    ]);

    $palette->moveSelection(-1);
    expect($palette->selectedIndex)->toBe(2);

    $palette->moveSelection(1);
    expect($palette->selectedIndex)->toBe(0);
});
