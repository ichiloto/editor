<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Field\Enumerations\BedPosture;
use Ichiloto\Engine\Field\NpcBedDefinition;
use InvalidArgumentException;

/**
 * Authors an NPC's bed (`CinematicCommandSchema::export()['bedOccupancy']`):
 * the world object on its map it rests in, that object's resource attachment
 * the occupant lies at, the posture it starts in, the Terminal glyph of the
 * occupied bed and, in the graphical editor, the character's own lying and
 * sitting poses and the clips that play on the way into each posture. The
 * NPC's ordinary X and Y stay its standing exit.
 *
 * The Engine's `NpcBedDefinition` judges every edit; the physical facts (a
 * solid bed cell, a clear exit, occupants sharing a slot) are the map's, which
 * the Engine's `MapBedRule` checks when the project is validated.
 *
 * @package Ichiloto\Editor\Field
 */
final class NpcBedFields
{
    /** The NPC field holding its bed. */
    public const string BED = 'bed';

    public const string OBJECT = self::BED . '.object';
    public const string ATTACHMENT = self::BED . '.attachment';
    public const string POSTURE = self::BED . '.posture';
    public const string TERMINAL = self::BED . '.terminal';

    /** The reference category of a map's world objects whose resource declares attachments, any of which can hold a bed. */
    public const string BED_OBJECTS = 'map_bed_objects';

    /** The entry choice source of the attachments the chosen bed object's resource declares. */
    public const string BED_ATTACHMENTS = 'npc_bed_attachments';

    /** The posture an NPC that names none starts in, as `NpcBedDefinition` reads it. */
    private const BedPosture DEFAULT_POSTURE = BedPosture::LYING;

    /** The occupied-bed glyph of an NPC that names none, as `NpcBedDefinition` reads it. */
    private const string DEFAULT_TERMINAL = '▣';

    private function __construct()
    {
    }

    /**
     * The bed rows of one NPC: the bed object always, the rest once it rests
     * in one, and the graphical poses only where they are drawn.
     *
     * @param array<string, mixed> $npc The NPC entry.
     * @return list<RecordField>
     */
    public static function getFields(array $npc, bool $graphical): array
    {
        $fields = [RecordField::reference(self::OBJECT, 'Bed', self::BED_OBJECTS, allowsNone: true, noneLabel: '(not in a bed)')];
        if (! is_array($npc[self::BED] ?? null)) {
            return $fields;
        }
        $fields = [
            ...$fields,
            RecordField::entryChoice(self::ATTACHMENT, 'Occupant Attachment', self::BED_ATTACHMENTS, '(choose an attachment)'),
            new RecordField(self::POSTURE, 'Starting Posture', options: self::listPostures(), removeWhenEmpty: true,
                displayDefault: self::DEFAULT_POSTURE->value),
            new RecordField(self::TERMINAL, 'Occupied Bed Glyph', removeWhenEmpty: true, displayDefault: self::DEFAULT_TERMINAL),
        ];
        if (! $graphical) {
            return $fields;
        }
        $contract = CinematicCommandSchema::export()['bedOccupancy'];
        foreach ($contract['poseRoles'] as $posture) {
            array_push($fields, ...FieldSpriteFields::getFields($npc, path: self::getPosePath($posture), label: ucfirst($posture) . ' Pose'));
        }
        foreach ($contract['transitionRoles'] as $posture) {
            array_push($fields, ...FieldSpriteFields::getFields($npc, path: self::getTransitionPath($posture),
                label: sprintf('Into %s Clip', ucfirst($posture))));
        }

        return $fields;
    }

    /** The posture values, the Engine's own. @return list<string> */
    public static function listPostures(): array
    {
        return array_map(static fn(BedPosture $posture): string => $posture->value, BedPosture::cases());
    }

    /**
     * Keeps an NPC's bed consistent after one of its fields is edited, then has
     * the Engine read it. Clearing the bed object takes the NPC out of its bed;
     * choosing another object drops an attachment chosen on the old one. A bed
     * whose attachment is still to be chosen is a draft validation reports.
     *
     * @param array<string, mixed> $npc The NPC entry, edited.
     * @param array<string, mixed> $before The NPC entry before the edit.
     * @return array<string, mixed>
     * @throws InvalidArgumentException When the edit leaves a bed the Engine refuses.
     */
    public static function prepareEdit(array $npc, string $field, array $before = []): array
    {
        if (! str_starts_with($field, self::BED . '.')) {
            return $npc;
        }
        if ($field === self::OBJECT) {
            if (! is_string($npc[self::BED]['object'] ?? null)) {
                unset($npc[self::BED]);
                return $npc;
            }
            if (($before[self::BED]['object'] ?? null) !== $npc[self::BED]['object']) {
                unset($npc[self::BED]['attachment']);
            }
        }
        $contract = CinematicCommandSchema::export()['bedOccupancy'];
        foreach (array_map(self::getPosePath(...), $contract['poseRoles']) as $path) {
            $npc = FieldSpriteFields::prepareEdit($npc, $field, path: $path);
        }
        foreach ($contract['transitionRoles'] as $posture) {
            $path = self::getTransitionPath($posture);
            if (str_starts_with($field, "$path.animation.") && is_array($npc[self::BED]['transitions2d'][$posture] ?? null)
                && ! isset($npc[self::BED]['transitions2d'][$posture]['animation']['loop'])) {
                // A clip plays once on the way into its posture; the Engine refuses one that loops.
                $npc[self::BED]['transitions2d'][$posture]['animation']['loop'] = false;
            }
            $npc = FieldSpriteFields::prepareEdit($npc, $field, path: $path);
        }
        $bed = $npc[self::BED];
        try {
            NpcBedDefinition::fromArray($bed);
        } catch (InvalidArgumentException $error) {
            if (array_key_exists('attachment', $bed)) {
                throw $error;
            }
            // Read with a placeholder attachment, so everything else the bed declares is still judged now.
            NpcBedDefinition::fromArray([...$bed, 'attachment' => 'pending']);
        }

        return $npc;
    }

    private static function getPosePath(string $posture): string
    {
        return self::BED . '.poses2d.' . $posture;
    }

    private static function getTransitionPath(string $posture): string
    {
        return self::BED . '.transitions2d.' . $posture;
    }
}
