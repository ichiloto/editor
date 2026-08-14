<?php

declare(strict_types=1);

namespace Ichiloto\Editor\IO;

use RuntimeException;

/**
 * The one way the editor writes a project file.
 *
 * Every save in the editor goes through here, which is what makes two
 * promises global instead of per-class: a write is atomic (the file either
 * keeps its old bytes or has all its new ones, never a torn middle), and a
 * write of identical bytes is a no-op — an unchanged asset saved keeps its
 * content, its mtime, and its place in a git diff.
 *
 * @package Ichiloto\Editor\IO
 */
final class AtomicFile
{
    /**
     * Writes a file atomically, skipping identical content.
     *
     * @param string $path The destination path.
     * @param string $contents The complete new contents.
     * @return bool True when bytes changed on disk; false for the no-op.
     */
    public static function write(string $path, string $contents): bool
    {
        if (is_file($path) && (string) file_get_contents($path) === $contents) {
            return false;
        }

        $temporaryPath = $path . '.tmp';

        if (file_put_contents($temporaryPath, $contents) === false) {
            throw new RuntimeException("Unable to write temporary file for {$path}.");
        }

        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Unable to replace {$path}.");
        }

        return true;
    }
}
