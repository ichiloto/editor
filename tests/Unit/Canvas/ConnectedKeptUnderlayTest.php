<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;

/**
 * A connected piece that keeps a tile layer, such as a stone border keeping
 * the base beneath its see-through edges, leaves that layer as it was when it
 * is drawn over a piece owning it, when it reshapes, and when it is erased.
 * Synthetic pieces and maps only.
 */

/** @return array{string, EditorSession} A map of dirt (a base and a ground tile) and two borders, one keeping the base. */
function createConnectedUnderlayProject(): array
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: [
        'dirt' => ['name' => 'Dirt', 'layer' => 'terrain', 'glyphs' => ['.'], 'tiles' => ['substrate' => ['2864'], 'floor' => ['2816']]],
        'kept-border' => ['name' => 'Kept Border', 'layer' => 'terrain', 'connects' => 'lines',
            'glyphs' => ['horizontal' => '=', 'vertical' => '!', 'corner' => '#'], 'tiles' => ['decor' => '6'], 'keeps' => ['substrate']],
        'plain-border' => ['name' => 'Plain Border', 'layer' => 'terrain', 'connects' => 'lines',
            'glyphs' => ['horizontal' => '~', 'vertical' => ':', 'corner' => '%'], 'tiles' => ['decor' => '7']],
    ]);
    $directory = $root . '/assets/Maps/test-map';
    writeTileLayer($directory, '00.substrate.tiles.php', "2864 2864 2864 2864\n2864 2864 2864 2864");
    writeTileLayer($directory, '02.decor.tiles.php', "0 0 0 0\n0 0 0 0");

    return [$root, EditorSession::open($root)];
}

/** @return list<int> One tile layer's first row, as the session reads it. */
function readUnderlayRow(EditorSession $session, string $layer): array
{
    return array_find($session->readTiles('test-map')['layers'], static fn(array $found): bool => $found['name'] === $layer)['rows'][0];
}

function drawUnderlayBorder(EditorSession $session, string $piece): void
{
    $session->placePiece('test-map', $session->readMap('test-map')['revision'], $piece, [0, 0], [3, 0]);
}

it('keeps the base beneath a connected border drawn over a piece that owned it', function () {
    [, $session] = createConnectedUnderlayProject();
    drawUnderlayBorder($session, 'kept-border');

    expect(readUnderlayRow($session, 'substrate'))->toBe([2864, 2864, 2864, 2864])
        // The dirt's own ground leaves with it; the border draws its own tile.
        ->and(readUnderlayRow($session, 'floor'))->toBe([0, 0, 0, 0])
        ->and(readUnderlayRow($session, 'decor'))->toBe([6, 6, 6, 6]);
});

it('takes the base with the dirt when a connected border does not keep it', function () {
    [, $session] = createConnectedUnderlayProject();
    drawUnderlayBorder($session, 'plain-border');

    expect(readUnderlayRow($session, 'substrate'))->toBe([0, 0, 0, 0]);
});

it('keeps the base when the border reshapes, is erased, undone and saved', function () {
    [$root, $session] = createConnectedUnderlayProject();
    drawUnderlayBorder($session, 'kept-border');
    // A second line joining the first reshapes its end into a corner.
    $session->placePiece('test-map', $session->readMap('test-map')['revision'], 'kept-border', [3, 0], [3, 1]);
    expect(readUnderlayRow($session, 'substrate'))->toBe([2864, 2864, 2864, 2864]);

    $map = $session->readMap('test-map');
    $session->paint('test-map', $map['revision'], $map['baseLayer'], [[0, 0], [1, 0]], ' ');
    expect(readUnderlayRow($session, 'substrate'))->toBe([2864, 2864, 2864, 2864])
        ->and(array_slice(readUnderlayRow($session, 'decor'), 0, 2))->toBe([0, 0]);

    $session->undo();
    expect(array_slice(readUnderlayRow($session, 'decor'), 0, 2))->toBe([6, 6]);
    $session->saveMap('test-map');
    expect(readUnderlayRow(EditorSession::open($root), 'substrate'))->toBe([2864, 2864, 2864, 2864]);
});
