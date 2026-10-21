<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * An event's record rows through the session, as a GUI editor or an agent
 * authors them: the fields every trigger reads on every event type, a Story
 * Script's inline commands in the shared command editor, a Dialogue event's
 * conditional pages and talk-to id, the map's region station and a spawn
 * facing. Every edit is checked against the written source: authored
 * comments, expressions and untouched keys stay as they were.
 */

/** The row of an inspector read whose key names a field (an event record row) or a path. */
function eventRecordRow(array $read, string $field): array
{
    return array_find($read['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field
        || implode('.', $row['key']['path'] ?? []) === $field)
        ?? throw new RuntimeException(sprintf('No row %s among: %s', $field, implode(', ', array_map(
            static fn(array $row): string => (string) ($row['key']['field'] ?? implode('.', $row['key']['path'] ?? [])), $read['rows']))));
}

/** A JSON-line host over a project, and a request function. */
function eventRecordHost(string $root): Closure
{
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);

    return $request;
}

it('offers every trigger\'s conditions, writes, blocked message and cue conditions on a transfer, written only once set', function () {
    $root = eventRecordProject();
    $request = eventRecordHost($root);
    $before = file_get_contents(eventRecordDataPath($root));
    $read = $request('inspector.read', ['map' => 'test-map', 'event' => 'T'])['result'];

    expect(eventRecordRow($read, 'conditions'))->toMatchArray(['kind' => 'conditions', 'label' => 'Available When', 'raw' => ''])
        ->and(eventRecordRow($read, 'sets'))->toMatchArray(['kind' => 'writes', 'label' => 'Then Set'])
        ->and(eventRecordRow($read, 'whenBlocked'))->toMatchArray(['kind' => 'text', 'label' => 'When Blocked'])
        ->and(eventRecordRow($read, 'cue.conditions'))->toMatchArray(['kind' => 'conditions', 'label' => 'Cue When'])
        ->and(eventRecordRow($read, 'conditions')['key'])->toBe(['target' => 'event-record', 'field' => 'conditions', 'marker' => 'T', 'frame' => []])
        ->and(file_get_contents(eventRecordDataPath($root)))->toBe($before);

    $revision = $read['revision'];
    foreach ([
        'conditions' => '!event:door_open; quest:intro:active; map_event:test-map:E; variable:count:>=:3',
        'sets' => 'switch:lit:false; quest:intro:grant',
        'whenBlocked' => 'The door is shut.',
        'cue.conditions' => 'event:door_open',
    ] as $field => $value) {
        $applied = $request('inspector.apply', ['map' => 'test-map', 'revision' => $revision, 'key' => eventRecordRow($read, $field)['key'], 'value' => $value])['result'];
        expect($applied['changed'])->toBeTrue();
        $revision = $applied['revision'];
    }
    $request('map.save', ['map' => 'test-map']);
    $source = file_get_contents(eventRecordDataPath($root));
    $event = (require eventRecordDataPath($root))['events']['T'];

    expect($event['conditions'])->toBe([
        ['type' => 'event', 'name' => 'door_open', 'negate' => true],
        ['type' => 'quest', 'name' => 'intro', 'status' => 'active'],
        ['type' => 'map_event', 'name' => 'test-map', 'marker' => 'E'],
        ['type' => 'variable', 'name' => 'count', 'op' => '>=', 'value' => 3],
    ])
        ->and($event['sets'])->toBe([['type' => 'switch', 'name' => 'lit', 'value' => false], ['type' => 'quest', 'name' => 'intro', 'confirm' => false]])
        ->and($event['whenBlocked'])->toBe('The door is shut.')
        ->and($event['cue'])->toBe(['conditions' => [['type' => 'event', 'name' => 'door_open']]])
        ->and($event['data'])->toBe(['destinationMap' => 'test-map', 'spawnPoint' => ['x' => 1, 'y' => 1], 'spawnSprite' => ['@']])
        ->and($source)->toContain('// Synthetic map for event record authoring.', '// The way out.', '// Timed by the author.', "'seconds' => abs(1)");

    // Cleared, a field leaves the event as it was before it was set.
    $read = $request('inspector.read', ['map' => 'test-map', 'event' => 'T'])['result'];
    $revision = $request('inspector.apply', ['map' => 'test-map', 'revision' => $read['revision'], 'key' => eventRecordRow($read, 'conditions')['key'], 'value' => ''])['result']['revision'];
    $request('inspector.apply', ['map' => 'test-map', 'revision' => $revision, 'key' => eventRecordRow($read, 'cue.conditions')['key'], 'value' => '']);
    $request('map.save', ['map' => 'test-map']);
    expect((require eventRecordDataPath($root))['events']['T'])->not->toHaveKeys(['conditions', 'cue'])
        ->and($request('history.undo')['result']['label'])->toBe('Cue When edit');
});

it('refuses condition and write lines the Engine would not read, and lists the line cannot carry', function () {
    $root = eventRecordProject();
    $session = EditorSession::open($root);
    $before = file_get_contents(eventRecordDataPath($root));
    $read = $session->readInspector('test-map', 'D');

    expect(fn() => $session->applyInspector('test-map', $read['revision'], eventRecordRow($read, 'conditions')['key'], 'switch:open; nonsense'))
        ->toThrow(SessionRefusal::class, 'Condition "nonsense" cannot be read')
        ->and(fn() => $session->applyInspector('test-map', $read['revision'], eventRecordRow($read, 'conditions')['key'], 'variable:count:~:3'))
        ->toThrow(SessionRefusal::class, 'a variable compares with == != > >= < <=, not "~"')
        ->and(fn() => $session->applyInspector('test-map', $read['revision'], eventRecordRow($read, 'variant0Sets')['key'], 'teleport:home'))
        ->toThrow(SessionRefusal::class, 'Write "teleport:home" cannot be read');

    // An authored key outside the write line's vocabulary is kept, never dropped by an edit through the line.
    $sets = eventRecordRow($read, 'sets');
    expect($sets['kind'])->toBe('info')
        ->and($sets['value'])->toContain('write 1 carries "note"')
        ->and(fn() => $session->applyInspector('test-map', $read['revision'], $sets['key'], 'event:met_guide'))->toThrow(SessionRefusal::class, 'cannot be edited here')
        ->and($session->undo()['label'])->toBeNull();
    $session->saveMap('test-map');
    expect(file_get_contents(eventRecordDataPath($root)))->toBe($before);
});

it('leaves authored empty lists alone, writes no empty defaults on new events, and keeps trigger fields through a retype', function () {
    $root = eventRecordProject();
    $session = EditorSession::open($root);
    $read = $session->readInspector('test-map', 'S');
    $session->applyInspector('test-map', $read['revision'], eventRecordRow($read, 'whenBlocked')['key'], 'Not yet.');
    $session->saveMap('test-map');
    $event = (require eventRecordDataPath($root))['events']['S'];

    expect($event['conditions'])->toBe([])
        ->and($event['whenBlocked'])->toBe('Not yet.')
        ->and(file_get_contents(eventRecordDataPath($root)))->toContain("'seconds' => abs(1)");

    $map = $session->readMap('test-map');
    foreach (['Story Script', 'Cinematic', 'Dialogue'] as $index => $type) {
        $marker = $session->createEvent('test-map', $session->readMap('test-map')['revision'], [[1 + $index, 3]], $type)['marker'];
        expect(getSessionMap($session)->getEventDefinition($marker))->not->toHaveKeys(['conditions', 'sets', 'whenBlocked']);
    }
    expect($map['revision'])->toBeInt();

    // Conditions belong to the trigger, not its type: a retype keeps them and starts the data again.
    $dialogue = $session->readInspector('test-map', 'D');
    $revision = $session->applyInspector('test-map', $dialogue['revision'], eventRecordRow($dialogue, 'conditions')['key'], 'event:door_open')['revision'];
    $type = array_find($session->readInspector('test-map', 'D')['rows'], static fn(array $row): bool => ($row['key']['target'] ?? null) === 'event-type');
    $session->applyInspector('test-map', $revision, $type['key'], 'Transfer Player');
    $retyped = getSessionMap($session)->getEventDefinition('D');

    expect($retyped['class'])->toBe('Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger')
        ->and($retyped['conditions'])->toBe([['type' => 'event', 'name' => 'door_open']])
        ->and($retyped['sets'])->toBe([['type' => 'event', 'name' => 'met_guide', 'note' => 'kept by the author']])
        ->and($retyped['data'])->toHaveKeys(['destinationMap', 'spawnPoint', 'spawnSprite'])
        ->and($retyped['data'])->not->toHaveKey('dialogue');
});

it('creates and edits a Story Script\'s inline commands with the shared command editor, frame by frame over the protocol', function () {
    $root = eventRecordProject();
    $request = eventRecordHost($root);
    $map = $request('map.read', ['map' => 'test-map'])['result'];
    $marker = $request('event.create', ['map' => 'test-map', 'revision' => $map['revision'], 'cells' => [[2, 3]], 'type' => 'Story Script'])['result']['marker'];
    $script = eventRecordRow($request('inspector.read', ['map' => 'test-map', 'event' => $marker])['result'], 'commandListScript');

    expect($script)->toMatchArray(['kind' => 'info', 'label' => 'Script', 'value' => '0', 'frame' => ['script']]);

    $frame = $request('inspector.read', ['map' => 'test-map', 'event' => $marker, 'frame' => ['script']])['result'];
    expect($frame['frameLabel'])->toBe('Script')
        ->and(array_column($frame['rows'], 'label'))->toBe(['No commands yet']);
    $added = $request('inspector.add', ['map' => 'test-map', 'revision' => $frame['revision'], 'key' => $frame['rows'][0]['key']])['result'];
    expect($added['message'])->toBe('Added a command.');

    $frame = $request('inspector.read', ['map' => 'test-map', 'event' => $marker, 'frame' => ['script']])['result'];
    $type = eventRecordRow($frame, 'command0Type');
    expect($type['kind'])->toBe('options')
        ->and($type['options'])->toContain('text', 'branch', 'set_variable', 'move_player')
        ->and($type['key']['frame'])->toBe(['script']);
    $revision = $request('inspector.apply', ['map' => 'test-map', 'revision' => $frame['revision'], 'key' => eventRecordRow($frame, 'command0Text')['key'], 'value' => 'Halt.'])['result']['revision'];
    $revision = $request('inspector.add', ['map' => 'test-map', 'revision' => $revision, 'key' => eventRecordRow($frame, 'command0Text')['key']])['result']['revision'];
    $frame = $request('inspector.read', ['map' => 'test-map', 'event' => $marker, 'frame' => ['script']])['result'];
    $revision = $request('inspector.apply', ['map' => 'test-map', 'revision' => $revision, 'key' => eventRecordRow($frame, 'command1Type')['key'], 'value' => 'branch'])['result']['revision'];

    // A branch's arm is a frame of its own.
    $frame = $request('inspector.read', ['map' => 'test-map', 'event' => $marker, 'frame' => ['script']])['result'];
    $then = eventRecordRow($frame, 'command1Then');
    expect($then['frame'])->toBe(['script', 1, 'then']);
    $arm = $request('inspector.read', ['map' => 'test-map', 'event' => $marker, 'frame' => $then['frame']])['result'];
    $revision = $request('inspector.add', ['map' => 'test-map', 'revision' => $revision, 'key' => $arm['rows'][0]['key']])['result']['revision'];
    $arm = $request('inspector.read', ['map' => 'test-map', 'event' => $marker, 'frame' => $then['frame']])['result'];
    $request('inspector.apply', ['map' => 'test-map', 'revision' => $revision, 'key' => eventRecordRow($arm, 'command0Type')['key'], 'value' => 'set_switch']);
    $request('map.save', ['map' => 'test-map']);
    $saved = (require eventRecordDataPath($root))['events'][$marker]['data'];

    expect($saved['script'][0])->toBe(['type' => 'text', 'name' => '', 'text' => 'Halt.'])
        ->and($saved['script'][1]['type'])->toBe('branch')
        ->and($saved['script'][1]['then'][0]['type'])->toBe('set_switch')
        ->and($saved)->not->toHaveKey('scriptId')
        ->and($request('inspector.read', ['map' => 'test-map', 'event' => $marker, 'frame' => ['script', 9, 'then']])['error']['message'])
            ->toContain('no longer on the event');
});

it('drops an existing Story Script\'s empty script id when the event is edited, leaving untouched events as authored', function () {
    $root = eventRecordProject();
    $path = eventRecordDataPath($root);
    file_put_contents($path, str_replace("'scriptId' => 'harbour-watch',", "'scriptId' => '',", file_get_contents($path)));
    $session = EditorSession::open($root);
    $row = static function (string $label) use ($session): array {
        $read = $session->readInspector('test-map', 'S');
        return ['revision' => $read['revision'], 'key' => array_find($read['rows'], static fn(array $row): bool
            => ($row['key']['target'] ?? null) === 'event' && ($row['label'] ?? null) === $label)['key']
            ?? throw new RuntimeException('Missing row ' . $label)];
    };

    // Saving another event leaves this one's source alone.
    $dialogue = $session->readInspector('test-map', 'D');
    $session->applyInspector('test-map', $dialogue['revision'], array_find($dialogue['rows'], static fn(array $row): bool
        => ($row['key']['target'] ?? null) === 'event' && ($row['key']['marker'] ?? null) === 'D'
        && ($row['key']['path'] ?? null) !== null && ($row['kind'] ?? null) === 'text')['key'], 'Hello.');
    $session->saveMap('test-map');
    expect((require $path)['events']['S']['data'])->toHaveKey('scriptId');

    ['revision' => $revision, 'key' => $key] = $row('Mode');
    $session->applyInspector('test-map', $revision, $key, 'action');
    $session->saveMap('test-map');
    $saved = (require $path)['events']['S'];
    expect($saved['data'])->not->toHaveKey('scriptId')
        ->and($saved['data']['mode'])->toBe('action')
        ->and($saved['data']['script'])->toHaveCount(2)
        ->and(file_get_contents($path))->toContain('// Timed by the author.');
});

it('edits an authored inline script in place, keeping its comments and expressions, and refuses what the source cannot take', function () {
    $root = eventRecordProject();
    $session = EditorSession::open($root);
    $rootRows = $session->readInspector('test-map', 'S');

    expect(array_column($rootRows['rows'], 'label'))->toContain('! Inline script replaces Event Script');

    $frame = $session->readInspector('test-map', 'S', ['script']);
    $before = file_get_contents(eventRecordDataPath($root));
    expect(fn() => $session->applyInspector('test-map', $frame['revision'], eventRecordRow($frame, 'command1Seconds')['key'], '3'))
        ->toThrow(SessionRefusal::class)
        ->and($session->undo()['label'])->toBeNull();

    $session->applyInspector('test-map', $frame['revision'], eventRecordRow($frame, 'command0Text')['key'], 'Stop right there.');
    $session->saveMap('test-map');
    $after = file_get_contents(eventRecordDataPath($root));

    expect($after)->toBe(str_replace("'text' => 'Stop there.'", "'text' => 'Stop right there.'", $before))
        ->and($before)->not->toBe($after);
});

it('turns a Dialogue event\'s pages into conditional variants without losing a line, and names who is talked to', function () {
    $root = eventRecordProject();
    $session = EditorSession::open($root);
    $read = $session->readInspector('test-map', 'D');
    $pages = [['name' => 'Guide', 'text' => 'First line.'], ['name' => '', 'text' => 'Second line.']];

    expect(eventRecordRow($read, 'npcId'))->toMatchArray(['kind' => 'text', 'label' => 'Talk-To Id', 'value' => '', 'raw' => ''])
        ->and(eventRecordRow($read, 'variantList'))->toMatchArray(['listHeading' => true, 'value' => '1'])
        ->and(eventRecordRow($read, 'variant0Line1Text')['value'])->toBe('Second line.');

    // Pages stay pages while nothing makes them a variant.
    $revision = $session->applyInspector('test-map', $read['revision'], eventRecordRow($read, 'npcId')['key'], 'guide-post')['revision'];
    expect(getSessionMap($session)->getEventDefinition('D')['data'])->toBe(['dialogue' => $pages, 'npcId' => 'guide-post']);

    $revision = $session->applyInspector('test-map', $revision, eventRecordRow($read, 'variant0Conditions')['key'], 'event:met_guide')['revision'];
    expect(getSessionMap($session)->getEventDefinition('D')['data']['dialogue'])->toBe([
        ['lines' => $pages, 'conditions' => [['type' => 'event', 'name' => 'met_guide']]],
    ]);

    $read = $session->readInspector('test-map', 'D');
    $revision = $session->addInspectorListEntry('test-map', $revision, eventRecordRow($read, 'variant0Line1Text')['key'])['revision'];
    $added = $session->addInspectorListEntry('test-map', $revision, eventRecordRow($read, 'variantList')['key']);
    expect($added['message'])->toBe('Added a dialogue variant.');
    $read = $session->readInspector('test-map', 'D');
    // A new variant goes last, where the unconditioned fallback belongs: the first variant that holds is spoken.
    $session->applyInspector('test-map', $added['revision'], eventRecordRow($read, 'variant1Line0Text')['key'], 'Before we met.');
    $read = $session->readInspector('test-map', 'D');

    expect(fn() => $session->applyInspector('test-map', $read['revision'], eventRecordRow($read, 'variant1Conditions')['key'], 'nonsense'))
        ->toThrow(SessionRefusal::class, 'cannot be read');

    $revision = $session->applyInspector('test-map', $read['revision'], eventRecordRow($read, 'npcId')['key'], '')['revision'];
    $session->saveMap('test-map');
    $saved = (require eventRecordDataPath($root))['events']['D'];

    expect($saved['data'])->not->toHaveKey('npcId')
        ->and($saved['data']['dialogue'][0]['conditions'])->toBe([['type' => 'event', 'name' => 'met_guide']])
        ->and(array_column($saved['data']['dialogue'][0]['lines'], 'text'))->toBe(['First line.', 'Second line.', 'Something to say.'])
        ->and($saved['data']['dialogue'][1])->toBe(['lines' => [['name' => '', 'text' => 'Before we met.']]])
        ->and($saved['sets'])->toBe([['type' => 'event', 'name' => 'met_guide', 'note' => 'kept by the author']])
        ->and($revision)->toBeInt();
});

it('pins a map to a cell of its region map as one x, y pair, and takes the pin away', function () {
    $root = eventRecordProject();
    $request = eventRecordHost($root);
    $read = $request('inspector.read', ['map' => 'test-map'])['result'];
    $station = eventRecordRow($read, 'station');

    expect($station)->toMatchArray(['kind' => 'text', 'label' => 'Region Map Station', 'raw' => '', 'value' => '(placed from its doors)']);
    foreach (['3', '3, 4, 5', 'a, b', '1.5, 2'] as $bad) {
        expect($request('inspector.apply', ['map' => 'test-map', 'revision' => $read['revision'], 'key' => $station['key'], 'value' => $bad])['error']['message'])
            ->toContain('two whole numbers');
    }

    $applied = $request('inspector.apply', ['map' => 'test-map', 'revision' => $read['revision'], 'key' => $station['key'], 'value' => ' -2, 7 '])['result'];
    expect(eventRecordRow($request('inspector.read', ['map' => 'test-map'])['result'], 'station')['raw'])->toBe('-2, 7');
    $request('map.save', ['map' => 'test-map']);
    expect((require eventRecordDataPath($root))['station'])->toBe(['x' => -2, 'y' => 7])
        ->and(file_get_contents(eventRecordDataPath($root)))->toContain('// Synthetic map for event record authoring.');

    $request('inspector.apply', ['map' => 'test-map', 'revision' => $applied['revision'], 'key' => $station['key'], 'value' => '']);
    $request('map.save', ['map' => 'test-map']);
    expect(require eventRecordDataPath($root))->not->toHaveKey('station');
});

it('picks the heading a transfer arrives facing, with its destination or alone, from the Engine\'s headings', function () {
    $root = eventRecordProject();
    $request = eventRecordHost($root);
    $read = $request('inspector.read', ['map' => 'test-map', 'event' => 'T'])['result'];
    $facing = eventRecordRow($read, 'data.spawnSprite');

    expect($facing)->toMatchArray(['kind' => 'options', 'label' => 'Spawn Facing', 'value' => '(sprite art: @)', 'options' => ['North', 'East', 'South', 'West']])
        ->and($request('inspector.apply', ['map' => 'test-map', 'revision' => $read['revision'], 'key' => $facing['key'], 'value' => 'Up'])['error']['message'])
            ->toContain('choose one of');

    $revision = $request('inspector.apply', ['map' => 'test-map', 'revision' => $read['revision'], 'key' => $facing['key'], 'value' => 'North'])['result']['revision'];
    expect(eventRecordRow($request('inspector.read', ['map' => 'test-map', 'event' => 'T'])['result'], 'data.spawnSprite')['value'])->toBe('North');

    // With the destination: omitted, the facing stays; given, it is checked and set with the spawn point as one step.
    $moved = $request('event.destination', ['map' => 'test-map', 'revision' => $revision, 'marker' => 'T', 'destination' => 'test-map', 'x' => 2, 'y' => 2])['result'];
    expect($moved['changed'])->toBeTrue()
        ->and(eventRecordRow($request('inspector.read', ['map' => 'test-map', 'event' => 'T'])['result'], 'data.spawnSprite')['value'])->toBe('North');
    expect($request('event.destination', ['map' => 'test-map', 'revision' => $moved['revision'], 'marker' => 'T', 'destination' => 'test-map', 'x' => 3, 'y' => 2, 'heading' => 'None'])['error']['message'])
        ->toContain('not a spawn facing');
    $request('event.destination', ['map' => 'test-map', 'revision' => $moved['revision'], 'marker' => 'T', 'destination' => 'test-map', 'x' => 3, 'y' => 2, 'heading' => 'West']);
    $request('map.save', ['map' => 'test-map']);

    expect((require eventRecordDataPath($root))['events']['T']['data'])->toBe(['destinationMap' => 'test-map', 'spawnPoint' => ['x' => 3, 'y' => 2], 'spawnSprite' => ['West']])
        ->and($request('history.undo')['result']['label'])->toBe('Destination change')
        ->and(eventRecordRow($request('inspector.read', ['map' => 'test-map', 'event' => 'T'])['result'], 'data.spawnSprite')['value'])->toBe('North');

    $created = $request('event.create', ['map' => 'test-map', 'revision' => $request('map.read', ['map' => 'test-map'])['result']['revision'], 'cells' => [[2, 3]], 'type' => 'Transfer Player'])['result'];
    expect(eventRecordRow($request('inspector.read', ['map' => 'test-map', 'event' => $created['marker']])['result'], 'data.spawnSprite')['value'])->toBe('South');
});

it('places a Story Script\'s inline route on the map through its frame rows, at the commands\' place in the event data', function () {
    $root = eventRecordProject();
    $path = eventRecordDataPath($root);
    file_put_contents($path, str_replace("          ['type' => 'text', 'name' => 'Observer', 'text' => 'Stop there.'],",
        "          ['type' => 'move_route', 'subject' => 'player', 'steps' => [['direction' => 'right', 'count' => 1, 'faceOnly' => false]]],",
        (string) file_get_contents($path)));
    $session = EditorSession::open($root);
    $read = $session->readInspector('test-map', 'S', ['script']);
    $row = array_find($read['rows'], static fn(array $row): bool => ($row['mapPlacement']['kind'] ?? null) === 'route')
        ?? throw new RuntimeException('No route placement row.');

    expect($row['key']['frame'])->toBe(['script'])
        ->and($row['mapPlacement']['issue'] ?? null)->toBeNull();

    $session->applyMapPlacement(['kind' => 'event', 'map' => 'test-map', 'revision' => $read['revision']],
        $row['key'], $row['mapPlacement'], 'test-map', 0, 1, 3, [1, 1], $read['revision']);
    $session->saveMap('test-map');
    $script = (require $path)['events']['S']['data']['script'];

    expect($script[0]['steps'])->toBe([['direction' => 'down', 'count' => 2, 'faceOnly' => false]])
        ->and(file_get_contents($path))->toContain("'seconds' => abs(1)", '// Timed by the author.');
});

it('offers no row for the subject an event is bound to or its area, which their own operations set', function () {
    $root = eventRecordProject();
    $path = eventRecordDataPath($root);
    file_put_contents($path, str_replace("    'D' => [\n      'class'", "    'D' => [\n      'subject' => ['kind' => 'world_object', 'id' => 'guide-post'],\n      'class'", (string) file_get_contents($path)));
    $rows = EditorSession::open($root)->readInspector('test-map', 'D')['rows'];

    expect(array_filter($rows, static fn(array $row): bool => in_array($row['key']['path'][0] ?? $row['key']['field'] ?? null, ['subject', 'area'], true)))->toBe([]);
});
