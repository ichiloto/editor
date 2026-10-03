<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * NPC authoring through the editor session: each change one undo step made
 * against a current revision, refused with the reason an author reads, and
 * one NPC's rows read and edited by the key the session gave.
 */

/**
 * A throwaway project whose 12x5 fixture map carries the given NPCs.
 *
 * @param array<int, mixed> $npcs The npcs block.
 */
function npcSessionProject(array $npcs): string
{
    $root = makeTemporaryProject('ichiloto-npc-session-');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['npcs'] = $npcs;
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");

    return $root;
}

/** The ids of the map's NPCs as the session reads them. */
function npcSessionIds(EditorSession $session): array
{
    return array_column($session->readMap('test-map')['npcs'], 'id');
}

/** The row with a field id in an NPC read. */
function npcSessionRow(array $read, string $fieldId): array
{
    return array_find($read['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $fieldId)
        ?? throw new RuntimeException("No NPC row {$fieldId}.");
}

it('creates, moves, duplicates and deletes NPCs, each one undo step against a current revision', function () {
    $session = EditorSession::open(npcSessionProject([['id' => 'ann', 'name' => 'Ann', 'x' => 2, 'y' => 1]]));
    $revision = $session->readMap('test-map')['revision'];

    $created = $session->createNpc('test-map', $revision, 4, 2, 'Gate Guard');
    expect($created)->toMatchArray(['changed' => true, 'index' => 1, 'id' => 'gate-guard'])
        ->and(fn() => $session->createNpc('test-map', $revision, 5, 2, 'Late'))->toThrow(SessionRefusal::class, 'changed since revision');

    $moved = $session->moveNpc('test-map', $created['revision'], 1, 8, 3);
    $copy = $session->duplicateNpc('test-map', $moved['revision'], 0);
    expect(array_find($session->readMap('test-map')['npcs'], static fn(array $npc): bool => $npc['id'] === 'gate-guard'))
        ->toMatchArray(['x' => 8, 'y' => 3])
        ->and($copy)->toMatchArray(['index' => 2, 'id' => 'ann-2']);

    $deleted = $session->deleteNpc('test-map', $copy['revision'], 0);
    expect($deleted)->toMatchArray(['changed' => true, 'index' => null, 'id' => 'ann'])
        ->and(npcSessionIds($session))->toBe(['gate-guard', 'ann-2']);

    expect($session->undo())->toMatchArray(['label' => 'NPC delete', 'maps' => ['test-map']])
        ->and(npcSessionIds($session))->toBe(['ann', 'gate-guard', 'ann-2']);
    $session->undo();
    $session->undo();
    expect($session->undo()['label'])->toBe('NPC create')
        ->and(npcSessionIds($session))->toBe(['ann']);
    expect($session->redo()['label'])->toBe('NPC create')
        ->and(npcSessionIds($session))->toBe(['ann', 'gate-guard']);
});

it('refuses NPC changes the rules forbid, naming what stands in the way', function () {
    $session = EditorSession::open(npcSessionProject([
        ['id' => 'ann', 'name' => 'Ann', 'x' => 2, 'y' => 1],
        ['id' => 'bob', 'name' => 'Bob', 'x' => 6, 'y' => 3, 'script' => [
            ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'ann', 'steps' => [['direction' => 'north']]],
        ]],
    ]));
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->createNpc('test-map', $revision, 20, 1, 'Out'))->toThrow(SessionRefusal::class, '20,1 is outside the map.')
        ->and(fn() => $session->createNpc('test-map', $revision, 2, 1, 'On Ann'))->toThrow(SessionRefusal::class, 'Ann already stands at 2,1.')
        ->and(fn() => $session->moveNpc('test-map', $revision, 0, 6, 3))->toThrow(SessionRefusal::class, 'Bob already stands at 6,3.')
        ->and(fn() => $session->duplicateNpc('test-map', $revision, 9))->toThrow(SessionRefusal::class, 'test-map has no NPC 9.')
        ->and(fn() => $session->deleteNpc('test-map', $revision, 0))
            ->toThrow(SessionRefusal::class, "Ann is named by NPC Bob script - resolve those before deleting.\n- NPC Bob script")
        ->and(fn() => $session->assignNpcId('test-map', $revision, 0))->toThrow(SessionRefusal::class, 'ids do not change')
        ->and($session->moveNpc('test-map', $revision, 0, 2, 1))->toMatchArray(['changed' => false, 'revision' => $revision])
        ->and($session->hasUnsavedChanges())->toBeFalse()
        ->and($session->undo()['label'])->toBeNull();
});

it('reads an NPC\'s rows and edits one by its key, the id following a rename', function () {
    $session = EditorSession::open(npcSessionProject([['id' => 'ann', 'name' => 'Ann', 'x' => 2, 'y' => 1]]));
    $read = $session->readNpc('test-map', 0);
    $name = npcSessionRow($read, 'name');

    expect($read['npc'])->toMatchArray(['id' => 'ann', 'name' => 'Ann', 'x' => 2, 'y' => 1])
        ->and($name)->toMatchArray(['kind' => 'text', 'key' => ['target' => 'npc', 'field' => 'name', 'frame' => []]])
        ->and(npcSessionRow($read, 'x')['kind'])->toBe('integer')
        ->and(npcSessionRow($read, 'movement'))->toMatchArray(['kind' => 'options', 'options' => ['fixed', 'wander']])
        ->and(npcSessionRow($read, 'id')['kind'])->toBe('info')
        ->and(array_column($read['rows'], 'label'))->toContain('Identity')
        ->and(npcSessionRow($read, 'commandListScript')['frame'])->toBe(['script']);

    $applied = $session->applyNpc('test-map', $read['revision'], 0, $name['key'], 'Annabel');
    expect($applied)->toMatchArray(['changed' => true, 'id' => 'annabel', 'followedId' => 'annabel', 'idReferences' => []])
        ->and(fn() => $session->applyNpc('test-map', $applied['revision'], 0, npcSessionRow($read, 'id')['key'], 'x'))
            ->toThrow(SessionRefusal::class, 'Id cannot be edited here.')
        ->and(fn() => $session->applyNpc('test-map', $applied['revision'], 0, ['field' => 'nope'], 'x'))
            ->toThrow(SessionRefusal::class, 'no longer on the NPC')
        ->and(fn() => $session->applyNpc('test-map', $applied['revision'], 0, npcSessionRow($read, 'x')['key'], '40'))
            ->toThrow(SessionRefusal::class, '40,1 is outside the map.');

    expect($session->undo()['label'])->toBe('NPC Name edit')
        ->and($session->readNpc('test-map', 0)['npc'])->toMatchArray(['id' => 'ann', 'name' => 'Ann']);
});

it('adds and removes script commands inside a frame, and refuses a frame that is gone', function () {
    $session = EditorSession::open(npcSessionProject([['id' => 'ann', 'name' => 'Ann', 'x' => 2, 'y' => 1]]));
    $revision = $session->readMap('test-map')['revision'];

    $added = $session->addNpcItem('test-map', $revision, 0, ['frame' => ['script']]);
    $script = $session->readNpc('test-map', 0, ['script']);
    $type = npcSessionRow($script, 'command0Type');

    expect($added['changed'])->toBeTrue()
        ->and($script['frameLabel'])->toBe('Script')
        ->and($type['key']['frame'])->toBe(['script']);

    $removed = $session->removeNpcItem('test-map', $added['revision'], 0, $type['key']);
    expect($removed['changed'])->toBeTrue()
        ->and($session->readNpc('test-map', 0, ['script'])['rows'][0]['kind'])->toBe('info')
        ->and(fn() => $session->readNpc('test-map', 0, [4, 'then']))->toThrow(SessionRefusal::class, 'is no longer there')
        ->and(fn() => $session->readNpc('test-map', 3))->toThrow(SessionRefusal::class, 'test-map has no NPC 3.')
        ->and(fn() => $session->addNpcItem('test-map', $removed['revision'], 0, ['frame' => 'script']))
            ->toThrow(SessionRefusal::class, 'A frame must be a list');

    expect($session->undo()['label'])->toBe('NPC remove')
        ->and($session->undo()['label'])->toBe('NPC add')
        ->and($session->readMap('test-map')['dirty'])->toBeFalse();
});

it('serves NPC authoring over the line protocol', function () {
    $root = npcSessionProject([['name' => 'Old Man', 'x' => 2, 'y' => 1]]);
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]));
    $request(1, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $revision = $request(2, 'map.read', ['map' => 'test-map'])['result']['revision'];

    $assigned = $request(3, 'npc.assignId', ['map' => 'test-map', 'revision' => $revision, 'index' => 0])['result'];
    $created = $request(4, 'npc.create', ['map' => 'test-map', 'revision' => $assigned['revision'], 'x' => 5, 'y' => 2, 'name' => 'Guard'])['result'];
    $moved = $request(5, 'npc.move', ['map' => 'test-map', 'revision' => $created['revision'], 'index' => 1, 'x' => 6, 'y' => 2])['result'];
    $read = $request(6, 'npc.read', ['map' => 'test-map', 'index' => 1])['result'];
    $applied = $request(7, 'npc.apply', ['map' => 'test-map', 'revision' => $moved['revision'], 'index' => 1,
        'key' => npcSessionRow($read, 'sprite')['key'], 'value' => 'G'])['result'];
    $added = $request(8, 'npc.add', ['map' => 'test-map', 'revision' => $applied['revision'], 'index' => 1, 'key' => ['frame' => ['script']]])['result'];
    $removed = $request(9, 'npc.remove', ['map' => 'test-map', 'revision' => $added['revision'], 'index' => 1,
        'key' => ['field' => 'command0Type', 'frame' => ['script']]])['result'];
    $copy = $request(10, 'npc.duplicate', ['map' => 'test-map', 'revision' => $removed['revision'], 'index' => 1])['result'];
    $deleted = $request(11, 'npc.delete', ['map' => 'test-map', 'revision' => $copy['revision'], 'index' => 2])['result'];

    expect($assigned['id'])->toBe('old-man')
        ->and($created['id'])->toBe('guard')
        ->and($moved['changed'])->toBeTrue()
        ->and($applied['changed'])->toBeTrue()
        ->and([$added['changed'], $removed['changed']])->toBe([true, true])
        ->and($copy['id'])->toBe('guard-2')
        ->and($deleted['index'])->toBeNull()
        ->and($request(12, 'npc.delete', ['map' => 'test-map', 'revision' => $copy['revision'], 'index' => 0])['error']['kind'])->toBe('refusal')
        ->and($request(13, 'npc.read', ['map' => 'test-map', 'index' => 0, 'frame' => 'script'])['error'])
            ->toBe(['kind' => 'request', 'message' => '"frame" must be a list of indexes and keys.'])
        ->and($request(14, 'npc.apply', ['map' => 'test-map', 'revision' => $deleted['revision'], 'index' => 0, 'value' => 'x'])['error']['kind'])
            ->toBe('request');
});
