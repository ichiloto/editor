<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Copying, cutting and pasting part of a map layer through the session, as
 * the terminal editor's clipboard does: glyphs with the tiles that move with
 * their layer, each cut and paste one undo step. On the 4 x 2 graphics test
 * map the buildings layer (`map:4`) holds ` /  ` over ` xx `.
 */

function selectionSession(array $pieces = []): EditorSession
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: $pieces);

    return EditorSession::open($root);
}

function readSelectionRows(EditorSession $session): array
{
    $layer = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['id'] === 'map:4');

    return array_map(static fn(array $row): string => implode('', $row), $layer['rows']);
}

it('copies a block and pastes it elsewhere on its layer as one undo step', function () {
    $session = selectionSession();

    expect($session->copySelection('test-map', 'map:4', 1, 0, 2, 1))->toBe(['layer' => 'map:4', 'width' => 2, 'height' => 1]);
    expect($session->pasteSelection('test-map', $session->readMap('test-map')['revision'], 'map:4', 1, 1))
        ->toMatchArray(['status' => 'applied', 'changed' => 2])
        ->and(readSelectionRows($session))->toBe([' /  ', ' /  ']);

    $session->undo();
    expect(readSelectionRows($session))->toBe([' /  ', ' xx ']);
});

it('cuts a block, leaving its cells empty, and puts it back where it is pasted', function () {
    $session = selectionSession();

    expect($session->cutSelection('test-map', $session->readMap('test-map')['revision'], 'map:4', 1, 1, 2, 1))
        ->toMatchArray(['width' => 2, 'height' => 1, 'changed' => 2])
        ->and(readSelectionRows($session))->toBe([' /  ', '    ']);
    $session->pasteSelection('test-map', $session->readMap('test-map')['revision'], 'map:4', 2, 1);
    expect(readSelectionRows($session))->toBe([' /  ', '  xx']);
});

it('asks which piece a pasted glyph is when several draw it', function () {
    $session = selectionSession([
        'crate' => ['name' => 'Crate', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['furniture' => ['61']]],
        'barrel' => ['name' => 'Barrel', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['furniture' => ['62']]],
    ]);
    $session->copySelection('test-map', 'map:4', 1, 1, 1, 1);

    expect($session->pasteSelection('test-map', $session->readMap('test-map')['revision'], 'map:4', 0, 0))
        ->toMatchArray(['status' => 'question', 'glyph' => 'x'])
        ->and(readSelectionRows($session)[0])->toBe(' /  ');
});

it('refuses an empty clipboard, another layer\'s block, or a selection off the map, changing nothing', function () {
    $session = selectionSession();
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->pasteSelection('test-map', $revision, 'map:4', 0, 0))->toThrow(SessionRefusal::class, 'clipboard is empty')
        ->and(fn() => $session->copySelection('test-map', 'map:4', 3, 1, 2, 1))->toThrow(SessionRefusal::class, 'does not fit');
    $session->copySelection('test-map', 'map:4', 0, 0, 1, 1);
    $other = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['id'] !== 'map:4' && ! $layer['event'])['id'];
    expect(fn() => $session->pasteSelection('test-map', $revision, $other, 0, 0))->toThrow(SessionRefusal::class, 'before pasting')
        ->and($session->readMap('test-map')['dirty'])->toBeFalse();
});
