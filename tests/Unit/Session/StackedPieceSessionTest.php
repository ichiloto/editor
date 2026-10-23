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
