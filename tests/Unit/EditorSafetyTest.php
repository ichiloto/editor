<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Status\StatusLevel;

/**
 * Builds an unbooted editor with the fixture workspace loaded.
 */
function workspaceEditor(): Editor
{
  $editor = createEditorForTesting(fixturePath('sample-project'));
  setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject(fixturePath('sample-project')));
  setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);

  return $editor;
}

it('records keyboard paints and undoes them through the editor', function () {
  $editor = workspaceEditor();
  setEditorProperty($editor, 'cursorX', 1);
  setEditorProperty($editor, 'cursorY', 2);

  callEditorMethod($editor, 'replaceCurrentSymbol', '@');

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');
  $map = $workspace->getMapByIndex(0);

  expect($map->getTileSymbol(1, 2))->toBe('@')
    ->and($map->isDirty())->toBeTrue();

  /** @var CommandHistory $history */
  $history = getEditorProperty($editor, 'history');

  expect($history->count())->toBe(1);

  callEditorMethod($editor, 'performUndo');

  expect($map->getTileSymbol(1, 2))->toBe(' ');

  callEditorMethod($editor, 'performRedo');

  expect($map->getTileSymbol(1, 2))->toBe('@');
});

it('does not record a command when the paint is a no-op', function () {
  $editor = workspaceEditor();
  setEditorProperty($editor, 'cursorX', 1);
  setEditorProperty($editor, 'cursorY', 2);

  callEditorMethod($editor, 'replaceCurrentSymbol', ' ');

  /** @var CommandHistory $history */
  $history = getEditorProperty($editor, 'history');

  expect($history->count())->toBe(0);
});

it('treats direct status assignments as info toasts that never overwrite a live error', function () {
  $editor = workspaceEditor();
  setEditorProperty($editor, 'statusMessage', 'Plain update.');

  expect(getEditorProperty($editor, 'statusLevel'))->toBe(StatusLevel::INFO)
    ->and(getEditorProperty($editor, 'statusMessage'))->toBe('Plain update.');

  callEditorMethod($editor, 'setStatus', 'Something failed', StatusLevel::ERROR);

  expect(getEditorProperty($editor, 'statusLevel'))->toBe(StatusLevel::ERROR);

  // A legacy direct assignment (INFO) no longer clobbers the error.
  setEditorProperty($editor, 'statusMessage', 'Another plain update.');

  expect(getEditorProperty($editor, 'statusMessage'))->toBe('Something failed')
    ->and(getEditorProperty($editor, 'statusLevel'))->toBe(StatusLevel::ERROR);
});

it('reverts an expired status to the idle message', function () {
  $editor = workspaceEditor();
  callEditorMethod($editor, 'setStatus', 'Old news', StatusLevel::SUCCESS);

  expect(getEditorProperty($editor, 'statusMessage'))->toBe('Old news');

  /** @var \Ichiloto\Editor\Status\ToastQueue $toasts */
  $toasts = getEditorProperty($editor, 'toasts');

  expect($toasts->tick(microtime(true) + 60))->toBeTrue()
    ->and(getEditorProperty($editor, 'statusMessage'))->toBe('Ready.');
});

it('retains detail lines for the Ctrl+E overlay', function () {
  $editor = workspaceEditor();
  callEditorMethod($editor, 'setStatus', 'Saved with warnings', StatusLevel::WARN, ['A warning line.']);

  expect(getEditorProperty($editor, 'statusDetailLines'))->toBe(['A warning line.'])
    ->and(getEditorProperty($editor, 'statusDetailTitle'))->toBe('Warnings');
});

it('reports workspace-wide unsaved changes for the quit guard', function () {
  $workspace = ProjectWorkspace::fromProject(fixturePath('sample-project'));

  expect($workspace->hasUnsavedChanges())->toBeFalse();

  $workspace->getMapByIndex(0)->setTileSymbol(1, 1, 'X');

  expect($workspace->hasUnsavedChanges())->toBeTrue();
});

it('marks dirty maps in the asset list', function () {
  $workspace = ProjectWorkspace::fromProject(fixturePath('sample-project'));

  expect($workspace->getAssetLines(0))->toBe(['Maps', '> test-map']);

  $workspace->getMapByIndex(0)->setTileSymbol(1, 1, 'X');

  expect($workspace->getAssetLines(0))->toBe(['Maps', '> test-map *']);
});

it('builds the animation rows from the shared schema, an older record showing its own frames read-only', function () {
  $editor = workspaceEditor();
  setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('animations'));

  $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
  $rows = array_column($fields, null, 'label');

  expect(array_keys($rows))->toContain('Name', 'Caster Effect', 'Target Effect', 'Roles', 'Legacy Position', 'Legacy Frames')
    ->and($rows['Legacy Position']['options'])->toBe(['center', 'head', 'feet', 'screen'])
    ->and($rows['Legacy Frames']['editable'])->toBeFalse()
    ->and($rows['Target Effect']['reference'])->toBe('effects');
});

it('undoes database field edits against the pinned entry', function () {
  $editor = workspaceEditor();
  setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('animations'));

  $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
  $nameField = $fields[array_search('Name', array_column($fields, 'label'), true)];

  callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $nameField, 'Mega Slash');

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');
  $animation = $workspace->getRecordDatabase('animations')->getRecordByIndex(0);

  expect($animation->get('name'))->toBe('Mega Slash');

  // Move the selection elsewhere: undo must still hit animation 0.
  setEditorProperty($editor, 'databaseSelectedRecordIndexes', ['animations' => 5]);

  /** @var CommandHistory $history */
  $history = getEditorProperty($editor, 'history');
  $history->undo();

  expect($animation->get('name'))->toBe('Slash')
    ->and(getEditorProperty($editor, 'databaseSelectedRecordIndexes'))->toBe(['animations' => 5]);
});
