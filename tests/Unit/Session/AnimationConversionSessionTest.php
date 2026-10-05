<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;

/** Converting a legacy cell-frame animation to a timeline. Synthetic fixtures only. */

const LEGACY_ANIMATIONS = <<<'PHP'
<?php

return [
    // A spark in the old cell frames.
    [
        'id' => 1,
        'name' => 'Old Spark',
        'position' => 'center',
        'maxFrames' => 3,
        'frames' => [
            ['index' => 1, 'cells' => [['x' => 0, 'y' => 0, 'symbol' => '*', 'color' => 'yellow']]],
            ['index' => 2, 'cells' => [['x' => 1, 'y' => 0, 'symbol' => '+']]],
        ],
        'cues' => [['frame' => 1, 'soundEffect' => 'spark', 'flashColor' => 'white', 'flashDurationFrames' => 2]],
    ],
    ['id' => 2, 'name' => 'Already Timed', 'targetEffect' => 'shared-burst'],
];
PHP;

/** A project whose animations hold one legacy record and one already on a timeline. */
function legacyAnimationProject(): string
{
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/animations.php', LEGACY_ANIMATIONS);

    return $root;
}

function legacyAnimationIndex(EditorSession $session, string $name): int
{
    return array_search($name, $session->listDatabaseRecords('animations')['records'], true);
}

it('describes what a conversion touches: the legacy frames and cues, its bindings and who plays it', function () {
    $session = EditorSession::open(legacyAnimationProject());

    expect($session->describeAnimationConversion(legacyAnimationIndex($session, 'Old Spark')))->toMatchArray([
        'id' => 1, 'name' => 'Old Spark', 'frames' => 2, 'maxFrames' => 3, 'position' => 'center', 'cues' => 1, 'flashes' => 1,
        'sourceEffect' => null, 'targetEffect' => null, 'field' => [],
    ])->and(fn() => $session->describeAnimationConversion(legacyAnimationIndex($session, 'Already Timed')))
        ->toThrow(SessionRefusal::class, 'has no legacy frames or cues to convert');
});

it('asks first, then writes the timeline and the battle binding as one undo step, keeping the legacy frames', function () {
    $root = legacyAnimationProject();
    $session = EditorSession::open($root);
    $index = legacyAnimationIndex($session, 'Old Spark');
    $convert = static fn(?string $answer) => $session->convertAnimation($index, 'old-spark', 'battle_phase', null, 1, 0, true, 'targetEffect', $answer);
    $timeline = $root . '/assets/Animations/old-spark/old-spark.timeline.php';

    $asked = $convert(null);

    expect($asked['status'])->toBe('question')
        ->and(array_keys($asked['preview']))->toBe(['assets/Data/animations.php', 'assets/Animations/old-spark/old-spark.timeline.php'])
        ->and($asked['preview']['assets/Data/animations.php'])->toContain("// A spark in the old cell frames.", "'targetEffect' => 'old-spark'")
        ->and(file_exists($timeline))->toBeFalse();

    expect($convert('write'))->toMatchArray(['changed' => true, 'reloaded' => true])
        ->and(file_get_contents($root . '/assets/Data/animations.php'))->toBe($asked['preview']['assets/Data/animations.php'])
        ->and(file_get_contents($root . '/assets/Data/animations.php'))->toContain("'frames' => [", "'cues' => [")
        ->and(new EffectTimelineLibrary($root . '/assets')->load('old-spark', forBattle: true))->not->toBeNull();

    $session->undo();

    expect(file_get_contents($root . '/assets/Data/animations.php'))->toBe(LEGACY_ANIMATIONS)
        ->and(file_exists($timeline))->toBeFalse()
        ->and(is_dir(dirname($timeline)))->toBeFalse();
});

it('converts for a field consumer at an explicit fixed rate, leaving the record as it is', function () {
    $root = legacyAnimationProject();
    $session = EditorSession::open($root);

    $session->convertAnimation(legacyAnimationIndex($session, 'Old Spark'), 'field-old-spark', 'fixed', 25, 3, 0, false, null, 'write');
    $written = require $root . '/assets/Animations/field-old-spark/field-old-spark.timeline.php';

    expect(file_get_contents($root . '/assets/Data/animations.php'))->toBe(LEGACY_ANIMATIONS)
        ->and($written)->toMatchArray(['fps' => 25, 'lengthFrames' => 9, 'restFrame' => 0])
        ->and(new EffectTimelineLibrary($root . '/assets')->load('field-old-spark'))->not->toBeNull();
});

it('refuses, before writing, timing its consumer cannot use, a taken name, and pending edits', function () {
    $root = legacyAnimationProject();
    $session = EditorSession::open($root);
    $index = legacyAnimationIndex($session, 'Old Spark');
    mkdir($root . '/assets/Animations/taken', 0o777, true);

    expect(fn() => $session->convertAnimation($index, 'old-spark', 'battle_phase', null, 1, 0, true, null))->toThrow(SessionRefusal::class, 'Only a battle paces')
        ->and(fn() => $session->convertAnimation($index, 'old-spark', 'fixed', null, 1, 0, true, null))->toThrow(SessionRefusal::class, 'explicit consumer cadence')
        ->and(fn() => $session->convertAnimation($index, 'old-spark', 'battle_phase', null, 1, 9, true, 'targetEffect'))->toThrow(SessionRefusal::class, 'rest frame')
        ->and(fn() => $session->convertAnimation($index, 'taken', 'battle_phase', null, 1, 0, true, 'targetEffect'))->toThrow(SessionRefusal::class, 'already exists')
        ->and(fn() => $session->convertAnimation($index, 'old-spark', 'battle_phase', null, 1, 0, true, 'sideEffect'))->toThrow(SessionRefusal::class, 'sourceEffect or targetEffect');

    $name = array_find($session->readDatabaseRecord('animations', $index)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'name');
    $session->applyDatabaseRecord('animations', $index, $name['key'], 'Renamed Spark');

    expect(fn() => $session->convertAnimation($index, 'old-spark', 'battle_phase', null, 1, 0, true, 'targetEffect', 'write'))
        ->toThrow(SessionRefusal::class, 'Save or undo pending edits')
        ->and(file_exists($root . '/assets/Animations/old-spark'))->toBeFalse();
});
