<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Ichiloto\Editor\Database\DatabaseCategory;
use Ichiloto\Editor\Database\RecordChange;
use Ichiloto\Editor\Database\RecordItem;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectWorkspace;
use RuntimeException;
use Throwable;

/**
 * The project's actors as a Database category, authored through the same
 * actor service as the terminal editor ({@see ActorAuthoring}). Each actor
 * is a file of its own, so the list keeps file order and a move is refused;
 * actors have no command frames or lists of items.
 */
final readonly class ActorCategory implements DatabaseCategory
{
    public string $key;

    public function __construct(
        private ProjectWorkspace $workspace,
        private ActorAuthoring $authoring,
    ) {
        $this->key = 'actors';
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
        return $this->workspace->actorDatabase->isDirty();
    }

    public function supportsRecordCreation(): bool
    {
        return true;
    }

    public function supportsRecordDuplication(): bool
    {
        return false;
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
        return array_map(static fn(ProjectActor $actor): string => $actor->getName(), $this->workspace->actorDatabase->getActors());
    }

    public function getRecordRows(int $index, array $frame): array
    {
        self::refuseFrame($frame);

        return $this->authoring->describeFields($this->workspace, $this->requireActor($index));
    }

    public function describeFrame(array $frame): ?string
    {
        return null;
    }

    public function getListNoun(array $frame): ?string
    {
        return null;
    }

    public function locateItem(int $index, array $frame, string $fieldId): ?RecordItem
    {
        return null;
    }

    public function applyField(int $index, array $frame, string $fieldId, string $value, string $label): RecordChange
    {
        self::refuseFrame($frame);
        $actor = $this->requireActor($index);

        if ($fieldId === 'id' && ! array_key_exists('id', $actor->getData())) {
            // An actor authored without an id: the edit is the one-time
            // freeze of its name, as the terminal's Enter on the row is.
            $this->refuseWiderRepair($actor);

            return $this->authoring->freezeIdentity($this->workspace, $index);
        }

        return $this->authoring->applyField($this->workspace, $index, $fieldId, $value, $label);
    }

    public function addItem(int $index, array $frame, ?string $fieldId, bool $child): RecordChange
    {
        throw new RecordRefusal('An actor has no list to add to.');
    }

    public function removeItem(int $index, array $frame, string $fieldId): RecordChange
    {
        throw new RecordRefusal('An actor has no list to remove from.');
    }

    public function createRecord(): RecordChange
    {
        return $this->authoring->createActor($this->workspace);
    }

    public function duplicateRecord(int $index): RecordChange
    {
        throw new RecordRefusal('Actors cannot be duplicated: each one is a character with its own identity. Create a new actor instead.');
    }

    public function deleteRecord(int $index): RecordChange
    {
        return $this->authoring->deleteActor($this->workspace, $index);
    }

    public function moveRecord(int $index, int $step): RecordChange
    {
        throw new RecordRefusal('Each actor is its own file; the list shows them in file order, which a move cannot change.');
    }

    public function getBackupPaths(): array
    {
        return array_values($this->workspace->actorDatabase->getBackupPaths());
    }

    public function save(): void
    {
        try {
            $this->workspace->actorDatabase->save();
        } catch (RuntimeException $failure) {
            // The actors that could not be written stay unsaved, and the
            // message names the first one and why.
            throw new RecordRefusal($failure->getMessage(), previous: $failure);
        }
    }

    /** @throws RecordRefusal */
    private function requireActor(int $index): ProjectActor
    {
        return $this->workspace->actorDatabase->getActorByIndex($index)
            ?? throw new RecordRefusal(sprintf('actors has no record %d.', $index));
    }

    /**
     * Freezing one actor's name is the whole repair only when no other file
     * needs it too. A repair across files rewrites them at once rather than
     * on save, so it is made from the terminal editor's repair dialog.
     *
     * @throws RecordRefusal
     */
    private function refuseWiderRepair(ProjectActor $actor): void
    {
        try {
            $paths = ActorIdentityMigration::planProject($this->workspace->projectRoot)->getChangedPaths();
        } catch (Throwable $failure) {
            throw new RecordRefusal(sprintf('The actor identity repair could not be planned: %s', $failure->getMessage()), previous: $failure);
        }

        if ($paths !== [$actor->path]) {
            throw new RecordRefusal(sprintf(
                'Freezing this id also needs %d other file(s) repaired at once. Repair actor identities and references from Database > Actors in the terminal editor.',
                max(0, count($paths) - 1),
            ));
        }
    }

    /**
     * @param list<int|string> $frame
     * @throws RecordRefusal
     */
    private static function refuseFrame(array $frame): void
    {
        if ($frame !== []) {
            throw new RecordRefusal('Actors have no command frames.');
        }
    }
}
