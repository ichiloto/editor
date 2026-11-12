<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use InvalidArgumentException;

/**
 * Reads an MP3 file's sound by walking its MPEG Layer III frames from the
 * first to the last: every frame must follow the one before it, with only
 * ID3 and APE tags around them, so a damaged or foreign file is refused
 * rather than half played. How long it plays is its frames' samples, less
 * the encoder's delay and padding when its Xing/Info frame records them
 * (the gapless information LAME and FFmpeg write), and the Xing/Info or
 * VBRI frame itself, which holds no sound.
 */
final class Mp3Audio
{
    /** Kilobits a second by bitrate index, for MPEG-1 and for MPEG-2 and 2.5, Layer III. */
    private const array BITRATES = [
        3 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
        2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
        0 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
    ];

    /** Samples a second by sample rate index, for MPEG-1, MPEG-2 and MPEG-2.5. */
    private const array SAMPLE_RATES = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];

    /** How far into a file its first frame is looked for, past its ID3 tags. */
    private const int FIRST_FRAME_SEARCH = 64 * 1024;

    /** The encoders whose Xing/Info frame records their delay and padding where LAME puts them. */
    private const array GAPLESS_ENCODERS = ['LAME', 'Lavf', 'Lavc'];

    private function __construct()
    {
    }

    /** Whether a Layer III frame header that can be decoded starts at an offset. */
    public static function isFrameStart(string $bytes, int $offset): bool
    {
        return self::readFrameHeader($bytes, $offset) !== null;
    }

    /** @throws InvalidArgumentException When the bytes are not a whole MP3 file. */
    public static function read(string $bytes, string $label): AudioClip
    {
        $start = self::skipId3v2($bytes);
        $end = self::findTagsStart($bytes, $start);
        $first = self::findFirstFrame($bytes, $start, $end)
            ?? throw new InvalidArgumentException("{$label} is not an MP3 sound.");
        $header = self::readFrameHeader($bytes, $first);
        ['delay' => $delay, 'padding' => $padding, 'silent' => $silent] = self::readInfoFrame($bytes, $first, $header);
        $frames = 0;
        $offset = $first;
        while ($offset < $end) {
            $frame = self::readFrameHeader($bytes, $offset);
            if ($frame === null) {
                throw new InvalidArgumentException("{$label} is damaged: something other than MP3 sound follows its frame at byte {$offset}.");
            }
            if ($frame['rate'] !== $header['rate'] || $frame['samples'] !== $header['samples']) {
                throw new InvalidArgumentException("{$label} changes its sample rate partway through, which players cannot read.");
            }
            if ($offset + $frame['length'] > $end) {
                // The last frame is cut short; players stop before it.
                break;
            }
            $frames++;
            $offset += $frame['length'];
        }
        $samples = ($frames - ($silent ? 1 : 0)) * $header['samples'] - $delay - $padding;
        if ($samples < 1) {
            throw new InvalidArgumentException("{$label} holds no sound.");
        }

        return new AudioClip(AudioFormat::Mp3, $samples, $header['rate'], $header['channels']);
    }

    /** @return array{length: int, samples: int, rate: int, channels: int, mpeg1: bool}|null */
    private static function readFrameHeader(string $bytes, int $offset): ?array
    {
        if ($offset + 4 > strlen($bytes) || ord($bytes[$offset]) !== 0xFF) {
            return null;
        }
        [$b1, $b2, $b3] = [ord($bytes[$offset + 1]), ord($bytes[$offset + 2]), ord($bytes[$offset + 3])];
        $version = ($b1 >> 3) & 3;
        $bitrate = $b2 >> 4;
        $rate = ($b2 >> 2) & 3;
        // A frame sync, a known MPEG version, Layer III, a fixed bitrate and a known sample rate.
        if (($b1 & 0xE0) !== 0xE0 || $version === 1 || (($b1 >> 1) & 3) !== 1 || $bitrate === 0 || $bitrate === 15 || $rate === 3) {
            return null;
        }
        $mpeg1 = $version === 3;
        $sampleRate = self::SAMPLE_RATES[$version][$rate];
        $length = intdiv(($mpeg1 ? 144000 : 72000) * self::BITRATES[$version][$bitrate], $sampleRate) + (($b2 >> 1) & 1);

        return ['length' => $length, 'samples' => $mpeg1 ? 1152 : 576, 'rate' => $sampleRate, 'channels' => ($b3 >> 6) === 3 ? 1 : 2,
            'mpeg1' => $mpeg1];
    }

    /** Where the frames start, past any ID3v2 tags. */
    private static function skipId3v2(string $bytes): int
    {
        $offset = 0;
        while (substr($bytes, $offset, 3) === 'ID3' && strlen($bytes) >= $offset + 10) {
            $size = 0;
            for ($i = 6; $i < 10; $i++) {
                $size = ($size << 7) | (ord($bytes[$offset + $i]) & 0x7F);
            }
            $offset += 10 + $size + ((ord($bytes[$offset + 5]) & 0x10) !== 0 ? 10 : 0);
        }

        return $offset;
    }

    /** Where the frames end: before an APEv2 tag and an ID3v1 tag at the end of the file. */
    private static function findTagsStart(string $bytes, int $start): int
    {
        $end = strlen($bytes);
        if ($end - 128 >= $start && substr($bytes, $end - 128, 3) === 'TAG') {
            $end -= 128;
        }
        if ($end - 32 >= $start && substr($bytes, $end - 32, 8) === 'APETAGEX') {
            ['size' => $size, 'flags' => $flags] = unpack('Vsize/Vflags', $bytes, $end - 20);
            $end = max($start, $end - $size - (($flags & 0x80000000) !== 0 ? 32 : 0));
        }

        return $end;
    }

    /** The first frame that the next frame follows, or that ends the sound. */
    private static function findFirstFrame(string $bytes, int $start, int $end): ?int
    {
        $limit = min($end, $start + self::FIRST_FRAME_SEARCH);
        for ($offset = $start; $offset < $limit; $offset++) {
            $frame = self::readFrameHeader($bytes, $offset);
            if ($frame !== null && ($offset + $frame['length'] === $end || self::isFrameStart($bytes, $offset + $frame['length']))) {
                return $offset;
            }
        }

        return null;
    }

    /**
     * Whether the first frame is a Xing/Info or VBRI frame, which holds no
     * sound, and the encoder delay and padding it records.
     *
     * @param array{mpeg1: bool, channels: int} $header
     * @return array{delay: int, padding: int, silent: bool}
     */
    private static function readInfoFrame(string $bytes, int $offset, array $header): array
    {
        $sideInfo = $header['mpeg1'] ? ($header['channels'] === 1 ? 17 : 32) : ($header['channels'] === 1 ? 9 : 17);
        $tag = $offset + 4 + $sideInfo;
        if (substr($bytes, $offset + 36, 4) === 'VBRI') {
            return ['delay' => 0, 'padding' => 0, 'silent' => true];
        }
        if (! in_array(substr($bytes, $tag, 4), ['Xing', 'Info'], true) || strlen($bytes) < $tag + 8) {
            return ['delay' => 0, 'padding' => 0, 'silent' => false];
        }
        ['flags' => $flags] = unpack('Nflags', $bytes, $tag + 4);
        $encoder = $tag + 8 + (($flags & 1) !== 0 ? 4 : 0) + (($flags & 2) !== 0 ? 4 : 0) + (($flags & 4) !== 0 ? 100 : 0)
            + (($flags & 8) !== 0 ? 4 : 0);
        if (! in_array(substr($bytes, $encoder, 4), self::GAPLESS_ENCODERS, true) || strlen($bytes) < $encoder + 24) {
            return ['delay' => 0, 'padding' => 0, 'silent' => true];
        }
        [$a, $b, $c] = [ord($bytes[$encoder + 21]), ord($bytes[$encoder + 22]), ord($bytes[$encoder + 23])];

        return ['delay' => ($a << 4) | ($b >> 4), 'padding' => (($b & 0x0F) << 8) | $c, 'silent' => true];
    }
}
