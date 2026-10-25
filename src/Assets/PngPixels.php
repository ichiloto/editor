<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use InvalidArgumentException;

/**
 * A PNG's pixels, read and written without loss: every pixel as 8-bit red,
 * green, blue and alpha, exactly as the file holds it. Copying cells from one
 * sheet into another must leave every other pixel of the sheet byte for byte
 * as the artist made it, which GD cannot promise (it keeps alpha in 7 bits),
 * so the Editor reads and writes PNGs itself.
 *
 * Every 8-bit and lower PNG reads: greyscale, truecolour, palette (with its
 * transparency) and their alpha forms. Interlaced and 16-bit files are refused
 * with what to do instead, never reduced. Writing always gives an 8-bit RGBA
 * PNG, keeping the colour chunks (sRGB, gamma, chromaticities, ICC profile)
 * and the pixel density of the file read, so it shows as it did.
 */
final class PngPixels
{
    private const string SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** The chunks that say how the pixels are shown, kept when the image is written again. */
    private const array KEPT_CHUNKS = ['sRGB', 'gAMA', 'cHRM', 'iCCP', 'pHYs'];

    /**
     * @param string $rgba Four bytes per pixel, row by row from the top.
     * @param list<array{0: string, 1: string}> $kept Chunk types and data to write again, in file order.
     */
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        private string $rgba,
        private readonly array $kept = [],
    ) {
    }

    /** A blank image: every pixel transparent. */
    public static function createBlank(int $width, int $height): self
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('An image is at least one pixel each way.');
        }

        return new self($width, $height, str_repeat("\0", $width * $height * 4));
    }

    /**
     * Reads a PNG's bytes.
     *
     * @param string $label How the image is named in a refusal (its asset path).
     * @throws InvalidArgumentException When the bytes are not a PNG this reads, saying why.
     */
    public static function decode(string $bytes, string $label): self
    {
        if (! str_starts_with($bytes, self::SIGNATURE)) {
            throw new InvalidArgumentException("{$label} is not a PNG image.");
        }
        $offset = strlen(self::SIGNATURE);
        $header = null;
        $palette = '';
        $transparency = null;
        $data = '';
        $kept = [];
        $ended = false;
        while ($offset + 12 <= strlen($bytes)) {
            $length = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            if ($offset + 12 + $length > strlen($bytes)) {
                throw new InvalidArgumentException("{$label} is cut short: its {$type} chunk runs past the end of the file.");
            }
            $chunk = substr($bytes, $offset + 8, $length);
            $crc = unpack('N', substr($bytes, $offset + 8 + $length, 4))[1];
            if ((crc32($type . $chunk) & 0xFFFFFFFF) !== $crc) {
                throw new InvalidArgumentException("{$label} is damaged: its {$type} chunk does not match its checksum.");
            }
            $offset += 12 + $length;
            match ($type) {
                'IHDR' => $header = self::readHeader($chunk, $label),
                'PLTE' => $palette = $chunk,
                'tRNS' => $transparency = $chunk,
                'IDAT' => $data .= $chunk,
                default => null,
            };
            if (in_array($type, self::KEPT_CHUNKS, true)) {
                $kept[] = [$type, $chunk];
            }
            if ($type === 'IEND') {
                $ended = true;
                break;
            }
        }
        if (! $ended) {
            throw new InvalidArgumentException("{$label} is cut short: the file ends before its image does.");
        }
        if ($header === null) {
            throw new InvalidArgumentException("{$label} has no image header.");
        }
        $raw = @zlib_decode($data);
        if ($raw === false) {
            throw new InvalidArgumentException("{$label}'s pixels cannot be read: their compressed data is damaged.");
        }
        $rows = self::unfilter($raw, $header, $label);

        return new self($header['width'], $header['height'], self::expand($rows, $header, $palette, $transparency, $label), $kept);
    }

    /**
     * The image as PNG bytes: 8-bit RGBA, with the colour and density chunks
     * of the image it was read from.
     */
    public function encode(): string
    {
        $stride = $this->width * 4;
        $scanlines = '';
        for ($y = 0; $y < $this->height; $y++) {
            $scanlines .= "\0" . substr($this->rgba, $y * $stride, $stride);
        }
        $chunks = self::writeChunk('IHDR', pack('NNCCCCC', $this->width, $this->height, 8, 6, 0, 0, 0));
        foreach ($this->kept as [$type, $chunk]) {
            $chunks .= self::writeChunk($type, $chunk);
        }

        return self::SIGNATURE . $chunks . self::writeChunk('IDAT', (string) gzcompress($scanlines, 9)) . self::writeChunk('IEND', '');
    }

    /** One pixel as [red, green, blue, alpha]. */
    public function getPixel(int $x, int $y): array
    {
        $this->assertRectangle($x, $y, 1, 1);

        return array_values(unpack('C4', substr($this->rgba, ($y * $this->width + $x) * 4, 4)));
    }

    /** Sets one pixel to [red, green, blue, alpha]. */
    public function setPixel(int $x, int $y, array $rgba): void
    {
        $this->assertRectangle($x, $y, 1, 1);
        $this->rgba = substr_replace($this->rgba, pack('C4', ...$rgba), ($y * $this->width + $x) * 4, 4);
    }

    /** Whether every pixel of a rectangle is wholly transparent, so nothing is drawn there. */
    public function isTransparent(int $x, int $y, int $width, int $height): bool
    {
        $this->assertRectangle($x, $y, $width, $height);
        $mask = str_repeat("\0\0\0\xFF", $width);
        $clear = str_repeat("\0", $width * 4);
        for ($row = $y; $row < $y + $height; $row++) {
            if ((substr($this->rgba, ($row * $this->width + $x) * 4, $width * 4) & $mask) !== $clear) {
                return false;
            }
        }

        return true;
    }

    /**
     * A copy of this image with a rectangle of another image's pixels laid
     * over it at a point, every pixel replaced as it is, transparency
     * included; nothing else changes.
     */
    public function withPixelsFrom(self $source, int $sourceX, int $sourceY, int $width, int $height, int $x, int $y): self
    {
        $source->assertRectangle($sourceX, $sourceY, $width, $height);
        $this->assertRectangle($x, $y, $width, $height);
        $rgba = $this->rgba;
        for ($row = 0; $row < $height; $row++) {
            $rgba = substr_replace($rgba, substr($source->rgba, (($sourceY + $row) * $source->width + $sourceX) * 4, $width * 4),
                (($y + $row) * $this->width + $x) * 4, $width * 4);
        }

        return new self($this->width, $this->height, $rgba, $this->kept);
    }

    /** Whether two images hold the same pixels. */
    public function hasSamePixels(self $other): bool
    {
        return $this->width === $other->width && $this->height === $other->height && $this->rgba === $other->rgba;
    }

    /** A fingerprint of the pixels alone, whatever the file's encoding. */
    public function getPixelHash(): string
    {
        return hash('sha256', $this->width . 'x' . $this->height . ':' . $this->rgba);
    }

    private function assertRectangle(int $x, int $y, int $width, int $height): void
    {
        if ($x < 0 || $y < 0 || $width < 1 || $height < 1 || $x + $width > $this->width || $y + $height > $this->height) {
            throw new InvalidArgumentException(sprintf('The rectangle %d,%d %d x %d is outside the %d x %d image.',
                $x, $y, $width, $height, $this->width, $this->height));
        }
    }

    /** @return array{width: int, height: int, depth: int, color: int, channels: int} */
    private static function readHeader(string $chunk, string $label): array
    {
        if (strlen($chunk) !== 13) {
            throw new InvalidArgumentException("{$label} has a damaged header.");
        }
        ['width' => $width, 'height' => $height, 'depth' => $depth, 'color' => $color, 'compression' => $compression,
            'filter' => $filter, 'interlace' => $interlace] = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $chunk);
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color] ?? null;
        $depths = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]][$color] ?? [];
        if ($width < 1 || $height < 1 || $channels === null || ! in_array($depth, $depths, true) || $compression !== 0 || $filter !== 0) {
            throw new InvalidArgumentException("{$label} has a header no PNG reader accepts.");
        }
        if ($depth === 16) {
            throw new InvalidArgumentException("{$label} has 16 bits per channel. Save it with 8 bits per channel, as tileset sheets are, and try again.");
        }
        if ($interlace !== 0) {
            throw new InvalidArgumentException("{$label} is interlaced. Save it without interlacing and try again.");
        }

        return ['width' => $width, 'height' => $height, 'depth' => $depth, 'color' => $color, 'channels' => $channels];
    }

    /**
     * Each row's bytes with its filter undone.
     *
     * @param array{width: int, height: int, depth: int, color: int, channels: int} $header
     * @return list<list<int>>
     */
    private static function unfilter(string $raw, array $header, string $label): array
    {
        $bits = $header['depth'] * $header['channels'];
        $stride = intdiv($header['width'] * $bits + 7, 8);
        $step = max(1, intdiv($bits, 8));
        if (strlen($raw) !== ($stride + 1) * $header['height']) {
            throw new InvalidArgumentException("{$label}'s pixel data does not fit its size.");
        }
        $rows = [];
        $previous = array_fill(0, $stride, 0);
        for ($y = 0; $y < $header['height']; $y++) {
            $start = $y * ($stride + 1);
            $filter = ord($raw[$start]);
            $row = array_values(unpack('C*', substr($raw, $start + 1, $stride)) ?: []);
            switch ($filter) {
                case 0:
                    break;
                case 1:
                    for ($i = $step; $i < $stride; $i++) {
                        $row[$i] = ($row[$i] + $row[$i - $step]) & 0xFF;
                    }
                    break;
                case 2:
                    for ($i = 0; $i < $stride; $i++) {
                        $row[$i] = ($row[$i] + $previous[$i]) & 0xFF;
                    }
                    break;
                case 3:
                    for ($i = 0; $i < $stride; $i++) {
                        $left = $i >= $step ? $row[$i - $step] : 0;
                        $row[$i] = ($row[$i] + (($left + $previous[$i]) >> 1)) & 0xFF;
                    }
                    break;
                case 4:
                    for ($i = 0; $i < $stride; $i++) {
                        $left = $i >= $step ? $row[$i - $step] : 0;
                        $up = $previous[$i];
                        $corner = $i >= $step ? $previous[$i - $step] : 0;
                        $estimate = $left + $up - $corner;
                        [$toLeft, $toUp, $toCorner] = [abs($estimate - $left), abs($estimate - $up), abs($estimate - $corner)];
                        $predictor = $toLeft <= $toUp && $toLeft <= $toCorner ? $left : ($toUp <= $toCorner ? $up : $corner);
                        $row[$i] = ($row[$i] + $predictor) & 0xFF;
                    }
                    break;
                default:
                    throw new InvalidArgumentException("{$label} uses an unknown row filter.");
            }
            $rows[] = $row;
            $previous = $row;
        }

        return $rows;
    }

    /**
     * The rows' pixels as 8-bit RGBA.
     *
     * @param list<list<int>> $rows
     * @param array{width: int, height: int, depth: int, color: int, channels: int} $header
     */
    private static function expand(array $rows, array $header, string $palette, ?string $transparency, string $label): string
    {
        ['width' => $width, 'depth' => $depth, 'color' => $color] = $header;
        if ($color === 6) {
            return implode('', array_map(static fn(array $row): string => pack('C*', ...$row), $rows));
        }
        $paletteColors = [];
        if ($color === 3) {
            if ($palette === '' || strlen($palette) % 3 !== 0) {
                throw new InvalidArgumentException("{$label} names palette colours but has no palette.");
            }
            $alphas = $transparency === null ? [] : array_values(unpack('C*', $transparency) ?: []);
            foreach (str_split($palette, 3) as $index => $entry) {
                $paletteColors[] = $entry . chr($alphas[$index] ?? 255);
            }
        }
        $key = $transparency !== null && in_array($color, [0, 2], true) ? array_values(unpack('n*', $transparency) ?: []) : null;
        $scale = $depth < 8 ? intdiv(255, (1 << $depth) - 1) : 1;
        $pixels = '';
        foreach ($rows as $row) {
            $line = '';
            for ($x = 0; $x < $width; $x++) {
                $line .= match ($color) {
                    2 => self::packKeyed([$row[$x * 3], $row[$x * 3 + 1], $row[$x * 3 + 2]], $key),
                    4 => pack('C4', $row[$x * 2], $row[$x * 2], $row[$x * 2], $row[$x * 2 + 1]),
                    3 => $paletteColors[self::readSample($row, $x, $depth)]
                        ?? throw new InvalidArgumentException("{$label} names a colour its palette does not have."),
                    default => self::packGrey(self::readSample($row, $x, $depth), $scale, $key),
                };
            }
            $pixels .= $line;
        }

        return $pixels;
    }

    /** The sample of pixel x in a row of one-channel samples of the given depth. */
    private static function readSample(array $row, int $x, int $depth): int
    {
        return $depth === 8 ? $row[$x] : ($row[intdiv($x * $depth, 8)] >> (8 - $depth - ($x * $depth) % 8)) & ((1 << $depth) - 1);
    }

    /** @param list<int>|null $key */
    private static function packGrey(int $value, int $scale, ?array $key): string
    {
        $grey = $value * $scale;

        return pack('C4', $grey, $grey, $grey, $key !== null && $key[0] === $value ? 0 : 255);
    }

    /** @param list<int> $rgb @param list<int>|null $key */
    private static function packKeyed(array $rgb, ?array $key): string
    {
        return pack('C4', ...$rgb, ...[$key !== null && $key === $rgb ? 0 : 255]);
    }

    private static function writeChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data) & 0xFFFFFFFF);
    }
}
