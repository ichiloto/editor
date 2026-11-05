<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;

/** A throwaway asset root with one two-colour synthetic image: red left half, blue right half. */
function canvasStillAssets(): string
{
    $root = rememberTemporaryProject(sys_get_temp_dir() . '/ichiloto-canvas-still-' . bin2hex(random_bytes(5)));
    mkdir($root . '/Art', 0777, true);
    $image = imagecreatetruecolor(20, 10);
    imagefilledrectangle($image, 0, 0, 9, 9, imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 10, 0, 19, 9, imagecolorallocate($image, 0, 0, 255));
    imagepng($image, $root . '/Art/halves.png');

    return $root;
}

function canvasStillPixel(GdImage $image, int $x, int $y): array
{
    $pixel = imagecolorat($image, $x, $y);

    return [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255];
}

it('draws images cropped, stretched, mirrored and clipped in the renderer paint order', function () {
    $root = canvasStillAssets();
    $canvas = new PresentationCanvas(100, 50, [
        // A higher layer listed first still paints over a lower one.
        new CanvasImage('top', 'Art/halves.png', new CanvasRectangle(0, 0, 40, 20), 5, new SpriteSourceRect(10, 0, 10, 10)),
        new CanvasImage('under', 'Art/halves.png', new CanvasRectangle(0, 0, 100, 50), 1),
        new CanvasImage('mirrored', 'Art/halves.png', new CanvasRectangle(60, 30, 40, 20), 2, flipX: true,
            clipRect: new CanvasRectangle(60, 30, 20, 20)),
    ]);
    $raster = new CanvasStillRaster($root);
    $image = $raster->rasterize($canvas, 0x000000);
    expect(canvasStillPixel($image, 10, 10))->toBe([0, 0, 255])
        ->and(canvasStillPixel($image, 10, 40))->toBe([255, 0, 0])
        ->and(canvasStillPixel($image, 90, 10))->toBe([0, 0, 255])
        // Mirrored: blue now on the left, and the clip keeps the underlying image visible past x = 80.
        ->and(canvasStillPixel($image, 65, 40))->toBe([0, 0, 255])
        ->and(canvasStillPixel($image, 90, 40))->toBe([0, 0, 255])
        ->and($raster->diagnostics)->toBe([]);
});

it('paints text cell backgrounds and glyphs at the layer cell size, masked by the clip', function () {
    $root = canvasStillAssets();
    $green = PresentationColor::rgb(0, 200, 0);
    $white = PresentationColor::rgb(255, 255, 255);
    $canvas = new PresentationCanvas(80, 40, textLayers: [
        new CanvasTextLayer('fill', 0, 0, 0, new RendererGridConfig(4, 1, 20, 40), [new PresentationTextRun(0, 0, '    ', background: $green)],
            new CanvasRectangle(0, 0, 60, 40)),
        new CanvasTextLayer('word', 1, 0, 0, new RendererGridConfig(2, 1, 20, 40), [new PresentationTextRun(0, 1, 'W', $white)]),
    ]);
    $raster = new CanvasStillRaster($root);
    $image = $raster->rasterize($canvas, 0x101010);
    expect(canvasStillPixel($image, 5, 5))->toBe([0, 200, 0])
        // The clip ends the backing at x = 60.
        ->and(canvasStillPixel($image, 70, 5))->toBe([16, 16, 16]);
    $lit = 0;
    for ($y = 0; $y < 40; $y++) {
        for ($x = 20; $x < 40; $x++) {
            [$r, $g, $b] = canvasStillPixel($image, $x, $y);
            $lit += (int) ($r > 200 && $g > 200 && $b > 200);
        }
    }
    if (CanvasStillRaster::findSystemFont() !== null) {
        expect($lit)->toBeGreaterThan(10);
    } else {
        expect($raster->diagnostics)->not->toBeEmpty();
    }
});

it('strokes indicators inside their bounds and names what a still leaves out', function () {
    $root = canvasStillAssets();
    $canvas = new PresentationCanvas(60, 60,
        indicators: [new CanvasIndicator('focus', 'target', CanvasIndicatorKind::OUTLINE, new CanvasRectangle(10, 10, 40, 40), 3,
            PresentationColor::rgb(250, 250, 0), 4)],
        composites: [new CanvasComposite('shape', 10, 10, new CanvasRectangle(0, 0, 10, 10), [new CanvasCompositeOperation(
            ['type' => 'fill', 'destination' => ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10],
                'brush' => ['type' => 'solid', 'color' => ['kind' => 'rgb', 'r' => 1, 'g' => 2, 'b' => 3]]])])],
        images: [new CanvasImage('target', 'Art/missing.png', new CanvasRectangle(10, 10, 40, 40))]);
    $raster = new CanvasStillRaster($root);
    $image = $raster->rasterize($canvas, 0x000000);
    expect(canvasStillPixel($image, 11, 30))->toBe([250, 250, 0])
        ->and(canvasStillPixel($image, 30, 30))->toBe([0, 0, 0])
        ->and(canvasStillPixel($image, 30, 48))->toBe([250, 250, 0])
        ->and(implode("\n", $raster->diagnostics))->toContain('composites')->toContain('Art/missing.png');
});

it('keeps strongest dab coverage, preserves first ties and clips the retained surface', function () {
    $root = canvasStillAssets();
    $operation = new CanvasCompositeOperation([
        'type' => 'coverage_dabs', 'falloff' => 1, 'alphaSteps' => 100, 'minimumAlpha' => 1,
        'dabs' => [
            ['center' => [1.5, 2.5], 'radius' => 4, 'opacity' => 1],
            ['center' => [3.5, 2.5], 'radius' => 2, 'opacity' => .5],
        ],
        'bands' => [
            ['until' => .5, 'color' => PresentationColor::rgb(255, 0, 0)->toArray()],
            ['until' => 1, 'color' => PresentationColor::rgb(0, 0, 255)->toArray()],
        ],
    ]);
    $canvas = new PresentationCanvas(12, 10, composites: [
        new CanvasComposite('dabs', 6, 5, new CanvasRectangle(2, 3, 6, 5), [$operation],
            clipRect: new CanvasRectangle(3, 3, 4, 5)),
    ]);
    $raster = new CanvasStillRaster($root);
    $image = $raster->rasterize($canvas, 0x000000);
    expect(canvasStillPixel($image, 2, 5))->toBe([0, 0, 0])
        ->and(canvasStillPixel($image, 3, 5))->toBe([255, 0, 0])
        // Both dabs have .5 coverage here. The first dab's outer blue wins.
        ->and(canvasStillPixel($image, 5, 5)[0])->toBe(0)
        ->and(canvasStillPixel($image, 5, 5)[2])->toBeBetween(125, 130)
        ->and(canvasStillPixel($image, 7, 5))->toBe([0, 0, 0])
        ->and($raster->diagnostics)->toBe([]);
});

it('rotates around the authored pivot clockwise, with mirror before rotation and clip after it', function () {
    $raster = new CanvasStillRaster(canvasStillAssets());
    $part = new CanvasImage('turned', 'Art/halves.png', new CanvasRectangle(20, 10, 20, 10),
        clipRect: new CanvasRectangle(12, 10, 8, 16), flipX: true, rotation: 90,
        pivot: new PresentationSpritePivot(0, 0));
    $image = $raster->rasterize(new PresentationCanvas(50, 50, [$part]), 0x000000);
    expect(canvasStillPixel($image, 15, 15))->toBe([0, 0, 255])
        ->and(canvasStillPixel($image, 15, 24))->toBe([255, 0, 0])
        ->and(canvasStillPixel($image, 11, 15))->toBe([0, 0, 0])
        ->and(canvasStillPixel($image, 15, 26))->toBe([0, 0, 0])
        ->and(canvasStillPixel($image, 23, 15))->toBe([0, 0, 0])
        ->and($raster->diagnostics)->toBe([]);
});

it('resamples a rotated atlas crop without leaking neighbouring colour', function () {
    $raster = new CanvasStillRaster(canvasStillAssets());
    $part = new CanvasImage('diagonal', 'Art/halves.png', new CanvasRectangle(15, 15, 10, 10),
        sourceRect: new SpriteSourceRect(0, 0, 10, 10), brightness: .5, rotation: 45,
        pivot: new PresentationSpritePivot(.5, .5));
    $image = $raster->rasterize(new PresentationCanvas(40, 40, [$part]), 0x000000);
    expect(canvasStillPixel($image, 20, 20))->toBe([128, 0, 0])
        ->and(canvasStillPixel($image, 13, 13))->toBe([0, 0, 0])
        ->and($raster->diagnostics)->toBe([]);
    $edge = 0;
    for ($y = 12; $y < 28; $y++) {
        for ($x = 12; $x < 28; $x++) {
            [$red, $green, $blue] = canvasStillPixel($image, $x, $y);
            expect($green | $blue)->toBe(0);
            $edge += (int) ($red > 0 && $red < 128);
        }
    }
    expect($edge)->toBeGreaterThan(0);
});

it('paints one continuous texture strip with crop bounds and opacity', function () {
    $root = canvasStillAssets();
    $operation = new CanvasCompositeOperation([
        'type' => 'texture_strip', 'asset' => 'Art/halves.png', 'opacity' => .5,
        'sourceRect' => ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10],
        'geometry' => ['origin' => [1, 1], 'axis' => [1, 0], 'length' => 8, 'height' => 2,
            'knots' => [['u' => 0, 'offset' => 0], ['u' => .5, 'offset' => 2], ['u' => 1, 'offset' => 0]]],
    ]);
    $canvas = new PresentationCanvas(12, 8, composites: [
        new CanvasComposite('bent', 12, 8, new CanvasRectangle(0, 0, 12, 8), [$operation]),
    ]);
    $raster = new CanvasStillRaster($root);
    $image = $raster->rasterize($canvas, 0x000000);
    expect(canvasStillPixel($image, 1, 1)[0])->toBeBetween(125, 130)
        ->and(canvasStillPixel($image, 4, 1))->toBe([0, 0, 0])
        ->and(canvasStillPixel($image, 4, 3)[0])->toBeBetween(125, 130)
        ->and(canvasStillPixel($image, 5, 3)[0])->toBeBetween(125, 130)
        ->and(canvasStillPixel($image, 8, 3))->toBe([0, 0, 0])
        ->and(canvasStillPixel($image, 8, 1)[2])->toBe(0)
        ->and($raster->diagnostics)->toBe([]);
});
