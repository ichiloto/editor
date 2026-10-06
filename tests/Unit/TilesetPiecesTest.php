<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Engine\Field\MapGraphics;

/**
 * Tileset pieces on the terminal canvas: P chooses a piece of the map's
 * tileset and Enter stamps its glyphs and tiles as one undo step, or draws a
 * connected piece such as a wall. The layered test map is 4 x 2 cells:
 * terrain `./..` over `.xx.` from buildings; walls are drawn on a 6 x 5 map.
 */

/** @return array<string, array<string, mixed>> Synthetic pieces for tileset `home`. */
function buildTestPieces(): array
{
    return [
        'bed' => ['name' => 'Bed', 'layer' => 'buildings', 'glyphs' => ['= ', '=H'],
            'tiles' => ['decor' => ['5 5', '6 0'], 'furniture' => ['7 0', '0 8']]],
        'lamp' => ['name' => 'Lamp', 'layer' => 'fixtures', 'glyphs' => ['i']],
        'rug' => ['name' => 'Rug', 'layer' => 'terrain', 'glyphs' => ['~~'], 'tiles' => ['floor' => ['2816 2816']]],
        'wall' => ['name' => 'Wall', 'layer' => 'buildings', 'connects' => 'lines',
            'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'],
            'tiles' => ['walls' => ['horizontal' => '10', 'vertical' => '11', 'corner' => '12']]],
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

/** Selects the layer a test piece goes on, as L does. */
function selectPieceLayer(Editor $editor, string $layerName): void
{
    $layer = array_find(callEditorMethod($editor, 'getSelectedMap')->getLayers(), static fn(array $layer): bool => $layer['name'] === $layerName);
    callEditorMethod($editor, 'selectCanvasLayer', $layer['id']);
}

/** Chooses a piece with the keys: its layer, P, down to its entry and Enter. */
function choosePieceByKeys(Editor $editor, string $id = 'bed'): void
{
    selectPieceLayer($editor, buildTestPieces()[$id]['layer']);
    callEditorMethod($editor, 'dispatchInput', 'P');
    $downs = array_search($id, array_column(getEditorProperty($editor, 'eventOptionDialogEntries'), 'value'), true);
    for ($i = 0; $i < $downs; $i++) {
        callEditorMethod($editor, 'dispatchInput', "\033[B");
    }
    callEditorMethod($editor, 'dispatchInput', "\n");
}

it('lists the pieces for the layer being edited in the shared picker with P', function () {
    [$editor] = createPieceCanvasEditor();
    selectPieceLayer($editor, 'buildings');
    callEditorMethod($editor, 'dispatchInput', 'P');

    expect(getEditorProperty($editor, 'eventOptionDialogTitle'))->toBe('Buildings piece')
        ->and(getEditorProperty($editor, 'eventOptionDialogEntries'))->toBe([
            ['label' => 'Bed', 'value' => 'bed', 'description' => '2 x 2 · tiles: decor, furniture'],
            ['label' => 'Wall', 'value' => 'wall', 'description' => 'connected · tiles: walls'],
        ]);

    // Esc cancels without placing anything.
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect(getEditorProperty($editor, 'eventOptionDialogEntries'))->toBe([])
        ->and(getEditorProperty($editor, 'piecePlacement'))->toBeNull();

    // A filter narrows the list; Enter chooses the match.
    foreach (['P', '/', 'W', 'a', "\n", "\n"] as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    expect(getEditorProperty($editor, 'piecePlacement')['piece']->id)->toBe('wall');

    // Another layer offers only its own pieces.
    callEditorMethod($editor, 'dispatchInput', "\033");
    selectPieceLayer($editor, 'terrain');
    callEditorMethod($editor, 'dispatchInput', 'P');
    expect(array_column(getEditorProperty($editor, 'eventOptionDialogEntries'), 'value'))->toBe(['rug']);
});

it('says which layers have pieces when the layer being edited has none', function () {
    [$editor, $map] = createPieceCanvasEditor();
    writeTestTileset(getEditorProperty($editor, 'projectRoot'), pieces: ['wall' => buildTestPieces()['wall']]);
    selectPieceLayer($editor, 'terrain');
    callEditorMethod($editor, 'dispatchInput', 'P');

    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and(getCurrentToast($editor)?->level)->toBe(StatusLevel::WARN)
        ->and(getCurrentToast($editor)?->message)->toBe('Home has no pieces for the Terrain layer. It has pieces for Buildings; press L to switch layers.')
        ->and($map->isDirty())->toBeFalse();
});

it('offers choosing a piece and drawing a layer\'s tiles in the command palette', function () {
    [$editor] = createPieceCanvasEditor();
    $labels = array_map(static fn($item): string => $item->label . ' ' . $item->hint, callEditorMethod($editor, 'buildPaletteItems'));

    expect(array_values(array_filter($labels, static fn(string $label): bool => str_starts_with($label, 'Pieces:'))))
        ->toBe(['Pieces: Choose a piece to place P', "Pieces: Draw tiles for this layer's glyphs T"]);
});

it('says why a map has no pieces and opens nothing', function (string $case, string $message) {
    $root = $case === 'no tileset' ? layeredMapProject() : mapGraphicsProject();
    if ($case === 'no tileset') {
        writeTestTileset($root, pieces: buildTestPieces());
    }
    if ($case === 'broken tileset') {
        file_put_contents($root . '/assets/Data/Tilesets/home.php', "<?php\n\nreturn ['name' => 'Home'];\n");
    }
    if ($case === 'half-tile piece') {
        writeTestTileset($root, pieces: ['bed' => ['name' => 'Bed', 'layer' => 'buildings', 'glyphs' => ['=='],
            'tiles' => ['decor' => ['5L 5R']]]]);
    }
    [$editor, $map] = layeredCanvasEditor($root);
    callEditorMethod($editor, 'dispatchInput', 'P');

    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and(getCurrentToast($editor)?->level)->toBe(StatusLevel::WARN)
        ->and(getCurrentToast($editor)?->message)->toContain($message)
        ->and($map->isDirty())->toBeFalse();
})->with([
    'no tileset' => ['no tileset', 'This map has no kind yet, so it has no pieces. Set its Kind in the Inspector.'],
    'no pieces' => ['no pieces', 'Tileset home has no pieces'],
    'broken tileset' => ['broken tileset', 'Pieces are unavailable: Tileset home needs at least one sheet.'],
    // A field cell holds one whole tile, so a piece naming half a tile is refused, not reinterpreted.
    'half-tile piece' => ['half-tile piece', "'5L' names half of tile 5, but each field cell holds one whole tile; use whole tiles, such as '5'."],
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
    expect(renderEditorPlainFrame($editor, 200, 45))->toContain('i:Paint b/l/r/R/s:Tool L:Layer P:Piece');
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

    // The glyphs land on buildings, the layer being edited, and leave the
    // terrain alone; a space glyph and a 0 tile leave their cells alone.
    expect($map->getLayerSymbol('map:4', 2, 0))->toBe('=')
        ->and($map->getLayerSymbol('map:4', 3, 0))->toBe(' ')
        ->and($map->getLayerSymbol('map:4', 2, 1))->toBe('=')
        ->and($map->getLayerSymbol('map:4', 3, 1))->toBe('H')
        ->and($map->getLayerColor('map:4', 3, 1))->toBe('cyan')
        ->and($map->getLayerSymbol('map:1', 2, 0))->toBe('.')
        ->and(callEditorMethod($editor, 'getActiveCanvasLayer'))->toBe('map:4')
        ->and($map->isDirty())->toBeTrue();
    $tiles = $map->getTileLayerSources();
    expect(readTileEntries($tiles[$graphics . '/02.decor.tiles.php']))->toBe([['0', '5', '5', '5'], ['0', '0', '6', '5']])
        ->and(readTileEntries($tiles[$graphics . '/03.furniture.tiles.php']))->toBe([['0', '0', '7', '0'], ['0', '0', '0', '8']])
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
        ->and(file_get_contents($graphics . '/02.decor.tiles.php'))->toBe("<?php\n\nreturn <<<'TILES'\n0 5 5 5\n0 0 6 5\nTILES;\n")
        ->and(file_get_contents($graphics . '/03.furniture.tiles.php'))->toBe("<?php\n\nreturn <<<'TILES'\n0 0 7 0\n0 0 0 8\nTILES;\n")
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

    choosePieceByKeys($editor, 'rug');
    callEditorMethod($editor, 'dispatchInput', "\033[B");
    callEditorMethod($editor, 'dispatchInput', "\n");
    callEditorMethod($editor, 'dispatchInput', "\x13");

    expect($map->getLayerSymbol('map:1', 0, 1))->toBe('~')
        ->and(file_get_contents($map->directory . '/graphics/00.floor.tiles.php'))
        ->toBe("<?php\n\nreturn <<<'TILES'\n0 0 0 0\n2816 2816 0 0\nTILES;\n")
        ->and(MapGraphics::loadFromDirectory($map->directory, 'test-map', 'home', loadLayeredMap($root)->getLayerSet(), $root . '/assets'))
        ->not->toBeNull();
});

it('orders a new tile layer by the gameplay layer it belongs to', function () {
    [$editor, $map, $root] = createPieceCanvasEditor();
    // floor belongs to Terrain (the rug writes it) and decor to Buildings (the bed).
    writeTestTileset($root, pieces: [...buildTestPieces(),
        'grass' => ['name' => 'Grass', 'layer' => 'terrain', 'glyphs' => [';'], 'tiles' => ['ground' => ['2864']]]]);
    $graphics = $map->directory . '/graphics';

    // A Terrain layer goes under Buildings' decor; with no free order below
    // decor, decor and every later layer move up one.
    callEditorMethod($editor, 'choosePiece', 'grass');
    callEditorMethod($editor, 'dispatchInput', "\n");
    expect(array_map(basename(...), array_keys($map->getTileLayerSources())))
        ->toBe(['01.floor.tiles.php', '02.ground.tiles.php', '03.decor.tiles.php']);

    // A Buildings layer goes after every layer of Buildings and below.
    callEditorMethod($editor, 'dispatchInput', "\033");
    callEditorMethod($editor, 'choosePiece', 'bed');
    callEditorMethod($editor, 'dispatchInput', "\033[C");
    callEditorMethod($editor, 'dispatchInput', "\033[C");
    callEditorMethod($editor, 'dispatchInput', "\n");
    expect(array_map(basename(...), array_keys($map->getTileLayerSources())))
        ->toBe(['01.floor.tiles.php', '02.ground.tiles.php', '03.decor.tiles.php', '04.furniture.tiles.php']);

    // Saving writes the renumbered layer and removes its old file; undo restores the order.
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(array_map(basename(...), glob($graphics . '/*.tiles.php')))
        ->toBe(['01.floor.tiles.php', '02.ground.tiles.php', '03.decor.tiles.php', '04.furniture.tiles.php']);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect(array_map(basename(...), array_keys($map->getTileLayerSources())))->toBe(['01.floor.tiles.php', '02.decor.tiles.php']);
});

it('puts a new layer in the free order just below a higher layer without renumbering', function () {
    [$editor, $map, $root] = createPieceCanvasEditor();
    rename($map->directory . '/graphics/01.floor.tiles.php', $map->directory . '/graphics/00.floor.tiles.php');
    rename($map->directory . '/graphics/02.decor.tiles.php', $map->directory . '/graphics/05.decor.tiles.php');
    writeTestTileset($root, pieces: [...buildTestPieces(),
        'grass' => ['name' => 'Grass', 'layer' => 'terrain', 'glyphs' => [';'], 'tiles' => ['ground' => ['2864']]]]);
    [$editor, $map] = layeredCanvasEditor($root);

    callEditorMethod($editor, 'choosePiece', 'grass');
    callEditorMethod($editor, 'dispatchInput', "\n");
    expect(array_map(basename(...), array_keys($map->getTileLayerSources())))
        ->toBe(['00.floor.tiles.php', '04.ground.tiles.php', '05.decor.tiles.php']);
});

it('refuses a stamp that cannot be made whole and changes nothing', function (string $case, string $message) {
    [$editor, $map, $root] = createPieceCanvasEditor($case === 'ragged');
    if ($case === 'unreadable tile layer' || $case === 'half-tile layer') {
        writeTileLayer($map->directory, '02.decor.tiles.php', $case === 'half-tile layer' ? "0 5L 5R 0\n0 0 0 5" : "0 5 0 0\n0 0 0 x");
        [$editor, $map] = layeredCanvasEditor($root);
    }
    $before = $map->captureLayerSnapshot();
    $tiles = $map->getTileLayerSources();
    if ($case === 'missing layer') {
        callEditorMethod($editor, 'choosePiece', 'lamp');
    } else {
        choosePieceByKeys($editor);
    }
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
    'half-tile layer' => ['half-tile layer', "'5L' names half of tile 5, but each field cell holds one whole tile"],
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
    callEditorMethod($editor, 'applyPieceAtCursor');
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

/**
 * The editor on a 6 x 5 map naming tileset `home`, for drawing walls: dots on
 * terrain under a buildings layer holding the given rows (spaces by default),
 * with a floor tile layer and no walls tile layer yet. A ragged map's last
 * row is 3 cells wide.
 *
 * @param list<string>|null $buildings The buildings layer's rows, as authored.
 * @param array<string, array<string, mixed>>|null $pieces The tileset's pieces, the test pieces by default.
 * @return array{Editor, ProjectMap, string}
 */
function createWallCanvasEditor(?array $buildings = null, bool $ragged = false, ?array $pieces = null): array
{
    $root = layeredMapProject();
    $directory = $root . '/assets/Maps/test-map';
    $widths = $ragged ? [6, 6, 6, 6, 3] : [6, 6, 6, 6, 6];
    $fill = static fn(string $cell): string => implode("\n", array_map(static fn(int $width): string => implode($cell === '2816' ? ' ' : '', array_fill(0, $width, $cell)), $widths));
    $layers = ['01.terrain.map.php' => $fill('.'), '04.buildings.map.php' => $buildings === null ? $fill(' ') : implode("\n", $buildings), '07.detail.deco.php' => $fill(' ')];
    foreach ($layers as $file => $text) {
        file_put_contents($directory . '/layers/' . $file, Ichiloto\Engine\Field\MapGridSource::buildSource($text, 'AUTHORED', '// keep ' . $file . "\n"));
    }
    file_put_contents($directory . '/test-map.event.php', Ichiloto\Engine\Field\MapGridSource::buildSource($fill(' '), 'EVENTS'));
    $data = $directory . '/test-map.data.php';
    file_put_contents($data, str_replace("'events' => [],", "'events' => [], 'tileset' => 'home',", (string) file_get_contents($data)));
    writeTestTileset($root, pieces: $pieces ?? buildTestPieces());
    writeTileLayer($directory, '01.floor.tiles.php', $fill('2816'));

    return layeredCanvasEditor($root);
}

/** Starts drawing the wall, the fourth piece in the picker. */
function chooseWallByKeys(Editor $editor): void
{
    choosePieceByKeys($editor, 'wall');
}

/** Walks the canvas cursor to a cell with the arrow keys. */
function moveCanvasCursorTo(Editor $editor, int $x, int $y): void
{
    foreach (['cursorX' => [$x, "\033[C", "\033[D"], 'cursorY' => [$y, "\033[B", "\033[A"]] as $property => [$target, $forward, $back]) {
        for ($guard = 0; getEditorProperty($editor, $property) !== $target && $guard < 20; $guard++) {
            callEditorMethod($editor, 'dispatchInput', getEditorProperty($editor, $property) < $target ? $forward : $back);
        }
    }
}

/** Presses Enter at each cell in turn, as a keyboard author draws. */
function drawWallThrough(Editor $editor, array ...$cells): void
{
    foreach ($cells as [$x, $y]) {
        moveCanvasCursorTo($editor, $x, $y);
        callEditorMethod($editor, 'dispatchInput', "\n");
    }
}

/** @return list<string> The buildings layer's rows, one glyph per cell. */
function readBuildingRows(ProjectMap $map): array
{
    $rows = [];
    for ($y = 0; $y < $map->getHeight(); $y++) {
        $row = '';
        for ($x = 0; $map->hasLayerCell('map:4', $x, $y); $x++) {
            $row .= $map->getLayerSymbol('map:4', $x, $y);
        }
        $rows[] = $row;
    }

    return $rows;
}

/** @return list<list<string>>|null The walls tile layer's entries, or null when the map has none. */
function readWallTiles(ProjectMap $map, string $file = '02.walls.tiles.php'): ?array
{
    $source = $map->getTileLayerSources()[$map->directory . '/graphics/' . $file] ?? null;

    return $source === null ? null : readTileEntries($source);
}

/**
 * The walls tile entries a map's wall glyphs should have: the test wall's
 * tile for each shape glyph, `0` elsewhere.
 *
 * @param list<string> $rows
 * @return list<list<string>>
 */
function buildWallTiles(array $rows): array
{
    return array_map(static fn(string $row): array => array_map(
        static fn(string $glyph): string => ['-' => '10', '|' => '11', '+' => '12'][$glyph] ?? '0',
        mb_str_split($row),
    ), $rows);
}

it('draws a lone wall post at the cursor and anchors there', function () {
    [$editor, $map] = createWallCanvasEditor();
    chooseWallByKeys($editor);
    expect(getEditorProperty($editor, 'piecePlacement')['anchor'])->toBeNull();

    moveCanvasCursorTo($editor, 2, 1);
    getEditorProperty($editor, 'toasts')->clear();
    callEditorMethod($editor, 'dispatchInput', "\n");

    $rows = ['      ', '  +   ', '      ', '      ', '      '];
    expect(readBuildingRows($map))->toBe($rows)
        ->and(readWallTiles($map))->toBe(buildWallTiles($rows))
        ->and(getEditorProperty($editor, 'piecePlacement')['anchor'])->toBe(['x' => 2, 'y' => 1])
        ->and(getCurrentToast($editor)?->message)->toBe('Drew 1 wall cell. Anchor set; move and press Enter to draw a line or a room.')
        ->and($map->getLayerSymbol('map:1', 2, 1))->toBe('.')
        ->and($map->isDirty())->toBeTrue();
});

it('draws straight wall lines whose ends take the line\'s shape and turn where they meet, one undo step each', function () {
    [$editor, $map] = createWallCanvasEditor();
    $before = $map->captureLayerSnapshot();
    $tilesBefore = $map->getTileLayerSources();
    chooseWallByKeys($editor);

    drawWallThrough($editor, [1, 1]);
    $post = [$map->captureLayerSnapshot(), $map->getTileLayerSources()];
    getEditorProperty($editor, 'toasts')->clear();
    drawWallThrough($editor, [4, 1]);
    $across = ['      ', ' ---- ', '      ', '      ', '      '];
    expect(readBuildingRows($map))->toBe($across)
        ->and(readWallTiles($map))->toBe(buildWallTiles($across))
        ->and(getCurrentToast($editor)?->message)->toBe('Drew 4 wall cells. Enter draws on from here; Esc drops the anchor.')
        ->and(getEditorProperty($editor, 'piecePlacement')['anchor'])->toBe(['x' => 4, 'y' => 1]);
    $line = [$map->captureLayerSnapshot(), $map->getTileLayerSources()];

    // The next line starts at the anchor; the cell where the lines meet turns.
    drawWallThrough($editor, [4, 3]);
    $turned = ['      ', ' ---+ ', '    | ', '    | ', '      '];
    expect(readBuildingRows($map))->toBe($turned)
        ->and(readWallTiles($map))->toBe(buildWallTiles($turned));
    $after = [$map->captureLayerSnapshot(), $map->getTileLayerSources()];

    foreach ([$line, $post, [$before, $tilesBefore]] as [$layers, $tiles]) {
        callEditorMethod($editor, 'dispatchInput', "\x1a");
        expect($map->captureLayerSnapshot())->toBe($layers)
            ->and($map->getTileLayerSources())->toBe($tiles);
    }
    expect($map->isDirty())->toBeFalse();
    foreach ([$post, $line, $after] as [$layers, $tiles]) {
        callEditorMethod($editor, 'dispatchInput', "\x19");
        expect($map->captureLayerSnapshot())->toBe($layers)
            ->and($map->getTileLayerSources())->toBe($tiles);
    }
});

it('draws a room as the outline of the rectangle from the anchor to the cursor', function () {
    [$editor, $map] = createWallCanvasEditor();
    chooseWallByKeys($editor);
    drawWallThrough($editor, [0, 0]);
    getEditorProperty($editor, 'toasts')->clear();
    drawWallThrough($editor, [3, 2]);

    $room = ['+--+  ', '|  |  ', '+--+  ', '      ', '      '];
    expect(readBuildingRows($map))->toBe($room)
        ->and(readWallTiles($map))->toBe(buildWallTiles($room))
        ->and(getCurrentToast($editor)?->message)->toBe('Drew 10 wall cells. Enter draws on from here; Esc drops the anchor.');
});

it('joins walls typed by hand, keeping their colour, and leaves other glyphs alone', function () {
    [$editor, $map] = createWallCanvasEditor(['   <fg=red>|</>  ', '   <fg=red>|</>  ', '_  <fg=red>|</>  ', '      ', '      ']);
    setEditorProperty($editor, 'selectedPaintColor', 'cyan');
    chooseWallByKeys($editor);
    drawWallThrough($editor, [0, 1], [2, 1]);

    $rows = ['   |  ', '---+  ', '_  |  ', '      ', '      '];
    expect(readBuildingRows($map))->toBe($rows)
        // The hand-typed wall beside the line joins it and gets its tile;
        // the walls further along are not touched.
        ->and(readWallTiles($map))->toBe(buildWallTiles(['      ', '---+  ', '      ', '      ', '      ']))
        ->and($map->getLayerColor('map:4', 3, 1))->toBe('red')
        ->and($map->getLayerColor('map:4', 0, 1))->toBe('cyan')
        ->and($map->getLayerSymbol('map:4', 0, 2))->toBe('_');
});

it('erases a wall cell with the erase keys and reshapes the walls beside it', function () {
    [$editor, $map] = createWallCanvasEditor();
    chooseWallByKeys($editor);
    drawWallThrough($editor, [0, 0], [3, 2]);
    callEditorMethod($editor, 'dispatchInput', "\033");
    $room = [$map->captureLayerSnapshot(), $map->getTileLayerSources()];

    moveCanvasCursorTo($editor, 1, 0);
    getEditorProperty($editor, 'toasts')->clear();
    callEditorMethod($editor, 'dispatchInput', "\177");
    $opened = ['| -+  ', '|  |  ', '+--+  ', '      ', '      '];
    expect(readBuildingRows($map))->toBe($opened)
        ->and(readWallTiles($map))->toBe(buildWallTiles($opened))
        ->and(getCurrentToast($editor)?->message)->toBe('Erased the wall cell at (1, 0).')
        ->and(getEditorProperty($editor, 'piecePlacement'))->not->toBeNull();

    // One undo puts the cell and the reshaped walls back exactly.
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureLayerSnapshot())->toBe($room[0])
        ->and($map->getTileLayerSources())->toBe($room[1]);

    // Delete erases too; the corners it leaves straighten into lines.
    moveCanvasCursorTo($editor, 3, 1);
    callEditorMethod($editor, 'dispatchInput', "\033[3~");
    $side = ['+---  ', '|     ', '+---  ', '      ', '      '];
    expect(readBuildingRows($map))->toBe($side)
        ->and(readWallTiles($map))->toBe(buildWallTiles($side));

    // A cell that is not a wall is left alone, with a word why.
    $layers = $map->captureLayerSnapshot();
    $tiles = $map->getTileLayerSources();
    moveCanvasCursorTo($editor, 1, 1);
    getEditorProperty($editor, 'toasts')->clear();
    callEditorMethod($editor, 'dispatchInput', "\177");
    expect(getCurrentToast($editor)?->message)->toBe('(1, 1) is not part of a wall, so nothing was erased.')
        ->and($map->captureLayerSnapshot())->toBe($layers)
        ->and($map->getTileLayerSources())->toBe($tiles);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($map->captureLayerSnapshot())->toBe($room[0]);
});

it('creates no tile layer for an erase that leaves only empty tiles', function () {
    [$editor, $map] = createWallCanvasEditor(['      ', '  +   ', '      ', '      ', '      ']);
    $tiles = $map->getTileLayerSources();
    chooseWallByKeys($editor);
    moveCanvasCursorTo($editor, 2, 1);
    callEditorMethod($editor, 'dispatchInput', "\177");

    expect(readBuildingRows($map)[1])->toBe('      ')
        ->and($map->getTileLayerSources())->toBe($tiles)
        ->and(readWallTiles($map))->toBeNull()
        ->and($map->isDirty())->toBeTrue();
});

it('refuses a wall draw or erase that cannot be made whole and changes nothing', function () {
    [$editor, $map] = createWallCanvasEditor(ragged: true);
    chooseWallByKeys($editor);

    // A cell past a short row is not on the map.
    moveCanvasCursorTo($editor, 5, 4);
    getEditorProperty($editor, 'toasts')->clear();
    callEditorMethod($editor, 'dispatchInput', "\n");
    expect(getCurrentToast($editor)?->level)->toBe(StatusLevel::WARN)
        ->and(getCurrentToast($editor)?->message)->toBe('Wall cannot reach (5, 4): the map has no cell there. Nothing was changed.')
        ->and(getEditorProperty($editor, 'piecePlacement')['anchor'])->toBeNull()
        ->and($map->isDirty())->toBeFalse();

    // A room reaching past it is refused whole; the anchor stays put.
    drawWallThrough($editor, [0, 3]);
    $layers = $map->captureLayerSnapshot();
    $tiles = $map->getTileLayerSources();
    moveCanvasCursorTo($editor, 5, 4);
    getEditorProperty($editor, 'toasts')->clear();
    callEditorMethod($editor, 'dispatchInput', "\n");
    expect(getCurrentToast($editor)?->message)->toBe('Wall cannot reach (3, 4): the map has no cell there. Nothing was changed.')
        ->and($map->captureLayerSnapshot())->toBe($layers)
        ->and($map->getTileLayerSources())->toBe($tiles)
        ->and(getEditorProperty($editor, 'piecePlacement')['anchor'])->toBe(['x' => 0, 'y' => 3]);

    // A wall whose gameplay layer the map lacks is never offered; placed
    // some other way, it is refused as a stamp is.
    $pieces = buildTestPieces();
    $pieces['wall']['layer'] = 'fixtures';
    [$editor, $map] = createWallCanvasEditor(pieces: $pieces);
    $layers = $map->captureLayerSnapshot();
    callEditorMethod($editor, 'choosePiece', 'wall');
    foreach (["\n", "\177"] as $key) {
        getEditorProperty($editor, 'toasts')->clear();
        callEditorMethod($editor, 'dispatchInput', $key);
        expect(getCurrentToast($editor)?->message)->toContain('Wall goes on the fixtures layer, which this map does not have.')
            ->and($map->captureLayerSnapshot())->toBe($layers)
            ->and($map->isDirty())->toBeFalse();
    }
});

it('saves only the wall\'s changed files, rewriting its tile layer only when it changed', function () {
    [$editor, $map, $root] = createWallCanvasEditor();
    writeTileLayer($map->directory, '03.walls.tiles.php', implode("\n", array_fill(0, 5, '0  0  0  0  0  0')), "// Walls drawn in the TUI.\n");
    [$editor, $map] = layeredCanvasEditor($root);
    $disk = sourceHashTree($map->directory);
    chooseWallByKeys($editor);
    drawWallThrough($editor, [0, 0], [3, 2]);
    callEditorMethod($editor, 'dispatchInput', "\x13");

    expect(array_keys(array_diff_assoc(sourceHashTree($map->directory), $disk)))->toBe(['graphics/03.walls.tiles.php', 'layers/04.buildings.map.php'])
        ->and(file_get_contents($map->directory . '/graphics/03.walls.tiles.php'))
        ->toBe("<?php\n\n// Walls drawn in the TUI.\nreturn <<<'TILES'\n12 10 10 12 0 0\n11 0 0 11 0 0\n12 10 10 12 0 0\n0 0 0 0 0 0\n0 0 0 0 0 0\nTILES;\n")
        ->and($map->isDirty())->toBeFalse();

    // Undoing both draws and saving restores every authored byte.
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(sourceHashTree($map->directory))->toBe($disk);
});

it('previews the cells Enter would draw with the shapes they would take', function () {
    [$editor, $map] = createWallCanvasEditor(['      ', '      ', '    | ', '      ', '      ']);
    chooseWallByKeys($editor);
    moveCanvasCursorTo($editor, 1, 1);
    expect(callEditorMethod($editor, 'getPiecePreviewCells'))->toBe([1 => [1 => '+']]);

    drawWallThrough($editor, [1, 1]);
    moveCanvasCursorTo($editor, 4, 1);
    // The line's far end meets the wall below it, so it would turn there.
    expect(callEditorMethod($editor, 'getPiecePreviewCells'))->toBe([1 => [1 => '-', 2 => '-', 3 => '-', 4 => '+']])
        ->and(renderEditorPlainFrame($editor, 160, 45))->toContain('.---+.', 'PIECE Wall  Enter:Draw  Del:Erase  Esc:Unanchor')
        ->and(readBuildingRows($map)[1])->toBe(' +    ');
});

it('drops the anchor with Esc before ending wall placement, and fits the hint to the canvas', function () {
    [$editor] = createWallCanvasEditor();
    chooseWallByKeys($editor);
    expect(callEditorMethod($editor, 'getPiecePlacementHelp', 46))->toBe('PIECE Wall  Enter:Draw  Del:Erase  Esc:Done')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 45))->toBe('PIECE Wall Enter:Draw Del:Erase Esc:Done')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 42))->toBe('PIECE Enter:Draw Del:Erase Esc:Done')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 37))->toBe('Enter:Draw Del:Erase Esc:Done')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 31))->toBe('Esc:Done');

    callEditorMethod($editor, 'dispatchInput', "\n");
    expect(getEditorProperty($editor, 'piecePlacement')['anchor'])->toBe(['x' => 0, 'y' => 0])
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 50))->toBe('PIECE Wall  Enter:Draw  Del:Erase  Esc:Unanchor')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 49))->toBe('PIECE Wall Enter:Draw Del:Erase Esc:Unanchor')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 46))->toBe('PIECE Enter:Draw Del:Erase Esc:Unanchor')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 41))->toBe('Enter:Draw Del:Erase Esc:Unanchor')
        ->and(callEditorMethod($editor, 'getPiecePlacementHelp', 35))->toBe('Esc:Unanchor');

    getEditorProperty($editor, 'toasts')->clear();
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect(getEditorProperty($editor, 'piecePlacement')['anchor'])->toBeNull()
        ->and(getEditorProperty($editor, 'piecePlacement')['piece']->id)->toBe('wall')
        ->and(getCurrentToast($editor)?->message)->toBe('Anchor dropped. Enter draws Wall at the cursor; Esc when done.');

    callEditorMethod($editor, 'dispatchInput', "\033");
    expect(getEditorProperty($editor, 'piecePlacement'))->toBeNull();
});

it('keeps the erase keys erasing with the brush while an item piece is placed', function () {
    [$editor, $map] = createPieceCanvasEditor();
    choosePieceByKeys($editor);
    setEditorProperty($editor, 'cursorX', 1);
    callEditorMethod($editor, 'dispatchInput', "\177");

    expect($map->getLayerSymbol('map:4', 1, 0))->toBe(' ')
        ->and(getEditorProperty($editor, 'piecePlacement')['piece']->id)->toBe('bed');
});

/** @return list<list<string>> A tile layer source's entries, read as the Engine reads them. */
function readTileEntries(string $source): array
{
    return Ichiloto\Editor\Maps\TileLayerSource::readLayer($source, 'layer.tiles.php')->getEntries();
}

it('takes a covered glyph\'s tiles with it when a wall or a piece is drawn over it', function () {
    [$editor, $map] = createWallCanvasEditor();
    $tilesOf = static function (string $layer) use ($map): array {
        $found = array_filter($map->getTileLayerSources(), static fn(string $path): bool => str_ends_with($path, ".{$layer}.tiles.php"), ARRAY_FILTER_USE_KEY);

        return $found === [] ? [] : readTileEntries((string) reset($found));
    };
    choosePieceByKeys($editor);
    callEditorMethod($editor, 'dispatchInput', "\n");
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect(readBuildingRows($map)[0])->toBe('=     ')
        ->and($tilesOf('decor')[0])->toBe(['5', '5', '0', '0', '0', '0'])
        ->and($tilesOf('furniture')[0][0])->toBe('7');

    // The wall covers the bed's top cell, which takes its own tiles and the
    // blank cell's beside it; the bed's lower cells keep theirs.
    chooseWallByKeys($editor);
    drawWallThrough($editor, [0, 0], [2, 0]);
    expect(readBuildingRows($map)[0])->toBe('---   ')
        ->and($tilesOf('decor'))->toBe([['0', '0', '0', '0', '0', '0'], ['6', '0', '0', '0', '0', '0'],
            ...array_fill(0, 3, array_fill(0, 6, '0'))])
        ->and($tilesOf('furniture')[0][0])->toBe('0')
        ->and($tilesOf('furniture')[1][1])->toBe('8');
});
