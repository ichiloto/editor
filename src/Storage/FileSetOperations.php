<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

/**
 * The filesystem a paired-file transaction acts on.
 *
 * The transaction owns the ordering, the rollback and the reporting; this
 * port owns the individual operations. Production uses
 * `FilesystemFileSetOperations`; a test proves a rollback branch by
 * failing one operation without contriving a filesystem that fails only
 * where it is wanted.
 *
 * Every method reports failure by returning false rather than throwing, so
 * the transaction can decide what a failure means at each step -- refusing
 * the work, or reporting that a restoration could not complete.
 *
 * @package Ichiloto\Editor\Storage
 */
interface FileSetOperations
{
    /**
     * Whether a regular file exists at the path.
     */
    public function isFile(string $path): bool;

    /**
     * Whether a directory exists at the path.
     */
    public function isDirectory(string $path): bool;

    /**
     * Returns a file's bytes, or null when it cannot be read.
     */
    public function read(string $path): ?string;

    /**
     * Writes bytes to a path, replacing what is there.
     */
    public function write(string $path, string $contents): bool;

    /**
     * Moves a path over another, replacing it.
     */
    public function move(string $from, string $to): bool;

    /**
     * Removes a file.
     */
    public function remove(string $path): bool;

    /**
     * Creates one absent directory.
     *
     * Returning false when the directory already exists lets a transaction
     * distinguish a directory it owns from one another writer created.
     * Parents are created separately, in order, by the transaction.
     */
    public function makeDirectory(string $path): bool;

    /**
     * Removes an empty directory.
     */
    public function removeDirectory(string $path): bool;

    /**
     * Returns the entries of a directory, excluding `.` and `..`.
     *
     * @return string[]
     */
    public function listDirectory(string $path): array;

    /**
     * Captures the metadata needed to restore a regular file exactly.
     *
     * @return array{mode: int, owner: int, group: int, modifiedAt: int, accessedAt: int}|null
     */
    public function metadata(string $path): ?array;

    /**
     * Restores and verifies a regular file's captured metadata.
     *
     * @param array{mode: int, owner: int, group: int, modifiedAt: int, accessedAt: int} $metadata
     */
    public function restoreMetadata(string $path, array $metadata): bool;
}
