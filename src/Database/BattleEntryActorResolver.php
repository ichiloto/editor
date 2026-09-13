<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\ProjectActor;

/**
 * The editor's mirror of the engine `ActorStore` reference resolution.
 *
 * The engine registers every actor definition under three references — its
 * durable id, its display name, and its file stem — normalized by lowercased
 * trim, and refuses to build the store at all when two definitions share an
 * identity or a reference points two ways. Battle-entry rules resolve actors
 * through that store, so the editor resolves them the same way: a missing,
 * ambiguous, or contested identity stays visible and fails validation rather
 * than being substituted with whichever record happened to load first.
 *
 * @package Ichiloto\Editor\Database
 */
final class BattleEntryActorResolver
{
    /**
     * @param array<string, string> $references Canonical id by normalized reference.
     * @param string[] $problems Why the engine's store would refuse to build.
     */
    private function __construct(
        private readonly array $references,
        private readonly array $problems,
    ) {
    }

    /**
     * Builds the resolver from the project's actors, the way the engine
     * builds its store from `assets/Data/Actors/*.php`.
     *
     * @param ProjectActor[] $actors The project's actors, in file order.
     * @return self The resolver.
     */
    public static function fromActors(array $actors): self
    {
        $definitions = [];
        $references = [];
        $problems = [];

        foreach ($actors as $actor) {
            $id = $actor->getDefinitionId();
            $normalizedId = self::normalize($id);

            if ($normalizedId === '') {
                continue;
            }

            if (isset($definitions[$normalizedId])) {
                $problems[] = sprintf('Duplicate actor definition identity: %s.', $id);

                continue;
            }

            $definitions[$normalizedId] = $id;

            $fileStem = $actor->path === '' ? '' : pathinfo($actor->path, PATHINFO_FILENAME);

            foreach ([$id, $actor->getName(), $fileStem] as $reference) {
                $reference = self::normalize($reference);

                if ($reference === '') {
                    continue;
                }

                $existing = $references[$reference] ?? null;

                if ($existing !== null && $existing !== $normalizedId) {
                    $problems[] = sprintf('Actor reference "%s" is ambiguous.', $reference);

                    continue;
                }

                $references[$reference] = $normalizedId;
            }
        }

        $canonical = [];

        foreach ($references as $reference => $normalizedId) {
            $canonical[$reference] = $definitions[$normalizedId];
        }

        return new self($canonical, array_values(array_unique($problems)));
    }

    /**
     * Returns why the engine's store would refuse this project's actors,
     * empty when it would build.
     *
     * @return string[] The problems, engine-worded.
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * Returns the canonical durable id behind an accepted actor reference,
     * or null for one the engine would not resolve.
     *
     * @param string $reference The authored reference.
     * @return string|null The durable id.
     */
    public function canonicalId(string $reference): ?string
    {
        return $this->references[self::normalize($reference)] ?? null;
    }

    /**
     * Normalizes a reference the way the engine's store does.
     *
     * @param string $reference The reference.
     * @return string The normalized form.
     */
    private static function normalize(string $reference): string
    {
        return strtolower(trim($reference));
    }
}
