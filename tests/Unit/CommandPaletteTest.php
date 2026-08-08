<?php

declare(strict_types=1);

use Ichiloto\Editor\UI\CommandPalette;
use Ichiloto\Editor\UI\PaletteItem;

/**
 * Builds palette items from plain labels.
 *
 * @param string[] $labels
 * @return PaletteItem[]
 */
function paletteItems(array $labels): array
{
  return array_map(
    static fn(string $label): PaletteItem => new PaletteItem($label, '', static function (): void {
    }),
    $labels,
  );
}

/**
 * @param PaletteItem[] $items
 * @return string[]
 */
function paletteLabels(array $items): array
{
  return array_map(static fn(PaletteItem $item): string => $item->label, $items);
}

it('returns every item in original order for an empty query', function () {
  $items = paletteItems(['Save Map', 'Map: home', 'Quit']);

  expect(paletteLabels(CommandPalette::filter($items, '')))
    ->toBe(['Save Map', 'Map: home', 'Quit'])
    ->and(paletteLabels(CommandPalette::filter($items, '   ')))
    ->toBe(['Save Map', 'Map: home', 'Quit']);
});

it('ranks substring matches above scattered subsequences', function () {
  $items = paletteItems(['Database: Quests', 'Quit Editor Stuff', 'Map: quarry']);
  $filtered = paletteLabels(CommandPalette::filter($items, 'quest'));

  expect($filtered[0])->toBe('Database: Quests')
    ->and($filtered)->toContain('Quit Editor Stuff');
});

it('ranks earlier substring positions first and drops non-matches', function () {
  $items = paletteItems(['Remap keys', 'Map: home', 'Quit']);
  $filtered = paletteLabels(CommandPalette::filter($items, 'map'));

  expect($filtered)->toBe(['Map: home', 'Remap keys']);
});

it('matches case-insensitively', function () {
  $items = paletteItems(['Save All']);

  expect(paletteLabels(CommandPalette::filter($items, 'SAVE a')))->toBe(['Save All']);
});

it('excludes labels missing a query symbol', function () {
  $items = paletteItems(['Save Map']);

  expect(CommandPalette::filter($items, 'xyz'))->toBe([]);
});

it('resets the selection when the query changes and clamps movement', function () {
  $palette = new CommandPalette();
  $palette->open(paletteItems(['Alpha', 'Beta', 'Gamma']));
  $palette->moveSelection(1);
  $palette->moveSelection(1);

  expect($palette->selectedIndex)->toBe(2);

  $palette->moveSelection(1);

  expect($palette->selectedIndex)->toBe(2);

  $palette->type('a');

  expect($palette->selectedIndex)->toBe(0);

  $palette->backspace();
  $palette->moveSelection(-5);

  expect($palette->selectedIndex)->toBe(0);
});

it('returns the selected filtered item', function () {
  $palette = new CommandPalette();
  $palette->open(paletteItems(['Alpha', 'Beta', 'Gamma']));
  $palette->type('a');

  // 'a' matches all three; 'Alpha' (position 0... 'alpha' contains 'a' at 0) first.
  expect($palette->selectedItem()?->label)->toBe('Alpha');

  $palette->moveSelection(1);

  expect($palette->selectedItem()?->label)->not->toBeNull();
});
