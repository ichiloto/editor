<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;

/**
 * New entries written into an authored array read like the entries around
 * them: an empty `[]` opens onto lines at the file's own indentation step,
 * and an array written on one line stays on one line, joined the way it
 * already joins its entries. Every byte outside the insertion is untouched.
 */

/**
 * Applies one planned insertion and returns the rewritten source.
 *
 * @param array<int, int|string> $path
 */
function insertEntry(string $source, array $path, int $position, int|string|null $key, string $literal): string
{
    $document = PhpArraySourceDocument::parse($source);

    return $document->withEdits([$document->insertEntryEdit($path, $position, $key, $literal)])->source;
}

it('opens an empty array after a key onto lines at the surrounding indentation', function (string $step) {
    $source = "<?php\n\nreturn [\n{$step}'name' => 'x',\n{$step}'upgrades' => [\n{$step}{$step}'migrations' => [],\n{$step}],\n];\n";

    expect(insertEntry($source, ['upgrades', 'migrations'], 0, null, "'v1'"))
        ->toBe("<?php\n\nreturn [\n{$step}'name' => 'x',\n{$step}'upgrades' => [\n{$step}{$step}'migrations' => [\n{$step}{$step}{$step}'v1',\n{$step}{$step}],\n{$step}],\n];\n")
        ->and(insertEntry($source, ['upgrades', 'migrations'], 0, '1.1.0', "'Upgrade'"))
        ->toContain("{$step}{$step}'migrations' => [\n{$step}{$step}{$step}'1.1.0' => 'Upgrade',\n{$step}{$step}],\n{$step}],");
})->with([
    'four spaces' => '    ',
    'two spaces' => '  ',
    'tabs' => "\t",
]);

it('opens an empty root array and a one-line parent child at the indentation step of the file', function () {
    expect(insertEntry("<?php\n\nreturn [];\n", [], 0, 'a', '1'))->toBe("<?php\n\nreturn [\n  'a' => 1,\n];\n")
        ->and(insertEntry("<?php\n\nreturn [\n    'box' => ['list' => []],\n];\n", ['box', 'list'], 0, null, '1'))
        ->toBe("<?php\n\nreturn [\n    'box' => ['list' => [\n        1,\n    ]],\n];\n");
});

it('appends through the writer into an empty array after a key at the surrounding indentation', function () {
    $source = "<?php\n\nreturn [\n    'name' => 'x',\n    'migrations' => [],\n];\n";
    $old = ['name' => 'x', 'migrations' => []];
    $new = ['name' => 'x', 'migrations' => ['v1', 'v2']];

    expect(ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $old, $new)->source)
        ->toBe("<?php\n\nreturn [\n    'name' => 'x',\n    'migrations' => [\n        'v1',\n        'v2',\n    ],\n];\n");
});

it('appends to a one-line array inline, following its trailing-comma convention', function (string $before, int|string|null $key, string $literal, string $after) {
    $source = "<?php\n\nreturn [\n    'list' => {$before}, // kept\n];\n";

    expect(insertEntry($source, ['list'], 2, $key, $literal))->toBe("<?php\n\nreturn [\n    'list' => {$after}, // kept\n];\n");
})->with([
    'list without a trailing comma' => ["['a', 'b']", null, "'c'", "['a', 'b', 'c']"],
    'list with a trailing comma' => ["['a', 'b',]", null, "'c'", "['a', 'b', 'c',]"],
    'keyed without a trailing comma' => ["['x' => 1, 'y' => 2]", 'z', '3', "['x' => 1, 'y' => 2, 'z' => 3]"],
    'keyed with a trailing comma' => ["['x' => 1, 'y' => 2, ]", 'z', '3', "['x' => 1, 'y' => 2, 'z' => 3, ]"],
]);

it('inserts ahead of a one-line neighbour without carrying indentation onto the line', function () {
    $source = "<?php\n\nreturn [\n    'list' => ['a', 'b'],\n];\n";

    expect(insertEntry($source, ['list'], 0, null, "'c'"))->toBe("<?php\n\nreturn [\n    'list' => ['c', 'a', 'b'],\n];\n")
        ->and(insertEntry($source, ['list'], 1, null, "'c'"))->toBe("<?php\n\nreturn [\n    'list' => ['a', 'c', 'b'],\n];\n");
});

it('appends through the writer to a one-line array following its trailing-comma convention', function (string $before, string $after) {
    $source = "<?php\n\nreturn [\n    'list' => {$before},\n];\n";

    expect(ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), ['list' => ['a', 'b']], ['list' => ['a', 'b', 'c', 'd']])->source)
        ->toBe("<?php\n\nreturn [\n    'list' => {$after},\n];\n");
})->with([
    'without a trailing comma' => ["['a', 'b']", "['a', 'b', 'c', 'd']"],
    'with a trailing comma' => ["['a', 'b',]", "['a', 'b', 'c', 'd',]"],
]);

it('appends after a last entry that shares its line with the closing bracket', function () {
    $source = "<?php\n\nreturn [\n    'list' => [\n        'a'],\n    'next' => 1,\n];\n";

    expect(insertEntry($source, ['list'], 1, null, "'b'"))->toBe("<?php\n\nreturn [\n    'list' => [\n        'a', 'b'],\n    'next' => 1,\n];\n");
});

it('inserts into an array that holds only a comment, after the comment', function () {
    $source = "<?php\n\nreturn [\n    'migrations' => [\n        // None yet.\n    ],\n    'inline' => [/* none */],\n];\n";

    expect(insertEntry($source, ['migrations'], 0, null, "'v1'"))
        ->toBe("<?php\n\nreturn [\n    'migrations' => [\n        // None yet.\n        'v1',\n    ],\n    'inline' => [/* none */],\n];\n")
        ->and(insertEntry($source, ['inline'], 0, null, "'v1'"))
        ->toBe("<?php\n\nreturn [\n    'migrations' => [\n        // None yet.\n    ],\n    'inline' => [/* none */ 'v1'],\n];\n");
});

it('appends after the last entry when the closing bracket shares its line, keeping the bracket and the comma style', function (string $comma) {
    $source = "<?php\nreturn [\n  'tracks' => [\n    ['type' => 'image', 'sheet' => ['columns' => 2],\n      'keyframes' => [['frame' => 0]]{$comma}],\n    ['type' => 'text'],\n  ],\n];\n";
    $old = eval('?>' . $source);
    $new = $old;
    $new['tracks'][0]['fit'] = 'contain';
    $new['tracks'][0]['depth'] = 'behind';
    $written = ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $old, $new)->source;

    expect($written)->toBe("<?php\nreturn [\n  'tracks' => [\n    ['type' => 'image', 'sheet' => ['columns' => 2],\n      'keyframes' => [['frame' => 0]],\n      'fit' => 'contain',\n      'depth' => 'behind'{$comma}],\n    ['type' => 'text'],\n  ],\n];\n")
        ->and(eval('?>' . $written))->toBe($new);
})->with(['without a trailing comma' => '', 'with a trailing comma' => ',']);
