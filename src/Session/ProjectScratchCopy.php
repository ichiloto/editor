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
        $entries = array_filter(
            iterator_to_array(new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS), false),
            static fn(SplFileInfo $entry): bool => ! in_array($entry->getFilename(), self::LEFT_BEHIND, true),
        );
        if ($entries === []) {
            return;
        }
        if (PHP_OS_FAMILY === 'Darwin' && function_exists('proc_open') && is_executable('/bin/cp')) {
            self::cloneEntries($entries, $copy);
        } else {
            foreach ($entries as $entry) {
                self::copyEntry($entry, $copy . '/' . $entry->getFilename());
            }
        }
        self::rebindLinks($source, $copy);
    }

    /** @param array<int, SplFileInfo> $entries */
    private static function cloneEntries(array $entries, string $copy): void
    {
        $paths = array_values(array_map(static fn(SplFileInfo $entry): string => $entry->getPathname(), $entries));
        // macOS cp clones files where the volume can, and copies them where it
        // cannot; -P carries a project's links across without following them.
        $process = @proc_open(['/bin/cp', '-cRP', ...$paths, $copy],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => STDERR], $pipes);
        if (! is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('The project could not be copied into scratch.');
        }
    }

    /**
     * Points every copied link at the copy's own data before any work runs on
     * it, or refuses a link the copy cannot isolate. The linked `vendor` stays:
     * it is the one reference a change set only reads.
     */
    private static function rebindLinks(string $source, string $copy): void
    {
        // Listed before any is replaced: a directory changing while it is read may skip an entry.
        $links = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($copy, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isLink() && $entry->getPathname() !== $copy . '/vendor') {
                $links[] = $entry->getPathname();
            }
        }
        foreach ($links as $path) {
            $original = new SplFileInfo($source . substr($path, strlen($copy)));
            if (! $original->isLink() || readlink($original->getPathname()) !== readlink($path) || ! unlink($path)) {
                throw new RuntimeException(sprintf('%s changed while copying a project link into scratch.', $original->getPathname()));
            }
            self::copyLink($original, $path, $source, $copy);
        }
    }

    private static function copyEntry(SplFileInfo $entry, string $target): void
    {
        if ($entry->isLink()) {
            if (! @symlink(readlink($entry->getPathname()), $target)) {
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

    private static function copyLink(SplFileInfo $entry, string $target, string $source, string $copy): void
    {
        $resolved = $entry->getRealPath();
        $prefix = $source . DIRECTORY_SEPARATOR;
        $relative = $resolved !== false && str_starts_with($resolved, $prefix)
            ? substr($resolved, strlen($prefix)) : '';
        $topLevel = explode(DIRECTORY_SEPARATOR, $relative, 2)[0];
        if ($relative === '' || in_array($topLevel, self::LEFT_BEHIND, true)) {
            throw new RuntimeException(sprintf('%s cannot be isolated in a scratch copy. Its link must resolve to copied project data.',
                $entry->getPathname()));
        }
        if (! @symlink($copy . DIRECTORY_SEPARATOR . $relative, $target)) {
            throw new RuntimeException(sprintf('%s could not be linked into a scratch copy.', $entry->getPathname()));
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
