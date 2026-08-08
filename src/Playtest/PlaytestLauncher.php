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
     * Locates the console entry point next to the editor package.
     *
     * @param string|null $override An explicit path (`ICHILOTO_CONSOLE_BIN`).
     * @return self
     */
    public static function discover(?string $override = null): self
    {
        $candidates = array_filter([
            $override,
            (string) getenv('ICHILOTO_CONSOLE_BIN') ?: null,
            dirname(__DIR__, 3) . '/console/bin/ichiloto',
            dirname(__DIR__, 2) . '/vendor/bin/ichiloto',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return new self($candidate);
            }
        }

        throw new RuntimeException(
            'Could not find the ichiloto console binary — set ICHILOTO_CONSOLE_BIN to its path.',
        );
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
