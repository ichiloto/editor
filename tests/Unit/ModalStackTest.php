<?php

declare(strict_types=1);

use Ichiloto\Editor\UI\Modal;
use Ichiloto\Editor\UI\ModalStack;

it('starts empty', function () {
  $stack = new ModalStack();

  expect($stack->isEmpty())->toBeTrue()
    ->and($stack->active())->toBeNull()
    ->and($stack->top())->toBeNull();
});

it('pushes and removes modals idempotently', function () {
  $stack = new ModalStack();
  $stack->push(Modal::DATABASE);
  $stack->push(Modal::DATABASE);

  expect($stack->has(Modal::DATABASE))->toBeTrue();

  $stack->remove(Modal::DATABASE);
  $stack->remove(Modal::DATABASE);

  expect($stack->has(Modal::DATABASE))->toBeFalse()
    ->and($stack->isEmpty())->toBeTrue();
});

it('resolves the active modal by declaration priority, not push order', function () {
  $stack = new ModalStack();
  $stack->push(Modal::CHARACTER_MAP);
  $stack->push(Modal::DATABASE);
  $stack->push(Modal::STATUS_DETAIL);

  // STATUS_DETAIL outranks everything regardless of push order.
  expect($stack->active())->toBe(Modal::STATUS_DETAIL)
    ->and($stack->top())->toBe(Modal::STATUS_DETAIL);

  $stack->remove(Modal::STATUS_DETAIL);

  expect($stack->active())->toBe(Modal::DATABASE);

  $stack->remove(Modal::DATABASE);

  expect($stack->active())->toBe(Modal::CHARACTER_MAP);
});

it('removes a modal buried under another without disturbing the rest', function () {
  $stack = new ModalStack();
  $stack->push(Modal::DESTINATION_DIALOG);
  $stack->push(Modal::STATUS_DETAIL);
  $stack->remove(Modal::DESTINATION_DIALOG);

  expect($stack->active())->toBe(Modal::STATUS_DETAIL)
    ->and($stack->has(Modal::DESTINATION_DIALOG))->toBeFalse();
});

it('orders the safety modals above the database and dialogs', function () {
  expect(Modal::STATUS_DETAIL->priority())->toBeLessThan(Modal::DATABASE->priority())
    ->and(Modal::UNSAVED_CHANGES_GUARD->priority())->toBeLessThan(Modal::DATABASE->priority())
    ->and(Modal::RENAME_CONFIRMATION->priority())->toBeLessThan(Modal::DATABASE->priority())
    ->and(Modal::DATABASE->priority())->toBeLessThan(Modal::DESTINATION_DIALOG->priority())
    ->and(Modal::EVENT_TYPE_DIALOG->priority())->toBeLessThan(Modal::CHARACTER_MAP->priority());
});

it('clears every open modal at once', function () {
  $stack = new ModalStack();
  $stack->push(Modal::LOOT_DIALOG);
  $stack->push(Modal::CHARACTER_MAP);
  $stack->clear();

  expect($stack->isEmpty())->toBeTrue();
});
