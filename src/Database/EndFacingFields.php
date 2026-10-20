<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicFacing;
use InvalidArgumentException;

/**
 * Authors the Engine's end facing of a staged position (`CinematicCommandSchema::export()['endFacing']`): a fixed
 * cardinal `facing`, or `faceToward` a map subject chosen like a relative position's `at`. The two are exclusive, so
 * choosing one clears the other; the Engine's `CinematicFacing` judges every value.
 */
final class EndFacingFields
{
    public const string FACING_FIELD = 'facing';
    public const string KIND_FIELD = 'faceToward.kind';
    public const string ID_FIELD = 'faceToward.id';

    /**
     * The facing choice, the subject to face toward and, when that subject is named by id, its id.
     *
     * @param string $facingDefault How an undeclared facing reads where the position is listed.
     * @return list<RecordField>
     */
    public static function getFields(array $entry, bool $cinematic, bool $graphical, string $facingDefault): array
    {
        $contract = CinematicCommandSchema::export()['endFacing'];
        $kinds = array_values(array_intersect(RelativePositionFields::getSubjectKinds($cinematic, $graphical),
            $contract['subjectKinds']));
        $fields = [
            new RecordField(self::FACING_FIELD, 'Facing', options: ['', ...$contract['cardinals']], removeWhenEmpty: true,
                displayDefault: $facingDefault),
            new RecordField(self::KIND_FIELD, 'Face Toward', options: ['', ...$kinds], removeWhenEmpty: true,
                displayDefault: '(no subject)'),
        ];
        $kind = is_array($entry['faceToward'] ?? null) ? strval($entry['faceToward']['kind'] ?? '') : '';
        $id = RelativePositionFields::getSubjectIdField(self::ID_FIELD, $kind, 'Face Toward ');
        if ($id !== null) {
            $fields[] = $id;
        }
        return $fields;
    }

    /**
     * Keeps at most one end facing after one field edit, then has the Engine judge it. A subject still waiting for
     * its id is a draft the owner's save boundary completes.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException When the edit leaves an end facing the Engine refuses.
     */
    public static function prepareEdit(array $entry, string $field): array
    {
        if (! in_array($field, [self::FACING_FIELD, self::KIND_FIELD, self::ID_FIELD], true)) {
            return $entry;
        }
        if ($field === self::FACING_FIELD && array_key_exists('facing', $entry)) {
            unset($entry['faceToward']);
        }
        if ($field === self::KIND_FIELD) {
            $kind = is_array($entry['faceToward'] ?? null) ? ($entry['faceToward']['kind'] ?? null) : null;
            if ($kind === null) {
                unset($entry['faceToward']);
            } else {
                unset($entry['facing']);
                if (! RelativePositionFields::namesSubjectId(strval($kind))) {
                    unset($entry['faceToward']['id']);
                }
            }
        }
        if (($entry['faceToward'] ?? null) === []) {
            unset($entry['faceToward']);
        }
        try {
            CinematicFacing::validate($entry);
        } catch (InvalidArgumentException $error) {
            $reference = $entry['faceToward'] ?? null;
            $awaitsId = is_array($reference) && ! array_key_exists('id', $reference)
                && RelativePositionFields::namesSubjectId(strval($reference['kind'] ?? ''));
            if (! $awaitsId) {
                throw $error;
            }
        }
        return $entry;
    }
}
