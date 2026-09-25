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

it('authors mapped floor and wall decoration through real canvas commands without changing gameplay', function () {
    $root = createMixedMaterialProject();
    [$editor, $map] = layeredCanvasEditor($root);
    $dictionary = require $root . '/assets/Maps/collisions.php';
    $collision = MapCollisionResolver::resolveLayers($map->getLayerSet(), $dictionary);
    $terminal = ['####i#####', '#        #', '#        #', '##########', '#        #', '#        #', '##########'];
    $originalFiles = sourceHashTree($root . '/assets/Maps');
    $getCells = static fn(array $snapshot): array => array_map(
        static fn(array $layer): array => $layer['grid']['cells'], $snapshot['layers']['layers'],
    );
    expect($collision[1][2])->toBe(CollisionType::NONE->value)
        ->and($collision[4][2])->toBe(CollisionType::NONE->value)
        ->and($collision[0][1])->toBe(CollisionType::SOLID->value)
        ->and($collision[3][5])->toBe(CollisionType::SOLID->value);

    callEditorMethod($editor, 'dispatchInput', ']');
    expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:2');
    foreach ([[1, 1, 'w'], [2, 1, 'w'], [3, 1, 'w'], [7, 1, 'k'], [8, 1, 'k'], [1, 4, 's'], [2, 4, 's']] as [$x, $y, $glyph]) {
        paintMixedMaterial($editor, $x, $y, $glyph);
        expect($map->getLayerSymbol('map:2', $x, $y))->toBe($glyph);
    }
    expect(getEditorProperty($editor, 'canvasPaintWarning'))->toContain('Crop mapping', 'read-only');
    callEditorMethod($editor, 'dispatchInput', ']');
    expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:3');
    paintMixedMaterial($editor, 2, 1, 'r');
    paintMixedMaterial($editor, 2, 4, 'c');
    callEditorMethod($editor, 'dispatchInput', ']'); // Solid gameplay walls, left untouched.
    expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:4');
    callEditorMethod($editor, 'dispatchInput', ']');
    expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:5');
    paintMixedMaterial($editor, 1, 0, 'w'); // Cosmetic writing, not an interaction marker.
    paintMixedMaterial($editor, 5, 3, 'o');
    $painted = $map->captureGridSnapshot();
    expect(getMixedMaterialCanvasRows($editor))->toBe([
        '#w##i#####', '#wrw   kk#', '#        #', '#####o####', '#sc      #', '#        #', '##########',
    ]);

    // Undo stays bound to its layer even after selecting the interactive fixture layer.
    callEditorMethod($editor, 'dispatchInput', ']');
    expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:6');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->getLayerSymbol('map:5', 5, 3))->toBe(' ')
        ->and($map->getLayerSymbol('map:6', 4, 0))->toBe('i');
    callEditorMethod($editor, 'dispatchInput', "\x19");
    expect($map->captureGridSnapshot())->toBe($painted);
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect($map->isDirty())->toBeFalse();
    $saved = sourceHashTree($root . '/assets/Maps');
    expect(array_keys(array_diff_assoc($saved, $originalFiles)))->toBe([
        'test-map/layers/02.floors.deco.php', 'test-map/layers/03.rugs.deco.php', 'test-map/layers/05.wall-detail.deco.php',
    ]);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(loadLayeredMap($root)->getLayerSymbol('map:5', 5, 3))->toBe(' ');
    callEditorMethod($editor, 'dispatchInput', "\x19");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(sourceHashTree($root . '/assets/Maps'))->toBe($saved);
    callEditorMethod($editor, 'dispatchInput', "\x12");
    $reloaded = callEditorMethod($editor, 'getSelectedMap');
    expect($reloaded)->not->toBe($map)
        ->and($getCells($reloaded->captureGridSnapshot()))->toBe($getCells($painted))
        ->and(MapCollisionResolver::resolveLayers($reloaded->getLayerSet(), $dictionary))->toBe($collision);

    $rectangles = [];
    foreach (['map:2' => ['w', 'k', 's'], 'map:3' => ['r', 'c'], 'map:5' => ['w', 'o']] as $id => $glyphs) {
        $definition = $reloaded->getLayerTiles2d($id);
        expect($definition['asset'])->toBe('Graphics/Tilesets/materials.png');
        foreach ($glyphs as $glyph) {
            $rectangles[] = json_encode($definition['symbols'][$glyph]);
        }
    }
    expect(array_unique($rectangles))->toHaveCount(7);
    // Terminal preview ignores the hidden gameplay layer as well as every cosmetic marker.
    callEditorMethod($editor, 'dispatchInput', 'v');
    callEditorMethod($editor, 'dispatchInput', 'd');
    callEditorMethod($editor, 'dispatchInput', 't');
    expect(callEditorMethod($editor, 'createCanvasWindow')->title)->toContain('Terminal preview')
        ->and(getMixedMaterialCanvasRows($editor))->toBe($terminal)
        ->and($reloaded->getLayerSymbol('map:6', 4, 0))->toBe('i')
        ->and($reloaded->getEditableData()['npcs'][0]['dialogue'])->toBe([['text' => 'Read the notice.']])
        ->and($reloaded->getEditableData()['npcs'][0]['sprite'])->toBe('');
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(sourceHashTree($root . '/assets/Maps'))->toBe($saved);
});

it('keeps symbol defaults read-only alongside cell art authoring and refuses unmapped decoration through Ctrl-S', function () {
    $root = createMixedMaterialProject();
    [$editor, $map] = layeredCanvasEditor($root);
    $before = sourceHashTree($root);
    callEditorMethod($editor, 'dispatchInput', ']');
    $fields = callEditorMethod($editor, 'getLayerInspectorFields');
    expect(array_column(array_filter($fields, static fn(array $field): bool => ($field['target'] ?? '') !== 'tile-art'), 'editable'))->each->toBeFalse();
    expect(array_values(array_filter($fields, static fn(array $field): bool => ($field['target'] ?? '') === 'tile-art'))[0]['editable'])->toBeTrue();
    callEditorMethod($editor, 'dispatchInput', "\t");
    $attempted = [];
    foreach (callEditorMethod($editor, 'getInspectorFields') as $index => $field) {
        if (! in_array($field['label'], ['tiles2d (read-only)', '  w', '  k', '  s'], true)) {
            continue;
        }
        setEditorProperty($editor, 'selectedInspectorFieldIndex', $index);
        callEditorMethod($editor, 'dispatchInput', "\r");
        expect(getEditorProperty($editor, 'isInspectorEditing'))->toBeFalse();
        $attempted[] = $field['label'];
    }
    expect($attempted)->toBe(['tiles2d (read-only)', '  w', '  k', '  s']);
    callEditorMethod($editor, 'dispatchInput', "\033[Z");
    paintMixedMaterial($editor, 1, 1, 'z');
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(getEditorProperty($editor, 'statusMessage'))->toContain('has no crop mapping')
        ->and($map->isDirty())->toBeTrue()
        ->and(sourceHashTree($root))->toBe($before);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect($map->isDirty())->toBeFalse()
        ->and(sourceHashTree($root))->toBe($before);
});
