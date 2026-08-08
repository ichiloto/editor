<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\UI\CommandPalette;
use Ichiloto\Editor\UI\ListFilter;
use Ichiloto\Editor\UI\PaletteItem;

/**
 * Builds an unbooted editor with the fixture workspace loaded.
 */
function filterEditor(): Editor
{
    $editor = createEditorForTesting(fixturePath('sample-project'));
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject(fixturePath('sample-project')));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);

    return $editor;
}

it('ranks labels through the one palette matcher', function () {
    $labels = ['Forest Path', 'Fort Ruins', 'Town Square'];

    // Substring beats subsequence; earlier substring beats later.
    expect(CommandPalette::filterLabels($labels, 'for'))->toBe([0, 1])
        ->and(CommandPalette::filterLabels($labels, 'fp'))->toBe([0])
        ->and(CommandPalette::filterLabels($labels, 'zzz'))->toBe([])
        ->and(CommandPalette::filterLabels($labels, ''))->toBe([0, 1, 2]);
});

it('keeps the palette item filter and the label matcher in agreement', function () {
    $items = [
        new PaletteItem('Save Map', '', fn() => null),
        new PaletteItem('Save All', '', fn() => null),
        new PaletteItem('Quit', '', fn() => null),
    ];
    $filtered = CommandPalette::filter($items, 'save');

    expect(array_map(static fn(PaletteItem $item): string => $item->label, $filtered))
        ->toBe(['Save Map', 'Save All']);
});

it('preserves original keys so filtering never reindexes an entry list', function () {
    $labels = [3 => 'Oracle', 7 => 'Vanguard', 9 => 'Strider'];

    expect(CommandPalette::filterLabels($labels, 'r'))->toBe([3, 9, 7]);
});

it('opens, types, commits, and clears a list filter', function () {
    $filter = new ListFilter();

    expect($filter->isActive())->toBeFalse()
        ->and($filter->describe())->toBe('');

    $filter->open();
    $filter->type('v');
    $filter->type('a');

    expect($filter->query)->toBe('va')
        ->and($filter->isCapturing)->toBeTrue()
        ->and($filter->describe())->toBe(' /va_');

    $filter->backspace();
    $filter->commit();

    // A committed filter keeps narrowing, but stops eating keystrokes.
    expect($filter->query)->toBe('v')
        ->and($filter->isCapturing)->toBeFalse()
        ->and($filter->isActive())->toBeTrue()
        ->and($filter->describe())->toBe(' /v');

    $filter->clear();

    expect($filter->isActive())->toBeFalse();
});

it('narrows the Assets list with / and restores it with Esc', function () {
    $editor = filterEditor();
    setEditorProperty($editor, 'focusedPane', 'assets');
    callEditorMethod($editor, 'dispatchInput', '/');

    /** @var ListFilter $filter */
    $filter = getEditorProperty($editor, 'assetFilter');

    expect($filter->isCapturing)->toBeTrue();

    foreach (str_split('test') as $symbol) {
        callEditorMethod($editor, 'dispatchInput', $symbol);
    }

    expect(callEditorMethod($editor, 'getVisibleAssetIndexes'))->toBe([0]);

    foreach (str_split('zzz') as $symbol) {
        callEditorMethod($editor, 'dispatchInput', $symbol);
    }

    expect(callEditorMethod($editor, 'getVisibleAssetIndexes'))->toBe([]);

    callEditorMethod($editor, 'dispatchInput', "\033");

    expect($filter->isActive())->toBeFalse()
        ->and(callEditorMethod($editor, 'getVisibleAssetIndexes'))->toBe([0]);
});

it('lets the Assets filter caret swallow ? instead of opening help', function () {
    $editor = filterEditor();
    setEditorProperty($editor, 'focusedPane', 'assets');
    callEditorMethod($editor, 'dispatchInput', '/');
    callEditorMethod($editor, 'dispatchInput', '?');

    /** @var ListFilter $filter */
    $filter = getEditorProperty($editor, 'assetFilter');

    expect($filter->query)->toBe('?')
        ->and(getEditorProperty($editor, 'isHelpOpen'))->toBeFalse();
});

it('leaves / paintable on the canvas', function () {
    $editor = filterEditor();
    setEditorProperty($editor, 'focusedPane', 'canvas');
    setEditorProperty($editor, 'cursorX', 2);
    setEditorProperty($editor, 'cursorY', 2);
    callEditorMethod($editor, 'dispatchInput', '/');

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');

    expect($workspace->getMapByIndex(0)->getTileSymbol(2, 2))->toBe('/');
});

it('narrows a Database entry list with / and moves selection inside it', function () {
    $editor = filterEditor();
    callEditorMethod($editor, 'dispatchInput', "\x04");
    setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('classes'));
    setEditorProperty($editor, 'databaseFocus', 'database_list');

    expect(callEditorMethod($editor, 'getVisibleDatabaseEntryIndexes'))->toBe([0, 1]);

    callEditorMethod($editor, 'dispatchInput', '/');

    foreach (str_split('ora') as $symbol) {
        callEditorMethod($editor, 'dispatchInput', $symbol);
    }

    // Oracle is index 1 in classes.php; the selection follows the filter.
    expect(callEditorMethod($editor, 'getVisibleDatabaseEntryIndexes'))->toBe([1])
        ->and(getEditorProperty($editor, 'databaseSelectedClassIndex'))->toBe(1);

    // Down inside a one-row filter cannot walk onto a hidden entry.
    callEditorMethod($editor, 'dispatchInput', "\033[B");

    expect(getEditorProperty($editor, 'databaseSelectedClassIndex'))->toBe(1);

    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(callEditorMethod($editor, 'getVisibleDatabaseEntryIndexes'))->toBe([0, 1])
        ->and(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue();
});

it('filters a picker dialog with the same matcher', function () {
    $editor = filterEditor();
    setEditorProperty($editor, 'focusedPane', 'canvas');
    callEditorMethod($editor, 'openEventTypeDialog', 'E');

    expect(getEditorProperty($editor, 'isEventTypeDialogOpen'))->toBeTrue();

    $unfiltered = callEditorMethod($editor, 'getVisibleEventTypeIndexes');

    callEditorMethod($editor, 'dispatchInput', '/');
    callEditorMethod($editor, 'dispatchInput', 'z');
    callEditorMethod($editor, 'dispatchInput', 'z');

    expect(callEditorMethod($editor, 'getVisibleEventTypeIndexes'))->toBe([])
        ->and(getEditorProperty($editor, 'isEventTypeDialogOpen'))->toBeTrue();

    // Esc pops the filter first, then the dialog.
    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(callEditorMethod($editor, 'getVisibleEventTypeIndexes'))->toBe($unfiltered)
        ->and(getEditorProperty($editor, 'isEventTypeDialogOpen'))->toBeTrue();

    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(getEditorProperty($editor, 'isEventTypeDialogOpen'))->toBeFalse();
});
