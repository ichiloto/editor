<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\MapValidator;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\Events\Triggers\DialogueEventTrigger;
use Ichiloto\Engine\Events\Triggers\SleepEventTrigger;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;

/**
 * Builds an in-memory map with the given data and event rows.
 */
function validatorMap(string $mapId, array $data, array $eventLines = ['    ', '    ']): ProjectMap
{
  return new ProjectMap(
    mapId: $mapId,
    directory: "/virtual/Maps/{$mapId}",
    dataPath: "/virtual/Maps/{$mapId}/{$mapId}.data.php",
    mapPath: "/virtual/Maps/{$mapId}/{$mapId}.map.php",
    eventPath: "/virtual/Maps/{$mapId}/{$mapId}.event.php",
    data: $data,
    tileLines: ['....', '....'],
    eventLines: $eventLines,
  );
}

it('accepts a clean map without warnings', function () {
  $map = validatorMap('town', [
    'name' => 'Town',
    'events' => [
      'E' => ['class' => DialogueEventTrigger::class, 'data' => []],
    ],
  ], ['E   ', '    ']);

  expect(MapValidator::validate($map, ['town' => $map], mapValidatorInventory()))->toBe([]);
});

it('reports an event marker without a definition as an error the map still saves with', function () {
  $map = validatorMap('town', ['name' => 'Town', 'events' => []], ['E   ', '    ']);

  $issues = MapValidator::validate($map, ['town' => $map], mapValidatorInventory());

  expect($issues)->toHaveCount(1)
    ->and($issues[0]->severity)->toBe(Severity::ERROR)
    ->and($issues[0]->map)->toBe('town')
    // The Engine's rule: its map loader refuses the marker.
    ->and($issues[0]->code)->toBe('event.unmapped')
    ->and($issues[0]->message)->toContain('E')
    ->and($issues[0]->hint)->not->toBe('');
});

it('accepts a marker painted in any shape, as the runtime triggers its exact cells', function () {
  $map = validatorMap('town', [
    'name' => 'Town',
    'events' => [
      'E' => ['class' => DialogueEventTrigger::class, 'data' => []],
    ],
  ], [' E E', 'EE  ']);

  expect(MapValidator::validate($map, ['town' => $map], mapValidatorInventory()))->toBe([]);
});

it('reports a transfer to a map that does not exist as an error', function () {
  $map = validatorMap('town', [
    'name' => 'Town',
    'events' => [
      'T' => ['class' => TransferPlayerTrigger::class, 'data' => ['destinationMap' => 'vanished-map']],
    ],
  ], ['T   ', '    ']);

  $issues = MapValidator::validate($map, ['town' => $map], mapValidatorInventory());

  expect($issues)->toHaveCount(1)
    ->and($issues[0]->severity)->toBe(Severity::ERROR)
    ->and($issues[0]->message)->toContain('vanished-map')
    ->and($issues[0]->message)->toContain('does not exist');
});

it('reports a spawn point outside the destination map as an error', function () {
  $destination = validatorMap('cave', ['name' => 'Cave', 'events' => []]);
  $map = validatorMap('town', [
    'name' => 'Town',
    'events' => [
      'T' => [
        'class' => TransferPlayerTrigger::class,
        'data' => [
          'destinationMap' => 'cave',
          'spawnPoint' => ['x' => 40, 'y' => 1],
        ],
      ],
    ],
  ], ['T   ', '    ']);

  $issues = MapValidator::validate($map, ['town' => $map, 'cave' => $destination], mapValidatorInventory());

  expect($issues)->toHaveCount(1)
    ->and($issues[0]->severity)->toBe(Severity::ERROR)
    ->and($issues[0]->message)->toContain('spawn point (40, 1)')
    ->and($issues[0]->message)->toContain('cave');
});

it('validates spawn points against this map when no destination is set', function () {
  $map = validatorMap('town', [
    'name' => 'Town',
    'events' => [
      'S' => ['class' => SleepEventTrigger::class, 'data' => ['spawnPoint' => ['x' => 1, 'y' => 1]]],
      'B' => ['class' => SleepEventTrigger::class, 'data' => ['spawnPoint' => ['x' => 9, 'y' => 9]]],
    ],
  ], ['SB  ', '    ']);

  $issues = MapValidator::validate($map, ['town' => $map], mapValidatorInventory());

  expect($issues)->toHaveCount(1)
    ->and($issues[0]->message)->toContain('B:')
    ->and($issues[0]->message)->toContain('this map');
});

it('collects multiple findings in one pass', function () {
  $map = validatorMap('town', [
    'name' => 'Town',
    'events' => [
      'T' => ['class' => TransferPlayerTrigger::class, 'data' => ['destinationMap' => 'gone']],
    ],
  ], ['TX  ', '    ']);

  expect(MapValidator::validate($map, ['town' => $map], mapValidatorInventory()))->toHaveCount(2);
});

it('warns before a save that a tiles2d crop table is no longer read', function () {
  $map = validatorMap('town', [
    'name' => 'Town',
    'events' => [],
    'tiles2d' => ['asset' => 'Graphics/Tilesets/shared.png', 'symbols' => []],
  ]);

  $issues = MapValidator::validate($map, ['town' => $map], mapValidatorInventory());

  expect(array_map(static fn(Issue $issue): array => [$issue->severity, $issue->message], $issues))
    ->toBe([[Severity::WARNING, 'tiles2d is no longer read; the map shows its terminal glyphs until it has a tileset.']]);
});
