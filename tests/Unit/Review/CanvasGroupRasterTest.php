<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Editor\Review\FieldStillRaster;
use Ichiloto\Editor\Review\SceneFrameRaster;
use Ichiloto\Editor\Review\StillLabelPreparation;
use Ichiloto\Editor\Review\TerminalPalette;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasColorTransform;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasLabel;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposition;
use Ichiloto\Engine\Rendering\Presentation\Text\TextBounds;
use Ichiloto\Engine\Rendering\Presentation\Text\TextMeasurement;
use Ichiloto\Engine\Rendering\Presentation\Text\TextMetrics;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * A canvas group paints its local canvas as one drawing at the group's
 * origin and layer, inside the group's clip and every clip around it, and
 * in the group's colour treatment when it has one. Everything here is
 * synthetic: solid fills, and one solid image.
 */
const CANVAS_GROUP_RED = [230, 0, 0];
const CANVAS_GROUP_GREEN = [0, 200, 0];
const CANVAS_GROUP_BLUE = [0, 0, 220];
const CANVAS_GROUP_YELLOW = [240, 240, 0];

/** A throwaway asset root with one solid green 10 x 10 image. */
function canvasGroupAssets(): string
{
    $root = reserveTemporaryPath('ichiloto-canvas-group-');
    mkdir($root . '/Art', 0777, true);
    $image = imagecreatetruecolor(10, 10);
    imagefilledrectangle($image, 0, 0, 9, 9, imagecolorallocate($image, ...CANVAS_GROUP_GREEN));
    imagepng($image, $root . '/Art/green.png');

    return $root;
}

/** One solid colour over a rectangle of its canvas, covering it whole or by an opacity. */
function canvasGroupFill(string $id, CanvasRectangle $area, array $rgb, int $layer = 0, float $opacity = 1): CanvasComposite
{
    return new CanvasComposite($id, 1, 1, $area, [new CanvasCompositeOperation([
        'type' => 'fill', 'destination' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1], 'opacity' => $opacity,
        'brush' => ['type' => 'solid', 'color' => PresentationColor::rgb(...$rgb)->toArray()],
    ])], $layer);
}

/** A canvas filled with one solid colour. */
function canvasGroupDrawing(int $width, int $height, array $rgb): PresentationCanvas
{
    return new PresentationCanvas($width, $height, composites: [canvasGroupFill('fill', new CanvasRectangle(0, 0, $width, $height), $rgb)]);
}

function canvasGroupPixel(GdImage $image, int $x, int $y): array
{
    $pixel = imagecolorat($image, $x, $y);

    return [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255];
}

/**
 * Where an image shows any of the given colours: the pixels' bounding rectangle, and whether they fill it.
 *
 * @return array{x: int, y: int, width: int, height: int, filled: bool}|null Null when no pixel shows one.
 */
function canvasGroupArea(GdImage $image, array ...$colours): ?array
{
    $left = $top = PHP_INT_MAX;
    $right = $bottom = $count = 0;
    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            if (in_array(canvasGroupPixel($image, $x, $y), $colours, true)) {
                [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x + 1), max($bottom, $y + 1)];
                $count++;
            }
        }
    }

    return $count === 0 ? null : ['x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top,
        'filled' => $count === ($right - $left) * ($bottom - $top)];
}

function canvasGroupPng(GdImage $image): string
{
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** A still of a canvas over black, with nothing left out. */
function canvasGroupStill(PresentationCanvas $canvas, ?string $assets = null): GdImage
{
    $raster = new CanvasStillRaster($assets ?? sys_get_temp_dir());
    $image = $raster->rasterize($canvas, 0x000000);
    expect($raster->diagnostics)->toBe([]);

    return $image;
}

/**
 * A canvas of fills and groups from the Engine's canvas JSON, as the Engine's model wrote it. Any other
 * drawing in the JSON is refused, so a fixture never loses part of itself on the way in.
 */
function canvasGroupFromJson(array $data): PresentationCanvas
{
    $rectangle = static fn(array $area): CanvasRectangle => new CanvasRectangle($area['x'], $area['y'], $area['width'], $area['height']);
    foreach (['images', 'indicators', 'textLayers', 'labels'] as $kind) {
        if (($data[$kind] ?? []) !== []) {
            throw new LogicException(sprintf('The canvas group fixtures hold fills and groups; this one also holds %s.', $kind));
        }
    }

    return new PresentationCanvas($data['width'], $data['height'],
        composites: array_map(static fn(array $composite): CanvasComposite => new CanvasComposite($composite['id'], $composite['width'],
            $composite['height'], $rectangle($composite['destination']),
            array_map(static fn(array $operation): CanvasCompositeOperation => new CanvasCompositeOperation($operation), $composite['operations']),
            $composite['layer'], $composite['opacity'] ?? 1, isset($composite['clipRect']) ? $rectangle($composite['clipRect']) : null),
            $data['composites'] ?? []),
        groups: array_map(static fn(array $group): CanvasGroup => new CanvasGroup($group['id'], $group['origin']['x'], $group['origin']['y'],
            canvasGroupFromJson($group['canvas']), $group['layer'], isset($group['clipRect']) ? $rectangle($group['clipRect']) : null,
            isset($group['colorTransform']) ? new CanvasColorTransform($group['colorTransform']['matrix'], $group['colorTransform']['offset']) : null),
            $data['groups'] ?? []));
}

/** One of the Engine's canvas group fixtures as its JSON holds it. */
function readCanvasGroupFixtureData(string $name): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/CanvasGroups/' . $name), true, flags: JSON_THROW_ON_ERROR);
}

/** A canvas of the Engine's canvas group fixtures, read back into the Engine's model whole. */
function createCanvasGroupFixtureCanvas(array $data): PresentationCanvas
{
    $canvas = canvasGroupFromJson($data);
    expect($canvas->toArray())->toEqual($data);

    return $canvas;
}

/** One of the Engine's full-frame canvas group fixtures, read back into the Engine's model whole. */
function readCanvasGroupFixture(string $name): PresentationCanvas
{
    return createCanvasGroupFixtureCanvas(readCanvasGroupFixtureData($name));
}

/** A colour treatment that turns every channel over: black to white, red to cyan. */
function canvasGroupInversion(): CanvasColorTransform
{
    return new CanvasColorTransform([[-1, 0, 0], [0, -1, 0], [0, 0, -1]], [1, 1, 1]);
}

/** A colour treatment that halves every channel. */
function canvasGroupDimming(): CanvasColorTransform
{
    return new CanvasColorTransform([[0.5, 0, 0], [0, 0.5, 0], [0, 0, 0.5]], [0, 0, 0]);
}

/**
 * A canvas drawn onto a surface nothing covers, with nothing left out, so each pixel keeps the colour and the
 * coverage its drawings gave it: what a group's flattened drawing holds before it is blended onto anything.
 */
function canvasGroupSurface(PresentationCanvas $canvas): GdImage
{
    $surface = imagecreatetruecolor($canvas->width, $canvas->height);
    imagealphablending($surface, false);
    imagefilledrectangle($surface, 0, 0, $canvas->width - 1, $canvas->height - 1, imagecolorallocatealpha($surface, 0, 0, 0, 127));
    imagealphablending($surface, true);
    imagesavealpha($surface, true);
    $raster = new CanvasStillRaster(sys_get_temp_dir());
    $raster->drawCanvas($surface, $canvas, 'canvas');
    expect($raster->diagnostics)->toBe([]);

    return $surface;
}

/** A pixel's red, green and blue of 255, and its coverage of GD's 127 steps: 0 where nothing is painted. */
function canvasGroupSample(GdImage $image, int $x, int $y): array
{
    $pixel = imagecolorat($image, $x, $y);

    return [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255, 127 - ($pixel >> 24)];
}

/**
 * How far a pixel is from a colour and a coverage given in 0..1, as the Engine's references give them: the
 * largest difference of a channel, and the difference of the coverage.
 *
 * @return array{float, float}
 */
function measureCanvasGroupSample(GdImage $image, int $x, int $y, array $rgb, float $alpha): array
{
    [$red, $green, $blue, $coverage] = canvasGroupSample($image, $x, $y);

    return [max(abs($red / 255 - $rgb[0]), abs($green / 255 - $rgb[1]), abs($blue / 255 - $rgb[2])), abs($coverage / 127 - $alpha)];
}

/** A fixture's ANSI colour as the rasters show it. */
function canvasGroupAnsi(int $index): array
{
    $rgb = TerminalPalette::resolveDescriptor(['kind' => 'ansi16', 'index' => $index], 0);

    return [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
}

it('names canvas groups among the features every raster draws', function () {
    expect(CanvasStillRaster::supports(RendererSessionConfig::CANVAS_GROUPS))->toBeTrue()
        ->and(SceneFrameRaster::supports(RendererSessionConfig::CANVAS_GROUPS))->toBeTrue()
        ->and(FieldStillRaster::supports(RendererSessionConfig::CANVAS_GROUPS))->toBeTrue();
});

it('paints a group\'s canvas at the group\'s origin with the canvas\'s own geometry', function () {
    $assets = canvasGroupAssets();
    $child = new PresentationCanvas(20, 16,
        [new CanvasImage('art', 'Art/green.png', new CanvasRectangle(2, 2, 6, 6), 1)],
        [new CanvasIndicator('focus', 'art', CanvasIndicatorKind::UNDERLINE, new CanvasRectangle(2, 10, 6, 4), 2,
            PresentationColor::rgb(...CANVAS_GROUP_YELLOW), 2)],
        [new CanvasTextLayer('backing', 1, 10, 0, new RendererGridConfig(2, 1, 5, 4),
            [new PresentationTextRun(0, 0, '  ', background: PresentationColor::rgb(...CANVAS_GROUP_BLUE))])],
        [canvasGroupFill('ground', new CanvasRectangle(0, 0, 20, 16), CANVAS_GROUP_RED)]);
    $image = canvasGroupStill(new PresentationCanvas(60, 40, groups: [new CanvasGroup('placed', 12, 9, $child)]), $assets);

    expect(canvasGroupArea($image, CANVAS_GROUP_RED, CANVAS_GROUP_GREEN, CANVAS_GROUP_YELLOW, CANVAS_GROUP_BLUE))
        ->toBe(['x' => 12, 'y' => 9, 'width' => 20, 'height' => 16, 'filled' => true])
        ->and(canvasGroupArea($image, CANVAS_GROUP_GREEN))->toBe(['x' => 14, 'y' => 11, 'width' => 6, 'height' => 6, 'filled' => true])
        ->and(canvasGroupArea($image, CANVAS_GROUP_YELLOW))->toBe(['x' => 14, 'y' => 21, 'width' => 6, 'height' => 2, 'filled' => true])
        ->and(canvasGroupArea($image, CANVAS_GROUP_BLUE))->toBe(['x' => 22, 'y' => 9, 'width' => 10, 'height' => 4, 'filled' => true]);

    // Cut by a clip on its left and top, every kind of drawing keeps its place: only what the clip holds is shown.
    $cut = canvasGroupStill(new PresentationCanvas(60, 40, groups: [new CanvasGroup('placed', 12, 9, $child,
        clipRect: new CanvasRectangle(16, 12, 30, 20))]), $assets);
    expect(canvasGroupArea($cut, CANVAS_GROUP_RED, CANVAS_GROUP_GREEN, CANVAS_GROUP_YELLOW, CANVAS_GROUP_BLUE))
        ->toBe(['x' => 16, 'y' => 12, 'width' => 16, 'height' => 13, 'filled' => true])
        ->and(canvasGroupArea($cut, CANVAS_GROUP_GREEN))->toBe(['x' => 16, 'y' => 12, 'width' => 4, 'height' => 5, 'filled' => true])
        ->and(canvasGroupArea($cut, CANVAS_GROUP_YELLOW))->toBe(['x' => 16, 'y' => 21, 'width' => 4, 'height' => 2, 'filled' => true])
        ->and(canvasGroupArea($cut, CANVAS_GROUP_BLUE))->toBe(['x' => 22, 'y' => 12, 'width' => 10, 'height' => 1, 'filled' => true]);
});

it('keeps a fractional or negative origin where it is, showing only what reaches the surface', function () {
    // Four blue columns, then six red: an origin moved into view would show blue.
    $child = new PresentationCanvas(10, 8, composites: [
        canvasGroupFill('left', new CanvasRectangle(0, 0, 4, 8), CANVAS_GROUP_BLUE),
        canvasGroupFill('right', new CanvasRectangle(4, 0, 6, 8), CANVAS_GROUP_RED),
    ]);
    $image = canvasGroupStill(new PresentationCanvas(30, 20, groups: [new CanvasGroup('offset', -4.25, 3.5, $child)]));

    expect(canvasGroupArea($image, CANVAS_GROUP_BLUE))->toBeNull()
        ->and(canvasGroupArea($image, CANVAS_GROUP_RED))->toBe(['x' => 0, 'y' => 4, 'width' => 6, 'height' => 8, 'filled' => true]);
});

it('moves a drawing equally for equal steps of its origin on both sides of zero', function (float $origin, int $right) {
    $area = canvasGroupArea(canvasGroupStill(new PresentationCanvas(12, 4, groups: [new CanvasGroup('stepped', $origin, 0,
        canvasGroupDrawing(8, 4, CANVAS_GROUP_RED))])), CANVAS_GROUP_RED);

    expect([$area['x'] + $area['width'], $area['filled']])->toBe([$right, true]);
})->with([[-1.5, 7], [-0.5, 8], [0.5, 9], [1.5, 10]]);

it('draws nothing of a group whose origin puts it off the surface', function (float $x, float $y) {
    $ground = canvasGroupFill('ground', new CanvasRectangle(0, 0, 30, 20), CANVAS_GROUP_GREEN);
    $with = canvasGroupStill(new PresentationCanvas(30, 20, composites: [$ground],
        groups: [new CanvasGroup('away', $x, $y, canvasGroupDrawing(10, 8, CANVAS_GROUP_RED), 5)]));

    expect(canvasGroupPng($with))->toBe(canvasGroupPng(canvasGroupStill(new PresentationCanvas(30, 20, composites: [$ground]))));
})->with([[-10.0, 0.0], [-500.25, 3.0], [30.0, 0.0], [0.0, -8.0], [4.0, 20.0], [9000.0, -9000.0]]);

it('clips a group to its clip rectangle and to its own canvas', function () {
    $clipped = canvasGroupStill(new PresentationCanvas(40, 40, groups: [new CanvasGroup('clipped', 5, 5,
        canvasGroupDrawing(20, 20, CANVAS_GROUP_RED), clipRect: new CanvasRectangle(10, 8, 6, 4))]));
    // A clip wider than the group's canvas shows no more than the canvas.
    $bounded = canvasGroupStill(new PresentationCanvas(40, 40, groups: [new CanvasGroup('bounded', 5, 5,
        canvasGroupDrawing(20, 20, CANVAS_GROUP_RED), clipRect: new CanvasRectangle(0, 10, 40, 30))]));
    // A group reaching past its parent's canvas ends at that canvas, which draws nothing of its own here.
    $parent = new PresentationCanvas(20, 20, groups: [new CanvasGroup('inner', 10, 10, canvasGroupDrawing(20, 20, CANVAS_GROUP_RED))]);
    $nested = canvasGroupStill(new PresentationCanvas(40, 40, groups: [new CanvasGroup('outer', 5, 5, $parent)]));

    expect(canvasGroupArea($clipped, CANVAS_GROUP_RED))->toBe(['x' => 10, 'y' => 8, 'width' => 6, 'height' => 4, 'filled' => true])
        ->and(canvasGroupArea($bounded, CANVAS_GROUP_RED))->toBe(['x' => 5, 'y' => 10, 'width' => 20, 'height' => 15, 'filled' => true])
        ->and(canvasGroupArea($nested, CANVAS_GROUP_RED))->toBe(['x' => 15, 'y' => 15, 'width' => 10, 'height' => 10, 'filled' => true]);
});

it('intersects a nested group\'s clip with the clips of the groups around it', function () {
    $inner = new CanvasGroup('inner', -5, 5, canvasGroupDrawing(20, 20, CANVAS_GROUP_RED), 1, new CanvasRectangle(0, 0, 10, 20));
    $outer = new PresentationCanvas(20, 20, composites: [canvasGroupFill('ground', new CanvasRectangle(0, 0, 20, 20), CANVAS_GROUP_BLUE)],
        groups: [$inner]);
    $image = canvasGroupStill(new PresentationCanvas(40, 40, groups: [new CanvasGroup('outer', 10, 10, $outer,
        clipRect: new CanvasRectangle(12, 12, 20, 10))]));

    // The outer group shows x 12..30, y 12..22; the inner one, x 10..20, y 15..30 of the root, shows only inside that.
    expect(canvasGroupArea($image, CANVAS_GROUP_RED, CANVAS_GROUP_BLUE))->toBe(['x' => 12, 'y' => 12, 'width' => 18, 'height' => 10, 'filled' => true])
        ->and(canvasGroupArea($image, CANVAS_GROUP_RED))->toBe(['x' => 12, 'y' => 15, 'width' => 8, 'height' => 7, 'filled' => true]);
});

it('paints a group after the images, fills and text of its own layer, under a higher layer and over a lower one', function () {
    $assets = canvasGroupAssets();
    // Nine drawn groups share the frame's drawing limits, so their one canvas is a text backing rather than a fill.
    $red = new PresentationCanvas(10, 10, textLayers: [new CanvasTextLayer('backing', 0, 0, 0, new RendererGridConfig(1, 1, 10, 10),
        [new PresentationTextRun(0, 0, ' ', background: PresentationColor::rgb(...CANVAS_GROUP_RED))])]);
    // One row for each kind of drawing at layer 3; across it a group below (2), level with (3) and above (4) that layer.
    $groups = [];
    foreach ([0, 10, 20] as $row => $top) {
        foreach ([4, 3, 2] as $column => $layer) {
            $groups[] = new CanvasGroup("row-{$row}-layer-{$layer}", (2 - $column) * 10, $top, $red, $layer);
        }
    }
    $image = canvasGroupStill(new PresentationCanvas(30, 30,
        [new CanvasImage('art', 'Art/green.png', new CanvasRectangle(0, 0, 30, 10), 3)],
        textLayers: [new CanvasTextLayer('backing', 3, 0, 10, new RendererGridConfig(3, 1, 10, 10),
            [new PresentationTextRun(0, 0, '   ', background: PresentationColor::rgb(...CANVAS_GROUP_BLUE))])],
        composites: [canvasGroupFill('fill', new CanvasRectangle(0, 20, 30, 10), CANVAS_GROUP_YELLOW, 3)],
        groups: $groups), $assets);

    foreach ([[5, CANVAS_GROUP_GREEN], [15, CANVAS_GROUP_BLUE], [25, CANVAS_GROUP_YELLOW]] as [$y, $drawing]) {
        expect([canvasGroupPixel($image, 5, $y), canvasGroupPixel($image, 15, $y), canvasGroupPixel($image, 25, $y)])
            ->toBe([$drawing, CANVAS_GROUP_RED, CANVAS_GROUP_RED]);
    }
});

it('paints groups of one layer in the order listed, and each group whole at its own layer', function () {
    $first = new CanvasGroup('first', 0, 0, canvasGroupDrawing(20, 10, CANVAS_GROUP_RED), 2);
    $second = new CanvasGroup('second', 10, 0, canvasGroupDrawing(20, 10, CANVAS_GROUP_BLUE), 2);
    // Inside a group its own layers order its drawings; none of them reaches past the group's layer in the parent.
    $child = new PresentationCanvas(10, 10, composites: [
        canvasGroupFill('high', new CanvasRectangle(0, 0, 10, 5), CANVAS_GROUP_YELLOW, 100),
        canvasGroupFill('low', new CanvasRectangle(0, 0, 10, 10), CANVAS_GROUP_RED, -100),
    ]);
    $cover = canvasGroupFill('cover', new CanvasRectangle(0, 0, 5, 10), CANVAS_GROUP_GREEN, 1);
    $whole = canvasGroupStill(new PresentationCanvas(10, 10, composites: [$cover], groups: [new CanvasGroup('whole', 0, 0, $child)]));

    expect(canvasGroupPixel(canvasGroupStill(new PresentationCanvas(30, 10, groups: [$first, $second])), 15, 5))->toBe(CANVAS_GROUP_BLUE)
        ->and(canvasGroupPixel(canvasGroupStill(new PresentationCanvas(30, 10, groups: [$second, $first])), 15, 5))->toBe(CANVAS_GROUP_RED)
        ->and([canvasGroupPixel($whole, 2, 2), canvasGroupPixel($whole, 2, 8), canvasGroupPixel($whole, 8, 2), canvasGroupPixel($whole, 8, 8)])
        ->toBe([CANVAS_GROUP_GREEN, CANVAS_GROUP_GREEN, CANVAS_GROUP_YELLOW, CANVAS_GROUP_RED]);
});

it('draws one local canvas wherever each group places it, and groups nested as deep as the Engine allows', function () {
    $drawing = canvasGroupDrawing(4, 4, CANVAS_GROUP_RED);
    $twice = canvasGroupStill(new PresentationCanvas(20, 10, groups: [new CanvasGroup('a', 1, 1, $drawing), new CanvasGroup('b', 12, 5, $drawing)]));
    $nested = $drawing;
    for ($level = 0; $level < CanvasGroup::MAX_DEPTH; $level++) {
        $nested = new PresentationCanvas(20, 20, groups: [new CanvasGroup('level', 1, 2, $nested)]);
    }

    expect([canvasGroupPixel($twice, 1, 1), canvasGroupPixel($twice, 4, 4), canvasGroupPixel($twice, 12, 5), canvasGroupPixel($twice, 15, 8),
        canvasGroupPixel($twice, 8, 4)])->toBe([CANVAS_GROUP_RED, CANVAS_GROUP_RED, CANVAS_GROUP_RED, CANVAS_GROUP_RED, [0, 0, 0]])
        ->and(canvasGroupArea(canvasGroupStill($nested), CANVAS_GROUP_RED))->toBe(['x' => 8, 'y' => 16, 'width' => 4, 'height' => 4, 'filled' => true]);
});

it('draws a canvas the same on its own and as a group, and the same again after groups were drawn', function () {
    $assets = canvasGroupAssets();
    $plain = new PresentationCanvas(40, 30,
        [new CanvasImage('art', 'Art/green.png', new CanvasRectangle(3, 4, 21, 13), 2, clipRect: new CanvasRectangle(5, 4, 30, 10))],
        [new CanvasIndicator('focus', 'art', CanvasIndicatorKind::OUTLINE, new CanvasRectangle(2, 2, 30, 20), 2,
            PresentationColor::rgb(...CANVAS_GROUP_YELLOW), 3)],
        [new CanvasTextLayer('backing', 1, 20, 18, new RendererGridConfig(3, 2, 6, 5),
            [new PresentationTextRun(1, 0, '   ', background: PresentationColor::rgb(...CANVAS_GROUP_BLUE))])],
        [canvasGroupFill('ground', new CanvasRectangle(0, 0, 40, 30), CANVAS_GROUP_RED, -1)]);
    $raster = new CanvasStillRaster($assets);
    $before = canvasGroupPng($raster->rasterize($plain, 0x102030));
    $grouped = canvasGroupPng($raster->rasterize(new PresentationCanvas(40, 30, groups: [new CanvasGroup('same', 0, 0, $plain)]), 0x102030));
    $raster->rasterize(new PresentationCanvas(40, 30, groups: [new CanvasGroup('moved', -7.5, 11, $plain, clipRect: new CanvasRectangle(1, 2, 30, 20))]));

    expect($grouped)->toBe($before)
        ->and(canvasGroupPng($raster->rasterize($plain, 0x102030)))->toBe($before)
        ->and($raster->diagnostics)->toBe([]);
});

it('shows a label inside a group by the graphical renderer\'s raster of it, where the group places it', function () {
    $guard = StillLabelPreparation::RASTER_GUARD;
    $raster = imagecreatetruecolor(10 + 2 * $guard, 8 + 2 * $guard);
    imagefilledrectangle($raster, 0, 0, imagesx($raster) - 1, imagesy($raster) - 1, imagecolorallocate($raster, ...CANVAS_GROUP_YELLOW));
    $measurement = new TextMeasurement('synthetic-layout', new TextMetrics(8, 6, new TextBounds(0, 0, 8, 7), new TextBounds(0, 0, 9, 7),
        new TextBounds(0, 0, 10, 8)));
    $child = new PresentationCanvas(20, 12, labels: [new CanvasLabel('sign', $measurement, 4, 2)]);
    $canvases = new CanvasStillRaster(sys_get_temp_dir(), labelRasters: ['synthetic-layout' => canvasGroupPng($raster)]);
    $image = $canvases->rasterize(new PresentationCanvas(40, 30, groups: [new CanvasGroup('post', 6, 5, $child)]), 0x000000);

    expect(canvasGroupArea($image, CANVAS_GROUP_YELLOW))->toBe(['x' => 10, 'y' => 7, 'width' => 10, 'height' => 8, 'filled' => true])
        ->and($canvases->diagnostics)->toBe([]);
});

it('names the group whose canvas holds what a still leaves out', function () {
    $child = new PresentationCanvas(10, 10, [new CanvasImage('art', 'Art/absent.png', new CanvasRectangle(0, 0, 10, 10))],
        composites: [new CanvasComposite('shape', 10, 10, new CanvasRectangle(0, 0, 10, 10), [new CanvasCompositeOperation(
            ['type' => 'fill', 'blend' => 'screen', 'destination' => ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10],
                'brush' => ['type' => 'solid', 'color' => PresentationColor::rgb(1, 2, 3)->toArray()]])])]);
    $raster = new CanvasStillRaster(canvasGroupAssets());
    $raster->rasterize(new PresentationCanvas(20, 20, groups: [new CanvasGroup('field', 2, 2, $child)]));

    expect(implode("\n", $raster->diagnostics))->toContain('canvas group field')->toContain('Art/absent.png');
});

it('draws a frame\'s groups on the screen, from its canvas and its overlay, at the screen\'s scale', function () {
    $canvas = new PresentationCanvas(40, 40, composites: [canvasGroupFill('ground', new CanvasRectangle(0, 0, 40, 40), CANVAS_GROUP_GREEN)],
        groups: [new CanvasGroup('field', -5, 10, canvasGroupDrawing(20, 10, CANVAS_GROUP_RED), 1, new CanvasRectangle(0, 12, 40, 4))]);
    $overlay = new PresentationCanvas(40, 40, groups: [new CanvasGroup('battler', 30, 10, canvasGroupDrawing(6, 6, CANVAS_GROUP_BLUE))]);
    $raster = new SceneFrameRaster(sys_get_temp_dir());
    $native = $raster->rasterize(new SceneFrameComposition(null, canvas: $canvas, screenOverlay: $overlay), new RendererGridConfig(4, 2, 10, 20));
    // The same canvases on a screen twice their size: the groups take the screen's scale with their parent.
    $doubled = $raster->rasterize(new SceneFrameComposition(null, canvas: $canvas, screenOverlay: $overlay), new RendererGridConfig(8, 4, 10, 20));

    expect(canvasGroupArea($native, CANVAS_GROUP_RED))->toBe(['x' => 0, 'y' => 12, 'width' => 15, 'height' => 4, 'filled' => true])
        ->and(canvasGroupArea($native, CANVAS_GROUP_BLUE))->toBe(['x' => 30, 'y' => 10, 'width' => 6, 'height' => 6, 'filled' => true])
        ->and([imagesx($doubled), imagesy($doubled)])->toBe([80, 80])
        ->and([canvasGroupPixel($doubled, 14, 27), canvasGroupPixel($doubled, 66, 26), canvasGroupPixel($doubled, 40, 40)])
        ->toBe([CANVAS_GROUP_RED, CANVAS_GROUP_BLUE, CANVAS_GROUP_GREEN])
        ->and($raster->diagnostics)->toBe([]);
});

it('draws the Engine\'s clipped group fixture: the child only inside the clip, at its origin', function () {
    $image = canvasGroupStill(readCanvasGroupFixture('clipped-group.json'));
    [$child, $ground] = [canvasGroupAnsi(1), canvasGroupAnsi(2)];

    // The 80 x 40 child at (-10.5, 20.25) spans x -10.5..69.5, y 20.25..60.25; the clip keeps x 10..60, y 10..50.
    // A still has whole pixels, so the child's top edge at y 20.25 is drawn at y 20.
    expect(canvasGroupArea($image, $child))->toBe(['x' => 10, 'y' => 20, 'width' => 50, 'height' => 30, 'filled' => true])
        ->and(canvasGroupArea($image, $child, $ground))->toBe(['x' => 0, 'y' => 0, 'width' => 120, 'height' => 80, 'filled' => true]);
});

it('draws the Engine\'s nested groups fixture: each child inside every clip around it', function () {
    $image = canvasGroupStill(readCanvasGroupFixture('nested-groups.json'));
    [$inner, $ground, $outer] = [canvasGroupAnsi(1), canvasGroupAnsi(2), canvasGroupAnsi(4)];

    // The outer 70 x 50 child at (20, -5) inside its clip (10, 0, 70, 60) shows x 20..80, y 0..45. The inner
    // 40 x 30 child at (-12, 7) of the outer one, inside its clip (0, 0, 30, 35) there, shows x 20..48, y 2..30.
    expect(canvasGroupArea($image, $outer, $inner))->toBe(['x' => 20, 'y' => 0, 'width' => 60, 'height' => 45, 'filled' => true])
        ->and(canvasGroupArea($image, $inner))->toBe(['x' => 20, 'y' => 2, 'width' => 28, 'height' => 28, 'filled' => true])
        ->and(canvasGroupArea($image, $outer, $inner, $ground))->toBe(['x' => 0, 'y' => 0, 'width' => 120, 'height' => 80, 'filled' => true]);
});

/*
 * Group colour treatment. A treatment turns the straight colour of the group's flattened drawing through a
 * matrix and an offset, once, before the drawing is blended onto its parent; coverage is never changed.
 *
 * The stills hold 8 bits a channel and GD's 7 bits of coverage, and GD blends one drawing over another in whole
 * numbers, rounding its weights down. Against values worked out exactly, a channel is therefore within two of its
 * 255 steps and coverage within one of its 127; the hair added to each admits the arithmetic of the comparison.
 */
const CANVAS_GROUP_CHANNEL_TOLERANCE = 2 / 255 + 1.0e-9;
const CANVAS_GROUP_COVERAGE_TOLERANCE = 1 / 127 + 1.0e-9;

it('names group colour among the features every raster draws', function () {
    expect(CanvasStillRaster::supports(RendererSessionConfig::CANVAS_GROUP_COLOR))->toBeTrue()
        ->and(SceneFrameRaster::supports(RendererSessionConfig::CANVAS_GROUP_COLOR))->toBeTrue()
        ->and(FieldStillRaster::supports(RendererSessionConfig::CANVAS_GROUP_COLOR))->toBeTrue();
});

it('turns every channel of an inverted group over, each kind of drawing in it alike', function () {
    $assets = canvasGroupAssets();
    $child = new PresentationCanvas(20, 16,
        [new CanvasImage('art', 'Art/green.png', new CanvasRectangle(2, 2, 6, 6), 1)],
        [new CanvasIndicator('focus', 'art', CanvasIndicatorKind::UNDERLINE, new CanvasRectangle(2, 10, 6, 4), 2,
            PresentationColor::rgb(...CANVAS_GROUP_YELLOW), 2)],
        [new CanvasTextLayer('backing', 1, 10, 0, new RendererGridConfig(2, 1, 5, 4),
            [new PresentationTextRun(0, 0, '  ', background: PresentationColor::rgb(...CANVAS_GROUP_BLUE))])],
        [canvasGroupFill('ground', new CanvasRectangle(0, 0, 20, 16), CANVAS_GROUP_RED)]);
    $image = canvasGroupStill(new PresentationCanvas(60, 40, groups: [new CanvasGroup('placed', 12, 9, $child,
        colorTransform: canvasGroupInversion())]), $assets);
    [$red, $green, $yellow, $blue] = [[25, 255, 255], [255, 55, 255], [15, 15, 255], [255, 255, 35]];

    expect(canvasGroupArea($image, $red, $green, $yellow, $blue))->toBe(['x' => 12, 'y' => 9, 'width' => 20, 'height' => 16, 'filled' => true])
        ->and(canvasGroupArea($image, $green))->toBe(['x' => 14, 'y' => 11, 'width' => 6, 'height' => 6, 'filled' => true])
        ->and(canvasGroupArea($image, $yellow))->toBe(['x' => 14, 'y' => 21, 'width' => 6, 'height' => 2, 'filled' => true])
        ->and(canvasGroupArea($image, $blue))->toBe(['x' => 22, 'y' => 9, 'width' => 10, 'height' => 4, 'filled' => true])
        // Nothing keeps the colour it was drawn in, and nothing outside the group takes one.
        ->and(canvasGroupArea($image, CANVAS_GROUP_RED, CANVAS_GROUP_GREEN, CANVAS_GROUP_YELLOW, CANVAS_GROUP_BLUE))->toBeNull()
        ->and([canvasGroupPixel($image, 11, 9), canvasGroupPixel($image, 32, 24)])->toBe([[0, 0, 0], [0, 0, 0]]);
});

it('weighs the three channels into one grey and tints it, each output channel from its own matrix row', function () {
    // One weighting of red, green and blue (a quarter, a half, a quarter) at full, half and quarter strength.
    $tint = new CanvasColorTransform([[0.25, 0.5, 0.25], [0.125, 0.25, 0.125], [0.0625, 0.125, 0.0625]], [0, 0, 0]);
    $child = new PresentationCanvas(30, 10, composites: [
        canvasGroupFill('warm', new CanvasRectangle(0, 0, 10, 10), [200, 100, 48]),
        canvasGroupFill('green', new CanvasRectangle(10, 0, 10, 10), [0, 200, 0]),
        canvasGroupFill('white', new CanvasRectangle(20, 0, 10, 10), [255, 255, 255]),
    ]);
    $image = canvasGroupStill(new PresentationCanvas(30, 10, groups: [new CanvasGroup('tinted', 0, 0, $child, colorTransform: $tint)]));

    expect([canvasGroupPixel($image, 5, 5), canvasGroupPixel($image, 15, 5), canvasGroupPixel($image, 25, 5)])
        ->toBe([[112, 56, 28], [100, 50, 25], [255, 128, 64]]);
});

it('adds a treatment\'s offset to every painted pixel', function () {
    // Half of each channel, lifted by a quarter in red alone: black becomes a quarter red.
    $lift = new CanvasColorTransform([[0.5, 0, 0], [0, 0.5, 0], [0, 0, 0.5]], [0.25, 0, 0]);
    $child = new PresentationCanvas(20, 10, composites: [
        canvasGroupFill('black', new CanvasRectangle(0, 0, 10, 10), [0, 0, 0]),
        canvasGroupFill('grey', new CanvasRectangle(10, 0, 10, 10), [102, 204, 51]),
    ]);
    $image = canvasGroupStill(new PresentationCanvas(20, 10, groups: [new CanvasGroup('lifted', 0, 0, $child, colorTransform: $lift)]));

    // A quarter of 255 is 63.75, the nearest step 64; 51 + 63.75 is 114.75, the nearest step 115.
    expect([canvasGroupPixel($image, 5, 5), canvasGroupPixel($image, 15, 5)])->toBe([[64, 0, 0], [115, 102, 26]]);
});

it('treats a nested group by its own treatment first and by its parent\'s after', function () {
    $nest = static fn(array $rgb, CanvasColorTransform $inner, CanvasColorTransform $outer): GdImage => canvasGroupStill(
        new PresentationCanvas(30, 10, groups: [new CanvasGroup('parent', 0, 0, new PresentationCanvas(30, 10,
            // Beside the child, a drawing of the parent's own, which only the parent's treatment reaches.
            composites: [canvasGroupFill('own', new CanvasRectangle(20, 0, 10, 10), [200, 100, 50])],
            groups: [new CanvasGroup('child', 5, 0, canvasGroupDrawing(10, 10, $rgb), colorTransform: $inner)]), colorTransform: $outer)]));
    $invertedThenDimmed = $nest([1, 101, 255], canvasGroupInversion(), canvasGroupDimming());
    $dimmedThenInverted = $nest([0, 100, 254], canvasGroupDimming(), canvasGroupInversion());

    // Turned over then halved: (254, 154, 0) / 2. Halved then turned over: 255 - (0, 50, 127).
    expect(canvasGroupArea($invertedThenDimmed, [127, 77, 0]))->toBe(['x' => 5, 'y' => 0, 'width' => 10, 'height' => 10, 'filled' => true])
        ->and(canvasGroupPixel($invertedThenDimmed, 25, 5))->toBe([100, 50, 25])
        ->and(canvasGroupArea($dimmedThenInverted, [255, 205, 128]))->toBe(['x' => 5, 'y' => 0, 'width' => 10, 'height' => 10, 'filled' => true])
        ->and(canvasGroupPixel($dimmedThenInverted, 25, 5))->toBe([55, 155, 205])
        // What the parent's canvas leaves unpainted stays unpainted under an inversion.
        ->and(canvasGroupPixel($dimmedThenInverted, 2, 5))->toBe([0, 0, 0]);
});

it('treats drawings that overlap in part once, after they are blended, and keeps the coverage they had', function () {
    // Half-covering red, half-covering blue over its right half; in one composite, half-covering green over half-covering yellow.
    $child = new PresentationCanvas(40, 10, composites: [
        canvasGroupFill('red', new CanvasRectangle(0, 0, 16, 10), [255, 0, 0], 0, 0.5),
        canvasGroupFill('blue', new CanvasRectangle(8, 0, 16, 10), [0, 0, 255], 0, 0.5),
        new CanvasComposite('pair', 10, 10, new CanvasRectangle(30, 0, 10, 10), array_map(
            static fn(array $rgb, int $left): CanvasCompositeOperation => new CanvasCompositeOperation(['type' => 'fill', 'opacity' => 0.5,
                'destination' => ['x' => $left, 'y' => 0, 'width' => 10 - $left, 'height' => 10],
                'brush' => ['type' => 'solid', 'color' => PresentationColor::rgb(...$rgb)->toArray()]]),
            [CANVAS_GROUP_YELLOW, CANVAS_GROUP_GREEN], [0, 5])),
    ]);
    $plain = canvasGroupSurface(new PresentationCanvas(40, 10, groups: [new CanvasGroup('plain', 0, 0, $child)]));
    $treated = canvasGroupSurface(new PresentationCanvas(40, 10, groups: [new CanvasGroup('treated', 0, 0, $child,
        colorTransform: canvasGroupInversion())]));

    // Every pixel is the blended pixel turned over, with the blended pixel's coverage; an unpainted one is unpainted.
    $coverages = [];
    for ($x = 0; $x < 40; $x++) {
        [$red, $green, $blue, $coverage] = canvasGroupSample($plain, $x, 5);
        expect(canvasGroupSample($treated, $x, 5))->toBe($coverage === 0 ? [0, 0, 0, 0] : [255 - $red, 255 - $green, 255 - $blue, $coverage]);
        $coverages[$coverage] = true;
    }
    // Red alone, blue over red, blue alone and nothing; then yellow alone and green over yellow.
    expect(array_keys($coverages))->toHaveCount(3)
        ->and(max(measureCanvasGroupSample($treated, 4, 5, [0, 1, 1], 0.5)))->toBeLessThanOrEqual(CANVAS_GROUP_COVERAGE_TOLERANCE)
        ->and(measureCanvasGroupSample($treated, 12, 5, [2 / 3, 1, 1 / 3], 0.75)[0])->toBeLessThanOrEqual(CANVAS_GROUP_CHANNEL_TOLERANCE)
        ->and(measureCanvasGroupSample($treated, 12, 5, [2 / 3, 1, 1 / 3], 0.75)[1])->toBeLessThanOrEqual(CANVAS_GROUP_COVERAGE_TOLERANCE)
        ->and(canvasGroupSample($treated, 26, 5))->toBe([0, 0, 0, 0])
        ->and(measureCanvasGroupSample($treated, 37, 5, [1 - 80 / 255, 1 - 640 / 765, 1], 0.75)[0])->toBeLessThanOrEqual(CANVAS_GROUP_CHANNEL_TOLERANCE);
});

it('leaves an untreated sibling, and everything outside a treated group, as it is drawn without the treatment', function () {
    $scene = static fn(?CanvasColorTransform $treatment): GdImage => canvasGroupStill(new PresentationCanvas(40, 20,
        composites: [canvasGroupFill('ground', new CanvasRectangle(0, 0, 40, 20), CANVAS_GROUP_GREEN)],
        groups: [new CanvasGroup('treated', 4, 4, canvasGroupDrawing(10, 10, CANVAS_GROUP_RED), 1, colorTransform: $treatment),
            // A sibling under the treated group in part, and one over it.
            new CanvasGroup('under', 10, 2, canvasGroupDrawing(10, 6, CANVAS_GROUP_BLUE)),
            new CanvasGroup('over', 12, 10, canvasGroupDrawing(12, 8, CANVAS_GROUP_YELLOW), 2)]));
    $plain = $scene(null);
    $treated = $scene(canvasGroupInversion());

    $changed = [];
    for ($y = 0; $y < 20; $y++) {
        for ($x = 0; $x < 40; $x++) {
            if (imagecolorat($plain, $x, $y) !== imagecolorat($treated, $x, $y)) {
                $changed[] = [canvasGroupPixel($plain, $x, $y), canvasGroupPixel($treated, $x, $y)];
            }
        }
    }
    // Only where the treated group itself shows: its 10 x 10 less the 2 x 4 the sibling over it covers.
    expect(array_values(array_unique($changed, SORT_REGULAR)))->toBe([[CANVAS_GROUP_RED, [25, 255, 255]]])
        ->and($changed)->toHaveCount(92)
        ->and(canvasGroupArea($treated, CANVAS_GROUP_BLUE))->toBe(canvasGroupArea($plain, CANVAS_GROUP_BLUE))
        ->and(canvasGroupArea($treated, CANVAS_GROUP_YELLOW))->toBe(['x' => 12, 'y' => 10, 'width' => 12, 'height' => 8, 'filled' => true]);
});

it('treats a group where its origin and its clip leave it, on either side of the surface\'s edge', function () {
    // Four blue columns, then six red, half off the surface's left edge and cut by a clip above and below.
    $child = new PresentationCanvas(10, 8, composites: [
        canvasGroupFill('left', new CanvasRectangle(0, 0, 4, 8), CANVAS_GROUP_BLUE),
        canvasGroupFill('right', new CanvasRectangle(4, 0, 6, 8), CANVAS_GROUP_RED),
    ]);
    $ground = canvasGroupFill('ground', new CanvasRectangle(0, 0, 30, 20), CANVAS_GROUP_GREEN);
    $image = canvasGroupStill(new PresentationCanvas(30, 20, composites: [$ground], groups: [new CanvasGroup('offset', -4.25, 3.5, $child, 1,
        new CanvasRectangle(0, 6, 4, 3), canvasGroupInversion())]));

    // The child's red columns reach x 0..6, y 4..12; the clip keeps x 0..4, y 6..9. Its blue is off the surface.
    expect(canvasGroupArea($image, [25, 255, 255]))->toBe(['x' => 0, 'y' => 6, 'width' => 4, 'height' => 3, 'filled' => true])
        ->and(canvasGroupArea($image, [255, 255, 35], CANVAS_GROUP_RED, CANVAS_GROUP_BLUE))->toBeNull()
        ->and(canvasGroupArea($image, CANVAS_GROUP_GREEN, [25, 255, 255]))->toBe(['x' => 0, 'y' => 0, 'width' => 30, 'height' => 20, 'filled' => true]);
});

it('gives no colour to what a treated group leaves unpainted, whatever the treatment adds', function () {
    // A group painted over its left half alone, under treatments that would turn clear black white or red.
    $child = new PresentationCanvas(20, 10, composites: [canvasGroupFill('half', new CanvasRectangle(0, 0, 10, 10), CANVAS_GROUP_BLUE)]);
    foreach ([canvasGroupInversion(), new CanvasColorTransform([[0, 0, 0], [0, 0, 0], [0, 0, 0]], [1, 0, 0])] as $treatment) {
        $group = new CanvasGroup('half', 5, 5, $child, 1, colorTransform: $treatment);
        $surface = canvasGroupSurface(new PresentationCanvas(30, 20, groups: [$group]));
        $over = canvasGroupStill(new PresentationCanvas(30, 20, composites: [canvasGroupFill('ground', new CanvasRectangle(0, 0, 30, 20), CANVAS_GROUP_GREEN)],
            groups: [$group]));

        // On a clear surface the unpainted half is the pixel it was; over a ground the ground shows through it unchanged.
        expect(canvasGroupSample($surface, 10, 10)[3])->toBe(127)
            ->and(imagecolorat($surface, 20, 10))->toBe(imagecolorat($surface, 0, 0))
            ->and(canvasGroupSample($surface, 20, 10))->toBe([0, 0, 0, 0])
            ->and(canvasGroupArea($over, CANVAS_GROUP_GREEN))->toBe(['x' => 0, 'y' => 0, 'width' => 30, 'height' => 20, 'filled' => false])
            ->and(canvasGroupPixel($over, 20, 10))->toBe(CANVAS_GROUP_GREEN)
            ->and(canvasGroupPixel($over, 10, 10))->not->toBe(CANVAS_GROUP_GREEN);
    }
});

it('draws a group with no treatment, and one whose treatment changes nothing, to the same bytes as the canvas on its own', function () {
    $assets = canvasGroupAssets();
    $plain = new PresentationCanvas(40, 30,
        [new CanvasImage('art', 'Art/green.png', new CanvasRectangle(3, 4, 21, 13), 2, clipRect: new CanvasRectangle(5, 4, 30, 10))],
        [new CanvasIndicator('focus', 'art', CanvasIndicatorKind::OUTLINE, new CanvasRectangle(2, 2, 30, 20), 2,
            PresentationColor::rgb(...CANVAS_GROUP_YELLOW), 3)],
        [new CanvasTextLayer('backing', 1, 20, 18, new RendererGridConfig(3, 2, 6, 5),
            [new PresentationTextRun(1, 0, '   ', background: PresentationColor::rgb(...CANVAS_GROUP_BLUE))])],
        [canvasGroupFill('ground', new CanvasRectangle(0, 0, 40, 30), CANVAS_GROUP_RED, -1),
            canvasGroupFill('veil', new CanvasRectangle(10, 0, 20, 30), [10, 20, 30], 3, 0.4)]);
    $raster = new CanvasStillRaster($assets);
    $alone = canvasGroupPng($raster->rasterize($plain, 0x102030));
    $untreated = canvasGroupPng($raster->rasterize(new PresentationCanvas(40, 30, groups: [new CanvasGroup('same', 0, 0, $plain)]), 0x102030));
    $identity = canvasGroupPng($raster->rasterize(new PresentationCanvas(40, 30, groups: [new CanvasGroup('same', 0, 0, $plain,
        colorTransform: new CanvasColorTransform([[1, 0, 0], [0, 1, 0], [0, 0, 1]], [0, 0, 0]))]), 0x102030));
    // A treated group drawn in between changes nothing drawn after it.
    $raster->rasterize(new PresentationCanvas(40, 30, groups: [new CanvasGroup('turned', 0, 0, $plain, colorTransform: canvasGroupInversion())]));

    expect($untreated)->toBe($alone)
        ->and($identity)->toBe($alone)
        ->and(canvasGroupPng($raster->rasterize($plain, 0x102030)))->toBe($alone)
        ->and($raster->diagnostics)->toBe([]);
});

it('draws a frame\'s treated groups on the screen, from its canvas and its overlay, at the screen\'s scale', function () {
    $canvas = new PresentationCanvas(40, 40, composites: [canvasGroupFill('ground', new CanvasRectangle(0, 0, 40, 40), CANVAS_GROUP_GREEN)],
        groups: [new CanvasGroup('field', -5, 10, canvasGroupDrawing(20, 10, CANVAS_GROUP_RED), 1, new CanvasRectangle(0, 12, 40, 4),
            canvasGroupInversion())]);
    $overlay = new PresentationCanvas(40, 40, groups: [new CanvasGroup('battler', 30, 10, canvasGroupDrawing(6, 6, CANVAS_GROUP_BLUE),
        colorTransform: canvasGroupDimming())]);
    $raster = new SceneFrameRaster(sys_get_temp_dir());
    $native = $raster->rasterize(new SceneFrameComposition(null, canvas: $canvas, screenOverlay: $overlay), new RendererGridConfig(4, 2, 10, 20));
    $doubled = $raster->rasterize(new SceneFrameComposition(null, canvas: $canvas, screenOverlay: $overlay), new RendererGridConfig(8, 4, 10, 20));

    expect(canvasGroupArea($native, [25, 255, 255]))->toBe(['x' => 0, 'y' => 12, 'width' => 15, 'height' => 4, 'filled' => true])
        ->and(canvasGroupArea($native, [0, 0, 110]))->toBe(['x' => 30, 'y' => 10, 'width' => 6, 'height' => 6, 'filled' => true])
        ->and(canvasGroupArea($native, CANVAS_GROUP_RED, CANVAS_GROUP_BLUE))->toBeNull()
        ->and([canvasGroupPixel($doubled, 14, 27), canvasGroupPixel($doubled, 66, 26), canvasGroupPixel($doubled, 40, 40)])
        ->toBe([[25, 255, 255], [0, 0, 110], CANVAS_GROUP_GREEN])
        ->and($raster->diagnostics)->toBe([]);
});

it('draws the Engine\'s group colour fixture: its flattened drawing within the still\'s precision of the Engine\'s references', function () {
    $fixture = readCanvasGroupFixtureData('group-colour.json');
    $reference = $fixture['reference'];
    $surface = canvasGroupSurface(createCanvasGroupFixtureCanvas($fixture['canvas']));
    $measure = static fn(int $x, int $y, array $expected): array => measureCanvasGroupSample($surface, $x, $y, $expected['rgb'], $expected['alpha']);

    // The dimmed parent stands at (-2, 1) inside its clip (0, 0, 28, 16). Its inverted child's half-covering red spans
    // x -2..14 of the root and its half-covering blue x 6..22: red alone at x 0..6, blue over red at x 6..14.
    foreach ([[0, 1], [3, 8], [5, 15]] as [$x, $y]) {
        expect($measure($x, $y, $reference['childRedOnly'])[0])->toBeLessThanOrEqual(CANVAS_GROUP_CHANNEL_TOLERANCE)
            ->and($measure($x, $y, $reference['childRedOnly'])[1])->toBeLessThanOrEqual(CANVAS_GROUP_COVERAGE_TOLERANCE);
    }
    foreach ([[6, 1], [10, 8], [13, 15]] as [$x, $y]) {
        expect($measure($x, $y, $reference['childBlueOverRed'])[0])->toBeLessThanOrEqual(CANVAS_GROUP_CHANNEL_TOLERANCE)
            ->and($measure($x, $y, $reference['childBlueOverRed'])[1])->toBeLessThanOrEqual(CANVAS_GROUP_COVERAGE_TOLERANCE);
    }
    // The untreated sibling is exact where the group paints nothing over it, inside the group's clip and past it.
    foreach ([[23, 8], [27, 15], [28, 8], [31, 0], [16, 0]] as [$x, $y]) {
        expect($measure($x, $y, $reference['untreatedSibling']))->toEqual([0, 0]);
    }
    // Above the group's translated top edge nothing is painted, and nothing takes the treatment's colour.
    foreach ([[0, 0], [8, 0], [15, 0]] as [$x, $y]) {
        expect(canvasGroupSample($surface, $x, $y))->toBe([0, 0, 0, (int) (127 * $reference['transparentUnpainted']['alpha'])]);
    }
});
