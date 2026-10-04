<?php

use Ichiloto\Engine\Entities\Inventory\ItemCatalog;

// Items, weapons and armors are authored one per file in Items/, Weapons/ and
// Armors/, numbered in the order shops and menus list them.
return ItemCatalog::loadProjectItems(dirname(__DIR__));
