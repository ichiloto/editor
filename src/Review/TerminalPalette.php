<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;

/**
 * The colours a review page shows Terminal colours in: the dark terminal
 * palette the graphical renderer draws glyph cells with, so the Terminal
 * picture and a still of the graphical field read a cell's colour alike.
 * The xterm cube, its grey ramp and literal RGB colours stay literal. A
 * terminal shows the `.ans` picture in its own palette.
 */
final class TerminalPalette
{
    public const int DEFAULT_BACKGROUND = 0x111820;
    public const int DEFAULT_FOREGROUND = 0xd9e1e8;

    /** The sixteen standard and bright colours, as the graphical renderer's dark palette defines them. */
    private const array ANSI16 = [
        0x000000, 0xe06c75, 0x98c379, 0xe5c07b, 0x61afef, 0xc678dd, 0x56b6c2, 0xc5ced8,
        0x7f8c9a, 0xff8c95, 0xb3e38f, 0xffdfa3, 0x8ccaff, 0xe5a3f5, 0x83dce5, 0xffffff,
    ];

    /** A colour as 0xRRGGBB, or the given default for a cell that names none. */
    public static function resolveRgb(?PresentationColor $color, int $default): int
    {
        return $color === null ? $default : self::resolveDescriptor($color->toArray(), $default);
    }

    /**
     * A wire colour descriptor ({@see PresentationColor::toArray()}) as 0xRRGGBB.
     *
     * @param array<string, int|string>|null $descriptor
     */
    public static function resolveDescriptor(?array $descriptor, int $default): int
    {
        if ($descriptor === null) {
            return $default;
        }
        $index = (int) ($descriptor['index'] ?? 0);

        return match ($descriptor['kind'] ?? null) {
            'rgb' => ((int) $descriptor['r'] << 16) | ((int) $descriptor['g'] << 8) | (int) $descriptor['b'],
            'ansi16' => self::ANSI16[$index] ?? $default,
            'ansi256' => match (true) {
                $index < 16 => self::ANSI16[$index],
                $index < 232 => self::resolveCube($index - 16),
                default => (8 + ($index - 232) * 10) * 0x010101,
            },
            default => $default,
        };
    }

    /** A colour as a CSS hex value. */
    public static function formatCss(int $rgb): string
    {
        return sprintf('#%06x', $rgb & 0xffffff);
    }

    private static function resolveCube(int $index): int
    {
        $levels = [0, 95, 135, 175, 215, 255];

        return ($levels[intdiv($index, 36)] << 16) | ($levels[intdiv($index, 6) % 6] << 8) | $levels[$index % 6];
    }
}
