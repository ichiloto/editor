<?php

return [
  [
    'id' => 1,
    'name' => 'Slash',
    'position' => 'center',
    'maxFrames' => 2,
    // Every attack the fixture reaches: enemies, the unarmed, and its sword.
    'roles' => ['attack', 'attack-unarmed', 'attack-sword'],
    'frames' => [
      [
        'index' => 1,
        'cells' => [
          ['symbol' => '/', 'x' => 0, 'y' => 0, 'color' => 'red'],
        ],
      ],
    ],
    'cues' => [],
  ],
];
