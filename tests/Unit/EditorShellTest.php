<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\UI\CanvasPanel;
use Ichiloto\Editor\UI\DatabaseScreen;
use Ichiloto\Editor\UI\InspectorPanel;

/**
 * Builds an unbooted editor with the fixture workspace loaded, pinned to a
 * fixed terminal size (the panel/dirty-flag seam tests).
 */
function shellEditor(): Editor
{
  $editor = createEditorForTesting(fixturePath('sample-project'));
  setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject(fixturePath('sample-project')));
  setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);

  return $editor;
}

/**
 * Runs one dirty-panel flush and returns the bytes it wrote.
 */
function flushShell(Editor $editor): string
{
  ob_start();

  try {
    callEditorMethod($editor, 'flushDirtyPanels');
  } finally {
    $frame = (string) ob_get_clean();
  }

  return $frame;
}

it('marks panels dirty on mutation instead of painting immediately', function () {
  $editor = shellEditor();
  setEditorProperty($editor, 'focusedPane', 'canvas');

  /** @var CanvasPanel $canvas */
  $canvas = getEditorProperty($editor, 'canvasPanel');
  /** @var InspectorPanel $inspector */
  $inspector = getEditorProperty($editor, 'inspectorPanel');

  expect($canvas->isDirty())->toBeFalse();

  ob_start();

  try {
    callEditorMethod($editor, 'dispatchInput', '#');
  } finally {
    $paintedBytes = (string) ob_get_clean();
  }

  // The handler mutates state and marks panels; nothing hits the terminal
  // until the loop's render phase flushes.
  expect($paintedBytes)->toBe('')
    ->and($canvas->isDirty())->toBeTrue()
    ->and($inspector->isDirty())->toBeTrue();
});

it('flushes dirty panels once, then idles at zero bytes per frame', function () {
  $editor = shellEditor();
  setEditorProperty($editor, 'focusedPane', 'canvas');
  callEditorMethod($editor, 'dispatchInput', '#');

  expect(flushShell($editor))->not->toBe('');

  // With no new mutations, the next frame writes nothing at all.
  expect(flushShell($editor))->toBe('');
});

it('routes Tab through the binding table to cycle panel focus', function () {
  $editor = shellEditor();

  expect(getEditorProperty($editor, 'focusedPane'))->toBe('assets');

  callEditorMethod($editor, 'dispatchInput', "\t");

  expect(getEditorProperty($editor, 'focusedPane'))->toBe('canvas');

  callEditorMethod($editor, 'dispatchInput', "\t");

  expect(getEditorProperty($editor, 'focusedPane'))->toBe('inspector');
});

it('gates pane input by focus', function () {
  $editor = shellEditor();

  // Assets pane focused: the canvas Enter/paint handler must not fire.
  setEditorProperty($editor, 'cursorX', 1);
  setEditorProperty($editor, 'cursorY', 2);
  callEditorMethod($editor, 'dispatchInput', 'x');

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');

  expect($workspace->getMapByIndex(0)->getTileSymbol(1, 2))->toBe(' ');

  // Canvas focused: the same key paints.
  setEditorProperty($editor, 'focusedPane', 'canvas');
  callEditorMethod($editor, 'dispatchInput', 'x');

  expect($workspace->getMapByIndex(0)->getTileSymbol(1, 2))->toBe('x');
});

it('opens and closes the Database screen through the router', function () {
  $editor = shellEditor();
  callEditorMethod($editor, 'dispatchInput', "\x04");

  expect(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue();

  /** @var DatabaseScreen $screen */
  $screen = getEditorProperty($editor, 'databaseScreen');

  expect($screen->isDirty())->toBeTrue();

  callEditorMethod($editor, 'dispatchInput', "\033");

  expect(getEditorProperty($editor, 'isDatabaseOpen'))->toBeFalse();
});

it('opens the status detail overlay above the Database screen', function () {
  $editor = shellEditor();
  callEditorMethod($editor, 'dispatchInput', "\x04");
  // Ctrl+E only opens when a warn/error left detail lines behind.
  callEditorMethod(
    $editor,
    'setStatus',
    'Validation warning.',
    \Ichiloto\Editor\Status\StatusLevel::WARN,
    ['Something needs attention.'],
  );
  callEditorMethod($editor, 'dispatchInput', "\x05");

  expect(getEditorProperty($editor, 'isStatusDetailOpen'))->toBeTrue()
    ->and(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue();

  // While the safety modal is up, database keys must not reach the screen.
  $focusBefore = getEditorProperty($editor, 'databaseFocus');
  callEditorMethod($editor, 'dispatchInput', "\t");

  expect(getEditorProperty($editor, 'databaseFocus'))->toBe($focusBefore);
});

it('flushes queued Database panes while the screen is open', function () {
  $editor = shellEditor();
  callEditorMethod($editor, 'dispatchInput', "\x04");

  expect(flushShell($editor))->not->toBe('');

  /** @var DatabaseScreen $screen */
  $screen = getEditorProperty($editor, 'databaseScreen');

  expect($screen->isDirty())->toBeFalse()
    ->and(flushShell($editor))->toBe('');
});

it('still undoes a painted tile after routing through the binding table', function () {
  $editor = shellEditor();
  setEditorProperty($editor, 'focusedPane', 'canvas');
  setEditorProperty($editor, 'cursorX', 1);
  setEditorProperty($editor, 'cursorY', 2);
  callEditorMethod($editor, 'dispatchInput', '#');

  /** @var ProjectWorkspace $workspace */
  $workspace = getEditorProperty($editor, 'workspace');

  expect($workspace->getMapByIndex(0)->getTileSymbol(1, 2))->toBe('#');

  callEditorMethod($editor, 'dispatchInput', "\x1a");

  expect($workspace->getMapByIndex(0)->getTileSymbol(1, 2))->toBe(' ');

  callEditorMethod($editor, 'dispatchInput', "\x19");

  expect($workspace->getMapByIndex(0)->getTileSymbol(1, 2))->toBe('#');
});
