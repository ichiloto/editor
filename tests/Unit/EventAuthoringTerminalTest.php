<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Events\EventAuthoring;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\ProjectMap;

/**
 * The terminal editor's own event flows, now applied through the shared
 * event authoring and inspector services: its dialogs and messages stay its
 * own, and its edits are the ones every interface makes.
 */

/** @return array{0: Editor, 1: ProjectMap} An editor in event mode over a fresh copy of the synthetic fixture. */
function terminalEventEditor(): array
{
    $editor = deletionEditor(makeTemporaryProject());
    callEditorMethod($editor, 'setEditingMode', 'event');

    return [$editor, getEditorProperty($editor, 'workspace')->maps[0]];
}

/** Puts the cursor on an event's first cell and returns the event's inspector rows by label. */
function terminalEventRows(Editor $editor, ProjectMap $map, string $marker): array
{
    [$x, $y] = $map->getEventArea($marker)->cells[0];
    setEditorProperty($editor, 'cursorX', $x);
    setEditorProperty($editor, 'cursorY', $y);
    $fields = callEditorMethod($editor, 'getInspectorFields');
    $fields = array_slice($fields, (int) array_search('Event', array_column($fields, 'label'), true));
    $rows = [];
    $section = '';
    foreach ($fields as $field) {
        if (! str_starts_with((string) $field['label'], '  ')) {
            $section = (string) $field['label'];
        }
        $rows[$section . '/' . trim((string) $field['label'])] = $field;
    }

    return $rows;
}

it('refuses an inspector move or resize onto another event instead of overwriting its cells', function () {
    [$editor, $map] = terminalEventEditor();
    [$marker] = array_map(strval(...), array_keys($map->getEventDefinitions()));
    $bounds = $map->getEventBounds($marker);
    $neighbour = EventAuthoring::createEvent($map, [[$bounds['x'] + 1, $bounds['y']]], EventTypeCatalog::at(0))['marker'];
    $rows = terminalEventRows($editor, $map, $marker);

    callEditorMethod($editor, 'applyInspectorFieldValue', $rows['Size/X'], '2');
    expect(getEditorProperty($editor, 'statusMessage'))->toContain("would cover marker {$neighbour}")
        ->and($map->getEventArea($neighbour)?->cells)->toBe([[$bounds['x'] + 1, $bounds['y']]]);

    callEditorMethod($editor, 'applyInspectorFieldValue', $rows['Position/X'], (string) ($bounds['x'] + 1));
    expect(getEditorProperty($editor, 'statusMessage'))->toContain("would cover marker {$neighbour}")
        ->and($map->getEventBounds($marker))->toBe($bounds);
});

it('refuses an inspector position off the map rather than clamping it onto the edge', function () {
    [$editor, $map] = terminalEventEditor();
    [$marker] = array_map(strval(...), array_keys($map->getEventDefinitions()));
    $bounds = $map->getEventBounds($marker);

    callEditorMethod($editor, 'applyInspectorFieldValue', terminalEventRows($editor, $map, $marker)['Position/X'], '-1');

    expect(getEditorProperty($editor, 'statusMessage'))->toContain('would leave the map')
        ->and($map->getEventBounds($marker))->toBe($bounds);
});

it('changes an event\'s type from the type dialog as one undo step', function () {
    [$editor, $map] = terminalEventEditor();
    [$marker] = array_map(strval(...), array_keys($map->getEventDefinitions()));
    $original = $map->getEventDefinition($marker);

    callEditorMethod($editor, 'openEventTypeDialog', $marker);
    setEditorProperty($editor, 'selectedEventTypeIndex', array_search('Dialogue', array_map(static fn($type): string => $type->label, EventTypeCatalog::all()), true));
    callEditorMethod($editor, 'applySelectedEventType');

    expect($map->getEventDefinition($marker))->toBe(EventTypeCatalog::findByLabel('Dialogue')->buildDefinition(null))
        ->and(getEditorProperty($editor, 'statusMessage'))->toBe("{$marker} is now a Dialogue event.");

    callEditorMethod($editor, 'performUndo');
    expect($map->getEventDefinition($marker))->toBe($original);
});

it('sets a transfer\'s destination and spawn point from the picker as one undo step', function () {
    [$editor, $map] = terminalEventEditor();
    $marker = EventAuthoring::createEvent($map, [[1, $map->getHeight() - 1]], EventTypeCatalog::findByLabel('Transfer Player'))['marker'];
    $before = $map->getEventDefinition($marker);
    terminalEventRows($editor, $map, $marker);

    callEditorMethod($editor, 'openDestinationDialog', $marker, ['data', 'destinationMap'], '');
    callEditorMethod($editor, 'applySelectedDestination');
    setEditorProperty($editor, 'cursorX', 2);
    setEditorProperty($editor, 'cursorY', 1);
    callEditorMethod($editor, 'commitDestinationSpawnSelection');

    expect($map->getEventField($marker, ['data', 'destinationMap']))->toBe($map->mapId)
        ->and($map->getEventField($marker, ['data', 'spawnPoint']))->toBe(['x' => 2, 'y' => 1])
        ->and(getEditorProperty($editor, 'statusMessage'))->toBe("{$marker} destination set to {$map->mapId} (2, 1).");

    callEditorMethod($editor, 'performUndo');
    expect($map->getEventDefinition($marker))->toBe($before);
});
