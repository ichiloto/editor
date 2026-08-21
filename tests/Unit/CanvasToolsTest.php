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
    callEditorMethod($editor, 'dispatchInput', 'W');

    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');

    expect(tileRows($editor)[1])->toBe('#  ~WWW    #')
        ->and(tileRows($editor)[3])->toBe('#   WWW    #')
        ->and($history->count())->toBe(1);

    callEditorMethod($editor, 'dispatchInput', "\x1a");

    expect(tileRows($editor))->toBe($before);
});

it('selects, copies, and stamps a region — each paste one undo step', function () {
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
