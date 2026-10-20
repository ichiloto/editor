<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Field\MapLandmarks;
use Ichiloto\Editor\Field\WorldObjectAuthoring;
use Ichiloto\Editor\Field\NpcCollection;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapLandmarkAnchors;
use InvalidArgumentException;

/**
 * Where the subjects a relative position may name stand on one map as it is authored, unsaved edits included: its
 * NPCs by stable id, its world objects at their anchors and its event markers at their first cell, read through the
 * Engine's own anchor adapter, and the terminal cells of the attachments their resources declare. Subjects that only exist while a scene runs (the player, party actors, staged actors)
 * are named as such, never guessed.
 */
final readonly class MapSubjectCells
{
    private function __construct(
        public string $mapId,
        private NpcCollection $npcs,
        private ?MapLandmarkAnchors $anchors,
        private ?string $anchorIssue,
        /** @var array<string, array<string, array{int, int}>> Attachment cells by object id, then attachment id. */
        private array $attachments = [],
    ) {
    }

    /** Reads the map as it stands now, so a later edit to it does not change what was read. */
    public static function readMap(ProjectMap $map): self
    {
        try {
            return new self($map->mapId, $map->getNpcs(), MapLandmarks::readAnchors($map), null, self::readAttachments($map));
        } catch (InvalidArgumentException $error) {
            return new self($map->mapId, $map->getNpcs(), null, $error->getMessage());
        }
    }

    /** @return array<string, array<string, array{int, int}>> */
    private static function readAttachments(ProjectMap $map): array
    {
        try {
            $entries = WorldObjectAuthoring::readEntries($map);
        } catch (InvalidArgumentException) {
            return [];
        }
        $cells = [];
        foreach ($entries as $entry) {
            $found = is_string($entry['id'] ?? null) ? WorldObjectAuthoring::findAttachments($map, $entry['id']) : null;
            foreach ($found['attachments'] ?? [] as $attachment) {
                $cells[$entry['id']][$attachment->id] = [$found['anchor']['x'] + $attachment->cellX, $found['anchor']['y'] + $attachment->cellY];
            }
        }
        return $cells;
    }

    /**
     * The cell a subject reference stands on, or why it has none here.
     *
     * @param array<string, mixed> $reference An `at` reference: kind, optional id and, on a world object, optional attachment.
     * @return array{cell: array{int, int}|null, issue: string|null}
     */
    public function resolveCell(array $reference): array
    {
        $kind = strval($reference['kind'] ?? '');
        $id = is_string($reference['id'] ?? null) ? $reference['id'] : '';
        if (in_array($kind, ['npc', 'world_object', 'marker'], true) && $id === '') {
            return self::describeIssue('Choose the subject this position is relative to.');
        }
        if ($kind === 'world_object' && is_string($reference['attachment'] ?? null)) {
            $cell = $this->attachments[$id][$reference['attachment']] ?? null;
            return $cell === null
                ? self::describeIssue(sprintf('World object %s on map %s has no attachment %s.', $id, $this->mapId, $reference['attachment']))
                : ['cell' => $cell, 'issue' => null];
        }

        return match ($kind) {
            'npc' => $this->resolveNpcCell($id),
            'world_object' => $this->resolveAnchorCell($this->anchors?->objects, $id, 'world object'),
            'marker' => $this->resolveAnchorCell($this->anchors?->events, $id, 'event marker'),
            'player' => self::describeIssue('The player stands where the scene finds them; this point is known only while it runs.'),
            'party_actor' => self::describeIssue('A party actor stands where the scene finds it; this point is known only while it runs.'),
            'staged_actor' => self::describeIssue('A staged actor stands where the scene puts it; this point is known only while it runs.'),
            default => self::describeIssue('Choose what this position is relative to.'),
        };
    }

    /** @return array{cell: array{int, int}|null, issue: string|null} */
    private function resolveNpcCell(string $id): array
    {
        $index = $this->npcs->indexOfId($id);
        $npc = $index === null ? null : $this->npcs->get($index);

        return $npc === null
            ? self::describeIssue(sprintf('Map %s has no NPC %s.', $this->mapId, $id))
            : ['cell' => [$npc->getX(), $npc->getY()], 'issue' => null];
    }

    /**
     * @param array<string, \Ichiloto\Engine\Field\MapLandmarkAnchor>|null $anchors
     * @return array{cell: array{int, int}|null, issue: string|null}
     */
    private function resolveAnchorCell(?array $anchors, string $id, string $noun): array
    {
        if ($anchors === null) {
            return self::describeIssue(sprintf('Map %s cannot be read: %s', $this->mapId, strval($this->anchorIssue)));
        }
        $anchor = $anchors[$id] ?? null;
        if ($anchor === null) {
            return self::describeIssue(sprintf('Map %s has no %s %s.', $this->mapId, $noun, $id));
        }
        $cell = $anchor->area->firstCell;

        return ['cell' => [(int) $cell->x, (int) $cell->y], 'issue' => null];
    }

    /** @return array{cell: null, issue: string} */
    private static function describeIssue(string $issue): array
    {
        return ['cell' => null, 'issue' => $issue];
    }
}
