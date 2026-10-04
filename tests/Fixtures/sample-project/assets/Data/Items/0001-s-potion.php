<?php

use Ichiloto\Engine\Entities\Inventory\InventoryItem;

return [
  'class' => InventoryItem::class,
  'data' => [
    'kind' => 'item',
    'id' => 'legacy.s-potion',
    'name' => 'S-Potion',
    'description' => 'A potion that restores 50 HP.',
    'icon' => '🧪',
    'price' => 50,
  ],
];
