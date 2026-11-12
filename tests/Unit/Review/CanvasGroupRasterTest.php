<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Editor\Review\FieldStillRaster;
use Ichiloto\Editor\Review\SceneFrameRaster;
use Ichiloto\Editor\Review\StillLabelPreparation;
use Ichiloto\Editor\Review\TerminalPalette;
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
 * origin and layer, inside the group's clip and every clip around it.
 * Everything here is synthetic: solid fills, and one solid image.
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

/** One solid colour over a rectangle of its canvas. */
function canvasGroupFill(string $id, CanvasRectangle $area, array $rgb, int $layer = 0): CanvasComposite
{
    return new CanvasComposite($id, 1, 1, $area, [new CanvasCompositeOperation([
        'type' => 'fill', 'destination' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
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
            canvasGroupFromJson($group['canvas']), $group['layer'], isset($group['clipRect']) ? $rectangle($group['clipRect']) : null),
            $data['groups'] ?? []));
}

/** One of the Engine's full-frame canvas group fixtures, read back into the Engine's model whole. */
function readCanvasGroupFixture(string $name): PresentationCanvas
{
    $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/CanvasGroups/' . $name), true, flags: JSON_THROW_ON_ERROR);
    $canvas = canvasGroupFromJson($data);
    expect($canvas->toArray())->toEqual($data);

    return $canvas;
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
