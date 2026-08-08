<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Validation\MapValidator;

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
      'E' => ['class' => 'SomeTrigger', 'data' => []],
    ],
  ], ['E   ', '    ']);

  expect(MapValidator::validate($map, ['town' => $map]))->toBe([]);
});

it('warns about event markers without definitions', function () {
  $map = validatorMap('town', ['name' => 'Town', 'events' => []], ['E   ', '    ']);

  $warnings = MapValidator::validate($map, ['town' => $map]);

  expect($warnings)->toHaveCount(1)
    ->and($warnings[0])->toContain('marker E')
    ->and($warnings[0])->toContain('no definition');
});

it('warns about dangling transfer destinations', function () {
  $map = validatorMap('town', [
    'name' => 'Town',
    'events' => [
      'T' => ['class' => 'Transfer', 'data' => ['destinationMap' => 'vanished-map']],
    ],
  ], ['T   ', '    ']);

  $warnings = MapValidator::validate($map, ['town' => $map]);

  expect($warnings)->toHaveCount(1)
    ->and($warnings[0])->toContain('vanished-map')
    ->and($warnings[0])->toContain('does not exist');
});

it('warns about spawn points outside the destination map', function () {
  $destination = validatorMap('cave', ['name' => 'Cave', 'events' => []]);
  $map = validatorMap('town', [
    'name' => 'Town',
    'events' => [
      'T' => [
        'class' => 'Transfer',
        'data' => [
          'destinationMap' => 'cave',
          'spawnPoint' => ['x' => 40, 'y' => 1],
        ],
      ],
    ],
  ], ['T   ', '    ']);

  $warnings = MapValidator::validate($map, ['town' => $map, 'cave' => $destination]);

  expect($warnings)->toHaveCount(1)
    ->and($warnings[0])->toContain('spawn point (40, 1)')
    ->and($warnings[0])->toContain('cave');
});

it('validates spawn points against this map when no destination is set', function () {
  $map = validatorMap('town', [
    'name' => 'Town',
    'events' => [
      'S' => ['class' => 'Spawn', 'data' => ['spawnPoint' => ['x' => 1, 'y' => 1]]],
      'B' => ['class' => 'Spawn', 'data' => ['spawnPoint' => ['x' => 9, 'y' => 9]]],
    ],
  ], ['SB  ', '    ']);

  $warnings = MapValidator::validate($map, ['town' => $map]);

  expect($warnings)->toHaveCount(1)
    ->and($warnings[0])->toContain('B:')
    ->and($warnings[0])->toContain('this map');
});

it('collects multiple findings in one pass', function () {
  $map = validatorMap('town', [
    'name' => 'Town',
    'events' => [
      'T' => ['class' => 'Transfer', 'data' => ['destinationMap' => 'gone']],
    ],
  ], ['TX  ', '    ']);

  $warnings = MapValidator::validate($map, ['town' => $map]);

  expect($warnings)->toHaveCount(2);
});
