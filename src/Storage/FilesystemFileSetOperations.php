<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

/**
 * The real filesystem behind a paired-file transaction.
 *
 * @package Ichiloto\Editor\Storage
 */
final class FilesystemFileSetOperations implements FileSetOperations
{
    public function isFile(string $path): bool
    {
        return is_file($path);
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function read(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function write(string $path, string $contents): bool
    {
        if (! $this->isWritableDestination($path)) {
            return false;
        }

        $written = @file_put_contents($path, $contents);

        // A short write is a failure: the bytes on disk would not be the
        // bytes the caller proposed.
        return $written !== false && $written === strlen($contents);
    }

    public function move(string $from, string $to): bool
    {
        if (! $this->isWritableDestination($to)) {
            return false;
        }

        return @rename($from, $to);
    }

    public function remove(string $path): bool
    {
        // Removing an entry needs write permission on the folder holding it,
        // not on the file. Asking first keeps a predictable refusal from
        // being reported as an error nobody can act on.
        if (! is_writable(dirname($path)) || ! is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * Whether a path can be created or replaced: its folder must accept the
     * write, and the path itself must not be a directory, which no file can
     * replace.
     */
    private function isWritableDestination(string $path): bool
    {
        if (is_dir($path)) {
            return false;
        }

        return is_file($path) ? is_writable($path) && is_writable(dirname($path)) : is_writable(dirname($path));
    }

    public function makeDirectory(string $path): bool
    {
        return is_dir($path) || (@mkdir($path, 0o777, true) || is_dir($path));
    }

    public function removeDirectory(string $path): bool
    {
        return @rmdir($path);
    }

    public function listDirectory(string $path): array
    {
        $entries = @scandir($path);

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }

    public function modifiedAt(string $path): ?int
    {
        $modified = @filemtime($path);

        return $modified === false ? null : $modified;
    }

    public function setModifiedAt(string $path, int $timestamp): bool
    {
        return @touch($path, $timestamp);
    }
}
