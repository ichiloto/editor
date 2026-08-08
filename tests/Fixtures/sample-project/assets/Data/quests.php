<?php

return [
  [
    'id' => 'breakfast-duty',
    'name' => 'Breakfast Duty',
    'description' => 'Stock the family medicine chest before your journey.',
    'giver' => 'Mom',
    'objectives' => [
      ['type' => 'reach_map', 'target' => 'happyville/town-center', 'description' => 'Visit the Happyville town center'],
      ['type' => 'collect', 'target' => 'S-Mana', 'quantity' => 1, 'description' => 'Obtain an S-Mana wafer'],
    ],
    'rewards' => [
      'gold' => 200,
    ],
  ],
  [
    'id' => 'pest-control',
    'name' => 'Pest Control',
    'description' => 'Thin out the monsters so the merchants can travel safely again.',
    'giver' => 'Mom',
    'prerequisites' => [
      ['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'completed'],
    ],
    'objectives' => [
      ['type' => 'defeat', 'target' => 'Sewer Rat', 'quantity' => 2],
    ],
    'rewards' => [
      'gold' => 500,
      'experience' => 50,
    ],
  ],
];
