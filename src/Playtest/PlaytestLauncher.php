<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Playtest;

use RuntimeException;

/**
 * Runs `ichiloto play` against a playtest overlay.
 *
 * `--no-tmux` is always passed: the editor already owns this terminal, and
 * letting the play command open or reuse a tmux session would detach the game
 * from the pane the author is looking at.
 */
final class PlaytestLauncher
{
    /**
     * @param string $consoleBinary The path to the console entry point.
     */
    public function __construct(private readonly string $consoleBinary)
    {
    }

    /**
     * Locates the console entry point.
     *
     * Where it lives depends on how the tooling was installed, not on how
     * this repository happens to be laid out: a project that requires the
     * console has it in its own vendor directory, a global install puts it on
     * PATH, and this package may itself be installed under someone else's
     * vendor tree. All of those are looked for before the side-by-side
     * checkout the packages are developed in.
     *
     * @param string|null $override An explicit path (`ICHILOTO_CONSOLE_BIN`).
     * @param string|null $projectRoot The project being edited, if known.
     * @return self
     */
    public static function discover(?string $override = null, ?string $projectRoot = null): self
    {
        $candidates = self::candidates($override, $projectRoot);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return new self($candidate);
            }
        }

        throw new RuntimeException(sprintf(
            "Could not find the ichiloto console binary — set ICHILOTO_CONSOLE_BIN to its path.\nLooked in:\n  %s",
            implode("\n  ", $candidates),
        ));
    }

    /**
     * Returns where the console binary might be, best guess first.
     *
     * @param string|null $override An explicit path.
     * @param string|null $projectRoot The project being edited, if known.
     * @return string[] The paths to try.
     */
    private static function candidates(?string $override, ?string $projectRoot): array
    {
        return array_values(array_unique(array_filter([
            $override,
            (string) getenv('ICHILOTO_CONSOLE_BIN') ?: null,
            // A project that requires ichiloto/console.
            is_string($projectRoot) && $projectRoot !== ''
                ? rtrim($projectRoot, DIRECTORY_SEPARATOR) . '/vendor/bin/ichiloto'
                : null,
            // This package installed under a vendor tree, the project's or
            // the console's own.
            ...self::vendorBinariesAbove(),
            // Installed globally.
            self::binaryOnPath(),
            // The packages checked out side by side, which is how they are
            // developed rather than how they are installed.
            dirname(__DIR__, 3) . '/console/bin/ichiloto',
        ])));
    }

    /**
     * Returns every `vendor/bin/ichiloto` in a directory above this package.
     *
     * @return string[] The paths.
     */
    private static function vendorBinariesAbove(): array
    {
        $paths = [];
        $directory = dirname(__DIR__, 2);

        // Deep enough to climb out of `vendor/ichiloto/editor` and any
        // directory nesting a project keeps above it.
        for ($depth = 0; $depth < 8; $depth++) {
            $paths[] = $directory . '/vendor/bin/ichiloto';
            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return $paths;
    }

    /**
     * Returns the console binary found on PATH, if it is there.
     *
     * @return string|null The path, or null when PATH has no ichiloto.
     */
    private static function binaryOnPath(): ?string
    {
        $path = (string) getenv('PATH');

        if ($path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . '/ichiloto';

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the shell command that runs the playtest.
     *
     * @param PlaytestOverlay $overlay The overlay to play.
     * @return string
     */
    public function buildCommand(PlaytestOverlay $overlay): string
    {
        return sprintf(
            '%s %s play --no-tmux -d %s',
            escapeshellcmd(PHP_BINARY),
            escapeshellarg($this->consoleBinary),
            escapeshellarg($overlay->root),
        );
    }

    /**
     * Runs the playtest, returning the child process exit code.
     *
     * @param PlaytestOverlay $overlay The overlay to play.
     * @return int
     */
    public function run(PlaytestOverlay $overlay): int
    {
        $exitCode = 0;
        passthru($this->buildCommand($overlay), $exitCode);

        return $exitCode;
    }
}
