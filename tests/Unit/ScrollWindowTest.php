<?php

declare(strict_types=1);

use Ichiloto\Editor\UI\ScrollWindow;

it('keeps early selections pinned to the top', function () {
  $lines = ['a', 'b', 'c', 'd', 'e'];

  expect(ScrollWindow::slice($lines, 0, 3))->toBe($lines)
    ->and(ScrollWindow::slice($lines, 2, 3))->toBe($lines);
});

it('slides the window only once the selection passes the last visible row', function () {
  $lines = ['a', 'b', 'c', 'd', 'e'];

  expect(ScrollWindow::slice($lines, 3, 3))->toBe(['b', 'c', 'd', 'e'])
    ->and(ScrollWindow::slice($lines, 4, 3))->toBe(['c', 'd', 'e']);
});

it('lands the selected row at min(selected, visible - 1)', function () {
  // The formula the live edit cursors assume.
  foreach ([0, 1, 2, 3, 4, 9] as $selected) {
    $visible = 4;
    $offset = ScrollWindow::offset($selected, $visible);

    expect($selected - $offset)->toBe(min($selected, $visible - 1));
  }
});

it('degrades gracefully for tiny panes', function () {
  $lines = ['a', 'b', 'c'];

  expect(ScrollWindow::slice($lines, 2, 1))->toBe(['c'])
    ->and(ScrollWindow::slice($lines, 2, 0))->toBe(['c'])
    ->and(ScrollWindow::slice([], 5, 3))->toBe([]);
});
