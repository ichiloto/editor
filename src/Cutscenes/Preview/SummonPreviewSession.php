<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonPlaybackSession;
use Ichiloto\Engine\IO\Console\TerminalText;

/**
 * A summon timeline played by the Engine's own `SummonPlaybackSession`.
 *
 * The Engine compiles the timeline and owns the playhead, the frame clock,
 * the active segments and the cue schedule; the editor adds a clock it can
 * pause or step, a log of the cues the playhead crossed, and a picture of
 * the frame drawn with the battle field's rules (position, content lines,
 * asset placeholder, visibility) into a buffer the pane can show.
 */
final class SummonPreviewSession
{
    private SummonPlaybackSession $session;
    private bool $playing = false;
    private float $elapsed = 0.0;
    private bool $loop = false;
    private ?float $speed = null;
    /** @var array<int, array{frame: int, id: string, type: string}> */
    private array $cueLog = [];

    public function __construct(public readonly SummonCompiledCutscene $cutscene)
    {
        $this->session = new SummonPlaybackSession($cutscene, loop: false);
        $this->session->pause();
    }

    /**
     * Whether playback wraps at the last frame.
     */
    public function isLooping(): bool
    {
        return $this->loop;
    }

    public function toggleLoop(): bool
    {
        $this->loop = ! $this->loop;
        $this->rebuildSession();

        return $this->loop;
    }

    /**
     * The playback speed multiplier the Engine session runs at.
     */
    public function speed(): float
    {
        return $this->session->effectiveSpeed;
    }

    /**
     * Steps the speed through 0.25×, 0.5×, 1×, 2× and 4×.
     */
    public function changeSpeed(int $direction): float
    {
        $steps = [0.25, 0.5, 1.0, 2.0, 4.0];
        $current = $this->speed();
        $position = 2;

        foreach ($steps as $index => $step) {
            if (abs($step - $current) < 0.001) {
                $position = $index;
            }
        }

        $position = max(0, min(count($steps) - 1, $position + $direction));
        $this->speed = $steps[$position];
        $this->rebuildSession();

        return $this->speed;
    }

    /**
     * Re-creates the Engine session with the current loop and speed at the
     * same playhead; the session's own constructor owns both settings.
     */
    private function rebuildSession(): void
    {
        $frame = $this->session->currentFrame;
        $wasPlaying = $this->isPlaying();
        $this->session = new SummonPlaybackSession($this->cutscene, loop: $this->loop, speed: $this->speed);
        $this->session->seek($frame);

        if ($wasPlaying) {
            $this->session->resume();
        } else {
            $this->session->pause();
        }
    }

    public function play(): void
    {
        if ($this->session->isCompleted) {
            $this->restart();
        }

        $this->playing = true;
        $this->session->resume();
    }

    public function pause(): void
    {
        $this->playing = false;
        $this->session->pause();
    }

    public function isPlaying(): bool
    {
        return $this->playing && ! $this->session->isCompleted;
    }

    public function restart(): void
    {
        $this->session->restart();
        $this->session->pause();
        $this->elapsed = 0.0;
        $this->cueLog = [];
        $this->playing = false;
    }

    public function stepForward(): void
    {
        $this->pause();
        $this->session->stepForward();
    }

    public function stepBackward(): void
    {
        $this->pause();
        $this->session->stepBackward();
    }

    public function seek(int $frame): void
    {
        $this->pause();
        $this->session->seek($frame);
    }

    /**
     * Jumps to the next keyframe boundary after the playhead (or the last
     * frame), or the previous one before it.
     */
    public function seekBoundary(int $direction): void
    {
        $boundaries = $this->boundaries();
        $current = $this->session->currentFrame;

        if ($direction > 0) {
            foreach ($boundaries as $frame) {
                if ($frame > $current) {
                    $this->seek($frame);

                    return;
                }
            }

            $this->seek($this->session->totalFrames - 1);

            return;
        }

        foreach (array_reverse($boundaries) as $frame) {
            if ($frame < $current) {
                $this->seek($frame);

                return;
            }
        }

        $this->seek(0);
    }

    /**
     * Advances the clock while playing.
     */
    public function tick(float $seconds): void
    {
        if (! $this->isPlaying()) {
            return;
        }

        $update = $this->session->update($seconds);
        $this->elapsed += $seconds;

        foreach ($update->crossedCues as $cue) {
            if (is_array($cue)) {
                $this->cueLog[] = ['frame' => intval($cue['frame'] ?? $this->session->currentFrame), 'id' => strval($cue['id'] ?? ''), 'type' => strval($cue['type'] ?? '')];
            }
        }

        if ($this->session->isCompleted) {
            $this->playing = false;
        }
    }

    public function currentFrame(): int
    {
        return $this->session->currentFrame;
    }

    public function totalFrames(): int
    {
        return $this->session->totalFrames;
    }

    public function fps(): int
    {
        return $this->session->fps;
    }

    public function secondsPerFrame(): float
    {
        return $this->session->secondsPerFrame;
    }

    public function isCompleted(): bool
    {
        return $this->session->isCompleted;
    }

    public function elapsed(): float
    {
        return $this->elapsed;
    }

    /**
     * @return array<int, array{frame: int, id: string, type: string}>
     */
    public function cueLog(): array
    {
        return $this->cueLog;
    }

    /**
     * The cues scheduled on a frame.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cuesAt(?int $frame = null): array
    {
        return array_values(array_filter($this->session->cuesAt($frame), is_array(...)));
    }

    /**
     * The segments drawn on a frame.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeSegments(?int $frame = null): array
    {
        return array_values(array_filter($this->session->activeSegments($frame), is_array(...)));
    }

    /**
     * Every frame a keyframe starts or ends on, ascending.
     *
     * @return int[]
     */
    public function boundaries(): array
    {
        $frames = [];

        foreach ($this->cutscene->playbackSegments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $frames[] = intval($segment['startFrame'] ?? 0);
            $frames[] = intval($segment['endFrame'] ?? 0) + 1;
        }

        foreach ($this->cutscene->cueSchedule as $cue) {
            if (is_array($cue)) {
                $frames[] = intval($cue['frame'] ?? 0);
            }
        }

        $frames = array_values(array_unique(array_filter($frames, fn(int $frame): bool => $frame >= 0 && $frame < $this->session->totalFrames)));
        sort($frames);

        return $frames;
    }

    /**
     * The frame as the battle field would compose it, as plain rows.
     *
     * @return string[]
     */
    public function frame(int $width, int $height, ?int $frameIndex = null): array
    {
        $width = max(1, $width);
        $height = max(1, $height);
        $cells = array_fill(0, $height, array_fill(0, $width, ' '));

        foreach ($this->activeSegments($frameIndex) as $segment) {
            foreach (array_values(array_filter((array) ($segment['drawCommands'] ?? []), is_array(...))) as $draw) {
                if (($draw['visible'] ?? true) === false) {
                    continue;
                }

                $position = is_array($draw['position'] ?? null) ? $draw['position'] : [];
                $x = intval($position['x'] ?? $position[0] ?? 0);
                $y = intval($position['y'] ?? $position[1] ?? 0);
                $content = trim(strval($draw['content'] ?? ''), "\r\n");

                if (trim($content) === '') {
                    $assetId = trim(strval($draw['assetId'] ?? ''));
                    $content = $assetId !== '' ? '[' . strtoupper($assetId) . ']' : '';
                }

                foreach (preg_split('/\r?\n/', $content) ?: [] as $lineIndex => $line) {
                    $row = $y + $lineIndex;

                    if ($line === '' || $row < 0 || $row >= $height) {
                        continue;
                    }

                    $column = $x;

                    foreach (TerminalText::visibleSymbols($line) as $symbol) {
                        $symbolWidth = max(1, TerminalText::displayWidth($symbol));

                        if ($column >= 0 && $column + $symbolWidth <= $width) {
                            $cells[$row][$column] = $symbol;

                            for ($offset = 1; $offset < $symbolWidth; $offset++) {
                                $cells[$row][$column + $offset] = '';
                            }
                        }

                        $column += $symbolWidth;
                    }
                }
            }
        }

        return array_map(static fn(array $row): string => implode('', $row), $cells);
    }

    /**
     * A ruler across the timeline with the playhead, and the keyframe bars
     * per track under it.
     *
     * @return string[]
     */
    public function rulerLines(int $width): array
    {
        $total = $this->session->totalFrames;
        $width = max(10, $width);
        $labelWidth = 10;
        $barWidth = max(4, $width - $labelWidth - 1);
        $scale = static fn(int $frame): int => (int) floor($frame * $barWidth / max(1, $total));
        $ruler = array_fill(0, $barWidth, '·');

        // One label per second of timeline, where it fits.
        for ($frame = 0; $frame < $total; $frame += max(1, $this->session->fps)) {
            $column = $scale($frame);
            $label = (string) $frame;

            if ($column + strlen($label) <= $barWidth) {
                foreach (str_split($label) as $offset => $digit) {
                    $ruler[$column + $offset] = $digit;
                }
            }
        }

        $playhead = min($barWidth - 1, $scale($this->session->currentFrame));
        $marks = str_repeat(' ', $playhead) . '▼';
        $lines = [
            str_pad(sprintf('f%d/%d', $this->session->currentFrame, $total), $labelWidth) . ' ' . $marks,
            str_pad(sprintf('%dfps', $this->session->fps), $labelWidth) . ' ' . implode('', $ruler),
        ];
        /** @var array<string, string[]> $tracks */
        $tracks = [];

        foreach ($this->cutscene->playbackSegments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $draw = (array) (((array) ($segment['drawCommands'] ?? []))[0] ?? []);
            $trackId = strval($draw['trackId'] ?? $segment['layer'] ?? 'track');
            $tracks[$trackId] ??= array_fill(0, $barWidth, ' ');
            $start = $scale(intval($segment['startFrame'] ?? 0));
            $end = min($barWidth - 1, max($start, $scale(intval($segment['endFrame'] ?? 0))));

            for ($column = $start; $column <= $end && $column < $barWidth; $column++) {
                $tracks[$trackId][$column] = '█';
            }
        }

        foreach ($tracks as $trackId => $bar) {
            $bar[$playhead] = $bar[$playhead] === '█' ? '▓' : '│';
            $lines[] = str_pad(mb_strimwidth($trackId, 0, $labelWidth, ''), $labelWidth) . ' ' . implode('', $bar);
        }

        $cues = array_fill(0, $barWidth, ' ');
        $hasCues = false;

        foreach ($this->cutscene->cueSchedule as $cue) {
            if (is_array($cue)) {
                $column = min($barWidth - 1, $scale(intval($cue['frame'] ?? 0)));
                $cues[$column] = '◆';
                $hasCues = true;
            }
        }

        if ($hasCues) {
            $lines[] = str_pad('cues', $labelWidth) . ' ' . implode('', $cues);
        }

        return $lines;
    }
}
