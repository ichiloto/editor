<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Field\MapAreaAuthoring;
use Ichiloto\Editor\Inspector\MapAreaFields;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Named areas in the terminal editor, by keyboard on the canvas cursor: the
 * same areas the session authors, chosen cell by cell or by a rectangle's
 * corners, with the inspector's rows shared with a graphical editor. The
 * fixture is {@see createLandmarkProject()}'s 4 x 2 map.
 */

/** An unbooted editor over the project, on the canvas, in Area mode. */
function areaEditor(string $root): Editor
{
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    setEditorProperty($editor, 'focusedPane', 'canvas');
    callEditorMethod($editor, 'dispatchInput', 'a');

    return $editor;
}

/** Moves the canvas cursor to a cell and sends keys there. */
function pressAreaKeys(Editor $editor, int $x, int $y, string ...$keys): void
{
    setEditorProperty($editor, 'cursorX', $x);
    setEditorProperty($editor, 'cursorY', $y);
    foreach ($keys as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
}

/** The editor's map's areas as written. */
function readEditorAreas(Editor $editor): array
{
    return MapAreaAuthoring::readEntries(callEditorMethod($editor, 'getSelectedMap'));
}

it('makes an area of cells selected by keyboard, writing a filled rectangle as one', function () {
    [$root] = createLandmarkProject();
    $editor = areaEditor($root);

    expect(getEditorProperty($editor, 'editingMode'))->toBe('area');
    pressAreaKeys($editor, 0, 1, ' ');
    pressAreaKeys($editor, 1, 1, ' ');
    expect(callEditorMethod($editor, 'getCanvasPreviewCells'))->toBe([1 => [0 => null, 1 => null]]);
    pressAreaKeys($editor, 1, 1, "\r", 'y', 'a', 'r', 'd', "\r");

    expect(readEditorAreas($editor))->toBe([['id' => 'yard', 'area' => ['x' => 0, 'y' => 1, 'width' => 2, 'height' => 1]]])
        ->and(getEditorProperty($editor, 'areaSelection'))->toBe([])
        ->and(getEditorProperty($editor, 'statusMessage'))->toBe('Made area yard of 2 cells.');

    // A rectangle by its corners, and a cell taken back, written as exact cells.
    pressAreaKeys($editor, 1, 0, 'r');
    pressAreaKeys($editor, 2, 1, 'r');
    pressAreaKeys($editor, 2, 1, ' ', "\r", 'p', 'o', 'r', 'c', 'h', "\r");
    expect(readEditorAreas($editor)[1])->toBe(['id' => 'porch', 'area' => ['cells' => [[1, 0], [2, 0], [1, 1]]]]);
});

it('picks an area up with Enter to change its cells, steps through areas sharing a cell, and deletes one', function () {
    [$root] = createLandmarkProject();
    $editor = areaEditor($root);
    pressAreaKeys($editor, 0, 1, ' ', "\r", 'y', 'a', 'r', 'd', "\r");
    pressAreaKeys($editor, 0, 1, ' ', "\r", 'g', 'a', 't', 'e', "\r");

    pressAreaKeys($editor, 0, 1, "\r");
    expect(getEditorProperty($editor, 'editedAreaId'))->toBe('yard');
    pressAreaKeys($editor, 0, 1, 'n');
    expect(getEditorProperty($editor, 'editedAreaId'))->toBe('gate');

    pressAreaKeys($editor, 1, 1, ' ', "\r");
    expect(readEditorAreas($editor)[1])->toBe(['id' => 'gate', 'area' => ['x' => 0, 'y' => 1, 'width' => 2, 'height' => 1]]);

    pressAreaKeys($editor, 1, 1, "\r", "\x7f");
    expect(array_column(readEditorAreas($editor), 'id'))->toBe(['yard']);

    callEditorMethod($editor, 'performUndo');
    expect(array_column(readEditorAreas($editor), 'id'))->toBe(['yard', 'gate']);
});

it('shows the areas in the map inspector and offers them as an event\'s subject', function () {
    [$root] = createLandmarkProject();
    $editor = areaEditor($root);
    pressAreaKeys($editor, 0, 1, ' ', "\r", 'y', 'a', 'r', 'd', "\r");
    $map = callEditorMethod($editor, 'getSelectedMap');
    $inspector = callEditorMethod($editor, 'createMapInspector', $map);

    $rows = array_values(array_filter($inspector->getMapFields($map), static fn(array $row): bool => ($row['target'] ?? null) === MapAreaFields::TARGET));
    $subject = array_find($inspector->getEventFields($map, 'E'), static fn(array $row): bool => ($row['target'] ?? null) === MapAreaFields::SUBJECT_TARGET);

    expect(array_column($rows, 'label'))->toBe(['Areas · 1', '  Area', '    Cells', '    Named By'])
        ->and(array_column($rows, 'value'))->toBe(['', 'yard', 'x 0, y 1 · 1 x 1', 'nothing'])
        ->and($subject['options'])->toBe(['', 'world_object:old-tower', 'area:yard']);
});
