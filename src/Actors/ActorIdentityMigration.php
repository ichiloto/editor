<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectActorDatabase;
use Ichiloto\Editor\Storage\FileSetTransaction;
use RuntimeException;
use Throwable;

/** Explicit, shared legacy identity repair for TUI and CLI callers. */
final class ActorIdentityMigration
{
    /** @return list<ProjectActor> Only absent IDs, never malformed authored IDs. */
    public static function getPendingActors(ProjectActorDatabase $database): array
    {
        return array_values(array_filter($database->getActors(),
            static fn(ProjectActor $actor): bool => ! array_key_exists('id', $actor->getData())));
    }

    /** Freeze in memory only; the editor records getData/restoreData in its undo history. */
    public static function freezeCurrentName(ProjectActorDatabase $database, ProjectActor $actor): void
    {
        if (! in_array($actor, $database->getActors(), true)) {
            throw new RuntimeException('The actor does not belong to this project database.');
        }
        if (array_key_exists('id', $actor->getData())) {
            throw new RuntimeException('Only an absent actor id can be migrated; established or malformed ids cannot be replaced.');
        }
        $name = $actor->getData()['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            throw new RuntimeException("{$actor->path} has no non-empty current name to freeze.");
        }
        $id = trim($name);
        foreach ($database->getActors() as $other) {
            if ($other === $actor) { continue; }
            $otherId = array_key_exists('id', $other->getData()) ? $other->getDefinitionId() : trim($other->getName());
            if (strtolower($otherId) === strtolower($id)) {
                throw new RuntimeException("Cannot freeze actor id \"{$id}\": it conflicts with {$other->path}.");
            }
        }
        $data = $actor->getData();
        $data['id'] = $id;
        $actor->getProposedSource($data);
        $actor->setField('id', $id);
    }

    /** Explicit CLI operation. Preflight the whole batch and install it as one transaction.
     * @return list<string> Changed actor file paths.
     */
    public static function migrateProject(string $projectRoot): array
    {
        $database = ProjectActorDatabase::fromProject($projectRoot);
        $actors = self::getPendingActors($database);
        if ($actors === []) { return []; }
        $transaction = new FileSetTransaction($database->directory);
        foreach ($actors as $actor) {
            self::freezeCurrentName($database, $actor);
            $transaction->write($actor->path, $actor->getProposedSource());
        }
        try {
            $staged = $transaction->stage();
            foreach ($actors as $actor) { $actor->validateStagedSource($staged[$actor->path]); }
            $transaction->commit();
        } catch (Throwable $failure) {
            $transaction->rollBack();
            throw $failure;
        }
        return array_map(static fn(ProjectActor $actor): string => $actor->path, $actors);
    }
}
