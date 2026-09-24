<?php

declare(strict_types=1);

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\IO\Console\TerminalText;

it('loads ordered gameplay decoration and event layers through the Engine source contract', function () {
    $map = loadLayeredMap(layeredMapProject());
    expect(array_column($map->getLayers(), 'name'))->toBe(['terrain', 'buildings', 'detail', 'Events'])
        ->and(array_column($map->getLayers(), 'order'))->toBe([1, 4, 7, null])
        ->and($map->getLayerSymbol('map:4', 1, 0))->toBe('/')
        ->and($map->getLayerSymbol('map:7', 0, 0))->toBe('d');
    $terminal = array_map(TerminalText::stripAnsi(...), $map->renderPreview(4, 2, terminalPreview: true));
    expect($terminal)->toBe(['./..', '.xx.']);
    expect(array_map(TerminalText::stripAnsi(...), $map->renderPreview(4, 2)))->toBe(['d/.E', '.xxd']);
    expect(array_map(TerminalText::stripAnsi(...), $map->renderPreview(4, 2, showEventOverlay: false, layerVisibility: ['map:7' => false])))->toBe($terminal);
    expect(implode('', $map->renderPreview(4, 2, activeLayer: 'map:4', dimInactive: true)))->toContain("\033[2m")
        ->and(implode('', $map->renderPreview(4, 2, terminalPreview: true, layerVisibility: ['map:4' => false], dimInactive: true)))->not->toContain("\033[2m");
});

it('does not write any untouched layer and preserves source scaffolding and unchanged colour-run rows', function () {
    $root = layeredMapProject();
    $map = loadLayeredMap($root);
    $path = $map->directory . '/layers/01.terrain.map.php';
    $source = file_get_contents($path);
    foreach ($map->getStoredGridPaths() as $file) {
        touch($file, 1000000000);
    }
    $before = sourceHashTree($map->directory);
    $map->save();
    expect(sourceHashTree($map->directory))->toBe($before);
    $map->setLayerCell('map:1', 0, 0, 'T', '<fg=green>', '</>');
    $map->setLayerCell('map:4', 0, 0, '|');
    $map->save();
    expect(file_get_contents($path))->toContain('// keep 01.terrain.map.php', "return <<<'AUTHORED'", '<fg=red>..</><fg=red>..</>')
        ->and(filemtime($map->directory . '/layers/07.detail.deco.php'))->toBe(1000000000)
        ->and(filemtime($map->eventPath))->toBe(1000000000);
    $map->setLayerCell('map:1', 0, 0, '.', '<fg=green>', '</>');
    $map->save();
    expect(file_get_contents($path))->toBe($source);
});

it('retains CRLF source indentation comments and unedited rows', function () {
    $root = layeredMapProject();
    $path = $root . '/assets/Maps/test-map/layers/04.buildings.map.php';
    $source = "<?php\r\n// retained\r\nreturn <<<'HOUSE'\r\n    /   \r\n    xx  \r\n    HOUSE; // trailing\r\n";
    file_put_contents($path, $source);
    $map = loadLayeredMap($root);
    $map->setLayerCell('map:4', 2, 0, '|');
    $map->save();
    expect(file_get_contents($path))->toBe(str_replace('    /   ', '    / | ', $source));
});

it('preserves ragged per-row dimensions when creating and round-tripping layers', function () {
    $root = layeredMapProject(true);
    $map = loadLayeredMap($root);
    $id = $map->createLayer('fixtures');
    $map->setLayerCell($id, 1, 1, 'i');
    $map->save();
    $loaded = loadLayeredMap($root);
    foreach ($loaded->getLayerSet()->layers as $layer) {
        expect(array_map(count(...), $layer->grid))->toBe([4, 2]);
    }
    expect($loaded->getLayerSymbol($id, 1, 1))->toBe('i');
});

it('rolls the complete layered save and rename back after any installation failure', function () {
    $map = loadLayeredMap(layeredMapProject());
    $before = sourceHashTree($map->directory);
    $map->setLayerCell('map:1', 0, 0, 'T');
    $map->renameLayer('map:4', 'houses');
    $failing = new FailingFileSetOperations(failures: ['move' => [$map->directory . '/layers/04.houses.map.php']]);
    expect(fn() => $map->save(files: $failing))->toThrow(FileSetTransactionFailure::class)
        ->and(sourceHashTree($map->directory))->toBe($before)
        ->and($map->isDirty())->toBeTrue();
    $map->save();
    expect(is_file($map->directory . '/layers/04.houses.map.php'))->toBeTrue()
        ->and(is_file($map->directory . '/layers/04.buildings.map.php'))->toBeFalse()
        ->and($map->getEditableData()['tiles2d']['layers'])->toHaveKey('houses');
});

it('duplicates and moves every layer preserving authored bytes and numeric order', function () {
    $root = layeredMapProject();
    $map = loadLayeredMap($root);
    $before = sourceHashTree($map->directory . '/layers');
    $map->duplicateTo($root . '/assets/Maps/copy', 'copy', 'Copy');
    expect(sourceHashTree($root . '/assets/Maps/copy/layers'))->toBe($before);
    $moved = $map->moveTo('district/new');
    expect($moved->mapId)->toBe('district/new')
        ->and(sourceHashTree($moved->directory . '/layers'))->toBe($before)
        ->and(is_dir($map->directory))->toBeFalse()
        ->and(is_file($moved->directory . '/new.map.php'))->toBeFalse();
});

it('refuses an executable unchanged or removed layer before save duplicate or move without executing it', function (string $action) {
    $root = layeredMapProject();
    $map = loadLayeredMap($root);
    $map->removeLayer('map:7');
    $path = $map->directory . '/layers/07.detail.deco.php';
    file_put_contents($path, "<?php file_put_contents(" . var_export($root . '/executed', true) . ", 'yes'); return 'bad';");
    $before = sourceHashTree($root);
    expect(fn() => match ($action) {
        'save' => $map->save(),
        'duplicate' => $map->duplicateTo($root . '/assets/Maps/copy', 'copy', 'Copy'),
        'move' => $map->moveTo('new'),
    })->toThrow(MapSourceRefusal::class, '07.detail.deco.php');
    expect(sourceHashTree($root))->toBe($before)->and(is_file($root . '/executed'))->toBeFalse();
})->with(['save', 'duplicate', 'move']);

it('isolates a malformed layer and refuses mismatched dimensions before evaluating data', function () {
    $root = layeredMapProject();
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/layers/07.detail.deco.php', MapGridSource::buildSource('too short', 'BAD'));
    file_put_contents($directory . '/test-map.data.php', "<?php file_put_contents(" . var_export($root . '/executed', true) . ", 'yes'); return [];");
    ProjectMap::createBlank($root . '/assets/Maps/healthy', 'healthy', 'Healthy');
    $workspace = ProjectWorkspace::fromProject($root);
    expect($workspace->maps)->toHaveCount(2)
        ->and($workspace->maps[1]->getGridSourceIssue())->toContain('07.detail.deco.php')
        ->and($workspace->maps[0]->getGridSourceIssue())->toBeNull()
        ->and(is_file($root . '/executed'))->toBeFalse();
});

it('rejects externally changed or newly added layers without overwriting author work', function (bool $added) {
    $map = loadLayeredMap(layeredMapProject());
    $path = $map->directory . '/layers/' . ($added ? '09.other.map.php' : '04.buildings.map.php');
    file_put_contents($path, MapGridSource::buildSource("new \n    ", 'OTHER'));
    $map->setLayerCell('map:1', 0, 0, 'X');
    $before = sourceHashTree($map->directory);
    expect(fn() => $map->save())->toThrow(MapSourceRefusal::class)
        ->and(sourceHashTree($map->directory))->toBe($before);
})->with([false, true]);

it('converts legacy only when a layer is created and undo restores the original file shape', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Maps/test-map/test-map.map.php';
    file_put_contents($path, str_replace(['<blue>', '</blue>'], ['<fg=blue>', '</>'], file_get_contents($path)));
    $map = authoredMap($root);
    $before = $map->captureLayerSnapshot();
    $source = file_get_contents($map->mapPath);
    $map->createLayer('buildings');
    $map->save();
    expect(is_file($map->mapPath))->toBeFalse()
        ->and(file_get_contents($map->directory . '/layers/00.terrain.map.php'))->toBe($source);
    $map->restoreLayerSnapshot($before);
    $map->save();
    expect(file_get_contents($map->mapPath))->toBe($source)
        ->and(authoredMap($root)->isLegacyMap())->toBeTrue();
});

it('rejects decoration glyphs without crops and reports normalized layer-specific crop tables', function () {
    $map = loadLayeredMap(layeredMapProject());
    expect($map->getLayerTiles2d('map:4')['asset'])->toBe('Graphics/Tilesets/shared.png')
        ->and($map->getLayerTiles2d('map:7')['asset'])->toBe('Graphics/Tilesets/detail.png')
        ->and($map->getLayerTiles2d('map:1'))->toBe([]);
    $before = sourceHashTree($map->directory);
    $map->setLayerCell('map:7', 2, 0, '?');
    expect(fn() => $map->save())->toThrow(InvalidArgumentException::class, 'no crop mapping')
        ->and(sourceHashTree($map->directory))->toBe($before);
});

it('composes terminal preview with the exact shared Engine style and transparency semantics', function () {
    $root = layeredMapProject();
    $path = $root . '/assets/Maps/test-map/layers/04.buildings.map.php';
    file_put_contents($path, MapGridSource::buildSource("<fg=red;bg=blue;options=bold> /  </>\n<fg=cyan> xx </>", 'STYLE'));
    $map = loadLayeredMap($root);
    $expected = array_map(static fn(array $row): string => rtrim(implode('', $row)), $map->getLayerSet()->getComposedGrid());
    expect($map->renderPreview(4, 2, showNpcOverlay: true, terminalPreview: true))->toBe($expected);
    $map->setLayerCell('map:4', 2, 0, 'x', '<fg=red;bg=blue;options=bold>', '</>');
    $expected = array_map(static fn(array $row): string => rtrim(implode('', $row)), $map->getLayerSet()->getComposedGrid());
    expect($map->renderPreview(4, 2, terminalPreview: true))->toBe($expected);
});

it('converts a legacy flat crop table without losing authored comments expressions or crops', function () {
    $root = makeTemporaryProject();
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/test-map.map.php', MapGridSource::buildSource("xx\nx ", 'MAP'));
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource("  \n  ", 'EVENT'));
    $source = <<<'PHP'
<?php
$cropWidth = 8 * 2;
return [
    'name' => 'Test Map',
    // Preserve the complete table, not merely its evaluated value.
    'tiles2d' => [
        'asset' => 'Graphics/Tilesets/shared.png',
        // An author-owned expression.
        'symbols' => ['x' => ['x' => 0, 'y' => 0, 'width' => $cropWidth, 'height' => 16]],
    ],
];
PHP;
    file_put_contents($directory . '/test-map.data.php', $source);
    $map = authoredMap($root);
    $before = $map->captureLayerSnapshot();
    $crops = $map->getLayerTiles2d('tile');
    $map->createLayer('buildings');
    $after = $map->captureLayerSnapshot();
    $map->save();
    $converted = str_replace("'tiles2d' => [", "'tiles2d' => ['layers' => ['terrain' => [", $source);
    $converted = str_replace("    ],\n];", "    ]]],\n];", $converted);
    expect(file_get_contents($map->dataPath))->toBe($converted)
        ->and(authoredMap($root)->getLayerTiles2d('map:0'))->toBe($crops);
    $map->restoreLayerSnapshot($before);
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe($source);
    $map->restoreLayerSnapshot($after);
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe($converted);
});

it('restores removed crop-table source and layer bytes through undo after save', function () {
    $root = layeredMapProject();
    $map = loadLayeredMap($root);
    $before = $map->captureLayerSnapshot();
    $files = sourceHashTree($map->directory);
    $map->removeLayer('map:7');
    $map->save();
    $map->restoreLayerSnapshot($before);
    $map->save();
    expect(sourceHashTree($map->directory))->toBe($files);
    $map->renameLayer('map:7', 'floors');
    $map->save();
    expect(file_get_contents($map->dataPath))->toContain("'floors' => ['asset' =>");
});

it('validates combined named and flat collision fallback through the shared resolver', function () {
    $root = layeredMapProject();
    file_put_contents($root . '/assets/Maps/collisions.php', <<<'PHP'
<?php
use Ichiloto\Engine\Events\Enumerations\CollisionType;
return ['.' => CollisionType::NONE, 'x' => CollisionType::PASS_THROUGH,
    'buildings' => ['/' => CollisionType::NONE]];
PHP);
    $map = loadLayeredMap($root);
    $map->validateLayerContracts();
    $dictionary = require $root . '/assets/Maps/collisions.php';
    expect(Ichiloto\Engine\Field\MapCollisionResolver::resolveLayers($map->getLayerSet(), $dictionary))->toBe([[0, 0, 0, 0], [0, 0, 0, 0]]);
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php return ['detail' => []];");
    expect(fn() => $map->validateLayerContracts())->toThrow(InvalidArgumentException::class, 'Decoration');
});

it('rolls every layered source back when a move changes relative data evaluation', function () {
    $root = layeredMapProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($path, str_replace("'region' => ''", "'region' => basename(__DIR__)", file_get_contents($path)));
    $map = loadLayeredMap($root);
    $before = sourceHashTree($root);
    expect(fn() => $map->moveTo('district/new'))->toThrow(RuntimeException::class, 'rolled back')
        ->and(sourceHashTree($root))->toBe($before)
        ->and(is_dir($root . '/assets/Maps/district'))->toBeFalse();
});

it('keeps raw ANSI styling separate from editable glyphs and preserves untouched source rows', function (string $prefix, string $color) {
    $root = layeredMapProject();
    $path = $root . '/assets/Maps/test-map/layers/04.buildings.map.php';
    $source = MapGridSource::buildSource($prefix . " /  \033[0m\n xx ", 'ANSI');
    file_put_contents($path, $source);
    $map = loadLayeredMap($root);
    expect($map->getLayerSymbol('map:4', 1, 0))->toBe('/')
        ->and($map->getLayerColor('map:4', 1, 0))->toBe($color);
    $map->setLayerCell('map:4', 0, 1, '|');
    $map->save();
    expect(file_get_contents($path))->toBe(str_replace(' xx ', '|xx ', $source));
})->with([
    ["\033[31m", 'red'], ["\033[38;2;12;34;56m", '#0c2238'],
    ["\033[38;5;196m", '#ff0000'], ["\033[38;5;232m", '#080808'],
]);
