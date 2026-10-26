<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/** The GUI's removal path: review, confirm with the reviewed landing, undo. The fixture is {@see mapLineRemovalProject()}. */

it('reviews a removal without writing, names the save landing, and writes it once confirmed as one undo step', function () {
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    $revision = $session->readMap('home')['revision'];
    $before = sourceHashTree($root);

    $question = $session->removeMapLines('home', $revision, 'y', 2, 1);
    expect($question)->toMatchArray(['status' => 'question', 'map' => 'home', 'axis' => 'y', 'at' => 2, 'count' => 1,
        'landing' => [2, 1], 'width' => 6, 'height' => 4])
        ->and($question['question'])->toBe('Remove 1 row from row 3 of home?')
        ->and($question['notes'])->toContain('Saves standing on the removed lines land at x 2, y 1.')
        ->and($question['paths'])->toContain('assets/Maps/home/layers/01.terrain.map.php', 'assets/Data/save-compatibility.php')
        ->and(sourceHashTree($root))->toBe($before);

    $written = $session->removeMapLines('home', $revision, 'y', 2, 1, $question['landing'], 'write', $question['confirm']);
    expect($written)->toMatchArray(['status' => 'removed', 'map' => 'home', 'changed' => true])
        ->and($session->readMap('home')['height'])->toBe(4);

    $session->undo();
    expect(sourceHashTree($root))->toBe($before)
        ->and($session->readMap('home')['height'])->toBe(5);
});

it('refuses a removal that something authored stands on, and one the review did not cover', function () {
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    $revision = $session->readMap('home')['revision'];
    $before = sourceHashTree($root);

    expect(fn() => $session->removeMapLines('home', $revision, 'y', 4, 1))->toThrow(SessionRefusal::class, 'is refused; nothing was changed.');
    $question = $session->removeMapLines('home', $revision, 'y', 2, 1);
    expect(fn() => $session->removeMapLines('home', $revision, 'y', 2, 1, [3, 2], 'write', $question['confirm']))->toThrow(SessionRefusal::class)
        ->and(sourceHashTree($root))->toBe($before);
});

it('routes map.removeLines through the host with an optional landing', function () {
    $root = mapLineRemovalProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w+'), fopen('php://memory', 'w+'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    expect($request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');
    $revision = $request('map.read', ['map' => 'home'])['result']['revision'];

    expect($request('map.removeLines', ['map' => 'home', 'revision' => $revision, 'axis' => 'y', 'at' => 2, 'count' => 1, 'landing' => [3, 2]])['result'])
        ->toMatchArray(['status' => 'question', 'landing' => [3, 2]]);
});

it('moves no save when the player reaches the map nowhere, such as one not yet joined to the world', function () {
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    $session->createMap('Far field', null, 6, 5, 'far-field');
    $manifest = (string) file_get_contents($root . '/assets/Data/save-compatibility.php');
    $revision = $session->readMap('far-field')['revision'];

    $question = $session->removeMapLines('far-field', $revision, 'x', 0, 2);
    expect($question)->toMatchArray(['status' => 'question', 'landing' => null])
        ->and($question['notes'])->toContain('No save moves: the player reaches this map nowhere today.')
        ->and($question['paths'])->not->toContain('assets/Data/save-compatibility.php');

    $session->removeMapLines('far-field', $revision, 'x', 0, 2, null, 'write', $question['confirm']);
    expect($session->readMap('far-field')['width'])->toBe(4)
        ->and((string) file_get_contents($root . '/assets/Data/save-compatibility.php'))->toBe($manifest);
});
