<?php

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
        'loot' => 'Potion',
      ],
    ],
  ],
];
