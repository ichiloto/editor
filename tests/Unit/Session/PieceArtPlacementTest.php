<?php

declare(strict_types=1);

use Ichiloto\Editor\Canvas\PieceOwnership;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * A piece's art laid alone over a footprint the author drew in their own
 * glyphs: the tiles change, the glyphs, their colours and collision stay byte
 * for byte. The 4 x 3 synthetic map has a wall row (row 0), an authored green
 * `%%` shelf footprint on row 1 and open floor on row 2, with declared
 * occupancy; synthetic pieces only.
 */

/** @param list<list<CollisionType>>|null $occupancy */
function pieceArtProject(?array $occupancy = null): string
{
    $root = makeTemporaryProject('editor-piece-art-');
    $directory = $root . '/assets/Maps/test-map';
    unlink($directory . '/test-map.map.php');
    mkdir($directory . '/layers');
    $layers = ['01.terrain.map.php' => "....\n....\n....", '04.buildings.map.php' => "####\n <fg=#5fd75f>%%</> \n    "];
    foreach ($layers as $file => $text) {
        file_put_contents($directory . '/layers/' . $file, MapGridSource::buildSource($text, 'AUTHORED', '// keep ' . $file . "\n"));
    }
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource("    \n    \n    ", 'EVENTS'));
    [$solid, $none] = [CollisionType::SOLID, CollisionType::NONE];
    $occupancy ??= [[$solid, $solid, $solid, $solid], [$none, $solid, $solid, $none], [$none, $none, $none, $none]];
    file_put_contents($directory . '/test-map.data.php', "<?php\n// Authored metadata remains authored.\nreturn "
        . var_export(['name' => 'Piece art', 'region' => '', 'events' => [], 'tileset' => 'home', 'occupancy' => $occupancy], true) . ";\n");
    writeTileLayer($directory, '01.floor.tiles.php', "2816 2816 2816 2816\n2816 2816 2816 2816\n2816 2816 2816 2816");
    writeTileLayer($directory, '02.decor.tiles.php', "0 0 0 0\n0 21 22 0\n0 0 0 0", "// The old shelf art.\n");
    writeTestTileset($root, pieces: [
        'shelf' => ['name' => 'Shelf', 'layer' => 'buildings', 'overhang' => 1, 'glyphs' => ['  ', '##'],
            'tiles' => ['decor' => ['7 8', '9 10']], 'occupancy' => [[null, null], [$solid, $solid]]],
        'crate' => ['name' => 'Crate', 'layer' => 'buildings', 'glyphs' => ['#'], 'tiles' => ['decor' => ['11']]],
        'wall' => ['name' => 'Wall', 'layer' => 'buildings', 'connects' => 'lines',
            'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['decor' => '12']],
    ]);

    return $root;
}

/** @return array<string, string> Every map source file's bytes, by path below the map's folder. */
function readPieceArtSources(string $root): array
{
    $directory = $root . '/assets/Maps/test-map';
    $sources = [];
    foreach (['test-map.data.php', 'test-map.event.php', 'layers/01.terrain.map.php', 'layers/04.buildings.map.php',
        'graphics/01.floor.tiles.php', 'graphics/02.decor.tiles.php'] as $file) {
        $sources[$file] = (string) file_get_contents($directory . '/' . $file);
    }

    return $sources;
}

/** @return list<list<int>> */
function readPieceArtDecor(EditorSession $session): array
{
    return array_map(static fn(array $row): array => array_map(intval(...), $row),
        getPhysicalOccupancyMap($session)->readTileEntries(['decor'], 0, 0, 4, 3)['decor']);
}

it('lays a piece\'s art over an authored footprint and keeps its glyphs and collision byte for byte through save, reload and undo', function () {
    $root = pieceArtProject();
    $before = readPieceArtSources($root);
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');

    $placed = $session->placePiece('test-map', $map['revision'], 'shelf', [1, 1], [1, 1], ownership: PieceOwnership::GRAPHICAL);
    expect($placed)->toMatchArray(['changed' => true, 'copies' => 1, 'footprint' => 0])
        // The shelf's top rises over the wall row and its base replaces the old art; nothing else moves.
        ->and(readPieceArtDecor($session))->toBe([[0, 7, 8, 0], [0, 9, 10, 0], [0, 0, 0, 0]])
        ->and($session->readMap('test-map')['occupancy']['rows'])->toBe($map['occupancy']['rows']);

    $session->saveAll();
    $saved = readPieceArtSources($root);
    expect(array_diff_key($saved, ['graphics/02.decor.tiles.php' => true]))
        ->toBe(array_diff_key($before, ['graphics/02.decor.tiles.php' => true]))
        ->and($saved['graphics/02.decor.tiles.php'])->toContain('// The old shelf art.')
        ->and(readPieceArtDecor(EditorSession::open($root)))->toBe([[0, 7, 8, 0], [0, 9, 10, 0], [0, 0, 0, 0]]);

    $session->undo();
    $session->saveAll();
    expect(readPieceArtSources($root))->toBe($before);
});

it('refuses art whose ground the map does not hold as the piece would, and changes nothing', function () {
    $root = pieceArtProject();
    $before = readPieceArtSources($root);
    $session = EditorSession::open($root);
    $revision = $session->readMap('test-map')['revision'];

    // The shelf would stand on open floor at (0, 1).
    expect(fn() => $session->placePiece('test-map', $revision, 'shelf', [0, 1], [0, 1], ownership: PieceOwnership::GRAPHICAL))
        ->toThrow(SessionRefusal::class, 'Shelf at (0, 1) needs solid collision at (0, 1), where the map has none')
        // A piece with no declared occupancy is held to its glyph's collision.
        ->and(fn() => $session->placePiece('test-map', $revision, 'crate', [3, 2], [3, 2], ownership: PieceOwnership::GRAPHICAL))
        ->toThrow(SessionRefusal::class, 'Crate at (3, 2) needs solid collision at (3, 2), where the map has none')
        ->and(readPieceArtDecor($session))->toBe([[0, 0, 0, 0], [0, 21, 22, 0], [0, 0, 0, 0]]);

    $session->saveAll();
    expect(readPieceArtSources($root))->toBe($before);
});

it('refuses art that would rise over walkable floor, and changes nothing', function () {
    [$solid, $none] = [CollisionType::SOLID, CollisionType::NONE];
    $session = EditorSession::open(pieceArtProject([[$solid, $none, $solid, $solid], [$none, $solid, $solid, $none], [$none, $none, $none, $none]]));
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->placePiece('test-map', $revision, 'shelf', [1, 1], [1, 1], ownership: PieceOwnership::GRAPHICAL))
        ->toThrow(SessionRefusal::class, 'Shelf at (1, 1) would rise over walkable floor at (1, 0)')
        ->and(readPieceArtDecor($session))->toBe([[0, 0, 0, 0], [0, 21, 22, 0], [0, 0, 0, 0]]);
});

it('lays a glyph-only piece\'s art where the map already has its glyph\'s collision', function () {
    $session = EditorSession::open(pieceArtProject());

    $session->placePieceCopies('test-map', $session->readMap('test-map')['revision'], 'crate', [[2, 1]], ownership: PieceOwnership::GRAPHICAL);
    expect(readPieceArtDecor($session))->toBe([[0, 0, 0, 0], [0, 21, 11, 0], [0, 0, 0, 0]])
        ->and(array_map(static fn(array $row): string => implode('', $row), array_find($session->readMap('test-map')['layers'],
            static fn(array $layer): bool => $layer['id'] === 'map:4')['rows']))->toBe(['####', ' %% ', '    ']);
});

it('refuses a graphical placement that would stamp a footprint or lay a connected piece', function () {
    $session = EditorSession::open(pieceArtProject());
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->placePiece('test-map', $revision, 'shelf', [1, 1], [1, 1], footprint: true, ownership: PieceOwnership::GRAPHICAL))
        ->toThrow(SessionRefusal::class, 'A graphical placement of Shelf leaves collision as it is; it cannot also stamp a footprint.')
        ->and(fn() => $session->placePiece('test-map', $revision, 'wall', [0, 2], [3, 2], ownership: PieceOwnership::GRAPHICAL))
        ->toThrow(SessionRefusal::class, 'Wall is drawn as connected cells; its art cannot be laid alone.');
});

it('takes the graphical mode as a session request, as map:apply and the GUI editor send it', function () {
    $root = pieceArtProject();
    $request = createPhysicalOccupancyRpc($root);
    $revision = $request('map.read', ['map' => 'test-map'])['result']['revision'];

    expect($request('pieces.place', ['map' => 'test-map', 'revision' => $revision, 'piece' => 'shelf', 'from' => [1, 1], 'to' => [1, 1],
        'ownership' => 'art'])['error']['message'])->toBe('"ownership" must be one of gameplay, graphical.');
    $placed = $request('pieces.place', ['map' => 'test-map', 'revision' => $revision, 'piece' => 'shelf', 'origins' => [[1, 1]],
        'ownership' => 'graphical'])['result'];
    $buildings = array_find($request('map.read', ['map' => 'test-map'])['result']['layers'], static fn(array $layer): bool => $layer['id'] === 'map:4');
    expect($placed)->toMatchArray(['changed' => true, 'copies' => 1])
        ->and(array_map(static fn(array $row): string => implode('', $row), $buildings['rows']))->toBe(['####', ' %% ', '    ']);
});
