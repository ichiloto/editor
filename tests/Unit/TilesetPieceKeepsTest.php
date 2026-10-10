<?php

declare(strict_types=1);

use Ichiloto\Editor\Canvas\CanvasEditor;
use Ichiloto\Editor\Canvas\GlyphTilePlanner;
use Ichiloto\Editor\Canvas\PiecePlacer;
use Ichiloto\Editor\Canvas\PieceRole;
use Ichiloto\Editor\Validation\MapGraphicsValidator;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/** Builds only synthetic pieces, through the actual Engine contract. */
function createKeepsTestPiece(string $id, array $data): TilesetPiece
{
    return TilesetPiece::fromArray($id, ['name' => $id, 'layer' => 'buildings'] + $data, 'Synthetic keeps');
}

function planKeepsTestChanges(array $pieces, array $changes, array $glyphs, array $tiles, array $choices = [], array $assigned = []): array
{
    return GlyphTilePlanner::fromPieces($pieces, 'buildings')->plan($changes,
        static fn(int $x, int $y): ?string => $glyphs[$y][$x] ?? null,
        static fn(string $layer, int $x, int $y): string => $tiles[$layer][$y][$x] ?? '0',
        $choices, assigned: $assigned);
}

/** Reports only stale piece tiles, apart from unrelated synthetic fixture coverage. */
function getKeepsTestStaleIssues(string $root): array
{
    return array_values(array_map(static fn($issue): string => $issue->message,
        array_filter(MapGraphicsValidator::validateCoverage(loadLayeredMap($root)),
            static fn($issue): bool => str_contains($issue->message, 'no longer there'))));
}

it('gives kept cells to the same glyph roles as blank attachments, even for zero tiles', function () {
    $piece = createKeepsTestPiece('mounted', ['glyphs' => ['a ', ' b'],
        'tiles' => ['decor' => ['5 0', '0 6']], 'keeps' => ['walls', 'floor']]);
    $roles = PieceRole::readPiece($piece);
    $attached = [['dx' => 0, 'dy' => 0], ['dx' => 1, 'dy' => 0], ['dx' => 0, 'dy' => 1]];

    expect($roles['a'][0]->keeps)->toBe(['walls' => $attached, 'floor' => $attached])
        ->and($roles['b'][0]->keeps)->toBe(['walls' => [['dx' => 0, 'dy' => 0]], 'floor' => [['dx' => 0, 'dy' => 0]]])
        ->and($roles['a'][0]->tiles)->toBe(['decor' => [['dx' => 0, 'dy' => 0, 'entry' => '5']]])
        ->and(PieceRole::readPiece($piece, ['walls', 'decor'])['a'][0]->keeps)->toBe(['floor' => $attached])
        ->and(PieceRole::readPiece($piece, ['walls', 'decor'])['a'][0]->tiles)->toBe([]);

    $middle = createKeepsTestPiece('middle', ['glyphs' => [' w '], 'keeps' => ['walls']]);
    expect(PieceRole::readPiece($middle)['w'][0]->keeps['walls'])->toBe([
        ['dx' => 0, 'dy' => 0], ['dx' => -1, 'dy' => 0], ['dx' => 1, 'dy' => 0],
    ]);
});

it('deduplicates equivalent roles but not distinct kept layers or footprints', function () {
    $data = ['glyphs' => ['w'], 'tiles' => ['decor' => ['5']]];
    $planner = GlyphTilePlanner::fromPieces([
        createKeepsTestPiece('plain', $data),
        createKeepsTestPiece('mounted', $data + ['keeps' => ['walls']]),
        createKeepsTestPiece('both', $data + ['keeps' => ['walls', 'floor']]),
        createKeepsTestPiece('both-reordered', $data + ['keeps' => ['floor', 'walls']]),
        createKeepsTestPiece('wide', array_replace($data, ['glyphs' => ['w '], 'tiles' => ['decor' => ['5 0']], 'keeps' => ['walls']])),
    ], 'buildings');

    expect(array_map(static fn(PieceRole $role): string => $role->key, $planner->getRoles('w')))
        ->toBe(['plain:0:0', 'mounted:0:0', 'both:0:0', 'wide:0:0'])
        ->and($planner->findRole('w', 'both-reordered:0:0'))->toBe($planner->findRole('w', 'both:0:0'))
        ->and($planner->findRole('w', 'mounted:0:0'))->not->toBe($planner->findRole('w', 'plain:0:0'));
});

it('preserves the actual underlay at the arriving role and its attached blank', function (array $keeps, array $expectedWalls) {
    $pieces = [
        createKeepsTestPiece('wall', ['glyphs' => ['# '], 'tiles' => ['walls' => ['10 11']]]),
        createKeepsTestPiece('window', ['glyphs' => ['w '], 'tiles' => ['decor' => ['5 6']], 'keeps' => $keeps]),
    ];
    $plan = planKeepsTestChanges($pieces, [['x' => 1, 'y' => 0, 'old' => '#', 'new' => 'w']],
        [[' ', '#', ' ', ' ']], ['walls' => [['0', '10', '11', '0']]]);

    expect($plan['unresolved'])->toBe([])
        ->and($plan['tiles']['walls'] ?? [])->toBe($expectedWalls)
        ->and($plan['tiles']['decor'])->toBe([
            ['x' => 1, 'y' => 0, 'entry' => '5'], ['x' => 2, 'y' => 0, 'entry' => '6'],
        ]);
})->with([
    'kept layer' => [['walls'], []],
    'existing non-keeping behaviour' => [[], [['x' => 1, 'y' => 0, 'entry' => '0'], ['x' => 2, 'y' => 0, 'entry' => '0']]],
]);

it('protects an arriving kept cell from a different leaving role but not cells outside it', function () {
    $pieces = [
        createKeepsTestPiece('wall', ['glyphs' => ['# '], 'tiles' => ['walls' => ['10 11']]]),
        createKeepsTestPiece('window', ['glyphs' => ['w'], 'tiles' => ['decor' => ['5']], 'keeps' => ['walls']]),
    ];
    $plan = planKeepsTestChanges($pieces, [
        ['x' => 1, 'y' => 0, 'old' => '#', 'new' => ' '], ['x' => 2, 'y' => 0, 'old' => ' ', 'new' => 'w'],
    ], [[' ', '#', ' ', ' ']], ['walls' => [['0', '10', '11', '0']]]);

    expect($plan['tiles'])->toBe([
        'walls' => [['x' => 1, 'y' => 0, 'entry' => '0']],
        'decor' => [['x' => 2, 'y' => 0, 'entry' => '5']],
    ]);
});

it('erases and moves only a keeping role\'s own tiles, never independent underlay', function () {
    $pieces = [
        createKeepsTestPiece('wall', ['glyphs' => ['#'], 'tiles' => ['walls' => ['10']]]),
        createKeepsTestPiece('window', ['glyphs' => ['w'], 'tiles' => ['decor' => ['5']], 'keeps' => ['walls']]),
    ];
    $plan = planKeepsTestChanges($pieces, [
        ['x' => 1, 'y' => 0, 'old' => 'w', 'new' => ' '], ['x' => 3, 'y' => 0, 'old' => '#', 'new' => 'w'],
    ], [[' ', 'w', ' ', '#']], ['walls' => [['0', '13', '0', '10']], 'decor' => [['0', '5', '0', '0']]]);

    expect($plan['tiles'])->toBe(['decor' => [['x' => 1, 'y' => 0, 'entry' => '0'], ['x' => 3, 'y' => 0, 'entry' => '5']]]);
});

it('requires a real role choice when identical artwork has different keeping behaviour', function () {
    $wall = createKeepsTestPiece('wall', ['glyphs' => ['#'], 'tiles' => ['walls' => ['10']]]);
    $data = ['glyphs' => ['w'], 'tiles' => ['decor' => ['5']]];
    $pieces = [$wall, createKeepsTestPiece('plain', $data), createKeepsTestPiece('mounted', $data + ['keeps' => ['walls']])];
    $changes = [['x' => 0, 'y' => 0, 'old' => '#', 'new' => 'w']];
    $glyphs = [['#']];
    $tiles = ['walls' => [['10']]];

    expect(planKeepsTestChanges($pieces, $changes, $glyphs, $tiles)['unresolved']['w'])->toHaveCount(2)
        ->and(planKeepsTestChanges($pieces, $changes, $glyphs, $tiles, ['w' => 'mounted:0:0'])['tiles'])->not->toHaveKey('walls')
        ->and(planKeepsTestChanges($pieces, $changes, $glyphs, $tiles, ['w' => 'plain:0:0'])['tiles']['walls'])
        ->toBe([['x' => 0, 'y' => 0, 'entry' => '0']])
        ->and(planKeepsTestChanges($pieces, $changes, $glyphs, $tiles, ['w' => 'plain:0:0'], ['0,0' => 'mounted:0:0'])['tiles'])
        ->not->toHaveKey('walls');
});

it('does not invent missing kept layers or block independently authored arriving tiles', function () {
    $pieces = [
        createKeepsTestPiece('window', ['glyphs' => ['w '], 'tiles' => ['decor' => ['5 6']], 'keeps' => ['walls']]),
        createKeepsTestPiece('wall', ['glyphs' => ['#'], 'tiles' => ['walls' => ['12']]]),
    ];
    $plan = planKeepsTestChanges($pieces, [['x' => 0, 'y' => 0, 'old' => ' ', 'new' => 'w']], [[' ', ' ']], []);
    expect($plan['tiles'])->toBe(['decor' => [['x' => 0, 'y' => 0, 'entry' => '5'], ['x' => 1, 'y' => 0, 'entry' => '6']]]);

    $plan = planKeepsTestChanges($pieces, [
        ['x' => 0, 'y' => 0, 'old' => ' ', 'new' => 'w'], ['x' => 1, 'y' => 0, 'old' => ' ', 'new' => '#'],
    ], [[' ', ' ']], ['walls' => [['10', '11']]]);
    expect($plan['tiles']['walls'])->toBe([['x' => 1, 'y' => 0, 'entry' => '12']]);
});

it('stamps multi-cell keeps through PiecePlacer with byte-preserving undo and save round trips', function () {
    $root = mapGraphicsProject();
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/layers/04.buildings.map.php', MapGridSource::buildSource("####\n####", 'AUTHORED', "// Preserve this authored layer.\n"));
    writeTileLayer($directory, '03.walls.tiles.php', "10 11 10 11\n11 10 11 10", "// Independent underlay.\n");
    writeTileLayer($directory, '02.decor.tiles.php', "0 0 0 0\n0 0 0 0");
    writeTestTileset($root, pieces: [
        'wall-one' => ['name' => 'Wall one', 'layer' => 'buildings', 'glyphs' => ['#'], 'tiles' => ['walls' => ['10']]],
        'wall-two' => ['name' => 'Wall two', 'layer' => 'buildings', 'glyphs' => ['#'], 'tiles' => ['walls' => ['11']]],
        'mounted' => ['name' => 'Mounted', 'layer' => 'buildings', 'glyphs' => ['w ', ' b'],
            'tiles' => ['decor' => ['5 6', '0 7']], 'keeps' => ['walls']],
    ]);
    $map = loadLayeredMap($root);
    $before = $map->captureLayerSnapshot();
    $tilesBefore = $map->getTileLayerSources();
    $disk = sourceHashTree($directory);
    $piece = PiecePlacer::loadPieces($map)['mounted'];
    $result = PiecePlacer::stampArea($map, $piece, ['x' => 1, 'y' => 0], ['x' => 1, 'y' => 0], null);
    $after = $map->captureLayerSnapshot();
    $glyphSourcesAfter = $map->getGridSources();
    $tilesAfter = $map->getTileLayerSources();
    $walls = $directory . '/graphics/03.walls.tiles.php';

    expect($result['command'])->not->toBeNull()
        ->and($tilesAfter[$walls])->toBe($tilesBefore[$walls])
        ->and($map->getLayerSymbol('map:4', 2, 0))->toBe('#')
        ->and($map->getLayerSymbol('map:4', 1, 1))->toBe('#')
        ->and(sourceHashTree($directory))->toBe($disk);
    $result['command']->undo();
    expect($map->captureLayerSnapshot())->toBe($before)->and($map->getTileLayerSources())->toBe($tilesBefore);
    $result['command']->execute();
    expect($map->captureLayerSnapshot())->toBe($after)->and($map->getTileLayerSources())->toBe($tilesAfter);
    $map->save();
    expect(array_keys(array_diff_assoc(sourceHashTree($directory), $disk)))
        ->toBe(['graphics/02.decor.tiles.php', 'layers/04.buildings.map.php'])
        ->and(file_get_contents($walls))->toBe($tilesBefore[$walls]);

    $map = loadLayeredMap($root);
    expect($map->getGridSources())->toBe($glyphSourcesAfter)->and($map->getTileLayerSources())->toBe($tilesAfter)
        ->and($map->isDirty())->toBeFalse();
    $writes = [['x' => 1, 'y' => 0, 'symbol' => ' '], ['x' => 2, 'y' => 1, 'symbol' => ' ']];
    $plan = CanvasEditor::plan($map, 'map:4', $writes);
    expect($plan['unresolved'])->toBe([])->and($plan['tiles'])->not->toHaveKey('walls');
    CanvasEditor::apply($map, 'map:4', $writes, 'Erase mounted glyphs', $plan['tiles']);
    expect($map->getTileLayerSources()[$walls])->toBe($tilesBefore[$walls]);
});

it('explains arbitrary kept underlay only at matching role cells, not stale tiles elsewhere', function () {
    $root = mapGraphicsProject();
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/layers/04.buildings.map.php', MapGridSource::buildSource("w   \n    ", 'AUTHORED'));
    writeTestTileset($root, pieces: [
        'wall-one' => ['name' => 'Wall one', 'layer' => 'buildings', 'glyphs' => ['#'], 'tiles' => ['walls' => ['10']]],
        'wall-two' => ['name' => 'Wall two', 'layer' => 'buildings', 'glyphs' => ['@'], 'tiles' => ['walls' => ['11']]],
        'window' => ['name' => 'Window', 'layer' => 'buildings', 'glyphs' => ['w '], 'tiles' => ['decor' => ['5 6']], 'keeps' => ['walls']],
    ]);
    writeTileLayer($directory, '02.decor.tiles.php', "5 6 0 0\n0 0 0 0");
    writeTileLayer($directory, '03.walls.tiles.php', "10 11 10 0\n0 0 11 10");
    expect(getKeepsTestStaleIssues($root))->toBe([
        '3 tiles on the walls tile layer are drawn by a tileset piece whose glyph is no longer there: (2, 0), (2, 1), (3, 1).',
    ]);

    // The glyph alone must not excuse another role's underlay when its identifying art contradicts it.
    writeTileLayer($directory, '02.decor.tiles.php', "9 0 0 0\n0 0 0 0");
    expect(getKeepsTestStaleIssues($root))->toBe([
        '5 tiles on the walls tile layer are drawn by a tileset piece whose glyph is no longer there: (0, 0), (1, 0), (2, 0) and 2 more.',
    ]);
});

it('does not extend a surviving role\'s keeps into a missing sibling glyph\'s cells', function () {
    $root = mapGraphicsProject();
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/layers/04.buildings.map.php', MapGridSource::buildSource("a   \n    ", 'AUTHORED'));
    writeTestTileset($root, pieces: [
        'wall' => ['name' => 'Wall', 'layer' => 'buildings', 'glyphs' => ['#'], 'tiles' => ['walls' => ['10']]],
        'mounted' => ['name' => 'Mounted', 'layer' => 'buildings', 'glyphs' => ['a b'],
            'tiles' => ['decor' => ['5 0 6']], 'keeps' => ['walls']],
    ]);
    writeTileLayer($directory, '02.decor.tiles.php', "5 0 0 0\n0 0 0 0");
    writeTileLayer($directory, '03.walls.tiles.php', "10 10 10 0\n0 0 0 0");
    expect(getKeepsTestStaleIssues($root))->toBe([
        '1 tile on the walls tile layer is drawn by a tileset piece whose glyph is no longer there: (2, 0).',
    ]);
});

it('allows glyph-only keeping roles without claiming an entire layer', function () {
    $root = mapGraphicsProject();
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/layers/04.buildings.map.php', MapGridSource::buildSource("g   \n    ", 'AUTHORED'));
    writeTestTileset($root, pieces: [
        'wall' => ['name' => 'Wall', 'layer' => 'buildings', 'glyphs' => ['#'], 'tiles' => ['walls' => ['10']]],
        'mounted' => ['name' => 'Mounted', 'layer' => 'buildings', 'glyphs' => ['g '], 'keeps' => ['walls']],
    ]);
    writeTileLayer($directory, '03.walls.tiles.php', "10 10 10 0\n0 0 0 0");
    expect(getKeepsTestStaleIssues($root))->toBe([
        '1 tile on the walls tile layer is drawn by a tileset piece whose glyph is no longer there: (2, 0).',
    ]);
});

it('keeps underlay for an assigned same-glyph repaint and clips attached cells off the map', function () {
    $pieces = [
        createKeepsTestPiece('wall', ['glyphs' => ['#'], 'tiles' => ['walls' => ['10']]]),
        createKeepsTestPiece('mounted', ['glyphs' => ['# '], 'tiles' => ['decor' => ['5 6']], 'keeps' => ['walls']]),
    ];
    $plan = planKeepsTestChanges($pieces, [['x' => 0, 'y' => 0, 'old' => '#', 'new' => '#']],
        [['#']], ['walls' => [['10']]], assigned: ['0,0' => 'mounted:0:0']);
    expect($plan)->toBe(['tiles' => ['decor' => [['x' => 0, 'y' => 0, 'entry' => '5']]], 'unresolved' => []]);
});

it('leaves underlay intact when PiecePlacer stamps a piece containing only blank glyphs', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: [
        'blank' => ['name' => 'Blank', 'layer' => 'buildings', 'glyphs' => ['  '],
            'tiles' => ['decor' => ['8 9']], 'keeps' => ['floor']],
    ]);
    $map = loadLayeredMap($root);
    $before = $map->getTileLayerSources();
    $glyphs = array_diff_key($map->getGridSources(), $before);
    $result = PiecePlacer::stampArea($map, PiecePlacer::loadPieces($map)['blank'],
        ['x' => 1, 'y' => 0], ['x' => 1, 'y' => 0], null);

    expect(array_diff_key($map->getGridSources(), $map->getTileLayerSources()))->toBe($glyphs)
        ->and($map->getTileLayerSources()[$map->directory . '/graphics/01.floor.tiles.php'])
        ->toBe($before[$map->directory . '/graphics/01.floor.tiles.php'])
        ->and($result['command'])->not->toBeNull();
    $result['command']->undo();
    expect($map->getTileLayerSources())->toBe($before);
});
