<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectMap;

/**
 * Shared fixtures for the map save-integrity and lifecycle suites: a map
 * whose data file carries authored PHP the evaluated array cannot
 * reproduce, and the state helpers that prove nothing else moved.
 */

/**
 * A project whose fixture map carries authored PHP the evaluated array
 * cannot reproduce: a use import, comments, an enum expression, a shared
 * local variable, a require-backed script, named-argument-like formatting
 * and a future key.
 */
function authoredMapProject(): string
{
    $root = makeTemporaryProject('ichiloto-map-integrity-');
    if (! is_dir($root . '/assets/Events')) {
        mkdir($root . '/assets/Events', 0o777, true);
    }

    file_put_contents($root . '/assets/Events/harbour-watch.php', <<<'PHP_SOURCE'
<?php

return [
  ['type' => 'text', 'name' => 'Watcher', 'text' => 'The tide is out.'],
];
PHP_SOURCE);
    file_put_contents($root . '/assets/Maps/test-map/test-map.data.php', <<<'PHP_SOURCE'
<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;

// The harbour district, hand-annotated.
$watchScript = require dirname(__DIR__, 2) . '/Events/harbour-watch.php';

return [
  'name' => 'Test Map',
  'region' => '',
  'description' => 'A tiny fixture map.',
  // Station keeps its comment and its odd spacing.
  'station'     => ['x' => 3, 'y' => 1],
  'futureLighting' => ['mode' => 'dusk', 'level' => 2],
  'triggers' => [],
  'events' => [
    'E' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\ChestEventTrigger',
      'data' => [
        'lootType' => 'item',
        'loot' => 'S-Potion',
      ],
    ],
    'W' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\ScriptEventTrigger',
      'data' => [
        'script' => $watchScript,
        'mode' => 'action',
        'reusable' => true,
      ],
    ],
    'R' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\ScriptEventTrigger',
      'data' => [
        'script' => require dirname(__DIR__, 2) . '/Events/harbour-watch.php',
        'mode' => 'auto',
        'reusable' => false,
      ],
    ],
  ],
  'npcs' => [
    [
      'id' => 'harbour-keeper',
      'name' => 'Keeper',
      'sprite' => '<fg=#5fd7d7>@</>',
      'x' => 4,
      'y' => 2,
      'spawnSprite' => [MovementHeading::NORTH->value],
    ],
    [
      'name' => 'Old Gull',
      'sprite' => 'g',
      'x' => 6,
      'y' => 3,
    ],
  ],
];
PHP_SOURCE);

    return $root;
}

/**
 * The fixture map, loaded as the editor loads it.
 */
function authoredMap(string $root): ProjectMap
{
    return ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/test-map');
}

/**
 * The bytes and modification time of the map triplet.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function tripletState(ProjectMap $map): array
{
    $state = [];

    foreach (['data' => $map->dataPath, 'map' => $map->mapPath, 'event' => $map->eventPath] as $member => $path) {
        $state[$member] = [(string) file_get_contents($path), (int) filemtime($path)];
    }

    return $state;
}

/**
 * Backdates the triplet so any rewrite shows in its modification time.
 */
function backdateTriplet(ProjectMap $map): void
{
    foreach ([$map->dataPath, $map->mapPath, $map->eventPath] as $path) {
        touch($path, time() - 3600);
    }
}
