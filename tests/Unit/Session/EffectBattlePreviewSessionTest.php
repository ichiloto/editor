<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\Formation\BattleTargeting;
use Ichiloto\Engine\Battle\Formation\BattleTargetShape;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\BasicSkill;

/** A battle effect previewed in an action the battle test party actually plays it in, by the Engine's own animation selection. Synthetic fixtures only. */

it('previews an effect in the action that plays it, as the battle draws it, for both presentations', function () {
    $session = EditorSession::open(effectBattlePreviewProject());
    $index = findEffectIndex($session, 'dusk-slash');
    $graphical = $session->readEffectBattlePreview($index, 0);
    $atLane = $session->readEffectBattlePreview($index, 0, authoredFrame: 1);
    $terminal = $session->readEffectBattlePreview($index, 0, presentation: EffectPresentation::TERMINAL, authoredFrame: 0);

    expect($graphical['contexts'])->toBe([['caster' => 'Kaelion', 'action' => $graphical['contexts'][0]['action'], 'lane' => 'target']])
        ->and($graphical)->toMatchArray(['lane' => 'target', 'binding' => 0, 'caster' => 'Kaelion', 'troop' => 'Pair'])
        ->and($atLane['phase'])->toBe('target')
        ->and($atLane['authoredFrames']['target'])->toBe(1)
        ->and(array_find($atLane['canvas']['images'] ?? [], static fn(array $image): bool => $image['asset'] === 'Graphics/Effects/dusk-slash.png'))->not->toBeNull()
        ->and($terminal['canvas'])->toBeNull()
        ->and($terminal['terminalLines'])->not->toBe([]);
});

it('round-trips graphical flight authoring through the shared rows and previews the negotiated heading', function () {
    $root = effectBattlePreviewProject();
    $path = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
    $initial = require $path;
    $category = 'cutscenes/effect';
    $bindingPath = $root . '/assets/Data/Presentation/battlers.php';
    writeTilesetTestPng($root . '/assets/Graphics/FixturePose.png', 8, 8);
    writeTilesetTestPng($root . '/assets/Graphics/FixtureEnemy.png', 8, 8);
    file_put_contents($bindingPath, '<?php return ' . var_export(['actors' => ['Kaelion' => [
        'artwork' => ['image' => 'Graphics/FixturePose.png'],
        'poses' => ['attack' => ['image' => 'Graphics/FixturePose.png',
            'attachmentPoints' => [['cell' => 0, 'name' => '0', 'point' => ['x' => .5, 'y' => .4]]]]],
    ]], 'enemies' => ['Regular Bat' => ['artwork' => ['image' => 'Graphics/FixtureEnemy.png']]]], true) . ';');
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'dusk-slash');
    $session->selectCutscenePresentation($category, $index, 'graphical');

    $choices = $session->listReferences(null, 'battle_pose_points', ['category' => $category, 'index' => $index]);
    expect($choices)->toBe([['value' => '0', 'label' => '0 (Kaelion attack)']]);
    $trackFrame = $session->describeCutsceneTimeline($category, $index)['tracks'][0]['art']['pivotKey']['frame'];
    $row = static fn(string $field, array $frame): array =>
        array_find($session->readDatabaseRecord($category, $index, $frame)['rows'],
            static fn(array $entry): bool => ($entry['key']['field'] ?? null) === $field);
    $change = static function (string $field, string $value, ?array $frame = null) use ($session, $category, $index, $row, $trackFrame): void {
        expect($session->applyDatabaseRecord($category, $index, $row($field, $frame ?? $trackFrame)['key'], $value)['changed'])->toBeTrue();
    };
    $change('poseSyncFrame', '0', []);
    $change('track0Anchor', 'caster');
    $change('track0AttachmentPoint', '0');
    $change('track0DetachFrame', '0');
    $change('track0FlightarrivalFrame', '1');
    $change('track0Flightroute', 'straight');
    $change('track0FlighttipX', '.25');
    $session->undo();
    expect($row('track0FlighttipX', $trackFrame)['value'])->toBe('');
    $session->redo();
    $session->saveAll();

    $saved = require $path;
    $track = $saved['presentations']['graphical']['tracks'][0];
    expect($saved['presentations']['terminal'])->toBe($initial['presentations']['terminal'])
        ->and($saved['presentations']['graphical']['poseSyncFrame'])->toBe(0)
        ->and($track)->toMatchArray(['attachmentPoint' => '0', 'detachFrame' => 0,
            'flight' => ['arrivalFrame' => 1, 'route' => 'straight', 'tipX' => .25]]);

    $ordinary = $session->readEffectBattlePreview($index, 0, authoredFrame: 1);
    $rotating = $session->readEffectBattlePreview($index, 0, authoredFrame: 1, imageRotation: true);
    $image = static fn(array $frame): ?array => array_find($frame['canvas']['images'] ?? [],
        static fn(array $image): bool => $image['asset'] === 'Graphics/Effects/dusk-slash.png');
    expect($image($ordinary))->not->toBeNull()->not->toHaveKey('rotation')
        ->and($image($rotating))->toHaveKeys(['rotation', 'pivot'])
        ->and($rotating['diagnostics'])->toBe([]);
});

it('says so when no action of the battle test party plays an effect, inventing none', function () {
    $session = EditorSession::open(effectBattlePreviewProject());

    expect(fn() => $session->readEffectBattlePreview(findEffectIndex($session, 'ember-spark'), 0))
        ->toThrow(SessionRefusal::class, 'No action of the battle test party plays ember-spark in battle');
});

it('previews the selected combat line from the troop formation in both presentations', function () {
    $root = effectBattlePreviewProject();
    writeSkillRecords($root, new BasicSkill('Piercing shot', '', '', 0, 0,
        occasion: Occasion::BATTLE_SCREEN, animationId: 1,
        battleTargeting: new BattleTargeting(BattleTargetShape::LINE, ignoreCover: true)));
    $actorPath = $root . '/assets/Data/Actors/Kaelion.php';
    $actor = require $actorPath;
    $actor['data']['attackSkill'] = 'Piercing shot';
    file_put_contents($actorPath, '<?php return ' . var_export($actor, true) . ';');
    $troopPath = $root . '/assets/Data/troops.php';
    $troops = require $troopPath;
    $troops[0]['enemies'][0]['battlePosition'] = ['row' => 'front', 'lane' => 0];
    $troops[0]['enemies'][1]['battlePosition'] = ['row' => 'back', 'lane' => 0];
    $troops[0]['enemies'][] = [
        'enemy' => 'Regular Bat', 'position' => [20, 10], 'battlePosition' => ['row' => 'front', 'lane' => 1],
    ];
    file_put_contents($troopPath, '<?php return ' . var_export($troops, true) . ';');
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'dusk-slash');
    $graphical = $session->readEffectBattlePreview($index, 0, authoredFrame: 1);
    $terminal = $session->readEffectBattlePreview($index, 0, presentation: EffectPresentation::TERMINAL, authoredFrame: 1);
    $images = array_filter($graphical['canvas']['images'],
        static fn(array $image): bool => $image['asset'] === 'Graphics/Effects/dusk-slash.png');
    expect($graphical['targets'])->toBe(['Regular Bat', 'Regular Bat'])
        ->and($terminal['targets'])->toBe($graphical['targets'])
        ->and($images)->toHaveCount(2)
        ->and($session->listUnsavedChanges())->toBe([]);
});


it('finishes impact-anchored recovery in the current graphical arena preview without changing terminal clocks or source', function (bool $hasArena) {
    $root = effectBattlePreviewProject();
    file_put_contents($root . '/config.php', "<?php return ['ui' => ['battle' => ['animation_pace' => 'fast']]];");
    $actorPath = $root . '/assets/Data/Actors/Kaelion.php';
    $actor = require $actorPath;
    $actor['data']['name'] = 'Travelling Actor'; // The stable actor id still selects its art after a display-name change.
    file_put_contents($actorPath, '<?php return ' . var_export($actor, true) . ';');
    writeTilesetTestPng($root . '/assets/Graphics/FixtureBase.png', 8, 8);
    writeTilesetTestPng($root . '/assets/Graphics/FixtureAttack.png', 16, 8);
    $bindingPath = $root . '/assets/Data/Presentation/battlers.php';
    file_put_contents($bindingPath, '<?php return ' . var_export(['actors' => ['Kaelion' => [
        'artwork' => ['image' => 'Graphics/FixtureBase.png'],
        'poses' => ['attack' => ['image' => 'Graphics/FixtureAttack.png', 'columns' => 2,
            'frames' => [0,0,0,...array_fill(0, 17, 0),1], 'fps' => 20, 'loop' => false,
            'impactFrame' => 3, 'restFrame' => 0]],
    ]]], true) . ';');
    $catalogPath = $root . '/assets/Data/Presentation/battle.php';
    if (!$hasArena) {
        $catalog = file_get_contents($catalogPath);
        $catalog = str_replace("arenas: ['arena.road' => \$arena('Road', 'Road.png'), 'arena.cave' => \$arena('Cave', 'Cave.png')],", 'arenas: [],', $catalog);
        $catalog = str_replace("defaultArena: 'arena.cave'", 'defaultArena: null', $catalog);
        file_put_contents($catalogPath, $catalog);
    }
    $sources = [$actorPath, $bindingPath, $catalogPath, $root . '/config.php'];
    $before = array_map(file_get_contents(...), $sources);
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'dusk-slash');
    $graphical = $session->readEffectBattlePreview($index, 0);
    $terminal = $session->readEffectBattlePreview($index, 0, presentation: EffectPresentation::TERMINAL);
    expect($graphical['caster'])->toBe('Travelling Actor');
    foreach (['advance','announce','source','target','reaction','return'] as $phase) {
        expect($graphical['phases'][$phase])->toBe($terminal['phases'][$phase]);
    }
    if ($hasArena) {
        $recoveryEnd = $graphical['phases']['target']['start'] + 108; // Eighteen authored entries at 20 fps on the 120 fps command clock.
        expect($graphical['totalFrames'])->toBe($recoveryEnd)
            ->and($terminal['totalFrames'])->toBeLessThan($recoveryEnd);
        $last = $session->readEffectBattlePreview($index, $recoveryEnd - 1);
        $rest = $session->readEffectBattlePreview($index, $recoveryEnd - 1, reducedMotion: true);
        $image = static fn(array $frame): ?array => array_find($frame['canvas']['images'] ?? [],
            static fn(array $image): bool => $image['asset'] === 'Graphics/FixtureAttack.png');
        expect($last['phase'])->toBe('finish')
            ->and($image($last)['sourceRect']['x'] ?? null)->toBe(8)
            ->and($image($rest)['sourceRect']['x'] ?? null)->toBe(0);
        $hits = array_values(array_filter($last['crossedCues'], static fn(array $cue): bool => $cue['type'] === 'commandResolved'));
        expect($hits)->toHaveCount(1)
            ->and($hits[0]['frame'])->toBe($graphical['phases']['target']['start'] + $graphical['phases']['target']['length']);
    } else {
        expect($graphical['canvas'])->toBeNull()->and($graphical['totalFrames'])->toBe($terminal['totalFrames']);
    }
    expect(array_map(file_get_contents(...), $sources))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([]);
})->with([true, false]);


it('previews movement and full recovery through production phase clocks while isolating reduced-motion caching', function () {
    $root = effectBattlePreviewProject();
    file_put_contents($root . '/config.php', "<?php return ['ui' => ['battle' => ['animation_pace' => 'fast']]];");
    writeTilesetTestPng($root . '/assets/Graphics/Move.png', 16, 8);
    writeTilesetTestPng($root . '/assets/Graphics/Attack.png', 16, 8);
    $binding = $root . '/assets/Data/Presentation/battlers.php';
    file_put_contents($binding, '<?php return ' . var_export(['actors' => ['Kaelion' => [
        'poses' => [
            'attack' => ['image' => 'Graphics/Attack.png', 'columns' => 2, 'frames' => [0, ...array_fill(0, 18, 1)],
                'fps' => 20, 'loop' => false, 'impactFrame' => 1],
            'move' => ['image' => 'Graphics/Move.png', 'columns' => 2, 'frames' => [0,1], 'fps' => 20],
            'return' => ['image' => 'Graphics/Move.png', 'columns' => 2, 'frames' => [1,0], 'fps' => 20, 'loop' => false],
        ],
    ]]], true) . ';');
    $before = file_get_contents($binding);
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'dusk-slash');
    $normal = $session->readEffectBattlePreview($index, 0);
    $image = static fn(array $frame): ?array => array_find($frame['canvas']['images'] ?? [],
        static fn(array $image): bool => $image['asset'] === 'Graphics/Move.png');
    expect($image($normal)['sourceRect']['x'] ?? null)->toBe(0)
        ->and($image($session->readEffectBattlePreview($index, 6))['sourceRect']['x'] ?? null)->toBe(8);
    $home = $normal['phases']['return']['start'];
    expect($normal['phases']['recovery']['length'])->toBeGreaterThan(0)
        ->and($home)->toBe($normal['phases']['target']['start'] + 108);
    $returned = $session->readEffectBattlePreview($index, $home);
    expect($returned['phase'])->toBe('return')->and($image($returned)['sourceRect']['x'] ?? null)->toBe(8)
        ->and($image($session->readEffectBattlePreview($index, $home + (int)ceil($normal['phases']['return']['length'] / 2)))['sourceRect']['x'] ?? null)->toBe(0);
    $reduced = $session->readEffectBattlePreview($index, 0, reducedMotion: true);
    expect($reduced['phases'])->not->toHaveKey('recovery')
        ->and($reduced['phases']['return']['start'])->toBeLessThan($home)->and($image($reduced))->toBeNull();
    $terminal = $session->readEffectBattlePreview($index, 0, presentation: EffectPresentation::TERMINAL);
    expect($terminal['phases'])->not->toHaveKey('recovery')->and($terminal['canvas'])->toBeNull()
        ->and($session->readEffectBattlePreview($index, 0)['phases'])->toBe($normal['phases'])
        ->and(file_get_contents($binding))->toBe($before)->and($session->listUnsavedChanges())->toBe([]);
});

it('previews the shared run-to-target plan while keeping Terminal and reduced motion at rest', function () {
    $root = effectBattlePreviewProject();
    writeTilesetTestPng($root . '/assets/Graphics/Contact.png', 32, 64);
    writeTilesetTestPng($root . '/assets/Graphics/ContactEnemy.png', 32, 64);
    writeTilesetTestPng($root . '/assets/Graphics/Lifecycle.png', 128, 64);
    $binding = $root . '/assets/Data/Presentation/battlers.php';
    file_put_contents($binding, '<?php return ' . var_export([
        'actors' => ['Kaelion' => ['artwork' => ['image' => 'Graphics/Contact.png'], 'poses' => [
            'attack' => ['image' => 'Graphics/Contact.png'],
            'move' => ['image' => 'Graphics/Lifecycle.png', 'columns' => 4, 'frames' => [1,2], 'entryFrames' => [0], 'exitFrames' => [3]],
            'return' => ['image' => 'Graphics/Lifecycle.png', 'columns' => 4, 'frames' => [2,1], 'loop' => false, 'entryFrames' => [3], 'exitFrames' => [0]],
        ]]],
        'enemies' => ['Regular Bat' => ['artwork' => ['image' => 'Graphics/ContactEnemy.png']]],
    ], true) . ';');
    $path = $root . '/assets/Data/animations.php'; $data = require $path;
    $data[0]['movement'] = 'run-to-target';
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    $sources = array_map(file_get_contents(...), [$path, $binding]);
    $session = EditorSession::open($root); $effect = findEffectIndex($session, 'dusk-slash');
    $home = $session->readEffectBattlePreview($effect, 0);
    $strike = $session->readEffectBattlePreview($effect, $home['phases']['target']['start']);
    $image = static fn(array $frame): array => array_find($frame['canvas']['images'],
        static fn(array $image): bool => in_array($image['asset'], ['Graphics/Contact.png', 'Graphics/Lifecycle.png'], true));
    expect($home['phase'])->toBe('enter-advance')->and($image($home)['sourceRect']['x'])->toBe(0)
        ->and($home['phases'])->toHaveKeys(['enter-advance','exit-advance','enter-return','exit-return'])
        ->and($home['phases'])->toHaveKey('recovery')
        ->and($strike['diagnostics'])->toBe([])
        ->and($image($strike)['destination'])->not->toBe($image($home)['destination']);
    $loop = $session->readEffectBattlePreview($effect, $home['phases']['advance']['start']);
    $land = $session->readEffectBattlePreview($effect, $home['phases']['exit-return']['start']);
    expect($loop['phase'])->toBe('advance')->and($image($loop)['sourceRect']['x'])->toBe(32)
        ->and($land['phase'])->toBe('exit-return')->and($image($land)['sourceRect']['x'])->toBe(0)
        ->and($image($land)['destination'])->toBe($image($home)['destination']);
    $rest = $session->readEffectBattlePreview($effect, 0, reducedMotion: true);
    $terminal = $session->readEffectBattlePreview($effect, 0, presentation: EffectPresentation::TERMINAL);
    expect($rest['phases'])->not->toHaveKey('recovery')->and($terminal['phases'])->not->toHaveKey('recovery')
        ->and($terminal['canvas'])->toBeNull()->and($session->listUnsavedChanges())->toBe([])
        ->and(array_map(file_get_contents(...), [$path, $binding]))->toBe($sources);
});
