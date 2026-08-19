<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Cutscenes\Source\SourceVariable;

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

        $new = $evaluated;

        foreach ($new['tracks'] as &$track) {
            foreach ($track['keyframes'] as &$keyframe) {
                $keyframe['frame'] = $keyframe['frame'] + 1;
            }
        }

        unset($track, $keyframe);
        $rewritten = ArraySourceWriter::rewrite($document, $evaluated, $new);

        expect(evaluateSource($rewritten->source))->toBe($new)
            ->and(count($rewritten->variables()))->toBe(count($document->variables()));
        $seen++;
    }

    expect($seen)->toBeGreaterThan(0);
})->group('engine');
