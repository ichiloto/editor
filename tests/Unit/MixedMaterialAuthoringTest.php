<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\IO\Console\TerminalText;

function createMixedMaterialProject(): string
{
    $root = makeTemporaryProject('ichiloto-mixed-material-');
    $directory = $root . '/assets/Maps/test-map';
    unlink($directory . '/test-map.map.php');
    mkdir($directory . '/layers');
    // Ten cells across, two terminal columns each.
    $blank = implode("\n", array_fill(0, 7, str_repeat(' ', 20)));
    $wall = str_repeat('#', 20);
    $room = '##' . str_repeat(' ', 16) . '##';
    $walls = implode("\n", [$wall, $room, $room, $wall, $room, $room, $wall]);
    $fixtures = substr_replace($blank, 'ii', 8, 2);
    foreach ([
        '01.terrain.map.php' => $blank,
        '02.floors.deco.php' => $blank,
        '03.rugs.deco.php' => $blank,
        '04.walls.map.php' => $walls,
        '05.wall-detail.deco.php' => $blank,
        '06.fixtures.map.php' => $fixtures,
    ] as $file => $grid) {
        file_put_contents($directory . '/layers/' . $file, MapGridSource::buildSource($grid, 'ROOM', "// Keep $file.\n"));
    }
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource($blank, 'EVENTS'));
    file_put_contents($directory . '/test-map.data.php', "<?php\n// Preserve material definitions and interactions.\nreturn " . var_export([
        'name' => 'Mixed materials', 'region' => '', 'events' => [],
        'npcs' => [['id' => 'notice', 'name' => 'Notice', 'x' => 4, 'y' => 0, 'sprite' => '',
            'movement' => 'fixed', 'dialogue' => [['text' => 'Read the notice.']]]],
    ], true) . ";\n");
    file_put_contents($root . '/assets/Maps/collisions.php', <<<'PHP'
<?php
use Ichiloto\Engine\Events\Enumerations\CollisionType;
return [' ' => CollisionType::NONE, '#' => CollisionType::SOLID, 'i' => CollisionType::SOLID];
PHP);
    return $root;
}

/** Select a cell with a Normal-mode mouse click, then paint through the real key router. */
function paintMixedMaterial(Editor $editor, int $x, int $y, string $symbol): void
{
    callEditorMethod($editor, 'dispatchInput', "\033");
    $bounds = callEditorMethod($editor, 'getCanvasPreviewBounds');
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<0;%d;%dM", $bounds['left'] + $x * 2, $bounds['top'] + $y));
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<0;%d;%dm", $bounds['left'] + $x * 2, $bounds['top'] + $y));
    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'dispatchInput', $symbol);
    callEditorMethod($editor, 'dispatchInput', "\033");
}

function getMixedMaterialCanvasRows(Editor $editor): array
{
    $window = callEditorMethod($editor, 'createCanvasWindow');
    return array_map(static fn(string $line): string => substr(TerminalText::stripAnsi($line), 0, 20),
        array_slice($window->content, ProjectWorkspace::CANVAS_HEADER_ROWS, 7));
}

it('preserves mixed graphical materials through model save restore and reload without changing terminal gameplay', function () {
    $root = createMixedMaterialProject();
    $map = loadLayeredMap($root);
    $dictionary = require $root . '/assets/Maps/collisions.php';
    $collision = MapCollisionResolver::resolveLayers($map->getLayerSet(), $dictionary);
    $terminal = $map->renderPreview(10, 7);
    $original = $map->captureLayerSnapshot();
    $originalFiles = sourceHashTree($root . '/assets/Maps');
    expect($collision[1][2])->toBe(CollisionType::NONE->value)
        ->and($collision[4][2])->toBe(CollisionType::NONE->value)
        ->and($collision[0][1])->toBe(CollisionType::SOLID->value)
        ->and($collision[3][5])->toBe(CollisionType::SOLID->value);

    foreach ([
        'map:2' => [[1, 1, 'w'], [2, 1, 'w'], [3, 1, 'w'], [7, 1, 'k'], [8, 1, 'k'], [1, 4, 's'], [2, 4, 's']],
        'map:3' => [[2, 1, 'r'], [2, 4, 'c']],
        'map:5' => [[1, 0, 'w'], [5, 3, 'o']],
    ] as $id => $cells) {
        foreach ($cells as [$x, $y, $glyph]) {
            $map->setLayerCell($id, $x, $y, $glyph);
        }
    }
    $painted = $map->captureLayerSnapshot();
    $map->save();
    $saved = sourceHashTree($root . '/assets/Maps');
    expect(array_keys(array_diff_assoc($saved, $originalFiles)))->toBe([
        'test-map/layers/02.floors.deco.php', 'test-map/layers/03.rugs.deco.php', 'test-map/layers/05.wall-detail.deco.php',
    ]);
    $map->restoreLayerSnapshot($original);
    $map->save();
    expect(sourceHashTree($root . '/assets/Maps'))->toBe($originalFiles);
    $map->restoreLayerSnapshot($painted);
    $map->save();
    expect(sourceHashTree($root . '/assets/Maps'))->toBe($saved);
    $reloaded = loadLayeredMap($root);
    expect($reloaded->getLayerSymbol('map:2', 7, 1))->toBe('kk')
        ->and($reloaded->getLayerSymbol('map:3', 2, 4))->toBe('cc')
        ->and($reloaded->getLayerSymbol('map:5', 5, 3))->toBe('oo')
        ->and($reloaded->renderPreview(10, 7))->toBe($terminal)
        ->and(MapCollisionResolver::resolveLayers($reloaded->getLayerSet(), $dictionary))->toBe($collision)
        ->and($reloaded->getEditableData()['npcs'][0]['dialogue'])->toBe([['text' => 'Read the notice.']])
        ->and($reloaded->getEditableData()['npcs'][0]['sprite'])->toBe('');
});

it('excludes material markers and crop controls from the TUI while terminal editing preserves their source', function () {
    $root = createMixedMaterialProject();
    $map = loadLayeredMap($root);
    foreach (['map:2' => [1, 1, 'w'], 'map:3' => [2, 1, 'r'], 'map:5' => [1, 0, 'o']] as $id => [$x, $y, $glyph]) {
        $map->setLayerCell($id, $x, $y, $glyph);
    }
    $map->save();
    $before = sourceHashTree($root . '/assets/Maps');
    [$editor, $map] = layeredCanvasEditor($root);
    $wall = str_repeat('#', 20);
    $room = '##' . str_repeat(' ', 16) . '##';
    $terminal = ['########ii##########', $room, $room, $wall, $room, $room, $wall];
    expect(getMixedMaterialCanvasRows($editor))->toBe($terminal);
    foreach (['map:4', 'map:6', 'event', 'map:1'] as $id) {
        callEditorMethod($editor, 'dispatchInput', ']');
        expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe($id);
    }
    foreach (['map:2', 'map:3', 'map:5'] as $id) {
        callEditorMethod($editor, 'selectCanvasLayer', $id);
        expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:1');
    }
    $labels = implode("\n", array_map(static fn($item): string => $item->label, callEditorMethod($editor, 'buildLayerPaletteItems')));
    expect($labels)->not->toContain('floors', 'rugs', 'wall-detail', 'Tile art', 'Terminal preview')
        ->and(json_encode(callEditorMethod($editor, 'getLayerInspectorFields')))->not->toContain('floors', 'rugs', 'wall-detail', 'tiles2d', 'tile-art');

    paintMixedMaterial($editor, 2, 1, '#');
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect($map->getLayerSymbol('map:1', 2, 1))->toBe('##')
        ->and(array_keys(array_diff_assoc(sourceHashTree($root . '/assets/Maps'), $before)))->toBe(['test-map/layers/01.terrain.map.php']);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(sourceHashTree($root . '/assets/Maps'))->toBe($before)
        ->and(getMixedMaterialCanvasRows($editor))->toBe($terminal);
    callEditorMethod($editor, 'dispatchInput', "\x19");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    callEditorMethod($editor, 'dispatchInput', "\x12");
    $loaded = callEditorMethod($editor, 'getSelectedMap');
    expect($loaded->getLayerSymbol('map:1', 2, 1))->toBe('##')
        ->and($loaded->getLayerSymbol('map:2', 1, 1))->toBe('ww')
        ->and($loaded->getLayerSymbol('map:3', 2, 1))->toBe('rr')
        ->and($loaded->getLayerSymbol('map:5', 1, 0))->toBe('oo');
    callEditorMethod($editor, 'selectCanvasLayer', 'map:6');
    callEditorMethod($editor, 'dispatchInput', 'v');
    expect(getMixedMaterialCanvasRows($editor)[0])->toBe($wall);
    callEditorMethod($editor, 'dispatchInput', 'v');
    expect(getMixedMaterialCanvasRows($editor)[0])->toBe('########ii##########');
});
