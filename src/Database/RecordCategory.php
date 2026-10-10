<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;

/**
 * A schema-driven category ({@see ProjectRecordDatabase}) as a Database
 * category, edited through the shared record rules ({@see RecordAuthoring}).
 */
final readonly class RecordCategory implements DatabaseCategory
{
    public string $key;

    public function __construct(
        private ProjectRecordDatabase $database,
        private RecordAuthoring $authoring = new RecordAuthoring(),
    ) {
        $this->key = $database->schema->key;
    }

    public function isEditable(): bool
    {
        return $this->database->isEditable();
    }

    public function getReadOnlyReason(): ?string
    {
        return $this->database->getReadOnlyReason();
    }

    public function isDirty(): bool
    {
        return $this->database->isDirty();
    }

    public function supportsRecordCreation(): bool
    {
        return $this->database->supportsRecordCreation();
    }

    public function supportsRecordDuplication(): bool
    {
        return $this->database->duplicateRecordSupported();
    }

    public function supportsRecordDeletion(): bool
    {
        return $this->database->supportsRecordDeletion();
    }

    public function supportsDurableReorder(): bool
    {
        return $this->database->supportsDurableReorder();
    }

    public function getRecordLabels(): array
    {
        return array_values(array_map('strval', $this->database->getEntryLabels()));
    }

    public function getRecordRows(int $index, array $frame): array
    {
        if ($this->database->getRecordByIndex($index) === null) {
            throw new RecordRefusal(sprintf('%s has no record %d.', $this->key, $index));
        }
        if ($frame !== [] && (! $this->database->hasCommandFrames() || $this->database->getFrameCommands($index, $frame) === null)) {
            throw new RecordRefusal(sprintf('%s is no longer there; read the record again.', $this->database->describeFramePath($frame)));
        }

        return $this->database->getFrameSettingsFields($index, $frame);
    }

    public function describeFrame(array $frame): ?string
    {
        return $frame === [] ? null : $this->database->describeFramePath($frame);
    }

    public function getListNoun(array $frame): ?string
    {
        return $this->database->getFrameSubList($frame)?->singular;
    }

    public function locateItem(int $index, array $frame, string $fieldId): ?RecordItem
    {
        return $this->database->locateItem($index, $frame, $fieldId);
    }

    public function applyField(int $index, array $frame, string $fieldId, string $value, string $label): RecordChange
    {
        return $this->authoring->applyField($this->database, $index, $frame, $fieldId, $value, $label);
    }

    public function addItem(int $index, array $frame, ?string $fieldId, bool $child): RecordChange
    {
        return $this->authoring->addItem($this->database, $index, $frame, $fieldId, $child);
    }

    public function removeItem(int $index, array $frame, string $fieldId): RecordChange
    {
        return $this->authoring->removeItem($this->database, $index, $frame, $fieldId);
    }

    public function createRecord(?string $identity = null): RecordChange
    {
        return $this->authoring->createRecord($this->database, $identity);
    }

    public function duplicateRecord(int $index): RecordChange
    {
        return $this->authoring->duplicateRecord($this->database, $index);
    }

    public function deleteRecord(int $index): RecordChange
    {
        return $this->authoring->deleteRecord($this->database, $index);
    }

    public function moveRecord(int $index, int $step): RecordChange
    {
        return $this->authoring->moveRecord($this->database, $index, $step);
    }

    public function getBackupPaths(): array
    {
        return array_values($this->database->getBackupPaths());
    }

    public function save(): void
    {
        if (! $this->database->isEditable()) {
            throw new RecordRefusal(sprintf('Read-only: %s.', $this->database->getReadOnlyReason() ?? 'this category cannot be written'));
        }

        try {
            $this->database->save();
        } catch (SourceIdentityConflict|SourcePreservationRefusal $refusal) {
            throw new RecordRefusal($refusal->getMessage(), previous: $refusal);
        }
    }
}
