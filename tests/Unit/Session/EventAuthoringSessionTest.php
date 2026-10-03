<?php

declare(strict_types=1);

use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Events authored through the session as the GUI editor authors them:
 * created, retyped, moved, reshaped and deleted as one undo step each, the
 * rows the terminal edits through flows of its own (an event's type, its
 * destination, the map's kind, list entries) served as plain edits, and
 * every refusal (stale, read-only, overlapping) a reason an author reads.
 */

/** The first row matching a predicate. */
function eventSessionRow(array $inspector, callable $matches): array
{
    return array_find($inspector['rows'], $matches) ?? throw new RuntimeException('No such inspector row.');
}

/** The first row with a label, after the row labelled $after when given. */
function eventSessionRowLabelled(array $inspector, string $label, ?string $after = null): array
{
    $start = $after === null ? 0 : array_search($after, array_map('trim', array_column($inspector['rows'], 'label')), true) + 1;

    return eventSessionRow(['rows' => array_slice($inspector['rows'], $start)], static fn(array $row): bool => trim($row['label']) === $label);
}

/** The event a map read lists under a marker. */
function eventSessionEvent(array $map, string $marker): ?array
{
    return array_find($map['events'], static fn(array $event): bool => $event['marker'] === $marker);
}

/** Free event cells in one row of a map read, found rather than assumed. */
function eventSessionFreeCells(array $map, int $count): array
{
    $layer = array_find($map['layers'], static fn(array $layer): bool => $layer['event']);
    foreach ($layer['rows'] as $y => $row) {
        for ($x = 0; $x + $count <= count($row); $x++) {
            if (array_all(array_slice($row, $x, $count), static fn(string $symbol): bool => trim($symbol) === '')) {
                return array_map(static fn(int $offset): array => [$x + $offset, $y], range(0, $count - 1));
            }
        }
    }

    throw new RuntimeException('No free event cells.');
}

it('lists every placed event, saying which has no definition yet', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $map = $session->readMap('test-map');
    [$cell] = eventSessionFreeCells($map, 1);
    $session->paint('test-map', $map['revision'], 'event', [$cell], '@');

    $read = $session->readMap('test-map');

    expect(eventSessionEvent($read, '@'))->toBe(['marker' => '@', 'type' => 'Unset', 'defined' => false, 'cells' => [$cell]])
        ->and(array_filter($read['events'], static fn(array $event): bool => $event['marker'] !== '@'))
            ->each->toHaveKey('defined', true);
});

it('creates, moves, reshapes and deletes an event over the protocol, one undo step each, refusing stale and overlapping edits', function () {
    $root = makeTemporaryProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params]));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $map = $request('map.read', ['map' => 'test-map'])['result'];
    $cells = eventSessionFreeCells($map, 2);

    expect(array_column($request('event.types')['result'], 'label'))
        ->toBe(array_map(static fn($type): string => $type->label, EventTypeCatalog::all()));

    $created = $request('event.create', ['map' => 'test-map', 'revision' => $map['revision'], 'cells' => $cells, 'type' => 'Dialogue'])['result'];
    $marker = $created['marker'];
    $read = $request('map.read', ['map' => 'test-map'])['result'];

    expect(eventSessionEvent($read, $marker))->toBe(['marker' => $marker, 'type' => 'Dialogue', 'defined' => true, 'cells' => $cells])
        ->and($created['revision'])->toBe($read['revision'])
        ->and($request('event.create', ['map' => 'test-map', 'revision' => $map['revision'], 'cells' => $cells, 'type' => 'Dialogue'])['error']['message'])
            ->toContain('changed since revision')
        ->and($request('event.create', ['map' => 'test-map', 'revision' => $read['revision'], 'cells' => $cells, 'type' => 'Dialogue'])['error']['message'])
            ->toContain("Marker {$marker} already holds")
        ->and($request('event.create', ['map' => 'test-map', 'revision' => $read['revision'], 'cells' => [[0, 0]], 'type' => 'Nope'])['error'])
            ->toBe(['kind' => 'refusal', 'message' => 'There is no event type Nope.']);

    // Another event's cells are never covered: a move onto them is refused.
    $other = array_find($read['events'], static fn(array $event): bool => $event['marker'] !== $marker);
    [$ox, $oy] = $other['cells'][0];
    [$mx, $my] = $cells[0];
    expect($request('event.move', ['map' => 'test-map', 'revision' => $read['revision'], 'marker' => $marker, 'dx' => $ox - $mx, 'dy' => $oy - $my])['error']['message'])
        ->toContain("would cover marker {$other['marker']}");

    $height = $read['height'];
    $moved = $request('event.move', ['map' => 'test-map', 'revision' => $read['revision'], 'marker' => $marker, 'dx' => 0, 'dy' => $my + 1 < $height ? 1 : -1])['result'];
    expect($moved['changed'])->toBeTrue()
        ->and($request('event.bounds', ['map' => 'test-map', 'revision' => $moved['revision'], 'marker' => $marker,
            'x' => 0, 'y' => 0, 'width' => $read['width'] + 1, 'height' => 1])['error']['message'])->toContain('would leave the map');

    $deleted = $request('event.delete', ['map' => 'test-map', 'revision' => $moved['revision'], 'marker' => $marker])['result'];
    expect(eventSessionEvent($request('map.read', ['map' => 'test-map'])['result'], $marker))->toBeNull()
        ->and($deleted['revision'])->toBeInt();

    expect($request('history.undo')['result']['label'])->toBe('Event delete')
        ->and(eventSessionEvent($request('map.read', ['map' => 'test-map'])['result'], $marker)['type'])->toBe('Dialogue')
        ->and($request('history.undo')['result']['label'])->toBe('Event move')
        ->and($request('history.undo')['result']['label'])->toBe('Event create')
        ->and(eventSessionEvent($request('map.read', ['map' => 'test-map'])['result'], $marker))->toBeNull()
        ->and($request('history.redo')['result']['label'])->toBe('Event create')
        ->and(eventSessionEvent($request('map.read', ['map' => 'test-map'])['result'], $marker)['cells'])->toBe($cells);
});

it('refuses event edits on a read-only map', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Maps/test-map/test-map.map.php', "<?php\nreturn 'not a nowdoc';\n");
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');

    expect(fn() => $session->createEvent('test-map', $map['revision'], [[0, 0]], 'Dialogue'))->toThrow(SessionRefusal::class, 'read-only')
        ->and(fn() => $session->deleteEvent('test-map', $map['revision'], '@'))->toThrow(SessionRefusal::class, 'read-only');
});

it('retypes an event from its Type row, one of the catalog\'s labels, and picks chest and loot types from theirs', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $map = $session->readMap('test-map');
    $created = $session->createEvent('test-map', $map['revision'], eventSessionFreeCells($map, 1), 'Dialogue');
    $marker = $created['marker'];
    $type = eventSessionRowLabelled($session->readInspector('test-map', $marker), 'Type');

    expect($type)->toMatchArray(['kind' => 'options', 'value' => 'Dialogue'])
        ->and($type['options'])->toBe(array_map(static fn($definition): string => $definition->label, EventTypeCatalog::all()))
        ->and(fn() => $session->applyInspector('test-map', $created['revision'], $type['key'], 'Nope'))
            ->toThrow(SessionRefusal::class, 'choose one of');

    $applied = $session->applyInspector('test-map', $created['revision'], $type['key'], 'Chest');
    $chest = $session->readInspector('test-map', $marker);
    $chestType = eventSessionRowLabelled($chest, 'Chest Type');

    expect($applied)->toMatchArray(['status' => 'applied', 'changed' => true])
        ->and(eventSessionEvent($session->readMap('test-map'), $marker)['type'])->toBe('Chest')
        ->and($chestType['kind'])->toBe('options')
        ->and($chestType['optionLabels'])->toContain('Common', 'Legendary')
        ->and(eventSessionRowLabelled($chest, 'Loot Type')['options'])->toContain('item', 'gold')
        ->and(fn() => $session->applyInspector('test-map', $chest['revision'], $chestType['key'], 'shiny'))
            ->toThrow(SessionRefusal::class, 'choose one of');

    $session->applyInspector('test-map', $chest['revision'], $chestType['key'], 'rare');
    expect(eventSessionRowLabelled($session->readInspector('test-map', $marker), 'Chest Type')['value'])->toBe('rare')
        ->and($session->undo()['label'])->toBe('Chest Type edit')
        ->and($session->undo()['label'])->toBe('Event type change')
        ->and(eventSessionEvent($session->readMap('test-map'), $marker)['type'])->toBe('Dialogue');
});

it('sets a transfer\'s destination map and spawn point together, as one undo step', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $map = $session->readMap('test-map');
    $created = $session->createEvent('test-map', $map['revision'], eventSessionFreeCells($map, 1), 'Transfer Player');
    $marker = $created['marker'];
    $inspector = $session->readInspector('test-map', $marker);
    $destination = eventSessionRowLabelled($inspector, 'Destination Map');

    expect($destination)->toMatchArray(['kind' => 'destination', 'reference' => 'maps'])
        ->and(fn() => $session->applyInspector('test-map', $created['revision'], $destination['key'], 'test-map'))
            ->toThrow(SessionRefusal::class, 'set with its spawn point')
        ->and(fn() => $session->setEventDestination('test-map', $created['revision'], $marker, 'test-map', $map['width'], 0))
            ->toThrow(SessionRefusal::class, 'is outside test-map')
        ->and(fn() => $session->setEventDestination('test-map', $created['revision'], $marker, 'nowhere', 0, 0))
            ->toThrow(SessionRefusal::class, 'There is no map nowhere.');

    $set = $session->setEventDestination('test-map', $created['revision'], $marker, 'test-map', $map['width'] - 1, $map['height'] - 1);
    $after = $session->readInspector('test-map', $marker);

    expect($set['changed'])->toBeTrue()
        ->and(eventSessionRowLabelled($after, 'Destination Map')['value'])->toBe('test-map')
        ->and(eventSessionRowLabelled($after, 'X', 'Spawn Point')['value'])->toBe((string) ($map['width'] - 1))
        ->and(eventSessionRowLabelled($after, 'Y', 'Spawn Point')['value'])->toBe((string) ($map['height'] - 1))
        ->and($session->undo()['label'])->toBe('Destination change');

    $undone = $session->readInspector('test-map', $marker);
    expect(eventSessionRowLabelled($undone, 'Destination Map')['value'])->toBe('')
        ->and(eventSessionRowLabelled($undone, 'X', 'Spawn Point')['value'])->toBe('0');
});

it('adds and removes entries of an event\'s list, the map\'s encounters and its music variants', function () {
    $session = EditorSession::open(metadataProject());
    $map = $session->readMap('test-map');
    $created = $session->createEvent('test-map', $map['revision'], eventSessionFreeCells($map, 1), 'Dialogue');
    $marker = $created['marker'];
    $isDialogue = static fn(array $row): bool => ($row['key']['list'] ?? null) === ['data', 'dialogue'];
    $heading = eventSessionRow($session->readInspector('test-map', $marker), $isDialogue);

    $added = $session->addInspectorListEntry('test-map', $created['revision'], $heading['key']);
    $rows = array_filter($session->readInspector('test-map', $marker)['rows'], $isDialogue);

    expect($heading['list'])->toBe(['index' => 0])
        ->and($added)->toMatchArray(['changed' => true, 'message' => 'Added dialogue 2.'])
        ->and(array_column($rows, 'list'))->toContain(['index' => 1]);

    $second = array_find($rows, static fn(array $row): bool => ($row['list'] ?? null) === ['index' => 1] && $row['kind'] === 'text');
    $removed = $session->removeInspectorListEntry('test-map', $added['revision'], $second['key']);
    expect($removed['message'])->toBe('Removed dialogue 2.')
        ->and(fn() => $session->addInspectorListEntry('test-map', $removed['revision'], eventSessionRowLabelled($session->readInspector('test-map'), 'Name')['key']))
            ->toThrow(SessionRefusal::class, 'Nothing here is a list to add to.');

    // The map's encounter table: the first troop enables encounters.
    $troops = eventSessionRow($session->readInspector('test-map'), static fn(array $row): bool => str_starts_with(trim($row['label']), 'Troops'));
    $enabled = $session->addInspectorListEntry('test-map', $removed['revision'], $troops['key']);
    $troop = eventSessionRowLabelled($session->readInspector('test-map'), 'Troop');
    expect($enabled['message'])->toStartWith('Added ')
        ->and($troop['list'])->toBe(['index' => 0]);
    $disabled = $session->removeInspectorListEntry('test-map', $enabled['revision'], $troop['key']);
    expect($disabled['message'])->toContain('no longer has random encounters');

    // Music variants, and a variant's conditions as the line that holds them.
    $variants = eventSessionRowLabelled($session->readInspector('test-map'), 'Music Variants');
    $variant = $session->addInspectorListEntry('test-map', $disabled['revision'], $variants['key']);
    $when = eventSessionRowLabelled($session->readInspector('test-map'), 'Variant 1 When');

    expect($when)->toMatchArray(['kind' => 'conditions', 'list' => ['index' => 0]])
        ->and(fn() => $session->applyInspector('test-map', $variant['revision'], $when['key'], 'switch:door_open; nonsense'))
            ->toThrow(SessionRefusal::class, 'Condition "nonsense" cannot be read');

    $session->applyInspector('test-map', $variant['revision'], $when['key'], 'switch:door_open');
    expect(eventSessionRowLabelled($session->readInspector('test-map'), 'Variant 1 When')['raw'])->toBe('switch:door_open');
});

it('asks before a kind change clears the map\'s tiles, and applies the answer as one undo step', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root, 'cave');
    $session = EditorSession::open($root);
    $kind = eventSessionRowLabelled($session->readInspector('test-map'), 'Kind');
    $revision = $session->readMap('test-map')['revision'];
    $tileLayers = static fn(): array => array_values(array_filter(array_column($session->readWorld('test-map')['operations'][0]['value']['layers'], 'id'),
        static fn(string $id): bool => str_starts_with($id, 'tiles:')));
    $before = $tileLayers();

    $question = $session->applyInspector('test-map', $revision, $kind['key'], 'cave');

    expect($kind)->toMatchArray(['kind' => 'reference', 'reference' => 'tilesets'])
        ->and($question['status'])->toBe('question')
        ->and(array_column($question['answers'], 'key'))->toBe(['cancel', 'clear'])
        ->and($session->readMap('test-map')['revision'])->toBe($revision)
        ->and($session->applyInspector('test-map', $revision, $kind['key'], 'cave', 'cancel'))
            ->toBe(['status' => 'applied', 'revision' => $revision, 'changed' => false])
        ->and(fn() => $session->applyInspector('test-map', $revision, $kind['key'], 'nope'))->toThrow(SessionRefusal::class, 'no tileset nope')
        ->and($before)->not->toBe([]);

    // The key as an interface may store it: members in another order.
    $changed = $session->applyInspector('test-map', $revision, array_reverse($kind['key'], true), 'cave', 'clear');
    expect($changed['changed'])->toBeTrue()
        ->and(eventSessionRowLabelled($session->readInspector('test-map'), 'Kind')['value'])->toBe('Cave')
        ->and($tileLayers())->toBe([])
        ->and($session->undo()['label'])->toBe('Kind change')
        ->and($tileLayers())->toBe($before);
});

it('gives a map without a kind one without asking, keeping its tiles whatever the answer', function () {
    $root = mapGraphicsProject();
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("'tileset' => 'home',", '', (string) file_get_contents($data)));
    $session = EditorSession::open($root);
    $kind = eventSessionRowLabelled($session->readInspector('test-map'), 'Kind');
    $layers = static fn(): array => array_values(array_filter(array_column($session->readWorld('test-map')['operations'][0]['value']['layers'], 'id'),
        static fn(string $id): bool => str_starts_with($id, 'tiles:')));

    $applied = $session->applyInspector('test-map', $session->readMap('test-map')['revision'], $kind['key'], 'home', 'clear');

    expect($applied)->toMatchArray(['status' => 'applied', 'changed' => true])
        ->and($layers())->not->toBe([]);
});
