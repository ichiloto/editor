<?php

declare(strict_types=1);

use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\History\PaintStrokeCommand;
use Ichiloto\Editor\ProjectMap;

/**
 * Builds a command that mutates a shared value holder.
 */
function trackedCommand(ArrayObject $state, string $key, mixed $old, mixed $new): GenericCommand
{
  return new GenericCommand(
    "$key change",
    static function () use ($state, $key, $new): void {
      $state[$key] = $new;
    },
    static function () use ($state, $key, $old): void {
      $state[$key] = $old;
    },
  );
}

/**
 * Builds a small in-memory map for stroke tests.
 */
function strokeTestMap(): ProjectMap
{
  return new ProjectMap(
    mapId: 'stroke-map',
    directory: '/virtual/Maps/stroke-map',
    dataPath: '/virtual/Maps/stroke-map/stroke-map.data.php',
    mapPath: '/virtual/Maps/stroke-map/stroke-map.map.php',
    eventPath: '/virtual/Maps/stroke-map/stroke-map.event.php',
    data: ['name' => 'Stroke Map', 'events' => []],
    tileLines: ['....', '....', '....'],
    eventLines: ['    ', '    ', '    '],
  );
}

it('undoes and redoes a recorded command', function () {
  $state = new ArrayObject(['value' => 'old']);
  $history = new CommandHistory();
  $state['value'] = 'new';
  $history->record(trackedCommand($state, 'value', 'old', 'new'));

  expect($history->undo())->not->toBeNull()
    ->and($state['value'])->toBe('old')
    ->and($history->redo())->not->toBeNull()
    ->and($state['value'])->toBe('new');
});

it('returns null when there is nothing to undo or redo', function () {
  $history = new CommandHistory();

  expect($history->undo())->toBeNull()
    ->and($history->redo())->toBeNull()
    ->and($history->canUndo())->toBeFalse()
    ->and($history->canRedo())->toBeFalse();
});

it('clears the redo stack when a new command is recorded', function () {
  $state = new ArrayObject(['value' => 0]);
  $history = new CommandHistory();
  $history->record(trackedCommand($state, 'value', 0, 1));
  $history->undo();

  expect($history->canRedo())->toBeTrue();

  $history->record(trackedCommand($state, 'value', 0, 2));

  expect($history->canRedo())->toBeFalse()
    ->and($history->redo())->toBeNull();
});

it('evicts the oldest entries past the capacity', function () {
  $state = new ArrayObject(['value' => 0]);
  $history = new CommandHistory(capacity: 3);

  foreach ([1, 2, 3, 4, 5] as $step) {
    $history->record(trackedCommand($state, 'value', $step - 1, $step));
  }

  expect($history->count())->toBe(3);

  $history->undo();
  $history->undo();
  $history->undo();

  // Only the newest three survive, so undo bottoms out at value 2.
  expect($state['value'])->toBe(2)
    ->and($history->canUndo())->toBeFalse();
});

it('supports undoing multiple commands in reverse order', function () {
  $state = new ArrayObject(['value' => 'a']);
  $history = new CommandHistory();
  $state['value'] = 'b';
  $history->record(trackedCommand($state, 'value', 'a', 'b'));
  $state['value'] = 'c';
  $history->record(trackedCommand($state, 'value', 'b', 'c'));

  $history->undo();
  expect($state['value'])->toBe('b');
  $history->undo();
  expect($state['value'])->toBe('a');
});

it('empties both stacks on clear', function () {
  $state = new ArrayObject(['value' => 0]);
  $history = new CommandHistory();
  $history->record(trackedCommand($state, 'value', 0, 1));
  $history->undo();
  $history->clear();

  expect($history->canUndo())->toBeFalse()
    ->and($history->canRedo())->toBeFalse()
    ->and($history->count())->toBe(0);
});

it('coalesces a drag stroke into one undoable command', function () {
  $map = strokeTestMap();
  $history = new CommandHistory();
  $stroke = new PaintStrokeCommand($map, PaintStrokeCommand::LAYER_TILE, 'Paint stroke');

  foreach ([[0, 0], [1, 0], [2, 0]] as [$x, $y]) {
    $old = $map->getTileSymbol($x, $y);
    $map->setTileSymbol($x, $y, '#');
    $stroke->appendCell($x, $y, $old, '#');
  }

  $history->record($stroke);

  expect($history->count())->toBe(1)
    ->and($map->getTileSymbol(1, 0))->toBe('#');

  $history->undo();

  expect($map->getTileSymbol(0, 0))->toBe('.')
    ->and($map->getTileSymbol(1, 0))->toBe('.')
    ->and($map->getTileSymbol(2, 0))->toBe('.');

  $history->redo();

  expect($map->getTileSymbol(2, 0))->toBe('#');
});

it('keeps the first old symbol when a stroke revisits a cell', function () {
  $map = strokeTestMap();
  $stroke = new PaintStrokeCommand($map, PaintStrokeCommand::LAYER_TILE);

  $stroke->appendCell(1, 1, '.', '#');
  $stroke->appendCell(1, 1, '#', '@');

  expect($stroke->getCellCount())->toBe(1);

  $map->setTileSymbol(1, 1, '@');
  $stroke->undo();

  expect($map->getTileSymbol(1, 1))->toBe('.');
});

it('reports no changes when a stroke paints identical symbols', function () {
  $map = strokeTestMap();
  $stroke = new PaintStrokeCommand($map, PaintStrokeCommand::LAYER_TILE);
  $stroke->appendCell(0, 0, '.', '.');

  expect($stroke->hasChanges())->toBeFalse();

  $stroke->appendCell(1, 0, '.', '#');

  expect($stroke->hasChanges())->toBeTrue();
});

it('paints the event layer when constructed for events', function () {
  $map = strokeTestMap();
  $stroke = new PaintStrokeCommand($map, PaintStrokeCommand::LAYER_EVENT, 'Event stroke');
  $map->setEventSymbol(2, 1, 'E');
  $stroke->appendCell(2, 1, ' ', 'E');

  $stroke->undo();

  expect($map->getEventSymbol(2, 1))->toBe(' ')
    ->and($map->getTileSymbol(2, 1))->toBe('.');

  $stroke->execute();

  expect($map->getEventSymbol(2, 1))->toBe('E');
});
