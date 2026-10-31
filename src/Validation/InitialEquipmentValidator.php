<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Entities\Actors\InitialEquipment;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Stats;
use InvalidArgumentException;

final class InitialEquipmentValidator
{
    /** @return list<Issue> */
    public function validate(ProjectWorkspace $workspace): array
    {
        $issues = [];
        $catalog = InventoryCatalog::fromWorkspace($workspace);
        $allowed = array_fill_keys(array_map(static fn(EquipmentSlotType $slot): string => $slot->value, EquipmentSlotType::cases()), []);
        $items = [];
        foreach (InventoryCatalog::CATEGORIES as $category) {
            foreach ($workspace->getRecordDatabase($category)?->getRecords() ?? [] as $record) {
                $item = InventoryCatalog::readDefinition($record);
                if ($item instanceof Equipment && $catalog->has($item->id)) {
                    $items[$item->id] = $item;
                    $allowed[$item->semanticSlot->value][] = $item->id;
                }
            }
        }
        $classes = [];
        foreach ($workspace->getRecordDatabase('classes')?->getRecords() ?? [] as $record) {
            $classes[strtolower((string) $record->get('name'))] = $record->toArray();
        }
        foreach ($workspace->actorDatabase->getActors() as $actor) {
            try {
                $loadout = InitialEquipment::fromArray($actor->getData()['initialEquipment'] ?? [], $actor->getName());
                $class = $classes[strtolower($actor->getClassName())] ?? [];
                $types = static fn(array $names, bool $weapon): array => array_values(array_filter(array_map(
                    static fn($name) => Equipment::resolveEquipmentType($name, $weapon), $names)));
                $role = $loadout->items === [] ? null : new CharacterRole(new Character('Equipment validation', 0, new Stats()),
                    $actor->getClassName(),
                    allowedWeaponTypes: $types((array) ($class['equipment']['weapons'] ?? []), true),
                    allowedArmorTypes: $types((array) ($class['equipment']['armor'] ?? []), false));
                foreach ($loadout->items as $slot => $reference) {
                    $id = $catalog->definitionIdFor($reference);
                    if (!in_array($id, $allowed[$slot], true)) {
                        throw new InvalidArgumentException("Starting {$slot} reference '{$reference}' is not equipment for that slot.");
                    }
                    if (!$role->allowsEquipmentType($items[$id]->equipmentType)) {
                        throw new InvalidArgumentException("Starting {$slot} reference '{$reference}' is not allowed by the actor's class.");
                    }
                }
            } catch (InvalidArgumentException $error) {
                $issues[] = Issue::error('actor ' . $actor->getName(), $error->getMessage(), 'Choose current equipment in the Starting Equipment pickers.');
            }
        }
        return $issues;
    }
}
