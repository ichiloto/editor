<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

/**
 * Where the canvas a still is drawing lies on the surface it is drawn into: the one account of a canvas's
 * place that every drawing of the canvas raster reads, so an image, a composite, an indicator, a text cell, a
 * label, a clip and a nested group all land by the same arithmetic.
 *
 * A canvas's place is held twice, and its edges are read from one of the two. The first is the Engine's own:
 * a coordinate `c` of the canvas lies at `shift + factor * c` of the outermost canvas, the factors of the
 * groups around it multiplied and their origins and translations added in full precision, whether or not any
 * of them is scaled ({@see CanvasGroup::projectRectangle()}). The second is in whole pixels: each group's
 * origin taken to the nearest one, a half upwards on either side of zero, so equal steps of an origin move the
 * drawing equally and the drawings inside it keep their places beside each other.
 *
 * A canvas no scale reaches lies on the surface's own pixels and is read in whole pixels: its edge is at
 * `pixel + round(c)`. A scaled group ({@see CanvasGroup::$scale}) takes its canvas off those pixels, and it and
 * everything in it are read from the Engine's place, at `surface + round(shift + factor * c)`: nothing on the
 * way down to them was taken to a whole pixel, the origins of the unscaled groups around the scaled one
 * included, and each edge is rounded once, where it is drawn ({@see getEdge()}).
 */
final readonly class CanvasStillTransform
{
    /**
     * @param int $surfaceX The surface pixel the outermost canvas has its corner at.
     * @param int $pixelX The surface pixel this canvas has its corner at while no scale reaches it.
     * @param float $factor How many pixels a unit of this canvas is.
     * @param float $shiftX Where this canvas has its corner in the outermost canvas, in full precision.
     * @param bool $scaled Whether a scaled group holds this canvas, directly or through other groups.
     */
    private function __construct(private int $surfaceX, private int $surfaceY, private int $pixelX, private int $pixelY,
        public float $factor = 1.0, private float $shiftX = 0.0, private float $shiftY = 0.0, private bool $scaled = false)
    {
    }

    /** A canvas drawn at its own size with its corner on the surface's. */
    public static function createIdentity(): self
    {
        return new self(0, 0, 0, 0);
    }

    /**
     * The canvas of a group of the canvas this one places. Where the group's corner goes and by how much its
     * canvas grows are read from the Engine's own projection of the group ({@see CanvasGroup::projectRectangle()}):
     * a point `p` of the group's canvas goes to `(x, y) + origin * size + factor * (p - origin * size)`, the
     * scale first and the group's translation after it.
     */
    public function nestGroup(CanvasGroup $group): self
    {
        $unit = $group->projectRectangle(new CanvasRectangle(0, 0, 1, 1));

        return $this->place($unit->x, $unit->y, $unit->width);
    }

    /**
     * A canvas whose corner is at a point of the canvas this one places, drawn there larger or smaller by a
     * factor. A factor of exactly one is no scale: the canvas is read as this one is.
     */
    public function place(float $x, float $y, float $factor = 1.0): self
    {
        return new self($this->surfaceX, $this->surfaceY, $this->pixelX + (int) floor($x + 0.5), $this->pixelY + (int) floor($y + 0.5),
            $this->factor * $factor, $this->shiftX + $this->factor * $x, $this->shiftY + $this->factor * $y,
            $this->scaled || $factor !== 1.0);
    }

    /** The same place read from another corner of the surface: a surface that holds the part from a pixel of this one on. */
    public function rebase(int $left, int $top): self
    {
        return new self($this->surfaceX - $left, $this->surfaceY - $top, $this->pixelX - $left, $this->pixelY - $top,
            $this->factor, $this->shiftX, $this->shiftY, $this->scaled);
    }

    /** The surface pixel a horizontal coordinate of the canvas has its edge at. */
    public function getX(float $x): int
    {
        return $this->getEdge($x, $this->pixelX, $this->surfaceX, $this->shiftX);
    }

    /** The surface pixel a vertical coordinate of the canvas has its edge at. */
    public function getY(float $y): int
    {
        return $this->getEdge($y, $this->pixelY, $this->surfaceY, $this->shiftY);
    }

    /**
     * The surface pixel a coordinate of the canvas has its edge at along one axis: the one place a coordinate
     * is taken to a pixel. Under a scale the Engine's place of it is rounded, whole; otherwise the coordinate
     * is rounded from the canvas's own pixel.
     */
    private function getEdge(float $coordinate, int $pixel, int $surface, float $shift): int
    {
        return $this->scaled ? $surface + (int) round($shift + $this->factor * $coordinate) : $pixel + (int) round($coordinate);
    }

    /**
     * The surface pixels a rectangle of the canvas has its edges at.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} Left, top, right and bottom; the right and bottom are the first pixels outside.
     */
    public function getEdges(CanvasRectangle $rectangle): array
    {
        return [$this->getX($rectangle->x), $this->getY($rectangle->y),
            $this->getX($rectangle->x + $rectangle->width), $this->getY($rectangle->y + $rectangle->height)];
    }

    /**
     * Where a length measured from a corner already on a surface pixel ends, in pixels from that corner: the
     * cells of a text grid are laid this way from the grid's corner, so neighbouring cells always meet.
     */
    public function getSpan(float $length): int
    {
        return (int) round($this->factor * $length);
    }
}
