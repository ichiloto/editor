<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Maps\MapLinePlanner;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * The synthetic project for inserting and removing map rows and columns: a
 * 6 x 5 layered map `home` with a styled terrain layer, a decoration layer,
 * events and a tile layer, and a legacy map `town` whose events and scripts
 * lead into it.
 */

/** Writes the synthetic map line project and returns its root. */
function mapLineProject(): string
{
    $root = makeTemporaryProject('ichiloto-line-insertion-');
    writeTestTileset($root);
    $home = $root . '/assets/Maps/home';
    mkdir($home . '/layers', 0o777, true);
    $grids = [
        'layers/01.terrain.map.php' => "<fg=green>######</>\n#....#\n#.<fg=red>~~</>.#\n#....#\n######",
        'layers/02.detail.deco.php' => "      \n  d   \n      \n    d \n      ",
        'home.event.php' => "      \n    W \n      \n D  S \n      ",
    ];
    foreach ($grids as $file => $text) {
        file_put_contents($home . '/' . $file, MapGridSource::buildSource($text, 'AUTHORED', "// keep {$file}\n"));
    }
    writeTileLayer($home, '01.floor.tiles.php', implode("\n", array_map(
        static fn(int $row): string => implode(' ', array_fill(0, 6, $row === 2 ? '5' : '2816')), range(0, 4))),
        "// Painted in the GUI editor.\n");
    file_put_contents($home . '/home.data.php', <<<'PHP'
<?php

// The house.
return [
  'name' => 'Home',
  'region' => '',
  'tileset' => 'home',
  'station' => ['x' => 3, 'y' => 3],
  'tileLayers' => ['floor' => ['offset' => [0, 3]]],
  'npcs' => [
    ['id' => 'cat', 'name' => 'Cat', 'sprite' => 'c', 'x' => 2, 'y' => 3,
      'movement' => 'wander', 'wanderArea' => ['x' => 1, 'y' => 1, 'width' => 3, 'height' => 3]],
    ['id' => 'mother', 'name' => 'Mother', 'sprite' => '@', 'x' => 4, 'y' => 0, 'script' => [
      ['type' => 'move_player', 'x' => 1, 'y' => 4],
      ['type' => 'transfer', 'map' => 'town', 'x' => 2, 'y' => 3],
      ['type' => 'move_player', 'x' => 3, 'y' => 3],
    ]],
    ['id' => 'lamp', 'name' => 'Lamp', 'sprite' => 'i', 'x' => 1, 'y' => 2 + 1],
  ],
  'events' => [
    'D' => [
      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\SleepEventTrigger',
      'data' => ['spawnPoint' => ['x' => 1, 'y' => 4], 'spawnSprite' => ['South'], 'cost' => 0],
    ],
    'W' => [
      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
      'data' => ['mode' => 'action', 'reusable' => true, 'scriptId' => 'home-only'],
    ],
    'S' => [
      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
      'data' => ['mode' => 'action', 'reusable' => true, 'scriptId' => 'shared'],
    ],
  ],
];
PHP);

    $town = $root . '/assets/Maps/town';
    mkdir($town, 0o777, true);
    file_put_contents($town . '/town.map.php', MapGridSource::buildSource("######\n#    #\n######", 'ICHILOTO_MAP'));
    file_put_contents($town . '/town.event.php', MapGridSource::buildSource(" AB S \n      \n      ", 'ICHILOTO_EVENT_MAP'));
    file_put_contents($town . '/town.data.php', <<<'PHP'
<?php

return [
  'name' => 'Town',
  'region' => '',
  'npcs' => [
    ['id' => 'guard', 'name' => 'Guard', 'sprite' => 'g', 'x' => 1, 'y' => 1, 'script' => [
      ['type' => 'transfer', 'map' => 'home', 'x' => 1, 'y' => 3],
      ['type' => 'move_player', 'x' => 2, 'y' => 4],
      ['type' => 'move_route', 'subject' => 'player', 'waypoints' => [['x' => 5], ['y' => 4]]],
    ]],
  ],
  'events' => [
    'A' => [
      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger',
      'data' => ['destinationMap' => 'home', 'spawnPoint' => ['x' => 2, 'y' => 4], 'spawnSprite' => ['North']],
    ],
    'B' => [
      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger',
      'data' => ['destinationMap' => 'test-map', 'spawnPoint' => ['x' => 2, 'y' => 4], 'spawnSprite' => ['North']],
    ],
    'S' => [
      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
      'data' => ['mode' => 'action', 'reusable' => true, 'scriptId' => 'shared'],
    ],
  ],
];
PHP);

    file_put_contents($root . '/assets/Events/home-only.php', <<<'PHP'
<?php

// Runs only from home.
return [
  ['type' => 'move_player', 'x' => 3, 'y' => 4],
  ['type' => 'camera', 'operation' => 'pan', 'target' => ['kind' => 'position', 'x' => 2, 'y' => 1], 'seconds' => 1.0],
];
PHP);
    file_put_contents($root . '/assets/Events/shared.php', <<<'PHP'
<?php

return [
  ['type' => 'move_player', 'x' => 3, 'y' => 4],
];
PHP);
    file_put_contents($root . '/assets/Data/system.php', <<<'PHP'
<?php

return [
  'startingPositions' => [
    'player' => ['destinationMap' => 'home', 'spawnPoint' => ['x' => 1, 'y' => 4], 'spawnSprite' => ['South']],
  ],
];
PHP);

    return $root;
}

/** Plans an insertion on the fixture's home map. */
function planLineInsertion(string $root, string $axis, int $at, int $count): Ichiloto\Editor\Maps\MapLinePlan
{
    return MapLinePlanner::planInsertion($root, 'home', $axis, $at, $count);
}

/** @return list<string> Each hand edit as `file where`. */
function describeMapLineHandEdits(Ichiloto\Editor\Maps\MapLinePlan $plan): array
{
    return array_map(static fn(array $edit): string => $edit['file'] . ' ' . $edit['where'], $plan->handEdits);
}

/** @return list<string> The messages validation reports for the save manifest. */
function describeSaveCompatibilityIssues(string $root): array
{
    return array_values(array_map(static fn($issue): string => $issue->message, array_filter(validateProject($root),
        static fn($issue): bool => str_starts_with($issue->where, 'assets/Data/save-compatibility.php'))));
}

/** The editor over the fixture with `home` selected and the cursor on the canvas. */
function mapLineEditor(string $root): Editor
{
    $editor = deletionEditor($root);
    setEditorProperty($editor, 'focusedPane', 'canvas');
    expect(getEditorProperty($editor, 'workspace')->getMapByIndex(0)->mapId)->toBe('home');

    return $editor;
}

function openMapLinePaletteItem(Editor $editor, string $label): void
{
    $items = callEditorMethod($editor, 'buildPaletteItems');
    $item = array_values(array_filter($items, static fn($item) => $item->label === $label))[0] ?? null;
    expect($item)->not->toBeNull();
    ($item->action)();
}

/**
 * The map line project with a collision dictionary, so the player's reach on
 * `home` is known. `g` paints walkable encounter grass and `P` a save point.
 */
function mapLineRemovalProject(): string
{
    $root = mapLineProject();
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n"
        . "return ['#' => CollisionType::SOLID, '.' => CollisionType::NONE, '~' => CollisionType::SOLID, ' ' => CollisionType::NONE,"
        . " 'g' => CollisionType::ENCOUNTER, 'P' => CollisionType::SAVE_POINT];\n");
    // The start and the town arrival stand in a gap in the bottom wall, so the floor is reachable from them.
    file_put_contents($root . '/assets/Maps/home/layers/01.terrain.map.php', MapGridSource::buildSource(
        "<fg=green>######</>\n#....#\n#.<fg=red>~~</>.#\n#....#\n#..###", 'AUTHORED', "// keep layers/01.terrain.map.php\n"));
    // The game reads a bed only with its question.
    $home = $root . '/assets/Maps/home/home.data.php';
    file_put_contents($home, str_replace("'cost' => 0]", "'cost' => 0, 'confirmDialogue' => ['text' => 'Rest?']]", (string) file_get_contents($home)));

    return $root;
}

/** Plans a removal on the fixture's home map. */
function planLineRemoval(string $root, string $axis, int $at, int $count, ?array $landing = null): Ichiloto\Editor\Maps\MapLinePlan
{
    return MapLinePlanner::planRemoval($root, 'home', $axis, $at, $count, $landing);
}
