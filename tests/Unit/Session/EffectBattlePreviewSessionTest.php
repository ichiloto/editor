<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\EffectBattlePreview;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\Formation\BattleTargeting;
use Ichiloto\Engine\Battle\Formation\BattleTargetShape;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

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

it('keeps the graphical command boundaries when the independent Terminal target becomes longer', function () {
    $root = effectBattlePreviewProject();
    $path = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
    $before = EditorSession::open($root);
    $index = findEffectIndex($before, 'dusk-slash');
    $graphical = $before->readEffectBattlePreview($index, 0);
    $terminal = $before->readEffectBattlePreview($index, 0, presentation: EffectPresentation::TERMINAL);
    $timeline = require $path;
    $timeline['presentations']['terminal']['lengthFrames'] = 48;
    file_put_contents($path, '<?php return ' . var_export($timeline, true) . ';');
    $after = EditorSession::open($root);
    $longTerminal = $after->readEffectBattlePreview($index, 0, presentation: EffectPresentation::TERMINAL);
    $unchangedGraphical = $after->readEffectBattlePreview($index, 0);

    expect($unchangedGraphical['phases'])->toBe($graphical['phases'])
        ->and($unchangedGraphical['totalFrames'])->toBe($graphical['totalFrames'])
        ->and($longTerminal['phases']['target']['length'])->toBeGreaterThan($terminal['phases']['target']['length']);
});

it('uses the saved shared impact delay in both battle previews without retiming the effect', function () {
    $root = effectBattlePreviewProject();
    $effect = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
    $source = file_get_contents($effect);
    $database = \Ichiloto\Editor\ProjectWorkspace::fromProject($root)->getRecordDatabase('animations');
    $database->setField(0, 'targetImpactSeconds', '3');
    $database->save();
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'dusk-slash');
    foreach (EffectPresentation::cases() as $presentation) {
        $first = $session->readEffectBattlePreview($index, 0, presentation: $presentation);
        $target = $first['phases']['target'];
        $late = $session->readEffectBattlePreview($index, $target['start'] + 359, presentation: $presentation);
        expect($target['length'])->toBe(360)
            ->and($first['phases']['reaction']['start'])->toBe($target['start'] + 360)
            ->and($late['authoredFrames']['target'])->toBeNull();
    }
    expect(file_get_contents($effect))->toBe($source);
});

it('refuses an animation delay when either effect presentation owns impact', function () {
    $root = effectBattlePreviewProject();
    $effect = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
    $timeline = require $effect;
    $timeline['presentations']['terminal']['cues'] = [
        ['id' => 'hit', 'type' => 'applyEffect', 'frame' => 0, 'payload' => []],
    ];
    file_put_contents($effect, '<?php return ' . var_export($timeline, true) . ';');
    $animations = $root . '/assets/Data/animations.php';
    $before = file_get_contents($animations);
    $workspace = \Ichiloto\Editor\ProjectWorkspace::fromProject($root);
    $database = $workspace->getRecordDatabase('animations');
    $database->setField(0, 'targetImpactSeconds', '0.8');
    expect(fn() => $database->save())->toThrow(\RuntimeException::class, 'Clear the target effect impact timing')
        ->and(file_get_contents($animations))->toBe($before);

    $issues = new \Ichiloto\Editor\Validation\EffectValidator()->validate($workspace);
    expect(array_any($issues, static fn($issue): bool => str_contains($issue->message, 'also owns combat resolution for the terminal presentation.')))
        ->toBeTrue();
});

it('refreshes both battle previews after an unsaved actor edit and history changes', function () {
    $session = EditorSession::open(effectBattlePreviewProject());
    $index = findEffectIndex($session, 'dusk-slash');
    $casters = static function () use ($session, $index): array {
        $casters = [];
        foreach (EffectPresentation::cases() as $presentation) {
            $casters[] = $session->readEffectBattlePreview($index, 0, presentation: $presentation)['caster'];
            $casters[] = $session->readSummonBattlePreview(0, 0, presentation: $presentation)['caster'];
        }

        return $casters;
    };

    $before = $casters();
    expect($before)->toBe(array_fill(0, 4, 'Kaelion'));
    $row = array_find($session->readDatabaseRecord('actors', 0)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'name');
    expect($session->applyDatabaseRecord('actors', 0, $row['key'], 'Travelling Actor')['changed'])->toBeTrue();
    $edited = array_fill(0, 4, 'Travelling Actor');
    expect($casters())->toBe($edited);
    $session->undo();
    expect($casters())->toBe($before);
    $session->redo();
    expect($casters())->toBe($edited);
});

it('round-trips graphical flight authoring through the shared rows and previews the negotiated heading', function (bool $curved, bool $timed) {
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
    $route = $curved ? 'cubic' : 'straight';
    $curve = ['launchProgress' => .28, 'launchOffsetY' => -.15, 'arrivalProgress' => .71, 'arrivalOffsetY' => -.075];
    $change('track0Flightroute', $route);
    $values = $curved ? array_combine(array_map(static fn(string $key): string => 'track0Flightcurve' . $key, array_keys($curve)), $curve)
        : ['track0FlighttipX' => .25];
    foreach ($values as $field => $value) {
        $change($field, (string) $value);
    }
    $authoredImpact = $session->readEffectBattlePreview($index, 0, authoredFrame: 1);
    if ($timed) {
        expect($session->listReferences(null, 'effect_flight_tracks', ['category' => $category, 'index' => $index]))
            ->toBe([['value' => 'stroke-art', 'label' => 'stroke-art']]);
        $change('effectTiming.mode', 'frame', []);
        $change('effectTiming.frame', '1', []);
        $change('flightTiming.track', 'stroke-art', []);
        $change('flightTiming.speed', '.5', []);
    }
    $session->undo();
    expect($timed ? $row('flightTiming.speed', [])['value'] : $row(array_key_last($values), $trackFrame)['value'])->toBe('');
    $session->redo();
    expect($session->saveAll()['failures'])->toBe([]);

    $saved = require $path;
    $track = $saved['presentations']['graphical']['tracks'][0];
    expect($saved['presentations']['terminal'])->toBe($initial['presentations']['terminal'])
        ->and($saved['presentations']['graphical']['poseSyncFrame'])->toBe(0)
        ->and($track)->toMatchArray(['attachmentPoint' => '0', 'detachFrame' => 0,
            'flight' => ['arrivalFrame' => 1, 'route' => $route, ...($curved ? ['curve' => $curve] : ['tipX' => .25])]]);

    if ($timed) {
        expect($saved['presentations']['graphical']['flightTiming'])->toBe(['track' => 'stroke-art', 'speed' => .5]);
    }
    $ordinary = $session->readEffectBattlePreview($index, 0, authoredFrame: 1);
    $rotating = $session->readEffectBattlePreview($index, 0, authoredFrame: 1, imageRotation: true);
    $image = static fn(array $frame): ?array => array_find($frame['canvas']['images'] ?? [],
        static fn(array $image): bool => $image['asset'] === 'Graphics/Effects/dusk-slash.png');
    expect($image($ordinary))->not->toBeNull()->not->toHaveKey('rotation')
        ->and($image($rotating))->toHaveKeys(['rotation', 'pivot'])
        ->and($rotating['diagnostics'])->toBe([]);
    if ($timed) {
        expect($rotating['frame'])->toBeGreaterThan($authoredImpact['frame'])
            ->and($rotating['totalFrames'])->toBeGreaterThan($authoredImpact['totalFrames'])
            ->and($rotating['authoredFrames']['target'])->toBe(1)
            ->and(array_find($rotating['crossedCues'], static fn(array $cue): bool => $cue['type'] === 'commandResolved')['frame'])
            ->toBe($rotating['frame']);
        $reduced = $session->readEffectBattlePreview($index, 0, authoredFrame: 1, reducedMotion: true);
        expect($reduced['diagnostics'])->toBe([])
            ->and(array_find($reduced['crossedCues'], static fn(array $cue): bool => $cue['type'] === 'commandResolved')['frame'])
            ->toBe($reduced['frame']);
    }
})->with([[false, false], [true, false], [false, true]]);

it('round-trips range timing keys and uses projected distance in the graphical battle preview', function () {
    $root = effectBattlePreviewProject();
    $path = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
    $source = require $path;
    $source['presentations']['graphical']['lengthFrames'] = 4;
    $source['presentations']['graphical']['restFrame'] = 2;
    $source['presentations']['graphical']['tracks'][0]['keyframes'][1] = ['frame' => 2, 'duration' => 2, 'sourceFrame' => 1];
    file_put_contents($path, '<?php return ' . var_export($source, true) . ';');
    $session = EditorSession::open($root);
    $category = 'cutscenes/effect';
    $index = findEffectIndex($session, 'dusk-slash');
    $session->selectCutscenePresentation($category, $index, 'graphical');
    $field = static fn(array $frame, string $label): array =>
        array_find($session->readDatabaseRecord($category, $index, $frame)['rows'],
            static fn(array $entry): bool => trim($entry['label']) === $label)['key'];
    $apply = static fn(array $frame, string $label, string $value): array =>
        $session->applyDatabaseRecord($category, $index, $field($frame, $label), $value);
    $normal = $session->readEffectBattlePreview($index, 0, authoredFrame: 2);
    expect($apply([], 'Far Range Threshold', '.001')['changed'])->toBeTrue();
    $keys = ['rangeTiming.keys'];
    expect($session->addDatabaseItem($category, $index, ['frame' => $keys])['changed'])->toBeTrue()
        ->and($apply($keys, 'Range timing key 1 Authored Frame', '2')['changed'])->toBeTrue();
    $session->undo();
    $session->redo();
    expect($session->saveAll()['failures'])->toBe([]);
    $saved = require $path;
    expect($saved['presentations']['terminal'])->toBe($source['presentations']['terminal'])
        ->and($saved['presentations']['graphical']['rangeTiming'])->toBe(['minDistance' => .001, 'keys' => [['frame' => 2, 'at' => 1]]]);
    $far = $session->readEffectBattlePreview($index, 0, authoredFrame: 2);
    expect($far['frame'])->toBe($normal['frame'] - 10)
        ->and($far['authoredFrames']['target'])->toBe(2)
        ->and($far['diagnostics'])->toBe([]);
});

it('edits a graphical trail and previews its coverage without rewriting Terminal data', function () {
    $root = effectBattlePreviewProject();
    $path = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
    $source = require $path;
    writeTilesetTestPng($root . '/assets/Graphics/FixturePose.png', 8, 8);
    writeTilesetTestPng($root . '/assets/Graphics/FixtureEnemy.png', 8, 8);
    $bindings = ['actors' => ['Kaelion' => ['artwork' => ['image' => 'Graphics/FixturePose.png'],
        'poses' => ['attack' => ['image' => 'Graphics/FixturePose.png',
            'attachmentPoints' => [['cell' => 0, 'name' => 'launch', 'point' => ['x' => .5, 'y' => .4]]]]]]],
        'enemies' => ['Regular Bat' => ['artwork' => ['image' => 'Graphics/FixtureEnemy.png']]]];
    file_put_contents($root . '/assets/Data/Presentation/battlers.php', '<?php return ' . var_export($bindings, true) . ';');
    $source['presentations']['graphical']['lengthFrames'] = 5;
    $source['presentations']['graphical']['restFrame'] = 1;
    $source['presentations']['graphical']['tracks'] = [[
        'id' => 'trail', 'type' => 'trail', 'attachmentPoint' => 'launch', 'detachFrame' => 0, 'fadeEndFrame' => 5,
        'flight' => ['arrivalFrame' => 1, 'route' => 'cubic', 'bystanderClearance' => 1,
            'curve' => ['launchProgress' => .3, 'launchOffsetY' => -.1, 'arrivalProgress' => .7, 'arrivalOffsetY' => -.1]],
        'profile' => ['length' => 40, 'spacing' => 2, 'startRadius' => 1, 'endRadius' => 5, 'radiusPower' => 1,
            'startOpacity' => .1, 'endOpacity' => .8, 'opacityPower' => 1, 'fadeLengthRatio' => .5,
            'brush' => ['falloff' => 1, 'alphaSteps' => 100, 'minimumAlpha' => 1,
                'bands' => [['until' => 1, 'color' => ['kind' => 'rgb', 'r' => 4, 'g' => 5, 'b' => 6]]]]],
    ]];
    file_put_contents($path, '<?php return ' . var_export($source, true) . ';');
    $session = EditorSession::open($root);
    $category = 'cutscenes/effect';
    $index = findEffectIndex($session, 'dusk-slash');
    $session->selectCutscenePresentation($category, $index, 'graphical');
    $row = static fn(string $field): array => array_find($session->readDatabaseRecord($category, $index, ['tracks'])['rows'],
        static fn(array $entry): bool => ($entry['key']['field'] ?? null) === $field);
    $change = static fn(string $field, string $value): array => $session->applyDatabaseRecord($category, $index, $row($field)['key'], $value);
    expect($session->listReferences(null, 'battle_pose_points', ['category' => $category, 'index' => $index]))
        ->toBe([['value' => 'launch', 'label' => 'launch (Kaelion attack)']])
        ->and($change('track0ProfileendRadius', '6')['changed'])->toBeTrue()
        ->and($change('track0FlightbystanderClearance', '2')['changed'])->toBeTrue()
        ->and($change('track0Band0Colorr', '99')['changed'])->toBeTrue();
    $session->undo();
    expect($row('track0Band0Colorr')['value'])->toBe('4');
    $session->redo();
    expect($session->saveAll()['failures'])->toBe([]);
    $saved = require $path;
    expect($saved['presentations']['terminal'])->toBe($source['presentations']['terminal'])
        ->and($saved['presentations']['graphical']['tracks'][0]['flight']['bystanderClearance'])->toBe(2.0)
        ->and($saved['presentations']['graphical']['tracks'][0]['profile']['brush']['bands'][0]['color']['r'])->toBe(99);
    $ordinary = $session->readEffectBattlePreview($index, 0, authoredFrame: 1);
    $enabled = $session->readEffectBattlePreview($index, 0, authoredFrame: 1, coverageDabs: true);
    $trail = static fn(array $frame): ?array => array_find($frame['canvas']['composites'] ?? [],
        static fn(array $part): bool => str_contains($part['id'], 'target-trail'));
    expect($trail($ordinary))->toBeNull()
        ->and($trail($enabled)['operations'][0]['bands'][0]['color']['r'])->toBe(99)
        ->and($enabled['diagnostics'])->toBe([]);
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
    $otherLane = $session->readEffectBattlePreview($index, 0, authoredFrame: 1, targetIndex: 2);
    $otherLaneTerminal = $session->readEffectBattlePreview($index, 0, presentation: EffectPresentation::TERMINAL, authoredFrame: 1, targetIndex: 2);
    $images = array_filter($graphical['canvas']['images'],
        static fn(array $image): bool => $image['asset'] === 'Graphics/Effects/dusk-slash.png');
    expect($graphical['targets'])->toBe(['Regular Bat', 'Regular Bat'])
        ->and($terminal['targets'])->toBe($graphical['targets'])
        ->and($graphical['targetIndex'])->toBe(0)
        ->and($graphical['targetOptions'][2])->toBe(['name' => 'Regular Bat', 'side' => 'enemy', 'position' => ['row' => 'front', 'lane' => 1]])
        ->and($otherLane['targetIndex'])->toBe(2)
        ->and($otherLane['targets'])->toBe(['Regular Bat'])
        ->and($otherLaneTerminal['targets'])->toBe($otherLane['targets'])
        ->and(fn() => $session->readEffectBattlePreview($index, 0, targetIndex: 3))->toThrow(SessionRefusal::class, 'Choose a target')
        ->and($images)->toHaveCount(2)
        ->and($session->listUnsavedChanges())->toBe([]);

    $shape = array_find($session->readDatabaseRecord('skills', 0)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'battleTargeting.shape');
    foreach (EffectPresentation::cases() as $presentation) {
        $session->readEffectBattlePreview($index, 0, presentation: $presentation, authoredFrame: 1);
    }
    expect($session->applyDatabaseRecord('skills', 0, $shape['key'], BattleTargetShape::BACK_ROW->value)['changed'])->toBeTrue();
    foreach (EffectPresentation::cases() as $presentation) {
        expect($session->readEffectBattlePreview($index, 0, presentation: $presentation, authoredFrame: 1)['targets'])
            ->toBe(['Regular Bat']);
    }
    $session->undo();
    foreach (EffectPresentation::cases() as $presentation) {
        expect($session->readEffectBattlePreview($index, 0, presentation: $presentation, authoredFrame: 1)['targets'])
            ->toBe(['Regular Bat', 'Regular Bat']);
    }
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
    foreach (['advance', 'announce', 'source'] as $phase) {
        expect($graphical['phases'][$phase])->toBe($terminal['phases'][$phase]);
    }
    if ($hasArena) {
        expect($graphical['phases']['target']['length'])->toBe(20)
            ->and($terminal['phases']['target']['length'])->toBe(40);
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
        expect($graphical['canvas'])->toBeNull()
            ->and($graphical['phases'])->toBe($terminal['phases'])
            ->and($graphical['totalFrames'])->toBe($terminal['totalFrames']);
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

it('previews the saved flash step and its action cells through the shared session in both modes', function () {
    $root = effectBattlePreviewProject();
    writeTilesetTestPng($root . '/assets/Graphics/Contact.png', 32, 64);
    writeTilesetTestPng($root . '/assets/Graphics/ContactEnemy.png', 32, 64);
    writeTilesetTestPng($root . '/assets/Graphics/Flash.png', 128, 64);
    $binding = $root . '/assets/Data/Presentation/battlers.php';
    $pose = ['image' => 'Graphics/Flash.png', 'columns' => 4, 'frames' => [2], 'fps' => 24,
        'loop' => false, 'restFrame' => 2, 'impactFrame' => 0, 'entryFrames' => [0, 1], 'exitFrames' => [3]];
    file_put_contents($binding, '<?php return ' . var_export([
        'actors' => ['Kaelion' => ['artwork' => ['image' => 'Graphics/Contact.png'], 'actionPoses' => [1 => $pose],
            'poses' => ['move' => ['image' => 'Graphics/UnusedRun.png']]]],
        'enemies' => ['Regular Bat' => ['artwork' => ['image' => 'Graphics/ContactEnemy.png']]],
    ], true) . ';');
    $path = $root . '/assets/Data/animations.php';
    $animations = require $path;
    $animations[0]['movement'] = 'flash-step';
    $animations[0]['flashStep'] = ['fps' => 24, 'guardFrames' => 20, 'advanceFrames' => 3, 'holdFrames' => 10,
        'returnFrames' => 3, 'settleFrames' => 7, 'echoes' => 2];
    file_put_contents($path, '<?php return ' . var_export($animations, true) . ';');
    $session = EditorSession::open($root);
    $effect = findEffectIndex($session, 'dusk-slash');
    $advance = $session->readEffectBattlePreview($effect, 110);
    $strike = $session->readEffectBattlePreview($effect, 0, authoredFrame: 0);
    $echoes = static fn(array $frame): array => array_values(array_filter($frame['canvas']['images'] ?? [],
        static fn(array $image): bool => str_starts_with($image['id'], 'command-echo-')));
    $body = static fn(array $frame): ?array => array_find($frame['canvas']['images'] ?? [],
        static fn(array $image): bool => $image['asset'] === 'Graphics/Flash.png' && str_starts_with($image['id'], 'combatant-'));
    expect($advance['phases']['advance'])->toBe(['start' => 100, 'length' => 15])
        ->and($advance['diagnostics'])->toBe([])
        ->and($echoes($advance))->toHaveCount(2)
        ->and($body($advance)['sourceRect']['x'])->toBe(32)
        ->and($strike['frame'])->toBe(115)
        ->and($body($strike)['sourceRect']['x'])->toBe(64)
        ->and($echoes($strike))->toBe([]);
    $rest = $session->readEffectBattlePreview($effect, 0, reducedMotion: true);
    $terminal = $session->readEffectBattlePreview($effect, 0, presentation: EffectPresentation::TERMINAL);
    expect($rest['phases'])->not->toHaveKey('guard')
        ->and($terminal['phases'])->not->toHaveKey('guard')
        ->and($terminal['canvas'])->toBeNull()
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('saves a captured target-group contact rail and previews it only with strip support', function () {
    $root = effectBattlePreviewProject();
    $path = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
    $source = require $path;
    $rail = ['left' => .2, 'right' => .8, 'ridgeY' => .45, 'referenceHeight' => .5];
    // The page exceeds the legacy frame budget once the arena is included.
    // The 64px crop itself is small enough for a region-capable preview.
    writeTilesetTestPng($root . '/assets/Graphics/Effects/LargeGroup.png', 4096, 4096);
    $source['presentations']['graphical']['tracks'][0] = array_replace($source['presentations']['graphical']['tracks'][0], [
        'anchor' => 'target-group', 'attachment' => 'ground', 'anchorLifetime' => 'effect-start', 'contactRail' => $rail,
        'asset' => 'Graphics/Effects/LargeGroup.png', 'sheet' => ['columns' => 64, 'rows' => 64],
    ]);
    file_put_contents($path, '<?php return ' . var_export($source, true) . ';');
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'dusk-slash');
    $category = 'cutscenes/effect';
    $session->selectCutscenePresentation($category, $index, 'graphical');
    $trackFrame = $session->describeCutsceneTimeline($category, $index)['tracks'][0]['art']['pivotKey']['frame'];
    $row = array_find($session->readDatabaseRecord($category, $index, $trackFrame)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'track0ContactRailridgeY');
    expect($row)->not->toBeNull()
        ->and($session->applyDatabaseRecord($category, $index, $row['key'], '.4')['changed'])->toBeTrue();
    $session->undo();
    $session->redo();
    expect($session->saveAll()['failures'])->toBe([]);
    $saved = require $path;
    expect($saved['presentations']['graphical']['tracks'][0]['contactRail'])->toBe([...$rail, 'ridgeY' => .4])
        ->and($saved['presentations']['terminal'])->toBe($source['presentations']['terminal']);
    $legacy = $session->readEffectBattlePreview($index, 0, authoredFrame: 1, textureStrip: true);
    $enabled = $session->readEffectBattlePreview($index, 0, authoredFrame: 1, textureStrip: true, regionBudget: true);
    $legacyAgain = $session->readEffectBattlePreview($index, 0, authoredFrame: 1, textureStrip: true);
    $usesStrip = static fn(array $canvas): bool => array_any($canvas['composites'] ?? [],
        static fn(array $composite): bool => ($composite['operations'][0]['type'] ?? null) === 'texture_strip');
    expect($usesStrip($legacy['canvas']))->toBeFalse()
        ->and($legacy['diagnostics'])->not->toBeEmpty()
        ->and($usesStrip($enabled['canvas']))->toBeTrue()
        ->and($enabled['diagnostics'])->toBe([])
        ->and($usesStrip($legacyAgain['canvas']))->toBeFalse();
});

it('authors a graphical tether with the shared asset and pose pickers and reopens it in the battle preview', function () {
    $root = effectBattlePreviewProject();
    $path = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
    $initial = require $path;
    $category = 'cutscenes/effect';
    $head = 'Graphics/FixturePose.png';
    $links = 'Graphics/Effects/dusk-slash.png';
    writeTilesetTestPng($root . '/assets/' . $head, 8, 8);
    writeTilesetTestPng($root . '/assets/Graphics/FixtureEnemy.png', 8, 8);
    $binding = ['actors' => ['Kaelion' => [
        'artwork' => ['image' => $head],
        'poses' => ['attack' => ['image' => $head,
            'attachmentPoints' => [['cell' => 0, 'name' => 'grip', 'point' => ['x' => .5, 'y' => .4]]]]],
    ]], 'enemies' => ['Regular Bat' => ['artwork' => ['image' => 'Graphics/FixtureEnemy.png']]]];
    file_put_contents($root . '/assets/Data/Presentation/battlers.php', '<?php return ' . var_export($binding, true) . ';');
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'dusk-slash');
    $session->selectCutscenePresentation($category, $index, 'graphical');
    $clock = array_find($session->readDatabaseRecord($category, $index)['rows'],
        static fn(array $entry): bool => ($entry['key']['field'] ?? null) === 'poseClock');
    expect($clock)->not->toBeNull()
        ->and($session->applyDatabaseRecord($category, $index, $clock['key'], 'target')['changed'])->toBeTrue();
    $track = $session->describeCutsceneTimeline($category, $index)['tracks'][0]['art']['pivotKey']['frame'];
    $apply = static function (string $field, string $value) use ($session, $category, $index, $track): void {
        $row = array_find($session->readDatabaseRecord($category, $index, $track)['rows'],
            static fn(array $entry): bool => ($entry['key']['field'] ?? null) === 'track0' . $field);
        expect($row)->not->toBeNull();
        expect($session->applyDatabaseRecord($category, $index, $row['key'], $value)['changed'])->toBeTrue();
    };
    $apply('Type', 'tether');
    $apply('Grip', 'grip');
    $apply('Head', $head);
    $apply('HeadPivot', '0.5, 0.5');
    $apply('Links', $links);
    $apply('HeadHeight', '12');
    $apply('LinkHeight', '8');
    $apply('LinkSpacing', '8');
    $apply('GuideGripOffset', '10');
    $apply('GuideHandOffset', '7');
    $apply('CurveFirstProgress', '0.29');
    $apply('CurveSecondProgress', '0.70');
    $apply('LinkDepth', 'behind');
    $type = array_find($session->readDatabaseRecord($category, $index, $track)['rows'],
        static fn(array $entry): bool => ($entry['key']['field'] ?? null) === 'track0Type');
    expect($session->addDatabaseItem($category, $index, $type['key'], child: true)['changed'])->toBeTrue();
    $sampleDuration = array_find($session->readDatabaseRecord($category, $index, $track)['rows'],
        static fn(array $entry): bool => str_ends_with($entry['key']['field'] ?? '', 'Duration'));
    expect($sampleDuration)->not->toBeNull()
        ->and($session->applyDatabaseRecord($category, $index, $sampleDuration['key'], '2')['changed'])->toBeTrue();
    $session->undo();
    $session->redo();
    expect($session->saveAll()['failures'])->toBe([]);
    $saved = require $path;
    expect($saved['presentations']['terminal'])->toBe($initial['presentations']['terminal'])
        ->and($saved['presentations']['graphical']['poseClock'])->toBe('target')
        ->and($saved['presentations']['graphical']['tracks'][0])
        ->toMatchArray(['type' => 'tether', 'head' => $head, 'links' => $links, 'grip' => 'grip', 'linkSpacing' => 8.0,
            'guideGripOffset' => 10.0, 'guideHandOffset' => 7.0, 'curveFirstProgress' => .29,
            'curveSecondProgress' => .7, 'linkDepth' => 'behind']);
    $reopened = EditorSession::open($root);
    $preview = $reopened->readEffectBattlePreview(findEffectIndex($reopened, 'dusk-slash'), 0, imageRotation: true, authoredFrame: 0);
    expect($preview['diagnostics'])->toBe([])
        ->and(array_any($preview['canvas']['images'], static fn(array $image): bool => str_contains($image['id'], '-link-0')))->toBeTrue();
});

it('previews each distinct authored crisis technique through the battle test party without activating it', function () {
    $root = effectBattlePreviewProject();
    $actorPath = $root . '/assets/Data/Actors/Kaelion.php';
    $actor = require $actorPath;
    $actor['data']['crisisAbility'] = 'surge';
    file_put_contents($actorPath, '<?php return ' . var_export($actor, true) . ';');
    $abilities = $root . '/assets/Data/crisis-abilities.php';
    $variants = [];
    foreach (['first' => 'First strike', 'changed' => 'Second strike', 'later' => 'Second strike'] as $form => $skill) {
        $variants[] = ['skill' => $skill, 'conditions' => [['type' => 'variable', 'name' => 'form', 'value' => $form]]];
    }
    file_put_contents($abilities, '<?php return ' . var_export([[
        'id' => 'surge', 'name' => 'Surge', 'hpBelowPercent' => 10, 'variants' => $variants,
    ]], true) . ';');
    writeSkillRecords($root,
        new \Ichiloto\Engine\Entities\Skills\SpecialSkill('First strike', '', '', 0, 0, animationId: 1),
        new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Second strike', '', '', 0, 0, animationId: 1));
    $before = file_get_contents($actorPath);
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'dusk-slash');
    $graphical = $session->readEffectBattlePreview($index, 0, authoredFrame: 1, binding: 2);
    $terminal = $session->readEffectBattlePreview($index, 0, presentation: EffectPresentation::TERMINAL,
        authoredFrame: 1, binding: 1);

    expect(array_column($graphical['contexts'], 'action'))->toBe(['Attack', 'First strike', 'Second strike'])
        ->and($graphical)->toMatchArray(['phase' => 'target', 'caster' => 'Kaelion', 'binding' => 2, 'diagnostics' => []])
        ->and($graphical['canvas']['images'])->not->toBeEmpty()
        ->and($terminal)->toMatchArray(['phase' => 'target', 'caster' => 'Kaelion', 'binding' => 1, 'diagnostics' => []])
        ->and($terminal['terminalLines'])->not->toBeEmpty()
        ->and(file_get_contents($actorPath))->toBe($before);
});

it('advertises group colour to the Engine only for a frame whose request asks for it', function () {
    $every = ['imageRotation' => true, 'regionBudget' => true, 'canvasGroups' => true, 'clipOpacity' => true];

    expect(EffectBattlePreview::listNegotiatedCapabilities())->toBe([])
        ->and(EffectBattlePreview::listNegotiatedCapabilities(groupColor: true))->toBe([RendererSessionConfig::CANVAS_GROUP_COLOR])
        ->and(EffectBattlePreview::listNegotiatedCapabilities(...$every))->not->toContain(RendererSessionConfig::CANVAS_GROUP_COLOR)
        // Asked for beside the others, it joins them and removes none: the session has what it had, and group colour.
        ->and(array_values(array_diff(EffectBattlePreview::listNegotiatedCapabilities(...$every, groupColor: true),
            EffectBattlePreview::listNegotiatedCapabilities(...$every))))->toBe([RendererSessionConfig::CANVAS_GROUP_COLOR])
        ->and(array_diff(EffectBattlePreview::listNegotiatedCapabilities(...$every), EffectBattlePreview::listNegotiatedCapabilities(...$every, groupColor: true)))
        ->toBe([]);
});

it('composes a frame asked for with group colour in a session that has it, and every other frame as before', function () {
    $session = EditorSession::open(effectBattlePreviewProject());
    $index = findEffectIndex($session, 'dusk-slash');
    $frame = $session->readEffectBattlePreview($index, 0, authoredFrame: 1)['frame'];
    // The Engine's own command preview behind the session's, composed in a renderer session of exactly these capabilities.
    $preview = new ReflectionProperty($session, 'effectBattlePreviews')->getValue($session)[EffectPresentation::GRAPHICAL->value]['preview'];
    $command = new ReflectionProperty($preview, 'preview')->getValue($preview);
    $compose = static fn(array $negotiated): array => $negotiated === [] ? $command->getFrameAtIndex($frame, false)->toArray()
        : PresentationSession::runForSession(static fn(string $capability): bool => in_array($capability, $negotiated, true),
            static fn(): array => $command->getFrameAtIndex($frame, false)->toArray());
    $groups = [RendererSessionConfig::CANVAS_REGION_BUDGET, RendererSessionConfig::CANVAS_GROUPS];

    $asked = $session->readEffectBattlePreview($index, $frame, regionBudget: true, canvasGroups: true, groupColor: true);
    $alone = $session->readEffectBattlePreview($index, $frame, groupColor: true);
    $without = $session->readEffectBattlePreview($index, $frame, regionBudget: true, canvasGroups: true);
    $plain = $session->readEffectBattlePreview($index, $frame);

    expect(array_intersect_key($asked, $compose($groups)))->toBe($compose([...$groups, RendererSessionConfig::CANVAS_GROUP_COLOR]))
        ->and(array_intersect_key($alone, $compose([])))->toBe($compose([RendererSessionConfig::CANVAS_GROUP_COLOR]))
        ->and(array_intersect_key($without, $compose($groups)))->toBe($compose($groups))
        ->and(array_intersect_key($plain, $compose([])))->toBe($compose([]));
});

it('reads the group colour request from the host route beside the other drawing features', function () {
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array => $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params]));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => effectBattlePreviewProject()]);
    $index = array_find_key($request('database.records', ['category' => 'cutscenes/effect'])['result']['records'],
        static fn(mixed $label): bool => str_contains(strval($label), 'dusk-slash'));
    $preview = static fn(array $features): array => $request('cutscenes.effectBattlePreview',
        ['index' => $index, 'frame' => 0, 'authoredFrame' => 1, 'presentation' => 'graphical', ...$features]);
    $plain = $preview([]);

    // The flag is optional and a boolean: a frame comes back whether it is true, false, left out or anything else.
    foreach ([['groupColor' => true, 'canvasGroups' => true, 'regionBudget' => true], ['groupColor' => true], ['groupColor' => false], ['groupColor' => 'yes']] as $features) {
        $frame = $preview($features);
        expect($frame)->not->toHaveKey('error')
            ->and([$frame['result']['frame'], $frame['result']['lane'], $frame['result']['totalFrames']])
            ->toBe([$plain['result']['frame'], $plain['result']['lane'], $plain['result']['totalFrames']]);
    }
});

it('advertises qualified indicator paths to the Engine only for a frame whose request asks for them', function () {
    $every = ['imageRotation' => true, 'regionBudget' => true, 'canvasGroups' => true, 'clipOpacity' => true, 'groupColor' => true];

    expect(EffectBattlePreview::listNegotiatedCapabilities(indicatorImagePath: true))->toBe([RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH])
        ->and(EffectBattlePreview::listNegotiatedCapabilities(...$every))->not->toContain(RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH)
        // Asked for beside the others, it joins them and removes none.
        ->and(array_values(array_diff(EffectBattlePreview::listNegotiatedCapabilities(...$every, indicatorImagePath: true),
            EffectBattlePreview::listNegotiatedCapabilities(...$every))))->toBe([RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH])
        ->and(array_diff(EffectBattlePreview::listNegotiatedCapabilities(...$every), EffectBattlePreview::listNegotiatedCapabilities(...$every, indicatorImagePath: true)))
        ->toBe([]);
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('composes a frame asked for with qualified indicator paths in a session that has them, and every other frame as before', function () {
    $session = EditorSession::open(effectBattlePreviewProject());
    $index = findEffectIndex($session, 'dusk-slash');
    $frame = $session->readEffectBattlePreview($index, 0, authoredFrame: 1)['frame'];
    // The Engine's own command preview behind the session's, composed in a renderer session of exactly these capabilities.
    $preview = new ReflectionProperty($session, 'effectBattlePreviews')->getValue($session)[EffectPresentation::GRAPHICAL->value]['preview'];
    $command = new ReflectionProperty($preview, 'preview')->getValue($preview);
    $compose = static fn(array $negotiated): array => $negotiated === [] ? $command->getFrameAtIndex($frame, false)->toArray()
        : PresentationSession::runForSession(static fn(string $capability): bool => in_array($capability, $negotiated, true),
            static fn(): array => $command->getFrameAtIndex($frame, false)->toArray());
    $groups = [RendererSessionConfig::CANVAS_GROUPS];

    $asked = $session->readEffectBattlePreview($index, $frame, canvasGroups: true, indicatorImagePath: true);
    $alone = $session->readEffectBattlePreview($index, $frame, indicatorImagePath: true);
    $without = $session->readEffectBattlePreview($index, $frame, canvasGroups: true);

    expect(array_intersect_key($asked, $compose($groups)))->toBe($compose([...$groups, RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH]))
        ->and(array_intersect_key($alone, $compose([])))->toBe($compose([RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH]))
        ->and(array_intersect_key($without, $compose($groups)))->toBe($compose($groups));
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('reads the indicator path request from the host route beside the other drawing features', function () {
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array => $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params]));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => effectBattlePreviewProject()]);
    $index = array_find_key($request('database.records', ['category' => 'cutscenes/effect'])['result']['records'],
        static fn(mixed $label): bool => str_contains(strval($label), 'dusk-slash'));
    $preview = static fn(array $features): array => $request('cutscenes.effectBattlePreview',
        ['index' => $index, 'frame' => 0, 'authoredFrame' => 1, 'presentation' => 'graphical', ...$features]);
    $plain = $preview([]);

    // The flag is optional and a boolean: a frame comes back whether it is true, false, left out or anything else.
    foreach ([['indicatorImagePath' => true, 'canvasGroups' => true], ['indicatorImagePath' => true], ['indicatorImagePath' => false], ['indicatorImagePath' => 'yes']] as $features) {
        $frame = $preview($features);
        expect($frame)->not->toHaveKey('error')
            ->and([$frame['result']['frame'], $frame['result']['lane'], $frame['result']['totalFrames']])
            ->toBe([$plain['result']['frame'], $plain['result']['lane'], $plain['result']['totalFrames']]);
    }
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

/**
 * The effect battle preview project with its party to the left of its troop, so the battle turns every battler and
 * mirrors its art, and with Glint Strike, which an unarmed attack plays on its target: a stroke, a tint and a tone
 * on the target. With `$aquatic` the Regular Bat stands in water below the ground.
 */
function mirroredEffectBattleProject(bool $aquatic = false): string
{
    $root = effectBattlePreviewProject();
    file_put_contents($root . '/assets/Data/Presentation/battle.php', str_replace(
        'partySlots: [new BattleStageSlot(1050, 180, 180, 260), new BattleStageSlot(1180, 40, 180, 260)]',
        'partySlots: [new BattleStageSlot(150, 180, 180, 260), new BattleStageSlot(300, 40, 180, 260)]',
        (string) file_get_contents($root . '/assets/Data/Presentation/battle.php')));
    file_put_contents($root . '/assets/Data/troops.php', '<?php return ' . var_export([['name' => 'Pair', 'enemies' => [
        ['enemy' => 'Regular Bat', 'position' => [15, 7], 'graphicalPlacement' => ['x' => 800, 'depth' => 300, 'width' => 275, 'height' => 190]],
        ['enemy' => 'Regular Bat', 'position' => [15, 20], 'graphicalPlacement' => ['x' => 1050, 'depth' => 220, 'width' => 275, 'height' => 190]],
    ]]], true) . ';');
    writeTilesetTestPng($root . '/assets/Graphics/Battlers/Hero.png', 8, 16);
    writeTilesetTestPng($root . '/assets/Graphics/Battlers/Bat.png', 8, 16);
    file_put_contents($root . '/assets/Data/Presentation/battlers.php', '<?php return ' . var_export([
        'actors' => ['Kaelion' => ['artwork' => ['image' => 'Graphics/Battlers/Hero.png']]],
        'enemies' => ['Regular Bat' => ['artwork' => ['image' => 'Graphics/Battlers/Bat.png'],
            ...($aquatic ? ['grounding' => ['elevation' => -.25, 'contact' => 'ripple']] : [])]],
    ], true) . ';');
    @mkdir($root . '/assets/Animations/glint-strike', 0o777, true);
    file_put_contents($root . '/assets/Animations/glint-strike/glint-strike.timeline.php', <<<'PHP_SOURCE'
<?php

return [
  'fps' => 12,
  'lengthFrames' => 4,
  'tracks' => [
    ['id' => 'stroke', 'type' => 'glyph',
     'keyframes' => [['frame' => 0, 'duration' => 4, 'content' => '/', 'position' => ['x' => 0, 'y' => 0]]]],
    ['id' => 'glint', 'type' => 'battler-tint', 'anchor' => 'target', 'presentation' => 'graphical',
     'keyframes' => [['frame' => 0, 'duration' => 4, 'payload' => ['color' => ['kind' => 'rgb', 'r' => 240, 'g' => 20, 'b' => 30], 'strength' => .5]]]],
    ['id' => 'shade', 'type' => 'tone', 'anchor' => 'target', 'presentation' => 'graphical',
     'keyframes' => [['frame' => 0, 'duration' => 4, 'payload' => ['brightness' => .7]]]],
  ],
  'cues' => [],
];
PHP_SOURCE);
    file_put_contents($root . '/assets/Data/animations.php', "<?php\n\nreturn " . var_export([
        ['id' => 1, 'name' => 'Glint Strike', 'roles' => ['attack', 'attack-unarmed'], 'targetEffect' => 'glint-strike'],
    ], true) . ";\n");

    return $root;
}

/** The Engine's image of a battler in a preview frame, by the battler's art. */
function findPreviewBattlerImage(array $frame, string $asset): ?array
{
    return array_find($frame['canvas']['images'] ?? [], static fn(array $image): bool => $image['asset'] === $asset);
}

it('advertises mirrored images, mirrored masks, image tones and bottom fades to the Engine only for a frame whose request asks for each', function () {
    $flags = ['imageFlip' => RendererSessionConfig::CANVAS_IMAGE_FLIP, 'imageAlphaFlip' => RendererSessionConfig::CANVAS_IMAGE_ALPHA_FLIP,
        'imageTone' => RendererSessionConfig::CANVAS_IMAGE_TONE, 'imageBottomFade' => RendererSessionConfig::CANVAS_IMAGE_BOTTOM_FADE,
        'clipOpacity' => RendererSessionConfig::CANVAS_CLIP_OPACITY];
    $every = array_fill_keys(array_keys($flags), true);

    expect(EffectBattlePreview::listNegotiatedCapabilities())->toBe([]);
    foreach ($flags as $flag => $capability) {
        $others = array_diff_key($every, [$flag => true]);
        expect(EffectBattlePreview::listNegotiatedCapabilities(...[$flag => true]))->toBe([$capability])
            ->and(EffectBattlePreview::listNegotiatedCapabilities(...$others))->not->toContain($capability)
            ->and(array_values(array_diff(EffectBattlePreview::listNegotiatedCapabilities(...$every),
                EffectBattlePreview::listNegotiatedCapabilities(...$others))))->toBe([$capability]);
    }
});

it('composes a mirrored battler\'s tint only for a canvas that mirrors image-alpha masks, and otherwise gives the Engine\'s diagnostic', function () {
    $session = EditorSession::open(mirroredEffectBattleProject());
    $index = findEffectIndex($session, 'glint-strike');
    $read = static fn(array $features): array => $session->readEffectBattlePreview(...[$index, 0, 'authoredFrame' => 1,
        'imageFlip' => true, 'imageTone' => true, ...$features]);
    $tinted = static fn(array $frame): bool => array_any($frame['canvas']['composites'] ?? [],
        static fn(array $composite): bool => str_starts_with($composite['id'], 'command-tints-layer-'));

    $without = $read([]);
    $with = $read(['imageAlphaFlip' => true]);
    $bat = findPreviewBattlerImage($with, 'Graphics/Battlers/Bat.png');

    // The troop stands to the party's right, so the battle turns the bat toward the party and mirrors its art.
    expect($bat)->not->toBeNull()
        ->and(($bat['flipX'] ?? false) || ($bat['flipY'] ?? false))->toBeTrue()
        ->and(implode(' ', $without['diagnostics']))->toContain('cannot mirror image-alpha masks')
        ->and($tinted($without))->toBeFalse()
        ->and(array_column($without['canvas']['images'], 'id'))->toContain('arena')
        ->and($with['diagnostics'])->toBe([])
        ->and($tinted($with))->toBeTrue();
});

it('refuses a battler the battle must turn for a canvas that mirrors no image, in the Engine\'s words', function () {
    $session = EditorSession::open(mirroredEffectBattleProject());
    $index = findEffectIndex($session, 'glint-strike');

    expect(fn() => $session->readEffectBattlePreview($index, 0, authoredFrame: 1, imageAlphaFlip: true, imageTone: true))
        ->toThrow(SessionRefusal::class, 'cannot mirror a battler')
        ->and($session->readEffectBattlePreview($index, 0, authoredFrame: 1, imageFlip: true, imageAlphaFlip: true, imageTone: true)['diagnostics'])
        ->toBe([]);
});

it('tones a battler only for a canvas that tones images, and otherwise draws it untoned with the Engine\'s diagnostic', function () {
    $session = EditorSession::open(mirroredEffectBattleProject());
    $index = findEffectIndex($session, 'glint-strike');
    $read = static fn(bool $tone): array => $session->readEffectBattlePreview($index, 0, authoredFrame: 1, imageFlip: true,
        imageAlphaFlip: true, imageTone: $tone);
    $untoned = $read(false);
    $toned = $read(true);

    expect(implode(' ', $untoned['diagnostics']))->toContain('cannot apply the authored battler image tone')
        ->and(findPreviewBattlerImage($untoned, 'Graphics/Battlers/Bat.png')['brightness'] ?? 1.0)->toEqual(1.0)
        ->and(findPreviewBattlerImage($toned, 'Graphics/Battlers/Bat.png')['brightness'])->toEqual(.7)
        ->and($toned['diagnostics'])->toBe([])
        ->and($untoned['terminalLines'])->toBe($toned['terminalLines']);
});

it('fades an aquatic battler\'s bottom only for a canvas that fades image bottoms', function () {
    $session = EditorSession::open(mirroredEffectBattleProject(aquatic: true));
    $index = findEffectIndex($session, 'glint-strike');
    $read = static fn(bool $fade): array => $session->readEffectBattlePreview($index, 0, authoredFrame: 1, imageFlip: true,
        imageAlphaFlip: true, imageTone: true, imageBottomFade: $fade);
    $whole = $read(false);
    $faded = $read(true);

    expect(findPreviewBattlerImage($whole, 'Graphics/Battlers/Bat.png'))->not->toBeNull()->not->toHaveKey('bottomFade')
        ->and(findPreviewBattlerImage($faded, 'Graphics/Battlers/Bat.png')['bottomFade'] ?? null)->not->toBeNull()
        ->and($whole['diagnostics'])->toBe([])
        ->and($faded['diagnostics'])->toBe([]);
});

it('reads the mirrored image, mask, tone and bottom fade requests from the host route, and composes each for that request alone', function () {
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array => $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params]));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => mirroredEffectBattleProject(aquatic: true)]);
    $index = array_find_key($request('database.records', ['category' => 'cutscenes/effect'])['result']['records'],
        static fn(mixed $label): bool => str_contains(strval($label), 'glint-strike'));
    $preview = static fn(array $features): array => $request('cutscenes.effectBattlePreview',
        ['index' => $index, 'frame' => 0, 'authoredFrame' => 1, 'presentation' => 'graphical', ...$features]);
    $bat = static fn(array $reply): ?array => findPreviewBattlerImage($reply['result'], 'Graphics/Battlers/Bat.png');
    $every = ['imageFlip' => true, 'imageAlphaFlip' => true, 'imageTone' => true, 'imageBottomFade' => true];

    $all = $preview($every);
    expect($all)->not->toHaveKey('error')
        ->and($all['result']['diagnostics'])->toBe([])
        ->and($bat($all)['brightness'])->toEqual(.7)
        ->and($bat($all)['bottomFade'] ?? null)->not->toBeNull();
    // Only `true` asks: a flag left out, false or anything else composes the frame of a canvas without it.
    foreach ([false, 'yes', null] as $value) {
        $flip = $preview([...$every, 'imageFlip' => $value]);
        expect($flip['error']['message'] ?? '')->toContain('cannot mirror a battler');
        $mask = $preview([...$every, 'imageAlphaFlip' => $value]);
        expect(implode(' ', $mask['result']['diagnostics']))->toContain('cannot mirror image-alpha masks');
        $tone = $preview([...$every, 'imageTone' => $value]);
        expect(implode(' ', $tone['result']['diagnostics']))->toContain('cannot apply the authored battler image tone')
            ->and($bat($tone)['brightness'] ?? 1.0)->toEqual(1.0);
        $fade = $preview([...$every, 'imageBottomFade' => $value]);
        expect($bat($fade))->not->toHaveKey('bottomFade')
            ->and($fade['result']['diagnostics'])->toBe([]);
    }
});

/**
 * The effect battle preview project with Lance Beam, which an unarmed attack plays: a captured beam from the
 * caster's release point whose length past its tip runs off the battlefield's left edge, so the canvas clips it.
 */
function clippedBeamBattleProject(): string
{
    $root = effectBattlePreviewProject();
    writeTilesetTestPng($root . '/assets/Graphics/Battlers/Hero.png', 8, 16);
    writeTilesetTestPng($root . '/assets/Graphics/Battlers/Bat.png', 8, 16);
    writeTilesetTestPng($root . '/assets/Graphics/Effects/lance-beam.png', 400, 40);
    file_put_contents($root . '/assets/Data/Presentation/battlers.php', '<?php return ' . var_export([
        'actors' => ['Kaelion' => ['artwork' => ['image' => 'Graphics/Battlers/Hero.png'],
            'poses' => ['attack' => ['image' => 'Graphics/Battlers/Hero.png',
                'attachmentPoints' => [['cell' => 0, 'name' => 'release', 'point' => ['x' => .5, 'y' => .2]]]]]]],
        'enemies' => ['Regular Bat' => ['artwork' => ['image' => 'Graphics/Battlers/Bat.png']]],
    ], true) . ';');
    @mkdir($root . '/assets/Animations/lance-beam', 0o777, true);
    file_put_contents($root . '/assets/Animations/lance-beam/lance-beam.timeline.php', <<<'PHP_SOURCE'
<?php

return [
  'fps' => 24,
  'lengthFrames' => 4,
  'restFrame' => 1,
  'targetCaptureFrame' => 0,
  'targetContactSequence' => ['firstFrame' => 1, 'lastFrame' => 1, 'direction' => 'west', 'tracks' => ['hit']],
  'tracks' => [
    ['id' => 'stroke', 'type' => 'glyph',
     'keyframes' => [['frame' => 0, 'duration' => 4, 'content' => '-', 'position' => ['x' => 0, 'y' => 0]]]],
    ['id' => 'beam', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'Graphics/Effects/lance-beam.png',
     'anchor' => 'caster', 'attachmentPoint' => 'release', 'detachFrame' => 0, 'facing' => 'west', 'pivot' => ['x' => .99, 'y' => .5],
     'flight' => ['mode' => 'beam', 'route' => 'straight', 'tipX' => .5, 'arrivalFrame' => 1, 'fallback' => 'horizontal-exit'],
     'keyframes' => [['frame' => 1, 'duration' => 3]]],
    ['id' => 'hit', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'Graphics/Effects/dusk-slash.png',
     'anchor' => 'target', 'keyframes' => [['frame' => 1, 'duration' => 3]]],
  ],
  'cues' => [],
];
PHP_SOURCE);
    file_put_contents($root . '/assets/Data/animations.php', "<?php\n\nreturn " . var_export([
        ['id' => 1, 'name' => 'Lance Beam', 'roles' => ['attack', 'attack-unarmed'], 'targetEffect' => 'lance-beam'],
    ], true) . ";\n");

    return $root;
}

it('clips a captured beam only for a canvas that clips images, the one flag that negotiates clipped images', function () {
    $session = EditorSession::open(clippedBeamBattleProject());
    $index = findEffectIndex($session, 'lance-beam');
    $read = static fn(bool $clipOpacity): array => $session->readEffectBattlePreview($index, 0, authoredFrame: 1,
        imageRotation: true, clipOpacity: $clipOpacity, imageFlip: true, imageAlphaFlip: true, imageTone: true);
    $unclipped = $read(false);
    $clipped = $read(true);
    $beam = static fn(array $frame): ?array => array_find($frame['canvas']['images'] ?? [],
        static fn(array $image): bool => $image['asset'] === 'Graphics/Effects/lance-beam.png');

    expect(implode(' ', $unclipped['diagnostics']))->toContain('cannot clip the authored captured beam')
        ->and($clipped['diagnostics'])->toBe([])
        ->and($beam($clipped))->toHaveKey('clipRect');
});

it('previews each editor project’s condition effects even when another project supplies the process settings', function () {
    $effect = EditorSession::open(effectBattlePreviewProject());
    $effectIndex = findEffectIndex($effect, 'dusk-slash');
    $key = \Ichiloto\Engine\Util\Config\ProjectConfig::class;
    $previous = \Ichiloto\Engine\Util\Config\ConfigStore::has($key)
        ? \Ichiloto\Engine\Util\Config\ConfigStore::get($key) : null;
    $foreign = new \Ichiloto\Engine\Util\Config\PlaySettings(['ui' => ['battle' => [
        'conditions' => ['unknownGroup' => []],
    ]]]);
    \Ichiloto\Engine\Util\Config\ConfigStore::put($key, $foreign);
    try {
        $graphical = $effect->readEffectBattlePreview($effectIndex, 0, authoredFrame: 1);
        $terminal = $effect->readEffectBattlePreview($effectIndex, 0, presentation: EffectPresentation::TERMINAL, authoredFrame: 0);

        expect($graphical['canvas'])->not->toBeNull()
            ->and($terminal['terminalLines'])->not->toBeEmpty()
            ->and($effect->listUnsavedChanges())->toBe([])
            ->and(\Ichiloto\Engine\Util\Config\ConfigStore::get($key))->toBe($foreign);
    } finally {
        $previous === null
            ? \Ichiloto\Engine\Util\Config\ConfigStore::remove($key)
            : \Ichiloto\Engine\Util\Config\ConfigStore::put($key, $previous);
    }
});
