<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Editor\Review\CanvasStillTransform;
use Ichiloto\Editor\Review\FieldStillRaster;
use Ichiloto\Editor\Review\SceneFrameRaster;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasColorTransform;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroupScale;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
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

it('leaves the limits of a scale to the Engine, which refuses a factor that is not finite, positive and at most eight', function (float $factor) {
    expect(fn() => createGroupScale($factor))->toThrow(InvalidArgumentException::class, 'Canvas group scale must be finite, positive and at most 8.');
})->with([0.0, -1.0, 8.5, INF, NAN])->skip(fn (): bool => ! hasEngineCanvasGroupScale(), ENGINE_CANVAS_GROUP_SCALE_MISSING);
