<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\Projections\KeyedDictionaryProjection;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Engine\Field\RegionCatalog;
use Ichiloto\Engine\Field\SkitBackdrop;
use Throwable;

/**
 * Regions: what a project authors for a whole region, the place every map naming it on its Region row belongs to,
 * one record per region in the project's region catalogue (Engine RegionCatalog, `assets/Data/regions.php`), keyed by
 * the region's name exactly as its maps write it. A region's Skit Backdrop is the scene a skit shows on any of its
 * maps that chooses none of its own; it applies to every such map of the region, interiors included. A region needs
 * no record to be a region. The Engine's catalogue validates every save.
 */
final class RegionFields
{
    public const string CATEGORY = 'regions';
    /** How a region with no skit backdrop reads. */
    public const string BACKDROP_NONE = 'None: each map\'s own, or none';

    private function __construct() {}

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'region',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/' . RegionCatalog::FILE,
            fields: [],
            labelKey: 'id',
            identityKey: 'id',
            makeBlank: self::makeBlank(...),
            projection: new KeyedDictionaryProjection('regions', fileKeys: ['schema' => RegionCatalog::SCHEMA]),
            fieldsFor: self::getFields(...),
            saveCheck: self::checkCatalog(...),
            prepareEdit: self::checkEdit(...),
            requireUnchangedSource: true,
        );
    }

    /** A new region record, keyed by the region's name as its maps write it. */
    public static function makeBlank(string $name, string $projectRoot): array
    {
        $name = trim($name);
        if (! RegionCatalog::isRegionName($name)) {
            throw new RecordRefusal(sprintf('A region is named as its maps name it, in 1 to %d characters.', RegionCatalog::MAX_NAME_LENGTH));
        }

        return ['id' => $name];
    }

    /** @return list<RecordField> */
    public static function getFields(array $entry): array
    {
        return [
            // Chosen from the region names the project's maps use, the way a map's own Region row is.
            RecordField::reference('id', 'Region', 'map_regions'),
            new RecordField(SkitBackdrop::MAP_KEY, 'Skit Backdrop', reference: 'png_assets', removeWhenEmpty: true,
                allowsNone: true, displayDefault: self::BACKDROP_NONE),
        ];
    }

    /** Refuses a region name the Engine would not accept as it is chosen. */
    public static function checkEdit(array $entry, string $field): array
    {
        if ($field === 'id' && ! RegionCatalog::isRegionName($entry['id'] ?? null)) {
            throw new RecordRefusal(sprintf('A region is named as its maps name it, in 1 to %d characters.', RegionCatalog::MAX_NAME_LENGTH));
        }

        return $entry;
    }

    /** Why the Engine would not read the catalogue as a save would write it, or null when it would. */
    public static function checkCatalog(array $whole, string $projectRoot): ?string
    {
        try {
            RegionCatalog::fromArray($whole);
        } catch (Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }
}
