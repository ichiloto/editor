<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\Projections\KeyedDictionaryProjection;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\Database\RecordSubList;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\WorldObjectResource;
use Throwable;

/**
 * The project's named field resources: reusable whole images, such as a tree,
 * that maps place by reference. A resource owns its art, its ground contact
 * (pivot) and an optional authoring stamp of physical cells; it owns no map
 * collision, which only map occupancy holds. The Engine's catalogue admits the
 * file, so the editor refuses a save it would refuse.
 */
final class FieldResourceFields
{
    public const string CATEGORY = 'field_resources';

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'field resource',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/' . FieldPresentationCatalog::FILE,
            fields: [],
            labelKey: 'name',
            identityKey: 'id',
            makeBlank: self::makeBlank(...),
            projection: new KeyedDictionaryProjection('resources'),
            fieldsFor: self::getFields(...),
            subLists: [self::getStampList()],
            saveCheck: self::checkCatalog(...),
            prepareEdit: self::checkEdit(...),
            requireUnchangedSource: true,
        );
    }

    /**
     * A new resource, which saves at once as the Engine admits it: it starts on the project's first image, for the
     * author to replace, since a resource without art is not one.
     */
    public static function makeBlank(string $name, string $projectRoot): array
    {
        $images = ReferenceCatalog::getPngAssets($projectRoot);
        sort($images);
        if ($images === []) {
            throw new RecordRefusal('A new field resource starts on an image. Add a PNG under assets first.');
        }
        $id = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return ['id' => preg_match('/^[a-z]/', $id) === 1 ? $id : 'resource-' . $id, 'name' => $name,
            'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => ['asset' => $images[0]]];
    }

    /** @return list<RecordField> */
    public static function getFields(array $entry): array
    {
        return [
            new RecordField('id', 'Stable Id'),
            new RecordField('name', 'Name'),
            new RecordField('pivot.x', 'Ground Contact X (0..1)', InputControlType::FLOAT),
            new RecordField('pivot.y', 'Ground Contact Y (0..1)', InputControlType::FLOAT),
            ...FieldSpriteFields::getFields($entry),
        ];
    }

    /**
     * The optional authoring stamp: cells relative to a placement's anchor and the physical type each gets when a
     * brush places the resource. Placing never infers collision from the art.
     */
    private static function getStampList(): RecordSubList
    {
        $types = array_values(array_filter(CollisionType::cases(), static fn(CollisionType $type): bool => $type !== CollisionType::PASS_THROUGH));

        return new RecordSubList(key: 'occupancyStamp', prefix: 'stamp', singular: 'stamped cell',
            fields: [new RecordField('x', 'Offset X', InputControlType::INTEGER), new RecordField('y', 'Offset Y', InputControlType::INTEGER),
                new RecordField('type', 'Physical Type', options: array_map(static fn(CollisionType $type): string => $type->name, $types),
                    enumClass: CollisionType::class)],
            blank: ['x' => 0, 'y' => 0, 'type' => CollisionType::SOLID], heading: 'Occupancy Stamp', removeWhenEmpty: true);
    }

    /** A stable id is refused as it is typed, by the Engine's own rule, rather than at the save. */
    public static function checkEdit(array $entry, string $field): array
    {
        if ($field === 'id') {
            try {
                WorldObjectResource::assertId($entry['id'] ?? null);
            } catch (Throwable $error) {
                throw new RecordRefusal($error->getMessage(), previous: $error);
            }
        }

        return $entry;
    }

    /** The Engine's own admission of the whole catalogue, so a save never writes a file the game refuses. */
    public static function checkCatalog(array $whole, string $projectRoot): ?string
    {
        try {
            FieldPresentationCatalog::fromArray($whole, $projectRoot . '/assets');
        } catch (Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }
}
