<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Console;

use RuntimeException;

/**
 * Where the `ichiloto` console entry point is, for the editor commands that
 * run it: a playtest, a new project.
 */
final readonly class ConsoleBinary
{
    /**
     * @param string $path The path to the console entry point.
     */
    public function __construct(public string $path)
    {
    }

    /**
     * Locates the console entry point.
     *
     * A project may install the console in its own vendor directory, a global
     * install puts it on PATH, and this package may itself be installed under
     * another Composer vendor tree.
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
            "Could not find the ichiloto console binary; set ICHILOTO_CONSOLE_BIN to its path.\nLooked in:\n  %s",
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
}
