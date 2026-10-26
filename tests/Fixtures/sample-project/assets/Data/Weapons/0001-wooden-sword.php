<?php

use Ichiloto\Engine\Entities\Inventory\InventoryItem;

return [
  'class' => InventoryItem::class,
  'data' => [
    'kind' => 'weapon',
    'id' => 'legacy.wooden-sword',
    'name' => 'Wooden Sword',
    'description' => 'A wooden sword.',
    'icon' => '🗡️',
    'price' => 100,
    'equipmentType' => 'Sword',
    'parameterChanges' => [
      'attack' => 1,
    ],
  ],
];
