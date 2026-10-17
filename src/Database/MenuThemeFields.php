<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\MenuPresentationSettings;
use Ichiloto\Engine\UI\Presentation\MapAtlasTheme;

/**
 * Menu Theme: the settings of the game's shared menu presentation catalog, one row per setting by its path, as
 * Configuration edits config.php. It starts with the minimap atlas: the compact and larger corner sizes, their
 * margin, the marker size and the frame the atlas is drawn in. These are graphical presentation (logical pixels of
 * the GPUI atlas), so the category is the graphical editor's; the Terminal's opened map keeps its own layout.
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
            configPath: [MenuPresentationSettings::ATLAS],
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

        return [
            new RecordField('path', 'Setting', isReadOnly: true),
            match (true) {
                ($row['path'] ?? null) === MenuPresentationSettings::ATLAS . '.frameRole'
                    => new RecordField('value', 'Value', options: MapAtlasTheme::FRAME_ROLES, displayDefault: $default),
                is_int($kind) => new RecordField('value', 'Value', InputControlType::INTEGER, displayDefault: $default),
                default => new RecordField('value', 'Value', displayDefault: $default),
            },
        ];
    }
}
