<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use Closure;
use GdImage;

/**
 * How much of a composite operation the operation's masks let through at a pixel of the composite, as the
 * graphical renderer works it out: each mask's coverage at the pixel's centre, the coverages multiplied, and
 * the product held in one byte.
 *
 * The masks are the Engine's validated descriptors (`CanvasCompositeMask`), in the composite's own pixels:
 *
 * - `polygon`: the union of its contours, each closed and filled by the even-odd rule. Its coverage is read
 *   from the signed distance to the nearest edge of the contour that holds the point deepest, positive inside.
 * - `ellipse`: the signed distance along the ray from its centre through the point, which is the true
 *   distance for a circle.
 * - Both ramp that distance: with no feather over the one pixel the edge crosses, a half each side of it; with
 *   a feather from the edge inwards over the feather's length, eased by a smoothstep. `invert` turns the sign
 *   over before the ramp.
 * - `image_alpha`: the alpha of a picture, stretched over its destination from the whole picture or the
 *   normalised `source` crop of it, mirrored inside that crop by `flipX` and `flipY`, sampled between its four
 *   nearest texels and never beyond the crop, and cut to the destination's own rectangle. `invert` lets
 *   through what the picture does not cover.
 *
 * It is a review picture's mask: a picture's alpha has GD's 7 bits.
 */
final readonly class CanvasStillMask
{
    /**
     * @param list<array<string, mixed>> $masks Each mask as it is evaluated: a polygon or an ellipse as its
     * descriptor, an image mask with its decoded picture beside it.
     */
    private function __construct(private array $masks)
    {
    }

    /**
     * The masks of one operation, ready to be asked about its pixels. Null when a picture an image mask reads
     * cannot be had: the operation then has no mask to be drawn through, and is left out by whoever asked.
     *
     * @param list<array<string, mixed>> $masks The operation's masks, as `CanvasCompositeOperation::$data` holds them; at least one.
     * @param Closure(string): ?GdImage $readPicture Decodes a picture of the project's assets by its path.
     */
    public static function prepare(array $masks, Closure $readPicture): ?self
    {
        $prepared = [];
        foreach ($masks as $mask) {
            if ($mask['type'] === 'image_alpha') {
                $picture = $readPicture($mask['asset']);
                if ($picture === null) {
                    return null;
                }
                $mask['picture'] = $picture;
                $mask['source'] ??= ['x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0];
            }
            $prepared[] = $mask;
        }

        return new self($prepared);
    }

    /** What the masks together let through at a point of the composite, 0 to 1 in steps of a 255th. */
    public function getCoverage(float $x, float $y): float
    {
        $coverage = 1.0;
        foreach ($this->masks as $mask) {
            $coverage *= match ($mask['type']) {
                'polygon' => self::rampDistance(self::measurePolygon($x, $y, $mask['contours']), $mask['feather'], $mask['invert']),
                'ellipse' => self::rampDistance(self::measureEllipse($x, $y, $mask['center'], $mask['radius']), $mask['feather'], $mask['invert']),
                'image_alpha' => self::readImageCoverage($x, $y, $mask),
            };
        }

        return round($coverage * 255) / 255;
    }

    /** A signed distance as coverage: over one pixel centred on the edge without a feather, or eased inwards over the feather. */
    private static function rampDistance(float $distance, float $feather, bool $invert): float
    {
        $distance = $invert ? -$distance : $distance;
        if ($feather === 0.0) {
            return min(1.0, max(0.0, $distance + 0.5));
        }
        $t = min(1.0, max(0.0, $distance / $feather));

        return $t * $t * (3 - 2 * $t);
    }

    /**
     * The signed distance from a point to a polygon: of the contours, the one that holds the point deepest.
     *
     * @param list<list<array{0: float, 1: float}>> $contours
     */
    private static function measurePolygon(float $x, float $y, array $contours): float
    {
        $deepest = -INF;
        foreach ($contours as $contour) {
            $inside = false;
            $nearest = INF;
            $count = count($contour);
            foreach ($contour as $index => [$fromX, $fromY]) {
                [$toX, $toY] = $contour[($index + 1) % $count];
                $nearest = min($nearest, self::measureSegmentSquared($x, $y, $fromX, $fromY, $toX, $toY));
                if (($fromY > $y) !== ($toY > $y) && $x < ($toX - $fromX) * ($y - $fromY) / ($toY - $fromY) + $fromX) {
                    $inside = ! $inside;
                }
            }
            $deepest = max($deepest, $inside ? sqrt($nearest) : -sqrt($nearest));
        }

        return $deepest;
    }

    /** The square of the distance from a point to a straight piece of a contour. */
    private static function measureSegmentSquared(float $x, float $y, float $fromX, float $fromY, float $toX, float $toY): float
    {
        $dx = $toX - $fromX;
        $dy = $toY - $fromY;
        $length = $dx * $dx + $dy * $dy;
        $along = $length > 0 ? min(1.0, max(0.0, (($x - $fromX) * $dx + ($y - $fromY) * $dy) / $length)) : 0.0;
        $offX = $x - $fromX - $along * $dx;
        $offY = $y - $fromY - $along * $dy;

        return $offX * $offX + $offY * $offY;
    }

    /**
     * The signed distance from a point to an ellipse along the ray from its centre, positive inside.
     *
     * @param array{0: float, 1: float} $center
     * @param array{0: float, 1: float} $radius
     */
    private static function measureEllipse(float $x, float $y, array $center, array $radius): float
    {
        $offX = $x - $center[0];
        $offY = $y - $center[1];
        $reach = hypot($offX / $radius[0], $offY / $radius[1]);

        return $reach > 0 ? (1 / $reach - 1) * hypot($offX, $offY) : min($radius[0], $radius[1]);
    }

    /**
     * What an image mask lets through at a point: its picture's alpha there, cut to its destination.
     *
     * @param array<string, mixed> $mask The mask's descriptor with its decoded `picture`.
     */
    private static function readImageCoverage(float $x, float $y, array $mask): float
    {
        ['x' => $left, 'y' => $top, 'width' => $width, 'height' => $height] = $mask['destination'];
        $u = ($x - $left) / $width;
        $v = ($y - $top) / $height;
        $alpha = self::sampleAlpha($mask['picture'], $mask['source'],
            ($mask['flipX'] ?? false) ? 1 - $u : $u, ($mask['flipY'] ?? false) ? 1 - $v : $v);
        // The pixel's own square against the destination's rectangle.
        $alpha *= min(1.0, max(0.0, min($left + $width, $x + 0.5) - max($left, $x - 0.5)))
            * min(1.0, max(0.0, min($top + $height, $y + 0.5) - max($top, $y - 0.5)));

        return $mask['invert'] ? 1 - $alpha : $alpha;
    }

    /**
     * A picture's alpha at a point of a normalised crop of it, between the four texels nearest the point. No
     * texel outside the crop is read, so a crop of a sheet never takes its neighbour's alpha.
     *
     * @param array{x: float, y: float, width: float, height: float} $source The crop, each value a fraction of the picture.
     */
    private static function sampleAlpha(GdImage $picture, array $source, float $u, float $v): float
    {
        $width = imagesx($picture);
        $height = imagesy($picture);
        $minX = (int) min($width - 1, max(0, floor($source['x'] * $width)));
        $minY = (int) min($height - 1, max(0, floor($source['y'] * $height)));
        $maxX = max($minX, (int) min($width, max(1, ceil(($source['x'] + $source['width']) * $width))) - 1);
        $maxY = max($minY, (int) min($height, max(1, ceil(($source['y'] + $source['height']) * $height))) - 1);
        $atX = min($maxX, max($minX, ($source['x'] + min(1.0, max(0.0, $u)) * $source['width']) * $width - 0.5));
        $atY = min($maxY, max($minY, ($source['y'] + min(1.0, max(0.0, $v)) * $source['height']) * $height - 0.5));
        $texelX = (int) floor($atX);
        $texelY = (int) floor($atY);
        $nextX = min($texelX + 1, $maxX);
        $nextY = min($texelY + 1, $maxY);
        $read = static fn(int $x, int $y): float => (127 - ((imagecolorat($picture, $x, $y) >> 24) & 127)) / 127;
        $mixX = $atX - $texelX;
        $upper = $read($texelX, $texelY) + ($read($nextX, $texelY) - $read($texelX, $texelY)) * $mixX;
        $lower = $read($texelX, $nextY) + ($read($nextX, $nextY) - $read($texelX, $nextY)) * $mixX;

        return $upper + ($lower - $upper) * ($atY - $texelY);
    }
}
