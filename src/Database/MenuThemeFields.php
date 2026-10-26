<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\MenuPresentationSettings;
use Ichiloto\Engine\UI\Presentation\MapAtlasTheme;

/**
 * Menu Theme: the settings of the game's shared menu presentation catalog, one row per setting by its path, as
 * Configuration edits config.php. It offers the minimap atlas (the compact and larger corner sizes, their margin,
 * the marker size, the frame the atlas is drawn in and the corner map's opacity) and the icon bindings, among them a marker per atlas role
 * and place category. These are graphical presentation, so the category is the graphical editor's; the Terminal's
 * maps keep their own symbols.
 * MenuPresentationSettings owns the file, its defaults and the values the Engine accepts.
 */
final class MenuThemeFields
{
    public const string CATEGORY = 'menu_theme';

    private function __construct() {}

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'setting',
            storage: RecordStorage::CONFIG_SUBTREE,
            relativePath: MenuPresentationSettings::RELATIVE_PATH,
            fields: [
                new RecordField('path', 'Setting', isReadOnly: true),
                new RecordField('value', 'Value'),
            ],
            labelKey: 'path',
            identityKey: 'path',
            configPath: [MenuPresentationSettings::ATLAS, MenuPresentationSettings::ICONS],
            fieldsFor: self::getFields(...),
        );
    }

    /**
     * A setting's rows: its path, and its value as the kind the Engine reads, showing the Engine's default where the
     * catalog leaves the setting out. The atlas frame is chosen among the frames the Engine draws an atlas in.
     *
     * @return list<RecordField>
     */
    public static function getFields(array $row): array
    {
        $default = array_key_exists('default', $row) ? ProjectRecord::stringify($row['default']) : null;
        $kind = array_key_exists('default', $row) ? $row['default'] : ($row['value'] ?? null);

        $path = (string) ($row['path'] ?? '');
        return [
            new RecordField('path', 'Setting', isReadOnly: true),
            match (true) {
                // An icon is a project image chosen with the shared picker; none leaves the Engine's own treatment.
                MenuPresentationSettings::isIcon($path) => RecordField::reference('value', 'Image', 'png_assets', allowsNone: true,
                    noneLabel: array_key_exists('default', $row) ? '(Engine pictogram)' : '(none)'),
                ($row['path'] ?? null) === MenuPresentationSettings::ATLAS . '.frameRole'
                    => new RecordField('value', 'Value', options: MapAtlasTheme::FRAME_ROLES, displayDefault: $default),
                is_int($kind) => new RecordField('value', self::describeRange((string) ($row['path'] ?? '')),
                    InputControlType::INTEGER, displayDefault: $default),
                // A fraction such as the corner map's opacity: a number, checked by the Engine with its neighbours.
                is_float($kind) => new RecordField('value', self::describeRange($path), InputControlType::FLOAT, displayDefault: $default),
                default => new RecordField('value', 'Value', displayDefault: $default),
            },
        ];
    }

    /** A number's label with the logical-pixel range the Engine accepts for it, so an author sees it before a refusal. */
    private static function describeRange(string $path): string
    {
        $range = match ($path) {
            'atlas.compactWidth', 'atlas.compactHeight', 'atlas.largeWidth', 'atlas.largeHeight'
                => [MapAtlasTheme::MIN_EXTENT, MapAtlasTheme::MAX_EXTENT],
            'atlas.margin' => [0, MapAtlasTheme::MAX_MARGIN],
            'atlas.markerSize' => [MapAtlasTheme::MIN_MARKER_SIZE, MapAtlasTheme::MAX_MARKER_SIZE],
            default => null,
        };

        return match (true) {
            $range !== null => sprintf('Pixels (%d..%d)', ...$range),
            // Opacity's own meaning: how much of what lies beneath shows through.
            $path === 'atlas.cornerOpacity' => 'Opacity (0 clear, 1 solid)',
            default => 'Value',
        };
    }
}
