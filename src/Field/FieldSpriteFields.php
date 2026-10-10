<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordFieldCodec;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;

/** One field-role vocabulary for map objects and cinematic visual overrides. */
final class FieldSpriteFields
{
    public static function getFields(array $entry, bool $explicitAbsence = false, bool $pickSheetIndex = true): array
    {
        $sprites = $entry['sprites2d'] ?? null;
        $fields = [
            RecordField::reference('sprites2d.asset', 'Image / Pose Sheet', 'png_assets', allowsNone: true,
                noneLabel: $explicitAbsence ? 'None: explicit absence' : '(inherit / no graphical override)'),
            RecordField::reference('sprites2d.sheet', 'Walking Character Sheet', 'png_assets', allowsNone: true,
                noneLabel: $explicitAbsence ? 'None: explicit absence' : '(not a walking sheet)'),
        ];
        if (!is_array($sprites) || $sprites === []) { return $fields; }
        if (isset($sprites['sheet'])) {
            $walking = CharacterSheetFields::getFields($sprites, 'sprites2d', 'Walking Character Sheet');
            if (!$pickSheetIndex) {
                $walking = array_map(static fn($field): RecordField => $field->key === 'sprites2d.index'
                    ? new RecordField('sprites2d.index', 'Sheet Character Index', InputControlType::INTEGER, displayDefault: '0') : $field, $walking);
            }
            return [$fields[0], ...$walking];
        }
        return [...$fields,
            new RecordField('sprites2d.layer', 'Graphical Layer', InputControlType::INTEGER, displayDefault: '0'),
            new RecordField('sprites2d.cells', 'Visual Size (cells)', codec: RecordFieldCodec::SIZE, removeWhenEmpty: true, displayDefault: '1, 1'),
            new RecordField('sprites2d.sourceRect', 'Image Region', codec: RecordFieldCodec::SOURCE_RECT, removeWhenEmpty: true, displayDefault: '(whole image)'),
            new RecordField('sprites2d.animation.frames', 'Pose Frame Order (empty: static)', codec: RecordFieldCodec::CSV_INTEGERS, removeWhenEmpty: true),
            new RecordField('sprites2d.animation.columns', 'Pose Grid Columns', InputControlType::INTEGER, displayDefault: '1'),
            new RecordField('sprites2d.animation.rows', 'Pose Grid Rows', InputControlType::INTEGER, displayDefault: '1'),
            new RecordField('sprites2d.animation.fps', 'Pose Frames Per Second', InputControlType::INTEGER, displayDefault: (string) FieldPoseAnimation::DEFAULT_FPS),
            RecordField::boolean('sprites2d.animation.loop', 'Loop Pose', removeWhenEmpty: false, displayDefault: 'true'),
            new RecordField('sprites2d.animation.restFrame', 'Reduced-Motion Rest Cell', InputControlType::INTEGER, displayDefault: '0'),
        ];
    }

    public static function prepareEdit(array $entry, string $field, bool $explicitAbsence = false): array
    {
        if (!str_starts_with($field, 'sprites2d.')) { return $entry; }
        $sprites = $entry['sprites2d'] ?? [];
        if ($field === 'sprites2d.sheet' || $field === 'sprites2d.asset') {
            $key = substr($field, strlen('sprites2d.'));
            if (!isset($sprites[$key])) {
                if (($key === 'sheet' && isset($sprites['asset'])) || ($key === 'asset' && isset($sprites['sheet']))) { return $entry; }
                if ($explicitAbsence) { $entry['sprites2d'] = null; } else { unset($entry['sprites2d']); }
                return $entry;
            }
            if ($key === 'sheet') { unset($sprites['asset'], $sprites['cells'], $sprites['sourceRect'], $sprites['animation']); }
            else { unset($sprites['sheet'], $sprites['index']); }
        }
        if ($field === 'sprites2d.animation.frames' && !isset($sprites['animation']['frames'])) { unset($sprites['animation']); }
        elseif (str_starts_with($field, 'sprites2d.animation.')) { $sprites['animation']['frames'] ??= [0]; }
        FieldSpriteRole::readDefinition($sprites);
        $entry['sprites2d'] = $sprites;
        return $entry;
    }

    public static function describeFields(array $fields, array $entry, ?string $assetRoot): array
    {
        foreach ($fields as &$field) {
            if (($field['sourceKey'] ?? $field['field'] ?? null) === 'sprites2d.sheet' && is_array($entry['sprites2d'] ?? null)) {
                $field['imagePreview'] = CharacterSheetPreview::describe($entry['sprites2d'], $assetRoot);
            }
        }
        unset($field);
        return $fields;
    }
}
