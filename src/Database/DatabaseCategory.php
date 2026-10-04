<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * One Database category as an editor outside the terminal reaches it: its
 * records' labels and rows, and the edits it takes, each one undo step. The
 * schema-driven categories are one kind ({@see RecordCategory}); actors,
 * whose rows carry previews and choices about the panes, are another
 * ({@see \Ichiloto\Editor\Actors\ActorCategory}). Every edit is the same
 * operation the terminal editor makes, through the same service.
 */
interface DatabaseCategory
{
    /** The category's key, as the Database catalog names it. */
    public string $key { get; }

    public function isEditable(): bool;

    /** Why the category cannot be edited, or null when it can. */
    public function getReadOnlyReason(): ?string;

    public function isDirty(): bool;

    public function supportsRecordCreation(): bool;

    public function supportsRecordDuplication(): bool;

    public function supportsRecordDeletion(): bool;

    /** Whether a move survives a save, because the file keeps the order. */
    public function supportsDurableReorder(): bool;

    /** @return list<string> The records' labels, as the list shows them. */
    public function getRecordLabels(): array;

    /**
     * A record's rows in a frame of its commands ([] for the record itself),
     * as the terminal editor's record pane builds them.
     *
     * @param list<int|string> $frame
     * @return array<int, array<string, mixed>>
     * @throws RecordRefusal When the record or frame is gone.
     */
    public function getRecordRows(int $index, array $frame): array;

    /**
     * How a frame reads, or null at the record itself.
     *
     * @param list<int|string> $frame
     */
    public function describeFrame(array $frame): ?string;

    /**
     * What an entry of the list an add with no row goes to is called, or
     * null when there is none.
     *
     * @param list<int|string> $frame
     */
    public function getListNoun(array $frame): ?string;

    /**
     * The item a row belongs to, or null when it belongs to none.
     *
     * @param list<int|string> $frame
     */
    public function locateItem(int $index, array $frame, string $fieldId): ?RecordItem;

    /**
     * @param list<int|string> $frame
     * @throws RecordRefusal When the record is gone or read-only, or the value is refused.
     */
    public function applyField(int $index, array $frame, string $fieldId, string $value, string $label): RecordChange;

    /**
     * @param list<int|string> $frame
     * @throws RecordRefusal When the record is gone or read-only, or the row's item cannot take it.
     */
    public function addItem(int $index, array $frame, ?string $fieldId, bool $child): RecordChange;

    /**
     * @param list<int|string> $frame
     * @throws RecordRefusal When the record is gone or read-only.
     */
    public function removeItem(int $index, array $frame, string $fieldId): RecordChange;

    /** @throws RecordRefusal When the category takes no new records. */
    public function createRecord(): RecordChange;

    /** @throws RecordRefusal When the record cannot be duplicated. */
    public function duplicateRecord(int $index): RecordChange;

    /** @throws RecordRefusal When the record cannot be deleted. */
    public function deleteRecord(int $index): RecordChange;

    /**
     * @param int $step -1 for up, 1 for down.
     * @throws RecordRefusal When the record is gone or the file would not keep the order.
     */
    public function moveRecord(int $index, int $step): RecordChange;

    /** @return list<string> The files a save would overwrite. */
    public function getBackupPaths(): array;

    /** @throws RecordRefusal When the source cannot take the save, with why. */
    public function save(): void;
}
