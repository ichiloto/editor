<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Schema database records through the editor session: rows read by frame
 * with the items add and remove act on, each change one undo step refused
 * with the reason an author reads, history naming the categories a step
 * changed, and saving through the category's own transaction. Synthetic
 * fixtures only.
 */

/** The row with a field id in a record read. */
function databaseSessionRow(array $read, string $fieldId): array
{
    return array_find($read['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $fieldId)
        ?? throw new RuntimeException("No record row {$fieldId}.");
}

it('lists a category with what it can do, and reads a frame\'s rows with the items they belong to', function () {
    $root = makeTemporaryProject();
    $unwritable = makeUnwritableCategory($root);
    $session = EditorSession::open($root);
    $states = $session->listDatabaseRecords('states');
    $types = $session->listDatabaseRecords($unwritable);
    $root = $session->readDatabaseRecord('common_events', 0);
    $then = $session->readDatabaseRecord('common_events', 0, [3, 'then']);

    expect($states)->toMatchArray(['editable' => true, 'dirty' => false, 'canCreate' => true, 'canDuplicate' => true, 'canDelete' => true, 'canReorder' => false])
        ->and($types)->toMatchArray(['editable' => false, 'canCreate' => false, 'canDelete' => false])
        ->and($root)->toMatchArray(['frame' => [], 'frameLabel' => null, 'editable' => true, 'readOnly' => null])
        ->and(databaseSessionRow($root, '__scriptId'))->not->toHaveKey('item')
        ->and(databaseSessionRow($root, '__scriptId')['kind'])->toBe('info')
        ->and(databaseSessionRow($root, 'command3Then'))->toMatchArray(['frame' => [3, 'then'], 'item' => true, 'itemNoun' => 'command'])
        ->and($then['frameLabel'])->toBe('Commands › Branch 4 › Then')
        ->and(databaseSessionRow($then, 'command0Text'))->toMatchArray([
            'kind' => 'text',
            'key' => ['target' => 'record', 'field' => 'command0Text', 'frame' => [3, 'then']],
            'item' => true,
        ])
        ->and(fn() => $session->readDatabaseRecord('common_events', 0, [9, 'then']))->toThrow(SessionRefusal::class, 'is no longer there')
        ->and(fn() => $session->readDatabaseRecord('common_events', 0, ['then' => 3]))->toThrow(SessionRefusal::class, 'A frame must be a list')
        ->and(fn() => $session->readDatabaseRecord('states', 9))->toThrow(SessionRefusal::class, 'states has no record 9.')
        ->and(fn() => $session->readDatabaseRecord('skills', 0))->toThrow(SessionRefusal::class, 'edited in the terminal editor for now');
});

it('applies a row as one undo step, history naming the category, and refuses what the field cannot take', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $name = databaseSessionRow($session->readDatabaseRecord('states', 0), 'name');

    $applied = $session->applyDatabaseRecord('states', 0, $name['key'], 'Venom');
    expect($applied)->toBe(['changed' => true, 'records' => ['Venom', 'Stun']])
        ->and($session->listDatabaseRecords('states')['dirty'])->toBeTrue()
        ->and($session->applyDatabaseRecord('states', 0, $name['key'], 'Venom')['changed'])->toBeFalse();

    $undone = $session->undo();
    expect($undone)->toMatchArray(['label' => 'Name edit', 'maps' => [], 'databases' => ['states']])
        ->and($session->listDatabaseRecords('states'))->toMatchArray(['dirty' => false, 'records' => ['Poison', 'Stun']])
        ->and($session->redo()['databases'])->toBe(['states'])
        ->and($session->listDatabaseRecords('states')['records'][0])->toBe('Venom');

    $conditions = databaseSessionRow($session->readDatabaseRecord('common_events', 0), 'command3Conditions');
    expect(fn() => $session->applyDatabaseRecord('common_events', 0, $conditions['key'], 'nonsense'))
        ->toThrow(SessionRefusal::class, 'Condition "nonsense" cannot be read')
        ->and(fn() => $session->applyDatabaseRecord('common_events', 0, ['field' => 'command3Then', 'frame' => []], '2'))
        ->toThrow(SessionRefusal::class, 'Command 4 Then Commands cannot be edited here.')
        ->and(fn() => $session->applyDatabaseRecord('states', 0, ['field' => 'nope', 'frame' => []], 'x'))
        ->toThrow(SessionRefusal::class, 'no longer in the record')
        ->and($session->listDatabaseRecords('common_events')['dirty'])->toBeFalse();
});

it('adds and removes items by row, beneath an item that holds a list, and at a frame\'s end', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $commands = static fn(array $frame = []): array => array_column(
        array_filter($session->readDatabaseRecord('common_events', 0, $frame)['rows'], static fn(array $row): bool => str_ends_with($row['key']['field'] ?? '', 'Type')),
        'value',
    );

    expect($session->addDatabaseItem('common_events', 0, ['frame' => [3, 'then']])['changed'])->toBeTrue()
        ->and($commands([3, 'then']))->toBe(['text', 'text']);

    $session->applyDatabaseRecord('common_events', 0, ['field' => 'command0Type', 'frame' => []], 'choice');
    $prompt = databaseSessionRow($session->readDatabaseRecord('common_events', 0), 'command0Prompt');
    expect($prompt['childNoun'])->toBe('option')
        ->and($session->addDatabaseItem('common_events', 0, $prompt['key'], child: true)['changed'])->toBeTrue();
    $option = databaseSessionRow($session->readDatabaseRecord('common_events', 0), 'command0Option0Text');
    expect($option)->toMatchArray(['item' => true, 'itemNoun' => 'option'])->not->toHaveKey('childNoun');

    $session->addDatabaseItem('common_events', 0, $prompt['key']);
    expect($commands())->toBe(['choice', 'text', 'record_event', 'give_gold', 'branch'])
        ->and($session->removeDatabaseItem('common_events', 0, $option['key'])['changed'])->toBeTrue()
        ->and(fn() => $session->addDatabaseItem('common_events', 0, ['field' => '__scriptId', 'frame' => []]))
        ->toThrow(SessionRefusal::class, 'belongs to no item')
        ->and($session->removeDatabaseItem('common_events', 0, ['field' => '__scriptId', 'frame' => []])['changed'])->toBeFalse();

    expect(array_column([$session->undo(), $session->undo(), $session->undo(), $session->undo(), $session->undo()], 'label'))
        ->toBe(['Option remove', 'Command add', 'Option add', 'Command 1 Type edit', 'Command add'])
        ->and($session->listDatabaseRecords('common_events')['dirty'])->toBeFalse();
});

it('creates, duplicates, moves and deletes records, each one undo step', function () {
    $root = makeTemporaryProject();
    $unwritable = makeUnwritableCategory($root);
    $session = EditorSession::open($root);

    expect($session->createDatabaseRecord('states'))->toBe(['index' => 2, 'records' => ['Poison', 'Stun', 'New State']])
        ->and($session->duplicateDatabaseRecord('states', 0))->toBe(['index' => 1, 'records' => ['Poison', 'Poison', 'Stun', 'New State']])
        ->and($session->deleteDatabaseRecord('states', 1))->toBe(['index' => 0, 'records' => ['Poison', 'Stun', 'New State']])
        ->and(fn() => $session->moveDatabaseRecord('states', 0, 'down'))->toThrow(SessionRefusal::class, 'would not survive reopening')
        ->and(fn() => $session->createDatabaseRecord('terms'))->toThrow(SessionRefusal::class, 'Term entries cannot be created from the editor.')
        ->and(fn() => $session->deleteDatabaseRecord($unwritable, 0))->toThrow(SessionRefusal::class, 'Read-only: ');

    expect(array_column([$session->undo(), $session->undo(), $session->undo()], 'label'))
        ->toBe(['Delete state Poison', 'State duplicate', 'State create'])
        ->and($session->listDatabaseRecords('states'))->toMatchArray(['dirty' => false, 'records' => ['Poison', 'Stun']]);

    $first = $session->createDatabaseRecord('battle_entry_rules');
    $second = $session->createDatabaseRecord('battle_entry_rules');
    $labels = $second['records'];
    expect($session->moveDatabaseRecord('battle_entry_rules', $first['index'], 'down'))
        ->toBe(['index' => $second['index'], 'changed' => true, 'records' => array_reverse($labels)])
        ->and($session->moveDatabaseRecord('battle_entry_rules', $second['index'], 'down')['changed'])->toBeFalse()
        ->and($session->undo())->toMatchArray(['label' => 'Battle entry rule move', 'databases' => ['battle_entry_rules']])
        ->and($session->listDatabaseRecords('battle_entry_rules')['records'])->toBe($labels);
});

it('saves a category through its own file, and refuses one that cannot be written', function () {
    $root = makeTemporaryProject();
    $unwritable = makeUnwritableCategory($root);
    $session = EditorSession::open($root);

    expect($session->saveDatabase('states'))->toBe(['saved' => false, 'warnings' => [], 'backupFailures' => []]);

    $session->applyDatabaseRecord('states', 0, ['field' => 'name', 'frame' => []], 'Venom');
    $saved = $session->saveDatabase('states');

    expect($saved)->toMatchArray(['saved' => true, 'warnings' => [], 'backupFailures' => []])
        ->and(loadRecordDatabase($root, 'states')->getEntryLabels())->toBe(['Venom', 'Stun'])
        ->and($session->listDatabaseRecords('states')['dirty'])->toBeFalse()
        ->and($session->hasUnsavedChanges())->toBeFalse()
        ->and(fn() => $session->saveDatabase($unwritable))->toThrow(SessionRefusal::class, 'Read-only: ');
});

it('serves database authoring over the line protocol', function () {
    $root = makeTemporaryProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]));
    $request(1, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);

    $read = $request(2, 'database.record', ['category' => 'troops', 'index' => 0, 'frame' => []])['result'];
    $applied = $request(3, 'database.apply', ['category' => 'troops', 'index' => 0, 'key' => databaseSessionRow($read, 'name')['key'], 'value' => 'Bats'])['result'];
    $added = $request(4, 'database.add', ['category' => 'troops', 'index' => 0, 'key' => databaseSessionRow($read, 'member0Enemy')['key']])['result'];
    $removed = $request(5, 'database.remove', ['category' => 'troops', 'index' => 0, 'key' => databaseSessionRow($read, 'member0Enemy')['key']])['result'];
    $created = $request(6, 'database.create', ['category' => 'troops'])['result'];
    $copy = $request(7, 'database.duplicate', ['category' => 'troops', 'index' => 0])['result'];
    $deleted = $request(8, 'database.delete', ['category' => 'troops', 'index' => 1])['result'];
    $undone = $request(9, 'history.undo')['result'];
    $saved = $request(10, 'database.save', ['category' => 'troops'])['result'];

    expect($applied)->toBe(['changed' => true, 'records' => ['Bats', 'Lone Rat']])
        ->and([$added['changed'], $removed['changed']])->toBe([true, true])
        ->and($created['index'])->toBe(2)
        ->and($copy['index'])->toBe(1)
        ->and($deleted['index'])->toBe(0)
        ->and($undone['databases'])->toBe(['troops'])
        ->and($saved['saved'])->toBeTrue()
        ->and($request(11, 'database.records', ['category' => 'troops'])['result']['dirty'])->toBeFalse()
        ->and($request(12, 'database.move', ['category' => 'troops', 'index' => 0, 'direction' => 'down'])['error']['kind'])->toBe('refusal')
        ->and($request(13, 'database.move', ['category' => 'troops', 'index' => 0, 'direction' => 'sideways'])['error'])
            ->toBe(['kind' => 'request', 'message' => '"direction" must be "up" or "down".'])
        ->and($request(14, 'database.apply', ['category' => 'troops', 'index' => 0, 'value' => 'x'])['error']['kind'])->toBe('request')
        ->and($request(15, 'database.add', ['category' => 'troops', 'index' => 0, 'key' => [], 'child' => 'yes'])['error'])
            ->toBe(['kind' => 'request', 'message' => '"child" must be a boolean.'])
        ->and($request(16, 'database.record', ['category' => 'troops', 'index' => 0, 'frame' => 'x'])['error']['kind'])->toBe('request');
});

it('marks the items NPC rows belong to, from the same rules the database rows use', function () {
    $root = makeTemporaryProject('ichiloto-npc-items-');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['npcs'] = [['id' => 'ann', 'name' => 'Ann', 'x' => 2, 'y' => 1, 'script' => [
        ['type' => 'choice', 'prompt' => 'Well?', 'options' => [['text' => 'Yes', 'then' => []], ['text' => 'No', 'then' => []]]],
    ]]];
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    $session = EditorSession::open($root);
    $script = $session->readNpc('test-map', 0, ['script']);
    $option = databaseSessionRow($script, 'command0Option1Text');

    expect(databaseSessionRow($script, 'command0Type'))->toMatchArray(['item' => true, 'itemNoun' => 'command', 'childNoun' => 'option'])
        ->and($option)->toMatchArray(['item' => true, 'itemNoun' => 'option'])
        ->and(databaseSessionRow($session->readNpc('test-map', 0), 'name'))->not->toHaveKey('item');

    // An option row removes that option, not the whole choice.
    $removed = $session->removeNpcItem('test-map', $script['revision'], 0, $option['key']);
    $after = $session->readNpc('test-map', 0, ['script']);
    expect($removed['changed'])->toBeTrue()
        ->and(array_column(array_filter($after['rows'], static fn(array $row): bool => str_ends_with($row['key']['field'] ?? '', 'Text')), 'value'))
        ->toBe(['Yes']);
});

it('names every unsaved map and database, and saves them all in one request', function () {
    $root = makeTemporaryProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]))['result'];
    $request(1, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);

    expect($request(2, 'project.dirty'))->toBe(['dirty' => false, 'unsaved' => []]);

    $map = $request(3, 'map.read', ['map' => 'test-map']);
    $request(4, 'map.paint', ['map' => 'test-map', 'revision' => $map['revision'], 'layer' => $map['baseLayer'], 'cells' => [[1, 1]], 'symbol' => '#']);
    $request(5, 'database.apply', ['category' => 'states', 'index' => 0, 'key' => ['field' => 'name', 'frame' => []], 'value' => 'Venom']);
    $states = DatabaseCatalog::at(DatabaseCatalog::indexOf('states'))->label;

    expect($request(6, 'project.dirty'))->toBe(['dirty' => true, 'unsaved' => ['test-map', $states . ' database']]);

    $saved = $request(7, 'project.saveAll');

    expect($saved)->toMatchArray(['summary' => 'Saved 1 map, 1 database and 0 cutscenes.', 'failures' => [], 'skippedRenames' => [], 'unsaved' => []])
        ->and($request(8, 'project.dirty'))->toBe(['dirty' => false, 'unsaved' => []])
        ->and(ProjectWorkspace::fromProject($root)->maps[0]->getLayerSymbol($map['baseLayer'], 1, 1))->toBe('#')
        ->and(loadRecordDatabase($root, 'states')->getEntryLabels())->toBe(['Venom', 'Stun']);
});

it('serves animations as a schema category, its roles a list picked a member at a time', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/animations.php', "<?php return [['id' => 1, 'name' => 'Hit Spark', 'roles' => ['attack'], 'targetEffect' => 'battle-physical-impact']];");
    $session = EditorSession::open($root);
    $roles = databaseSessionRow($session->readDatabaseRecord('animations', 0), 'roles');

    expect($session->listDatabaseRecords('animations')['records'])->toBe(['Hit Spark'])
        ->and($roles)->toMatchArray(['kind' => 'reference', 'reference' => 'animation_roles', 'multi' => true, 'value' => 'attack']);
});
