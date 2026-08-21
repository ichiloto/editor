<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutscenePairShape;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\UI\CutscenesScreen;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * The editor holds a pair as one record. It may offer that record for
 * editing only while merging and splitting it returns the two files exactly
 * as they were read: a shape it would normalise is shown, named, and left
 * alone. Validation may call a source malformed; that is not permission to
 * rewrite it.
 */

/**
 * Writes a cinematic pair with the given sources.
 */
function pairProjectWith(string $data, string $script, string $id = 'odd-one'): string
{
    $root = cutsceneProject();
    $folder = $root . '/assets/Cutscenes/Cinematics/' . $id;
    mkdir($folder, 0o777, true);
    file_put_contents($folder . '/' . $id . '.data.php', $data);
    file_put_contents($folder . '/' . $id . '.script.php', $script);
    touch($folder . '/' . $id . '.data.php', time() - 3600);
    touch($folder . '/' . $id . '.script.php', time() - 3600);

    return $root;
}

/**
 * The bytes and modification time of every file in a folder.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function pairState(string $folder): array
{
    $state = [];

    foreach (array_diff(scandir($folder) ?: [], ['.', '..']) as $entry) {
        $path = $folder . '/' . $entry;

        if (is_file($path)) {
            $state[$entry] = [(string) file_get_contents($path), (int) filemtime($path)];
        }
    }

    ksort($state);

    return $state;
}

it('proves a pair reverses exactly, and names precisely what a shape would cost', function (string $data, array $partner, ?string $expected) {
    $shape = CutscenePairShape::of(CutsceneType::CINEMATIC, $decoded = require_pair_data($data), $partner);
    $refusal = $shape->refusalFor($decoded, $partner);

    if ($expected === null) {
        expect($refusal)->toBeNull()
            ->and($shape->split($shape->merge($decoded, $partner)))->toBe([$decoded, $partner]);

        return;
    }

    expect($refusal)->toContain($expected);
})->with([
    'a bare command list' => [
        "['id' => 'a', 'name' => 'A']",
        [['type' => 'wait', 'seconds' => 1]],
        null,
    ],
    'a script map with commands and an unknown key' => [
        "['id' => 'a', 'name' => 'A']",
        ['commands' => [['type' => 'wait']], 'futureSetting' => ['depth' => 2]],
        null,
    ],
    'a script map that authored its unknown key first' => [
        "['id' => 'a', 'name' => 'A']",
        ['futureSetting' => 1, 'commands' => [['type' => 'wait']]],
        null,
    ],
    'an empty script' => ["['id' => 'a', 'name' => 'A']", [], null],
    'commands keyed by name' => [
        "['id' => 'a', 'name' => 'A']",
        ['commands' => ['opening' => ['type' => 'wait'], 'closing' => ['type' => 'wait']]],
        'is keyed ("opening", "closing"), and the command tree would renumber it',
    ],
    'commands that are not an array at all' => [
        "['id' => 'a', 'name' => 'A']",
        ['commands' => 'see the other file'],
        'is string, not a command list',
    ],
    'a bare map of settings' => [
        "['id' => 'a', 'name' => 'A']",
        ['tempo' => 4, 'mood' => 'dusk'],
        'no "commands" list, so its keys would be read as data-file settings',
    ],
    'a key authored in both files' => [
        "['id' => 'a', 'name' => 'A', 'version' => 1]",
        ['commands' => [], 'version' => 2],
        'both files declare the top-level key "version"',
    ],
    'a data file that declares commands too' => [
        "['id' => 'a', 'name' => 'A', 'commands' => ['stale']]",
        [['type' => 'wait']],
        'the data file also declares "commands"',
    ],
]);

it('keeps a summon timeline reversible, and refuses one whose keys collide with the data file', function () {
    $data = ['id' => 's', 'name' => 'S', 'linkedActionId' => 'Fireball'];
    $timeline = ['formatVersion' => 1, 'fps' => 12, 'lengthFrames' => 24, 'tracks' => [], 'cues' => [], 'futureKey' => ['x' => 1]];
    $shape = CutscenePairShape::of(CutsceneType::SUMMON, $data, $timeline);

    expect($shape->refusalFor($data, $timeline))->toBeNull()
        ->and($shape->split($shape->merge($data, $timeline)))->toBe([$data, $timeline]);

    $collidingData = [...$data, 'fps' => 30];
    $colliding = CutscenePairShape::of(CutsceneType::SUMMON, $collidingData, $timeline);
    expect($colliding->refusalFor($collidingData, $timeline))->toContain('both files declare the top-level key "fps"');
});

it('opens a pair it cannot reverse read-only, and an editing session never rewrites it', function () {
    // The defect: a script whose commands are keyed. Editing only the
    // display name used to renumber them into a list on the next save.
    $root = pairProjectWith(
        "<?php\n\n// A future script the editor does not model.\nreturn [\n  'id' => 'odd-one',\n  'name' => 'Odd One',\n];\n",
        "<?php\n\nreturn [\n  'commands' => [\n    'opening' => ['type' => 'wait', 'seconds' => 1],\n  ],\n];\n",
    );
    $folder = $root . '/assets/Cutscenes/Cinematics/odd-one';
    $before = pairState($folder);
    $library = CutsceneLibrary::fromProject($root);
    $asset = $library->find(CutsceneType::CINEMATIC, 'odd-one');

    expect($asset)->not->toBeNull()
        ->and($asset->isEditable())->toBeFalse()
        ->and($asset->readOnlyReason())->toContain('the command tree would renumber it');

    // Applying the record anyway -- as the record write-back does for every
    // asset whenever any one of them is saved -- changes nothing.
    $payload = $asset->payload();
    $payload['name'] = 'Renamed By Mistake';
    $asset->apply($payload);

    expect($asset->isDirty())->toBeFalse()
        ->and($asset->data()['name'])->toBe('Odd One');

    // Nothing became dirty, so a save writes nothing at all -- and a pair
    // that did somehow reach save while read-only is refused by name.
    expect($asset->save())->toBeFalse()
        ->and(pairState($folder))->toBe($before)
        ->and($library->saveAll())->toBe(['saved' => [], 'failed' => []])
        ->and(pairState($folder))->toBe($before);
});

it('does not reshape one cutscene because a different one was edited and saved', function () {
    $root = pairProjectWith(
        "<?php\n\nreturn ['id' => 'odd-one', 'name' => 'Odd One'];\n",
        "<?php\n\nreturn ['commands' => ['opening' => ['type' => 'wait']]];\n",
    );
    $folder = $root . '/assets/Cutscenes/Cinematics/odd-one';
    $before = pairState($folder);
    $editor = cutscenesEditor($root, 160, 50);

    // Edit and save the healthy cinematic through the real record path.
    callEditorMethod($editor, 'selectCutsceneById', 'harbour-lanterns');
    setCutsceneField($editor, 'name', 'Harbour Lanterns At Dusk');
    pressKeys($editor, "\x01");

    expect(file_get_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php'))
        ->toContain('Harbour Lanterns At Dusk')
        ->and(pairState($folder))->toBe($before, 'the pair the editor cannot reverse was not touched');
});

it('leaves an unknown but representable partner alone on a data-only edit, and keeps it on a partner edit', function () {
    $root = pairProjectWith(
        "<?php\n\nreturn ['id' => 'future', 'name' => 'Future'];\n",
        "<?php\n\n\$opening = <<<'ART'\n  /\\\n ART;\n\nreturn [\n  // A key a later engine will read.\n  'futureSetting' => ['depth' => 2],\n  'commands' => [\n    ['type' => 'narration', 'text' => \$opening, 'seconds' => 1],\n  ],\n];\n",
        'future',
    );
    $folder = $root . '/assets/Cutscenes/Cinematics/future';
    $library = CutsceneLibrary::fromProject($root);
    $asset = $library->find(CutsceneType::CINEMATIC, 'future');

    expect($asset->isEditable())->toBeTrue('an unknown key it can hold exactly is not a reason to refuse');

    $scriptBefore = pairState($folder)['future.script.php'];
    $payload = $asset->payload();
    $payload['name'] = 'Future Renamed';
    $asset->apply($payload);
    $asset->save();

    expect(pairState($folder)['future.script.php'])->toBe($scriptBefore, 'a data-only edit leaves the script byte- and mtime-identical');

    $dataBefore = pairState($folder)['future.data.php'];
    $payload = $asset->payload();
    $payload['commands'][] = ['type' => 'wait', 'seconds' => 2];
    $asset->apply($payload);
    $asset->save();
    $script = (string) file_get_contents($folder . '/future.script.php');

    expect(pairState($folder)['future.data.php'])->toBe($dataBefore, 'a partner edit leaves the data file byte- and mtime-identical')
        ->and($script)->toContain("'futureSetting' => ['depth' => 2]")
        ->and($script)->toContain("\$opening = <<<'ART'")
        ->and($script)->toContain("'type' => 'wait'");
});

it('writes nothing while a pair it cannot reverse is opened, filtered, previewed and validated', function () {
    $root = pairProjectWith(
        "<?php\n\nreturn ['id' => 'odd-one', 'name' => 'Odd One', 'startMap' => 'harbour'];\n",
        "<?php\n\nreturn ['commands' => 'elsewhere'];\n",
    );
    $before = authoredHashTree($root);
    $editor = cutscenesEditor($root, 160, 50);
    callEditorMethod($editor, 'selectCutsceneById', 'odd-one');
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);
    pressKeys($editor, ' ');
    pressKeys($editor, '/');
    typeText($editor, 'odd');
    pressKeys($editor, "\033");
    renderEditorPlainFrame($editor, 160, 50);
    new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));

    expect(authoredHashTree($root))->toBe($before);
});

it('reports the shape in validation so the reason is visible outside the editor', function () {
    $root = pairProjectWith(
        "<?php\n\nreturn ['id' => 'odd-one', 'name' => 'Odd One'];\n",
        "<?php\n\nreturn ['tempo' => 4];\n",
    );
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $messages = implode("\n", array_map(
        static fn(\Ichiloto\Editor\Validation\Issue $issue): string => $issue->where . ': ' . $issue->message,
        $issues,
    ));

    expect($messages)->toContain('cinematic odd-one')
        ->and($messages)->toContain('no "commands" list');
});
