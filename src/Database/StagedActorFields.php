<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Field\CharacterSheetPreview;
use Ichiloto\Editor\Field\FieldSpriteFields;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;

/** Graphical authoring controls; Engine remains the authority for accepted data. */
final class StagedActorFields
{
    /** A stage command, or a cast member: one naming no kind is a staged actor, as the Engine reads it. */
    public static function isStagedActorEntry(array $entry): bool
    {
        return ($entry['type'] ?? null) === 'stage_actor'
            || (! array_key_exists('type', $entry) && ($entry['kind'] ?? 'staged_actor') === 'staged_actor');
    }

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
        if (! self::isStagedActorEntry($entry)) {
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
        if (isset($entry['appearance'])) {
            // The worn NPC look owns the sheet; such an actor never binds a real subject.
            return [RecordField::boolean('replace', 'Replace Existing Visual'), self::getGraphicalVisibilityField($entry)];
        }
        $contract = CinematicCommandSchema::export();
        $fields = [
            new RecordField('subject.kind', 'Bind Visual To', options: ['', ...explode('|', $contract['stagedActorBinding']['subject']['kind'])],
                removeWhenEmpty: true, displayDefault: '(independent staged actor)'),
            RecordField::boolean('replace', 'Replace Existing Visual'),
        ];
        if (($entry['subject']['kind'] ?? null) === 'npc') {
            $fields[] = RecordField::reference('subject.id', 'Bound NPC', 'map_npcs');
        }
        if (($entry['subject']['kind'] ?? null) === 'world_object') {
            $fields[] = RecordField::reference('subject.id', 'Bound World Object', 'map_world_objects');
        }
        return [...FieldSpriteFields::getFields($entry, pickSheetIndex: false), ...$fields, self::getGraphicalVisibilityField($entry)];
    }

    /**
     * Whether the actor's graphical art draws, as the Engine's cinematic schema exports the choice
     * (`stagedActorGraphicalVisibility`): its field and default are the Engine's. `false` withholds the image while
     * the Terminal glyph, placement, collision and subject leases stay as they are. A new visual draws its image
     * unless told otherwise, so only `false` is stored for it. A replacement keeps what the visual it replaces had,
     * so it stores either value, or neither to keep the current one.
     */
    public static function getGraphicalVisibilityField(array $entry): RecordField
    {
        $visibility = CinematicCommandSchema::export()['stagedActorGraphicalVisibility'];
        $replaces = ($entry['replace'] ?? false) === true;

        return new RecordField($visibility['field'], 'Draw Image', InputControlType::BOOLEAN,
            $replaces ? ['', 'false', 'true'] : ['false', 'true'], removeWhenEmpty: true,
            displayDefault: $replaces ? '(keep current)' : var_export($visibility['default'], true));
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
            variants: ['npc' => [RecordField::reference('id', 'NPC', 'map_npcs')],
                'world_object' => [RecordField::reference('id', 'World Object', 'map_world_objects')]],
            variantKey: 'kind', removeWhenEmpty: true,
        );
    }

    /**
     * Keeps an independent staged actor at either a map cell or a cell relative to a map subject, in both editors,
     * as the Engine's stage binding validator accepts it.
     */
    public static function preparePositionEdit(array $entry, string $field): array
    {
        if (($entry['type'] ?? null) === 'stage_actor' && is_array($entry['actor'] ?? null) && str_starts_with($field, 'actor.')) {
            $actor = self::preparePositionEdit(['type' => 'stage_actor', ...$entry['actor']], substr($field, strlen('actor.')));
            if (! array_key_exists('type', $entry['actor'])) {
                unset($actor['type']);
            }
            $entry['actor'] = $actor;

            return $entry;
        }
        if (! self::isStagedActorEntry($entry)) {
            return $entry;
        }
        return RelativePositionFields::prepareEdit(EndFacingFields::prepareEdit($entry, $field), $field,
            static function (array $actor): void {
                CinematicStageManager::validateBinding($actor);
            });
    }

    /**
     * Refuses a plane the Engine's stage binding refuses for the actor as it stands, such as `aboveCover` on a world
     * object takeover, whose art draws in the field. Choosing the plane or the bound subject is checked; the
     * Engine's validator judges the entry with and without its plane, so only a refusal the plane causes stops the
     * edit, and an entry still being completed (a subject without its id) waits for the save and validation.
     *
     * @throws \InvalidArgumentException In the Engine's words.
     */
    public static function preparePlaneEdit(array $entry, string $field): array
    {
        if (($entry['type'] ?? null) === 'stage_actor' && is_array($entry['actor'] ?? null) && str_starts_with($field, 'actor.')) {
            self::preparePlaneEdit($entry['actor'], substr($field, strlen('actor.')));

            return $entry;
        }
        $plane = CinematicCommandSchema::export()['fieldPlane']['field'];
        if (! in_array($field, [$plane, 'subject.kind', 'subject.id'], true) || ! array_key_exists($plane, $entry)
            || ! self::isStagedActorEntry($entry)) {
            return $entry;
        }
        $actor = array_diff_key($entry, ['type' => true]);
        try {
            CinematicStageManager::validateBinding(array_diff_key($actor, [$plane => true]));
        } catch (\InvalidArgumentException) {
            return $entry;
        }
        CinematicStageManager::validateBinding($actor);

        return $entry;
    }

    /** Wearing a map NPC's look clears the actor's own look, which the Engine refuses beside it; both editors share this. */
    public static function prepareAppearanceEdit(array $entry, string $field): array
    {
        if (($entry['type'] ?? null) === 'stage_actor' && is_array($entry['actor'] ?? null) && str_starts_with($field, 'actor.')) {
            $actor = self::prepareAppearanceEdit(['type' => 'stage_actor', ...$entry['actor']], substr($field, strlen('actor.')));
            if (! array_key_exists('type', $entry['actor'])) {
                unset($actor['type']);
            }
            $entry['actor'] = $actor;

            return $entry;
        }
        if ($field !== 'appearance.kind' || ! self::isStagedActorEntry($entry)) {
            return $entry;
        }
        if (! isset($entry['appearance']['kind'])) {
            unset($entry['appearance']);
        } else {
            unset($entry['sprite'], $entry['sprites'], $entry['sprites2d'], $entry['asset'], $entry['subject'], $entry['suppress']);
        }

        return $entry;
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
        if (! self::isStagedActorEntry($entry)) {
            return $entry;
        }
        // The Engine's default for a new visual is its absence; a replacement's absence keeps the current value.
        $visibility = CinematicCommandSchema::export()['stagedActorGraphicalVisibility'];
        if (in_array($field, [$visibility['field'], 'replace'], true) && ($entry['replace'] ?? false) !== true
            && ($entry[$visibility['field']] ?? null) === $visibility['default']) {
            unset($entry[$visibility['field']]);
        }
        if ($field === 'subject.kind') {
            $kind = $entry['subject']['kind'] ?? null;
            if ($kind === null) {
                unset($entry['subject'], $entry['suppress']);
            } else {
                unset($entry['x'], $entry['y'], $entry['at'], $entry['offset'], $entry['facing'], $entry['collision']);
                if ($kind === 'player') {
                    unset($entry['subject']['id']);
                }
            }
        }
        return FieldSpriteFields::prepareEdit($entry, $field);
    }
}
