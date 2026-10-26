<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\ProjectRecord;
use Ichiloto\Editor\Database\Projections\KeyedDictionaryProjection;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordFieldCodec;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapLandmarkCatalog;
use Ichiloto\Engine\Field\MapRoleCatalog;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use InvalidArgumentException;
use Throwable;

/**
 * Map Roles: what a game's maps are to its world (an overworld, an outside place, an interior, a dungeon), one
 * record per role in the project's map role catalogue (Engine MapRoleCatalog), and the roles each one's maps may
 * lead to. A role that names none leads anywhere. A map names its role by its stable id, so while a map uses one
 * its id stays and it cannot be deleted; so does
 * one a tileset is drawn for. The Engine's catalogue validates every save; validation applies the
 * destinations to every way off each map ({@see \Ichiloto\Editor\Validation\MapRoleCheck}).
 */
final class MapRoleFields
{
    public const string CATEGORY = 'map_roles';

    private function __construct() {}

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'map role',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/' . MapRoleCatalog::FILE,
            fields: [],
            labelKey: 'id',
            identityKey: 'id',
            makeBlank: self::makeBlank(...),
            projection: new KeyedDictionaryProjection('roles', fileKeys: ['schema' => MapRoleCatalog::SCHEMA]),
            fieldsFor: self::getFields(...),
            saveCheck: self::checkCatalog(...),
            prepareEdit: self::checkEdit(...),
            requireUnchangedSource: true,
        );
    }

    /** A new role, which leads anywhere until the author chooses where its maps may lead. */
    public static function makeBlank(string $name, string $projectRoot): array
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $id = preg_match('/^[a-z]/', $slug) === 1 ? $slug : 'role-' . $slug;
        if (preg_match(MapLandmarkCatalog::ID_PATTERN, $id) !== 1) {
            throw new RecordRefusal(sprintf('"%s" cannot name a map role: its id would be %s, which is not a stable id.', $name, $id));
        }

        return ['id' => $id, 'name' => ['message' => MapRoleCatalog::getNameMessageKey($id), 'default' => trim($name)]];
    }

    /** @return list<RecordField> */
    public static function getFields(array $entry): array
    {
        return [
            // Maps name the role by its id: it changes only while none does.
            new RecordField('id', 'Stable Id'),
            new RecordField('name.default', 'Name'),
            new RecordField('name.message', 'Name Translation Key'),
            new RecordField('leadsTo', 'Leads To', codec: RecordFieldCodec::CSV_LIST, reference: self::CATEGORY,
                removeWhenEmpty: true, displayDefault: '(any map)'),
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
            throw new RecordRefusal('A map role id is a stable lowercase id of 1 to 64 characters.');
        }
        $probe = 'probe';
        $derived = '/\\A' . str_replace($probe, '[a-z][a-z0-9._-]*', preg_quote(MapRoleCatalog::getNameMessageKey($probe), '/')) . '\\z/';
        $message = $entry['name']['message'] ?? null;
        if (is_string($message) && preg_match($derived, $message) === 1) {
            $entry['name']['message'] = MapRoleCatalog::getNameMessageKey($id);
        }

        return $entry;
    }

    /** Why the Engine would not read the catalogue as a save would write it, or null when it would. */
    public static function checkCatalog(array $whole, string $projectRoot): ?string
    {
        try {
            MapRoleCatalog::fromArray($whole);
        } catch (Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }

    /**
     * What names a role, in words: the maps that are of it and the tilesets drawn for it. Another role leading to
     * it is refused by the Engine's catalogue check when it saves.
     *
     * @param iterable<ProjectMap> $maps
     * @param iterable<ProjectRecord> $tilesets The tileset records, unsaved edits included.
     * @return list<string>
     */
    public static function describeRoleReferences(iterable $maps, string $role, iterable $tilesets = []): array
    {
        $found = [];
        foreach ($tilesets as $tileset) {
            $roles = $tileset->get(Tileset::ROLES_KEY);
            if (is_array($roles) && in_array($role, $roles, true)) {
                $found[] = sprintf('tileset %s', $tileset->recordId);
            }
        }
        foreach ($maps as $map) {
            try {
                if (MapRoleCatalog::readMapRole($map->getEditableData()) === $role) {
                    $found[] = sprintf('map %s', $map->mapId);
                }
            } catch (InvalidArgumentException) {
                // A malformed role names no role; validation reports it on its map.
            }
        }

        return $found;
    }
}
