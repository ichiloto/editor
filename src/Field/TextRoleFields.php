<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\Projections\KeyedDictionaryProjection;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\Database\RecordSubList;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Rendering\Presentation\Text\ProjectTextCatalog;
use Throwable;

/**
 * The project's text roles: how text such as a sign's label is set, by an
 * ordered chain of the project's own font files, a size and a colour. The
 * Engine embeds no font; a project may use any font it has the right to.
 * The Engine's catalogue admits the file, so the editor refuses a save it
 * would refuse.
 */
final class TextRoleFields
{
    public const string CATEGORY = 'text_roles';

    /** The colour kinds a role's foreground may take. */
    private const array COLOUR_KINDS = ['rgb', 'ansi16', 'ansi256'];

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'text role',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/' . ProjectTextCatalog::FILE,
            fields: [],
            labelKey: 'id',
            identityKey: 'id',
            makeBlank: self::makeBlank(...),
            projection: new KeyedDictionaryProjection('roles', fileKeys: ['schema' => ProjectTextCatalog::SCHEMA]),
            fieldsFor: self::getFields(...),
            subLists: [new RecordSubList(key: 'fonts', prefix: 'font', singular: 'font',
                fields: [RecordField::reference('asset', 'Font File', 'font_assets'),
                    new RecordField('faceIndex', 'Face Index', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0')],
                blank: ['asset' => ''], heading: 'Fonts, first choice first')],
            saveCheck: self::checkCatalog(...),
            prepareEdit: self::checkEdit(...),
            requireUnchangedSource: true,
        );
    }

    /** A new role, which saves at once as the Engine admits it: set in the project's first font. */
    public static function makeBlank(string $name, string $projectRoot): array
    {
        $fonts = ReferenceCatalog::getFontAssets($projectRoot);
        if ($fonts === []) {
            throw new RecordRefusal('A new text role starts on the project\'s first font. Add a font file (.ttf or .otf) under assets first.');
        }
        $id = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return ['id' => preg_match('/^[a-z]/', $id) === 1 ? $id : 'role-' . $id, 'fonts' => [['asset' => $fonts[0]]], 'fontSize' => 12,
            'foreground' => ['kind' => 'rgb', 'r' => 255, 'g' => 255, 'b' => 255]];
    }

    /** @return list<RecordField> */
    public static function getFields(array $entry): array
    {
        $kind = $entry['foreground']['kind'] ?? 'rgb';
        $colour = $kind === 'rgb'
            ? [new RecordField('foreground.r', 'Red (0..255)', InputControlType::INTEGER),
                new RecordField('foreground.g', 'Green (0..255)', InputControlType::INTEGER),
                new RecordField('foreground.b', 'Blue (0..255)', InputControlType::INTEGER)]
            : [new RecordField('foreground.index', 'Palette Index', InputControlType::INTEGER)];

        return [
            new RecordField('id', 'Stable Id'),
            new RecordField('fontSize', 'Font Size', InputControlType::FLOAT),
            new RecordField('foreground.kind', 'Colour Kind', options: self::COLOUR_KINDS),
            ...$colour,
            // Glyph effects are typed PHP values; they are kept as authored, never rewritten here.
            ...(array_key_exists('glyphEffects', $entry) ? [new RecordField('glyphEffects', 'Glyph Effects', isReadOnly: true)] : []),
        ];
    }

    /** A role's id and colour kind are checked and reshaped as they are typed, by the Engine's own rules. */
    public static function checkEdit(array $entry, string $field): array
    {
        if ($field === 'id') {
            try {
                ProjectTextCatalog::assertRoleId($entry['id'] ?? null);
            } catch (Throwable $error) {
                throw new RecordRefusal($error->getMessage(), previous: $error);
            }
        }
        if ($field === 'foreground.kind') {
            $kind = $entry['foreground']['kind'] ?? 'rgb';
            $entry['foreground'] = $kind === 'rgb'
                ? ['kind' => 'rgb', 'r' => 255, 'g' => 255, 'b' => 255]
                : ['kind' => $kind, 'index' => $entry['foreground']['index'] ?? 15];
        }

        return $entry;
    }

    /** The Engine's own admission of the whole catalogue, so a save never writes a file the game refuses. */
    public static function checkCatalog(array $whole, string $projectRoot): ?string
    {
        try {
            ProjectTextCatalog::fromArray($whole);
        } catch (Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }
}
