<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Field\MapGridSource;

function mapInsertionSessionProject(): string
{
    $root = mapGraphicsProject();
    $directory = $root . '/assets/Maps/test-map';
    $path = $directory . '/test-map.data.php';
    file_put_contents($path, str_replace("'events' => [],", <<<'PHP'
'station' => ['x' => 2, 'y' => 1],
    'tileLayers' => ['floor' => ['offset' => [0, 1]]],
    'npcs' => [['id' => 'visitor', 'name' => 'Visitor', 'sprite' => 'v', 'x' => 2, 'y' => 1,
        'movement' => 'wander', 'wanderArea' => ['x' => 1, 'y' => 0, 'width' => 2, 'height' => 2]]],
    'events' => ['D' => ['class' => 'Ichiloto\Engine\Events\Triggers\SleepEventTrigger', 'data' => ['spawnPoint' => ['x' => 2, 'y' => 1]]]],
PHP, (string) file_get_contents($path)));
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource("    \n D  ", 'EVENTS', "// Authored event notes.\n"));
    $other = $root . '/assets/Maps/approach';
    mkdir($other, 0o777, true);
    file_put_contents($other . '/approach.map.php', MapGridSource::buildSource("....\n....", 'MAP'));
    file_put_contents($other . '/approach.event.php', MapGridSource::buildSource(" D  \n    ", 'EVENTS'));
    file_put_contents($other . '/approach.data.php', <<<'PHP'
<?php
// Keep this author's comment.
return ['name' => 'Approach', 'region' => '', 'events' => ['D' => [
    'class' => 'Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger',
    'data' => ['destinationMap' => 'test-map', 'spawnPoint' => ['x' => 2, 'y' => 1]],
]]];
PHP);
    file_put_contents($root . '/assets/Events/arrival.php', <<<'PHP'
<?php
return [
    ['type' => 'transfer', 'map' => 'test-map', 'x' => 2, 'y' => 1],
    ['type' => 'move_player', 'x' => 3, 'y' => 1],
];
PHP);
    $cinematic = $root . '/assets/Cutscenes/Cinematics/arrival';
    mkdir($cinematic, 0o777, true);
    file_put_contents($cinematic . '/arrival.data.php', <<<'PHP'
<?php
return ['id' => 'arrival', 'name' => 'Arrival', 'startMap' => 'test-map',
    'cast' => [['id' => 'visitor', 'sprite' => 'v', 'x' => 2, 'y' => 1]]];
PHP);
    file_put_contents($cinematic . '/arrival.script.php', <<<'PHP'
<?php
return [['type' => 'move_player', 'x' => 3, 'y' => 1]];
PHP);
    $system = $root . '/assets/Data/system.php';
    $data = ['startingPositions' => ['player' => ['destinationMap' => 'test-map', 'spawnPoint' => ['x' => 2, 'y' => 1]]]];
    file_put_contents($system, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    file_put_contents($root . '/assets/Data/save-compatibility.php', "<?php\nreturn ['contentVersion' => 0, 'migrations' => [], 'aliases' => [], 'tombstones' => []];\n");

    return $root;
}

function writeReviewedMapInsertion(EditorSession $session, array $question): array
{
    return $session->insertMapLines($question['map'], $question['revision'], $question['axis'], $question['at'], $question['count'], 'write', $question['confirm']);
}

it('reviews the shared complete insertion without writing and cancels without losing undo or copied content', function () {
    $root = mapInsertionSessionProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $clipboard = $session->copySelection('test-map', $map['baseLayer'], 0, 0, 1, 1);
    $before = sourceHashTree($root);
    $question = $session->insertMapLines('test-map', $map['revision'], 'y', 1, 2);
    expect($question)->toMatchArray(['status' => 'question', 'map' => 'test-map', 'revision' => $map['revision'],
        'axis' => 'y', 'at' => 1, 'count' => 2, 'width' => 4, 'height' => 4])
        ->and(array_column($question['answers'], 'key'))->toBe(['cancel', 'write'])
        ->and($question['answers'][1]['description'])->toContain('now, not on Save', 'Undo restores')
        ->and($question['paths'])->toContain('assets/Maps/test-map/test-map.event.php', 'assets/Maps/approach/approach.data.php',
            'assets/Events/arrival.php', 'assets/Data/system.php', 'assets/Data/save-compatibility.php',
            'assets/Cutscenes/Cinematics/arrival/arrival.data.php', 'assets/Cutscenes/Cinematics/arrival/arrival.script.php')
        ->and($question['notes'][0])->toContain('Saves move with the map')
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->insertMapLines('test-map', $map['revision'], 'y', 1, 2, 'cancel')['status'])->toBe('cancelled')
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->describeClipboard())->toBe($clipboard)
        ->and($session->undo()['label'])->toBeNull();
});

it('writes all coordinate owners together, reloads every document and reverses the source set through undo and redo', function (string $axis) {
    $root = mapInsertionSessionProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $other = $session->readMap('approach');
    $before = sourceHashTree($root);
    $question = $session->insertMapLines('test-map', $map['revision'], $axis, 1, 2);
    $result = writeReviewedMapInsertion($session, $question);
    $after = sourceHashTree($root);
    $data = require $root . '/assets/Maps/test-map/test-map.data.php';
    $point = $axis === 'x' ? ['x' => 4, 'y' => 1] : ['x' => 2, 'y' => 3];
    $loaded = $session->readMap('test-map');
    $eventLayer = array_find($loaded['layers'], static fn(array $layer): bool => $layer['event']);
    expect($result)->toMatchArray(['status' => 'inserted', 'map' => 'test-map', 'changed' => true])
        ->and($result['revision'])->toBeGreaterThan($map['revision'])
        ->and($session->readMap('approach')['revision'])->toBeGreaterThan($other['revision'])
        ->and([$loaded['width'], $loaded['height']])->toBe([$question['width'], $question['height']])
        ->and($loaded['dirty'])->toBeFalse()
        ->and($data['npcs'][0])->toMatchArray($point)
        ->and($data['npcs'][0]['wanderArea'])->toBe($axis === 'x'
            ? ['x' => 3, 'y' => 0, 'width' => 2, 'height' => 2]
            : ['x' => 1, 'y' => 0, 'width' => 2, 'height' => 4])
        ->and($data['events']['D']['data']['spawnPoint'])->toBe($point)
        ->and($data['station'])->toBe(['x' => 2, 'y' => 1])
        ->and($data['tileLayers'])->toBe(['floor' => ['offset' => [0, 1]]])
        ->and((require $root . '/assets/Maps/approach/approach.data.php')['events']['D']['data']['spawnPoint'])->toBe($point)
        ->and((require $root . '/assets/Data/system.php')['startingPositions']['player']['spawnPoint'])->toBe($point)
        ->and((require $root . '/assets/Cutscenes/Cinematics/arrival/arrival.data.php')['cast'][0])->toMatchArray($point)
        ->and((require $root . '/assets/Events/arrival.php')[0])->toMatchArray($point)
        ->and((require $root . '/assets/Data/save-compatibility.php')['migrations'][0]['mapShifts'][0])
            ->toBe(['map' => 'test-map', 'axis' => $axis, 'at' => 1, 'by' => 2])
        ->and($eventLayer)->not->toBeNull()
        ->and($eventLayer['rows'][$axis === 'x' ? 1 : 3][$axis === 'x' ? 3 : 1])->toBe('D')
        ->and(readTileRows($root . '/assets/Maps/test-map/graphics/01.floor.tiles.php')[$axis === 'x' ? 0 : 1][$axis === 'x' ? 1 : 0])->toBe(0)
        ->and(file_get_contents($root . '/assets/Maps/approach/approach.data.php'))->toContain("// Keep this author's comment.")
        ->and(fn() => writeReviewedMapInsertion($session, $question))->toThrow(SessionRefusal::class, 'changed since revision');
    $undo = $session->undo();
    expect($undo['maps'])->toContain('test-map', 'approach')
        ->and($undo['databases'])->not->toBeEmpty()
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->readMap('test-map')['revision'])->toBeGreaterThan($result['revision']);
    expect($session->redo()['label'])->toBe('Insert ' . ($axis === 'x' ? 'columns' : 'rows'))
        ->and(sourceHashTree($root))->toBe($after)
        ->and($session->readMap('test-map')['dirty'])->toBeFalse();
})->with(['x', 'y']);

it('keeps copied glyphs, styles and relative tiles usable after insertion, undo and redo', function (string $axis, string $state) {
    $root = mapGraphicsProject();
    writeTileLayer($root . '/assets/Maps/test-map', '01.floor.tiles.php', "5 6 0 0\n0 0 0 0");
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($path, str_replace("'events' => [],", "'events' => [], 'tileLayers' => ['floor' => ['movesWith' => 'terrain']],", (string) file_get_contents($path)));
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $layerId = $map['baseLayer'];
    $sourceLayer = array_find($map['layers'], static fn(array $layer): bool => $layer['id'] === $layerId);
    $sourceColors = array_column(array_filter($sourceLayer['colors'], static fn(array $cell): bool => $cell[1] === 0 && $cell[0] < 2), 2);
    $clipboard = $session->copySelection('test-map', $layerId, 0, 0, 2, 1);
    $before = sourceHashTree($root);
    $question = $session->insertMapLines('test-map', $map['revision'], $axis, 1, 2);
    writeReviewedMapInsertion($session, $question);
    $after = sourceHashTree($root);

    if ($state !== 'inserted') {
        $session->undo();
        expect(sourceHashTree($root))->toBe($before);
    }
    if ($state === 'redone') {
        $session->redo();
        expect(sourceHashTree($root))->toBe($after);
    }
    $x = $state !== 'undone' && $axis === 'x' ? 1 : 0;
    $y = $state !== 'undone' && $axis === 'x' ? 0 : 1;
    expect($session->describeClipboard())->toBe($clipboard);
    $pasted = $session->pasteSelection('test-map', $session->readMap('test-map')['revision'], $layerId, $x, $y);
    $layer = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['id'] === $layerId);
    $colors = array_column(array_filter($layer['colors'], static fn(array $cell): bool => $cell[1] === $y && $cell[0] >= $x && $cell[0] < $x + 2), 2);
    $tiles = array_column($session->readTiles('test-map')['layers'], null, 'name');
    expect($pasted)->toMatchArray(['status' => 'applied', 'changed' => 2])
        ->and(array_slice($layer['rows'][$y], $x, 2))->toBe(array_slice($sourceLayer['rows'][0], 0, 2))
        ->and($colors)->toBe($sourceColors)
        ->and(array_slice($tiles['floor']['rows'][$y], $x, 2))->toBe([5, 6])
        ->and($session->undo()['label'])->toBe('Paste selection')
        ->and($session->describeClipboard())->toBe($clipboard)
        ->and(sourceHashTree($root))->toBe($state === 'undone' ? $before : $after);
})->with(['x', 'y'])->with(['inserted', 'undone', 'redone']);

it('refuses changed choices and unreviewed writes without changing a file', function () {
    $root = mapInsertionSessionProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $question = $session->insertMapLines('test-map', $map['revision'], 'y', 1, 1);
    $before = sourceHashTree($root);
    expect(fn() => $session->insertMapLines('test-map', $map['revision'], 'y', 1, 1, 'write'))->toThrow(SessionRefusal::class, 'files or choices changed')
        ->and(fn() => $session->insertMapLines('test-map', $map['revision'], 'x', 1, 1, 'write', $question['confirm']))->toThrow(SessionRefusal::class, 'files or choices changed')
        ->and(fn() => $session->insertMapLines('test-map', $map['revision'], 'y', 1, 2, 'write', $question['confirm']))->toThrow(SessionRefusal::class, 'files or choices changed')
        ->and(fn() => $session->insertMapLines('test-map', $map['revision'], 'y', 1, 1, 'yes', $question['confirm']))->toThrow(SessionRefusal::class, 'Answer write or cancel')
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->undo()['label'])->toBeNull();
});

it('refuses unsaved work in any map before both review and confirmed writes', function () {
    $root = mapInsertionSessionProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $question = $session->insertMapLines('test-map', $map['revision'], 'y', 1, 1);
    $other = $session->readMap('approach');
    $session->paint('approach', $other['revision'], $other['baseLayer'], [[0, 0]], '#');
    $before = sourceHashTree($root);
    expect(fn() => $session->insertMapLines('test-map', $map['revision'], 'y', 1, 1))->toThrow(SessionRefusal::class, 'Save or undo pending')
        ->and(fn() => writeReviewedMapInsertion($session, $question))->toThrow(SessionRefusal::class, 'Save or undo pending')
        ->and($session->readMap('approach')['dirty'])->toBeTrue()
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->undo()['label'])->toBe('Paint');
});

it('refuses external changes after review, including newly discovered references', function (bool $newFile) {
    $root = mapInsertionSessionProject();
    $session = EditorSession::open($root);
    $question = $session->insertMapLines('test-map', $session->readMap('test-map')['revision'], 'y', 1, 1);
    $path = $root . '/assets/Events/' . ($newFile ? 'new-reference' : 'arrival') . '.php';
    if ($newFile) {
        file_put_contents($path, "<?php\nreturn [['type' => 'transfer', 'map' => 'test-map', 'x' => 2, 'y' => 1]];\n");
    } else {
        file_put_contents($path, (string) file_get_contents($path) . "\n// Another writer's change.\n");
    }
    $before = sourceHashTree($root);
    expect(fn() => writeReviewedMapInsertion($session, $question))->toThrow(SessionRefusal::class, 'files or choices changed')
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->undo()['label'])->toBeNull();
})->with([false, true]);

it('preserves external edits when undoing an applied insertion is no longer safe', function () {
    $root = mapInsertionSessionProject();
    $session = EditorSession::open($root);
    $question = $session->insertMapLines('test-map', $session->readMap('test-map')['revision'], 'y', 1, 1);
    writeReviewedMapInsertion($session, $question);
    $path = $root . '/assets/Events/arrival.php';
    file_put_contents($path, (string) file_get_contents($path) . "\n// Another writer's change.\n");
    $before = sourceHashTree($root);
    expect(fn() => $session->undo())->toThrow(RuntimeException::class, 'changed outside')
        ->and(sourceHashTree($root))->toBe($before);
});

it('reports expressions needing hand edits and never flattens their source', function () {
    $root = mapInsertionSessionProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($path, str_replace("'y' => 1,", "'y' => 0 + 1,", (string) file_get_contents($path)));
    $session = EditorSession::open($root);
    $question = $session->insertMapLines('test-map', $session->readMap('test-map')['revision'], 'y', 1, 1);
    expect(array_column($question['handEdits'], 'where'))->toContain('npcs.0.y');
    writeReviewedMapInsertion($session, $question);
    expect(file_get_contents($path))->toContain("'y' => 0 + 1,");
});

it('exposes strict request validation, review, cancel, apply and undo through the actual host', function () {
    $root = mapInsertionSessionProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array => $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $map = $request('map.read', ['map' => 'test-map'])['result'];
    $params = ['map' => 'test-map', 'revision' => $map['revision'], 'axis' => 'y', 'at' => 1, 'count' => 1];
    $before = sourceHashTree($root);
    foreach (['revision', 'at', 'count', 'axis', 'confirm', 'answer'] as $field) {
        expect($request('map.insertLines', [...$params, $field => []])['error']['kind'])->toBe('request');
    }
    expect($request('map.insertLines', [...$params, 'axis' => 'z'])['error']['kind'])->toBe('refusal')
        ->and($request('map.insertLines', [...$params, 'at' => 99])['error']['kind'])->toBe('refusal')
        ->and($request('map.insertLines', [...$params, 'count' => 0])['error']['kind'])->toBe('refusal');
    $question = $request('map.insertLines', $params)['result'];
    expect($question['status'])->toBe('question')
        ->and($request('map.insertLines', [...$params, 'answer' => 'cancel'])['result']['status'])->toBe('cancelled')
        ->and(sourceHashTree($root))->toBe($before)
        ->and($request('map.insertLines', [...$params, 'answer' => 'write', 'confirm' => $question['confirm']])['result']['status'])->toBe('inserted')
        ->and($request('history.undo')['result']['label'])->toBe('Insert row')
        ->and(sourceHashTree($root))->toBe($before);
});
