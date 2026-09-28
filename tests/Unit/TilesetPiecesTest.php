<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Engine\Field\MapGraphics;

/**
 * Tileset pieces on the terminal canvas: P chooses a piece of the map's
 * tileset and Enter stamps its glyphs and tiles as one undo step. The layered
 * test map is 4 x 2 cells: terrain `./..` over `.xx.` from buildings.
 */

/** @return array<string, array<string, mixed>> Synthetic pieces for tileset `home`. */
function buildTestPieces(): array
{
    return [
        'bed' => ['name' => 'Bed', 'layer' => 'buildings', 'glyphs' => ['= ', '=H'],
            'tiles' => ['decor' => ['5L 5R', '6 0'], 'furniture' => ['7 0', '0 8R']]],
        'lamp' => ['name' => 'Lamp', 'layer' => 'fixtures', 'glyphs' => ['i']],
        'rug' => ['name' => 'Rug', 'layer' => 'terrain', 'glyphs' => ['~~'], 'tiles' => ['floor' => ['2816 2816']]],
    ];
}

/**
 * The editor on the graphics test map, its tileset listing the test pieces.
 *
 * @return array{Editor, ProjectMap, string}
 */
function createPieceCanvasEditor(bool $ragged = false): array
{
    $root = mapGraphicsProject($ragged);
    writeTestTileset($root, pieces: buildTestPieces());

    return layeredCanvasEditor($root);
}

function getCurrentToast(Editor $editor): ?Ichiloto\Editor\Status\Toast
{
    return getEditorProperty($editor, 'toasts')->current();
}

function choosePieceByKeys(Editor $editor, int $downs = 0): void
{
    callEditorMethod($editor, 'dispatchInput', 'P');
    for ($i = 0; $i < $downs; $i++) {
        callEditorMethod($editor, 'dispatchInput', "\033[B");
    }
    callEditorMethod($editor, 'dispatchInput', "\n");
}

it('lists the pieces of the map\'s tileset in the shared picker with P', function () {
    [$editor] = createPieceCanvasEditor();
    callEditorMethod($editor, 'dispatchInput', 'P');

    expect(getEditorProperty($editor, 'eventOptionDialogTitle'))->toBe('Piece')
        ->and(getEditorProperty($editor, 'eventOptionDialogEntries'))->toBe([
            ['label' => 'Bed', 'value' => 'bed', 'description' => '2 x 2 · buildings · tiles: decor, furniture'],
            ['label' => 'Lamp', 'value' => 'lamp', 'description' => '1 x 1 · fixtures'],
            ['label' => 'Rug', 'value' => 'rug', 'description' => '2 x 1 · terrain · tiles: floor'],
        ]);

    // Esc cancels without placing anything.
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect(getEditorProperty($editor, 'eventOptionDialogEntries'))->toBe([])
        ->and(getEditorProperty($editor, 'piecePlacement'))->toBeNull();

    // A filter narrows the list; Enter chooses the match.
    foreach (['P', '/', 'L', 'a', "\n", "\n"] as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    expect(getEditorProperty($editor, 'piecePlacement')['piece']->id)->toBe('lamp');
});

it('offers one piece entry in the command palette', function () {
    [$editor] = createPieceCanvasEditor();
    $labels = array_map(static fn($item): string => $item->label . ' ' . $item->hint, callEditorMethod($editor, 'buildPaletteItems'));

    expect(array_values(array_filter($labels, static fn(string $label): bool => str_starts_with($label, 'Pieces:'))))
        ->toBe(['Pieces: Choose a piece to place P']);
});

it('says why a map has no pieces and opens nothing', function (string $case, string $message) {
    $root = $case === 'no tileset' ? layeredMapProject() : mapGraphicsProject();
    if ($case === 'broken tileset') {
        file_put_contents($root . '/assets/Data/Tilesets/home.php', "<?php\n\nreturn ['name' => 'Home'];\n");
    }
    [$editor, $map] = layeredCanvasEditor($root);
    callEditorMethod($editor, 'dispatchInput', 'P');

    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and(getCurrentToast($editor)?->level)->toBe(StatusLevel::WARN)
        ->and(getCurrentToast($editor)?->message)->toContain($message)
        ->and($map->isDirty())->toBeFalse();
})->with([
    'no tileset' => ['no tileset', 'names no tileset'],
    'no pieces' => ['no pieces', 'Tileset home has no pieces'],
    'broken tileset' => ['broken tileset', 'Pieces are unavailable: Tileset home needs at least one sheet.'],
]);

it('previews the piece\'s footprint at the cursor while placing it', function () {
    [$editor, $map] = createPieceCanvasEditor();
    choosePieceByKeys($editor);
    callEditorMethod($editor, 'dispatchInput', "\033[C");

    expect(callEditorMethod($editor, 'getPiecePreviewCells'))->toBe([0 => [1 => '=', 2 => null], 1 => [1 => '=', 2 => 'H']])
        ->and($map->renderPreview(4, 2, stampPreview: callEditorMethod($editor, 'getPiecePreviewCells'))[1])
        ->toContain("\033[7m=\033[0m", "\033[7mH\033[0m");

    $frame = renderEditorPlainFrame($editor, 160, 45);
    expect($frame)->toContain('.=..', '.=H.', 'PIECE Bed  Enter:Stamp  Esc:Done')
        ->and($map->isDirty())->toBeFalse();

    // A Normal-mode click only moves the cursor, and the preview with it.
    $bounds = callEditorMethod($editor, 'getCanvasPreviewBounds');
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<0;%d;%dM", $bounds['left'] + 2, $bounds['top']));
    callEditorMethod($editor, 'dispatchInput', sprintf("\033[<0;%d;%dm", $bounds['left'] + 2, $bounds['top']));
    expect(array_keys(callEditorMethod($editor, 'getPiecePreviewCells')[0]))->toBe([2, 3])
        ->and($map->isDirty())->toBeFalse();

    // Esc ends placement and the preview goes with it.
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect(getEditorProperty($editor, 'piecePlacement'))->toBeNull()
        ->and(renderEditorPlainFrame($editor, 160, 45))->toContain('./..', '.xx.')->not->toContain('PIECE');
});

it('fits the placement hint to narrow canvases and shows P:Piece in Normal mode', function () {
    [$editor] = createPieceCanvasEditor();
    expect(callEditorMethod($editor, 'getPiecePlacementHelp', 40))->toBeNull();
    choosePieceByKeys($editor);

    expect(callEditorMethod($editor, 'getPiecePlacementHelp', 40))->toBe('PIECE Bed  Enter:Stamp  Esc:Done')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 33))->toBe('PIECE Bed Enter:Stamp Esc:Done')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 29))->toBe('PIECE Enter:Stamp Esc:Done')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 24))->toBe('Enter:Stamp Esc:Done')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 12))->toBe('Esc:Done');
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect(renderEditorPlainFrame($editor, 200, 45))->toContain('i:Paint L:Layer P:Piece');
});

it('stamps glyphs on the piece\'s layer and its tiles in one undo step', function () {
    [$editor, $map, $root] = createPieceCanvasEditor();
    $graphics = $map->directory . '/graphics';
    $before = $map->captureLayerSnapshot();
    $tilesBefore = $map->getTileLayerSources();
    $disk = sourceHashTree($map->directory);
    setEditorProperty($editor, 'selectedPaintColor', 'cyan');
    expect(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:1');

    choosePieceByKeys($editor);
    setEditorProperty($editor, 'cursorX', 2);
    callEditorMethod($editor, 'dispatchInput', "\n");

    // The glyphs land on buildings, not the terrain layer being edited; a
    // space glyph and a 0 tile leave their cells alone.
    expect($map->getLayerSymbol('map:4', 2, 0))->toBe('=')
        ->and($map->getLayerSymbol('map:4', 3, 0))->toBe(' ')
        ->and($map->getLayerSymbol('map:4', 2, 1))->toBe('=')
        ->and($map->getLayerSymbol('map:4', 3, 1))->toBe('H')
        ->and($map->getLayerColor('map:4', 3, 1))->toBe('cyan')
        ->and($map->getLayerSymbol('map:1', 2, 0))->toBe('.')
        ->and(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:1')
        ->and($map->isDirty())->toBeTrue();
    $tiles = $map->getTileLayerSources();
    expect(readTileEntries($tiles[$graphics . '/02.decor.tiles.php']))->toBe([['0', '5', '5L', '5R'], ['0', '0', '6', '5']])
        ->and(readTileEntries($tiles[$graphics . '/03.furniture.tiles.php']))->toBe([['0', '0', '7', '0'], ['0', '0', '0', '8R']])
        ->and($tiles[$graphics . '/01.floor.tiles.php'])->toBe($tilesBefore[$graphics . '/01.floor.tiles.php']);

    // Placement stays for the next stamp; one undo takes the whole stamp back.
    expect(getEditorProperty($editor, 'piecePlacement'))->not->toBeNull();
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureLayerSnapshot())->toBe($before)
        ->and($map->getTileLayerSources())->toBe($tilesBefore)
        ->and($map->isDirty())->toBeFalse();
    callEditorMethod($editor, 'dispatchInput', "\x19");
    expect($map->getTileLayerSources())->toBe($tiles)
        ->and($map->getLayerSymbol('map:4', 3, 1))->toBe('H');

    // Saving writes the changed terminal layer and tile layers, and nothing else.
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(array_keys(array_diff_assoc(sourceHashTree($map->directory), $disk)))->toBe([
        'graphics/02.decor.tiles.php', 'graphics/03.furniture.tiles.php', 'layers/04.buildings.map.php',
    ])
        ->and(file_get_contents($graphics . '/02.decor.tiles.php'))->toBe("<?php\n\nreturn <<<'TILES'\n0 5 5L 5R\n0 0 6 5\nTILES;\n")
        ->and(file_get_contents($graphics . '/03.furniture.tiles.php'))->toBe("<?php\n\nreturn <<<'TILES'\n0 0 7 0\n0 0 0 8R\nTILES;\n")
        ->and(array_map(static fn($layer): string => $layer->name, MapGraphics::loadFromDirectory(
            $map->directory, 'test-map', 'home', loadLayeredMap($root)->getLayerSet(), $root . '/assets')->layers))
        ->toBe(['floor', 'decor', 'furniture']);

    // Undoing after the save and saving again restores every authored byte.
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(sourceHashTree($map->directory))->toBe($disk);
});

it('creates graphics/ for a map whose tileset pieces name tile layers it lacks', function () {
    $root = layeredMapProject();
    writeTestTileset($root, pieces: buildTestPieces());
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("'events' => [],", "'events' => [], 'tileset' => 'home',", (string) file_get_contents($data)));
    [$editor, $map] = layeredCanvasEditor($root);

    choosePieceByKeys($editor, 2);
    callEditorMethod($editor, 'dispatchInput', "\033[B");
    callEditorMethod($editor, 'dispatchInput', "\n");
    callEditorMethod($editor, 'dispatchInput', "\x13");

    expect($map->getLayerSymbol('map:1', 0, 1))->toBe('~')
        ->and(file_get_contents($map->directory . '/graphics/00.floor.tiles.php'))
        ->toBe("<?php\n\nreturn <<<'TILES'\n0 0 0 0\n2816 2816 0 0\nTILES;\n")
        ->and(MapGraphics::loadFromDirectory($map->directory, 'test-map', 'home', loadLayeredMap($root)->getLayerSet(), $root . '/assets'))
        ->not->toBeNull();
});

it('refuses a stamp that cannot be made whole and changes nothing', function (string $case, string $message) {
    [$editor, $map, $root] = createPieceCanvasEditor($case === 'ragged');
    if ($case === 'unreadable tile layer') {
        writeTileLayer($map->directory, '02.decor.tiles.php', "0 5 0 0\n0 0 0 x");
        [$editor, $map] = layeredCanvasEditor($root);
    }
    $before = $map->captureLayerSnapshot();
    $tiles = $map->getTileLayerSources();
    choosePieceByKeys($editor, $case === 'missing layer' ? 1 : 0);
    setEditorProperty($editor, 'cursorX', match ($case) { 'off the edge' => 3, 'ragged' => 1, default => 0 });
    getEditorProperty($editor, 'toasts')->clear();
    callEditorMethod($editor, 'dispatchInput', "\n");

    expect(getCurrentToast($editor)?->level)->toBe(StatusLevel::WARN)
        ->and(getCurrentToast($editor)?->message)->toContain($message)
        ->and(strtolower((string) getCurrentToast($editor)?->message))->toContain('nothing was changed.')
        ->and($map->captureLayerSnapshot())->toBe($before)
        ->and($map->getTileLayerSources())->toBe($tiles)
        ->and($map->isDirty())->toBeFalse();
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureLayerSnapshot())->toBe($before);
})->with([
    'missing layer' => ['missing layer', 'Lamp goes on the fixtures layer, which this map does not have.'],
    'off the edge' => ['off the edge', 'Bed (2 x 2) does not fit at (3, 0): the map has no cell at (4, 0).'],
    'ragged' => ['ragged', 'Bed (2 x 2) does not fit at (1, 0): the map has no cell at (2, 1).'],
    'unreadable tile layer' => ['unreadable tile layer', "'x' is not an RPG Maker tile identity"],
]);

it('ends placement when the mode, map, layer or tool changes', function (string $key) {
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: buildTestPieces());
    loadLayeredMap($root)->duplicateTo($root . '/assets/Maps/zz-copy', 'zz-copy', 'Copy');
    [$editor, $map] = layeredCanvasEditor($root);
    choosePieceByKeys($editor);
    $before = $map->captureLayerSnapshot();
    if ($key === 'asset') {
        setEditorProperty($editor, 'focusedPane', 'assets');
        callEditorMethod($editor, 'dispatchInput', 'j');
        setEditorProperty($editor, 'focusedPane', 'canvas');
    } elseif ($key === 'layer') {
        callEditorMethod($editor, 'dispatchInput', 'L');
        callEditorMethod($editor, 'dispatchInput', "\n");
    } else {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    expect(getEditorProperty($editor, 'piecePlacement'))->toBeNull();
    callEditorMethod($editor, 'stampPiece');
    expect($map->captureLayerSnapshot())->toBe($before);
})->with(['e', 'n', 'i', 'b', 's', 'layer', 'asset']);

it('switches to Map mode when a piece is chosen from Event mode', function () {
    [$editor] = createPieceCanvasEditor();
    callEditorMethod($editor, 'dispatchInput', 'e');
    choosePieceByKeys($editor);

    expect(getEditorProperty($editor, 'editingMode'))->toBe('map')
        ->and(callEditorMethod($editor, 'getActivePiecePlacement')['piece']->id)->toBe('bed');
});

it('keeps P a glyph in Paint mode', function () {
    [$editor, $map] = createPieceCanvasEditor();
    foreach (['i', 'P'] as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }

    expect($map->getLayerSymbol('map:1', 0, 0))->toBe('P')
        ->and(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and(getEditorProperty($editor, 'piecePlacement'))->toBeNull();
});

it('lists P in the help overlay', function () {
    [$editor] = createPieceCanvasEditor();
    $keys = array_column(getEditorProperty($editor, 'inputRouter')->describeBindings(), 'description', 'key');

    expect($keys['P'] ?? null)->toContain('tileset piece');
});

/** @return list<list<string>> A tile layer source's entries, read as the Engine reads them. */
function readTileEntries(string $source): array
{
    return Ichiloto\Editor\Maps\TileLayerSource::readLayer($source, 'layer.tiles.php')->getEntries();
}
