<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Database\MovementRouteFields;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Cutscenes\Preview\PreviewField;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\MovementRouteRunner;
use Ichiloto\Engine\Exceptions\MovementRouteException;

function routeAuthoringRow(array $view, string $name, int $command = 0): array
{
    return array_find($view['rows'], static fn(array $row): bool => ($row['entry'] ?? []) === [$command]
        && ($row['name'] ?? '') === $name)
        ?? throw new RuntimeException('Missing route field ' . $name);
}

function inlineRouteRow(EditorSession $session, array $path): array
{
    return array_find($session->readInspector('test-map', 'S')['rows'], static fn(array $row): bool => ($row['key']['path'] ?? []) === $path)
        ?? throw new RuntimeException('Missing inline route field ' . implode('.', $path));
}

function makeRouteOwnerProject(): array
{
    $root = cutsceneProject();
    $remember = static fn(string $id): array => ['type' => 'move_route', 'remember' => $id, 'steps' => [['direction' => 'right']]];
    $commands = [
        [...$remember('root-recorded'), 'annotation' => $remember('not-a-command')],
        ['type' => 'common_event', 'id' => 'route-shared'],
        ['type' => 'sequence', 'commands' => [['type' => 'move_route', 'retrace' => 'root-recorded']]],
        ['type' => 'wait', 'seconds' => 1],
    ];
    foreach ([
        'route-owner' => $commands,
        'route-shared' => [$remember('common-recorded'), ['type' => 'common_event', 'id' => 'route-cycle']],
        'route-cycle' => [$remember('cycle-recorded'), ['type' => 'common_event', 'id' => 'route-shared']],
        'route-other' => [$remember('unrelated-common')],
    ] as $id => $script) {
        file_put_contents($root . '/assets/Events/' . $id . '.php', "<?php\n// Preserve common source.\nreturn " . var_export($script, true) . ";\n");
    }
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $data = require $mapPath;
    $data['npcs'][0]['script'] = $commands;
    $data['npcs'][0]['dialogue'] = [
        ['script' => [$remember('variant-a'), ['type' => 'move_route', 'retrace' => 'variant-a'], $remember('variant-a-second')]],
        ['conditions' => ['flag=on'], 'script' => [$remember('variant-b'), ['type' => 'move_route', 'retrace' => 'variant-b']]],
    ];
    $data['npcs'][] = ['id' => 'other', 'name' => 'Other', 'sprite' => 'O', 'x' => 6, 'y' => 1, 'script' => [$remember('other-npc')]];
    $data['events']['S'] = ['class' => \Ichiloto\Engine\Events\Triggers\ScriptEventTrigger::class, 'data' => ['script' => $commands]];
    $data['events']['T'] = ['class' => \Ichiloto\Engine\Events\Triggers\ScriptEventTrigger::class, 'data' => ['script' => [$remember('other-event')]]];
    $source = "<?php\n// Preserve map source.\nreturn " . var_export($data, true) . ";\n";
    $source = str_replace("'seconds' => 1,", "'seconds' => abs(1),", $source);
    file_put_contents($mapPath, $source);
    return [$root, $mapPath];
}

it('transports exact route row owners through the real GUI host boundary without changing source', function () {
    [$root] = makeRouteOwnerProject();
    $before = sourceHashTree($root);
    $input = fopen('php://memory', 'r');
    $output = fopen('php://memory', 'w+');
    $diagnostics = fopen('php://memory', 'w+');
    $host = new \Ichiloto\Editor\Session\SessionHost($input, $output, $diagnostics);
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    $list = static fn(?string $map, array $owner): array =>
        $request('references.list', ['map' => $map, 'category' => 'cinematic_movement_routes', 'record' => $owner]);
    try {
        expect($request('hello', ['protocol' => \Ichiloto\Editor\Session\SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');
        foreach ([['script', 2, 'commands'], [0, 'script'], [1, 'script']] as $frame) {
            $view = $request('npc.read', ['map' => 'harbour', 'index' => 0, 'frame' => $frame])['result'];
            $command = $frame[0] === 'script' ? 0 : 1;
            $row = routeAuthoringRow($view, 'Recorded Route', $command);
            $reply = $list($view['map'], ['kind' => 'npc', 'index' => 0, 'frame' => $row['key']['frame']]);
            $expected = match ($frame[0]) {
                'script' => ['root-recorded', 'common-recorded', 'cycle-recorded'],
                0 => ['variant-a', 'variant-a-second'],
                1 => ['variant-b'],
            };
            expect($reply)->toHaveKey('result')->and(array_column($reply['result'], 'value'))->toBe($expected);
        }
        $event = $request('inspector.read', ['map' => 'harbour', 'event' => 'S'])['result'];
        $row = array_find($event['rows'], static fn(array $row): bool => ($row['reference'] ?? '') === 'cinematic_movement_routes');
        expect($row)->not->toBeNull();
        $reply = $list($event['map'], ['kind' => 'event', 'marker' => $row['key']['marker'], 'path' => $row['key']['path']]);
        expect($reply)->toHaveKey('result')
            ->and(array_column($reply['result'], 'value'))->toBe(['root-recorded', 'common-recorded', 'cycle-recorded']);

        $index = array_search('route-owner', $request('database.records', ['category' => 'common_events'])['result']['records'], true);
        expect($index)->toBeInt();
        $view = $request('database.record', ['category' => 'common_events', 'index' => $index, 'frame' => [2, 'commands']])['result'];
        $row = routeAuthoringRow($view, 'Recorded Route');
        $reply = $list(null, ['category' => 'common_events', 'index' => $index, 'frame' => $row['key']['frame']]);
        expect($reply)->toHaveKey('result')
            ->and(array_column($reply['result'], 'value'))->toBe(['root-recorded', 'common-recorded', 'cycle-recorded'])
            ->and($request('references.list', ['map' => 'harbour', 'category' => 'cinematic_movement_routes'])['result'])->toBe([])
            ->and($list(null, ['kind' => 'npc', 'index' => 0, 'frame' => ['script']]))->toHaveKey('error')
            ->and(sourceHashTree($root))->toBe($before);
        rewind($diagnostics);
        expect(stream_get_contents($diagnostics))->toBe('');
    } finally {
        $host->run();
        fclose($input);
        fclose($output);
        fclose($diagnostics);
    }
});

it('lists only declarations reachable from the explicit live command owner including cyclic common-event references', function () {
    [$root] = makeRouteOwnerProject();
    $session = EditorSession::open($root);
    foreach ([['kind' => 'npc', 'index' => 0, 'frame' => ['script', 2, 'commands']],
        ['kind' => 'event', 'marker' => 'S', 'path' => ['data', 'script', '2', 'commands', '0', 'retrace']]] as $owner) {
        expect(array_column($session->listReferences('harbour', 'cinematic_movement_routes', $owner), 'value'))
            ->toBe(['root-recorded', 'common-recorded', 'cycle-recorded']);
    }
    foreach ([0 => 'variant-a', 1 => 'variant-b'] as $index => $expected) {
        expect(array_column($session->listReferences('harbour', 'cinematic_movement_routes',
            ['kind' => 'npc', 'index' => 0, 'frame' => [$index, 'script']]), 'value'))->toBe($index === 0 ? [$expected, 'variant-a-second'] : [$expected]);
    }
    expect($session->listReferences('harbour', 'cinematic_movement_routes'))->toBe([])
        ->and(fn() => $session->listReferences(null, 'cinematic_movement_routes', ['kind' => 'npc', 'index' => 0, 'frame' => ['script']]))
        ->toThrow(SessionRefusal::class, 'requires its map')
        ->and(fn() => $session->listReferences('harbour', 'cinematic_movement_routes', ['kind' => 'event', 'marker' => 'missing', 'path' => ['data', 'script']]))
        ->toThrow(SessionRefusal::class, 'no longer present')
        ->and(fn() => $session->listReferences('harbour', 'cinematic_movement_routes', ['kind' => 'npc', 'index' => 0, 'frame' => [99, 'script']]))
        ->toThrow(SessionRefusal::class, 'no longer present');
});

it('writes and refuses NPC route references against the same invocation while preserving undo expressions and variants', function () {
    [$root, $mapPath] = makeRouteOwnerProject();
    $session = EditorSession::open($root);
    $original = file_get_contents($mapPath);
    $row = routeAuthoringRow($session->readNpc('harbour', 0, ['script', 2, 'commands']), 'Recorded Route');
    $revision = $session->readNpc('harbour', 0, ['script', 2, 'commands'])['revision'];
    foreach (['variant-a', 'other-npc', 'unrelated-common', 'not-a-command'] as $bad) {
        expect(fn() => $session->applyNpc('harbour', $revision, 0, $row['key'], $bad))->toThrow(SessionRefusal::class, 'Unknown cinematic_movement_routes');
    }
    expect($session->undo()['label'])->toBeNull()->and(file_get_contents($mapPath))->toBe($original);
    $session->applyNpc('harbour', $revision, 0, $row['key'], 'common-recorded');
    expect(routeAuthoringRow($session->readNpc('harbour', 0, ['script', 2, 'commands']), 'Recorded Route')['value'])->toBe('common-recorded');
    $session->undo();
    expect(routeAuthoringRow($session->readNpc('harbour', 0, ['script', 2, 'commands']), 'Recorded Route')['value'])->toBe('root-recorded');
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($original);
    $session->redo();
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toContain('// Preserve map source.', "'seconds' => abs(1)");
    $reopened = EditorSession::open($root);
    expect(routeAuthoringRow($reopened->readNpc('harbour', 0, ['script', 2, 'commands']), 'Recorded Route')['value'])->toBe('common-recorded');
    $variant = $reopened->readNpc('harbour', 0, [0, 'script']);
    $row = routeAuthoringRow($variant, 'Recorded Route', 1);
    expect(fn() => $reopened->applyNpc('harbour', $variant['revision'], 0, $row['key'], 'variant-b'))->toThrow(SessionRefusal::class, 'Unknown cinematic_movement_routes');
    expect(fn() => $reopened->applyNpc('harbour', $variant['revision'], 0, $row['key'], 'root-recorded'))->toThrow(SessionRefusal::class, 'Unknown cinematic_movement_routes');
    $reopened->applyNpc('harbour', $variant['revision'], 0, $row['key'], 'variant-a-second');
    $reopened->saveMap('harbour');
    expect((require $mapPath)['npcs'][0]['dialogue'][0]['script'][1]['retrace'])->toBe('variant-a-second')
        ->and((require $mapPath)['npcs'][0]['dialogue'][1]['script'][1]['retrace'])->toBe('variant-b');
    $reopened->undo();
    $reopened->saveMap('harbour');
    expect((require $mapPath)['npcs'][0]['dialogue'][0]['script'][1]['retrace'])->toBe('variant-a');
});

it('writes inline route references from the actual event script and refuses siblings without changing source or history', function () {
    [$root, $mapPath] = makeRouteOwnerProject();
    $session = EditorSession::open($root);
    $view = $session->readInspector('harbour', 'S');
    $path = ['data', 'script', '2', 'commands', '0', 'retrace'];
    $row = array_find($view['rows'], static fn(array $row): bool => ($row['key']['path'] ?? []) === $path);
    expect($row)->not->toBeNull();
    $original = file_get_contents($mapPath);
    expect(fn() => $session->applyInspector('harbour', $view['revision'], $row['key'], 'other-event'))
        ->toThrow(SessionRefusal::class, 'Unknown cinematic_movement_routes');
    expect($session->undo()['label'])->toBeNull()->and(file_get_contents($mapPath))->toBe($original);
    $session->applyInspector('harbour', $view['revision'], $row['key'], 'common-recorded');
    $session->saveMap('harbour');
    expect((require $mapPath)['events']['S']['data']['script'][2]['commands'][0]['retrace'])->toBe('common-recorded')
        ->and(file_get_contents($mapPath))->toContain('// Preserve map source.', "'seconds' => abs(1)");
    $session->undo();
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($original);
    $session->redo();
    $session->saveMap('harbour');
    expect(EditorSession::open($root)->listReferences('harbour', 'cinematic_movement_routes', ['kind' => 'event', 'marker' => 'S', 'path' => $path]))
        ->toHaveCount(3);
});

it('uses a standalone common-event record for list and apply rather than unrelated cinematic or event records', function () {
    [$root] = makeRouteOwnerProject();
    $session = EditorSession::open($root);
    $index = array_search('route-owner', $session->listDatabaseRecords('common_events')['records'], true);
    expect($index)->toBeInt();
    $owner = ['category' => 'common_events', 'index' => $index, 'frame' => [2, 'commands']];
    expect(array_column($session->listReferences(null, 'cinematic_movement_routes', $owner), 'value'))
        ->toBe(['root-recorded', 'common-recorded', 'cycle-recorded']);
    $row = routeAuthoringRow($session->readDatabaseRecord('common_events', $index, [2, 'commands']), 'Recorded Route');
    $path = $root . '/assets/Events/route-owner.php';
    $original = file_get_contents($path);
    expect(fn() => $session->applyDatabaseRecord('common_events', $index, $row['key'], 'unrelated-common'))
        ->toThrow(SessionRefusal::class, 'Unknown cinematic_movement_routes');
    $session->applyDatabaseRecord('common_events', $index, $row['key'], 'common-recorded');
    $session->saveDatabase('common_events');
    expect((require $path)[2]['commands'][0]['retrace'])->toBe('common-recorded')->and(file_get_contents($path))->toContain('// Preserve common source.');
    $session->undo();
    $session->saveDatabase('common_events');
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $session->saveDatabase('common_events');
    $reopened = EditorSession::open($root);
    expect(routeAuthoringRow($reopened->readDatabaseRecord('common_events', $index, [2, 'commands']), 'Recorded Route')['value'])->toBe('common-recorded');
});

it('shares all three runtime route shapes between event and cinematic schemas without projecting one into another', function () {
    foreach ([RecordSchemaCatalog::eventCommandList('script'), CutsceneSchemas::cinematicCommandList('commands')] as $schema) {
        foreach (['steps' => [['direction' => 'right', 'count' => 1]], 'waypoints' => [['x' => 0], ['y' => 2]], 'retrace' => 'outbound'] as $mode => $value) {
            $route = ['type' => 'move_route', 'subject' => 'player', $mode => $value, 'secondsPerStep' => 0.2, 'wait' => true];
            $fields = $schema->fieldsFor($route);
            $modeField = array_find($fields, static fn($f): bool => $f->key === MovementRouteFields::MODE_FIELD);
            expect($modeField->displayDefault)->toBe($mode)
                ->and($schema->removeConflictingFields($route, 'secondsPerStep'))->toBe($route)
                ->and($schema->nestedListFor($route)?->key)->toBe($mode === 'retrace' ? null : $mode);
            if ($mode === 'retrace') {
                expect(array_find($fields, static fn($f): bool => $f->key === 'retrace')->reference)->toBe('cinematic_movement_routes');
            }
        }
    }
    $route = ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide', 'remember' => 'outbound', 'speed' => 2.0,
        'steps' => [['direction' => 'left', 'faceOnly' => true, 'seconds' => 0.4]], 'annotation' => 'keep'];
    expect(MovementRouteFields::prepareEdit([...$route, MovementRouteFields::MODE_FIELD => 'steps'], MovementRouteFields::MODE_FIELD))->toBe($route);
    $waypoints = MovementRouteFields::prepareEdit([...$route, MovementRouteFields::MODE_FIELD => 'waypoints'], MovementRouteFields::MODE_FIELD);
    expect($waypoints)->toBe([...array_diff_key($route, ['steps' => true]), 'waypoints' => []]);
    $retrace = MovementRouteFields::prepareEdit([...$waypoints, MovementRouteFields::MODE_FIELD => 'retrace'], MovementRouteFields::MODE_FIELD);
    expect($retrace)->toBe([...array_diff_key($waypoints, ['waypoints' => true, 'remember' => true]), 'retrace' => ''])
        ->and(fn() => MovementRouteFields::prepareEdit([...$route, MovementRouteFields::MODE_FIELD => 'bogus'], MovementRouteFields::MODE_FIELD))->toThrow(InvalidArgumentException::class)
        ->and(fn() => MovementRouteFields::prepareEdit([...$route, 'remember' => '../unsafe'], 'remember'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => MovementRouteFields::prepareEdit([...$waypoints, 'subject' => 'staged_actor'], 'subject'))->toThrow(InvalidArgumentException::class);
});

it('keeps sparse waypoint axes and zero through source preserving edits undo save and reopen', function () {
    $root = makeTemporaryProject('ichiloto-route-authoring-');
    $path = $root . '/assets/Events/waypoint-route.php';
    file_put_contents($path, <<<'PHP'
<?php
// Authored route, not a generated path.
return [['type' => 'move_route', 'subject' => 'player', 'wait' => true,
    'secondsPerStep' => 0.25, 'remember' => 'outbound', 'annotation' => 'keep',
    'waypoints' => [['x' => 2], ['y' => 1], ['x' => 4, 'y' => 2]],
]];
PHP);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $index = array_search('waypoint-route', $session->listDatabaseRecords('common_events')['records'], true);
    $view = $session->readDatabaseRecord('common_events', $index);
    $placement = array_find($view['rows'], static fn(array $row): bool => ($row['mapPlacement']['kind'] ?? '') === 'waypoints');
    expect($placement['mapPlacement']['points'][0]['point'])->toBe([2, null])
        ->and($placement['mapPlacement']['points'][1]['point'])->toBe([2, 1]);
    $context = ['kind' => 'database', 'category' => 'common_events', 'index' => $index];
    $revision = $session->readMap('test-map')['revision'];
    $session->applyMapPlacement($context, $placement['key'], $placement['mapPlacement'], 'test-map', 0, 0, 3, [1, 2], $revision);
    $changed = array_find($session->readDatabaseRecord('common_events', $index)['rows'], static fn(array $row): bool => isset($row['mapPlacement']));
    expect($changed['mapPlacement']['points'][0]['point'])->toBe([0, null]);
    $session->undo();
    $session->saveDatabase('common_events');
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $session->saveDatabase('common_events');
    expect((require $path)[0])->toMatchArray(['secondsPerStep' => 0.25, 'remember' => 'outbound', 'annotation' => 'keep',
        'waypoints' => [['x' => 0], ['y' => 1], ['x' => 4, 'y' => 2]]])
        ->and(file_get_contents($path))->toContain('// Authored route, not a generated path.');
    $reopened = EditorSession::open($root);
    $view = $reopened->readDatabaseRecord('common_events', $index);
    $y = array_find($view['rows'], static fn(array $row): bool => ($row['entry'] ?? []) === [0, 2] && ($row['name'] ?? '') === 'Y');
    expect($y)->not->toBeNull();
    $reopened->applyDatabaseRecord('common_events', $index, $y['key'], '');
    $reopened->saveDatabase('common_events');
    expect((require $path)[0]['waypoints'][2])->toBe(['x' => 4]);
});

it('refuses expression owned waypoint placement without flattening source or creating history', function () {
    $root = makeTemporaryProject('ichiloto-route-expression-');
    $path = $root . '/assets/Events/waypoint-expression.php';
    file_put_contents($path, <<<'PHP'
<?php
$destination = 2;
return [['type' => 'move_route', 'subject' => 'player', 'waypoints' => [['x' => abs($destination)]]]];
PHP);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $index = array_search('waypoint-expression', $session->listDatabaseRecords('common_events')['records'], true);
    $row = array_find($session->readDatabaseRecord('common_events', $index)['rows'], static fn(array $r): bool => isset($r['mapPlacement']));
    expect($row['mapPlacement']['issue'])->toContain('literal source fields')
        ->and(fn() => $session->applyMapPlacement(['kind' => 'database', 'category' => 'common_events', 'index' => $index],
            $row['key'], $row['mapPlacement'], 'test-map', 0, 3, 1, null, $session->readMap('test-map')['revision']))->toThrow(SessionRefusal::class)
        ->and($session->undo()['label'])->toBeNull();
    $session->saveDatabase('common_events');
    expect(file_get_contents($path))->toBe($original);
});

it('selects live cinematic remembered route identities at nested command depths and round trips retrace', function () {
    $root = cutsceneProject();
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, <<<'PHP'
<?php
// The record is created by movement, not editor geometry.
return [['type' => 'sequence', 'commands' => [
    ['type' => 'move_route', 'subject' => 'player', 'remember' => 'outbound', 'waypoints' => [['x' => 2]], 'secondsPerStep' => 0.3],
    ['type' => 'move_route', 'subject' => 'player', 'retrace' => 'outbound', 'speed' => 2.0],
]]];
PHP);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $record = ['category' => 'cutscenes/cinematic', 'index' => 0];
    expect(array_column($session->listReferences(null, 'cinematic_movement_routes', $record), 'value'))->toBe(['outbound'])
        ->and($session->listReferences(null, 'cinematic_movement_routes'))->toBe([]);
    $frame = ['commands', 0, 'commands'];
    $view = $session->readDatabaseRecord('cutscenes/cinematic', 0, $frame);
    $remember = routeAuthoringRow($view, 'Remember As');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $remember['key'], 'return-path');
    expect(array_column($session->listReferences(null, 'cinematic_movement_routes', $record), 'value'))->toBe(['return-path']);
    $view = $session->readDatabaseRecord('cutscenes/cinematic', 0, $frame);
    $retrace = routeAuthoringRow($view, 'Recorded Route', 1);
    expect($retrace['reference'])->toBe('cinematic_movement_routes');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $retrace['key'], 'return-path');
    $session->undo();
    $session->undo();
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $session->redo();
    $session->saveDatabase('cutscenes/cinematic');
    $saved = require $path;
    expect($saved[0]['commands'][0])->toMatchArray(['remember' => 'return-path', 'waypoints' => [['x' => 2]], 'secondsPerStep' => 0.3])
        ->and($saved[0]['commands'][1])->toBe(['type' => 'move_route', 'subject' => 'player', 'retrace' => 'return-path', 'speed' => 2.0])
        ->and(array_column(EditorSession::open($root)->listReferences(null, 'cinematic_movement_routes', $record), 'value'))->toBe(['return-path']);
});

it('authors a new waypoint mode and child axes without saving the derived selector or coordinate defaults', function () {
    $root = makeTemporaryProject('ichiloto-route-mode-');
    $path = $root . '/assets/Events/mode-route.php';
    file_put_contents($path, <<<'PHP'
<?php
// Preserve the command envelope.
return [['type' => 'move_route', 'subject' => 'player', 'remember' => 'outbound',
    'wait' => true, 'speed' => 3.0, 'annotation' => 'keep',
    'steps' => [['direction' => 'left', 'faceOnly' => true, 'seconds' => 0.4]],
]];
PHP);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $index = array_search('mode-route', $session->listDatabaseRecords('common_events')['records'], true);
    $mode = routeAuthoringRow($session->readDatabaseRecord('common_events', $index), 'Route Mode');
    $session->applyDatabaseRecord('common_events', $index, $mode['key'], 'waypoints');
    expect(fn() => $session->saveDatabase('common_events'))->toThrow(InvalidArgumentException::class, 'non-empty list')
        ->and(file_get_contents($path))->toBe($original);
    $mode = routeAuthoringRow($session->readDatabaseRecord('common_events', $index), 'Route Mode');
    $session->addDatabaseItem('common_events', $index, $mode['key'], child: true);
    expect(fn() => $session->saveDatabase('common_events'))->toThrow(InvalidArgumentException::class, 'x and/or y')
        ->and(file_get_contents($path))->toBe($original);
    $view = $session->readDatabaseRecord('common_events', $index);
    $x = array_find($view['rows'], static fn(array $row): bool => ($row['entry'] ?? []) === [0, 0] && ($row['name'] ?? '') === 'X');
    expect($x['value'])->toBe('(Keep current X)');
    $session->applyDatabaseRecord('common_events', $index, $x['key'], '0');
    $session->saveDatabase('common_events');
    expect((require $path)[0])->toBe(['type' => 'move_route', 'subject' => 'player', 'remember' => 'outbound',
        'wait' => true, 'speed' => 3.0, 'annotation' => 'keep', 'waypoints' => [['x' => 0]]])
        ->and(file_get_contents($path))->toContain('// Preserve the command envelope.')
        ->and(file_get_contents($path))->not->toContain('_routeMode');
    $session->undo();
    $session->undo();
    $session->undo();
    $session->saveDatabase('common_events');
    expect(require $path)->toBe([['type' => 'move_route', 'subject' => 'player', 'remember' => 'outbound',
        'wait' => true, 'speed' => 3.0, 'annotation' => 'keep',
        'steps' => [['direction' => 'left', 'faceOnly' => true, 'seconds' => 0.4]]]])
        ->and(file_get_contents($path))->toContain('// Preserve the command envelope.');
});

it('places sparse inline map-event waypoints through existing grouped path edits', function () {
    $root = makeTemporaryProject('ichiloto-event-waypoints-');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['events']['S'] = ['class' => \Ichiloto\Engine\Events\Triggers\StoryTrigger::class, 'data' => ['script' => [
        ['type' => 'move_route', 'subject' => 'player', 'secondsPerStep' => 0.4, 'wait' => true,
            'waypoints' => [['x' => 2], ['y' => 1]]],
    ]]];
    file_put_contents($path, "<?php\n// Preserve inline event source.\nreturn " . var_export($data, true) . ";\n");
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $read = $session->readInspector('test-map', 'S');
    $row = array_find($read['rows'], static fn(array $r): bool => ($r['mapPlacement']['kind'] ?? '') === 'waypoints');
    expect($row['mapPlacement']['points'][0]['point'])->toBe([2, null]);
    $session->applyMapPlacement(['kind' => 'event', 'map' => 'test-map', 'revision' => $read['revision']],
        $row['key'], $row['mapPlacement'], 'test-map', 0, 0, 2, null, $read['revision']);
    expect($session->undo()['label'])->toBe('Place on map');
    $session->saveMap('test-map');
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $session->saveMap('test-map');
    expect((require $path)['events']['S']['data']['script'][0])->toBe(['type' => 'move_route', 'subject' => 'player',
        'secondsPerStep' => 0.4, 'wait' => true, 'waypoints' => [['x' => 0], ['y' => 1]]])
        ->and(file_get_contents($path))->toContain('// Preserve inline event source.');
    $reopened = EditorSession::open($root)->readInspector('test-map', 'S');
    expect(array_find($reopened['rows'], static fn(array $r): bool => isset($r['mapPlacement']))['mapPlacement']['points'][0]['point'])->toBe([0, null]);
});

it('refuses clearing the last waypoint axis or negative coordinates without a partial edit', function () {
    $root = makeTemporaryProject('ichiloto-route-invalid-');
    $path = $root . '/assets/Events/waypoint-invalid.php';
    file_put_contents($path, "<?php\nreturn [['type' => 'move_route', 'subject' => 'player', 'waypoints' => [['y' => 1]]]];\n");
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $index = array_search('waypoint-invalid', $session->listDatabaseRecords('common_events')['records'], true);
    $row = array_find($session->readDatabaseRecord('common_events', $index)['rows'], static fn(array $r): bool => ($r['entry'] ?? []) === [0, 0] && ($r['name'] ?? '') === 'Y');
    expect(fn() => $session->applyDatabaseRecord('common_events', $index, $row['key'], ''))->toThrow(SessionRefusal::class, 'x and/or y')
        ->and(fn() => $session->applyDatabaseRecord('common_events', $index, $row['key'], '-1'))->toThrow(SessionRefusal::class, 'non-negative')
        ->and($session->undo()['label'])->toBeNull();
    $session->saveDatabase('common_events');
    expect(file_get_contents($path))->toBe($original);
});

it('offers remembered identities in every reachable cinematic arm and referenced common event without following cycles forever', function () {
    $root = cutsceneProject();
    file_put_contents($root . '/assets/Events/outbound.php', "<?php\nreturn [
        ['type' => 'move_route', 'subject' => 'player', 'remember' => 'common-route', 'steps' => [['direction' => 'right']]],
        ['type' => 'common_event', 'id' => 'outbound'],
    ];\n");
    file_put_contents($root . '/assets/Events/unrelated.php', "<?php\nreturn [['type' => 'move_route', 'subject' => 'player', 'remember' => 'unreachable-route', 'steps' => [['direction' => 'left']]]];\n");
    $route = static fn(string $id): array => ['type' => 'move_route', 'subject' => 'player', 'remember' => $id, 'waypoints' => [['x' => 1]]];
    $commands = [
        ['type' => 'branch', 'conditions' => [], 'then' => [$route('then-route')], 'else' => [$route('else-route')]],
        ['type' => 'choice', 'prompt' => 'Choose', 'options' => [['text' => 'Continue', 'then' => [$route('choice-route')]]], 'cancel' => [$route('cancel-route')]],
        ['type' => 'parallel', 'lanes' => [['id' => 'first', 'commands' => [$route('lane-route')]], [$route('legacy-lane-route')]]],
        ['type' => 'common_event', 'id' => 'outbound'],
    ];
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, "<?php\nreturn " . var_export($commands, true) . ";\n");
    $values = array_column(EditorSession::open($root)->listReferences(null, 'cinematic_movement_routes', ['category' => 'cutscenes/cinematic', 'index' => 0]), 'value');
    expect($values)->toBe(['then-route', 'else-route', 'choice-route', 'cancel-route', 'lane-route', 'legacy-lane-route', 'common-route']);
});

it('shares sparse inline route controls and validation with command records through undo save and reopen', function () {
    $root = makeTemporaryProject('ichiloto-inline-route-fields-');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $route = ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide', 'wait' => true,
        'speed' => 2.0, 'remember' => 'outbound', 'annotation' => 'keep', 'waypoints' => [['x' => 2]]];
    $data['events']['S'] = ['class' => \Ichiloto\Engine\Events\Triggers\StoryTrigger::class,
        'data' => ['script' => [['type' => 'sequence', 'commands' => [$route]]]]];
    file_put_contents($path, "<?php\n// Inline route ownership.\nreturn " . var_export($data, true) . ";\n");
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $base = ['data', 'script', '0', 'commands', '0'];
    $x = inlineRouteRow($session, [...$base, 'waypoints', '0', 'x']);
    $y = inlineRouteRow($session, [...$base, 'waypoints', '0', 'y']);
    expect($x['value'])->toBe('2')->and($y['value'])->toContain('Keep current Y')
        ->and($y['kind'])->toBe('integer');
    $read = $session->readInspector('test-map', 'S');
    expect(fn() => $session->applyInspector('test-map', $read['revision'], $x['key'], '-1'))->toThrow(SessionRefusal::class, 'non-negative')
        ->and(fn() => $session->applyInspector('test-map', $read['revision'], $x['key'], ''))->toThrow(SessionRefusal::class, 'x and/or y')
        ->and($session->undo()['label'])->toBeNull();
    $session->applyInspector('test-map', $read['revision'], $x['key'], '0');
    $session->undo();
    $session->saveMap('test-map');
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $session->saveMap('test-map');
    expect((require $path)['events']['S']['data']['script'][0]['commands'][0])->toBe([...$route, 'waypoints' => [['x' => 0]]])
        ->and(file_get_contents($path))->toContain('// Inline route ownership.');
    $reopened = EditorSession::open($root);
    $mode = inlineRouteRow($reopened, [...$base, MovementRouteFields::MODE_FIELD]);
    expect($mode['value'])->toBe('waypoints')->and($mode['options'])->toBe(['steps', 'waypoints', 'retrace']);
    $read = $reopened->readInspector('test-map', 'S');
    $reopened->applyInspector('test-map', $read['revision'], $mode['key'], 'steps');
    $beforeDraft = file_get_contents($path);
    expect(fn() => $reopened->saveMap('test-map'))->toThrow(InvalidArgumentException::class, 'at least one step')
        ->and(file_get_contents($path))->toBe($beforeDraft)
        ->and(inlineRouteRow($reopened, [...$base, MovementRouteFields::MODE_FIELD])['value'])->toBe('steps');
    $reopened->undo();
    expect(inlineRouteRow($reopened, [...$base, MovementRouteFields::MODE_FIELD])['value'])->toBe('waypoints');
    $reopened->redo();
    $steps = inlineRouteRow($reopened, [...$base, 'steps']);
    $reopened->addInspectorListEntry('test-map', $reopened->readInspector('test-map', 'S')['revision'], $steps['key']);
    $reopened->saveMap('test-map');
    expect((require $path)['events']['S']['data']['script'][0]['commands'][0])->toBe([...array_diff_key($route, ['waypoints' => true]), 'steps' => [RecordSchemaCatalog::routeStepList()->blank]])
        ->and(inlineRouteRow(EditorSession::open($root), [...$base, MovementRouteFields::MODE_FIELD])['value'])->toBe('steps')
        ->and(file_get_contents($path))->not->toContain('_routeMode');
});

it('refuses expression owned inline waypoint fields without source normalization or history', function () {
    $root = makeTemporaryProject('ichiloto-inline-route-expression-');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['events']['S'] = ['class' => \Ichiloto\Engine\Events\Triggers\StoryTrigger::class,
        'data' => ['script' => [['type' => 'move_route', 'waypoints' => [['x' => 2]]]]]];
    $source = "<?php\n// Authored expression.\nreturn " . var_export($data, true) . ";\n";
    $source = str_replace("'x' => 2,", "'x' => abs(-2),", $source);
    file_put_contents($path, $source);
    $session = EditorSession::open($root);
    $row = inlineRouteRow($session, ['data', 'script', '0', 'waypoints', '0', 'x']);
    $read = $session->readInspector('test-map', 'S');
    expect(fn() => $session->applyInspector('test-map', $read['revision'], $row['key'], '3'))->toThrow(SessionRefusal::class)
        ->and($session->undo()['label'])->toBeNull();
    $session->saveMap('test-map');
    expect(file_get_contents($path))->toBe($source);
});

it('authors inline route modes and nullable children without fabricating axes or clearing cardinal defaults', function () {
    $root = makeTemporaryProject('ichiloto-inline-route-children-');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $route = ['type' => 'move_route', 'subject' => 'player', 'wait' => true, 'secondsPerStep' => 0.25,
        'steps' => [['direction' => 'left', 'faceOnly' => true, 'seconds' => 0.5]]];
    $data['events']['S'] = ['class' => \Ichiloto\Engine\Events\Triggers\StoryTrigger::class, 'data' => ['script' => [$route]]];
    file_put_contents($path, "<?php\n// Mode conversion is deliberate.\nreturn " . var_export($data, true) . ";\n");
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $base = ['data', 'script', '0'];
    $apply = static function (array $field, string $value) use ($session): void {
        $session->applyInspector('test-map', $session->readInspector('test-map', 'S')['revision'], $field['key'], $value);
    };
    $apply(inlineRouteRow($session, [...$base, MovementRouteFields::MODE_FIELD]), 'waypoints');
    expect(fn() => $session->saveMap('test-map'))->toThrow(InvalidArgumentException::class, 'non-empty list')
        ->and(file_get_contents($path))->toBe($original);
    $heading = inlineRouteRow($session, [...$base, 'waypoints']);
    $session->addInspectorListEntry('test-map', $session->readInspector('test-map', 'S')['revision'], $heading['key']);
    expect(fn() => $session->saveMap('test-map'))->toThrow(InvalidArgumentException::class, 'x and/or y')
        ->and(file_get_contents($path))->toBe($original);
    $x = inlineRouteRow($session, [...$base, 'waypoints', '0', 'x']);
    expect($x['value'])->toContain('Keep current X');
    $apply($x, '0');
    $session->addInspectorListEntry('test-map', $session->readInspector('test-map', 'S')['revision'], $x['key']);
    $y = inlineRouteRow($session, [...$base, 'waypoints', '1', 'y']);
    expect(inlineRouteRow($session, [...$base, 'waypoints', '1', 'x'])['value'])->toContain('Keep current X');
    $apply($y, '2');
    $session->saveMap('test-map');
    $expected = [...array_diff_key($route, ['steps' => true]), 'waypoints' => [['x' => 0], ['y' => 2]]];
    expect((require $path)['events']['S']['data']['script'][0])->toBe($expected)
        ->and(file_get_contents($path))->not->toContain('_routeMode');
    $reopened = EditorSession::open($root);
    $y = inlineRouteRow($reopened, [...$base, 'waypoints', '1', 'y']);
    $reopened->removeInspectorListEntry('test-map', $reopened->readInspector('test-map', 'S')['revision'], $y['key']);
    $reopened->undo();
    $reopened->saveMap('test-map');
    expect((require $path)['events']['S']['data']['script'][0])->toBe($expected);
    for ($i = 0; $i < 5; ++$i) { $session->undo(); }
    $session->saveMap('test-map');
    expect((require $path)['events']['S']['data']['script'][0])->toBe($route)
        ->and(file_get_contents($path))->toContain('// Mode conversion is deliberate.');
    $steps = inlineRouteRow($session, [...$base, 'steps']);
    $session->addInspectorListEntry('test-map', $session->readInspector('test-map', 'S')['revision'], $steps['key']);
    $session->saveMap('test-map');
    expect((require $path)['events']['S']['data']['script'][0]['steps'][1])->toBe(RecordSchemaCatalog::routeStepList()->blank);
});

it('refuses cross-scene and unknown retrace references using the live owning record at every frame depth', function () {
    $root = cutsceneProject();
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    $route = ['type' => 'move_route', 'subject' => 'player', 'retrace' => 'outbound'];
    $commands = [
        ['type' => 'move_route', 'subject' => 'player', 'remember' => 'outbound', 'steps' => [['direction' => 'right']]],
        $route,
        ['type' => 'sequence', 'commands' => [$route]],
        ['type' => 'branch', 'conditions' => [], 'then' => [$route], 'else' => [$route]],
        ['type' => 'choice', 'prompt' => 'Choose', 'options' => [['text' => 'Go', 'then' => [$route]]], 'cancel' => [$route]],
        ['type' => 'parallel', 'lanes' => [['id' => 'route', 'commands' => [$route]]]],
    ];
    file_put_contents($path, "<?php\n// Owner-local records.\nreturn " . var_export($commands, true) . ";\n");
    $other = $root . '/assets/Cutscenes/Cinematics/other-scene';
    mkdir($other, 0777, true);
    file_put_contents($other . '/other-scene.data.php', "<?php return ['id' => 'other-scene', 'name' => 'Other', 'startMap' => 'harbour', 'skip' => ['policy' => 'forbidden']];\n");
    file_put_contents($other . '/other-scene.script.php', "<?php return [['type' => 'move_route', 'subject' => 'player', 'remember' => 'other-route', 'waypoints' => [['y' => 1]]]];\n");
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $frames = [[['commands'], 1], [['commands', 2, 'commands'], 0], [['commands', 3, 'then'], 0],
        [['commands', 3, 'else'], 0], [['commands', 4, 'options', 0, 'then'], 0], [['commands', 4, 'cancel'], 0],
        [['commands', 5, 'lanes', 0, 'commands'], 0]];
    foreach ($frames as [$frame, $command]) {
        $row = routeAuthoringRow($session->readDatabaseRecord('cutscenes/cinematic', 0, $frame), 'Recorded Route', $command);
        foreach (['other-route', 'unknown-route'] as $bad) {
            expect(fn() => $session->applyDatabaseRecord('cutscenes/cinematic', 0, $row['key'], $bad))->toThrow(SessionRefusal::class, 'Unknown cinematic_movement_routes');
        }
        $session->applyDatabaseRecord('cutscenes/cinematic', 0, $row['key'], 'outbound');
    }
    expect($session->undo()['label'])->toBeNull()
        ->and(array_column($session->listReferences(null, 'cinematic_movement_routes', ['category' => 'cutscenes/cinematic', 'index' => 0]), 'value'))->toBe(['outbound']);
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($path))->toBe($original);
});

it('validates changed route paths through every authored arm while preserving reordered legacy paths', function () {
    $invalid = ['type' => 'move_route', 'waypoints' => []];
    $old = ['type' => 'sequence', 'commands' => [$invalid]];
    MovementRouteFields::assertChangedCommandsValid($old, [...$old, 'annotation' => 'keep']);
    MovementRouteFields::assertChangedCommandsValid($old, ['type' => 'sequence', 'commands' => [[...$invalid, 'secondsPerStep' => 0.2]]]);
    MovementRouteFields::assertChangedCommandsValid($old, ['type' => 'sequence', 'commands' => [['type' => 'text', 'text' => 'New'], $invalid]]);
    foreach (['then', 'else', 'commands', 'cancel', 'options', 'lanes', 'script', 'finalizer'] as $arm) {
        expect(fn() => MovementRouteFields::assertChangedCommandsValid([], [$arm => [$invalid]]))->toThrow(InvalidArgumentException::class, 'non-empty list');
    }
    expect(fn() => MovementRouteFields::assertChangedCommandsValid([], ['type' => 'move_route', 'retrace' => '']))->toThrow(InvalidArgumentException::class, 'safe stable id')
        ->and(fn() => MovementRouteFields::assertChangedCommandsValid([], ['type' => 'move_route', 'steps' => [], 'waypoints' => [['x' => 0]]]))->toThrow(InvalidArgumentException::class, 'exactly one');
    expect(fn() => MovementRouteFields::assertChangedCommandsValid($old, ['type' => 'sequence', 'commands' => [$invalid, $invalid]]))->toThrow(InvalidArgumentException::class, 'non-empty list');
    foreach ([[], [[]], [['direction' => '']], [['direction' => 'diagonal']], [['direction' => 'left', 'count' => -1]], [['direction' => 'left', 'faceOnly' => 'true']]] as $steps) {
        expect(fn() => MovementRouteFields::assertChangedCommandsValid([], ['type' => 'move_route', 'steps' => $steps]))->toThrow(InvalidArgumentException::class);
    }
    MovementRouteFields::assertChangedCommandsValid([], ['type' => 'move_route', 'steps' => [['direction' => ' RIGHT ', 'count' => '0', 'faceOnly' => true], ['direction' => 'up']]]);
    MovementRouteFields::assertChangedCommandsValid([], ['type' => 'move_route', 'waypoints' => [['x' => 0], ['y' => 0]]]);
});

it('refuses unfinished cinematic routes at the actual paired source owner then saves completed sparse axes', function () {
    $root = cutsceneProject();
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    $route = ['type' => 'move_route', 'subject' => 'player', 'secondsPerStep' => 0.3,
        'steps' => [['direction' => 'left', 'faceOnly' => true]]];
    file_put_contents($path, "<?php\n// Owner-validated route.\nreturn " . var_export([$route], true) . ";\n");
    $original = sourceHashTree($root);
    $session = EditorSession::open($root);
    $frame = ['commands'];
    $mode = routeAuthoringRow($session->readDatabaseRecord('cutscenes/cinematic', 0, $frame), 'Route Mode');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $mode['key'], 'waypoints');
    expect(fn() => $session->saveDatabase('cutscenes/cinematic'))->toThrow(InvalidArgumentException::class, 'non-empty list')
        ->and(sourceHashTree($root))->toBe($original);
    $mode = routeAuthoringRow($session->readDatabaseRecord('cutscenes/cinematic', 0, $frame), 'Route Mode');
    $session->addDatabaseItem('cutscenes/cinematic', 0, $mode['key'], child: true);
    expect(fn() => $session->saveDatabase('cutscenes/cinematic'))->toThrow(InvalidArgumentException::class, 'x and/or y')
        ->and(sourceHashTree($root))->toBe($original);
    $view = $session->readDatabaseRecord('cutscenes/cinematic', 0, $frame);
    $x = array_find($view['rows'], static fn(array $row): bool => ($row['entry'] ?? []) === [0, 0] && ($row['name'] ?? '') === 'X');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $x['key'], '0');
    $session->saveDatabase('cutscenes/cinematic');
    expect((require $path)[0])->toBe([...array_diff_key($route, ['steps' => true]), 'waypoints' => [['x' => 0]]])
        ->and(file_get_contents($path))->toContain('// Owner-validated route.');
    $reopened = EditorSession::open($root);
    $mode = routeAuthoringRow($reopened->readDatabaseRecord('cutscenes/cinematic', 0, $frame), 'Route Mode');
    $reopened->applyDatabaseRecord('cutscenes/cinematic', 0, $mode['key'], 'retrace');
    $saved = sourceHashTree($root);
    expect(fn() => $reopened->saveDatabase('cutscenes/cinematic'))->toThrow(InvalidArgumentException::class, 'safe stable id')
        ->and(sourceHashTree($root))->toBe($saved);
    $reopened->undo();
    $reopened->saveDatabase('cutscenes/cinematic');
    expect(sourceHashTree($root))->toBe($saved);
});

it('keeps incomplete cardinal record drafts and their history on refusal then saves a corrected route', function () {
    $root = makeTemporaryProject('ichiloto-route-cardinal-save-');
    $path = $root . '/assets/Events/cardinal-draft.php';
    $route = ['type' => 'move_route', 'subject' => 'player', 'secondsPerStep' => 0.2,
        'annotation' => 'keep', 'waypoints' => [['y' => 0]]];
    file_put_contents($path, "<?php\n// Cardinal correction, not a fabricated path.\nreturn " . var_export([$route], true) . ";\n");
    $original = sourceHashTree($root);
    $session = EditorSession::open($root);
    $index = array_search('cardinal-draft', $session->listDatabaseRecords('common_events')['records'], true);
    $mode = routeAuthoringRow($session->readDatabaseRecord('common_events', $index), 'Route Mode');
    $session->applyDatabaseRecord('common_events', $index, $mode['key'], 'steps');
    expect(fn() => $session->saveDatabase('common_events'))->toThrow(InvalidArgumentException::class, 'at least one step')
        ->and(sourceHashTree($root))->toBe($original)
        ->and(routeAuthoringRow($session->readDatabaseRecord('common_events', $index), 'Route Mode')['value'])->toBe('steps');
    $session->undo();
    expect(routeAuthoringRow($session->readDatabaseRecord('common_events', $index), 'Route Mode')['value'])->toBe('waypoints');
    $session->redo();
    $session->addDatabaseItem('common_events', $index, $mode['key'], child: true);
    $count = array_find($session->readDatabaseRecord('common_events', $index)['rows'], static fn(array $row): bool => ($row['entry'] ?? []) === [0, 0] && $row['name'] === 'Count');
    $session->applyDatabaseRecord('common_events', $index, $count['key'], '-1');
    expect(fn() => $session->saveDatabase('common_events'))->toThrow(InvalidArgumentException::class, 'non-negative integer')
        ->and(sourceHashTree($root))->toBe($original);
    $session->undo();
    $session->saveDatabase('common_events');
    $expected = [...array_diff_key($route, ['waypoints' => true]), 'steps' => [RecordSchemaCatalog::routeStepList()->blank]];
    expect((require $path)[0])->toBe($expected)
        ->and(file_get_contents($path))->toContain('// Cardinal correction, not a fabricated path.')
        ->and(routeAuthoringRow(EditorSession::open($root)->readDatabaseRecord('common_events', $index), 'Route Mode')['value'])->toBe('steps');
});

it('preserves untouched loaded invalid route paths in Terminal and direct map saves but refuses newly incomplete paths', function () {
    $root = makeTemporaryProject('ichiloto-route-loaded-invalid-');
    $path = $root . '/assets/Events/invalid-existing.php';
    file_put_contents($path, "<?php\n// Legacy path preserved.\nreturn [['type' => 'move_route', 'waypoints' => []], ['type' => 'text', 'text' => 'Old']];\n");
    $mapPath = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $mapPath;
    $data['events']['S'] = ['class' => \Ichiloto\Engine\Events\Triggers\StoryTrigger::class,
        'data' => ['annotation' => 'Old', 'script' => [['type' => 'move_route', 'steps' => []]]]];
    $mapSource = "<?php\n// Legacy route source is not normalized.\nreturn " . var_export($data, true) . ";\n";
    $mapSource = str_replace("'type' => 'move_route'", "'type' => strtolower('MOVE_ROUTE')", $mapSource);
    file_put_contents($mapPath, $mapSource);
    $workspace = \Ichiloto\Editor\ProjectWorkspace::fromProject($root);
    $database = $workspace->getRecordDatabase('common_events');
    $index = array_search('invalid-existing', $database->getEntryLabels(), true);
    $database->setField($index, 'command1Text', 'Changed');
    $database->save();
    expect((require $path)[0])->toBe(['type' => 'move_route', 'waypoints' => []])
        ->and(file_get_contents($path))->toContain('// Legacy path preserved.', "[['type' => 'move_route', 'waypoints' => []],");
    $map = array_find($workspace->maps, static fn($map): bool => $map->mapId === 'test-map');
    $map->setEventField('S', ['data', 'annotation'], 'Changed');
    $map->save();
    expect((require $mapPath)['events']['S']['data']['script'])->toBe([['type' => 'move_route', 'steps' => []]])
        ->and(file_get_contents($mapPath))->toContain("'type' => strtolower('MOVE_ROUTE')", '// Legacy route source is not normalized.');
    $original = sourceHashTree($root);
    $map->setEventField('E', ['data', 'script'], [['type' => 'move_route', 'waypoints' => []]]);
    $backedUp = false;
    expect(fn() => $map->save(static function () use (&$backedUp): void { $backedUp = true; }))->toThrow(InvalidArgumentException::class, 'non-empty list')
        ->and($backedUp)->toBeFalse()->and(sourceHashTree($root))->toBe($original)
        ->and($map->getEventField('E', ['data', 'script']))->toBe([['type' => 'move_route', 'waypoints' => []]]);
});

it('protects direct cutscene saves before backup or staging and preserves loaded route data during metadata edits', function () {
    $root = cutsceneProject();
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, "<?php\n// Loaded route remains authored.\nreturn [['type' => 'move_route', 'waypoints' => [['x' => 0]]]];\n");
    $workspace = \Ichiloto\Editor\ProjectWorkspace::fromProject($root);
    $asset = $workspace->cutscenes->find(\Ichiloto\Editor\Cutscenes\CutsceneType::CINEMATIC, 'harbour-lanterns');
    $state = $asset->captureEditState();
    $state['data']['name'] = 'Changed metadata';
    $asset->restoreEditState($state);
    $asset->save();
    expect(require $path)->toBe([['type' => 'move_route', 'waypoints' => [['x' => 0]]]])
        ->and(file_get_contents($path))->toContain('// Loaded route remains authored.');
    $original = sourceHashTree($root);
    $state = $asset->captureEditState();
    $state['partner'] = [['type' => 'sequence', 'commands' => [['type' => 'move_route', 'retrace' => '']]]];
    $asset->restoreEditState($state);
    $backedUp = false;
    expect(fn() => $asset->save(static function () use (&$backedUp): void { $backedUp = true; }))->toThrow(InvalidArgumentException::class, 'safe stable id')
        ->and($backedUp)->toBeFalse()->and(sourceHashTree($root))->toBe($original)
        ->and(glob(dirname($path) . '/.*.tmp'))->toBe([]);
});

it('records and retraces within ordinary NPC map and standalone common-event execution sessions', function () {
    $root = cutsceneProject();
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php return [' ' => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::NONE, '#' => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::SOLID, '=' => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::SOLID];");
    $commands = [
        ['type' => 'move_route', 'remember' => 'outbound', 'secondsPerStep' => 0, 'steps' => [['direction' => 'right']]],
        ['type' => 'move_route', 'retrace' => 'outbound', 'secondsPerStep' => 0],
    ];
    $path = $root . '/assets/Events/standalone-route.php';
    file_put_contents($path, '<?php return ' . var_export($commands, true) . ';');
    $field = PreviewField::open($root, 'harbour', new Vector2(4, 4), 20, 8, fn(): float => 0.0);
    try {
        expect($field->mapFailure)->toBeNull();
        $field->run(function () use ($field, $commands): void {
            foreach ([
                'npc:harbour:keeper' => [$commands, ['npc' => 'keeper']],
                'harbour:event' => [$commands, ['marker' => 'event']],
                'standalone-common' => [[['type' => 'common_event', 'id' => 'standalone-route']], ['source' => 'common_event']],
            ] as $identity => [$commands, $origin]) {
                $interpreter = new EventInterpreter($field->scene, $field->presentation);
                $session = $interpreter->run($commands, $identity, origin: $origin);
                for ($tick = 0; $tick < 20 && $session->status !== EventExecutionStatus::COMPLETED; ++$tick) {
                    $interpreter->update(0.0);
                }
                expect($session?->cinematic)->toBeNull()
                    ->and($session?->status)->toBe(EventExecutionStatus::COMPLETED)
                    ->and($session?->failureMessage)->toBeNull()
                    ->and([$field->scene->player->position->x, $field->scene->player->position->y])->toBe([4.0, 4.0])
                    ->and(fn() => $session->movementRoute('outbound'))->toThrow(RuntimeException::class, 'was not recorded in this session');
            }
        });
        expect(require $path)->toBe($commands);
    } finally {
        $field->dispose();
    }
});

it('shares actual recorded movement with common events only within the owning execution lifetime', function (bool $cinematic) {
    $root = cutsceneProject();
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php return [' ' => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::NONE, '#' => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::SOLID, '=' => \\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::SOLID];");
    file_put_contents($root . '/assets/Events/record-route.php', "<?php return [['type' => 'move_route', 'remember' => 'common-recorded', 'secondsPerStep' => 0, 'steps' => [['direction' => 'right']]]];");
    file_put_contents($root . '/assets/Events/return-route.php', "<?php return [['type' => 'move_route', 'retrace' => 'root-recorded', 'secondsPerStep' => 0]];");
    $commands = [
        ['type' => 'common_event', 'id' => 'record-route'],
        ['type' => 'move_route', 'retrace' => 'common-recorded', 'secondsPerStep' => 0],
        ['type' => 'move_route', 'remember' => 'root-recorded', 'secondsPerStep' => 0, 'steps' => [['direction' => 'right']]],
        ['type' => 'common_event', 'id' => 'return-route'],
        ['type' => 'wait', 'seconds' => 1],
    ];
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, '<?php return ' . var_export($commands, true) . ';');
    $session = EditorSession::open($root);
    expect(array_column($session->listReferences(null, 'cinematic_movement_routes', ['category' => 'cutscenes/cinematic', 'index' => 0]), 'value'))
        ->toBe(['common-recorded', 'root-recorded'])
        ->and($session->listReferences('harbour', 'cinematic_movement_routes'))->toBe([]);
    $definition = CinematicDefinition::fromArrays(['id' => 'route-lifetime', 'name' => 'Route Lifetime', 'startMap' => 'harbour', 'skip' => ['policy' => 'forbidden']], $commands);
    $field = PreviewField::open($root, 'harbour', new Vector2(4, 4), 20, 8, fn(): float => 0.0);
    try {
        expect($field->mapFailure)->toBeNull();
        $field->run(function () use ($field, $definition, $commands, $cinematic): void {
            $interpreter = new EventInterpreter($field->scene, $field->presentation);
            $execution = $cinematic ? $interpreter->runCinematic($definition) : $interpreter->run($commands, 'ordinary-root');
            for ($tick = 0; $tick < 20 && ($execution?->pendingCommand['type'] ?? '') !== 'wait'; ++$tick) {
                $interpreter->update(0.0);
            }
            expect($execution?->failureMessage)->toBeNull()
                ->and($execution?->status)->toBe(EventExecutionStatus::YIELDED)
                ->and($execution?->pendingCommand['type'])->toBe('wait')
                ->and($execution->movementRoute('common-recorded')->complete)->toBeTrue()
                ->and($execution->movementRoute('root-recorded')->complete)->toBeTrue()
                ->and([$field->scene->player->position->x, $field->scene->player->position->y])->toBe([4.0, 4.0])
                ->and(fn() => new MovementRouteRunner($field->scene, ['retrace' => 'common-recorded'], $execution))
                ->toThrow(MovementRouteException::class, 'only be retraced once');
            $interpreter->update(2.0);
            expect($execution->status)->toBe(EventExecutionStatus::COMPLETED)
                ->and(fn() => $execution->movementRoute('common-recorded'))->toThrow(RuntimeException::class, 'was not recorded in this session');
            $next = $cinematic ? $interpreter->runCinematic($definition) : $interpreter->run($commands, 'ordinary-root');
            expect($next->id)->not->toBe($execution->id);
            for ($tick = 0; $tick < 20 && ($next->pendingCommand['type'] ?? '') !== 'wait'; ++$tick) {
                $interpreter->update(0.0);
            }
            expect($next->pendingCommand['type'])->toBe('wait');
            $next->cancelLanes();
            expect(fn() => $next->movementRoute('root-recorded'))->toThrow(RuntimeException::class, 'was not recorded in this session');
        });
    } finally {
        $field->dispose();
    }
})->with([false, true]);
