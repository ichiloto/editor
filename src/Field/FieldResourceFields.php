<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\Projections\KeyedDictionaryProjection;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordFieldCodec;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\Database\RecordSubList;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\Enumerations\DoorwayEntrance;
use Ichiloto\Engine\Field\WorldObjectResource;
use Ichiloto\Engine\Rendering\Tilesets\TileAppearance;
use Throwable;

/**
 * The project's named field resources: reusable whole images, such as a tree,
 * that maps place by reference. A resource owns its art, its ground contact
 * (pivot), an optional authoring stamp of physical cells and an optional
 * doorway where its art paints an opening; it owns no map collision, which
 * only map occupancy holds. The Engine's catalogue admits the
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
            subLists: [self::getStampList(), self::getDoorwayList(), self::getAttachmentList()],
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

    /** What a resource shows: an image, or a sign board its placements label. */
    public const string IMAGE = 'Image';
    public const string SIGN = 'Sign board';
    /** The font role a new sign board names until its author chooses one. */
    public const string DEFAULT_FONT_ROLE = 'sign';

    /** @return list<RecordField> */
    public static function getFields(array $entry): array
    {
        $sign = array_key_exists('sign', $entry);

        return [
            new RecordField('id', 'Stable Id'),
            new RecordField('name', 'Name'),
            self::getAppearanceField(),
            new RecordField('pivot.x', 'Ground Contact X (0..1)', InputControlType::FLOAT),
            new RecordField('pivot.y', 'Ground Contact Y (0..1)', InputControlType::FLOAT),
            // Not stored: choosing it reshapes the resource ({@see checkEdit()}).
            new RecordField('presentation', 'Shows', options: [self::IMAGE, self::SIGN], displayDefault: $sign ? self::SIGN : self::IMAGE),
            ...($sign ? self::getSignFields() : FieldSpriteFields::getFields($entry)),
        ];
    }

    /** How an image depicts the ground, shared by resources and inline objects; clearing preserves legacy inference. */
    public static function getAppearanceField(): RecordField
    {
        return new RecordField('appearance', 'Appearance', options: ['', ...array_column(TileAppearance::cases(), 'value')],
            removeWhenEmpty: true, displayDefault: '(unclassified)');
    }

    /**
     * A sign board: its image, cut into fixed end caps and a repeating centre so it widens to fit its label, the
     * project font role its label is set in, and the label's padding and width limits. Cuts are source pixels;
     * padding and widths are logical pixels.
     *
     * @return list<RecordField>
     */
    private static function getSignFields(): array
    {
        return [
            RecordField::reference('sign.asset', 'Board Image', 'png_assets'),
            RecordField::reference('sign.fontRole', 'Label Font Role', 'text_roles'),
            new RecordField('sign.left', 'Left Cap Width (source px)', InputControlType::INTEGER),
            new RecordField('sign.right', 'Right Cap Width (source px)', InputControlType::INTEGER),
            new RecordField('sign.repeatWidth', 'Repeating Centre Width (source px)', InputControlType::INTEGER, removeWhenEmpty: true,
                displayDefault: '(the whole centre)'),
            new RecordField('sign.density', 'Pixel Density', InputControlType::INTEGER),
            new RecordField('sign.padding', 'Label Padding (left, top, right, bottom)', codec: RecordFieldCodec::CSV_INTEGERS),
            new RecordField('sign.minimumWidth', 'Minimum Width', InputControlType::INTEGER),
            new RecordField('sign.maximumWidth', 'Maximum Width', InputControlType::INTEGER),
            new RecordField('sign.layer', 'Field Layer', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
        ];
    }

    /** A sign board as a resource first shows: the given image whole as its centre, for the author to cut. */
    private static function makeSign(string $asset, string $fontRole): array
    {
        return ['asset' => $asset, 'fontRole' => $fontRole, 'left' => 0, 'right' => 0, 'density' => 1,
            'padding' => [4, 2, 4, 2], 'minimumWidth' => 24, 'maximumWidth' => 192];
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

    /**
     * The optional doorway: cells relative to a placement's anchor where the art paints a door, and where each door's
     * entrance lies: on the painted opening (the default) or in front of a door painted on a solid face. Validation
     * requires each placement's doors to meet an entrance exactly so; the art conforms to the map, so a doorway that
     * misses its entrance is corrected here or in the art, never by moving the entrance.
     */
    private static function getDoorwayList(): RecordSubList
    {
        return new RecordSubList(key: 'doorway', prefix: 'doorway', singular: 'doorway cell',
            fields: [new RecordField('x', 'Offset X', InputControlType::INTEGER), new RecordField('y', 'Offset Y', InputControlType::INTEGER),
                new RecordField('entrance', 'Entrance (on the door, or in front of it)',
                    options: array_column(DoorwayEntrance::cases(), 'value'), displayDefault: DoorwayEntrance::ON->value)],
            blank: ['x' => 0, 'y' => 0], heading: 'Doorway', removeWhenEmpty: true);
    }

    /**
     * The optional attachments: named features of the art where visuals attach, such as an aerial a signal pulses
     * on. Each has the terminal cell that shows it, relative to a placement's anchor, and its point on the art,
     * normalized like the ground contact. When the art is replaced or re-cropped, the point is updated here once and
     * every visual staged on the feature follows it.
     */
    private static function getAttachmentList(): RecordSubList
    {
        return new RecordSubList(key: 'attachments', prefix: 'attachment', singular: 'attachment',
            fields: [new RecordField('id', 'Stable Id'),
                new RecordField('cell.x', 'Terminal Cell X', InputControlType::INTEGER), new RecordField('cell.y', 'Terminal Cell Y', InputControlType::INTEGER),
                new RecordField('point.x', 'Art Point X (0..1)', InputControlType::FLOAT), new RecordField('point.y', 'Art Point Y (0..1)', InputControlType::FLOAT)],
            blank: ['id' => 'attachment', 'cell' => ['x' => 0, 'y' => 0], 'point' => ['x' => .5, 'y' => .5]], heading: 'Attachments',
            removeWhenEmpty: true);
    }

    /** A stable id is refused as it is typed, by the Engine's own rule, rather than at the save. */
    public static function checkEdit(array $entry, string $field): array
    {
        if ($field === 'presentation') {
            // A resource shows exactly one presentation, as the Engine admits it; switching keeps the image chosen.
            $choice = $entry['presentation'] ?? null;
            unset($entry['presentation']);
            $image = $entry['sign']['asset'] ?? $entry['sprites2d']['asset'] ?? '';
            if ($choice === self::SIGN && ! array_key_exists('sign', $entry)) {
                unset($entry['sprites2d']);
                // The project's sign role by convention; validation names it when the catalogue has none.
                $entry['sign'] = self::makeSign($image, self::DEFAULT_FONT_ROLE);
            } elseif ($choice === self::IMAGE && array_key_exists('sign', $entry)) {
                unset($entry['sign']);
                $entry['sprites2d'] = ['asset' => $image];
            }
        }
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
