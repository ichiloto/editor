<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Tileset pieces placed through the session, as a graphical editor places
 * them: listed with a picture, previewed and placed between two corners as
 * one undo step. On the 4 x 2 graphics test map the buildings layer
 * (`map:4`) holds ` /  ` over ` xx `; synthetic pieces only.
 */

function pieceSession(): EditorSession
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: [
        // A small tree: leaves and a trunk in their own colours, and a gap that leaves the map's cell alone.
        'tree' => ['name' => 'Tree', 'layer' => 'buildings', 'glyphs' => ['<fg=#5faf00>^^</>', '<fg=#875f00>|</> ']],
        'wall' => ['name' => 'Wall', 'layer' => 'buildings', 'connects' => 'lines',
            'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['walls' => '5888']],
        'shelf' => ['name' => 'Shelf', 'layer' => 'storage', 'glyphs' => ['#']],
    ]);

    return EditorSession::open($root);
}

/** @return list<string> The buildings layer, row by row. */
function readPieceSessionRows(EditorSession $session): array
{
    $layer = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['id'] === 'map:4');

    return array_map(static fn(array $row): string => implode('', $row), $layer['rows']);
}

function placeSessionPiece(EditorSession $session, string $piece, array $from, array $to, ?string $color = null): array
{
    return $session->placePiece('test-map', $session->readMap('test-map')['revision'], $piece, $from, $to, $color);
}

it('lists the map tileset\'s pieces with the layer each goes on and a picture of what it draws', function () {
    $listed = pieceSession()->listPieces('test-map');
    [$tree, $wall, $shelf] = $listed['pieces'];

    expect($listed['issue'])->toBeNull()
        ->and($tree)->toMatchArray(['id' => 'tree', 'name' => 'Tree', 'layer' => 'buildings', 'layerIssue' => null,
            'connected' => false, 'width' => 2, 'height' => 2])
        ->and($tree['picture'])->toBe([
            [['symbol' => '^', 'color' => '#5faf00'], ['symbol' => '^', 'color' => '#5faf00']],
            [['symbol' => '|', 'color' => '#875f00'], ['symbol' => ' ', 'color' => null]],
        ])
        ->and($wall['connected'])->toBeTrue()
        ->and(array_map(static fn(array $row): string => implode('', array_column($row, 'symbol')), $wall['picture']))->toBe(['+-+', '| |', '+-+'])
        // A piece for a layer the map lacks is listed, saying so.
        ->and($shelf['layerIssue'])->toContain('storage layer, which this map does not have');
});

it('says why a map offers no pieces', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root);

    expect(EditorSession::open($root)->listPieces('test-map'))->toMatchArray(['pieces' => []])
        ->and(EditorSession::open($root)->listPieces('test-map')['issue'])->toContain('has no pieces');
});

it('stamps whole copies of a piece across the dragged area in their own colours, as previewed, in one undo step', function () {
    $session = pieceSession();
    $preview = $session->previewPiece('test-map', 'tree', [0, 0], [3, 1], 'cyan');

    expect($preview['cells'])->toBe([
        [0, 0, '^', '#5faf00'], [1, 0, '^', '#5faf00'], [2, 0, '^', '#5faf00'], [3, 0, '^', '#5faf00'],
        [0, 1, '|', '#875f00'], [2, 1, '|', '#875f00'],
    ]);
    expect(placeSessionPiece($session, 'tree', [0, 0], [3, 1], 'cyan'))->toMatchArray(['status' => 'applied', 'changed' => true, 'copies' => 2])
        // The gaps leave the map's own cells as they were.
        ->and(readPieceSessionRows($session))->toBe(['^^^^', '|x| ']);
    $layer = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['id'] === 'map:4');
    expect($layer['colors'])->toContain([0, 0, '#5faf00'], [2, 1, '#875f00']);

    $session->undo();
    expect(readPieceSessionRows($session))->toBe([' /  ', ' xx ']);
});

it('draws a connected piece along the outline between the corners, as previewed', function () {
    $session = pieceSession();
    $preview = $session->previewPiece('test-map', 'wall', [0, 0], [3, 1]);

    expect(placeSessionPiece($session, 'wall', [0, 0], [3, 1]))->toMatchArray(['changed' => true, 'cells' => 8]);
    $rows = readPieceSessionRows($session);
    // Every outline cell is a wall, each shaped as the preview showed it.
    expect(count($preview['cells']))->toBe(8);
    foreach ($preview['cells'] as [$x, $y, $glyph]) {
        expect(mb_substr($rows[$y], $x, 1))->toBe($glyph)->toBeIn(['+', '-', '|']);
    }
    expect(placeSessionPiece($session, 'wall', [0, 0], [3, 1]))->toMatchArray(['changed' => false]);
});

it('refuses a placement that cannot be made whole, a stale revision or an unknown piece, changing nothing', function () {
    $session = pieceSession();
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => placeSessionPiece($session, 'tree', [3, 1], [3, 1]))->toThrow(SessionRefusal::class, 'does not fit')
        ->and(fn() => placeSessionPiece($session, 'shelf', [0, 0], [0, 0]))->toThrow(SessionRefusal::class, 'storage layer')
        ->and(fn() => placeSessionPiece($session, 'gone', [0, 0], [0, 0]))->toThrow(SessionRefusal::class, 'no longer in the map\'s tileset')
        ->and(fn() => $session->placePiece('test-map', $revision + 1, 'tree', [0, 0], [0, 0]))->toThrow(SessionRefusal::class)
        ->and(readPieceSessionRows($session))->toBe([' /  ', ' xx '])
        ->and($session->readMap('test-map')['dirty'])->toBeFalse();
});
