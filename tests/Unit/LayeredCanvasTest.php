<?php

declare(strict_types=1);

use Ichiloto\Editor\Canvas\CanvasTool;
use Ichiloto\Editor\Canvas\FacadeCatalogue;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\UI\Modal;
use Ichiloto\Engine\Events\Enumerations\CollisionType;

it('cycles every layer including events without stealing printable Paint-mode glyphs', function () {
    [$editor, $map] = layeredCanvasEditor();
    foreach (['map:4', 'map:7', 'event', 'map:1'] as $id) {
        callEditorMethod($editor, 'dispatchInput', ']');
        expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe($id);
    }
    callEditorMethod($editor, 'selectCanvasLayer', 'map:7');
    callEditorMethod($editor, 'dispatchInput', 'i');
    foreach (['[', ']', 'v', 'd', 't', 'o'] as $glyph) {
        callEditorMethod($editor, 'dispatchInput', $glyph);
        expect($map->getLayerSymbol('map:7', 0, 0))->toBe($glyph)
            ->and(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:7');
    }
});

it('uses colour selection clipboard shapes fill eyedropper and undo on every layer', function (string $id) {
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
    expect($map->getLayerSymbol($id, 0, 0))->toBe('Q')
        ->and($map->getLayerColor($id, 0, 0))->toBe('yellow')
        ->and($map->getLayerColor($id, 1, 0))->toBe('yellow');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    callEditorMethod($editor, 'captureCanvasSelection');
    setEditorProperty($editor, 'cursorY', 1);
    callEditorMethod($editor, 'pasteCanvasClipboard');
    expect($map->getLayerSymbol($id, 0, 1))->toBe('Q')
        ->and($map->getLayerColor($id, 0, 1))->toBe('red');
    callEditorMethod($editor, 'pickSymbolUnderCursor');
    expect(getEditorProperty($editor, 'selectedPaintSymbol'))->toBe('Q')
        ->and(getEditorProperty($editor, 'selectedPaintColor'))->toBe('red');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    callEditorMethod($editor, 'selectCanvasTool', CanvasTool::LINE);
    setEditorProperty($editor, 'cursorX', 0);
    callEditorMethod($editor, 'applyCanvasToolAtCursor');
    setEditorProperty($editor, 'cursorX', 3);
    callEditorMethod($editor, 'applyCanvasToolAtCursor');
    expect($map->getLayerSymbol($id, 3, 1))->toBe('Q');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    setEditorProperty($editor, 'selectedPaintSymbol', 'F');
    callEditorMethod($editor, 'floodFillFromCursor');
    expect($map->getLayerSymbol($id, 3, 1))->toBe('F');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
})->with(['map:1', 'map:4', 'map:7', 'event']);

it('keeps mouse strokes layer-bound through undo and a later layer switch', function (string $id) {
    [$editor, $map] = layeredCanvasEditor();
    callEditorMethod($editor, 'selectCanvasLayer', $id);
    callEditorMethod($editor, 'enterPaintMode');
    setEditorProperty($editor, 'selectedPaintSymbol', 'M');
    setEditorProperty($editor, 'selectedPaintColor', 'cyan');
    $before = $map->captureGridSnapshot();
    $bounds = callEditorMethod($editor, 'getCanvasPreviewBounds');
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<0;%d;%dM", $bounds['left'] + 2, $bounds['top']));
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<32;%d;%dM", $bounds['left'] + 2, $bounds['top'] + 1));
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<0;%d;%dm", $bounds['left'] + 2, $bounds['top'] + 1));
    expect($map->getLayerSymbol($id, 2, 1))->toBe('M')
        ->and($map->getLayerColor($id, 2, 1))->toBe('cyan');
    callEditorMethod($editor, 'selectCanvasLayer', $id === 'map:1' ? 'map:4' : 'map:1');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    callEditorMethod($editor, 'dispatchInput', "\x19");
    expect($map->getLayerSymbol($id, 2, 1))->toBe('M');
})->with(['map:1', 'map:4', 'map:7', 'event']);

it('keeps visibility dimming and terminal preview out of authored state and blocks preview painting', function () {
    [$editor, $map] = layeredCanvasEditor();
    $before = $map->captureLayerSnapshot();
    callEditorMethod($editor, 'dispatchInput', 'v');
    callEditorMethod($editor, 'dispatchInput', 'd');
    callEditorMethod($editor, 'dispatchInput', 't');
    callEditorMethod($editor, 'applyCanvasWrites', $map, [['x' => 0, 'y' => 0, 'symbol' => 'X']], 'preview');
    $bounds = callEditorMethod($editor, 'getCanvasPreviewBounds');
    callEditorMethod($editor, 'enterPaintMode');
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<0;%d;%dM", $bounds['left'], $bounds['top']));
    expect($map->captureLayerSnapshot())->toBe($before)->and($map->isDirty())->toBeFalse();
    $frame = renderEditorPlainFrame($editor, 160, 45);
    expect($frame)->toContain('Terminal preview', 'Ctrl+P:Palette');
    $footer = callEditorMethod($editor, 'createFooterWindow');
    expect($footer->help)->not->toContain('Layer', 'Colour', 'Terminal');
});

it('shows crop tables read-only and keeps layer-specific painting warnings visible', function () {
    [$editor, $map] = layeredCanvasEditor();
    callEditorMethod($editor, 'selectCanvasLayer', 'map:4');
    $fields = callEditorMethod($editor, 'getLayerInspectorFields');
    expect(array_column($fields, 'editable'))->each->toBeFalse()
        ->and(json_encode($fields))->toContain('shared.png', '16');
    callEditorMethod($editor, 'applyCanvasWrites', $map, [['x' => 0, 'y' => 0, 'symbol' => 'x']], 'mapped');
    expect(getEditorProperty($editor, 'canvasPaintWarning'))->toContain('Crop mapping');
    callEditorMethod($editor, 'selectCanvasLayer', 'map:1');
    callEditorMethod($editor, 'applyCanvasWrites', $map, [['x' => 0, 'y' => 0, 'symbol' => 'x']], 'unmapped');
    expect(getEditorProperty($editor, 'canvasPaintWarning'))->toBe('');
});

it('requires explicit collision-change confirmation before renaming and preserves local crop source bytes', function (bool $changes) {
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
    expect(file_get_contents($map->dataPath))->toBe(str_replace("'buildings' =>", "'houses' =>", $dataSource))
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
    file_put_contents($path, " /\nxx\n\nabc\n def\n");
    expect(FacadeCatalogue::load($path))->toHaveCount(2);
    $items = callEditorMethod($editor, 'buildLayerPaletteItems');
    expect(array_filter($items, static fn($item): bool => str_starts_with($item->label, 'Facade:')))->toHaveCount(2);
    callEditorMethod($editor, 'selectFacadeBrush', $path, 0);
    $before = $map->captureGridSnapshot();
    callEditorMethod($editor, 'applyCanvasToolAtCursor');
    expect($map->getLayerSymbol('map:4', 0, 1))->toBe('x');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    file_put_contents($path, " /\n||\n\nabc\n def\n");
    callEditorMethod($editor, 'applyCanvasToolAtCursor');
    expect($map->getLayerSymbol('map:4', 0, 1))->toBe('|')
        ->and($map->getLayerSymbol('map:1', 0, 1))->toBe('.');
});

it('keeps facade mouse clicks selection-only in Normal mode and stamps explicitly or in Paint mode', function () {
    [$editor, $map, $root] = layeredCanvasEditor();
    mkdir($root . '/assets/Graphics/Tilesets', 0777, true);
    $path = $root . '/assets/Graphics/Tilesets/buildings.txt';
    file_put_contents($path, " /\n||\n");
    callEditorMethod($editor, 'selectFacadeBrush', $path, 0);
    $before = $map->captureGridSnapshot();
    $bounds = callEditorMethod($editor, 'getCanvasPreviewBounds');
    $click = sprintf("\033[<0;%d;%dM", $bounds['left'], $bounds['top']);
    $release = sprintf("\033[<0;%d;%dm", $bounds['left'], $bounds['top']);
    callEditorMethod($editor, 'dispatchInput', $click);
    callEditorMethod($editor, 'dispatchInput', $release);
    expect($map->captureGridSnapshot())->toBe($before);
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect($map->getLayerSymbol('map:4', 0, 1))->toBe('|');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'dispatchInput', $click);
    callEditorMethod($editor, 'dispatchInput', $release);
    expect($map->getLayerSymbol('map:4', 0, 1))->toBe('|');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureGridSnapshot())->toBe($before);
});

it('clears facade brushes on legacy mode commands and asset navigation', function (string $command) {
    $root = layeredMapProject();
    loadLayeredMap($root)->duplicateTo($root . '/assets/Maps/zz-copy', 'zz-copy', 'Copy');
    [$editor, $map] = layeredCanvasEditor($root);
    mkdir($root . '/assets/Graphics/Tilesets', 0777, true);
    $path = $root . '/assets/Graphics/Tilesets/buildings.txt';
    file_put_contents($path, " /\n||\n");
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
