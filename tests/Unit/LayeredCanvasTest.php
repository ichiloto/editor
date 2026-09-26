<?php

declare(strict_types=1);

use Ichiloto\Editor\Canvas\CanvasTool;
use Ichiloto\Editor\Canvas\FacadeCatalogue;
use Ichiloto\Editor\Field\NpcCollection;
use Ichiloto\Editor\UI\Modal;

it('cycles only gameplay and event layers without stealing printable Paint-mode glyphs', function () {
    [$editor, $map] = layeredCanvasEditor();
    foreach (['map:4', 'event', 'map:1'] as $id) {
        callEditorMethod($editor, 'dispatchInput', ']');
        expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe($id);
    }
    foreach (['event', 'map:4', 'map:1'] as $id) {
        callEditorMethod($editor, 'dispatchInput', '[');
        expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe($id);
    }
    callEditorMethod($editor, 'selectCanvasLayer', 'map:4');
    callEditorMethod($editor, 'dispatchInput', 'i');
    // Each key paints: one fills the cell, a quick second makes a pair and
    // a third starts the next cell over.
    foreach (['[' => '[[', ']' => '[]', 'v' => 'vv', 'd' => 'vd', 't' => 'tt', 'o' => 'to'] as $glyph => $cell) {
        callEditorMethod($editor, 'dispatchInput', $glyph);
        expect($map->getLayerSymbol('map:4', 0, 0))->toBe($cell)
            ->and(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:4');
    }
});

it('uses colour selection clipboard shapes fill eyedropper and undo on gameplay and event layers', function (string $id) {
    [$editor, $map] = layeredCanvasEditor();
    callEditorMethod($editor, 'selectCanvasLayer', $id);
    callEditorMethod($editor, 'applyCanvasWrites', $map, [
        ['x' => 0, 'y' => 0, 'symbol' => 'Q', 'color' => 'red'],
        ['x' => 1, 'y' => 0, 'symbol' => 'R', 'color' => 'blue'],
    ], 'Layer test');
    $before = $map->captureGridSnapshot();
    setEditorProperty($editor, 'canvasSelection', ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 1]);
    callEditorMethod($editor, 'openColorPicker');
    setEditorProperty($editor, 'colorPaletteIndex', 5); // yellow
    callEditorMethod($editor, 'dispatchInput', "\n");
    expect($map->getLayerSymbol($id, 0, 0))->toBe('QQ')
        ->and($map->getLayerColor($id, 0, 0))->toBe('yellow')
        ->and($map->getLayerColor($id, 1, 0))->toBe('yellow');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    callEditorMethod($editor, 'captureCanvasSelection');
    setEditorProperty($editor, 'cursorY', 1);
    callEditorMethod($editor, 'pasteCanvasClipboard');
    expect($map->getLayerSymbol($id, 0, 1))->toBe('QQ')
        ->and($map->getLayerColor($id, 0, 1))->toBe('red');
    callEditorMethod($editor, 'pickSymbolUnderCursor');
    expect(getEditorProperty($editor, 'selectedPaintSymbol'))->toBe('QQ')
        ->and(getEditorProperty($editor, 'selectedPaintColor'))->toBe('red');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    callEditorMethod($editor, 'selectCanvasTool', CanvasTool::LINE);
    setEditorProperty($editor, 'cursorX', 0);
    callEditorMethod($editor, 'applyCanvasToolAtCursor');
    setEditorProperty($editor, 'cursorX', 3);
    callEditorMethod($editor, 'applyCanvasToolAtCursor');
    expect($map->getLayerSymbol($id, 3, 1))->toBe('QQ');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    setEditorProperty($editor, 'selectedPaintSymbol', 'FF');
    callEditorMethod($editor, 'floodFillFromCursor');
    expect($map->getLayerSymbol($id, 3, 1))->toBe('FF');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
})->with(['map:1', 'map:4', 'event']);

it('keeps mouse strokes layer-bound through undo and a later layer switch', function (string $id) {
    [$editor, $map] = layeredCanvasEditor();
    callEditorMethod($editor, 'selectCanvasLayer', $id);
    callEditorMethod($editor, 'enterPaintMode');
    setEditorProperty($editor, 'selectedPaintSymbol', 'MM');
    setEditorProperty($editor, 'selectedPaintColor', 'cyan');
    $before = $map->captureGridSnapshot();
    $bounds = callEditorMethod($editor, 'getCanvasPreviewBounds');
    // Cell 2 spans the canvas's fifth and sixth columns.
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<0;%d;%dM", $bounds['left'] + 4, $bounds['top']));
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<32;%d;%dM", $bounds['left'] + 5, $bounds['top'] + 1));
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<0;%d;%dm", $bounds['left'] + 5, $bounds['top'] + 1));
    expect($map->getLayerSymbol($id, 2, 1))->toBe('MM')
        ->and($map->getLayerColor($id, 2, 1))->toBe('cyan');
    callEditorMethod($editor, 'selectCanvasLayer', $id === 'map:1' ? 'map:4' : 'map:1');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    callEditorMethod($editor, 'dispatchInput', "\x19");
    expect($map->getLayerSymbol($id, 2, 1))->toBe('MM');
})->with(['map:1', 'map:4', 'event']);

it('renders readable gameplay immediately and keeps visibility and dimming session-only', function () {
    [$editor, $map] = layeredCanvasEditor();
    $before = $map->captureLayerSnapshot();
    $frame = renderEditorPlainFrame($editor, 160, 45);
    expect($frame)->toContain('..//....', '..xxxx..')
        ->not->toContain('dd//....', '..xxxxdd', 'Terminal preview', 't:Terminal');
    callEditorMethod($editor, 'selectCanvasLayer', 'map:4');
    callEditorMethod($editor, 'dispatchInput', 'v');
    callEditorMethod($editor, 'dispatchInput', 'd');
    expect(renderEditorPlainFrame($editor, 160, 45))->toContain('........')
        ->and($map->captureLayerSnapshot())->toBe($before)
        ->and($map->isDirty())->toBeFalse();
    callEditorMethod($editor, 'dispatchInput', 'v');
    expect(renderEditorPlainFrame($editor, 160, 45))->toContain('..//....', '..xxxx..')
        ->and($map->captureLayerSnapshot())->toBe($before);
});

it('edits terminal glyphs immediately through undo redo save and reload without changing graphical bytes', function () {
    $root = layeredMapProject();
    [$editor, $map] = layeredCanvasEditor($root);
    $before = sourceHashTree($map->directory);
    $graphicalSource = file_get_contents($map->dataPath);
    $decorationSource = file_get_contents($map->directory . '/layers/07.detail.deco.php');
    foreach (['i', 'Z', "\033", "\x13"] as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    expect($map->getLayerSymbol('map:1', 0, 0))->toBe('ZZ')
        ->and(array_keys(array_diff_assoc(sourceHashTree($map->directory), $before)))->toBe(['layers/01.terrain.map.php']);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(sourceHashTree($map->directory))->toBe($before);
    callEditorMethod($editor, 'dispatchInput', "\x19");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    callEditorMethod($editor, 'dispatchInput', "\x12");
    $reloaded = callEditorMethod($editor, 'getSelectedMap');
    expect($reloaded)->not->toBe($map)
        ->and($reloaded->getLayerSymbol('map:1', 0, 0))->toBe('ZZ')
        ->and(loadLayeredMap($root)->getLayerSymbol('map:1', 0, 0))->toBe('ZZ')
        ->and(file_get_contents($reloaded->dataPath))->toBe($graphicalSource)
        ->and(file_get_contents($reloaded->directory . '/layers/07.detail.deco.php'))->toBe($decorationSource)
        ->and(renderEditorPlainFrame($editor, 160, 45))->toContain('ZZ//....');
});

it('does not expose a read-only terminal toggle or crop painting warnings', function () {
    [$editor, $map] = layeredCanvasEditor();
    callEditorMethod($editor, 'selectCanvasLayer', 'map:4');
    callEditorMethod($editor, 'dispatchInput', 't');
    foreach (['i', 'x', "\033"] as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    expect($map->getLayerSymbol('map:4', 0, 0))->toBe('xx')
        ->and(renderEditorPlainFrame($editor, 160, 45))->not->toContain('Terminal preview', 'Crop mapping', 'cell overrides');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->getLayerSymbol('map:4', 0, 0))->toBe('  ');
});

it('excludes graphical layers and Tile art from the shell palette and inspector', function () {
    [$editor, $map] = layeredCanvasEditor();
    $before = $map->captureLayerSnapshot();
    $labels = implode('\n', array_map(static fn($item): string => $item->label, callEditorMethod($editor, 'buildLayerPaletteItems')));
    expect($labels)->toContain('terrain', 'buildings', 'Events', 'Create gameplay layer')
        ->not->toContain('detail', 'decoration', 'Tile art', 'Terminal preview');
    foreach (['map:1', 'map:4', 'event'] as $id) {
        callEditorMethod($editor, 'selectCanvasLayer', $id);
        $fields = json_encode(callEditorMethod($editor, 'getInspectorFields'));
        expect($fields)->not->toContain('tile-art', 'Tile art', 'tiles2d', 'shared.png', 'detail.png');
        expect(json_encode(callEditorMethod($editor, 'getLayerInspectorFields')))->not->toContain('detail');
    }
    callEditorMethod($editor, 'dispatchInput', "\x10");
    foreach (str_split('Tile art: Edit selected cell') as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(method_exists($editor, 'openCellTileArt'))->toBeFalse()
        ->and(renderEditorPlainFrame($editor, 160, 45))->not->toContain('Layer atlas', 'Apply override')
        ->and($map->captureLayerSnapshot())->toBe($before);
});

it('keeps NPC authoring and event overlays readable on the terminal canvas', function () {
    [$editor, $map] = layeredCanvasEditor();
    $map->setNpcs(NpcCollection::fromMapData([
        ['id' => 'resident', 'name' => 'Resident', 'sprite' => 'N', 'x' => 0, 'y' => 0],
    ]));
    $before = $map->captureLayerSnapshot();
    callEditorMethod($editor, 'dispatchInput', 'n');
    expect(getEditorProperty($editor, 'editingMode'))->toBe('npc')
        ->and(renderEditorPlainFrame($editor, 160, 45))->toContain('N //....', '..xxxx..', 'Name: Resident');
    callEditorMethod($editor, 'dispatchInput', "\033");
    callEditorMethod($editor, 'dispatchInput', 'e');
    expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('event')
        ->and(renderEditorPlainFrame($editor, 160, 45))->toContain('..//..EE', '..xxxx..')
        ->and($map->captureLayerSnapshot())->toBe($before);
});

it('refuses hidden decoration selection creation and stale graphical layer requests in the shell', function (string $action) {
    [$editor, $map] = layeredCanvasEditor();
    $before = $map->captureLayerSnapshot();
    $disk = sourceHashTree($map->directory);
    callEditorMethod($editor, 'selectCanvasLayer', 'map:7');
    expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:1');
    callEditorMethod($editor, 'openLayerPrompt', 'decoration');
    expect(getEditorProperty($editor, 'layerPrompt'))->toBeNull();
    setEditorProperty($editor, 'layerPrompt', ['action' => $action, 'id' => 'map:7', 'name' => 'hidden-art']);
    getEditorProperty($editor, 'modals')->push(Modal::LAYER_EDIT);
    callEditorMethod($editor, 'dispatchInput', $action === 'remove' ? 'y' : "\r");
    expect($map->captureLayerSnapshot())->toBe($before)
        ->and(sourceHashTree($map->directory))->toBe($disk)
        ->and($map->isDirty())->toBeFalse();
})->with(['remove', 'rename', 'decoration']);

it('ignores stale graphical selection and terminal read-only state when painting gameplay', function () {
    [$editor, $map] = layeredCanvasEditor();
    $before = $map->captureLayerSnapshot();
    setEditorProperty($editor, 'canvasLayerStates', [$map->mapId => ['selected' => 'map:7', 'terminal' => true]]);
    expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:1');
    foreach (['i', 'Z', "\033"] as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    expect($map->getLayerSymbol('map:1', 0, 0))->toBe('ZZ')
        ->and($map->getLayerSymbol('map:7', 0, 0))->toBe('dd')
        ->and($map->getEditableData())->toBe($before['data'])
        ->and(renderEditorPlainFrame($editor, 160, 45))->toContain('ZZ//....')
        ->not->toContain('Terminal preview');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureLayerSnapshot())->toBe($before);
});

it('requires explicit collision-change confirmation before renaming and leaves map data untouched', function (bool $changes) {
    [$editor, $map, $root] = layeredCanvasEditor();
    $dictionaryPath = $root . '/assets/Maps/collisions.php';
    file_put_contents($dictionaryPath, '<?php return ["buildings" => ["x" => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::SOLID], "houses" => ["x" => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::' . ($changes ? 'PASS_THROUGH' : 'SOLID') . '], "." => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::NONE];');
    $disk = sourceHashTree($root);
    $before = $map->captureLayerSnapshot();
    $dataSource = file_get_contents($map->dataPath);
    callEditorMethod($editor, 'selectCanvasLayer', 'map:4');
    callEditorMethod($editor, 'openLayerPrompt', 'rename');
    setEditorProperty($editor, 'layerPrompt', ['action' => 'rename', 'id' => 'map:4', 'name' => 'houses']);
    callEditorMethod($editor, 'dispatchInput', "\n");
    if ($changes) {
        expect($map->captureLayerSnapshot())->toBe($before)
            ->and(sourceHashTree($root))->toBe($disk)
            ->and(getEditorProperty($editor, 'layerPrompt')['confirmation'])->toContain('changes collision at 2 cell(s)', 'collisions.php stays unchanged');
        expect(renderEditorPlainFrame($editor, 160, 45))->toContain('Y:Confirm collision change');
        callEditorMethod($editor, 'dispatchInput', "\n");
        expect($map->captureLayerSnapshot())->toBe($before);
        callEditorMethod($editor, 'dispatchInput', 'y');
    }
    expect(getEditorProperty($editor, 'layerPrompt'))->toBeNull()
        ->and(array_column($map->getLayers(), 'name'))->toContain('houses');
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe($dataSource)
        ->and(is_file($map->directory . '/layers/04.houses.map.php'))->toBeTrue()
        ->and(hash_file('sha256', $dictionaryPath))->toBe($disk['assets/Maps/collisions.php']);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe($dataSource)
        ->and(is_file($map->directory . '/layers/04.buildings.map.php'))->toBeTrue();
})->with([false, true]);

it('creates renames and removes layers through modal controls as undoable operations', function () {
    [$editor, $map] = layeredCanvasEditor();
    callEditorMethod($editor, 'openLayerPrompt', 'create');
    foreach (str_split('fixtures') as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    callEditorMethod($editor, 'dispatchInput', "\n");
    expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:8');
    $map->save();
    expect(is_file($map->directory . '/layers/08.fixtures.map.php'))->toBeTrue();
    callEditorMethod($editor, 'openLayerPrompt', 'remove');
    callEditorMethod($editor, 'dispatchInput', "\n");
    expect($map->getLayers())->toHaveCount(5);
    callEditorMethod($editor, 'dispatchInput', 'y');
    expect($map->getLayers())->toHaveCount(4);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->getLayers())->toHaveCount(5);
});

it('reads facade brushes from the catalogue on every stamp and preserves multi-row shapes in one undo step', function () {
    [$editor, $map, $root] = layeredCanvasEditor();
    $directory = $root . '/assets/Graphics/Tilesets';
    mkdir($directory, 0777, true);
    $path = $directory . '/buildings.txt';
    file_put_contents($path, "  //\nxxxx\n\naabbcc\n  ddeeff\n");
    expect(FacadeCatalogue::load($path))->toHaveCount(2);
    $items = callEditorMethod($editor, 'buildLayerPaletteItems');
    expect(array_filter($items, static fn($item): bool => str_starts_with($item->label, 'Facade:')))->toHaveCount(2);
    callEditorMethod($editor, 'selectFacadeBrush', $path, 0);
    $before = $map->captureGridSnapshot();
    callEditorMethod($editor, 'applyCanvasToolAtCursor');
    expect($map->getLayerSymbol('map:4', 0, 1))->toBe('xx');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    file_put_contents($path, "  //\n||||\n\naabbcc\n  ddeeff\n");
    callEditorMethod($editor, 'applyCanvasToolAtCursor');
    expect($map->getLayerSymbol('map:4', 0, 1))->toBe('||')
        ->and($map->getLayerSymbol('map:1', 0, 1))->toBe('..');
});

it('keeps facade mouse clicks selection-only in Normal mode and stamps explicitly or in Paint mode', function () {
    [$editor, $map, $root] = layeredCanvasEditor();
    mkdir($root . '/assets/Graphics/Tilesets', 0777, true);
    $path = $root . '/assets/Graphics/Tilesets/buildings.txt';
    file_put_contents($path, "  //\n||||\n");
    callEditorMethod($editor, 'selectFacadeBrush', $path, 0);
    $before = $map->captureGridSnapshot();
    $bounds = callEditorMethod($editor, 'getCanvasPreviewBounds');
    $click = sprintf("\033[<0;%d;%dM", $bounds['left'], $bounds['top']);
    $release = sprintf("\033[<0;%d;%dm", $bounds['left'], $bounds['top']);
    callEditorMethod($editor, 'dispatchInput', $click);
    callEditorMethod($editor, 'dispatchInput', $release);
    expect($map->captureGridSnapshot())->toBe($before);
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect($map->getLayerSymbol('map:4', 0, 1))->toBe('||');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'dispatchInput', $click);
    callEditorMethod($editor, 'dispatchInput', $release);
    expect($map->getLayerSymbol('map:4', 0, 1))->toBe('||');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
});

it('clears facade brushes on legacy mode commands and asset navigation', function (string $command) {
    $root = layeredMapProject();
    loadLayeredMap($root)->duplicateTo($root . '/assets/Maps/zz-copy', 'zz-copy', 'Copy');
    [$editor, $map] = layeredCanvasEditor($root);
    mkdir($root . '/assets/Graphics/Tilesets', 0777, true);
    $path = $root . '/assets/Graphics/Tilesets/buildings.txt';
    file_put_contents($path, "  //\n||||\n");
    callEditorMethod($editor, 'selectFacadeBrush', $path, 0);
    $before = $map->captureLayerSnapshot();
    if ($command === 'asset') {
        setEditorProperty($editor, 'focusedPane', 'assets');
        callEditorMethod($editor, 'dispatchInput', 'j');
        expect(getEditorProperty($editor, 'selectedAssetIndex'))->toBe(1);
    } else {
        callEditorMethod($editor, 'dispatchInput', $command);
    }
    expect(getEditorProperty($editor, 'facadeBrush'))->toBeNull()
        ->and($map->captureLayerSnapshot())->toBe($before);
    callEditorMethod($editor, 'stampFacadeBrush');
    expect($map->captureLayerSnapshot())->toBe($before);
})->with(['m', 'e', 'n', 'asset']);
