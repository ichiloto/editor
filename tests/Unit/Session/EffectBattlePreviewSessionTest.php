<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;

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

it('says so when no action of the battle test party plays an effect, inventing none', function () {
    $session = EditorSession::open(effectBattlePreviewProject());

    expect(fn() => $session->readEffectBattlePreview(findEffectIndex($session, 'ember-spark'), 0))
        ->toThrow(SessionRefusal::class, 'No action of the battle test party plays ember-spark in battle');
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
