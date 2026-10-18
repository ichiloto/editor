<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;

/**
 * The terminal editor edits an event's record rows through the same map
 * inspector as every interface: a Story Script's inline commands in frames
 * entered with Enter and left with Esc, and completion writes in the shared
 * write editor hosted in the Inspector.
 */

/** An editor in event mode on an event record project, its cursor on a marker's cell. */
function eventRecordTerminal(int $x): Editor
{
    $editor = deletionEditor(eventRecordProject());
    callEditorMethod($editor, 'setEditingMode', 'event');
    setEditorProperty($editor, 'focusedPane', 'inspector');
    setEditorProperty($editor, 'cursorX', $x);
    setEditorProperty($editor, 'cursorY', 1);

    return $editor;
}

/** Puts the Inspector cursor on the row with a field id and returns it. */
function selectEventRecordField(Editor $editor, string $field): array
{
    foreach (callEditorMethod($editor, 'getInspectorFields') as $index => $row) {
        if (($row['field'] ?? null) === $field) {
            setEditorProperty($editor, 'selectedInspectorFieldIndex', $index);

            return $row;
        }
    }

    throw new RuntimeException("No inspector row {$field}.");
}

it('opens a Story Script\'s inline commands as a frame, edits them there, and leaves with Esc', function () {
    $editor = eventRecordTerminal(10);
    $map = getEditorProperty($editor, 'workspace')->maps[0];

    selectEventRecordField($editor, 'commandListScript');
    callEditorMethod($editor, 'dispatchInput', "\r");
    $rows = callEditorMethod($editor, 'getInspectorFields');

    expect($rows[0]['label'])->toBe('Script')
        ->and(array_column($rows, 'field'))->toContain('command0Text', 'command1Seconds')
        ->and(array_column($rows, 'field'))->not->toContain('conditions');

    callEditorMethod($editor, 'applyInspectorFieldValue', selectEventRecordField($editor, 'command0Text'), 'Wait for me.');
    selectEventRecordField($editor, 'command0Text');
    callEditorMethod($editor, 'dispatchInput', 'O');
    expect($map->getEventDefinition('S')['data']['script'][0]['text'])->toBe('Wait for me.')
        ->and($map->getEventDefinition('S')['data']['script'])->toHaveCount(3);

    callEditorMethod($editor, 'dispatchInput', "\033");
    $field = callEditorMethod($editor, 'getInspectorFields')[getEditorProperty($editor, 'selectedInspectorFieldIndex')];
    expect($field['field'])->toBe('commandListScript')
        ->and($field['value'])->toBe('3');
});

it('builds an event\'s completion writes in the shared write editor hosted in the Inspector', function () {
    $editor = eventRecordTerminal(1);
    $map = getEditorProperty($editor, 'workspace')->maps[0];

    selectEventRecordField($editor, 'sets');
    callEditorMethod($editor, 'dispatchInput', "\r");
    $writes = getEditorProperty($editor, 'worldWriteEditor');
    expect($writes->isOpen())->toBeTrue();

    callEditorMethod($editor, 'dispatchInput', 'a');
    callEditorMethod($editor, 'dispatchInput', 't');
    $writes->setName('door_seen');
    callEditorMethod($editor, 'dispatchInput', "\r");

    expect($map->getEventDefinition('T')['sets'])->toBe([['type' => 'event', 'name' => 'door_seen']])
        ->and(getEditorProperty($editor, 'mapWriteField'))->toBeNull();

    // Esc leaves the writes alone and releases the hosting.
    selectEventRecordField($editor, 'sets');
    callEditorMethod($editor, 'dispatchInput', "\r");
    callEditorMethod($editor, 'dispatchInput', 'a');
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect($map->getEventDefinition('T')['sets'])->toBe([['type' => 'event', 'name' => 'door_seen']])
        ->and(getEditorProperty($editor, 'mapWriteField'))->toBeNull();
});
