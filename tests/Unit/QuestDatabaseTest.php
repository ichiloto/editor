<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\ProjectQuest;
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

/** The quests category of a project, through the shared record service. */
function questRecords(string $root): ProjectRecordDatabase
{
  return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('quests'));
}

/** @return list<ProjectQuest> */
function questViews(ProjectRecordDatabase $database): array
{
  return array_map(static fn($record): ProjectQuest => new ProjectQuest((array) $record->toArray()), $database->getRecords());
}

/**
 * Copies the fixture project into a scratch directory so saves never touch
 * the shared fixture.
 */
function scratchQuestProject(): string
{
  $root = rememberTemporaryProject(sys_get_temp_dir() . '/ichiloto-quests-' . uniqid());
  mkdir($root . '/assets/Data', 0777, true);
  copy(fixturePath('sample-project/ichiloto.json'), $root . '/ichiloto.json');
  copy(fixturePath('sample-project/assets/Data/quests.php'), $root . '/assets/Data/quests.php');

  return $root;
}

it('loads the quests from the fixture project', function () {
  $database = questRecords(fixturePath('sample-project'));
  $quests = questViews($database);

  expect($quests)->toHaveCount(2)
    ->and($quests[0]->getId())->toBe('breakfast-duty')
    ->and($quests[0]->getRewardGold())->toBe(200)
    ->and($quests[1]->getPrerequisites())->toBe([
      ['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'completed'],
    ])
    ->and($database->isDirty())->toBeFalse();
});

it('tolerates a missing quests.php and creates it on first save', function () {
  $root = rememberTemporaryProject(sys_get_temp_dir() . '/ichiloto-quests-empty-' . uniqid());
  mkdir($root, 0777, true);
  $database = questRecords($root);

  expect($database->getRecords())->toBe([]);

  $index = $database->addRecord();
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
  $original = (string) file_get_contents($root . '/assets/Data/quests.php');

  $database = questRecords($root);
  $database->setField(0, 'giver', 'Grandma');
  $database->setField(0, 'giver', 'Mom');
  $database->save();

  expect((string) file_get_contents($root . '/assets/Data/quests.php'))->toBe($original);

  foreach (require $root . '/assets/Data/quests.php' as $entry) {
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

  $database = questRecords($root);
  $database->setField(1, 'giver', 'Noticeboard');
  $database->save();

  $savedSource = (string) file_get_contents($path);
  $saved = require $path;
  $original[1]['giver'] = 'Noticeboard';

  expect($saved)->toBe($original)
    ->and($savedSource)->toStartWith($header)
    ->and($saved[0]['productionMetadata'])->toBe(['owner' => 'narrative', 'revision' => 3]);
});

it('builds the quest settings fields with the five objective types', function () {
  $editor = questsEditor();

  $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
  $labels = array_column($fields, 'label');
  $typeField = $fields[array_search('Objective 1 Type', $labels, true)];

  expect($labels)->toContain('Id', 'Name', 'Description', 'Giver', 'Optional', 'Reward Gold', 'Reward EXP', 'Prereqs',
      'Objective 1 Type', 'Objective 1 Target', 'Objective 1 Quantity', 'Objective 1 Text', 'Objective 1 Revealed',
      'Objective 1 Reveal When', 'Objective 2 Type', 'Reward Items')
    ->and($typeField['options'])->toBe(['talk_to', 'collect', 'defeat', 'reach_map', 'flag'])
    ->and($typeField['value'])->toBe('reach_map')
    // The target is picked from what the type asks for: a map to reach.
    ->and($fields[array_search('Objective 1 Target', $labels, true)]['reference'] ?? null)->toBe('maps');
});

it('edits, saves, and reloads a quest through the editor', function () {
  $root = scratchQuestProject();
  $editor = createEditorForTesting($root);
  setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
  setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
  setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('quests'));

  $field = static function (string $label) use ($editor): array {
    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');

    return $fields[array_search($label, array_column($fields, 'label'), true)];
  };

  callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $field('Name'), 'Morning Errand');
  callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $field('Reward Gold'), '350');
  callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $field('Objective 1 Target'), 'happyville/plaza');
  callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $field('Objective 1 Revealed'), 'Return to the east gate');
  callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $field('Objective 1 Reveal When'), 'event:east_gate_identified');

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');
  $database = $workspace->getRecordDatabase('quests');

  expect($database->isDirty())->toBeTrue()
    ->and(callEditorMethod($editor, 'isDatabaseCategoryDirty', 'quests'))->toBeTrue();

  $database->save();

  expect($database->isDirty())->toBeFalse();

  $quest = questViews(questRecords($root))[0];

  expect($quest->getName())->toBe('Morning Errand')
    // Something points at breakfast-duty, so its id stayed.
    ->and($quest->getId())->toBe('breakfast-duty')
    ->and($quest->getRewardGold())->toBe(350)
    ->and($quest->getObjectives()[0]['target'])->toBe('happyville/plaza')
    ->and($quest->getObjectives()[0]['description'])->toBe('Visit the Happyville town center')
    ->and($quest->getObjectives()[0]['revealedDescription'])->toBe('Return to the east gate')
    ->and($quest->getObjectives()[0]['revealConditions'])->toBe([['type' => 'event', 'name' => 'east_gate_identified']]);

  foreach (require $root . '/assets/Data/quests.php' as $entry) {
    expect(Quest::fromArray($entry))->toBeInstanceOf(Quest::class);
  }
});

it('undoes quest field edits against the pinned entry', function () {
  $editor = questsEditor();

  $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
  $labels = array_column($fields, 'label');

  callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields[array_search('Giver', $labels, true)], 'Grandma');

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');
  $record = $workspace->getRecordDatabase('quests')->getRecordByIndex(0);

  expect($record->get('giver'))->toBe('Grandma');

  // Move the selection elsewhere: undo must still hit quest 0.
  setEditorProperty($editor, 'databaseSelectedRecordIndexes', ['quests' => 1]);

  /** @var CommandHistory $history */
  $history = getEditorProperty($editor, 'history');
  $history->undo();

  expect($record->get('giver'))->toBe('Mom')
    ->and(getEditorProperty($editor, 'databaseSelectedRecordIndexes')['quests'])->toBe(1);

  $history->redo();

  expect($record->get('giver'))->toBe('Grandma');
});

it('adds and removes objectives with undo support', function () {
  $editor = questsEditor();

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');
  $database = $workspace->getRecordDatabase('quests');
  $objectives = static fn(): array => $database->getSubItems(0);

  expect($objectives())->toHaveCount(2);

  // The handlers repaint the Database panes; swallow the ANSI output.
  ob_start();
  callEditorMethod($editor, 'addDatabaseRecordSubItem');

  expect($objectives())->toHaveCount(3)
    ->and($objectives()[2]['type'])->toBe('talk_to');

  /** @var CommandHistory $history */
  $history = getEditorProperty($editor, 'history');
  $history->undo();

  expect($objectives())->toHaveCount(2);

  $history->redo();

  expect($objectives())->toHaveCount(3);

  callEditorMethod($editor, 'removeDatabaseRecordSubItem');
  ob_end_clean();

  expect($objectives())->toHaveCount(2);

  $history->undo();

  expect($objectives())->toHaveCount(3);
});

it('round-trips prerequisites through the one-line editable form', function () {
  $database = questRecords(fixturePath('sample-project'));

  expect(questViews($database)[1]->getPrerequisitesString())->toBe('quest:breakfast-duty:completed');

  $database->setField(1, 'prerequisites', 'quest:breakfast-duty:active; !switch:dark-mode; item:Rusty Key:2');

  expect(questViews($database)[1]->getPrerequisites())->toBe([
    ['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'active'],
    ['type' => 'switch', 'name' => 'dark-mode', 'negate' => true],
    ['type' => 'item', 'name' => 'Rusty Key', 'quantity' => 2],
  ]);

  $database->setField(1, 'prerequisites', '');

  expect(questViews($database)[1]->getPrerequisites())->toBe([])
    ->and(array_key_exists('prerequisites', questViews($database)[1]->toArray()))->toBeFalse();
});
