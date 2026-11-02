<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

/**
 * A piece backed on a wall, as RPG Maker furniture is: its ground row stamps
 * glyphs and collision on the floor, and its overhang row draws its tiles
 * over the wall face behind it. On the 4 x 2 graphics test map row 0 stands
 * for the wall (buildings ` /  `) and row 1 for the floor (` xx `); synthetic
 * pieces only.
 */

/** @return array<string, mixed> */
function wallOverhangShelf(array $extra = []): array
{
    return $extra + ['name' => 'Shelf', 'layer' => 'buildings', 'overhang' => 1,
        'glyphs' => ['  ', '##'], 'tiles' => ['decor' => ['7 8', '9 10']],
        'occupancy' => [[null, null], [CollisionType::SOLID, CollisionType::SOLID]]];
}

function wallOverhangProject(?array $shelf = null): string
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: ['shelf' => $shelf ?? wallOverhangShelf(), 'crate' => ['name' => 'Crate', 'layer' => 'buildings',
        'glyphs' => ['#'], 'tiles' => ['decor' => ['11']]]]);

    return $root;
}

function wallOverhangSession(?array $shelf = null): EditorSession
{
    return EditorSession::open(wallOverhangProject($shelf));
}

/** @return array{buildings: list<string>, decor: list<list<int>>} The buildings glyphs and decor tiles as the session holds them. */
function readWallOverhangMap(EditorSession $session): array
{
    $layer = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['id'] === 'map:4');
    $decor = getPhysicalOccupancyMap($session)->readTileEntries(['decor'], 0, 0, 4, 2)['decor'];

    return ['buildings' => array_map(static fn(array $row): string => implode('', $row), $layer['rows']),
        'decor' => array_map(static fn(array $row): array => array_map(intval(...), $row), $decor)];
}

it('lists how many rows of a piece overhang the wall behind it', function () {
    [$shelf, $crate] = wallOverhangSession()->listPieces('test-map')['pieces'];

    expect($shelf)->toMatchArray(['id' => 'shelf', 'width' => 2, 'height' => 2, 'overhang' => 1])
        ->and($crate['overhang'])->toBe(0);
});

it('stands a wall-backed piece on one floor row with its top drawn over the wall row and the terminal wall unchanged', function () {
    $session = wallOverhangSession();
    $before = readWallOverhangMap($session);
    $revision = $session->readMap('test-map')['revision'];

    // The author points at the floor cell the shelf stands on; the preview shows only its ground glyphs.
    expect($session->previewPiece('test-map', 'shelf', [0, 1], [0, 1])['cells'])->toBe([[0, 1, '#', null], [1, 1, '#', null]]);
    $placed = $session->placePiece('test-map', $revision, 'shelf', [0, 1], [0, 1]);
    $after = readWallOverhangMap($session);

    expect($placed)->toMatchArray(['changed' => true, 'copies' => 1])
        // The wall row keeps its terminal glyphs; the floor row takes the shelf's.
        ->and($after['buildings'])->toBe([$before['buildings'][0], '##x '])
        // Its top tiles lie over the wall row, its base tiles on the floor row.
        ->and($after['decor'])->toBe([[7, 8, 0, 0], [9, 10, 0, 5]])
        // A placed copy is still found from its top-left, on the wall row.
        ->and(array_map(static fn(array $copy): array => [$copy['id'], $copy['x'], $copy['y']],
            $session->findPlacedPieces('test-map', 1, 0)['pieces']))->toBe([['shelf', 0, 0]]);

    $session->undo();
    expect(readWallOverhangMap($session))->toBe($before);
});

it('refuses an overhang that would leave the map and changes nothing', function () {
    $session = wallOverhangSession();
    $before = readWallOverhangMap($session);
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->placePiece('test-map', $revision, 'shelf', [0, 0], [0, 0]))
        ->toThrow(SessionRefusal::class, 'Shelf (2 x 2) does not fit at (0, 0): the map has no cell at (0, -1).')
        ->and(readWallOverhangMap($session))->toBe($before);
});

it('places scripted copies and moves a placed copy by the cell each stands on', function () {
    $session = wallOverhangSession();
    $before = readWallOverhangMap($session);
    $session->placePieceCopies('test-map', $session->readMap('test-map')['revision'], 'shelf', [[0, 1]]);
    expect(readWallOverhangMap($session)['decor'])->toBe([[7, 8, 0, 0], [9, 10, 0, 5]]);

    // Found by its top-left on the wall row, put down standing on another floor cell.
    $session->movePlacedPiece('test-map', $session->readMap('test-map')['revision'], 'shelf', [0, 0], [2, 1], false, null);
    $moved = readWallOverhangMap($session);
    expect($moved['decor'])->toBe([[0, 0, 7, 8], [0, 0, 9, 10]])
        // Its old ground glyphs go with it; the wall row's glyphs never changed.
        ->and($moved['buildings'])->toBe([$before['buildings'][0], '  ##']);
});

it('stamps the physical footprint on the ground row alone, leaving the wall row\'s collision as it was', function () {
    $root = createPhysicalOccupancyProject(glyphs: "####\n####");
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace('return array (', "return array (\n  'tileset' => 'home',", (string) file_get_contents($data)));
    writeTestTileset($root, pieces: ['shelf' => wallOverhangShelf(['layer' => 'not-required-for-collision'])]);
    $session = EditorSession::open($root);
    $revision = $session->readMap('test-map')['revision'];
    $recipe = [[null, null], [1, 1]];
    $session->paintOccupancy('test-map', $revision, [[0, 0], [1, 0]], CollisionType::SOLID->value);
    $revision = $session->readMap('test-map')['revision'];

    expect($session->previewOccupancyPiece('test-map', $revision, 'home', 'shelf', $recipe, 0, 1)['cells'])->toBe([[0, 1, 1], [1, 1, 1]]);
    $session->stampOccupancyPiece('test-map', $revision, 'home', 'shelf', $recipe, 0, 1);
    expect($session->readMap('test-map')['occupancy']['rows'])->toBe([[1, 1, 0, 0], [1, 1, 0, 0]]);
});

it('authors the overhang rows in the graphical editor, and the terminal editor keeps them when it saves the tileset', function () {
    $shelf = wallOverhangShelf();
    unset($shelf['overhang']);
    $root = wallOverhangProject($shelf);
    $path = $root . '/assets/Data/Tilesets/home.php';
    file_put_contents($path, str_replace("<?php\n", "<?php\n// Keep the author's tileset notes.\n", (string) file_get_contents($path)));
    $session = EditorSession::open($root);
    $row = array_find($session->readDatabaseRecord('tilesets', 0)['rows'], static fn(array $row): bool =>
        ($row['key']['field'] ?? null) === 'piece0Overhang');
    expect($row)->not->toBeNull();

    $session->applyDatabaseRecord('tilesets', 0, $row['key'], '1');
    $session->saveDatabase('tilesets');
    $authoring = array_find(EditorSession::open($root)->readTilesetPreview(0)['authoring'], static fn(array $piece): bool => $piece['id'] === 'shelf');
    expect(Tileset::load($root . '/assets', 'home')->pieces['shelf']->overhang)->toBe(1)
        ->and(file_get_contents($path))->toContain('// Keep the author', "'overhang' => 1")
        ->and($authoring)->toMatchArray(['overhang' => 1, 'overhangIssue' => null, 'issue' => null]);

    // The terminal editor has no overhang field and keeps it byte for byte.
    $source = (string) file_get_contents($path);
    $terminal = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::all()['tilesets']);
    $terminal->setField(0, 'name', 'Home renamed');
    $terminal->save();
    expect(Tileset::load($root . '/assets', 'home')->pieces['shelf']->overhang)->toBe(1)
        ->and(file_get_contents($path))->toBe(str_replace("'name' => 'Home'", "'name' => 'Home renamed'", $source));
});

it('reports an overhang the Engine would refuse beside the piece instead of writing past it', function () {
    $session = wallOverhangSession(wallOverhangShelf(['glyphs' => ['# ', '##']]));
    $authoring = array_find($session->readTilesetPreview(0)['authoring'], static fn(array $piece): bool => $piece['id'] === 'shelf');

    expect($authoring['overhangIssue'])->toContain('draw over the wall and stamp no glyph');
});

it('keeps a map stamped before its piece declared an overhang loading unchanged, with the old copy still found where it stands', function () {
    $shelf = wallOverhangShelf(['glyphs' => ['##', '##'], 'occupancy' => [[CollisionType::SOLID, CollisionType::SOLID], [CollisionType::SOLID, CollisionType::SOLID]]]);
    unset($shelf['overhang']);
    $root = wallOverhangProject($shelf);
    $session = EditorSession::open($root);
    $session->placePiece('test-map', $session->readMap('test-map')['revision'], 'shelf', [0, 0], [0, 0]);
    $session->saveMap('test-map');
    $maps = sourceHashTree($root . '/assets/Maps');
    $before = EditorSession::open($root)->readMap('test-map');

    writeTestTileset($root, pieces: ['shelf' => wallOverhangShelf()]);
    $reopened = EditorSession::open($root);
    expect($reopened->readMap('test-map'))->toBe($before)
        ->and(sourceHashTree($root . '/assets/Maps'))->toBe($maps)
        ->and(array_map(static fn(array $copy): array => [$copy['id'], $copy['x'], $copy['y']],
            $reopened->findPlacedPieces('test-map', 0, 1)['pieces']))->toBe([['shelf', 0, 0]]);
});
