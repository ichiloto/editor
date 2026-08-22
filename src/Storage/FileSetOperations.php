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
     * Creates a directory and every missing parent.
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
     * Returns a file's modification time, or null when it has none.
     */
    public function modifiedAt(string $path): ?int;

    /**
     * Restores a file's modification time, so a file put back after a
     * refused write is the file that was there in every respect.
     */
    public function setModifiedAt(string $path, int $timestamp): bool;
}
