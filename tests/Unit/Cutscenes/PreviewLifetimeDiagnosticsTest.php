<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\PreviewField;
use Ichiloto\Editor\Cutscenes\Preview\SilentAudioBackend;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/** A synthetic project whose preview map is independent of authored production content. */
function createPreviewContractProject(string $mapKind = 'valid', bool $effect = false): string
{
    $root = $effect ? effectProject() : cutsceneProject();
    $mapId = match ($mapKind) {
        'missing' => 'missing-preview-map',
        'none' => '',
        default => 'harbour',
    };
    $file = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    $data = require $file;
    $data['startMap'] = $mapId === '' ? null : $mapId;
    $data['cast'] = [];
    file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
    file_put_contents(dirname($file) . '/harbour-lanterns.script.php',
        '<?php return ' . var_export([['type' => 'wait', 'seconds' => 1.0]], true) . ';');
    if ($effect) {
        file_put_contents($root . '/assets/Data/system.php', '<?php return ' . var_export([
            'startingPositions' => ['player' => ['destinationMap' => $mapId, 'spawnPoint' => ['x' => 2, 'y' => 3]]],
        ], true) . ';');
    }

    return $root;
}

function readPreviewContractField(EditorSession $session, bool $effect = false): PreviewField
{
    if ($effect) {
        return new ReflectionProperty($session, 'effectField')->getValue($session);
    }
    $preview = new ReflectionProperty($session, 'cinematicPreview')->getValue($session);

    return new ReflectionProperty($preview, 'field')->getValue($preview);
}

it('forwards the graphical epoch over both wire routes and refuses retired feedback', function (bool $effect) {
    $root = createPreviewContractProject(effect: $effect);
    $before = sourceHashTree($root);
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params]));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $session = new ReflectionProperty($host, 'session')->getValue($host);
    $index = $effect ? array_search('ember-spark', $session->listDatabaseRecords('cutscenes/effect')['records'], true) : 0;
    $start = $effect ? 'cutscenes.effectField' : 'cutscenes.cinematicPreview';
    $exchange = $effect ? 'cutscenes.effectFieldScene' : 'cutscenes.cinematicScene';
    try {
        $first = $request($start, ['index' => $index, 'width' => 40, 'height' => 12])['result'];
        $picture = $request($exchange, ['sessionId' => $first['sessionId'], 'events' => [previewWindowReady()]])['result'];
        expect($picture['sessionId'])->toBe($first['sessionId'])
            ->and(previewFrameGenerations($picture['messages']))->not->toBe([]);
        if ($effect) {
            $request('cutscenes.effectFieldClose');
            $current = $request($start, ['index' => $index, 'width' => 40, 'height' => 12])['result'];
        } else {
            $current = $request('cutscenes.cinematicControl', ['action' => 'restart'])['result'];
        }
        expect($current['sessionId'])->not->toBe($first['sessionId'])
            ->and($request($exchange, ['sessionId' => $first['sessionId'], 'events' => [previewWindowReady()]])['result'])
            ->toMatchArray(['sessionId' => $current['sessionId'], 'messages' => [], 'diagnostics' => []]);
        expect(previewFrameGenerations($request($exchange, ['sessionId' => $current['sessionId'], 'events' => [previewWindowReady()]])['result']['messages']))->not->toBe([]);
        expect($request($exchange, ['sessionId' => 42, 'events' => []])['error']['kind'])->toBe('request');
    } finally {
        $session->close();
    }
    expect(sourceHashTree($root))->toBe($before);
})->with(['cinematic' => [false], 'effect' => [true]]);

it('describes a stable cinematic epoch without opening a graphical host and uses it for the exchange', function () {
    $root = createPreviewContractProject();
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    try {
        $started = $session->startCinematicPreview(0, 40, 12);
        $field = readPreviewContractField($session);
        expect($started['sessionId'])->toBeString()->not->toBe('')
            ->and($started['diagnostics'])->toBe([])
            ->and(new ReflectionProperty($field, 'sceneHost')->getValue($field))->toBeNull();
        foreach (['play', 'tick', 'pause'] as $action) {
            expect($session->controlCinematicPreview($action, 0.1)['sessionId'])->toBe($started['sessionId']);
        }
        expect($session->exchangeCinematicScene([]))->toMatchArray(['sessionId' => $started['sessionId'], 'diagnostics' => [], 'messages' => []])
            ->and(new ReflectionProperty($field, 'sceneHost')->getValue($field))->toBeNull();
        $answer = $session->exchangeCinematicScene([previewWindowReady()], $started['sessionId']);
        expect($answer['sessionId'])->toBe($started['sessionId'])
            ->and(previewFrameGenerations($answer['messages']))->not->toBe([])
            ->and(new ReflectionProperty($field, 'sceneHost')->getValue($field)->sessionId)->toBe($started['sessionId']);
        expect(fn() => $session->exchangeCinematicScene(['not json'], $started['sessionId']))->toThrow(SessionRefusal::class);
        expect(ConfigStore::get(ProjectConfig::class)->get('audio.music'))->toBeFalse()
            ->and(ConfigStore::get(ProjectConfig::class)->get('audio.sfx'))->toBeFalse()
            ->and(ConfigStore::get(ProjectConfig::class)->get('audio.voice'))->toBeFalse()
            ->and(ConfigStore::get(ProjectConfig::class)->get('save.autosave'))->toBeFalse();
        $backends = new ReflectionProperty($field->scene->previewSceneManager->game->audioManager, 'backends')
            ->getValue($field->scene->previewSceneManager->game->audioManager);
        expect($backends)->toHaveCount(1)->and($backends[0])->toBeInstanceOf(SilentAudioBackend::class);
    } finally {
        $session->close();
    }
    expect(sourceHashTree($root))->toBe($before);
});

it('retires the same-grid cinematic epoch at restart before exchange and requires fresh READY', function () {
    $root = createPreviewContractProject();
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    try {
        $started = $session->startCinematicPreview(0, 40, 12);
        $old = $session->exchangeCinematicScene([previewWindowReady()], $started['sessionId']);
        $generation = max(previewFrameGenerations($old['messages']));
        $session->controlCinematicPreview('play');
        $session->controlCinematicPreview('tick', 0.1);
        $restart = $session->controlCinematicPreview('restart');
        expect($restart['sessionId'])->toBeString()->not->toBe('')->not->toBe($started['sessionId'])
            ->and($restart['elapsed'])->toBe(0.0)
            ->and(new ReflectionProperty(readPreviewContractField($session), 'sceneHost')
                ->getValue(readPreviewContractField($session)))->toBeNull();
        $pending = $session->exchangeCinematicScene([json_encode(['protocol' => 2, 'type' => 'frame_ack',
            'generation' => $generation, 'frame' => 1, 'presented' => true])], $started['sessionId']);
        expect($pending)->toMatchArray(['sessionId' => $restart['sessionId'], 'grid' => $old['grid'], 'messages' => []]);
        $fresh = $session->exchangeCinematicScene([previewWindowReady()], $restart['sessionId']);
        $frames = array_values(array_filter($fresh['messages'], static fn(array $message): bool => $message['type'] === 'frame'));
        expect($fresh['sessionId'])->toBe($restart['sessionId'])->and($frames)->not->toBe([])
            ->and($frames[0]['payload']['reset'])->toBeTrue()
            ->and($session->startCinematicPreview(0, 40, 12, keep: true)['sessionId'])->toBe($restart['sessionId']);
        $replacement = $session->startCinematicPreview(0, 40, 12);
        expect($replacement['sessionId'])->not->toBe($restart['sessionId']);
    } finally {
        $session->close();
    }
    expect(sourceHashTree($root))->toBe($before);
});

it('rotates the shared field epoch on actual detach and resize but not a repeated detach without a host', function () {
    $root = createPreviewContractProject();
    $before = sourceHashTree($root);
    $field = PreviewField::open($root, 'harbour', new Vector2(2, 3), 40, 12, static fn(): float => 0.0);
    try {
        $initial = $field->sessionId;
        $field->detachScene();
        expect($field->sessionId)->toBe($initial);
        $field->exchangeScene([previewWindowReady()], $field->sessionId);
        $field->detachScene();
        $detached = $field->sessionId;
        expect($detached)->not->toBe($initial)
            ->and($field->scene->getPresentationContext())->toBeNull()
            ->and(count($field->frame()))->toBe(12);
        $field->detachScene();
        expect($field->sessionId)->toBe($detached);
        $field->exchangeScene([], $field->sessionId);
        expect($field->sessionId)->toBe($detached);
        $field->exchangeScene([previewWindowReady()], $field->sessionId);
        $field->resize(40, 12);
        $resized = $field->sessionId;
        expect($resized)->not->toBe($detached)->and($field->getSceneGrid()->columns)->toBe(40);
        $field->resize(50, 16);
        expect($field->sessionId)->not->toBe($resized)->and(count($field->frame()))->toBe(16);
    } finally {
        $field->dispose();
    }
    expect(sourceHashTree($root))->toBe($before);
});

it('reports the detached cinematic epoch in controls before reopening the same-grid host', function () {
    $session = EditorSession::open(createPreviewContractProject());
    try {
        $first = $session->startCinematicPreview(0, 40, 12);
        $session->exchangeCinematicScene([previewWindowReady()], $first['sessionId']);
        $session->detachCinematicScene();
        $detached = $session->controlCinematicPreview('pause');
        expect($detached['sessionId'])->not->toBe($first['sessionId'])
            ->and($session->exchangeCinematicScene([]))->toMatchArray(['sessionId' => $detached['sessionId'], 'messages' => []])
            ->and(previewFrameGenerations($session->exchangeCinematicScene([previewWindowReady()], $detached['sessionId'])['messages']))->not->toBe([]);
    } finally {
        $session->close();
    }
});

it('shares effect controls and exchanges identity across seeks and replaces it on same-grid reopen or resize', function () {
    $root = createPreviewContractProject(effect: true);
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    $index = array_search('ember-spark', $session->listDatabaseRecords('cutscenes/effect')['records'], true);
    try {
        $first = $session->showEffectOnField($index, 0, 40, 12);
        $field = readPreviewContractField($session, true);
        expect($first['sessionId'])->toBeString()->not->toBe('')
            ->and(new ReflectionProperty($field, 'sceneHost')->getValue($field))->toBeNull()
            ->and($session->exchangeEffectFieldScene([]))->toMatchArray(['sessionId' => $first['sessionId'], 'messages' => []]);
        $session->exchangeEffectFieldScene([previewWindowReady()], $first['sessionId']);
        expect($session->showEffectOnField($index, 4, 40, 12)['sessionId'])->toBe($first['sessionId']);
        $session->closeEffectField();
        $reopened = $session->showEffectOnField($index, 0, 40, 12);
        expect($reopened['sessionId'])->not->toBe($first['sessionId'])
            ->and($session->exchangeEffectFieldScene([]))->toMatchArray(['sessionId' => $reopened['sessionId'], 'messages' => []]);
        $session->exchangeEffectFieldScene([previewWindowReady()], $reopened['sessionId']);
        $resized = $session->showEffectOnField($index, 0, 50, 16);
        expect($resized['sessionId'])->not->toBe($reopened['sessionId'])
            ->and($session->exchangeEffectFieldScene([]))->toMatchArray(['sessionId' => $resized['sessionId'], 'messages' => []]);
        expect(fn() => $session->exchangeEffectFieldScene(['not json'], $resized['sessionId']))->toThrow(SessionRefusal::class);
    } finally {
        $session->close();
    }
    expect(sourceHashTree($root))->toBe($before);
});

it('describes current map diagnostics without hiding useful cinematic or effect terrain', function (string $mapKind, bool $effect) {
    $root = createPreviewContractProject($mapKind, $effect);
    $session = EditorSession::open($root);
    if ($mapKind === 'malformed') {
        // The workspace is already open: exercise the runtime reader, not the Editor's import refusal.
        file_put_contents($root . '/assets/Maps/harbour/harbour.data.php', '<?php return "not map data";');
    }
    $before = sourceHashTree($root);
    try {
        $answer = $effect
            ? $session->showEffectOnField(array_search('ember-spark', $session->listDatabaseRecords('cutscenes/effect')['records'], true), 0, 40, 12)
            : $session->startCinematicPreview(0, 40, 12);
        expect($answer['diagnostics'])->toBeArray()->and(array_is_list($answer['diagnostics']))->toBeTrue()
            ->and($answer['lines'])->toHaveCount(12)->and($answer['sessionId'])->toBeString()->not->toBe('');
        if ($mapKind === 'valid') {
            expect($answer['diagnostics'])->toBe([]);
        } else {
            expect($answer['diagnostics'])->not->toBe([]);
            foreach ($answer['diagnostics'] as $diagnostic) {
                expect($diagnostic)->toBeString()->not->toBe('');
            }
            $diagnostic = implode('\n', $answer['diagnostics']);
            expect($diagnostic)->toContain('undefined terrain');
            if ($mapKind !== 'none') {
                expect($diagnostic)->toContain($mapKind === 'missing' ? 'missing-preview-map' : 'harbour');
            } else {
                expect($diagnostic)->toContain('No map');
            }
        }
        $field = readPreviewContractField($session, $effect);
        if ($effect) {
            expect($answer['issue'])->toBe($field->mapFailure);
        } else {
            expect($answer['failure'])->toBeNull()->and($answer['finished'])->toBeFalse();
        }
        $exchange = $effect ? $session->exchangeEffectFieldScene([previewWindowReady()], $answer['sessionId'])
            : $session->exchangeCinematicScene([previewWindowReady()], $answer['sessionId']);
        expect($exchange['sessionId'])->toBe($answer['sessionId'])
            ->and($exchange['diagnostics'])->toBe($answer['diagnostics'])
            ->and(previewFrameGenerations($exchange['messages']))->not->toBe([]);
    } finally {
        $session->close();
    }
    expect(sourceHashTree($root))->toBe($before);
})->with([
    'cinematic valid' => ['valid', false], 'cinematic missing' => ['missing', false],
    'cinematic malformed' => ['malformed', false], 'cinematic no map' => ['none', false],
    'effect valid' => ['valid', true], 'effect missing' => ['missing', true],
    'effect malformed' => ['malformed', true], 'effect no map' => ['none', true],
]);

it('replaces boot map failures through actual cinematic transfers and clears them on recovery without changing host identity', function () {
    $root = createPreviewContractProject('missing');
    $script = [
        ['type' => 'wait', 'seconds' => 0.1],
        ['type' => 'transfer', 'map' => 'second-missing-map', 'x' => 2, 'y' => 3],
        ['type' => 'wait', 'seconds' => 0.1],
        ['type' => 'transfer', 'map' => 'harbour', 'x' => 2, 'y' => 3],
        ['type' => 'wait', 'seconds' => 1.0],
    ];
    file_put_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php',
        '<?php return ' . var_export($script, true) . ';');
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    try {
        $started = $session->startCinematicPreview(0, 40, 12);
        expect(implode('\n', $started['diagnostics']))->toContain('missing-preview-map');
        $session->exchangeCinematicScene([previewWindowReady()], $started['sessionId']);
        $session->controlCinematicPreview('play');
        $transferred = $session->controlCinematicPreview('tick', 0.1);
        expect(implode('\n', $transferred['diagnostics']))->toContain('second-missing-map')->not->toContain('missing-preview-map')
            ->and($transferred['sessionId'])->toBe($started['sessionId']);
        $field = readPreviewContractField($session);
        $recovered = $transferred;
        for ($tick = 0; $tick < 8 && count($field->scene->transfers) < 2; $tick++) {
            $recovered = $session->controlCinematicPreview('tick', 0.1);
        }
        expect(array_column($field->scene->transfers, 'map'))->toBe(['second-missing-map', 'harbour']);
        expect($recovered['diagnostics'])->toBe([])->and($recovered['sessionId'])->toBe($started['sessionId'])
            ->and(readPreviewContractField($session)->mapFailure)->toBeNull();
    } finally {
        $session->close();
    }
    expect(sourceHashTree($root))->toBe($before);
});

it('discards same-grid retired and unqualified feedback before READY can reach a replacement host', function (bool $effect) {
    $root = createPreviewContractProject(effect: $effect);
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    $index = $effect ? array_search('ember-spark', $session->listDatabaseRecords('cutscenes/effect')['records'], true) : 0;
    $exchange = $effect ? $session->exchangeEffectFieldScene(...) : $session->exchangeCinematicScene(...);
    try {
        $old = $effect ? $session->showEffectOnField($index, 0, 40, 12) : $session->startCinematicPreview(0, 40, 12);
        $oldFrame = $exchange([previewWindowReady()], $old['sessionId']);
        if ($effect) {
            $session->closeEffectField();
            $current = $session->showEffectOnField($index, 0, 40, 12);
        } else {
            $current = $session->controlCinematicPreview('restart');
        }
        $field = readPreviewContractField($session, $effect);
        $feedback = [
            previewWindowReady(),
            json_encode(['protocol' => 2, 'type' => 'frame_ack', 'generation' => max(previewFrameGenerations($oldFrame['messages'])),
                'frame' => 1, 'presented' => true]),
            json_encode(['protocol' => 2, 'type' => 'frame_rejected', 'generation' => 1, 'expectedGeneration' => 777, 'message' => 'retired rejection']),
            json_encode(['protocol' => 2, 'type' => 'error', 'message' => 'retired view error']),
        ];
        foreach ([null, '', $old['sessionId']] as $retiredId) {
            expect($exchange([previewWindowReady()], $retiredId)['messages'])->toBe([])
                ->and(new ReflectionProperty($field, 'sceneHost')->getValue($field))->toBeNull();
            $reply = $exchange($feedback, $retiredId);
            expect($reply)->toMatchArray(['sessionId' => $current['sessionId'], 'diagnostics' => $current['diagnostics'],
                'grid' => $oldFrame['grid'], 'messages' => []])
                ->and(new ReflectionProperty($field, 'sceneHost')->getValue($field))->toBeNull();
        }
        expect($exchange([], null))->toMatchArray(['sessionId' => $current['sessionId'], 'messages' => []]);
        $exchange([], $current['sessionId']);
        expect($exchange(['not json', ...$feedback], $old['sessionId'])['messages'])->toBe([]);
        $fresh = $exchange([previewWindowReady()], $current['sessionId']);
        $frames = array_values(array_filter($fresh['messages'], static fn(array $message): bool => $message['type'] === 'frame'));
        expect($fresh['sessionId'])->toBe($current['sessionId'])->and($frames)->not->toBe([])
            ->and($frames[0]['payload']['generation'])->toBe(1)->and($frames[0]['payload']['reset'])->toBeTrue();
        expect(fn() => $exchange(['not json'], $current['sessionId']))->toThrow(SessionRefusal::class);
    } finally {
        $session->close();
    }
    expect(sourceHashTree($root))->toBe($before);
})->with(['cinematic restart' => [false], 'effect reopen' => [true]]);

it('resizes a kept cinematic through its control description while preserving playback and retiring only the host epoch', function () {
    $root = createPreviewContractProject();
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    try {
        $first = $session->startCinematicPreview(0, 40, 12, play: true);
        $session->exchangeCinematicScene([previewWindowReady()], $first['sessionId']);
        $advanced = $session->controlCinematicPreview('tick', 0.1);
        $field = readPreviewContractField($session);
        $resized = $session->startCinematicPreview(0, 50, 16, keep: true);
        expect($resized['sessionId'])->not->toBe($first['sessionId'])
            ->and($resized['elapsed'])->toBe($advanced['elapsed'])->and($resized['playing'])->toBeTrue()
            ->and($resized['lines'])->toHaveCount(16)->and(readPreviewContractField($session))->toBe($field)
            ->and(new ReflectionProperty($field, 'sceneHost')->getValue($field))->toBeNull()
            ->and($session->exchangeCinematicScene([], $first['sessionId']))->toMatchArray([
                'sessionId' => $resized['sessionId'], 'diagnostics' => [], 'messages' => [],
                'grid' => ['columns' => 50, 'rows' => 16, 'cellWidth' => 10, 'cellHeight' => 20],
            ]);
        expect($session->startCinematicPreview(0, 50, 16, keep: true)['sessionId'])->toBe($resized['sessionId']);
        $fresh = $session->exchangeCinematicScene([previewWindowReady()], $resized['sessionId']);
        expect(previewFrameGenerations($fresh['messages']))->toBe([1]);
    } finally {
        $session->close();
    }
    expect(sourceHashTree($root))->toBe($before);
});
