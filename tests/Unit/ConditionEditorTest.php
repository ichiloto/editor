<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\ConditionEditor;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Core\WorldConditionType;

/**
 * Opens an editor on an encoded condition line.
 *
 * @param string $encoded The line as a data file stores it.
 * @return ConditionEditor The open editor.
 */
function conditionEditorOn(string $encoded): ConditionEditor
{
    $editor = new ConditionEditor();
    $editor->open('conditions', 'Conditions', ConditionCodec::decodeAll($encoded));

    return $editor;
}

it('uses the runtime condition vocabulary', function () {
    expect(ConditionCodec::types())->toBe(WorldConditionType::values());
});

it('gives back the line it was opened on', function () {
    $line = 'quest:breakfast-duty:active; !switch:door_open; item:S-Potion:3';

    // Opening and closing without touching anything must not rewrite an
    // author's file.
    expect(conditionEditorOn($line)->encoded())->toBe($line);
});

it('reads each condition out in words', function () {
    $editor = conditionEditorOn('quest:breakfast-duty:active; !switch:door_open; item:S-Potion:3');

    expect($editor->rows())->toBe([
        'Quest breakfast-duty is active',
        'NOT Switch door_open is on',
        'Item S-Potion x3 is held',
    ]);
});

it('adds a condition below the cursor and lands on it', function () {
    $editor = conditionEditorOn('quest:a:active; quest:b:active');
    $editor->move(1);
    $editor->add();

    expect($editor->selectedIndex())->toBe(2)
        ->and($editor->count())->toBe(3)
        // A new one is unnamed until it is named, and says so.
        ->and($editor->rows()[2])->toBe('Switch (unnamed) is on');
});

it('drops the condition under the cursor', function () {
    $editor = conditionEditorOn('quest:a:active; switch:b; item:c');
    $editor->move(1);
    $editor->remove();

    expect($editor->encoded())->toBe('quest:a:active; item:c')
        ->and($editor->selectedIndex())->toBe(1);
});

it('rebuilds the extras when the type changes', function () {
    $editor = conditionEditorOn('quest:breakfast-duty:active');
    $editor->cycleType(1);

    // A quest status means nothing to a switch, so it does not come along.
    expect($editor->selected())->toBe(['type' => 'switch', 'name' => 'breakfast-duty']);
});

it('keeps a negation across a type change', function () {
    $editor = conditionEditorOn('!quest:breakfast-duty:active');
    $editor->cycleType(1);

    expect($editor->selected()['negate'] ?? null)->toBeTrue();
});

it('cycles what a type carries beyond a name', function () {
    $quest = conditionEditorOn('quest:a:completed');
    $quest->cycleExtra(1);

    $switch = conditionEditorOn('switch:door');
    $switch->cycleExtra(1);

    $variable = conditionEditorOn('variable:gold:==:10');
    $variable->cycleExtra(1);

    expect($quest->encoded())->toBe('quest:a:active')
        ->and($switch->encoded())->toBe('switch:door:false')
        ->and($variable->encoded())->toBe('variable:gold:!=:10');
});

it('counts an item up and back down to none', function () {
    $editor = conditionEditorOn('item:S-Potion');
    $editor->cycleExtra(1);

    expect($editor->encoded())->toBe('item:S-Potion:2');

    $editor->cycleExtra(-1);

    // One is the default the wire form leaves out.
    expect($editor->encoded())->toBe('item:S-Potion');
});

it('flips a condition to having to not hold, and back', function () {
    $editor = conditionEditorOn('switch:door');
    $editor->toggleNegate();

    expect($editor->encoded())->toBe('!switch:door');

    $editor->toggleNegate();

    expect($editor->encoded())->toBe('switch:door');
});

it('knows which names are chosen and which are invented', function () {
    $quest = conditionEditorOn('quest:a:active');
    $item = conditionEditorOn('item:S-Potion');
    $switch = conditionEditorOn('switch:door');

    expect($quest->nameReference())->toBe(['label' => 'Quest', 'category' => 'quests'])
        ->and($item->nameReference())->toBe(['label' => 'Item', 'category' => 'inventory'])
        // Nothing declares a switch, so there is no list to choose from.
        ->and($switch->nameReference())->toBe(['label' => 'Switch', 'category' => null]);
});

it('wraps the cursor rather than running off the ends', function () {
    $editor = conditionEditorOn('quest:a:active; switch:b');
    $editor->move(-1);

    expect($editor->selectedIndex())->toBe(1);

    $editor->move(1);

    expect($editor->selectedIndex())->toBe(0);
});

it('copes with an empty list', function () {
    $editor = conditionEditorOn('');

    $editor->move(1);
    $editor->remove();
    $editor->toggleNegate();
    $editor->cycleType(1);

    // A skit with no conditions always plays, and nothing here may crash on
    // the way to adding the first one.
    expect($editor->count())->toBe(0)
        ->and($editor->selected())->toBeNull()
        ->and($editor->encoded())->toBe('');
});

it('builds a whole condition from nothing', function () {
    $editor = new ConditionEditor();
    $editor->open('conditions', 'Conditions', []);
    $editor->add();

    // A new condition starts as a switch; four steps on is the key item.
    $editor->cycleType(4);
    $editor->setName('Rusty Key');

    expect($editor->encoded())->toBe('key_item:Rusty Key');

    // A key item is held or it is not, so there is no extra to cycle.
    $editor->cycleExtra(1);

    expect($editor->encoded())->toBe('key_item:Rusty Key');
});

it('offers a project\'s own quests to a quest condition', function () {
    $workspace = ProjectWorkspace::fromProject(fixturePath('sample-project'));
    $editor = conditionEditorOn('quest:breakfast-duty:active');
    $reference = $editor->nameReference();

    $values = new Ichiloto\Editor\Database\ReferenceCatalog($workspace)->valuesFor($reference['category']);

    expect($values)->toContain('breakfast-duty');
});
