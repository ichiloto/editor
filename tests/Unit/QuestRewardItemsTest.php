<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectQuestDatabase;
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

it('shows each reward item as a picked row, not a line to type', function () {
    $root = makeTemporaryProject();
    $database = ProjectQuestDatabase::fromProject($root);
    $database->getQuestByIndex(0)->setRewardItems(['S-Potion', 'Wooden Sword']);
    $database->save();

    $editor = editorOnQuests($root);
    $byField = [];

    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $field) {
        $byField[(string) ($field['field'] ?? '')] = $field;
    }

    // Names resolve through the engine's item store, so a misspelling is a
    // reward that silently never arrives. Chosen, never spelled.
    expect($byField['rewardItem0']['reference'] ?? null)->toBe('inventory')
        ->and($byField['rewardItem0']['value'] ?? null)->toBe('S-Potion')
        ->and($byField['rewardItem1']['value'] ?? null)->toBe('Wooden Sword')
        ->and($byField['rewardItem0'] ?? [])->not->toHaveKey('control')
        ->and($byField)->not->toHaveKey('rewardItems');
});

it('adds a reward slot from the reward rows and removes it again', function () {
    $root = makeTemporaryProject();
    $editor = editorOnQuests($root);

    // Adding the first slot works from the Reward Gold row, since there is
    // no reward row to stand on yet.
    selectQuestField($editor, 'rewardGold');
    callEditorMethod($editor, 'addDatabaseQuestObjective');

    $quest = getEditorProperty($editor, 'workspace')->questDatabase->getQuestByIndex(0);

    expect($quest->getRewardItems())->toHaveCount(1);

    selectQuestField($editor, 'rewardItem0');
    callEditorMethod($editor, 'removeDatabaseQuestObjective');

    expect($quest->getRewardItems())->toBe([]);
});

it('still adds an objective when the cursor is not on a reward row', function () {
    $root = makeTemporaryProject();
    $editor = editorOnQuests($root);
    $quest = getEditorProperty($editor, 'workspace')->questDatabase->getQuestByIndex(0);
    $objectivesBefore = count($quest->getObjectives());

    selectQuestField($editor, 'name');
    callEditorMethod($editor, 'addDatabaseQuestObjective');

    expect(count($quest->getObjectives()))->toBe($objectivesBefore + 1)
        ->and($quest->getRewardItems())->toBe([]);
});

it('puts back what undo removed, in the same slot', function () {
    $root = makeTemporaryProject();
    $editor = editorOnQuests($root);
    $database = getEditorProperty($editor, 'workspace')->questDatabase;
    $database->getQuestByIndex(0)->setRewardItems(['S-Potion', 'Wooden Sword', 'S-Mana']);

    selectQuestField($editor, 'rewardItem1');
    callEditorMethod($editor, 'removeDatabaseQuestObjective');

    expect($database->getQuestByIndex(0)->getRewardItems())->toBe(['S-Potion', 'S-Mana']);

    callEditorMethod($editor, 'performUndo');

    expect($database->getQuestByIndex(0)->getRewardItems())->toBe(['S-Potion', 'Wooden Sword', 'S-Mana']);
});

it('round-trips picked rewards through the saved file', function () {
    $root = makeTemporaryProject();
    $database = ProjectQuestDatabase::fromProject($root);
    $quest = $database->getQuestByIndex(0);
    $quest->addRewardItem();
    $quest->setField('rewardItem0', 'Wooden Sword');
    $database->save();

    $reloaded = ProjectQuestDatabase::fromProject($root);

    expect($reloaded->getQuestByIndex(0)->getRewardItems())->toBe(['Wooden Sword']);
});

it('drops the items key entirely when the last reward goes', function () {
    $quest = ProjectQuestDatabase::fromProject(makeTemporaryProject())->getQuestByIndex(0);
    $quest->setRewardItems(['S-Potion']);
    $quest->removeRewardItem(0);

    // An empty list would read as "rewards nothing, explicitly"; absence is
    // what the authored files use.
    expect($quest->getRewards())->not->toHaveKey('items');
});
