<?php

declare(strict_types=1);

use Ichiloto\Editor\Assets\PngPixels;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\Enumerations\DoorwayEntrance;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;

/*
 * Making a building as a game maker does in the GUI editor, over the session
 * requests it sends: art brought in through the picker, its cells copied into
 * a sheet, a piece defined over them cell by cell, placed with its collision,
 * given an entrance at its painted door, moved and removed, undone and redone
 * throughout. Everything is synthetic; no test reads a game's content.
 */

/** The tiles where the hut's 3 x 2 cells land when copied to C's column 7, row 0: across both halves of the sheet. */
function getHutTiles(): array
{
    return [[263, 384, 385], [271, 392, 393]];
}

/** A pixel of a sheet image as it is on disk. */
function readSheetPixel(string $root, string $sheet, int $x, int $y): array
{
    return PngPixels::decode((string) file_get_contents($root . "/assets/Graphics/Tilesets/Town_{$sheet}.png"), $sheet)->getPixel($x, $y);
}

/** Brings the hut's art in, copies it into sheet C and defines the piece the walkthrough uses, as one author would. */
function defineHutPiece(string $root, Closure $request): void
{
    $incoming = writeIncomingBuildingArt(dirname($root) . '/' . basename($root) . '-incoming');
    rememberTemporaryProject(dirname($incoming));
    expectAnswer($request('assets.import', ['source' => $incoming, 'destination' => 'Graphics/Incoming/Hut.png']));
    expectAnswer($request('tilesets.copyCells', ['index' => 0, 'tileset' => 'town', 'source' => 'Graphics/Incoming/Hut.png',
        'from' => [0, 0], 'size' => [3, 2], 'tile' => 263]));
    expect(expectAnswer($request('tilesets.createPiece', ['index' => 0, 'tileset' => 'town', 'name' => 'Hut', 'layer' => 'buildings',
        'tileLayer' => 'houses', 'rows' => getHutTiles()]))['id'])->toBe('hut');
    foreach ([[0, 0, '/'], [1, 0, '^'], [2, 0, '\\'], [0, 1, '|'], [2, 1, '|']] as [$x, $y, $glyph]) {
        expectAnswer($request('tilesets.setPieceGlyph', ['index' => 0, 'tileset' => 'town', 'piece' => 'hut', 'x' => $x, 'y' => $y, 'glyph' => $glyph]));
    }
    expectAnswer($request('tilesets.setPieceGlyph', ['index' => 0, 'tileset' => 'town', 'piece' => 'hut', 'x' => 1, 'y' => 1,
        'glyph' => '⊓', 'color' => '#ffd75f']));
    expectAnswer($request('tileset.setOccupancy', ['index' => 0, 'tileset' => 'town', 'piece' => 'hut', 'expected' => null,
        'value' => [[null, null, null], [CollisionType::SOLID->value, CollisionType::SOLID->value, CollisionType::SOLID->value]]]));
    expectAnswer($request('tilesets.setPieceDoor', ['index' => 0, 'tileset' => 'town', 'piece' => 'hut', 'x' => 1, 'y' => 1, 'entrance' => 'front']));
    foreach (getHutTiles()[0] as $roof) {
        expectAnswer($request('tilesets.mark', ['index' => 0, 'mark' => 'above', 'tile' => $roof]));
    }
    expectAnswer($request('database.save', ['category' => 'tilesets']));
}

/** The town as the GUI reads it: its buildings glyphs, collision rows and events. */
function readTown(Closure $request): array
{
    $map = expectAnswer($request('map.read', ['map' => 'town']));
    $buildings = array_find($map['layers'], static fn(array $layer): bool => $layer['name'] === 'buildings');

    return ['revision' => $map['revision'], 'glyphs' => array_map(static fn(array $row): string => implode('', $row), $buildings['rows']), 'occupancy' => $map['occupancy']['rows'],
        'events' => array_column($map['events'], 'cells', 'marker')];
}

it('brings building art in through the asset picker, keeping its bytes, refusing to replace another image unasked, with undo', function () {
    $root = createBuildingProject();
    $request = createBuildingRpc($root);
    $incoming = writeIncomingBuildingArt(dirname($root) . '/' . basename($root) . '-incoming');
    rememberTemporaryProject(dirname($incoming));
    $target = $root . '/assets/Graphics/Incoming/Hut.png';

    $imported = expectAnswer($request('assets.import', ['source' => $incoming, 'destination' => 'Graphics/Incoming/Hut.png']));
    expect($imported)->toMatchArray(['path' => 'Graphics/Incoming/Hut.png', 'width' => 6, 'height' => 4, 'changed' => true])
        ->and(file_get_contents($target))->toBe(file_get_contents($incoming))
        ->and(ReferenceCatalog::getPngAssets($root))->toContain('Graphics/Incoming/Hut.png');
    // The same image again changes nothing; another one asks first.
    expect(expectAnswer($request('assets.import', ['source' => $incoming, 'destination' => $target]))['changed'])->toBeFalse();
    $other = dirname($incoming) . '/Other.png';
    file_put_contents($other, PngPixels::createBlank(4, 4)->encode());
    expect(expectRefusal($request('assets.import', ['source' => $other, 'destination' => 'Graphics/Incoming/Hut.png'])))
        ->toBe('assets/Graphics/Incoming/Hut.png already holds another image. Choose another name, or replace it.');
    expectAnswer($request('assets.import', ['source' => $other, 'destination' => 'Graphics/Incoming/Hut.png', 'replace' => true]));
    expect(file_get_contents($target))->toBe(file_get_contents($other));
    expectAnswer($request('history.undo'));
    expect(file_get_contents($target))->toBe(file_get_contents($incoming));
    expectAnswer($request('history.undo'));
    expect(is_file($target))->toBeFalse()->and(is_dir(dirname($target)))->toBeFalse();
    expectAnswer($request('history.redo'));
    expect(file_get_contents($target))->toBe(file_get_contents($incoming));

    // Only images the game draws come in, and only into the assets.
    $text = dirname($incoming) . '/notes.png';
    file_put_contents($text, 'not an image');
    expect(expectRefusal($request('assets.import', ['source' => $text, 'destination' => 'Graphics/notes.png'])))->toBe('notes.png is not a PNG image.')
        ->and(expectRefusal($request('assets.import', ['source' => $incoming, 'destination' => '../Hut.png'])))
        ->toBe('../Hut.png is not a place inside the assets folder.')
        ->and(expectRefusal($request('assets.import', ['source' => $incoming, 'destination' => dirname($root) . '/Hut.png'])))
        ->toBe("Images are brought into the project's assets folder. Choose a place inside it.")
        ->and(expectRefusal($request('assets.import', ['source' => $incoming, 'destination' => 'Graphics/Hut.jpg'])))
        ->toContain('is not a PNG\'s name');
});

it('copies an image\'s cells into free cells of a sheet pixel for pixel, never over art, with undo and redo', function () {
    $root = createBuildingProject();
    $request = createBuildingRpc($root);
    $incoming = writeIncomingBuildingArt(dirname($root) . '/' . basename($root) . '-incoming');
    rememberTemporaryProject(dirname($incoming));
    expectAnswer($request('assets.import', ['source' => $incoming, 'destination' => 'Graphics/Incoming/Hut.png']));
    $sheet = $root . '/assets/Graphics/Tilesets/Town_C.png';
    $before = (string) file_get_contents($sheet);

    $free = expectAnswer($request('tilesets.freeCells', ['index' => 0, 'tileset' => 'town']));
    expect($free['issues'])->toBe([])
        ->and($free['free'])->not->toContain(TileId::EMPTY, TileId::C)
        ->and($free['free'])->toContain(TileId::B + 1, TileId::C + 1, ...getHutTiles()[0]);
    expect(expectAnswer($request('tilesets.imageCells', ['index' => 0, 'tileset' => 'town', 'source' => 'Graphics/Incoming/Hut.png'])))
        ->toMatchArray(['tileSize' => 2, 'columns' => 3, 'rows' => 2, 'empty' => []]);

    // Onto art, or the empty tile, nothing is copied.
    expect(expectRefusal($request('tilesets.copyCells', ['index' => 0, 'tileset' => 'town', 'source' => 'Graphics/Incoming/Hut.png',
        'from' => [0, 0], 'size' => [3, 2], 'tile' => TileId::C])))->toBe('Sheet C already has art in cell 0, 0. Copy into free cells only; nothing was copied.')
        ->and(expectRefusal($request('tilesets.copyCells', ['index' => 0, 'tileset' => 'town', 'source' => 'Graphics/Incoming/Hut.png',
            'from' => [0, 0], 'size' => [1, 1], 'tile' => TileId::EMPTY])))->toContain('empty tile')
        ->and(expectRefusal($request('tilesets.copyCells', ['index' => 0, 'tileset' => 'town', 'source' => 'Graphics/Incoming/Hut.png',
            'from' => [0, 0], 'size' => [3, 2], 'tile' => TileId::getPlainTileAt(TilesetSheet::C, 14, 0)])))->toContain('do not fit sheet C')
        ->and(expectRefusal($request('tilesets.copyCells', ['index' => 0, 'tileset' => 'town', 'source' => 'Graphics/Incoming/Hut.png',
            'from' => [2, 1], 'size' => [2, 1], 'tile' => 263])))->toContain('has no 2 x 1 cells at 2, 1')
        ->and(file_get_contents($sheet))->toBe($before);

    $copied = expectAnswer($request('tilesets.copyCells', ['index' => 0, 'tileset' => 'town', 'source' => 'Graphics/Incoming/Hut.png',
        'from' => [0, 0], 'size' => [3, 2], 'tile' => 263]));
    expect($copied)->toBe(['sheet' => 'C', 'asset' => 'Graphics/Tilesets/Town_C.png', 'tiles' => getHutTiles()]);
    $art = PngPixels::decode((string) file_get_contents($incoming), 'art');
    $written = PngPixels::decode((string) file_get_contents($sheet), 'sheet');
    $original = PngPixels::decode($before, 'before');
    for ($y = 0; $y < 32; $y++) {
        for ($x = 0; $x < 32; $x++) {
            $inside = $x >= 14 && $x < 20 && $y < 4;
            expect($written->getPixel($x, $y))->toBe($inside ? $art->getPixel($x - 14, $y) : $original->getPixel($x, $y));
        }
    }
    // The tiles the copy names draw those cells, as the Engine composes them.
    expect(\Ichiloto\Engine\Rendering\Tilesets\TileComposer::compose(384, 2)[0][0]->x)->toBe(16);
    expect(expectAnswer($request('tilesets.freeCells', ['index' => 0, 'tileset' => 'town']))['free'])->not->toContain(...getHutTiles()[0]);

    expectAnswer($request('history.undo'));
    expect(file_get_contents($sheet))->toBe($before);
    expectAnswer($request('history.redo'));
    expect(PngPixels::decode((string) file_get_contents($sheet), 'sheet')->hasSamePixels($written))->toBeTrue();
    // A sheet changed outside the Editor is not overwritten by undo.
    file_put_contents($sheet, $before);
    expect(expectRefusal($request('history.undo')))->toContain('changed outside the cell copy');
});

it('defines a building piece over its cells: tiles, a glyph per cell, footprint, roof above characters and a painted door, with undo', function () {
    $root = createBuildingProject();
    $request = createBuildingRpc($root);
    $path = $root . '/assets/Data/Tilesets/town.php';
    defineHutPiece($root, $request);

    $piece = Tileset::load($root . '/assets', 'town')->pieces['hut'];
    expect($piece->name)->toBe('Hut')
        ->and($piece->layer)->toBe('buildings')
        ->and($piece->glyphs)->toBe([['/', '^', '\\'], ['|', '⊓', '|']])
        ->and($piece->tiles)->toBe(['houses' => array_map(static fn(array $row): array => array_map(strval(...), $row), getHutTiles())])
        ->and($piece->occupancy)->toBe([[null, null, null], [CollisionType::SOLID, CollisionType::SOLID, CollisionType::SOLID]])
        ->and($piece->doorway)->toBe([['x' => 1, 'y' => 1, 'entrance' => DoorwayEntrance::FRONT]])
        ->and(Tileset::load($root . '/assets', 'town')->above)->toBe(getHutTiles()[0])
        ->and(file_get_contents($path))->toContain('// The town\'s art.', '<fg=#ffd75f>⊓</>');

    // The tileset view lays the upper sheets out as their images do, and describes the piece cell by cell.
    $preview = expectAnswer($request('tilesets.preview', ['index' => 0]));
    $c = array_find($preview['palette']['tabs'], static fn(array $tab): bool => $tab['name'] === 'C');
    expect($c['ids'][0])->toBe(array_map(static fn(int $column): int => TileId::getPlainTileAt(TilesetSheet::C, $column, 0), range(0, 15)))
        ->and($preview['layerNames'])->toBe(['glyph' => ['buildings', 'terrain'], 'tiles' => ['houses']]);
    $authored = array_find($preview['authoring'], static fn(array $entry): bool => $entry['id'] === 'hut');
    expect($authored['cells'][1][1])->toBe(['symbol' => '⊓', 'color' => '#ffd75f'])
        ->and($authored['doorway'])->toBe([['x' => 1, 'y' => 1, 'entrance' => 'front']]);

    // Each cell edit is one undo step: the door, then a glyph, go back as they came.
    expectAnswer($request('tilesets.setPieceDoor', ['index' => 0, 'tileset' => 'town', 'piece' => 'hut', 'x' => 1, 'y' => 1, 'entrance' => null]));
    expectAnswer($request('tilesets.setPieceGlyph', ['index' => 0, 'tileset' => 'town', 'piece' => 'hut', 'x' => 1, 'y' => 1, 'glyph' => ' ']));
    $cleared = array_find(expectAnswer($request('tilesets.preview', ['index' => 0]))['authoring'], static fn(array $entry): bool => $entry['id'] === 'hut');
    expect($cleared['doorway'])->toBe([])->and($cleared['cells'][1][1]['symbol'])->toBe(' ');
    expectAnswer($request('history.undo'));
    expectAnswer($request('history.undo'));
    expectAnswer($request('database.save', ['category' => 'tilesets']));
    expect(Tileset::load($root . '/assets', 'town')->pieces['hut']->doorway)->toHaveCount(1)
        ->and(file_get_contents($path))->toContain('<fg=#ffd75f>⊓</>');

    // Plain words for what cannot be.
    expect(expectRefusal($request('tilesets.setPieceGlyph', ['index' => 0, 'tileset' => 'town', 'piece' => 'hut', 'x' => 0, 'y' => 0, 'glyph' => 'ab'])))
        ->toBe('A cell holds one character that fills one terminal cell.')
        ->and(expectRefusal($request('tilesets.setPieceDoor', ['index' => 0, 'tileset' => 'town', 'piece' => 'hut', 'x' => 3, 'y' => 0, 'entrance' => 'on'])))
        ->toBe('The piece has no cell 3, 0.')
        ->and(expectRefusal($request('tilesets.createPiece', ['index' => 0, 'tileset' => 'town', 'name' => '  ', 'layer' => 'buildings',
            'tileLayer' => 'houses', 'rows' => [[263]]])))->toBe('Give the piece a name.');
    // A second piece of the same name gets an id of its own.
    expect(expectAnswer($request('tilesets.createPiece', ['index' => 0, 'tileset' => 'town', 'name' => 'Hut', 'layer' => 'buildings',
        'tileLayer' => 'houses', 'rows' => [[263]]]))['id'])->toBe('hut-2');
    expectAnswer($request('history.undo'));
    expect(array_column(expectAnswer($request('tilesets.preview', ['index' => 0]))['authoring'], 'id'))->toBe(['hut']);
});

it('places the building with its footprint, gives its painted door an entrance, moves and removes it whole, with undo and redo', function () {
    $root = createBuildingProject();
    $request = createBuildingRpc($root);
    defineHutPiece($root, $request);
    $town = readTown($request);

    // Placing it writes its glyphs, its tiles and, asked, the collision its footprint gives, in one step.
    $placed = expectAnswer($request('pieces.place', ['map' => 'town', 'revision' => $town['revision'], 'piece' => 'hut',
        'from' => [2, 1], 'to' => [2, 1], 'footprint' => true]));
    expect($placed)->toMatchArray(['changed' => true, 'copies' => 1, 'footprint' => 3]);
    $town = readTown($request);
    expect(array_slice($town['glyphs'], 1, 2))->toBe(['  /^\\   ', '  |⊓|   '])
        ->and($town['occupancy'][1])->toBe([0, 0, 0, 0, 0, 0, 0, 0])
        ->and($town['occupancy'][2])->toBe([0, 0, 1, 1, 1, 0, 0, 0]);

    // Selected, it says where it stands and that its door has no entrance yet; validation says so too.
    $found = expectAnswer($request('pieces.findPlaced', ['map' => 'town', 'x' => 3, 'y' => 2]))['pieces'];
    expect($found)->toBe([['id' => 'hut', 'name' => 'Hut', 'x' => 2, 'y' => 1, 'width' => 3, 'height' => 2, 'footprint' => true,
        'doors' => [['x' => 3, 'y' => 2, 'entrance' => 'front', 'entranceCell' => [3, 3], 'event' => null]]]])
        ->and(expectAnswer($request('pieces.findPlaced', ['map' => 'town', 'x' => 0, 'y' => 0]))['pieces'])->toBe([]);
    $doorway = static fn(): array => array_values(array_filter(expectAnswer($request('project.validate', ['maps' => ['town']]))['issues'],
        static fn(array $issue): bool => ($issue['code'] ?? null) === 'object.doorway'));
    expect(array_column($doorway(), 'message'))->toBe(["Piece 'hut' at 2,1 paints a door at 3,2 entered from the front, with no entrance at 3,3. At (3, 2)."]);

    // The door's entrance goes on the cell in front of it, then leads inside like any transfer.
    $entrance = expectAnswer($request('pieces.addEntrance', ['map' => 'town', 'revision' => readTown($request)['revision'], 'piece' => 'hut',
        'x' => 2, 'y' => 1, 'door' => 0]));
    expect($entrance['cell'])->toBe([3, 3]);
    expectAnswer($request('event.destination', ['map' => 'town', 'revision' => $entrance['revision'], 'marker' => $entrance['marker'],
        'destination' => 'inside', 'x' => 1, 'y' => 1, 'heading' => 'North']));
    expect($doorway())->toBe([])
        ->and(expectAnswer($request('pieces.findPlaced', ['map' => 'town', 'x' => 2, 'y' => 1]))['pieces'][0]['doors'][0]['event'])->toBe($entrance['marker'])
        ->and(expectRefusal($request('pieces.addEntrance', ['map' => 'town', 'revision' => readTown($request)['revision'], 'piece' => 'hut',
            'x' => 2, 'y' => 1, 'door' => 0])))->toBe("Entrance {$entrance['marker']} already meets that door.");

    // Moved, it takes its glyphs, tiles, collision and entrance with it, and leaves open ground behind.
    $moved = expectAnswer($request('pieces.move', ['map' => 'town', 'revision' => readTown($request)['revision'], 'piece' => 'hut',
        'from' => [2, 1], 'to' => [4, 2], 'footprint' => true]));
    expect($moved['entrances'])->toBe([$entrance['marker']]);
    $town = readTown($request);
    expect(array_slice($town['glyphs'], 1, 3))->toBe(['        ', '    /^\\ ', '    |⊓| '])
        ->and($town['occupancy'][2])->toBe([0, 0, 0, 0, 0, 0, 0, 0])
        ->and($town['occupancy'][3])->toBe([0, 0, 0, 0, 1, 1, 1, 0])
        ->and($town['events'][$entrance['marker']])->toBe([[5, 4]])
        ->and(expectAnswer($request('pieces.findPlaced', ['map' => 'town', 'x' => 5, 'y' => 3]))['pieces'][0]['x'])->toBe(4)
        ->and(expectAnswer($request('pieces.findPlaced', ['map' => 'town', 'x' => 3, 'y' => 2]))['pieces'])->toBe([])
        ->and($doorway())->toBe([]);
    $houses = array_find(expectAnswer($request('tiles.read', ['map' => 'town']))['layers'], static fn(array $layer): bool => $layer['name'] === 'houses');
    expect(array_slice($houses['rows'][1], 2, 3))->toBe([0, 0, 0])
        ->and(array_slice($houses['rows'][2], 4, 3))->toBe(getHutTiles()[0])
        ->and(array_slice($houses['rows'][3], 4, 3))->toBe(getHutTiles()[1]);

    // One undo puts it back where it stood, entrance and all; redo moves it again.
    expectAnswer($request('history.undo'));
    $back = readTown($request);
    expect($back['occupancy'][2])->toBe([0, 0, 1, 1, 1, 0, 0, 0])->and($back['events'][$entrance['marker']])->toBe([[3, 3]]);
    expectAnswer($request('history.redo'));
    expect(readTown($request)['events'][$entrance['marker']])->toBe([[5, 4]]);

    // Removed, it takes its glyphs, tiles and collision away; open ground is walkable again.
    $removed = expectAnswer($request('pieces.remove', ['map' => 'town', 'revision' => readTown($request)['revision'], 'piece' => 'hut',
        'x' => 4, 'y' => 2, 'footprint' => true]));
    expect($removed['freed'])->toBe(3);
    $town = readTown($request);
    expect(implode('', $town['glyphs']))->toBe(str_repeat(' ', 48))
        ->and(array_merge(...$town['occupancy']))->toBe(array_fill(0, 48, 0))
        ->and(expectAnswer($request('pieces.findPlaced', ['map' => 'town', 'x' => 5, 'y' => 3]))['pieces'])->toBe([])
        ->and(expectRefusal($request('pieces.remove', ['map' => 'town', 'revision' => $town['revision'], 'piece' => 'hut',
            'x' => 4, 'y' => 2, 'footprint' => true])))->toBe('No whole Hut stands at 4, 2 any more. Nothing was changed.');
    expectAnswer($request('history.undo'));
    expect(readTown($request)['occupancy'][3])->toBe([0, 0, 0, 0, 1, 1, 1, 0]);

    // Saved and opened again, the moved building is there as the game reads it.
    expectAnswer($request('map.save', ['map' => 'town']));
    $reopened = createBuildingRpc($root);
    expect(expectAnswer($reopened('pieces.findPlaced', ['map' => 'town', 'x' => 6, 'y' => 3]))['pieces'][0])->toMatchArray(['x' => 4, 'y' => 2]);
});

it('refuses moves that cannot be whole and placement without a footprint leaves collision alone', function () {
    $root = createBuildingProject();
    $request = createBuildingRpc($root);
    defineHutPiece($root, $request);
    $placed = expectAnswer($request('pieces.place', ['map' => 'town', 'revision' => readTown($request)['revision'], 'piece' => 'hut',
        'from' => [0, 0], 'to' => [0, 0]]));
    expect($placed['footprint'])->toBe(0)->and(array_merge(...readTown($request)['occupancy']))->toBe(array_fill(0, 48, 0));
    $before = readTown($request);
    expect(expectRefusal($request('pieces.move', ['map' => 'town', 'revision' => $before['revision'], 'piece' => 'hut',
        'from' => [0, 0], 'to' => [6, 0], 'footprint' => true])))->toContain('does not fit at (6, 0)');
    expect(readTown($request)['glyphs'])->toBe($before['glyphs']);
});

it('writes a review page of a map as the game draws it, unsaved edits included, without changing the project', function () {
    $root = createBuildingProject();
    $request = createBuildingRpc($root);
    defineHutPiece($root, $request);
    expectAnswer($request('pieces.place', ['map' => 'town', 'revision' => readTown($request)['revision'], 'piece' => 'hut',
        'from' => [2, 1], 'to' => [2, 1], 'footprint' => true]));
    $out = rememberTemporaryProject(sys_get_temp_dir() . '/' . uniqid('editor-building-review-', true));
    $before = sourceHashTree($root . '/assets');

    $review = expectAnswer($request('map.review', ['map' => 'town', 'directory' => $out, 'region' => [1, 0, 5, 4], 'player' => [3, 3]]));
    expect($review['page'])->toBe($out . '/review.html')
        ->and(file_get_contents($review['page']))->toContain('<img')
        ->and(is_file($review['files']['png']))->toBeTrue()
        ->and(file_get_contents($review['files']['terminal-ans']))->toContain('⊓')
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
    expect(expectRefusal($request('map.review', ['map' => 'town', 'directory' => $out, 'region' => [7, 5, 4, 4]])))->not->toBe('');
});
