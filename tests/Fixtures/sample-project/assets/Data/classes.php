<?php

return [
  [
    'id' => 1,
    'name' => 'Vanguard',
    'description' => 'Balanced frontline fighters who lead the charge.',
    'initialLevel' => 1,
    'maxLevel' => 99,
    'note' => '',
    'traits' => [],
    'experienceCurve' => [
      'baseValue' => 30,
      'extraValue' => 20,
      'accelerationA' => 30,
      'accelerationB' => 30,
    ],
    'parameterCurves' => [
      'totalHp' => ['baseValue' => 140, 'extraGrowth' => 520, 'flatIncrement' => 42],
      'totalMp' => ['baseValue' => 10, 'extraGrowth' => 80, 'flatIncrement' => 8],
    ],
  ],
  [
    'id' => 2,
    'name' => 'Oracle',
    'description' => 'Mystics who favor mana reserves and high spell output.',
    'initialLevel' => 1,
    'maxLevel' => 99,
    'note' => '',
    'traits' => [],
    'experienceCurve' => [
      'baseValue' => 28,
      'extraValue' => 22,
      'accelerationA' => 28,
      'accelerationB' => 34,
    ],
    'parameterCurves' => [
      'totalHp' => ['baseValue' => 90, 'extraGrowth' => 360, 'flatIncrement' => 28],
      'totalMp' => ['baseValue' => 24, 'extraGrowth' => 140, 'flatIncrement' => 12],
    ],
  ],
];
