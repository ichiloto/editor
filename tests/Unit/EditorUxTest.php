<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Inspector\InputControl;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Builds an unbooted editor with the fixture workspace loaded, pinned to a
 * fixed terminal size (the Phase 4 input/UX coherence tests).
 */
function uxEditor(): Editor
{
  $editor = createEditorForTesting(fixturePath('sample-project'));
  setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject(fixturePath('sample-project')));
  setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
  setEditorProperty($editor, 'isRunning', true);

  return $editor;
}

it('paints ! on the canvas instead of opening the Database', function () {
  $editor = uxEditor();
  setEditorProperty($editor, 'focusedPane', 'canvas');
  setEditorProperty($editor, 'cursorX', 1);
  setEditorProperty($editor, 'cursorY', 1);
  callEditorMethod($editor, 'dispatchInput', '!');

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');

  expect($workspace->getMapByIndex(0)->getTileSymbol(1, 1))->toBe('!')
    ->and(getEditorProperty($editor, 'isDatabaseOpen'))->toBeFalse();
});

it('paints h, j, k, and l as glyphs — the movement aliases are gone', function () {
  $editor = uxEditor();
  setEditorProperty($editor, 'focusedPane', 'canvas');

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');

  foreach (['h', 'j', 'k', 'l'] as $column => $glyph) {
    setEditorProperty($editor, 'cursorX', $column);
    setEditorProperty($editor, 'cursorY', 2);
    callEditorMethod($editor, 'dispatchInput', $glyph);

    expect($workspace->getMapByIndex(0)->getTileSymbol($column, 2))->toBe($glyph)
      ->and(getEditorProperty($editor, 'cursorX'))->toBe($column)
      ->and(getEditorProperty($editor, 'cursorY'))->toBe(2);
  }
});

it('opens the Database with Ctrl+D and toggles it closed again', function () {
  $editor = uxEditor();
  callEditorMethod($editor, 'dispatchInput', "\x04");

  expect(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue();

  callEditorMethod($editor, 'dispatchInput', "\x04");

  expect(getEditorProperty($editor, 'isDatabaseOpen'))->toBeFalse();
});

it('opens the Database with both F2 encodings', function () {
  foreach (["\033OQ", "\033[12~"] as $sequence) {
    $editor = uxEditor();
    callEditorMethod($editor, 'dispatchInput', $sequence);

    expect(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue();
  }
});

it('pops exactly one level per Esc: settings edit, then the Database screen', function () {
  $editor = uxEditor();
  callEditorMethod($editor, 'dispatchInput', "\x04");
  setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('quests'));
  setEditorProperty($editor, 'databaseFocus', 'database_settings');
  // A quest's id is derived rather than typed, so the name is the first
  // field there is anything to pop out of.
  setEditorProperty($editor, 'databaseSelectedSettingIndex', 1);
  callEditorMethod($editor, 'dispatchInput', "\n");

  expect(getEditorProperty($editor, 'isDatabaseEditing'))->toBeTrue();

  callEditorMethod($editor, 'dispatchInput', "\033");

  expect(getEditorProperty($editor, 'isDatabaseEditing'))->toBeFalse()
    ->and(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue();

  callEditorMethod($editor, 'dispatchInput', "\033");

  expect(getEditorProperty($editor, 'isDatabaseOpen'))->toBeFalse();
});

it('pops exactly one level per Esc: inspector edit before anything else', function () {
  $editor = uxEditor();
  setEditorProperty($editor, 'focusedPane', 'inspector');
  callEditorMethod($editor, 'dispatchInput', "\n");

  expect(getEditorProperty($editor, 'isInspectorEditing'))->toBeTrue();

  callEditorMethod($editor, 'dispatchInput', "\033");

  expect(getEditorProperty($editor, 'isInspectorEditing'))->toBeFalse()
    ->and(getEditorProperty($editor, 'isRunning'))->toBeTrue();
});

it('opens help with ? and closes only the help layer with Esc', function () {
  $editor = uxEditor();
  callEditorMethod($editor, 'dispatchInput', "\x04");
  callEditorMethod($editor, 'dispatchInput', '?');

  expect(getEditorProperty($editor, 'isHelpOpen'))->toBeTrue()
    ->and(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue();

  callEditorMethod($editor, 'dispatchInput', "\033");

  expect(getEditorProperty($editor, 'isHelpOpen'))->toBeFalse()
    ->and(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue();
});

it('derives the help overlay from the live binding tables', function () {
  $editor = uxEditor();

  /** @var \Ichiloto\Editor\IO\InputRouter $router */
  $router = getEditorProperty($editor, 'inputRouter');
  $entries = $router->describeBindings();
  $helpText = implode("\n", callEditorMethod($editor, 'getHelpLines'));

  expect($entries)->not->toBe([]);

  // Every documented binding must appear verbatim — the overlay cannot go
  // stale against the dispatch tables.
  foreach ($entries as $entry) {
    expect($helpText)->toContain($entry['key'])
      ->and($helpText)->toContain($entry['description']);
  }

  expect($helpText)->toContain('Ctrl+D / F2')
    ->and($helpText)->toContain('Ctrl+P');
});

it('jumps to a database category through the command palette', function () {
  $editor = uxEditor();
  callEditorMethod($editor, 'dispatchInput', "\x10");

  expect(getEditorProperty($editor, 'isCommandPaletteOpen'))->toBeTrue();

  foreach (str_split('quests') as $symbol) {
    callEditorMethod($editor, 'dispatchInput', $symbol);
  }

  callEditorMethod($editor, 'dispatchInput', "\n");

  expect(getEditorProperty($editor, 'isCommandPaletteOpen'))->toBeFalse()
    ->and(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue()
    ->and(getEditorProperty($editor, 'databaseCategoryIndex'))->toBe(DatabaseCatalog::indexOf('quests'));
});

it('jumps to an event marker through the command palette', function () {
  $editor = uxEditor();
  callEditorMethod($editor, 'dispatchInput', "\x10");

  foreach (str_split('event: e') as $symbol) {
    callEditorMethod($editor, 'dispatchInput', $symbol);
  }

  callEditorMethod($editor, 'dispatchInput', "\n");

  expect(getEditorProperty($editor, 'editingMode'))->toBe('event')
    ->and(getEditorProperty($editor, 'focusedPane'))->toBe('canvas')
    ->and(getEditorProperty($editor, 'cursorX'))->toBe(5)
    ->and(getEditorProperty($editor, 'cursorY'))->toBe(1);
});

it('closes the command palette one level with Esc', function () {
  $editor = uxEditor();
  callEditorMethod($editor, 'dispatchInput', "\x10");
  callEditorMethod($editor, 'dispatchInput', "\033");

  expect(getEditorProperty($editor, 'isCommandPaletteOpen'))->toBeFalse()
    ->and(getEditorProperty($editor, 'isRunning'))->toBeTrue();
});

it('defaults the delete confirmation to Cancel: Enter does not delete', function () {
  $editor = uxEditor();
  setEditorProperty($editor, 'focusedPane', 'assets');
  callEditorMethod($editor, 'dispatchInput', "\033[3~");

  expect(getEditorProperty($editor, 'isDeleteConfirmationOpen'))->toBeTrue();

  callEditorMethod($editor, 'dispatchInput', "\n");

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');

  expect(getEditorProperty($editor, 'isDeleteConfirmationOpen'))->toBeFalse()
    ->and($workspace->mapIds)->toBe(['test-map']);
});

it('defaults the unsaved-changes guard to Cancel: Enter does not discard', function () {
  $editor = uxEditor();

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');
  $workspace->getMapByIndex(0)->setTileSymbol(0, 0, '#');

  callEditorMethod($editor, 'dispatchInput', "\x11");

  expect(getEditorProperty($editor, 'isUnsavedChangesGuardOpen'))->toBeTrue();

  callEditorMethod($editor, 'dispatchInput', "\n");

  expect(getEditorProperty($editor, 'isUnsavedChangesGuardOpen'))->toBeFalse()
    ->and(getEditorProperty($editor, 'isRunning'))->toBeTrue();

  // The explicit y still confirms.
  callEditorMethod($editor, 'dispatchInput', "\x11");
  callEditorMethod($editor, 'dispatchInput', 'y');

  expect(getEditorProperty($editor, 'isRunning'))->toBeFalse();
});

it('steps integer inspector fields with the Left/Right idiom and records undo', function () {
  $editor = uxEditor();
  setEditorProperty($editor, 'focusedPane', 'inspector');
  setEditorProperty($editor, 'selectedInspectorFieldIndex', 4); // Size X (width)

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');
  $originalWidth = $workspace->getMapByIndex(0)->getWidth();

  callEditorMethod($editor, 'dispatchInput', "\033[C");

  expect($workspace->getMapByIndex(0)->getWidth())->toBe($originalWidth + 1);

  callEditorMethod($editor, 'dispatchInput', "\x1a");

  expect($workspace->getMapByIndex(0)->getWidth())->toBe($originalWidth);
});

it('builds boolean and float controls for event data values', function () {
  $editor = uxEditor();
  $fields = callEditorMethod($editor, 'flattenInspectorFields', ['locked' => true, 'rate' => 1.5], ['data']);
  $byLabel = array_column($fields, null, 'label');

  expect($byLabel['Locked']['control']->type)->toBe(InputControlType::BOOLEAN)
    ->and($byLabel['Locked']['value'])->toBe('true')
    ->and($byLabel['Rate']['control']->type)->toBe(InputControlType::FLOAT)
    ->and($byLabel['Rate']['value'])->toBe('1.5');
});

it('toggles booleans and steps floats through InputControl::adjust', function () {
  $boolean = new InputControl(InputControlType::BOOLEAN, 'true');

  expect($boolean->adjust('true', 1))->toBe('false')
    ->and($boolean->adjust('false', -1))->toBe('true')
    ->and($boolean->acceptsTypedInput())->toBeFalse();

  $float = new InputControl(InputControlType::FLOAT, '1.5');

  expect($float->adjust('1.5', 1))->toBe('2.5')
    ->and($float->adjust('1.5', -1))->toBe('0.5')
    ->and($float->acceptsSymbol('.', '1'))->toBeTrue()
    ->and($float->acceptsSymbol('.', '1.5'))->toBeFalse()
    ->and($float->acceptsSymbol('-', ''))->toBeTrue()
    ->and($float->acceptsSymbol('-', '1'))->toBeFalse();
});

it('styles visible but non-editable inspector rows distinctly', function () {
  $editor = uxEditor();
  $lines = implode("\n", callEditorMethod($editor, 'getInspectorLines'));

  expect($lines)->toMatch('/Events · \d+/')
    ->and($lines)->toMatch('/Triggers · \d+/')
    ->and($lines)->toContain('Name: Test Map');
});

it('scrolls the inspector so a deep selection stays visible', function () {
  $editor = uxEditor();
  setEditorProperty($editor, 'focusedPane', 'inspector');

  // Selection inside the window: the list starts at the first field.
  expect(callEditorMethod($editor, 'getInspectorLines')[0])->toContain('Name');

  // One row past the last visible one, whatever the pane's height works out
  // to, and the window slides by exactly that one row.
  $visibleRows = callEditorMethod($editor, 'resolveLayout')['contentHeight'] - 2;
  setEditorProperty($editor, 'selectedInspectorFieldIndex', $visibleRows);

  expect(callEditorMethod($editor, 'getInspectorLines')[0])->toContain('Region');
});

it('scrolls the assets list so the selected map stays visible', function () {
  $editor = uxEditor();

  // 120x40 → contentHeight 32 → 30 visible rows. With the header row on
  // top, a selection index past 28 slides the window.
  setEditorProperty($editor, 'selectedAssetIndex', 31);

  ob_start();

  try {
    $window = callEditorMethod($editor, 'createAssetWindow');
  } finally {
    ob_end_clean();
  }

  expect($window)->not->toBeNull();
});
