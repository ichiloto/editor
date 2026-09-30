<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Status\StatusLevel;

/** An editor on the fixture map with the Inspector focused. */
function kindEditor(string $root): array
{
    [$editor, $map] = layeredCanvasEditor($root);
    setEditorProperty($editor, 'focusedPane', 'inspector');

    return [$editor, $map];
}

function kindToast(Editor $editor): ?Ichiloto\Editor\Status\Toast
{
    return getEditorProperty($editor, 'toasts')->current();
}

it('offers the project\'s kinds by name and sets one on a map without a kind, keeping its tiles, as one undo step', function () {
    $root = mapGraphicsProject();
    editTestMapData($root, static fn(string $source): string => str_replace(" 'tileset' => 'home',", '', $source));
    writeTestTileset($root, 'interior');
    writeTestTileset($root, 'exterior');
    [$editor, $map] = kindEditor($root);

    expect(inspectorValueOf($editor, 'Kind'))->toBe('Not set');

    selectInspectorField($editor, 'Kind');
    callEditorMethod($editor, 'activateInspectorField');
    expect(getEditorProperty($editor, 'eventOptionDialogTitle'))->toBe('Kind')
        ->and(array_column(getEditorProperty($editor, 'eventOptionDialogEntries'), 'label', 'value'))
        ->toBe(['exterior' => 'Exterior', 'home' => 'Home', 'interior' => 'Interior']);
    callEditorMethod($editor, 'closeEventOptionDialog', 'cancelled');

    // Tiles drawn before the map had a kind are kept; they now draw from it.
    pickInspectorReference($editor, 'Kind', 'interior');
    expect($map->getMapDataField(['tileset']))->toBe('interior')
        ->and($map->getTileLayerSources())->toHaveCount(2)
        ->and(inspectorValueOf($editor, 'Kind'))->toBe('Interior')
        ->and($map->isDirty())->toBeTrue()
        ->and(kindToast($editor)?->level)->toBe(StatusLevel::INFO)
        ->and(kindToast($editor)?->message)->toBe('Test Map\'s kind is now Interior; save to keep it.');

    callEditorMethod($editor, 'performUndo');
    expect($map->hasMapDataField(['tileset']))->toBeFalse()
        ->and($map->isDirty())->toBeFalse();
});

it('asks before changing a map with tiles to another kind, and clears its tiles and their settings as one undo step', function () {
    $root = mapGraphicsProject();
    editTestMapData($root, static fn(string $source): string => str_replace("'tileset' => 'home',",
        "'tileset' => 'home', 'tileLayers' => ['floor' => ['movesWith' => 'buildings']],", $source));
    writeTestTileset($root, 'cave');
    [$editor, $map] = kindEditor($root);
    $graphics = $root . '/assets/Maps/test-map/graphics';
    $sources = $map->getTileLayerSources();

    expect(inspectorValueOf($editor, 'Kind'))->toBe('Home');

    // Cancel is first and leaves everything as it was.
    pickInspectorReference($editor, 'Kind', 'cave');
    expect(getEditorProperty($editor, 'eventOptionDialogTitle'))->toBe('Change Test Map\'s kind to Cave')
        ->and(array_column(getEditorProperty($editor, 'eventOptionDialogEntries'), 'label'))->toBe(['Cancel', 'Clear 2 tile layers and change'])
        ->and(getEditorProperty($editor, 'selectedEventOptionIndex'))->toBe(0);
    callEditorMethod($editor, 'applySelectedEventOption');
    expect($map->getMapDataField(['tileset']))->toBe('home')
        ->and($map->getTileLayerSources())->toBe($sources)
        ->and($map->isDirty())->toBeFalse();

    pickInspectorReference($editor, 'Kind', 'cave');
    setEditorProperty($editor, 'selectedEventOptionIndex', 1);
    callEditorMethod($editor, 'applySelectedEventOption');
    expect($map->getMapDataField(['tileset']))->toBe('cave')
        ->and($map->getTileLayerSources())->toBe([])
        ->and($map->hasMapDataField(['tileLayers']))->toBeFalse()
        ->and(kindToast($editor)?->message)->toBe('Test Map\'s kind is now Cave, and its 2 tile layers are cleared; save to keep it.');

    callEditorMethod($editor, 'performUndo');
    expect($map->getMapDataField(['tileset']))->toBe('home')
        ->and($map->getTileLayerSources())->toBe($sources)
        ->and($map->getMapDataField(['tileLayers']))->toBe(['floor' => ['movesWith' => 'buildings']])
        ->and($map->isDirty())->toBeFalse();

    // Saving the change removes the old kind's tile layers; glyphs stay.
    callEditorMethod($editor, 'performRedo');
    $map->save();
    $saved = require $root . '/assets/Maps/test-map/test-map.data.php';
    expect($saved['tileset'])->toBe('cave')
        ->and($saved)->not->toHaveKey('tileLayers')
        ->and(glob($graphics . '/*.tiles.php'))->toBe([])
        ->and(is_file($root . '/assets/Maps/test-map/layers/04.buildings.map.php'))->toBeTrue();
});

it('changes a map without tiles to another kind without asking', function () {
    $root = layeredMapProject();
    editTestMapData($root, static fn(string $source): string => str_replace("'events' => [],", "'events' => [], 'tileset' => 'home',", $source));
    writeTestTileset($root);
    writeTestTileset($root, 'cave');
    [$editor, $map] = kindEditor($root);

    pickInspectorReference($editor, 'Kind', 'cave');
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and($map->getMapDataField(['tileset']))->toBe('cave');
});

it('changes nothing when the chosen kind is the map\'s own', function () {
    [$editor, $map] = kindEditor(mapGraphicsProject());

    pickInspectorReference($editor, 'Kind', 'home');
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and($map->isDirty())->toBeFalse()
        ->and(kindToast($editor)?->message)->toBe('Test Map\'s kind is already Home.');
});

it('keeps a kind the project no longer has visible and diagnosable', function () {
    $root = mapGraphicsProject();
    unlink($root . '/assets/Data/Tilesets/home.php');
    writeTestTileset($root, 'cave');
    [$editor, $map] = kindEditor($root);

    expect(inspectorValueOf($editor, 'Kind'))->toBe('home · not in assets/Data/Tilesets')
        ->and($map->isDirty())->toBeFalse();
});
