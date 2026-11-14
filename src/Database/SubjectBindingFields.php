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
     * @param list<string> $kinds The subject kinds the Engine binds here.
     * @param string $kindField The reference's kind field, as the Engine names it.
     * @param string $idField The reference's id field, as the Engine names it.
     * @return list<RecordField>
     */
    public static function getFields(array $entry, string $keyLabel, array $kinds, string $kindField = 'kind', string $idField = 'id'): array
    {
        return [new RecordField(self::KEY_FIELD, $keyLabel), ...self::getReferenceFields($entry, new RecordField($kindField, 'Subject', options: $kinds),
            $kindField, $idField)];
    }

    /**
     * A subject reference's rows: the kind row given, and the id row of the
     * kind the entry holds, where that kind names one.
     *
     * @return list<RecordField>
     */
    public static function getReferenceFields(array $entry, RecordField $kind, string $kindField = 'kind', string $idField = 'id'): array
    {
        $id = RelativePositionFields::getSubjectIdField($idField, strval($entry[$kindField] ?? ''));

        return $id === null ? [$kind] : [$kind, $id];
    }

    /** A new subject kind keeps no id the old kind named, where the new one names none. */
    public static function prepareEdit(array $entry, string $field, string $kindField = 'kind', string $idField = 'id'): array
    {
        if ($field === $kindField && ! RelativePositionFields::namesSubjectId(strval($entry[$kindField] ?? ''))) {
            unset($entry[$idField]);
        }

        return $entry;
    }
}
