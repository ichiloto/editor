<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\FieldPresentationSettings;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Animations\Field\FieldCueStyle;
use Ichiloto\Engine\Animations\Field\FieldWaymarkStyle;

/**
 * Field Cues: how the game draws cue marks and objective waymarks, one row
 * per setting. The cue style keeps marks readable on any ground (a gentle bob, the edge pointer's spacing, and the Terminal cell's
 * background and bold); each cue colour names its upright mark effect, the
 * plate behind it and the eight pointer images an edge marker turns; the waymark style picks the
 * colour, symbol and edge inset. Both editors offer it, since the Terminal
 * draws cues too. FieldPresentationSettings owns the file.
 */
final class FieldCueFields
{
    public const string CATEGORY = 'field_cues';
    /** The terminal colours a cue may take, in Symfony formatter names. */
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
            configPath: [FieldPresentationSettings::CUE_STYLE, FieldPresentationSettings::CUES, FieldPresentationSettings::WAYMARKS],
            fieldsFor: self::getFields(...),
        );
    }

    /** @return list<RecordField> A setting's rows, showing the Engine's default where the catalog is silent. */
    public static function getFields(array $row): array
    {
        $default = array_key_exists('default', $row) ? ProjectRecord::stringify($row['default']) : null;
        $path = (string) ($row['path'] ?? '');
        $style = FieldPresentationSettings::CUE_STYLE;

        return [
            new RecordField('path', 'Setting', isReadOnly: true),
            match (true) {
                $path === FieldPresentationSettings::WAYMARKS . '.color', $path === "{$style}.terminal.background"
                    => new RecordField('value', 'Colour', options: self::COLORS, displayDefault: $default),
                $path === FieldPresentationSettings::WAYMARKS . '.inset' => new RecordField('value',
                    sprintf('Edge inset (0..%d cells)', FieldWaymarkStyle::MAX_INSET), InputControlType::INTEGER, displayDefault: $default),
                $path === FieldPresentationSettings::WAYMARKS . '.symbol' => new RecordField('value', 'Symbol (one cell)', displayDefault: $default),
                str_starts_with($path, FieldPresentationSettings::CUES . '.') && str_ends_with($path, '.plate')
                    => RecordField::reference('value', 'Plate image', 'png_assets', allowsNone: true, noneLabel: '(no plate)'),
                $path === "{$style}.bob.height" => new RecordField('value',
                    sprintf('Bob height (0..%d px)', FieldCueStyle::MAX_BOB_HEIGHT), InputControlType::INTEGER, displayDefault: $default),
                $path === "{$style}.bob.seconds" => new RecordField('value', 'Bob seconds (0.4..4)', InputControlType::FLOAT, displayDefault: $default),
                $path === "{$style}.pointerSpacing.x", $path === "{$style}.pointerSpacing.y" => new RecordField('value',
                    sprintf('Pointer spacing %s (0..%d px)', str_ends_with($path, '.x') ? 'across' : 'up or down',
                        FieldCueStyle::MAX_POINTER_SPACING), InputControlType::INTEGER, displayDefault: $default),
                $path === "{$style}.terminal.bold" => RecordField::boolean('value', 'Bold'),
                str_ends_with($path, '.effect') => RecordField::reference('value', 'Mark effect', 'effects'),
                str_contains($path, '.pointer.') && str_ends_with($path, '.asset')
                    => RecordField::reference('value', 'Pointer image', 'png_assets', allowsNone: true),
                str_contains($path, '.pointer.') && str_ends_with($path, '.quarterTurns')
                    => new RecordField('value', 'Quarter turns (0..3 clockwise)', InputControlType::INTEGER, displayDefault: $default),
                // Images that baked the mark into each edge are read-only until split into pointers.
                str_contains($path, '.edges.') => new RecordField('value', 'Old edge image (split into pointers)', isReadOnly: true),
                default => new RecordField('value', 'Value', displayDefault: $default),
            },
        ];
    }
}
