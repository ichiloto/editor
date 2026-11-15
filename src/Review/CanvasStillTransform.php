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
 * A coordinate `c` of the canvas has its edge at the surface pixel `pixel + round(shift + factor * c)`. A
 * canvas no scale reaches lies on the surface's own pixels: its factor is 1, its shift is 0, and a group in it
 * moves by whole pixels, its origin taken to the nearest one, a half upwards on either side of zero, so equal
 * steps of an origin move the drawing equally and the drawings inside it keep their places beside each other.
 * A scaled group ({@see CanvasGroup::$scale}) takes its canvas off those pixels. From there on nothing is
 * taken to a whole pixel before the edge of a drawing is: the factors of nested groups multiply, their origins
 * and translations add in full precision, and each edge is rounded once, where it is drawn.
 */
final readonly class CanvasStillTransform
{
    private function __construct(private int $pixelX, private int $pixelY, public float $factor = 1.0,
        private float $shiftX = 0.0, private float $shiftY = 0.0)
    {
    }

    /** A canvas drawn at its own size with its corner on the surface's. */
    public static function createIdentity(): self
    {
        return new self(0, 0);
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

    /** A canvas whose corner is at a point of the canvas this one places, drawn there larger or smaller by a factor. */
    public function place(float $x, float $y, float $factor = 1.0): self
    {
        if ($factor === 1.0 && $this->factor === 1.0 && $this->shiftX === 0.0 && $this->shiftY === 0.0) {
            return new self($this->pixelX + (int) floor($x + 0.5), $this->pixelY + (int) floor($y + 0.5));
        }

        return new self($this->pixelX, $this->pixelY, $this->factor * $factor,
            $this->shiftX + $this->factor * $x, $this->shiftY + $this->factor * $y);
    }

    /** The same place read from another corner of the surface: a surface that holds the part from a pixel of this one on. */
    public function rebase(int $left, int $top): self
    {
        return new self($this->pixelX - $left, $this->pixelY - $top, $this->factor, $this->shiftX, $this->shiftY);
    }

    /** The surface pixel a horizontal coordinate of the canvas has its edge at. */
    public function getX(float $x): int
    {
        return $this->pixelX + (int) round($this->shiftX + $this->factor * $x);
    }

    /** The surface pixel a vertical coordinate of the canvas has its edge at. */
    public function getY(float $y): int
    {
        return $this->pixelY + (int) round($this->shiftY + $this->factor * $y);
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
