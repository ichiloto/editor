<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Pieces on a tileset stack, such as world map peaks over a mountain kind,
 * placed through the session: each cell's stack holds the overlapping
 * objects' tiles in depth order (further south, then further east, in
 * front), whatever order they were placed in, and placing writes no glyph.
 * The 4 x 2 graphics test map; synthetic pieces only.
 */

function stackedPieceSession(): EditorSession
{
    $root = mapGraphicsProject();
    writeTestTileset($root);
    $path = $root . '/assets/Data/Tilesets/home.php';
    $data = require $path;
    $data['stacks'] = ['relief' => ['layers' => ['relief-a', 'relief-b']]];
    $data['pieces'] = [
        // A 2 x 2 crag and a one-cell rock, decoration only.
        'crag' => ['name' => 'Crag', 'layer' => 'terrain', 'glyphs' => ['  ', '  '], 'tiles' => ['relief-a' => ['1 2', '3 4']]],
        'rock' => ['name' => 'Rock', 'layer' => 'terrain', 'glyphs' => [' '], 'tiles' => ['relief-a' => ['9']]],
    ];
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");

    return EditorSession::open($root);
}

/** @return array<string, list<list<int>>> The map's stack layers, by row. */
function readStackLayers(EditorSession $session): array
{
    $layers = [];
    foreach ($session->readTiles('test-map')['layers'] as $layer) {
        if (str_starts_with($layer['name'], 'relief-')) {
            $layers[$layer['name']] = $layer['rows'];
        }
    }

    return $layers;
}

function placeStackedPiece(EditorSession $session, string $piece, int $x, int $y): array
{
    return $session->placePiece('test-map', $session->readMap('test-map')['revision'], $piece, [$x, $y], [$x, $y]);
}

it('lays overlapping pieces in depth order whichever is placed first, creating the stack\'s layers in order', function (array $order) {
    $session = stackedPieceSession();
    $terrain = static fn(): array => array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['name'] === 'terrain')['rows'];
    $glyphs = $terrain();
    foreach ($order as [$piece, $x, $y]) {
        expect(placeStackedPiece($session, $piece, $x, $y))->toMatchArray(['status' => 'applied', 'changed' => true]);
    }

    // The crag at (1, 0) stands on row 1; the rock at (1, 1) on row 1 too, centred further west, so it draws behind.
    // The rock at (2, 0) stands on row 0, north of the crag, and draws behind it as well.
    expect(readStackLayers($session))->toBe([
        'relief-a' => [[0, 1, 9, 0], [0, 9, 4, 0]],
        'relief-b' => [[0, 0, 2, 0], [0, 3, 0, 0]],
    ])
        ->and(array_slice(array_column($session->readTiles('test-map')['layers'], 'name'), -2))->toBe(['relief-a', 'relief-b'])
        // Decoration only: no glyph changes.
        ->and($terrain())->toBe($glyphs);
})->with([
    'crag first' => [[['crag', 1, 0], ['rock', 1, 1], ['rock', 2, 0]]],
    'rocks first' => [[['rock', 2, 0], ['rock', 1, 1], ['crag', 1, 0]]],
]);

it('lays copies at listed origins in one request as one undo step, as single placements would, and refuses one off the map', function () {
    $session = stackedPieceSession();
    $single = stackedPieceSession();
    placeStackedPiece($single, 'rock', 2, 0);
    placeStackedPiece($single, 'rock', 1, 1);

    expect($session->placePieceCopies('test-map', $session->readMap('test-map')['revision'], 'rock', [[2, 0], [1, 1]]))
        ->toMatchArray(['status' => 'applied', 'changed' => true, 'copies' => 2])
        ->and(readStackLayers($session))->toBe(readStackLayers($single));
    $session->undo();
    expect(array_filter(readStackLayers($session), static fn(array $rows): bool => array_sum(array_merge(...$rows)) > 0))->toBe([]);

    expect(fn() => $session->placePieceCopies('test-map', $session->readMap('test-map')['revision'], 'crag', [[0, 0], [3, 1]]))
        ->toThrow(SessionRefusal::class, 'does not fit at (3, 1)');
});

it('lays a piece again on the same cells without doubling it, and undoes a placement as one step', function () {
    $session = stackedPieceSession();
    placeStackedPiece($session, 'crag', 0, 0);
    $once = readStackLayers($session);
    placeStackedPiece($session, 'crag', 0, 0);
    expect(readStackLayers($session))->toBe($once);

    // The rock at (1, 1) is centred further east than the crag on the same row, so it draws in front.
    placeStackedPiece($session, 'rock', 1, 1);
    expect(readStackLayers($session))->toBe(['relief-a' => [[1, 2, 0, 0], [3, 4, 0, 0]], 'relief-b' => [[0, 0, 0, 0], [0, 9, 0, 0]]]);
    $session->undo();
    expect(readStackLayers($session))->toBe($once);
});

it('refuses a cell that would hold more tiles than the stack has layers, changing nothing', function () {
    $session = stackedPieceSession();
    placeStackedPiece($session, 'crag', 0, 0);
    placeStackedPiece($session, 'crag', 1, 0);
    $before = readStackLayers($session);

    expect(fn() => placeStackedPiece($session, 'rock', 1, 1))->toThrow(SessionRefusal::class, 'which has 2 layers')
        ->and(readStackLayers($session))->toBe($before);
});

/**
 * The stacked crag and rock on the terrain-kind test map ({@see terrainKindProject()}), converted to its meadow kind,
 * so painting the path kind clears the stack the terrain owns.
 */
function stackedKindSession(): EditorSession
{
    $root = terrainKindProject();
    $path = $root . '/assets/Data/Tilesets/home.php';
    $data = require $path;
    $data['stacks'] = ['relief' => ['layers' => ['relief-a', 'relief-b']]];
    $data['pieces'] = [
        'crag' => ['name' => 'Crag', 'layer' => 'terrain', 'glyphs' => ['  ', '  '], 'tiles' => ['relief-a' => ['1 2', '3 4']]],
        'rock' => ['name' => 'Rock', 'layer' => 'terrain', 'glyphs' => [' '], 'tiles' => ['relief-a' => ['9']]],
    ];
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    $session = EditorSession::open($root);
    $session->convertToKinds('test-map', $session->readMap('test-map')['revision'], ['.' => 'meadow']);
    placeStackedPiece($session, 'crag', 1, 0);
    placeStackedPiece($session, 'rock', 3, 0);

    return $session;
}

function paintStackedKind(EditorSession $session, array $from, array $to): void
{
    $session->paintKind('test-map', $session->readMap('test-map')['revision'], 'path', $from, $to);
}

it('keeps a stacked object whole when a kind is painted on only some of its cells, so painting beside it never crops it', function () {
    $session = stackedKindSession();
    expect(readStackLayers($session))->toBe(['relief-a' => [[0, 1, 2, 9], [0, 3, 4, 0]]]);

    // The path takes the crag's lower-left cell and the whole rock: the crag still shows on three other cells.
    paintStackedKind($session, [1, 1], [1, 1]);
    paintStackedKind($session, [3, 0], [3, 0]);

    expect(readStackLayers($session))->toBe(['relief-a' => [[0, 1, 2, 0], [0, 3, 4, 0]]]);
});

it('clears a stacked object, every tile of it, when one paint covers all of its cells', function () {
    $session = stackedKindSession();

    paintStackedKind($session, [1, 0], [2, 1]);

    expect(readStackLayers($session))->toBe(['relief-a' => [[0, 0, 0, 9], [0, 0, 0, 0]]]);
    $session->undo();
    expect(readStackLayers($session))->toBe(['relief-a' => [[0, 1, 2, 9], [0, 3, 4, 0]]]);
});

/**
 * The stacked crag and rock of {@see stackedKindSession()} beside a mound: a stacked obstacle whose middle cell blocks
 * the ground, its footprint glyph on the buildings layer above the terrain kinds.
 */
function stackedObstacleSession(): EditorSession
{
    $root = terrainKindProject();
    $path = $root . '/assets/Data/Tilesets/home.php';
    $data = require $path;
    $data['stacks'] = ['relief' => ['layers' => ['relief-a', 'relief-b']]];
    $data['pieces'] = [
        'crag' => ['name' => 'Crag', 'layer' => 'terrain', 'glyphs' => ['  ', '  '], 'tiles' => ['relief-a' => ['1 2', '3 4']]],
        'rock' => ['name' => 'Rock', 'layer' => 'terrain', 'glyphs' => [' '], 'tiles' => ['relief-a' => ['9']]],
        'mound' => ['name' => 'Mound', 'layer' => 'buildings', 'glyphs' => [' n '], 'tiles' => ['relief-a' => ['30 31 32']],
            'occupancy' => [[null, \Ichiloto\Engine\Events\Enumerations\CollisionType::SOLID, null]]],
    ];
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    $session = EditorSession::open($root);
    $session->convertToKinds('test-map', $session->readMap('test-map')['revision'], ['.' => 'meadow']);

    return $session;
}

/** @return list<string> The buildings layer's glyphs, a row at a time. */
function readStackedBuildings(EditorSession $session): array
{
    $layer = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['name'] === 'buildings');

    return array_map(static fn(array|string $row): string => is_array($row) ? implode('', $row) : $row, $layer['rows']);
}

it('stamps a stacked obstacle\'s footprint glyph above the terrain, and painting a kind over it takes the glyph with the object', function () {
    $session = stackedObstacleSession();
    placeStackedPiece($session, 'mound', 0, 1);

    expect(readStackLayers($session))->toBe(['relief-a' => [[0, 0, 0, 0], [30, 31, 32, 0]]])
        ->and(readStackedBuildings($session)[1][1])->toBe('n');

    // Painting the path beside the mound leaves it whole; painting all of its cells clears it and its footprint.
    paintStackedKind($session, [0, 1], [1, 1]);
    expect(readStackLayers($session)['relief-a'][1])->toBe([30, 31, 32, 0])
        ->and(readStackedBuildings($session)[1][1])->toBe('n');
    paintStackedKind($session, [0, 1], [2, 1]);
    expect(readStackLayers($session)['relief-a'][1])->toBe([0, 0, 0, 0])
        ->and(readStackedBuildings($session)[1][1])->toBe(' ');
    $session->undo();
    expect(readStackLayers($session)['relief-a'][1])->toBe([30, 31, 32, 0])
        ->and(readStackedBuildings($session)[1][1])->toBe('n');
});

it('moves each stacked object with the layer it belongs to when a selection is cut and pasted', function () {
    $session = stackedObstacleSession();
    placeStackedPiece($session, 'rock', 3, 0);
    placeStackedPiece($session, 'mound', 0, 1);
    $layerId = static fn(string $name): string => array_find($session->readMap('test-map')['layers'],
        static fn(array $layer): bool => $layer['name'] === $name)['id'];

    // Cutting the terrain over the whole map lifts the rock, a terrain object, and leaves the mound, a buildings object.
    $session->cutSelection('test-map', $session->readMap('test-map')['revision'], $layerId('terrain'), 0, 0, 4, 2);
    expect(readStackLayers($session)['relief-a'])->toBe([[0, 0, 0, 0], [30, 31, 32, 0]]);
    $session->pasteSelection('test-map', $session->readMap('test-map')['revision'], $layerId('terrain'), 0, 0);
    expect(readStackLayers($session)['relief-a'])->toBe([[0, 0, 0, 9], [30, 31, 32, 0]]);

    // Cutting the buildings row lifts the mound's tiles with its glyph, and pasting it a row up lays them there.
    $session->cutSelection('test-map', $session->readMap('test-map')['revision'], $layerId('buildings'), 0, 1, 3, 1);
    expect(readStackLayers($session)['relief-a'])->toBe([[0, 0, 0, 9], [0, 0, 0, 0]])
        ->and(readStackedBuildings($session)[1][1])->toBe(' ');
    $session->pasteSelection('test-map', $session->readMap('test-map')['revision'], $layerId('buildings'), 0, 0);
    expect(readStackLayers($session)['relief-a'])->toBe([[30, 31, 32, 9], [0, 0, 0, 0]])
        ->and(readStackedBuildings($session)[0][1])->toBe('n');
});
