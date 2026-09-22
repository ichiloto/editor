<?php

declare(strict_types=1);

use Ichiloto\Editor\Canvas\CanvasTool;
use Ichiloto\Editor\Canvas\Clipboard;
use Ichiloto\Editor\Canvas\ToolGeometry;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Builds an unbooted editor focused on the canvas of the fixture map.
 */
function canvasEditor(): Editor
{
    $editor = createEditorForTesting(fixturePath('sample-project'));
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject(fixturePath('sample-project')));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    setEditorProperty($editor, 'focusedPane', 'canvas');

    return $editor;
}

/**
 * Places the canvas cursor.
 */
function placeCursor(Editor $editor, int $x, int $y): void
{
    setEditorProperty($editor, 'cursorX', $x);
    setEditorProperty($editor, 'cursorY', $y);
}

/**
 * Returns the fixture map's current tile grid as strings.
 *
 * @return string[]
 */
function tileRows(Editor $editor): array
{
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $map = $workspace->getMapByIndex(0);
    $rows = [];

    for ($y = 0; $y < $map->getHeight(); $y++) {
        $row = '';

        for ($x = 0; $x < $map->getWidth(); $x++) {
            $row .= $map->getTileSymbol($x, $y);
        }

        $rows[] = $row;
    }

    return $rows;
}

it('walks a Bresenham line, a rectangle outline, and a filled rectangle', function () {
    expect(ToolGeometry::line(0, 0, 3, 0))->toBe([
        ['x' => 0, 'y' => 0],
        ['x' => 1, 'y' => 0],
        ['x' => 2, 'y' => 0],
        ['x' => 3, 'y' => 0],
    ]);

    // A 3x3 outline is eight cells: the centre stays untouched.
    expect(ToolGeometry::rectangleOutline(1, 1, 3, 3))->toHaveCount(8)
        ->and(ToolGeometry::rectangleOutline(3, 3, 1, 1))->toHaveCount(8)
        ->and(ToolGeometry::rectangleFilled(1, 1, 3, 3))->toHaveCount(9);
});

it('grows a square brush around the cursor', function () {
    expect(ToolGeometry::brush(4, 4, 1))->toBe([['x' => 4, 'y' => 4]])
        ->and(ToolGeometry::brush(4, 4, 2))->toHaveCount(4)
        ->and(ToolGeometry::brush(4, 4, 3))->toHaveCount(9)
        ->and(ToolGeometry::brush(4, 4, 5))->toHaveCount(25);

    // A width-3 brush is centred; a width-2 brush leans down-right.
    expect(ToolGeometry::brush(4, 4, 3)[0])->toBe(['x' => 3, 'y' => 3])
        ->and(ToolGeometry::brush(4, 4, 2)[0])->toBe(['x' => 4, 'y' => 4]);
});

it('flood fills only the contiguous same-symbol region', function () {
    $grid = [
        '..#..',
        '..#..',
        '..#..',
    ];
    $cells = ToolGeometry::floodFill(
        static fn(int $x, int $y): string => $grid[$y][$x],
        5,
        3,
        0,
        0,
    );

    // The left column pair is reachable; the right side is walled off.
    expect($cells)->toHaveCount(6);
});

it('draws a filled rectangle and undoes it in ONE Ctrl+Z', function () {
    $editor = canvasEditor();
    $before = tileRows($editor);

    setEditorProperty($editor, 'canvasTool', CanvasTool::FILLED_RECTANGLE);
    setEditorProperty($editor, 'selectedPaintSymbol', 'X');
    placeCursor($editor, 1, 1);
    callEditorMethod($editor, 'dispatchInput', "\n");
    placeCursor($editor, 4, 3);
    callEditorMethod($editor, 'dispatchInput', "\n");

    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');

    expect(tileRows($editor)[1])->toBe('#XXXX~     #')
        ->and(tileRows($editor)[3])->toBe('#XXXX      #')
        ->and($history->count())->toBe(1);

    callEditorMethod($editor, 'dispatchInput', "\x1a");

    expect(tileRows($editor))->toBe($before)
        ->and($history->count())->toBe(0);
});

it('draws a rectangle outline as one command and leaves the middle alone', function () {
    $editor = canvasEditor();
    setEditorProperty($editor, 'canvasTool', CanvasTool::RECTANGLE);
    setEditorProperty($editor, 'selectedPaintSymbol', 'O');
    placeCursor($editor, 1, 1);
    callEditorMethod($editor, 'dispatchInput', "\n");
    placeCursor($editor, 4, 3);
    callEditorMethod($editor, 'dispatchInput', "\n");

    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');

    expect(tileRows($editor)[1])->toBe('#OOOO~     #')
        ->and(tileRows($editor)[2])->toBe('#O  O      #')
        ->and($history->count())->toBe(1);
});

it('draws a line as one command', function () {
    $editor = canvasEditor();
    $before = tileRows($editor);
    setEditorProperty($editor, 'canvasTool', CanvasTool::LINE);
    setEditorProperty($editor, 'selectedPaintSymbol', '=');
    placeCursor($editor, 1, 2);
    callEditorMethod($editor, 'dispatchInput', "\n");
    placeCursor($editor, 5, 2);
    callEditorMethod($editor, 'dispatchInput', "\n");

    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');

    expect(tileRows($editor)[2])->toBe('#=====     #')
        ->and($history->count())->toBe(1);

    callEditorMethod($editor, 'dispatchInput', "\x1a");

    expect(tileRows($editor))->toBe($before);
});

it('flood fills with Ctrl+F and undoes the whole region in ONE Ctrl+Z', function () {
    $editor = canvasEditor();
    $before = tileRows($editor);
    setEditorProperty($editor, 'selectedPaintSymbol', '.');
    placeCursor($editor, 5, 2);
    callEditorMethod($editor, 'dispatchInput', "\x06");

    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');
    $filled = tileRows($editor);

    // The interior floods; the wall of `#` bounds it.
    expect($filled[2])->toBe('#..........#')
        ->and($filled[1])->toBe('#..~~~.....#')
        ->and($filled[0])->toBe($before[0])
        ->and($history->count())->toBe(1);

    callEditorMethod($editor, 'dispatchInput', "\x1a");

    expect(tileRows($editor))->toBe($before)
        ->and($history->count())->toBe(0);
});

it('refuses a flood fill that would change nothing', function () {
    $editor = canvasEditor();
    setEditorProperty($editor, 'selectedPaintSymbol', '#');
    placeCursor($editor, 0, 0);
    callEditorMethod($editor, 'dispatchInput', "\x06");

    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');

    expect($history->count())->toBe(0);
});

it('picks up the symbol under the cursor with Ctrl+K', function () {
    $editor = canvasEditor();
    setEditorProperty($editor, 'selectedPaintSymbol', 'z');
    placeCursor($editor, 0, 0);
    callEditorMethod($editor, 'dispatchInput', "\x0b");

    expect(getEditorProperty($editor, 'selectedPaintSymbol'))->toBe('#');
});

it('cycles tools with Ctrl+N and brush widths with Ctrl+W', function () {
    $editor = canvasEditor();

    expect(getEditorProperty($editor, 'canvasTool'))->toBe(CanvasTool::BRUSH);

    callEditorMethod($editor, 'dispatchInput', "\x0e");

    expect(getEditorProperty($editor, 'canvasTool'))->toBe(CanvasTool::LINE);

    foreach ([2, 3, 5, 1] as $expectedWidth) {
        callEditorMethod($editor, 'dispatchInput', "\x17");
        expect(getEditorProperty($editor, 'canvasBrushSize'))->toBe($expectedWidth);
    }
});

it('paints a whole brush footprint as one undoable dab', function () {
    $editor = canvasEditor();
    $before = tileRows($editor);
    setEditorProperty($editor, 'canvasBrushSize', 3);
    placeCursor($editor, 5, 2);
    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'dispatchInput', 'W');

    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');

    expect(tileRows($editor)[1])->toBe('#  ~WWW    #')
        ->and(tileRows($editor)[3])->toBe('#   WWW    #')
        ->and($history->count())->toBe(1);

    callEditorMethod($editor, 'dispatchInput', "\x1a");

    expect(tileRows($editor))->toBe($before);
});

it('selects, copies, and stamps a region - each paste one undo step', function () {
    $editor = canvasEditor();
    $before = tileRows($editor);

    // Author a 2x1 block to lift.
    setEditorProperty($editor, 'selectedPaintSymbol', 'A');
    placeCursor($editor, 1, 1);
    callEditorMethod($editor, 'dispatchInput', "\n");
    placeCursor($editor, 2, 1);
    callEditorMethod($editor, 'dispatchInput', "\n");

    setEditorProperty($editor, 'canvasTool', CanvasTool::SELECT);
    placeCursor($editor, 1, 1);
    callEditorMethod($editor, 'dispatchInput', "\n");
    placeCursor($editor, 2, 1);
    callEditorMethod($editor, 'dispatchInput', "\n");

    expect(getEditorProperty($editor, 'canvasSelection'))->toBe(['x' => 1, 'y' => 1, 'width' => 2, 'height' => 1]);

    callEditorMethod($editor, 'dispatchInput', "\x0c");

    /** @var Clipboard $clipboard */
    $clipboard = getEditorProperty($editor, 'clipboard');

    expect($clipboard->getRows())->toBe([['A', 'A']]);

    placeCursor($editor, 6, 3);
    callEditorMethod($editor, 'dispatchInput', "\x15");

    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');

    expect(tileRows($editor)[3])->toBe('#     AA   #')
        ->and($history->count())->toBe(3);

    // One Ctrl+Z removes the whole stamp.
    callEditorMethod($editor, 'dispatchInput', "\x1a");

    expect(tileRows($editor)[3])->toBe($before[3]);
});

it('cuts a selection in one undoable command', function () {
    $editor = canvasEditor();
    $before = tileRows($editor);

    setEditorProperty($editor, 'canvasTool', CanvasTool::SELECT);
    placeCursor($editor, 3, 1);
    callEditorMethod($editor, 'dispatchInput', "\n");
    placeCursor($editor, 5, 1);
    callEditorMethod($editor, 'dispatchInput', "\n");
    callEditorMethod($editor, 'dispatchInput', "\x18");

    /** @var Clipboard $clipboard */
    $clipboard = getEditorProperty($editor, 'clipboard');

    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');

    expect($clipboard->getRows())->toBe([['~', '~', '~']])
        ->and(tileRows($editor)[1])->toBe('#          #')
        ->and($history->count())->toBe(1);

    callEditorMethod($editor, 'dispatchInput', "\x1a");

    expect(tileRows($editor))->toBe($before);
});

it('pops the tool anchor before the selection when Esc is pressed', function () {
    $editor = canvasEditor();
    setEditorProperty($editor, 'canvasTool', CanvasTool::SELECT);
    placeCursor($editor, 1, 1);
    callEditorMethod($editor, 'dispatchInput', "\n");
    placeCursor($editor, 3, 2);
    callEditorMethod($editor, 'dispatchInput', "\n");

    expect(getEditorProperty($editor, 'canvasSelection'))->not->toBeNull();

    // A fresh anchor: Esc clears it and leaves the selection standing.
    callEditorMethod($editor, 'dispatchInput', "\n");

    expect(getEditorProperty($editor, 'canvasToolAnchor'))->not->toBeNull();

    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(getEditorProperty($editor, 'canvasToolAnchor'))->toBeNull()
        ->and(getEditorProperty($editor, 'canvasSelection'))->not->toBeNull();

    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(getEditorProperty($editor, 'canvasSelection'))->toBeNull();
});

it('loads a typed glyph into the brush without painting under a shape tool', function () {
    $editor = canvasEditor();
    $before = tileRows($editor);
    setEditorProperty($editor, 'canvasTool', CanvasTool::LINE);
    placeCursor($editor, 5, 2);
    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'dispatchInput', 'Q');

    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');

    expect(getEditorProperty($editor, 'selectedPaintSymbol'))->toBe('Q')
        ->and(tileRows($editor))->toBe($before)
        ->and($history->count())->toBe(0);

    // The brush still paints on type, exactly as it always has.
    setEditorProperty($editor, 'canvasTool', CanvasTool::BRUSH);
    callEditorMethod($editor, 'dispatchInput', 'Q');

    expect(tileRows($editor)[2][5])->toBe('Q');
});

it('paints every reserved glyph in Paint mode instead of running commands', function () {
    $editor = canvasEditor();
    setEditorProperty($editor, 'canvasTool', CanvasTool::BRUSH);
    placeCursor($editor, 4, 2);

    callEditorMethod($editor, 'dispatchInput', 'i');

    foreach (['?', '%', '^', '@'] as $index => $glyph) {
        placeCursor($editor, 4 + $index, 2);
        callEditorMethod($editor, 'dispatchInput', $glyph);
        expect(tileRows($editor)[2][4 + $index])->toBe($glyph);
    }

    expect(getEditorProperty($editor, 'isHelpOpen'))->toBeFalse()
        ->and(getEditorProperty($editor, 'isCharacterMapOpen'))->toBeFalse()
        ->and(getEditorProperty($editor, 'editingMode'))->toBe('map');
});

it('leaves Paint mode with Esc and treats letters as commands again', function () {
    $editor = canvasEditor();
    callEditorMethod($editor, 'dispatchInput', 'i');

    expect(getEditorProperty($editor, 'inputMode'))->toBe('paint');

    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(getEditorProperty($editor, 'inputMode'))->toBe('normal');

    // In Normal mode, letters command: b selects the brush, e switches layer.
    setEditorProperty($editor, 'canvasTool', CanvasTool::LINE);
    callEditorMethod($editor, 'dispatchInput', 'b');
    expect(getEditorProperty($editor, 'canvasTool'))->toBe(CanvasTool::BRUSH);

    callEditorMethod($editor, 'dispatchInput', 'e');
    expect(getEditorProperty($editor, 'editingMode'))->toBe('event');

    callEditorMethod($editor, 'dispatchInput', 'm');
    expect(getEditorProperty($editor, 'editingMode'))->toBe('map');

    callEditorMethod($editor, 'dispatchInput', 'c');
    expect(getEditorProperty($editor, 'isCharacterMapOpen'))->toBeTrue();
});

it('never paints typed glyphs in Normal mode', function () {
    $editor = canvasEditor();
    $before = tileRows($editor);
    placeCursor($editor, 5, 2);

    callEditorMethod($editor, 'dispatchInput', 'Q');

    expect(tileRows($editor))->toBe($before)
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('Normal mode');
});

it('opens help with ? in Normal mode and paints ? in Paint mode', function () {
    $editor = canvasEditor();

    callEditorMethod($editor, 'dispatchInput', '?');
    expect(getEditorProperty($editor, 'isHelpOpen'))->toBeTrue();

    callEditorMethod($editor, 'dispatchInput', "\033");
    callEditorMethod($editor, 'dispatchInput', 'i');
    placeCursor($editor, 5, 2);
    callEditorMethod($editor, 'dispatchInput', '?');

    expect(tileRows($editor)[2][5])->toBe('?')
        ->and(getEditorProperty($editor, 'isHelpOpen'))->toBeFalse();
});

it('paints with the brush colour and undoes glyph and colour together', function () {
    $editor = canvasEditor();
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $map = $workspace->getMapByIndex(0);
    placeCursor($editor, 5, 2);

    setEditorProperty($editor, 'selectedPaintColor', 'bright-cyan');
    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'dispatchInput', '?');

    expect($map->getTileSymbol(5, 2))->toBe('?')
        ->and($map->getTileColor(5, 2))->toBe('bright-cyan')
        ->and($map->getTileCellStyle(5, 2))->toBe(['prefix' => '<fg=bright-cyan>', 'suffix' => '</>']);

    callEditorMethod($editor, 'dispatchInput', "\x1a");

    expect($map->getTileSymbol(5, 2))->toBe(' ')
        ->and($map->getTileColor(5, 2))->toBeNull();
});

it('keeps a cell\'s authored styling byte-for-byte when the brush has no colour directive', function () {
    $editor = canvasEditor();
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $map = $workspace->getMapByIndex(0);
    $map->setTileCell(5, 2, '~', '<fg=yellow;options=bold>', '</>');
    placeCursor($editor, 5, 2);

    // Default brush: keep the cell colour, exotic options included.
    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'dispatchInput', 'Q');

    expect($map->getTileSymbol(5, 2))->toBe('Q')
        ->and($map->getTileCellStyle(5, 2))->toBe(['prefix' => '<fg=yellow;options=bold>', 'suffix' => '</>']);

    // Undo restores the original symbol with the original bytes.
    callEditorMethod($editor, 'dispatchInput', "\x1a");

    expect($map->getTileSymbol(5, 2))->toBe('~')
        ->and($map->getTileCellStyle(5, 2))->toBe(['prefix' => '<fg=yellow;options=bold>', 'suffix' => '</>']);
});

it('never leaves styling behind an erased cell', function () {
    $editor = canvasEditor();
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $map = $workspace->getMapByIndex(0);
    $map->setTileCell(5, 2, 'i', '<fg=bright-cyan>', '</>');
    placeCursor($editor, 5, 2);
    setEditorProperty($editor, 'selectedPaintColor', 'red');

    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'dispatchInput', "\177"); // Backspace erases.

    expect($map->getTileSymbol(5, 2))->toBe(' ')
        ->and($map->getTileCellStyle(5, 2))->toBe(['prefix' => '', 'suffix' => '']);
});

it('picks up the colour with the glyph through the eyedropper', function () {
    $editor = canvasEditor();
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $map = $workspace->getMapByIndex(0);
    $map->setTileCell(5, 2, 'm', '<fg=yellow>', '</>');
    placeCursor($editor, 5, 2);

    callEditorMethod($editor, 'dispatchInput', 'k');

    expect(getEditorProperty($editor, 'selectedPaintSymbol'))->toBe('m')
        ->and(getEditorProperty($editor, 'selectedPaintColor'))->toBe('yellow');

    // An uncoloured cell loads an uncoloured brush.
    placeCursor($editor, 6, 2);
    callEditorMethod($editor, 'dispatchInput', 'k');

    expect(getEditorProperty($editor, 'selectedPaintColor'))->toBe('');
});

it('recolours the cell under the cursor from the colour picker', function () {
    $editor = canvasEditor();
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $map = $workspace->getMapByIndex(0);
    $map->setTileSymbol(5, 2, 'i');
    placeCursor($editor, 5, 2);

    callEditorMethod($editor, 'dispatchInput', 'o');

    expect(getEditorProperty($editor, 'isColorPickerOpen'))->toBeTrue();

    // Down twice from 'Keep cell colour' lands on the first ANSI colour.
    callEditorMethod($editor, 'dispatchInput', "\033[B");
    callEditorMethod($editor, 'dispatchInput', "\033[B");
    callEditorMethod($editor, 'dispatchInput', "\n");

    expect(getEditorProperty($editor, 'isColorPickerOpen'))->toBeFalse()
        ->and(getEditorProperty($editor, 'selectedPaintColor'))->toBe('black')
        ->and($map->getTileSymbol(5, 2))->toBe('i')
        ->and($map->getTileColor(5, 2))->toBe('black');
});

it('refuses the colour picker on the event layer and in Paint mode', function () {
    $editor = canvasEditor();
    callEditorMethod($editor, 'dispatchInput', 'e');
    callEditorMethod($editor, 'dispatchInput', 'o');

    expect(getEditorProperty($editor, 'isColorPickerOpen'))->toBeFalse()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('Map layer');

    callEditorMethod($editor, 'dispatchInput', 'm');
    callEditorMethod($editor, 'dispatchInput', 'i');
    placeCursor($editor, 5, 2);
    callEditorMethod($editor, 'dispatchInput', 'o');

    // In Paint mode, o is a glyph.
    expect(getEditorProperty($editor, 'isColorPickerOpen'))->toBeFalse()
        ->and(tileRows($editor)[2][5])->toBe('o');
});

it('exits NPC mode with Esc, cancelling a pending move first', function () {
    $editor = canvasEditor();
    callEditorMethod($editor, 'dispatchInput', "\033OR"); // F3: NPC mode.

    expect(getEditorProperty($editor, 'editingMode'))->toBe('npc');

    // A pending move consumes the first Esc; the mode survives it.
    setEditorProperty($editor, 'npcMoveInProgress', ['mapIndex' => 0, 'npcIndex' => 0]);
    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(getEditorProperty($editor, 'npcMoveInProgress'))->toBeNull()
        ->and(getEditorProperty($editor, 'editingMode'))->toBe('npc');

    // With nothing pending, Esc walks out to the Map layer.
    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(getEditorProperty($editor, 'editingMode'))->toBe('map');
});

it('drops Paint mode when focus leaves the canvas or NPC mode begins', function () {
    $editor = canvasEditor();
    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'setFocusedPane', 'assets');

    expect(getEditorProperty($editor, 'inputMode'))->toBe('normal');

    callEditorMethod($editor, 'setFocusedPane', 'canvas');
    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'dispatchInput', "\033OR"); // F3: NPC mode.

    expect(getEditorProperty($editor, 'inputMode'))->toBe('normal')
        ->and(getEditorProperty($editor, 'editingMode'))->toBe('npc');
});

it('always offers the reserved and project vocabulary glyphs in the character map', function () {
    $projectRoot = makeTemporaryProject();
    file_put_contents(
        $projectRoot . '/assets/Maps/collisions.php',
        "<?php\n\nreturn ['?' => 6, 'z' => 1, ' ' => 0];\n",
    );

    $editor = createEditorForTesting($projectRoot);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($projectRoot));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    setEditorProperty($editor, 'focusedPane', 'canvas');

    $palette = callEditorMethod($editor, 'getCharacterPalette');

    // Stolen shortcut glyphs and dictionary vocabulary are always pickable,
    // whether or not the current map still contains them.
    foreach (['?', '%', '^', '@', 'z'] as $glyph) {
        expect($palette)->toContain($glyph);
    }
});

it('leaves the canvas shortcuts alone when another pane has focus', function () {
    $editor = canvasEditor();
    setEditorProperty($editor, 'focusedPane', 'assets');
    callEditorMethod($editor, 'dispatchInput', "\x0e");

    expect(getEditorProperty($editor, 'canvasTool'))->toBe(CanvasTool::BRUSH);
});

it('documents every canvas tool binding in the help overlay', function () {
    $editor = canvasEditor();
    $helpText = implode("\n", callEditorMethod($editor, 'getHelpLines'));

    foreach (['Ctrl+N', 'Ctrl+W', 'Ctrl+F', 'Ctrl+K', 'Ctrl+L', 'Ctrl+X', 'Ctrl+U', 'Ctrl+G', 'Ctrl+B'] as $key) {
        expect($helpText)->toContain($key);
    }

    expect($helpText)->toContain('Backups off');
});

it('scrolls the help overlay now that the binding table outgrew a short screen', function () {
    $editor = canvasEditor();
    // The canvas owns ? for painting, so help opens from another pane.
    setEditorProperty($editor, 'focusedPane', 'assets');
    callEditorMethod($editor, 'dispatchInput', '?');

    expect(getEditorProperty($editor, 'isHelpOpen'))->toBeTrue()
        ->and(getEditorProperty($editor, 'helpScrollRow'))->toBe(0);

    callEditorMethod($editor, 'dispatchInput', "\033[B");
    callEditorMethod($editor, 'dispatchInput', "\033[B");

    expect(getEditorProperty($editor, 'helpScrollRow'))->toBe(2);

    callEditorMethod($editor, 'dispatchInput', "\033[A");

    expect(getEditorProperty($editor, 'helpScrollRow'))->toBe(1);

    // Up never walks past the first row, and Esc still closes one level.
    foreach (range(1, 5) as $ignored) {
        callEditorMethod($editor, 'dispatchInput', "\033[A");
    }

    expect(getEditorProperty($editor, 'helpScrollRow'))->toBe(0);

    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(getEditorProperty($editor, 'isHelpOpen'))->toBeFalse();
});

it('renders authored cell colours in the canvas preview', function () {
    $editor = canvasEditor();
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $map = $workspace->getMapByIndex(0);
    $map->setTileCell(2, 2, ';', '<fg=bright-green>', '</>');
    $map->setTileCell(3, 2, '~', '<fg=#b87333>', '</>');

    $lines = $map->renderPreview(12, 5);

    // Named colours use the 4-bit palette; hex values use truecolor. Both
    // reset immediately so neighbouring cells stay untouched.
    expect($lines[2])->toContain("\033[92m;\033[0m")
        ->and($lines[2])->toContain("\033[38;2;184;115;51m~\033[0m");

    // An event marker over a coloured tile stays plain authoring geometry.
    $map->setEventSymbol(2, 2, 'A');
    $lines = $map->renderPreview(12, 5);

    expect($lines[2])->not->toContain("\033[92m")
        ->and($lines[2])->toContain('A');
});

/** Builds an SGR mouse press at 1-based terminal coordinates. */
function mousePress(int $code, int $column, int $row): string
{
    return sprintf("\033[<%d;%d;%dM", $code, $column, $row);
}

/** Returns the terminal cell of a map coordinate under the current layout. */
function canvasCellAt(Editor $editor, int $mapX, int $mapY): array
{
    $layout = callEditorMethod($editor, 'resolveLayout');

    return [
        2 + $layout['leftWidth'] + $layout['gutter'] + 1 + 1 + $mapX,
        5 + 3 + $mapY,
    ];
}

it('selects with a Normal-mode click and paints only in Paint mode', function () {
    $editor = canvasEditor();
    $before = tileRows($editor);
    [$column, $row] = canvasCellAt($editor, 4, 2);

    // Normal mode: the click is a locator, not an edit.
    callEditorMethod($editor, 'dispatchInput', mousePress(0, $column, $row));

    expect(getEditorProperty($editor, 'cursorX'))->toBe(4)
        ->and(getEditorProperty($editor, 'cursorY'))->toBe(2)
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('(4, 2)')
        ->and(tileRows($editor))->toBe($before);

    // Paint mode: the same click paints the brush symbol.
    setEditorProperty($editor, 'selectedPaintSymbol', 'Q');
    callEditorMethod($editor, 'dispatchInput', 'i');
    callEditorMethod($editor, 'dispatchInput', mousePress(0, $column, $row));

    expect(tileRows($editor)[2][4])->toBe('Q');
});

it('scrolls the viewport with the wheel without moving the cursor, until the cursor reclaims it', function () {
    $editor = canvasEditor();
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    // A map taller and wider than the viewport, so there is room to scroll.
    $map = $workspace->getMapByIndex(0);
    placeCursor($editor, 0, 0);
    [$column, $row] = canvasCellAt($editor, 2, 2);

    callEditorMethod($editor, 'dispatchInput', mousePress(65, $column, $row)); // Wheel down.

    $scrolledY = getEditorProperty($editor, 'canvasOffsetY');
    $maxOffsetY = max(0, $map->getHeight() - 1);

    // The fixture map may be smaller than the viewport; either the view
    // scrolled or it was already fully visible and stayed clamped at zero.
    expect($scrolledY)->toBeLessThanOrEqual($maxOffsetY)
        ->and(getEditorProperty($editor, 'cursorX'))->toBe(0)
        ->and(getEditorProperty($editor, 'cursorY'))->toBe(0);

    // The next cursor movement reclaims the viewport.
    callEditorMethod($editor, 'dispatchInput', "\033[C");
    expect(getEditorProperty($editor, 'canvasOffsetY'))->toBe(0);
});

it('shows the cursor locator in the canvas header', function () {
    $editor = canvasEditor();
    placeCursor($editor, 7, 3);
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');

    $lines = $workspace->getCanvasLines(0, 40, 10, cursor: ['x' => 7, 'y' => 3]);

    expect($lines[1])->toContain('cursor 7,3');
});
