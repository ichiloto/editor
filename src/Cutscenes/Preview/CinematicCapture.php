<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Generator;
use InvalidArgumentException;

/**
 * Records a captured cinematic as a moving review: one composition of the
 * scene per frame at a fixed rate, from launch to completion, as a player
 * watching the game's graphical window would see it.
 *
 * The Engine plays every command; the capture only stands in for the
 * player. What waits on the player (a dialogue page, a choice, a notice)
 * stays on screen for the reading time and is then acknowledged, a choice
 * by its highlighted option. While dialogue waits the field goes on moving,
 * as it does in the game; while a notice waits the scene is held, as the
 * game's notice window holds it, so those frames show the same scene.
 * Each frame carries the capture's own time and the scene's clock.
 */
final class CinematicCapture
{
    /** @var list<array{kind: string, captureSeconds: float}> The waits acknowledged, in order, with the capture time each closed at. */
    public private(set) array $acknowledged = [];
    /** Whether the run gave up at the longest capture time before it finished. */
    public private(set) bool $gaveUp = false;

    /**
     * @param float $fps Frames per second of capture time.
     * @param float $readingSeconds How long each waiting page stays on screen before it is acknowledged.
     * @param float $maxSeconds The longest capture time recorded.
     */
    public function __construct(
        public readonly CinematicPreviewSession $session,
        public readonly float $fps,
        public readonly float $readingSeconds,
        public readonly float $maxSeconds,
    ) {
        if (! $session->isCaptured()) {
            throw new InvalidArgumentException('Record a session started for capture.');
        }
        if (! is_finite($fps) || $fps <= 0 || $fps > 120) {
            throw new InvalidArgumentException('A capture records from above 0 to 120 frames per second.');
        }
        if (! is_finite($readingSeconds) || $readingSeconds < 0 || ! is_finite($maxSeconds) || $maxSeconds <= 0) {
            throw new InvalidArgumentException('A capture needs a reading time of 0 or more and a positive longest time.');
        }
    }

    /**
     * The frames, in order, until the run finishes, its finished scene the
     * last, or the longest time is reached.
     *
     * @return Generator<int, CapturedFrame>
     */
    public function record(): Generator
    {
        $step = 1 / $this->fps;
        $waited = 0.0;
        $lastFrame = (int) floor($this->maxSeconds * $this->fps);
        for ($index = 0; $index <= $lastFrame; $index++) {
            $finished = $this->session->isFinished();
            yield new CapturedFrame($index, $index * $step, $this->session->elapsed(), $this->session->getWaitKind(),
                $this->session->composeFrame());
            if ($finished) {
                return;
            }
            $kind = $this->session->getWaitKind();
            if ($kind !== null) {
                $waited += $step;
                if ($waited + 1e-9 >= $this->readingSeconds) {
                    $this->acknowledged[] = ['kind' => $kind, 'captureSeconds' => ($index + 1) * $step];
                    $this->session->confirm();
                    $waited = 0.0;
                }
            } else {
                $waited = 0.0;
            }
            $this->session->step($step);
        }
        $this->gaveUp = ! $this->session->isFinished();
    }
}
