<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\Presentation\BattleTargetContactSequence;

it('authors contacts using the graphical battle renderer and keeps the paired Terminal source intact', function (): void {
    $root = effectBattlePreviewProject();
    $id = 'dusk-slash';
    $path = effectTimelinePath($root, $id);
    $timeline = require $path;
    $graphical = &$timeline['presentations']['graphical'];
    $graphical['lengthFrames'] = 12;
    $graphical['restFrame'] = 2;
    $graphical['effectTiming'] = ['mode' => 'frame', 'frame' => 2];
    $graphical['tracks'][0]['presentation'] = EffectPresentation::GRAPHICAL->value;
    $graphical['tracks'][0]['keyframes'] = [['frame' => 2, 'duration' => 2, 'sourceFrame' => 0]];
    $graphical['tracks'][] = [...$graphical['tracks'][0], 'id' => 'contact-debris',
        'keyframes' => [['frame' => 3, 'sourceFrame' => 1]]];
    $screen = [...$graphical['tracks'][0], 'id' => 'screen-light', 'anchor' => 'screen',
        'keyframes' => [['frame' => 0, 'sourceFrame' => 0]]];
    unset($screen['facing']);
    $graphical['tracks'][] = $screen;
    unset($graphical);
    $before = '<?php return ' . var_export($timeline, true) . ';';
    file_put_contents($path, $before);

    $session = EditorSession::open($root);
    $category = 'cutscenes/effect';
    $index = findEffectIndex($session, $id);
    $record = ['category' => $category, 'index' => $index];
    $field = BattleTargetContactSequence::FIELD;
    $contactRows = static fn(EditorSession $session): array => array_filter(
        array_column(array_column($session->readDatabaseRecord($category, $index)['rows'], 'key'), null, 'field'),
        static fn(string $key): bool => str_starts_with($key, $field . '.'),
        ARRAY_FILTER_USE_KEY,
    );
    try {
        $session->selectCutscenePresentation($category, $index, EffectPresentation::TERMINAL->value);
        expect($contactRows($session))->toBe([])
            ->and($session->listReferences(null, ReferenceCatalog::EFFECT_GRAPHICAL_CONTACT_TRACKS, $record))->toBe([]);

        $session->selectCutscenePresentation($category, $index, EffectPresentation::GRAPHICAL->value);
        $rows = $contactRows($session);
        expect(array_keys($rows))->toBe([
            $field . '.direction', $field . '.intervalFrames', $field . '.firstFrame', $field . '.lastFrame',
            $field . '.presentResults', $field . '.tracks',
        ])->and(array_column($session->listReferences(null, ReferenceCatalog::EFFECT_GRAPHICAL_CONTACT_TRACKS, $record), 'value'))
            ->toBe(['stroke-art', 'contact-debris']);
        expect(array_find($session->readDatabaseRecord($category, $index)['rows'],
            static fn(array $row): bool => ($row['key']['field'] ?? null) === $field . '.tracks'))
            ->toMatchArray(['kind' => 'reference', 'multi' => true]);
        expect(fn() => $session->applyDatabaseRecord($category, $index, $rows[$field . '.tracks'], 'screen-light'))
            ->toThrow(SessionRefusal::class, 'choose one from the picker.');

        $values = ['direction' => 'west', 'firstFrame' => '2', 'lastFrame' => '11', 'tracks' => 'stroke-art, contact-debris'];
        $changes = [];
        foreach ($values as $name => $value) {
            $changes[] = ['key' => $rows[$field . '.' . $name], 'value' => $value];
        }
        expect($session->applyDatabaseRecordValues($category, $index, $changes, 'Set contact sequence')['changed'])->toBeTrue();
        expect(fn() => $session->saveDatabase($category))->toThrow(RuntimeException::class, 'final target delay');
        expect(file_get_contents($path))->toBe($before);
        expect($session->applyDatabaseRecord($category, $index, $rows[$field . '.lastFrame'], '4')['changed'])->toBeTrue()
            ->and($session->saveDatabase($category)['saved'])->toBeTrue();
    } finally {
        $session->close();
    }

    $saved = file_get_contents($path);
    expect(explode("'graphical' =>", $saved, 2)[0])->toBe(explode("'graphical' =>", $before, 2)[0]);
    $expected = ['firstFrame' => 2, 'lastFrame' => 4, 'direction' => 'west', 'tracks' => ['stroke-art', 'contact-debris']];
    $effect = ProjectWorkspace::fromProject($root)->cutscenes->find(CutsceneType::EFFECT, $id);
    expect($effect->compileEffect(EffectPresentation::GRAPHICAL, true)->defaults[$field])->toBe($expected)
        ->and($effect->compileEffect(EffectPresentation::TERMINAL, true)->defaults)->not->toHaveKey($field);

    $reopened = EditorSession::open($root);
    try {
        $reopened->selectCutscenePresentation($category, $index, EffectPresentation::GRAPHICAL->value);
        $savedRows = $contactRows($reopened);
        $preview = $reopened->readEffectBattlePreview($index, 0, authoredFrame: 2);
        expect($preview['phase'])->toBe('target')
            ->and(array_find($preview['canvas']['images'], static fn(array $image): bool =>
                $image['asset'] === 'Graphics/Effects/dusk-slash.png'))->not->toBeNull();
        expect($reopened->applyDatabaseRecord($category, $index, $savedRows[$field . '.direction'], '')['changed'])->toBeTrue();
        $reopened->undo();
        expect($reopened->saveDatabase($category)['saved'])->toBeFalse()
            ->and(file_get_contents($path))->toBe($saved);
        $reopened->redo();
        expect($reopened->saveDatabase($category)['saved'])->toBeTrue()
            ->and((require $path)['presentations']['graphical'])->not->toHaveKey($field);
        $reopened->undo();
        expect($reopened->applyDatabaseRecord($category, $index, $savedRows[$field . '.intervalFrames'], '2')['changed'])->toBeTrue()
            ->and($reopened->applyDatabaseRecord($category, $index, $savedRows[$field . '.presentResults'], 'true')['changed'])->toBeTrue()
            ->and($reopened->saveDatabase($category)['saved'])->toBeTrue();
        $staggered = ProjectWorkspace::fromProject($root)->cutscenes->find(CutsceneType::EFFECT, $id);
        expect($staggered->compileEffect(EffectPresentation::GRAPHICAL, true)->defaults[$field])->toBe([
            'firstFrame' => 2, 'lastFrame' => 4, 'intervalFrames' => 2, 'tracks' => ['stroke-art', 'contact-debris'], 'presentResults' => true,
        ]);
        $preview = $reopened->readEffectBattlePreview($index, 0, authoredFrame: 2);
        expect(array_find($preview['canvas']['images'], static fn(array $image): bool =>
            $image['asset'] === 'Graphics/Effects/dusk-slash.png'))->not->toBeNull();
        expect(explode("'graphical' =>", file_get_contents($path), 2)[0])->toBe(explode("'graphical' =>", $before, 2)[0]);
    } finally {
        $reopened->close();
    }
});

it('offers the authored contact preview example only with contact results and clears it with its owner', function (): void {
    $root = effectBattlePreviewProject();
    $path = effectTimelinePath($root, 'dusk-slash');
    $timeline = require $path;
    $graphical = &$timeline['presentations']['graphical'];
    $graphical['tracks'][0]['presentation'] = 'graphical';
    $graphical['tracks'][0]['keyframes'] = [['frame' => 1, 'sourceFrame' => 0]];
    $graphical['effectTiming'] = ['mode' => 'frame', 'frame' => 1];
    $graphical['targetContactSequence'] = ['firstFrame' => 1, 'lastFrame' => 1, 'intervalFrames' => 1, 'tracks' => ['stroke-art']];
    file_put_contents($path, '<?php return ' . var_export($timeline, true) . ';');

    $session = EditorSession::open($root);
    $category = 'cutscenes/effect';
    $index = findEffectIndex($session, 'dusk-slash');
    $session->selectCutscenePresentation($category, $index, 'graphical');
    $rows = static fn(): array => array_column($session->readDatabaseRecord($category, $index)['rows'], null, 'label');
    try {
        expect($rows())->not->toHaveKey('Contact Preview Pose');
        $session->applyDatabaseRecord($category, $index, $rows()['Show Results at Contact']['key'], 'true');
        expect($rows()['Contact Preview Pose']['options'])->toBe(['', 'damage', 'heal']);
        $session->applyDatabaseRecord($category, $index, $rows()['Contact Preview Pose']['key'], 'damage');
        expect($session->saveDatabase($category)['saved'])->toBeTrue();
        $saved = require $path;
        expect($saved['presentations']['graphical']['targetContactSequence']['previewReaction'])->toBe('damage')
            ->and($saved['presentations']['terminal'])->toBe($timeline['presentations']['terminal']);
        $session->applyDatabaseRecord($category, $index, $rows()['Show Results at Contact']['key'], 'false');
        expect($rows())->not->toHaveKey('Contact Preview Pose')
            ->and($session->saveDatabase($category)['saved'])->toBeTrue()
            ->and((require $path)['presentations']['graphical']['targetContactSequence'])->not->toHaveKey('previewReaction');
    } finally {
        $session->close();
    }
});
