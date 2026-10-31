<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use InvalidArgumentException;

/**
 * A kind of sound file the Editor brings into a project, by the extension it
 * is named with. Each is one the Engine's players decode
 * ({@see \Ichiloto\Engine\Audio\Backends\CommandLineAudioBackend}): MP3 and
 * WAV on every platform's default player, Ogg (Vorbis or Opus) where mpv,
 * ffplay or paplay is installed. The file's own bytes must be that format;
 * its sound is read from them ({@see AudioClip}).
 */
enum AudioFormat: string
{
    case Mp3 = 'mp3';
    case Wav = 'wav';
    case Ogg = 'ogg';

    /** How an author knows the format. */
    public function getLabel(): string
    {
        return match ($this) {
            self::Mp3 => 'MP3',
            self::Wav => 'WAV',
            self::Ogg => 'Ogg',
        };
    }

    /** The format a file name's extension names, or null when it names none of these. */
    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom(strtolower(pathinfo($name, PATHINFO_EXTENSION)));
    }

    /** The format a file's bytes start as, or null when they start as none of these. */
    public static function tryFromBytes(string $bytes): ?self
    {
        return match (true) {
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE' => self::Wav,
            str_starts_with($bytes, 'OggS') => self::Ogg,
            str_starts_with($bytes, 'ID3') || Mp3Audio::isFrameStart($bytes, 0) => self::Mp3,
            default => null,
        };
    }

    /** The extensions, as an author is asked to name a sound with. */
    public static function describeExtensions(): string
    {
        $names = array_map(static fn(self $format): string => '.' . $format->value, self::cases());

        return implode(', ', array_slice($names, 0, -1)) . ' or ' . $names[array_key_last($names)];
    }

    /**
     * Reads a file of this format's sound.
     *
     * @param string $label How the file is named in a refusal.
     * @throws InvalidArgumentException When the bytes are not a whole, playable file of this format.
     */
    public function readClip(string $bytes, string $label): AudioClip
    {
        return match ($this) {
            self::Mp3 => Mp3Audio::read($bytes, $label),
            self::Wav => WavAudio::read($bytes, $label),
            self::Ogg => OggAudio::read($bytes, $label),
        };
    }
}
