<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectMap;

/**
 * Terrain kinds on the terminal canvas: K opens the kind picker for the
 * layer being edited, and the chosen kind paints like an item piece. The
 * kinds and map are the synthetic ones of the session tests
 * ({@see terrainKindProject()}), with the map already painted in kinds.
 */

/** @return array{Editor, ProjectMap, string} */
function createKindCanvasEditor(bool $converted = true): array
{
    $root = terrainKindProject();
    if ($converted) {
        $data = $root . '/assets/Maps/test-map/test-map.data.php';
        file_put_contents($data, str_replace("'tileset' => 'home',", "'tileset' => 'home', 'terrainKinds' => true,", (string) file_get_contents($data)));
    }

    return layeredCanvasEditor($root);
}

/** Chooses a kind with the keys: K, down to its entry and Enter. */
function chooseKindByKeys(Editor $editor, string $id): void
{
    callEditorMethod($editor, 'dispatchInput', 'K');
    $current = getEditorProperty($editor, 'selectedEventOptionIndex');
    $target = array_search($id, array_column(getEditorProperty($editor, 'eventOptionDialogEntries'), 'value'), true);
    for ($i = $current; $i < $target; $i++) {
        callEditorMethod($editor, 'dispatchInput', "\033[B");
    }
    callEditorMethod($editor, 'dispatchInput', "\n");
}

it('lists the kinds of the layer being edited with K, opening on the kind under the cursor', function () {
    [$editor] = createKindCanvasEditor();
    setEditorProperty($editor, 'cursorX', 1);

    callEditorMethod($editor, 'dispatchInput', 'K');

    // The fixture's terrain cells are bare glyphs over meadow tiles: the cursor's cell shows meadow.
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeTrue()
        ->and(getEditorProperty($editor, 'eventOptionDialogTitle'))->toBe('Terrain kind')
        ->and(getEditorProperty($editor, 'eventOptionDialogEntries'))->toBe([
            ['label' => '.  Meadow', 'value' => 'meadow', 'description' => 'walk · meadow encounters'],
            ['label' => '=  Path', 'value' => 'path', 'description' => 'walk · no encounters'],
            ['label' => '♣  Grove', 'value' => 'grove', 'description' => 'walk · grove encounters · on Meadow'],
            ['label' => '^  Peaks', 'value' => 'peaks', 'description' => 'solid · on Meadow · awaiting art'],
        ])
        ->and(getEditorProperty($editor, 'selectedEventOptionIndex'))->toBe(0);
});

it('shows K:Kind in Normal mode on a map painted in kinds only', function (bool $converted, bool $shown) {
    [$editor] = createKindCanvasEditor($converted);

    expect(str_contains(renderEditorPlainFrame($editor, 200, 45), 'L:Layer P:Piece K:Kind'))->toBe($shown);
})->with(['painted in kinds' => [true, true], 'not yet' => [false, false]]);

it('says how to convert a map that is not painted in kinds, and opens nothing', function () {
    [$editor] = createKindCanvasEditor(converted: false);

    callEditorMethod($editor, 'dispatchInput', 'K');

    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('map:convert-kinds test-map');
});

it('paints a kind\'s glyph and tiles at the cursor and fills to the anchor, each one undo step', function () {
    [$editor, $map] = createKindCanvasEditor();
    $before = $map->captureLayerSnapshot();
    $tilesBefore = $map->getTileLayerSources();

    chooseKindByKeys($editor, 'grove');
    expect(callEditorMethod($editor, 'getPiecePlacementHelp', 60))->toBe('KIND Grove  Enter:Paint  Drag:Fill  Esc:Done');
    callEditorMethod($editor, 'dispatchInput', "\n");
    setEditorProperty($editor, 'cursorX', 2);
    callEditorMethod($editor, 'dispatchInput', "\n");

    expect($map->getLayerSymbol('map:1', 0, 0))->toBe('♣')
        ->and($map->getLayerSymbol('map:1', 2, 0))->toBe('♣')
        ->and($map->getLayerColor('map:1', 1, 0))->toBe('green')
        ->and($map->readTileEntries(['canopy'], 0, 0, 4, 1)['canopy'][0])->toBe(['2912', '2912', '2912', '0'])
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('Painted 3 cells of Grove');

    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureLayerSnapshot())->toBe($before)
        ->and($map->getTileLayerSources())->toBe($tilesBefore);
});
