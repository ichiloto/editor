<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * A field subject a cinematic binds under a stable key of its own: a
 * conversation's speakers, the subjects its framing phases select. Each is
 * the kind of subject and, where the kind names one, its stable id, picked
 * where every subject reference picks it
 * ({@see RelativePositionFields::getSubjectIdField()}): an NPC among the NPCs
 * of the maps the cinematic runs on, a staged actor among its cast and the
 * actors its commands stage. The player names no id.
 *
 * @package Ichiloto\Editor\Database
 */
final class SubjectBindingFields
{
    /** The editor's own field of a binding: the key it is stored under. */
    public const string KEY_FIELD = 'key';

    private function __construct()
    {
    }

    /**
     * A binding's key, the kind of subject it binds, and that subject's id
     * where the kind names one.
     *
     * @param array<string, array{idField: string|null, identity?: string}> $sources Each subject kind the Engine
     *   binds here, with the field its id is stored in, or null for a kind that names none.
     * @param string $kindField The reference's kind field, as the Engine names it.
     * @return list<RecordField>
     */
    public static function getFields(array $entry, string $keyLabel, array $sources, string $kindField = 'kind'): array
    {
        return [new RecordField(self::KEY_FIELD, $keyLabel), ...self::getReferenceFields($entry,
            new RecordField($kindField, 'Subject', options: array_map(strval(...), array_keys($sources))), $sources, $kindField)];
    }

    /**
     * A subject reference's rows: the kind row given, and the id row of the
     * kind the entry holds, where that kind names one. The id is picked
     * where every subject reference of that kind picks it. A kind this
     * Editor has no picker for keeps a plain row under the Engine's name for
     * what it identifies, so an Engine update never hides the field.
     *
     * @param array<string, array{idField: string|null, identity?: string}> $sources
     * @return list<RecordField>
     */
    public static function getReferenceFields(array $entry, RecordField $kind, array $sources, string $kindField = 'kind'): array
    {
        $selected = strval($entry[$kindField] ?? '');
        $idField = $sources[$selected]['idField'] ?? null;
        if ($idField === null) {
            return [$kind];
        }

        return [$kind, RelativePositionFields::getSubjectIdField($idField, $selected)
            ?? new RecordField($idField, ucfirst(strval($sources[$selected]['identity'] ?? $selected)))];
    }

    /**
     * A new subject kind keeps no id an earlier kind named, where the new one names none there.
     *
     * @param array<string, array{idField: string|null, identity?: string}> $sources
     */
    public static function prepareEdit(array $entry, string $field, array $sources, string $kindField = 'kind'): array
    {
        if ($field !== $kindField) {
            return $entry;
        }
        $kept = $sources[strval($entry[$kindField] ?? '')]['idField'] ?? null;
        foreach (array_column($sources, 'idField') as $idField) {
            if ($idField !== null && $idField !== $kept) {
                unset($entry[$idField]);
            }
        }

        return $entry;
    }

    /**
     * The sources of subject kinds whose contract exports none: each kind's id is stored as `id` where this
     * Editor's subject references pick one for it ({@see RelativePositionFields::namesSubjectId()}).
     *
     * @param list<string> $kinds
     * @return array<string, array{idField: string|null}>
     */
    public static function describeSources(array $kinds): array
    {
        return array_combine($kinds, array_map(static fn(string $kind): array
            => ['idField' => RelativePositionFields::namesSubjectId($kind) ? 'id' : null], $kinds));
    }
}
