<?php

declare(strict_types=1);

use Ichiloto\Editor\Maps\EditableGrid;
use Ichiloto\Engine\Field\MapGridSource;

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
        [
            ['symbol' => '0', 'prefix' => "\033[31m", 'suffix' => "\033[0m"],
            ['symbol' => '0', 'prefix' => "\033[31m", 'suffix' => "\033[0m"],
            ['symbol' => '0', 'prefix' => '<fg=red>', 'suffix' => '</>'],
        ],
        [['symbol' => '0', 'prefix' => '<fg=red><options=bold>', 'suffix' => '</></>']],
    ])->and($grid->getSource())->toBe($source);
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
