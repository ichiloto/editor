<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * A tile that stands for a glyph never moves without it: placing, erasing,
 * covering and moving such tiles in a tile layer writes the glyph, and so its
 * collision, with every tile of its role, as one undo step. A tile that
 * stands for nothing stays free. On the 4 x 2 graphics test map the buildings
 * layer (`map:4`) holds ` /  ` over ` xx `; synthetic pieces only.
 */

function tilesCarryGlyphsSession(): EditorSession
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: [
        'chest' => ['name' => 'Chest', 'layer' => 'buildings', 'glyphs' => ['m'], 'tiles' => ['furniture' => ['60']]],
        // The sofa's back rises into the row above its seat.
        'sofa' => ['name' => 'Sofa', 'layer' => 'buildings', 'glyphs' => ['  ', '##'], 'tiles' => ['furniture' => ['70 71', '72 73']]],
        // A window draws one tile over two glyph cells.
        'window' => ['name' => 'Window', 'layer' => 'buildings', 'glyphs' => ['X', 'X'], 'tiles' => ['windows' => ['384', '0']]],
        // Two pieces drawing the same tile.
        'stool' => ['name' => 'Stool', 'layer' => 'buildings', 'glyphs' => ['o'], 'tiles' => ['furniture' => ['80']]],
        'pot' => ['name' => 'Pot', 'layer' => 'buildings', 'glyphs' => ['p'], 'tiles' => ['furniture' => ['80']]],
        'wall' => ['name' => 'Wall', 'layer' => 'buildings', 'connects' => 'lines',
            'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['walls' => '5888']],
        // A rug has no glyph: its tiles stand for nothing in the terminal.
        'rug' => ['name' => 'Rug', 'layer' => 'buildings', 'glyphs' => [' '], 'tiles' => ['rug' => ['90']]],
    ]);

    return EditorSession::open($root);
}

/** @return list<string> The buildings layer, row by row. */
function readSessionBuildingRows(EditorSession $session): array
{
    $layer = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['id'] === 'map:4');

    return array_map(static fn(array $row): string => implode('', $row), $layer['rows']);
}

/** @return list<list<int>> A tile layer's tiles, row by row; none where the map has no such layer. */
function readSessionTiles(EditorSession $session, string $layer): array
{
    $found = array_find($session->readTiles('test-map')['layers'], static fn(array $candidate): bool => $candidate['name'] === $layer);

    return $found['rows'] ?? [];
}

function stampSessionTiles(EditorSession $session, string $layer, array $cells, array $choices = []): array
{
    return $session->stampTiles('test-map', $session->readMap('test-map')['revision'], $layer, $cells, 'Place tiles', $choices);
}

it('writes the glyph a placed tile stands for and removes it with the tile, each one undo step', function () {
    $session = tilesCarryGlyphsSession();

    expect(stampSessionTiles($session, 'furniture', [[0, 0, 60]]))->toMatchArray(['status' => 'applied', 'changed' => 1, 'glyphs' => 1])
        ->and(readSessionBuildingRows($session)[0])->toBe('m/  ');

    stampSessionTiles($session, 'furniture', [[0, 0, 0]]);
    expect(readSessionBuildingRows($session)[0])->toBe(' /  ')
        ->and(readSessionTiles($session, 'furniture')[0][0])->toBe(0);

    $session->undo();
    expect(readSessionBuildingRows($session)[0])->toBe('m/  ')
        ->and(readSessionTiles($session, 'furniture')[0][0])->toBe(60);
    $session->undo();
    expect(readSessionBuildingRows($session)[0])->toBe(' /  ');
});

it('moves a piece with its glyphs and every tile of it when one stamp erases it and places it again', function () {
    $session = tilesCarryGlyphsSession();
    stampSessionTiles($session, 'furniture', [[0, 1, 72], [1, 1, 73]]);
    // The seat's glyphs arrive with the back in the row above.
    expect(readSessionBuildingRows($session))->toBe([' /  ', '##x '])
        ->and(readSessionTiles($session, 'furniture'))->toBe([[70, 71, 0, 0], [72, 73, 0, 0]]);

    stampSessionTiles($session, 'furniture', [[0, 1, 0], [1, 1, 0], [2, 1, 72], [3, 1, 73]]);
    expect(readSessionBuildingRows($session))->toBe([' /  ', '  ##'])
        ->and(readSessionTiles($session, 'furniture'))->toBe([[0, 0, 70, 71], [0, 0, 72, 73]]);

    // The whole move is one step.
    $session->undo();
    expect(readSessionBuildingRows($session))->toBe([' /  ', '##x '])
        ->and(readSessionTiles($session, 'furniture'))->toBe([[70, 71, 0, 0], [72, 73, 0, 0]]);
});

it('takes the glyph a covered tile stood for, with the rest of its tiles', function () {
    $session = tilesCarryGlyphsSession();
    stampSessionTiles($session, 'furniture', [[0, 1, 72], [1, 1, 73]]);

    // Covering the sofa's back removes the seat glyph it belongs to and that seat's other tiles.
    stampSessionTiles($session, 'furniture', [[0, 0, 60]]);
    expect(readSessionBuildingRows($session))->toBe(['m/  ', ' #x '])
        ->and(readSessionTiles($session, 'furniture'))->toBe([[60, 71, 0, 0], [0, 73, 0, 0]]);
});

it('brings a piece\'s glyph cells that draw no tile with the one that does', function () {
    $session = tilesCarryGlyphsSession();

    stampSessionTiles($session, 'windows', [[3, 0, 384]]);
    expect(readSessionBuildingRows($session))->toBe([' / X', ' xxX']);

    stampSessionTiles($session, 'windows', [[3, 0, 0]]);
    expect(readSessionBuildingRows($session))->toBe([' /  ', ' xx ']);
});

it('joins and leaves a wall, each wall cell taking the shape its neighbours give it', function () {
    $session = tilesCarryGlyphsSession();

    stampSessionTiles($session, 'walls', [[2, 0, 5888], [3, 0, 5888]]);
    expect(readSessionBuildingRows($session)[0])->toBe(' /--');

    stampSessionTiles($session, 'walls', [[3, 0, 0]]);
    expect(readSessionBuildingRows($session)[0][3])->toBe(' ')
        ->and(readSessionTiles($session, 'walls')[0])->toBe([0, 0, 5888, 0]);
});

it('leaves glyphs alone for a tile that stands for nothing', function () {
    $session = tilesCarryGlyphsSession();

    expect(stampSessionTiles($session, 'rug', [[0, 0, 90], [3, 1, 90]]))->toMatchArray(['changed' => 2, 'glyphs' => 0])
        ->and(readSessionBuildingRows($session))->toBe([' /  ', ' xx ']);
});

it('asks which glyph a tile shared by several pieces stands for, changing nothing until answered', function () {
    $session = tilesCarryGlyphsSession();

    $asked = stampSessionTiles($session, 'furniture', [[0, 0, 80]]);
    expect($asked['status'])->toBe('question')
        ->and($asked['tile'])->toBe('80')
        ->and(array_column($asked['roles'], 'label'))->toBe(['Stool', 'Pot'])
        ->and(readSessionBuildingRows($session)[0])->toBe(' /  ')
        ->and($session->listUnsavedChanges())->toBe([]);

    stampSessionTiles($session, 'furniture', [[0, 0, 80]], ['80' => $asked['roles'][1]['key']]);
    expect(readSessionBuildingRows($session)[0])->toBe('p/  ');
});

it('refuses a tile whose glyph would fall outside the map, changing nothing', function () {
    $session = tilesCarryGlyphsSession();

    // The sofa's back on the last row would need its seat below the map.
    expect(fn() => stampSessionTiles($session, 'furniture', [[0, 1, 70]]))->toThrow(SessionRefusal::class, 'outside the map')
        ->and(readSessionBuildingRows($session))->toBe([' /  ', ' xx '])
        ->and($session->listUnsavedChanges())->toBe([]);
});
