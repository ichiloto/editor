<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Layer editing served through the editor session and its line protocol:
 * the map lists its tile layers, each layer edit is one undo step made
 * against the map's current revision, an edit that changes collisions is a
 * question until confirmed, and a save writes the layer files as one set.
 * Every project here is a synthetic fixture.
 */

/** @return callable(int, string, array<string, mixed>): array<string, mixed> */
function layerSessionHost(string $root): callable
{
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]));
    $request(0, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);

    return $request;
}

it('lists a map\'s tile layers with their files and settings as the Engine reads them', function () {
    $root = mapGraphicsProject();
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("'tileset' => 'home',", "'tileset' => 'home', 'tileLayers' => ['decor' => ['offset' => [0, -0.5], 'movesWith' => 'buildings']],", (string) file_get_contents($data)));

    $map = EditorSession::open($root)->readMap('test-map');

    expect($map['tileLayers'])->toBe(['layers' => [
        ['name' => 'floor', 'order' => 1, 'file' => 'graphics/01.floor.tiles.php', 'offset' => [0.0, 0.0], 'movesWith' => null, 'owner' => null],
        ['name' => 'decor', 'order' => 2, 'file' => 'graphics/02.decor.tiles.php', 'offset' => [0.0, -0.5], 'movesWith' => 'buildings', 'owner' => 'buildings'],
    ], 'issue' => null]);
});

it('asks before a glyph layer edit that changes collisions, then applies it confirmed as one undo step', function () {
    $root = layeredMapProject();
    file_put_contents($root . '/assets/Maps/collisions.php', '<?php use Ichiloto\Engine\Events\Enumerations\CollisionType;'
        . ' return ["." => CollisionType::NONE, "/" => CollisionType::SOLID, "x" => CollisionType::SOLID];');
    $session = EditorSession::open($root);
    $revision = $session->readMap('test-map')['revision'];

    $asked = $session->setLayerDecoration('test-map', $revision, 'map:4', true);
    expect($asked['status'])->toBe('question')
        ->and($asked['question'])->toContain('Making buildings decoration changes collision at 3 cell(s)')
        ->and($session->readMap('test-map')['revision'])->toBe($revision);

    $applied = $session->setLayerDecoration('test-map', $revision, 'map:4', true, true);
    $layer = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['id'] === 'map:4');

    expect($applied)->toMatchArray(['status' => 'applied', 'changed' => true, 'layer' => 'map:4'])
        ->and($layer['decoration'])->toBeTrue()
        ->and(fn() => $session->renameLayer('test-map', $revision, 'map:4', 'houses'))->toThrow(SessionRefusal::class, 'changed since revision')
        ->and($session->undo())->toMatchArray(['label' => 'Layer decoration', 'maps' => ['test-map']]);
});

it('creates, renames, reorders and removes glyph layers, saving the layer files as one set', function () {
    $root = layeredMapProject();
    $session = EditorSession::open($root);
    $revision = static fn(): int => $session->readMap('test-map')['revision'];

    $created = $session->createLayer('test-map', $revision(), 'fixtures');
    $session->renameLayer('test-map', $revision(), $created['layer'], 'props');
    $session->moveLayer('test-map', $revision(), $created['layer'], null, 'below');
    $session->removeLayer('test-map', $revision(), 'map:7');
    $session->saveMap('test-map');

    $files = array_map('basename', glob($root . '/assets/Maps/test-map/layers/*.php') ?: []);
    expect($files)->toBe(['01.terrain.map.php', '04.buildings.map.php', '07.props.map.php'])
        ->and(array_column(ProjectWorkspace::fromProject($root)->maps[0]->getLayers(), 'name'))->toBe(['terrain', 'buildings', 'props', 'Events'])
        ->and(fn() => $session->moveLayer('test-map', $revision(), 'map:1', 3, 'above'))->toThrow(SessionRefusal::class, 'not both or neither')
        ->and(fn() => $session->removeLayer('test-map', $revision(), 'event', true))->toThrow(SessionRefusal::class, 'Only authored layers');
});

it('serves tile layer editing over the line protocol, refusing malformed requests by kind', function () {
    $root = mapGraphicsProject();
    $request = layerSessionHost($root);
    $revision = static fn(): int => $request(1, 'map.read', ['map' => 'test-map'])['result']['revision'];

    expect($request(2, 'tileLayer.create', ['map' => 'test-map', 'revision' => $revision(), 'name' => 'rugs'])['result'])
        ->toMatchArray(['status' => 'applied', 'changed' => true, 'layer' => 'rugs'])
        ->and($request(3, 'tileLayer.rename', ['map' => 'test-map', 'revision' => $revision(), 'name' => 'rugs', 'newName' => 'carpets'])['result']['layer'])
            ->toBe('carpets')
        ->and($request(4, 'tileLayer.reorder', ['map' => 'test-map', 'revision' => $revision(), 'name' => 'carpets', 'order' => 0])['result']['status'])
            ->toBe('applied')
        ->and($request(5, 'tileLayer.settings', ['map' => 'test-map', 'revision' => $revision(), 'name' => 'carpets',
            'offset' => [0.5, 0], 'movesWith' => 'buildings'])['result']['status'])->toBe('applied')
        ->and($request(6, 'tileLayer.settings', ['map' => 'test-map', 'revision' => $revision(), 'name' => 'carpets',
            'offset' => [0.25, 0], 'movesWith' => null])['error']['kind'])->toBe('refusal')
        ->and($request(7, 'tileLayer.settings', ['map' => 'test-map', 'revision' => $revision(), 'name' => 'carpets', 'offset' => [0]])['error'])
            ->toBe(['kind' => 'request', 'message' => '"offset" must be two numbers, across and down.'])
        ->and($request(8, 'tileLayer.remove', ['map' => 'test-map', 'revision' => $revision(), 'name' => 'decor'])['result']['layer'])->toBeNull()
        ->and($request(9, 'layer.decoration', ['map' => 'test-map', 'revision' => $revision(), 'layer' => 'map:4'])['error']['kind'])->toBe('request')
        ->and($request(10, 'map.save', ['map' => 'test-map'])['result']['saved'])->toBe('test-map');

    $map = ProjectWorkspace::fromProject($root)->maps[0];
    expect(array_map('basename', glob($map->directory . '/graphics/*.php') ?: []))->toBe(['00.carpets.tiles.php', '01.floor.tiles.php'])
        ->and($map->getMapDataField(['tileLayers']))->toBe(['carpets' => ['offset' => [0.5, 0], 'movesWith' => 'buildings']])
        ->and($map->loadGraphics()->offsets)->toBe(['carpets' => [0.5, 0.0]]);
});
