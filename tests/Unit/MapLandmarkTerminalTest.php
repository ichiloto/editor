<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;

/**
 * Minimap landmarks authored from the Terminal's own keyboard workflow: the same inspector rows the graphical editor
 * reads, chosen from lists and built with the shared conditions editor, with no graphical tool.
 */

function landmarkTerminalEditor(): Editor
{
    [$root] = createLandmarkProject();
    return metadataEditor($root);
}

/** Puts the inspector cursor on the landmark row editing this field. */
function selectLandmarkField(Editor $editor, string $field): void
{
    foreach (callEditorMethod($editor, 'getInspectorFields') as $index => $row) {
        if (($row['target'] ?? null) === 'map-landmarks' && ($row['field'] ?? null) === $field) {
            setEditorProperty($editor, 'selectedInspectorFieldIndex', $index);
            return;
        }
    }
    throw new RuntimeException('No landmark row edits ' . $field);
}

/** Opens the row's list and chooses the entry with this value. */
function chooseLandmarkOption(Editor $editor, string $field, string $value): void
{
    selectLandmarkField($editor, $field);
    callEditorMethod($editor, 'activateInspectorField');
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeTrue();
    $entries = getEditorProperty($editor, 'eventOptionDialogEntries');
    $index = array_search($value, array_column($entries, 'value'), true);
    expect($index)->toBeInt();
    setEditorProperty($editor, 'selectedEventOptionIndex', $index);
    callEditorMethod($editor, 'applySelectedEventOption');
}

it('steps an event through what it is on the minimap and picks a landmark anchor from a list', function () {
    $editor = landmarkTerminalEditor();
    expect(inspectorValueOf($editor, 'Unnamed Route Markers'))->toBe('1');

    setEditorProperty($editor, 'editingMode', 'event');
    setEditorProperty($editor, 'cursorX', 0);
    setEditorProperty($editor, 'cursorY', 0);
    expect(array_column(selectInspectorField($editor, 'Minimap')['choices'], 'label'))->toBe(['Unnamed route marker', 'Passage', 'Entrance', 'Exit']);
    // Enter moves a short enum on, as Left and Right do.
    callEditorMethod($editor, 'activateInspectorField');
    expect(mapDataOf($editor)['landmarks'])->toBe([['id' => 'landmark-1', 'kind' => 'passage', 'anchor' => ['event' => 'T']]]);
    callEditorMethod($editor, 'adjustInspectorOptionField', 1);
    callEditorMethod($editor, 'adjustInspectorOptionField', 1);
    expect(mapDataOf($editor)['landmarks'][0]['kind'])->toBe('exit')
        ->and(inspectorValueOf($editor, 'Landmark'))->toBe('landmark-1 · unnamed');

    setEditorProperty($editor, 'editingMode', 'map');
    selectLandmarkField($editor, 'anchor');
    callEditorMethod($editor, 'activateInspectorField');
    expect(array_column(getEditorProperty($editor, 'eventOptionDialogEntries'), 'label'))
        ->toBe(['Event T · Transfer Player to test-map', 'Event E · Dialogue', 'World object old-tower']);
    callEditorMethod($editor, 'closeEventOptionDialog');
    chooseLandmarkOption($editor, 'anchor', 'object:old-tower');
    expect(mapDataOf($editor)['landmarks'][0])->toBe(['id' => 'landmark-1', 'kind' => 'landmark', 'anchor' => ['object' => 'old-tower']]);
    callEditorMethod($editor, 'performUndo');
    expect(mapDataOf($editor)['landmarks'][0]['anchor'])->toBe(['event' => 'T']);
});

it('builds reveal conditions with the shared editor and reports a refusal without claiming success', function () {
    $editor = landmarkTerminalEditor();
    setEditorProperty($editor, 'editingMode', 'event');
    setEditorProperty($editor, 'cursorX', 3);
    setEditorProperty($editor, 'cursorY', 0);
    selectInspectorField($editor, 'Minimap');
    callEditorMethod($editor, 'activateInspectorField');
    expect(mapDataOf($editor)['landmarks'][0]['kind'])->toBe('building');
    setEditorProperty($editor, 'editingMode', 'map');

    selectLandmarkField($editor, 'revealConditions');
    callEditorMethod($editor, 'activateInspectorField');
    $conditionEditor = getEditorProperty($editor, 'conditionEditor');
    expect($conditionEditor->isOpen())->toBeTrue()
        ->and($conditionEditor->label())->toBe('Landmark landmark-1 Reveal Conditions');
    callEditorMethod($editor, 'dispatchInput', 'a');
    callEditorMethod($editor, 'dispatchInput', 't');
    $conditionEditor->setName('heard_of_tower');
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(mapDataOf($editor)['landmarks'][0]['revealConditions'])->toBe([['type' => 'event', 'name' => 'heard_of_tower']])
        ->and(getEditorProperty($editor, 'mapConditionField'))->toBeNull();

    $toasts = getEditorProperty($editor, 'toasts');
    $toasts->clear();
    selectLandmarkField($editor, 'approachDistance');
    setEditorProperty($editor, 'isInspectorEditing', true);
    setEditorProperty($editor, 'inspectorEditBuffer', '99');
    callEditorMethod($editor, 'commitInspectorEdit');
    expect($toasts->current()?->message)->toBe('An approach distance is 0 to 64 cells.')
        ->and($toasts->pendingCount())->toBe(0)
        ->and(mapDataOf($editor)['landmarks'][0])->not->toHaveKey('approachDistance');
});

it('places a map in the atlas from the Terminal: scope stepped, parent entry picked from a list', function () {
    [$root] = createAtlasProject();
    $editor = metadataEditor($root);
    $select = static function (string $field) use ($editor): void {
        foreach (callEditorMethod($editor, 'getInspectorFields') as $index => $row) {
            if (($row['target'] ?? null) === 'map-atlas' && ($row['field'] ?? null) === $field) {
                setEditorProperty($editor, 'selectedInspectorFieldIndex', $index);
                return;
            }
        }
        throw new RuntimeException('No atlas row edits ' . $field);
    };
    $select('parent');
    callEditorMethod($editor, 'activateInspectorField');
    $entries = getEditorProperty($editor, 'eventOptionDialogEntries');
    expect(array_column($entries, 'label'))->toBe(['(none)', 'World · town-gate']);
    setEditorProperty($editor, 'selectedEventOptionIndex', 1);
    callEditorMethod($editor, 'applySelectedEventOption');
    expect(mapDataOf($editor)['atlas'])->toBe(['parent' => ['map' => 'world', 'landmark' => 'town-gate']])
        ->and(inspectorValueOf($editor, 'Global Map'))->toBe('world at town-gate');

    $select('scope');
    callEditorMethod($editor, 'activateInspectorField');
    expect(mapDataOf($editor)['atlas'])->toBe(['scope' => 'global']);
});
