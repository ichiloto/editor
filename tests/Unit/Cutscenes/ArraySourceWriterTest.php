<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Cutscenes\Source\SourceVariable;
use Ichiloto\Editor\Tests\Fixtures\SourceWriterBacked;
use Ichiloto\Editor\Tests\Fixtures\SourceWriterUnit;

require_once fixturePath('SourceWriterEnums.php');

/**
 * Editing an authored PHP array file in place.
 *
 * A cutscene file is the author's PHP: comments before and inside the data,
 * ASCII blocks as nowdocs and heredocs, entries that name them, trailing
 * spaces and backslashes that mean something. Every change here rewrites
 * only the bytes of what changed, and every result is proved by evaluating
 * it: the file must return exactly the intended array, or nothing is
 * written.
 */

/**
 * A production-shaped timeline: header comment, nowdoc art with trailing
 * spaces, backslashes and Unicode, a heredoc, a shared block, a plain string
 * variable, comments inside the returned data, and an unknown key.
 */
function authoredTimelineSource(): string
{
    return <<<'PHP_SOURCE'
<?php

/*
 * An original test timeline.
 * Positions are relative to the inner canvas.
 */

$banner = <<<'ART'
 _____ 
|  ★  |  
|_/\_|\
ART;

$spark = <<<'ART'
 . 
.*.
 . 
ART;

$sharedGlow = <<<'ART'
~ ~ ~
ART;

$note = <<<TXT
plain heredoc
TXT;

$plain = 'a plain string';

return [
  'formatVersion' => 1,
  'fps' => 12,
  'lengthFrames' => 40,
  'tracks' => [
    // ── The banner ─────────────
    [
      'type' => 'text',
      'id' => 'banner',
      'keyframes' => [
        ['frame' => 2, 'duration' => 20, 'content' => $banner, 'position' => [10, 1], 'color' => 'yellow', 'zIndex' => 5],
      ],
    ],
    // ── Sparks, sharing one glow ──
    [
      'type' => 'glyph',
      'id' => 'sparks',
      'keyframes' => [
        ['frame' => 0, 'duration' => 3, 'content' => $spark, 'position' => [4, 4], 'color' => 'red', 'zIndex' => 1], // first
        ['frame' => 3, 'duration' => 3, 'content' => $sharedGlow, 'position' => [6, 4], 'color' => 'red', 'zIndex' => 1],
        ['frame' => 6, 'duration' => 3, 'content' => $sharedGlow, 'position' => [8, 4], 'color' => 'yellow', 'zIndex' => 1],
      ],
    ],
    [
      'type' => 'text',
      'id' => 'notes',
      'keyframes' => [
        ['frame' => 10, 'duration' => 2, 'content' => $note, 'position' => [0, 0]],
        ['frame' => 12, 'duration' => 2, 'content' => $plain, 'position' => [0, 1]],
      ],
    ],
  ],
  'cues' => [
    ['id' => 'apply_damage', 'frame' => 21, 'type' => 'applyEffect'],
  ],
  'futureKey' => ['kept' => true],
];
PHP_SOURCE;
}

/**
 * Evaluates source in a throwaway file, the way the game reads it.
 */
function evaluateSource(string $source): mixed
{
    $root = rememberTemporaryProject(sys_get_temp_dir() . '/' . uniqid('ichiloto-source-', true));
    mkdir($root, 0o777, true);
    $path = $root . '/evaluated.php';
    file_put_contents($path, $source);

    return (static fn(): mixed => require $path)();
}

/**
 * Rewrites the authored timeline so it evaluates to a changed array, and
 * proves it evaluates to exactly that.
 *
 * @return string The rewritten source.
 */
function rewriteAuthored(callable $change): string
{
    $source = authoredTimelineSource();
    $document = PhpArraySourceDocument::parse($source);
    $old = evaluateSource($source);
    $new = $old;
    $change($new);
    $rewritten = ArraySourceWriter::rewrite($document, $old, $new);

    expect(evaluateSource($rewritten->source))->toBe($new);

    return $rewritten->source;
}

it('reads header, variables, comments and the returned array with exact spans', function () {
    $source = authoredTimelineSource();
    $document = PhpArraySourceDocument::parse($source);
    $root = $document->root();

    expect($root->kind)->toBe(SourceNode::ARRAY)
        ->and($root->isList)->toBeFalse()
        ->and(array_map(static fn($entry) => $entry->key, $root->entries))->toBe(['formatVersion', 'fps', 'lengthFrames', 'tracks', 'cues', 'futureKey'])
        ->and($document->nodeAt(['tracks'])?->isList)->toBeTrue()
        ->and(count($document->nodeAt(['tracks'])?->entries ?? []))->toBe(3)
        ->and($document->nodeAt(['tracks', 0, 'keyframes', 0, 'content'])?->kind)->toBe(SourceNode::VARIABLE)
        ->and($document->nodeAt(['tracks', 0, 'keyframes', 0, 'content'])?->variable)->toBe('banner')
        ->and($document->nodeAt(['fps'])?->kind)->toBe(SourceNode::SCALAR)
        ->and(substr($source, $document->nodeAt(['fps'])->start, $document->nodeAt(['fps'])->end - $document->nodeAt(['fps'])->start))->toBe('12');

    // Every variable, by kind, and its content exactly as PHP reads it.
    $variables = $document->variables();
    $evaluated = evaluateSource($source);

    expect(array_keys($variables))->toBe(['banner', 'spark', 'sharedGlow', 'note', 'plain'])
        ->and($variables['banner']->kind)->toBe(SourceVariable::NOWDOC)
        ->and($variables['note']->kind)->toBe(SourceVariable::HEREDOC)
        ->and($variables['plain']->kind)->toBe(SourceVariable::STRING)
        ->and($document->variableContent('banner'))->toBe($evaluated['tracks'][0]['keyframes'][0]['content'])
        ->and($document->variableContent('banner'))->toBe(" _____ \n|  ★  |  \n|_/\\_|\\")
        ->and($document->variableContent('note'))->toBe("plain heredoc")
        ->and($document->variableContent('plain'))->toBe('a plain string')
        ->and($document->referenceCount('sharedGlow'))->toBe(2)
        ->and($document->referenceCount('banner'))->toBe(1);
});

it('refuses a file that is not one returned array literal', function (string $source, string $reason) {
    expect(static fn() => PhpArraySourceDocument::parse($source))->toThrow(SourceUnreadable::class, $reason);
})->with([
    ["<?php\n\nfunction x() { return 1; }\n", 'no top-level return'],
    ["<?php\n\nreturn 5;\n", 'not return an array literal'],
    ["<?php\n\nreturn [1, 2];\necho 'more';\n", 'continues after'],
    ["<?php\n\nreturn build();\n", 'not return an array literal'],
]);

it('writes nothing for a no-op', function () {
    $source = authoredTimelineSource();
    $document = PhpArraySourceDocument::parse($source);
    $evaluated = evaluateSource($source);

    expect(ArraySourceWriter::rewrite($document, $evaluated, $evaluated))->toBe($document)
        ->and(ArraySourceWriter::rewrite($document, $evaluated, $evaluated)->source)->toBe($source);
});

it('patches one scalar and leaves every other byte alone', function () {
    $source = authoredTimelineSource();
    $rewritten = rewriteAuthored(static function (array &$new): void {
        $new['fps'] = 24;
    });

    expect($rewritten)->toBe(str_replace("'fps' => 12,", "'fps' => 24,", $source));
});

it('edits a nowdoc named once between its markers, keeping trailing spaces and backslashes', function () {
    $rewritten = rewriteAuthored(static function (array &$new): void {
        $new['tracks'][0]['keyframes'][0]['content'] = " ___ \n| ☆ |  \n|\\_/|\\ ";
    });

    expect($rewritten)->toContain("\$banner = <<<'ART'\n ___ \n| ☆ |  \n|\\_/|\\ \nART;")
        // The other art, the comments and the keyframe line are as they were.
        ->and($rewritten)->toContain("\$spark = <<<'ART'\n . \n.*.\n . \nART;")
        ->and($rewritten)->toContain("// ── The banner ─────────────")
        ->and($rewritten)->toContain("['frame' => 2, 'duration' => 20, 'content' => \$banner, 'position' => [10, 1], 'color' => 'yellow', 'zIndex' => 5],");
});

it('gives an entry its own variable when it edits text several entries share', function () {
    $rewritten = rewriteAuthored(static function (array &$new): void {
        $new['tracks'][1]['keyframes'][1]['content'] = "~ * ~\n  ~  ";
    });

    // The shared block is untouched and still named by the other keyframe;
    // the edited keyframe names a new nowdoc of its own.
    expect($rewritten)->toContain("\$sharedGlow = <<<'ART'\n~ ~ ~\nART;")
        ->and($rewritten)->toContain("\$sharedGlow_2 = <<<'ART'\n~ * ~\n  ~  \nART;")
        ->and($rewritten)->toContain("'content' => \$sharedGlow_2, 'position' => [6, 4]")
        ->and($rewritten)->toContain("'content' => \$sharedGlow, 'position' => [8, 4]");
});

it('retargets a heredoc and a plain string variable rather than rewriting them', function () {
    $rewritten = rewriteAuthored(static function (array &$new): void {
        $new['tracks'][2]['keyframes'][0]['content'] = "line one\nline \$two";
        $new['tracks'][2]['keyframes'][1]['content'] = 'another plain';
    });

    expect($rewritten)->toContain("\$note = <<<TXT\nplain heredoc\nTXT;")
        ->and($rewritten)->toContain("\$plain = 'a plain string';")
        ->and($rewritten)->toContain("\$note_2 = <<<'ART'\nline one\nline \$two\nART;")
        ->and($rewritten)->toContain("\$plain_2 = <<<'ART'\nanother plain\nART;")
        ->and($rewritten)->toContain("'content' => \$note_2,")
        ->and($rewritten)->toContain("'content' => \$plain_2,");
});

it('inserts a keyframe on one line like its neighbours, with new art as a nowdoc', function () {
    $rewritten = rewriteAuthored(static function (array &$new): void {
        $new['tracks'][1]['keyframes'][] = ['frame' => 9, 'duration' => 2, 'content' => "/|\\\n \\|/ ", 'position' => [10, 4], 'color' => 'white', 'zIndex' => 2];
    });

    expect($rewritten)->toContain("        ['frame' => 6, 'duration' => 3, 'content' => \$sharedGlow, 'position' => [8, 4], 'color' => 'yellow', 'zIndex' => 1],\n        ['frame' => 9, 'duration' => 2, 'content' => \$keyframes_3_content, 'position' => [10, 4], 'color' => 'white', 'zIndex' => 2],\n      ],")
        ->and($rewritten)->toContain("\$keyframes_3_content = <<<'ART'\n/|\\\n \\|/ \nART;");
});

it('removes an entry with its own line and comment, keeping the rest', function () {
    $rewritten = rewriteAuthored(static function (array &$new): void {
        array_splice($new['tracks'][1]['keyframes'], 0, 1);
    });

    expect($rewritten)->not->toContain('// first')
        ->and($rewritten)->toContain("      'keyframes' => [\n        ['frame' => 3, 'duration' => 3, 'content' => \$sharedGlow, 'position' => [6, 4]");
});

it('moves a track with its heading and its bytes when the list is reordered', function () {
    $rewritten = rewriteAuthored(static function (array &$new): void {
        $tracks = $new['tracks'];
        $first = array_shift($tracks);
        $tracks[] = $first;
        $new['tracks'] = $tracks;
    });

    // The banner track, heading first, now sits after the notes track, and
    // the sparks track's heading leads the list.
    expect($rewritten)->toContain("  'tracks' => [\n    // ── Sparks, sharing one glow ──\n    [")
        ->and($rewritten)->toContain("      'id' => 'notes',\n      'keyframes' => [\n        ['frame' => 10, 'duration' => 2, 'content' => \$note, 'position' => [0, 0]],\n        ['frame' => 12, 'duration' => 2, 'content' => \$plain, 'position' => [0, 1]],\n      ],\n    ],\n    // ── The banner ─────────────\n    [\n      'type' => 'text',\n      'id' => 'banner',");
});

it('moves and edits an entry in one save, patches included', function () {
    $rewritten = rewriteAuthored(static function (array &$new): void {
        $tracks = $new['tracks'];
        $first = array_shift($tracks);
        $first['keyframes'][0]['color'] = 'white';
        $tracks[] = $first;
        $new['tracks'] = $tracks;
    });

    expect($rewritten)->toContain("      'id' => 'banner',\n      'keyframes' => [\n        ['frame' => 2, 'duration' => 20, 'content' => \$banner, 'position' => [10, 1], 'color' => 'white', 'zIndex' => 5],");
});

it('adds and removes keyed entries and keeps unknown keys', function () {
    $rewritten = rewriteAuthored(static function (array &$new): void {
        $new['editor'] = ['zoom' => 2, 'lane' => 'sparks'];
        unset($new['cues']);
    });

    expect($rewritten)->toContain("  'futureKey' => ['kept' => true],\n  'editor' => [\n    'zoom' => 2,\n    'lane' => 'sparks',\n  ],\n];")
        ->and($rewritten)->not->toContain('apply_damage');
});

it('refuses to rewrite what it cannot express, and names the place', function () {
    $source = "<?php\n\n\$width = 10;\n\nreturn [\n  'size' => \$width * 2,\n  'name' => strtoupper('x'),\n  'plain' => 1,\n];\n";
    $document = PhpArraySourceDocument::parse($source);
    $old = evaluateSource($source);

    expect($document->nodeAt(['size'])?->kind)->toBe(SourceNode::EXPRESSION);

    $new = $old;
    $new['size'] = 21;
    expect(static fn() => ArraySourceWriter::rewrite($document, $old, $new))
        ->toThrow(SourcePreservationRefusal::class, 'size');

    // Leaving the expression alone and changing plain data is fine.
    $new = $old;
    $new['plain'] = 2;
    expect(ArraySourceWriter::rewrite($document, $old, $new)->source)->toBe(str_replace("'plain' => 1", "'plain' => 2", $source));
});

it('retains existing non-tile list identity and comment rules without treating arbitrary coordinates as ids', function () {
    $source = <<<'PHP'
<?php
return ['npcs' => [
    // The named NPC moves with its source.
    ['name' => 'Mira', 'column' => 0, 'row' => 0, 'text' => 'first'],
    // The other NPC.
    ['name' => 'Tari', 'column' => 1, 'row' => 0, 'text' => 'second'],
], 'keyframes' => [
    // This is an edited position, not a new tile identity.
    ['column' => 0, 'row' => 0, 'duration' => 10],
    ['column' => 1, 'row' => 0, 'duration' => 20],
]];
PHP;
    $old = evaluateSource($source);
    $new = $old;
    $new['npcs'] = [$old['npcs'][1], $old['npcs'][0]];
    $new['npcs'][1]['text'] = 'edited';
    $new['npcs'][1]['column'] = 8;
    $new['keyframes'][0]['column'] = 9;
    $rewritten = ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $old, $new)->source;
    expect(evaluateSource($rewritten))->toBe($new)
        ->and($rewritten)->toContain("// The named NPC moves with its source.\n    ['name' => 'Mira', 'column' => 8, 'row' => 0, 'text' => 'edited']")
        ->and($rewritten)->toContain("// This is an edited position, not a new tile identity.\n    ['column' => 9");
});

it('removes only an indented inline entry and its heading in other existing list consumers', function (string $key) {
    $source = "<?php\nreturn ['$key' => [\n    // Removed entry owns this heading.\n    ['id'=>'first'], ['id'=>'second'], ['id'=>'third']]];\n";
    $old = evaluateSource($source);
    $new = $old;
    array_shift($new[$key]);
    $rewritten = ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $old, $new)->source;
    expect(evaluateSource($rewritten))->toBe($new)
        ->and($rewritten)->toBe(str_replace("    // Removed entry owns this heading.\n    ['id'=>'first'],", '', $source));
})->with(['npcs', 'tracks', 'cues']);

it('rewrites the real Last Legend summon timelines to themselves and back from any change', function () {
    $game = gameSourceRoot();

    if ($game === null || ! is_dir($game . '/assets/Cutscenes/Summons')) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    $seen = 0;

    foreach (glob($game . '/assets/Cutscenes/Summons/*/*.timeline.php') ?: [] as $path) {
        $source = (string) file_get_contents($path);
        $document = PhpArraySourceDocument::parse($source);
        $evaluated = evaluateSource($source);

        // Every keyframe content the file names through a variable is read
        // back exactly as PHP does.
        foreach ($document->variables() as $name => $variable) {
            if ($variable->kind === SourceVariable::NOWDOC) {
                expect($document->variableContent($name))->toBeString();
            }
        }

        // A no-op is a no-op, and a change to every keyframe's frame is
        // patched in place and evaluates exactly.
        expect(ArraySourceWriter::rewrite($document, $evaluated, $evaluated)->source)->toBe($source);

        // Every sequence the file authors: its own tracks, or each
        // presentation's when it pairs terminal and graphical ones.
        $new = $evaluated;
        $shift = static function (array $sequence): array {
            foreach ($sequence['tracks'] ?? [] as $track => $authored) {
                foreach ($authored['keyframes'] ?? [] as $keyframe => $key) {
                    $sequence['tracks'][$track]['keyframes'][$keyframe]['frame'] = $key['frame'] + 1;
                }
            }

            return $sequence;
        };
        $new = $shift($new);
        foreach (array_keys($new['presentations'] ?? []) as $presentation) {
            $new['presentations'][$presentation] = $shift($new['presentations'][$presentation]);
        }

        expect($new)->not->toBe($evaluated);
        $rewritten = ArraySourceWriter::rewrite($document, $evaluated, $new);

        expect(evaluateSource($rewritten->source))->toBe($new)
            ->and(count($rewritten->variables()))->toBe(count($document->variables()));
        $seen++;
    }

    expect($seen)->toBeGreaterThan(0);
})->group('engine');

it('puts a keyed entry back where the new value orders it, so a removal and its undo leave the bytes', function () {
    $source = "<?php\n\nreturn [\n  'kind' => 'armor',\n  // The armor's type.\n  'equipmentType' => 'Shield',\n  'price' => 10,\n  'inline' => ['type' => 'text', 'name' => '', 'text' => 'A note.'],\n];\n";
    $full = evaluateSource($source);
    $without = $full;
    unset($without['equipmentType'], $without['inline']['name']);

    $removed = ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $full, $without);
    $restored = ArraySourceWriter::rewrite($removed, $without, $full);

    expect($removed->source)->toBe("<?php\n\nreturn [\n  'kind' => 'armor',\n  'price' => 10,\n  'inline' => ['type' => 'text', 'text' => 'A note.'],\n];\n")
        ->and(evaluateSource($restored->source))->toBe($full)
        // The comment went with its entry, so only that is not back.
        ->and($restored->source)->toBe(str_replace("  // The armor's type.\n", '', $source));
});

it('cuts the entries of an array written on one line with the separators that joined them', function (array $remove, string $expected, bool $restoresBytes) {
    $source = "<?php\n\nreturn [['type' => 'text', 'name' => '', 'text' => 'A note.']];\n";
    $old = evaluateSource($source);
    $new = $old;

    foreach ($remove as $key) {
        unset($new[0][$key]);
    }

    $rewritten = ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $old, $new);

    expect($rewritten->source)->toBe("<?php\n\nreturn [{$expected}];\n")
        ->and(evaluateSource($rewritten->source))->toBe($new);

    // Put back, the line reads as it did; an array left empty has no line
    // to follow, so it opens onto lines of its own.
    $restored = ArraySourceWriter::rewrite($rewritten, $new, $old)->source;

    expect(evaluateSource($restored))->toBe($old)
        ->and($restored === $source)->toBe($restoresBytes);
})->with([
    'the first' => [['type'], "['name' => '', 'text' => 'A note.']", true],
    'one between' => [['name'], "['type' => 'text', 'text' => 'A note.']", true],
    'the last two' => [['name', 'text'], "['type' => 'text']", true],
    'every one' => [['type', 'name', 'text'], '[]', false],
]);

it('rewrites backed and unit enum literals without changing class spelling comments or key order', function (
    string $import, string $expression, string $replacement, UnitEnum $first, UnitEnum $second,
) {
    $source = "<?php {$import}\nreturn array(\n"
        . "  'before' => 'kept',\n  // Authored case heading.\n"
        . "  'case' => {$expression}, // Authored tail.\n"
        . "  'after' => strtoupper('kept'),\n);\n";
    $document = PhpArraySourceDocument::parse($source);
    $old = evaluateSource($source);
    $new = $old;
    $new['case'] = $second;
    expect($old['case'])->toBe($first);
    $rewritten = ArraySourceWriter::rewrite($document, $old, $new);
    expect($rewritten->source)->toBe(str_replace($expression, $replacement, $source))
        ->and(evaluateSource($rewritten->source))->toBe($new)
        ->and(array_keys(evaluateSource($rewritten->source)))->toBe(['before', 'case', 'after'])
        ->and($document->source)->toBe($source)
        ->and(ArraySourceWriter::rewrite($rewritten, $new, $old)->source)->toBe($source);
})->with([
    'backed import' => ['use Ichiloto\Editor\Tests\Fixtures\SourceWriterBacked;',
        'SourceWriterBacked::FIRST', 'SourceWriterBacked::SECOND', SourceWriterBacked::FIRST, SourceWriterBacked::SECOND],
    'unit import' => ['use Ichiloto\Editor\Tests\Fixtures\SourceWriterUnit;',
        'SourceWriterUnit::FIRST', 'SourceWriterUnit::SECOND', SourceWriterUnit::FIRST, SourceWriterUnit::SECOND],
    'backed alias' => ['use Ichiloto\Editor\Tests\Fixtures\SourceWriterBacked as Choice;',
        'Choice::FIRST', 'Choice::SECOND', SourceWriterBacked::FIRST, SourceWriterBacked::SECOND],
    'case-insensitive alias' => ['use Ichiloto\Editor\Tests\Fixtures\SourceWriterBacked as Choice;',
        'choice::FIRST', 'choice::SECOND', SourceWriterBacked::FIRST, SourceWriterBacked::SECOND],
    'inline import' => ['use Ichiloto\Editor\Tests\Fixtures\SourceWriterBacked as Choice; /* kept */',
        'Choice::FIRST', 'Choice::SECOND', SourceWriterBacked::FIRST, SourceWriterBacked::SECOND],
    'commented import' => ['use /* class */ Ichiloto\Editor\Tests\Fixtures\SourceWriterUnit /* alias */ as Choice;',
        'Choice::FIRST', 'Choice::SECOND', SourceWriterUnit::FIRST, SourceWriterUnit::SECOND],
    'unit alias' => ['use Ichiloto\Editor\Tests\Fixtures\SourceWriterUnit as Choice;',
        'Choice::FIRST', 'Choice::SECOND', SourceWriterUnit::FIRST, SourceWriterUnit::SECOND],
    'namespace alias' => ['use Ichiloto\Editor\Tests\Fixtures as Fixtures;',
        'Fixtures\SourceWriterUnit::FIRST', 'Fixtures\SourceWriterUnit::SECOND', SourceWriterUnit::FIRST, SourceWriterUnit::SECOND],
    'backed fully qualified' => ['', '\Ichiloto\Editor\Tests\Fixtures\SourceWriterBacked::FIRST',
        '\Ichiloto\Editor\Tests\Fixtures\SourceWriterBacked::SECOND', SourceWriterBacked::FIRST, SourceWriterBacked::SECOND],
    'unit fully qualified' => ['', '\Ichiloto\Editor\Tests\Fixtures\SourceWriterUnit::FIRST',
        '\Ichiloto\Editor\Tests\Fixtures\SourceWriterUnit::SECOND', SourceWriterUnit::FIRST, SourceWriterUnit::SECOND],
    'inter-token comments' => ['use Ichiloto\Editor\Tests\Fixtures\SourceWriterBacked as Choice;',
        "Choice /* class */ :: /* case */ FIRST", "Choice /* class */ :: /* case */ SECOND", SourceWriterBacked::FIRST, SourceWriterBacked::SECOND],
    'inter-token newlines' => ['use Ichiloto\Editor\Tests\Fixtures\SourceWriterUnit as Choice;',
        "Choice\n    ::\n    FIRST", "Choice\n    ::\n    SECOND", SourceWriterUnit::FIRST, SourceWriterUnit::SECOND],
]);

it('preserves byte-exact enum source on no-op and refuses replacement by a different enum type or scalar', function (mixed $replacement) {
    $source = "<?php\nuse Ichiloto\\Editor\\Tests\\Fixtures\\SourceWriterBacked as Choice;\n"
        . "return ['case' => Choice::FIRST, 'kept' => 1];\n";
    $document = PhpArraySourceDocument::parse($source);
    $old = evaluateSource($source);
    expect(ArraySourceWriter::rewrite($document, $old, $old))->toBe($document);
    $new = $old;
    $new['case'] = $replacement;
    expect(fn() => ArraySourceWriter::rewrite($document, $old, $new))->toThrow(SourcePreservationRefusal::class, 'case')
        ->and($document->source)->toBe($source);
})->with([
    'another enum' => [SourceWriterUnit::SECOND],
    'backing value' => ['second'],
    'integer' => [1],
    'null' => [null],
]);

it('refuses computed enum values and noncase constants rather than flattening their expressions', function (string $expression) {
    $source = "<?php\nuse Ichiloto\\Editor\\Tests\\Fixtures\\SourceWriterBacked as Choice;\n"
        . "use Ichiloto\\Editor\\Tests\\Fixtures\\SourceWriterConstants as Constants;\n"
        . "return ['case' => {$expression}, 'plain' => 1];\n";
    $document = PhpArraySourceDocument::parse($source);
    $old = evaluateSource($source);
    $new = $old;
    $new['case'] = SourceWriterBacked::SECOND;
    expect($old['case'])->toBe(SourceWriterBacked::FIRST)
        ->and(fn() => ArraySourceWriter::rewrite($document, $old, $new))->toThrow(SourcePreservationRefusal::class, 'case')
        ->and($document->source)->toBe($source);
    $new = $old;
    $new['plain'] = 2;
    expect(ArraySourceWriter::rewrite($document, $old, $new)->source)->toBe(str_replace("'plain' => 1", "'plain' => 2", $source));
})->with([
    "Choice::from('first')",
    "Choice::tryFrom('first')",
    'Choice::ALIAS',
    'Constants::CASE_ALIAS',
    'Constants::FIRST',
    "constant(Choice::class . '::FIRST')",
    'true ? Choice::FIRST : Choice::SECOND',
    '(Choice::FIRST)',
]);

it('refuses unresolved class names and unknown constants without evaluating them', function (string $expression) {
    $source = "<?php\nuse Ichiloto\\Editor\\Tests\\Fixtures\\SourceWriterBacked as Choice;\n"
        . "return ['case' => {$expression}];\n";
    $document = PhpArraySourceDocument::parse($source);
    expect(fn() => ArraySourceWriter::rewrite($document, ['case' => SourceWriterBacked::FIRST], ['case' => SourceWriterBacked::SECOND]))
        ->toThrow(SourcePreservationRefusal::class, 'case')
        ->and($document->source)->toBe($source);
})->with(['Choice::UNKNOWN_CASE', 'UnknownChoice::FIRST', '\UnloadedUnknownChoice::FIRST']);

it('requires the loaded enum case to match the authored literal before rewriting it', function () {
    $source = "<?php\nuse Ichiloto\\Editor\\Tests\\Fixtures\\SourceWriterBacked as Choice;\nreturn ['case' => Choice::FIRST];\n";
    $document = PhpArraySourceDocument::parse($source);
    expect(fn() => ArraySourceWriter::rewrite($document, ['case' => SourceWriterBacked::SECOND], ['case' => SourceWriterBacked::FIRST]))
        ->toThrow(SourcePreservationRefusal::class, 'case')
        ->and($document->source)->toBe($source);
});

it('does not mistake imports inside authored text or another namespace for actual enum imports', function (string $source) {
    $document = PhpArraySourceDocument::parse($source);
    $old = evaluateSource($source);
    $new = $old;
    $new['case'] = SourceWriterBacked::SECOND;
    expect(fn() => ArraySourceWriter::rewrite($document, $old, $new))->toThrow(SourcePreservationRefusal::class, 'case')
        ->and($document->source)->toBe($source);
})->with([
    'nowdoc' => <<<'PHP'
    <?php
    $documentation = <<<'TEXT'
    use Ichiloto\Editor\Tests\Fixtures\SourceWriterBacked as Choice;
    TEXT;
    use Ichiloto\Editor\Tests\Fixtures\SourceWriterConstants as Choice;
    return ['case' => Choice::FIRST, 'documentation' => $documentation];
    PHP,
    'comment' => <<<'PHP'
    <?php
    /*
    use Ichiloto\Editor\Tests\Fixtures\SourceWriterBacked as Choice;
    */
    use Ichiloto\Editor\Tests\Fixtures\SourceWriterConstants as Choice;
    return ['case' => Choice::FIRST];
    PHP,
    'previous namespace' => <<<'PHP'
    <?php
    namespace First;
    use Ichiloto\Editor\Tests\Fixtures\SourceWriterBacked as Choice;
    namespace Second;
    use Ichiloto\Editor\Tests\Fixtures\SourceWriterConstants as Choice;
    return ['case' => Choice::FIRST];
    PHP,
]);

it('keeps existing backed enum value expressions editable without switching them to enum objects', function () {
    $source = "<?php\nuse Ichiloto\\Editor\\Tests\\Fixtures\\SourceWriterBacked as Choice;\nreturn ['case' => Choice::FIRST->value];\n";
    $document = PhpArraySourceDocument::parse($source);
    $old = evaluateSource($source);
    $new = ['case' => 'second'];
    $rewritten = ArraySourceWriter::rewrite($document, $old, $new)->source;
    expect($rewritten)->toBe(str_replace('Choice::FIRST->value', 'Choice::SECOND->value', $source))
        ->and(evaluateSource($rewritten))->toBe($new)
        ->and(fn() => ArraySourceWriter::rewrite($document, $old, ['case' => SourceWriterBacked::SECOND]))
            ->toThrow(SourcePreservationRefusal::class, 'case');
});
