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
