<?php

declare(strict_types=1);

use Ichiloto\Editor\Canvas\CanvasClipboard;
use Ichiloto\Editor\Canvas\CanvasEditor;
use Ichiloto\Editor\Canvas\Clipboard;
use Ichiloto\Editor\Canvas\GlyphTilePlanner;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Validation\MapGraphicsValidator;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/**
 * Erasing a piece that keeps the tiles beneath it, such as a window mounted on
 * a wall face, gives the cell back to the role those kept tiles prove, so the
 * terminal and collision role returns with the face that never left.
 * Synthetic pieces and maps only.
 */

/** A synthetic piece on the buildings layer, read through the Engine's own contract. */
function createUnderlayPiece(string $id, array $data): TilesetPiece
{
    return TilesetPiece::fromArray($id, ['name' => $id, 'layer' => 'buildings'] + $data, 'Synthetic underlay');
}

/** @param list<TilesetPiece> $pieces */
function planUnderlayErase(array $pieces, string $old, array $tiles, array $choices = []): array
{
    return GlyphTilePlanner::fromPieces($pieces, 'buildings')->plan([['x' => 0, 'y' => 0, 'old' => $old, 'new' => ' ']],
        static fn(int $x, int $y): ?string => $x === 0 && $y === 0 ? $old : null,
        static fn(string $layer, int $x, int $y): string => $x === 0 && $y === 0 ? ($tiles[$layer] ?? '0') : '0',
        $choices);
}

/** @return array<string, string> The face, window and an unrelated piece. */
function underlayPieces(): array
{
    return [
        createUnderlayPiece('plaster', ['glyphs' => ['#'], 'tiles' => ['walls' => ['10']]]),
        createUnderlayPiece('stone', ['glyphs' => ['%'], 'tiles' => ['walls' => ['11']]]),
        createUnderlayPiece('window', ['glyphs' => ['w'], 'tiles' => ['decor' => ['5']], 'keeps' => ['walls']]),
        createUnderlayPiece('sign', ['glyphs' => ['s'], 'tiles' => ['decor' => ['6']]]),
    ];
}

it('gives an erased mounted window back to the wall face its kept tiles prove', function () {
    $plan = planUnderlayErase(underlayPieces(), 'w', ['walls' => '10', 'decor' => '5']);

    expect(array_map(static fn(array $glyph): array => [$glyph['symbol'], $glyph['role']->key], $plan['glyphs']))
        ->toBe([['#', 'plaster:0:0']])
        // The window's own tile goes; the face it kept stays exactly as it was.
        ->and($plan['tiles'])->toBe(['decor' => [['x' => 0, 'y' => 0, 'entry' => '0']]])
        ->and($plan['unresolved'])->toBe([]);
});

it('leaves an erased cell blank when nothing kept proves a role', function (string $old, array $tiles) {
    $plan = planUnderlayErase(underlayPieces(), $old, $tiles);

    expect($plan['glyphs'])->toBe([])->and($plan['unresolved'])->toBe([]);
})->with([
    'a piece that keeps nothing' => ['s', ['walls' => '10', 'decor' => '6']],
    'a mounted window with no face behind it' => ['w', ['decor' => '5']],
    'a kept tile no piece draws' => ['w', ['walls' => '42', 'decor' => '5']],
]);

it('asks which face an erase restores when the kept tiles prove several, and keeps the answer', function () {
    $pieces = [...underlayPieces(), createUnderlayPiece('panel', ['glyphs' => ['P'], 'tiles' => ['walls' => ['10']]])];
    $tiles = ['walls' => '10', 'decor' => '5'];

    expect(array_map(static fn($role): string => $role->key, planUnderlayErase($pieces, 'w', $tiles)['unresolved'][' ']))
        ->toBe(['plaster:0:0', 'panel:0:0'])
        ->and(planUnderlayErase($pieces, 'w', $tiles)['glyphs'])->toBe([])
        ->and(planUnderlayErase($pieces, 'w', $tiles, [' ' => 'panel:0:0'])['glyphs'][0]['symbol'])->toBe('P')
        // Choosing none leaves the cell blank, as an author may want.
        ->and(planUnderlayErase($pieces, 'w', $tiles, [' ' => null])['glyphs'])->toBe([])
        ->and(planUnderlayErase($pieces, 'w', $tiles, [' ' => null])['unresolved'])->toBe([]);
});

/** A synthetic map with a plaster face at (1,0) and (2,0), a window mounted on (1,0), and the editor's buildings layer id. */
function mountedWindowMap(): array
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: [
        'plaster' => ['name' => 'Plaster', 'layer' => 'buildings', 'glyphs' => ['#'], 'tiles' => ['walls' => ['10']]],
        'window' => ['name' => 'Window', 'layer' => 'buildings', 'glyphs' => ['w'], 'tiles' => ['decor' => ['5']], 'keeps' => ['walls']],
    ]);
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/layers/04.buildings.map.php',
        Ichiloto\Engine\Field\MapGridSource::buildSource(" w# \n    ", 'AUTHORED', "// keep 04.buildings.map.php\n"));
    writeTileLayer($directory, '02.decor.tiles.php', "0 5 0 0\n0 0 0 0");
    writeTileLayer($directory, '03.walls.tiles.php', "0 10 10 0\n0 0 0 0");
    $map = loadLayeredMap($root);
    $layerId = array_find($map->getLayers(), static fn(array $layer): bool => $layer['name'] === 'buildings')['id'];

    return [$root, $map, $layerId];
}

/** @return list<string> Validation's warnings about piece tiles whose glyph has gone, for the map as it stands in memory. */
function readStaleUnderlayIssues(ProjectMap $map): array
{
    return array_values(array_map(static fn($issue): string => $issue->message, array_filter(MapGraphicsValidator::validateCoverage($map),
        static fn($issue): bool => str_contains($issue->message, 'no longer there'))));
}

/** @return array{string, string, string} The buildings glyph at (1,0) and the decor and walls tiles there. */
function readMountedCell(ProjectMap $map, string $layerId): array
{
    $tile = CanvasEditor::createTileReader($map);

    return [$map->getLayerSymbol($layerId, 1, 0), $tile('decor', 1, 0), $tile('walls', 1, 0)];
}

it('restores the face when a window is erased, as one undoable step that saves as authored', function () {
    [$root, $map, $layerId] = mountedWindowMap();
    $writes = [['x' => 1, 'y' => 0, 'symbol' => ' ', 'color' => null]];
    $plan = CanvasEditor::plan($map, $layerId, $writes);
    $applied = CanvasEditor::apply($map, $layerId, $plan['writes'], 'Erase', $plan['tiles']);

    expect(readMountedCell($map, $layerId))->toBe(['#', '0', '10'])
        ->and(readStaleUnderlayIssues($map))->toBe([]);
    $applied['command']->undo();
    expect(readMountedCell($map, $layerId))->toBe(['w', '5', '10']);
    $applied['command']->execute();
    expect(readMountedCell($map, $layerId))->toBe(['#', '0', '10']);

    $map->save();
    $reloaded = loadLayeredMap($root);
    expect(readMountedCell($reloaded, $layerId))->toBe(['#', '0', '10'])
        ->and((string) file_get_contents($root . '/assets/Maps/test-map/layers/04.buildings.map.php'))
        ->toContain("// keep 04.buildings.map.php\n")->toContain(" ## \n");
});

it('moves a mounted window with the face it sits on, leaving nothing stale behind', function () {
    [$root, $map, $layerId] = mountedWindowMap();
    $clipboard = new Clipboard();
    CanvasClipboard::cut($map, $layerId, 1, 0, 1, 1, $clipboard);

    // A cut takes the whole block, face included: the cell it leaves holds nothing.
    expect(readMountedCell($map, $layerId))->toBe([' ', '0', '0']);
    CanvasClipboard::paste($map, $layerId, $clipboard, 1, 1);
    $tile = CanvasEditor::createTileReader($map);
    expect([$map->getLayerSymbol($layerId, 1, 1), $tile('decor', 1, 1), $tile('walls', 1, 1)])->toBe(['w', '5', '10'])
        // The face beside it, and its other layers, are untouched.
        ->and([$map->getLayerSymbol($layerId, 2, 0), $tile('walls', 2, 0)])->toBe(['#', '10'])
        ->and(readStaleUnderlayIssues($map))->toBe([]);
});
