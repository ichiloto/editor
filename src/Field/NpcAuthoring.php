<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Database\RecordChange;
use Ichiloto\Editor\Database\RecordItem;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use InvalidArgumentException;
use Throwable;

/**
 * Authoring a map's NPCs, as every editor interface does it: creating,
 * moving, duplicating, deleting, giving an id, and editing one NPC's rows
 * through its record pane ({@see NpcInspector}).
 *
 * The rules live here: an NPC stands inside the map on a tile no other NPC
 * anchors to, a new NPC's stable id derives from its name, an NPC something
 * names is never deleted, and a renamed NPC takes the id its new name
 * derives only while nothing names its old one. Each operation applies its
 * change to the map and returns an {@see NpcChange} whose command redoes and
 * undoes exactly the map's NPC collection; selection, prompts and status
 * belong to the interface. A change that cannot be made is an
 * {@see NpcRefusal}, and a map whose source cannot take it a
 * {@see MapSourceRefusal}; either way nothing changed.
 *
 * @package Ichiloto\Editor\Field
 */
final readonly class NpcAuthoring
{
    /**
     * The name a new NPC takes when none is given.
     */
    public const string DEFAULT_NAME = 'New NPC';

    private NpcReferences $references;

    public function __construct(ProjectWorkspace $workspace)
    {
        $this->references = new NpcReferences($workspace);
    }

    /**
     * Creates a fixed NPC at a tile, its stable id derived from its name.
     *
     * @param string $name The display name; blank takes {@see DEFAULT_NAME}.
     * @throws NpcRefusal When the tile is outside the map or another NPC anchors there.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function create(ProjectMap $map, int $x, int $y, string $name): NpcChange
    {
        $before = $map->getNpcs();
        $this->assertPlaceable($map, $before, null, $x, $y);
        $name = trim($name) === '' ? self::DEFAULT_NAME : trim($name);
        $npc = ProjectNpc::createAt($before->uniqueIdFor($name), $name, $x, $y);
        $after = $before->withAdded($npc);
        $map->setNpcs($after);

        return new NpcChange($this->createCommand($map, 'NPC create', $before, $after), $before->count(), $npc);
    }

    /**
     * Moves an NPC's anchor to a tile. A move to where it already stands
     * changes nothing.
     *
     * @throws NpcRefusal When there is no such NPC, the tile is outside the map, or another NPC anchors there.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function move(ProjectMap $map, int $index, int $x, int $y): NpcChange
    {
        $before = $map->getNpcs();
        $npc = $this->requireNpc($map, $before, $index);

        if ($npc->getX() === $x && $npc->getY() === $y) {
            return new NpcChange(null, $index, $npc);
        }

        $this->assertPlaceable($map, $before, $index, $x, $y);
        $moved = $npc->movedTo($x, $y);
        $after = $before->withReplaced($index, $moved);
        $map->setNpcs($after);

        return new NpcChange($this->createCommand($map, 'NPC move', $before, $after), $index, $moved);
    }

    /**
     * Duplicates an NPC under a fresh id derived from its name, one sprite
     * width to the right when that tile is inside the map and free, and on
     * its own tile otherwise. The copy is appended.
     *
     * @throws NpcRefusal When there is no such NPC.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function duplicate(ProjectMap $map, int $index): NpcChange
    {
        $before = $map->getNpcs();
        $npc = $this->requireNpc($map, $before, $index);
        $copy = $npc->asCopyWithId($before->uniqueIdFor($npc->getName()));
        $x = $npc->getX() + $npc->getSpriteWidth();

        if ($x < $map->getWidth() && $before->indexAt($x, $npc->getY()) === null) {
            $copy = $copy->movedTo($x, $npc->getY());
        }

        $after = $before->withAdded($copy);
        $map->setNpcs($after);

        return new NpcChange($this->createCommand($map, 'NPC duplicate', $before, $after), $before->count(), $copy);
    }

    /**
     * Deletes an NPC, refusing while anything names its id: a route or
     * script that silently stops resolving is worse than a refusal, and the
     * refusal's details are what the author goes to fix.
     *
     * @throws NpcRefusal When there is no such NPC or something names it.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function delete(ProjectMap $map, int $index): NpcChange
    {
        $before = $map->getNpcs();
        $npc = $this->requireNpc($map, $before, $index);
        $references = $npc->getId() !== null ? $this->references->describe($map, $npc->getId()) : [];

        if ($references !== []) {
            throw new NpcRefusal(
                sprintf('%s is named by %s - resolve those before deleting.', $npc->getName(), implode(', ', $references)),
                array_map(static fn(string $reference): string => '- ' . $reference, $references),
            );
        }

        $after = $before->withRemoved($index);
        $map->setNpcs($after);

        return new NpcChange($this->createCommand($map, 'NPC delete', $before, $after), null, $npc);
    }

    /**
     * Gives an NPC authored without a stable id one, derived from its name
     * and unique on its map, so movement routes can target it. An existing
     * id never changes here: what names it would not follow.
     *
     * @throws NpcRefusal When there is no such NPC or it already has an id.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function assignId(ProjectMap $map, int $index): NpcChange
    {
        $before = $map->getNpcs();
        $npc = $this->requireNpc($map, $before, $index);

        if ($npc->getId() !== null) {
            throw new NpcRefusal(sprintf('%s already has the stable id "%s"; ids do not change.', $npc->getName(), $npc->getId()));
        }

        $id = $before->uniqueIdFor($npc->getName());
        $identified = $npc->asCopyWithId($id);
        $after = $before->withReplaced($index, $identified);
        $map->setNpcs($after);

        return new NpcChange($this->createCommand($map, sprintf('Assign NPC id %s', $id), $before, $after), $index, $identified);
    }

    /**
     * Applies one row's edit through the NPC's record pane. A new name
     * carries the id with it while nothing names the old id; otherwise the
     * id stays and the change says what names it. The rename and its id
     * are one undo step. The X and Y rows move the NPC, under the same tile
     * rules as {@see move()}.
     *
     * @param array<int, int|string> $framePath The frame the row belongs to; [] for the NPC itself.
     * @param array<string, mixed> $field The row, as {@see NpcInspector::getFields()} gave it.
     * @throws NpcRefusal When there is no such NPC or frame, or a coordinate leaves the map or lands on another NPC.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function applyField(NpcInspector $inspector, int $index, array $framePath, array $field, string $rawValue): NpcChange
    {
        $map = $inspector->map;
        $before = $map->getNpcs();
        $this->requireFrame($inspector, $before, $index, $framePath);
        $fieldId = (string) ($field['field'] ?? '');
        $followedId = null;
        $idReferences = [];

        try {
            try {
                $inspector->records()->setFrameField($index, $framePath, $fieldId, $rawValue);
            } catch (InvalidArgumentException $invalid) {
                // A value its field cannot take, such as an unreadable condition, is the author's to correct.
                throw new NpcRefusal($invalid->getMessage(), previous: $invalid);
            }
            $inspector->commit();
            $after = $map->getNpcs();
            $placed = $after->get($index);
            $was = $before->get($index);

            if ($framePath === [] && in_array($fieldId, ['x', 'y'], true) && $placed !== null && $was !== null
                && ($placed->getX() !== $was->getX() || $placed->getY() !== $was->getY())) {
                // A coordinate row is a move: the tile rules a move keeps apply.
                $this->assertPlaceable($map, $before, $index, $placed->getX(), $placed->getY());
            }

            if ($framePath === [] && $fieldId === 'name') {
                [$after, $followedId, $idReferences] = $this->followNameWithId($map, $after, $index);
            }
        } catch (Throwable $failure) {
            // The rename and its id are one change: when the id cannot
            // follow, the name does not land alone.
            if ($map->getNpcs()->toMapData() !== $before->toMapData()) {
                $map->setNpcs($before);
            }

            throw $failure;
        } finally {
            // The pane always shows the map, whether the write landed or not.
            $inspector->refresh();
        }

        return new NpcChange(
            $this->createCommand($map, sprintf('NPC %s edit', $field['label'] ?? 'field'), $before, $after),
            $index,
            $after->get($index),
            $followedId,
            $idReferences,
        );
    }

    /**
     * Adds an item at a row of the NPC's record pane: inside a script frame,
     * a route step under the route the row belongs to, another option on a
     * choice option's row, or a command after the row's command (at the
     * end when the row names none); at the root, a line in the row's
     * dialogue variant, or a new variant. Which item a row belongs to, and
     * how it is added, are the shared record rules' ({@see RecordAuthoring}).
     *
     * @param array<int, int|string> $framePath The frame the row belongs to.
     * @param string $fieldId The row's field id; '' for none.
     * @throws NpcRefusal When there is no such NPC or frame.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function addSubItem(NpcInspector $inspector, int $index, array $framePath, string $fieldId): NpcChange
    {
        $records = $inspector->records();
        $item = $records->locateItem($index, $framePath, $fieldId);
        // Inside a frame, any row of a command that owns steps adds a step.
        $nested = $framePath !== [] ? $records->frameNestedContext($index, $framePath, $fieldId) : null;

        return $this->changeRows($inspector, $index, $framePath, 'NPC add',
            static fn(RecordAuthoring $authoring): RecordChange => match (true) {
                $nested !== null => $authoring->addNestedItem($records, $index, $framePath, $nested['parentIndex']),
                $item?->kind === RecordItem::OPTION => $authoring->addOption($records, $index, $framePath, $item->entryIndex),
                $framePath !== [] => $authoring->addEntry($records, $index, $framePath, $item?->entryIndex),
                $item?->kind === RecordItem::NESTED => $authoring->addNestedItem($records, $index, $framePath, $item->entryIndex),
                default => $authoring->addEntry($records, $index, $framePath),
            });
    }

    /**
     * Removes the item a row of the NPC's record pane belongs to: a route
     * step, a choice's option, a command, a dialogue line or a dialogue
     * variant ({@see RecordAuthoring::removeItem()}). A row that belongs to
     * none changes nothing.
     *
     * @param array<int, int|string> $framePath The frame the row belongs to.
     * @param string $fieldId The row's field id.
     * @throws NpcRefusal When there is no such NPC or frame.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function removeSubItem(NpcInspector $inspector, int $index, array $framePath, string $fieldId): NpcChange
    {
        $records = $inspector->records();

        return $this->changeRows($inspector, $index, $framePath, 'NPC remove',
            static fn(RecordAuthoring $authoring): RecordChange => $authoring->removeItem($records, $index, $framePath, $fieldId));
    }

    /**
     * Makes one item change through the record pane and writes it back to
     * the map, as one undo step over the map's NPCs.
     *
     * @param array<int, int|string> $framePath
     * @param callable(RecordAuthoring): RecordChange $change
     * @throws NpcRefusal When there is no such NPC or frame, or the pane refuses the change.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    private function changeRows(NpcInspector $inspector, int $index, array $framePath, string $label, callable $change): NpcChange
    {
        $map = $inspector->map;
        $before = $map->getNpcs();
        $this->requireFrame($inspector, $before, $index, $framePath);

        try {
            // The pane's own undo step is over its records; the map's NPCs are
            // what this change's undo restores.
            $change(new RecordAuthoring());
            $inspector->commit();
        } catch (RecordRefusal $refusal) {
            throw new NpcRefusal($refusal->getMessage(), previous: $refusal);
        } finally {
            $inspector->refresh();
        }

        return $this->createRowChange($map, $label, $before, $index);
    }

    /**
     * Gives a renamed NPC the id its new name derives, unless something
     * names its current id.
     *
     * @return array{0: NpcCollection, 1: ?string, 2: list<string>} The NPCs afterwards, the id taken, and what names the id kept.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    private function followNameWithId(ProjectMap $map, NpcCollection $npcs, int $index): array
    {
        $npc = $npcs->get($index);
        $id = $npc?->getId();

        if ($npc === null || $id === null) {
            return [$npcs, null, []];
        }

        $derived = $npcs->withRemoved($index)->uniqueIdFor($npc->getName());

        if ($derived === $id) {
            return [$npcs, null, []];
        }

        $references = $this->references->describe($map, $id);

        if ($references !== []) {
            return [$npcs, null, $references];
        }

        $renamed = $npcs->withReplaced($index, $npc->withId($derived));
        $map->setNpcs($renamed);

        return [$renamed, $derived, []];
    }

    /**
     * Refuses a tile outside the map or one another NPC anchors to.
     *
     * @param int|null $ownIndex The NPC being placed, when it is already on the map.
     * @throws NpcRefusal
     */
    private function assertPlaceable(ProjectMap $map, NpcCollection $npcs, ?int $ownIndex, int $x, int $y): void
    {
        if ($x < 0 || $y < 0 || $x >= $map->getWidth() || $y >= $map->getHeight()) {
            throw new NpcRefusal(sprintf('%d,%d is outside the map.', $x, $y));
        }

        $occupant = $npcs->indexAt($x, $y);

        if ($occupant !== null && $occupant !== $ownIndex) {
            throw new NpcRefusal(sprintf('%s already stands at %d,%d.', $npcs->get($occupant)?->getName() ?? 'An NPC', $x, $y));
        }
    }

    /** @throws NpcRefusal */
    private function requireNpc(ProjectMap $map, NpcCollection $npcs, int $index): ProjectNpc
    {
        return $npcs->get($index) ?? throw new NpcRefusal(sprintf('%s has no NPC %d.', $map->mapId, $index));
    }

    /**
     * Refuses an NPC or a script frame that is no longer there.
     *
     * @param array<int, int|string> $framePath
     * @throws NpcRefusal
     */
    private function requireFrame(NpcInspector $inspector, NpcCollection $npcs, int $index, array $framePath): void
    {
        $this->requireNpc($inspector->map, $npcs, $index);

        if ($framePath !== [] && $inspector->records()->getFrameCommands($index, $framePath) === null) {
            throw new NpcRefusal(sprintf('%s is no longer there.', $inspector->records()->describeFramePath($framePath)));
        }
    }

    /** The change a row edit made to one NPC, with its command when it changed anything. */
    private function createRowChange(ProjectMap $map, string $label, NpcCollection $before, int $index): NpcChange
    {
        $after = $map->getNpcs();

        return new NpcChange($this->createCommand($map, $label, $before, $after), $index, $after->get($index));
    }

    /**
     * The command that puts either collection back, or null when the two
     * are the same: a same-value edit leaves no history.
     */
    private function createCommand(ProjectMap $map, string $label, NpcCollection $before, NpcCollection $after): ?Command
    {
        if ($after->toMapData() === $before->toMapData()) {
            return null;
        }

        return new GenericCommand(
            $label,
            static fn() => $map->setNpcs($after),
            static fn() => $map->setNpcs($before),
        );
    }
}
