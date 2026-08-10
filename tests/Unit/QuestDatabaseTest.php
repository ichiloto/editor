<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\ProjectQuestDatabase;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Quests\Quest;

/**
 * Builds an unbooted editor with the fixture workspace and the Quests
 * category selected.
 */
function questsEditor(): \Ichiloto\Editor\Editor
{
  $editor = createEditorForTesting(fixturePath('sample-project'));
  setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject(fixturePath('sample-project')));
  setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
  setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('quests'));

  return $editor;
}

/**
 * Copies the fixture project into a scratch directory so saves never touch
 * the shared fixture.
 */
function scratchQuestProject(): string
{
  $root = sys_get_temp_dir() . '/ichiloto-quests-' . uniqid();
  mkdir($root . '/assets/Data', 0777, true);
  copy(fixturePath('sample-project/ichiloto.json'), $root . '/ichiloto.json');
  copy(fixturePath('sample-project/assets/Data/quests.php'), $root . '/assets/Data/quests.php');

  return $root;
}

it('loads the quests from the fixture project', function () {
  $database = ProjectQuestDatabase::fromProject(fixturePath('sample-project'));
  $quests = $database->getQuests();

  expect($quests)->toHaveCount(2)
    ->and($quests[0]->getId())->toBe('breakfast-duty')
    ->and($quests[0]->getRewardGold())->toBe(200)
    ->and($quests[1]->getPrerequisites())->toBe([
      ['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'completed'],
    ])
    ->and($database->isDirty())->toBeFalse();
});

it('tolerates a missing quests.php and creates it on first save', function () {
  $root = sys_get_temp_dir() . '/ichiloto-quests-empty-' . uniqid();
  mkdir($root, 0777, true);
  $database = ProjectQuestDatabase::fromProject($root);

  expect($database->getQuests())->toBe([]);

  $index = $database->addQuest();
  $database->setField($index, 'objective0Target', 'Mom');
  $database->save();

  expect(is_file($root . '/assets/Data/quests.php'))->toBeTrue()
    ->and($database->isDirty())->toBeFalse();

  $payload = require $root . '/assets/Data/quests.php';

  expect($payload[0]['id'])->toBe('new-quest')
    ->and(Quest::fromArray($payload[0]))->toBeInstanceOf(Quest::class);
});

it('round-trips the example file shape losslessly through save', function () {
  $root = scratchQuestProject();
  $original = require $root . '/assets/Data/quests.php';

  $database = ProjectQuestDatabase::fromProject($root);
  $database->save();

  $saved = require $root . '/assets/Data/quests.php';

  expect($saved)->toBe($original);

  foreach ($saved as $entry) {
    expect(Quest::fromArray($entry))->toBeInstanceOf(Quest::class);
  }
});

it('preserves the quest file prologue, header, and unedited fields', function () {
  $root = scratchQuestProject();
  $path = $root . '/assets/Data/quests.php';
  $quests = require $path;
  $quests[0]['productionMetadata'] = ['owner' => 'narrative', 'revision' => 3];
  $header = <<<'PHP'
<?php

declare(strict_types=1);

// Production quest definitions. Keep this file-level header.

PHP;
  file_put_contents($path, $header . 'return ' . var_export($quests, true) . ";\n");
  $original = require $path;

  $database = ProjectQuestDatabase::fromProject($root);
  $database->save();

  $savedSource = (string) file_get_contents($path);
  $saved = require $path;
  $reloaded = ProjectQuestDatabase::fromProject($root);

  expect($saved)->toBe($original)
    ->and($savedSource)->toStartWith($header)
    ->and($savedSource)->toContain('// Production quest definitions. Keep this file-level header.')
    ->and($saved[0]['productionMetadata'])->toBe(['owner' => 'narrative', 'revision' => 3])
    ->and(array_map(
      static fn(\Ichiloto\Editor\ProjectQuest $quest): array => $quest->toArray(),
      $reloaded->getQuests(),
    ))->toBe($original);
});

it('builds the quest settings fields with the five objective types', function () {
  // Regression guard mirroring the AnimationTargetPosition test: the field
  // builder must resolve QuestObjectiveType and flatten the objectives.
  $editor = questsEditor();

  $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
  $labels = array_column($fields, 'label');
  $typeField = $fields[array_search('Obj 1 Type', $labels, true)];

  expect($labels)->toContain('Id', 'Name', 'Description', 'Giver', 'Reward Gold', 'Reward EXP', 'Reward Items', 'Prereqs', 'Obj 1 Type', 'Obj 1 Target', 'Obj 1 Qty', 'Obj 1 Text', 'Obj 2 Type')
    ->and($typeField['options'])->toBe(['talk_to', 'collect', 'defeat', 'reach_map', 'flag'])
    ->and($typeField['value'])->toBe('reach_map');
});

it('edits, saves, and reloads a quest through the editor', function () {
  $root = scratchQuestProject();
  $editor = createEditorForTesting($root);
  setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
  setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
  setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('quests'));

  $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
  $labels = array_column($fields, 'label');

  callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields[array_search('Name', $labels, true)], 'Morning Errand');
  callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields[array_search('Reward Gold', $labels, true)], '350');
  callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields[array_search('Obj 1 Target', $labels, true)], 'happyville/plaza');

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');

  expect($workspace->questDatabase->isDirty())->toBeTrue()
    ->and(callEditorMethod($editor, 'isDatabaseCategoryDirty', 'quests'))->toBeTrue();

  $workspace->questDatabase->save();

  expect($workspace->questDatabase->isDirty())->toBeFalse();

  $reloaded = ProjectQuestDatabase::fromProject($root);
  $quest = $reloaded->getQuestByIndex(0);

  expect($quest->getName())->toBe('Morning Errand')
    ->and($quest->getRewardGold())->toBe(350)
    ->and($quest->getObjectives()[0]['target'])->toBe('happyville/plaza')
    ->and($quest->getObjectives()[0]['description'])->toBe('Visit the Happyville town center');

  foreach ($reloaded->getQuests() as $entry) {
    expect(Quest::fromArray($entry->toArray()))->toBeInstanceOf(Quest::class);
  }
});

it('undoes quest field edits against the pinned entry', function () {
  $editor = questsEditor();

  $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
  $labels = array_column($fields, 'label');

  callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields[array_search('Giver', $labels, true)], 'Grandma');

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');
  $quest = $workspace->questDatabase->getQuestByIndex(0);

  expect($quest->getGiver())->toBe('Grandma');

  // Move the selection elsewhere: undo must still hit quest 0.
  setEditorProperty($editor, 'databaseSelectedQuestIndex', 1);

  /** @var CommandHistory $history */
  $history = getEditorProperty($editor, 'history');
  $history->undo();

  expect($quest->getGiver())->toBe('Mom')
    ->and(getEditorProperty($editor, 'databaseSelectedQuestIndex'))->toBe(1);

  $history->redo();

  expect($quest->getGiver())->toBe('Grandma');
});

it('adds and removes objectives with undo support', function () {
  $editor = questsEditor();

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');
  $quest = $workspace->questDatabase->getQuestByIndex(0);

  expect($quest->getObjectives())->toHaveCount(2);

  // The handlers repaint the Database panes; swallow the ANSI output.
  ob_start();
  callEditorMethod($editor, 'addDatabaseQuestObjective');

  expect($quest->getObjectives())->toHaveCount(3)
    ->and($quest->getObjectives()[2]['type'])->toBe('talk_to');

  /** @var CommandHistory $history */
  $history = getEditorProperty($editor, 'history');
  $history->undo();

  expect($quest->getObjectives())->toHaveCount(2);

  $history->redo();

  expect($quest->getObjectives())->toHaveCount(3);

  callEditorMethod($editor, 'removeDatabaseQuestObjective');
  ob_end_clean();

  expect($quest->getObjectives())->toHaveCount(2);

  $history->undo();

  expect($quest->getObjectives())->toHaveCount(3);
});

it('round-trips prerequisites through the one-line editable form', function () {
  $editor = questsEditor();
  setEditorProperty($editor, 'databaseSelectedQuestIndex', 1);

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');
  $quest = $workspace->questDatabase->getQuestByIndex(1);

  expect($quest->getPrerequisitesString())->toBe('quest:breakfast-duty:completed');

  $quest->setField('prerequisites', 'quest:breakfast-duty:active; !switch:dark-mode; item:Rusty Key:2');

  expect($quest->getPrerequisites())->toBe([
    ['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'active'],
    ['type' => 'switch', 'name' => 'dark-mode', 'negate' => true],
    ['type' => 'item', 'name' => 'Rusty Key', 'quantity' => 2],
  ]);

  $quest->setField('prerequisites', '');

  expect($quest->getPrerequisites())->toBe([])
    ->and(array_key_exists('prerequisites', $quest->toArray()))->toBeFalse();
});
