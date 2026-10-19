<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * NPC phases: two NPCs anchored on one tile, allowed only while their
 * visibility conditions exclude each other, so the game never shows both.
 * Any move or visibility edit that would leave two bodies visible on one
 * tile is refused with nothing changed.
 */

/** Sets one NPC row by field id, returning the session's answer. */
function npcPhaseApply(EditorSession $session, int $index, string $fieldId, string $value): array
{
    $read = $session->readNpc('test-map', $index);

    return $session->applyNpc('test-map', $read['revision'], $index, npcSessionRow($read, $fieldId)['key'], $value);
}

/** The NPC entries as the map's data file holds them. */
function npcPhaseSource(string $root): array
{
    return (require $root . '/assets/Maps/test-map/test-map.data.php')['npcs'];
}

/** The validator's NPC tile issues for the map. */
function npcPhaseTileIssues(EditorSession $session): array
{
    return array_values(array_filter(
        array_column($session->validateProject(['test-map'])['issues'], 'message'),
        static fn(?string $message): bool => str_contains((string) $message, 'same tile') || str_contains((string) $message, 'shares tile')
            || str_contains((string) $message, 'covers the tile'),
    ));
}

it('lets a duplicate share its original\'s tile once their conditions exclude each other, with undo and a faithful save', function () {
    $root = npcSessionProject([
        ['id' => 'ivara', 'name' => 'Ivara', 'x' => 2, 'y' => 1, 'conditions' => [['type' => 'event', 'name' => 'evidence', 'negate' => true]]],
    ]);
    $session = EditorSession::open($root);

    $copy = $session->duplicateNpc('test-map', $session->readMap('test-map')['revision'], 0);
    npcPhaseApply($session, $copy['index'], 'conditions', 'event:evidence');
    npcPhaseApply($session, $copy['index'], 'x', '2');

    expect(array_map(static fn(array $npc): array => [$npc['id'], $npc['x'], $npc['y']], $session->readMap('test-map')['npcs']))
        ->toBe([['ivara', 2, 1], ['ivara-2', 2, 1]])
        ->and(npcPhaseTileIssues($session))->toBe([]);

    expect($session->undo()['label'])->not->toBeNull()
        ->and($session->readMap('test-map')['npcs'][1])->toMatchArray(['x' => 3, 'y' => 1]);
    $session->redo();
    $session->saveMap('test-map');

    $reopened = EditorSession::open($root);
    expect(npcPhaseSource($root))->toMatchArray([
        ['id' => 'ivara', 'name' => 'Ivara', 'x' => 2, 'y' => 1, 'conditions' => [['type' => 'event', 'name' => 'evidence', 'negate' => true]]],
        ['id' => 'ivara-2', 'name' => 'Ivara', 'x' => 2, 'y' => 1, 'conditions' => [['type' => 'event', 'name' => 'evidence']]],
    ])
        ->and(array_column($reopened->readMap('test-map')['npcs'], 'x'))->toBe([2, 2])
        ->and(npcPhaseTileIssues($reopened))->toBe([]);
});

it('refuses a second body on a tile while both NPCs can be visible', function (array $original, string $conditions) {
    $session = EditorSession::open(npcSessionProject([
        ['id' => 'ivara', 'name' => 'Ivara', 'x' => 2, 'y' => 1, 'conditions' => $original],
        ['id' => 'post', 'name' => 'Post', 'x' => 5, 'y' => 1],
    ]));
    npcPhaseApply($session, 1, 'conditions', $conditions);
    $before = $session->readMap('test-map');

    expect(fn() => npcPhaseApply($session, 1, 'x', '2'))
        ->toThrow(SessionRefusal::class, 'Ivara already stands at 2,1 while both can be visible.')
        ->and(fn() => $session->moveNpc('test-map', $session->readMap('test-map')['revision'], 1, 2, 1))
        ->toThrow(SessionRefusal::class, 'Ivara already stands at 2,1 while both can be visible.')
        ->and(fn() => $session->createNpc('test-map', $session->readMap('test-map')['revision'], 2, 1, 'Stranger'))
        ->toThrow(SessionRefusal::class, 'Ivara already stands at 2,1 while both can be visible.')
        ->and($session->readMap('test-map')['npcs'])->toBe($before['npcs']);
})->with([
    'neither guarded' => [[], ''],
    'one guarded' => [[['type' => 'event', 'name' => 'evidence', 'negate' => true]], ''],
    'the same flag' => [[['type' => 'event', 'name' => 'evidence']], 'event:evidence'],
    'different flags' => [[['type' => 'event', 'name' => 'evidence', 'negate' => true]], 'event:dispatched'],
    'quest statuses' => [[['type' => 'quest', 'name' => 'q', 'status' => 'active']], 'quest:q:completed'],
    'variable ranges' => [[['type' => 'variable', 'name' => 'v', 'op' => '<', 'value' => 3]], 'variable:v:>=:3'],
]);

it('lets phases share a tile on any explicit complement the Engine evaluates', function (array $original, string $conditions) {
    $session = EditorSession::open(npcSessionProject([
        ['id' => 'ivara', 'name' => 'Ivara', 'x' => 2, 'y' => 1, 'conditions' => $original],
        ['id' => 'post', 'name' => 'Post', 'x' => 5, 'y' => 1],
    ]));
    npcPhaseApply($session, 1, 'conditions', $conditions);

    expect(npcPhaseApply($session, 1, 'x', '2')['changed'])->toBeTrue()
        ->and(npcPhaseTileIssues($session))->toBe([]);
})->with([
    'event' => [[['type' => 'event', 'name' => 'evidence']], '!event:evidence'],
    'switch value' => [[['type' => 'switch', 'name' => 'door']], 'switch:door:false'],
    'negated switch' => [[['type' => 'switch', 'name' => 'door', 'value' => false]], '!switch:door:false'],
    'one of several' => [[['type' => 'item', 'name' => 'key'], ['type' => 'event', 'name' => 'evidence']], 'quest:q:active; !event:evidence'],
    'map event' => [[['type' => 'map_event', 'name' => 'town', 'marker' => 'A']], '!map_event:town:A'],
    'variable equality' => [[['type' => 'variable', 'name' => 'v', 'op' => '==', 'value' => 3]], 'variable:v:!=:3'],
]);

it('refuses a visibility edit that would show two phases on one tile together, changing nothing', function (int $edited, string $conditions) {
    $root = npcSessionProject([
        ['id' => 'ivara', 'name' => 'Ivara', 'x' => 2, 'y' => 1, 'conditions' => [['type' => 'event', 'name' => 'evidence', 'negate' => true]]],
        ['id' => 'ivara-returned', 'name' => 'Ivara Returned', 'x' => 2, 'y' => 1, 'conditions' => [['type' => 'event', 'name' => 'evidence']]],
    ]);
    $session = EditorSession::open($root);
    $before = $session->readMap('test-map');
    $other = $edited === 0 ? 'Ivara Returned' : 'Ivara';

    expect(fn() => npcPhaseApply($session, $edited, 'conditions', $conditions))
        ->toThrow(SessionRefusal::class, sprintf('%s already stands at 2,1 while both can be visible.', $other))
        ->and($session->readMap('test-map')['npcs'])->toBe($before['npcs'])
        ->and($session->hasUnsavedChanges())->toBeFalse()
        ->and($session->undo()['label'])->toBeNull()
        ->and(npcPhaseTileIssues($session))->toBe([]);
})->with([
    'the original loses its guard' => [0, ''],
    'the returned phase loses its guard' => [1, ''],
    'the returned phase takes another flag' => [1, 'event:dispatched'],
]);

it('reports two NPCs anchored on one tile when nothing keeps them apart, and not phases', function () {
    $session = EditorSession::open(npcSessionProject([
        ['id' => 'ann', 'name' => 'Ann', 'x' => 2, 'y' => 1],
        ['id' => 'bob', 'name' => 'Bob', 'x' => 2, 'y' => 1, 'conditions' => [['type' => 'event', 'name' => 'met']]],
        ['id' => 'cal', 'name' => 'Cal', 'x' => 6, 'y' => 1, 'conditions' => [['type' => 'switch', 'name' => 'night']]],
        ['id' => 'dee', 'name' => 'Dee', 'x' => 6, 'y' => 1, 'conditions' => [['type' => 'switch', 'name' => 'night', 'value' => false]]],
    ]));

    expect(npcPhaseTileIssues($session))->toBe(['It shares tile (2, 1) with NPC Bob.']);
});

it('reads structured subjects and ids as references, without warnings, when duplicating and renaming', function () {
    $root = npcSessionProject([
        ['id' => 'ann', 'name' => 'Ann', 'x' => 2, 'y' => 1],
        ['id' => 'bob', 'name' => 'Bob', 'x' => 6, 'y' => 3, 'script' => [
            ['type' => 'stage', 'subject' => ['kind' => 'npc', 'id' => 'ann'], 'at' => ['kind' => 'world_object', 'id' => 'desk']],
            ['type' => 'field_animation', 'id' => ['kind' => 'npc', 'id' => 'bob']],
            ['type' => 'common_event', 'id' => ['kind' => 'script', 'id' => 'a']],
        ]],
    ]);
    $session = EditorSession::open($root);
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $copy = $session->duplicateNpc('test-map', $session->readMap('test-map')['revision'], 0);
        $renamed = npcPhaseApply($session, 0, 'name', 'Annabel');
        $copyRenamed = npcPhaseApply($session, $copy['index'], 'name', 'Anja');
        $refusal = null;

        try {
            $session->deleteNpc('test-map', $copyRenamed['revision'], 0);
        } catch (SessionRefusal $caught) {
            $refusal = $caught->getMessage();
        }
    } finally {
        restore_error_handler();
    }

    expect($warnings)->toBe([])
        ->and($renamed)->toMatchArray(['id' => 'ann', 'followedId' => null, 'idReferences' => ['NPC Bob script']])
        ->and($copyRenamed)->toMatchArray(['id' => 'anja', 'followedId' => 'anja'])
        ->and($refusal)->toContain('Annabel is named by NPC Bob script');
});
