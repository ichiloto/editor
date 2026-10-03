<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Playtest;

/**
 * A playtest running in the background ({@see PlaytestLauncher::start()}).
 * It owns its overlay: once the game has exited, or has been stopped, the
 * overlay is removed. The play command starts the game as a child of its
 * own, which starts its renderer, so stopping ends the whole tree.
 */
final class PlaytestRun
{
    private ?int $exitCode = null;
    private bool $finished = false;
    private bool $stopped = false;

    /**
     * @param resource $process The play command's process.
     */
    public function __construct(private $process, public readonly PlaytestOverlay $overlay, public readonly string $logPath)
    {
    }

    /** Whether the game is still running; a finished run removes its overlay. */
    public function isRunning(): bool
    {
        if ($this->finished) {
            return false;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            return true;
        }
        // The exit code is only reported by the first status after exit.
        $this->exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
        $this->finish();

        return false;
    }

    /** The play command's process id, while it runs. */
    public function getProcessId(): ?int
    {
        return $this->isRunning() ? proc_get_status($this->process)['pid'] : null;
    }

    /** The play command's exit code once it has exited, null while running or when unknown. */
    public function getExitCode(): ?int
    {
        return $this->isRunning() ? null : $this->exitCode;
    }

    /** Ends the game, its renderer and the play command, then removes the overlay. */
    public function stop(): void
    {
        if (! $this->isRunning()) {
            return;
        }
        $root = (int) $this->getProcessId();
        // The deepest processes first, so no parent outlives to restart or report them.
        foreach (array_reverse(self::findDescendants($root)) as $pid) {
            posix_kill($pid, SIGTERM);
        }
        $this->stopped = true;
        proc_terminate($this->process);
        $this->exitCode = proc_close($this->process);
        $this->finish(closed: true);
    }

    /** Whether it ended because it was stopped, not on its own. */
    public function wasStopped(): bool
    {
        return $this->stopped;
    }

    /** The end of the play command's output, for saying why a run failed. */
    public function readLogTail(int $lines = 12): string
    {
        $content = is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
        $kept = array_slice(preg_split('/\R/', trim($content)) ?: [], -$lines);

        return implode("\n", $kept);
    }

    private function finish(bool $closed = false): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        if (! $closed) {
            proc_close($this->process);
        }
        $this->overlay->destroy();
    }

    /**
     * Every process below one, parents before their children.
     *
     * @return list<int>
     */
    private static function findDescendants(int $pid): array
    {
        $children = array_map(intval(...), array_filter(preg_split('/\s+/', trim((string) shell_exec('pgrep -P ' . $pid))) ?: []));
        $found = [];
        foreach ($children as $child) {
            $found = [...$found, $child, ...self::findDescendants($child)];
        }

        return $found;
    }
}
