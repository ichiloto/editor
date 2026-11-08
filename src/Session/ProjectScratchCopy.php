<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A disposable copy of a project for work that must leave the project itself
 * untouched: checking or previewing a change set. Some edits write their files
 * at once and are undone by rolling the change set back, which cannot happen if
 * the process dies part way; on a copy, that costs nothing but the copy.
 *
 * Dependencies, version control and logs stay behind; `vendor` is linked so the
 * project's own classes still load.
 */
final class ProjectScratchCopy
{
    /** Top-level entries a change set neither reads nor writes. */
    private const array LEFT_BEHIND = ['.git', 'vendor', 'logs', 'error.log'];

    /**
     * Runs work on a scratch copy of a project and removes the copy, however the
     * process ends.
     *
     * @template T
     * @param Closure(string): T $work Given the copy's root.
     * @return T
     * @throws RuntimeException When the copy cannot be made.
     */
    public static function run(string $projectRoot, Closure $work): mixed
    {
        $source = realpath($projectRoot);
        if ($source === false || ! is_dir($source)) {
            throw new RuntimeException(sprintf('%s is not a project directory.', $projectRoot));
        }
        $copy = sys_get_temp_dir() . '/' . uniqid('ichiloto-change-check-', true);
        $remove = static function () use ($copy): void {
            self::remove($copy);
        };
        // A fatal error or exit skips `finally`; the shutdown still clears the copy.
        register_shutdown_function($remove);

        try {
            self::copy($source, $copy);

            return $work($copy);
        } finally {
            $remove();
        }
    }

    private static function copy(string $source, string $copy): void
    {
        self::makeDirectory($copy);
        if (is_dir($source . '/vendor') && ! @symlink($source . '/vendor', $copy . '/vendor')) {
            throw new RuntimeException('The project\'s dependencies could not be linked into a scratch copy.');
        }
        foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (! in_array($entry->getFilename(), self::LEFT_BEHIND, true)) {
                self::copyEntry($entry, $copy . '/' . $entry->getFilename());
            }
        }
    }

    private static function copyEntry(SplFileInfo $entry, string $target): void
    {
        if ($entry->isLink()) {
            $resolved = $entry->getRealPath();
            if ($resolved !== false && ! @symlink($resolved, $target)) {
                throw new RuntimeException(sprintf('%s could not be linked into a scratch copy.', $entry->getPathname()));
            }

            return;
        }
        if (! $entry->isDir()) {
            if (! @copy($entry->getPathname(), $target)) {
                throw new RuntimeException(sprintf('%s could not be copied into a scratch copy.', $entry->getPathname()));
            }

            return;
        }
        self::makeDirectory($target);
        foreach (new FilesystemIterator($entry->getPathname(), FilesystemIterator::SKIP_DOTS) as $child) {
            self::copyEntry($child, $target . '/' . $child->getFilename());
        }
    }

    private static function makeDirectory(string $path): void
    {
        if (! mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new RuntimeException(sprintf('%s could not be created.', $path));
        }
    }

    private static function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($path);
    }
}
