<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Maps\TileLayerSource;
use Ichiloto\Editor\ProjectMap;

/**
 * Copying, cutting and pasting a gameplay layer's glyphs carries the tiles
 * that move with that layer, cell for cell, as one undo step. On the 4 x 2
 * graphics test map, decor moves with buildings because only the Bed piece
 * (on buildings) writes it, and floor moves with terrain because only the
 * Rug piece does, unless the map data names otherwise.
 */

/** @return array{Editor, ProjectMap} */
function createMovingTilesEditor(string $settings = ''): array
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: buildMovingTilePieces());
    if ($settings !== '') {
        $data = $root . '/assets/Maps/test-map/test-map.data.php';
        file_put_contents($data, str_replace("'tileset' => 'home',", "'tileset' => 'home', {$settings}", (string) file_get_contents($data)));
    }
    [$editor, $map] = layeredCanvasEditor($root);

    return [$editor, $map];
}

/** @return array<string, array<string, mixed>> */
function buildMovingTilePieces(): array
{
    return [
        'bed' => ['name' => 'Bed', 'layer' => 'buildings', 'glyphs' => ['='], 'tiles' => ['decor' => ['5']]],
        'rug' => ['name' => 'Rug', 'layer' => 'terrain', 'glyphs' => ['~'], 'tiles' => ['floor' => ['2816']]],
    ];
}

/** @return list<list<string>> */
function readMovingTileRows(ProjectMap $map, string $file): array
{
    return TileLayerSource::readLayer($map->getTileLayerSources()[$map->directory . '/graphics/' . $file], $file)->getEntries();
}

function selectCanvasBlock(Editor $editor, string $layer, int $x, int $y, int $width, int $height): void
{
    callEditorMethod($editor, 'selectCanvasLayer', $layer);
    setEditorProperty($editor, 'canvasSelection', ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]);
}

it('finds the tile layers that move with a gameplay layer from its tileset pieces', function () {
    [, $map] = createMovingTilesEditor();

    expect($map->getTileLayersMovingWith('map:4'))->toBe(['decor'])
        ->and($map->getTileLayersMovingWith('map:1'))->toBe(['floor'])
        ->and($map->getTileLayersMovingWith('event'))->toBe([]);
});

it('lets the map data name the gameplay layer a tile layer moves with', function () {
    [, $map] = createMovingTilesEditor("'tileLayers' => ['floor' => ['movesWith' => 'buildings']],");

    expect($map->getTileLayersMovingWith('map:4'))->toBe(['floor', 'decor'])
        ->and($map->getTileLayersMovingWith('map:1'))->toBe([]);
});

it('cuts and pastes a block with its tiles, one undo step each, leaving other tile layers alone', function () {
    [$editor, $map] = createMovingTilesEditor();
    $floor = readMovingTileRows($map, '01.floor.tiles.php');
    $original = $map->getTileLayerSources();
    $glyphs = [$map->getLayerSymbol('map:4', 1, 0), $map->getLayerSymbol('map:4', 1, 1)];
    expect(readMovingTileRows($map, '02.decor.tiles.php'))->toBe([['0', '5', '0', '0'], ['0', '0', '0', '5']]);

    selectCanvasBlock($editor, 'map:4', 1, 0, 1, 2);
    callEditorMethod($editor, 'cutCanvasSelection');
    expect(readMovingTileRows($map, '02.decor.tiles.php'))->toBe([['0', '0', '0', '0'], ['0', '0', '0', '5']])
        ->and($map->getLayerSymbol('map:4', 1, 0))->toBe(' ');

    setEditorProperty($editor, 'cursorX', 2);
    setEditorProperty($editor, 'cursorY', 0);
    callEditorMethod($editor, 'pasteCanvasClipboard');
    // The block replaces the tiles under it as it replaces the glyphs.
    expect(readMovingTileRows($map, '02.decor.tiles.php'))->toBe([['0', '0', '5', '0'], ['0', '0', '0', '5']])
        ->and([$map->getLayerSymbol('map:4', 2, 0), $map->getLayerSymbol('map:4', 2, 1)])->toBe($glyphs)
        ->and(readMovingTileRows($map, '01.floor.tiles.php'))->toBe($floor);

    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect(readMovingTileRows($map, '02.decor.tiles.php'))->toBe([['0', '0', '0', '0'], ['0', '0', '0', '5']]);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->getTileLayerSources())->toBe($original)
        ->and([$map->getLayerSymbol('map:4', 1, 0), $map->getLayerSymbol('map:4', 1, 1)])->toBe($glyphs);
});

it('copies the tiles of the layers the map data names, and none from the event layer', function () {
    [$editor, $map] = createMovingTilesEditor("'tileLayers' => ['floor' => ['movesWith' => 'buildings']],");

    selectCanvasBlock($editor, 'map:4', 0, 0, 2, 1);
    callEditorMethod($editor, 'copyCanvasSelection');
    expect(getEditorProperty($editor, 'clipboard')->projectTiles(0, 1, 4, 2))->toBe([
        'floor' => [['x' => 0, 'y' => 1, 'entry' => '2816'], ['x' => 1, 'y' => 1, 'entry' => '2816']],
        'decor' => [['x' => 0, 'y' => 1, 'entry' => '0'], ['x' => 1, 'y' => 1, 'entry' => '5']],
    ]);

    selectCanvasBlock($editor, 'event', 0, 0, 2, 1);
    callEditorMethod($editor, 'copyCanvasSelection');
    expect(getEditorProperty($editor, 'clipboard')->projectTiles(0, 1, 4, 2))->toBe([]);
});
