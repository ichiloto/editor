<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\Projections\KeyedDictionaryProjection;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapLandmarkCatalog;
use Ichiloto\Engine\Field\PlaceTypeCatalog;
use InvalidArgumentException;
use Throwable;

/**
 * Place Types: what kinds of place a game's maps lead into (a city, a town, a village, a castle, an outpost, an inn,
 * a shrine, a cave, a wild area), one record per type in the project's place type catalogue (Engine
 * PlaceTypeCatalog). A map chooses the type of the place it belongs to on its Place Type row; each tileset shows a
 * type by the piece its Place Icons give it, and validation holds every entrance's icon to its destination's type
 * ({@see \Ichiloto\Editor\Validation\PlaceIconCheck}). A map names a type by its stable id, so while a map is of one
 * its id stays and it cannot be deleted. The Engine's catalogue validates every save.
 */
final class PlaceTypeFields
{
    public const string CATEGORY = 'place_types';

    private function __construct() {}

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'place type',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/' . PlaceTypeCatalog::FILE,
            fields: [],
            labelKey: 'id',
            identityKey: 'id',
            makeBlank: self::makeBlank(...),
            projection: new KeyedDictionaryProjection('types', fileKeys: ['schema' => PlaceTypeCatalog::SCHEMA]),
            fieldsFor: self::getFields(...),
            saveCheck: self::checkCatalog(...),
            prepareEdit: self::checkEdit(...),
            requireUnchangedSource: true,
        );
    }

    /** A new type, whose id is its name's slug and whose name takes the Engine's translation key. */
    public static function makeBlank(string $name, string $projectRoot): array
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $id = preg_match('/^[a-z]/', $slug) === 1 ? $slug : 'place-' . $slug;
        if (preg_match(MapLandmarkCatalog::ID_PATTERN, $id) !== 1) {
            throw new RecordRefusal(sprintf('"%s" cannot name a place type: its id would be %s, which is not a stable id.', $name, $id));
        }

        return ['id' => $id, 'name' => ['message' => PlaceTypeCatalog::getNameMessageKey($id), 'default' => trim($name)]];
    }

    /** @return list<RecordField> */
    public static function getFields(array $entry): array
    {
        return [
            // Maps and tilesets name the type by its id: it changes only while none does.
            new RecordField('id', 'Stable Id'),
            new RecordField('name.default', 'Name'),
            new RecordField('name.message', 'Name Translation Key'),
        ];
    }

    /**
     * Refuses an id the Engine would not accept as it is typed, and keeps a name translation key that followed the
     * old id following the new one; a key an author chose stays.
     */
    public static function checkEdit(array $entry, string $field): array
    {
        if ($field !== 'id') { return $entry; }
        $id = $entry['id'] ?? null;
        if (!is_string($id) || preg_match(MapLandmarkCatalog::ID_PATTERN, $id) !== 1) {
            throw new RecordRefusal('A place type id is a stable lowercase id of 1 to 64 characters.');
        }
        $probe = 'probe';
        $derived = '/\\A' . str_replace($probe, '[a-z][a-z0-9._-]*', preg_quote(PlaceTypeCatalog::getNameMessageKey($probe), '/')) . '\\z/';
        $message = $entry['name']['message'] ?? null;
        if (is_string($message) && preg_match($derived, $message) === 1) {
            $entry['name']['message'] = PlaceTypeCatalog::getNameMessageKey($id);
        }

        return $entry;
    }

    /** Why the Engine would not read the catalogue as a save would write it, or null when it would. */
    public static function checkCatalog(array $whole, string $projectRoot): ?string
    {
        try {
            PlaceTypeCatalog::fromArray($whole);
        } catch (Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }

    /**
     * What names a type, in words: the maps that are of it and the tilesets that give it an icon.
     *
     * @param iterable<ProjectMap> $maps
     * @param array<string, array<string, mixed>> $tilesets Tileset sources by id, as the Tilesets page holds them.
     * @return list<string>
     */
    public static function describeTypeReferences(iterable $maps, array $tilesets, string $type): array
    {
        $found = [];
        foreach ($maps as $map) {
            try {
                if (PlaceTypeCatalog::readMapPlaceType($map->getEditableData()) === $type) {
                    $found[] = sprintf('map %s', $map->mapId);
                }
            } catch (InvalidArgumentException) {
                // A malformed type names no type; validation reports it on its map.
            }
        }
        foreach ($tilesets as $id => $tileset) {
            $icons = $tileset[\Ichiloto\Engine\Rendering\Tilesets\Tileset::PLACE_ICONS] ?? null;
            if (is_array($icons) && array_key_exists($type, $icons)) {
                $found[] = sprintf('tileset %s', $id);
            }
        }

        return $found;
    }
}
