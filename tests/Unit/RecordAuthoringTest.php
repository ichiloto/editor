<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Database\RecordChange;
use Ichiloto\Editor\Database\RecordItem;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\PermanentGrowthCatalog;

/**
 * The record rules every editor interface edits schema categories through:
 * each change applied once and undone exactly, the item a row belongs to
 * located by the category's own field ids, and a change that cannot be
 * made refused with the reason an author reads, leaving the record as it
 * was. Synthetic fixtures only.
 */

/** The labels of a category's records. */
function authoringLabels(ProjectRecordDatabase $database): array
{
    return array_values($database->getEntryLabels());
}

/** Undoes and redoes a change's command, checking each lands where it should. */
function authoringRoundTrip(RecordChange $change, Closure $read, mixed $before, mixed $after): void
{
    expect($change->command)->not->toBeNull()
        ->and($read())->toBe($after);
    $change->command?->undo();
    expect($read())->toBe($before);
    $change->command?->execute();
    expect($read())->toBe($after);
}

/** A script with a choice first and a movement route second. */
function authoringScript(ProjectRecordDatabase $events, RecordAuthoring $authoring): void
{
    $authoring->applyField($events, 0, [], 'command0Type', 'choice', 'Command 1 Type');
    $authoring->applyField($events, 0, [], 'command1Type', 'move_route', 'Command 2 Type');
}

it('applies a row and undoes it to the exact record, a same-value edit leaving no step', function () {
    $states = loadRecordDatabase(makeTemporaryProject(), 'states');
    $authoring = new RecordAuthoring();
    $before = $states->getRecordByIndex(0)?->toArray();

    $change = $authoring->applyField($states, 0, [], 'name', 'Venom', 'Name');

    expect($change->command?->label)->toBe('Name edit')
        ->and(authoringLabels($states)[0])->toBe('Venom');
    $change->command?->undo();
    expect($states->getRecordByIndex(0)?->toArray())->toBe($before)
        ->and($states->isDirty())->toBeFalse();
    $change->command?->execute();
    expect(authoringLabels($states)[0])->toBe('Venom')
        ->and($authoring->applyField($states, 0, [], 'name', 'Venom', 'Name')->command)->toBeNull();
});

it('refuses a parameter line or a value its codec cannot read, leaving the record as it was', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/' . PermanentGrowthCatalog::RELATIVE_PATH, <<<'PHP'
    <?php

    return [
      ['id' => 'growth.synthetic', 'stat' => 'maxHp', 'amount' => 1, 'sourceType' => 'event', 'sourceId' => 'test', 'metadata' => ['label' => 'Synthetic']],
    ];
    PHP);
    $growth = loadRecordDatabase($root, 'permanent_growth');
    $events = loadRecordDatabase($root, 'common_events');
    $authoring = new RecordAuthoring();
    $growthBefore = $growth->getRecordByIndex(0)?->toArray();
    $eventsBefore = $events->getRecordByIndex(0)?->toArray();

    expect(fn() => $authoring->applyField($growth, 0, [], 'metadata', 'tier=1, tier=2', 'Metadata'))
        ->toThrow(RecordRefusal::class, 'named twice')
        ->and(fn() => $authoring->applyField($events, 0, [], 'command3Conditions', 'nonsense', 'Command 4 Conditions'))
        ->toThrow(RecordRefusal::class, 'Condition "nonsense" cannot be read')
        ->and($growth->getRecordByIndex(0)?->toArray())->toBe($growthBefore)
        ->and($events->getRecordByIndex(0)?->toArray())->toBe($eventsBefore)
        ->and($growth->isDirty())->toBeFalse()
        ->and($events->isDirty())->toBeFalse();
});

it('adds and removes a sub-list entry after the row\'s entry or at the end, each undone exactly', function () {
    $troops = loadRecordDatabase(makeTemporaryProject(), 'troops');
    $authoring = new RecordAuthoring();
    $members = static fn(): array => array_column($troops->getRecordByIndex(0)?->getSubList('enemies') ?? [], 'position');
    $before = $members();

    $after = $authoring->addItem($troops, 0, [], 'member0Enemy');
    expect($after->index)->toBe(1)
        ->and($after->command?->label)->toBe('Member add');
    authoringRoundTrip($after, $members, $before, [$before[0], [15, 7], $before[1]]);
    $after->command?->undo();

    $appended = $authoring->addItem($troops, 0, [], null);
    expect($appended->index)->toBe(count($before));
    $appended->command?->undo();

    $removed = $authoring->removeItem($troops, 0, [], 'member1Position1');
    expect($removed->removed)->toMatchArray(['position' => $before[1]]);
    authoringRoundTrip($removed, $members, $before, [$before[0]]);

    // The record's own rows belong to no item: nothing to add after, nothing to remove.
    expect(fn() => $authoring->addItem($troops, 0, [], 'name'))->toThrow(RecordRefusal::class, 'belongs to no item')
        ->and($authoring->removeItem($troops, 0, [], 'name')->command)->toBeNull();
});

it('adds and removes commands inside a frame, and refuses a frame that is gone', function () {
    $events = loadRecordDatabase(makeTemporaryProject(), 'common_events');
    $authoring = new RecordAuthoring();
    $then = [3, 'then'];
    $types = static fn(): array => array_column($events->getFrameCommands(0, $then) ?? [], 'type');
    $before = $types();

    $appended = $authoring->addItem($events, 0, $then, null);
    expect($appended->index)->toBe(count($before))
        ->and($appended->command?->label)->toBe('Command add');
    authoringRoundTrip($appended, $types, $before, [...$before, 'text']);

    $removed = $authoring->removeItem($events, 0, $then, 'command0Text');
    expect($removed->command?->label)->toBe('Command remove');
    authoringRoundTrip($removed, $types, [...$before, 'text'], ['text']);

    expect(fn() => $authoring->addItem($events, 0, [9, 'then'], null))->toThrow(RecordRefusal::class, 'is no longer there')
        ->and(fn() => $authoring->applyField($events, 0, [9, 'then'], 'command0Text', 'x', 'Text'))
        ->toThrow(RecordRefusal::class, 'is no longer there');
});

it('locates the item a row belongs to and adds beneath an entry that holds a list', function () {
    $events = loadRecordDatabase(makeTemporaryProject(), 'common_events');
    $authoring = new RecordAuthoring();
    authoringScript($events, $authoring);

    $choice = $events->locateItem(0, [], 'command0Prompt');
    $route = $events->locateItem(0, [], 'command1Subject');
    expect([$choice?->kind, $choice?->childKind, $choice?->childNoun])->toBe([RecordItem::ENTRY, RecordItem::OPTION, 'option'])
        ->and([$route?->kind, $route?->childKind, $route?->childNoun])->toBe([RecordItem::ENTRY, RecordItem::NESTED, 'route step'])
        ->and($events->locateItem(0, [], '__scriptId'))->toBeNull()
        ->and(fn() => $authoring->addItem($events, 0, [], 'command2Amount', child: true))
        ->toThrow(RecordRefusal::class, 'holds nothing that can be added beneath it');

    $options = static fn(): array => array_column($events->getFrameCommands(0, [])[0]['options'] ?? [], 'text');
    $firstOption = $authoring->addItem($events, 0, [], 'command0Prompt', child: true);
    expect($firstOption->command?->label)->toBe('Option add');
    authoringRoundTrip($firstOption, $options, [], ['New option']);
    $authoring->applyField($events, 0, [], 'command0Option0Text', 'Read it', 'Option 1 Text');
    $authoring->addItem($events, 0, [], 'command0Option0Text');
    expect($options())->toBe(['Read it', 'New option'])
        ->and($events->locateItem(0, [], 'command0Option1Then'))->toEqual(new RecordItem(RecordItem::OPTION, [], 0, 1, 'option'));
    authoringRoundTrip($authoring->removeItem($events, 0, [], 'command0Option0Text'), $options, ['Read it', 'New option'], ['New option']);

    $steps = static fn(): array => array_column($events->getFrameCommands(0, [])[1]['steps'] ?? [], 'direction');
    $firstStep = $authoring->addItem($events, 0, [], 'command1Subject', child: true);
    expect($firstStep->command?->label)->toBe('Route step add');
    authoringRoundTrip($firstStep, $steps, [], ['down']);
    $authoring->applyField($events, 0, [], 'command1Step0Direction', 'up', 'Step 1 Direction');
    $authoring->addItem($events, 0, [], 'command1Step0Count');
    expect($steps())->toBe(['up', 'down'])
        ->and($events->locateItem(0, [], 'command1Step1Count')?->kind)->toBe(RecordItem::NESTED);
    authoringRoundTrip($authoring->removeItem($events, 0, [], 'command1Step0Direction'), $steps, ['up', 'down'], ['down']);
});

it('creates, duplicates and deletes records, each one undo step', function () {
    $states = loadRecordDatabase(makeTemporaryProject(), 'states');
    $authoring = new RecordAuthoring();
    $labels = static fn(): array => authoringLabels($states);
    $before = $labels();

    $created = $authoring->createRecord($states);
    expect($created->index)->toBe(2)
        ->and($created->command?->label)->toBe('State create');
    authoringRoundTrip($created, $labels, $before, [...$before, 'New State']);
    $created->command?->undo();

    $copy = $authoring->duplicateRecord($states, 0);
    expect($copy->index)->toBe(1);
    authoringRoundTrip($copy, $labels, $before, [$before[0], $before[0], $before[1]]);
    $copy->command?->undo();

    $deleted = $authoring->deleteRecord($states, 1);
    expect($deleted->index)->toBe(0)
        ->and($deleted->command?->label)->toBe(sprintf('Delete state %s', $before[1]));
    authoringRoundTrip($deleted, $labels, $before, [$before[0]]);
    expect($authoring->deleteRecord($states, 0)->index)->toBeNull();
});

it('moves a record only where its file keeps the order, saying why elsewhere', function () {
    $root = makeTemporaryProject();
    $rules = loadRecordDatabase($root, 'battle_entry_rules');

    foreach (['alpha', 'beta'] as $id) {
        $index = $rules->addRecord();
        $rules->setField((int) $index, 'id', $id);
    }

    $authoring = new RecordAuthoring();
    $labels = static fn(): array => authoringLabels($rules);
    $before = $labels();
    $moved = $authoring->moveRecord($rules, 0, 1);

    expect($moved->index)->toBe(1)
        ->and($authoring->moveRecord($rules, 1, 1)->command)->toBeNull();
    authoringRoundTrip($moved, $labels, $before, array_reverse($before));

    $states = loadRecordDatabase($root, 'states');
    expect(fn() => $authoring->moveRecord($states, 0, 1))->toThrow(RecordRefusal::class, (string) $states->reorderRefusalReason())
        ->and($states->isDirty())->toBeFalse();
});

it('refuses every change to a read-only category with its reason', function () {
    $types = loadRecordDatabase(makeTemporaryProject(), 'types');
    $authoring = new RecordAuthoring();
    $reason = sprintf('Read-only: %s.', $types->getReadOnlyReason());

    expect(fn() => $authoring->applyField($types, 0, [], 'file', 'x', 'File'))->toThrow(RecordRefusal::class, $reason)
        ->and(fn() => $authoring->createRecord($types))->toThrow(RecordRefusal::class, $reason)
        ->and(fn() => $authoring->deleteRecord($types, 0))->toThrow(RecordRefusal::class, $reason)
        ->and(fn() => $authoring->addItem($types, 0, [], null))->toThrow(RecordRefusal::class, $reason);
});
