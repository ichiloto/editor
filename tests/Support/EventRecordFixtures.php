<?php

declare(strict_types=1);

/**
 * A synthetic project for event record authoring: its test map holds a
 * transfer whose spawn sprite is legacy art, a dialogue of plain pages
 * with a completion write the write line cannot carry, and a story script
 * with an authored empty condition list, an inline script and an Event
 * Script id, among source comments and an expression.
 */
function eventRecordProject(): string
{
    $root = makeTemporaryProject('ichiloto-event-record-');
    $rows = [str_repeat(' ', 12), ' T   E  D S ', str_repeat(' ', 12), str_repeat(' ', 12), str_repeat(' ', 12)];
    file_put_contents($root . '/assets/Maps/test-map/test-map.event.php',
        "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n" . implode("\n", $rows) . "\nICHILOTO_EVENT_MAP;\n");
    file_put_contents(eventRecordDataPath($root), <<<'PHP'
<?php

// Synthetic map for event record authoring.
return [
  'name' => 'Test Map',
  'region' => '',
  'description' => 'A tiny fixture map.',
  'triggers' => [],
  'events' => [
    'E' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\ChestEventTrigger',
      'data' => [
        'lootType' => 'item',
        'loot' => 'S-Potion',
      ],
    ],
    // The way out.
    'T' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger',
      'data' => [
        'destinationMap' => 'test-map',
        'spawnPoint' => ['x' => 1, 'y' => 1],
        'spawnSprite' => ['@'],
      ],
    ],
    'D' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\DialogueEventTrigger',
      'sets' => [
        ['type' => 'event', 'name' => 'met_guide', 'note' => 'kept by the author'],
      ],
      'data' => [
        'dialogue' => [
          ['name' => 'Guide', 'text' => 'First line.'],
          ['name' => '', 'text' => 'Second line.'],
        ],
      ],
    ],
    'S' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\ScriptEventTrigger',
      'conditions' => [],
      'data' => [
        'mode' => 'auto',
        'reusable' => true,
        'scriptId' => 'harbour-watch',
        'script' => [
          ['type' => 'text', 'name' => 'Observer', 'text' => 'Stop there.'],
          // Timed by the author.
          ['type' => 'wait', 'seconds' => abs(1)],
        ],
      ],
    ],
  ],
];
PHP);

    return $root;
}

/** The test map's data file in an event record project. */
function eventRecordDataPath(string $root): string
{
    return $root . '/assets/Maps/test-map/test-map.data.php';
}
