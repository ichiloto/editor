<?php

declare(strict_types=1);

use Ichiloto\Editor\Inspector\InputControl;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\UI\TextFieldEditor;
use Ichiloto\Editor\UI\TextFieldKeyResult;

function textControl(string $rawValue = ''): InputControl
{
  return new InputControl(InputControlType::TEXT, $rawValue);
}

function integerControl(string $rawValue = '', int $step = 1): InputControl
{
  return new InputControl(InputControlType::INTEGER, $rawValue, $step);
}

it('opens with the caret at the end of the value', function () {
  $editor = new TextFieldEditor();
  $editor->open('Test Map');

  expect($editor->isActive)->toBeTrue()
    ->and($editor->value)->toBe('Test Map')
    ->and($editor->caret)->toBe(8);
});

it('closes back to an empty inactive buffer', function () {
  $editor = new TextFieldEditor();
  $editor->open('abc');
  $editor->close();

  expect($editor->isActive)->toBeFalse()
    ->and($editor->value)->toBe('')
    ->and($editor->caret)->toBe(0);
});

it('reports Esc as cancelled and Enter as submitted', function () {
  $editor = new TextFieldEditor();
  $editor->open('abc');

  expect($editor->handleKey("\033", textControl()))->toBe(TextFieldKeyResult::CANCELLED)
    ->and($editor->handleKey("\n", textControl()))->toBe(TextFieldKeyResult::SUBMITTED)
    ->and($editor->handleKey("\r", textControl()))->toBe(TextFieldKeyResult::SUBMITTED);
});

it('inserts typed symbols at the caret', function () {
  $editor = new TextFieldEditor();
  $editor->open('ac');
  $editor->caret = 1;

  expect($editor->handleKey('b', textControl()))->toBe(TextFieldKeyResult::CHANGED)
    ->and($editor->value)->toBe('abc')
    ->and($editor->caret)->toBe(2);
});

it('moves the caret with arrow keys and clamps at the edges', function () {
  $editor = new TextFieldEditor();
  $editor->open('ab');

  expect($editor->handleKey("\033[D", textControl()))->toBe(TextFieldKeyResult::CHANGED)
    ->and($editor->caret)->toBe(1);

  $editor->handleKey("\033[D", textControl());
  $editor->handleKey("\033[D", textControl());

  expect($editor->caret)->toBe(0);

  $editor->handleKey("\033[C", textControl());
  $editor->handleKey("\033[C", textControl());
  $editor->handleKey("\033[C", textControl());

  expect($editor->caret)->toBe(2);
});

it('backspaces before the caret and deletes forward at the caret', function () {
  $editor = new TextFieldEditor();
  $editor->open('abcd');
  $editor->caret = 2;

  expect($editor->handleKey("\177", textControl()))->toBe(TextFieldKeyResult::CHANGED)
    ->and($editor->value)->toBe('acd')
    ->and($editor->caret)->toBe(1);

  expect($editor->handleKey("\033[3~", textControl()))->toBe(TextFieldKeyResult::CHANGED)
    ->and($editor->value)->toBe('ad')
    ->and($editor->caret)->toBe(1);
});

it('steps integer fields with the up and down arrows', function () {
  $editor = new TextFieldEditor();
  $editor->open('4');

  expect($editor->handleKey("\033[A", integerControl(step: 5)))->toBe(TextFieldKeyResult::CHANGED)
    ->and($editor->value)->toBe('9');

  expect($editor->handleKey("\033[B", integerControl(step: 5)))->toBe(TextFieldKeyResult::CHANGED)
    ->and($editor->value)->toBe('4')
    ->and($editor->caret)->toBe(1);
});

it('rejects symbols the control does not accept', function () {
  $editor = new TextFieldEditor();
  $editor->open('12');

  expect($editor->handleKey('x', integerControl()))->toBe(TextFieldKeyResult::IGNORED)
    ->and($editor->value)->toBe('12');

  expect($editor->handleKey('-', integerControl()))->toBe(TextFieldKeyResult::IGNORED);
});

it('ignores unrecognized escape sequences and missing controls', function () {
  $editor = new TextFieldEditor();
  $editor->open('ab');

  expect($editor->handleKey("\033[H", textControl()))->toBe(TextFieldKeyResult::IGNORED)
    ->and($editor->handleKey('x', null))->toBe(TextFieldKeyResult::IGNORED)
    ->and($editor->value)->toBe('ab');
});
