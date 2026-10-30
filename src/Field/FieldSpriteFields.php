<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\ProjectRecord;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordFieldCodec;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;

/**
 * One field-role vocabulary for map objects, cinematic visual overrides and
 * an NPC's bed poses. A role lives at a dotted path of its entry,
 * `sprites2d` unless the owner keeps several roles (an NPC's
 * `bed.poses2d.lying`); a label prefix tells those roles apart in one pane.
 */
final class FieldSpriteFields
{
    /** Where an entry keeps its one field role. */
    public const string ROLE = 'sprites2d';

    public static function getFields(array $entry, bool $explicitAbsence = false, bool $pickSheetIndex = true, string $path = self::ROLE,
        string $label = ''): array
    {
        $sprites = self::readRole($entry, $path);
        $named = static fn(RecordField $field): RecordField => $label === '' ? $field : $field->withLabel($label . ' ' . $field->label);
        $fields = [
            RecordField::reference("$path.asset", 'Image / Pose Sheet', 'png_assets', allowsNone: true,
                noneLabel: $explicitAbsence ? 'None: explicit absence' : '(inherit / no graphical override)'),
            RecordField::reference("$path.sheet", 'Walking Character Sheet', 'png_assets', allowsNone: true,
                noneLabel: $explicitAbsence ? 'None: explicit absence' : '(not a walking sheet)'),
        ];
        if (!is_array($sprites) || $sprites === []) { return array_map($named, $fields); }
        if (isset($sprites['sheet'])) {
            $walking = CharacterSheetFields::getFields($sprites, $path, 'Walking Character Sheet');
            if (!$pickSheetIndex) {
                $walking = array_map(static fn($field): RecordField => $field->key === "$path.index"
                    ? new RecordField("$path.index", 'Sheet Character Index', InputControlType::INTEGER, displayDefault: '0') : $field, $walking);
            }
            return array_map($named, [$fields[0], ...$walking]);
        }
        return array_map($named, [...$fields,
            new RecordField("$path.layer", 'Graphical Layer', InputControlType::INTEGER, displayDefault: '0'),
            new RecordField("$path.cells", 'Visual Size (cells)', codec: RecordFieldCodec::SIZE, removeWhenEmpty: true, displayDefault: '1, 1'),
            new RecordField("$path.sourceRect", 'Image Region', codec: RecordFieldCodec::SOURCE_RECT, removeWhenEmpty: true, displayDefault: '(whole image)'),
            new RecordField("$path.animation.frames", 'Pose Frame Order (empty: static)', codec: RecordFieldCodec::CSV_INTEGERS, removeWhenEmpty: true),
            new RecordField("$path.animation.columns", 'Pose Grid Columns', InputControlType::INTEGER, displayDefault: '1'),
            new RecordField("$path.animation.rows", 'Pose Grid Rows', InputControlType::INTEGER, displayDefault: '1'),
            new RecordField("$path.animation.fps", 'Pose Frames Per Second', InputControlType::INTEGER, displayDefault: (string) FieldPoseAnimation::DEFAULT_FPS),
            RecordField::boolean("$path.animation.loop", 'Loop Pose', removeWhenEmpty: false, displayDefault: 'true'),
            new RecordField("$path.animation.restFrame", 'Reduced-Motion Rest Cell', InputControlType::INTEGER, displayDefault: '0'),
        ]);
    }

    public static function prepareEdit(array $entry, string $field, bool $explicitAbsence = false, string $path = self::ROLE): array
    {
        if (!str_starts_with($field, "$path.")) { return $entry; }
        $sprites = self::readRole($entry, $path) ?? [];
        if ($field === "$path.sheet" || $field === "$path.asset") {
            $key = substr($field, strlen("$path."));
            if (!isset($sprites[$key])) {
                if (($key === 'sheet' && isset($sprites['asset'])) || ($key === 'asset' && isset($sprites['sheet']))) { return $entry; }
                return self::writeRole($entry, $path, $explicitAbsence ? [] : null);
            }
            if ($key === 'sheet') { unset($sprites['asset'], $sprites['cells'], $sprites['sourceRect'], $sprites['animation']); }
            else { unset($sprites['sheet'], $sprites['index']); }
        }
        if ($field === "$path.animation.frames" && !isset($sprites['animation']['frames'])) { unset($sprites['animation']); }
        elseif (str_starts_with($field, "$path.animation.")) { $sprites['animation']['frames'] ??= [0]; }
        FieldSpriteRole::readDefinition($sprites);
        return self::writeRole($entry, $path, $sprites);
    }

    public static function describeFields(array $fields, array $entry, ?string $assetRoot, string $path = self::ROLE): array
    {
        $sprites = self::readRole($entry, $path);
        foreach ($fields as &$field) {
            if (($field['sourceKey'] ?? $field['field'] ?? null) === "$path.sheet" && is_array($sprites)) {
                $field['imagePreview'] = CharacterSheetPreview::describe($sprites, $assetRoot);
            }
        }
        unset($field);
        return $fields;
    }

    /** The role an entry holds at a path, as authored; null when it holds none there. */
    private static function readRole(array $entry, string $path): mixed
    {
        return new ProjectRecord($entry)->get($path);
    }

    /**
     * The entry with its role at a path replaced: `[]` writes the explicit absence (`null`) an owner may declare,
     * null removes the role, and a parent the removal leaves empty.
     */
    private static function writeRole(array $entry, string $path, ?array $sprites): array
    {
        if ($sprites === []) {
            // ProjectRecord removes on null, so an explicit absence is written directly.
            $segments = explode('.', $path);
            $target = &$entry;
            foreach (array_slice($segments, 0, -1) as $segment) {
                $target[$segment] = is_array($target[$segment] ?? null) ? $target[$segment] : [];
                $target = &$target[$segment];
            }
            $target[end($segments)] = null;
            unset($target);
            return $entry;
        }
        $record = new ProjectRecord($entry);
        $record->set($path, $sprites);
        return (array) $record->toArray();
    }
}
