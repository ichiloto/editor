<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use InvalidArgumentException;

/**
 * Reads an Ogg file's sound by walking its pages from the first to the
 * last, each one's checksum proven: one Vorbis or Opus stream, whose first
 * packet gives its rate and channels and whose last granule position gives
 * how long it plays (less Opus's pre-skip). A page cut short, a damaged
 * page, another codec or a second stream is refused.
 */
final class OggAudio
{
    /** The rate an Opus stream's granule positions count at, whatever rate it was made from. */
    private const int OPUS_RATE = 48000;

    /** @var list<int>|null The page checksum's table, made once. */
    private static ?array $crcTable = null;

    private function __construct()
    {
    }

    /** @throws InvalidArgumentException When the bytes are not one whole Vorbis or Opus stream in Ogg pages. */
    public static function read(string $bytes, string $label): AudioClip
    {
        $length = strlen($bytes);
        $offset = 0;
        $stream = null;
        $granule = -1;
        while ($offset < $length) {
            if (substr($bytes, $offset, 4) !== 'OggS' || $offset + 27 > $length) {
                throw new InvalidArgumentException($offset === 0 ? "{$label} is not an Ogg sound." : "{$label} is damaged at byte {$offset}.");
            }
            ['version' => $version, 'type' => $type, 'granule' => $position, 'serial' => $serial, 'crc' => $crc, 'segments' => $segments]
                = unpack('Cversion/Ctype/Pgranule/Vserial/Vsequence/Vcrc/Csegments', $bytes, $offset + 4);
            $body = $offset + 27 + $segments;
            if ($version !== 0 || $body > $length) {
                throw new InvalidArgumentException("{$label} is " . ($version !== 0 ? "damaged at byte {$offset}." : 'cut short.'));
            }
            $size = array_sum(unpack("C{$segments}", $bytes, $offset + 27) ?: []);
            if ($body + $size > $length) {
                throw new InvalidArgumentException("{$label} is cut short.");
            }
            $page = substr($bytes, $offset, $body + $size - $offset);
            if (self::computeCrc(substr_replace($page, "\0\0\0\0", 22, 4)) !== $crc) {
                throw new InvalidArgumentException("{$label} is damaged at byte {$offset}.");
            }
            if ($stream === null) {
                $stream = self::readFirstPacket(substr($bytes, $body, $size), ($type & 2) !== 0, $label) + ['serial' => $serial];
            } elseif ($serial !== $stream['serial'] || ($type & 2) !== 0) {
                throw new InvalidArgumentException("{$label} holds more than one stream. Save it as one Vorbis or Opus stream and try again.");
            }
            if ($position !== -1) {
                $granule = $position;
            }
            $offset = $body + $size;
        }
        $samples = $stream === null ? 0 : $granule - $stream['skip'];
        if ($stream === null || $samples < 1) {
            throw new InvalidArgumentException("{$label} holds no sound.");
        }

        return new AudioClip(AudioFormat::Ogg, $samples, $stream['rate'], $stream['channels']);
    }

    /** @return array{rate: int, channels: int, skip: int} */
    private static function readFirstPacket(string $packet, bool $beginsStream, string $label): array
    {
        if ($beginsStream && str_starts_with($packet, "\x01vorbis") && strlen($packet) >= 30) {
            ['version' => $version, 'channels' => $channels, 'rate' => $rate] = unpack('Vversion/Cchannels/Vrate', $packet, 7);
            if ($version === 0 && $channels > 0 && $rate > 0) {
                return ['rate' => $rate, 'channels' => $channels, 'skip' => 0];
            }
        }
        if ($beginsStream && str_starts_with($packet, 'OpusHead') && strlen($packet) >= 19) {
            ['version' => $version, 'channels' => $channels, 'skip' => $skip] = unpack('Cversion/Cchannels/vskip', $packet, 8);
            if (($version >> 4) === 0 && $channels > 0) {
                return ['rate' => self::OPUS_RATE, 'channels' => $channels, 'skip' => $skip];
            }
        }

        throw new InvalidArgumentException("{$label} is not Vorbis or Opus sound, which the game's players read in an Ogg file.");
    }

    /** The checksum an Ogg page carries: CRC-32 with polynomial 0x04C11DB7, unreflected, from zero. */
    private static function computeCrc(string $page): int
    {
        $table = self::$crcTable ??= self::createCrcTable();
        $crc = 0;
        $length = strlen($page);
        for ($i = 0; $i < $length; $i++) {
            $crc = (($crc << 8) & 0xFFFFFFFF) ^ $table[(($crc >> 24) ^ ord($page[$i])) & 0xFF];
        }

        return $crc;
    }

    /** @return list<int> */
    private static function createCrcTable(): array
    {
        $table = [];
        for ($byte = 0; $byte < 256; $byte++) {
            $crc = $byte << 24;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x80000000) !== 0 ? (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF : ($crc << 1) & 0xFFFFFFFF;
            }
            $table[] = $crc;
        }

        return $table;
    }
}
