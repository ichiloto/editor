<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectAnimationDatabase;

/**
 * The editor edits an animation's name, position, frames and cues. Everything
 * else an entry holds (effect bindings, roles, fields the Engine adds later)
 * and the file's header must survive a save untouched.
 */
it('keeps every field it does not edit, and the file header, when it saves an animation', function () {
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
        'roles' => ['attack'],
        'sourceEffect' => 'battle-blade-slash',
        'targetEffect' => 'battle-physical-impact',
        'futureField' => ['kept' => true],
        'frames' => [['index' => 1, 'cells' => [['symbol' => '/', 'x' => 0, 'y' => 0, 'color' => 'red']]]],
        'cues' => [],
      ],
    ];
    PHP);

    $database = ProjectAnimationDatabase::fromProject($root);
    $database->setField(0, 'name', 'Blade Slash');
    $database->save();
    $saved = require $path;
    $source = (string) file_get_contents($path);

    expect($saved[0]['name'])->toBe('Blade Slash')
        ->and($saved[0]['roles'])->toBe(['attack'])
        ->and($saved[0]['sourceEffect'])->toBe('battle-blade-slash')
        ->and($saved[0]['targetEffect'])->toBe('battle-physical-impact')
        ->and($saved[0]['futureField'])->toBe(['kept' => true])
        ->and($saved[0]['frames'][0]['cells'][0]['symbol'])->toBe('/')
        ->and($source)->toContain('// Battle and field animations.')
        ->and($source)->toContain('use Ichiloto\Engine\Animations\AnimationTargetPosition;');

    // A new animation has only what the editor writes, and saving twice changes nothing more.
    $database->addAnimation('Spark');
    $database->save();
    $again = require $path;

    expect($again)->toHaveCount(2)
        ->and($again[0]['targetEffect'])->toBe('battle-physical-impact')
        ->and($again[1]['name'])->toBe('Spark')
        ->and($again[1])->not->toHaveKey('targetEffect');
});
