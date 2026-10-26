<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;

/**
 * Opens the editor on the quests category of a temporary project.
 *
 * @param string $root The project root.
 * @return object The editor.
 */
function editorOnQuests(string $root): object
{
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    callEditorMethod($editor, 'openDatabaseAtCategory', 'quests');

    return $editor;
}

/**
 * Puts the settings cursor on a field by its id.
 *
 * @param object $editor The editor.
 * @param string $fieldId The field id.
 * @return void
 */
function selectQuestField(object $editor, string $fieldId): void
{
    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $index => $field) {
        if (($field['field'] ?? null) === $fieldId) {
            setEditorProperty($editor, 'databaseSelectedSettingIndex', $index);

            return;
        }
    }

    throw new RuntimeException("No field {$fieldId} in the settings pane.");
}

/** The open quest's reward items, as the record holds them. */
function questRewardItems(object $editor): mixed
{
    return getEditorProperty($editor, 'workspace')->getRecordDatabase('quests')->getRecordByIndex(0)->get('rewards.items');
}

/** Gives the first quest reward items, authored as bare names. */
function giveQuestRewards(object $editor, array $items): void
{
    getEditorProperty($editor, 'workspace')->getRecordDatabase('quests')->getRecordByIndex(0)->set('rewards.items', $items);
}

it('shows each reward item as a picked row, not a line to type', function () {
    $editor = editorOnQuests(makeTemporaryProject());
    giveQuestRewards($editor, ['S-Potion', 'Wooden Sword']);
    $byField = [];

    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $field) {
        $byField[(string) ($field['field'] ?? '')] = $field;
    }

    // Names resolve through the engine's item store, so a misspelling is a
    // reward that silently never arrives. Chosen, never spelled.
    expect($byField['reward0Item']['reference'] ?? null)->toBe('inventory')
        ->and($byField['reward0Item']['value'] ?? null)->toBe('S-Potion')
        ->and($byField['reward1Item']['value'] ?? null)->toBe('Wooden Sword')
        ->and($byField['reward0Item'] ?? [])->not->toHaveKey('control')
        ->and($byField['rewardList']['value'] ?? null)->toBe('2');
});

it('adds a reward item from the reward heading and removes it again', function () {
    $editor = editorOnQuests(makeTemporaryProject());

    // The heading is where the first one is added, as there is no reward
    // row to stand on yet.
    selectQuestField($editor, 'rewardList');
    callEditorMethod($editor, 'addDatabaseRecordSubItem');

    expect(questRewardItems($editor))->toHaveCount(1);

    selectQuestField($editor, 'reward0Item');
    callEditorMethod($editor, 'removeDatabaseRecordSubItem');

    expect(questRewardItems($editor))->toBeNull();
});

it('still adds an objective when the cursor is not on a reward row', function () {
    $editor = editorOnQuests(makeTemporaryProject());
    $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('quests');
    $objectivesBefore = count($database->getSubItems(0));

    selectQuestField($editor, 'name');
    callEditorMethod($editor, 'addDatabaseRecordSubItem');

    expect(count($database->getSubItems(0)))->toBe($objectivesBefore + 1)
        ->and(questRewardItems($editor))->toBeNull();
});

it('puts back what undo removed, in the same slot', function () {
    $editor = editorOnQuests(makeTemporaryProject());
    giveQuestRewards($editor, ['S-Potion', 'Wooden Sword', 'S-Mana']);

    selectQuestField($editor, 'reward1Item');
    callEditorMethod($editor, 'removeDatabaseRecordSubItem');

    expect(questRewardItems($editor))->toBe(['S-Potion', 'S-Mana']);

    callEditorMethod($editor, 'performUndo');

    expect(questRewardItems($editor))->toBe(['S-Potion', 'Wooden Sword', 'S-Mana']);
});

it('round-trips picked rewards through the saved file, a bare name until given a quantity', function () {
    $root = makeTemporaryProject();
    $database = ProjectWorkspace::fromProject($root)->getRecordDatabase('quests');
    $first = $database->addSubItem(0, listKey: 'rewards.items');
    $database->setField(0, 'reward' . $first . 'Item', 'Wooden Sword');
    $second = $database->addSubItem(0, listKey: 'rewards.items');
    $database->setField(0, 'reward' . $second . 'Item', 'S-Potion');
    $database->setField(0, 'reward' . $second . 'Quantity', '3');
    $database->save();

    expect((require $root . '/assets/Data/quests.php')[0]['rewards']['items'])
        ->toBe(['Wooden Sword', ['item' => 'S-Potion', 'quantity' => 3]]);
});

it('drops the items key entirely when the last reward goes', function () {
    $editor = editorOnQuests(makeTemporaryProject());
    giveQuestRewards($editor, ['S-Potion']);

    selectQuestField($editor, 'reward0Item');
    callEditorMethod($editor, 'removeDatabaseRecordSubItem');

    // An empty list would read as "rewards nothing, explicitly"; absence is
    // what the authored files use.
    expect(getEditorProperty($editor, 'workspace')->getRecordDatabase('quests')->getRecordByIndex(0)->get('rewards'))
        ->not->toHaveKey('items');
});

it('lets the GUI add to a list beside the record\'s own from its heading, and rename with a note', function () {
    $session = \Ichiloto\Editor\Session\EditorSession::open(makeTemporaryProject());
    $rows = $session->readDatabaseRecord('quests', 0)['rows'];
    $heading = array_find($rows, static fn(array $row): bool => ($row['key']['field'] ?? null) === 'rewardList');

    expect($heading)->toMatchArray(['label' => 'Reward Items', 'value' => '0', 'item' => true, 'listHeading' => true, 'childNoun' => 'reward item']);

    $session->addDatabaseItem('quests', 0, $heading['key'], true);
    $rows = $session->readDatabaseRecord('quests', 0)['rows'];
    $item = array_find($rows, static fn(array $row): bool => ($row['key']['field'] ?? null) === 'reward0Item');

    expect($item)->toMatchArray(['item' => true, 'itemNoun' => 'reward item', 'reference' => 'inventory'])
        ->and($item)->not->toHaveKey('listHeading');

    $name = array_find($rows, static fn(array $row): bool => ($row['key']['field'] ?? null) === 'name');

    expect($session->applyDatabaseRecord('quests', 0, $name['key'], 'Morning Errand')['note'] ?? null)
        ->toBe('Its id stays breakfast-duty, which other things point at.');
});

it('names the record\'s own list for an add with no row, not the list beside it', function () {
    $session = \Ichiloto\Editor\Session\EditorSession::open(makeTemporaryProject());

    expect($session->readDatabaseRecord('quests', 0)['listNoun'])->toBe('objective')
        ->and($session->readDatabaseRecord('states', 0)['listNoun'] ?? null)->toBeNull();
});
