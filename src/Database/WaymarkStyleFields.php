<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\FieldPresentationSettings;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Animations\Field\FieldWaymarkStyle;

/**
 * Waymarks: how the game draws objective waymarks on the field, one row per
 * setting. The colour is a terminal colour and also picks the cue binding
 * whose art draws waymarks in graphics; the symbol is the on-screen mark; the
 * inset keeps edge arrows in from the view edge. Both editors offer it, since
 * the Terminal draws waymarks too. FieldPresentationSettings owns the file.
 */
final class WaymarkStyleFields
{
    public const string CATEGORY = 'waymark_style';
    /** The terminal colours a waymark may take, in Symfony formatter names. */
    public const array COLORS = [
        'black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white',
        'gray', 'bright-red', 'bright-green', 'bright-yellow', 'bright-blue',
        'bright-magenta', 'bright-cyan', 'bright-white',
    ];

    private function __construct() {}

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'setting',
            storage: RecordStorage::CONFIG_SUBTREE,
            relativePath: FieldPresentationSettings::RELATIVE_PATH,
            fields: [
                new RecordField('path', 'Setting', isReadOnly: true),
                new RecordField('value', 'Value'),
            ],
            labelKey: 'path',
            identityKey: 'path',
            configPath: [FieldPresentationSettings::WAYMARKS],
            fieldsFor: self::getFields(...),
        );
    }

    /** @return list<RecordField> A setting's rows, showing the Engine's default where the catalog is silent. */
    public static function getFields(array $row): array
    {
        $default = array_key_exists('default', $row) ? ProjectRecord::stringify($row['default']) : null;

        return [
            new RecordField('path', 'Setting', isReadOnly: true),
            match ((string) ($row['path'] ?? '')) {
                FieldPresentationSettings::WAYMARKS . '.color' => new RecordField('value', 'Colour', options: self::COLORS,
                    displayDefault: $default),
                FieldPresentationSettings::WAYMARKS . '.inset' => new RecordField('value',
                    sprintf('Edge inset (0..%d cells)', FieldWaymarkStyle::MAX_INSET), InputControlType::INTEGER, displayDefault: $default),
                default => new RecordField('value', 'Symbol (one cell)', displayDefault: $default),
            },
        ];
    }
}
