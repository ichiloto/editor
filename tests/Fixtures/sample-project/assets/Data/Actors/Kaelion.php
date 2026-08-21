<?php

use Ichiloto\Engine\Entities\Character;

return [
  'class' => Character::class,
  'data' => [
    'name' => 'Kaelion',
    'description' => 'A brave and steadfast swordsman.',
    'level' => 1,
    'currentExp' => 0,
    'stats' => [
      'currentHp' => 100,
      'currentMp' => 20,
      'currentAp' => 10,
    ],
    'images' => [
      'dialog' => [],
      'field' => [],
      'battle' => [],
    ],
  ]
];
