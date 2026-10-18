<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use BackedEnum;
use Ichiloto\Engine\Events\Enumerations\LootType;

/**
 * What a chest event's loot means to the Engine, for every editor surface
 * that authors or checks one.
 *
 * The rules are the Engine's, mirrored rather than reinvented.
 * `ChestEventTrigger::configure()` reads the trigger data after a JSON round
 * trip, so a `LootType` case and its string value are the same declaration;
 * it reads the type with `LootType::tryFrom()`, exactly and case-sensitively,
 * and reads a missing, blank or unknown type as an item. `ChestOpeningAction`
 * then grants gold as an amount and resolves every other kind through the
 * `ItemStore`, so only gold and inventory kinds can be given. A skill, spell
 * or experience chest names something the item store does not hold.
 *
 * The Engine does not yet publish which kinds its chest grants, so the set
 * below mirrors `ChestOpeningAction`. It is the one place the Editor states
 * it: the validator, the inspector's loot type choices and the GUI editor
 * all read it from here, and it is replaced by the Engine's own answer once
 * that contract exists.
 *
 * @package Ichiloto\Editor\Events
 */
final class ChestLoot
{
    /** The trigger class a chest event declares, matched the way the validator matches trigger classes. */
    public const string TRIGGER = 'ChestEventTrigger';

    /** The kinds `ChestOpeningAction` grants: gold as an amount, the rest from the item store. */
    private const array GRANTABLE = [
        LootType::GOLD,
        LootType::ITEM,
        LootType::WEAPON,
        LootType::ARMOR,
        LootType::ACCESSORY,
    ];

    private function __construct()
    {
    }

    /**
     * Tells whether an event definition is a chest.
     *
     * @param array<string, mixed> $definition The event definition.
     */
    public static function isChest(array $definition): bool
    {
        return str_contains(strval($definition['class'] ?? ''), self::TRIGGER);
    }

    /**
     * Reads a declared loot type the way the Engine does.
     *
     * A missing or blank type is an item, as the Engine reads it. A value the
     * Engine does not know returns null: the Engine would read it as an item
     * too, but silently, so the author has to be told.
     *
     * @param mixed $declared The `lootType` data value: a case, its value, or nothing.
     * @return LootType|null The type the chest holds, or null when the Engine does not know it.
     */
    public static function readLootType(mixed $declared): ?LootType
    {
        if ($declared instanceof LootType) {
            return $declared;
        }

        if ($declared instanceof BackedEnum) {
            $declared = $declared->value;
        }

        if ($declared === null || $declared === '') {
            return LootType::ITEM;
        }

        return is_string($declared) || is_int($declared) ? LootType::tryFrom(strval($declared)) : null;
    }

    /**
     * Tells whether the Engine's chest can give this kind of loot.
     */
    public static function isGrantable(LootType $type): bool
    {
        return in_array($type, self::GRANTABLE, true);
    }

    /**
     * Tells whether the Engine's chest gives this kind from the item store.
     */
    public static function isInventory(LootType $type): bool
    {
        return $type !== LootType::GOLD && self::isGrantable($type);
    }
}
