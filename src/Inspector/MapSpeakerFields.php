<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\MapEventDefinition;

/**
 * An event's speakers: the NPCs of its map who speak its lines, chosen from
 * the map's NPCs by their stable ids, the same rows in the Terminal and in a
 * graphical editor. The Engine requires each speaker to stand beside the
 * event whenever it can run, and validation says where one does not
 * ({@see \Ichiloto\Engine\Field\Validation\MapEventSpeakerRule}); naming a
 * speaker here moves no one.
 *
 * @package Ichiloto\Editor\Inspector
 */
final readonly class MapSpeakerFields
{
    public const string TARGET = 'event-speakers';
    /** The field of a speaker row; the heading has none. */
    public const string SPEAKER_FIELD = 'speaker';

    /**
     * The Speakers heading and one row per speaker. An event without a
     * definition, or a map whose NPCs have no ids, has none to choose.
     *
     * @return list<array<string, mixed>>
     */
    public static function describeEventFields(ProjectMap $map, string $marker): array
    {
        $definition = $map->getEventDefinition($marker);
        if ($definition === null) {
            return [];
        }
        $speakers = $definition['speakers'] ?? [];
        if (! is_array($speakers) || ! array_is_list($speakers) || array_filter($speakers, is_string(...)) !== $speakers) {
            return [['label' => 'Speakers', 'value' => '! Not a list of NPC ids; fix it in the source.', 'editable' => false]];
        }
        $choices = self::describeChoices($map);
        $fields = [['label' => sprintf('Speakers · %d', count($speakers)),
            'value' => $speakers === [] ? ($choices === [] ? 'No NPC on this map has an id' : 'No one speaks it') : '',
            'editable' => false, 'target' => self::TARGET, 'marker' => $marker, 'speakerList' => ['index' => max(0, count($speakers) - 1)]]];
        foreach ($speakers as $index => $id) {
            $known = array_find($choices, static fn(array $choice): bool => $choice['value'] === $id);
            $fields[] = ['label' => '  Speaker', 'value' => $id, 'editable' => true,
                'choices' => $known === null ? [...$choices, ['label' => $id . ' (not on this map)', 'value' => $id,
                    'description' => 'No NPC on this map has this id.']] : $choices,
                'target' => self::TARGET, 'marker' => $marker, 'field' => self::SPEAKER_FIELD, 'index' => $index,
                'speakerList' => ['index' => $index]];
        }

        return $fields;
    }

    /**
     * Names another speaker: the nearest NPC to the event not already named,
     * for the author to change.
     *
     * @param array<string, mixed> $field
     * @throws InspectorRefusal When every NPC with an id already speaks it, or there is none.
     */
    public static function addSpeaker(ProjectMap $map, array $field): InspectorListEdit
    {
        $marker = (string) $field['marker'];
        $speakers = self::readSpeakers($map, $marker);
        if (count($speakers) >= MapEventDefinition::MAX_SPEAKERS) {
            throw new InspectorRefusal(sprintf('An event names at most %d speakers.', MapEventDefinition::MAX_SPEAKERS));
        }
        $cells = $map->getEventTriggerArea($marker)?->cells ?? [];
        $candidates = [];
        foreach ($map->getNpcs()->all() as $npc) {
            $id = $npc->getId();
            if ($id === null || in_array($id, $speakers, true)) {
                continue;
            }
            $distance = $cells === [] ? 0 : min(array_map(static fn(array $cell): int
                => max(abs($cell[0] - $npc->getX()), abs($cell[1] - $npc->getY())), $cells));
            $candidates[$id] = min($candidates[$id] ?? PHP_INT_MAX, $distance);
        }
        if ($candidates === []) {
            throw new InspectorRefusal($speakers === [] ? 'No NPC on this map has an id to name as a speaker.'
                : 'Every NPC with an id already speaks this event.');
        }
        asort($candidates);
        $id = (string) array_key_first($candidates);

        return new InspectorListEdit(self::writeSpeakers($map, $marker, [...$speakers, $id], 'Speaker add'),
            sprintf('Added speaker %s; choose another NPC on its row.', $id));
    }

    /**
     * @param array<string, mixed> $field
     * @throws InspectorRefusal When the row's speaker is gone.
     */
    public static function removeSpeaker(ProjectMap $map, array $field): InspectorListEdit
    {
        $marker = (string) $field['marker'];
        $speakers = self::readSpeakers($map, $marker);
        $index = (int) ($field['index'] ?? -1);
        if (! array_key_exists($index, $speakers)) {
            throw new InspectorRefusal('That speaker is no longer on the event; read it again.');
        }
        $removed = $speakers[$index];
        array_splice($speakers, $index, 1);

        return new InspectorListEdit(self::writeSpeakers($map, $marker, $speakers, 'Speaker remove'), sprintf('Removed speaker %s.', $removed));
    }

    /**
     * Chooses the NPC a speaker row names. Null when it already names it.
     *
     * @param array<string, mixed> $field
     * @throws InspectorRefusal When the choice is not an NPC id on the map, or already speaks it.
     */
    public static function applyValue(ProjectMap $map, array $field, string $value): ?Command
    {
        $marker = (string) $field['marker'];
        $speakers = self::readSpeakers($map, $marker);
        $index = (int) ($field['index'] ?? -1);
        if (($field['field'] ?? null) !== self::SPEAKER_FIELD || ! array_key_exists($index, $speakers)) {
            throw new InspectorRefusal('That speaker is no longer on the event; read it again.');
        }
        $id = trim($value);
        if ($speakers[$index] === $id) {
            return null;
        }
        if ($map->getNpcs()->indexOfId($id) === null) {
            throw new InspectorRefusal(sprintf('No NPC on %s has the id "%s".', $map->mapId, $id));
        }
        if (in_array($id, $speakers, true)) {
            throw new InspectorRefusal(sprintf('%s already speaks this event.', $id));
        }
        $speakers[$index] = $id;

        return self::writeSpeakers($map, $marker, $speakers, 'Speaker edit');
    }

    /** @return list<array{label: string, value: string, description: string}> One choice per NPC id, in map order. */
    private static function describeChoices(ProjectMap $map): array
    {
        $choices = [];
        foreach ($map->getNpcs()->all() as $npc) {
            $id = $npc->getId();
            if ($id === null || isset($choices[$id])) {
                continue;
            }
            $choices[$id] = ['label' => sprintf('%s (%s)', $npc->getName(), $id), 'value' => $id,
                'description' => sprintf('Stands at %d,%d.', $npc->getX(), $npc->getY())];
        }

        return array_values($choices);
    }

    /**
     * @return list<string>
     * @throws InspectorRefusal When the event holds speakers the editor cannot read as a list of ids.
     */
    private static function readSpeakers(ProjectMap $map, string $marker): array
    {
        $definition = $map->getEventDefinition($marker) ?? throw new InspectorRefusal(sprintf('There is no event %s to name speakers for.', $marker));
        $speakers = $definition['speakers'] ?? [];
        if (! is_array($speakers) || ! array_is_list($speakers) || array_filter($speakers, is_string(...)) !== $speakers) {
            throw new InspectorRefusal(sprintf('Event %s speakers are not a list of NPC ids; fix them in the source first.', $marker));
        }

        return $speakers;
    }

    /** @param list<string> $speakers An empty list drops the key, as the event was before one was named. */
    private static function writeSpeakers(ProjectMap $map, string $marker, array $speakers, string $label): Command
    {
        $held = $map->hasEventField($marker, ['speakers']);
        $old = $map->getEventField($marker, ['speakers']);
        $apply = $speakers === [] ? static fn() => $map->removeEventField($marker, ['speakers'])
            : static fn() => $map->setEventField($marker, ['speakers'], $speakers);
        $restore = $held ? static fn() => $map->setEventField($marker, ['speakers'], $old)
            : static fn() => $map->removeEventField($marker, ['speakers']);
        $apply();

        return new GenericCommand($label, $apply, $restore);
    }
}
