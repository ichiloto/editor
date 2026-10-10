<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\Field\CharacterSheetPreview;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;

/** Graphical authoring controls; Engine remains the authority for accepted data. */
final class StagedActorFields
{
    /**
     * Adds preview data using the actual entry owning these descriptors, never a selected/reconstructed actor.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    public static function describeFields(array $entry, array $fields, ?string $assetRoot): array
    {
        $wrapped = ($entry['type'] ?? null) === 'stage_actor' && is_array($entry['actor'] ?? null);
        $actor = $wrapped ? $entry['actor'] : $entry;
        if (($entry['type'] ?? null) !== 'stage_actor' && ($entry['kind'] ?? null) !== 'staged_actor') {
            return $fields;
        }
        $sprites = $actor['sprites2d'] ?? null;
        if (! is_array($sprites) || ! array_key_exists('sheet', $sprites)) {
            return $fields;
        }
        $key = ($wrapped ? 'actor.' : '') . 'sprites2d.sheet';
        foreach ($fields as &$field) {
            if (($field['sourceKey'] ?? null) === $key) {
                $field['imagePreview'] = CharacterSheetPreview::describe($sprites, $assetRoot);
            }
        }
        unset($field);

        return $fields;
    }

    /** The runtime accepts inline stage commands and an explicit actor envelope. */
    public static function getCommandFields(array $command): array
    {
        if (! is_array($command['actor'] ?? null)) {
            return CutsceneSchemas::stagedActorFields(true, $command);
        }

        return array_map(static fn(RecordField $field): RecordField => new RecordField(...array_replace(
            get_object_vars($field), ['key' => 'actor.' . $field->key],
        )), CutsceneSchemas::stagedActorFields(true, $command['actor']));
    }

    /** @return RecordField[] */
    public static function getFields(array $entry): array
    {
        $contract = CinematicCommandSchema::export();
        $animation = $contract['stagedPoseAnimation'];
        $fields = [
            RecordField::reference('sprites2d.asset', 'Graphical Image / Pose Sheet', 'png_assets', allowsNone: true, noneLabel: '(inherit / no graphical override)'),
            RecordField::reference('sprites2d.sheet', 'Walking Character Sheet', 'png_assets', allowsNone: true, noneLabel: '(not a walking sheet)'),
            new RecordField('subject.kind', 'Bind Visual To', options: ['', ...explode('|', $contract['stagedActorBinding']['subject']['kind'])],
                removeWhenEmpty: true, displayDefault: '(independent staged actor)'),
            RecordField::boolean('replace', 'Replace Existing Visual'),
        ];
        if (($entry['subject']['kind'] ?? null) === 'npc') {
            $fields[] = RecordField::reference('subject.id', 'Bound NPC', 'map_npcs');
        }
        $sprites = $entry['sprites2d'] ?? [];
        if (! is_array($sprites) || $sprites === []) {
            return $fields;
        }
        $fields[] = new RecordField('sprites2d.layer', 'Graphical Layer', InputControlType::INTEGER, displayDefault: '0');
        if (isset($sprites['sheet'])) {
            $fields[] = new RecordField('sprites2d.index', 'Sheet Character Index', InputControlType::INTEGER, displayDefault: '0');

            return $fields;
        }
        $fields[] = new RecordField('sprites2d.cells', 'Visual Size (cells: width, height)', codec: RecordFieldCodec::SIZE,
            removeWhenEmpty: true, displayDefault: '1, 1');
        $fields[] = new RecordField('sprites2d.sourceRect', 'Sheet Region (x, y, width, height)', codec: RecordFieldCodec::SOURCE_RECT,
            removeWhenEmpty: true, displayDefault: '(whole image)');
        $controls = [
            'columns' => new RecordField('sprites2d.animation.columns', 'Pose Grid Columns', InputControlType::INTEGER, displayDefault: (string) $animation['defaults']['columns']),
            'rows' => new RecordField('sprites2d.animation.rows', 'Pose Grid Rows', InputControlType::INTEGER, displayDefault: (string) $animation['defaults']['rows']),
            'frames' => new RecordField('sprites2d.animation.frames', 'Pose Frame Order (empty: static)', codec: RecordFieldCodec::CSV_INTEGERS, removeWhenEmpty: true),
            'fps' => new RecordField('sprites2d.animation.fps', 'Pose Frames Per Second', InputControlType::INTEGER, displayDefault: (string) $animation['defaultFps']),
            'loop' => RecordField::boolean('sprites2d.animation.loop', 'Loop Pose', removeWhenEmpty: false, displayDefault: $animation['defaults']['loop'] ? 'true' : 'false'),
            'restFrame' => new RecordField('sprites2d.animation.restFrame', 'Reduced-Motion Rest Cell', InputControlType::INTEGER, displayDefault: (string) $animation['defaults']['restFrame']),
        ];
        foreach ($animation['fields'] as $key) {
            if (isset($controls[$key])) {
                $fields[] = $controls[$key];
            }
        }

        return $fields;
    }

    public static function getSuppressionList(array $entry): ?RecordSubList
    {
        $wrapped = is_array($entry['actor'] ?? null);
        $entry = $wrapped ? $entry['actor'] : $entry;
        if (! isset($entry['subject'])) {
            return null;
        }
        $kinds = explode('|', CinematicCommandSchema::export()['stagedActorBinding']['subject']['kind']);

        return new RecordSubList(
            key: $wrapped ? 'actor.suppress' : 'suppress', prefix: 'suppress', singular: 'suppressed subject',
            fields: [new RecordField('kind', 'Subject Kind', options: $kinds)],
            blank: ['kind' => 'player'],
            variants: ['npc' => [RecordField::reference('id', 'NPC', 'map_npcs')]],
            variantKey: 'kind', removeWhenEmpty: true,
        );
    }

    /** Changing a representation clears only the fields owned by the old representation. */
    public static function prepareEdit(array $entry, string $field): array
    {
        if (($entry['type'] ?? null) === 'stage_actor' && is_array($entry['actor'] ?? null) && str_starts_with($field, 'actor.')) {
            $actor = self::prepareEdit(['type' => 'stage_actor', ...$entry['actor']], substr($field, strlen('actor.')));
            if (! array_key_exists('type', $entry['actor'])) {
                unset($actor['type']);
            }
            $entry['actor'] = $actor;

            return $entry;
        }
        if (($entry['type'] ?? null) !== 'stage_actor' && ($entry['kind'] ?? null) !== 'staged_actor') {
            return $entry;
        }
        if ($field === 'subject.kind') {
            $kind = $entry['subject']['kind'] ?? null;
            if ($kind === null) {
                unset($entry['subject'], $entry['suppress']);
            } else {
                unset($entry['x'], $entry['y'], $entry['facing'], $entry['collision']);
                if ($kind === 'player') {
                    unset($entry['subject']['id']);
                }
            }
        }
        if (! str_starts_with($field, 'sprites2d.')) {
            return $entry;
        }
        $sprites = $entry['sprites2d'] ?? [];
        if ($field === 'sprites2d.sheet' || $field === 'sprites2d.asset') {
            $key = substr($field, strlen('sprites2d.'));
            if (! isset($sprites[$key])) {
                // Clearing an already absent alternate picker must not erase the active form.
                if (($key === 'sheet' && isset($sprites['asset'])) || ($key === 'asset' && isset($sprites['sheet']))) {
                    return $entry;
                }
                unset($entry['sprites2d']);

                return $entry;
            }
            if ($key === 'sheet') {
                unset($sprites['asset'], $sprites['cells'], $sprites['sourceRect'], $sprites['animation']);
            } else {
                unset($sprites['sheet'], $sprites['index']);
            }
        }
        if ($field === 'sprites2d.animation.frames' && ! isset($sprites['animation']['frames'])) {
            unset($sprites['animation']);
        } elseif (str_starts_with($field, 'sprites2d.animation.')) {
            $sprites['animation']['frames'] ??= [0];
        }
        // Use runtime validation rather than a second copy of its bounds and shape rules.
        CinematicStageManager::getGraphicalSprites($sprites);
        $entry['sprites2d'] = $sprites;

        return $entry;
    }
}
