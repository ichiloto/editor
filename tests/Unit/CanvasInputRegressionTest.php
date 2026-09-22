<?php

declare(strict_types=1);

use Ichiloto\Editor\Canvas\Clipboard;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectWorkspace;

/** @return array{Editor, \Ichiloto\Editor\ProjectMap} */
function createCanvasRegressionEditor(): array
{
    $root = makeTemporaryProject('ichiloto-canvas-regression-');
    $editor = createEditorForTesting($root);
    $workspace = ProjectWorkspace::fromProject($root);
    setEditorProperty($editor, 'workspace', $workspace);
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    setEditorProperty($editor, 'focusedPane', 'canvas');

    return [$editor, $workspace->getMapByIndex(0)];
}

it('projects clipped clipboard styles with their source cells and clears stale styles', function () {
    $clipboard = new Clipboard();
    $style = ['prefix' => '<fg=red;bg=blue;options=bold>', 'suffix' => '</>'];
    $clipboard->store([['A', 'B']], 'tile', [[['prefix' => '', 'suffix' => ''], $style]]);
    expect($clipboard->project(-1, 0, 2, 1))->toBe([
        ['x' => 0, 'y' => 0, 'symbol' => 'B', 'style' => $style],
    ]);
    $clipboard->store([['E']], 'event');
    expect($clipboard->project(0, 0, 1, 1))->toBe([['x' => 0, 'y' => 0, 'symbol' => 'E']]);
    $clipboard->clear();
    expect($clipboard->project(0, 0, 1, 1))->toBe([]);
});

it('preserves copied tile styles through paste undo and redo', function (bool $cut) {
    [$editor, $map] = createCanvasRegressionEditor();
    $source = [
        ['symbol' => 'A', 'prefix' => '<fg=#b87333;bg=blue;options=bold>', 'suffix' => '</>'],
        ['symbol' => 'B', 'prefix' => '', 'suffix' => ''],
        ['symbol' => ' ', 'prefix' => '<bg=red>', 'suffix' => '</>'],
    ];
    foreach ($source as $index => $cell) {
        $map->setTileCell(1 + $index, 1, ...array_values($cell));
        $map->setTileCell(5 + $index, 2, 'X', '<fg=green>', '</>');
    }
    setEditorProperty($editor, 'canvasSelection', ['x' => 1, 'y' => 1, 'width' => 3, 'height' => 1]);
    callEditorMethod($editor, $cut ? 'cutCanvasSelection' : 'captureCanvasSelection');
    if ($cut) {
        expect($map->getTileSymbol(1, 1))->toBe(' ')
            ->and($map->getTileCellStyle(1, 1))->toBe(['prefix' => '', 'suffix' => '']);
    }
    setEditorProperty($editor, 'cursorX', 5);
    setEditorProperty($editor, 'cursorY', 2);
    setEditorProperty($editor, 'selectedPaintColor', 'yellow');
    callEditorMethod($editor, 'pasteCanvasClipboard');
    foreach ($source as $index => $cell) {
        expect($map->getTileSymbol(5 + $index, 2))->toBe($cell['symbol'])
            ->and($map->getTileCellStyle(5 + $index, 2))->toBe(['prefix' => $cell['prefix'], 'suffix' => $cell['suffix']]);
    }
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    foreach (array_keys($source) as $index) {
        expect($map->getTileSymbol(5 + $index, 2))->toBe('X')
            ->and($map->getTileCellStyle(5 + $index, 2))->toBe(['prefix' => '<fg=green>', 'suffix' => '</>']);
    }
    callEditorMethod($editor, 'dispatchInput', "\x19");
    foreach ($source as $index => $cell) {
        expect($map->getTileSymbol(5 + $index, 2))->toBe($cell['symbol'])
            ->and($map->getTileCellStyle(5 + $index, 2))->toBe(['prefix' => $cell['prefix'], 'suffix' => $cell['suffix']]);
    }
})->with(['copy' => false, 'cut' => true]);

it('keeps colour-picker mouse clicks and wheel events away from the canvas', function (string $mode) {
    [$editor, $map] = createCanvasRegressionEditor();
    $map->resize(200, 200);
    setEditorProperty($editor, 'inputMode', $mode);
    setEditorProperty($editor, 'cursorX', 1);
    setEditorProperty($editor, 'cursorY', 1);
    $map->setTileCell(1, 1, 'X', '', '');
    $before = $map->captureGridSnapshot();
    callEditorMethod($editor, 'openColorPicker');
    setEditorProperty($editor, 'colorPaletteIndex', 3);
    $bounds = callEditorMethod($editor, 'getCanvasPreviewBounds');
    foreach ([0, 2, 32, 64, 65, 66, 67] as $code) {
        callEditorMethod($editor, 'dispatchInput', sprintf("\033[<%d;%d;%dM", $code, $bounds['left'] + 3, $bounds['top'] + 2));
    }
    expect(getEditorProperty($editor, 'cursorX'))->toBe(1)
        ->and(getEditorProperty($editor, 'cursorY'))->toBe(1)
        ->and(getEditorProperty($editor, 'canvasOffsetX'))->toBe(0)
        ->and(getEditorProperty($editor, 'canvasOffsetY'))->toBe(0)
        ->and($map->captureGridSnapshot())->toBe($before);
    callEditorMethod($editor, 'dispatchInput', "\n");
    expect(getEditorProperty($editor, 'isColorPickerOpen'))->toBeFalse()
        ->and($map->getTileColor(1, 1))->not->toBeNull()
        ->and($map->getTileCellStyle(3, 2))->toBe([
            'prefix' => $before['tiles'][2][3]['prefix'], 'suffix' => $before['tiles'][2][3]['suffix'],
        ]);
})->with(['normal', 'paint']);

it('scrolls only within the canvas preview including its edge cells', function (string $mode) {
    [$editor, $map] = createCanvasRegressionEditor();
    $map->resize(200, 200);
    setEditorProperty($editor, 'editingMode', $mode);
    setEditorProperty($editor, 'canvasOffsetX', 10);
    setEditorProperty($editor, 'canvasOffsetY', 10);
    $bounds = callEditorMethod($editor, 'getCanvasPreviewBounds');
    $outside = [
        [2, $bounds['top']], [119, $bounds['top']],
        [$bounds['left'] - 1, $bounds['top']], [$bounds['right'] + 1, $bounds['top']],
        [$bounds['left'], $bounds['top'] - 1], [$bounds['left'], $bounds['bottom'] + 1],
    ];
    foreach ($outside as [$x, $y]) {
        foreach ([64, 65, 66, 67] as $code) {
            callEditorMethod($editor, 'dispatchInput', sprintf("\033[<%d;%d;%dM", $code, $x, $y));
            expect(getEditorProperty($editor, 'canvasOffsetX'))->toBe(10)
                ->and(getEditorProperty($editor, 'canvasOffsetY'))->toBe(10);
        }
    }
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<65;%d;%dM", $bounds['left'], $bounds['top']));
    expect(getEditorProperty($editor, 'canvasOffsetY'))->toBeGreaterThan(10);
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<67;%d;%dM", $bounds['right'], $bounds['bottom']));
    expect(getEditorProperty($editor, 'canvasOffsetX'))->toBeGreaterThan(10);
})->with(['map', 'event']);
