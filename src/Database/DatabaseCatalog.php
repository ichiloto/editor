<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Field\PlayerPresentationFields;

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
            new DatabaseCategoryDefinition('items', 'Items', 'Manage consumables, key items, and resources.', true),
            new DatabaseCategoryDefinition('weapons', 'Weapons', 'Configure weapon stats and restrictions.', true),
            new DatabaseCategoryDefinition('armors', 'Armors', 'Configure armor stats and resistances.', true),
            new DatabaseCategoryDefinition('enemies', 'Enemies', 'Define enemy stats, traits, and drops.', true),
            new DatabaseCategoryDefinition('troops', 'Troops', 'Compose encounter groups and formations.', true),
            new DatabaseCategoryDefinition('battle_entry_rules', 'Battle Entry', 'Author rules that shape how battles begin.', true),
            new DatabaseCategoryDefinition('states', 'States', 'Define status effects and conditions.', true),
            new DatabaseCategoryDefinition('animations', 'Animations', 'Create reusable keyframed effects.', true),
            new DatabaseCategoryDefinition('tilesets', 'Tilesets', 'Assign tiles and terrain behavior.', true),
            new DatabaseCategoryDefinition('common_events', 'Common Events', 'Author cutscene and event command lists.', true),
            new DatabaseCategoryDefinition('quests', 'Quests', 'Author quests, objectives, and rewards.', true),
            new DatabaseCategoryDefinition('skits', 'Skits', 'Write optional party banter scenes.', true),
            new DatabaseCategoryDefinition('knowledge_subjects', 'Knowledge', 'Author what the party can come to know.', true),
            new DatabaseCategoryDefinition('knowledge_reports', 'Knowledge Reports', 'Author claims about knowledge subjects.', true),
            new DatabaseCategoryDefinition('knowledge_record_types', 'Knowledge Types', 'Declare the kinds of record a subject can be.', true),
            new DatabaseCategoryDefinition('knowledge_enemy_mappings', 'Knowledge Enemies', 'Say which subject an enemy is a record of.', true),
            new DatabaseCategoryDefinition('permanent_growth', 'Permanent Growth', 'Define stat increases the party can earn and keep.', true),
            new DatabaseCategoryDefinition('optimize_weights', 'Optimize Weights', 'Weight what Optimize values, by role and by slot.', true),
            new DatabaseCategoryDefinition('optimize_outcomes', 'Optimize Outcomes', 'Weight elemental outcomes and special properties.', true),
            new DatabaseCategoryDefinition('optimize_exclusions', 'Optimize Exclusions', 'Keep gear out of automatic selection.', true),
            new DatabaseCategoryDefinition('system', 'System', 'Configure system-wide project settings.', true),
            new DatabaseCategoryDefinition('configuration', 'Configuration', 'Adjust saving, accessibility, interface, graphics, audio and inn settings.', true),
            new DatabaseCategoryDefinition('types', 'Types', 'Manage element and weapon-type tables.', true),
            new DatabaseCategoryDefinition('terms', 'Terms', 'Customize UI labels and message terms.', true),
        ];
    }

    /** The GUI's categories; the Terminal registry and its list positions stay unchanged. */
    public static function getGraphicalCategories(): array
    {
        return [...self::all(), new DatabaseCategoryDefinition(PlayerPresentationFields::CATEGORY,
            'Player Field Appearance', 'Choose fixed player art or the selected party leader\'s field role.', true)];
    }

    /**
     * Returns the categories edited from another category's records rather
     * than listed on their own: battle art is set on the actor's or enemy's
     * own page, in the graphical editor, and never shown in the terminal's.
     *
     * @return list<DatabaseCategoryDefinition>
     */
    public static function getEmbedded(): array
    {
        return [
            new DatabaseCategoryDefinition('battler_actors', 'Actor Battle Art', 'The art an actor fights with in a graphical battle.', true, ['actors']),
            new DatabaseCategoryDefinition('battler_enemies', 'Enemy Battle Art', 'The art an enemy fights with in a graphical battle.', true, ['enemies']),
            new DatabaseCategoryDefinition('battle_scale', 'Battle Scale', 'The actor every battler\'s size is measured against.', true, ['actors', 'enemies']),
        ];
    }

    /**
     * Returns whether a key names a category, listed or embedded.
     */
    public static function knows(string $key): bool
    {
        return self::findByKey($key) !== null;
    }

    /**
     * Returns the category a key names, listed or embedded; null when none
     * does. Unlike {@see indexOf()}, which places a listed category in the
     * Database list, this never answers with another category.
     */
    public static function findByKey(string $key): ?DatabaseCategoryDefinition
    {
        return array_find([...self::getGraphicalCategories(), ...self::getEmbedded()], static fn(DatabaseCategoryDefinition $category): bool => $category->key === $key);
    }

    /**
     * Returns the Database list position of a listed category; the first
     * category for any other key, embedded ones included. To name a
     * category by its key, use {@see findByKey()}.
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
