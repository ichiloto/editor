<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordSubList;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\NpcPlacementSet;
use Ichiloto\Engine\Field\Validation\MapNpcPlacementRule;
use InvalidArgumentException;

/**
 * Authors an NPC's named placements (`CinematicCommandSchema::export()['npcPlacements']`):
 * stable names, each with the cell the NPC stands on there and an optional
 * heading. The NPC's own X and Y are its reserved `default` placement.
 * `commit_npc_placement` saves one of these names once the real NPC has
 * walked there; the map's current cells resolve it at load.
 *
 * The Engine owns every rule. Its `NpcPlacementSet` reads the names and
 * cells, and its `MapNpcPlacementRule` checks every destination against the
 * map's resolved physical floor. {@see assertPlacements()} runs both on the
 * NPC an edit changes, before the edit is kept.
 *
 * @package Ichiloto\Editor\Field
 */
final class NpcPlacementFields
{
    /** The NPC field holding its named placements. */
    public const string PLACEMENTS = 'placements';

    /** The settings-id prefix of a placement's rows. */
    public const string PREFIX = 'placement';

    /** The field a placement carries its stable name as while it is edited. */
    public const string NAME = 'id';

    private function __construct()
    {
    }

    /**
     * The NPC's named placements as a list beside its pages, keyed by name.
     * A new one starts on the NPC's own cell, which is floor wherever the NPC
     * itself may stand.
     */
    public static function getList(): RecordSubList
    {
        return new RecordSubList(
            key: self::PLACEMENTS,
            prefix: self::PREFIX,
            singular: 'placement',
            fields: [
                new RecordField(self::NAME, 'Name'),
                new RecordField('x', 'X', InputControlType::INTEGER),
                new RecordField('y', 'Y', InputControlType::INTEGER),
                // Absent, the placement keeps the heading the NPC stands with.
                new RecordField('facing', 'Facing', options: ProjectNpc::DIRECTIONS, removeWhenEmpty: true, displayDefault: '(as it stands facing)'),
            ],
            blank: [self::NAME => 'placement', 'x' => 0, 'y' => 0],
            heading: 'Named Placements',
            removeWhenEmpty: true,
            keyField: self::NAME,
            blankFor: static fn(array $npc): array => [self::NAME => 'placement', 'x' => intval($npc['x'] ?? 0), 'y' => intval($npc['y'] ?? 0)],
        );
    }

    /**
     * The named placements an NPC declares as authored, by name; the Engine
     * refusing them does not hide what the author wrote.
     *
     * @param array<string, mixed> $npc
     * @return list<string>
     */
    public static function listAuthoredNames(array $npc): array
    {
        $placements = $npc[self::PLACEMENTS] ?? null;

        return is_array($placements) ? array_values(array_filter(array_map(strval(...), array_keys($placements)), static fn(string $name): bool => $name !== '')) : [];
    }

    /**
     * Refuses an NPC whose placements the game would refuse to load: names
     * and cells the Engine's reader rejects, or a destination, its root cell
     * included, that is not clear physical floor on the map as it stands. An
     * NPC without placements has nothing to check.
     *
     * @param array<string, mixed> $npc The NPC entry as the edit leaves it.
     * @throws InvalidArgumentException Naming what the Engine refuses.
     */
    public static function assertPlacements(ProjectMap $map, array $npc): void
    {
        if (! array_key_exists(self::PLACEMENTS, $npc)) {
            return;
        }
        NpcPlacementSet::fromEntry($npc);
        $issues = MapNpcPlacementRule::inspect($map->mapId, ['npcs' => [$npc]], $map->getResolvedCollisionMap());
        if ($issues !== []) {
            throw new InvalidArgumentException(sprintf('%s: %s', strval($npc['name'] ?? $npc['id'] ?? 'NPC'), $issues[0]->message));
        }
    }
}
