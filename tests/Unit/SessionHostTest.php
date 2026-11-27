<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Engine\Battle\UI\BattleScreen;

it('captures saved terrain for a compatibility history entry and refuses pending edits', function () {
    $root = mapLineRemovalProject();
    $input = fopen('php://memory', 'r');
    $output = fopen('php://memory', 'w+');
    $diagnostics = fopen('php://memory', 'w+');
    $host = new SessionHost($input, $output, $diagnostics);
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    try {
        expect($request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');
        $before = sourceHashTree($root);
        $capture = $request('saveCompatibility.captureLayout')['result'];
        expect($capture['version'])->toBe(0)
            ->and($capture['maps']['home'])->toContain([1, 2])
            ->and($capture['maps']['home'])->not->toContain([2, 2])
            ->and(sourceHashTree($root))->toBe($before);

        $map = $host->session->readMap('home');
        $host->session->paint('home', $map['revision'], $map['baseLayer'], [[1, 2]], '#');
        expect($request('saveCompatibility.captureLayout')['error']['kind'])->toBe('refusal')
            ->and(sourceHashTree($root))->toBe($before);
    } finally {
        $host->run();
        fclose($input);
        fclose($output);
        fclose($diagnostics);
    }
});

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
    // A newly attached window's whole first frame for an epoch, every exchange of it answered with a result.
    $draw = static fn(string $sessionId): array => exchangeSceneUntilPresented(static function (array $events) use ($request, $exchange, $sessionId): array {
        $reply = $request($exchange, ['sessionId' => $sessionId, 'events' => $events]);
        expect($reply)->toHaveKey('result');

        return $reply['result'];
    }, [previewWindowReady()]);

    try {
        expect($request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');
        $category = $source === 'cinematic' ? 'cutscenes/cinematic' : 'cutscenes/effect';
        $identity = $source === 'cinematic' ? 'Harbour Lanterns' : 'ember-spark';
        $index = array_search($identity, $request('database.records', ['category' => $category])['result']['records'], true);
        expect($index)->toBeInt();
        $startParams = ['index' => $index, 'frame' => 0, 'width' => 40, 'height' => 12];
        $started = $request($start, $startParams);
        expect($started)->toHaveKey('result');
        $discovery = $request($exchange)['result'];
        $sessionId = $discovery['sessionId'];
        expect($discovery['messages'])->toBe([])
            ->and($sessionId)->toBeString()->not->toBe('');

        $ready = $draw($sessionId);
        $graphicalGrid = $source === 'cinematic'
            ? ['columns' => BattleScreen::WIDTH, 'rows' => BattleScreen::HEIGHT]
            : ['columns' => 40, 'rows' => 12];
        expect($discovery['grid'])->toBe($graphicalGrid + ['cellWidth' => 10, 'cellHeight' => 20])
            ->and($ready['grid'])->toBe($discovery['grid']);
        $messages = $ready['messages'];
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
        if ($source === 'cinematic') {
            $terminal = $request($start, $startParams + ['keep' => true])['result'];
            expect($terminal['terminalCanvas'])->toBe($started['result']['terminalCanvas'])
                ->and($terminal['elapsed'])->toBe($started['result']['elapsed']);
        }
        if ($source !== 'cinematic') {
            expect($request($start, $startParams))->toHaveKey('result');
        }
        $nextSessionId = $request($exchange)['result']['sessionId'];
        expect($nextSessionId)->not->toBe($sessionId);
        $again = $draw($nextSessionId);
        $frames = array_values(array_filter($again['messages'], static fn(array $message): bool => $message['type'] === 'frame'));
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

it('names the Engine tool theme file before any project is open', function () {
    $input = fopen('php://memory', 'r');
    $output = fopen('php://memory', 'w+');
    $diagnostics = fopen('php://memory', 'w+');
    $host = new SessionHost($input, $output, $diagnostics);

    try {
        $answer = $host->handle(json_encode(['id' => 7, 'method' => 'tools.theme', 'params' => []], JSON_THROW_ON_ERROR));
        expect($answer['id'])->toBe(7)
            ->and($answer['result']['path'])->toBe(realpath(Ichiloto\Engine\UI\Presentation\ToolTheme::FILE));
        // The file named is the one the Engine's own tool screens load.
        Ichiloto\Engine\UI\Presentation\ToolTheme::load($answer['result']['path']);
        expect($host->session)->toBeNull();
        rewind($diagnostics);
        expect(stream_get_contents($diagnostics))->toBe('');
    } finally {
        fclose($input);
        fclose($output);
        fclose($diagnostics);
    }
});
