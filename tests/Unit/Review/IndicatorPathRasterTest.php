<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Editor\Review\FieldStill;
use Ichiloto\Editor\Review\FieldStillRaster;
use Ichiloto\Editor\Review\MapRenderView;
use Ichiloto\Editor\Review\SceneFrameRaster;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposition;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * An indicator may name the image or composite it marks by a path of group ids read from the root canvas of
 * its frame, so a body, an untreated effect over it and the body's outline over that can lie in three places of
 * one paint order: a treated group, the root, and a second treated group. The path is an association only. The
 * stills draw the outline where its own bounds put it, in the colour treatment of the group it is in, and a
 * frame whose path leads nowhere is refused whole, in the Engine's words. The frames are the Engine's
 * ({@see readIndicatorPathFixture()}); the pictures of them are the fixture the native renderer is compared with.
 */

/** The outline's colour as the frames give it, and as the inverting group it is in shows it. */
const INDICATOR_PATH_OUTLINE = '#dcb428';
const INDICATOR_PATH_TREATED_OUTLINE = '#234bd7';
/** The composite body's fill as the inverting group it is in shows it. */
const INDICATOR_PATH_TREATED_FILL = '#d7b99b';

/** One of the fixture's positive frames in the Engine's model, its images drawn from the unit sprite. */
function createIndicatorPathUnitCanvas(string $id): PresentationCanvas
{
    return createIndicatorPathCanvas(replaceIndicatorPathSprite(findIndicatorPathWire('cases', $id), INDICATOR_PATH_UNIT_SPRITE));
}

/** A pixel of the rows {@see drawIndicatorPathRows()} gives, as `#rrggbb`. */
function readIndicatorPathPixel(array $rows, int $x, int $y): string
{
    return '#' . substr($rows[$y], $x * 8, 6);
}

/** The colour of the unit sprite's texel, as a row writes it, or null where the texel is clear. */
function encodeIndicatorPathUnitTexel(int $x, int $y, bool $inverted = false): ?string
{
    $texel = getIndicatorPathUnitTexel($x, $y);

    return $texel === null ? null : vsprintf('#%02x%02x%02x', $inverted ? array_map(static fn(int $channel): int => 255 - $channel, $texel) : $texel);
}

/**
 * Where rows show a colour: the pixels' bounding rectangle and how many there are.
 *
 * @return array{x: int, y: int, width: int, height: int, count: int}|null Null when no pixel shows it.
 */
function findIndicatorPathArea(array $rows, string $colour): ?array
{
    $left = $top = PHP_INT_MAX;
    $right = $bottom = $count = 0;
    foreach ($rows as $y => $row) {
        for ($x = 0; $x < strlen($row) / 8; $x++) {
            if (readIndicatorPathPixel($rows, $x, $y) === $colour) {
                [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x + 1), max($bottom, $y + 1)];
                $count++;
            }
        }
    }

    return $count === 0 ? null : ['x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top, 'count' => $count];
}

it('keeps the Engine\'s fixture unchanged, and beside it the pictures the raster draws of its frames', function () {
    $directory = getIndicatorPathFixtureDirectory();
    $fixture = readIndicatorPathFixture();
    $unit = imagecreatefrompng($directory . '/' . INDICATOR_PATH_UNIT_SPRITE);
    $expected = createIndicatorPathUnitSprite();
    $different = 0;
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $different += (int) (imagecolorat($unit, $x, $y) !== imagecolorat($expected, $x, $y));
        }
    }

    // A difference in the last is a change to the raster's picture of these frames: see writeIndicatorPathRasterFixture().
    expect(hash_file('sha256', $directory . '/' . INDICATOR_PATH_FIXTURE))->toBe(INDICATOR_PATH_FIXTURE_SHA256)
        ->and(hash_file('sha256', $directory . '/' . INDICATOR_PATH_ENGINE_SPRITE))->toBe($fixture['reviewAssetSha256'][INDICATOR_PATH_ENGINE_SPRITE])
        ->and([imagesx($unit), imagesy($unit), $different])->toBe([8, 8, 0])
        ->and(encodeIndicatorPathRasterFixture(buildIndicatorPathRasterFixture()))
        ->toBe(file_get_contents($directory . '/' . INDICATOR_PATH_RASTER_FIXTURE));
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('reads each frame into the Engine\'s model whole and admits it as the Engine charged it', function (string $id) {
    $case = array_find(readIndicatorPathFixture()['cases'], static fn(array $case): bool => $case['id'] === $id);
    $canvas = createIndicatorPathCanvas($case['canvas']);
    // The path adds no drawing: a frame costs its one picture, the crop drawn from it and that crop inverted,
    // whether one treated group draws it or two with the same treatment do.
    $charged = PresentationSession::runForSession(CanvasStillRaster::supports(...),
        fn(): array => CanvasImagePreflight::inspectCanvasResources([$canvas], getIndicatorPathFixtureDirectory()));
    [, $diagnostics] = drawIndicatorPathRows($canvas);

    expect($canvas->toArray())->toEqual($case['canvas'])
        ->and([count($charged['sources']), count($charged['regions'])])->toBe([$case['enginePreparedSourceCount'], $case['enginePreparedRegionCount']])
        ->and([count($charged['sources']), count($charged['regions'])])->toBe([1, 2])
        ->and($diagnostics)->toBe([]);
})->with(['image-leaf-interleaving', 'composite-leaf-interleaving', 'wrapped-clipped-prefix', 'shared-treatment-variants'])
    ->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('draws the body, the untreated effect over it and the treated outline over that', function () {
    $canvas = createIndicatorPathUnitCanvas('image-leaf-interleaving');
    [$rows, $diagnostics] = drawIndicatorPathRows($canvas);
    $layers = array_map(static fn(object $drawing): int => $drawing->layer, [$canvas->groups[0], $canvas->images[0], $canvas->groups[1]]);
    $wrong = 0;
    for ($y = 1; $y < 7; $y++) {
        for ($x = 1; $x < 7; $x++) {
            // Inside the outline the effect, which no group treats, covers the inverted body texel for texel.
            $wrong += (int) (readIndicatorPathPixel($rows, 4 + $x, 4 + $y) !== (encodeIndicatorPathUnitTexel($x, $y) ?? sprintf('#%06x', INDICATOR_PATH_BACKGROUND)));
        }
    }

    expect($diagnostics)->toBe([])
        ->and($layers)->toBe([120, 180, 200])
        ->and($canvas->groups[1]->canvas->indicators[0]->imagePath)->toBe(['body-group', 'body'])
        ->and($wrong)->toBe(0)
        // The outline is one pixel wide on its own bounds, in its group's treatment and nowhere in its own colour.
        ->and(findIndicatorPathArea($rows, INDICATOR_PATH_TREATED_OUTLINE))->toBe(['x' => 4, 'y' => 4, 'width' => 8, 'height' => 8, 'count' => 28])
        ->and(findIndicatorPathArea($rows, INDICATOR_PATH_OUTLINE))->toBeNull();
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('shows a treated composite body through the effect\'s clear texels when the path ends at a composite', function () {
    [$rows, $diagnostics] = drawIndicatorPathRows(createIndicatorPathUnitCanvas('composite-leaf-interleaving'));
    $shown = $covered = 0;
    for ($y = 1; $y < 7; $y++) {
        for ($x = 1; $x < 7; $x++) {
            $pixel = readIndicatorPathPixel($rows, 4 + $x, 4 + $y);
            $texel = encodeIndicatorPathUnitTexel($x, $y);
            $shown += (int) ($texel === null && $pixel === INDICATOR_PATH_TREATED_FILL);
            $covered += (int) ($texel !== null && $pixel === $texel);
        }
    }

    expect($diagnostics)->toBe([])
        ->and([$shown, $covered])->toBe([8, 28])
        ->and(findIndicatorPathArea($rows, INDICATOR_PATH_TREATED_OUTLINE))->toBe(['x' => 4, 'y' => 4, 'width' => 8, 'height' => 8, 'count' => 28]);
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('places a wrapped frame at its group\'s nearest whole pixel and clips its outline with everything else', function () {
    $wire = replaceIndicatorPathSprite(findIndicatorPathWire('cases', 'wrapped-clipped-prefix'), INDICATOR_PATH_UNIT_SPRITE);
    $clipped = createIndicatorPathCanvas($wire);
    [$cut, $diagnostics] = drawIndicatorPathRows($clipped);
    // The same frame with no clip, to see what the Engine's 8 by 8 clip cuts.
    unset($wire['groups'][0]['clipRect']);
    [$rows] = drawIndicatorPathRows(createIndicatorPathCanvas($wire));
    $background = sprintf('#%06x', INDICATOR_PATH_BACKGROUND);
    $kept = $cutAway = 0;
    foreach ($rows as $y => $row) {
        for ($x = 0; $x < strlen($row) / 8; $x++) {
            $inside = $x < 8 && $y < 8;
            // Inside the clip the clipped frame is the whole frame; outside it nothing is painted.
            $kept += (int) ($inside && readIndicatorPathPixel($cut, $x, $y) === readIndicatorPathPixel($rows, $x, $y));
            $cutAway += (int) (! $inside && readIndicatorPathPixel($cut, $x, $y) === $background);
        }
    }

    // An origin of (-2.5, 1.25) is drawn at (-2, 1): a half goes upwards, as for every group.
    expect(findIndicatorPathArea($rows, INDICATOR_PATH_TREATED_OUTLINE))->toBe(['x' => 2, 'y' => 5, 'width' => 8, 'height' => 8, 'count' => 28])
        ->and(readIndicatorPathPixel($rows, 3, 6))->toBe(encodeIndicatorPathUnitTexel(1, 1))
        ->and($clipped->groups[0]->clipRect?->toArray())->toEqual(['x' => 0, 'y' => 0, 'width' => 8, 'height' => 8])
        ->and($clipped->groups[0]->canvas->groups[1]->canvas->indicators[0]->imagePath)->toBe(['review-field', 'body-group', 'body'])
        ->and($diagnostics)->toBe([])
        ->and([$kept, $cutAway])->toBe([64, 32 * 32 - 64])
        // The clip keeps six pixels of the outline's top edge and two of its left edge, and the effect's texels beside them.
        ->and(findIndicatorPathArea($cut, INDICATOR_PATH_TREATED_OUTLINE))->toBe(['x' => 2, 'y' => 5, 'width' => 6, 'height' => 3, 'count' => 8])
        ->and(readIndicatorPathPixel($cut, 3, 6))->toBe(encodeIndicatorPathUnitTexel(1, 1))
        ->and(readIndicatorPathPixel($cut, 7, 7))->toBe(encodeIndicatorPathUnitTexel(5, 2));
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('draws two treated bodies of one treatment alike, each marked through its own group', function () {
    [$rows, $diagnostics] = drawIndicatorPathRows(createIndicatorPathUnitCanvas('shared-treatment-variants'));
    $wrong = 0;
    for ($y = 0; $y < 7; $y++) {
        for ($x = 1; $x < 7; $x++) {
            // Both bodies show the unit sprite inverted, the second twelve pixels to the right of the first.
            $expected = encodeIndicatorPathUnitTexel($x, $y, true) ?? sprintf('#%06x', INDICATOR_PATH_BACKGROUND);
            $wrong += (int) ($y > 0 && readIndicatorPathPixel($rows, 4 + $x, 4 + $y) !== $expected);
            $wrong += (int) (readIndicatorPathPixel($rows, 16 + $x, 4 + $y) !== $expected);
        }
    }

    expect($diagnostics)->toBe([])
        ->and($wrong)->toBe(0)
        // An outline round the first and an underline beneath the second, both in their groups' treatment.
        ->and(findIndicatorPathArea($rows, INDICATOR_PATH_TREATED_OUTLINE))->toBe(['x' => 4, 'y' => 4, 'width' => 20, 'height' => 8, 'count' => 36])
        ->and(readIndicatorPathPixel($rows, 16, 11))->toBe(INDICATOR_PATH_TREATED_OUTLINE)
        ->and(readIndicatorPathPixel($rows, 23, 11))->toBe(INDICATOR_PATH_TREATED_OUTLINE);
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('refuses a frame whose path leads nowhere whole, in the Engine\'s words, before any of it is drawn', function (string $id, string $refusal) {
    $wire = findIndicatorPathWire('negativeCases', $id);

    // The Engine's model refuses three of the frames as they are read; the fourth it refuses when it is admitted.
    expect(fn() => new CanvasStillRaster(getIndicatorPathFixtureDirectory())->rasterize(createIndicatorPathCanvas($wire), INDICATOR_PATH_BACKGROUND))
        ->toThrow(InvalidArgumentException::class, $refusal);
})->with([
    'a group that is not there' => ['missing-group', 'Indicator imagePath requires one existing group at each step: body-group'],
    'a leaf that is not the image' => ['leaf-id-mismatch', 'Indicator imagePath leaf must equal imageId.'],
    'two sibling groups of one id' => ['duplicate-sibling-group', 'unique IDs'],
    'a ninth group' => ['ninth-group-path', 'Indicator imagePath requires 1..8 group IDs and an image/composite leaf.'],
])->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('refuses a path into a group its own root does not have, in every raster that admits canvases', function () {
    $frame = createIndicatorPathUnitCanvas('image-leaf-interleaving');
    // The outline's group without the body's: the model holds it, and admission follows the path.
    $orphan = createIndicatorPathCanvas(replaceIndicatorPathSprite(findIndicatorPathWire('negativeCases', 'missing-group'), INDICATOR_PATH_UNIT_SPRITE));
    $raster = new CanvasStillRaster(getIndicatorPathFixtureDirectory());
    $scene = new SceneFrameRaster(getIndicatorPathFixtureDirectory());

    expect(fn() => $raster->rasterize($orphan))->toThrow(InvalidArgumentException::class, 'existing group')
        // A path is read from its own root: another root of the still that has the group does not answer for it.
        ->and(fn() => $raster->prepareCanvases([$frame, $orphan]))->toThrow(InvalidArgumentException::class, 'existing group')
        ->and(fn() => $scene->rasterize(new SceneFrameComposition(null, canvas: $orphan), new RendererGridConfig(4, 2, 8, 16)))
        ->toThrow(InvalidArgumentException::class, 'existing group')
        // The same roots are admitted once the group is back.
        ->and($raster->prepareCanvases([$frame, $frame]))->toBeNull();
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

/**
 * A scene frame holding a screen and an overlay that no constructor has admitted: what the frame raster's own
 * stacking of the two ({@see SceneFrameRaster::rasterize()}) is given when nothing before it has refused them.
 * The Engine's composition refuses such a pair as it is constructed, so only this reaches the raster with one.
 */
function createUnadmittedIndicatorPathFrame(?PresentationCanvas $canvas, ?PresentationCanvas $overlay): SceneFrameComposition
{
    $class = new ReflectionClass(SceneFrameComposition::class);
    $frame = $class->newInstanceWithoutConstructor();
    foreach (['snapshot' => null, 'sprites' => [], 'viewport' => null, 'world' => null, 'canvas' => $canvas,
        'screenOverlay' => $overlay, 'worldCanvases' => []] as $property => $value) {
        $class->getProperty($property)->setValue($frame, $value);
    }

    return $frame;
}

it('refuses a screen overlay whose path leads into the screen beneath it, as the Engine refuses the layers it composes', function () {
    $frame = createIndicatorPathUnitCanvas('image-leaf-interleaving');
    // The body's group alone is a complete layer; the outline's group alone names a group its own layer lacks.
    $base = new PresentationCanvas(32, 32, groups: [$frame->groups[0]]);
    $orphan = createIndicatorPathCanvas(replaceIndicatorPathSprite(findIndicatorPathWire('negativeCases', 'missing-group'), INDICATOR_PATH_UNIT_SPRITE));
    $grid = new RendererGridConfig(4, 2, 8, 16);
    $raster = new SceneFrameRaster(getIndicatorPathFixtureDirectory());
    $draw = static fn(?PresentationCanvas $canvas, ?PresentationCanvas $overlay): Closure => static fn(): GdImage
        => $raster->rasterize(createUnadmittedIndicatorPathFrame($canvas, $overlay), $grid);
    $refusal = 'Indicator imagePath requires one existing group at each step: body-group';

    // The Engine stacks an overlay on a screen with no prefix, and admits each layer on its own first: the overlay
    // cannot borrow the screen's group. The frame the raster is given is refused as it is composed.
    expect(fn() => PresentationCanvas::composeOverlay($base, $orphan))->toThrow(InvalidArgumentException::class, $refusal)
        ->and(fn() => new SceneFrameComposition(null, canvas: $base, screenOverlay: $orphan))->toThrow(InvalidArgumentException::class, $refusal)
        // The raster stacks the two the same way before it draws: whichever layer holds the group, and with no
        // screen at all, the frame is refused in the Engine's words and nothing is drawn.
        ->and($draw($base, $orphan))->toThrow(InvalidArgumentException::class, $refusal)
        ->and($draw($orphan, $base))->toThrow(InvalidArgumentException::class, $refusal)
        ->and($draw(null, $orphan))->toThrow(InvalidArgumentException::class, $refusal)
        // The same pair assembled as one complete root is drawn, over a screen or alone.
        ->and(imagesx($draw(new PresentationCanvas(32, 32), $frame)()))->toBe(32)
        ->and(imagesx($raster->rasterize(new SceneFrameComposition(null, canvas: new PresentationCanvas(32, 32), screenOverlay: $frame), $grid)))->toBe(32)
        ->and($raster->diagnostics)->toBe([]);
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('draws a world canvas whose outline is marked through a path on a map still, and refuses one whose path leads nowhere', function () {
    $project = makeTemporaryProject('ichiloto-indicator-path-');
    mkdir($project . '/assets/Art', 0o777, true);
    copy(getIndicatorPathFixtureDirectory() . '/' . INDICATOR_PATH_UNIT_SPRITE, $project . '/assets/Art/unit.png');
    $map = EditorSession::open($project)->renderMapReview(new MapRenderView('test-map'))->still;
    $still = static fn(string $list, string $id): FieldStill => new FieldStill($map->world, [], $map->region, worldCanvases: [
        new PresentationWorldCanvas('prop', 0, 0, new PresentationSpritePivot(0.0, 0.0),
            createIndicatorPathCanvas(replaceIndicatorPathSprite(findIndicatorPathWire($list, $id), 'Art/unit.png')))]);
    $raster = new FieldStillRaster($project . '/assets');
    $image = $raster->rasterize($still('cases', 'image-leaf-interleaving'));
    $outline = hexdec(substr(INDICATOR_PATH_TREATED_OUTLINE, 1));
    $count = 0;
    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            $count += (int) ((imagecolorat($image, $x, $y) & 0xFFFFFF) === $outline);
        }
    }

    // Each world canvas is the root its paths are read from.
    expect($count)->toBe(28)
        ->and($raster->diagnostics)->toBe([])
        ->and(fn() => new FieldStillRaster($project . '/assets')->rasterize($still('negativeCases', 'missing-group')))
        ->toThrow(InvalidArgumentException::class, 'existing group');
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('draws a frame composed over a screen, and a frame wrapped in a placed group, as the frame alone', function () {
    $frame = createIndicatorPathUnitCanvas('image-leaf-interleaving');
    [$alone] = drawIndicatorPathRows($frame);
    // The Engine's overlay composition renames the root ids it prefixes and the first element of each path.
    $stacked = PresentationCanvas::composeOverlay(new PresentationCanvas(32, 32), $frame, 'screen-');
    [$composed, $diagnostics] = drawIndicatorPathRows($stacked);
    // The Engine's wrapping adds the placed group to the front of each path; here it moves the frame 3 by 2.
    $placed = new PresentationCanvas(32, 32, groups: [CanvasGroup::wrapRoot('field', 3, 2, $frame, 0, new CanvasRectangle(0, 0, 32, 32))]);
    [$moved] = drawIndicatorPathRows($placed);

    expect($stacked->groups[1]->canvas->indicators[0]->imagePath)->toBe(['screen-body-group', 'body'])
        ->and($composed)->toBe($alone)
        ->and($diagnostics)->toBe([])
        ->and($placed->groups[0]->canvas->groups[1]->canvas->indicators[0]->imagePath)->toBe(['field', 'body-group', 'body'])
        ->and(findIndicatorPathArea($moved, INDICATOR_PATH_TREATED_OUTLINE))->toBe(['x' => 7, 'y' => 6, 'width' => 8, 'height' => 8, 'count' => 28]);
})->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);

it('names qualified indicator paths among the features every raster draws, beside the groups they are read through', function (string $raster) {
    // What the Engine requires of a session that negotiates the paths.
    $pairing = array_values(array_intersect([RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS,
        RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH], $raster::CAPABILITIES));
    $session = static fn(array $required): RendererSessionConfig => new RendererSessionConfig('Still', sys_get_temp_dir(),
        protocol: RendererProtocolVersion::V2, requiredCapabilities: $required);

    expect($raster::supports(RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH))->toBeTrue()
        ->and($pairing)->toHaveCount(3)
        ->and($session($pairing)->requiredCapabilities)->toBe($pairing)
        ->and(fn() => $session(array_values(array_diff($pairing, [RendererSessionConfig::CANVAS_GROUPS]))))
        ->toThrow(InvalidArgumentException::class, 'canvas_groups');
})->with([CanvasStillRaster::class, FieldStillRaster::class, SceneFrameRaster::class])
    ->skip(fn (): bool => ! hasEngineIndicatorPath(), ENGINE_INDICATOR_PATH_MISSING);
