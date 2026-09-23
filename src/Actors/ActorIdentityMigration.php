<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectActorDatabase;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\Database\PhpDataFile;
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

    /** Read-only plan; callers show changed paths and obtain confirmation before apply. */
    public static function planProject(string $projectRoot): ActorIdentityMigrationPlan
    {
        return ProjectDirectoryContext::run($projectRoot, self::buildPlan(...));
    }

    private static function buildPlan(string $projectRoot): ActorIdentityMigrationPlan
    {
        $database = ProjectActorDatabase::fromProject($projectRoot);
        $index = new ActorIdentityIndex($database);
        $watched = $originals = $proposals = $before = $after = [];
        foreach ($database->getActors() as $actor) {
            $watched[$actor->path] = (string) file_get_contents($actor->path);
            if (array_key_exists('id', $actor->getData())) { continue; }
            $before[$actor->path] = ActorReferenceInventory::getComparableValue(PhpDataFile::evaluateIsolated($actor->path, $projectRoot));
            try {
                self::freezeCurrentName($database, $actor);
                $proposals[$actor->path] = $actor->getProposedSource();
            } catch (Throwable $failure) {
                throw new ($failure::class)("{$actor->path}: {$failure->getMessage()}");
            }
            $originals[$actor->path] = $watched[$actor->path];
            $after[$actor->path] = $before[$actor->path];
            $after[$actor->path]['data'] = $actor->getData();
        }
        foreach (ActorReferenceInventory::getSourcePaths($projectRoot) as $path) {
            $source = (string) file_get_contents($path);
            $watched[$path] = $source;
            $payload = PhpDataFile::evaluateIsolated($path, $projectRoot);
            $relative = substr($path, strlen($projectRoot) + 1);
            $changes = [];
            foreach (ActorReferenceInventory::getReferences($payload, $relative) as $reference) {
                $target = $index->resolveReference($reference['reference'], $path . ':' . implode('.', $reference['path']), $reference['kind'] === 'speaker');
                if ($target !== null && ($target !== $reference['reference'] || $reference['kind'] === 'speaker')) {
                    $changes[] = [...$reference, 'target' => $target];
                }
            }
            if ($changes === []) { continue; }
            try {
                $after[$path] = ActorReferenceSource::getUpdatedValue($payload, $changes);
                $proposals[$path] = ActorReferenceSource::rewrite($source, $changes);
            } catch (Throwable $failure) {
                throw new RuntimeException("{$path}: {$failure->getMessage()}", previous: $failure);
            }
            $originals[$path] = $source;
            $before[$path] = ActorReferenceInventory::getComparableValue($payload);
        }
        return new ActorIdentityMigrationPlan($projectRoot, $watched, $originals, $proposals, $before, $after);
    }

    /** Explicit confirmed operation. Returns every changed actor and reference file. */
    public static function migrateProject(string $projectRoot): array
    {
        return self::planProject($projectRoot)->apply();
    }
}
