<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Field\CharacterSheetPreview;
use Ichiloto\Editor\Field\FieldSpriteFields;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;

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
        return [...FieldSpriteFields::getFields($entry, pickSheetIndex: false), ...$fields];
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
        return FieldSpriteFields::prepareEdit($entry, $field);
    }
}
