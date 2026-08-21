<?php

return [
  'name' => 'Technical Map',
  'region' => '',
  'description' => 'Disposable validation data; not game story content.',
  'triggers' => [],
  'npcs' => [
    [
      'id' => 'technical-guide',
      'name' => 'Guide',
      'sprite' => 'G',
      'x' => 7,
      'y' => 2,
      'movement' => 'fixed',
    ],
  ],
  'events' => [
    'S' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\ScriptEventTrigger',
      'conditions' => [],
      'sets' => [
        ['type' => 'event', 'name' => 'phase7_fixture_complete'],
      ],
      'whenBlocked' => '',
      'data' => [
        'scriptId' => 'phase7-technical',
        'mode' => 'action',
        'reusable' => false,
      ],
    ],
  ],
];
