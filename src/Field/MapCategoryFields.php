<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\Projections\KeyedDictionaryProjection;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Engine\Field\MapCategoryCatalog;
use Ichiloto\Engine\Field\MapLandmarkCatalog;
use Ichiloto\Engine\Field\MapLandmarkCategory;
use Throwable;

/**
 * Place Categories: what a game's places offer beyond the Engine's generic inn, shop, home and organization (a
 * school, a temple, a guild), one record per category in the project's map category catalogue (Engine
 * MapCategoryCatalog). Each names the generic category it specializes, whose marker it shows until the theme gives
 * it one of its own. Landmarks name a category by its stable id, so while a landmark uses one its id stays and it
 * cannot be deleted. The Engine's catalogue validates every save.
 */
final class MapCategoryFields
{
    public const string CATEGORY = 'map_categories';

    private function __construct() {}

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'place category',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/' . MapCategoryCatalog::FILE,
            fields: [],
            labelKey: 'id',
            identityKey: 'id',
            makeBlank: self::makeBlank(...),
            projection: new KeyedDictionaryProjection('categories', fileKeys: ['schema' => MapCategoryCatalog::SCHEMA]),
            fieldsFor: self::getFields(...),
            saveCheck: self::checkCatalog(...),
            prepareEdit: self::checkEdit(...),
            requireUnchangedSource: true,
        );
    }

    /**
     * A new category, which saves at once as the Engine admits it: its id is its name's slug, its name takes the
     * Engine's translation key, and it starts as a kind of the Engine's first generic category for the author to
     * change, as a new text role starts on the project's first font.
     */
    public static function makeBlank(string $name, string $projectRoot): array
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $id = preg_match('/^[a-z]/', $slug) === 1 ? $slug : 'place-' . $slug;
        if (preg_match(MapLandmarkCatalog::ID_PATTERN, $id) !== 1 || MapLandmarkCategory::tryFrom($id) !== null) {
            throw new RecordRefusal(sprintf('"%s" cannot name a place category: its id would be %s, which is not a new stable id.', $name, $id));
        }

        return ['id' => $id, 'name' => ['message' => MapCategoryCatalog::getNameMessageKey($id), 'default' => trim($name)],
            'base' => MapLandmarkCategory::cases()[0]->value];
    }

    /** @return list<RecordField> */
    public static function getFields(array $entry): array
    {
        return [
            // Landmarks name the category by its id: it changes only while none does.
            new RecordField('id', 'Stable Id'),
            new RecordField('name.default', 'Name'),
            new RecordField('name.message', 'Name Translation Key'),
            new RecordField('base', 'Specializes', options: array_column(MapLandmarkCategory::cases(), 'value')),
        ];
    }

    /**
     * Refuses an id the Engine would not accept (a generic category's, or not a stable id) as it is typed, and keeps
     * a name translation key that followed the old id following the new one; a key an author chose stays.
     */
    public static function checkEdit(array $entry, string $field): array
    {
        if ($field !== 'id') { return $entry; }
        $id = $entry['id'] ?? null;
        if (!is_string($id) || preg_match(MapLandmarkCatalog::ID_PATTERN, $id) !== 1 || MapLandmarkCategory::tryFrom($id) !== null) {
            throw new RecordRefusal('A place category id is a new stable lowercase id of 1 to 64 characters, not inn, shop, home or organization.');
        }
        // A key the Engine derived from an id, read from its own rule, follows the id; any other key stays.
        $probe = 'probe';
        $derived = '/\\A' . str_replace($probe, '[a-z][a-z0-9._-]*', preg_quote(MapCategoryCatalog::getNameMessageKey($probe), '/')) . '\\z/';
        $message = $entry['name']['message'] ?? null;
        if (is_string($message) && preg_match($derived, $message) === 1) {
            $entry['name']['message'] = MapCategoryCatalog::getNameMessageKey($id);
        }

        return $entry;
    }

    /** Why the Engine would not read the catalogue as a save would write it, or null when it would. */
    public static function checkCatalog(array $whole, string $projectRoot): ?string
    {
        try {
            MapCategoryCatalog::fromArray($whole);
        } catch (Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }
}
