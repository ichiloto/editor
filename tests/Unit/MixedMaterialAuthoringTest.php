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
    $blank = implode("\n", array_fill(0, 7, str_repeat(' ', 10)));
    $walls = "##########\n#        #\n#        #\n##########\n#        #\n#        #\n##########";
    $fixtures = substr_replace($blank, 'i', 4, 1);
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
    $crops = [];
    foreach (['floors' => ['w', 'k', 's'], 'rugs' => ['r', 'c'], 'wall-detail' => ['w', 'o']] as $layer => $symbols) {
        $y = count($crops) * 16;
        foreach ($symbols as $x => $symbol) {
            $crops[$layer]['symbols'][$symbol] = ['x' => $x * 16, 'y' => $y, 'width' => 16, 'height' => 16];
        }
    }
    file_put_contents($directory . '/test-map.data.php', "<?php\n// Preserve material definitions and interactions.\nreturn " . var_export([
        'name' => 'Mixed materials', 'region' => '', 'events' => [],
        'npcs' => [['id' => 'notice', 'name' => 'Notice', 'x' => 4, 'y' => 0, 'sprite' => '',
            'movement' => 'fixed', 'dialogue' => [['text' => 'Read the notice.']]]],
        'tiles2d' => ['asset' => 'Graphics/Tilesets/materials.png', 'layers' => $crops],
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
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<0;%d;%dM", $bounds['left'] + $x, $bounds['top'] + $y));
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<0;%d;%dm", $bounds['left'] + $x, $bounds['top'] + $y));
    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'dispatchInput', $symbol);
    callEditorMethod($editor, 'dispatchInput', "\033");
}

function getMixedMaterialCanvasRows(Editor $editor): array
{
    $window = callEditorMethod($editor, 'createCanvasWindow');
    return array_map(static fn(string $line): string => substr(TerminalText::stripAnsi($line), 0, 10),
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
    expect($reloaded->getLayerSymbol('map:2', 7, 1))->toBe('k')
        ->and($reloaded->getLayerSymbol('map:3', 2, 4))->toBe('c')
        ->and($reloaded->getLayerSymbol('map:5', 5, 3))->toBe('o')
        ->and($reloaded->renderPreview(10, 7))->toBe($terminal)
        ->and(MapCollisionResolver::resolveLayers($reloaded->getLayerSet(), $dictionary))->toBe($collision)
        ->and($reloaded->getEditableData()['npcs'][0]['dialogue'])->toBe([['text' => 'Read the notice.']])
        ->and($reloaded->getEditableData()['npcs'][0]['sprite'])->toBe('');

    $rectangles = [];
    foreach (['map:2' => ['w', 'k', 's'], 'map:3' => ['r', 'c'], 'map:5' => ['w', 'o']] as $id => $glyphs) {
        $definition = $reloaded->getLayerTiles2d($id);
        expect($definition['asset'])->toBe('Graphics/Tilesets/materials.png');
        foreach ($glyphs as $glyph) {
            $rectangles[] = json_encode($definition['symbols'][$glyph]);
        }
    }
    expect(array_unique($rectangles))->toHaveCount(7);
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
    $terminal = ['####i#####', '#        #', '#        #', '##########', '#        #', '#        #', '##########'];
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
    expect($map->getLayerSymbol('map:1', 2, 1))->toBe('#')
        ->and(array_keys(array_diff_assoc(sourceHashTree($root . '/assets/Maps'), $before)))->toBe(['test-map/layers/01.terrain.map.php']);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(sourceHashTree($root . '/assets/Maps'))->toBe($before)
        ->and(getMixedMaterialCanvasRows($editor))->toBe($terminal);
    callEditorMethod($editor, 'dispatchInput', "\x19");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    callEditorMethod($editor, 'dispatchInput', "\x12");
    $loaded = callEditorMethod($editor, 'getSelectedMap');
    expect($loaded->getLayerSymbol('map:1', 2, 1))->toBe('#')
        ->and($loaded->getLayerSymbol('map:2', 1, 1))->toBe('w')
        ->and($loaded->getLayerSymbol('map:3', 2, 1))->toBe('r')
        ->and($loaded->getLayerSymbol('map:5', 1, 0))->toBe('o');
    callEditorMethod($editor, 'selectCanvasLayer', 'map:6');
    callEditorMethod($editor, 'dispatchInput', 'v');
    expect(getMixedMaterialCanvasRows($editor)[0])->toBe('##########');
    callEditorMethod($editor, 'dispatchInput', 'v');
    expect(getMixedMaterialCanvasRows($editor)[0])->toBe('####i#####');
});

it('retains strict model refusal of newly unmapped graphical material without exposing a TUI crop workflow', function () {
    $root = createMixedMaterialProject();
    $map = loadLayeredMap($root);
    $before = sourceHashTree($root);
    $original = $map->captureLayerSnapshot();
    $map->setLayerCell('map:2', 1, 1, 'z');
    expect(fn() => $map->save())->toThrow(InvalidArgumentException::class, 'has no crop mapping')
        ->and($map->isDirty())->toBeTrue()
        ->and(sourceHashTree($root))->toBe($before);
    $map->restoreLayerSnapshot($original);
    $map->save();
    expect($map->isDirty())->toBeFalse()
        ->and(sourceHashTree($root))->toBe($before);
});
