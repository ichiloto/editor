<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use GdImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;

/** Prepares the interior of a clockwise canvas-image variant in source pixels. */
final class CanvasStillRotation
{
    public static function rotate(GdImage $source, int $degrees): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        [$outWidth, $outHeight] = CanvasImage::getRotatedPixelSize($width, $height, $degrees);
        $output = imagecreatetruecolor($outWidth, $outHeight);
        imagealphablending($output, false);
        imagesavealpha($output, true);

        $angle = deg2rad($degrees);
        $quarterTurn = $degrees % 90 === 0;
        $cos = $quarterTurn ? round(cos($angle)) : cos($angle);
        $sin = $quarterTurn ? round(sin($angle)) : sin($angle);
        for ($y = 0; $y < $outHeight; $y++) {
            $v = $y + .5 - $outHeight / 2;
            for ($x = 0; $x < $outWidth; $x++) {
                $u = $x + .5 - $outWidth / 2;
                $sx = $cos * $u + $sin * $v + ($width - 1) / 2;
                $sy = $cos * $v - $sin * $u + ($height - 1) / 2;
                $pixel = $quarterTurn ? imagecolorat($source, (int) $sx, (int) $sy)
                    : self::sample($source, $sx, $sy, $width, $height);
                imagesetpixel($output, $x, $y, $pixel);
            }
        }

        return $output;
    }

    /** Premultiplied bilinear sampling, with transparent surroundings outside the crop. */
    private static function sample(GdImage $image, float $x, float $y, int $width, int $height): int
    {
        $left = (int) floor($x);
        $top = (int) floor($y);
        $tx = $x - $left;
        $ty = $y - $top;
        $coverage = $red = $green = $blue = 0.0;
        foreach ([[0, 0, (1 - $tx) * (1 - $ty)], [1, 0, $tx * (1 - $ty)],
            [0, 1, (1 - $tx) * $ty], [1, 1, $tx * $ty]] as [$dx, $dy, $weight]) {
            $sx = $left + $dx;
            $sy = $top + $dy;
            if ($weight <= 0 || $sx < 0 || $sy < 0 || $sx >= $width || $sy >= $height) {
                continue;
            }
            $pixel = imagecolorat($image, $sx, $sy);
            $alpha = (127 - ($pixel >> 24)) * $weight;
            $coverage += $alpha;
            $red += (($pixel >> 16) & 255) * $alpha;
            $green += (($pixel >> 8) & 255) * $alpha;
            $blue += ($pixel & 255) * $alpha;
        }
        $alpha = (int) round($coverage);
        if ($alpha === 0) {
            return 127 << 24;
        }

        return ((127 - $alpha) << 24) | ((int) round($red / $coverage) << 16)
            | ((int) round($green / $coverage) << 8) | (int) round($blue / $coverage);
    }
}
