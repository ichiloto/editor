<?php

declare(strict_types=1);

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\EditableGrid;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\IO\Console\TerminalText;

it('keys repeated cells by the exact glyph prefix and suffix without merging styled identities', function () {
    $text = "<fg=red>00</><fg=blue>0</><fg=red>x</>\n\033[31m00\033[0m<fg=red>0</>\n<fg=red><options=bold>0</></>";
    $source = MapGridSource::buildSource($text, 'IDENTITIES');
    $grid = new EditableGrid($text, $source);
    expect($grid->cells)->toBe([
        [
            ['symbol' => '0', 'prefix' => '<fg=red>', 'suffix' => '</>'],
            ['symbol' => '0', 'prefix' => '<fg=red>', 'suffix' => '</>'],
            ['symbol' => '0', 'prefix' => '<fg=blue>', 'suffix' => '</>'],
            ['symbol' => 'x', 'prefix' => '<fg=red>', 'suffix' => '</>'],
        ],
        // A row mixing ANSI and tags is read as the Engine reads it: each cell replays the row's controls.
        TerminalText::parseSourceCells("\033[31m00\033[0m<fg=red>0</>"),
        [['symbol' => '0', 'prefix' => '<fg=red><options=bold>', 'suffix' => '</></>']],
    ])->and($grid->cells[1][0])->toBe($grid->cells[1][1])
        ->and($grid->cells[1][2]['prefix'])->not->toBe($grid->cells[1][0]['prefix'])
        ->and($grid->getSource())->toBe($source);
});

it('keeps repeated cell values independently editable with exact source and snapshot restoration', function () {
    $text = "<fg=green>xx</>\n<fg=green>xx</>\n<fg=red>x</>";
    $source = MapGridSource::buildSource($text, 'CELLS', "// Preserve the authored rows.\n");
    $grid = new EditableGrid($text, $source);
    $before = $grid->captureSnapshot();
    $cell = &$grid->cells[0][0];
    $cell['symbol'] = 'y';
    $cell['prefix'] = '<fg=blue>';
    unset($cell);

    expect($grid->cells[0][1])->toBe(['symbol' => 'x', 'prefix' => '<fg=green>', 'suffix' => '</>'])
        ->and($grid->cells[1])->toBe($before['cells'][1])
        ->and($grid->cells[2])->toBe($before['cells'][2])
        ->and($before['cells'][0][0]['symbol'])->toBe('x')
        ->and($grid->getSource())->toBe(str_replace(
            "<fg=green>xx</>\n<fg=green>xx</>",
            "<fg=blue>y</><fg=green>x</>\n<fg=green>xx</>",
            $source,
        ));
    $edited = $grid->captureSnapshot();
    $restored = EditableGrid::createFromSnapshot($before);
    expect($restored->getSource())->toBe($source)
        ->and(array_map(count(...), $restored->cells))->toBe([2, 2, 1]);
    $redone = EditableGrid::createFromSnapshot($edited);
    expect($redone->getSource())->toBe($grid->getSource());
    $redone->cells[1][0]['suffix'] = '';
    expect($grid->cells[1][0]['suffix'])->toBe('</>')
        ->and($redone->cells[1][1]['suffix'])->toBe('</>');
});

it('holds a multi-map workload of repeated editable cells within the unchanged 128 MiB CLI budget', function () {
    $probe = <<<'PHP'
    require $argv[1];
    $lines = [];
    for ($row = 0; $row < 96; $row++) {
        $lines[] = '<fg=green>' . str_repeat('.', 80) . '</>' . str_repeat(' ', 80 - $row % 2);
    }
    $text = implode("\n", $lines);
    $source = \Ichiloto\Engine\Field\MapGridSource::buildSource($text, 'CELLS');
    $grids = [];
    $cellCount = 0;
    for ($index = 0; $index < 30; $index++) {
        $grid = new \Ichiloto\Editor\Maps\EditableGrid($text, $source);
        $cellCount += array_sum(array_map(count(...), $grid->cells));
        $grids[] = $grid;
    }
    $grids[0]->cells[0][0]['symbol'] = '#';
    echo json_encode([
        'grids' => count($grids),
        'cells' => $cellCount,
        'independent' => $grids[1]->cells[0][0]['symbol'] === '.' && $grids[0]->cells[0][1]['symbol'] === '.',
        'limit' => ini_get('memory_limit'),
        'peak' => memory_get_peak_usage(true),
    ], JSON_THROW_ON_ERROR);
    PHP;
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=128M', '-r', $probe, dirname(__DIR__) . '/bootstrap.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    expect(is_resource($process))->toBeTrue();
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    expect($exitCode)->toBe(0, $error)
        ->and($error)->toBe('');
    $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($result['grids'])->toBe(30)
        ->and($result['cells'])->toBe(459360)
        ->and($result['independent'])->toBeTrue()
        ->and($result['limit'])->toBe('128M')
        ->and($result['peak'])->toBeLessThan(128 * 1024 * 1024);
});

/** The glyphs the Engine reads from one written row, without their colours. */
function readEngineRowGlyphs(string $row): array
{
    return array_map(TerminalText::stripAnsi(...), new MapLayer('literal', 0, false, 'synthetic', $row)->getRowGlyphs(0));
}

it('writes a styled literal delimiter or backslash beside styled neighbours so the Engine and a reload read the same cells', function (string $glyph) {
    // The untouched row holds a styled backslash and a less-than, written as the Engine composes them.
    $text = "<fg=green>abc</>d\n<fg=red>untouched </>" . TerminalText::composeSourceCell('\\', '<fg=red>', '</>') . " <\n<fg=blue>xyz</>";
    $source = MapGridSource::buildSource($text, 'LITERALS');
    $grid = new EditableGrid($text, $source);
    $untouched = $grid->getLines();
    $grid->cells[0][1] = ['symbol' => $glyph, 'prefix' => '<fg=red>', 'suffix' => '</>'];
    $grid->cells[0][3] = ['symbol' => $glyph, 'prefix' => '', 'suffix' => ''];
    $grid->cells[2][2] = ['symbol' => $glyph, 'prefix' => '<fg=blue>', 'suffix' => '</>'];

    $written = $grid->getSource();
    $rows = explode("\n", MapGridSource::parseSource($written, 'synthetic'));
    expect(readEngineRowGlyphs($rows[0]))->toBe(['a', $glyph, 'c', $glyph])
        ->and(readEngineRowGlyphs($rows[2]))->toBe(['x', 'y', $glyph])
        // The row nobody edited keeps its authored bytes, its own backslash and less-than included.
        ->and($rows[1])->toBe($untouched[1])
        ->and($written)->not->toContain("\0");

    // Reading the written rows back gives the painted cells, and their colours stay distinct.
    $reloaded = new EditableGrid(implode("\n", $rows), $written);
    expect($reloaded->getSymbols()[0])->toBe(['a', $glyph, 'c', $glyph])
        ->and($reloaded->getSymbols()[2])->toBe(['x', 'y', $glyph])
        ->and(ProjectMap::getInnermostForeground($reloaded->cells[0][1]['prefix']))->toBe('red')
        ->and(ProjectMap::getInnermostForeground($reloaded->cells[0][3]['prefix']))->toBeNull()
        ->and(ProjectMap::getInnermostForeground($reloaded->cells[2][2]['prefix']))->toBe('blue')
        ->and($reloaded->getSource())->toBe($written);

    // A further edit of the reloaded row writes the same cells again.
    $reloaded->cells[0][0] = ['symbol' => 'q', 'prefix' => '<fg=green>', 'suffix' => '</>'];
    expect(readEngineRowGlyphs(explode("\n", MapGridSource::parseSource($reloaded->getSource(), 'synthetic'))[0]))
        ->toBe(['q', $glyph, 'c', $glyph]);
})->with(['less-than' => '<', 'greater-than' => '>', 'backslash' => '\\']);

it('keeps separately painted literal cells from joining into a formatter tag or escaping one another', function () {
    $glyphs = ['<', 'i', 'n', 'f', 'o', '>', '\\', '<', 'f', 'g', '=', 'r', 'e', 'd', '>'];
    $text = str_repeat('.', count($glyphs));
    $grid = new EditableGrid($text, MapGridSource::buildSource($text, 'LITERALS'));
    foreach ($glyphs as $x => $glyph) {
        $grid->cells[0][$x] = ['symbol' => $glyph, 'prefix' => '', 'suffix' => ''];
    }
    $row = MapGridSource::parseSource($grid->getSource(), 'synthetic');
    expect(readEngineRowGlyphs($row))->toBe($glyphs)
        ->and(new EditableGrid($row)->getSymbols()[0])->toBe($glyphs);
});

it('refuses to write a literal delimiter in a style the formatter cannot compose, writing nothing', function () {
    $text = 'ab';
    $source = MapGridSource::buildSource($text, 'LITERALS');
    $grid = new EditableGrid($text, $source);
    $grid->cells[0][0] = ['symbol' => '<', 'prefix' => '<not-a-style>', 'suffix' => '</>'];
    expect(fn() => $grid->getSource())->toThrow(MapSourceRefusal::class, 'Nothing was written.');
});

it('reads every row exactly as the Engine source parser does, escapes and unrecognised tags included', function (string $row) {
    $text = MapGridSource::parseSource(MapGridSource::buildSource($row, 'ROWS'), 'synthetic');
    expect(new EditableGrid($text)->cells)->toBe([TerminalText::parseSourceCells($text)]);
})->with([
    'escaped delimiter' => ['a\\<b'],
    'unrecognised tag, read as its glyphs' => ['#  <blue>~~~</blue>  #'],
    'colour across a literal less-than' => ["\033[31ma<b\033[0m"],
    'nested styles' => ['<fg=red><options=bold>x</></>y'],
]);

it('refuses a row the Engine cannot split into independent cells before anything can be written', function () {
    expect(fn() => new EditableGrid("ab\n<fg=red>a\\</>"))
        ->toThrow(MapSourceRefusal::class, 'Row 1 cannot be read as independent cells');
});

it('keeps a map whose row the Engine refuses browsable and read-only, its files untouched', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Maps/test-map/test-map.map.php';
    file_put_contents($path, str_replace('#          #', '#<fg=red>a\\</>        #', (string) file_get_contents($path), $count));
    $before = sourceHashTree($root);
    $map = array_find(\Ichiloto\Editor\ProjectWorkspace::fromProject($root)->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map');

    expect($count)->toBeGreaterThan(0)
        ->and($map->getGridSourceIssue())->toContain('cannot be read as independent cells')
        ->and(fn() => $map->setTileSymbol(1, 1, '@'))->toThrow(MapSourceRefusal::class, 'read-only')
        ->and(sourceHashTree($root))->toBe($before);
});

it('refuses to write a cell whose style would change the glyphs the Engine reads, writing nothing', function () {
    $text = 'ab';
    $grid = new EditableGrid($text, MapGridSource::buildSource($text, 'STYLES'));
    // An unrecognised tag is glyphs to the Engine, so the painted cell would read back as more than one.
    $grid->cells[0][0] = ['symbol' => 'x', 'prefix' => '<not-a-style>', 'suffix' => ''];
    expect(fn() => $grid->getSource())->toThrow(MapSourceRefusal::class, 'would not read back as the cells painted');
});
