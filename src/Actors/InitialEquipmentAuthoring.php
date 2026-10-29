<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;

/** Current semantic-slot equipment references for the shared actor loadout picker. */
final class InitialEquipmentAuthoring
{
    public const array REFERENCES = ['initial_weapon', 'initial_shield', 'initial_head', 'initial_body', 'initial_accessory'];

    /** @return list<string> */
    public static function getItemIds(ProjectWorkspace $workspace, EquipmentSlotType $slot): array
    {
        $catalog = InventoryCatalog::fromWorkspace($workspace);
        $ids = [];
        foreach (InventoryCatalog::CATEGORIES as $category) {
            foreach ($workspace->getRecordDatabase($category)?->getRecords() ?? [] as $record) {
                $item = InventoryCatalog::readDefinition($record);
                if ($item instanceof Equipment && $item->semanticSlot === $slot && $catalog->has($item->id)) {
                    $ids[] = $item->id;
                }
            }
        }
        return $ids;
    }
}
