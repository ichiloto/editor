<?php

declare(strict_types=1);

use Ichiloto\Editor\IO\InputRouter;
use Ichiloto\Editor\IO\KeyBinding;
use Ichiloto\Editor\UI\Modal;
use Ichiloto\Editor\UI\ModalStack;

/**
 * Builds a router wired to a call log so routing order is observable.
 *
 * @return array{router: InputRouter, modals: ModalStack, calls: ArrayObject}
 */
function routerHarness(): array
{
  $modals = new ModalStack();
  $router = new InputRouter($modals);
  $calls = new ArrayObject();
  $log = static fn(string $label): Closure => static function () use ($calls, $label): void {
    $calls[] = $label;
  };

  foreach (Modal::cases() as $modal) {
    $router->bindModal($modal, static function (string $input, string $normalized) use ($calls, $modal): void {
      $calls[] = "modal:{$modal->value}:{$input}";
    });
  }

  $router->onStatusDetailShortcut($log('status-detail'));
  $router->onDatabaseShortcut($log('open-database'));
  $router->setMouseInterceptor(static function (string $input) use ($calls): bool {
    if (str_starts_with($input, "\033[<")) {
      $calls[] = 'mouse';
      return true;
    }

    return false;
  });
  $router->bindTextEditing(static fn(): bool => false, $log('text-edit'));
  $router->bindBase(
    KeyBinding::exact("\x11", $log('quit')),
    KeyBinding::contains("\033[Z", $log('back-tab')),
    KeyBinding::when(static fn(string $input): bool => $input === 'y', $log('predicate')),
    KeyBinding::attempt(static function (string $input) use ($calls): bool {
      if ($input === "\x1a") {
        $calls[] = 'undo';
        return true;
      }

      return false;
    }),
  );
  $router->setFallback(static function (string $input, string $normalized) use ($calls): void {
    $calls[] = "pane:{$input}";
  });

  return ['router' => $router, 'modals' => $modals, 'calls' => $calls];
}

it('routes to the focused pane when nothing else claims the input', function () {
  ['router' => $router, 'calls' => $calls] = routerHarness();
  $router->route('j');

  expect((array) $calls)->toBe(['pane:j']);
});

it('consumes base bindings in table order', function () {
  ['router' => $router, 'calls' => $calls] = routerHarness();
  $router->route("\x11");
  $router->route("\033[Z");
  $router->route('y');
  $router->route("\x1a");

  expect((array) $calls)->toBe(['quit', 'back-tab', 'predicate', 'undo']);
});

it('routes everything to the active modal handler', function () {
  ['router' => $router, 'modals' => $modals, 'calls' => $calls] = routerHarness();
  $modals->push(Modal::LOOT_DIALOG);
  $router->route('j');

  expect((array) $calls)->toBe(['modal:loot_dialog:j']);
});

it('opens the status detail overlay from under any non-safety modal', function () {
  ['router' => $router, 'modals' => $modals, 'calls' => $calls] = routerHarness();
  $modals->push(Modal::DATABASE);
  $router->route("\x05");

  expect((array) $calls)->toBe(['status-detail']);
});

it('lets safety modals consume the global shortcuts', function () {
  ['router' => $router, 'modals' => $modals, 'calls' => $calls] = routerHarness();
  $modals->push(Modal::UNSAVED_CHANGES_GUARD);
  $router->route("\x05");
  $router->route("\x04");

  expect((array) $calls)->toBe([
    "modal:unsaved_changes_guard:\x05",
    "modal:unsaved_changes_guard:\x04",
  ]);
});

it('opens the database with Ctrl+D only when the database is not already open', function () {
  ['router' => $router, 'modals' => $modals, 'calls' => $calls] = routerHarness();
  $router->route("\x04");

  expect((array) $calls)->toBe(['open-database']);

  $modals->push(Modal::DATABASE);
  $router->route("\x04");

  expect((array) $calls)->toBe(['open-database', "modal:database:\x04"]);
});

it('opens the database with either F2 encoding', function () {
  foreach (["\033OQ", "\033[12~"] as $sequence) {
    ['router' => $router, 'calls' => $calls] = routerHarness();
    $router->route($sequence);

    expect((array) $calls)->toBe(['open-database']);
  }
});

it('leaves ! free for the focused pane (painting)', function () {
  ['router' => $router, 'calls' => $calls] = routerHarness();
  $router->route('!');

  expect((array) $calls)->toBe(['pane:!']);
});

it('describes its bindings for the help overlay', function () {
  ['router' => $router] = routerHarness();
  $entries = $router->describeBindings();
  $keys = array_column($entries, 'key');

  expect($keys)->toContain(InputRouter::KEY_DATABASE_LABEL)
    ->and($keys)->toContain('Ctrl+E');

  foreach ($entries as $entry) {
    expect($entry['key'])->not->toBe('')
      ->and($entry['description'])->not->toBe('');
  }
});

it('keeps mouse input flowing under the character map but not under dialogs', function () {
  ['router' => $router, 'modals' => $modals, 'calls' => $calls] = routerHarness();
  $modals->push(Modal::CHARACTER_MAP);
  $router->route("\033[<0;5;5M");

  expect((array) $calls)->toBe(['mouse']);

  $modals->clear();
  $modals->push(Modal::DESTINATION_DIALOG);
  $router->route("\033[<0;5;5M");

  expect((array) $calls)->toBe(['mouse', "modal:destination_dialog:\033[<0;5;5M"]);
});

it('routes to the highest-priority modal when several are open', function () {
  ['router' => $router, 'modals' => $modals, 'calls' => $calls] = routerHarness();
  $modals->push(Modal::CHARACTER_MAP);
  $modals->push(Modal::DATABASE);
  $router->route('j');

  expect((array) $calls)->toBe(['modal:database:j']);
});

it('ignores empty input', function () {
  ['router' => $router, 'calls' => $calls] = routerHarness();
  $router->route('');

  expect((array) $calls)->toBe([]);
});

it('routes to the text-edit handler when the gate reports active', function () {
  $modals = new ModalStack();
  $router = new InputRouter($modals);
  $calls = new ArrayObject();
  $router->bindTextEditing(
    static fn(): bool => true,
    static function (string $input) use ($calls): void {
      $calls[] = "edit:{$input}";
    },
  );
  $router->setFallback(static function () use ($calls): void {
    $calls[] = 'pane';
  });
  $router->route('a');

  expect((array) $calls)->toBe(['edit:a']);
});
