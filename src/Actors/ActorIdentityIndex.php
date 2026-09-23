<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Ichiloto\Editor\ProjectActorDatabase;
use RuntimeException;

/** Migration-only aliases. Runtime identity remains the authored ID. */
final class ActorIdentityIndex
{
    private array $ids = [];
    private array $aliases = [];

    public function __construct(ProjectActorDatabase $database)
    {
        foreach ($database->getActors() as $actor) {
            $data = $actor->getData();
            $id = array_key_exists('id', $data) ? $data['id'] : ($data['name'] ?? null);
            if (! is_string($id) || trim($id) === '') {
                throw new RuntimeException("{$actor->path}: actor identity must be a non-empty string.");
            }
            $id = trim($id);
            $key = strtolower($id);
            if (isset($this->ids[$key])) {
                throw new RuntimeException("{$actor->path}: actor id \"{$id}\" conflicts with another actor.");
            }
            $this->ids[$key] = $id;
            foreach ([$actor->id, $actor->getName()] as $alias) {
                $this->aliases[strtolower(trim($alias))][$id] = true;
            }
        }
    }

    public function resolveReference(mixed $reference, string $where, bool $optionalSpeaker = false): ?string
    {
        if (! is_string($reference) || trim($reference) === '') {
            if ($optionalSpeaker) { return null; }
            throw new RuntimeException("{$where}: actor reference must be a non-empty string.");
        }
        $key = strtolower(trim($reference));
        // An explicit ID is never hijacked by another actor's name or file.
        if (isset($this->ids[$key])) { return $this->ids[$key]; }
        $ids = array_keys($this->aliases[$key] ?? []);
        if (count($ids) > 1) {
            throw new RuntimeException("{$where}: ambiguous legacy actor reference \"{$reference}\".");
        }
        if ($ids !== []) { return $ids[0]; }
        if ($optionalSpeaker) { return null; }
        throw new RuntimeException("{$where}: unresolved actor reference \"{$reference}\".");
    }
}
