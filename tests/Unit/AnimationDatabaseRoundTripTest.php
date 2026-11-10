<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;

/**
 * Animations are edited through the shared record database: a record's name,
 * effects and roles. Everything else an entry holds (an older record's own
 * frames and cues, fields the Engine adds later), the file's comments and its
 * header survive a save untouched, and a new animation takes the next id.
 */
it('keeps every field it does not edit, comments and the header, and numbers a new animation', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/animations.php';
    file_put_contents($path, <<<'PHP'
    <?php

    // Battle and field animations.
    use Ichiloto\Engine\Animations\AnimationTargetPosition;

    return [
      [
        'id' => 1,
        'name' => 'Slash',
        'position' => AnimationTargetPosition::CENTER->value,
        'maxFrames' => 2,
        // Played for sword attacks.
        'roles' => ['attack'],
        'sourceEffect' => 'battle-blade-slash',
        'targetEffect' => 'battle-physical-impact',
        'futureField' => ['kept' => true],
        'frames' => [['index' => 1, 'cells' => [['symbol' => '/', 'x' => 0, 'y' => 0, 'color' => 'red']]]],
        'cues' => [],
      ],
      ['id' => 4, 'name' => 'Spark', 'targetEffect' => 'battle-physical-impact'],
    ];
    PHP);

    $database = ProjectWorkspace::fromProject($root)->getRecordDatabase('animations');
    $legacy = array_column($database->getSettingsFields(0), null, 'label');
    $current = array_column($database->getSettingsFields(1), 'label');

    // An older record shows its own frames and cues read-only; a current one has none.
    expect($legacy['Legacy Frames'])->toMatchArray(['value' => '(1)', 'editable' => false])
        ->and($legacy['Legacy Position']['value'])->toBe('center')
        ->and($current)->toBe(['Id', 'Name', 'Movement', 'Caster Effect', 'Target Effect', 'Roles']);

    $database->setField(0, 'name', 'Blade Slash');
    $database->save();
    $saved = require $path;
    $source = (string) file_get_contents($path);

    expect($saved[0]['name'])->toBe('Blade Slash')
        ->and($saved[0]['roles'])->toBe(['attack'])
        ->and($saved[0]['sourceEffect'])->toBe('battle-blade-slash')
        ->and($saved[0]['futureField'])->toBe(['kept' => true])
        ->and($saved[0]['frames'][0]['cells'][0]['symbol'])->toBe('/')
        ->and($source)->toContain('// Battle and field animations.', '// Played for sword attacks.', 'use Ichiloto\Engine\Animations\AnimationTargetPosition;');

    // A new animation takes the next number after the largest, and only what the editor writes.
    $index = $database->addRecord();
    $database->save();
    $again = require $path;

    expect($again)->toHaveCount(3)
        ->and($again[$index])->toBe(['id' => 5, 'name' => 'New Animation'])
        ->and($again[0]['targetEffect'])->toBe('battle-physical-impact');
});

it('authors flash-step timing through the animation record and preserves its source', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/animations.php';
    file_put_contents($path, "<?php\n// Preserve this animation note.\nreturn [['id' => 1, 'name' => 'Test']];\n");
    $database = ProjectWorkspace::fromProject($root)->getRecordDatabase('animations');
    $database->setField(0, 'movement', 'flash-step');
    $timing = ['fps' => 24, 'guardFrames' => 20, 'advanceFrames' => 3, 'holdFrames' => 10,
        'returnFrames' => 3, 'settleFrames' => 7, 'echoes' => 0, 'overlapEffectTail' => true, 'placeTransitOnPoseKeys' => true];
    foreach ($timing as $field => $value) {
        $database->setField(0, 'flashStep.' . $field, $value === true ? 'true' : (string) $value);
    }
    $database->save();
    $saved = (require $path)[0];
    expect($saved['movement'])->toBe('flash-step')
        ->and($saved['flashStep'])->toBe($timing)
        ->and(file_get_contents($path))->toContain('// Preserve this animation note.');
    $database->setField(0, 'movement', 'run-to-target');
    $database->save();
    expect((require $path)[0])->toMatchArray(['movement' => 'run-to-target'])->not->toHaveKey('flashStep');
});
