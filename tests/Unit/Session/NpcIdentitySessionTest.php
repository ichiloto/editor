<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * An NPC's stable id chosen by its author, which may differ from its name (one character met as several NPCs):
 * given at creation or assignment, changed through the Id row while nothing names it, unique on its map, and kept
 * when the NPC is renamed.
 */

/** The NPC session fixture with a comment in its data file, so a save shows it kept the authored source. */
function npcIdentityProject(array $npcs, array $events = []): string
{
    $root = npcSessionProject($npcs);
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['events'] = array_replace($data['events'] ?? [], $events);
    file_put_contents($path, "<?php\n\n// The cast stays authored.\nreturn " . var_export($data, true) . ";\n");

    return $root;
}

it('creates and identifies NPCs under chosen ids through the session protocol, saving them as authored', function () {
    $root = npcIdentityProject([['name' => 'Liora', 'x' => 2, 'y' => 1]]);
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $revision = $request('map.read', ['map' => 'test-map'])['result']['revision'];

    $created = $request('npc.create', ['map' => 'test-map', 'revision' => $revision, 'x' => 5, 'y' => 2, 'name' => 'Djin', 'id' => 'djin-contract-trial'])['result'];
    $assigned = $request('npc.assignId', ['map' => 'test-map', 'revision' => $created['revision'], 'index' => 0, 'id' => 'liora-home'])['result'];
    $derived = $request('npc.create', ['map' => 'test-map', 'revision' => $assigned['revision'], 'x' => 7, 'y' => 2, 'name' => 'Djin'])['result'];
    expect($created)->toMatchArray(['changed' => true, 'index' => 1, 'id' => 'djin-contract-trial'])
        ->and($assigned)->toMatchArray(['changed' => true, 'index' => 0, 'id' => 'liora-home'])
        // Without an id the name still gives one.
        ->and($derived['id'])->toBe('djin')
        ->and($request('npc.create', ['map' => 'test-map', 'revision' => $derived['revision'], 'x' => 8, 'y' => 2, 'name' => 'X', 'id' => 4])['error']['kind'])
            ->toBe('request');

    expect($request('map.save', ['map' => 'test-map'])['result']['saved'])->toBe('test-map');
    $source = (string) file_get_contents($root . '/assets/Maps/test-map/test-map.data.php');
    expect($source)->toContain('// The cast stays authored.', "'djin-contract-trial'", "'liora-home'");
    $npcs = ProjectWorkspace::fromProject($root)->getMapByIndex(0)?->getNpcs();
    expect(array_map(static fn($npc): array => [$npc->getId(), $npc->getName()], $npcs?->all() ?? []))
        ->toBe([['liora-home', 'Liora'], ['djin-contract-trial', 'Djin'], ['djin', 'Djin']]);
});

it('refuses a chosen id that is no id or that another NPC on the map has, changing nothing', function () {
    $session = EditorSession::open(npcIdentityProject([
        ['id' => 'ann', 'name' => 'Ann', 'x' => 2, 'y' => 1],
        ['name' => 'Old Man', 'x' => 6, 'y' => 3],
    ]));
    $revision = $session->readMap('test-map')['revision'];
    $row = npcSessionRow($session->readNpc('test-map', 0), 'id');

    expect(fn() => $session->createNpc('test-map', $revision, 4, 2, 'Guard', 'Gate Guard'))->toThrow(SessionRefusal::class, '"Gate Guard" is not an NPC id')
        ->and(fn() => $session->createNpc('test-map', $revision, 4, 2, 'Guard', 'ann'))->toThrow(SessionRefusal::class, 'Ann already has the id "ann" on this map.')
        ->and(fn() => $session->createNpc('test-map', $revision, 4, 2, 'Guard', '  '))->toThrow(SessionRefusal::class, 'is not an NPC id')
        ->and(fn() => $session->assignNpcId('test-map', $revision, 1, 'ann'))->toThrow(SessionRefusal::class, 'Ann already has the id "ann"')
        ->and(fn() => $session->assignNpcId('test-map', $revision, 1, 'old--man'))->toThrow(SessionRefusal::class, 'is not an NPC id')
        ->and(fn() => $session->assignNpcId('test-map', $revision, 0, 'anna'))->toThrow(SessionRefusal::class, 'change it through its Id row')
        ->and(fn() => $session->applyNpc('test-map', $revision, 0, $row['key'], 'café'))->toThrow(SessionRefusal::class, 'is not an NPC id')
        ->and($session->applyNpc('test-map', $revision, 0, $row['key'], 'ann'))->toMatchArray(['changed' => false, 'revision' => $revision])
        ->and(npcSessionIds($session))->toBe(['ann', null])
        ->and($session->hasUnsavedChanges())->toBeFalse();
});

it('changes an id nothing names through the Id row as one undo step', function () {
    $session = EditorSession::open(npcIdentityProject([['id' => 'nivira', 'name' => 'Nivira', 'x' => 2, 'y' => 1]]));
    $read = $session->readNpc('test-map', 0);

    $applied = $session->applyNpc('test-map', $read['revision'], 0, npcSessionRow($read, 'id')['key'], ' nivira-morning-visit ');
    expect($applied)->toMatchArray(['changed' => true, 'id' => 'nivira-morning-visit', 'followedId' => null])
        ->and(npcSessionRow($session->readNpc('test-map', 0), 'id')['raw'])->toBe('nivira-morning-visit')
        ->and($session->undo()['label'])->toBe('NPC id nivira-morning-visit')
        ->and(npcSessionIds($session))->toBe(['nivira'])
        ->and($session->redo()['maps'])->toBe(['test-map'])
        ->and(npcSessionIds($session))->toBe(['nivira-morning-visit']);
});

it('refuses to change an id a route, a script or a dialogue event names, naming each', function () {
    $session = EditorSession::open(npcIdentityProject(
        [
            ['id' => 'ann', 'name' => 'Ann', 'x' => 2, 'y' => 1],
            ['id' => 'bob', 'name' => 'Bob', 'x' => 6, 'y' => 3, 'script' => [
                ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'ann', 'steps' => [['direction' => 'north']]],
            ]],
        ],
        ['T' => ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\DialogueEventTrigger', 'data' => ['npcId' => 'ann']]],
    ));
    $read = $session->readNpc('test-map', 0);

    expect(fn() => $session->applyNpc('test-map', $read['revision'], 0, npcSessionRow($read, 'id')['key'], 'ann-at-home'))
        ->toThrow(SessionRefusal::class, "Ann keeps the id \"ann\": test-map event T, NPC Bob script name it. Change those first.\n- test-map event T\n- NPC Bob script")
        ->and(npcSessionIds($session))->toBe(['ann', 'bob'])
        ->and($session->hasUnsavedChanges())->toBeFalse();
});

it('keeps a chosen id when the NPC is renamed, and still carries a derived one', function () {
    $session = EditorSession::open(npcIdentityProject([
        ['id' => 'djin-contract-trial', 'name' => 'Djin', 'x' => 2, 'y' => 1],
        ['id' => 'mara', 'name' => 'Mara', 'x' => 6, 'y' => 3],
    ]));
    $read = $session->readNpc('test-map', 0);
    $kept = $session->applyNpc('test-map', $read['revision'], 0, npcSessionRow($read, 'name')['key'], 'Djinn');
    $other = $session->readNpc('test-map', 1);
    $followed = $session->applyNpc('test-map', $kept['revision'], 1, npcSessionRow($other, 'name')['key'], 'Old Mara');

    expect($kept)->toMatchArray(['changed' => true, 'id' => 'djin-contract-trial', 'followedId' => null, 'idReferences' => []])
        ->and($followed)->toMatchArray(['id' => 'old-mara', 'followedId' => 'old-mara'])
        ->and(npcSessionIds($session))->toBe(['djin-contract-trial', 'old-mara']);
});

it('keeps and refuses to change an id only an event script reached through common_event chains names', function (array $bob, array $events, array $scripts) {
    $root = npcIdentityProject([
        ['id' => 'mara', 'name' => 'Mara', 'x' => 2, 'y' => 1],
        ['id' => 'bob', 'name' => 'Bob', 'x' => 6, 'y' => 3, ...$bob],
    ], $events);
    writeNpcEventScripts($root, $scripts);
    $session = EditorSession::open($root);
    $read = $session->readNpc('test-map', 0);

    expect(fn() => $session->applyNpc('test-map', $read['revision'], 0, npcSessionRow($read, 'id')['key'], 'mara-at-home'))
        ->toThrow(SessionRefusal::class, 'Mara keeps the id "mara": event script b (via event script a) names it.')
        ->and(npcSessionIds($session))->toBe(['mara', 'bob']);

    $renamed = $session->applyNpc('test-map', $read['revision'], 0, npcSessionRow($read, 'name')['key'], 'Old Mara');
    expect($renamed)->toMatchArray(['changed' => true, 'id' => 'mara', 'followedId' => null, 'idReferences' => ['event script b (via event script a)']])
        ->and(npcSessionIds($session))->toBe(['mara', 'bob']);
})->with(npcEventScriptChains());

it('lets a derived id follow its name when no script this map reaches names it', function () {
    $root = npcIdentityProject([['id' => 'mara', 'name' => 'Mara', 'x' => 2, 'y' => 1]]);
    writeNpcEventScripts($root, npcEventScriptChains()['a cycle between a and b'][2]);
    $session = EditorSession::open($root);
    $read = $session->readNpc('test-map', 0);

    expect($session->applyNpc('test-map', $read['revision'], 0, npcSessionRow($read, 'name')['key'], 'Old Mara'))
        ->toMatchArray(['id' => 'old-mara', 'followedId' => 'old-mara', 'idReferences' => []]);
});
