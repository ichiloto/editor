<?php

use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ParameterChanges;

return [
  new Item('S-Potion', 'A potion that restores 50 HP.', '🧪', 50),
  new Item('Antidote', 'Cures Poison.', '🧪', 80),
  new Weapon('Wooden Sword', 'A wooden sword.', '🗡️', 100, 1, equipmentType: WeaponType::SWORD, parameterChanges: new ParameterChanges(attack: 1)),
];
