<?php

declare(strict_types=1);

use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\Maps\CellSymbol;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\MapValidator;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\Core\ProjectFormat;
use Ichiloto\Engine\Exceptions\UnsupportedProjectFormatException;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\IO\Console\TerminalText;

/**
 * An editor on a disposable copy of the sample project, focused on its canvas.
 *
 * @return array{0: \Ichiloto\Editor\Editor, 1: \Ichiloto\Editor\ProjectMap, 2: string}
 */
function createCellEditor(?string $root = null): array
{
    $root ??= makeTemporaryProject('ichiloto-cells-');
    $editor = createEditorForTesting($root);
    $workspace = ProjectWorkspace::fromProject($root);
    setEditorProperty($editor, 'workspace', $workspace);
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    setEditorProperty($editor, 'focusedPane', 'canvas');

    return [$editor, $workspace->getMapByIndex(0), $root];
}

/** Writes the sample map's tile rows, a project copy's only map. */
function writeCellMapRows(string $root, array $rows): string
{
    $path = $root . '/assets/Maps/test-map/test-map.map.php';
    file_put_contents($path, MapGridSource::buildSource(implode("\n", $rows), 'ICHILOTO_MAP'));

    return $path;
}

/** Replaces the sample map's data entries. */
function writeCellMapData(string $root, array $entries): void
{
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = array_replace(require $path, $entries);
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
}

/** Types keys through the editor's input router. */
function typeCellKeys(\Ichiloto\Editor\Editor $editor, string ...$keys): void
{
    foreach ($keys as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
}

it('paints one typed character as a cell of it repeated and a two-column glyph as it is', function () {
    [$editor, $map] = createCellEditor();
    setEditorProperty($editor, 'cursorX', 1);
    setEditorProperty($editor, 'cursorY', 2);

    typeCellKeys($editor, 'i', '#', "\033[C", '🌲', "\033[C", ' ');

    expect($map->getTileSymbol(1, 2))->toBe('##')
        ->and($map->getTileSymbol(2, 2))->toBe('🌲')
        ->and($map->getTileSymbol(3, 2))->toBe('  ');
});

it('turns a quick second keystroke into a pair that undoes as one step, and starts over on the third', function () {
    [$editor, $map] = createCellEditor();
    setEditorProperty($editor, 'cursorX', 1);
    setEditorProperty($editor, 'cursorY', 2);
    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');

    typeCellKeys($editor, 'i', '[');
    expect($map->getTileSymbol(1, 2))->toBe('[[');
    typeCellKeys($editor, ']');
    expect($map->getTileSymbol(1, 2))->toBe('[]')
        ->and($history->count())->toBe(1)
        ->and(getEditorProperty($editor, 'selectedPaintSymbol'))->toBe('[]');

    typeCellKeys($editor, 'x');
    expect($map->getTileSymbol(1, 2))->toBe('xx');
    typeCellKeys($editor, 'y');
    expect($map->getTileSymbol(1, 2))->toBe('xy')
        ->and($history->count())->toBe(2);

    typeCellKeys($editor, "\x1a");
    expect($map->getTileSymbol(1, 2))->toBe('[]');
    typeCellKeys($editor, "\x1a");
    expect($map->getTileSymbol(1, 2))->toBe('  ')
        ->and($map->isDirty())->toBeFalse();
});

it('forgets the first keystroke once the cursor moves', function () {
    [$editor, $map] = createCellEditor();
    setEditorProperty($editor, 'cursorX', 1);
    setEditorProperty($editor, 'cursorY', 2);

    typeCellKeys($editor, 'i', '[', "\033[C", "\033[D", ']');

    expect($map->getTileSymbol(1, 2))->toBe(']]');

    // A key aimed at another cell starts that cell over too.
    typeCellKeys($editor, "\033[C", "\033[D", '<');
    setEditorProperty($editor, 'cursorX', 2);
    typeCellKeys($editor, '>');

    expect($map->getTileSymbol(1, 2))->toBe('<<')
        ->and($map->getTileSymbol(2, 2))->toBe('>>');
});

it('fills a cell with the brush colour and keeps each character of a styled pair under the default brush', function () {
    $root = makeTemporaryProject('ichiloto-cells-');
    $path = writeCellMapRows($root, [
        str_repeat('#', 24),
        '##<fg=red>[</><fg=blue>]</>' . str_repeat(' ', 16) . '##',
        '##' . str_repeat(' ', 20) . '##',
        '##' . str_repeat(' ', 20) . '##',
        str_repeat('#', 24),
    ]);
    $original = (string) file_get_contents($path);
    [$editor, $map] = createCellEditor($root);
    setEditorProperty($editor, 'cursorX', 1);
    setEditorProperty($editor, 'cursorY', 1);
    $pairStyle = $map->getTileCellStyle(1, 1);

    expect($map->getTileSymbol(1, 1))->toBe('[]')
        ->and($pairStyle['styles'])->toBe([
            ['prefix' => '<fg=red>', 'suffix' => '</>'], ['prefix' => '<fg=blue>', 'suffix' => '</>'],
        ]);

    typeCellKeys($editor, 'i', '#', "\033");
    expect($map->getTileSymbol(1, 1))->toBe('##')
        ->and($map->getTileCellStyle(1, 1))->toBe($pairStyle);

    typeCellKeys($editor, "\x13");
    expect((string) file_get_contents($path))->toContain('##<fg=red>#</><fg=blue>#</>' . str_repeat(' ', 16) . '##');

    typeCellKeys($editor, "\x1a", "\x13");
    expect((string) file_get_contents($path))->toBe($original)
        ->and($map->getTileSymbol(1, 1))->toBe('[]');

    setEditorProperty($editor, 'selectedPaintColor', 'green');
    typeCellKeys($editor, 'i', '#');
    expect($map->getTileCellStyle(1, 1))->toBe(['prefix' => '<fg=green>', 'suffix' => '</>']);
});

it('repeats one marker per event cell and never pairs markers', function () {
    [$editor, $map] = createCellEditor();
    setEditorProperty($editor, 'cursorX', 2);
    setEditorProperty($editor, 'cursorY', 2);

    typeCellKeys($editor, 'e', 'i', 'A');
    expect($map->getEventSymbol(2, 2))->toBe('AA');
    typeCellKeys($editor, 'B');

    expect($map->getEventSymbol(2, 2))->toBe('BB')
        ->and($map->getEventMarkerAt(2, 2))->toBe('B');

    // A pair carried over from the tile layer paints its first marker.
    setEditorProperty($editor, 'selectedPaintSymbol', '[]');
    typeCellKeys($editor, "\r");
    expect($map->getEventSymbol(2, 2))->toBe('[[')
        ->and($map->describeConflictingEventCells())->toBe([]);
});

it('offers whole cells in the character map and enters a typed pair', function () {
    $root = makeTemporaryProject('ichiloto-cells-');
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\n\nreturn ['=' => 0, 'z' => 1];\n");
    [$editor, $map] = createCellEditor($root);
    setEditorProperty($editor, 'cursorX', 3);
    setEditorProperty($editor, 'cursorY', 2);

    $palette = callEditorMethod($editor, 'getCharacterPalette');
    expect($palette)->toContain('##', '~~', '  ', '==', 'zz', '??', '@@', '██', '🧍')
        ->and(array_filter($palette, static fn(string $cell): bool => ! CellSymbol::isCell($cell)))->toBe([]);

    typeCellKeys($editor, 'c', '<', '>');
    expect(getEditorProperty($editor, 'characterMapCell'))->toBe('<>');
    typeCellKeys($editor, "\r");

    expect($map->getTileSymbol(3, 2))->toBe('<>')
        ->and(getEditorProperty($editor, 'isCharacterMapOpen'))->toBeFalse()
        ->and(getEditorProperty($editor, 'selectedPaintSymbol'))->toBe('<>');

    // A third key starts the typed cell over; arrows return to the palette.
    typeCellKeys($editor, 'c', 'a', 'b', 'c');
    expect(getEditorProperty($editor, 'characterMapCell'))->toBe('cc');
    typeCellKeys($editor, "\033[C");
    expect(getEditorProperty($editor, 'characterMapCell'))->toBe('');
});

it('maps the cursor, clicks and the wheel to two terminal columns per cell', function () {
    [$editor, $map] = createCellEditor();
    $map->resize(100, 50);
    $bounds = callEditorMethod($editor, 'getCanvasPreviewBounds');
    expect($bounds['cells'])->toBe(intdiv($bounds['width'], 2));

    setEditorProperty($editor, 'canvasOffsetX', 3);
    foreach ([0, 1] as $column) {
        setEditorProperty($editor, 'cursorX', 0);
        typeCellKeys($editor, sprintf("\033[<0;%d;%dM", $bounds['left'] + 8 + $column, $bounds['top'] + 1));
        typeCellKeys($editor, sprintf("\033[<0;%d;%dm", $bounds['left'] + 8 + $column, $bounds['top'] + 1));
        expect(getEditorProperty($editor, 'cursorX'))->toBe(7, "column {$column} of the cell")
            ->and(getEditorProperty($editor, 'cursorY'))->toBe(1);
    }

    // A column past the last whole cell selects nothing.
    typeCellKeys($editor, sprintf("\033[<0;%d;%dM", $bounds['left'] + $bounds['cells'] * 2, $bounds['top'] + 1));
    expect(getEditorProperty($editor, 'cursorX'))->toBe(7);

    $layout = callEditorMethod($editor, 'resolveLayout');
    ob_start();
    callEditorMethod($editor, 'renderCanvasCursor', $layout);
    $output = (string) ob_get_clean();
    expect($output)->toContain(sprintf("\033[%d;%dH", $bounds['top'] + 1, $bounds['left'] + 8));

    // The last cell scrolls into view; the wheel stops where it is last visible.
    setEditorProperty($editor, 'cursorX', 99);
    callEditorMethod($editor, 'syncViewportToCursor');
    expect(getEditorProperty($editor, 'canvasOffsetX'))->toBe(100 - $bounds['cells']);
    setEditorProperty($editor, 'canvasOffsetX', 100 - $bounds['cells'] - 1);
    typeCellKeys($editor, sprintf("\033[<67;%d;%dM", $bounds['left'], $bounds['top']));
    expect(getEditorProperty($editor, 'canvasOffsetX'))->toBe(100 - $bounds['cells']);
});

it('writes event markers repeated across each cell and reads a marker in either column', function () {
    [, $map] = createCellEditor();
    $map->setEventBounds('E', 2, 3, 2, 1);

    expect($map->getEventSymbol(2, 3))->toBe('EE')
        ->and($map->getEventSymbol(3, 3))->toBe('EE')
        ->and($map->getEventSymbol(5, 1))->toBe('  ')
        ->and($map->getEventBounds('E'))->toBe(['x' => 2, 'y' => 3, 'width' => 2, 'height' => 1]);

    $map->setEventSymbol(4, 3, ' E');
    expect($map->getEventMarkerAt(4, 3))->toBe('E')
        ->and($map->isEventMarkerSolidRectangle('E'))->toBeTrue();

    $map->setEventSymbol(0, 0, 'EF');
    expect($map->getEventMarkerAt(0, 0))->toBeNull()
        ->and($map->getPlacedEventMarkers())->toBe(['E'])
        ->and($map->describeConflictingEventCells())->toBe(['Event cell at row 0, column 0 holds two different markers, E and F.'])
        ->and(MapValidator::validate($map, [$map->mapId => $map]))->toContain('Event cell at row 0, column 0 holds two different markers, E and F.');
});

it('overhangs a sprite wider than its cell and keeps the row in whole cells', function () {
    $root = makeTemporaryProject('ichiloto-cells-');
    writeCellMapData($root, ['npcs' => [
        ['id' => 'wide', 'name' => 'Wide', 'sprite' => 'abc', 'x' => 2, 'y' => 2],
        ['id' => 'tree', 'name' => 'Tree', 'sprite' => '🌲', 'x' => 6, 'y' => 2],
    ]]);
    [$editor, $map] = createCellEditor($root);

    $row = $map->renderPreview(12, 5, showNpcOverlay: true, selectedNpcIndex: 0)[2];

    expect(TerminalText::stripAnsi($row))->toBe('##  abc     🌲        ##')
        ->and($row)->toContain("\033[7mabc \033[0m")
        ->and(mb_strwidth(TerminalText::stripAnsi($row)))->toBe(24);

    // The view starting inside the overhang keeps its columns.
    expect(TerminalText::stripAnsi($map->renderPreview(4, 5, offsetX: 3, showNpcOverlay: true)[2]))->toBe('      🌲');

    // A duplicate stands in the next cell, whatever its sprite's width.
    callEditorMethod($editor, 'setEditingMode', 'npc');
    callEditorMethod($editor, 'selectNpc', 1);
    typeCellKeys($editor, 'D');
    expect($map->getNpcs()->get(2)?->getX())->toBe(7);
});

it('refuses misaligned rows with the Engine row and column, in the editor and validation', function (string $row, string $message) {
    $root = makeTemporaryProject('ichiloto-cells-');
    writeCellMapRows($root, [str_repeat('#', 24), $row, str_repeat('#', 24)]);
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);

    expect($map->getGridSourceIssue())->toContain($message);

    $issues = issuesMentioning(validateProject($root), $message);
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR);
})->with([
    'glyph after a lone character' => ['#🌲' . str_repeat(' ', 20) . '#', 'row 1, column 1: a two-column glyph must begin a cell'],
    'lone trailing character' => [str_repeat('#', 23), 'row 1 ends halfway through a cell'],
]);

it('refuses to open a project in another format with the Engine explanation', function (mixed $format) {
    $root = makeTemporaryProject('ichiloto-cells-');
    $config = json_decode((string) file_get_contents($root . '/ichiloto.json'), true);
    if ($format === null) {
        unset($config[ProjectFormat::KEY]);
    } else {
        $config[ProjectFormat::KEY] = $format;
    }
    file_put_contents($root . '/ichiloto.json', json_encode($config));
    try {
        ProjectFormat::assertSupported($format);
        $expected = null;
    } catch (UnsupportedProjectFormatException $refusal) {
        $expected = $refusal->getMessage();
    }

    expect($expected)->not->toBeNull()
        ->and(fn() => ProjectWorkspace::fromProject($root))->toThrow(UnsupportedProjectFormatException::class, $expected);
})->with([
    'unrecorded' => [null],
    'older' => [ProjectFormat::CURRENT - 1],
    'newer' => [ProjectFormat::CURRENT + 1],
]);

it('warns that a tiles2d crop table is no longer read', function () {
    $root = makeTemporaryProject('ichiloto-cells-');
    writeCellMapData($root, ['tiles2d' => ['asset' => 'Graphics/Tilesets/shared.png', 'symbols' => []]]);

    $issues = issuesMentioning(validateProject($root), 'tiles2d');
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::WARNING)
        ->and($issues[0]->message)->toBe('Its tiles2d crop table is no longer read.')
        ->and(MapValidator::validate($map, [$map->mapId => $map]))->toContain('tiles2d is no longer read; the map shows its terminal glyphs until it has a tileset.');
});
