<?php

declare(strict_types=1);

use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\Status\Toast;
use Ichiloto\Editor\Status\ToastQueue;

it('shows the first toast immediately', function () {
  $queue = new ToastQueue();
  $queue->push(new Toast('First'), 100.0);

  expect($queue->current()?->message)->toBe('First');
});

it('replaces an info ticker with newer info instantly', function () {
  $queue = new ToastQueue();
  $queue->push(new Toast('Cursor (1, 1)'), 100.0);
  $queue->push(new Toast('Cursor (2, 1)'), 100.1);

  expect($queue->current()?->message)->toBe('Cursor (2, 1)')
    ->and($queue->pendingCount())->toBe(0);
});

it('never lets info overwrite a live error', function () {
  $queue = new ToastQueue();
  $queue->push(new Toast('Save failed', StatusLevel::ERROR), 100.0);
  $queue->push(new Toast('Cursor moved'), 100.5);

  expect($queue->current()?->message)->toBe('Save failed')
    ->and($queue->current()?->level)->toBe(StatusLevel::ERROR)
    ->and($queue->pendingCount())->toBe(0);
});

it('queues typed statuses instead of overwriting each other', function () {
  $queue = new ToastQueue();
  $queue->push(new Toast('Saved a.', StatusLevel::SUCCESS), 100.0);
  $queue->push(new Toast('Saved b.', StatusLevel::SUCCESS), 100.1);
  $queue->push(new Toast('Saved c.', StatusLevel::SUCCESS), 100.2);

  expect($queue->current()?->message)->toBe('Saved a.')
    ->and($queue->pendingCount())->toBe(2);

  expect($queue->tick(100.3))->toBeFalse();
  expect($queue->tick(105.0))->toBeTrue()
    ->and($queue->current()?->message)->toBe('Saved b.');
  expect($queue->tick(110.0))->toBeTrue()
    ->and($queue->current()?->message)->toBe('Saved c.');
});

it('lets a higher severity preempt and requeues the displaced toast', function () {
  $queue = new ToastQueue();
  $queue->push(new Toast('Heads up', StatusLevel::WARN), 100.0);
  $queue->push(new Toast('Broken', StatusLevel::ERROR), 100.1);

  expect($queue->current()?->message)->toBe('Broken')
    ->and($queue->pendingCount())->toBe(1);

  $queue->tick(120.0);

  expect($queue->current()?->message)->toBe('Heads up');
});

it('promotes success over an info ticker without queueing', function () {
  $queue = new ToastQueue();
  $queue->push(new Toast('Cursor moved'), 100.0);
  $queue->push(new Toast('Saved.', StatusLevel::SUCCESS), 100.1);

  expect($queue->current()?->message)->toBe('Saved.')
    ->and($queue->pendingCount())->toBe(0);
});

it('expires to idle (null) when nothing is queued', function () {
  $queue = new ToastQueue();
  $queue->push(new Toast('Saved.', StatusLevel::SUCCESS), 100.0);

  expect($queue->tick(200.0))->toBeTrue()
    ->and($queue->current())->toBeNull()
    ->and($queue->tick(201.0))->toBeFalse();
});

it('deduplicates repeats of the newest message', function () {
  $queue = new ToastQueue();
  $queue->push(new Toast('Width updated.', StatusLevel::SUCCESS), 100.0);
  $queue->push(new Toast('Width updated.', StatusLevel::SUCCESS), 100.1);
  $queue->push(new Toast('Width updated.', StatusLevel::SUCCESS), 100.2);

  expect($queue->pendingCount())->toBe(0);
});

it('caps the pending backlog', function () {
  $queue = new ToastQueue();
  $queue->push(new Toast('Current', StatusLevel::ERROR), 100.0);

  for ($index = 0; $index < 10; $index++) {
    $queue->push(new Toast("Queued {$index}", StatusLevel::SUCCESS), 100.1 + $index / 10);
  }

  expect($queue->pendingCount())->toBeLessThanOrEqual(5);
});

it('dismisses a matching current toast and promotes the next', function () {
  $queue = new ToastQueue();
  $queue->push(new Toast('Confirm the prompt', StatusLevel::WARN), 100.0);
  $queue->push(new Toast('Saved earlier.', StatusLevel::SUCCESS), 100.1);

  // A level-filtered dismiss ignores non-matching toasts.
  $queue->dismissCurrent(100.2, StatusLevel::ERROR);

  expect($queue->current()?->message)->toBe('Confirm the prompt');

  $queue->dismissCurrent(100.3, StatusLevel::WARN);

  expect($queue->current()?->message)->toBe('Saved earlier.');

  $queue->dismissCurrent(100.4);

  expect($queue->current())->toBeNull();
});

it('clears the queue completely', function () {
  $queue = new ToastQueue();
  $queue->push(new Toast('Error', StatusLevel::ERROR), 100.0);
  $queue->push(new Toast('Next', StatusLevel::SUCCESS), 100.1);
  $queue->clear();

  expect($queue->current())->toBeNull()
    ->and($queue->pendingCount())->toBe(0);
});
