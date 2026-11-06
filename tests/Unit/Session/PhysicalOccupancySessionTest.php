<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Cutscenes\Preview\PreviewField;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGridSource;

it('explicitly migrates current occupancy as one source-preserving undo step', function () {
    $root = layeredMapProject();
    file_put_contents($root . '/assets/Maps/collisions.php', '<?php use Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;'
        . ' return ["." => CollisionType::NONE, "/" => CollisionType::SOLID, "x" => CollisionType::COUNTER];');
    $session = EditorSession::open($root);
    $map = new ReflectionProperty(EditorSession::class, 'workspace')->getValue($session)->getMapByIndex(0);
    $original = sourceHashTree($root);
    $originalData = $map->getMapDataField([]);
    $terminal = $map->getLayerSet()->getComposedGrid();
    $collision = $map->getResolvedCollisionMap();
    $revision = $session->readMap('test-map')['revision'];
    expect($session->readMap('test-map')['physicalOccupancy'])->toBeFalse();

    expect($session->migratePhysicalOccupancy('test-map', $revision))
        ->toMatchArray(['status' => 'applied', 'changed' => true])
        ->and($map->getLayerSet()->getComposedGrid())->toBe($terminal)
        ->and($map->getResolvedCollisionMap())->toBe($collision)
        ->and($session->readMap('test-map')['physicalOccupancy'])->toBeTrue()
        ->and(sourceHashTree($root))->toBe($original)
        ->and(fn() => $session->migratePhysicalOccupancy('test-map', $revision))
            ->toThrow(SessionRefusal::class, 'changed since revision');
    $session->saveMap('test-map');
    $migrated = sourceHashTree($root);
    expect($migrated)->not->toBe($original)
        ->and(ProjectWorkspace::fromProject($root)->getMapByIndex(0)->getResolvedCollisionMap())->toBe($collision);
    expect($session->undo())->toMatchArray(['label' => 'Physical occupancy migration', 'maps' => ['test-map']]);
    $session->saveMap('test-map');
    expect(sourceHashTree($root))->toBe($original)
        ->and($map->getMapDataField([]))->toBe($originalData);
    $session->redo();
    $session->saveMap('test-map');
    expect(sourceHashTree($root))->toBe($migrated)
        ->and($map->getResolvedCollisionMap())->toBe($collision);
    $current = $session->readMap('test-map')['revision'];
    expect($session->migratePhysicalOccupancy('test-map', $current))
        ->toMatchArray(['status' => 'applied', 'changed' => false])
        ->and($session->readMap('test-map')['revision'])->toBe($current);
});

it('serves explicit occupancy migration through the existing revisioned session protocol', function () {
    $root = layeredMapProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params): array =>
        $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]));
    $request(0, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $revision = $request(1, 'map.read', ['map' => 'test-map'])['result']['revision'];
    expect($request(2, 'map.migrateOccupancy', ['map' => 'test-map', 'revision' => $revision])['result'])
        ->toMatchArray(['status' => 'applied', 'changed' => true])
        ->and($request(3, 'map.migrateOccupancy', ['map' => 'test-map', 'revision' => $revision])['error']['kind'])
            ->toBe('refusal')
        ->and($request(4, 'map.migrateOccupancy', ['map' => 'test-map', 'revision' => 'old'])['error']['kind'])
            ->toBe('request');
});

it('keeps physical cells unchanged across later presentation and layer edits', function () {
    $root = layeredMapProject();
    file_put_contents($root . '/assets/Maps/collisions.php', '<?php use Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;'
        . ' return ["." => CollisionType::NONE, "/" => CollisionType::SOLID, "x" => CollisionType::COUNTER];');
    $map = loadLayeredMap($root);
    $collision = $map->getResolvedCollisionMap();
    $map->migratePhysicalOccupancy();
    $map->setLayerCell('map:1', 0, 0, '/');
    expect($map->getResolvedCollisionMap())->toBe($collision)
        ->and($map->countDecorationCollisionChanges('map:4', true))->toBe(0);
    $map->setLayerDecoration('map:4', true);
    $map->renameLayer('map:4', 'appearance');
    $map->save();
    expect(loadLayeredMap($root)->getResolvedCollisionMap())->toBe($collision);
});

it('refuses malformed existing occupancy without source edits revision changes or history entries', function () {
    $root = layeredMapProject();
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, '<?php return ["events" => [], "occupancy" => null];');
    $session = EditorSession::open($root);
    $map = new ReflectionProperty(EditorSession::class, 'workspace')->getValue($session)->getMapByIndex(0);
    $source = sourceHashTree($root);
    $revision = $session->readMap('test-map')['revision'];
    expect(fn() => $map->migratePhysicalOccupancy())->toThrow(MapSourceRefusal::class, 'occupancy')
        ->and(fn() => $session->migratePhysicalOccupancy('test-map', $revision))
            ->toThrow(SessionRefusal::class, 'occupancy')
        ->and($session->readMap('test-map')['revision'])->toBe($revision)
        ->and($session->readMap('test-map')['dirty'])->toBeFalse()
        ->and($session->undo())->toMatchArray(['label' => null, 'maps' => [], 'revisions' => [], 'databases' => []])
        ->and(sourceHashTree($root))->toBe($source);
});

it('refuses explicit migration when source cannot preserve the added declaration', function () {
    $root = layeredMapProject();
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, '<?php $data = ["events" => []]; return $data;');
    $map = loadLayeredMap($root);
    $before = sourceHashTree($root);
    $snapshot = $map->captureLayerSnapshot();
    expect(fn() => $map->migratePhysicalOccupancy())->toThrow(MapSourceRefusal::class)
        ->and(sourceHashTree($root))->toBe($before)
        ->and($map->captureLayerSnapshot())->toBe($snapshot)
        ->and($map->getMapDataField(['occupancy']))->toBeNull();
});

it('uses declared occupancy in the shared field preview and refuses malformed replacement', function () {
    $root = makeTemporaryProject();
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/test-map.map.php', MapGridSource::buildSource('###', 'MAP'));
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource('   ', 'EVENT'));
    $path = $directory . '/test-map.data.php';
    $data = ['events' => [], 'occupancy' => [[CollisionType::NONE, CollisionType::COUNTER, CollisionType::SOLID]]];
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    // A migrated map no longer needs glyph-derived passage for a preview.
    file_put_contents($root . '/assets/Maps/collisions.php', '<?php return "invalid legacy dictionary";');
    $source = sourceHashTree($root);
    $field = PreviewField::open($root, 'test-map', new Vector2(0, 0), 20, 8, static fn(): float => 0.0);
    try {
        expect($field->mapFailure)->toBeNull()
            ->and($field->scene->previewMap->getCollision(0, 0))->toBe(CollisionType::NONE)
            ->and($field->scene->previewMap->getCollision(1, 0))->toBe(CollisionType::COUNTER)
            ->and($field->scene->previewMap->getCollision(2, 0))->toBe(CollisionType::SOLID)
            ->and(sourceHashTree($root))->toBe($source);
        file_put_contents($path, '<?php return ["events" => [], "occupancy" => null];');
        $field->run(function () use ($field): void {
            expect(fn() => $field->scene->previewMap->loadForPreview('test-map'))
                ->toThrow(InvalidArgumentException::class, 'Map occupancy must be a zero-based list');
        });
        expect($field->mapFailure)->toContain('Map occupancy must be a zero-based list')
            ->and($field->scene->previewMap->mapData)->toBe([]);
    } finally {
        $field->dispose();
    }
});

it('reviews, writes and undoes compact occupancy through the revisioned session', function () {
    $root = createPhysicalOccupancyProject(<<<'MAP'
<?php
use Ichiloto\Engine\Events\Enumerations\CollisionType as Collision;
return [
    'name' => strtoupper('physical fixture'),
    'events' => [],
    // Keep the author comment outside the converted value.
    'occupancy' => [[Collision::NONE, Collision::COUNTER, Collision::SOLID], [Collision::SAVE_POINT, Collision::ENCOUNTER]],
];
MAP);
    $request = createPhysicalOccupancyRpc($root);
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    $initial = file_get_contents($file);
    $before = sourceHashTree($root);
    $revision = $request('map.read', ['map' => 'test-map'])['result']['revision'];
    $params = ['map' => 'test-map', 'revision' => $revision];
    $review = $request('map.packOccupancy', $params)['result'];

    expect($review)->toMatchArray(['status' => 'question', 'paths' => ['assets/Maps/test-map/test-map.data.php']])
        ->and(sourceHashTree($root))->toBe($before)
        ->and($request('map.packOccupancy', $params + ['answer' => 'write', 'confirm' => 'stale'])['error']['kind'])->toBe('refusal')
        ->and($request('map.packOccupancy', $params + ['answer' => 'cancel'])['result']['status'])->toBe('cancelled')
        ->and(sourceHashTree($root))->toBe($before);

    $applied = $request('map.packOccupancy', $params + ['answer' => 'write', 'confirm' => $review['confirm']])['result'];
    $packed = file_get_contents($file);
    expect($applied)->toMatchArray(['status' => 'packed', 'changed' => true])
        ->and((require $file)['occupancy'])->toBe(['packedRows' => ['0A1', '67']])
        ->and($packed)->toContain("strtoupper('physical fixture')", '// Keep the author comment outside the converted value.')
        ->and($request('map.read', ['map' => 'test-map'])['result']['occupancy']['rows'])->toBe([[0, 10, 1], [6, 7]])
        ->and($request('map.packOccupancy', ['map' => 'test-map', 'revision' => $applied['revision']])['result']['status'])->toBe('unchanged');
    $request('history.undo', []);
    expect(file_get_contents($file))->toBe($initial)->and(sourceHashTree($root))->toBe($before);
    $request('history.redo', []);
    expect(file_get_contents($file))->toBe($packed);
});

it('refuses to pack computed or commented occupancy without a source change', function (string $rows, string $reason) {
    $root = createPhysicalOccupancyProject('<?php use Ichiloto\Engine\Events\Enumerations\CollisionType as C; '
        . 'return ["events" => [], "occupancy" => ' . $rows . '];');
    $session = EditorSession::open($root);
    $before = sourceHashTree($root);
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->packPhysicalOccupancy('test-map', $revision))->toThrow(SessionRefusal::class, $reason)
        ->and(sourceHashTree($root))->toBe($before);
})->with([
    'computed row' => ['[[C::NONE, C::SOLID, C::COUNTER], array_fill(0, 2, C::NONE)]', 'literal list of enum cases'],
    'computed cell' => ['[[C::from(0), C::SOLID, C::COUNTER], [C::NONE, C::NONE]]', 'must be a literal enum case'],
    'commented cell' => ['[[C::NONE, /* keep */ C::SOLID, C::COUNTER], [C::NONE, C::NONE]]', 'contains comments'],
]);
