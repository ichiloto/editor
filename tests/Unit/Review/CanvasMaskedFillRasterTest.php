<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroupScale;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageTint;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;

/**
 * A composite's fill may be drawn through masks: polygons, ellipses and the alpha of a picture, each feathered,
 * inverted, cropped or mirrored as the Engine's composite contract allows, their coverages multiplied. The
 * ordinary hit tint on a battler is such a fill (`CanvasImageTint`). The CPU raster draws them as the graphical
 * renderer works a mask out: at each pixel's centre, a hard edge ramped over the one pixel it crosses, a feather
 * eased inwards, the product held in one byte. Every operation is built through the Engine's own composite
 * model, every picture is synthetic, and every expected value is worked from the contract by hand: a coverage
 * `c` of 0..1 is the mask byte `round(255 c)`, and a pixel's coverage of GD's 127 steps is
 * `round(127 * opacity * byte / 255)`.
 */
const MASKED_FILL_RED = [230, 20, 10];

/** A throwaway asset root with one picture: an L of opaque texels in a clear 4 by 4, the column x = 0 and the texel (1, 0). */
function createMaskedFillAssets(): string
{
    $root = reserveTemporaryPath('ichiloto-masked-fill-');
    mkdir($root . '/Art', 0o777, true);
    writeMaskedFillPicture($root . '/Art/corner.png', 4, 4, static fn(int $x, int $y): bool => $x === 0 || ($x === 1 && $y === 0));

    return $root;
}

/** A picture of opaque texels of one colour where the callback says, clear everywhere else. */
function writeMaskedFillPicture(string $path, int $width, int $height, Closure $isOpaque, array $rgb = [200, 40, 40]): void
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            imagesetpixel($image, $x, $y, $isOpaque($x, $y) ? (int) imagecolorallocate($image, ...$rgb) : (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        }
    }
    imagepng($image, $path);
}

/** A fill of the whole of a composite of the given size in one solid colour, through masks, as the Engine validates it. */
function createMaskedFill(int $width, int $height, array $masks, float $opacity = 1, ?array $brush = null): CanvasComposite
{
    return new CanvasComposite('masked', $width, $height, new CanvasRectangle(0, 0, $width, $height), [new CanvasCompositeOperation([
        'type' => 'fill', 'destination' => ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height], 'opacity' => $opacity,
        'brush' => $brush ?? ['type' => 'solid', 'color' => PresentationColor::rgb(...MASKED_FILL_RED)->toArray()], 'masks' => $masks,
    ])]);
}

/**
 * A canvas drawn onto a surface nothing covers: each pixel's coverage of GD's 127 steps, row by row, with what
 * the still left out. Every painted pixel must have the colour given.
 *
 * @return array{0: list<list<int>>, 1: list<string>}
 */
function drawMaskedFillCoverage(PresentationCanvas $canvas, string $assets, array $rgb = MASKED_FILL_RED): array
{
    $raster = new CanvasStillRaster($assets);
    $raster->prepareCanvases([$canvas]);
    $surface = imagecreatetruecolor($canvas->width, $canvas->height);
    imagealphablending($surface, false);
    imagefilledrectangle($surface, 0, 0, $canvas->width - 1, $canvas->height - 1, imagecolorallocatealpha($surface, 0, 0, 0, 127));
    imagealphablending($surface, true);
    $raster->drawCanvas($surface, $canvas, 'canvas');
    $rows = [];
    for ($y = 0; $y < $canvas->height; $y++) {
        for ($x = 0; $x < $canvas->width; $x++) {
            $pixel = imagecolorat($surface, $x, $y);
            $rows[$y][$x] = 127 - ($pixel >> 24);
            if ($rows[$y][$x] > 0 && [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255] !== $rgb) {
                throw new LogicException(sprintf('Pixel %d, %d is painted in another colour than the fill\'s.', $x, $y));
            }
        }
    }

    return [$rows, $raster->diagnostics];
}

/** The coverage of a masked fill of a composite of the given size, drawn alone. */
function drawMaskedFill(int $width, int $height, array $masks, ?string $assets = null, float $opacity = 1): array
{
    [$rows, $diagnostics] = drawMaskedFillCoverage(new PresentationCanvas($width, $height, composites: [createMaskedFill($width, $height, $masks, $opacity)]),
        $assets ?? sys_get_temp_dir());
    expect($diagnostics)->toBe([]);

    return $rows;
}

/** Rows of coverage written as a picture of characters: `#` whole, `.` none, `+` anything between. */
function sketchMaskedFill(array $rows): array
{
    return array_map(static fn(array $row): string => implode('', array_map(
        static fn(int $coverage): string => match ($coverage) { 127 => '#', 0 => '.', default => '+' }, $row)), $rows);
}

it('draws a fill through a polygon: whole inside its edges, nothing outside, and the other way about when it is inverted', function () {
    $square = ['type' => 'polygon', 'contours' => [[[2, 1], [6, 1], [6, 5], [2, 5]]]];

    expect(sketchMaskedFill(drawMaskedFill(8, 6, [$square])))->toBe([
        '........',
        '..####..',
        '..####..',
        '..####..',
        '..####..',
        '........',
    ])->and(sketchMaskedFill(drawMaskedFill(8, 6, [[...$square, 'invert' => true]])))->toBe([
        '########',
        '##....##',
        '##....##',
        '##....##',
        '##....##',
        '########',
    ]);
});

it('fills the union of a polygon\'s contours and ramps a slanted edge over the pixels it crosses', function () {
    $corners = ['type' => 'polygon', 'contours' => [[[0, 0], [3, 0], [3, 3], [0, 3]], [[5, 5], [8, 5], [8, 8], [5, 8]]]];
    $triangle = drawMaskedFill(8, 8, [['type' => 'polygon', 'contours' => [[[0, 0], [8, 0], [0, 8]]]]]);

    expect(sketchMaskedFill(drawMaskedFill(8, 8, [$corners])))->toBe([
        '###.....',
        '###.....',
        '###.....',
        '........',
        '........',
        '.....###',
        '.....###',
        '.....###',
    ])->and(sketchMaskedFill($triangle))->toBe([
        '#######+',
        '######+.',
        '#####+..',
        '####+...',
        '###+....',
        '##+.....',
        '#+......',
        '+.......',
    ]);
    // A centre on the edge is half covered: the byte 128 of 255, and 64 of GD's 127 steps.
    foreach (range(0, 7) as $y) {
        expect($triangle[$y][7 - $y])->toBe(64);
    }
});

it('eases a feathered edge inwards by a smoothstep over the feather\'s length, inverted or not', function () {
    // One straight edge at x = 2 with the polygon to its right; the others lie far outside the composite.
    $edge = ['type' => 'polygon', 'contours' => [[[2, -50], [62, -50], [62, 50], [2, 50]]], 'feather' => 4];

    // Centres at 0.5, 1.5, 2.5 and 3.5 pixels inside give t = 1/8, 3/8, 5/8 and 7/8 of the feather.
    expect(drawMaskedFill(8, 2, [$edge])[0])->toBe([0, 0, 5, 40, 87, 122, 127, 127])
        ->and(drawMaskedFill(8, 2, [[...$edge, 'invert' => true]])[1])->toBe([40, 5, 0, 0, 0, 0, 0, 0]);
});

it('draws a fill through an ellipse, measured along the ray from its centre', function () {
    $circle = drawMaskedFill(8, 8, [['type' => 'ellipse', 'center' => [4, 4], 'radius' => [3, 3]]]);
    $oval = drawMaskedFill(8, 8, [['type' => 'ellipse', 'center' => [4, 4], 'radius' => [3, 1.5]]]);

    expect(sketchMaskedFill($circle))->toBe([
        '........',
        '..++++..',
        '.+####+.',
        '.+####+.',
        '.+####+.',
        '.+####+.',
        '..++++..',
        '........',
    ])
        // A centre 2.55 from the circle's centre is 0.45 inside its edge: coverage 0.95, the byte 242.
        ->and([$circle[4][6], $circle[4][1], $circle[1][3], $circle[6][4]])->toBe([121, 121, 121, 121])
        ->and(sketchMaskedFill($oval))->toBe([
            '........',
            '........',
            '..++++..',
            '.+####+.',
            '.+####+.',
            '..++++..',
            '........',
            '........',
        ])
        // Along the ray, a centre at (2.5, 0.5) from the oval's centre is 0.291 inside: coverage 0.791, the byte 202.
        ->and([$oval[4][6], $oval[3][1]])->toBe([101, 101])
        ->and(sketchMaskedFill(drawMaskedFill(8, 8, [['type' => 'ellipse', 'center' => [4, 4], 'radius' => [3, 3], 'invert' => true]]))[3])->toBe('#+....+#');
});

it('draws a fill through a picture\'s alpha: whole, cropped, mirrored, stretched and inverted', function () {
    $assets = createMaskedFillAssets();
    $picture = ['type' => 'image_alpha', 'asset' => 'Art/corner.png', 'destination' => ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4]];
    $draw = static fn(array $mask, int $size = 4): array => drawMaskedFill($size, $size, [$mask], $assets);

    expect(sketchMaskedFill($draw($picture)))->toBe(['##..', '#...', '#...', '#...'])
        ->and(sketchMaskedFill($draw([...$picture, 'flipX' => true])))->toBe(['..##', '...#', '...#', '...#'])
        ->and(sketchMaskedFill($draw([...$picture, 'flipY' => true])))->toBe(['#...', '#...', '#...', '##..'])
        ->and(sketchMaskedFill($draw([...$picture, 'flipX' => true, 'flipY' => true])))->toBe(['...#', '...#', '...#', '..##'])
        ->and(sketchMaskedFill($draw([...$picture, 'invert' => true])))->toBe(['..##', '.###', '.###', '.###']);

    // The top-left quarter of the picture over the top-left quarter of the composite, a texel a pixel. Nothing
    // of the picture reaches beyond its destination; inverted, everything beyond it is let through.
    $quarter = [...$picture, 'source' => ['x' => 0, 'y' => 0, 'width' => 0.5, 'height' => 0.5], 'destination' => ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2]];
    expect(sketchMaskedFill($draw($quarter)))->toBe(['##..', '#...', '....', '....'])
        ->and(sketchMaskedFill($draw([...$quarter, 'invert' => true])))->toBe(['..##', '.###', '####', '####'])
        // The mirror turns the crop over inside itself: its clear texel (1, 1) comes to the left.
        ->and(sketchMaskedFill($draw([...$quarter, 'flipX' => true])))->toBe(['##..', '.#..', '....', '....']);

    // Twice its size, the alpha is taken between the texels: a quarter of the way from an opaque texel to a clear one is 0.75.
    $stretched = $draw([...$picture, 'destination' => ['x' => 0, 'y' => 0, 'width' => 8, 'height' => 8]], 8);
    expect(sketchMaskedFill($stretched)[0])->toBe('###++...')
        ->and([$stretched[0][3], $stretched[0][4]])->toBe([95, 32])
        // The right half of the picture is clear; a crop of the left half never reads it, so its last column stays whole.
        ->and(sketchMaskedFill($draw([...$picture, 'source' => ['x' => 0, 'y' => 0, 'width' => 0.25, 'height' => 1],
            'destination' => ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4]])))->toBe(['####', '####', '####', '####']);
});

it('multiplies the coverages of every mask of a fill with the fill\'s opacity, whatever the brush', function () {
    $assets = createMaskedFillAssets();
    $picture = ['type' => 'image_alpha', 'asset' => 'Art/corner.png', 'destination' => ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4]];
    $band = ['type' => 'polygon', 'contours' => [[[0, 1], [4, 1], [4, 3], [0, 3]]]];
    $disc = ['type' => 'ellipse', 'center' => [0, 2], 'radius' => [1, 1], 'feather' => 1];

    // The picture's column, cut to the band's two rows: 16 hundredths of 127 steps is 20.
    expect(drawMaskedFill(4, 4, [$picture, $band], $assets, 0.16))->toBe([[0, 0, 0, 0], [20, 0, 0, 0], [20, 0, 0, 0], [0, 0, 0, 0]])
        // A third mask eases those two pixels: 0.293 inside the disc is a smoothstep of 0.207, the byte 53.
        ->and(drawMaskedFill(4, 4, [$picture, $band, $disc], $assets))->toBe([[0, 0, 0, 0], [26, 0, 0, 0], [26, 0, 0, 0], [0, 0, 0, 0]])
        // Eight masks, the most a fill may have, are all applied.
        ->and(sketchMaskedFill(drawMaskedFill(4, 4, array_fill(0, 8, $band))))->toBe(['....', '####', '####', '....']);

    // A gradient is masked as a solid colour is: the same pixels, each in the gradient's own coverage there.
    $fade = ['type' => 'linear', 'start' => [0, 0], 'end' => [0, 4], 'stops' => [
        ['offset' => 0, 'color' => PresentationColor::rgb(...MASKED_FILL_RED)->toArray()],
        ['offset' => 1, 'color' => PresentationColor::rgb(...MASKED_FILL_RED)->toArray(), 'opacity' => 0],
    ]];
    [$rows, $diagnostics] = drawMaskedFillCoverage(new PresentationCanvas(4, 4, composites: [createMaskedFill(4, 4, [$band], brush: $fade)]), $assets);
    expect($diagnostics)->toBe([])
        // The gradient's alpha at the centres of rows 1 and 2 is 5/8 and 3/8.
        ->and($rows)->toBe([[0, 0, 0, 0], [79, 79, 79, 79], [48, 48, 48, 48], [0, 0, 0, 0]]);
});

it('draws the Engine\'s own hit tint: a fill through a battler\'s alpha, and through its clip when tints share a surface', function () {
    $assets = createMaskedFillAssets();
    // A body of 6 by 4 with a clear notch in its top-right corner, and a second whose left column is clear.
    writeMaskedFillPicture($assets . '/Art/body.png', 6, 4, static fn(int $x, int $y): bool => ! ($x >= 4 && $y === 0));
    writeMaskedFillPicture($assets . '/Art/other.png', 4, 4, static fn(int $x, int $y): bool => $x > 0);
    $white = PresentationColor::rgb(255, 255, 255);
    $body = new CanvasImage('body', 'Art/body.png', new CanvasRectangle(2, 1, 6, 4), 100, clipRect: new CanvasRectangle(3, 1, 4, 3));
    $other = new CanvasImage('other', 'Art/other.png', new CanvasRectangle(10, 2, 4, 4), 100, flipX: true);
    $tints = [CanvasImageTint::compose('command-tint-1', $body, $white, 0.16, $assets), CanvasImageTint::compose('command-tint-2', $other, $white, 0.16, $assets)];
    $shared = CanvasImageTint::combine('command-tints-layer-101', $tints);

    // What the Engine composed: one surface for the layer, each fill through its battler's alpha, the clipped one through its clip as well.
    expect($shared->layer)->toBe(101)
        ->and(array_map(static fn(CanvasCompositeOperation $operation): array => [$operation->data['type'], $operation->data['opacity'],
            array_column($operation->data['masks'], 'type')], $shared->operations))
        ->toBe([['fill', 0.16, ['image_alpha', 'polygon']], ['fill', 0.16, ['image_alpha']]])
        ->and($shared->operations[1]->data['masks'][0]['flipX'])->toBeTrue();

    [$rows, $diagnostics] = drawMaskedFillCoverage(new PresentationCanvas(16, 8, composites: [$shared]), $assets, [255, 255, 255]);
    expect($diagnostics)->toBe([])
        ->and(array_map(static fn(array $row): string => implode('', array_map(static fn(int $coverage): string => match ($coverage) { 20 => 't', 0 => '.' }, $row)), $rows))
        ->toBe([
            '................',
            // The body's clip keeps columns 3 to 6 and rows 1 to 3; its notch at columns 6 and 7 of row 1 stays clear.
            '...ttt..........',
            '...tttt...ttt...',
            // The other battler is mirrored: its clear column is on its right.
            '...tttt...ttt...',
            '..........ttt...',
            '..........ttt...',
            '................',
            '................',
        ]);

    // One tint alone keeps the surface and the clip the Engine gave it, and reads the same.
    [$alone] = drawMaskedFillCoverage(new PresentationCanvas(16, 8, composites: [CanvasImageTint::combine('command-tints-layer-101', [$tints[0]])]), $assets, [255, 255, 255]);
    expect(array_map(static fn(array $row): array => array_slice($row, 0, 8), $alone))->toBe(array_map(static fn(array $row): array => array_slice($row, 0, 8), $rows));

    // Over its battlers on a still, the tint lightens what it covers and nothing is left out.
    $raster = new CanvasStillRaster($assets);
    $still = $raster->rasterize(new PresentationCanvas(16, 8, [$body, $other], composites: [$shared]), 0x000000);
    $pixel = static fn(int $x, int $y): array => [(imagecolorat($still, $x, $y) >> 16) & 255, (imagecolorat($still, $x, $y) >> 8) & 255, imagecolorat($still, $x, $y) & 255];
    expect($raster->diagnostics)->toBe([])
        // 20 of 127 parts white over the body's (200, 40, 40), as GD blends them.
        ->and($pixel(4, 2))->toBe([208, 73, 73])
        ->and($pixel(11, 3))->toBe([208, 73, 73])
        ->and($pixel(13, 3))->toBe([0, 0, 0])
        ->and($pixel(0, 0))->toBe([0, 0, 0]);
});

it('stretches a masked fill with its composite, in a scaled group as anywhere', function () {
    $square = ['type' => 'polygon', 'contours' => [[[1, 1], [3, 1], [3, 3], [1, 3]]]];
    $child = new PresentationCanvas(4, 4, composites: [createMaskedFill(4, 4, [$square])]);
    [$rows, $diagnostics] = drawMaskedFillCoverage(new PresentationCanvas(8, 8, groups: [new CanvasGroup('scaled', 0, 0, $child,
        scale: new CanvasGroupScale(2, new PresentationSpritePivot(0, 0)))]), sys_get_temp_dir());

    expect($diagnostics)->toBe([])
        ->and(sketchMaskedFill($rows))->toBe(['........', '........', '..####..', '..####..', '..####..', '..####..', '........', '........']);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('leaves out a fill whose mask picture cannot be read and names the picture, drawing the rest of the composite', function () {
    $assets = createMaskedFillAssets();
    $missing = ['type' => 'image_alpha', 'asset' => 'Art/missing.png', 'destination' => ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4]];
    $whole = ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4];
    $brush = ['type' => 'solid', 'color' => PresentationColor::rgb(...MASKED_FILL_RED)->toArray()];
    $composite = new CanvasComposite('partly', 4, 4, new CanvasRectangle(0, 0, 4, 4), [
        new CanvasCompositeOperation(['type' => 'fill', 'destination' => ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 1], 'brush' => $brush]),
        new CanvasCompositeOperation(['type' => 'fill', 'destination' => $whole, 'brush' => $brush, 'masks' => [$missing]]),
    ]);
    [$rows, $diagnostics] = drawMaskedFillCoverage(new PresentationCanvas(4, 4, composites: [$composite]), $assets);

    expect(sketchMaskedFill($rows))->toBe(['####', '....', '....', '....'])
        ->and($diagnostics)->toBe(['The canvas mask image Art/missing.png cannot be read from the project\'s assets; what it draws is left out.']);
});

it('still names a composite it leaves out whole: one with an image, a stroke, or a fill blended by screen', function (array $operation) {
    $assets = createMaskedFillAssets();
    $brush = ['type' => 'solid', 'color' => PresentationColor::rgb(...MASKED_FILL_RED)->toArray()];
    $whole = ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4];
    $composite = new CanvasComposite('mixed', 4, 4, new CanvasRectangle(0, 0, 4, 4), [
        new CanvasCompositeOperation(['type' => 'fill', 'destination' => $whole, 'brush' => $brush]),
        new CanvasCompositeOperation([...$operation, ...(($operation['type'] === 'image') ? [] : ['brush' => $brush])]),
    ]);
    [$rows, $diagnostics] = drawMaskedFillCoverage(new PresentationCanvas(4, 4, composites: [$composite]), $assets);

    // Nothing of the composite is drawn, the fill it could draw included: the rest would be blended against the wrong picture.
    expect(sketchMaskedFill($rows))->toBe(['....', '....', '....', '....'])
        ->and($diagnostics)->toBe(['The canvas draws composites with operations this still leaves out.']);
})->with([
    'an image' => [['type' => 'image', 'asset' => 'Art/corner.png', 'destination' => ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4]]],
    'a stroke' => [['type' => 'stroke', 'points' => [[0, 0], [4, 4]], 'width' => 1]],
    'a screen fill' => [['type' => 'fill', 'blend' => 'screen', 'destination' => ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4]]],
    'a masked screen fill' => [['type' => 'fill', 'blend' => 'screen', 'destination' => ['x' => 0, 'y' => 0, 'width' => 4, 'height' => 4],
        'masks' => [['type' => 'ellipse', 'center' => [2, 2], 'radius' => [2, 2]]]]],
]);
