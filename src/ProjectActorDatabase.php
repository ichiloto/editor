<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use RuntimeException;

/**
 * Manages the project's actor database assets.
 */
final class ProjectActorDatabase
{
    /**
     * @param ProjectActor[] $actors
     */
    /**
     * @var array<string, string> Asset paths staged for deletion on the next save.
     */
    private array $pendingDeletions = [];

    /**
     * @param ProjectActor[] $actors
     */
    public function __construct(
        public readonly string $directory,
        private array $actors = [],
        private bool $isDirty = false,
    ) {
    }

    /**
     * Loads the actor database from the project.
     *
     * @param string $projectRoot The project root.
     * @return self
     */
    public static function fromProject(string $projectRoot): self
    {
        $directory = rtrim($projectRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'assets'
            . DIRECTORY_SEPARATOR
            . 'Data'
            . DIRECTORY_SEPARATOR
            . 'Actors';

        if (! is_dir($directory)) {
            return new self($directory, []);
        }

        $paths = glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [];
        natcasesort($paths);
        $actors = [];

        foreach ($paths as $path) {
            $actors[] = ProjectActor::fromFile($path);
        }

        return new self($directory, $actors);
    }

    /**
     * Returns the stored actor records.
     *
     * @return ProjectActor[]
     */
    public function getActors(): array
    {
        return array_values($this->actors);
    }

    /**
     * Returns whether the database has unsaved changes.
     *
     * @return bool
     */
    public function isDirty(): bool
    {
        if ($this->isDirty) {
            return true;
        }

        foreach ($this->actors as $actor) {
            if ($actor->isDirty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the actor at the requested index.
     *
     * @param int $index The actor index.
     * @return ProjectActor|null
     */
    public function getActorByIndex(int $index): ?ProjectActor
    {
        return $this->actors[$index] ?? null;
    }

    /**
     * Adds a blank actor and returns its index.
     *
     * @param string $name The default actor display name.
     * @return int
     */
    public function addActor(string $name = 'New Actor'): int
    {
        $id = $this->getNextAvailableId($name);
        $path = $this->directory . DIRECTORY_SEPARATOR . $id . '.php';
        $this->actors[] = ProjectActor::createBlank($path, $id, self::humanizeId($id));
        $this->isDirty = true;

        return count($this->actors) - 1;
    }

    /**
     * Removes the actor at the given index from the in-memory database.
     *
     * The asset file is not touched here: the removal is staged and applied
     * on the next save, so an undo before saving costs nothing and an undo
     * after saving simply re-creates the file on the following save. This is
     * what makes entry deletion undoable at all.
     *
     * @param int $index The actor index.
     * @return ProjectActor|null The removed actor, or null when the index is unknown.
     */
    public function removeActor(int $index): ?ProjectActor
    {
        $actors = array_values($this->actors);
        $actor = $actors[$index] ?? null;

        if (! $actor instanceof ProjectActor) {
            return null;
        }

        array_splice($actors, $index, 1);
        $this->actors = $actors;

        if (is_file($actor->path)) {
            $this->pendingDeletions[$actor->path] = $actor->path;
        }

        $this->isDirty = true;

        return $actor;
    }

    /**
     * Re-inserts a previously removed actor (the undo of removeActor()).
     *
     * @param int $index The index to restore the actor at.
     * @param ProjectActor $actor The actor to restore.
     * @return void
     */
    public function insertActor(int $index, ProjectActor $actor): void
    {
        $actors = array_values($this->actors);
        $index = max(0, min(count($actors), $index));
        array_splice($actors, $index, 0, [$actor]);
        $this->actors = $actors;
        unset($this->pendingDeletions[$actor->path]);
        $this->isDirty = true;
    }

    /**
     * Returns the asset paths staged for deletion on the next save.
     *
     * @return string[]
     */
    public function getPendingDeletions(): array
    {
        return array_values($this->pendingDeletions);
    }

    /**
     * Updates one actor field.
     *
     * @param int $index The actor index.
     * @param string $field The field identifier.
     * @param mixed $value The replacement value.
     * @return void
     */
    public function setField(int $index, string $field, mixed $value): void
    {
        $actor = $this->getActorByIndex($index);

        if (! $actor instanceof ProjectActor) {
            return;
        }

        $actor->setField($field, $value);
        $this->isDirty = true;
    }

    /**
     * Writes all actor assets back to disk.
     *
     * @return void
     */
    public function save(): void
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0777, true) && ! is_dir($this->directory)) {
            throw new RuntimeException("Unable to create {$this->directory}.");
        }

        // Staged deletions run first so re-creating an entry with a removed
        // entry's id in the same session still lands on disk.
        foreach ($this->pendingDeletions as $path) {
            if (is_file($path) && ! @unlink($path)) {
                throw new RuntimeException("Unable to remove {$path}.");
            }
        }

        $this->pendingDeletions = [];

        foreach ($this->actors as $actor) {
            $actor->save();
        }

        $this->isDirty = false;
    }

    /**
     * Resolves the next available actor file id.
     *
     * @param string $baseName The preferred base name.
     * @return string
     */
    private function getNextAvailableId(string $baseName): string
    {
        $baseId = self::normalizeId($baseName);
        $candidate = $baseId;
        $suffix = 2;
        $existingIds = array_map(static fn(ProjectActor $actor): string => $actor->id, $this->actors);

        while (in_array($candidate, $existingIds, true)) {
            $candidate = $baseId . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Normalizes a label into an actor file id.
     *
     * @param string $value The raw label.
     * @return string
     */
    private static function normalizeId(string $value): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $value) ?: [];
        $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));

        if ($parts === []) {
            return 'NewActor';
        }

        return implode('', array_map(static fn(string $part): string => ucfirst(strtolower($part)), $parts));
    }

    /**
     * Humanizes an actor file id into a display name.
     *
     * @param string $id The actor file id.
     * @return string
     */
    private static function humanizeId(string $id): string
    {
        $label = preg_replace('/(?<!^)([A-Z])/', ' $1', $id) ?? $id;

        return trim(str_replace(['_', '-'], ' ', $label));
    }
}
