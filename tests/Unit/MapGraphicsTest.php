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
 * displays them or paints single tiles (it writes them only by stamping a
 * tileset piece), but carries them intact through resize, duplicate, move
 * and delete, and validates them as the Engine reads them.
 */

/** @return list<string> The graphics and coverage issues reported for the test map. */
function graphicsIssueLines(string $root, ?Severity $severity = null): array
{
    $map = loadLayeredMap($root);
    $issues = array_filter(
        [...MapGraphicsValidator::validate($map), ...MapGraphicsValidator::validateCoverage($map)],
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

it('validates tile layer offsets in the map data as the Engine reads them', function () {
    $root = mapGraphicsProject();
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = (string) file_get_contents($data);

    file_put_contents($data, str_replace("'tileset' => 'home',", "'tileset' => 'home', 'tileLayers' => ['decor' => ['offset' => [0, -0.5]]],", $source));
    expect(graphicsIssueLines($root, Severity::ERROR))->toBe([]);

    file_put_contents($data, str_replace("'tileset' => 'home',", "'tileset' => 'home', 'tileLayers' => ['rugs' => ['offset' => [0, 1]]],", $source));
    expect(graphicsIssueLines($root, Severity::ERROR))->toBe(["Map test-map tileLayers names 'rugs', which is not one of its tile layers."]);
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
    // A field cell holds one whole tile; half-tile entries are refused, not reinterpreted.
    'half tile' => ["0 5L 5R 0\n0 0 0 5"],
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
        // Named but not yet painted: every glyph still shows in the graphical field.
        ->and(graphicsIssueLines($root))->toBe(['It has no tiles yet, so all 8 of its glyph cells show in the graphical field.']);
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
    }, 'It has graphics/ but no kind.'],
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
    'half tile' => [function (string $root, string $directory): void {
        writeTileLayer($directory, '02.decor.tiles.php', "0 5L 5R 0\n0 0 0 0");
    }, "Tile layer test-map/graphics/02.decor.tiles.php row 0, cell 1: '5L' names half of tile 5, but each field cell holds one whole tile; use whole tiles, such as '5'."],
    'piece with a half tile' => [function (string $root): void {
        writeTestTileset($root, pieces: ['bed' => ['name' => 'Bed', 'layer' => 'buildings', 'glyphs' => ['=='],
            'tiles' => ['decor' => ['5L 5R']]]]);
    }, "'5L' names half of tile 5, but each field cell holds one whole tile; use whole tiles, such as '5'."],
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
        // Tiles from unusable sheets draw nothing, so their glyphs show.
        '5 cells of . on the terrain layer have no tile, so the glyph shows in the graphical field: (0, 0), (2, 0), (3, 0) and 2 more.',
        '1 cell of / on the buildings layer has no tile, so the glyph shows in the graphical field: (1, 0).',
        '2 cells of x on the buildings layer have no tile, so the glyph shows in the graphical field: (1, 1), (2, 1).',
    ])->and(graphicsIssueLines($root, Severity::ERROR))->toBe([]);
});

it('warns for glyphs no tile covers, NPCs without field sprites and copies of an NPC\'s glyph under it', function () {
    $root = mapGraphicsProject();
    $directory = $root . '/assets/Maps/test-map';
    writeTileLayer($directory, '01.floor.tiles.php', "2816 0 2816 2816\n2816 0 0 2816");
    writeTileLayer($directory, '02.decor.tiles.php', "0 0 0 0\n0 0 0 0");
    editTestMapData($root, static fn(string $source): string => str_replace("'events' => [],", "'events' => [], 'npcs' => [
        ['id' => 'guard', 'name' => 'Guard', 'sprite' => '<fg=red>@</>', 'x' => 0, 'y' => 0],
        ['id' => 'board', 'name' => 'Board', 'sprite' => '', 'x' => 3, 'y' => 0],
        ['id' => 'sign', 'name' => 'Sign', 'sprite' => 'x', 'sprites2d' => ['sheet' => 'Graphics/Characters/!\$Sign.png'], 'x' => 1, 'y' => 1],
        ['id' => 'lamp', 'name' => 'Lamp', 'sprite' => 'i', 'sprites2d' => ['sheet' => 'Graphics/Characters/!\$Lamp.png'], 'x' => 3, 'y' => 1],
        ['id' => 'cat', 'name' => 'Cat', 'sprite' => 'c', 'sprites2d' => ['sheet' => 'Graphics/Characters/\$Cat.png'], 'x' => 2, 'y' => 1],
    ],", $source));

    // The board draws no glyph, and the lamp stands on a covered cell. The
    // sign's copy is named once, for the NPC, not again among the x cells;
    // the cat stands on another glyph, an uncovered cell like any other.
    expect(graphicsIssueLines($root))->toBe([
        'NPC Guard (guard) has no field sprite, so its glyph @ shows in the graphical field.',
        'NPC Sign (sign) stands on a copy of its glyph x on the buildings layer, which shows under its sprite.',
        '1 cell of / on the buildings layer has no tile, so the glyph shows in the graphical field: (1, 0).',
        '1 cell of x on the buildings layer has no tile, so the glyph shows in the graphical field: (2, 1).',
    ])->and(graphicsIssueLines($root, Severity::ERROR))->toBe([]);
});

it('warns for tiles a piece draws whose glyph is no longer there, and only those', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: [
        'window' => ['name' => 'Window', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['decor' => ['5']]],
        'board' => ['name' => 'Board', 'layer' => 'buildings', 'glyphs' => ['/ '], 'tiles' => ['decor' => ['7 6']]],
    ]);
    // Buildings: " /  " over " xx ". The windows at (1, 1) and (2, 1), and
    // the board's / at (1, 0) with its blank cell beside it at (2, 0), are
    // accounted for; a window tile at (3, 1) has no x, and a board tile at
    // (3, 0) has no / beside it. The floor's 2816 is drawn by no piece, so
    // it is never judged.
    writeTileLayer($root . '/assets/Maps/test-map', '02.decor.tiles.php', "0 0 0 6\n0 5 5 5");
    $stale = array_values(array_filter(graphicsIssueLines($root), static fn(string $line): bool => str_contains($line, 'no longer there')));
    expect($stale)->toBe(['2 tiles on the decor tile layer are drawn by a tileset piece whose glyph is no longer there: (3, 0), (3, 1).']);

    writeTileLayer($root . '/assets/Maps/test-map', '02.decor.tiles.php', "0 7 6 0\n0 5 5 0");
    expect(array_filter(graphicsIssueLines($root), static fn(string $line): bool => str_contains($line, 'no longer there')))->toBe([]);
});

it('lists the cells showing the tileset\'s missing-art placeholder', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root, missingArt: 255);
    writeTileLayer($root . '/assets/Maps/test-map', '02.decor.tiles.php', "0 255 0 0\n0 0 255 255");
    $placeholder = static fn(): array => array_values(array_filter(graphicsIssueLines($root), static fn(string $line): bool =>
        str_contains($line, 'missing-art placeholder')));
    expect($placeholder())->toBe(['3 cells on the decor tile layer show the missing-art placeholder: (1, 0), (2, 1), (3, 1).']);

    // A tileset that names none has no placeholder to report.
    writeTestTileset($root);
    expect($placeholder())->toBe([]);
});

it('counts a tile as covering only the glyphs of the gameplay layer it belongs to', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: ['ground' => ['name' => 'Ground', 'layer' => 'terrain', 'glyphs' => ['.'], 'tiles' => ['floor' => ['2816']]]]);
    writeTileLayer($root . '/assets/Maps/test-map', '02.decor.tiles.php', "0 0 0 0\n0 0 0 0");

    // The floor belongs to terrain, so the building glyphs above it still show.
    expect(graphicsIssueLines($root))->toBe([
        '1 cell of / on the buildings layer has no tile, so the glyph shows in the graphical field: (1, 0).',
        '2 cells of x on the buildings layer have no tile, so the glyph shows in the graphical field: (1, 1), (2, 1).',
    ]);
});

it('includes graphics problems in the pre-save map warnings, unsaved resizes included', function () {
    $root = mapGraphicsProject();
    $map = loadLayeredMap($root);
    $map->setMapDataField(['tileset'], 'missing');

    expect(MapValidator::validate($map, ['test-map' => $map]))->toContain('Tileset missing was not found at Data/Tilesets/missing.php.');

    $map->setMapDataField(['tileset'], 'home');
    $map->resize(6, 3);
    expect(MapGraphicsValidator::validate($map))->toBe([]);

    // Glyphs without tiles are art still to do, not a save problem.
    writeTileLayer($root . '/assets/Maps/test-map', '01.floor.tiles.php', "0 0 0 0\n0 0 0 0");
    $map = loadLayeredMap($root);
    $coverage = array_map(static fn(Issue $issue): string => $issue->message, MapGraphicsValidator::validateCoverage($map));
    expect($coverage)->not->toBe([])
        ->and(array_intersect(MapValidator::validate($map, ['test-map' => $map]), $coverage))->toBe([]);
});

it('warns about a map without a kind only in a project that has tilesets', function () {
    $root = layeredMapProject();
    expect(MapGraphicsValidator::validate(loadLayeredMap($root)))->toBe([]);

    writeTestTileset($root, 'interior');
    $issues = MapGraphicsValidator::validate(loadLayeredMap($root));
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::WARNING)
        ->and($issues[0]->message)->toBe('It has no kind, so it has no tiles or pieces.')
        ->and($issues[0]->hint)->toStartWith('Set its Kind in the Inspector, one of the tilesets in assets/Data/Tilesets.');

    editTestMapData($root, static fn(string $source): string => str_replace("'events' => [],", "'events' => [], 'tileset' => 'interior',", $source));
    $map = loadLayeredMap($root);
    expect(MapGraphicsValidator::validate($map))->toBe([])
        // Coverage is reported apart: the map has a kind but no tiles yet.
        ->and(array_map(static fn(Issue $issue): string => $issue->message, MapGraphicsValidator::validateCoverage($map)))
        ->toBe(['It has no tiles yet, so all 8 of its glyph cells show in the graphical field.']);
});

it('reports coverage through project validation, as ichiloto validate runs it', function () {
    $root = mapGraphicsProject();
    writeTileLayer($root . '/assets/Maps/test-map', '01.floor.tiles.php', "2816 2816 2816 2816\n2816 0 2816 2816");

    $messages = array_map(static fn(Issue $issue): string => $issue->message, array_filter(
        new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn(Issue $issue): bool => $issue->where === 'test-map',
    ));
    expect($messages)->toContain('1 cell of x on the buildings layer has no tile, so the glyph shows in the graphical field: (1, 1).');
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
