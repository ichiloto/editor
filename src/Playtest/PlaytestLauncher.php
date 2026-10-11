<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Playtest;

use Ichiloto\Editor\Console\ConsoleBinary;
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

    /** The renderer choice that lets the console pick: GPUI when it is installed and usable, otherwise the terminal. */
    public const string AUTOMATIC = 'auto';

    /**
     * Starts the playtest in the background with a renderer of its own, for
     * an editor that owns no terminal to hand over: the play command runs
     * without prompts, reading nothing and writing its output to a log. It
     * begins a new game at the overlay's starting position, skipping the
     * title, or at the title; a terminal session opens in a terminal window of
     * its own, which closes when the game ends.
     *
     * @param string $rendererId The renderer, such as `gpui`, or {@see AUTOMATIC}.
     * @param string $logPath Where the play command's output goes.
     * @throws RuntimeException When the process cannot start.
     */
    public function start(PlaytestOverlay $overlay, string $rendererId, string $logPath, PlaytestStart $start = PlaytestStart::CELL): PlaytestRun
    {
        return $this->spawn($overlay, ['play', '--no-tmux', '--no-interaction',
            '--start=' . ($start === PlaytestStart::TITLE ? 'title' : 'new-game'), '--renderer=' . $rendererId,
            '--terminal-window', '-d', $overlay->root], $logPath);
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
     * Starts a battle test in the background with a renderer of its own, as
     * {@see start()} starts a playtest: `ichiloto battle` against a battle
     * test overlay, fighting the named troop with the party and arena its
     * system data's battle test sets.
     *
     * @param string $troop The troop to fight, by name.
     * @throws RuntimeException When the process cannot start.
     */
    public function startBattle(PlaytestOverlay $overlay, string $rendererId, string $logPath, string $troop): PlaytestRun
    {
        return $this->spawn($overlay, ['battle', '--no-interaction', '--renderer=' . $rendererId, '-d', $overlay->root, '--troop', $troop], $logPath);
    }

    /**
     * Runs a console command against an overlay, reading nothing and writing
     * its output to a log.
     *
     * @param list<string> $arguments The command and its options.
     */
    private function spawn(PlaytestOverlay $overlay, array $arguments, string $logPath): PlaytestRun
    {
        $log = @fopen($logPath, 'ab');
        if (! is_resource($log)) {
            throw new RuntimeException("Unable to open the playtest log at {$logPath}.");
        }

        try {
            $pipes = [];
            $process = @proc_open(
                [PHP_BINARY, $this->consoleBinary, ...$arguments],
                [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log],
                $pipes,
                $overlay->root,
            );
        } finally {
            fclose($log);
        }

        if (! is_resource($process)) {
            throw new RuntimeException('The playtest could not be started.');
        }

        return new PlaytestRun($process, $overlay, $logPath);
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
