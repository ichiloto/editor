<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use RuntimeException;

/**
 * Runs project-data evaluation with the project as the temporary process cwd.
 *
 * Some engine data constructors still resolve assets through the process
 * working directory. Editor and validation callers may start anywhere, so
 * project loading borrows the canonical project root for the complete read
 * and restores the caller's directory afterwards. Nested calls restore in
 * stack order.
 */
final class ProjectDirectoryContext
{
    /**
     * Runs an operation from a canonical project directory.
     *
     * @template TResult
     * @param string $projectRoot Absolute or caller-relative project root.
     * @param callable(string): TResult $operation Receives an absolute root.
     * @return TResult
     */
    public static function run(string $projectRoot, callable $operation): mixed
    {
        $canonicalRoot = realpath($projectRoot);

        if ($canonicalRoot === false || ! is_dir($canonicalRoot)) {
            throw new RuntimeException("Project directory does not exist: {$projectRoot}");
        }

        // Preserve an absolute caller spelling such as macOS's `/var` alias.
        // Editor paths and backup roots must agree byte-for-byte even though
        // chdir/getcwd may expose the canonical `/private/var` spelling.
        $operationRoot = self::isAbsolutePath($projectRoot)
            ? rtrim($projectRoot, DIRECTORY_SEPARATOR)
            : $canonicalRoot;

        $previousDirectory = getcwd();

        if (! is_string($previousDirectory)) {
            throw new RuntimeException('Unable to determine the current working directory.');
        }

        if (! @chdir($canonicalRoot)) {
            throw new RuntimeException("Unable to enter project directory: {$canonicalRoot}");
        }

        try {
            return $operation($operationRoot);
        } finally {
            if (! @chdir($previousDirectory)) {
                throw new RuntimeException("Unable to restore working directory: {$previousDirectory}");
            }
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
