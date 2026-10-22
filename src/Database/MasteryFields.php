<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Database\Projections\KeyedListProjection;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryMap;
use Ichiloto\Engine\Progression\Mastery\MasteryRegion;
use InvalidArgumentException;
use Throwable;

/**
 * The Mastery Map categories: the regions every map shares
 * (`assets/Data/mastery.php`) and one map per character
 * (`assets/Data/Mastery/*.php`). Each map is a list of nodes naming the
 * skills it teaches; both editors write them through these records, and the
 * Engine's own readers refuse a map it could not read before anything is
 * written. A node's place on the graphical map is authored in the GUI editor;
 * the Terminal editor keeps it as it is.
 */
final class MasteryFields
{
    public const string REGIONS = 'mastery_regions';
    public const string MAPS = 'mastery_maps';

    private function __construct()
    {
    }

    public static function getRegionSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::REGIONS,
            entryNoun: 'mastery region',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/' . MasteryCatalog::SETTINGS,
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('name', 'Name'),
                new RecordField('description', 'Description', removeWhenEmpty: true),
            ],
            labelKey: 'name',
            identityKey: 'id',
            blank: ['id' => 'new-region', 'name' => 'New Region'],
            projection: new KeyedListProjection('regions'),
            saveCheck: self::checkRegions(...),
        );
    }

    public static function getMapSchema(bool $graphical = false): RecordSchema
    {
        $nodeFields = [
            new RecordField('id', 'Id'),
            RecordField::reference('skill', 'Skill', 'skills'),
            RecordField::reference('region', 'Region', self::REGIONS),
            // The Mastery points (SP) a character spends to learn it; 0 is learned as soon as it opens.
            new RecordField('cost', 'Cost (SP)', InputControlType::INTEGER),
            new RecordField('opensAtLevel', 'Opens At Level', InputControlType::INTEGER),
            // The ids of the nodes this one builds on, from the same map.
            new RecordField('requires', 'Requires', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
            // Menus show ??? for it while it is locked.
            RecordField::boolean('hidden', 'Secret Until Unlocked'),
            ...($graphical ? [
                new RecordField('position.x', 'Map X (0 to 1)', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField('position.y', 'Map Y (0 to 1)', InputControlType::FLOAT, removeWhenEmpty: true),
            ] : []),
        ];

        return new RecordSchema(
            key: self::MAPS,
            entryNoun: 'mastery map',
            storage: RecordStorage::DIRECTORY,
            relativePath: 'assets/' . MasteryCatalog::DIRECTORY,
            fields: [RecordField::reference('actor', 'Actor', 'actor_ids')],
            labelKey: 'actor',
            identityKey: 'actor',
            // A map created for a character takes their id; one created without starts under a placeholder to rename.
            blank: ['actor' => 'new-mastery-map', 'nodes' => []],
            subList: new RecordSubList(
                key: 'nodes',
                prefix: 'node',
                singular: 'node',
                fields: $nodeFields,
                blank: ['id' => 'new-node', 'skill' => '', 'region' => '', 'cost' => 0, 'opensAtLevel' => 1],
                heading: 'Nodes',
            ),
            saveCheck: self::checkMap(...),
        );
    }

    /**
     * Why the Engine could not read the regions as they would be saved.
     *
     * @param array<array-key, mixed> $whole The settings file, as it would be saved.
     */
    public static function checkRegions(array $whole, string $projectRoot): ?string
    {
        $seen = [];
        foreach (is_array($whole['regions'] ?? null) ? $whole['regions'] : [] as $index => $region) {
            try {
                $id = MasteryRegion::fromArray(is_array($region) ? $region : [])->id;
            } catch (InvalidArgumentException $error) {
                return sprintf('Mastery region %d could not be read: %s', intval($index) + 1, $error->getMessage());
            }
            if (isset($seen[$id])) {
                return sprintf('Mastery region %s is declared twice.', $id);
            }
            $seen[$id] = true;
        }

        return null;
    }

    /**
     * Why the Engine could not read a map as it would be saved: an unknown
     * region, skill or required node, a loop, or a node it cannot read.
     *
     * @param array<array-key, mixed> $whole The map, as it would be saved.
     */
    public static function checkMap(array $whole, string $projectRoot): ?string
    {
        $regionIds = array_map(static fn(MasteryRegion $region): string => $region->id,
            MasteryCatalog::load($projectRoot . '/assets')->regions);
        try {
            $map = MasteryMap::fromArray($whole, $regionIds);
        } catch (Throwable $error) {
            return sprintf('The mastery map could not be read: %s', $error->getMessage());
        }
        $skills = SkillCatalog::load($projectRoot . '/assets');
        foreach ($map->nodes as $node) {
            if ($skills->findSkill($node->skill) === null) {
                return sprintf('%s\'s mastery node %s teaches unknown skill "%s".', $map->actorId, $node->id, $node->skill);
            }
        }

        return null;
    }
}
