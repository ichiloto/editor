<?php

declare(strict_types=1);

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\MapGraphicsValidator;
use Ichiloto\Editor\Validation\MapValidator;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * A map's graphics/ tile layers belong to the GUI editor: the TUI never
 * paints or displays them, but carries them intact through resize,
 * duplicate, move and delete, and validates them as the Engine reads them.
 */

/** @return list<string> The graphics issues reported for the test map. */
function graphicsIssueLines(string $root, ?Severity $severity = null): array
{
    $issues = array_filter(
        MapGraphicsValidator::validate(loadLayeredMap($root)),
        static fn(Issue $issue): bool => $issue->where === 'test-map' && ($severity === null || $issue->severity === $severity),
    );

    return array_values(array_map(static fn(Issue $issue): string => $issue->message, $issues));
}

// -- Resize -------------------------------------------------------------------

it('resizes every tile layer with the map, cropping and padding with empty tiles', function () {
    $root = mapGraphicsProject();
    $map = loadLayeredMap($root);
    $floor = $map->directory . '/graphics/01.floor.tiles.php';
    $decor = $map->directory . '/graphics/02.decor.tiles.php';

    $map->resize(6, 3);
    $map->save();

    expect(file_get_contents($floor))->toBe("<?php\n\n// Painted in the GUI editor.\nreturn <<<'TILES'\n"
        . "2816 2816 2816 2816 0 0\n2816 2816 2816 2816 0 0\n0 0 0 0 0 0\nTILES;\n")
        ->and(readTileRows($decor))->toBe([[0, 5, 0, 0, 0, 0], [0, 0, 0, 5, 0, 0], [0, 0, 0, 0, 0, 0]]);

    $map->resize(3, 1);
    $map->save();
    $reloaded = loadLayeredMap($root);
    $graphics = MapGraphics::loadFromDirectory($reloaded->directory, 'test-map', 'home', $reloaded->getLayerSet(), $root . '/assets');

    expect(readTileRows($floor))->toBe([[2816, 2816, 2816]])
        ->and(readTileRows($decor))->toBe([[0, 5, 0]])
        ->and(array_map(static fn($layer): array => $layer->tiles, $graphics?->layers ?? []))->toBe([[[2816, 2816, 2816]], [[0, 5, 0]]]);
});

it('keeps named tile halves when a resize rewrites a tile layer', function () {
    $root = mapGraphicsProject();
    $map = loadLayeredMap($root);
    $furniture = writeTileLayer($map->directory, '03.furniture.tiles.php', "0 5L 5R 0\n5 0 0 0");
    $map = loadLayeredMap($root);

    $map->resize(5, 1);
    $map->save();

    expect(file_get_contents($furniture))->toContain("0 5L 5R 0 0\nTILES;");
});

it('resizes a ragged map\'s tile rows to the widths its terminal rows take', function () {
    $root = mapGraphicsProject(ragged: true);
    $map = loadLayeredMap($root);

    $map->resize(5, 2);
    $map->save();
    $reloaded = loadLayeredMap($root);

    expect(readTileRows($map->directory . '/graphics/01.floor.tiles.php'))->toBe([[2816, 2816, 2816, 2816, 0], [2816, 2816, 0, 0, 0]])
        ->and(readTileRows($map->directory . '/graphics/02.decor.tiles.php'))->toBe([[0, 5, 0, 0, 0], [0, 5, 0, 0, 0]])
        ->and(MapGraphics::loadFromDirectory($reloaded->directory, 'test-map', 'home', $reloaded->getLayerSet(), $root . '/assets'))
        ->not->toBeNull();
});

it('undoes a resize back to the authored tile layer bytes', function () {
    $root = mapGraphicsProject();
    [$editor, $map] = layeredCanvasEditor($root);
    $before = sourceHashTree($map->directory);
    $sources = $map->getTileLayerSources();
    callEditorMethod($editor, 'setEditingMode', 'map');

    callEditorMethod($editor, 'applyInspectorFieldValue', ['label' => '  X', 'value' => '4', 'target' => 'map-size', 'field' => 'width'], '6');
    expect($map->getWidth())->toBe(6)
        ->and($map->getTileLayerSources())->not->toBe($sources);

    callEditorMethod($editor, 'performUndo');
    expect($map->getWidth())->toBe(4)
        ->and($map->getTileLayerSources())->toBe($sources);

    $map->save();
    expect(sourceHashTree($map->directory))->toBe($before);
});

it('keeps a tile layer\'s authored bytes when a resize returns it to its saved size', function () {
    $root = mapGraphicsProject();
    $map = loadLayeredMap($root);
    $before = sourceHashTree($map->directory . '/graphics');

    $map->resize(6, 3);
    $map->resize(4, 2);

    expect($map->getTileLayerSources())->toBe(array_map(file_get_contents(...), array_combine(
        array_keys($map->getTileLayerSources()), array_keys($map->getTileLayerSources()),
    )));
    $map->save();
    expect(sourceHashTree($map->directory . '/graphics'))->toBe($before);
});

it('refuses a resize while a tile layer cannot be read, changing nothing', function (string $body) {
    $root = mapGraphicsProject();
    writeTileLayer($root . '/assets/Maps/test-map', '02.decor.tiles.php', $body);
    $map = loadLayeredMap($root);
    $sources = $map->getTileLayerSources();

    expect(fn() => $map->resize(6, 3))->toThrow(MapSourceRefusal::class, '02.decor.tiles.php');
    expect($map->getWidth())->toBe(4)
        ->and($map->getHeight())->toBe(2)
        ->and($map->getTileLayerSources())->toBe($sources)
        ->and($map->isDirty())->toBeFalse();
})->with([
    'invalid identity' => ["0 5 0 abc\n0 0 0 5"],
    'short row' => ["0 5 0\n0 0 0 5"],
    'missing row' => ['0 5 0 0'],
]);

it('carries graphics untouched through ordinary edits, and never lets an unreadable one block a save', function () {
    $root = mapGraphicsProject();
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/graphics/02.decor.tiles.php', "<?php\n\nreturn strtoupper('not literal');\n");
    $map = loadLayeredMap($root);
    $before = sourceHashTree($directory . '/graphics');

    $map->setLayerCell('map:1', 0, 0, 'X');
    $map->setMapField('description', 'Edited in the TUI.');
    $map->save();

    expect(sourceHashTree($directory . '/graphics'))->toBe($before);
});

it('refuses to save over tile layers changed or added after opening', function (bool $added) {
    $root = mapGraphicsProject();
    $map = loadLayeredMap($root);
    writeTileLayer($map->directory, $added ? '03.shadows.tiles.php' : '02.decor.tiles.php', "0 0 0 0\n0 0 0 0");
    $map->resize(6, 3);
    $before = sourceHashTree($map->directory);

    expect(fn() => $map->save())->toThrow(MapSourceRefusal::class, 'after opening')
        ->and(sourceHashTree($map->directory))->toBe($before);
})->with(['changed' => false, 'added' => true]);

// -- Duplicate, move and delete -------------------------------------------------

it('duplicates a map with its graphics, including an unsaved resize', function () {
    $root = mapGraphicsProject();
    $workspace = ProjectWorkspace::fromProject($root);
    $index = array_search('test-map', $workspace->mapIds, true);
    $before = sourceHashTree($root . '/assets/Maps/test-map/graphics');

    $workspace->duplicateMap($index);
    expect(sourceHashTree($root . '/assets/Maps/test-map-copy/graphics'))->toBe($before);

    $map = $workspace->getMapByIndex($index);
    $map->resize(5, 2);
    $map->duplicateTo($root . '/assets/Maps/wider', 'wider', 'Wider');
    expect(readTileRows($root . '/assets/Maps/wider/graphics/01.floor.tiles.php'))->toBe([[2816, 2816, 2816, 2816, 0], [2816, 2816, 2816, 2816, 0]])
        ->and(sourceHashTree($root . '/assets/Maps/test-map/graphics'))->toBe($before, 'the original stays unsaved');
});

it('moves a map with its graphics and leaves no folder behind', function () {
    $root = mapGraphicsProject();
    $map = loadLayeredMap($root);
    $before = sourceHashTree($map->directory . '/graphics');

    $moved = $map->moveTo('district/new');

    expect(sourceHashTree($moved->directory . '/graphics'))->toBe($before)
        ->and($moved->getTileLayerSources())->toHaveCount(2)
        ->and(is_dir($map->directory))->toBeFalse();
});

it('carries a tile layer the Engine cannot read byte for byte', function () {
    $root = mapGraphicsProject();
    file_put_contents($root . '/assets/Maps/test-map/graphics/02.decor.tiles.php', "<?php\n\nreturn strtoupper('not literal');\n");
    $map = loadLayeredMap($root);
    $before = sourceHashTree($map->directory . '/graphics');

    $map->duplicateTo($root . '/assets/Maps/copy', 'copy', 'Copy');
    $moved = $map->moveTo('district/new');

    expect(sourceHashTree($root . '/assets/Maps/copy/graphics'))->toBe($before)
        ->and(sourceHashTree($moved->directory . '/graphics'))->toBe($before)
        ->and(is_dir($map->directory))->toBeFalse();
});

it('rolls a failed move back with its graphics in place', function () {
    $root = mapGraphicsProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($path, str_replace("'region' => ''", "'region' => basename(__DIR__)", (string) file_get_contents($path)));
    $map = loadLayeredMap($root);
    $before = sourceHashTree($root);

    expect(fn() => $map->moveTo('district/new'))->toThrow(RuntimeException::class, 'rolled back')
        ->and(sourceHashTree($root))->toBe($before)
        ->and(is_dir($root . '/assets/Maps/test-map/graphics'))->toBeTrue()
        ->and(is_dir($root . '/assets/Maps/district'))->toBeFalse();
});

it('deletes a map\'s graphics with it, keeping the author\'s other files', function (bool $withNotes) {
    $root = mapGraphicsProject();
    $notes = $root . '/assets/Maps/test-map/graphics/palette-notes.txt';
    if ($withNotes) {
        file_put_contents($notes, "Warm floor.\n");
    }
    $workspace = ProjectWorkspace::fromProject($root);

    $workspace->deleteMap(array_search('test-map', $workspace->mapIds, true));

    expect(glob($root . '/assets/Maps/test-map/graphics/*.tiles.php') ?: [])->toBe([])
        ->and(is_file($notes))->toBe($withNotes)
        ->and(is_dir($root . '/assets/Maps/test-map'))->toBe($withNotes);
})->with(['empty folder' => false, 'author notes' => true]);

it('restores every member when deleting a tile layer fails', function () {
    $root = mapGraphicsProject();
    $workspace = ProjectWorkspace::fromProject($root);
    $before = sourceHashTree($root . '/assets/Maps/test-map');
    $failing = new FailingFileSetOperations(failures: ['remove' => [$root . '/assets/Maps/test-map/graphics/02.decor.tiles.php']]);

    expect(fn() => $workspace->deleteMap(array_search('test-map', $workspace->mapIds, true), $failing))
        ->toThrow(FileSetTransactionFailure::class)
        ->and(sourceHashTree($root . '/assets/Maps/test-map'))->toBe($before);
});

// -- Map data -----------------------------------------------------------------

it('names a tileset through the source-preserving map data path', function () {
    $root = layeredMapProject();
    writeTestTileset($root);
    $map = loadLayeredMap($root);

    $map->setMapDataField(['tileset'], 'home');
    $map->save();

    expect((string) file_get_contents($map->dataPath))->toContain('// Authored metadata remains authored.')
        ->and(loadLayeredMap($root)->getMapDataField(['tileset']))->toBe('home')
        ->and(graphicsIssueLines($root))->toBe([]);
});

// -- Validation ---------------------------------------------------------------

it('accepts valid graphics without issues', function () {
    expect(graphicsIssueLines(mapGraphicsProject()))->toBe([]);
});

it('reports graphics the Engine refuses as errors', function (Closure $breakGraphics, string $expected) {
    $root = mapGraphicsProject();
    $breakGraphics($root, $root . '/assets/Maps/test-map');

    expect(implode("\n", graphicsIssueLines($root, Severity::ERROR)))->toContain($expected);
})->with([
    'missing tileset file' => [function (string $root): void {
        unlink($root . '/assets/Data/Tilesets/home.php');
    }, 'Tileset home was not found at Data/Tilesets/home.php.'],
    'invalid tileset' => [function (string $root): void {
        file_put_contents($root . '/assets/Data/Tilesets/home.php', "<?php\n\nreturn ['name' => 'Home', 'sheets' => ['Z9' => 'x.png']];\n");
    }, "Tileset home: 'Z9' is not an RPG Maker sheet"],
    'graphics without a tileset' => [function (string $root, string $directory): void {
        $data = $directory . '/test-map.data.php';
        file_put_contents($data, str_replace(" 'tileset' => 'home',", '', (string) file_get_contents($data)));
    }, 'It has graphics/ but names no tileset.'],
    'badly named file' => [function (string $root, string $directory): void {
        writeTileLayer($directory, 'floor.tiles.php', "0 0 0 0\n0 0 0 0");
    }, 'Tile layer test-map/graphics/floor.tiles.php must be named NN.name.tiles.php.'],
    'duplicate order' => [function (string $root, string $directory): void {
        writeTileLayer($directory, '02.shadows.tiles.php', "0 0 0 0\n0 0 0 0");
    }, 'Tile layer test-map/graphics/02.shadows.tiles.php repeats order 02.'],
    'rows' => [function (string $root, string $directory): void {
        writeTileLayer($directory, '02.decor.tiles.php', '0 0 0 0');
    }, 'Tile layer test-map/graphics/02.decor.tiles.php must have 2 rows.'],
    'cells' => [function (string $root, string $directory): void {
        writeTileLayer($directory, '02.decor.tiles.php', "0 0 0 0\n0 0 0");
    }, 'Tile layer test-map/graphics/02.decor.tiles.php row 1 must be 4 cells wide.'],
    'invalid identity' => [function (string $root, string $directory): void {
        writeTileLayer($directory, '02.decor.tiles.php', "0 0 0 0\n0 0 0 9999");
    }, "Tile layer test-map/graphics/02.decor.tiles.php row 1, cell 3: '9999' is not an RPG Maker tile identity."],
    'executable source' => [function (string $root, string $directory): void {
        file_put_contents($directory . '/graphics/02.decor.tiles.php', "<?php\n\nreturn strtoupper('x');\n");
    }, 'must return one literal nowdoc string without executable code'],
]);

it('warns for unusable sheets and for tiles from sheets the tileset does not provide', function () {
    $root = mapGraphicsProject();
    writeTilesetTestPng($root . '/assets/Graphics/Tilesets/Home_A2.png', 30, 24);
    file_put_contents($root . '/assets/Graphics/Tilesets/Home_B.png', 'not a png');
    writeTileLayer($root . '/assets/Maps/test-map', '02.decor.tiles.php', "0 300 0 0\n0 0 1536 5");

    $warnings = graphicsIssueLines($root, Severity::WARNING);

    expect($warnings)->toBe([
        'Tileset home sheet A2 is unusable: Graphics/Tilesets/Home_A2.png is 30x24, which does not fit an A2 sheet of 16 x 12 tiles in one even tile size shared by every sheet (768x576 at 48 pixels).',
        'Tileset home sheet B is unusable: Invalid PNG header: Graphics/Tilesets/Home_B.png',
        'Tile layer test-map/graphics/02.decor.tiles.php uses sheets A5, C, which tileset home does not provide.',
    ])->and(graphicsIssueLines($root, Severity::ERROR))->toBe([]);
});

it('includes graphics problems in the pre-save map warnings, unsaved resizes included', function () {
    $root = mapGraphicsProject();
    $map = loadLayeredMap($root);
    $map->setMapDataField(['tileset'], 'missing');

    expect(MapValidator::validate($map, ['test-map' => $map]))->toContain('Tileset missing was not found at Data/Tilesets/missing.php.');

    $map->setMapDataField(['tileset'], 'home');
    $map->resize(6, 3);
    expect(MapGraphicsValidator::validate($map))->toBe([]);
});

it('reports graphics through project validation, as ichiloto validate runs it', function () {
    $root = mapGraphicsProject();
    unlink($root . '/assets/Data/Tilesets/home.php');

    $issues = array_filter(
        new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn(Issue $issue): bool => $issue->where === 'test-map' && $issue->severity === Severity::ERROR
            && $issue->message === 'Tileset home was not found at Data/Tilesets/home.php.',
    );

    expect($issues)->toHaveCount(1)
        ->and(array_values($issues)[0]->hint)->toContain('terminal glyphs');
});
