<?php

use Ichiloto\Engine\Entities\Inventory\InventoryItem;

return [
  'class' => InventoryItem::class,
  'data' => [
    'kind' => 'item',
    'id' => 'legacy.antidote',
    'name' => 'Antidote',
    'description' => 'Cures Poison.',
    'icon' => '🧪',
    'price' => 80,
  ],
];
