<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Provides the editor's Database category registry.
 */
final class DatabaseCatalog
{
    /**
     * Returns the available Database categories.
     *
     * @return DatabaseCategoryDefinition[]
     */
    public static function all(): array
    {
        return [
            new DatabaseCategoryDefinition('actors', 'Actors', 'Manage playable character records.', true),
            new DatabaseCategoryDefinition('classes', 'Classes', 'Define growth, roles, and class data.', true),
            new DatabaseCategoryDefinition('skills', 'Skills', 'Author active and passive skill entries.', true),
            new DatabaseCategoryDefinition('items', 'Items', 'Manage consumables, key items, and resources.'),
            new DatabaseCategoryDefinition('weapons', 'Weapons', 'Configure weapon stats and restrictions.'),
            new DatabaseCategoryDefinition('armors', 'Armors', 'Configure armor stats and resistances.'),
            new DatabaseCategoryDefinition('enemies', 'Enemies', 'Define enemy stats, traits, and drops.'),
            new DatabaseCategoryDefinition('troops', 'Troops', 'Compose encounter groups and formations.'),
            new DatabaseCategoryDefinition('states', 'States', 'Define status effects and conditions.'),
            new DatabaseCategoryDefinition('animations', 'Animations', 'Create reusable keyframed effects.', true),
            new DatabaseCategoryDefinition('tilesets', 'Tilesets', 'Assign tiles and terrain behavior.'),
            new DatabaseCategoryDefinition('common_events', 'Common Events', 'Create reusable event scripts.'),
            new DatabaseCategoryDefinition('system', 'System', 'Configure system-wide project settings.', true),
            new DatabaseCategoryDefinition('types', 'Types', 'Manage element and weapon-type tables.'),
            new DatabaseCategoryDefinition('terms', 'Terms', 'Customize UI labels and message terms.'),
        ];
    }

    /**
     * Returns the category index for the requested key.
     *
     * @param string $key Stable category key.
     * @return int
     */
    public static function indexOf(string $key): int
    {
        foreach (self::all() as $index => $category) {
            if ($category->key === $key) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * Returns the category definition at the requested index.
     *
     * @param int $index The category index.
     * @return DatabaseCategoryDefinition
     */
    public static function at(int $index): DatabaseCategoryDefinition
    {
        return self::all()[$index] ?? self::all()[0];
    }
}
