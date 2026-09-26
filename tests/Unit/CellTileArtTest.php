<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapGridSource;

function createCellTileArtProject(bool $legacy = false): string
{
    $root = $legacy ? makeTemporaryProject('ichiloto-cell-art-') : layeredMapProject(true);
    mkdir($root . '/assets/Graphics/Tilesets', 0777, true);
    $chunk = static fn(string $kind, string $bytes): string => pack('N', strlen($bytes)) . $kind . $bytes . pack('N', crc32($kind . $bytes));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNC5', 64, 64, 8, 6, 0, 0, 0))
        . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xff\xff\xff\xff", 64), 64))) . $chunk('IEND', '');
    foreach (['shared', 'replacement', 'detail'] as $name) {
        file_put_contents($root . '/assets/Graphics/Tilesets/' . $name . '.png', $png);
    }
    file_put_contents($root . '/assets/Graphics/Tilesets/not-art.txt', 'not art');
    if ($legacy) {
        file_put_contents($root . '/assets/Maps/test-map/test-map.map.php', MapGridSource::buildSource("xx  \nx ", 'TILES'));
        file_put_contents($root . '/assets/Maps/test-map/test-map.event.php', MapGridSource::buildSource("    \n  ", 'EVENTS'));
        file_put_contents($root . '/assets/Maps/test-map/test-map.data.php', "<?php\nreturn ['name' => 'Legacy', 'events' => []];\n");
    }
    return $root;
}

function getCellTileArtRectangle(int $x = 16): array
{
    return ['x' => $x, 'y' => 0, 'width' => 16, 'height' => 16];
}

it('sets and removes cell crops without changing inherited symbols other layers or authored grids', function () {
    $root = createCellTileArtProject();
    $map = loadLayeredMap($root);
    $before = $map->captureLayerSnapshot();
    $files = sourceHashTree($root . '/assets/Maps');
    $map->setCellTileArt('map:4', 1, 0, getCellTileArtRectangle());
    $map->setCellTileArt('map:4', 0, 1, getCellTileArtRectangle(32));
    $after = $map->captureLayerSnapshot();
    expect($map->getCellTileArt('map:4', 0, 1)['override'])->toBe(getCellTileArtRectangle(32))
        ->and($map->getCellTileArt('map:4', 0, 1)['inherited'])->toBe(getCellTileArtRectangle(0))
        ->and($map->getCellTileArt('map:4', 1, 1)['override'])->toBeNull()
        ->and($map->getEditableData()['tiles2d']['layers']['detail'])->toBe($before['data']['tiles2d']['layers']['detail'])
        ->and($map->getEditableData()['tiles2d']['layers']['buildings']['symbols'])->toBe($before['data']['tiles2d']['layers']['buildings']['symbols'])
        ->and($map->captureGridSnapshot())->toBe($before['grid']);
    $map->save();
    expect(array_keys(array_diff_assoc(sourceHashTree($root . '/assets/Maps'), $files)))->toBe(['test-map/test-map.data.php']);
    $savedSource = file_get_contents($map->dataPath);
    $map->restoreLayerSnapshot($before);
    $map->save();
    expect(sourceHashTree($root . '/assets/Maps'))->toBe($files);
    $map->restoreLayerSnapshot($after);
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe($savedSource);
    $map = loadLayeredMap($root);
    $map->removeCellTileArt('map:4', 0, 1);
    expect($map->getCellTileArt('map:4', 0, 1)['override'])->toBeNull()
        ->and($map->getCellTileArt('map:4', 0, 1)['inherited'])->toBe(getCellTileArtRectangle(0))
        ->and($map->getCellTileArt('map:4', 1, 0)['override'])->toBe(getCellTileArtRectangle());
    $map->removeCellTileArt('map:4', 1, 0);
    $map->save();
    expect($map->getEditableData())->toBe($before['data']);
});

it('creates cells-only art on blank cells and prunes only the final empty definition', function (bool $legacy) {
    $root = createCellTileArtProject($legacy);
    $map = loadLayeredMap($root);
    $id = $map->getBaseLayerId();
    $before = $map->captureLayerSnapshot();
    $map->setCellTileArt($id, 3, 0, getCellTileArtRectangle(), 'Graphics/Tilesets/shared.png');
    $map->save();
    $map = loadLayeredMap($root);
    expect($map->getCellTileArt($id, 3, 0)['override'])->toBe(getCellTileArtRectangle())
        ->and($map->isLegacyMap())->toBe($legacy)
        ->and($map->captureGridSnapshot())->toBe($before['grid']);
    $map->removeCellTileArt($id, 3, 0);
    $map->save();
    expect($map->getEditableData())->toBe($before['data']);
})->with([false, true]);

it('refuses out-of-row coordinates events invalid rectangles and unpicked asset paths before mutation', function (string $id, int $x, int $y, array $rect, ?string $asset) {
    $root = createCellTileArtProject();
    $map = loadLayeredMap($root);
    $before = $map->captureLayerSnapshot();
    $files = sourceHashTree($root);
    expect(fn() => $map->setCellTileArt($id, $x, $y, $rect, $asset))->toThrow(Exception::class)
        ->and($map->captureLayerSnapshot())->toBe($before)
        ->and(sourceHashTree($root))->toBe($files)
        ->and($map->isDirty())->toBeFalse();
})->with([
    ['map:4', 2, 1, getCellTileArtRectangle(), null],
    ['map:4', -1, 0, getCellTileArtRectangle(), null],
    ['event', 0, 0, getCellTileArtRectangle(), null],
    ['map:4', 0, 0, ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 16], null],
    ['map:4', 0, 0, ['x' => -1, 'y' => 0, 'width' => 16, 'height' => 16], null],
    ['map:4', 0, 0, ['x' => 60, 'y' => 0, 'width' => 16, 'height' => 16], null],
    ['map:4', 0, 0, ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 16, 'extra' => true], null],
    ['map:4', 0, 0, ['x' => 0, 'y' => 0, 'width' => 16], null],
    ['map:4', 0, 0, getCellTileArtRectangle(), '../outside.png'],
    ['map:4', 0, 0, getCellTileArtRectangle(), 'Graphics/Tilesets/not-art.txt'],
]);

it('refuses duplicate or out-of-bounds loaded overrides rather than silently normalizing them', function (bool $duplicate) {
    $root = createCellTileArtProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = file_get_contents($path);
    $cell = ['column' => $duplicate ? 0 : 2, 'row' => 1, 'source' => getCellTileArtRectangle()];
    $source = str_replace("'buildings' => [", "'buildings' => ['cells' => " . var_export($duplicate ? [$cell, $cell] : [$cell], true) . ', ', $source);
    file_put_contents($path, $source);
    $map = loadLayeredMap($root);
    $before = $map->getEditableData();
    expect(fn() => $map->setCellTileArt('map:4', 0, 0, getCellTileArtRectangle()))->toThrow(InvalidArgumentException::class)
        ->and($map->getEditableData())->toBe($before)
        ->and(file_get_contents($path))->toBe($source)
        ->and($map->isDirty())->toBeFalse();
})->with([false, true]);

it('refuses opaque tile-art containers but leaves unrelated executable data alone', function (string $shape) {
    $root = createCellTileArtProject(true);
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $cell = "['column' => 0, 'row' => 0, 'source' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 16]]";
    $literal = "['asset' => 'Graphics/Tilesets/shared.png', 'cells' => [$cell]]";
    $source = match ($shape) {
        'variable' => "<?php \$art = $literal; return ['tiles2d' => \$art];",
        'expression' => "<?php return ['tiles2d' => array_replace([], $literal)];",
        'cells' => "<?php \$cells = [$cell]; return ['tiles2d' => ['asset' => 'Graphics/Tilesets/shared.png', 'cells' => \$cells]];",
        'key' => "<?php \$key = 'cells'; return ['tiles2d' => ['asset' => 'Graphics/Tilesets/shared.png', \$key => [$cell]]];",
        'explicit index' => "<?php return ['tiles2d' => ['asset' => 'Graphics/Tilesets/shared.png', 'cells' => [0 => $cell]]];",
    };
    file_put_contents($path, $source);
    $map = loadLayeredMap($root);
    $before = $map->captureLayerSnapshot();
    expect(fn() => $map->setCellTileArt('tile', 0, 0, getCellTileArtRectangle()))->toThrow(MapSourceRefusal::class)
        ->and(fn() => $map->removeCellTileArt('tile', 0, 0))->toThrow(MapSourceRefusal::class)
        ->and($map->captureLayerSnapshot())->toBe($before)
        ->and(file_get_contents($path))->toBe($source);
})->with(['variable', 'expression', 'cells', 'key', 'explicit index']);

it('edits a literal crop leaf surgically without rewriting symbol expressions or neighboring comments', function () {
    $root = createCellTileArtProject(true);
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = <<<'PHP'
<?php
// Global expression stays executable.
return ['name' => strtoupper('legacy'), 'tiles2d' => [
    'asset' => 'Graphics/Tilesets/shared.png',
    'symbols' => array_replace([], ['x' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 16]]),
    'cells' => [
        // Selected cell keeps this heading.
        ['column' => 0, 'row' => 0, 'source' => ['x' => 8 /* crop start */, 'y' => 0, 'width' => 16, 'height' => 16]],
        // Unrelated cell keeps its heading and spacing.
        ['column'=>1, 'row'=>0, 'source'=>['x'=>0, 'y'=>0, 'width'=>16, 'height'=>16]],
    ],
]];
PHP;
    file_put_contents($path, $source);
    $map = loadLayeredMap($root);
    $map->setCellTileArt('tile', 0, 0, getCellTileArtRectangle(32));
    $map->save();
    expect(file_get_contents($path))->toBe(str_replace('8 /* crop start */', '32 /* crop start */', $source));
});

it('retains authored cell and rectangle key order for exact transaction verification and no-op edits', function () {
    $root = createCellTileArtProject(true);
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = <<<'PHP'
<?php
return ['tiles2d' => ['asset' => 'Graphics/Tilesets/shared.png', 'cells' => [
    ['source' => ['width' => 16, 'height' => 16, 'y' => 0, 'x' => 16], 'row' => 0, 'column' => 0],
]]];
PHP;
    file_put_contents($path, $source);
    $map = loadLayeredMap($root);
    $before = $map->getEditableData();
    expect(fn() => $map->setCellTileArt('tile', 0, 0, ['x' => 0, 'y' => 0, 'width' => 16]))
        ->toThrow(InvalidArgumentException::class, 'height');
    $map->setCellTileArt('tile', 0, 0, getCellTileArtRectangle());
    expect($map->getEditableData())->toBe($before)->and($map->isDirty())->toBeFalse();
    $map->setCellTileArt('tile', 0, 0, getCellTileArtRectangle(32));
    $map->save();
    expect(file_get_contents($path))->toBe(str_replace("'x' => 16", "'x' => 32", $source));
});

it('preserves existing cell crops and graphical assets through terminal painting undo redo save and reload', function (bool $legacy) {
    $root = createCellTileArtProject($legacy);
    $map = loadLayeredMap($root);
    $id = $legacy ? 'tile' : 'map:4';
    $map->setCellTileArt($id, 0, 0, getCellTileArtRectangle(32), 'Graphics/Tilesets/shared.png');
    $map->save();
    $source = file_get_contents($map->dataPath);
    $assets = sourceHashTree($root . '/assets/Graphics');
    $layers = sourceHashTree($map->directory);
    [$editor, $map] = layeredCanvasEditor($root);
    callEditorMethod($editor, 'selectCanvasLayer', $id);
    $oldSymbol = $map->getLayerSymbol($id, 0, 0);
    foreach (['i', 'Z', "\033", "\x13"] as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    expect($map->getLayerSymbol($id, 0, 0))->toBe('Z')
        ->and(file_get_contents($map->dataPath))->toBe($source)
        ->and(renderEditorPlainFrame($editor, 160, 45))->not->toContain('Crop mapping', 'cell overrides', 'Tile art');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect($map->getLayerSymbol($id, 0, 0))->toBe($oldSymbol)
        ->and(sourceHashTree($map->directory))->toBe($layers);
    foreach (["\x19", "\x13", "\x12"] as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    $loaded = callEditorMethod($editor, 'getSelectedMap');
    expect($loaded->getLayerSymbol($id, 0, 0))->toBe('Z')
        ->and($loaded->getCellTileArt($id, 0, 0)['override'])->toBe(getCellTileArtRectangle(32))
        ->and(file_get_contents($loaded->dataPath))->toBe($source)
        ->and(sourceHashTree($root . '/assets/Graphics'))->toBe($assets);
    if (! $legacy) {
        expect(sourceHashTree($loaded->directory)['layers/07.detail.deco.php'])->toBe($layers['layers/07.detail.deco.php']);
    }
})->with([false, true]);

it('offers only project-contained PNG assets in the shared resource catalogue', function () {
    $root = createCellTileArtProject();
    copy($root . '/assets/Graphics/Tilesets/shared.png', $root . '/outside.png');
    symlink($root . '/outside.png', $root . '/assets/Graphics/Tilesets/outside.png');
    expect(ReferenceCatalog::getPngAssets($root))->toBe([
        'Graphics/Tilesets/detail.png', 'Graphics/Tilesets/replacement.png', 'Graphics/Tilesets/shared.png',
    ]);
});

it('requires atlas confirmation before mutation and rejects invalid PNGs or existing crops outside the replacement', function () {
    $root = createCellTileArtProject();
    $map = loadLayeredMap($root);
    $before = $map->captureLayerSnapshot();
    $asset = 'Graphics/Tilesets/replacement.png';
    expect(fn() => $map->setCellTileArt('map:4', 0, 1, getCellTileArtRectangle(), $asset))
        ->toThrow(MapSourceRefusal::class, 'explicit confirmation')
        ->and($map->captureLayerSnapshot())->toBe($before);
    file_put_contents($root . '/assets/' . $asset, 'not a PNG');
    expect(fn() => $map->setCellTileArt('map:4', 0, 1, getCellTileArtRectangle(), $asset, true))
        ->toThrow(RuntimeException::class, 'Invalid PNG header')
        ->and($map->captureLayerSnapshot())->toBe($before);
    // The new cell fits, but the inherited 16px symbol crop would not.
    $png = file_get_contents($root . '/assets/Graphics/Tilesets/shared.png');
    file_put_contents($root . '/assets/' . $asset, substr_replace($png, pack('NN', 8, 8), 16, 8));
    expect(fn() => $map->setCellTileArt('map:4', 0, 1, ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1], $asset, true))
        ->toThrow(RuntimeException::class, 'crop exceed image bounds')
        ->and($map->captureLayerSnapshot())->toBe($before);
});

it('keeps coordinate ownership through layer rename map duplication move removal and restoration', function () {
    $root = createCellTileArtProject();
    $map = loadLayeredMap($root);
    foreach (['map:4', 'map:7'] as $id) { $map->setCellTileArt($id, 1, 1, getCellTileArtRectangle(32)); }
    $map->save();
    $before = $map->captureLayerSnapshot();
    $files = sourceHashTree($map->directory);
    $map->renameLayer('map:4', 'furniture');
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe(str_replace("'buildings' =>", "'furniture' =>", $before['source']))
        ->and($map->getCellTileArt('map:4', 1, 1)['override'])->toBe(getCellTileArtRectangle(32));
    $map->duplicateTo($root . '/assets/Maps/copy', 'copy', 'Copy');
    $copy = ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/copy');
    expect($copy->getEditableData()['tiles2d'])->toBe($map->getEditableData()['tiles2d'])
        ->and(sourceHashTree($copy->directory . '/layers'))->toBe(sourceHashTree($map->directory . '/layers'));
    $moved = $copy->moveTo('district/copy');
    expect($moved->getEditableData()['tiles2d'])->toBe($map->getEditableData()['tiles2d'])
        ->and($moved->getCellTileArt('map:7', 1, 1)['override'])->toBe(getCellTileArtRectangle(32));
    $map->removeLayer('map:4');
    $map->save();
    expect($map->getEditableData()['tiles2d']['layers'])->not->toHaveKey('furniture')
        ->and($map->getCellTileArt('map:7', 1, 1)['override'])->toBe(getCellTileArtRectangle(32));
    $map->restoreLayerSnapshot($before);
    $map->save();
    expect(sourceHashTree($map->directory))->toBe($files);
});

it('leaves coordinates in place on resizing and revalidates cached bounds before saving', function (bool $legacy) {
    $root = createCellTileArtProject($legacy);
    $map = loadLayeredMap($root);
    $id = $map->getBaseLayerId();
    $map->setCellTileArt($id, 3, 0, getCellTileArtRectangle(), 'Graphics/Tilesets/shared.png');
    $map->setCellTileArt($id, 1, 1, getCellTileArtRectangle(32));
    $map->save();
    $original = $map->captureLayerSnapshot();
    $files = sourceHashTree($map->directory);
    $data = $map->getEditableData();
    $map->getLayerTileDefinitions();
    $map->resize(3, 2);
    expect($map->getEditableData())->toBe($data)
        ->and(fn() => $map->getLayerTileDefinitions())->toThrow(InvalidArgumentException::class, 'outside layer')
        ->and(fn() => $map->save())->toThrow(InvalidArgumentException::class, 'outside layer')
        ->and(sourceHashTree($map->directory))->toBe($files);
    $map->restoreLayerSnapshot($original);
    $map->getLayerTileDefinitions();
    $map->resize(4, 1);
    expect(fn() => $map->save())->toThrow(InvalidArgumentException::class, 'outside layer');
    $map->restoreLayerSnapshot($original);
    $map->resize(5, 3);
    $map->save();
    expect($map->getEditableData())->toBe($data)
        ->and($map->getCellTileArt($id, 3, 0)['override'])->toBe(getCellTileArtRectangle())
        ->and($map->getCellTileArt($id, 1, 1)['override'])->toBe(getCellTileArtRectangle(32));
    $map->restoreLayerSnapshot($original);
    $map->save();
    expect(sourceHashTree($map->directory))->toBe($files)
        ->and(array_map(count(...), $map->getLayerSet()->layers[0]->grid))->toBe([4, 2]);
})->with([false, true]);

it('rolls back cell metadata and changed grids together when transactional installation fails', function () {
    $root = createCellTileArtProject();
    $map = loadLayeredMap($root);
    $before = sourceHashTree($map->directory);
    $map->setCellTileArt('map:4', 0, 1, getCellTileArtRectangle(32));
    $map->setLayerCell('map:1', 0, 0, '#');
    $failure = new FailingFileSetOperations(failures: ['move' => [$map->dataPath]]);
    expect(fn() => $map->save(files: $failure))->toThrow(FileSetTransactionFailure::class)
        ->and(sourceHashTree($map->directory))->toBe($before)
        ->and($map->isDirty())->toBeTrue();
    $map->save();
    expect(loadLayeredMap($root)->getCellTileArt('map:4', 0, 1)['override'])->toBe(getCellTileArtRectangle(32));
});

it('authors coordinate-only decoration in stages while terminal preview and collision stay unchanged', function () {
    $root = createCellTileArtProject();
    $map = loadLayeredMap($root);
    $dictionary = [];
    $collision = MapCollisionResolver::resolveLayers($map->getLayerSet(), $dictionary);
    $terminal = $map->renderPreview(4, 2, terminalPreview: true);
    $id = $map->createLayer('materials', true);
    $map->setLayerCell($id, 1, 0, 'w');
    $map->setLayerCell($id, 2, 0, 'k');
    $map->setCellTileArt($id, 1, 0, getCellTileArtRectangle(), 'Graphics/Tilesets/shared.png');
    $before = sourceHashTree($map->directory);
    expect(fn() => $map->save())->toThrow(InvalidArgumentException::class, 'no crop mapping')
        ->and(sourceHashTree($map->directory))->toBe($before);
    $map->setCellTileArt($id, 2, 0, getCellTileArtRectangle(32));
    $map->save();
    $map->setLayerCell($id, 1, 0, ' ');
    expect($map->getCellTileArt($id, 1, 0)['override'])->toBe(getCellTileArtRectangle());
    $map->save();
    expect($map->renderPreview(4, 2, terminalPreview: true))->toBe($terminal)
        ->and(MapCollisionResolver::resolveLayers($map->getLayerSet(), $dictionary))->toBe($collision);
    $map->removeCellTileArt($id, 2, 0);
    expect(fn() => $map->save())->toThrow(InvalidArgumentException::class, 'no crop mapping');
});

it('reuses validated crop definitions across every paint dab and invalidates them for data and layer changes', function () {
    $map = loadLayeredMap(createCellTileArtProject());
    $map->setCellTileArt('map:4', 0, 0, getCellTileArtRectangle(32));
    $definitions = $map->getLayerTileDefinitions();
    for ($dab = 0; $dab < 20; $dab++) {
        $map->setLayerCell('map:4', 0, 0, $dab % 2 === 0 ? 'x' : ' ');
        expect($map->getLayerTileDefinitions()['buildings'])->toBe($definitions['buildings']);
    }
    $map->setCellTileArt('map:4', 0, 0, getCellTileArtRectangle());
    expect($map->getLayerTileDefinitions()['buildings'])->not->toBe($definitions['buildings']);
    $map->renameLayer('map:4', 'furniture');
    expect($map->getLayerTileDefinitions())->toHaveKey('furniture')->not->toHaveKey('buildings');
    $map->removeLayer('map:4');
    expect($map->getLayerTileDefinitions())->not->toHaveKey('furniture');
});

it('detects retained layer and grid mutations without relying on ProjectMap setters', function () {
    $root = createCellTileArtProject();
    $original = loadLayeredMap($root);
    $original->setCellTileArt('map:4', 3, 0, getCellTileArtRectangle());
    $original->save();
    $layers = \Ichiloto\Editor\Maps\MapLayers::createFromSource($original->directory, $original->getLayerSet(),
        $original->eventPath, MapGridSource::readFile($original->eventPath));
    $map = new ProjectMap($original->mapId, $original->directory, $original->dataPath, $original->mapPath,
        $original->eventPath, $original->getEditableData(), $original->tileLines, $original->eventLines,
        file_get_contents($original->dataPath), layers: $layers);
    $before = $layers->captureSnapshot();
    $map->getLayerTileDefinitions();
    $retained = $layers->getGrid('map:4');
    array_pop($retained->cells[0]);
    expect(fn() => $map->getLayerTileDefinitions())->toThrow(InvalidArgumentException::class);
    $layers->restoreSnapshot($before);
    $map->getLayerTileDefinitions();
    $layers->resize(3, 2);
    expect(fn() => $map->getLayerTileDefinitions())->toThrow(InvalidArgumentException::class, 'outside layer');
    $layers->restoreSnapshot($before);
    $map->getLayerTileDefinitions();
    $layers->renameLayer('map:4', 'furniture');
    expect(fn() => $map->getLayerTileDefinitions())->toThrow(InvalidArgumentException::class, 'unknown or malformed layer');
});

it('refuses pruning the final layer definition when it would remove an opaque shared atlas expression', function () {
    $root = createCellTileArtProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = <<<'PHP'
<?php
return ['tiles2d' => [
    'asset' => strval('Graphics/Tilesets/shared.png'),
    'layers' => ['buildings' => ['cells' => [
        ['column' => 0, 'row' => 0, 'source' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 16]],
    ]]],
]];
PHP;
    file_put_contents($path, $source);
    $map = loadLayeredMap($root);
    $before = $map->captureLayerSnapshot();
    expect(fn() => $map->removeCellTileArt('map:4', 0, 0))->toThrow(MapSourceRefusal::class, 'opaque')
        ->and($map->captureLayerSnapshot())->toBe($before)
        ->and(file_get_contents($path))->toBe($source);
});
