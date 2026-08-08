<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Runtime;

use Atatusoft\Termutil\IO\Console\Console;
use Atatusoft\Termutil\IO\Enumerations\Color;
use Atatusoft\Termutil\IO\Enumerations\MouseTrackingMode;

/**
 * Owns the terminal session: termios/stty state, the alternate screen,
 * mouse reporting, the throttled size probe, and the buffered frame writer.
 *
 * The editor never talks to `stty` or the escape-sequence layer directly —
 * every terminal side effect funnels through this host so the enter/restore
 * pairs stay symmetrical and testable.
 */
final class TerminalHost
{
    /**
     * The `stty -g` snapshot restored on exit.
     */
    private string $previousTerminalSettings = '';
    /**
     * Whether the session entered the alternate screen (disabled under tmux).
     */
    public private(set) bool $usesAlternateScreen = false;
    /**
     * When the next terminal-size probe is allowed.
     */
    private float $nextSizeProbeAt = 0.0;

    /**
     * @param float $sizeProbeIntervalSeconds The minimum seconds between size probes (`stty size` forks a subprocess, so it must never run per frame).
     */
    public function __construct(private readonly float $sizeProbeIntervalSeconds)
    {
    }

    /**
     * Snapshots the terminal settings and switches to raw, non-blocking input.
     *
     * Frees Ctrl+Z (undo) and Ctrl+Y (redo) from job control — with ISIG
     * still active they would suspend the editor instead of reaching the
     * input decoder. dsusp only exists on BSD ttys, hence the separate,
     * silenced call. The stty -g snapshot restores both on exit.
     *
     * @return void
     */
    public function enterRawMode(): void
    {
        $this->previousTerminalSettings = trim((string) shell_exec('stty -g'));
        shell_exec('stty -icanon -echo -ixon -ixoff min 0 time 0');
        shell_exec('stty susp undef 2>/dev/null');
        shell_exec('stty dsusp undef 2>/dev/null');
    }

    /**
     * Restores the stty settings captured by enterRawMode().
     *
     * @return void
     */
    public function restoreTerminalSettings(): void
    {
        if ($this->previousTerminalSettings !== '') {
            shell_exec('stty ' . $this->previousTerminalSettings);
        }
    }

    /**
     * Enters the alternate screen unless running inside tmux.
     *
     * @return void
     */
    public function enterAlternateScreen(): void
    {
        $this->usesAlternateScreen = (string) getenv('TMUX') === '';

        if ($this->usesAlternateScreen) {
            echo "\033[?1049h\033[2J\033[H";
        }
    }

    /**
     * Leaves the alternate screen if it was entered.
     *
     * @return void
     */
    public function leaveAlternateScreen(): void
    {
        if ($this->usesAlternateScreen) {
            echo "\033[?1049l";
        }
    }

    /**
     * Initializes the Console layer for a session at the given size.
     *
     * @param array{width: int, height: int} $size The terminal size.
     * @param string $title The terminal window title.
     * @return void
     */
    public function beginConsoleSession(array $size, string $title): void
    {
        Console::saveSettings();
        $this->applySize($size);
        Console::setName($title);
        Console::enableMouseReporting(MouseTrackingMode::CELL_MOTION_TRACKING);
        Console::cursor()->hide();
    }

    /**
     * Tears the Console layer down in the inverse order of the session start.
     *
     * @return void
     */
    public function endConsoleSession(): void
    {
        echo Color::RESET->value;
        Console::disableMouseReporting();
        Console::cursor()->show();
        Console::restoreSettings();
    }

    /**
     * Reconfigures the Console layer for a new terminal size.
     *
     * @param array{width: int, height: int} $size The terminal size.
     * @return void
     */
    public function applySize(array $size): void
    {
        Console::init([
            'width' => $size['width'],
            'height' => $size['height'],
        ]);
    }

    /**
     * Returns the live terminal size.
     *
     * @return array{width: int, height: int}
     */
    public function probeSize(): array
    {
        $width = 80;
        $height = 24;
        $sttySize = trim((string) shell_exec('stty size 2>/dev/null'));

        if (preg_match('/^(\d+)\s+(\d+)$/', $sttySize, $matches) === 1) {
            $height = (int) $matches[1];
            $width = (int) $matches[2];
        }

        return [
            'width' => max(80, $width),
            'height' => max(24, $height),
        ];
    }

    /**
     * Returns the live terminal size, or null while the probe is throttled.
     *
     * @return array{width: int, height: int}|null
     */
    public function probeSizeThrottled(): ?array
    {
        $now = microtime(true);

        if ($now < $this->nextSizeProbeAt) {
            return null;
        }

        $this->nextSizeProbeAt = $now + $this->sizeProbeIntervalSeconds;

        return $this->probeSize();
    }

    /**
     * Clears the visible screen with a single escape sequence.
     *
     * Never shells out: `system("clear")` forks a subprocess (~5ms) on every
     * call and is the difference between a repaint and a visible flash.
     *
     * @return void
     */
    public function clearScreen(): void
    {
        echo "\033[2J\033[H";
    }

    /**
     * Starts buffering a frame so it reaches the terminal as a single write
     * instead of hundreds — no tearing, no flicker.
     *
     * @return void
     */
    public function beginFrame(): void
    {
        ob_start();
    }

    /**
     * Flushes the buffered frame to the terminal in one write.
     *
     * @return void
     */
    public function endFrame(): void
    {
        ob_end_flush();
    }

    /**
     * Flushes any nested frame buffers (used during shutdown).
     *
     * @return void
     */
    public function flushBufferedFrames(): void
    {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    /**
     * Discards any half-rendered frame so terminal-restore sequences reach
     * the terminal directly (used by the crash handler).
     *
     * @return void
     */
    public function discardBufferedFrames(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
