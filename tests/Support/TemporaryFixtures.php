<?php

declare(strict_types=1);

/**
 * Owns every throwaway file and directory the suite makes in the system
 * temporary directory, from creation to removal.
 *
 * A test asks this registry for its fixture, and the registry removes it
 * when the test ends, whether the test passed or not. Anything still
 * registered when the process ends is removed then: a fixture made outside
 * a test (a cached catalogue, a script that loads the test helpers to probe
 * the Editor) has the same owner as one made inside. A fixture that cannot
 * be removed is reported with the reason the filesystem gave, so residue
 * never builds up unseen.
 *
 * Each process makes its fixtures inside one run directory of its own and
 * holds a lock beside it for as long as it lives. A process that is killed
 * cannot clean up after itself, but its lock dies with it, so the next
 * process to make a fixture removes that abandoned run directory.
 */
final class TemporaryFixtures
{
    private const string RUN_PREFIX = 'ichiloto-editor-fixtures-';

    /** @var array<string, string> Each owned path, mapped to what made it. */
    private static array $owned = [];

    private static bool $removesAtShutdown = false;

    private static ?string $runDirectory = null;

    /** @var resource|null The lock that marks the run directory as in use. */
    private static mixed $runLock = null;

    /**
     * Makes an empty directory in this process's run directory and owns it.
     *
     * @param string $prefix The start of the directory's name.
     * @return string The directory.
     */
    public static function createDirectory(string $prefix): string
    {
        $path = self::reservePath($prefix);
        $problems = self::attempt(static fn(): bool => mkdir($path, 0o777, true), $path);

        if ($problems !== []) {
            throw new RuntimeException('Unable to create the temporary fixture ' . $problems[0]);
        }

        return self::register($path);
    }

    /**
     * Makes an empty file in this process's run directory and owns it.
     *
     * @param string $prefix The start of the file's name.
     * @param string $suffix The end of the file's name, such as `.php`.
     * @return string The file.
     */
    public static function createFile(string $prefix, string $suffix = ''): string
    {
        $path = self::reservePath($prefix, $suffix);
        $problems = self::attempt(static function () use ($path): bool {
            $handle = fopen($path, 'x');

            return $handle !== false && fclose($handle);
        }, $path);

        if ($problems !== []) {
            throw new RuntimeException('Unable to create the temporary fixture ' . $problems[0]);
        }

        return self::register($path);
    }

    /**
     * Owns a fresh path that does not exist yet, for code under test to
     * create there itself.
     *
     * @param string $prefix The start of the path's name.
     * @param string $suffix The end of the path's name, such as `.php`.
     * @return string The path.
     */
    public static function reservePath(string $prefix, string $suffix = ''): string
    {
        return self::register(self::getRunDirectory() . '/' . uniqid($prefix, true) . $suffix);
    }

    /**
     * Owns a path a test lays out itself, so it is removed with the rest.
     *
     * @param string $path A file or directory under the temporary directory.
     * @return string The same path, for chaining.
     */
    public static function register(string $path): string
    {
        self::assertTemporary($path);
        self::$owned[$path] = self::describeOwner();
        self::removeAtShutdownOnce();

        return $path;
    }

    /**
     * Removes every owned path and stops owning it.
     *
     * @return list<string> What could not be removed, and why.
     */
    public static function removeAll(): array
    {
        $problems = [];

        foreach (self::$owned as $path => $owner) {
            foreach (self::removePath($path) as $problem) {
                $problems[] = sprintf('%s (made by %s)', $problem, $owner);
            }
        }

        self::$owned = [];

        return $problems;
    }

    /**
     * Removes a file or a directory tree under the temporary directory.
     *
     * A link is removed as a link: what it points at -- a shared vendor
     * tree, an engine -- is not the fixture's to remove. A directory the
     * test made read-only is made writable again first, since the fixture
     * is still its owner's to remove.
     *
     * @param string $path The file or directory.
     * @return list<string> What could not be removed, and why.
     */
    public static function removePath(string $path): array
    {
        clearstatcache(true, $path);

        if (! is_link($path) && ! file_exists($path)) {
            return [];
        }

        self::assertTemporary($path);

        return self::removeTree($path);
    }

    /**
     * @return list<string> What could not be removed, and why.
     */
    private static function removeTree(string $path): array
    {
        if (is_link($path) || ! is_dir($path)) {
            return self::attempt(static fn(): bool => unlink($path), $path);
        }

        $permissions = fileperms($path) & 0o7777;

        if (($permissions & 0o700) !== 0o700) {
            $problems = self::attempt(static fn(): bool => chmod($path, $permissions | 0o700), $path);

            if ($problems !== []) {
                return $problems;
            }
        }

        $entries = [];
        $problems = self::attempt(static function () use ($path, &$entries): bool {
            $entries = scandir($path);

            return $entries !== false;
        }, $path);

        foreach ($entries ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                array_push($problems, ...self::removeTree($path . '/' . $entry));
            }
        }

        return $problems === [] ? self::attempt(static fn(): bool => rmdir($path), $path) : $problems;
    }

    /**
     * Runs one filesystem operation, turning its failure into a reported
     * problem that carries the reason the filesystem gave.
     *
     * @param Closure(): bool $operation The operation.
     * @return list<string> Nothing, or the one problem.
     */
    private static function attempt(Closure $operation, string $path): array
    {
        $reason = 'unknown error';
        set_error_handler(static function (int $severity, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });

        try {
            $succeeded = $operation();
        } finally {
            restore_error_handler();
        }

        return $succeeded ? [] : [sprintf('%s: %s', $path, $reason)];
    }

    /**
     * Removes what is still owned as the process ends and reports what
     * could not be removed on the error stream, where the run shows it.
     */
    private static function removeAtShutdown(): void
    {
        $problems = self::removeAll();

        if (self::$runDirectory !== null && is_resource(self::$runLock)) {
            $problems = [...$problems, ...self::removeRun(self::$runDirectory, self::$runLock)];
            self::$runDirectory = self::$runLock = null;
        }

        self::reportProblems($problems);
    }

    private static function removeAtShutdownOnce(): void
    {
        if (! self::$removesAtShutdown) {
            self::$removesAtShutdown = true;
            register_shutdown_function(self::removeAtShutdown(...));
        }
    }

    /**
     * Returns this process's run directory, making it on first use after
     * removing the run directories of processes that ended without cleaning
     * up.
     */
    private static function getRunDirectory(): string
    {
        if (self::$runDirectory !== null) {
            return self::$runDirectory;
        }

        self::reportProblems(self::removeAbandonedRuns());

        do {
            $lockPath = self::getTemporaryDirectory() . '/' . uniqid(self::RUN_PREFIX, true) . '.lock';
            $handle = null;
            $problems = self::attempt(static function () use ($lockPath, &$handle): bool {
                $handle = fopen($lockPath, 'x');

                return $handle !== false && flock($handle, LOCK_EX);
            }, $lockPath);

            if ($problems !== []) {
                throw new RuntimeException('Unable to lock the temporary fixture run ' . $problems[0]);
            }
        } while (! self::isLockAt($handle, $lockPath));

        $directory = substr($lockPath, 0, -strlen('.lock'));
        $problems = self::attempt(static fn(): bool => mkdir($directory, 0o700), $directory);

        if ($problems !== []) {
            throw new RuntimeException('Unable to create the temporary fixture run ' . $problems[0]);
        }

        self::$runLock = $handle;
        self::$runDirectory = $directory;
        self::removeAtShutdownOnce();

        return $directory;
    }

    /**
     * Removes every run directory whose lock no live process holds.
     *
     * @return list<string> What could not be removed, and why.
     */
    private static function removeAbandonedRuns(): array
    {
        $problems = [];

        foreach (glob(self::getTemporaryDirectory() . '/' . self::RUN_PREFIX . '*.lock') ?: [] as $lockPath) {
            $handle = @fopen($lockPath, 'r');

            if ($handle === false) {
                continue;
            }

            if (! flock($handle, LOCK_EX | LOCK_NB) || ! self::isLockAt($handle, $lockPath)) {
                fclose($handle);

                continue;
            }

            array_push($problems, ...self::removeRun(substr($lockPath, 0, -strlen('.lock')), $handle));
        }

        return $problems;
    }

    /**
     * Removes a run directory, then its lock while still holding it, so no
     * process can take an emptied run for a live one.
     *
     * @param resource $lock The held lock.
     * @return list<string> What could not be removed, and why.
     */
    private static function removeRun(string $directory, mixed $lock): array
    {
        $problems = self::removePath($directory);
        $lockPath = $directory . '.lock';

        if ($problems === []) {
            $problems = self::attempt(static fn(): bool => unlink($lockPath), $lockPath);
        }

        flock($lock, LOCK_UN);
        fclose($lock);

        return $problems;
    }

    /**
     * Whether a held lock is still the file at its path. A process that
     * opened a lock file just before another removed it holds a file no
     * other process can find, which marks nothing.
     *
     * @param resource|null $handle The held lock.
     */
    private static function isLockAt(mixed $handle, string $lockPath): bool
    {
        clearstatcache(true, $lockPath);
        $held = is_resource($handle) ? fstat($handle) : false;
        $current = @stat($lockPath);

        if (is_array($held) && is_array($current) && $held['dev'] === $current['dev'] && $held['ino'] === $current['ino']) {
            return true;
        }

        if (is_resource($handle)) {
            fclose($handle);
        }

        return false;
    }

    /**
     * @param list<string> $problems What could not be removed, and why.
     */
    private static function reportProblems(array $problems): void
    {
        if ($problems !== []) {
            fwrite(STDERR, "Temporary fixtures could not be removed:\n  " . implode("\n  ", $problems) . "\n");
        }
    }

    /**
     * Refuses any path outside the temporary directory: such a path is a
     * mistake, not a fixture, whatever asked for it.
     */
    private static function assertTemporary(string $path): void
    {
        $temporary = rtrim(realpath(self::getTemporaryDirectory()) ?: self::getTemporaryDirectory(), '/') . '/';
        $parent = realpath(dirname($path));
        $resolved = $parent === false ? $path : $parent . '/' . basename($path);

        if (! str_starts_with($resolved, $temporary)) {
            throw new RuntimeException(sprintf('Refusing to own or remove %s: it is not a temporary fixture.', $path));
        }
    }

    private static function getTemporaryDirectory(): string
    {
        return rtrim(sys_get_temp_dir(), '/');
    }

    private static function describeOwner(): string
    {
        $test = class_exists(Pest\TestSuite::class, false)
            ? new ReflectionProperty(Pest\TestSuite::class, 'instance')->getValue()?->test
            : null;

        return $test instanceof PHPUnit\Framework\TestCase
            ? $test::class . '::' . $test->name()
            : 'code outside a test in ' . (($_SERVER['SCRIPT_FILENAME'] ?? '') ?: 'a command-line script');
    }
}
