<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Validation\BrokenPieceCheck;
use Ichiloto\Editor\Validation\Issue;

/**
 * Part of a multi-cell tileset piece shown without the rest of it is reported,
 * from the tileset's own pieces. Synthetic tilesets and tiles only.
 */

function brokenPieceProject(array $pieces, string $tiles, array $extra = []): string
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: $pieces);
    if ($extra !== []) {
        $path = $root . '/assets/Data/Tilesets/home.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export([...require $path, ...$extra], true) . ";\n");
    }
    writeTileLayer($root . '/assets/Maps/test-map', '02.decor.tiles.php', $tiles);

    return $root;
}

/** @return list<Issue> */
function findBrokenPieces(string $root): array
{
    return BrokenPieceCheck::validate(loadLayeredMap($root));
}

$table = ['table' => ['name' => 'Table', 'layer' => 'buildings', 'glyphs' => ['||'], 'tiles' => ['decor' => ['5 6']]]];

it('reports part of a piece standing without the rest, naming the piece and its cell', function () use ($table) {
    $issues = findBrokenPieces(brokenPieceProject($table, "0 5 0 0\n0 0 0 0"));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->message)->toContain('Part of Table stands at (1, 0)')
        ->and($issues[0]->code)->toBe(BrokenPieceCheck::CODE)
        ->and($issues[0]->cells)->toBe([[1, 0]]);
});

it('reports a piece whose other half another tile has replaced', function () use ($table) {
    expect(findBrokenPieces(brokenPieceProject($table, "9 5 9 0\n0 0 0 0"))[0]->cells)->toBe([[1, 0]]);
});

it('accepts whole copies, repeated copies and copies laid on another tile layer', function () use ($table) {
    expect(findBrokenPieces(brokenPieceProject($table, "5 6 5 6\n0 0 0 0")))->toBe([]);

    $root = brokenPieceProject($table, "0 0 0 0\n0 0 0 0");
    writeTileLayer($root . '/assets/Maps/test-map', '03.props.tiles.php', "5 6 0 0\n0 0 0 0");
    expect(findBrokenPieces($root))->toBe([]);
});

it('accepts a tile a one-cell piece also draws, and any of the pieces sharing a tile standing whole', function () use ($table) {
    $stool = ['stool' => ['name' => 'Stool', 'layer' => 'buildings', 'glyphs' => ['o'], 'tiles' => ['decor' => ['5']]]];
    expect(findBrokenPieces(brokenPieceProject([...$table, ...$stool], "0 5 0 0\n0 0 0 0")))->toBe([]);

    $short = ['short' => ['name' => 'Short table', 'layer' => 'buildings', 'glyphs' => ['||'], 'tiles' => ['decor' => ['5 7']]]];
    expect(findBrokenPieces(brokenPieceProject([...$table, ...$short], "5 7 0 0\n0 0 0 0")))->toBe([]);
});

it('checks tall pieces across rows and leaves their empty cells free', function () {
    $bed = ['bed' => ['name' => 'Bed', 'layer' => 'buildings', 'glyphs' => ['  ', 'OO'], 'tiles' => ['decor' => ['5 0', '6 7']]]];
    expect(findBrokenPieces(brokenPieceProject($bed, "5 9 0 0\n6 7 0 0")))->toBe([])
        ->and(findBrokenPieces(brokenPieceProject($bed, "5 0 0 0\n0 7 0 0"))[0]->cells)->toBe([[0, 0], [1, 1]]);
});

it('does not judge connected pieces, ground sheets or a tileset\'s overlapping stacks', function () {
    $connected = ['fence' => ['name' => 'Fence', 'layer' => 'buildings', 'connects' => 'lines',
        'shapes' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'],
        'tiles' => ['decor' => ['horizontal' => '5', 'vertical' => '6', 'corner' => '7']]]];
    expect(findBrokenPieces(brokenPieceProject($connected, "5 0 0 0\n0 0 0 0")))->toBe([]);

    $rug = ['rug' => ['name' => 'Rug', 'layer' => 'buildings', 'glyphs' => ['  '], 'tiles' => ['decor' => ['1544 1545']]]];
    expect(findBrokenPieces(brokenPieceProject($rug, "1544 1545 1545 0\n1544 0 0 0")))->toBe([]);

    $peak = ['peak' => ['name' => 'Peak', 'layer' => 'buildings', 'glyphs' => ['  '], 'tiles' => ['decor' => ['5 6']]]];
    expect(findBrokenPieces(brokenPieceProject($peak, "5 0 0 0\n0 0 0 0", ['stacks' => ['relief' => ['layers' => ['decor']]]])))->toBe([]);
});

it('warns when a change set leaves part of a piece', function () use ($table) {
    $root = brokenPieceProject($table, "0 0 0 0\n0 0 0 0");

    $outcome = ChangeBatch::apply($root, [['method' => 'tiles.stamp', 'params' => ['map' => 'test-map', 'layer' => 'decor', 'cells' => [[1, 0, 5]]]]], checkOnly: true);

    $found = array_values(array_filter($outcome['issues'], static fn(array $issue): bool => $issue['code'] === BrokenPieceCheck::CODE));
    expect($found)->toHaveCount(1)
        ->and($found[0]['severity'])->toBe('warning')
        ->and($found[0]['cells'])->toBe([[1, 0]]);
});
