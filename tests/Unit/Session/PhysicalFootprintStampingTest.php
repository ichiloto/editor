<?php

declare(strict_types=1);

use Ichiloto\Editor\Canvas\PhysicalOccupancyEditor;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\Enumerations\CollisionType;

function createPhysicalFootprintProject(?array $recipe = null, string $glyphs = "####\n###"): string
{
    $root = createPhysicalOccupancyProject(glyphs: $glyphs);
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    $body = file_get_contents($data);
    file_put_contents($data, str_replace('return array (', "// Physical source notes.\nreturn array (\n  'tileset' => 'home',", $body));
    writeTestTileset($root, pieces: ['desk' => ['name' => 'Desk', 'layer' => 'not-required-for-collision',
        'glyphs' => ['##', '##'], 'occupancy' => $recipe ?? [[null, CollisionType::COUNTER], [CollisionType::SOLID, CollisionType::NONE]]]]);

    return $root;
}

it('previews and stamps a heterogeneous footprint independently of either presentation with shared undo and saved source', function () {
    $root = createPhysicalFootprintProject();
    $session = EditorSession::open($root);
    $before = $session->readMap('test-map');
    $hashes = sourceHashTree($root);
    $pieces = $session->listPieces('test-map');
    $expected = [[null, 10], [1, 0]];
    expect($pieces['tileset'])->toBe('home')
        ->and($pieces['pieces'][0]['layerIssue'])->toBeString()
        ->and($pieces['pieces'][0]['occupancy'])->toBe($expected)
        ->and($session->previewOccupancyPiece('test-map', $before['revision'], 'home', 'desk', $expected, 0, 0)['cells'])
            ->toBe([[1, 0, 10], [0, 1, 1], [1, 1, 0]])
        ->and($session->readMap('test-map'))->toBe($before)
        ->and(sourceHashTree($root))->toBe($hashes);
    $applied = $session->stampOccupancyPiece('test-map', $before['revision'], 'home', 'desk', $expected, 0, 0);
    $after = $session->readMap('test-map');
    expect($applied['changed'])->toBe(2)
        ->and($after['occupancy']['rows'])->toBe([[0, 10, 0, 0], [1, 0, 0]])
        ->and($after['layers'])->toBe($before['layers']);
    $session->saveMap('test-map');
    expect(file_get_contents($root . '/assets/Maps/test-map/test-map.data.php'))->toContain('// Physical source notes.');
    $map = getPhysicalOccupancyMap($session);
    expect($map->getMapDataField(['occupancy', 0, 1]))->toBe(CollisionType::COUNTER);
    $session->undo();
    expect($session->readMap('test-map')['occupancy']['rows'])->toBe($before['occupancy']['rows']);
    $session->redo();
    $session->saveMap('test-map');
    expect(EditorSession::open($root)->readMap('test-map')['occupancy']['rows'])->toBe($after['occupancy']['rows']);
    foreach ($hashes as $path => $hash) {
        if ($path !== 'assets/Maps/test-map/test-map.data.php') {
            expect(sourceHashTree($root)[$path])->toBe($hash);
        }
    }
});

it('refuses a partial ragged footprint, stale map revision and changed recipe without touching source or history', function () {
    $root = createPhysicalFootprintProject();
    $session = EditorSession::open($root);
    $before = $session->readMap('test-map');
    $hashes = sourceHashTree($root);
    $expected = [[null, 10], [1, 0]];
    expect(fn() => $session->previewOccupancyPiece('test-map', $before['revision'], 'home', 'desk', $expected, 2, 0))
        ->toThrow(SessionRefusal::class, 'whole physical footprint')
        ->and(fn() => $session->stampOccupancyPiece('test-map', $before['revision'], 'home', 'desk', $expected, 2, 0))
            ->toThrow(SessionRefusal::class, 'whole physical footprint')
        ->and(fn() => $session->stampOccupancyPiece('test-map', $before['revision'], 'wrong', 'desk', $expected, 0, 0))
            ->toThrow(SessionRefusal::class, 'tileset changed')
        ->and(fn() => $session->stampOccupancyPiece('test-map', $before['revision'], 'home', 'desk', [[1, 10], [1, 0]], 0, 0))
            ->toThrow(SessionRefusal::class, 'footprint changed')
        ->and($session->undo()['label'])->toBeNull()
        ->and($session->readMap('test-map'))->toBe($before)
        ->and(sourceHashTree($root))->toBe($hashes);
    $session->paintOccupancy('test-map', $before['revision'], [[3, 0]], 1);
    expect(fn() => $session->stampOccupancyPiece('test-map', $before['revision'], 'home', 'desk', $expected, 0, 0))
        ->toThrow(SessionRefusal::class);
    $new = $session->readMap('test-map');
    writeTestTileset($root, pieces: ['desk' => ['name' => 'Desk', 'layer' => 'not-required-for-collision', 'glyphs' => ['##', '##'],
        'occupancy' => [[null, CollisionType::COUNTER], [CollisionType::NONE, CollisionType::NONE]]]]);
    expect(fn() => $session->stampOccupancyPiece('test-map', $new['revision'], 'home', 'desk', $expected, 0, 0))
        ->toThrow(SessionRefusal::class, 'footprint changed')
        ->and($session->readMap('test-map'))->toBe($new);
});

it('keeps all-null recipes as no-op masks and rejects legacy maps without implicit migration', function () {
    $root = createPhysicalFootprintProject([[null, null], [null, null]]);
    $session = EditorSession::open($root);
    $before = $session->readMap('test-map');
    expect($session->stampOccupancyPiece('test-map', $before['revision'], 'home', 'desk', [[null, null], [null, null]], 0, 0)['changed'])->toBe(0)
        ->and($session->undo()['label'])->toBeNull()
        ->and($session->readMap('test-map'))->toBe($before);
    $map = getPhysicalOccupancyMap($session);
    $map->setMapDataField(['occupancy'], null);
    file_put_contents($root . '/assets/Maps/collisions.php', '<?php return ["#" => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::NONE];');
    $read = $session->readMap('test-map');
    expect(fn() => $session->stampOccupancyPiece('test-map', $read['revision'], 'home', 'desk', [[null, null], [null, null]], 0, 0))
        ->toThrow(SessionRefusal::class, 'explicitly migrated');
});

it('never applies a physical recipe as a side effect of graphical or glyph piece placement', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: ['post' => ['name' => 'Post', 'layer' => 'buildings', 'glyphs' => ['#'],
        'tiles' => ['decor' => ['5']], 'occupancy' => [[CollisionType::COUNTER]]]]);
    $session = EditorSession::open($root);
    $before = $session->readMap('test-map');
    $session->migratePhysicalOccupancy('test-map', $before['revision']);
    $separated = $session->readMap('test-map');
    $session->placePiece('test-map', $separated['revision'], 'post', [0, 0], [0, 0]);
    expect($session->readMap('test-map')['occupancy'])->toBe($separated['occupancy']);
});

it('refuses conflicting or invalid heterogeneous writes atomically through the shared source gate', function () {
    $root = createPhysicalFootprintProject();
    $session = EditorSession::open($root);
    $map = getPhysicalOccupancyMap($session);
    $before = $session->readMap('test-map');
    foreach ([[[0, 0, CollisionType::NONE], [0, 0, CollisionType::COUNTER]],
        [[0, 0, CollisionType::COUNTER], [3, 1, CollisionType::SOLID]],
        [[0, 0, CollisionType::COUNTER], [1, 0, CollisionType::PASS_THROUGH]], [[0, 0, 1]]] as $writes) {
        expect(fn() => $map->writePhysicalOccupancy($writes))->toThrow(\Ichiloto\Editor\MapSourceRefusal::class)
            ->and($session->readMap('test-map'))->toBe($before);
    }
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($path, str_replace('// Physical source notes.', '// External edit.', file_get_contents($path)));
    expect(fn() => PhysicalOccupancyEditor::applyPiece($map, $map->loadTileset()->pieces['desk'], 0, 0))
        ->toThrow(\Ichiloto\Editor\MapSourceRefusal::class);
});

it('validates physical footprint RPC identity and values before mutation', function (array $override) {
    $root = createPhysicalFootprintProject();
    $request = createPhysicalOccupancyRpc($root);
    $before = $request('map.read', ['map' => 'test-map'])['result'];
    $params = ['map' => 'test-map', 'revision' => $before['revision'], 'tileset' => 'home', 'piece' => 'desk', 'expected' => [[null, 10], [1, 0]], 'x' => 0, 'y' => 0];
    expect($request('occupancy.stampPiece', array_replace($params, $override))['error']['kind'])->toBe('request')
        ->and($request('map.read', ['map' => 'test-map'])['result'])->toBe($before);
})->with([
    'missing expected' => [['expected' => null]], 'bad row' => [['expected' => [1]]], 'boolean cell' => [['expected' => [[true, 10], [1, 0]]]],
    'fractional origin' => [['x' => 1.5]], 'tileset integer' => [['tileset' => 1]],
]);
