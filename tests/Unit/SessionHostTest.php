<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\SessionHost;

it('exchanges renderer readiness and acknowledgements through the real preview request boundary', function (string $source) {
    $root = effectProject();
    file_put_contents($root . '/assets/Data/system.php', '<?php return ' . var_export([
        'startingPositions' => ['player' => ['destinationMap' => 'harbour',
            'spawnPoint' => ['x' => 4, 'y' => 4], 'spawnSprite' => ['^']]],
    ], true) . ';');
    $before = sourceHashTree($root);
    $input = fopen('php://memory', 'r');
    $output = fopen('php://memory', 'w+');
    $diagnostics = fopen('php://memory', 'w+');
    $host = new SessionHost($input, $output, $diagnostics);
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    $exchange = 'cutscenes.' . $source . 'Scene';
    $start = $source === 'cinematic' ? 'cutscenes.cinematicPreview' : 'cutscenes.effectField';
    $detach = $source === 'cinematic' ? 'cutscenes.cinematicSceneDetach' : 'cutscenes.effectFieldClose';

    try {
        expect($request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');
        $category = $source === 'cinematic' ? 'cutscenes/cinematic' : 'cutscenes/effect';
        $identity = $source === 'cinematic' ? 'Harbour Lanterns' : 'ember-spark';
        $index = array_search($identity, $request('database.records', ['category' => $category])['result']['records'], true);
        expect($index)->toBeInt();
        $startParams = ['index' => $index, 'frame' => 0, 'width' => 40, 'height' => 12];
        expect($request($start, $startParams))->toHaveKey('result');
        $discovery = $request($exchange)['result'];
        $sessionId = $discovery['sessionId'];
        expect($discovery['messages'])->toBe([])
            ->and($sessionId)->toBeString()->not->toBe('');

        $ready = $request($exchange, ['sessionId' => $sessionId, 'events' => [previewWindowReady()]]);
        expect($ready)->toHaveKey('result')
            ->and($ready['result']['grid'])->toBe(['columns' => 40, 'rows' => 12, 'cellWidth' => 10, 'cellHeight' => 20]);
        $messages = $ready['result']['messages'];
        $generation = max(previewFrameGenerations($messages));
        expect(array_column($messages, 'type'))->toContain('frame');
        $ack = json_encode(['protocol' => 2, 'type' => 'frame_ack',
            'generation' => $generation, 'frame' => 1, 'presented' => true], JSON_THROW_ON_ERROR);
        expect($request($exchange, ['sessionId' => $sessionId, 'events' => [$ack]])['result']['messages'])->toBe([]);

        // Malformed requests must be refused before touching the active relay.
        foreach ([null, 'not a list', [0 => previewWindowReady(), 2 => $ack], [previewWindowReady(), 7], [false]] as $events) {
            expect($request($exchange, ['sessionId' => $sessionId, 'events' => $events])['error'])->toBe([
                'kind' => 'request', 'message' => '"events" must be a list of strings.',
            ]);
        }
        expect($request($exchange, ['sessionId' => $sessionId, 'events' => []])['result']['messages'])->toBe([]);
        expect($request($detach))->toHaveKey('result');
        if ($source !== 'cinematic') {
            expect($request($start, $startParams))->toHaveKey('result');
        }
        $nextSessionId = $request($exchange)['result']['sessionId'];
        expect($nextSessionId)->not->toBe($sessionId);
        $again = $request($exchange, ['sessionId' => $nextSessionId, 'events' => [previewWindowReady()]]);
        expect($again)->toHaveKey('result');
        $frames = array_values(array_filter($again['result']['messages'], static fn(array $message): bool => $message['type'] === 'frame'));
        expect($frames)->not->toBe([])->and($frames[0]['payload']['reset'] ?? false)->toBeTrue();
        rewind($diagnostics);
        expect(stream_get_contents($diagnostics))->toBe('')
            ->and(sourceHashTree($root))->toBe($before);
    } finally {
        // EOF uses the production host teardown, including preview disposal.
        $host->run();
        fclose($input);
        fclose($output);
        fclose($diagnostics);
    }
})->with(['cinematic', 'effectField']);
