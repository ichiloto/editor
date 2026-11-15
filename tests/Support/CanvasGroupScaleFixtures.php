<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasColorTransform;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroupScale;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;

/**
 * The Engine's port fixtures for a canvas group's scale (`canvas_group_scale`), kept as their owner published
 * them in `Fixtures/CanvasGroupScale`: four cases its models and its fake retained transport composed, the
 * one-pixel-wide picture they draw, and the picture one of them must be drawn as. The Editor's CPU raster draws
 * the frames; nothing here is a game's art or a battle's layout.
 */

/** Each file of the fixture as the Engine published it, by its SHA-256. */
const CANVAS_GROUP_SCALE_FIXTURE_SHA256 = [
    'manifest.json' => 'ad1b4130a8a03286489032bb2d95816769a92ef7cbb893d1ff7bca08c5900ac1',
    'line-2x.json' => '01fa4ddb2bfe03b4c4cd304d225166161db5a51579c456d095dce286e925d9fb',
    'line-2x-expected.png' => 'c9dd938ab30a100423722c3b1192bce1da0b06582ca565d5301499056e4e03b6',
    'white-line.png' => '2fdc15b31c879988c8829d168cb6264588a087cf143a57e7443e531dac0b41d4',
    'fractional.json' => '763b1fdbbaa8488f768c17144e0a3d80017622c895dd1da15734a2f5f5f14f84',
    'nested-colour.json' => '60e4f0b2aaa95b37b0ef2378b0b28d928a202ea080a7e0514f281a5d05ccb340',
    'retained-changing-scale.json' => 'a82c61b53129afff7036e5b341c6298be5509a4fec92dc29b2b1adeba8f90569',
];

/** Why a group scale test is skipped: the Engine installed with the Editor predates the contract. */
const ENGINE_CANVAS_GROUP_SCALE_MISSING = 'The Engine in use has no canvas group scale; set ICHILOTO_ENGINE_SRC to one that has.';

/** Whether the Engine in use scales canvas groups. */
function hasEngineCanvasGroupScale(): bool
{
    return class_exists(CanvasGroupScale::class);
}

function getCanvasGroupScaleFixtureDirectory(): string
{
    return dirname(__DIR__) . '/Fixtures/CanvasGroupScale';
}

/** One of the fixture's JSON files as it holds it. */
function readCanvasGroupScaleFixture(string $name): array
{
    return json_decode((string) file_get_contents(getCanvasGroupScaleFixtureDirectory() . '/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * A canvas of the Engine's wire read into the Engine's model: images, and groups with their clip, colour
 * treatment and scale. Any other drawing in the wire is refused, so a frame never loses part of itself on the
 * way in, and the canvas read is proved to be the wire's own by writing it back.
 */
function createCanvasGroupScaleCanvas(array $wire): PresentationCanvas
{
    $rectangle = static fn(array $area): CanvasRectangle => new CanvasRectangle($area['x'], $area['y'], $area['width'], $area['height']);
    foreach (['indicators', 'textLayers', 'labels', 'composites'] as $kind) {
        if (($wire[$kind] ?? []) !== []) {
            throw new LogicException(sprintf('The group scale frames hold images and groups; this one also holds %s.', $kind));
        }
    }
    $canvas = new PresentationCanvas($wire['width'], $wire['height'], array_map(CanvasImage::fromArray(...), $wire['images'] ?? []),
        groups: array_map(static fn(array $group): CanvasGroup => new CanvasGroup($group['id'], $group['origin']['x'], $group['origin']['y'],
            createCanvasGroupScaleCanvas($group['canvas']), $group['layer'], isset($group['clipRect']) ? $rectangle($group['clipRect']) : null,
            isset($group['colorTransform']) ? new CanvasColorTransform($group['colorTransform']['matrix'], $group['colorTransform']['offset']) : null,
            isset($group['scale']) ? CanvasGroupScale::fromArray($group['scale']) : null),
            $wire['groups'] ?? []));
    if ($canvas->toArray() != $wire) {
        throw new LogicException('The group scale frame did not read into the Engine\'s model as its wire holds it.');
    }

    return $canvas;
}

/**
 * A canvas drawn by the CPU raster onto a surface nothing covers, with the fixture's directory as its assets:
 * each pixel keeps the colour and the coverage its drawings gave it.
 *
 * @return array{0: GdImage, 1: list<string>} The surface, and what the still left out.
 */
function drawCanvasGroupScaleSurface(PresentationCanvas $canvas, ?CanvasStillRaster $raster = null): array
{
    $raster ??= new CanvasStillRaster(getCanvasGroupScaleFixtureDirectory());
    $raster->prepareCanvases([$canvas]);
    $surface = imagecreatetruecolor($canvas->width, $canvas->height);
    imagealphablending($surface, false);
    imagefilledrectangle($surface, 0, 0, $canvas->width - 1, $canvas->height - 1, imagecolorallocatealpha($surface, 0, 0, 0, 127));
    imagealphablending($surface, true);
    imagesavealpha($surface, true);
    $raster->drawCanvas($surface, $canvas, 'canvas');

    return [$surface, $raster->diagnostics];
}

/** A pixel's red, green, blue and alpha, each of 255, as a PNG holds them. */
function readCanvasGroupScalePixel(GdImage $image, int $x, int $y): array
{
    $pixel = imagecolorat($image, $x, $y);

    return [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255, (int) round((127 - ($pixel >> 24)) * 255 / 127)];
}

/**
 * Where a surface is painted at all: the painted pixels' bounding rectangle, and whether they fill it.
 *
 * @return array{x: int, y: int, width: int, height: int, filled: bool}|null Null when nothing is painted.
 */
function findCanvasGroupScalePaint(GdImage $image, int $left = 0, int $top = 0, ?int $right = null, ?int $bottom = null): ?array
{
    [$minX, $minY, $maxX, $maxY, $count] = [PHP_INT_MAX, PHP_INT_MAX, 0, 0, 0];
    for ($y = $top; $y < ($bottom ?? imagesy($image)); $y++) {
        for ($x = $left; $x < ($right ?? imagesx($image)); $x++) {
            if (readCanvasGroupScalePixel($image, $x, $y)[3] !== 0) {
                [$minX, $minY, $maxX, $maxY] = [min($minX, $x), min($minY, $y), max($maxX, $x + 1), max($maxY, $y + 1)];
                $count++;
            }
        }
    }

    return $count === 0 ? null : ['x' => $minX, 'y' => $minY, 'width' => $maxX - $minX, 'height' => $maxY - $minY,
        'filled' => $count === ($maxX - $minX) * ($maxY - $minY)];
}

/**
 * The whole pixels a rectangle of full precision is drawn on once a clip and the surface cut it: each edge at
 * its nearest pixel, as the raster takes it.
 *
 * @param array{x: float, y: float, width: float, height: float} $rectangle
 * @return array{x: int, y: int, width: int, height: int, filled: bool}
 */
function roundCanvasGroupScaleArea(array $rectangle, int $width, int $height): array
{
    $left = max(0, (int) round($rectangle['x']));
    $top = max(0, (int) round($rectangle['y']));
    $right = min($width, (int) round($rectangle['x'] + $rectangle['width']));
    $bottom = min($height, (int) round($rectangle['y'] + $rectangle['height']));

    return ['x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top, 'filled' => true];
}
