<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use BackedEnum;
use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\Events\ChestLoot;
use Ichiloto\Engine\Events\Enumerations\LootType;

/**
 * Checks that a chest can give what it holds.
 *
 * A chest whose reward the Engine cannot grant fails when the player opens
 * it, so every such chest is an error: no item chosen, an item reference the
 * Engine's item store does not resolve, a kind the Engine's chest does not
 * give, a gold amount or quantity that gives nothing. An unfinished chest is
 * still saved as the author left it; the error is how the Editor says it is
 * not ready to play. One check serves the project report, the inspectors of
 * both editors and the play preflight, so they always agree.
 *
 * @package Ichiloto\Editor\Validation
 */
final readonly class ChestRewardValidator
{
    /** Stable identity for consumers that place chest findings, never derived from message prose. */
    public const string CODE = 'chest.reward';

    /**
     * @param InventoryCatalog $inventory What an inventory reference means in the project, by the Engine's identity rules.
     */
    public function __construct(private InventoryCatalog $inventory)
    {
    }

    /**
     * Checks a chest's data.
     *
     * @param array<string, mixed> $data The chest event's `data`.
     * @param string $where Where the chest lives.
     * @return Issue[] The issues found.
     */
    public function validate(array $data, string $where): array
    {
        $declaredType = $data['lootType'] ?? null;
        $lootType = ChestLoot::readLootType($declaredType);

        if (! $lootType instanceof LootType) {
            return [$this->createError(
                $where,
                sprintf('Its loot type "%s" is not one the Engine knows, so the game reads it as an item.', self::describe($declaredType)),
                'Choose the loot type from the picker.',
            )];
        }

        if (! ChestLoot::isGrantable($lootType)) {
            return [$this->createError(
                $where,
                sprintf('It gives %s, which the Engine\'s chests cannot give, so it fails when opened.', self::describeKind($lootType)),
                'Choose an item or gold. Chests need Engine support before they can give this.',
            )];
        }

        $quantity = $data['quantity'] ?? 1;

        if (! self::isWholeNumber($quantity)) {
            return [$this->createError(
                $where,
                sprintf('Its quantity "%s" is not a whole number, so the game cannot read the chest.', self::describe($quantity)),
                'Write a whole number above zero.',
            )];
        }

        $quantity = intval($quantity);
        $loot = $data['loot'] ?? null;

        if ($loot instanceof BackedEnum) {
            $loot = $loot->value;
        }

        return $lootType === LootType::GOLD
            ? $this->validateGold($loot, $quantity, $where)
            : $this->validateInventory($loot, $quantity, $lootType, $where);
    }

    /**
     * Checks a gold chest: its loot is the amount, or its quantity when the loot is not a number.
     *
     * @return Issue[]
     */
    private function validateGold(mixed $loot, int $quantity, string $where): array
    {
        $isBlank = $loot === null || (is_string($loot) && trim($loot) === '');

        if (! $isBlank && ! is_numeric($loot)) {
            return [$this->createError(
                $where,
                sprintf('It gives gold "%s", which is not an amount.', self::describe($loot)),
                'Write a whole number.',
            )];
        }

        $amount = $isBlank ? $quantity : intval($loot);

        if ($amount >= 1) {
            return [];
        }

        return [$this->createError(
            $where,
            $amount === 0
                ? 'It gives 0 gold, so opening it gives nothing.'
                : sprintf('It gives %d gold, which opening it takes from the party.', $amount),
            'Write the amount above zero in its loot, or leave the loot empty and set its quantity.',
        )];
    }

    /**
     * Checks a chest the Engine fills from its item store.
     *
     * @return Issue[]
     */
    private function validateInventory(mixed $loot, int $quantity, LootType $lootType, string $where): array
    {
        $noun = self::describeKind($lootType, article: false);

        if ($loot === null || (is_string($loot) && trim($loot) === '')) {
            return [$this->createError(
                $where,
                sprintf('It has no %s chosen, so it fails when opened.', $noun),
                sprintf('Choose the %s from the picker. The chest saves as it is until then.', $noun),
            )];
        }

        if (! is_scalar($loot)) {
            return [$this->createError(
                $where,
                sprintf('Its loot is %s, not a reference.', get_debug_type($loot)),
                'Choose the loot from the picker.',
            )];
        }

        $reference = trim(strval($loot));
        $issues = [];

        if ($this->inventory->isAmbiguous($reference)) {
            $issues[] = $this->createError(
                $where,
                sprintf('It gives the %s "%s", which more than one definition answers to, so it fails when opened.', $noun, $reference),
                'The Engine refuses an ambiguous reference. Give one of them a distinct name or alias.',
            );
        } elseif (! $this->inventory->has($reference)) {
            $issues[] = $this->createError(
                $where,
                sprintf('It gives the %s "%s", which does not exist, so it fails when opened.', $noun, $reference),
                'Choose it from the picker, which stores the stable id.',
            );
        }

        if ($quantity < 1) {
            $issues[] = $this->createError(
                $where,
                sprintf('Its quantity is %d, so opening it gives nothing.', $quantity),
                'Set the quantity above zero.',
            );
        }

        return $issues;
    }

    private function createError(string $where, string $message, string $hint): Issue
    {
        return Issue::error($where, $message, $hint, self::CODE);
    }

    /** Tells whether a quantity reads as a whole number, the way the Engine's integer property takes it. */
    private static function isWholeNumber(mixed $value): bool
    {
        return is_int($value)
            || (is_float($value) && floor($value) === $value)
            || (is_string($value) && filter_var(trim($value), FILTER_VALIDATE_INT) !== false);
    }

    private static function describe(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return strval($value->value);
        }

        return is_scalar($value) ? strval($value) : get_debug_type($value);
    }

    private static function describeKind(LootType $type, bool $article = true): string
    {
        $noun = match ($type) {
            LootType::ITEM => 'item',
            LootType::GOLD => 'gold',
            LootType::EXPERIENCE => 'experience',
            LootType::SKILL => 'skill',
            LootType::SPELL => 'spell',
            LootType::WEAPON => 'weapon',
            LootType::ARMOR => 'armor',
            LootType::ACCESSORY => 'accessory',
        };

        if (! $article || in_array($type, [LootType::GOLD, LootType::EXPERIENCE, LootType::ARMOR], true)) {
            return $noun;
        }

        return (in_array($noun[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ') . $noun;
    }
}
