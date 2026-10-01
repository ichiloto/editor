<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Maps\TileLayerSource;
use Ichiloto\Editor\ProjectMap;
use Atatusoft\Termutil\IO\Mouse\Enumerations\MouseButton;

/**
 * Tiles follow glyphs however they are edited: typing, painting, erasing,
 * the tools and the mouse all keep a gameplay layer's pieces drawn where
 * their glyphs are, in the same undo step. On the 4 x 2 graphics test map
 * the buildings layer holds ` /  ` over ` xx `, and its pieces draw on a
 * furniture tile layer the map does not have yet.
 */

/** @return array{Editor, ProjectMap} */
function createFollowingTilesEditor(): array
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: [
        'chest' => ['name' => 'Chest', 'layer' => 'buildings', 'glyphs' => ['m'], 'tiles' => ['furniture' => ['60']]],
        'chair-facing-south' => ['name' => 'Chair facing south', 'layer' => 'buildings', 'glyphs' => ['-'], 'tiles' => ['furniture' => ['50']]],
        'chair-facing-north' => ['name' => 'Chair facing north', 'layer' => 'buildings', 'glyphs' => ['-'], 'tiles' => ['furniture' => ['51']]],
        'table' => ['name' => 'Dining table', 'layer' => 'buildings', 'glyphs' => ['##'], 'tiles' => ['furniture' => ['40 41']]],
        // The sofa's back rises into the row above its seat.
        'sofa' => ['name' => 'Sofa', 'layer' => 'buildings', 'glyphs' => ['  ', '##'], 'tiles' => ['furniture' => ['70 71', '72 73']]],
    ]);
    [$editor, $map] = layeredCanvasEditor($root);
    callEditorMethod($editor, 'selectCanvasLayer', 'map:4');

    return [$editor, $map];
}

/** @return list<list<string>> The furniture layer's entries, all `0` while the map has none. */
function readFurnitureRows(ProjectMap $map): array
{
    $source = $map->getTileLayerSources()[$map->directory . '/graphics/03.furniture.tiles.php'] ?? null;

    return $source === null ? [['0', '0', '0', '0'], ['0', '0', '0', '0']]
        : TileLayerSource::readLayer($source, '03.furniture.tiles.php')->getEntries();
}

function typeCanvasGlyph(Editor $editor, string $glyph, int $x, int $y): void
{
    setEditorProperty($editor, 'cursorX', $x);
    setEditorProperty($editor, 'cursorY', $y);
    callEditorMethod($editor, 'adoptPaintSymbol', $glyph);
}

function choosePieceFor(Editor $editor, string $label): void
{
    $entries = getEditorProperty($editor, 'eventOptionDialogEntries');
    setEditorProperty($editor, 'selectedEventOptionIndex', array_search($label, array_column($entries, 'label'), true));
    callEditorMethod($editor, 'applySelectedEventOption');
}

it('stamps a typed glyph\'s tiles and clears them when it is erased, one undo step each, leaving other tiles alone', function () {
    [$editor, $map] = createFollowingTilesEditor();
    $others = [$map->directory . '/graphics/01.floor.tiles.php', $map->directory . '/graphics/02.decor.tiles.php'];
    $before = array_intersect_key($map->getTileLayerSources(), array_flip($others));

    typeCanvasGlyph($editor, 'm', 0, 0);
    expect($map->getLayerSymbol('map:4', 0, 0))->toBe('m')
        ->and(readFurnitureRows($map))->toBe([['60', '0', '0', '0'], ['0', '0', '0', '0']]);

    typeCanvasGlyph($editor, ' ', 0, 0);
    expect(readFurnitureRows($map))->toBe([['0', '0', '0', '0'], ['0', '0', '0', '0']])
        ->and(array_intersect_key($map->getTileLayerSources(), array_flip($others)))->toBe($before);

    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->getLayerSymbol('map:4', 0, 0))->toBe('m')
        ->and(readFurnitureRows($map)[0][0])->toBe('60');
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->getLayerSymbol('map:4', 0, 0))->toBe(' ')
        ->and(readFurnitureRows($map)[0][0])->toBe('0');
});

it('asks which piece a shared glyph is, and the brush remembers the answer until a glyph is typed again', function () {
    [$editor, $map] = createFollowingTilesEditor();

    typeCanvasGlyph($editor, '-', 2, 0);
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeTrue()
        ->and(array_column(getEditorProperty($editor, 'eventOptionDialogEntries'), 'label'))
            ->toBe(['Chair facing south', 'Chair facing north', 'No tiles'])
        ->and($map->getLayerSymbol('map:4', 2, 0))->toBe(' ');

    choosePieceFor($editor, 'Chair facing south');
    expect($map->getLayerSymbol('map:4', 2, 0))->toBe('-')
        ->and(readFurnitureRows($map)[0])->toBe(['0', '0', '50', '0']);

    // Painting the brush again draws the same chair without asking.
    setEditorProperty($editor, 'cursorX', 3);
    callEditorMethod($editor, 'applySelectedPaintSymbol');
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and(readFurnitureRows($map)[0])->toBe(['0', '0', '50', '50']);

    // A chair moved by erasing and typing takes its tiles along.
    typeCanvasGlyph($editor, ' ', 2, 0);
    typeCanvasGlyph($editor, '-', 0, 1);
    choosePieceFor($editor, 'Chair facing north');
    expect(readFurnitureRows($map))->toBe([['0', '0', '0', '50'], ['51', '0', '0', '0']]);
});

it('leaves the map as it was when the choice is dismissed', function () {
    [$editor, $map] = createFollowingTilesEditor();
    $sources = $map->getTileLayerSources();

    typeCanvasGlyph($editor, '-', 2, 0);
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and($map->getLayerSymbol('map:4', 2, 0))->toBe(' ')
        ->and($map->getTileLayerSources())->toBe($sources)
        ->and(getEditorProperty($editor, 'statusMessage'))->toBe('Nothing was painted; the map is as it was.');
});

it('gives a glyph the part of a piece its neighbours prove, without asking', function () {
    [$editor, $map] = createFollowingTilesEditor();

    typeCanvasGlyph($editor, '#', 2, 0);
    choosePieceFor($editor, 'Dining table (left)');
    typeCanvasGlyph($editor, '#', 3, 0);
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and(readFurnitureRows($map)[0])->toBe(['0', '0', '40', '41']);
});

it('carries the tiles of a piece\'s blank cells with the glyph nearest them', function () {
    [$editor, $map] = createFollowingTilesEditor();

    typeCanvasGlyph($editor, '#', 0, 1);
    choosePieceFor($editor, 'Sofa (left)');
    expect(readFurnitureRows($map))->toBe([['70', '0', '0', '0'], ['72', '0', '0', '0']]);

    typeCanvasGlyph($editor, ' ', 0, 1);
    expect(readFurnitureRows($map))->toBe([['0', '0', '0', '0'], ['0', '0', '0', '0']]);
});

it('picks up the piece a glyph draws with the eyedropper', function () {
    [$editor, $map] = createFollowingTilesEditor();
    typeCanvasGlyph($editor, '-', 2, 0);
    choosePieceFor($editor, 'Chair facing north');

    callEditorMethod($editor, 'pickSymbolUnderCursor');
    expect(getEditorProperty($editor, 'statusMessage'))->toBe('Picked up - (Chair facing north) from (2, 0).');

    setEditorProperty($editor, 'cursorX', 3);
    setEditorProperty($editor, 'cursorY', 1);
    callEditorMethod($editor, 'applySelectedPaintSymbol');
    expect(readFurnitureRows($map)[1])->toBe(['0', '0', '0', '51']);
});

it('keeps a recoloured glyph\'s tiles', function () {
    [$editor, $map] = createFollowingTilesEditor();
    typeCanvasGlyph($editor, 'm', 0, 0);
    $sources = $map->getTileLayerSources();

    callEditorMethod($editor, 'applyCanvasWrites', $map, [['x' => 0, 'y' => 0, 'symbol' => 'm', 'color' => 'red']], 'Recolour');
    expect($map->getTileLayerSources())->toBe($sources);
});

it('records a mouse stroke\'s tiles with its glyphs as one undo step', function () {
    [$editor, $map] = createFollowingTilesEditor();
    setEditorProperty($editor, 'selectedPaintSymbol', 'm');
    $sources = $map->getTileLayerSources();

    callEditorMethod($editor, 'paintCanvasStroke', $map, 'm', 0, 1, MouseButton::LEFT_BUTTON);
    callEditorMethod($editor, 'paintCanvasStroke', $map, 'm', 2, 1, MouseButton::LEFT_BUTTON);
    callEditorMethod($editor, 'finalizeActiveStroke');
    expect(readFurnitureRows($map)[1])->toBe(['60', '60', '60', '0']);

    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->getTileLayerSources())->toBe($sources)
        ->and($map->getLayerSymbol('map:4', 1, 1))->toBe('x');
});

it('draws the tiles for the glyphs already on a layer with T, asking about a shared glyph, in one undo step', function () {
    [$editor, $map] = createFollowingTilesEditor();
    // The buildings layer already holds ` /  ` over ` xx `, authored before
    // any piece drew tiles for them.
    writeTestTileset(dirname($map->directory, 3), pieces: [
        'crate' => ['name' => 'Crate', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['furniture' => ['60']]],
        'banner-red' => ['name' => 'Red banner', 'layer' => 'buildings', 'glyphs' => ['/'], 'tiles' => ['furniture' => ['70']]],
        'banner-blue' => ['name' => 'Blue banner', 'layer' => 'buildings', 'glyphs' => ['/'], 'tiles' => ['furniture' => ['71']]],
    ]);
    $glyphs = [$map->getLayerSymbol('map:4', 1, 0), $map->getLayerSymbol('map:4', 1, 1), $map->getLayerSymbol('map:4', 2, 1)];
    setEditorProperty($editor, 'focusedPane', 'canvas');

    callEditorMethod($editor, 'dispatchInput', 'T');
    expect(getEditorProperty($editor, 'eventOptionDialogTitle'))->toBe('Piece for /')
        ->and(readFurnitureRows($map))->toBe([['0', '0', '0', '0'], ['0', '0', '0', '0']]);

    choosePieceFor($editor, 'Blue banner');
    expect(readFurnitureRows($map))->toBe([['0', '71', '0', '0'], ['0', '60', '60', '0']])
        ->and([$map->getLayerSymbol('map:4', 1, 0), $map->getLayerSymbol('map:4', 1, 1), $map->getLayerSymbol('map:4', 2, 1)])->toBe($glyphs)
        ->and($map->isDirty())->toBeTrue();

    // Drawing again changes nothing.
    callEditorMethod($editor, 'dispatchInput', 'T');
    choosePieceFor($editor, 'Blue banner');
    expect(getEditorProperty($editor, 'toasts')->current()?->message)->toBe('Every glyph on the Buildings layer already has its tiles.');

    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect(readFurnitureRows($map))->toBe([['0', '0', '0', '0'], ['0', '0', '0', '0']]);
});
