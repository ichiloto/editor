<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use Generator;
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
     * Reads a PNG's bytes. Its pixels are read one scanline at a time
     * ({@see PngScanlines}), so a large image costs four bytes a pixel.
     *
     * @param string $label How the image is named in a refusal (its asset path).
     * @throws InvalidArgumentException When the bytes are not a PNG this reads, saying why.
     */
    public static function decode(string $bytes, string $label): self
    {
        $header = null;
        $palette = '';
        $transparency = null;
        $data = '';
        $kept = [];
        foreach (self::readChunks($bytes, $label) as [$type, $chunk]) {
            match ($type) {
                'IHDR' => $header = PngHeader::read($chunk, $label),
                'PLTE' => $palette = $chunk,
                'tRNS' => $transparency = $chunk,
                'IDAT' => $data .= $chunk,
                default => null,
            };
            if (in_array($type, self::KEPT_CHUNKS, true)) {
                $kept[] = [$type, $chunk];
            }
        }
        if ($header === null) {
            throw new InvalidArgumentException("{$label} has no image header.");
        }

        return new self($header->width, $header->height, PngScanlines::decode($data, $header, $palette, $transparency, $label), $kept);
    }

    /**
     * Reads only a PNG's image header, to learn its size and form before its
     * pixels are read.
     *
     * @param string $label How the image is named in a refusal (its asset path).
     * @throws InvalidArgumentException When the bytes are not a PNG this reads, or end before its header, saying why.
     */
    public static function readHeader(string $bytes, string $label): PngHeader
    {
        foreach (self::readChunks($bytes, $label) as [$type, $chunk]) {
            if ($type === 'IHDR') {
                return PngHeader::read($chunk, $label);
            }
        }

        throw new InvalidArgumentException("{$label} has no image header.");
    }

    /**
     * The chunks of a PNG in file order, each checked against its checksum,
     * up to and including IEND.
     *
     * @return Generator<int, array{0: string, 1: string}>
     * @throws InvalidArgumentException When the bytes are not a PNG, a chunk is damaged, or the file ends before IEND.
     */
    private static function readChunks(string $bytes, string $label): Generator
    {
        if (! str_starts_with($bytes, self::SIGNATURE)) {
            throw new InvalidArgumentException("{$label} is not a PNG image.");
        }
        $offset = strlen(self::SIGNATURE);
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
            yield [$type, $chunk];
            if ($type === 'IEND') {
                return;
            }
        }

        throw new InvalidArgumentException("{$label} is cut short: the file ends before its image does.");
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

    private static function writeChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data) & 0xFFFFFFFF);
    }
}
