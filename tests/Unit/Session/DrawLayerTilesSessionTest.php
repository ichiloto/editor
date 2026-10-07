<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Drawing the tiles for the glyphs already on a layer through the session, as
 * the terminal editor's T does: one undo step, asking about a glyph that could
 * be several pieces. On the 4 x 2 graphics test map the buildings layer
 * (`map:4`) holds ` /  ` over ` xx `; synthetic pieces only.
 */

function drawLayerTilesSession(array $pieces): EditorSession
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: $pieces);

    return EditorSession::open($root);
}

function readDrawnTiles(EditorSession $session, string $layer): array
{
    return array_find($session->readTiles('test-map')['layers'], static fn(array $found): bool => $found['name'] === $layer)['rows'] ?? [];
}

it('draws every glyph its piece\'s tiles in one undo step, and nothing more when they are already there', function () {
    $session = drawLayerTilesSession([
        'ramp' => ['name' => 'Ramp', 'layer' => 'buildings', 'glyphs' => ['/'], 'tiles' => ['furniture' => ['60']]],
        'crate' => ['name' => 'Crate', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['furniture' => ['61']]],
    ]);
    $revision = $session->readMap('test-map')['revision'];

    expect($session->drawLayerTiles('test-map', $revision, 'map:4'))->toMatchArray(['status' => 'applied', 'cells' => 3])
        ->and(readDrawnTiles($session, 'furniture'))->toBe([[0, 60, 0, 0], [0, 61, 61, 0]])
        ->and($session->drawLayerTiles('test-map', $session->readMap('test-map')['revision'], 'map:4'))->toMatchArray(['cells' => 0]);

    $session->undo();
    expect(readDrawnTiles($session, 'furniture'))->toBe([]);
});

it('asks which piece a glyph is when several draw it, and draws with the answer', function () {
    $session = drawLayerTilesSession([
        'crate' => ['name' => 'Crate', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['furniture' => ['61']]],
        'barrel' => ['name' => 'Barrel', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['furniture' => ['62']]],
    ]);
    $revision = $session->readMap('test-map')['revision'];
    $question = $session->drawLayerTiles('test-map', $revision, 'map:4');

    expect($question['status'])->toBe('question')
        ->and($question['glyph'])->toBe('x')
        ->and(array_column($question['roles'], 'label'))->toHaveCount(2)
        ->and(readDrawnTiles($session, 'furniture'))->toBe([]);
    $barrel = array_find($question['roles'], static fn(array $role): bool => str_contains($role['label'], 'Barrel'))['key'];
    expect($session->drawLayerTiles('test-map', $revision, 'map:4', ['x' => $barrel]))->toMatchArray(['status' => 'applied', 'cells' => 2])
        ->and(readDrawnTiles($session, 'furniture')[1])->toBe([0, 62, 62, 0]);
});

it('refuses a layer no piece draws, or one the map does not have, changing nothing', function () {
    $session = drawLayerTilesSession([]);
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->drawLayerTiles('test-map', $revision, 'map:4'))->toThrow(SessionRefusal::class)
        ->and(fn() => $session->drawLayerTiles('test-map', $revision, 'map:99'))->toThrow(SessionRefusal::class, 'has no layer')
        ->and($session->readMap('test-map')['dirty'])->toBeFalse();
});

it('lists the cells still showing glyphs, and they go once their tiles are drawn', function () {
    $session = drawLayerTilesSession([
        'ramp' => ['name' => 'Ramp', 'layer' => 'buildings', 'glyphs' => ['/'], 'tiles' => ['furniture' => ['60']]],
    ]);
    // Without its floor and decor tiles, every glyph on the map shows.
    foreach (['floor', 'decor'] as $layer) {
        $session->removeTileLayer('test-map', $session->readMap('test-map')['revision'], $layer);
    }
    $before = $session->readCoverage('test-map');
    $shownRamps = static fn(array $coverage): array => array_values(array_filter($coverage['cells'], static fn(array $cell): bool => $cell[2] === '/'));

    expect($before['available'])->toBeTrue()
        ->and($shownRamps($before))->toBe([[1, 0, '/', 'buildings']]);
    $session->drawLayerTiles('test-map', $before['revision'], 'map:4', ['x' => null]);
    expect($shownRamps($session->readCoverage('test-map')))->toBe([]);
});

it('says which piece a glyph plays at a cell, from the tiles around it, for the eyedropper', function () {
    $session = drawLayerTilesSession([
        'crate' => ['name' => 'Crate', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['furniture' => ['61']]],
        'barrel' => ['name' => 'Barrel', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['furniture' => ['62']]],
    ]);
    expect($session->readPieceAt('test-map', 'map:4', 1, 1))->toBe(['glyph' => 'x', 'role' => null]);

    $revision = $session->readMap('test-map')['revision'];
    $barrel = array_find($session->drawLayerTiles('test-map', $revision, 'map:4')['roles'], static fn(array $role): bool => str_contains($role['label'], 'Barrel'))['key'];
    $session->drawLayerTiles('test-map', $revision, 'map:4', ['x' => $barrel]);
    expect($session->readPieceAt('test-map', 'map:4', 1, 1)['role']['key'])->toBe($barrel);
});
