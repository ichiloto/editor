<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

function renameLifecycleMap(EditorSession $session, string $name): void
{
    $inspector = $session->readInspector('test-map');
    $session->applyInspector('test-map', $inspector['revision'], ['target' => 'map', 'field' => 'name'], $name);
}

function readLifecycleGlyph(array $map, string $layer, int $x, int $y): string
{
    return array_find($map['layers'], static fn(array $entry): bool => $entry['id'] === $layer)['rows'][$y][$x];
}

it('duplicates all current layers and unsaved tiles without changing the original or its undo history', function () {
    $root = mapGraphicsProject();
    $original = $root . '/assets/Maps/test-map';
    $before = sourceHashTree($original);
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $session->paint('test-map', $map['revision'], $map['baseLayer'], [[0, 0]], '#');
    $session->paintTiles('test-map', $session->readMap('test-map')['revision'], 'floor', [[1, 1]], 5);
    $session->createMap('Cellar', null, 3, 3);
    $other = $session->readMap('cellar');
    $session->paint('cellar', $other['revision'], $other['baseLayer'], [[0, 0]], '%');

    $created = $session->duplicateMap('test-map', $session->readMap('test-map')['revision']);
    $copy = $session->readMap($created['map']);
    expect($created['map'])->toBe('test-map-copy')
        ->and(array_column($created['maps'], 'id'))->toContain('test-map', 'test-map-copy', 'cellar')
        ->and($copy['dirty'])->toBeFalse()
        ->and(readLifecycleGlyph($copy, $copy['baseLayer'], 0, 0))->toBe('#')
        ->and(readTileRows($root . '/assets/Maps/test-map-copy/graphics/01.floor.tiles.php')[1][1])->toBe(5)
        ->and(array_column($copy['layers'], 'id'))->toBe(array_column($map['layers'], 'id'))
        ->and(file_get_contents($root . '/assets/Maps/test-map-copy/test-map-copy.data.php'))->toContain('// Authored metadata remains authored.')
        ->and(file_get_contents($root . '/assets/Maps/test-map-copy/graphics/01.floor.tiles.php'))->toContain('// Painted in the GUI editor.')
        ->and(sourceHashTree($original))->toBe($before)
        ->and($session->readMap('test-map')['dirty'])->toBeTrue()
        ->and($session->readMap('cellar')['dirty'])->toBeTrue()
        ->and($session->undo()['maps'])->toBe(['cellar'])
        ->and($session->undo()['label'])->toBe('Place tiles')
        ->and(readTileRows($root . '/assets/Maps/test-map-copy/graphics/01.floor.tiles.php')[1][1])->toBe(5)
        ->and($session->duplicateMap('test-map', $session->readMap('test-map')['revision'])['map'])->toBe('test-map-copy-2');
});

it('refuses stale copy requests without writing any files', function () {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $session->paint('test-map', $map['revision'], $map['baseLayer'], [[0, 0]], '#');
    $before = sourceHashTree($root);
    expect(fn() => $session->duplicateMap('test-map', $map['revision']))->toThrow(SessionRefusal::class, 'changed since revision')
        ->and(fn() => $session->duplicateMap('missing', 0))->toThrow(SessionRefusal::class, 'There is no map')
        ->and(sourceHashTree($root))->toBe($before);
});

it('asks for an explicit derived-path move and does not move on an ordinary save', function () {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    renameLifecycleMap($session, 'Harbour Workshop');
    $map = $session->readMap('test-map');
    $before = sourceHashTree($root);
    $question = $session->moveMap('test-map', $map['revision']);
    expect($question)->toMatchArray(['status' => 'question', 'map' => 'test-map', 'revision' => $map['revision'], 'destination' => 'harbour-workshop'])
        ->and($question['warning'])->toContain('References are not migrated', 'Moving clears the undo history')
        ->and(sourceHashTree($root))->toBe($before)
        ->and(fn() => $session->moveMap('test-map', $map['revision'], confirmed: true))->toThrow(SessionRefusal::class, 'Review the proposed destination')
        ->and(fn() => $session->moveMap('test-map', $map['revision'], '../outside', true))->toThrow(SessionRefusal::class, 'derived destination changed')
        ->and($session->saveMap('test-map')['saved'])->toBe('test-map')
        ->and(is_dir($root . '/assets/Maps/harbour-workshop'))->toBeFalse();
});

it('rejects a previously reviewed move when the map or destination changed', function () {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    renameLifecycleMap($session, 'Workshop');
    $question = $session->moveMap('test-map', $session->readMap('test-map')['revision']);
    renameLifecycleMap($session, 'New Workshop');
    $before = sourceHashTree($root);
    expect(fn() => $session->moveMap('test-map', $question['revision'], $question['destination'], true))
        ->toThrow(SessionRefusal::class, 'changed since revision')
        ->and(fn() => $session->moveMap('test-map', $session->readMap('test-map')['revision'], $question['destination'], true))
            ->toThrow(SessionRefusal::class, 'derived destination changed')
        ->and(sourceHashTree($root))->toBe($before);
});

it('moves the authored map transactionally, keeps unrelated work and notes, and explicitly leaves references unchanged', function () {
    $root = mapGraphicsProject();
    $original = $root . '/assets/Maps/test-map';
    file_put_contents($original . '/notes.txt', 'Author-owned notes');
    $session = EditorSession::open($root);
    $session->createMap('Cellar', null, 3, 3);
    $data = $root . '/assets/Maps/cellar/cellar.data.php';
    file_put_contents($data, str_replace("'events' => []", "'events' => ['D' => ['class' => 'Door', 'data' => ['destinationMap' => 'test-map']]]", (string) file_get_contents($data)));
    $session = EditorSession::open($root);
    $other = $session->readMap('cellar');
    $session->paint('cellar', $other['revision'], $other['baseLayer'], [[0, 0]], '%');
    $map = $session->readMap('test-map');
    $session->paint('test-map', $map['revision'], $map['baseLayer'], [[0, 0]], '#');
    $session->paintTiles('test-map', $session->readMap('test-map')['revision'], 'floor', [[1, 1]], 5);
    renameLifecycleMap($session, 'Harbour Workshop');
    $question = $session->moveMap('test-map', $session->readMap('test-map')['revision']);
    expect($question['references'])->toBe(['event D on cellar']);

    $moved = $session->moveMap('test-map', $question['revision'], $question['destination'], true);
    $destination = $root . '/assets/Maps/harbour-workshop';
    $current = $session->readMap('harbour-workshop');
    expect($moved)->toMatchArray(['status' => 'moved', 'previousMap' => 'test-map', 'map' => 'harbour-workshop'])
        ->and(array_column($moved['maps'], 'id'))->not->toContain('test-map')
        ->and($current['dirty'])->toBeFalse()
        ->and(readLifecycleGlyph($current, $current['baseLayer'], 0, 0))->toBe('#')
        ->and(readTileRows($destination . '/graphics/01.floor.tiles.php')[1][1])->toBe(5)
        ->and(file_get_contents($destination . '/harbour-workshop.data.php'))->toContain('// Authored metadata remains authored.')
        ->and(file_get_contents($original . '/notes.txt'))->toBe('Author-owned notes')
        ->and(is_file($original . '/test-map.data.php'))->toBeFalse()
        ->and($session->readMap('cellar')['dirty'])->toBeTrue()
        ->and($session->undo())->toMatchArray(['label' => null, 'maps' => []])
        ->and(fn() => $session->readMap('test-map'))->toThrow(SessionRefusal::class, 'There is no map');
    $loaded = ProjectWorkspace::fromProject($root);
    $cellar = array_find($loaded->maps, static fn($map): bool => $map->mapId === 'cellar');
    expect($cellar->getEventDefinitions()['D']['data']['destinationMap'])->toBe('test-map');
});

it('refuses a colliding destination and keeps undo and all sources intact', function () {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    $session->createMap('Workshop', null, 3, 3);
    renameLifecycleMap($session, 'Workshop');
    $map = $session->readMap('test-map');
    $before = sourceHashTree($root);
    expect(fn() => $session->moveMap('test-map', $map['revision'], 'workshop', true))
        ->toThrow(SessionRefusal::class, 'already exists')
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->undo()['maps'])->toBe(['test-map']);
});

it('keeps an unchanged derived path as a no-op without clearing undo', function () {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $session->paint('test-map', $map['revision'], $map['baseLayer'], [[0, 0]], '#');
    $before = sourceHashTree($root);
    expect($session->moveMap('test-map', $session->readMap('test-map')['revision'])['status'])->toBe('unchanged')
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->undo()['label'])->toBe('Paint');
});

it('refuses external source edits across save, copy and move without overwriting them', function (string $member, string $operation) {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    renameLifecycleMap($session, 'Workshop');
    $path = $root . '/assets/Maps/test-map/' . $member;
    file_put_contents($path, (string) file_get_contents($path) . "\n// Changed outside the editor.\n");
    $before = sourceHashTree($root);
    $revision = $session->readMap('test-map')['revision'];
    $execute = match ($operation) {
        'save' => fn() => $session->saveMap('test-map'),
        'copy' => fn() => $session->duplicateMap('test-map', $revision),
        'move' => fn() => $session->moveMap('test-map', $revision, 'workshop', true),
    };
    expect($execute)->toThrow(SessionRefusal::class, 'changed')
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->readMap('test-map')['dirty'])->toBeTrue();
})->with(['test-map.data.php', 'layers/01.terrain.map.php', 'graphics/01.floor.tiles.php'])->with(['save', 'copy', 'move']);

it('checks source freshness even when an otherwise clean map would not need writing', function () {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    $session->readMap('test-map');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($path, (string) file_get_contents($path) . "\n// Changed outside the editor.\n");
    $before = sourceHashTree($root);
    expect(fn() => $session->saveMap('test-map'))->toThrow(SessionRefusal::class, 'changed after opening')
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->readMap('test-map')['dirty'])->toBeFalse();
});

it('exposes duplication and reviewed relocation through the session protocol', function () {
    $root = mapGraphicsProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    expect($request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');
    $map = $request('map.read', ['map' => 'test-map'])['result'];
    expect($request('map.duplicate', ['map' => 'test-map', 'revision' => 'bad'])['error']['kind'])->toBe('request')
        ->and($request('map.duplicate', ['map' => 'test-map', 'revision' => $map['revision']])['result']['map'])->toBe('test-map-copy');
    $changed = $request('inspector.apply', ['map' => 'test-map', 'revision' => $map['revision'],
        'key' => ['target' => 'map', 'field' => 'name'], 'value' => 'Workshop'])['result'];
    $question = $request('map.move', ['map' => 'test-map', 'revision' => $changed['revision']])['result'];
    expect($question['status'])->toBe('question')
        ->and($request('map.move', ['map' => 'test-map', 'revision' => $question['revision'], 'destination' => 4])['error']['kind'])->toBe('request')
        ->and($request('map.move', ['map' => 'test-map', 'revision' => $question['revision'], 'destination' => $question['destination'], 'confirm' => true])['result']['status'])->toBe('moved');
});
