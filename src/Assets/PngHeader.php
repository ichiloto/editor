<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use InvalidArgumentException;

/**
 * A PNG's image header (its IHDR chunk), refused when it is not one the
 * Editor reads without loss: 16-bit and interlaced images are refused with
 * what to do instead.
 */
final class PngHeader
{
    /** The bytes of one row of pixels, without the filter byte that starts it in the file. */
    public int $stride {
        get => intdiv($this->width * $this->depth * $this->colorType->getChannels() + 7, 8);
    }

    /** How far back a row filter looks for the byte to the left: one pixel, or one byte below 8 bits a pixel. */
    public int $filterStep {
        get => max(1, intdiv($this->depth * $this->colorType->getChannels(), 8));
    }

    private function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $depth,
        public readonly PngColorType $colorType,
    ) {
    }

    /**
     * Reads the header chunk's data.
     *
     * @param string $label How the image is named in a refusal (its asset path).
     * @throws InvalidArgumentException When it is damaged, not a PNG header, or one the Editor does not read.
     */
    public static function read(string $chunk, string $label): self
    {
        if (strlen($chunk) !== 13) {
            throw new InvalidArgumentException("{$label} has a damaged header.");
        }
        ['width' => $width, 'height' => $height, 'depth' => $depth, 'color' => $color, 'compression' => $compression,
            'filter' => $filter, 'interlace' => $interlace] = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $chunk);
        $colorType = PngColorType::tryFrom($color);
        if ($width < 1 || $height < 1 || $colorType === null || ! $colorType->allowsDepth($depth) || $compression !== 0 || $filter !== 0) {
            throw new InvalidArgumentException("{$label} has a header no PNG reader accepts.");
        }
        if ($depth === 16) {
            throw new InvalidArgumentException("{$label} has 16 bits per channel. Save it with 8 bits per channel, as tileset sheets are, and try again.");
        }
        if ($interlace !== 0) {
            throw new InvalidArgumentException("{$label} is interlaced. Save it without interlacing and try again.");
        }

        return new self($width, $height, $depth, $colorType);
    }
}
