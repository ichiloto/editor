<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use InvalidArgumentException;

/**
 * Reads a WAV file's sound from its RIFF chunks: the `fmt ` chunk's
 * encoding, rate and channels, and the `data` chunk's length. Integer PCM
 * and floating-point samples are read; every player the Engine uses plays
 * them. A chunk that runs past the end of the file is a file cut short.
 */
final class WavAudio
{
    private const int PCM = 1;
    private const int FLOAT = 3;
    private const int EXTENSIBLE = 0xFFFE;

    private function __construct()
    {
    }

    /** @throws InvalidArgumentException When the bytes are not a whole WAV file of samples the game plays. */
    public static function read(string $bytes, string $label): AudioClip
    {
        if (! str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WAVE') {
            throw new InvalidArgumentException("{$label} is not a WAV sound.");
        }
        $format = null;
        $length = strlen($bytes);
        $offset = 12;
        while ($offset + 8 <= $length) {
            ['size' => $size] = unpack('Vsize', $bytes, $offset + 4);
            $id = substr($bytes, $offset, 4);
            $body = $offset + 8;
            if ($body + $size > $length) {
                throw new InvalidArgumentException("{$label} is cut short: its {$id} chunk runs past the end of the file.");
            }
            if ($id === 'fmt ') {
                $format = self::readFormat(substr($bytes, $body, $size), $label);
            } elseif ($id === 'data') {
                if ($format === null) {
                    throw new InvalidArgumentException("{$label} has its sound before the description of it, which players cannot read.");
                }
                $samples = intdiv($size, $format['blockAlign']);
                if ($samples < 1) {
                    throw new InvalidArgumentException("{$label} holds no sound.");
                }

                return new AudioClip(AudioFormat::Wav, $samples, $format['rate'], $format['channels']);
            }
            $offset = $body + $size + ($size & 1);
        }

        throw new InvalidArgumentException("{$label} holds no sound.");
    }

    /** @return array{channels: int, rate: int, blockAlign: int} */
    private static function readFormat(string $chunk, string $label): array
    {
        if (strlen($chunk) < 16) {
            throw new InvalidArgumentException("{$label} has a damaged description of its sound.");
        }
        ['tag' => $tag, 'channels' => $channels, 'rate' => $rate, 'blockAlign' => $blockAlign, 'bits' => $bits]
            = unpack('vtag/vchannels/Vrate/VbyteRate/vblockAlign/vbits', $chunk);
        if ($tag === self::EXTENSIBLE && strlen($chunk) >= 26) {
            // The encoding an extensible header names is the first two bytes of its subformat.
            ['tag' => $tag] = unpack('vtag', $chunk, 24);
        }
        if ($tag !== self::PCM && $tag !== self::FLOAT) {
            throw new InvalidArgumentException("{$label} is compressed in a way the game's players do not read. Save it as PCM WAV and try again.");
        }
        if ($channels < 1 || $rate < 1 || $bits < 1 || $blockAlign !== $channels * intdiv($bits + 7, 8)) {
            throw new InvalidArgumentException("{$label} has a damaged description of its sound.");
        }

        return ['channels' => $channels, 'rate' => $rate, 'blockAlign' => $blockAlign];
    }
}
