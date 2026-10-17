<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use InvalidArgumentException;
use RuntimeException;

/**
 * Authoring a schema-driven category's records, as every editor interface
 * does it: editing a row, adding and removing the items a record's lists
 * hold, and creating, duplicating, moving and deleting records.
 *
 * Each operation applies its change and returns a {@see RecordChange}
 * whose command redoes and undoes exactly that change: a field edit swaps
 * the record's whole payload, an item or record goes back where it was.
 * Selection, prompts and status belong to the interface. A change that
 * cannot be made is a {@see RecordRefusal}, and nothing changed. Which item
 * a row belongs to is the category's own to say
 * ({@see ProjectRecordDatabase::locateItem()}); this decides what adding
 * or removing at that row does.
 *
 * @package Ichiloto\Editor\Database
 */
final readonly class RecordAuthoring
{
    /**
     * Applies one row's edit: a field of the record, of an entry in its
     * list, or of a command in one of its frames. A value its field cannot
     * take - a parameter line it cannot read, a coordinate pair that is not
     * two numbers - is refused with what is wrong with it.
     *
     * @param array<int, int|string> $framePath The frame the row belongs to; [] for the record itself.
     * @param string $label The row's label, which names the undo step.
     * @throws RecordRefusal When the category or record is read-only or gone, the frame is gone, or the value is refused.
     */
    public function applyField(
        ProjectRecordDatabase $database,
        int $index,
        array $framePath,
        string $fieldId,
        string $rawValue,
        string $label,
    ): RecordChange {
        $record = $this->requireRecord($database, $index);
        $this->requireFrame($database, $index, $framePath);
        $before = $record->toArray();

        try {
            if ($database->hasCommandFrames()) {
                $database->setFrameField($index, $framePath, $fieldId, $rawValue);
            } else {
                $database->setField($index, $fieldId, $rawValue);
            }
        } catch (ParameterMapSyntaxError|SourcePreservationRefusal|InvalidArgumentException $refused) {
            // The value is the author's to correct; the record was left as it was.
            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }

        $after = $record->toArray();

        if ($before === $after) {
            return new RecordChange(null, $index);
        }

        try {
            $database->assertSourceAccepts($index);
        } catch (SourcePreservationRefusal|SourceIdentityConflict|RuntimeException $refused) {
            // The file could not be saved with it: refused now, not at save.
            $record->restorePayload($before);

            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }

        return new RecordChange(new GenericCommand(
            sprintf('%s edit', $label),
            static fn() => $record->restorePayload($after),
            static fn() => $record->restorePayload($before),
        ), $index, note: self::describeIdentityFollow($database, $fieldId, $framePath, $before, $after));
    }

    /**
     * What a rename did to an identity that follows its label: moved with it,
     * or stayed because something refers to it.
     *
     * @param array<string, mixed>|object $before
     * @param array<string, mixed>|object $after
     */
    private static function describeIdentityFollow(ProjectRecordDatabase $database, string $fieldId, array $framePath, array|object $before, array|object $after): ?string
    {
        $schema = $database->schema;

        if (! $schema->identityFollowsLabel || $framePath !== [] || $fieldId !== $schema->labelKey || ! is_array($before) || ! is_array($after)) {
            return null;
        }

        $was = strval($before[$schema->identityKey] ?? '');
        $is = strval($after[$schema->identityKey] ?? '');

        if ($was !== $is) {
            return sprintf('Its id is now %s.', $is);
        }

        return Slug::of(strval($after[$schema->labelKey] ?? '')) === $is
            ? null
            : sprintf('Its id stays %s, which other things point at.', $is);
    }

    /**
     * Adds an item at a row: after the item the row belongs to, or, with
     * `$child`, at the end of what that item holds (a route's steps, a
     * choice's options). With no row it adds at the end of the frame, or of
     * the record's own list.
     *
     * @param array<int, int|string> $framePath The frame the row belongs to.
     * @param string|null $fieldId The row's field id; null for the end of the frame.
     * @param bool $child True to add beneath the row's item rather than after it.
     * @throws RecordRefusal When the category or record is read-only or gone, the frame is gone, or the row's item cannot take it.
     */
    public function addItem(ProjectRecordDatabase $database, int $index, array $framePath, ?string $fieldId, bool $child = false): RecordChange
    {
        // The list is the row's, so it is required where the row is found.
        $this->requireRecord($database, $index);
        $this->requireFrame($database, $index, $framePath);

        if ($fieldId === null) {
            if ($child) {
                throw new RecordRefusal('Name the row whose item the new one goes beneath.');
            }

            return $this->addEntry($database, $index, $framePath);
        }

        $item = $database->locateItem($index, $framePath, $fieldId)
            ?? throw new RecordRefusal('That row belongs to no item to add after; add at the end of the list instead.');

        if ($item->kind === RecordItem::LIST) {
            // A list's heading: the entry goes first in that list.
            return $this->addEntry($database, $index, $framePath, -1, $item->listKey);
        }

        if ($child) {
            return match ($item->kind === RecordItem::ENTRY ? $item->childKind : null) {
                RecordItem::NESTED => $this->addNestedItem($database, $index, $framePath, $item->entryIndex),
                RecordItem::OPTION => $this->addOption($database, $index, $framePath, $item->entryIndex),
                default => throw new RecordRefusal(sprintf('A %s holds nothing that can be added beneath it.', $item->noun)),
            };
        }

        return match ($item->kind) {
            RecordItem::NESTED => $this->addNestedItem($database, $index, $framePath, $item->entryIndex, $item->childIndex),
            RecordItem::OPTION => $this->addOption($database, $index, $framePath, $item->entryIndex, $item->childIndex),
            default => $this->addEntry($database, $index, $framePath, $item->entryIndex, $item->listKey),
        };
    }

    /**
     * Removes the item a row belongs to: a route step, line or lane, a
     * choice's option with its whole arm, or an entry or command with
     * everything under it. A row that belongs to none changes nothing.
     *
     * @param array<int, int|string> $framePath The frame the row belongs to.
     * @throws RecordRefusal When the category or record is read-only or gone, or the frame is gone.
     */
    public function removeItem(ProjectRecordDatabase $database, int $index, array $framePath, string $fieldId): RecordChange
    {
        $this->requireRecord($database, $index);
        $this->requireFrame($database, $index, $framePath);
        $item = $database->locateItem($index, $framePath, $fieldId);

        return match ($item?->kind) {
            null, RecordItem::LIST => new RecordChange(null),
            RecordItem::NESTED => $this->removeNestedItem($database, $index, $framePath, $item->entryIndex, (int) $item->childIndex),
            RecordItem::OPTION => $this->removeOption($database, $index, $framePath, $item->entryIndex, (int) $item->childIndex),
            default => $this->removeEntry($database, $index, $framePath, $item->entryIndex, $item->listKey),
        };
    }

    /**
     * Adds a blank entry to the record's own list, or a blank command to a
     * frame: after an entry, or at the end.
     *
     * @param array<int, int|string> $framePath The frame; [] for the record's own list.
     * @param int|null $after The entry it follows; null for the end, -1 for the start.
     * @param string|null $listKey At the record's root, the inline list; the record's own when null.
     * @throws RecordRefusal When the category or record is read-only or gone, or there is no such list.
     */
    public function addEntry(ProjectRecordDatabase $database, int $index, array $framePath, ?int $after = null, ?string $listKey = null): RecordChange
    {
        $list = $this->requireList($database, $index, $framePath, $listKey);
        $at = $framePath === []
            ? $database->addSubItem($index, at: $after === null ? null : $after + 1, listKey: $listKey)
            : $database->addFrameCommand($index, $framePath, $after);
        $entry = $at === null ? null : (($framePath === [] ? $database->getSubItems($index, $listKey) : $database->getFrameCommands($index, $framePath))[$at] ?? null);

        if ($at === null || ! is_array($entry)) {
            return new RecordChange(null);
        }

        return new RecordChange(new GenericCommand(
            sprintf('%s add', ucfirst($list->singular)),
            $framePath === []
                ? static fn() => $database->insertSubItem($index, $at, $entry, $listKey)
                : static fn() => $database->insertFrameCommand($index, $framePath, $at, $entry),
            $framePath === []
                ? static fn() => $database->removeSubItem($index, $at, $listKey)
                : static fn() => $database->removeFrameCommand($index, $framePath, $at),
        ), $at);
    }

    /**
     * Removes an entry of the record's own list, or a command of a frame,
     * with everything under it.
     *
     * @param array<int, int|string> $framePath The frame; [] for the record's own list.
     * @param string|null $listKey At the record's root, the inline list; the record's own when null.
     * @throws RecordRefusal When the category or record is read-only or gone, or there is no such list.
     */
    public function removeEntry(ProjectRecordDatabase $database, int $index, array $framePath, int $entryIndex, ?string $listKey = null): RecordChange
    {
        $list = $this->requireList($database, $index, $framePath, $listKey);
        $removed = $framePath === []
            ? $database->removeSubItem($index, $entryIndex, $listKey)
            : $database->removeFrameCommand($index, $framePath, $entryIndex);

        if ($removed === null) {
            return new RecordChange(null);
        }

        return new RecordChange(new GenericCommand(
            sprintf('%s remove', ucfirst($list->singular)),
            $framePath === []
                ? static fn() => $database->removeSubItem($index, $entryIndex, $listKey)
                : static fn() => $database->removeFrameCommand($index, $framePath, $entryIndex),
            $framePath === []
                ? static fn() => $database->insertSubItem($index, $entryIndex, $removed, $listKey)
                : static fn() => $database->insertFrameCommand($index, $framePath, $entryIndex, $removed),
        ), $entryIndex, $removed);
    }

    /**
     * Adds a blank entry to the nested list an entry owns (a route step,
     * a dialogue line, a lane): after one of them, or at the end.
     *
     * @param array<int, int|string> $framePath The frame the owning entry is in.
     * @param int|null $after The nested entry it follows; null for the end.
     * @throws RecordRefusal When the category or record is read-only or gone, or the frame is gone.
     */
    public function addNestedItem(ProjectRecordDatabase $database, int $index, array $framePath, int $parentIndex, ?int $after = null): RecordChange
    {
        $list = $this->requireList($database, $index, $framePath);
        $nestedList = $this->findNestedList($database, $index, $framePath, $list, $parentIndex);
        $at = $nestedList === null ? null : $database->addFrameNestedItem($index, $framePath, $parentIndex, null, $after === null ? null : $after + 1);
        $entry = $at === null || $nestedList === null
            ? null
            : (new ProjectRecord($database->getFrameCommands($index, $framePath)[$parentIndex]))->getEntries($nestedList)[$at] ?? null;

        if ($at === null || $nestedList === null || ! is_array($entry)) {
            return new RecordChange(null);
        }

        return new RecordChange(new GenericCommand(
            sprintf('%s add', ucfirst($nestedList->singular)),
            static fn() => $database->addFrameNestedItem($index, $framePath, $parentIndex, $entry, $at),
            static fn() => $database->removeFrameNestedItem($index, $framePath, $parentIndex, $at),
        ), $at);
    }

    /**
     * Removes an entry of the nested list an entry owns.
     *
     * @param array<int, int|string> $framePath The frame the owning entry is in.
     * @throws RecordRefusal When the category or record is read-only or gone, or the frame is gone.
     */
    public function removeNestedItem(ProjectRecordDatabase $database, int $index, array $framePath, int $parentIndex, int $nestedIndex): RecordChange
    {
        $list = $this->requireList($database, $index, $framePath);
        $nestedList = $this->findNestedList($database, $index, $framePath, $list, $parentIndex);
        $removed = $nestedList === null ? null : $database->removeFrameNestedItem($index, $framePath, $parentIndex, $nestedIndex);

        if ($removed === null || $nestedList === null) {
            return new RecordChange(null);
        }

        return new RecordChange(new GenericCommand(
            sprintf('%s remove', ucfirst($nestedList->singular)),
            static fn() => $database->removeFrameNestedItem($index, $framePath, $parentIndex, $nestedIndex),
            static fn() => $database->addFrameNestedItem($index, $framePath, $parentIndex, $removed, $nestedIndex),
        ), $nestedIndex, $removed);
    }

    /**
     * Adds an option to a choice command: after one of its options, or at
     * the end.
     *
     * @param array<int, int|string> $framePath The frame the choice is in.
     * @param int|null $after The option it follows; null for the end.
     * @throws RecordRefusal When the category or record is read-only or gone, or the frame is gone.
     */
    public function addOption(ProjectRecordDatabase $database, int $index, array $framePath, int $commandIndex, ?int $after = null): RecordChange
    {
        $this->requireList($database, $index, $framePath);
        $at = $database->addChoiceOption($index, $framePath, $commandIndex, $after === null ? null : $after + 1);
        $option = $at === null ? null : ($database->getFrameCommands($index, $framePath)[$commandIndex]['options'][$at] ?? null);

        if ($at === null || ! is_array($option)) {
            return new RecordChange(null);
        }

        return new RecordChange(new GenericCommand(
            'Option add',
            static fn() => $database->insertChoiceOption($index, $framePath, $commandIndex, $at, $option),
            static fn() => $database->removeChoiceOption($index, $framePath, $commandIndex, $at),
        ), $at);
    }

    /**
     * Removes an option from a choice command, its arm included.
     *
     * @param array<int, int|string> $framePath The frame the choice is in.
     * @throws RecordRefusal When the category or record is read-only or gone, or the frame is gone.
     */
    public function removeOption(ProjectRecordDatabase $database, int $index, array $framePath, int $commandIndex, int $optionIndex): RecordChange
    {
        $this->requireList($database, $index, $framePath);
        $removed = $database->removeChoiceOption($index, $framePath, $commandIndex, $optionIndex);

        if ($removed === null) {
            return new RecordChange(null);
        }

        return new RecordChange(new GenericCommand(
            'Option remove',
            static fn() => $database->removeChoiceOption($index, $framePath, $commandIndex, $optionIndex),
            static fn() => $database->insertChoiceOption($index, $framePath, $commandIndex, $optionIndex, $removed),
        ), $optionIndex, $removed);
    }

    /**
     * Creates a blank record at the end of the category, for an identity
     * when one is given. Its index is the change's.
     *
     * @throws RecordRefusal When the category is read-only, takes no new records, the project lacks what one needs, or the identity has one.
     */
    public function createRecord(ProjectRecordDatabase $database, ?string $identity = null): RecordChange
    {
        $this->requireEditable($database);
        $index = $database->requireNewRecord($identity);
        $record = $database->getRecordByIndex($index)
            ?? throw new RecordRefusal(sprintf('%s entries cannot be created from the editor.', ucfirst($database->schema->entryNoun)));

        return new RecordChange(new GenericCommand(
            sprintf('%s create', ucfirst($database->schema->entryNoun)),
            static fn() => $database->insertRecord($index, $record),
            static fn() => $database->removeRecord($index),
        ), $index);
    }

    /**
     * Duplicates a record below itself, under a fresh identity. The copy's
     * index is the change's.
     *
     * @throws RecordRefusal When the category or record is read-only or gone, or the record cannot be duplicated.
     */
    public function duplicateRecord(ProjectRecordDatabase $database, int $index): RecordChange
    {
        $this->requireRecord($database, $index);
        $copyIndex = $database->duplicateRecord($index);
        $copy = $copyIndex === null ? null : $database->getRecordByIndex($copyIndex);

        if ($copyIndex === null || $copy === null) {
            throw new RecordRefusal(sprintf('This %s cannot be duplicated.', $database->schema->entryNoun));
        }

        return new RecordChange(new GenericCommand(
            sprintf('%s duplicate', ucfirst($database->schema->entryNoun)),
            static fn() => $database->insertRecord($copyIndex, $copy),
            static fn() => $database->removeRecord($copyIndex),
        ), $copyIndex);
    }

    /**
     * Moves a record one place up or down its list. Declaration order is
     * part of some categories' meaning - battle-entry rules break priority
     * ties by it - so the move is a recorded edit, made only where the file
     * keeps the order. A move past either end changes nothing. The record's
     * new index is the change's.
     *
     * @param int $step -1 for up, 1 for down.
     * @throws RecordRefusal When the category is read-only, the record is gone, or the file would not keep the order.
     */
    public function moveRecord(ProjectRecordDatabase $database, int $index, int $step): RecordChange
    {
        $this->requireEditable($database);

        if (! $database->supportsDurableReorder()) {
            // Refusing beats a reorder the file cannot keep: nothing moves,
            // nothing dirties, and the author learns why.
            throw new RecordRefusal((string) $database->reorderRefusalReason());
        }

        $this->requireRecord($database, $index);
        $to = $index + $step;

        if (! $database->moveRecord($index, $to)) {
            return new RecordChange(null, $index);
        }

        return new RecordChange(new GenericCommand(
            sprintf('%s move', ucfirst($database->schema->entryNoun)),
            static fn() => $database->moveRecord($index, $to),
            static fn() => $database->moveRecord($to, $index),
        ), $to);
    }

    /**
     * Deletes a record. The file changes on save, which is what makes the
     * undo honest. The change's index is the record to select next: the
     * one before it, or null when none is left.
     *
     * @throws RecordRefusal When the category is read-only, the record is gone, or the category keeps its entries.
     */
    public function deleteRecord(ProjectRecordDatabase $database, int $index): RecordChange
    {
        $this->requireRecord($database, $index);
        $label = $database->getEntryLabels()[$index] ?? '';
        $removed = $database->removeRecord($index)
            ?? throw new RecordRefusal('This category does not support entry deletion yet.');
        $remaining = count($database->getRecords());

        return new RecordChange(new GenericCommand(
            sprintf('Delete %s %s', $database->schema->entryNoun, $label),
            static function () use ($database, $index): void {
                $database->removeRecord($index);
            },
            static fn() => $database->insertRecord($index, $removed),
        ), $remaining === 0 ? null : min(max(0, $index - 1), $remaining - 1));
    }

    /** @throws RecordRefusal When the category cannot be written. */
    private function requireEditable(ProjectRecordDatabase $database): void
    {
        if (! $database->isEditable()) {
            throw new RecordRefusal(sprintf('Read-only: %s.', $database->getReadOnlyReason() ?? 'this category cannot be written'));
        }
    }

    /**
     * The record at an index of a category that can be written. A record
     * whose own values cannot be written is the category's to protect: its
     * read-only rows say so, and its writes change nothing.
     *
     * @throws RecordRefusal When the category cannot be written or there is no such record.
     */
    private function requireRecord(ProjectRecordDatabase $database, int $index): ProjectRecord
    {
        $this->requireEditable($database);

        return $database->getRecordByIndex($index)
            ?? throw new RecordRefusal(sprintf('%s has no record %d.', $database->schema->key, $index));
    }

    /**
     * Refuses a frame that no longer resolves, or any frame in a category
     * whose commands do not nest.
     *
     * @param array<int, int|string> $framePath
     * @throws RecordRefusal
     */
    private function requireFrame(ProjectRecordDatabase $database, int $index, array $framePath): void
    {
        if ($framePath !== [] && (! $database->hasCommandFrames() || $database->getFrameCommands($index, $framePath) === null)) {
            throw new RecordRefusal(sprintf('%s is no longer there.', $database->describeFramePath($framePath)));
        }
    }

    /**
     * The list a frame holds - the record's own at the root - refusing a
     * record or frame that cannot take an item.
     *
     * @param array<int, int|string> $framePath
     * @throws RecordRefusal
     */
    private function requireList(ProjectRecordDatabase $database, int $index, array $framePath, ?string $listKey = null): RecordSubList
    {
        $this->requireRecord($database, $index);
        $this->requireFrame($database, $index, $framePath);

        return ($framePath === [] && $listKey !== null ? $database->schema->findInlineSubList($listKey) : $database->getFrameSubList($framePath))
            ?? throw new RecordRefusal(sprintf('A %s has no list of its own to add to.', $database->schema->entryNoun));
    }

    /** The nested list an entry of a frame owns, or null when it owns none. */
    private function findNestedList(ProjectRecordDatabase $database, int $index, array $framePath, RecordSubList $list, int $parentIndex): ?RecordSubList
    {
        $parent = $database->getFrameCommands($index, $framePath)[$parentIndex] ?? null;

        return is_array($parent) ? $list->nestedListFor($parent) : null;
    }
}
