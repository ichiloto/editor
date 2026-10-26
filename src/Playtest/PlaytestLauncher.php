<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Playtest;

use Ichiloto\Editor\Console\ConsoleBinary;
use RuntimeException;

/**
 * Runs `ichiloto play` from the author's real project root for a
 * {@see PlaytestLaunch}, which carries where the game starts and where its
 * data lives in the Engine's launch context.
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
     * Locates the console entry point ({@see ConsoleBinary::discover()}).
     *
     * @param string|null $override An explicit path (`ICHILOTO_CONSOLE_BIN`).
     * @param string|null $projectRoot The project being edited, if known.
     * @return self
     */
    public static function discover(?string $override = null, ?string $projectRoot = null): self
    {
        return new self(ConsoleBinary::discover($override, $projectRoot)->path);
    }

    /**
     * The play command's arguments, from its interpreter on: the game in this
     * terminal, from the project's own root.
     *
     * @return list<string>
     */
    public function buildPlayArguments(PlaytestLaunch $launch): array
    {
        return [PHP_BINARY, $this->consoleBinary, 'play', '--no-tmux', $launch->buildPlayOption(), '-d', $launch->projectRoot];
    }

    /** The renderer choice that lets the console pick: GPUI when it is installed and usable, otherwise the terminal. */
    public const string AUTOMATIC = 'auto';

    /**
     * Starts the playtest in the background with a renderer of its own, for
     * an editor that owns no terminal to hand over: the play command runs
     * without prompts, reading nothing and writing its output to the launch's
     * log. A terminal session opens in a terminal window of its own, which
     * closes when the game ends. Field playtests and battle tests start the
     * same way; the launch context says which it is.
     *
     * @param string $rendererId The renderer, such as `gpui`, or {@see AUTOMATIC}.
     * @throws RuntimeException When the process cannot start.
     */
    public function start(PlaytestLaunch $launch, string $rendererId): PlaytestRun
    {
        $log = @fopen($launch->getLogPath(), 'ab');
        if (! is_resource($log)) {
            throw new RuntimeException("Unable to open the playtest log at {$launch->getLogPath()}.");
        }

        try {
            $pipes = [];
            $process = @proc_open(
                [PHP_BINARY, $this->consoleBinary, 'play', '--no-tmux', '--no-interaction', '--renderer=' . $rendererId,
                    '--terminal-window', $launch->buildPlayOption(), '-d', $launch->projectRoot],
                [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log],
                $pipes,
                $launch->projectRoot,
                $launch->buildEnvironment(),
            );
        } finally {
            fclose($log);
        }

        if (! is_resource($process)) {
            throw new RuntimeException('The playtest could not be started.');
        }

        return new PlaytestRun($process, $launch);
    }

    /**
     * The renderers the project's Engine can launch, as the console reports
     * them without starting any: each with its name, whether it is usable,
     * and why not when it is not, and the one it prefers.
     *
     * @return array{renderers: list<array{id: string, displayName: string, available: bool, reason: ?string}>, preferredId: ?string}
     * @throws RuntimeException When the console cannot report them.
     */
    public function listRenderers(string $projectRoot): array
    {
        $pipes = [];
        $process = @proc_open([PHP_BINARY, $this->consoleBinary, 'renderer:list', '--directory=' . $projectRoot, '--json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $projectRoot);
        if (! is_resource($process)) {
            throw new RuntimeException('The renderers could not be listed: the console did not start.');
        }
        $output = (string) stream_get_contents($pipes[1]);
        $errors = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $report = json_decode($output, true);
        if ($exit !== 0 || ! is_array($report) || ($report['schema'] ?? null) !== 'ichiloto.renderers/1' || ! is_array($report['renderers'] ?? null)) {
            throw new RuntimeException('The renderers could not be listed' . ($errors === '' ? '.' : ': ' . $errors));
        }
        $renderers = [];
        foreach ($report['renderers'] as $renderer) {
            if (! is_array($renderer) || ! is_string($renderer['id'] ?? null) || ! is_bool($renderer['available'] ?? null)) {
                throw new RuntimeException('The console reported a renderer the editor cannot read.');
            }
            $renderers[] = ['id' => $renderer['id'], 'displayName' => is_string($renderer['displayName'] ?? null) ? $renderer['displayName'] : $renderer['id'],
                'available' => $renderer['available'], 'reason' => is_string($renderer['reason'] ?? null) ? $renderer['reason'] : null];
        }

        return ['renderers' => $renderers, 'preferredId' => is_string($report['preferredId'] ?? null) ? $report['preferredId'] : null];
    }

    /**
     * Runs the playtest in this terminal, returning the play command's exit code.
     *
     * @throws RuntimeException When the process cannot start.
     */
    public function run(PlaytestLaunch $launch): int
    {
        $pipes = [];
        $process = @proc_open($this->buildPlayArguments($launch), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes,
            $launch->projectRoot, $launch->buildEnvironment());
        if (! is_resource($process)) {
            throw new RuntimeException('The playtest could not be started.');
        }

        return proc_close($process);
    }
}
