<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Database\PhpDataFile;

/**
 * Editing authored command lists that splice in spreads and compose
 * commands with the file's own helpers.
 *
 * Entries written around a spread, and records a helper call returns, stay
 * the author's to edit: every expression and spread keeps its bytes, and
 * only what changed is written. A change that would need the spread's own
 * entries or an expression's own result rewritten is refused. Every result
 * is proved by evaluating it.
 */

/**
 * Writes authored files into a throwaway project and returns its root.
 *
 * @param array<string, string> $files Relative path => source.
 */
function spreadEditProject(array $files): string
{
    $root = makeTemporaryProject('ichiloto-spread-edits-');

    foreach ($files as $relative => $source) {
        $path = $root . '/' . $relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $source);
    }

    return $root;
}

/** Evaluates a file the way the game reads it. */
function evaluateSpreadFile(string $path): mixed
{
    return (static fn(): mixed => require $path)();
}

/**
 * Rewrites a file so it evaluates to a changed value, proves it does, and
 * returns the rewritten source.
 */
function rewriteSpreadFile(string $path, callable $change): string
{
    $source = (string) file_get_contents($path);
    $old = evaluateSpreadFile($path);
    $new = $old;
    $change($new);
    $rewritten = ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $old, $new)->source;
    file_put_contents($path, $rewritten);

    expect(evaluateSpreadFile($path))->toBe($new);

    return $rewritten;
}

function sharedCommandsSource(): string
{
    return "<?php\n\nreturn [\n  ['type' => 'text', 'name' => 'Guide', 'text' => 'Shared first.'],\n  ['type' => 'wait', 'seconds' => 1],\n];\n";
}

function splicedScriptSource(): string
{
    return <<<'PHP_SOURCE'
<?php

return [
  ['type' => 'text', 'name' => 'Ana', 'text' => 'Before the shared commands.'],
  // The shared commands play here.
  ...require __DIR__ . '/shared.php',
  ['type' => 'wait', 'seconds' => 0.5],
  ['type' => 'text', 'name' => 'Ben', 'text' => 'After the shared commands.'],
  ['type' => 'clear_presentation'],
];
PHP_SOURCE;
}

it('edits entries written before and after a spread, keeping the spread and its comment', function () {
    $root = spreadEditProject(['events/shared.php' => sharedCommandsSource(), 'events/script.php' => splicedScriptSource()]);
    $path = $root . '/events/script.php';

    $rewritten = rewriteSpreadFile($path, static function (array &$value): void {
        $value[0]['emotion'] = 'Happy';
        $value[4]['emotion'] = 'Sad';
        $value[3]['seconds'] = 0.75;
    });

    expect($rewritten)->toBe(str_replace(
        ["'Before the shared commands.']", "'seconds' => 0.5]", "'After the shared commands.']"],
        ["'Before the shared commands.', 'emotion' => 'Happy']", "'seconds' => 0.75]", "'After the shared commands.', 'emotion' => 'Sad']"],
        splicedScriptSource(),
    ));
});

it('inserts and removes entries on either side of a spread without touching what it adds', function () {
    $root = spreadEditProject(['events/shared.php' => sharedCommandsSource(), 'events/script.php' => splicedScriptSource()]);
    $path = $root . '/events/script.php';

    $rewritten = rewriteSpreadFile($path, static function (array &$value): void {
        array_splice($value, 1, 0, [['type' => 'wait', 'seconds' => 2]]);
        unset($value[6]);
        $value = array_values($value);
        $value[] = ['type' => 'record_event', 'name' => 'heard'];
    });

    expect($rewritten)->toContain("  ['type' => 'wait', 'seconds' => 2],\n  // The shared commands play here.\n  ...require __DIR__ . '/shared.php',")
        ->and($rewritten)->not->toContain('clear_presentation')
        ->and($rewritten)->toContain("['type' => 'record_event', 'name' => 'heard']")
        ->and((string) file_get_contents($root . '/events/shared.php'))->toBe(sharedCommandsSource());
});

it('refuses a change to the entries a spread adds, naming the spread', function () {
    $root = spreadEditProject(['events/shared.php' => sharedCommandsSource(), 'events/script.php' => splicedScriptSource()]);
    $path = $root . '/events/script.php';
    $old = evaluateSpreadFile($path);
    $new = $old;
    $new[1]['emotion'] = 'Happy';

    expect(static fn() => ArraySourceWriter::rewrite(PhpArraySourceDocument::parse(splicedScriptSource()), $old, $new))
        ->toThrow(SourcePreservationRefusal::class, 'The entries the spread at 1 adds would change');
});

it('refuses a list with two spreads, whose entries only evaluation can count', function () {
    $source = "<?php\n\n\$a = [];\n\$a[] = 1;\n\$b = [];\n\$b[] = 2;\n\nreturn [\n  ...\$a,\n  ['type' => 'wait'],\n  ...\$b,\n];\n";
    $old = [1, ['type' => 'wait'], 2];
    $new = [1, ['type' => 'wait', 'seconds' => 1], 2];

    expect(static fn() => ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $old, $new))
        ->toThrow(SourcePreservationRefusal::class, 'splices in 2 spreads');
});

it('edits an entry beside helper calls and a spread built by a loop inside a literal variable', function () {
    $source = <<<'PHP_SOURCE'
<?php

$step = static fn(string $direction): array => ['type' => 'move', 'direction' => $direction];
$walk = [];
for ($i = 0; $i < 3; $i++) {
  $walk[] = $step('left');
}

$scene = [
  $step('up'),
  ['type' => 'text', 'name' => 'Ana', 'text' => 'Walk with me.'],
  ...$walk,
  ['type' => 'text', 'name' => 'Ben', 'text' => 'Here we are.'],
];

return [
  ['type' => 'branch', 'then' => $scene],
];
PHP_SOURCE;
    $root = spreadEditProject(['events/scene.php' => $source]);

    $rewritten = rewriteSpreadFile($root . '/events/scene.php', static function (array &$value): void {
        $value[0]['then'][1]['emotion'] = 'Happy';
        $value[0]['then'][5]['emotion'] = 'Thinking';
    });

    expect($rewritten)->toBe(str_replace(
        ["'Walk with me.']", "'Here we are.']"],
        ["'Walk with me.', 'emotion' => 'Happy']", "'Here we are.', 'emotion' => 'Thinking']"],
        $source,
    ));
});

it('adds a field to a record a helper call returns by spreading the call, and edits it there afterwards', function () {
    $source = <<<'PHP_SOURCE'
<?php

$line = static fn(string $name, string $text): array => ['type' => 'text', 'name' => $name, 'text' => $text,
  ...($name === 'Vax' ? ['speaker' => 'vax'] : [])];

return [
  $line('Vax', 'I know you.'),
  $line('Ana', "You don't."),
];
PHP_SOURCE;
    $root = spreadEditProject(['events/talk.php' => $source]);
    $path = $root . '/events/talk.php';

    $added = rewriteSpreadFile($path, static function (array &$value): void {
        $value[1]['emotion'] = 'Angry';
        $value[0]['emotion'] = 'Thinking';
    });

    expect($added)->toBe(str_replace(
        ["\$line('Vax', 'I know you.'),", "\$line('Ana', \"You don't.\"),"],
        ["[...\$line('Vax', 'I know you.'), 'emotion' => 'Thinking'],", "[...\$line('Ana', \"You don't.\"), 'emotion' => 'Angry'],"],
        $source,
    ));

    $edited = rewriteSpreadFile($path, static function (array &$value): void {
        $value[1]['emotion'] = 'Sad';
    });

    expect($edited)->toBe(str_replace("'emotion' => 'Angry'", "'emotion' => 'Sad'", $added));
});

it('refuses changing or removing what a helper call returns, or a key a spread may replace', function (string $source, array $old, callable $change, string $reason) {
    $new = $old;
    $change($new);

    expect(static fn() => ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $old, $new))
        ->toThrow(SourcePreservationRefusal::class, $reason);
})->with([
    'the text a call returns' => [
        "<?php\n\$line = static fn(string \$text): array => ['type' => 'text', 'text' => \$text];\n\nreturn [\n  \$line('Hi.'),\n];\n",
        [['type' => 'text', 'text' => 'Hi.']],
        static function (array &$value): void { $value[0]['text'] = 'Hello.'; },
        'written as an expression the editor cannot rewrite',
    ],
    'a key a call returns' => [
        "<?php\n\$line = static fn(string \$text): array => ['type' => 'text', 'text' => \$text];\n\nreturn [\n  \$line('Hi.'),\n];\n",
        [['type' => 'text', 'text' => 'Hi.']],
        static function (array &$value): void { unset($value[0]['type']); },
        'written as an expression the editor cannot rewrite',
    ],
    'a key written before a spread' => [
        "<?php\n\$extra = ['b' => 2];\n\$extra['c'] = 3;\n\nreturn [\n  ['a' => 1, ...\$extra],\n];\n",
        [['a' => 1, 'b' => 2, 'c' => 3]],
        static function (array &$value): void { $value[0]['a'] = 5; },
        'comes from a spread, or is written before one that may replace it',
    ],
    'a key a spread adds' => [
        "<?php\n\$extra = ['b' => 2];\n\$extra['c'] = 3;\n\nreturn [\n  ['a' => 1, ...\$extra],\n];\n",
        [['a' => 1, 'b' => 2, 'c' => 3]],
        static function (array &$value): void { unset($value[0]['b']); },
        'removing or reordering keys would rewrite what the spread brings',
    ],
]);

it('saves an emotion through PhpDataFile into a file that splices in another, writing nothing else', function () {
    $root = spreadEditProject(['events/shared.php' => sharedCommandsSource(), 'events/script.php' => splicedScriptSource()]);
    $path = $root . '/events/script.php';
    $file = PhpDataFile::load($path, $root);
    $payload = $file->payload;
    $payload[4]['emotion'] = 'Sad';

    expect($file->isEditable())->toBeTrue();
    $file->save($payload);

    expect(evaluateSpreadFile($path))->toBe($payload)
        ->and((string) file_get_contents($path))->toBe(str_replace("'After the shared commands.']", "'After the shared commands.', 'emotion' => 'Sad']", splicedScriptSource()))
        ->and((string) file_get_contents($root . '/events/shared.php'))->toBe(sharedCommandsSource());
});
