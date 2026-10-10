<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/** A synthetic authored cinematic whose finalizer must be advanced, not just started. */
function createCinematicControlProject(string $policy = 'authored'): string
{
    $root = cutsceneProject();
    $file = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    $source = str_replace("'policy' => 'authored'", "'policy' => '{$policy}'", (string) file_get_contents($file));
    $source = str_replace("  'finalizer' => [", "  'finalizer' => [\n    ['type' => 'cinematic_music', 'track' => 'preview-theme', 'loop' => false, 'fadeIn' => 0.2, 'completionBehavior' => 'stop'],", $source);
    file_put_contents($file, $source);
    mkdir($root . '/assets/Audio/BGM', 0o777, true);
    file_put_contents($root . '/assets/Audio/BGM/preview-theme.ogg', '');

    return $root;
}

/** Inspect the owned isolated preview without adding a runtime state-mutation protocol. */
function readControlledCinematic(EditorSession $session): CinematicPreviewSession
{
    return new ReflectionProperty($session, 'cinematicPreview')->getValue($session);
}

it('skips a cinematic through its timed finalizer with renderer-neutral cleanup and no project writes', function (bool $graphical, bool $playing) {
    $root = createCinematicControlProject();
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    $map = $session->readMap('harbour');
    try {
        $started = $session->startCinematicPreview(0, 60, 16);
        if ($graphical) {
            $session->exchangeCinematicScene([json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => [
                'sprite_source_rect', 'graphical_canvas', 'canvas_overlay', 'canvas_clip_opacity', 'frame_viewport', 'field_motion',
            ]])], $started['sessionId']);
        }
        $preview = readControlledCinematic($session);
        if ($playing) {
            $session->controlCinematicPreview('play');
            $session->controlCinematicPreview('tick', 0.1);
        }
        expect($preview->snapshot()->values['staged actors'])->not->toBe([])
            ->and($preview->snapshot()->values['field input'])->toBe('cinematic');

        $skipped = $session->controlCinematicPreview('skip');
        expect($skipped)->toMatchArray(['status' => 'finalizing', 'playing' => true, 'finished' => false, 'failure' => null])
            ->and($skipped['terminalCanvas']['textLayers'][0]['grid'])->toMatchArray(['columns' => 60, 'rows' => 16]);
        $finalizing = $preview->snapshot()->values;
        expect(fn() => $session->controlCinematicPreview('skip'))->toThrow(SessionRefusal::class, 'the finalizer is already running')
            ->and($preview->snapshot()->values)->toBe($finalizing);

        $finished = $session->controlCinematicPreview('tick', 0.5);
        expect($finished)->toMatchArray(['status' => 'completed', 'playing' => false, 'finished' => true, 'failure' => null]);
        $values = $preview->snapshot()->values;
        expect($values['switch harbour_lanterns_seen'])->toBeTrue()
            ->and($values['story events'])->toContain('cinematic:harbour-lanterns:completed')
            ->and($values['staged actors'])->toBe([])
            ->and($values['camera']['followsPlayer'])->toBeTrue()
            ->and($values['field input'])->toBe('player')
            ->and($values['save available'])->toBeTrue()
            ->and($values['checkpoints'])->toBe([])
            ->and(fn() => $session->controlCinematicPreview('skip'))->toThrow(SessionRefusal::class, 'no cinematic is active')
            ->and($session->readMap('harbour'))->toBe($map)
            ->and($session->listUnsavedChanges())->toBe([])
            ->and(sourceHashTree($root))->toBe($before);
    } finally {
        $session->close();
    }
    expect(sourceHashTree($root))->toBe($before);
})->with([
    'terminal paused' => [false, false], 'terminal playing' => [false, true],
    'graphical paused' => [true, false], 'graphical playing' => [true, true],
]);

it('completes an immediate finalizer without leaving playback running or changing authored source', function () {
    $root = cutsceneProject();
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    try {
        $session->startCinematicPreview(0, 40, 12);
        expect($session->controlCinematicPreview('skip'))->toMatchArray(['status' => 'completed', 'finished' => true, 'playing' => false])
            ->and(readControlledCinematic($session)->snapshot()->values['switch harbour_lanterns_seen'])->toBeTrue()
            ->and($session->listUnsavedChanges())->toBe([])
            ->and(sourceHashTree($root))->toBe($before);
    } finally {
        $session->close();
    }
});

it('returns skip policy refusals over the existing protocol without replacing or advancing the isolated preview', function () {
    $root = createCinematicControlProject('forbidden');
    $before = sourceHashTree($root);
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params]));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $session = new ReflectionProperty($host, 'session')->getValue($host);
    try {
        $started = $request('cutscenes.cinematicPreview', ['index' => 0, 'width' => 40, 'height' => 12])['result'];
        $preview = readControlledCinematic($session);
        $state = $preview->snapshot()->values;
        $reply = $request('cutscenes.cinematicControl', ['action' => 'skip']);

        expect($reply['error']['kind'])->toBe('refusal')
            ->and($reply['error']['message'])->toBe('Skip refused: ' . $preview->skipRefusalReason())
            ->and($reply['error']['message'])->toContain('skip policy is "forbidden"')
            ->and(readControlledCinematic($session))->toBe($preview)
            ->and($preview->snapshot()->values)->toBe($state)
            ->and($request('cutscenes.cinematicPreview', ['index' => 0, 'width' => 40, 'height' => 12, 'keep' => true])['result'])->toBe($started)
            ->and($session->listUnsavedChanges())->toBe([])
            ->and(sourceHashTree($root))->toBe($before);
    } finally {
        $session->close();
    }
});

it('reports the Engine failure when skip is requested after a failed preview instead of hiding it behind an inactive-cinematic refusal', function () {
    $root = cutsceneProject();
    $file = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($file, "<?php\nreturn " . var_export([
        ['type' => 'wait', 'seconds' => 0.1],
        ['type' => 'move_route', 'subject' => 'staged_actor', 'actorId' => 'nobody', 'steps' => [['direction' => 'up', 'count' => 1]]],
    ], true) . ";\n");
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    try {
        $session->startCinematicPreview(0, 40, 12);
        $session->controlCinematicPreview('step');
        $result = $session->controlCinematicPreview('skip');
        expect($result)->toMatchArray(['status' => 'failed', 'finished' => true, 'playing' => false])
            ->and($result['failure'])->toBe(readControlledCinematic($session)->failure()['message'])
            ->and($result['failure'])->toContain('nobody')
            ->and($session->listUnsavedChanges())->toBe([])
            ->and(sourceHashTree($root))->toBe($before);
    } finally {
        $session->close();
    }
});

it('refuses an unsafe authored finalizer before preview launch and leaves no cinematic to skip', function (array $finalizer, string $reason) {
    $root = cutsceneProject();
    $file = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    $data = require $file;
    $data['finalizer'] = $finalizer;
    file_put_contents($file, "<?php\nreturn " . var_export($data, true) . ";\n");
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    try {
        expect(fn() => $session->startCinematicPreview(0, 40, 12))->toThrow(SessionRefusal::class, $reason)
            ->and(fn() => $session->controlCinematicPreview('skip'))->toThrow(SessionRefusal::class, 'No cinematic is being previewed.')
            ->and($session->listUnsavedChanges())->toBe([])
            ->and(sourceHashTree($root))->toBe($before);
    } finally {
        $session->close();
    }
})->with([
    'empty finalizer' => [[], 'requires an authored finalizer'],
    'unsafe command' => [[['type' => 'wait', 'seconds' => 0.2]], 'unsafe type "wait"'],
]);

it('serves semantic skip and finalization over the protocol and refuses an absent preview', function () {
    $root = createCinematicControlProject();
    $before = sourceHashTree($root);
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 7, 'method' => $method, 'params' => $params]));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $session = new ReflectionProperty($host, 'session')->getValue($host);
    try {
        $absent = ['kind' => 'refusal', 'message' => 'No cinematic is being previewed.'];
        expect($request('cutscenes.cinematicControl', ['action' => 'skip'])['error'])->toBe($absent);
        $request('cutscenes.cinematicPreview', ['index' => 0, 'width' => 40, 'height' => 12]);
        expect($request('cutscenes.cinematicControl', ['action' => 'skip'])['result'])->toMatchArray(['status' => 'finalizing', 'playing' => true])
            ->and($request('cutscenes.cinematicControl', ['action' => 'tick', 'seconds' => 0.5])['result'])
            ->toMatchArray(['status' => 'completed', 'finished' => true, 'failure' => null]);
        $request('cutscenes.cinematicStop');
        expect($request('cutscenes.cinematicControl', ['action' => 'skip'])['error'])->toBe($absent)
            ->and(sourceHashTree($root))->toBe($before);
    } finally {
        $session->close();
    }
});
