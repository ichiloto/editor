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

    /** The colour kinds a role's foreground and glyph effects may take. */
    private const array COLOUR_KINDS = ['rgb', 'ansi16', 'ansi256'];

    /** Whether a role's glyphs carry an outline and a shadow, as the Engine's plain descriptor holds both or neither. */
    public const string NO_EFFECTS = 'None';
    public const string OUTLINE_AND_SHADOW = 'Outline and shadow';

    /** The colours a role names, each a typed descriptor reshaped when its kind changes. */
    private const array COLOUR_PATHS = ['foreground', 'glyphEffects.outline.color', 'glyphEffects.shadow.color'];

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
        return [
            new RecordField('id', 'Stable Id'),
            new RecordField('fontSize', 'Font Size', InputControlType::FLOAT),
            ...self::getColourFields($entry, 'foreground', 'Colour'),
            ...self::getEffectFields($entry),
        ];
    }

    /**
     * A role's glyph effects, as the Engine's plain descriptor holds them: an outline and a shadow, each with its
     * colour. Effects written as constructed PHP values are shown and kept as authored, never rewritten here.
     *
     * @return list<RecordField>
     */
    private static function getEffectFields(array $entry): array
    {
        $effects = $entry['glyphEffects'] ?? null;
        if ($effects !== null && ! is_array($effects)) {
            return [new RecordField('glyphEffects', 'Glyph Effects', isReadOnly: true)];
        }
        $choice = new RecordField('effects', 'Glyph Effects', options: [self::NO_EFFECTS, self::OUTLINE_AND_SHADOW],
            displayDefault: $effects === null ? self::NO_EFFECTS : self::OUTLINE_AND_SHADOW);
        if ($effects === null) {
            return [$choice];
        }

        return [
            $choice,
            new RecordField('glyphEffects.outline.width', 'Outline Width (0..4 px)', InputControlType::FLOAT),
            ...self::getColourFields($entry, 'glyphEffects.outline.color', 'Outline Colour'),
            new RecordField('glyphEffects.shadow.offsetX', 'Shadow Offset X (-8..8 px)', InputControlType::FLOAT),
            new RecordField('glyphEffects.shadow.offsetY', 'Shadow Offset Y (-8..8 px)', InputControlType::FLOAT),
            new RecordField('glyphEffects.shadow.sigma', 'Shadow Blur (0..4)', InputControlType::FLOAT),
            new RecordField('glyphEffects.shadow.opacity', 'Shadow Opacity (0..1)', InputControlType::FLOAT),
            ...self::getColourFields($entry, 'glyphEffects.shadow.color', 'Shadow Colour'),
        ];
    }

    /**
     * A colour's kind and the components that kind takes.
     *
     * @return list<RecordField>
     */
    private static function getColourFields(array $entry, string $path, string $label): array
    {
        $kind = self::readPath($entry, $path)['kind'] ?? 'rgb';

        return [
            new RecordField($path . '.kind', $label . ' Kind', options: self::COLOUR_KINDS),
            ...($kind === 'rgb'
                ? [new RecordField($path . '.r', $label . ' Red (0..255)', InputControlType::INTEGER),
                    new RecordField($path . '.g', $label . ' Green (0..255)', InputControlType::INTEGER),
                    new RecordField($path . '.b', $label . ' Blue (0..255)', InputControlType::INTEGER)]
                : [new RecordField($path . '.index', $label . ' Palette Index', InputControlType::INTEGER)]),
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
        if ($field === 'effects') {
            // The descriptor holds an outline and a shadow together, so effects come and go whole.
            $choice = $entry['effects'] ?? null;
            unset($entry['effects']);
            if ($choice === self::OUTLINE_AND_SHADOW && ! is_array($entry['glyphEffects'] ?? null)) {
                $entry['glyphEffects'] = self::makeEffects();
            } elseif ($choice === self::NO_EFFECTS) {
                unset($entry['glyphEffects']);
            }
        }
        foreach (self::COLOUR_PATHS as $path) {
            if ($field === $path . '.kind') {
                $colour = self::readPath($entry, $path);
                $kind = $colour['kind'] ?? 'rgb';
                $light = $path === 'foreground';
                $entry = self::writePath($entry, $path, $kind === 'rgb'
                    ? ['kind' => 'rgb', 'r' => $light ? 255 : 0, 'g' => $light ? 255 : 0, 'b' => $light ? 255 : 0]
                    : ['kind' => $kind, 'index' => $colour['index'] ?? ($light ? 15 : 0)]);
            }
        }

        return $entry;
    }

    /** Glyph effects as they first appear: no outline, and a hard one-pixel shadow down and to the right, in black. */
    private static function makeEffects(): array
    {
        return ['outline' => ['width' => 0, 'color' => ['kind' => 'rgb', 'r' => 0, 'g' => 0, 'b' => 0]],
            'shadow' => ['offsetX' => 1, 'offsetY' => 1, 'sigma' => 0, 'opacity' => 1,
                'color' => ['kind' => 'rgb', 'r' => 0, 'g' => 0, 'b' => 0]]];
    }

    /** @return array<string, mixed> The value at a dotted path, or none. */
    private static function readPath(array $entry, string $path): array
    {
        $value = $entry;
        foreach (explode('.', $path) as $key) {
            $value = is_array($value) ? $value[$key] ?? null : null;
        }

        return is_array($value) ? $value : [];
    }

    private static function writePath(array $entry, string $path, array $value): array
    {
        [$key, $rest] = array_pad(explode('.', $path, 2), 2, null);
        $entry[$key] = $rest === null ? $value : self::writePath(is_array($entry[$key] ?? null) ? $entry[$key] : [], $rest, $value);

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
