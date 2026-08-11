<?php

// Technical validation fixture only; this is not game story content.
return [
  [
    'type' => 'move_route',
    'subject' => 'player',
    'wait' => true,
    'secondsPerStep' => 0.1,
    'steps' => [
      ['direction' => 'right', 'count' => 2, 'faceOnly' => false],
    ],
  ],
  [
    'type' => 'move_route',
    'subject' => 'npc',
    'npcId' => 'technical-guide',
    'wait' => true,
    'speed' => 8,
    'steps' => [
      ['direction' => 'left', 'count' => 1, 'faceOnly' => false],
      ['direction' => 'down', 'count' => 1, 'faceOnly' => true],
    ],
  ],
  [
    'type' => 'start_battle',
    'troop' => 'Technical Troop',
    'resultVariable' => 'phase7_fixture_battle_result',
    'defeatPolicy' => 'continue',
  ],
];
