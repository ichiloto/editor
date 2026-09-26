<?php

declare(strict_types=1);

use Ichiloto\Editor\Maps\EditableGrid;
use Ichiloto\Engine\Field\MapGridSource;

it('keys repeated cells by the exact cell text prefix and suffix without merging styled identities', function () {
    $text = "<fg=red>0000</><fg=blue>00</><fg=red>xx</>\n\033[31m0000\033[0m<fg=red>00</>\n<fg=red><options=bold>00</></>";
    $source = MapGridSource::buildSource($text, 'IDENTITIES');
    $grid = new EditableGrid($text, $source);
    expect($grid->cells)->toBe([
        [
            ['symbol' => '00', 'prefix' => '<fg=red>', 'suffix' => '</>'],
            ['symbol' => '00', 'prefix' => '<fg=red>', 'suffix' => '</>'],
            ['symbol' => '00', 'prefix' => '<fg=blue>', 'suffix' => '</>'],
            ['symbol' => 'xx', 'prefix' => '<fg=red>', 'suffix' => '</>'],
        ],
        [
            ['symbol' => '00', 'prefix' => "\033[31m", 'suffix' => "\033[0m"],
            ['symbol' => '00', 'prefix' => "\033[31m", 'suffix' => "\033[0m"],
            ['symbol' => '00', 'prefix' => '<fg=red>', 'suffix' => '</>'],
        ],
        [['symbol' => '00', 'prefix' => '<fg=red><options=bold>', 'suffix' => '</></>']],
    ])->and($grid->getSource())->toBe($source);
});

it('keeps repeated cell values independently editable with exact source and snapshot restoration', function () {
    $text = "<fg=green>xxxx</>\n<fg=green>xxxx</>\n<fg=red>xx</>";
    $source = MapGridSource::buildSource($text, 'CELLS', "// Preserve the authored rows.\n");
    $grid = new EditableGrid($text, $source);
    $before = $grid->captureSnapshot();
    $cell = &$grid->cells[0][0];
    $cell['symbol'] = 'yy';
    $cell['prefix'] = '<fg=blue>';
    unset($cell);

    expect($grid->cells[0][1])->toBe(['symbol' => 'xx', 'prefix' => '<fg=green>', 'suffix' => '</>'])
        ->and($grid->cells[1])->toBe($before['cells'][1])
        ->and($grid->cells[2])->toBe($before['cells'][2])
        ->and($before['cells'][0][0]['symbol'])->toBe('xx')
        ->and($grid->getSource())->toBe(str_replace(
            "<fg=green>xxxx</>\n<fg=green>xxxx</>",
            "<fg=blue>yy</><fg=green>xx</>\n<fg=green>xxxx</>",
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

it('groups pairs, two-column glyphs and blank cells into whole cells', function () {
    $grid = new EditableGrid("##[]🌲  \n.. E🧍");
    expect($grid->getSymbols())->toBe([['##', '[]', '🌲', '  '], ['..', ' E', '🧍']])
        ->and($grid->cells[0][2])->toBe(['symbol' => '🌲', 'prefix' => '', 'suffix' => '']);
});

it('keeps each character of a differently styled pair and round-trips it byte for byte', function (string $row) {
    $text = $row . "\n<fg=red>##</>..";
    $source = MapGridSource::buildSource($text, 'PAIRS');
    $grid = new EditableGrid($text, $source);
    $pair = $grid->cells[0][0];
    expect($pair['symbol'])->toBe('[]')
        ->and(EditableGrid::getCellRuns($pair))->toHaveCount(2)
        ->and($grid->getSource())->toBe($source);
    // Editing another cell regenerates the row; the pair keeps both styles.
    $grid->cells[0][1] = EditableGrid::createCell('##', ['prefix' => '', 'suffix' => '']);
    $expected = str_replace($row, preg_replace('/\.\.$/', '##', $row), $source);
    expect($grid->getSource())->toBe($expected)
        ->and(new EditableGrid(MapGridSource::parseSource($expected, 'PAIRS'))->cells[0][0])->toBe($pair);
})->with([
    'two colours' => ['<fg=red>[</><fg=blue>]</>..'],
    'one uncoloured half' => ['[<fg=yellow>]</>..'],
    'raw ANSI' => ["\033[31m[\033[0m\033[34m]\033[0m.."],
]);

it('keeps a nested style pair byte for byte while its row is unchanged', function () {
    $text = "<fg=red>[<options=bold>]</></>..\n####";
    $source = MapGridSource::buildSource($text, 'NESTED');
    $grid = new EditableGrid($text, $source);
    $grid->cells[1][0] = EditableGrid::createCell('..', ['prefix' => '', 'suffix' => '']);
    expect($grid->cells[0][0]['styles'])->toBe([
        ['prefix' => '<fg=red>', 'suffix' => '</>'],
        ['prefix' => '<fg=red><options=bold>', 'suffix' => '</></>'],
    ])->and($grid->getSource())->toBe(str_replace("\n####", "\n..##", $source));
});

it('restores a differently styled pair from its style and colours it by its first visible character', function () {
    $grid = new EditableGrid("<fg=red>[</><fg=blue>]</>\n <fg=green>x</>");
    $pair = $grid->cells[0][0];
    $style = EditableGrid::getCellStyle($pair);
    expect($style)->toBe(['prefix' => '<fg=red>', 'suffix' => '</>', 'styles' => [
        ['prefix' => '<fg=red>', 'suffix' => '</>'], ['prefix' => '<fg=blue>', 'suffix' => '</>'],
    ]])
        ->and(EditableGrid::createCell('[]', $style))->toBe($pair)
        ->and(EditableGrid::createCell('##', $style))->toBe(['symbol' => '##', ...$style])
        ->and(EditableGrid::createCell('🌲', $style))->toBe(['symbol' => '🌲', 'prefix' => '<fg=red>', 'suffix' => '</>'])
        ->and($grid->cells[1][0]['prefix'])->toBe('<fg=green>');
});

it('refuses misaligned rows with their row and column, as the Engine does', function (string $text, string $message) {
    expect(fn() => new EditableGrid($text, context: 'test/grid.map.php'))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'glyph after a lone character' => ["####\n#🌲", 'test/grid.map.php row 1, column 1: a two-column glyph must begin a cell'],
    'lone trailing character' => ["###", 'test/grid.map.php row 0 ends halfway through a cell'],
]);

it('keeps legacy tags as styles and writes them back around the cells they cover', function () {
    $text = "##<blue>~~~~</blue>##";
    $grid = new EditableGrid($text, legacyTags: true);
    expect($grid->getSymbols())->toBe([['##', '~~', '~~', '##']])
        ->and($grid->cells[0][1]['prefix'])->toBe('<blue>');
    $grid->cells[0][0] = EditableGrid::createCell('@@', ['prefix' => '', 'suffix' => '']);
    expect($grid->getLines())->toBe(['@@<blue>~~~~</>##']);
});

it('resizes to whole blank cells', function () {
    $grid = new EditableGrid("##..\n##..");
    $grid->resize(3, 3);
    expect($grid->getSymbols())->toBe([['##', '..', '  '], ['##', '..', '  '], ['  ', '  ', '  ']]);
});

it('holds a multi-map workload of repeated two-column cells within the unchanged 128 MiB CLI budget', function () {
    $probe = <<<'PHP'
    require $argv[1];
    $lines = [];
    for ($row = 0; $row < 96; $row++) {
        $lines[] = '<fg=green>' . str_repeat('.', 80) . '</>' . str_repeat(' ', 80 - ($row % 2) * 2);
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
    $grids[0]->cells[0][0]['symbol'] = '##';
    echo json_encode([
        'grids' => count($grids),
        'cells' => $cellCount,
        'independent' => $grids[1]->cells[0][0]['symbol'] === '..' && $grids[0]->cells[0][1]['symbol'] === '..',
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
        ->and($result['cells'])->toBe(228960)
        ->and($result['independent'])->toBeTrue()
        ->and($result['limit'])->toBe('128M')
        ->and($result['peak'])->toBeLessThan(128 * 1024 * 1024);
});
