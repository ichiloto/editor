<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

/**
 * What a sound file holds, as read from its own bytes: its format, how long
 * it plays, and its sample rate and channels. Nothing here is supplied by
 * an author or guessed from a file name.
 */
final class AudioClip
{
    /**
     * @param int $samples How many samples each channel plays.
     * @param int $sampleRate Samples a second, as the file is decoded.
     */
    public function __construct(
        public readonly AudioFormat $format,
        public readonly int $samples,
        public readonly int $sampleRate,
        public readonly int $channels,
    ) {
    }

    /** How long it plays, in seconds. */
    public float $duration {
        get => $this->samples / $this->sampleRate;
    }
}
