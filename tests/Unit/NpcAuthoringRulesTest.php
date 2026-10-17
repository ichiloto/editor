<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\NpcAuthoring;
use Ichiloto\Editor\Field\NpcInspector;
use Ichiloto\Editor\Field\NpcRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * The NPC authoring rules every interface applies through one service:
 * placement, identity and references, row edits through the record pane,
 * and commands that restore only the map's NPCs.
 */

/**
 * Returns a throwaway project whose 12x5 fixture map carries the given NPCs,
 * opened as a workspace, with its map.
 *
 * @param array<int, mixed> $npcs The npcs block.
 * @param array<string, mixed> $events Event definitions to merge.
 * @return array{0: ProjectWorkspace, 1: ProjectMap}
 */
function npcRulesProject(array $npcs, array $events = []): array
{
    $root = makeTemporaryProject('ichiloto-npc-rules-');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['npcs'] = $npcs;
    $data['events'] = array_replace($data['events'] ?? [], $events);
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    $workspace = ProjectWorkspace::fromProject($root);

    return [$workspace, $workspace->getMapByIndex(0)];
}

/** The row with a field id among an NPC's rows. */
function npcRulesRow(NpcInspector $inspector, int $index, string $fieldId, array $frame = []): array
{
    return array_find($inspector->getFields($index, $frame) ?? [], static fn(array $row): bool => ($row['field'] ?? null) === $fieldId)
        ?? throw new RuntimeException("No NPC row {$fieldId}.");
}

it('creates an NPC under an id its name derives, and refuses a tile outside the map or taken', function () {
    [$workspace, $map] = npcRulesProject([['id' => 'gate-guard', 'name' => 'Gate Guard', 'x' => 2, 'y' => 1]]);
    $authoring = new NpcAuthoring($workspace);

    $change = $authoring->create($map, 4, 2, 'Gate Guard');
    $blank = $authoring->create($map, 5, 2, '   ');

    expect($change->index)->toBe(1)
        ->and($change->npc?->getId())->toBe('gate-guard-2')
        ->and($map->getNpcs()->get(1)?->toArray())->toMatchArray(['x' => 4, 'y' => 2, 'movement' => 'fixed'])
        ->and($blank->npc?->getName())->toBe(NpcAuthoring::DEFAULT_NAME)
        ->and($blank->npc?->getId())->toBe('new-npc')
        ->and(fn() => $authoring->create($map, 12, 0, 'Out'))->toThrow(NpcRefusal::class, '12,0 is outside the map.')
        ->and(fn() => $authoring->create($map, 2, 1, 'Stacked'))->toThrow(NpcRefusal::class, 'Gate Guard already stands at 2,1.')
        ->and($map->getNpcs()->count())->toBe(3);

    // The command puts back exactly the collection; nothing else is involved.
    $blank->command?->undo();
    $change->command?->undo();
    expect($map->getNpcs()->ids())->toBe(['gate-guard'])
        ->and($map->isDirty())->toBeFalse();
    $change->command?->execute();
    expect($map->getNpcs()->ids())->toBe(['gate-guard', 'gate-guard-2']);
});

it('moves an NPC within the map onto a free tile, and changes nothing for its own tile', function () {
    [$workspace, $map] = npcRulesProject([
        ['id' => 'a', 'name' => 'Ann', 'x' => 2, 'y' => 1],
        ['id' => 'b', 'name' => 'Bob', 'x' => 6, 'y' => 3],
    ]);
    $authoring = new NpcAuthoring($workspace);

    expect($authoring->move($map, 0, 2, 1)->command)->toBeNull()
        ->and(fn() => $authoring->move($map, 0, -1, 1))->toThrow(NpcRefusal::class, '-1,1 is outside the map.')
        ->and(fn() => $authoring->move($map, 0, 6, 3))->toThrow(NpcRefusal::class, 'Bob already stands at 6,3.')
        ->and(fn() => $authoring->move($map, 7, 1, 1))->toThrow(NpcRefusal::class, 'test-map has no NPC 7.')
        ->and($map->isDirty())->toBeFalse();

    $moved = $authoring->move($map, 0, 8, 2);
    expect($moved->npc?->getX())->toBe(8)
        ->and($moved->command?->label)->toBe('NPC move');
    $moved->command?->undo();
    expect($map->getNpcs()->get(0)?->getX())->toBe(2);
});

it('duplicates beside the original when that tile is free, and on its own tile when it is not', function () {
    [$workspace, $map] = npcRulesProject([
        ['id' => 'a', 'name' => 'Ann', 'sprite' => 'A', 'x' => 2, 'y' => 1],
        ['id' => 'b', 'name' => 'Bob', 'sprite' => 'B', 'x' => 3, 'y' => 1],
    ]);
    $authoring = new NpcAuthoring($workspace);

    $beside = $authoring->duplicate($map, 1);
    $blocked = $authoring->duplicate($map, 0);

    expect($beside->index)->toBe(2)
        ->and($beside->npc?->getId())->toBe('bob')
        ->and([$beside->npc?->getX(), $beside->npc?->getY()])->toBe([4, 1])
        ->and($blocked->npc?->getId())->toBe('ann')
        ->and([$blocked->npc?->getX(), $blocked->npc?->getY()])->toBe([2, 1]);
});

it('refuses to delete an NPC something names, naming each reference, and deletes it once nothing does', function () {
    [$workspace, $map] = npcRulesProject([
        ['id' => 'a', 'name' => 'Ann', 'x' => 2, 'y' => 1],
        ['id' => 'b', 'name' => 'Bob', 'x' => 6, 'y' => 3, 'script' => [
            ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'a', 'steps' => [['direction' => 'north']]],
        ]],
        ['id' => 'c', 'name' => 'Cy', 'x' => 8, 'y' => 3],
    ]);
    $authoring = new NpcAuthoring($workspace);

    try {
        $authoring->delete($map, 0);
        $this->fail('Deleting a named NPC was not refused.');
    } catch (NpcRefusal $refusal) {
        expect($refusal->getMessage())->toBe('Ann is named by NPC Bob script - resolve those before deleting.')
            ->and($refusal->details)->toBe(['- NPC Bob script']);
    }

    $deleted = $authoring->delete($map, 1);
    expect($deleted->index)->toBeNull()
        ->and($deleted->npc?->getName())->toBe('Bob')
        ->and($map->getNpcs()->ids())->toBe(['a', 'c']);

    // Undo puts it back where it stood in the list, not at the end.
    $deleted->command?->undo();
    expect($map->getNpcs()->ids())->toBe(['a', 'b', 'c']);
});

it('assigns an id to an NPC without one, once', function () {
    [$workspace, $map] = npcRulesProject([['name' => 'Old Man', 'x' => 2, 'y' => 1]]);
    $authoring = new NpcAuthoring($workspace);

    expect(npcRulesRow(new NpcInspector($map), 0, NpcInspector::ASSIGN_ID_FIELD)['label'])->toContain('No stable id');

    $assigned = $authoring->assignId($map, 0);
    expect($assigned->npc?->getId())->toBe('old-man')
        ->and($assigned->command?->label)->toBe('Assign NPC id old-man')
        ->and(fn() => $authoring->assignId($map, 0))
            ->toThrow(NpcRefusal::class, 'Old Man already has the stable id "old-man"; ids do not change.');
});

it('edits rows through the record pane, carrying the id with a rename unless something names it', function () {
    [$workspace, $map] = npcRulesProject(
        [
            ['id' => 'mara', 'name' => 'Mara', 'x' => 2, 'y' => 1],
            ['id' => 'bob', 'name' => 'Bob', 'x' => 6, 'y' => 3],
        ],
        ['T' => ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\DialogueEventTrigger', 'data' => ['npcId' => 'mara']]],
    );
    $authoring = new NpcAuthoring($workspace);
    $inspector = new NpcInspector($map);

    $kept = $authoring->applyField($inspector, 0, [], npcRulesRow($inspector, 0, 'name'), 'Old Mara');
    $followed = $authoring->applyField($inspector, 1, [], npcRulesRow($inspector, 1, 'name'), 'Robert');

    expect($kept->npc?->getId())->toBe('mara')
        ->and($kept->followedId)->toBeNull()
        ->and($kept->idReferences)->toBe(['test-map event T'])
        ->and($followed->followedId)->toBe('robert')
        ->and($map->getNpcs()->get(1)?->getName())->toBe('Robert')
        ->and($authoring->applyField($inspector, 1, [], npcRulesRow($inspector, 1, 'name'), 'Robert')->command)->toBeNull();

    // The rename and its id are one step.
    $followed->command?->undo();
    expect($map->getNpcs()->get(1)?->getId())->toBe('bob')
        ->and($map->getNpcs()->get(1)?->getName())->toBe('Bob');
});

it('applies the move rules to the coordinate rows, leaving nothing changed when refused', function () {
    [$workspace, $map] = npcRulesProject([
        ['id' => 'a', 'name' => 'Ann', 'x' => 2, 'y' => 1],
        ['id' => 'b', 'name' => 'Bob', 'x' => 6, 'y' => 1],
    ]);
    $authoring = new NpcAuthoring($workspace);
    $inspector = new NpcInspector($map);

    expect(fn() => $authoring->applyField($inspector, 0, [], npcRulesRow($inspector, 0, 'x'), '6'))
        ->toThrow(NpcRefusal::class, 'Bob already stands at 6,1.')
        ->and(fn() => $authoring->applyField($inspector, 0, [], npcRulesRow($inspector, 0, 'y'), '9'))
        ->toThrow(NpcRefusal::class, '2,9 is outside the map.')
        ->and($map->getNpcs()->get(0)?->getX())->toBe(2)
        ->and($map->isDirty())->toBeFalse()
        ->and(npcRulesRow($inspector, 0, 'x')['value'])->toBe('2');

    expect($authoring->applyField($inspector, 0, [], npcRulesRow($inspector, 0, 'x'), '4')->npc?->getX())->toBe(4);
});

it('adds and removes dialogue variants, lines and script commands at a row', function () {
    [$workspace, $map] = npcRulesProject([['id' => 'a', 'name' => 'Ann', 'x' => 2, 'y' => 1, 'dialogue' => [['text' => 'Hi.']]]]);
    $authoring = new NpcAuthoring($workspace);
    $inspector = new NpcInspector($map);

    $variant = $authoring->addSubItem($inspector, 0, [], '');
    expect($map->getNpcs()->get(0)?->getDialogueAsVariants())->toHaveCount(2)
        ->and($variant->command?->label)->toBe('NPC add');

    $authoring->addSubItem($inspector, 0, [], 'variant0Line0Text');
    expect($map->getNpcs()->get(0)?->getDialogueAsVariants()[0]['lines'])->toHaveCount(2);

    $command = $authoring->addSubItem($inspector, 0, ['script'], '');
    expect($map->getNpcs()->get(0)?->getScript())->toHaveCount(1)
        ->and($inspector->getFields(0, ['script']))->not->toBeEmpty();

    $authoring->removeSubItem($inspector, 0, ['script'], 'command0Type');
    $authoring->removeSubItem($inspector, 0, [], 'variant1');
    expect($map->getNpcs()->get(0)?->getScript())->toBe([])
        ->and($map->getNpcs()->get(0)?->getDialogueAsVariants())->toHaveCount(1)
        ->and($authoring->removeSubItem($inspector, 0, [], 'name')->command)->toBeNull()
        ->and(fn() => $authoring->addSubItem($inspector, 0, [3, 'then'], ''))->toThrow(NpcRefusal::class, 'is no longer there');

    $command->command?->undo();
    expect($map->getNpcs()->get(0)?->getScript())->toBe([]);
});

it('groups an NPC\'s rows under headings and leaves a frame that no longer resolves to the caller', function () {
    [, $map] = npcRulesProject([['id' => 'a', 'name' => 'Ann', 'x' => 2, 'y' => 1, 'mood' => 'stern', 'dialogue' => [
        ['conditions' => ['flag:met'], 'lines' => [['text' => 'Again?']]],
    ]]]);
    $inspector = new NpcInspector($map);
    $labels = array_map(static fn(array $row): string => trim((string) $row['label']), $inspector->getFields(0) ?? []);

    expect($labels)->toContain('Identity', 'Placement', 'Movement', 'Preserved fields', 'Dialogue variant 1', 'Line 1 Text')
        ->and($inspector->getFields(0, [5, 'then']))->toBeNull()
        ->and($inspector->getFields(0, ['script']))->not->toBeNull();
});

it('warns in the terminal Inspector when a coordinate edit would stack an NPC, recording nothing', function () {
    [$workspace, $map] = npcRulesProject([
        ['id' => 'a', 'name' => 'Ann', 'x' => 2, 'y' => 1],
        ['id' => 'b', 'name' => 'Bob', 'x' => 6, 'y' => 1],
    ]);
    $editor = createEditorForTesting($workspace->projectRoot);
    setEditorProperty($editor, 'workspace', $workspace);
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    callEditorMethod($editor, 'setEditingMode', 'npc');
    callEditorMethod($editor, 'selectNpc', 0);
    $x = array_find(callEditorMethod($editor, 'getInspectorFields'), static fn(array $row): bool => ($row['field'] ?? null) === 'x');

    callEditorMethod($editor, 'applyNpcFieldValueRecorded', $x, '6');

    expect($map->getNpcs()->get(0)?->getX())->toBe(2)
        ->and(getEditorProperty($editor, 'statusMessage'))->toBe('Bob already stands at 6,1.')
        ->and(getEditorProperty($editor, 'history')->count())->toBe(0);
});
