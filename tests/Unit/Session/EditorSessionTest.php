<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * The editor session every interface edits through: documents read as an
 * interface draws them, a paint as one undo step that a stale revision cannot
 * overwrite, history across maps, and saving through the map's transaction.
 */
function sessionCell(array $map, string $layer, int $x, int $y): string
{
    $found = array_find($map['layers'], static fn(array $candidate): bool => $candidate['id'] === $layer);

    return $found['rows'][$y][$x] ?? throw new RuntimeException("No cell {$x},{$y} on {$layer}.");
}

it('describes the project: its maps and every database category', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $project = $session->describeProject();

    expect(array_column($project['maps'], 'id'))->toContain('test-map')
        ->and($project['maps'][0])->toHaveKeys(['id', 'name', 'dirty', 'readOnly', 'revision'])
        ->and(array_column($project['databases'], 'key'))->toBe(array_map(static fn($category): string => $category->key, DatabaseCatalog::all()));
});

it('reads a map as an interface draws it: layers, glyphs, events and NPCs', function () {
    $root = makeTemporaryProject();
    $map = EditorSession::open($root)->readMap('test-map');
    $authored = ProjectWorkspace::fromProject($root)->maps[0];

    expect($map['width'])->toBe($authored->getWidth())
        ->and($map['height'])->toBe($authored->getHeight())
        ->and(array_column($map['layers'], 'id'))->toContain($map['baseLayer'], 'event')
        ->and(sessionCell($map, $map['baseLayer'], 0, 0))->toBe($authored->getLayerSymbol($map['baseLayer'], 0, 0))
        ->and(array_column($map['events'], 'marker'))->toBe(array_keys($authored->getEventDefinitions()))
        ->and($map['dirty'])->toBeFalse();
});

it('paints as one undo step, undoes and redoes it, and refuses an edit made against an older revision', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $map = $session->readMap('test-map');
    $layer = $map['baseLayer'];
    $before = sessionCell($map, $layer, 2, 2);

    $result = $session->paint('test-map', $map['revision'], $layer, [[2, 2], [3, 2]], '%', 'red');
    $painted = $session->readMap('test-map');

    expect($result['status'])->toBe('applied')
        ->and($result['changed'])->toBe(2)
        ->and($result['revision'])->toBe($painted['revision'])
        ->and(sessionCell($painted, $layer, 2, 2))->toBe('%')
        ->and(array_find(array_find($painted['layers'], static fn(array $l): bool => $l['id'] === $layer)['colors'],
            static fn(array $c): bool => $c[0] === 2 && $c[1] === 2)[2] ?? null)->toBe('red')
        ->and($painted['dirty'])->toBeTrue();

    // The same request again carries a revision that is no longer current.
    expect(fn() => $session->paint('test-map', $map['revision'], $layer, [[4, 2]], '%'))
        ->toThrow(SessionRefusal::class, 'changed since revision');

    expect($session->undo())->toBe(['label' => 'Paint', 'maps' => ['test-map']])
        ->and(sessionCell($session->readMap('test-map'), $layer, 2, 2))->toBe($before);
    expect($session->redo()['maps'])->toBe(['test-map'])
        ->and(sessionCell($session->readMap('test-map'), $layer, 2, 2))->toBe('%');
    expect($session->undo()['label'])->toBe('Paint')
        ->and($session->undo())->toBe(['label' => null, 'maps' => []]);
});

it('saves a painted map through its transaction, so a fresh load sees the edit', function () {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $session->paint('test-map', $map['revision'], $map['baseLayer'], [[1, 1]], '#');

    $saved = $session->saveMap('test-map');

    expect($saved['saved'])->toBe('test-map')
        ->and($session->hasUnsavedChanges())->toBeFalse()
        ->and(ProjectWorkspace::fromProject($root)->maps[0]->getLayerSymbol($map['baseLayer'], 1, 1))->toBe('#');
});

it('refuses an unknown map or layer with a reason an author reads', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $map = $session->readMap('test-map');

    expect(fn() => $session->readMap('no-such-map'))->toThrow(SessionRefusal::class, 'There is no map no-such-map.')
        ->and(fn() => $session->paint('test-map', $map['revision'], 'no-layer', [[0, 0]], '#'))
            ->toThrow(SessionRefusal::class, 'test-map has no layer no-layer.');
});

it('serves the session over its line protocol, answering refusals and bad requests by kind', function () {
    $root = makeTemporaryProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]));

    expect($request(1, 'maps.list')['error']['kind'])->toBe('request')
        ->and($request(2, 'hello', ['protocol' => 99, 'project' => $root])['error']['message'])->toContain('protocol 1')
        ->and($host->handle('not json')['error']['kind'])->toBe('request');

    $hello = $request(3, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    expect($hello['id'])->toBe(3)
        ->and($hello['result']['protocol'])->toBe(SessionHost::PROTOCOL)
        ->and(array_column($hello['result']['project']['maps'], 'id'))->toContain('test-map');

    $map = $request(4, 'map.read', ['map' => 'test-map'])['result'];
    expect($request(5, 'map.paint', ['map' => 'test-map', 'revision' => $map['revision'], 'layer' => $map['baseLayer'],
        'cells' => [[0, 0]], 'symbol' => '%'])['result']['status'])->toBe('applied')
        ->and($request(6, 'map.paint', ['map' => 'test-map', 'revision' => $map['revision'], 'layer' => $map['baseLayer'],
            'cells' => [[0, 0]], 'symbol' => '#'])['error']['kind'])->toBe('refusal')
        ->and($request(7, 'map.paint', ['map' => 'test-map', 'revision' => 1, 'layer' => 'tile', 'cells' => [[0]], 'symbol' => '#'])['error'])
            ->toBe(['kind' => 'request', 'message' => '"cells" must be a list of [x, y] integer pairs.'])
        ->and($request(8, 'no.such')['error']['message'])->toBe('Unknown method "no.such".')
        ->and($request(9, 'history.undo')['result']['maps'])->toBe(['test-map']);
});
