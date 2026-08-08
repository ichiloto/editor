<?php

return [
  [
    'id' => 'poison',
    'name' => 'Poison',
    'icon' => '☠',
    'description' => 'Venom saps HP every turn.',
    'tickFormula' => '-max(1, intval($target->stats->totalHp * 0.08))',
    'persistsAfterBattle' => true,
  ],
  [
    'id' => 'stun',
    'name' => 'Stun',
    'icon' => '💫',
    'description' => 'Too dazed to act.',
    'durationTurns' => 2,
    'preventsAction' => true,
  ],
];
