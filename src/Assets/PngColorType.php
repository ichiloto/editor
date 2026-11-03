<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

/** How a PNG stores each pixel, as its header names it. */
enum PngColorType: int
{
    case Greyscale = 0;
    case Truecolor = 2;
    case Indexed = 3;
    case GreyscaleAlpha = 4;
    case TruecolorAlpha = 6;

    /** How many samples make one pixel. */
    public function getChannels(): int
    {
        return match ($this) {
            self::Greyscale, self::Indexed => 1,
            self::GreyscaleAlpha => 2,
            self::Truecolor => 3,
            self::TruecolorAlpha => 4,
        };
    }

    /** Whether the PNG standard allows this many bits per sample for this type. */
    public function allowsDepth(int $depth): bool
    {
        return in_array($depth, match ($this) {
            self::Greyscale => [1, 2, 4, 8, 16],
            self::Indexed => [1, 2, 4, 8],
            self::Truecolor, self::GreyscaleAlpha, self::TruecolorAlpha => [8, 16],
        }, true);
    }
}
