<?php

declare(strict_types=1);

use Ichiloto\Editor\Canvas\PhysicalOccupancyEditor;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGridSource;

function createPhysicalOccupancyProject(?string $source = null, string $glyphs = "###\n##"): string
{
    $root = makeTemporaryProject('editor-occupancy-paint-');
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/test-map.map.php', MapGridSource::buildSource($glyphs, 'MAP'));
    $events = implode("\n", array_map(static fn(string $row): string => str_repeat(' ', strlen($row)), explode("\n", $glyphs)));
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource($events, 'EVENT'));
    $rows = array_map(static fn(string $row): array => array_fill(0, strlen($row), CollisionType::NONE), explode("\n", $glyphs));
    file_put_contents($directory . '/test-map.data.php', $source ?? '<?php return '
        . var_export(['name' => 'Physical fixture', 'events' => [], 'occupancy' => $rows], true) . ';');

    return $root;
}

function getPhysicalOccupancyMap(EditorSession $session): \Ichiloto\Editor\ProjectMap
{
    return new ReflectionProperty(EditorSession::class, 'workspace')->getValue($session)->getMapByIndex(0);
}

function createPhysicalOccupancyRpc(string $root): Closure
{
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    expect($request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');

    return $request;
}

it('reads the resolved physical grid and final collision choices without presentation padding', function () {
    $root = createPhysicalOccupancyProject();
    $session = EditorSession::open($root);
    $read = $session->readMap('test-map');
    $map = getPhysicalOccupancyMap($session);
    expect($read['physicalOccupancy'])->toBeTrue()
        ->and($read['occupancy']['rows'])->toBe([[0, 0, 0], [0, 0]])
        ->and($read['occupancy']['issue'])->toBeNull()
        ->and(array_column($read['occupancy']['types'], 'value'))->toBe(array_values(array_map(
            static fn(CollisionType $type): int => $type->value,
            array_filter(CollisionType::cases(), static fn(CollisionType $type): bool => $type !== CollisionType::PASS_THROUGH))))
        ->and(array_column($read['occupancy']['types'], 'label'))->not->toContain('Pass Through')
        ->and($map->getMapDataField(['occupancy', 0, 0]))->toBe(CollisionType::NONE);
});

it('reads legacy collision truth but refuses painting without explicit conversion', function () {
    $root = createPhysicalOccupancyProject('<?php return ["events" => []];', "##\n#");
    file_put_contents($root . '/assets/Maps/collisions.php', '<?php return ["#" => '
        . var_export(CollisionType::COUNTER, true) . '];');
    $session = EditorSession::open($root);
    $before = $session->readMap('test-map');
    $hashes = sourceHashTree($root);
    expect($before['occupancy']['rows'])->toBe([[10, 10], [10]])
        ->and($before['occupancy']['issue'])->toBeNull()
        ->and($before['physicalOccupancy'])->toBeFalse()
        ->and($session->getOccupancyFillRegion('test-map', 0, 0, $before['revision'])['cells'])->toHaveCount(3)
        ->and(fn() => $session->paintOccupancy('test-map', $before['revision'], [[0, 0]], 0))
            ->toThrow(SessionRefusal::class, 'explicitly migrated')
        ->and($session->readMap('test-map'))->toBe($before)
        ->and($session->undo()['label'])->toBeNull()
        ->and(sourceHashTree($root))->toBe($hashes);
});

it('reports unresolved legacy collisions rather than inventing physical passage', function () {
    $root = createPhysicalOccupancyProject('<?php return ["events" => []];');
    file_put_contents($root . '/assets/Maps/collisions.php', '<?php return ["#" => 0];');
    $session = EditorSession::open($root);
    $read = $session->readMap('test-map');
    expect($read['occupancy']['rows'])->toBe([])
        ->and($read['occupancy']['issue'])->toContain('Invalid dictionary entry')
        ->and(fn() => $session->getOccupancyFillRegion('test-map', 0, 0, $read['revision']))
            ->toThrow(SessionRefusal::class, 'Invalid dictionary entry');
});

it('reports malformed declared occupancy and atomically refuses painting and fill', function (mixed $rows) {
    $root = createPhysicalOccupancyProject('<?php return ' . var_export(['events' => [], 'occupancy' => $rows], true) . ';');
    $session = EditorSession::open($root);
    $before = $session->readMap('test-map');
    $hashes = sourceHashTree($root);
    expect($before['physicalOccupancy'])->toBeTrue()
        ->and($before['occupancy']['rows'])->toBe([])
        ->and($before['occupancy']['issue'])->toBeString()
        ->and(fn() => $session->paintOccupancy('test-map', $before['revision'], [[0, 0]], 1))
            ->toThrow(SessionRefusal::class, 'occupancy')
        ->and(fn() => $session->getOccupancyFillRegion('test-map', 0, 0, $before['revision']))
            ->toThrow(SessionRefusal::class, 'occupancy')
        ->and($session->readMap('test-map'))->toBe($before)
        ->and($session->undo()['label'])->toBeNull()
        ->and(sourceHashTree($root))->toBe($hashes);
})->with([
    'null' => [null],
    'missing rows' => [[]],
    'integer cells' => [[[0, 0, 0], [0, 0]]],
    'wrong row width' => [[[CollisionType::NONE], [CollisionType::NONE, CollisionType::NONE]]],
    'unresolved cell' => [[[CollisionType::PASS_THROUGH, CollisionType::NONE, CollisionType::NONE], [CollisionType::NONE, CollisionType::NONE]]],
]);

it('strictly validates occupancy RPC arguments before changing any state', function (string $method, array $override) {
    $root = createPhysicalOccupancyProject();
    $request = createPhysicalOccupancyRpc($root);
    $before = $request('map.read', ['map' => 'test-map'])['result'];
    $params = ['map' => 'test-map', 'revision' => $before['revision'], 'cells' => [[0, 0]], 'collision' => 1, 'x' => 0, 'y' => 0];
    expect($request($method, array_replace($params, $override))['error']['kind'])->toBe('request')
        ->and($request('map.read', ['map' => 'test-map'])['result'])->toBe($before)
        ->and($request('history.undo', [])['result']['label'])->toBeNull();
})->with([
    'map integer' => ['map.paintOccupancy', ['map' => 1]],
    'revision string' => ['map.paintOccupancy', ['revision' => '0']],
    'collision string' => ['map.paintOccupancy', ['collision' => '1']],
    'collision float' => ['map.paintOccupancy', ['collision' => 1.5]],
    'collision boolean' => ['map.paintOccupancy', ['collision' => true]],
    'label null' => ['map.paintOccupancy', ['label' => null]],
    'cells null' => ['map.paintOccupancy', ['cells' => null]],
    'cell fractional' => ['map.paintOccupancy', ['cells' => [[0.5, 0]]]],
    'cell extra value' => ['map.paintOccupancy', ['cells' => [[0, 0, 1]]]],
    'cell text' => ['map.paintOccupancy', ['cells' => [[0, '0']]]],
    'fill x string' => ['canvas.fillOccupancy', ['x' => '0']],
    'fill revision null' => ['canvas.fillOccupancy', ['revision' => null]],
]);

it('rejects malformed or outside physical cells atomically even after a valid cell', function (array $cells) {
    $root = createPhysicalOccupancyProject();
    $session = EditorSession::open($root);
    $before = $session->readMap('test-map');
    $hashes = sourceHashTree($root);
    expect(fn() => $session->paintOccupancy('test-map', $before['revision'], $cells, 1))->toThrow(SessionRefusal::class)
        ->and($session->readMap('test-map'))->toBe($before)
        ->and($session->undo()['label'])->toBeNull()
        ->and(sourceHashTree($root))->toBe($hashes);
})->with([
    'ragged gap' => [[[0, 0], [2, 1]]],
    'negative x' => [[[0, 0], [-1, 0]]],
    'negative y' => [[[0, 0], [0, -1]]],
    'outside width' => [[[0, 0], [3, 0]]],
    'outside height' => [[[0, 0], [0, 2]]],
    'short pair' => [[[0, 0], [1]]],
    'noninteger' => [[[0, 0], [1, '0']]],
    'not pair' => [[[0, 0], null]],
    'nonlist' => [[1 => [0, 0]]],
    'reordered pair' => [[[1 => 0, 0 => 0]]],
]);

it('rejects nonfinal collision values without dirty state or history', function (int $collision) {
    $session = EditorSession::open(createPhysicalOccupancyProject());
    $before = $session->readMap('test-map');
    expect(fn() => $session->paintOccupancy('test-map', $before['revision'], [[0, 0]], $collision))
        ->toThrow(SessionRefusal::class, 'final CollisionType')
        ->and($session->readMap('test-map'))->toBe($before)
        ->and($session->undo()['label'])->toBeNull();
})->with([9, -1, 999]);

it('paints every final enum type after explicit migration and saves enums rather than integers', function (CollisionType $collision) {
    $root = createPhysicalOccupancyProject('<?php return ["events" => []];');
    $session = EditorSession::open($root);
    $session->migratePhysicalOccupancy('test-map', $session->readMap('test-map')['revision']);
    $before = $session->readMap('test-map');
    $changed = $before['occupancy']['rows'][0][0] === $collision->value ? 0 : 1;
    expect($session->paintOccupancy('test-map', $before['revision'], [[0, 0]], $collision->value)['changed'])->toBe($changed)
        ->and($session->readMap('test-map')['occupancy']['rows'][0][0])->toBe($collision->value);
    $session->saveMap('test-map');
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    expect($map->getMapDataField(['occupancy', 0, 0]))->toBe($collision);
})->with(array_values(array_filter(CollisionType::cases(), static fn(CollisionType $type): bool => $type !== CollisionType::PASS_THROUGH)));

it('deduplicates a physical stroke and rejects stale painting and fill over RPC', function () {
    $request = createPhysicalOccupancyRpc(createPhysicalOccupancyProject());
    $revision = $request('map.read', ['map' => 'test-map'])['result']['revision'];
    $params = ['map' => 'test-map', 'revision' => $revision, 'cells' => [[0, 0], [0, 0], [1, 0]], 'collision' => 10];
    $applied = $request('map.paintOccupancy', $params)['result'];
    expect($applied)->toBe(['status' => 'applied', 'changed' => 2, 'revision' => $revision + 1])
        ->and($request('map.paintOccupancy', $params)['error']['kind'])->toBe('refusal')
        ->and($request('canvas.fillOccupancy', ['map' => 'test-map', 'revision' => $revision, 'x' => 0, 'y' => 0])['error']['kind'])
            ->toBe('refusal')
        ->and($request('canvas.fillOccupancy', ['map' => 'test-map', 'revision' => $applied['revision'], 'x' => 0, 'y' => 0])['result'])
            ->toBe(['cells' => [[0, 0], [1, 0]], 'map' => 'test-map', 'revision' => $applied['revision']])
        ->and($request('history.undo', [])['result']['label'])->toBe('Paint collision');
});

it('leaves no-op physical strokes clean and preserves the redo stack', function () {
    $session = EditorSession::open(createPhysicalOccupancyProject());
    $before = $session->readMap('test-map');
    foreach ([[], [[0, 0], [0, 0]]] as $cells) {
        expect($session->paintOccupancy('test-map', $before['revision'], $cells, 0))
            ->toBe(['status' => 'applied', 'changed' => 0, 'revision' => $before['revision']]);
    }
    expect($session->readMap('test-map'))->toBe($before)->and($session->undo()['label'])->toBeNull();
    $session->paintOccupancy('test-map', $before['revision'], [[0, 0]], 1, 'Physical test stroke');
    expect($session->undo()['label'])->toBe('Physical test stroke');
    $revision = $session->readMap('test-map')['revision'];
    $session->paintOccupancy('test-map', $revision, [[0, 0]], 0);
    expect($session->redo()['label'])->toBe('Physical test stroke')
        ->and($session->readMap('test-map')['occupancy']['rows'][0][0])->toBe(1);
});

it('does not flood across missing ragged cells or accept a missing fill seed', function () {
    $rows = [[CollisionType::SOLID, CollisionType::NONE, CollisionType::NONE], [CollisionType::SOLID],
        [CollisionType::SOLID, CollisionType::NONE, CollisionType::NONE]];
    $root = createPhysicalOccupancyProject('<?php return ' . var_export(['events' => [], 'occupancy' => $rows], true) . ';', "###\n#\n###");
    $session = EditorSession::open($root);
    $before = $session->readMap('test-map');
    expect($session->getOccupancyFillRegion('test-map', 1, 0, $before['revision'])['cells'])->toBe([[1, 0], [2, 0]])
        ->and($session->getOccupancyFillRegion('test-map', 1, 2, $before['revision'])['cells'])->toBe([[1, 2], [2, 2]])
        ->and(fn() => $session->getOccupancyFillRegion('test-map', 1, 1, $before['revision']))
            ->toThrow(SessionRefusal::class, 'outside the map')
        ->and($session->readMap('test-map'))->toBe($before);
});

it('preserves authored comments and unrelated expressions through shared undo save and redo', function () {
    $source = <<<'PHP'
    <?php
    use Ichiloto\Engine\Events\Enumerations\CollisionType;
    $kept = 'artist note';
    return array(
      'name' => 'Physical fixture',
      'description' => strtoupper('kept'), // Keep this authored expression.
      'note' => $kept,
      'events' => [],
      'occupancy' => array(
        array(CollisionType::NONE, /* ground contact */ CollisionType::NONE, CollisionType::NONE),
        array(CollisionType::NONE, CollisionType::NONE),
      ),
    );
    PHP;
    $root = createPhysicalOccupancyProject($source);
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    $session = EditorSession::open($root);
    $map = getPhysicalOccupancyMap($session);
    $hashes = sourceHashTree($root);
    $session->paintOccupancy('test-map', $session->readMap('test-map')['revision'], [[1, 0]], 10);
    expect(sourceHashTree($root))->toBe($hashes)
        ->and($map->getMapDataField(['occupancy', 0, 1]))->toBe(CollisionType::COUNTER);
    $session->saveMap('test-map');
    $painted = file_get_contents($file);
    expect($painted)->toContain("'description' => strtoupper('kept'), // Keep this authored expression.",
        "'note' => \$kept", '/* ground contact */', "'occupancy' => array(")
        ->and(ProjectWorkspace::fromProject($root)->getMapByIndex(0)->getResolvedCollisionMap())->toBe([[0, 10, 0], [0, 0]]);
    expect($session->undo()['label'])->toBe('Paint collision');
    $session->saveMap('test-map');
    expect(file_get_contents($file))->toBe($source);
    $session->redo();
    $session->saveMap('test-map');
    expect(file_get_contents($file))->toBe($painted);
});

it('refuses computed occupancy writes without flattening authored PHP', function (string $declaration) {
    $root = createPhysicalOccupancyProject($declaration);
    $session = EditorSession::open($root);
    $before = $session->readMap('test-map');
    $hashes = sourceHashTree($root);
    expect($before['occupancy']['issue'])->toBeNull()
        ->and(fn() => $session->paintOccupancy('test-map', $before['revision'], [[0, 0]], 1))
            ->toThrow(SessionRefusal::class)
        ->and($session->readMap('test-map'))->toBe($before)
        ->and($session->undo()['label'])->toBeNull()
        ->and(sourceHashTree($root))->toBe($hashes);
})->with([
    'computed root' => ['<?php $data = ["events" => [], "occupancy" => [[\Ichiloto\Engine\Events\Enumerations\CollisionType::NONE, \Ichiloto\Engine\Events\Enumerations\CollisionType::NONE, \Ichiloto\Engine\Events\Enumerations\CollisionType::NONE], [\Ichiloto\Engine\Events\Enumerations\CollisionType::NONE, \Ichiloto\Engine\Events\Enumerations\CollisionType::NONE]]]; return $data;'],
    'computed rows' => ['<?php use Ichiloto\Engine\Events\Enumerations\CollisionType; return ["events" => [], "occupancy" => array_map(static fn($n) => array_fill(0, $n, CollisionType::NONE), [3, 2])];'],
    'computed cell' => ['<?php use Ichiloto\Engine\Events\Enumerations\CollisionType; return ["events" => [], "occupancy" => [[CollisionType::from(0), CollisionType::NONE, CollisionType::NONE], [CollisionType::NONE, CollisionType::NONE]]];'],
]);

it('refuses external source replacement on painting fill and saved history without losing history', function (string $member) {
    $root = createPhysicalOccupancyProject();
    $session = EditorSession::open($root);
    $session->paintOccupancy('test-map', $session->readMap('test-map')['revision'], [[0, 0]], 1);
    $session->saveMap('test-map');
    $path = $root . '/assets/Maps/test-map/test-map.' . $member . '.php';
    $source = file_get_contents($path);
    file_put_contents($path, $source . "\n// External author replacement.\n");
    $before = $session->readMap('test-map');
    $hashes = sourceHashTree($root);
    expect(fn() => $session->paintOccupancy('test-map', $before['revision'], [[1, 0]], 1))->toThrow(SessionRefusal::class)
        ->and(fn() => $session->getOccupancyFillRegion('test-map', 0, 0, $before['revision']))->toThrow(SessionRefusal::class)
        ->and(fn() => $session->undo())->toThrow(MapSourceRefusal::class)
        ->and($session->readMap('test-map'))->toBe($before)
        ->and(sourceHashTree($root))->toBe($hashes);
    file_put_contents($path, $source);
    expect($session->undo()['label'])->toBe('Paint collision')
        ->and($session->readMap('test-map')['occupancy']['rows'][0][0])->toBe(0);
})->with(['data', 'map']);

it('keeps occupancy painting independent from glyph and bound tile edits and their history', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: [
        'chest' => ['name' => 'Chest', 'layer' => 'buildings', 'glyphs' => ['m'], 'tiles' => ['furniture' => ['60']]],
    ]);
    $session = EditorSession::open($root);
    $session->migratePhysicalOccupancy('test-map', $session->readMap('test-map')['revision']);
    $map = getPhysicalOccupancyMap($session);
    $glyphs = $map->getLayerSet()->getComposedGrid();
    $tiles = $map->getTileLayerSources();
    $physical = $session->readMap('test-map')['occupancy']['rows'];
    $target = $physical[0][0] === 10 ? 1 : 10;
    $session->paintOccupancy('test-map', $session->readMap('test-map')['revision'], [[0, 0]], $target);
    $painted = $session->readMap('test-map')['occupancy']['rows'];
    expect($map->getLayerSet()->getComposedGrid())->toBe($glyphs)->and($map->getTileLayerSources())->toBe($tiles);
    $session->paint('test-map', $session->readMap('test-map')['revision'], 'map:4', [[0, 0]], 'm');
    expect($session->readMap('test-map')['occupancy']['rows'])->toBe($painted);
    $session->stampTiles('test-map', $session->readMap('test-map')['revision'], 'furniture', [[1, 1, 60]]);
    expect($session->readMap('test-map')['occupancy']['rows'])->toBe($painted);
    $session->undo();
    $session->undo();
    expect($map->getLayerSet()->getComposedGrid())->toBe($glyphs)
        ->and($map->getTileLayerSources())->toBe($tiles)
        ->and($session->readMap('test-map')['occupancy']['rows'])->toBe($painted);
    $session->undo();
    expect($session->readMap('test-map')['occupancy']['rows'])->toBe($physical);
    $session->redo();
    $session->redo();
    $session->redo();
    expect($session->readMap('test-map')['occupancy']['rows'])->toBe($painted);
    $session->saveMap('test-map');
    expect(ProjectWorkspace::fromProject($root)->getMapByIndex(0)->getResolvedCollisionMap())->toBe($painted);
});

it('restores only physical data rather than unrelated metadata or presentations', function () {
    $root = createPhysicalOccupancyProject();
    $session = EditorSession::open($root);
    $map = getPhysicalOccupancyMap($session);
    $edit = PhysicalOccupancyEditor::applyPaint($map, [[0, 0]], 1);
    $map->setMapField('name', 'Later authored name');
    $map->setLayerCell($map->getBaseLayerId(), 1, 0, '.');
    $edit['command']->undo();
    expect($map->getDisplayName())->toBe('Later authored name')
        ->and($map->getLayerSymbol($map->getBaseLayerId(), 1, 0))->toBe('.')
        ->and($map->getResolvedCollisionMap())->toBe([[0, 0, 0], [0, 0]]);
    $edit['command']->execute();
    expect($map->getDisplayName())->toBe('Later authored name')
        ->and($map->getLayerSymbol($map->getBaseLayerId(), 1, 0))->toBe('.')
        ->and($map->getResolvedCollisionMap())->toBe([[1, 0, 0], [0, 0]]);
});
