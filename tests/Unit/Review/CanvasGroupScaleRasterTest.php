<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Editor\Review\CanvasStillTransform;
use Ichiloto\Editor\Review\FieldStillRaster;
use Ichiloto\Editor\Review\SceneFrameRaster;
use Ichiloto\Editor\Review\StillLabelPreparation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasColorTransform;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroupScale;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasLabel;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\Text\TextBounds;
use Ichiloto\Engine\Rendering\Presentation\Text\TextMeasurement;
use Ichiloto\Engine\Rendering\Presentation\Text\TextMetrics;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * A canvas group may be scaled about a point of its own canvas, its translation applied after the scale
 * (`canvas_group_scale`). The CPU raster draws everything the group holds at the place and size the scale gives
 * it and never enlarges a picture of the group's canvas, keeps the group's clip where the parent has it,
 * multiplies the scales of nested groups, and carries the scale to colour treatments, clips, text cells and
 * indicators alike. The four frames are the Engine's ({@see readCanvasGroupScaleFixture()}); the rest is
 * synthetic: solid fills and a one-pixel-wide white picture.
 */
const GROUP_SCALE_RED = [230, 0, 0];
const GROUP_SCALE_BLUE = [0, 0, 220];
const GROUP_SCALE_YELLOW = [240, 240, 0];
const GROUP_SCALE_GREEN = [0, 200, 0];

/** One solid colour over a rectangle of its canvas. */
function createGroupScaleFill(string $id, CanvasRectangle $area, array $rgb, int $layer = 0, ?CanvasRectangle $clip = null): CanvasComposite
{
    return new CanvasComposite($id, 1, 1, $area, [new CanvasCompositeOperation([
        'type' => 'fill', 'destination' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
        'brush' => ['type' => 'solid', 'color' => PresentationColor::rgb(...$rgb)->toArray()],
    ])], $layer, clipRect: $clip);
}

/**
 * Where a surface shows any of the given colours at full coverage: the pixels' bounding rectangle, and whether they fill it.
 *
 * @return array{x: int, y: int, width: int, height: int, filled: bool}|null Null when no pixel shows one.
 */
function findGroupScaleColour(GdImage $image, array ...$colours): ?array
{
    [$left, $top, $right, $bottom, $count] = [PHP_INT_MAX, PHP_INT_MAX, 0, 0, 0];
    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            $pixel = readCanvasGroupScalePixel($image, $x, $y);
            if ($pixel[3] === 255 && in_array(array_slice($pixel, 0, 3), $colours, true)) {
                [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x + 1), max($bottom, $y + 1)];
                $count++;
            }
        }
    }

    return $count === 0 ? null : ['x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top,
        'filled' => $count === ($right - $left) * ($bottom - $top)];
}

/** A scale about a point of the group's canvas, each coordinate of the point a fraction of the canvas. */
function createGroupScale(float $factor, float $originX = 0, float $originY = 0): CanvasGroupScale
{
    return new CanvasGroupScale($factor, new PresentationSpritePivot($originX, $originY));
}

/** The layout a synthetic label is measured as, and its raster is kept under. */
const GROUP_SCALE_LABEL_LAYOUT = 'group-scale-label';

/** A label whose paint bounds are an area of its canvas, drawn from the raster kept for {@see GROUP_SCALE_LABEL_LAYOUT}. */
function createGroupScaleLabel(CanvasRectangle $area, ?CanvasRectangle $clip = null): CanvasLabel
{
    $bounds = new TextBounds(0, 0, $area->width, $area->height);

    return new CanvasLabel('word', new TextMeasurement(GROUP_SCALE_LABEL_LAYOUT, new TextMetrics($area->width, $area->height, $bounds, $bounds, $bounds)),
        $area->x, $area->y, clipRect: $clip);
}

/**
 * A picture kept as the graphical renderer writes a label's raster: PNG bytes with the renderer's guard of clear
 * pixels on every side.
 */
function encodeGroupScaleLabel(GdImage $picture): string
{
    $guard = StillLabelPreparation::RASTER_GUARD;
    $guarded = imagecreatetruecolor(imagesx($picture) + 2 * $guard, imagesy($picture) + 2 * $guard);
    imagealphablending($guarded, false);
    imagesavealpha($guarded, true);
    imagefilledrectangle($guarded, 0, 0, imagesx($guarded) - 1, imagesy($guarded) - 1, imagecolorallocatealpha($guarded, 0, 0, 0, 127));
    imagecopy($guarded, $picture, $guard, $guard, 0, 0, imagesx($picture), imagesy($picture));
    ob_start();
    imagepng($guarded);

    return (string) ob_get_clean();
}

/** A raster that draws canvases with a label raster of its own: a picture, kept as {@see encodeGroupScaleLabel()} keeps it. */
function createGroupScaleLabelRaster(GdImage $picture): CanvasStillRaster
{
    return new CanvasStillRaster(getCanvasGroupScaleFixtureDirectory(), labelRasters: [GROUP_SCALE_LABEL_LAYOUT => encodeGroupScaleLabel($picture)]);
}

/** A surface of a size that nothing covers. */
function createGroupScaleSurface(int $width, int $height): GdImage
{
    $surface = imagecreatetruecolor($width, $height);
    imagealphablending($surface, false);
    imagefilledrectangle($surface, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($surface, 0, 0, 0, 127));
    imagealphablending($surface, true);
    imagesavealpha($surface, true);

    return $surface;
}

/**
 * A word in white on nothing, drawn by a font and cut to its ink: real glyphs with soft edges, and ink in the
 * first and last row and column of the picture.
 */
function drawGroupScaleWord(string $font, string $word): GdImage
{
    $sheet = createGroupScaleSurface(160, 48);
    imagettftext($sheet, 14, 0, 8, 34, imagecolorallocate($sheet, 255, 255, 255), $font, $word);
    $ink = findCanvasGroupScalePaint($sheet);
    $picture = imagecreatetruecolor($ink['width'], $ink['height']);
    imagealphablending($picture, false);
    imagesavealpha($picture, true);
    imagecopy($picture, $sheet, 0, 0, $ink['x'], $ink['y'], $ink['width'], $ink['height']);

    return $picture;
}

/**
 * What a still shows of a label alone on a clear surface: its raster, less the guard, sampled once to the pixels
 * the rounded edges of a rectangle enclose, inside the rounded edges of a clip when one is given.
 */
function composeGroupScaleLabel(string $bytes, CanvasRectangle $place, ?CanvasRectangle $clip, int $width, int $height): GdImage
{
    $guard = StillLabelPreparation::RASTER_GUARD;
    $raster = imagecreatefromstring($bytes);
    [$left, $top, $right, $bottom] = roundGroupScaleEdges($place);
    $sampled = imagecreatetruecolor($right - $left, $bottom - $top);
    imagealphablending($sampled, false);
    imagecopyresampled($sampled, $raster, 0, 0, $guard, $guard, $right - $left, $bottom - $top, imagesx($raster) - 2 * $guard, imagesy($raster) - 2 * $guard);
    [$clipLeft, $clipTop, $clipRight, $clipBottom] = $clip === null ? [0, 0, $width, $height] : roundGroupScaleEdges($clip);
    [$maskLeft, $maskTop, $maskRight, $maskBottom] = [max(0, $left, $clipLeft), max(0, $top, $clipTop), min($width, $right, $clipRight), min($height, $bottom, $clipBottom)];
    $surface = createGroupScaleSurface($width, $height);
    imagecopy($surface, $sampled, $maskLeft, $maskTop, $maskLeft - $left, $maskTop - $top, $maskRight - $maskLeft, $maskBottom - $maskTop);

    return $surface;
}

/** How many pixels of two surfaces of one size differ in colour or coverage. */
function countGroupScaleDifferences(GdImage $first, GdImage $second): int
{
    $different = 0;
    for ($y = 0; $y < imagesy($first); $y++) {
        for ($x = 0; $x < imagesx($first); $x++) {
            $different += imagecolorat($first, $x, $y) === imagecolorat($second, $x, $y) ? 0 : 1;
        }
    }

    return $different;
}

/** A picture of one opaque colour. */
function createGroupScalePicture(int $width, int $height, array $rgb): GdImage
{
    $picture = imagecreatetruecolor($width, $height);
    imagefilledrectangle($picture, 0, 0, $width - 1, $height - 1, imagecolorallocate($picture, ...$rgb));

    return $picture;
}

/**
 * A canvas of 40 by 40 with one white drawing over an area of it: the fixture's line as an image, a fill as a
 * composite, or a label, whose raster {@see createGroupScaleLabelRaster()} gives.
 */
function createGroupScaleDrawing(string $kind, CanvasRectangle $area): PresentationCanvas
{
    return match ($kind) {
        'image' => new PresentationCanvas(40, 40, [new CanvasImage('line', 'white-line.png', $area)]),
        'composite' => new PresentationCanvas(40, 40, composites: [createGroupScaleFill('fill', $area, [255, 255, 255])]),
        'label' => new PresentationCanvas(40, 40, labels: [createGroupScaleLabel($area)]),
    };
}

/**
 * Groups nested one in the next, the outermost first. Each link is a group's origin and the scale it has, a
 * factor and the point it scales about, or null for none. Every group but the innermost holds a canvas of 24 by
 * 24 with the next group in it; the innermost holds the leaf.
 *
 * @param list<array{0: float, 1: float, 2: array{0: float, 1?: float, 2?: float}|null}> $links
 * @return list<CanvasGroup>
 */
function nestGroupScaleGroups(array $links, PresentationCanvas $leaf): array
{
    $groups = [];
    foreach (array_reverse($links) as $depth => [$x, $y, $scale]) {
        array_unshift($groups, new CanvasGroup('level-' . (count($links) - $depth), $x, $y,
            $groups === [] ? $leaf : new PresentationCanvas(24, 24, groups: [$groups[0]]),
            scale: $scale === null ? null : createGroupScale(...$scale)));
    }

    return $groups;
}

/** Where the Engine has a rectangle of the innermost group's canvas in the canvas that holds the outermost: each group's own projection, the innermost first. */
function projectGroupScaleRectangle(array $groups, CanvasRectangle $rectangle): CanvasRectangle
{
    return array_reduce(array_reverse($groups), static fn(CanvasRectangle $projected, CanvasGroup $group): CanvasRectangle => $group->projectRectangle($projected), $rectangle);
}

/**
 * The pixels a rectangle of full precision has its edges at, each taken to its nearest pixel once.
 *
 * @return array{0: int, 1: int, 2: int, 3: int} Left, top, right and bottom.
 */
function roundGroupScaleEdges(CanvasRectangle $rectangle): array
{
    return [(int) round($rectangle->x), (int) round($rectangle->y),
        (int) round($rectangle->x + $rectangle->width), (int) round($rectangle->y + $rectangle->height)];
}

/** The raster's place for the innermost of nested groups, the outermost given first. */
function placeGroupScaleGroups(array $groups): CanvasStillTransform
{
    return array_reduce($groups, static fn(CanvasStillTransform $placed, CanvasGroup $group): CanvasStillTransform => $placed->nestGroup($group),
        CanvasStillTransform::createIdentity());
}

it('keeps the Engine\'s fixture unchanged, with the capability it is for and what the Engine requires beside it', function () {
    $directory = getCanvasGroupScaleFixtureDirectory();
    $manifest = readCanvasGroupScaleFixture('manifest');

    expect(array_map(static fn(string $file): string => (string) hash_file('sha256', $directory . '/' . $file), array_keys(CANVAS_GROUP_SCALE_FIXTURE_SHA256)))
        ->toBe(array_values(CANVAS_GROUP_SCALE_FIXTURE_SHA256))
        ->and($manifest['capability'])->toBe(RendererSessionConfig::CANVAS_GROUP_SCALE)
        ->and($manifest['cases'])->toBe(['line-2x', 'fractional', 'nested-colour', 'retained-changing-scale']);

    // Every raster that draws canvases names the scale, and beside it everything the Engine requires with it.
    foreach ([CanvasStillRaster::class, FieldStillRaster::class, SceneFrameRaster::class] as $raster) {
        expect(array_diff($manifest['prerequisites'], $raster::CAPABILITIES))->toBe([])
            ->and($raster::supports(RendererSessionConfig::CANVAS_GROUP_SCALE))->toBeTrue();
    }
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('draws the doubled line as the Engine\'s picture of it, pixel for pixel: two hard columns, never an enlarged blur', function () {
    $case = readCanvasGroupScaleFixture('line-2x');
    $canvas = createCanvasGroupScaleCanvas($case['canvas']);
    [$surface, $diagnostics] = drawCanvasGroupScaleSurface($canvas);
    $expected = imagecreatefrompng(getCanvasGroupScaleFixtureDirectory() . '/' . $case['expected']['rgbaOracle']);
    $different = [];
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            if (readCanvasGroupScalePixel($surface, $x, $y) !== readCanvasGroupScalePixel($expected, $x, $y)) {
                $different[] = [$x, $y];
            }
        }
    }

    expect($diagnostics)->toBe([])
        ->and([imagesx($expected), imagesy($expected), imagesx($surface), imagesy($surface)])->toBe([8, 8, 8, 8])
        ->and($different)->toBe([])
        // The Engine's own projection of the line is where the picture has it.
        ->and($canvas->groups[0]->projectRectangle($canvas->groups[0]->canvas->images[0]->destination)->toArray())->toBe($case['expected']['lineDestination'])
        ->and(findCanvasGroupScalePaint($surface))->toBe(['x' => 2, 'y' => 0, 'width' => 2, 'height' => 8, 'filled' => true])
        ->and(readCanvasGroupScalePixel($surface, 2, 0))->toBe([255, 255, 255, 255])
        ->and(readCanvasGroupScalePixel($surface, 1, 0)[3])->toBe(0);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('keeps a fractional destination in full precision and the group\'s clip where the parent has it', function () {
    $case = readCanvasGroupScaleFixture('fractional');
    $canvas = createCanvasGroupScaleCanvas($case['canvas']);
    $group = $canvas->groups[0];
    $destination = $group->canvas->images[0]->destination;
    [$surface, $diagnostics] = drawCanvasGroupScaleSurface($canvas);
    $expected = $case['expected']['destination'];

    // The raster's place for the group is the Engine's projection of it, each edge taken to a pixel only where it is drawn.
    expect($diagnostics)->toBe([])
        ->and($group->projectRectangle($destination)->toArray())->toBe($expected)
        ->and(CanvasStillTransform::createIdentity()->nestGroup($group)->getEdges($destination))->toBe([
            (int) round($expected['x']), (int) round($expected['y']),
            (int) round($expected['x'] + $expected['width']), (int) round($expected['y'] + $expected['height']),
        ])
        ->and($group->clipRect->toArray())->toBe($case['expected']['visibleRectangle'])
        // What shows is the clip's own rectangle of the parent: the scale moves and grows the picture, never the clip.
        ->and(findCanvasGroupScalePaint($surface))->toBe(['x' => 2, 'y' => 0, 'width' => 5, 'height' => 4, 'filled' => true])
        ->and(readCanvasGroupScalePixel($surface, 2, 0))->toBe([255, 255, 255, 255]);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('multiplies nested scales, and gives the child\'s flat colour and then the parent\'s dimming through both', function () {
    $case = readCanvasGroupScaleFixture('nested-colour');
    $canvas = createCanvasGroupScaleCanvas($case['canvas']);
    $outer = $canvas->groups[0];
    $inner = $outer->canvas->groups[0];
    $line = $inner->canvas->images[0]->destination;
    [$surface, $diagnostics] = drawCanvasGroupScaleSurface($canvas);
    $pixel = $case['expected']['interiorPixel'];
    $nested = CanvasStillTransform::createIdentity()->nestGroup($outer)->nestGroup($inner);

    expect($diagnostics)->toBe([])
        ->and($outer->projectRectangle($inner->projectRectangle($line))->toArray())->toBe($case['expected']['destination'])
        ->and($nested->factor)->toBe($outer->scale->factor * $inner->scale->factor)
        ->and($nested->getEdges($line))->toBe([2, -2, 5, 10])
        ->and(findCanvasGroupScalePaint($surface))->toBe(roundCanvasGroupScaleArea($case['expected']['visibleRectangle'], 20, 20))
        ->and(findCanvasGroupScalePaint($surface))->toBe(['x' => 2, 'y' => 0, 'width' => 3, 'height' => 10, 'filled' => true])
        ->and(readCanvasGroupScalePixel($surface, $pixel['x'], $pixel['y']))->toBe($pixel['rgba']);

    // Every painted pixel has that one colour: the treatments reach the whole scaled line, edge to edge.
    $colours = [];
    for ($y = 0; $y < 10; $y++) {
        for ($x = 2; $x < 5; $x++) {
            $colours[implode(',', readCanvasGroupScalePixel($surface, $x, $y))] = true;
        }
    }
    expect(array_keys($colours))->toBe([implode(',', $pixel['rgba'])]);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('draws a changing factor from the one prepared picture, leaves the HUD where it is, and an identity frame clears the scale', function () {
    $case = readCanvasGroupScaleFixture('retained-changing-scale');
    $frames = array_map(createCanvasGroupScaleCanvas(...), $case['frames']);
    $raster = new CanvasStillRaster(getCanvasGroupScaleFixtureDirectory());
    $hud = $case['expected']['hudDestination'];
    $regions = new ReflectionProperty(CanvasStillRaster::class, 'regions');

    expect(array_map(static fn(PresentationCanvas $frame): ?float => $frame->groups[0]->scale?->factor, $frames))->toBe([1.25, 1.5, null])
        ->and(array_unique(array_map(static fn(array $frame): string => json_encode($frame['groups'][0]['canvas']), $case['frames'])))->toHaveCount(1)
        ->and($case['frames'][2]['groups'][0])->not->toHaveKey('scale');

    foreach ($frames as $index => $frame) {
        $group = $frame->groups[0];
        // The Engine charges a frame the same whatever its factor: the picture and the one crop drawn from it.
        $charged = PresentationSession::runForSession(CanvasStillRaster::supports(...),
            fn(): array => CanvasImagePreflight::inspectCanvases([$frame], getCanvasGroupScaleFixtureDirectory()));
        [$surface, $diagnostics] = drawCanvasGroupScaleSurface($frame, $raster);
        $line = $group->projectRectangle($group->canvas->images[0]->destination)->toArray();

        expect($diagnostics)->toBe([])
            ->and($charged)->toBe($case['expected']['budgetPerFrame'])
            // One region is held for the three frames: the factor makes no picture of its own.
            ->and(array_map(static fn(GdImage $region): array => [imagesx($region), imagesy($region)], array_values($regions->getValue($raster))))->toBe([[1, 4]])
            ->and($frame->images[0]->destination->toArray())->toBe($hud)
            ->and(findCanvasGroupScalePaint($surface, 8, 8))->toBe(['x' => (int) $hud['x'], 'y' => (int) $hud['y'], 'width' => (int) $hud['width'], 'height' => (int) $hud['height'], 'filled' => true])
            // The line is where the Engine's projection of it for this frame's factor puts it, and nowhere else.
            ->and(findCanvasGroupScalePaint($surface, 0, 0, 8, 8))->toBe(roundCanvasGroupScaleArea($line, 20, 20))
            ->and(findCanvasGroupScalePaint($surface, 0, 0, 8, 8)['height'])->toBe([5, 5, 4][$index]);
    }
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('carries the scale to every kind of drawing in the group: fills, images, indicators and text cells', function () {
    $child = new PresentationCanvas(10, 8,
        [new CanvasImage('line', 'white-line.png', new CanvasRectangle(6, 1, 1, 2), 1)],
        [new CanvasIndicator('focus', 'line', CanvasIndicatorKind::UNDERLINE, new CanvasRectangle(1, 5, 4, 2), 1, PresentationColor::rgb(...GROUP_SCALE_YELLOW), 2)],
        [new CanvasTextLayer('backing', 1, 8, 0, new RendererGridConfig(1, 2, 2, 3),
            [new PresentationTextRun(1, 0, ' ', background: PresentationColor::rgb(...GROUP_SCALE_BLUE))])],
        [createGroupScaleFill('ground', new CanvasRectangle(1, 1, 3, 2), GROUP_SCALE_RED)]);
    $canvas = new PresentationCanvas(40, 30, groups: [new CanvasGroup('scaled', 3, 2, $child, scale: createGroupScale(3))]);
    [$surface, $diagnostics] = drawCanvasGroupScaleSurface($canvas);

    expect($diagnostics)->toBe([])
        ->and(findGroupScaleColour($surface, GROUP_SCALE_RED))->toBe(['x' => 6, 'y' => 5, 'width' => 9, 'height' => 6, 'filled' => true])
        ->and(findGroupScaleColour($surface, [255, 255, 255]))->toBe(['x' => 21, 'y' => 5, 'width' => 3, 'height' => 6, 'filled' => true])
        // An underline's stroke grows with the group: one unit of the canvas is three pixels.
        ->and(findGroupScaleColour($surface, GROUP_SCALE_YELLOW))->toBe(['x' => 6, 'y' => 20, 'width' => 12, 'height' => 3, 'filled' => true])
        // The second cell of a grid of 2 by 3 cells at (8, 0).
        ->and(findGroupScaleColour($surface, GROUP_SCALE_BLUE))->toBe(['x' => 27, 'y' => 11, 'width' => 6, 'height' => 9, 'filled' => true]);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('draws a glyph in the font size of its scaled cell, inside that cell', function () {
    $layer = new CanvasTextLayer('word', 1, 0, 0, new RendererGridConfig(1, 1, 8, 16),
        [new PresentationTextRun(0, 0, 'M', PresentationColor::rgb(255, 255, 255))]);
    $child = new PresentationCanvas(8, 16, textLayers: [$layer]);
    $plain = findCanvasGroupScalePaint(drawCanvasGroupScaleSurface(new PresentationCanvas(40, 60, groups: [new CanvasGroup('plain', 4, 6, $child)]))[0]);
    $scaled = findCanvasGroupScalePaint(drawCanvasGroupScaleSurface(
        new PresentationCanvas(40, 60, groups: [new CanvasGroup('scaled', 4, 6, $child, scale: createGroupScale(3))]))[0]);

    // The glyph is drawn for a cell of 24 by 48 pixels: three times the height of the one drawn for 8 by 16, a pixel's rounding aside.
    expect($plain)->not->toBeNull()
        ->and($scaled)->not->toBeNull()
        ->and($plain['x'] >= 4 && $plain['y'] >= 6 && $plain['x'] + $plain['width'] <= 12 && $plain['y'] + $plain['height'] <= 22)->toBeTrue()
        ->and($scaled['x'] >= 4 && $scaled['y'] >= 6 && $scaled['x'] + $scaled['width'] <= 28 && $scaled['y'] + $scaled['height'] <= 54)->toBeTrue()
        ->and($scaled['height'])->toBeBetween(3 * $plain['height'] - 3, 3 * $plain['height'] + 3);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale() || CanvasStillRaster::findSystemFont() === null,
    'Needs an Engine with canvas group scale and a monospaced system font.');

it('draws a label of real glyphs in a scaled group where the scale puts its raster, at that size, inside the group\'s own unscaled clip', function () {
    $word = drawGroupScaleWord((string) CanvasStillRaster::findSystemFont(), 'HMH');
    $bytes = encodeGroupScaleLabel($word);
    [$wide, $high] = [imagesx($word), imagesy($word)];
    $soft = 0;
    for ($y = 0; $y < $high; $y++) {
        for ($x = 0; $x < $wide; $x++) {
            $soft += in_array(readCanvasGroupScalePixel($word, $x, $y)[3], [0, 255], true) ? 0 : 1;
        }
    }
    $area = new CanvasRectangle(5, 4, $wide, $high);
    $child = new PresentationCanvas($wide + 10, $high + 8, labels: [createGroupScaleLabel($area)]);
    $place = static fn(?CanvasGroupScale $scale, ?CanvasRectangle $clip = null): CanvasGroup => new CanvasGroup('sign', 6.3, 4.6, $child, clipRect: $clip, scale: $scale);
    $draw = static fn(CanvasGroup $group): array => drawCanvasGroupScaleSurface(new PresentationCanvas(160, 80, groups: [$group]),
        new CanvasStillRaster(getCanvasGroupScaleFixtureDirectory(), labelRasters: [GROUP_SCALE_LABEL_LAYOUT => $bytes]));
    $extent = static fn(?array $paint): ?array => $paint === null ? null : [$paint['x'], $paint['y'], $paint['x'] + $paint['width'], $paint['y'] + $paint['height']];

    // Scaled by one and a half about a point a quarter across and half down the group's canvas, from a fractional origin.
    $scaled = $place(createGroupScale(1.5, 0.25, 0.5));
    $projected = $scaled->projectRectangle($area);
    $edges = roundGroupScaleEdges($projected);
    [$surface, $diagnostics] = $draw($scaled);

    // The word is a raster of glyphs, soft at its edges. Its ink reaches every side of its picture, so what the still
    // paints of it is the whole rectangle the Engine's projection of the label rounds to: the place and the size
    // are the scale's, and every pixel is the raster sampled once to that size.
    expect($soft)->toBeGreaterThan(0)
        ->and($diagnostics)->toBe([])
        ->and($edges[0] >= 0 && $edges[1] >= 0 && $edges[2] <= 160 && $edges[3] <= 80)->toBeTrue()
        ->and(CanvasStillTransform::createIdentity()->nestGroup($scaled)->getEdges($area))->toBe($edges)
        ->and($extent(findCanvasGroupScalePaint($surface)))->toBe($edges)
        ->and([$edges[2] - $edges[0], $edges[3] - $edges[1]])->not->toBe([$wide, $high])
        ->and(countGroupScaleDifferences($surface, composeGroupScaleLabel($bytes, $projected, null, 160, 80)))->toBe(0);

    // With no scale the same label is drawn at its raster's own size, from the group's whole pixel.
    [$plain, $plainDiagnostics] = $draw($place(null));
    expect($plainDiagnostics)->toBe([])
        ->and($extent(findCanvasGroupScalePaint($plain)))->toBe([11, 9, 11 + $wide, 9 + $high]);

    // The group's clip is its parent's: it cuts the scaled label at the clip's own pixels, a few in from the label's
    // left and top and half-way across it, and is not moved or grown by the scale.
    $clip = new CanvasRectangle($edges[0] + 2.3, $edges[1] + 1.2, intdiv($edges[2] - $edges[0], 2) + 0.4, $edges[3] - $edges[1]);
    $cut = roundGroupScaleEdges($clip);
    [$clipped, $clippedDiagnostics] = $draw($place(createGroupScale(1.5, 0.25, 0.5), $clip));
    $painted = $extent(findCanvasGroupScalePaint($clipped));
    expect($clippedDiagnostics)->toBe([])
        ->and($cut[0] > $edges[0] && $cut[1] > $edges[1] && $cut[2] < $edges[2])->toBeTrue()
        ->and($painted)->not->toBeNull()
        ->and($painted[0] >= $cut[0] && $painted[1] >= $cut[1] && $painted[2] <= $cut[2] && $painted[3] <= min($cut[3], $edges[3]))->toBeTrue()
        ->and(countGroupScaleDifferences($clipped, composeGroupScaleLabel($bytes, $projected, $clip, 160, 80)))->toBe(0)
        ->and(countGroupScaleDifferences($clipped, $surface))->toBeGreaterThan(0);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale() || CanvasStillRaster::findSystemFont() === null,
    'Needs an Engine with canvas group scale and a monospaced system font.');

it('scales about the origin the group names, applies the translation after, and shrinks as it grows', function () {
    $child = new PresentationCanvas(8, 8, composites: [createGroupScaleFill('mark', new CanvasRectangle(2, 2, 4, 4), GROUP_SCALE_RED)]);
    $draw = static fn(float $factor, float $originX, float $originY): ?array => findGroupScaleColour(drawCanvasGroupScaleSurface(
        new PresentationCanvas(40, 40, groups: [new CanvasGroup('scaled', 10, 12, $child, scale: createGroupScale($factor, $originX, $originY))]))[0], GROUP_SCALE_RED);

    // About its centre the mark grows and shrinks in place; about a corner it runs away from that corner.
    expect($draw(2, 0.5, 0.5))->toBe(['x' => 10, 'y' => 12, 'width' => 8, 'height' => 8, 'filled' => true])
        ->and($draw(0.5, 0.5, 0.5))->toBe(['x' => 13, 'y' => 15, 'width' => 2, 'height' => 2, 'filled' => true])
        ->and($draw(2, 0, 0))->toBe(['x' => 14, 'y' => 16, 'width' => 8, 'height' => 8, 'filled' => true])
        ->and($draw(2, 1, 1))->toBe(['x' => 6, 'y' => 8, 'width' => 8, 'height' => 8, 'filled' => true])
        ->and($draw(1, 0.5, 0.5))->toBe(['x' => 12, 'y' => 14, 'width' => 4, 'height' => 4, 'filled' => true]);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('scales the clips inside the group with it, keeps the group\'s own clip in the parent, and intersects the two', function () {
    $child = new PresentationCanvas(10, 10, composites: [
        createGroupScaleFill('cut', new CanvasRectangle(0, 0, 10, 10), GROUP_SCALE_RED, clip: new CanvasRectangle(2, 2, 6, 6)),
    ]);
    $scaled = static fn(?CanvasRectangle $clip): PresentationCanvas => new PresentationCanvas(40, 40,
        groups: [new CanvasGroup('scaled', 4, 4, $child, clipRect: $clip, scale: createGroupScale(2))]);

    // The fill's own clip is the group canvas's: it doubles with the group. The group's clip is the parent's: it does not.
    expect(findGroupScaleColour(drawCanvasGroupScaleSurface($scaled(null))[0], GROUP_SCALE_RED))->toBe(['x' => 8, 'y' => 8, 'width' => 12, 'height' => 12, 'filled' => true])
        ->and(findGroupScaleColour(drawCanvasGroupScaleSurface($scaled(new CanvasRectangle(10, 6, 5, 5)))[0], GROUP_SCALE_RED))
        ->toBe(['x' => 10, 'y' => 8, 'width' => 5, 'height' => 3, 'filled' => true]);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('draws a group inside a scaled group at the scaled place, in its own colour treatment, whether or not it has a scale of its own', function () {
    $invert = new CanvasColorTransform([[-1, 0, 0], [0, -1, 0], [0, 0, -1]], [1, 1, 1]);
    $leaf = new PresentationCanvas(4, 4, composites: [createGroupScaleFill('mark', new CanvasRectangle(1, 1, 2, 2), [255, 0, 0])]);
    $nest = static fn(?CanvasGroupScale $own): PresentationCanvas => new PresentationCanvas(40, 40, groups: [new CanvasGroup('outer', 2, 2,
        new PresentationCanvas(12, 12, groups: [new CanvasGroup('inner', 1.5, 3, $leaf, colorTransform: $invert, scale: $own)]),
        scale: createGroupScale(2))]);

    // The inner origin of 1.5 is kept: doubled it is three whole pixels, where an origin rounded first would be four.
    expect(findGroupScaleColour(drawCanvasGroupScaleSurface($nest(null))[0], [0, 255, 255]))->toBe(['x' => 7, 'y' => 10, 'width' => 4, 'height' => 4, 'filled' => true])
        ->and(findGroupScaleColour(drawCanvasGroupScaleSurface($nest(createGroupScale(1.5)))[0], [0, 255, 255]))->toBe(['x' => 8, 'y' => 11, 'width' => 6, 'height' => 6, 'filled' => true])
        ->and(findGroupScaleColour(drawCanvasGroupScaleSurface($nest(null))[0], [255, 0, 0]))->toBeNull();
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('takes a group no scale reaches to a whole pixel, and rounds nothing before the edge once a scale applies', function () {
    $leaf = new PresentationCanvas(4, 4);
    $identity = CanvasStillTransform::createIdentity();
    $moved = $identity->nestGroup(new CanvasGroup('moved', 3.5, -3.5, $leaf));
    $scaled = $identity->nestGroup(new CanvasGroup('scaled', 0.4, 0.4, $leaf, scale: createGroupScale(2)));

    // An unscaled origin goes to its nearest pixel, a half upwards, and the drawings inside keep their places beside it.
    expect($moved->factor)->toBe(1.0)
        ->and([$moved->getX(0), $moved->getY(0), $moved->getX(0.4), $moved->getX(0.5)])->toBe([4, -3, 4, 5])
        // Under a scale the origin's fraction is kept: 0.4 + 2 * 0.1 is 0.6, a pixel on from an origin rounded to 0 first.
        ->and($scaled->factor)->toBe(2.0)
        ->and([$scaled->getX(0), $scaled->getX(0.1), $scaled->getX(1)])->toBe([0, 1, 2])
        // A group with no scale of its own inside it is placed in the same precision.
        ->and($scaled->nestGroup(new CanvasGroup('inner', 0.3, 0, $leaf))->getX(0))->toBe(1)
        ->and($scaled->nestGroup(new CanvasGroup('inner', 0.3, 0, $leaf, scale: createGroupScale(1.5)))->factor)->toBe(3.0)
        // A factor of exactly one is no scale: the Engine leaves it off the wire, and the group moves by whole pixels.
        ->and($identity->nestGroup(new CanvasGroup('one', 3.5, 0, $leaf, scale: createGroupScale(1, 0.5, 0.5)))->getX(0.4))->toBe(4);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('keeps the fraction of an unscaled group\'s origin for the scaled group inside it', function () {
    $mark = new CanvasRectangle(0, 0, 1, 1);
    $leaf = new PresentationCanvas(4, 4, composites: [createGroupScaleFill('mark', $mark, GROUP_SCALE_RED)]);
    $inner = new CanvasGroup('inner', 0.4, 0.4, $leaf, scale: createGroupScale(2));
    $outer = new CanvasGroup('outer', 0.4, 0.4, new PresentationCanvas(12, 12, groups: [$inner]));
    $projected = $outer->projectRectangle($inner->projectRectangle($mark));
    [$surface, $diagnostics] = drawCanvasGroupScaleSurface(new PresentationCanvas(20, 20, groups: [$outer]));

    // The Engine has the mark at (0.8, 0.8), two units square: the outer origin's 0.4 and the inner's make most of a pixel.
    // An outer origin taken to a whole pixel before the scale would leave the mark at the surface's corner.
    expect($diagnostics)->toBe([])
        ->and(roundGroupScaleEdges($projected))->toBe([1, 1, 3, 3])
        ->and(CanvasStillTransform::createIdentity()->nestGroup($outer)->nestGroup($inner)->getEdges($mark))->toBe(roundGroupScaleEdges($projected))
        ->and(findGroupScaleColour($surface, GROUP_SCALE_RED))->toBe(['x' => 1, 'y' => 1, 'width' => 2, 'height' => 2, 'filled' => true])
        ->and(findCanvasGroupScalePaint($surface))->toBe(roundCanvasGroupScaleArea($projected->toArray(), 20, 20));
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('places a scaled group by the full origins of every group around it, however they nest', function (array $links, array $area) {
    $mark = new CanvasRectangle(...$area);
    $groups = nestGroupScaleGroups($links, new PresentationCanvas(4, 4, composites: [createGroupScaleFill('mark', $mark, GROUP_SCALE_RED)]));
    $projected = projectGroupScaleRectangle($groups, $mark);
    $expected = roundCanvasGroupScaleArea($projected->toArray(), 20, 20);
    [$surface, $diagnostics] = drawCanvasGroupScaleSurface(new PresentationCanvas(20, 20, groups: [$groups[0]]));

    // The raster's edges are the Engine's projection through every group, each edge taken to a pixel once; the still shows
    // the mark on those pixels, less what lies off the surface.
    expect($diagnostics)->toBe([])
        ->and(placeGroupScaleGroups($groups)->getEdges($mark))->toBe(roundGroupScaleEdges($projected))
        ->and($expected['width'] > 0 && $expected['height'] > 0)->toBeTrue()
        ->and(findGroupScaleColour($surface, GROUP_SCALE_RED))->toBe($expected)
        ->and(findCanvasGroupScalePaint($surface))->toBe($expected);
})->with([
    'three levels, the scale in the middle' => [[[0.4, 0.4, null], [1.3, 2.4, [1.5]], [0.3, 0.2, null]], [1, 1, 2, 1]],
    'a negative fractional origin around the scale' => [[[-0.6, -1.4, null], [0.4, 0.3, [2]]], [0, 0, 1, 1]],
    'a scaled group in a scaled group, both at fractional origins' => [[[2.3, 3.7, [1.5, 0.25, 0.5]], [0.45, 2.2, [2]]], [1, 1, 2, 1]],
])->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('takes each group of a still no scale reaches to its own whole pixel, where the Engine\'s sum of their origins would round on', function () {
    $mark = new CanvasRectangle(0, 0, 1, 1);
    $groups = nestGroupScaleGroups([[0.4, 0.4, null], [0.4, 0.4, null]], new PresentationCanvas(4, 4, composites: [createGroupScaleFill('mark', $mark, GROUP_SCALE_RED)]));
    [$surface, $diagnostics] = drawCanvasGroupScaleSurface(new PresentationCanvas(20, 20, groups: [$groups[0]]));

    // Each origin of 0.4 goes to pixel 0 and the mark stays at the corner; the two together are 0.8, which a scaled group is drawn by.
    expect($diagnostics)->toBe([])
        ->and(roundGroupScaleEdges(projectGroupScaleRectangle($groups, $mark)))->toBe([1, 1, 2, 2])
        ->and(placeGroupScaleGroups($groups)->getEdges($mark))->toBe([0, 0, 1, 1])
        ->and(findGroupScaleColour($surface, GROUP_SCALE_RED))->toBe(['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'filled' => true]);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('paints nothing of a picture a scale leaves less than a pixel of on both axes', function () {
    $destination = new CanvasRectangle(0, 0, 1, 4);
    $group = new CanvasGroup('small', 0, 0, new PresentationCanvas(40, 40, [new CanvasImage('line', 'white-line.png', $destination)]),
        scale: createGroupScale(0.1));
    $projected = $group->projectRectangle($destination);
    [$surface, $diagnostics] = drawCanvasGroupScaleSurface(new PresentationCanvas(8, 8, groups: [$group]));

    // A tenth of the 1 by 4 line is 0.1 by 0.4 at the corner: both edges of each axis come to pixel 0, and no pixel is its.
    expect($projected->toArray())->toBe(['x' => 0.0, 'y' => 0.0, 'width' => 0.1, 'height' => 0.4])
        ->and(roundGroupScaleEdges($projected))->toBe([0, 0, 0, 0])
        ->and(CanvasStillTransform::createIdentity()->nestGroup($group)->getEdges($destination))->toBe(roundGroupScaleEdges($projected))
        ->and([imagesx($surface), imagesy($surface)])->toBe([8, 8])
        ->and(findCanvasGroupScalePaint($surface))->toBeNull()
        ->and($diagnostics)->toBe([]);
})->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('draws a scaled picture on the pixels its rounded edges enclose, and nothing where either axis encloses none', function (string $kind) {
    $draw = static function (CanvasRectangle $area) use ($kind): array {
        $group = new CanvasGroup('small', 0, 0, createGroupScaleDrawing($kind, $area), scale: createGroupScale(0.1));
        [$surface, $diagnostics] = drawCanvasGroupScaleSurface(new PresentationCanvas(8, 8, groups: [$group]),
            createGroupScaleLabelRaster(createGroupScalePicture(6, 6, [255, 255, 255])));
        [$left, $top, $right, $bottom] = roundGroupScaleEdges($group->projectRectangle($area));

        return [[$right - $left, $bottom - $top], [$left, $top], $surface, $diagnostics];
    };

    // Less than a pixel on both axes, and a pixel high but less than one wide: no pixel either way.
    foreach ([new CanvasRectangle(0, 0, 1, 4), new CanvasRectangle(0, 0, 1, 12)] as $area) {
        [$extent, , $surface, $diagnostics] = $draw($area);
        expect(min($extent))->toBe(0)
            ->and(findCanvasGroupScalePaint($surface))->toBeNull()
            ->and($diagnostics)->toBe([]);
    }

    // Edges that enclose exactly one pixel draw that pixel, whole.
    [$extent, [$left, $top], $surface, $diagnostics] = $draw(new CanvasRectangle(14, 26, 6, 12));
    expect($extent)->toBe([1, 1])
        ->and(findCanvasGroupScalePaint($surface))->toBe(['x' => $left, 'y' => $top, 'width' => 1, 'height' => 1, 'filled' => true])
        ->and(readCanvasGroupScalePixel($surface, $left, $top))->toBe([255, 255, 255, 255])
        ->and($diagnostics)->toBe([]);
})->with(['image', 'composite', 'label'])->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);

it('draws nothing of a picture no scale reaches whose edges come to one pixel, and the picture beside it that reaches a pixel', function (string $kind) {
    $draw = static fn(CanvasRectangle $area): array => drawCanvasGroupScaleSurface(createGroupScaleDrawing($kind, $area),
        createGroupScaleLabelRaster(createGroupScalePicture(6, 6, [255, 255, 255])));

    // 0.3 wide from 0.1: both edges at pixel 0. The same width from 0.4 has its right edge at pixel 1.
    foreach ([[new CanvasRectangle(0.1, 0, 0.3, 4), null], [new CanvasRectangle(0.4, 0, 0.3, 4), ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 4, 'filled' => true]]] as [$area, $painted]) {
        [$left, $top, $right, $bottom] = roundGroupScaleEdges($area);
        [$surface, $diagnostics] = $draw($area);
        expect([$right - $left, $bottom - $top])->toBe([$painted['width'] ?? 0, 4])
            ->and(findCanvasGroupScalePaint($surface))->toBe($painted)
            ->and($diagnostics)->toBe([]);
    }
})->with(['image', 'composite', 'label']);

it('leaves the limits of a scale to the Engine, which refuses a factor that is not finite, positive and at most eight', function (float $factor) {
    expect(fn() => createGroupScale($factor))->toThrow(InvalidArgumentException::class, 'Canvas group scale must be finite, positive and at most 8.');
})->with([0.0, -1.0, 8.5, INF, NAN])->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);
