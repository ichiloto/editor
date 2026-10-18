<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * A map's id is its folder under assets/Maps. Creating, copying and moving a map at an id the author chooses, nested
 * inside another map's folder or any other folder, through the session: checked before anything is written, and
 * leaving the maps around it as they were.
 */

/** A session request against a host over a fresh synthetic project. */
function mapIdentityHost(string $root): Closure
{
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    expect($request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');

    return $request;
}

it('creates a map at a chosen id inside another map\'s folder and inside folders that are no map', function () {
    $root = mapGraphicsProject();
    $parent = sourceHashTree($root . '/assets/Maps/test-map');
    $request = mapIdentityHost($root);

    $nested = $request('map.create', ['name' => 'Wine Cellar', 'width' => 3, 'height' => 2, 'id' => 'test-map/cellar'])['result'];
    $deep = $request('map.create', ['width' => 2, 'height' => 2, 'id' => 'harbour/licensing-office/front-desk'])['result'];
    $derived = $request('map.create', ['name' => 'Wine Cellar', 'width' => 2, 'height' => 2])['result'];

    expect($nested['map'])->toBe('test-map/cellar')
        ->and($deep['map'])->toBe('harbour/licensing-office/front-desk')
        // Without an id the map still goes at the root under its name's slug.
        ->and($derived['map'])->toBe('wine-cellar')
        ->and(is_file($root . '/assets/Maps/test-map/cellar/cellar.data.php'))->toBeTrue()
        ->and(is_file($root . '/assets/Maps/harbour/licensing-office/front-desk/front-desk.data.php'))->toBeTrue()
        ->and(array_column($request('maps.list')['result'], 'name', 'id'))->toMatchArray([
            'test-map/cellar' => 'Wine Cellar', 'harbour/licensing-office/front-desk' => 'Front Desk', 'test-map' => 'Test Map'])
        // The parent map's own files are untouched; only the nested folder is new.
        ->and(array_filter(sourceHashTree($root . '/assets/Maps/test-map'),
            static fn(string $path): bool => ! str_starts_with($path, 'cellar/'), ARRAY_FILTER_USE_KEY))->toBe($parent);

    // A fresh scan finds every map where it was written, the parent still whole.
    $loaded = ProjectWorkspace::fromProject($root);
    expect($loaded->mapIds)->toContain('test-map', 'test-map/cellar', 'harbour/licensing-office/front-desk', 'wine-cellar')
        ->and(array_find($loaded->maps, static fn($map): bool => $map->mapId === 'test-map')->getGridSourceIssue())->toBeNull()
        ->and(array_find($loaded->maps, static fn($map): bool => $map->mapId === 'test-map/cellar')->getDisplayName())->toBe('Wine Cellar');
});

it('refuses a chosen id that is no free map folder path before writing anything', function (string $id, string $reason) {
    $root = mapGraphicsProject();
    file_put_contents($root . '/assets/Maps/notes.txt', 'Author notes');
    $session = EditorSession::open($root);
    $before = sourceHashTree($root);

    expect(fn() => $session->createMap('Room', null, 2, 2, $id))->toThrow(SessionRefusal::class, $reason)
        ->and(sourceHashTree($root))->toBe($before)
        ->and(array_column($session->describeMaps(), 'id'))->toBe(['test-map']);
})->with([
    'climbing out' => ['../outside', 'is not a folder path under assets/Maps'],
    'absolute' => ['/tmp/room', 'is not a folder path under assets/Maps'],
    'empty segment' => ['town//room', 'is not a folder path under assets/Maps'],
    'capitals' => ['Town/Room', 'is not a folder path under assets/Maps'],
    'dot folder' => ['town/.room', 'is not a folder path under assets/Maps'],
    'backslash' => ['town\\room', 'is not a folder path under assets/Maps'],
    'blank' => ['  ', 'A map id cannot be empty.'],
    'an existing map' => ['test-map', 'Map path test-map already exists.'],
    'a map\'s layers' => ['test-map/layers', 'in the layers folder of the map at test-map'],
    'a map\'s graphics' => ['test-map/graphics', 'in the graphics folder of the map at test-map'],
    'a file' => ['notes.txt/room', 'is not a folder path'],
]);

it('refuses a chosen id that runs through a file or lands on one', function () {
    $root = mapGraphicsProject();
    file_put_contents($root . '/assets/Maps/annex', 'Not a folder');
    $session = EditorSession::open($root);
    $before = sourceHashTree($root);

    expect(fn() => $session->createMap('Room', null, 2, 2, 'annex'))->toThrow(SessionRefusal::class, 'is a file')
        ->and(fn() => $session->createMap('Room', null, 2, 2, 'annex/room'))->toThrow(SessionRefusal::class, 'passes through a file, annex')
        ->and(sourceHashTree($root))->toBe($before);
});

it('copies a map to a chosen id and refuses a taken one', function () {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    $session->createMap('Hall', null, 2, 2);
    $revision = $session->readMap('test-map')['revision'];
    $before = sourceHashTree($root);

    expect(fn() => $session->duplicateMap('test-map', $revision, 'hall'))->toThrow(SessionRefusal::class, 'Map path hall already exists.')
        ->and(fn() => $session->duplicateMap('test-map', $revision, 'hall/layers'))->toThrow(SessionRefusal::class, 'layers folder')
        ->and(sourceHashTree($root))->toBe($before);

    $request = mapIdentityHost($root);
    $copy = $request('map.duplicate', ['map' => 'test-map', 'revision' => $request('map.read', ['map' => 'test-map'])['result']['revision'],
        'to' => 'hall/back-room'])['result'];
    expect($copy['map'])->toBe('hall/back-room')
        ->and(file_get_contents($root . '/assets/Maps/hall/back-room/back-room.data.php'))->toContain('// Authored metadata remains authored.', "'Back Room'")
        ->and(readTileRows($root . '/assets/Maps/hall/back-room/graphics/01.floor.tiles.php'))->toBe(readTileRows($root . '/assets/Maps/test-map/graphics/01.floor.tiles.php'))
        ->and($request('map.duplicate', ['map' => 'test-map', 'revision' => 0, 'to' => 3])['error']['kind'])->toBe('request');
});

it('moves a map to a chosen id after the same review, naming references it does not migrate', function () {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    $session->createMap('Hall', null, 3, 3);
    $data = $root . '/assets/Maps/hall/hall.data.php';
    file_put_contents($data, str_replace("'events' => []", "'events' => ['D' => ['class' => 'Door', 'data' => ['destinationMap' => 'test-map']]]", (string) file_get_contents($data)));
    $request = mapIdentityHost($root);
    $revision = $request('map.read', ['map' => 'test-map'])['result']['revision'];
    $before = sourceHashTree($root);

    $question = $request('map.move', ['map' => 'test-map', 'revision' => $revision, 'to' => 'hall/workshop'])['result'];
    expect($question)->toMatchArray(['status' => 'question', 'destination' => 'hall/workshop', 'to' => 'hall/workshop', 'references' => ['event D on hall']])
        ->and($question['warning'])->toContain('References are not migrated')
        ->and(sourceHashTree($root))->toBe($before)
        // The confirmation names the destination it reviewed, as a derived move's does.
        ->and($request('map.move', ['map' => 'test-map', 'revision' => $revision, 'to' => 'hall/workshop', 'confirm' => true])['error']['message'])
            ->toContain('Review the proposed destination')
        ->and($request('map.move', ['map' => 'test-map', 'revision' => $revision, 'to' => 'hall/workshop', 'destination' => 'hall/other', 'confirm' => true])['error']['message'])
            ->toContain('reviewed destination is not the id to move to')
        // A destination that cannot be written is refused before any question.
        ->and($request('map.move', ['map' => 'test-map', 'revision' => $revision, 'to' => 'hall'])['error']['message'])->toContain('Map path hall already exists.')
        ->and($request('map.move', ['map' => 'test-map', 'revision' => $revision, 'to' => '../hall'])['error']['message'])->toContain('not a folder path')
        ->and($request('map.move', ['map' => 'test-map', 'revision' => $revision, 'to' => 'test-map'])['result']['status'])->toBe('unchanged')
        ->and(sourceHashTree($root))->toBe($before);

    $moved = $request('map.move', ['map' => 'test-map', 'revision' => $revision, 'to' => 'hall/workshop',
        'destination' => $question['destination'], 'confirm' => true])['result'];
    expect($moved)->toMatchArray(['status' => 'moved', 'previousMap' => 'test-map', 'map' => 'hall/workshop'])
        ->and(file_get_contents($root . '/assets/Maps/hall/workshop/workshop.data.php'))->toContain('// Authored metadata remains authored.')
        ->and(is_dir($root . '/assets/Maps/test-map'))->toBeFalse()
        ->and(is_file($data))->toBeTrue();
    $loaded = ProjectWorkspace::fromProject($root);
    expect($loaded->mapIds)->toBe(['hall', 'hall/workshop'])
        ->and(array_find($loaded->maps, static fn($map): bool => $map->mapId === 'hall')->getEventDefinitions()['D']['data']['destinationMap'])->toBe('test-map');
});

it('moves a nested map out of its folders and deletes one, leaving no empty folders behind', function () {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    $session->createMap('Front Desk', null, 2, 2, 'harbour/office/front-desk');
    $session->createMap('Back Room', null, 2, 2, 'harbour/office/back-room');
    $question = $session->moveMap('harbour/office/front-desk', $session->readMap('harbour/office/front-desk')['revision'], to: 'front-desk');
    $session->moveMap('harbour/office/front-desk', $question['revision'], $question['destination'], true, 'front-desk');

    expect(is_dir($root . '/assets/Maps/harbour/office/front-desk'))->toBeFalse()
        ->and(is_dir($root . '/assets/Maps/harbour/office/back-room'))->toBeTrue();
    $session->deleteMap('harbour/office/back-room');
    expect(is_dir($root . '/assets/Maps/harbour'))->toBeFalse()
        ->and(is_dir($root . '/assets/Maps'))->toBeTrue()
        ->and(array_column($session->describeMaps(), 'id'))->toBe(['front-desk', 'test-map']);
});

it('takes a nested map made inside a cancelled change set away with its folders', function () {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    $before = sourceHashTree($root);
    $session->beginChanges();
    $session->createMap('Cellar', null, 2, 2, 'test-map/cellar');
    $session->createMap('Gate', null, 2, 2, 'harbour/gate');
    expect(is_dir($root . '/assets/Maps/harbour/gate'))->toBeTrue();

    $session->cancelChanges();
    expect(sourceHashTree($root))->toBe($before)
        ->and(is_dir($root . '/assets/Maps/harbour'))->toBeFalse()
        ->and(is_dir($root . '/assets/Maps/test-map/cellar'))->toBeFalse()
        ->and(array_column($session->describeMaps(), 'id'))->toBe(['test-map']);
});
