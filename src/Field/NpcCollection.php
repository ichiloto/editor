<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\Slug;
use RuntimeException;

/**
 * A map's NPCs, read from and written back into the map's `npcs` array.
 *
 * The collection is a value: every operation returns the rewritten list of
 * entries for the owning map to store, so undo is "put the previous list
 * back" and every mutation goes through one place instead of the
 * coordinator reaching into `$mapData['npcs']`. List position is identity
 * for the editor's selection; the stable `id` is identity for the runtime.
 *
 * @package Ichiloto\Editor\Field
 */
final class NpcCollection
{
    /**
     * @var ProjectNpc[] The NPCs, in authored order.
     */
    private array $npcs = [];

    /**
     * @var array<int, mixed> The raw entries, so a non-array oddity an
     * author put in the list survives rather than being silently dropped.
     */
    private array $raw = [];

    /**
     * Reads a map's `npcs` array.
     *
     * @param mixed $entries The array as authored, or anything else.
     * @return self The collection.
     */
    public static function fromMapData(mixed $entries): self
    {
        $collection = new self();

        if (! is_array($entries)) {
            return $collection;
        }

        foreach (array_values($entries) as $index => $entry) {
            $collection->raw[$index] = $entry;

            if (is_array($entry)) {
                $collection->npcs[$index] = new ProjectNpc($entry);
            }
        }

        return $collection;
    }

    /**
     * Returns the array to store back under `npcs`.
     *
     * @return array<int, mixed> The entries, in order.
     */
    public function toMapData(): array
    {
        $entries = [];

        foreach ($this->raw as $index => $entry) {
            $entries[] = isset($this->npcs[$index]) ? $this->npcs[$index]->toArray() : $entry;
        }

        return $entries;
    }

    /**
     * @return ProjectNpc[] The NPCs, keyed by list position.
     */
    public function all(): array
    {
        return $this->npcs;
    }

    public function count(): int
    {
        return count($this->npcs);
    }

    public function get(int $index): ?ProjectNpc
    {
        return $this->npcs[$index] ?? null;
    }

    /**
     * Returns the position of the NPC anchored at a tile, if any.
     *
     * @param int $x The column.
     * @param int $y The row.
     * @return int|null The position.
     */
    public function indexAt(int $x, int $y): ?int
    {
        foreach ($this->npcs as $index => $npc) {
            if ($npc->getX() === $x && $npc->getY() === $y) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Returns the position of the NPC with a stable id, if any.
     *
     * @param string $id The id.
     * @return int|null The position.
     */
    public function indexOfId(string $id): ?int
    {
        foreach ($this->npcs as $index => $npc) {
            if ($npc->getId() === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return string[] Every stable id in the collection.
     */
    public function ids(): array
    {
        $ids = [];

        foreach ($this->npcs as $npc) {
            if ($npc->getId() !== null) {
                $ids[] = $npc->getId();
            }
        }

        return $ids;
    }

    /**
     * Returns an id like the name's that no NPC here is using.
     *
     * @param string $name The name to derive from.
     * @return string The unique id.
     */
    public function uniqueIdFor(string $name): string
    {
        return Slug::unique($name, $this->ids(), 'npc');
    }

    /**
     * Returns a copy with an NPC appended.
     *
     * @param ProjectNpc $npc The NPC; its id must be unique here.
     * @return self The rewritten collection.
     */
    public function withAdded(ProjectNpc $npc): self
    {
        $this->assertIdFree($npc->getId(), null);
        $copy = clone $this;
        $index = count($copy->raw);
        $copy->raw[$index] = $npc->toArray();
        $copy->npcs[$index] = $npc;

        return $copy;
    }

    /**
     * Returns a copy with an NPC re-inserted at the position it held — the
     * undo of a removal.
     *
     * @param int $index The position.
     * @param ProjectNpc $npc The NPC.
     * @return self The rewritten collection.
     */
    public function withInsertedAt(int $index, ProjectNpc $npc): self
    {
        $this->assertIdFree($npc->getId(), null);
        $entries = $this->toMapData();
        array_splice($entries, min($index, count($entries)), 0, [$npc->toArray()]);

        return self::fromMapData($entries);
    }

    /**
     * Returns a copy with the NPC at a position replaced.
     *
     * @param int $index The position.
     * @param ProjectNpc $npc The replacement.
     * @return self The rewritten collection.
     */
    public function withReplaced(int $index, ProjectNpc $npc): self
    {
        if (! isset($this->npcs[$index])) {
            return $this;
        }

        $this->assertIdFree($npc->getId(), $index);
        $copy = clone $this;
        $copy->raw[$index] = $npc->toArray();
        $copy->npcs[$index] = $npc;

        return $copy;
    }

    /**
     * Returns a copy without the NPC at a position.
     *
     * @param int $index The position.
     * @return self The rewritten collection.
     */
    public function withRemoved(int $index): self
    {
        $entries = $this->toMapData();

        if (! array_key_exists($index, $entries)) {
            return $this;
        }

        array_splice($entries, $index, 1);

        return self::fromMapData($entries);
    }

    /**
     * Refuses an id another NPC already holds.
     *
     * @param string|null $id The id to place.
     * @param int|null $ownIndex The position the id may already occupy.
     * @return void
     */
    private function assertIdFree(?string $id, ?int $ownIndex): void
    {
        if ($id === null) {
            return;
        }

        $existing = $this->indexOfId($id);

        if ($existing !== null && $existing !== $ownIndex) {
            throw new RuntimeException(sprintf('Another NPC on this map already has the id "%s".', $id));
        }
    }
}
