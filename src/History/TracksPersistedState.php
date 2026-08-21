<?php

declare(strict_types=1);

namespace Ichiloto\Editor\History;

/**
 * Dirty as a fact about content, not a memory of method calls.
 *
 * The one-way boolean this replaces meant "a mutator ran at least once",
 * which could never return to pristine: undoing every edit, or setting a
 * field to the value it already had, still left the flag up. Here an asset
 * fingerprints the exact bytes its save would write, keeps the fingerprint
 * of the last successful save as its baseline, and *is* dirty exactly when
 * the two differ. Undo back to the saved state is clean because the content
 * says so; redo away is dirty again; a save partway through history simply
 * moves the baseline; a failed save leaves it where it was.
 *
 * Mutators call touchState() where they used to raise the flag — the
 * fingerprint is recomputed lazily, once per generation, so a render loop
 * asking isDirty() every frame does not re-serialize anything that has not
 * changed.
 *
 * @package Ichiloto\Editor\History
 */
trait TracksPersistedState
{
    /**
     * @var string|null The fingerprint of the last successful save; null
     * before one, which is what makes a never-saved asset dirty.
     */
    private ?string $persistedFingerprint = null;
    private int $stateGeneration = 0;
    private string $fingerprintedVersion = '';
    private string $currentFingerprint = '';

    /**
     * Builds the exact persisted form this asset's save would write.
     *
     * @return string The bytes, concatenated when the asset spans files.
     */
    abstract protected function buildPersistedPayload(): string;

    /**
     * Marks that state may have changed since the last fingerprint.
     *
     * @return void
     */
    protected function touchState(): void
    {
        $this->stateGeneration++;
    }

    /**
     * Returns whether the current content differs from the last save.
     *
     * @return bool True when saving would change what is on disk.
     */
    public function isDirty(): bool
    {
        return $this->stateFingerprint() !== $this->persistedFingerprint;
    }

    /**
     * Adopts the current content as the saved baseline.
     *
     * Call this when the asset loads, and after — never before — a save
     * succeeds.
     *
     * @return void
     */
    protected function captureBaseline(): void
    {
        $this->persistedFingerprint = $this->stateFingerprint();
    }

    /**
     * Returns this asset's mutation version, for parents that aggregate it.
     *
     * @return int The version.
     */
    public function stateVersion(): int
    {
        return $this->stateGeneration;
    }

    /**
     * Returns the combined version of children this asset's payload embeds.
     *
     * An aggregate's bytes change when a child mutates, even though none of
     * the aggregate's own mutators ran; folding child versions into the
     * cache key keeps the fingerprint honest without re-serializing on
     * every look.
     *
     * @return string The dependency version, exact rather than summed — a
     *   removal and an edit must never cancel out to the same key.
     */
    protected function dependencyVersion(): string
    {
        return '';
    }

    /**
     * Returns the current content fingerprint, computed at most once per
     * mutation generation.
     *
     * @return string The fingerprint.
     */
    protected function stateFingerprint(): string
    {
        $version = $this->stateGeneration . '|' . $this->dependencyVersion();

        if ($this->fingerprintedVersion !== $version) {
            $this->currentFingerprint = sha1($this->buildPersistedPayload());
            $this->fingerprintedVersion = $version;
        }

        return $this->currentFingerprint;
    }
}
