<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * A tileset is seen as it is set up, and every field naming a picture says
 * so: the session describes a tileset record's sheets, marks and pieces for
 * drawing, unsaved edits included, and a picture reference row carries how
 * its value is shown. Synthetic tilesets only.
 */

function tilesetPreviewSession(array $pieces = [], ?int $missingArt = null): EditorSession
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: $pieces, missingArt: $missingArt);

    return EditorSession::open($root);
}

/** @return array<string, mixed> The tileset record's row with the label. */
function findTilesetRow(EditorSession $session, string $label): array
{
    return array_find($session->readDatabaseRecord('tilesets', 0)['rows'], static fn(array $row): bool => $row['label'] === $label);
}

it('lays out a tileset record\'s sheets as the tile palette does, with its marks and pieces drawn', function () {
    $session = tilesetPreviewSession(
        pieces: ['stool' => ['name' => 'Stool', 'layer' => 'buildings', 'glyphs' => ['o'], 'tiles' => ['furniture' => ['5']]]],
        missingArt: 255,
    );
    $preview = $session->readTilesetPreview(0);

    expect($preview['issue'])->toBeNull()
        ->and($preview['unreadable'])->toBe([])
        ->and(array_column($preview['palette']['tabs'], 'name'))->toBe(['A', 'B'])
        ->and($preview['palette']['tabs'][1]['operations'])->not->toBeEmpty()
        ->and($preview['missingArt'])->toBe(255)
        // Only the A2 autotiles shown can be tables.
        ->and($preview['tableTiles'])->not->toBeEmpty()
        ->and(array_filter($preview['tableTiles'], static fn(int $tile): bool => $tile < 2816 || $tile >= 4352))->toBe([])
        ->and($preview['pieces'][0])->toMatchArray(['id' => 'stool', 'name' => 'Stool', 'connected' => false, 'tileLayers' => ['furniture']])
        ->and($preview['pieces'][0]['operations'])->not->toBeEmpty();
});

it('follows unsaved edits, and still shows the sheets of a tileset the Engine would refuse, saying why', function () {
    $session = tilesetPreviewSession();
    $session->applyDatabaseRecord('tilesets', 0, findTilesetRow($session, 'Sheet A2')['key'], '');

    expect(array_column($session->readTilesetPreview(0)['palette']['tabs'], 'name'))->toBe(['B']);

    $session->applyDatabaseRecord('tilesets', 0, findTilesetRow($session, 'Missing Art Tile')['key'], '3000');
    $refused = $session->readTilesetPreview(0);
    expect($refused['issue'])->toContain('missingArt')
        ->and(array_column($refused['palette']['tabs'], 'name'))->toBe(['B']);
});

it('says how a picture reference\'s value is shown, and nothing for a reference that is not a picture', function () {
    $session = tilesetPreviewSession();

    expect(findTilesetRow($session, 'Sheet B')['media'])->toBe(['kind' => 'image', 'root' => 'assets'])
        ->and(findTilesetRow($session, 'Sheet B')['value'])->toBe('Graphics/Tilesets/Home_B.png')
        ->and(array_key_exists('media', findTilesetRow($session, 'Name')))->toBeFalse();
});

it('marks a tile above characters or as a table with a click, and a second click takes the mark away, each one undo step', function () {
    $session = tilesetPreviewSession();

    // Any shape of an autotile marks its kind, as the Engine reads the list.
    expect($session->toggleTilesetMark(0, 'above', 2816 + 5))->toMatchArray(['changed' => true, 'marked' => true])
        ->and($session->readTilesetPreview(0)['above'])->toBe([2816])
        ->and(findTilesetRow($session, 'Above Characters')['value'])->toBe('2816');
    $session->toggleTilesetMark(0, 'above', 7);
    $session->toggleTilesetMark(0, 'tables', 2816);
    expect($session->readTilesetPreview(0))->toMatchArray(['above' => [2816, 7], 'tables' => [2816], 'issue' => null]);

    expect($session->toggleTilesetMark(0, 'above', 2816))->toMatchArray(['marked' => false])
        ->and($session->readTilesetPreview(0)['above'])->toBe([7]);
    $session->undo();
    expect($session->readTilesetPreview(0)['above'])->toBe([2816, 7])
        ->and($session->listDatabaseRecords('tilesets')['dirty'])->toBeTrue();
});

it('refuses a mark the tile cannot take, changing nothing', function () {
    $session = tilesetPreviewSession();

    expect(fn() => $session->toggleTilesetMark(0, 'tables', 7))->toThrow(SessionRefusal::class, 'Only A2 autotiles can be tables')
        ->and(fn() => $session->toggleTilesetMark(0, 'above', 0))->toThrow(SessionRefusal::class, 'cannot be marked')
        ->and(fn() => $session->toggleTilesetMark(0, 'counter', 7))->toThrow(SessionRefusal::class, 'not a tile mark')
        ->and($session->listDatabaseRecords('tilesets')['dirty'])->toBeFalse();
});
