<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Database\RecordChange;
use Ichiloto\Editor\Database\RecordItem;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\Slug;
use Ichiloto\Engine\Core\WorldConditionExclusion;
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
 * anchors to, a new NPC's stable id is the one its author chose or else
 * derives from its name, an id is unique on its map and changes only while
 * nothing names it, an NPC something names is never deleted, and a renamed
 * NPC whose id its name derived takes the id its new name derives only
 * while nothing names its old one; a chosen id stays. Each operation applies its
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

    public function __construct(private ProjectWorkspace $workspace)
    {
        $this->references = new NpcReferences($workspace);
    }

    /**
     * Creates a fixed NPC at a tile under the stable id its author chose, or
     * one derived from its name.
     *
     * @param string $name The display name; blank takes {@see DEFAULT_NAME}.
     * @param string|null $id The id; null derives one from the name.
     * @throws NpcRefusal When the tile is outside the map or another NPC anchors there while both can be visible, or the id is not one or is taken.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function create(ProjectMap $map, int $x, int $y, string $name, ?string $id = null): NpcChange
    {
        $before = $map->getNpcs();
        $this->assertInsideMap($map, $x, $y);
        $name = trim($name) === '' ? self::DEFAULT_NAME : trim($name);
        $npc = ProjectNpc::createAt($id === null ? $before->uniqueIdFor($name) : self::requireChosenId($before, null, $id), $name, $x, $y);
        $this->assertPlaceable($map, $before, null, $npc);
        $after = $before->withAdded($npc);
        $map->setNpcs($after);

        return new NpcChange($this->createCommand($map, 'NPC create', $before, $after), $before->count(), $npc);
    }

    /**
     * Moves an NPC's anchor to a tile. A move to where it already stands
     * changes nothing.
     *
     * @throws NpcRefusal When there is no such NPC, the tile is outside the map, or another NPC anchors there while both can be visible.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function move(ProjectMap $map, int $index, int $x, int $y): NpcChange
    {
        $before = $map->getNpcs();
        $npc = $this->requireNpc($map, $before, $index);

        if ($npc->getX() === $x && $npc->getY() === $y) {
            return new NpcChange(null, $index, $npc);
        }

        $moved = $npc->movedTo($x, $y);
        $this->assertPlaceable($map, $before, $index, $moved);
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

        if ($x < $map->getWidth() && $before->indicesAt($x, $npc->getY()) === []) {
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
     * Gives an NPC authored without a stable id the one its author chose, or
     * one derived from its name and unique on its map, so movement routes can
     * target it. An existing id changes through {@see setId()}, which checks
     * what names it.
     *
     * @param string|null $id The id; null derives one from the name.
     * @throws NpcRefusal When there is no such NPC, it already has an id, or the chosen id is not one or is taken.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function assignId(ProjectMap $map, int $index, ?string $id = null): NpcChange
    {
        $before = $map->getNpcs();
        $npc = $this->requireNpc($map, $before, $index);

        if ($npc->getId() !== null) {
            throw new NpcRefusal(sprintf('%s already has the stable id "%s"; change it through its Id row.', $npc->getName(), $npc->getId()));
        }

        $id = $id === null ? $before->uniqueIdFor($npc->getName()) : self::requireChosenId($before, $index, $id);
        $identified = $npc->asCopyWithId($id);
        $after = $before->withReplaced($index, $identified);
        $map->setNpcs($after);

        return new NpcChange($this->createCommand($map, sprintf('Assign NPC id %s', $id), $before, $after), $index, $identified);
    }

    /**
     * Gives an NPC the stable id its author chose, refusing while anything
     * names its current one: a route or script that silently stops resolving
     * is worse than a refusal, and the refusal's details are what the author
     * goes to change. An NPC without an id simply takes it.
     *
     * @throws NpcRefusal When there is no such NPC, the id is not one or is taken, or something names the current id.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function setId(ProjectMap $map, int $index, string $id): NpcChange
    {
        $before = $map->getNpcs();
        $npc = $this->requireNpc($map, $before, $index);
        $id = self::requireChosenId($before, $index, $id);
        $current = $npc->getId();

        if ($current === $id) {
            return new NpcChange(null, $index, $npc);
        }

        $references = $current === null ? [] : $this->references->describe($map, $current);

        if ($references !== []) {
            throw new NpcRefusal(
                sprintf('%s keeps the id "%s": %s name%s it. Change those first.', $npc->getName(), $current, implode(', ', $references),
                    count($references) === 1 ? 's' : ''),
                array_map(static fn(string $reference): string => '- ' . $reference, $references),
            );
        }

        $identified = $current === null ? $npc->asCopyWithId($id) : $npc->withId($id);
        $after = $before->withReplaced($index, $identified);
        $map->setNpcs($after);

        return new NpcChange($this->createCommand($map, sprintf('NPC id %s', $id), $before, $after), $index, $identified);
    }

    /**
     * Applies one row's edit through the NPC's record pane. A new name
     * carries an id its old name derived with it while nothing names the old
     * id; otherwise the id stays and the change says what names it. The
     * rename and its id are one undo step. The Id row changes the id as
     * {@see setId()} does. The X and Y rows move the NPC, under the same tile
     * rules as {@see move()}, and so does a visibility edit to an NPC sharing
     * its tile with another.
     *
     * @param array<int, int|string> $framePath The frame the row belongs to; [] for the NPC itself.
     * @param array<string, mixed> $field The row, as {@see NpcInspector::getFields()} gave it.
     * @throws NpcRefusal When there is no such NPC or frame, a coordinate leaves the map, or a move or visibility edit leaves two NPCs on one tile visible at once.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function applyField(NpcInspector $inspector, int $index, array $framePath, array $field, string $rawValue): NpcChange
    {
        $map = $inspector->map;
        $before = $map->getNpcs();
        $this->requireFrame($inspector, $before, $index, $framePath);
        $fieldId = (string) ($field['field'] ?? '');

        if ($framePath === [] && $fieldId === 'id') {
            try {
                return $this->setId($map, $index, $rawValue);
            } finally {
                $inspector->refresh();
            }
        }

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

            if ($placed !== null && $was !== null && ($placed->getX() !== $was->getX() || $placed->getY() !== $was->getY()
                || $placed->getConditions() !== $was->getConditions())) {
                // A coordinate row is a move, and a visibility edit can make
                // phases sharing a tile visible together: the tile rules a
                // move keeps apply to both.
                $this->assertPlaceable($map, $before, $index, $placed);
            }

            if ($framePath === [] && $fieldId === 'name') {
                [$after, $followedId, $idReferences] = $this->followNameWithId($map, $before, $after, $index);
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
     * Moves the page a row of the NPC's record pane belongs to one place
     * earlier or later. Talking runs the first available page, so the order
     * is the NPC's priority. A row of no page changes nothing.
     *
     * @param string $fieldId Any row of the page: its conditions, cue or a line.
     * @param int $step -1 for earlier, 1 for later.
     * @throws NpcRefusal When there is no such NPC.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    public function moveSubItem(NpcInspector $inspector, int $index, string $fieldId, int $step): NpcChange
    {
        $records = $inspector->records();
        $item = $records->locateItem($index, [], $fieldId);

        return $this->changeRows($inspector, $index, [], 'NPC page move',
            static fn(RecordAuthoring $authoring): RecordChange => $item === null || $item->framePath !== []
                ? new RecordChange(null)
                : $authoring->moveEntry($records, $index, $item->entryIndex, $step, $item->listKey));
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
     * Gives a renamed NPC the id its new name derives, when its id is the
     * one its old name derived and nothing names it. An id an author chose
     * is not the name's to change.
     *
     * @return array{0: NpcCollection, 1: ?string, 2: list<string>} The NPCs afterwards, the id taken, and what names the id kept.
     * @throws MapSourceRefusal When the map's source cannot take the change.
     */
    private function followNameWithId(ProjectMap $map, NpcCollection $before, NpcCollection $npcs, int $index): array
    {
        $npc = $npcs->get($index);
        $id = $npc?->getId();
        $was = $before->get($index);

        if ($npc === null || $id === null || $was === null || $before->withRemoved($index)->uniqueIdFor($was->getName()) !== $id) {
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
     * An id an author chose for an NPC: the form ids the editor derives take
     * (lowercase letters and digits joined by single hyphens), so routes and
     * scripts name it without quoting, and no other NPC on the map has it.
     *
     * @param int|null $ownIndex The NPC taking it, when it is already on the map.
     * @throws NpcRefusal When it is not such an id, or another NPC has it.
     */
    private static function requireChosenId(NpcCollection $npcs, ?int $ownIndex, string $id): string
    {
        $id = trim($id);

        if ($id === '' || Slug::of($id) !== $id) {
            throw new NpcRefusal(sprintf('"%s" is not an NPC id: lowercase letters and digits joined by single hyphens, such as "gate-guard".', $id));
        }

        foreach ($npcs->all() as $index => $npc) {
            if ($index !== $ownIndex && $npc->getId() === $id) {
                throw new NpcRefusal(sprintf('%s already has the id "%s" on this map.', $npc->getName(), $id));
            }
        }

        return $id;
    }

    /**
     * Refuses a tile outside the map, or one another NPC anchors to while
     * both can be visible.
     *
     * The game shows an NPC only while its visibility conditions hold, so
     * two NPCs may share a tile as phases of one presence when their
     * conditions provably exclude each other ({@see WorldConditionExclusion}).
     * Any other pair would be two bodies on one tile.
     *
     * @param int|null $ownIndex The NPC being placed, when it is already on the map.
     * @param ProjectNpc $npc The NPC as it would stand, conditions included.
     * @throws NpcRefusal
     */
    private function assertPlaceable(ProjectMap $map, NpcCollection $npcs, ?int $ownIndex, ProjectNpc $npc): void
    {
        $x = $npc->getX();
        $y = $npc->getY();
        $this->assertInsideMap($map, $x, $y);
        // Phases are judged with the project's current world abilities, never another project's catalogue.
        $worldAbilities = $this->workspace->getWorldAbilities();

        foreach ($npcs->indicesAt($x, $y) as $occupant) {
            $other = $npcs->get($occupant);

            if ($occupant === $ownIndex || $other === null
                || $worldAbilities->proveExclusive($npc->getConditions(), $other->getConditions())) {
                continue;
            }

            throw new NpcRefusal(sprintf(
                '%s already stands at %d,%d while both can be visible. Give one a condition the other negates, such as event:x and !event:x, to share the tile.',
                $other->getName(), $x, $y,
            ));
        }
    }

    /** @throws NpcRefusal */
    private function assertInsideMap(ProjectMap $map, int $x, int $y): void
    {
        if ($x < 0 || $y < 0 || $x >= $map->getWidth() || $y >= $map->getHeight()) {
            throw new NpcRefusal(sprintf('%d,%d is outside the map.', $x, $y));
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

    /**
     * Gives an NPC the RPG Maker character sheet it walks with in the
     * graphical field, or takes it away (null), as one undo step. The sheet's
     * other settings are kept, the terminal sprite is untouched, and the sheet
     * is checked as the game will read it ({@see ProjectMap::setNpcGraphicalSprites()}).
     *
     * @throws NpcRefusal When there is no such NPC.
     * @throws MapSourceRefusal When the sheet is not a usable character sheet or the map's source cannot take the change.
     */
    public function setFieldSheet(ProjectMap $map, int $index, ?string $sheet): NpcChange
    {
        $before = $map->getNpcs();
        $npc = $this->requireNpc($map, $before, $index);
        $current = $npc->toArray()['sprites2d'] ?? null;
        $sheet = $sheet === null ? '' : trim($sheet);
        $map->setNpcGraphicalSprites($index, $sheet === '' ? null : [...(is_array($current) ? $current : []), 'sheet' => $sheet]);

        return $this->createRowChange($map, 'NPC field sheet', $before, $index);
    }

    public function setFieldSheetIndex(ProjectMap $map, int $index, int $characterIndex): NpcChange
    {
        return $this->setFieldSheetValue($map, $index, 'index', $characterIndex);
    }

    public function setFieldSheetLayer(ProjectMap $map, int $index, int $layer): NpcChange
    {
        return $this->setFieldSheetValue($map, $index, 'layer', $layer);
    }

    /** Changes only the selected sheet setting through the map's validated, source-safe writer. */
    private function setFieldSheetValue(ProjectMap $map, int $index, string $field, int $value): NpcChange
    {
        $before = $map->getNpcs();
        $npc = $this->requireNpc($map, $before, $index);
        $current = $npc->toArray()['sprites2d'] ?? null;
        if (! is_array($current) || ! is_string($current['sheet'] ?? null) || $current['sheet'] === '') {
            throw new NpcRefusal('Select an NPC field sheet before editing its character index or graphical layer.');
        }
        $map->setNpcGraphicalSprites($index, [...$current, $field => $value]);

        return $this->createRowChange($map, 'NPC field sheet ' . $field, $before, $index);
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
