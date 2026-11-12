<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use InvalidArgumentException;

/**
 * A PNG's compressed image data turned into 8-bit RGBA, one scanline at a
 * time. The data is inflated in slices; each complete row has its filter
 * undone against the row before it and is converted to RGBA at once, so
 * besides the RGBA result only the row being read, the row before it and
 * one slice of inflated bytes are held, all as binary strings. A large
 * sheet therefore costs its four bytes a pixel and little more.
 */
final class PngScanlines
{
    /** How much compressed data is inflated at a time. */
    private const int SLICE_BYTES = 64 * 1024;

    /**
     * @param array<string, string>|null $samples For pixels narrower than a byte: each byte to its pixels' samples, one byte each.
     * @param array<string, string>|null $colors For greyscale and palette pixels: each sample byte to its RGBA.
     * @param string|null $validSamples For palette pixels: the sample bytes the palette has colours for.
     * @param string|null $transparentRgb For truecolour pixels: the colour drawn transparent, when it is one 8-bit colour.
     */
    private function __construct(
        private readonly PngHeader $header,
        private readonly string $label,
        private readonly ?array $samples,
        private readonly ?array $colors,
        private readonly ?string $validSamples,
        private readonly ?string $transparentRgb,
    ) {
    }

    /**
     * The pixels of the image data as 8-bit RGBA, four bytes a pixel, row by row from the top.
     *
     * @param string $compressed The image's IDAT data, joined in file order.
     * @param string $palette The PLTE chunk's data, or '' when there is none.
     * @param string|null $transparency The tRNS chunk's data, or null when there is none.
     * @throws InvalidArgumentException When the data cannot be read as the header describes, saying why.
     */
    public static function decode(string $compressed, PngHeader $header, string $palette, ?string $transparency, string $label): string
    {
        return self::prepare($header, $palette, $transparency, $label)->readRows($compressed);
    }

    private static function prepare(PngHeader $header, string $palette, ?string $transparency, string $label): self
    {
        $samples = $header->depth < 8 ? self::mapSamples($header->depth) : null;
        $key = $transparency === null ? [] : array_values(unpack('n*', $transparency) ?: []);

        return match ($header->colorType) {
            PngColorType::Greyscale => new self($header, $label, $samples, self::mapGreys($header->depth, $key[0] ?? null), null, null),
            PngColorType::Indexed => self::prepareIndexed($header, $label, $samples, $palette, $transparency),
            PngColorType::Truecolor => new self($header, $label, null, null, null,
                $transparency !== null && count($key) === 3 && max($key) <= 0xFF ? pack('C3', ...$key) : null),
            PngColorType::GreyscaleAlpha, PngColorType::TruecolorAlpha => new self($header, $label, null, null, null, null),
        };
    }

    /** @param array<string, string>|null $samples */
    private static function prepareIndexed(PngHeader $header, string $label, ?array $samples, string $palette, ?string $transparency): self
    {
        if ($palette === '' || strlen($palette) % 3 !== 0) {
            throw new InvalidArgumentException("{$label} names palette colours but has no palette.");
        }
        $alphas = $transparency ?? '';
        $colors = [];
        foreach (array_slice(str_split($palette, 3), 0, 256) as $index => $entry) {
            $colors[chr($index)] = $entry . ($alphas[$index] ?? "\xFF");
        }

        return new self($header, $label, $samples, $colors, implode('', array_keys($colors)), null);
    }

    /**
     * Each byte of a row of samples narrower than a byte, to those samples a byte each.
     *
     * @return array<string, string>
     */
    private static function mapSamples(int $depth): array
    {
        $mask = (1 << $depth) - 1;
        $map = [];
        for ($byte = 0; $byte < 256; $byte++) {
            $samples = '';
            for ($shift = 8 - $depth; $shift >= 0; $shift -= $depth) {
                $samples .= chr(($byte >> $shift) & $mask);
            }
            $map[chr($byte)] = $samples;
        }

        return $map;
    }

    /**
     * Each greyscale sample of a depth to its RGBA, the sample a tRNS chunk names drawn transparent.
     *
     * @return array<string, string>
     */
    private static function mapGreys(int $depth, ?int $transparent): array
    {
        $scale = $depth < 8 ? intdiv(255, (1 << $depth) - 1) : 1;
        $map = [];
        for ($value = 0; $value < 1 << $depth; $value++) {
            $grey = chr($value * $scale);
            $map[chr($value)] = $grey . $grey . $grey . ($transparent === $value ? "\0" : "\xFF");
        }

        return $map;
    }

    private function readRows(string $compressed): string
    {
        $stride = $this->header->stride;
        $line = $stride + 1;
        $inflate = inflate_init(ZLIB_ENCODING_DEFLATE);
        $pending = '';
        $previous = str_repeat("\0", $stride);
        $rgba = '';
        $rows = 0;
        $length = strlen($compressed);
        for ($offset = 0; ; $offset += self::SLICE_BYTES) {
            $last = $offset + self::SLICE_BYTES >= $length;
            $inflated = @inflate_add($inflate, substr($compressed, $offset, self::SLICE_BYTES), $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($inflated === false) {
                throw $this->refuseDamaged();
            }
            $pending .= $inflated;
            $available = intdiv(strlen($pending), $line);
            if ($rows + $available > $this->header->height) {
                throw $this->refuseSize();
            }
            for ($start = 0; $available > 0; $available--, $start += $line, $rows++) {
                $previous = $this->unfilter(substr($pending, $start, $line), $previous);
                $rgba .= $this->expand($previous);
            }
            $pending = substr($pending, $start);
            if ($last) {
                break;
            }
        }
        if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
            throw $this->refuseDamaged();
        }
        if ($rows !== $this->header->height || $pending !== '') {
            throw $this->refuseSize();
        }

        return $rgba;
    }

    /** A row's bytes with its filter undone, given the row above with its filter undone. */
    private function unfilter(string $line, string $previous): string
    {
        $row = substr($line, 1);
        $stride = strlen($row);
        $step = $this->header->filterStep;
        switch (ord($line[0])) {
            case 0:
                break;
            case 1:
                for ($i = $step; $i < $stride; $i++) {
                    $row[$i] = chr((ord($row[$i]) + ord($row[$i - $step])) & 0xFF);
                }
                break;
            case 2:
                for ($i = 0; $i < $stride; $i++) {
                    $row[$i] = chr((ord($row[$i]) + ord($previous[$i])) & 0xFF);
                }
                break;
            case 3:
                for ($i = 0; $i < $stride && $i < $step; $i++) {
                    $row[$i] = chr((ord($row[$i]) + (ord($previous[$i]) >> 1)) & 0xFF);
                }
                for (; $i < $stride; $i++) {
                    $row[$i] = chr((ord($row[$i]) + ((ord($row[$i - $step]) + ord($previous[$i])) >> 1)) & 0xFF);
                }
                break;
            case 4:
                // The first pixel has nothing to its left or above-left: Paeth then predicts from above alone.
                for ($i = 0; $i < $stride && $i < $step; $i++) {
                    $row[$i] = chr((ord($row[$i]) + ord($previous[$i])) & 0xFF);
                }
                // Plain assignments rather than list destructuring: this loop runs for every byte of the image.
                for (; $i < $stride; $i++) {
                    $left = ord($row[$i - $step]);
                    $up = ord($previous[$i]);
                    $corner = ord($previous[$i - $step]);
                    $estimate = $left + $up - $corner;
                    $toLeft = abs($estimate - $left);
                    $toUp = abs($estimate - $up);
                    $toCorner = abs($estimate - $corner);
                    $predictor = $toLeft <= $toUp && $toLeft <= $toCorner ? $left : ($toUp <= $toCorner ? $up : $corner);
                    $row[$i] = chr((ord($row[$i]) + $predictor) & 0xFF);
                }
                break;
            default:
                throw new InvalidArgumentException("{$this->label} uses an unknown row filter.");
        }

        return $row;
    }

    /** One unfiltered row's pixels as 8-bit RGBA. */
    private function expand(string $row): string
    {
        return match ($this->header->colorType) {
            PngColorType::TruecolorAlpha => $row,
            PngColorType::Truecolor => $this->transparentRgb === null
                ? implode("\xFF", str_split($row, 3)) . "\xFF"
                : implode('', array_map(fn(string $rgb): string => $rgb . ($rgb === $this->transparentRgb ? "\0" : "\xFF"), str_split($row, 3))),
            PngColorType::GreyscaleAlpha => implode('', array_map(
                static fn(string $pixel): string => $pixel[0] . $pixel[0] . $pixel[0] . $pixel[1], str_split($row, 2))),
            PngColorType::Greyscale, PngColorType::Indexed => $this->colorSamples($row),
        };
    }

    /** A row of one-sample pixels (greyscale or palette) as RGBA. */
    private function colorSamples(string $row): string
    {
        $samples = $this->samples === null ? $row : substr(strtr($row, $this->samples), 0, $this->header->width);
        if ($this->validSamples !== null && strspn($samples, $this->validSamples) !== $this->header->width) {
            throw new InvalidArgumentException("{$this->label} names a colour its palette does not have.");
        }

        return strtr($samples, $this->colors ?? []);
    }

    private function refuseDamaged(): InvalidArgumentException
    {
        return new InvalidArgumentException("{$this->label}'s pixels cannot be read: their compressed data is damaged.");
    }

    private function refuseSize(): InvalidArgumentException
    {
        return new InvalidArgumentException("{$this->label}'s pixel data does not fit its size.");
    }
}
