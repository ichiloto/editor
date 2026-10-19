<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Closure;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use InvalidArgumentException;

/**
 * Authors the Engine's subject-relative map position: `at` names a map subject by kind and stable id, and an
 * optional `offset` moves the point whole cells from it. The Engine's exported contract
 * (`CinematicCommandSchema::export()['relativePosition']`) lists the subject kinds and its consumers' validators
 * judge every value; this class only chooses what an author is offered and keeps a point either absolute or relative.
 */
final class RelativePositionFields
{
    public const string KIND_FIELD = 'at.kind';
    public const string ID_FIELD = 'at.id';
    public const string OFFSET_X_FIELD = 'offset.x';
    public const string OFFSET_Y_FIELD = 'offset.y';

    /** The reference each named subject kind's stable id is chosen from. */
    private const array ID_REFERENCES = [
        'npc' => ['map_npcs', 'NPC'],
        'world_object' => ['map_world_objects', 'World Object'],
        'staged_actor' => ['cinematic_cast', 'Staged Actor'],
        'party_actor' => ['actors', 'Party Actor'],
        'marker' => ['event_markers', 'Event Marker'],
    ];

    /** Whether a point declares any part of a relative position. */
    public static function isRelative(array $entry): bool
    {
        return array_intersect(CinematicCommandSchema::RELATIVE_POSITION_FIELDS, array_keys($entry)) !== [];
    }

    /**
     * The subject kinds an author may choose here. Staged actors exist only on a cinematic's stage, and world
     * objects are authored in the graphical editor alone, as every other subject picker offers them.
     *
     * @return list<string>
     */
    public static function getSubjectKinds(bool $cinematic, bool $graphical): array
    {
        $kinds = CinematicCommandSchema::export()['relativePosition']['subjectKinds'];
        return array_values(array_filter($kinds, static fn(string $kind): bool
            => ($cinematic || $kind !== 'staged_actor') && ($graphical || $kind !== 'world_object')));
    }

    /**
     * The fields of a point: its absolute fields and a subject choice while it is absolute, or the subject, its id
     * and the offset once it is relative. Independent coordinates and an offset are never offered together.
     *
     * @param list<RecordField> $absoluteFields
     * @return list<RecordField>
     */
    public static function getFields(array $entry, array $absoluteFields, bool $cinematic, bool $graphical): array
    {
        $kinds = self::getSubjectKinds($cinematic, $graphical);
        $kind = new RecordField(self::KIND_FIELD, 'Relative To', options: ['', ...$kinds], removeWhenEmpty: true,
            displayDefault: '(map cell)');
        if (! self::isRelative($entry)) {
            return [$kind, ...$absoluteFields];
        }
        $selected = is_array($entry['at'] ?? null) ? strval($entry['at']['kind'] ?? '') : '';
        $fields = [$kind];
        if (isset(self::ID_REFERENCES[$selected])) {
            [$reference, $label] = self::ID_REFERENCES[$selected];
            // The leading party actor needs no id; every other named subject does.
            $fields[] = $selected === 'party_actor'
                ? RecordField::reference(self::ID_FIELD, $label, $reference, allowsNone: true, noneLabel: '(leading party actor)')
                : RecordField::reference(self::ID_FIELD, $label, $reference);
        }
        return [
            ...$fields,
            new RecordField(self::OFFSET_X_FIELD, 'Offset X', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
            new RecordField(self::OFFSET_Y_FIELD, 'Offset Y', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
        ];
    }

    /**
     * Keeps a point either absolute or relative after one field edit, then has the consumer's Engine validator judge
     * it. Choosing a subject drops independent coordinates, and the id when the subject is the player; clearing it
     * drops the offset with it. A subject still waiting for its id is a draft the owner's save boundary completes.
     *
     * @param Closure(array<string, mixed>): void $validate The consumer's Engine validator.
     * @return array<string, mixed>
     * @throws InvalidArgumentException When the edit leaves a point the Engine refuses.
     */
    public static function prepareEdit(array $entry, string $field, Closure $validate): array
    {
        if (! in_array($field, [self::KIND_FIELD, self::ID_FIELD, self::OFFSET_X_FIELD, self::OFFSET_Y_FIELD], true)) {
            return $entry;
        }
        if ($field === self::KIND_FIELD) {
            $kind = is_array($entry['at'] ?? null) ? ($entry['at']['kind'] ?? null) : null;
            if ($kind === null) {
                unset($entry['at'], $entry['offset']);
            } else {
                unset($entry['x'], $entry['y']);
                if (! isset(self::ID_REFERENCES[$kind])) {
                    unset($entry['at']['id']);
                }
            }
        }
        if (in_array($field, [self::OFFSET_X_FIELD, self::OFFSET_Y_FIELD], true) && ! is_array($entry['at'] ?? null)) {
            throw new InvalidArgumentException('Choose what this position is relative to before giving it an offset.');
        }
        if (($entry['offset'] ?? null) === []) {
            // Omitted axes are zero, and the Engine refuses an empty offset.
            unset($entry['offset']);
        }
        if (! self::isRelative($entry)) {
            return $entry;
        }
        try {
            $validate($entry);
        } catch (InvalidArgumentException $error) {
            if (array_key_exists('id', (array) $entry['at'])) {
                throw $error;
            }
        }
        return $entry;
    }

    /** How a point reads where it is listed: its subject and offset, in cells. */
    public static function describePosition(array $entry): string
    {
        $at = is_array($entry['at'] ?? null) ? $entry['at'] : [];
        $kind = strval($at['kind'] ?? '');
        $subject = match (true) {
            $kind === 'player' => 'the player',
            $kind === 'party_actor' && ! isset($at['id']) => 'the leading party actor',
            isset(self::ID_REFERENCES[$kind]) => sprintf('%s %s', self::ID_REFERENCES[$kind][1], strval($at['id'] ?? '(no id)')),
            default => '(no subject)',
        };
        $offset = is_array($entry['offset'] ?? null) ? $entry['offset'] : [];
        $x = intval($offset['x'] ?? 0);
        $y = intval($offset['y'] ?? 0);
        return $x === 0 && $y === 0 ? 'at ' . $subject : sprintf('%+d, %+d from %s', $x, $y, $subject);
    }
}
