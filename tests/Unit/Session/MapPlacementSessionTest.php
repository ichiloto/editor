<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

function mapPlacementProject(): string
{
    $root = makeTemporaryProject('ichiloto-map-placement-');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['npcs'] = [['id' => 'guide', 'name' => 'Guide', 'x' => 1, 'y' => 1, 'script' => [
        ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide', 'wait' => true, 'steps' => [
            ['direction' => 'right', 'count' => 2, 'faceOnly' => false, 'seconds' => 0.25, 'custom' => 'preserved'],
            ['direction' => 'up', 'count' => 3, 'faceOnly' => true],
        ]],
        ['type' => 'transfer', 'map' => 'test-map', 'x' => 2, 'y' => 1],
    ]]];
    file_put_contents($path, "<?php\n// Authored map note.\nreturn " . var_export($data, true) . ";\n");
    return $root;
}

function findMapPlacementRow(array $view, string $kind): array
{
    return array_find($view['rows'], static fn(array $row): bool => ($row['mapPlacement']['kind'] ?? null) === $kind)
        ?? throw new RuntimeException('Missing placement ' . $kind);
}

it('edits NPC route endpoints as one source preserving undo step without changing timing facing or identity', function () {
    $root = mapPlacementProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $read = $session->readNpc('test-map', 0, ['script']);
    $row = findMapPlacementRow($read, 'route');
    expect($row['mapPlacement']['points'][0]['point'])->toBe([2, 0])
        ->and($row['mapPlacement']['points'][1]['point'])->toBe([2, 0]);
    $context = ['kind' => 'npc', 'map' => 'test-map', 'revision' => $read['revision'], 'index' => 0];
    $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], 'test-map', 0, 1, 3, [1, 1], $read['revision']);
    $changed = findMapPlacementRow($session->readNpc('test-map', 0, ['script']), 'route');
    expect($changed['mapPlacement']['points'][0]['point'])->toBe([0, 2])
        ->and($session->undo()['label'])->toBe('Place on map');
    $session->saveMap('test-map');
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $session->saveMap('test-map');
    $saved = require $path;
    expect(file_get_contents($path))->toContain('// Authored map note.')
        ->and($saved['npcs'][0]['id'])->toBe('guide')
        ->and($saved['npcs'][0]['script'][0]['steps'][0])->toMatchArray(['direction' => 'down', 'count' => 2, 'faceOnly' => false, 'seconds' => 0.25, 'custom' => 'preserved'])
        ->and(findMapPlacementRow(EditorSession::open($root)->readNpc('test-map', 0, ['script']), 'route')['mapPlacement']['points'][0]['point'])->toBe([0, 2]);
});

it('refuses diagonal outside and stale route gestures without changing history', function () {
    $session = EditorSession::open(mapPlacementProject());
    $read = $session->readNpc('test-map', 0, ['script']);
    $row = findMapPlacementRow($read, 'route');
    $context = ['kind' => 'npc', 'map' => 'test-map', 'revision' => $read['revision'], 'index' => 0];
    expect(fn() => $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], 'test-map', 0, 3, 3, [1, 1], $read['revision']))->toThrow(SessionRefusal::class, 'cardinal')
        ->and(fn() => $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], 'test-map', 0, 99, 1, [1, 1], $read['revision']))->toThrow(SessionRefusal::class, 'inside')
        ->and(fn() => $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], 'test-map', 0, 2, 1, null, $read['revision']))->toThrow(SessionRefusal::class, 'origin')
        ->and($session->undo()['label'])->toBeNull();
    $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], 'test-map', 1, 5, 1, [1, 1], $read['revision']);
    $next = findMapPlacementRow($session->readNpc('test-map', 0, ['script']), 'route');
    expect($next['mapPlacement']['points'][1]['point'])->toBe([2, 0]);
    expect(fn() => $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], 'test-map', 0, 2, 1, [1, 1], $read['revision']))->toThrow(SessionRefusal::class);
});

it('places transfer command coordinates together through shared schema rows', function () {
    $session = EditorSession::open(mapPlacementProject());
    $read = $session->readNpc('test-map', 0, ['script']);
    $row = findMapPlacementRow($read, 'transfer');
    $session->applyMapPlacement(['kind' => 'npc', 'map' => 'test-map', 'revision' => $read['revision'], 'index' => 0],
        $row['key'], $row['mapPlacement'], 'test-map', 0, 4, 3, null, $read['revision']);
    expect(findMapPlacementRow($session->readNpc('test-map', 0, ['script']), 'transfer')['mapPlacement']['points'][0]['point'])->toBe([4, 3]);
    $session->undo();
    expect(findMapPlacementRow($session->readNpc('test-map', 0, ['script']), 'transfer')['mapPlacement']['points'][0]['point'])->toBe([2, 1]);
});

it('places transfer events through the atomic destination service and preserves unknown fields', function () {
    $root = mapPlacementProject();
    $session = EditorSession::open($root);
    $revision = $session->readMap('test-map')['revision'];
    $created = $session->createEvent('test-map', $revision, [[4, 1]], 'Transfer Player');
    $session->saveMap('test-map');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['events'][$created['marker']]['data']['spawnPoint']['annotation'] = 'keep arrival metadata';
    file_put_contents($path, "<?php\n// Keep transfer source notes.\nreturn " . var_export($data, true) . ";\n");
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $read = $session->readInspector('test-map', $created['marker']);
    $row = findMapPlacementRow($read, 'event-transfer');
    $session->applyMapPlacement(['kind' => 'event', 'map' => 'test-map', 'revision' => $read['revision']],
        $row['key'], $row['mapPlacement'], 'test-map', 0, 5, 2, null, $read['revision']);
    expect(findMapPlacementRow($session->readInspector('test-map', $created['marker']), 'event-transfer')['mapPlacement']['points'][0]['point'])->toBe([5, 2]);
    $session->undo();
    expect(findMapPlacementRow($session->readInspector('test-map', $created['marker']), 'event-transfer')['mapPlacement'])->toBe($row['mapPlacement']);
    $session->saveMap('test-map');
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $session->saveMap('test-map');
    expect(findMapPlacementRow(EditorSession::open($root)->readInspector('test-map', $created['marker']), 'event-transfer')['mapPlacement']['points'][0]['point'])->toBe([5, 2])
        ->and(file_get_contents($path))->toContain('// Keep transfer source notes.', 'keep arrival metadata');
});

it('exposes cinematic map targets but never screen or actor relative coordinates and preserves nested source', function () {
    $root = cutsceneProject();
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, <<<'PHP'
<?php
// Keep this script comment.
return [['type' => 'sequence', 'commands' => [
    ['type' => 'camera', 'operation' => 'pan', 'target' => ['kind' => 'position', 'x' => 1, 'y' => 1], 'seconds' => 0.5],
    ['type' => 'field_animation', 'effect' => 'spark', 'target' => ['kind' => 'position', 'x' => 2, 'y' => 1]],
    ['type' => 'field_animation', 'effect' => 'spark', 'target' => ['kind' => 'screen_position', 'x' => 2, 'y' => 1]],
    ['type' => 'camera', 'operation' => 'focus', 'target' => ['kind' => 'npc', 'id' => 'guide']],
    ['type' => 'camera', 'operation' => 'route', 'points' => [['kind' => 'position', 'x' => 1, 'y' => 1, 'seconds' => 0.25]]],
]]];
PHP);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $read = $session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands', 0, 'commands']);
    $placements = array_values(array_filter($read['rows'], static fn(array $r): bool => isset($r['mapPlacement'])));
    expect(array_column(array_column($placements, 'mapPlacement'), 'kind'))->toBe(['camera', 'effect', 'camera']);
    $row = $placements[0];
    // Transport object member order is not a source revision.
    $key = array_reverse($row['key'], true);
    $context = ['kind' => 'database', 'category' => 'cutscenes/cinematic', 'index' => 0];
    $previewRevision = $session->readMap('harbour')['revision'];
    $session->applyMapPlacement($context, $key, array_reverse($row['mapPlacement'], true), 'harbour', 0, 3, 2, null, $previewRevision);
    expect(findMapPlacementRow($session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands', 0, 'commands']), 'camera')['mapPlacement']['points'][0]['point'])->toBe([3, 2]);
    expect(fn() => $session->applyMapPlacement($context, $key, $row['mapPlacement'], 'harbour', 0, 4, 2, null, $previewRevision))->toThrow(SessionRefusal::class, 'changed');
    $session->undo();
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($path))->toContain('// Keep this script comment.', "'seconds' => 0.5")
        ->and(findMapPlacementRow(EditorSession::open($root)->readDatabaseRecord('cutscenes/cinematic', 0, ['commands', 0, 'commands']), 'camera')['mapPlacement']['points'][0]['point'])->toBe([3, 2]);
});

it('edits inline event script paths using the same map placement and grouped inspector service', function () {
    $root = mapPlacementProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['events'] = ['S' => ['class' => \Ichiloto\Engine\Events\Triggers\StoryTrigger::class, 'data' => [
        'script' => [['type' => 'move_route', 'subject' => 'player', 'steps' => [['direction' => 'right', 'count' => 1, 'faceOnly' => false, 'seconds' => 0.4]]]],
    ]]];
    file_put_contents($path, "<?php\nreturn " . var_export($data, true) . ";\n");
    $session = EditorSession::open($root);
    $read = $session->readInspector('test-map', 'S');
    $row = findMapPlacementRow($read, 'route');
    $session->applyMapPlacement(['kind' => 'event', 'map' => 'test-map', 'revision' => $read['revision']],
        $row['key'], $row['mapPlacement'], 'test-map', 0, 1, 3, [1, 1], $read['revision']);
    expect(findMapPlacementRow($session->readInspector('test-map', 'S'), 'route')['mapPlacement']['points'][0]['point'])->toBe([0, 2]);
    $session->undo();
    expect(findMapPlacementRow($session->readInspector('test-map', 'S'), 'route')['mapPlacement'])->toBe($row['mapPlacement']);
});

it('keeps the existing resize inspector safe for NPCs with undo and redo', function () {
    $session = EditorSession::open(mapPlacementProject());
    $read = $session->readInspector('test-map');
    $row = array_find($read['rows'], static fn(array $r): bool => ($r['key']['target'] ?? '') === 'map-size' && ($r['key']['field'] ?? '') === 'width');
    expect($row['kind'])->toBe('integer');
    expect(fn() => $session->applyInspector('test-map', $read['revision'], $row['key'], '1'))->toThrow(SessionRefusal::class, 'stranded');
    $session->applyInspector('test-map', $read['revision'], $row['key'], '15');
    expect($session->readMap('test-map')['width'])->toBe(15);
    $session->undo();
    expect($session->readMap('test-map')['width'])->toBe(12);
    $session->redo();
    expect($session->readMap('test-map')['width'])->toBe(15);
});

it('does not flatten expression owned coordinates or retain half of a grouped edit', function () {
    $root = makeTemporaryProject('ichiloto-map-placement-expression-');
    $path = $root . '/assets/Events/placement-expression.php';
    file_put_contents($path, <<<'PHP'
<?php
// This value remains authored PHP.
$arrival = 1;
return [['type' => 'transfer', 'map' => 'test-map', 'x' => 2, 'y' => abs($arrival)]];
PHP);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $labels = $session->listDatabaseRecords('common_events')['records'];
    $index = array_search('placement-expression', $labels, true);
    expect($index)->not->toBeFalse();
    $row = findMapPlacementRow($session->readDatabaseRecord('common_events', $index), 'transfer');
    expect(fn() => $session->applyMapPlacement(['kind' => 'database', 'category' => 'common_events', 'index' => $index],
        $row['key'], $row['mapPlacement'], 'test-map', 0, 4, 3, null, $session->readMap('test-map')['revision']))->toThrow(SessionRefusal::class);
    expect(findMapPlacementRow($session->readDatabaseRecord('common_events', $index), 'transfer')['mapPlacement'])->toBe($row['mapPlacement'])
        ->and($session->undo()['label'])->toBeNull();
    $session->saveDatabase('common_events');
    expect(file_get_contents($path))->toBe($original);
});

it('refuses a route edit that would move later authored points outside the preview map', function () {
    $root = mapPlacementProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['npcs'][0]['script'][0]['steps'][1] = ['direction' => 'down', 'count' => 2, 'faceOnly' => false];
    file_put_contents($path, "<?php\nreturn " . var_export($data, true) . ";\n");
    $session = EditorSession::open($root);
    $read = $session->readNpc('test-map', 0, ['script']);
    $row = findMapPlacementRow($read, 'route');
    expect(fn() => $session->applyMapPlacement(['kind' => 'npc', 'map' => 'test-map', 'revision' => $read['revision'], 'index' => 0],
        $row['key'], $row['mapPlacement'], 'test-map', 0, 1, 4, [1, 1], $read['revision']))->toThrow(SessionRefusal::class, 'Step 2 would leave')
        ->and($session->undo()['label'])->toBeNull();
});

it('round trips common event route placement over the host protocol', function () {
    $root = makeTemporaryProject('ichiloto-map-placement-protocol-');
    $path = $root . '/assets/Events/placement-route.php';
    file_put_contents($path, <<<'PHP'
<?php
// Keep route timing and annotations.
return [['type' => 'move_route', 'subject' => 'player', 'steps' => [
    ['direction' => 'right', 'count' => 1, 'faceOnly' => false, 'seconds' => 0.3, 'annotation' => 'keep'],
]]];
PHP);
    $original = file_get_contents($path);
    $host = new \Ichiloto\Editor\Session\SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array => $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params]));
    $request('hello', ['protocol' => \Ichiloto\Editor\Session\SessionHost::PROTOCOL, 'project' => $root]);
    $index = array_search('placement-route', $request('database.records', ['category' => 'common_events'])['result']['records'], true);
    expect($index)->not->toBeFalse();
    $record = ['category' => 'common_events', 'index' => $index];
    $row = findMapPlacementRow($request('database.record', $record)['result'], 'route');
    $gesture = ['context' => ['kind' => 'database', ...$record], 'key' => $row['key'], 'expected' => $row['mapPlacement'],
        'previewMap' => 'test-map', 'previewRevision' => $request('map.read', ['map' => 'test-map'])['result']['revision'],
        'point' => 0, 'x' => 1, 'y' => 3, 'origin' => [1, 1]];
    expect($request('mapPlacement.apply', [...$gesture, 'origin' => ['1', 1]])['error']['kind'])->toBe('request')
        ->and($request('mapPlacement.apply', [...$gesture, 'previewRevision' => '0'])['error']['kind'])->toBe('request')
        ->and($request('mapPlacement.apply', array_diff_key($gesture, ['previewRevision' => true]))['error']['kind'])->toBe('request')
        ->and($request('mapPlacement.apply', $gesture)['result']['changed'])->toBeTrue()
        ->and($request('history.undo')['result']['label'])->toBe('Place on map');
    $request('database.save', ['category' => 'common_events']);
    expect(file_get_contents($path))->toBe($original);
    $request('history.redo');
    $request('database.save', ['category' => 'common_events']);
    $saved = require $path;
    expect($saved[0]['steps'][0])->toMatchArray(['direction' => 'down', 'count' => 2, 'seconds' => 0.3, 'annotation' => 'keep'])
        ->and(file_get_contents($path))->toContain('// Keep route timing and annotations.')
        ->and(findMapPlacementRow(EditorSession::open($root)->readDatabaseRecord('common_events', $index), 'route')['mapPlacement']['points'][0]['point'])->toBe([0, 2]);
});

it('edits one camera route point without shifting other absolute targets or inventing actor relative geometry', function () {
    $root = cutsceneProject();
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, <<<'PHP'
<?php
return [['type'=>'camera', 'operation'=>'route', 'points'=>[
    ['kind'=>'position', 'x'=>1, 'y'=>1, 'seconds'=>0.2],
    ['kind'=>'position', 'x'=>2, 'y'=>1, 'seconds'=>0.3],
    ['kind'=>'npc', 'id'=>'guide', 'seconds'=>0.4],
    ['kind'=>'position', 'x'=>4, 'y'=>2, 'seconds'=>0.5],
]]];
PHP);
    $session = EditorSession::open($root);
    $rows = array_values(array_filter($session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands'])['rows'],
        static fn(array $row): bool => isset($row['mapPlacement'])));
    expect($rows)->toHaveCount(3)
        ->and($rows[1]['mapPlacement']['selectedPoint'])->toBe(1)
        ->and(array_column($rows[1]['mapPlacement']['points'], 'point'))->toBe([[1, 1], [2, 1], [4, 2]])
        ->and($rows[1]['mapPlacement']['points'][1]['from'])->toBe([1, 1])
        ->and($rows[1]['mapPlacement']['points'][2]['from'])->toBeNull();
    $session->applyMapPlacement(['kind'=>'database', 'category'=>'cutscenes/cinematic', 'index'=>0],
        $rows[1]['key'], $rows[1]['mapPlacement'], 'harbour', 1, 3, 2, null, $session->readMap('harbour')['revision']);
    $session->saveDatabase('cutscenes/cinematic');
    $saved = require $path;
    expect($saved[0]['points'])->toBe([
        ['kind'=>'position', 'x'=>1, 'y'=>1, 'seconds'=>0.2],
        ['kind'=>'position', 'x'=>3, 'y'=>2, 'seconds'=>0.3],
        ['kind'=>'npc', 'id'=>'guide', 'seconds'=>0.4],
        ['kind'=>'position', 'x'=>4, 'y'=>2, 'seconds'=>0.5],
    ]);
});

it('refuses incomplete or wrongly typed map axes without defaulting them to another cell', function (mixed $x, mixed $y) {
    $fields = [
        ['entry'=>[0], 'sourceKey'=>'target.x', 'field'=>'x', 'dataPath'=>['target', 'x']],
        ['entry'=>[0], 'sourceKey'=>'target.y', 'field'=>'y', 'dataPath'=>['target', 'y']],
    ];
    $fields = \Ichiloto\Editor\Maps\MapPlacement::describeFields(['type'=>'field_animation', 'target'=>['kind'=>'position', 'x'=>$x, 'y'=>$y]], $fields);
    $placement = $fields[0]['mapPlacement'];
    expect($placement['issue'])->toContain('explicit non-negative integer');
    expect(fn() => \Ichiloto\Editor\Maps\MapPlacement::getChanges($placement, 0, 2, 1, null, 'synthetic'))
        ->toThrow(InvalidArgumentException::class, 'explicit non-negative integer');
})->with([[null, 1], [1, null], [1.5, 1], ['1', 1], [-1, 1]]);

it('refuses current cinematic target expressions even when the evaluated coordinate or kind would not change', function (string $command, string $kind) {
    $root = cutsceneProject();
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, "<?php\nreturn [$command];\n");
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $row = findMapPlacementRow($session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands']), $kind);
    expect($row['mapPlacement']['issue'])->toContain('literal source');
    expect(fn() => $session->applyMapPlacement(['kind'=>'database', 'category'=>'cutscenes/cinematic', 'index'=>0],
        $row['key'], $row['mapPlacement'], 'harbour', 0, 3, 1, null, $session->readMap('harbour')['revision']))->toThrow(SessionRefusal::class, 'literal source');
    expect($session->undo()['label'])->toBeNull();
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($path))->toBe($original);
})->with([
    'camera unchanged axis' => ["['type'=>'camera','operation'=>'pan','target'=>['kind'=>'position','x'=>2,'y'=>abs(1)],'seconds'=>0.5]", 'camera'],
    'camera route axis' => ["['type'=>'camera','operation'=>'route','points'=>[['kind'=>'position','x'=>2,'y'=>abs(1),'seconds'=>0.5]]]", 'camera'],
    'field effect unchanged axis' => ["['type'=>'field_animation','effect'=>'spark','target'=>['kind'=>'position','x'=>2,'y'=>abs(1)]]", 'effect'],
    'field effect expression kind' => ["['type'=>'field_animation','effect'=>'spark','target'=>['kind'=>strtolower('POSITION'),'x'=>2,'y'=>1]]", 'effect'],
]);

it('does not record unchanged inline placement values as a new undo step', function () {
    $root = mapPlacementProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['events']['S'] = ['class'=>\Ichiloto\Engine\Events\Triggers\StoryTrigger::class,
        'data'=>['script'=>[['type'=>'transfer', 'map'=>'test-map', 'x'=>2, 'y'=>1]]]];
    file_put_contents($path, "<?php\nreturn " . var_export($data, true) . ";\n");
    $session = EditorSession::open($root);
    $read = $session->readInspector('test-map', 'S');
    $row = findMapPlacementRow($read, 'transfer');
    $result = $session->applyMapPlacement(['kind'=>'event', 'map'=>'test-map', 'revision'=>$read['revision']],
        $row['key'], $row['mapPlacement'], 'test-map', 0, 2, 1, null, $read['revision']);
    expect($result['changed'])->toBeFalse()->and($result['revision'])->toBe($read['revision'])
        ->and($session->undo()['label'])->toBeNull();
});

it('places authored waypoints and retains runtime owned retrace geometry', function () {
    foreach ([['waypoints' => [['x' => 2, 'y' => 1]]], ['retrace' => 'remembered-route']] as $mode) {
        $root = mapPlacementProject();
        $path = $root . '/assets/Maps/test-map/test-map.data.php';
        $data = require $path;
        $data['npcs'][0]['script'][0] = ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide', ...$mode];
        file_put_contents($path, "<?php\nreturn " . var_export($data, true) . ";\n");
        $original = file_get_contents($path);
        $session = EditorSession::open($root);
        $read = $session->readNpc('test-map', 0, ['script']);
        $row = findMapPlacementRow($read, isset($mode['waypoints']) ? 'waypoints' : 'route');
        if (isset($mode['waypoints'])) {
            expect($row['mapPlacement']['issue'])->toBeNull();
            $session->applyMapPlacement(['kind' => 'npc', 'map' => 'test-map', 'revision' => $read['revision'], 'index' => 0],
                $row['key'], $row['mapPlacement'], 'test-map', 0, 3, 2, null, $read['revision']);
            expect(findMapPlacementRow($session->readNpc('test-map', 0, ['script']), 'waypoints')['mapPlacement']['points'][0]['point'])->toBe([3, 2]);
            $session->undo();
        } else {
            expect($row['mapPlacement']['issue'])->toContain('completed runtime route record')
                ->and($row['mapPlacement']['points'])->toBe([])
                ->and(array_find($read['rows'], static fn(array $r): bool => ($r['reference'] ?? '') === 'cinematic_movement_routes'))->not->toBeNull()
                ->and(fn() => $session->applyMapPlacement(['kind' => 'npc', 'map' => 'test-map', 'revision' => $read['revision'], 'index' => 0],
                    $row['key'], $row['mapPlacement'], 'test-map', 0, 2, 1, [1, 1], $read['revision']))->toThrow(SessionRefusal::class, 'completed runtime route record')
                ->and($session->undo()['label'])->toBeNull();
        }
        $session->saveMap('test-map');
        expect(file_get_contents($path))->toBe($original);
    }
});

it('refuses type changed descriptors and keys but accepts reordered object members', function () {
    $session = EditorSession::open(mapPlacementProject());
    $read = $session->readNpc('test-map', 0, ['script']);
    $row = findMapPlacementRow($read, 'transfer');
    $context = ['kind' => 'npc', 'map' => 'test-map', 'revision' => $read['revision'], 'index' => 0];
    $expected = $row['mapPlacement'];
    $expected['points'][0]['point'][0] = '2';
    expect(fn() => $session->applyMapPlacement($context, $row['key'], $expected, 'test-map', 0, 4, 3, null, $read['revision']))
        ->toThrow(SessionRefusal::class, 'changed');
    $key = $row['key'];
    $key['frame'] = ['script', '0'];
    expect(fn() => $session->applyMapPlacement($context, $key, $row['mapPlacement'], 'test-map', 0, 4, 3, null, $read['revision']))
        ->toThrow(SessionRefusal::class);
    expect($session->undo()['label'])->toBeNull();
    $session->applyMapPlacement($context, array_reverse($row['key'], true), array_reverse($row['mapPlacement'], true),
        'test-map', 0, 4, 3, null, $read['revision']);
    expect(findMapPlacementRow($session->readNpc('test-map', 0, ['script']), 'transfer')['mapPlacement']['points'][0]['point'])->toBe([4, 3]);
});

it('refuses stale preview map revisions independently of the database owner', function () {
    $root = cutsceneProject();
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, "<?php\nreturn [['type'=>'move_route','subject'=>'player','steps'=>[['direction'=>'right','count'=>1]]]];\n");
    $session = EditorSession::open($root);
    $read = $session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands']);
    $row = findMapPlacementRow($read, 'route');
    $context = ['kind' => 'database', 'category' => 'cutscenes/cinematic', 'index' => 0];
    $map = $session->readMap('harbour');
    $inspector = $session->readInspector('harbour');
    $width = array_find($inspector['rows'], static fn(array $row): bool => ($row['key']['target'] ?? '') === 'map-size' && ($row['key']['field'] ?? '') === 'width');
    $session->applyInspector('harbour', $map['revision'], $width['key'], (string) ($map['width'] + 1));
    expect(fn() => $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], 'harbour', 0, 3, 2, [1, 1], $map['revision']))
        ->toThrow(SessionRefusal::class, 'changed since revision');
    expect(findMapPlacementRow($session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands']), 'route')['mapPlacement'])->toBe($row['mapPlacement']);
    expect($session->undo()['label'])->not->toBe('Place on map');
});

it('refuses an old database review after a timing edit and undo even when geometry matches again', function () {
    $root = cutsceneProject();
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, "<?php\nreturn [['type'=>'move_route','subject'=>'player','steps'=>[['direction'=>'right','count'=>1,'seconds'=>0.4]]]];\n");
    $session = EditorSession::open($root);
    $read = $session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands']);
    $row = findMapPlacementRow($read, 'route');
    $timing = array_find($read['rows'], static fn(array $row): bool => ($row['name'] ?? '') === 'Seconds');
    expect($timing)->not->toBeNull();
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $timing['key'], '0.8');
    $session->undo();
    expect(fn() => $session->applyMapPlacement(['kind'=>'database', 'category'=>'cutscenes/cinematic', 'index'=>0],
        $row['key'], $row['mapPlacement'], 'harbour', 0, 3, 1, [1, 1], $session->readMap('harbour')['revision']))
        ->toThrow(SessionRefusal::class, 'changed');
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($path))->toContain("'seconds'=>0.4");
});

it('refuses unchanged expression coordinates before any route or target mutation', function (string $owner, string $command, string $kind) {
    $root = mapPlacementProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    if ($owner === 'database') {
        $path = $root . '/assets/Events/placement-source.php';
        file_put_contents($path, "<?php\nreturn [$command];\n");
    } else {
        $data = require $path;
        if ($owner === 'npc') {
            $data['npcs'][0]['script'] = ['COMMAND_SOURCE'];
        } else {
            $data['events']['S'] = ['class' => \Ichiloto\Engine\Events\Triggers\StoryTrigger::class, 'data' => ['script' => ['COMMAND_SOURCE']]];
        }
        file_put_contents($path, "<?php\nreturn " . str_replace("'COMMAND_SOURCE'", $command, var_export($data, true)) . ";\n");
    }
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $context = ['kind' => $owner, 'map' => 'test-map', 'revision' => $session->readMap('test-map')['revision'], 'index' => 0];
    if ($owner === 'database') {
        $index = array_search('placement-source', $session->listDatabaseRecords('common_events')['records'], true);
        $context = ['kind' => 'database', 'category' => 'common_events', 'index' => $index];
        $read = $session->readDatabaseRecord('common_events', $index);
    } else {
        $read = $owner === 'npc' ? $session->readNpc('test-map', 0, ['script']) : $session->readInspector('test-map', 'S');
    }
    $row = findMapPlacementRow($read, $kind);
    expect($row['mapPlacement']['issue'])->toContain('literal source');
    expect(fn() => $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], 'test-map', 0, 4, 1,
        $kind === 'route' ? [1, 1] : null, $session->readMap('test-map')['revision']))->toThrow(SessionRefusal::class, 'literal source');
    expect($session->undo()['label'])->toBeNull();
    $owner === 'database' ? $session->saveDatabase('common_events') : $session->saveMap('test-map');
    expect(file_get_contents($path))->toBe($original);
})->with([
    'NPC transfer unchanged y' => ['npc', "['type'=>'transfer','map'=>'test-map','x'=>2,'y'=>abs(1)]", 'transfer'],
    'inline transfer unchanged y' => ['event', "['type'=>'transfer','map'=>'test-map','x'=>2,'y'=>abs(1)]", 'transfer'],
    'common transfer unchanged y' => ['database', "['type'=>'transfer','map'=>'test-map','x'=>2,'y'=>abs(1)]", 'transfer'],
    'NPC route count' => ['npc', "['type'=>'move_route','subject'=>'player','steps'=>[['direction'=>'right','count'=>abs(1)]]]", 'route'],
]);

it('refuses an external literal or expression rewrite rather than authoring over a stale source review', function (bool $expression) {
    $root = mapPlacementProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $session = EditorSession::open($root);
    $read = $session->readNpc('test-map', 0, ['script']);
    $row = findMapPlacementRow($read, 'transfer');
    file_put_contents($path, str_replace("'x' => 2", $expression ? "'x' => abs(2)" : "'x' => 3", file_get_contents($path)));
    $external = file_get_contents($path);
    expect(fn() => $session->applyMapPlacement(['kind'=>'npc', 'map'=>'test-map', 'revision'=>$read['revision'], 'index'=>0],
        $row['key'], $row['mapPlacement'], 'test-map', 0, 4, 3, null, $read['revision']))->toThrow(SessionRefusal::class, 'changed');
    expect($session->undo()['label'])->toBeNull()->and(file_get_contents($path))->toBe($external);
})->with([false, true]);

it('round trips every cinematic map target with grouped undo and unchanged terminal map geometry', function (array $command, string $kind, array $frame) {
    $root = cutsceneProject();
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, "<?php\n// Preserve this author note.\nreturn " . var_export([$command], true) . ";\n");
    $original = file_get_contents($path);
    $mapSources = sourceHashTree($root . '/assets/Maps');
    $session = EditorSession::open($root);
    $row = findMapPlacementRow($session->readDatabaseRecord('cutscenes/cinematic', 0, $frame), $kind);
    $session->applyMapPlacement(['kind'=>'database', 'category'=>'cutscenes/cinematic', 'index'=>0],
        $row['key'], $row['mapPlacement'], 'harbour', 0, 3, 2, null, $session->readMap('harbour')['revision']);
    expect($session->undo()['label'])->toBe('Place on map');
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $session->saveDatabase('cutscenes/cinematic');
    $reopened = EditorSession::open($root);
    expect(findMapPlacementRow($reopened->readDatabaseRecord('cutscenes/cinematic', 0, $frame), $kind)['mapPlacement']['points'][0]['point'])->toBe([3, 2])
        ->and(file_get_contents($path))->toContain('// Preserve this author note.', "'annotation' => 'keep'")
        ->and(sourceHashTree($root . '/assets/Maps'))->toBe($mapSources);
})->with([
    'camera pan' => [['type'=>'camera', 'operation'=>'pan', 'target'=>['kind'=>'position', 'x'=>1, 'y'=>1], 'seconds'=>0.5, 'annotation'=>'keep'], 'camera', ['commands']],
    'camera route point' => [['type'=>'camera', 'operation'=>'route', 'points'=>[['kind'=>'position', 'x'=>1, 'y'=>1, 'seconds'=>0.25, 'annotation'=>'keep']]], 'camera', ['commands']],
    'field effect map target' => [['type'=>'field_animation', 'effect'=>'spark', 'target'=>['kind'=>'position', 'x'=>1, 'y'=>1], 'annotation'=>'keep'], 'effect', ['commands']],
    'nested effect target' => [['type'=>'branch', 'conditions'=>[], 'then'=>[['type'=>'field_animation', 'effect'=>'spark', 'target'=>['kind'=>'position', 'x'=>1, 'y'=>1], 'annotation'=>'keep']]], 'effect', ['commands', 0, 'then']],
    'transfer target' => [['type'=>'transfer', 'map'=>'harbour', 'x'=>1, 'y'=>1, 'annotation'=>'keep'], 'transfer', ['commands']],
    'move player target' => [['type'=>'move_player', 'x'=>1, 'y'=>1, 'annotation'=>'keep'], 'position', ['commands']],
]);
