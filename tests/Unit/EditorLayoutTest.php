<?php

declare(strict_types=1);

/**
 * Builds an unbooted editor pinned to a fixed terminal size.
 */
function layoutEditor(int $width, int $height): \Ichiloto\Editor\Editor
{
  $editor = createEditorForTesting(fixturePath('sample-project'));
  setEditorProperty($editor, 'lastTerminalSize', ['width' => $width, 'height' => $height]);

  return $editor;
}

it('derives the three-pane layout from the terminal size', function () {
  $layout = callEditorMethod(layoutEditor(180, 50), 'resolveLayout');

  expect($layout['width'])->toBe(180)
    ->and($layout['height'])->toBe(50)
    ->and($layout['leftWidth'])->toBe(32)
    ->and($layout['rightWidth'])->toBe(34)
    ->and($layout['gutter'])->toBe(1)
    ->and($layout['centerWidth'])->toBe(180 - 32 - 34 - 4)
    ->and($layout['contentHeight'])->toBe(50 - 8);
});

it('clamps the layout at the minimum supported terminal size', function () {
  $layout = callEditorMethod(layoutEditor(80, 24), 'resolveLayout');

  expect($layout['centerWidth'])->toBe(30)
    ->and($layout['contentHeight'])->toBe(16);
});

it('memoizes the layout until the terminal size changes', function () {
  $editor = layoutEditor(120, 40);
  $first = callEditorMethod($editor, 'resolveLayout');
  $second = callEditorMethod($editor, 'resolveLayout');

  expect($second)->toBe($first);

  setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
  $resized = callEditorMethod($editor, 'resolveLayout');

  expect($resized['centerWidth'])->toBe($first['centerWidth'] + 20);
});

it('reserves border and padding inside window content widths', function () {
  $editor = layoutEditor(120, 40);

  expect(callEditorMethod($editor, 'getWindowContentWidth', 34))->toBe(30)
    ->and(callEditorMethod($editor, 'getWindowContentWidth', 3))->toBe(1);
});

it('fits lines by truncating and padding to the viewport', function () {
  $editor = layoutEditor(120, 40);
  $lines = callEditorMethod($editor, 'fitLines', ['abcdefgh', 'ij'], 4, 4);

  expect($lines)->toBe(['abcd', 'ij', '', '']);
});
