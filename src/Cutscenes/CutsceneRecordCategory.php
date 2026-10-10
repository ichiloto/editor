<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

use Ichiloto\Editor\Database\DatabaseCategory;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordCategory;
use Ichiloto\Editor\Database\RecordChange;
use Ichiloto\Editor\Database\RecordItem;
use Ichiloto\Editor\Database\RecordRefusal;
use RuntimeException;
use Throwable;

/**
 * A cutscene type's assets as a Database category, so an interface reads
 * and edits a summon's fields, tracks, keyframes and cues through the same
 * record rows and frames as any other category. Reads go through the shared
 * record rules; every change goes through {@see CutsceneLibrary::changeAsset()},
 * which takes it into the asset at once and undoes it by the asset's own edit
 * state. An asset is created from the schema's blank or duplicated under the
 * id the interface names (made free and safe), and deleted, each one undo
 * step through the library; the folder follows on save. Saving writes the
 * type's changed assets with their paired files. The records' order is the
 * folders', so they are not reordered.
 */
final readonly class CutsceneRecordCategory implements DatabaseCategory
{
    /** The record category key, such as `cutscenes/summon`. */
    public string $key;

    public function __construct(private CutsceneLibrary $library, private CutsceneType $type)
    {
        $this->key = $type->getRecordCategory();
    }

    public function isEditable(): bool
    {
        return true;
    }

    public function getReadOnlyReason(): ?string
    {
        return null;
    }

    public function isDirty(): bool
    {
        return array_any($this->library->assets($this->type), static fn(CutsceneAsset $asset): bool => $asset->isDirty());
    }

    public function supportsRecordCreation(): bool
    {
        return true;
    }

    public function supportsRecordDuplication(): bool
    {
        return true;
    }

    public function supportsRecordDeletion(): bool
    {
        return true;
    }

    public function supportsDurableReorder(): bool
    {
        return false;
    }

    public function getRecordLabels(): array
    {
        return $this->read()->getRecordLabels();
    }

    public function getRecordRows(int $index, array $frame): array
    {
        return $this->read()->getRecordRows($index, $frame);
    }

    public function describeFrame(array $frame): ?string
    {
        return $this->read()->describeFrame($frame);
    }

    public function getListNoun(array $frame): ?string
    {
        return $this->read()->getListNoun($frame);
    }

    public function locateItem(int $index, array $frame, string $fieldId): ?RecordItem
    {
        return $this->read()->locateItem($index, $frame, $fieldId);
    }

    public function applyField(int $index, array $frame, string $fieldId, string $value, string $label): RecordChange
    {
        return $this->change($index, $label, static fn(RecordCategory $records, int $at): RecordChange
            => $records->applyField($at, $frame, $fieldId, $value, $label));
    }

    public function addItem(int $index, array $frame, ?string $fieldId, bool $child): RecordChange
    {
        return $this->change($index, 'Add', static fn(RecordCategory $records, int $at): RecordChange
            => $records->addItem($at, $frame, $fieldId, $child));
    }

    public function removeItem(int $index, array $frame, string $fieldId): RecordChange
    {
        return $this->change($index, 'Remove', static fn(RecordCategory $records, int $at): RecordChange
            => $records->removeItem($at, $frame, $fieldId));
    }

    /** @param string|null $identity The new asset's id, made free and safe; the schema's blank id when none is given. */
    public function createRecord(?string $identity = null): RecordChange
    {
        ['asset' => $asset, 'command' => $command] = $this->library->createAsset($this->type, $identity);

        return new RecordChange($command, $this->findIndex($asset->id));
    }

    public function duplicateRecord(int $index): RecordChange
    {
        $id = $this->library->ids($this->type)[$index] ?? throw new RecordRefusal(sprintf('There is no %s %d.', $this->type->noun(), $index));
        try {
            ['asset' => $copy, 'command' => $command] = $this->library->duplicateAsset($this->type, $id);
        } catch (RuntimeException $failure) {
            throw new RecordRefusal($failure->getMessage(), previous: $failure);
        }

        return new RecordChange($command, $this->findIndex($copy->id));
    }

    public function deleteRecord(int $index): RecordChange
    {
        $label = sprintf('Delete %s', $this->type->noun());
        $deleted = $this->change($index, $label, static fn(RecordCategory $records, int $at): RecordChange => $records->deleteRecord($at));
        $count = count($this->library->ids($this->type));

        return new RecordChange($deleted->command, $count === 0 ? null : min($index, $count - 1));
    }

    public function moveRecord(int $index, int $step): RecordChange
    {
        throw $this->refuseRecordOperation('reordered');
    }

    public function getBackupPaths(): array
    {
        $paths = [];
        foreach ($this->library->assets($this->type) as $asset) {
            if ($asset->isDirty()) {
                array_push($paths, ...array_filter([$asset->dataPath(), $asset->partnerPath()], is_file(...)));
            }
        }

        return $paths;
    }

    public function save(): void
    {
        foreach ($this->library->assets($this->type) as $asset) {
            if ($asset->isDirty()) {
                $this->library->save($this->type, $asset->id);
            }
        }
    }

    /** Where an asset sits among its type's records. */
    private function findIndex(string $id): ?int
    {
        $index = array_search($id, $this->library->ids($this->type), true);

        return is_int($index) ? $index : null;
    }

    /** The type's records, read through the shared record rules. */
    private function read(): RecordCategory
    {
        return new RecordCategory($this->library->records($this->type, graphical: true));
    }

    /**
     * Runs one record change through the library, which takes it into the
     * asset and gives the step that undoes it.
     *
     * @param callable(RecordCategory, int): RecordChange $change
     * @throws RecordRefusal When the asset is missing or read-only, or the change is refused; nothing is changed.
     */
    private function change(int $index, string $label, callable $change): RecordChange
    {
        try {
            $applied = $this->library->changeAsset($this->type, $index, $label,
                static fn(ProjectRecordDatabase $records, int $at): RecordChange => $change(new RecordCategory($records), $at), graphical: true);
        } catch (RecordRefusal $refusal) {
            throw $refusal;
        } catch (RuntimeException $failure) {
            throw new RecordRefusal($failure->getMessage(), previous: $failure);
        }
        /** @var RecordChange $made */
        $made = $applied['result'];

        return new RecordChange($applied['command'], $made->index, $made->removed, $made->note);
    }

    private function refuseRecordOperation(string $done): Throwable
    {
        return new RecordRefusal(sprintf('A %s is %s from the Cutscenes workspace, which names its folder first.', $this->type->noun(), $done));
    }
}
