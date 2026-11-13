<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\PreviewRendererRelay;
use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Editor\Review\FieldStill;
use Ichiloto\Editor\Review\FieldStillRaster;
use Ichiloto\Editor\Review\MapRenderView;
use Ichiloto\Editor\Review\SceneFrameRaster;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasColorTransform;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageBottomFade;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageBudgetExceeded;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposition;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * The stills name the region budget beside group colour, as the Engine pairs them, and keep its terms: a still's
 * canvases are admitted together, groups and all, by the Engine's own accounting, an image costs the crop it is
 * drawn from and one variant for each distinct way the crop is drawn changed, a group's colour treatment among
 * them, and a still over the limits is refused whole. Everything here is synthetic: flat pictures and pictures of
 * noise.
 */

/** What the Engine pairs: a group's colour treatment with canvases, their groups and the region budget. */
const STILL_GROUP_COLOUR_PAIRING = [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_GROUPS,
    RendererSessionConfig::CANVAS_REGION_BUDGET, RendererSessionConfig::CANVAS_GROUP_COLOR];

/** The widest picture the Engine reads, tall enough that two of them exceed 64 MiB decoded, in one flat colour. */
function writeStillBudgetPicture(string $path, array $rgb, int $width = 4096, int $height = 2100): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    $image = imagecreate($width, $height);
    imagecolorallocate($image, ...$rgb);
    imagepng($image, $path);
}

/** A picture whose every pixel differs from its neighbours, some of them partly clear. */
function writeStillBudgetNoise(string $path, int $width, int $height): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            imagesetpixel($image, $x, $y, (int) imagecolorallocatealpha($image, ($x * 37 + $y * 11) % 256, ($x * 5 + $y * 53) % 256,
                ($x * 91 + $y * 29) % 256, ($x + $y) % 5 === 0 ? 20 + ($x * 7 + $y * 3) % 100 : 0));
        }
    }
    imagepng($image, $path);
}

function stillBudgetPixel(GdImage $image, int $x, int $y): array
{
    $pixel = imagecolorat($image, $x, $y);

    return [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255];
}

/** Every pixel of an image, coverage included. */
function readStillBudgetPixels(GdImage $image): array
{
    $pixels = [];
    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            $pixels[] = imagecolorat($image, $x, $y);
        }
    }

    return $pixels;
}

/** The Engine's presenter for a renderer whose READY names the given features. */
function createStillBudgetPresenter(array $capabilities): RendererPresentation
{
    $relay = new PreviewRendererRelay();
    $client = new RendererClient($relay);
    $grid = new RendererGridConfig(2, 1);
    $client->start(new RendererSessionConfig('Still', sys_get_temp_dir(), $grid, RendererProtocolVersion::V2));
    $relay->receive([json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => array_values(array_unique($capabilities))])]);
    $client->pump();

    return new RendererPresentation($client, $grid);
}

/** A colour treatment that scales each channel and adds one offset to all three. */
function createStillBudgetTreatment(float $scale, float $offset): CanvasColorTransform
{
    return new CanvasColorTransform([[$scale, 0, 0], [0, $scale, 0], [0, 0, $scale]], [$offset, $offset, $offset]);
}

/**
 * A raster's still of one 48 x 48 drawing and what the still could not draw: the canvas itself, a frame's screen
 * canvas, or the one world canvas of a map still of the synthetic project's map.
 *
 * @return array{GdImage, list<string>}
 */
function drawStillBudgetDrawing(string $raster, string $project, PresentationCanvas $drawing): array
{
    $still = new $raster($project . '/assets');
    $image = match ($raster) {
        CanvasStillRaster::class => $still->rasterize($drawing, 0x000000),
        SceneFrameRaster::class => $still->rasterize(new SceneFrameComposition(null, canvas: $drawing), new RendererGridConfig(2, 1, 24, 48)),
        FieldStillRaster::class => (static function () use ($still, $project, $drawing): GdImage {
            $map = EditorSession::open($project)->renderMapReview(new MapRenderView('test-map'))->still;

            return $still->rasterize(new FieldStill($map->world, [], $map->region, worldCanvases: [
                new PresentationWorldCanvas('prop', 0, 0, new PresentationSpritePivot(0.0, 0.0), $drawing)]));
        })(),
    };

    return [$image, $still->diagnostics];
}

/** How many pixels of an image show a colour. */
function countStillBudgetPixels(GdImage $image, array $rgb): int
{
    return count(array_keys(readStillBudgetPixels($image), ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2], true));
}

it('names the region budget beside group colour among the features every raster draws, as the Engine pairs them', function (string $raster) {
    $pairing = array_values(array_intersect(STILL_GROUP_COLOUR_PAIRING, $raster::CAPABILITIES));
    $session = static fn(array $capabilities): RendererSessionConfig => new RendererSessionConfig('Still', sys_get_temp_dir(),
        protocol: RendererProtocolVersion::V2, requiredCapabilities: $capabilities);

    expect($raster::supports(RendererSessionConfig::CANVAS_REGION_BUDGET))->toBeTrue()
        ->and($pairing)->toHaveCount(4)
        ->and($session($pairing)->requiredCapabilities)->toBe($pairing)
        // What a producer of the Engine asks of the session it composes for before it gives a group a treatment.
        ->and(PresentationSession::runForSession($raster::supports(...), static fn(): bool => array_all(STILL_GROUP_COLOUR_PAIRING,
            PresentationSession::supportsCapability(...))))->toBeTrue()
        ->and(fn() => $session(array_values(array_diff($pairing, [RendererSessionConfig::CANVAS_REGION_BUDGET]))))
        ->toThrow(InvalidArgumentException::class, 'canvas_region_budget');
})->with([CanvasStillRaster::class, FieldStillRaster::class, SceneFrameRaster::class]);

it('names features the Engine accepts together as one renderer session', function (string $raster) {
    $capabilities = array_values(array_unique($raster::CAPABILITIES));

    expect(new RendererSessionConfig('Still', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
        requiredCapabilities: $capabilities)->requiredCapabilities)->toBe($capabilities);
})->with([FieldStillRaster::class, SceneFrameRaster::class]);

it('is admitted by the Engine with a treated group for a renderer of a raster\'s features, and refused without the region budget', function (string $raster) {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    writeStillBudgetPicture($root . '/Art/flat.png', [230, 20, 10], 10, 10);
    $drawing = new PresentationCanvas(10, 10, [new CanvasImage('art', 'Art/flat.png', new CanvasRectangle(0, 0, 10, 10))]);
    $treated = new PresentationCanvas(20, 10, groups: [new CanvasGroup('turned', 10, 0, $drawing,
        colorTransform: new CanvasColorTransform([[-1, 0, 0], [0, -1, 0], [0, 0, -1]], [1, 1, 1]))]);
    $without = array_values(array_diff($raster::CAPABILITIES, [RendererSessionConfig::CANVAS_REGION_BUDGET]));

    expect(createStillBudgetPresenter($raster::CAPABILITIES)->prepareCanvas($treated)->canvas)->toBe($treated)
        ->and(fn() => createStillBudgetPresenter($without)->prepareCanvas($treated))
        ->toThrow(RendererProtocolException::class, 'canvas_region_budget');

    $still = new CanvasStillRaster($root);
    $image = $still->rasterize($treated, 0x000000);
    expect([stillBudgetPixel($image, 15, 5), stillBudgetPixel($image, 5, 5)])->toBe([[25, 235, 245], [0, 0, 0]])
        ->and($still->diagnostics)->toBe([]);
})->with([CanvasStillRaster::class, FieldStillRaster::class, SceneFrameRaster::class]);

it('admits a still on the crops it draws where its whole pictures exceed the limit of a renderer without the region budget', function () {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    writeStillBudgetPicture($root . '/Art/first.png', [200, 10, 20]);
    writeStillBudgetPicture($root . '/Art/second.png', [10, 20, 210]);
    $canvas = new PresentationCanvas(40, 20, [
        new CanvasImage('first', 'Art/first.png', new CanvasRectangle(0, 0, 20, 20), sourceRect: new SpriteSourceRect(4000, 2000, 10, 10)),
        new CanvasImage('second', 'Art/second.png', new CanvasRectangle(20, 0, 20, 20), sourceRect: new SpriteSourceRect(0, 0, 10, 10)),
    ]);
    $inspect = static fn(Closure $supports): array => PresentationSession::runForSession($supports,
        static fn(): array => CanvasImagePreflight::inspectCanvasResources([$canvas], $root));
    $raster = new CanvasStillRaster($root);
    $image = $raster->rasterize($canvas, 0x000000);

    // The Engine charges a renderer of the raster's features 2 guarded crops, and one without the region budget 2 whole pictures.
    expect(array_sum($inspect(CanvasStillRaster::supports(...))['regions']))->toBe(2 * 14 * 14 * 4)
        ->and(fn() => $inspect(static fn(string $capability): bool => $capability !== RendererSessionConfig::CANVAS_REGION_BUDGET
            && CanvasStillRaster::supports($capability)))->toThrow(CanvasImageBudgetExceeded::class, 'legacy whole-PNG')
        ->and([stillBudgetPixel($image, 10, 10), stillBudgetPixel($image, 30, 10)])->toBe([[200, 10, 20], [10, 20, 210]])
        ->and($raster->diagnostics)->toBe([]);
});

it('lets a picture go once its crops are cut, and the least recently prepared regions once a still needs their room', function () {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    $red = [200, 10, 20];
    $green = [10, 190, 30];
    foreach (['first', 'second', 'third'] as $name) {
        writeStillBudgetPicture($root . "/Art/{$name}.png", $red);
    }
    // A crop of 4000 x 2050 holds 31 MiB: two fit the Engine's 64 MiB of regions, three do not.
    $large = static fn(string $name, int $x): CanvasImage => new CanvasImage($name, "Art/{$name}.png", new CanvasRectangle($x, 0, 64, 32),
        sourceRect: new SpriteSourceRect(0, 0, 4000, 2050));
    $small = new PresentationCanvas(64, 32, [new CanvasImage('corner', 'Art/first.png', new CanvasRectangle(0, 0, 64, 32),
        sourceRect: new SpriteSourceRect(4090, 2090, 6, 6))]);
    $raster = new CanvasStillRaster($root);
    $shown = static fn(PresentationCanvas $canvas): array => stillBudgetPixel($raster->rasterize($canvas, 0x000000), 1, 1);

    expect($shown(new PresentationCanvas(64, 32, [$large('first', 0)])))->toBe($red);
    writeStillBudgetPicture($root . '/Art/first.png', $green);
    // The whole picture was not kept: a crop first drawn now is cut from the picture as it is now.
    expect($shown($small))->toBe($green)
        ->and($shown(new PresentationCanvas(128, 32, [$large('second', 0), $large('third', 64)])))->toBe($red)
        // The two crops of that still took the room of the first still's, which is cut again when it is drawn again.
        ->and($shown(new PresentationCanvas(64, 32, [$large('first', 0)])))->toBe($green)
        ->and($raster->diagnostics)->toBe([]);
});

it('refuses a still whose images exceed the region budget whole, with the Engine\'s account of them, and draws each part that fits', function () {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    writeStillBudgetPicture($root . '/Art/first.png', [200, 10, 20]);
    writeStillBudgetPicture($root . '/Art/second.png', [10, 20, 210]);
    $first = new CanvasImage('first', 'Art/first.png', new CanvasRectangle(0, 0, 64, 32));
    $second = new CanvasImage('second', 'Art/second.png', new CanvasRectangle(64, 0, 64, 32));
    // Named when it is drawn: a refused still draws nothing, so it names nothing.
    $absent = new CanvasImage('absent', 'Art/absent.png', new CanvasRectangle(0, 0, 4, 4), 1);
    $raster = new CanvasStillRaster($root);
    $refusal = null;
    try {
        $raster->rasterize(new PresentationCanvas(128, 32, [$first, $absent], groups: [new CanvasGroup('nested', 0, 0,
            new PresentationCanvas(128, 32, [$second]))]), 0x000000);
    } catch (CanvasImageBudgetExceeded $refusal) {
    }

    // Each whole picture is one region of (4096 + 4) x (2100 + 4) pixels, guard included: two exceed 64 MiB.
    expect($refusal?->getMessage())->toContain('Canvas prepared regions exceed the native 64 MiB / 4096-region limit')
        ->toContain('prepared regions: 2/4096, 69011200/67108864 bytes')
        ->toContain('Art/first.png:0,0,4096,2100=34505600 bytes')
        ->toContain('Art/second.png:0,0,4096,2100=34505600 bytes')
        ->and($raster->diagnostics)->toBe([])
        ->and(stillBudgetPixel($raster->rasterize(new PresentationCanvas(128, 32, [$first]), 0x000000), 1, 1))->toBe([200, 10, 20])
        ->and(stillBudgetPixel($raster->rasterize(new PresentationCanvas(128, 32, [$second, $absent]), 0x000000), 70, 5))->toBe([10, 20, 210])
        ->and($raster->diagnostics)->toHaveCount(1);
});

it('refuses a frame whose screen canvas and overlay together exceed the region budget, though each alone fits', function () {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    writeStillBudgetPicture($root . '/Art/first.png', [200, 10, 20]);
    writeStillBudgetPicture($root . '/Art/second.png', [10, 20, 210]);
    $canvas = new PresentationCanvas(20, 20, [new CanvasImage('first', 'Art/first.png', new CanvasRectangle(0, 0, 10, 20))]);
    $overlay = new PresentationCanvas(20, 20, [new CanvasImage('second', 'Art/second.png', new CanvasRectangle(10, 0, 10, 20))]);
    $grid = new RendererGridConfig(2, 1, 10, 20);
    $raster = new SceneFrameRaster($root);

    expect(fn() => $raster->rasterize(new SceneFrameComposition(null, canvas: $canvas, screenOverlay: $overlay), $grid))
        ->toThrow(CanvasImageBudgetExceeded::class, 'prepared regions')
        ->and(stillBudgetPixel($raster->rasterize(new SceneFrameComposition(null, canvas: $canvas), $grid), 5, 10))->toBe([200, 10, 20])
        ->and(stillBudgetPixel($raster->rasterize(new SceneFrameComposition(null, canvas: $overlay), $grid), 15, 10))->toBe([10, 20, 210])
        ->and($raster->diagnostics)->toBe([]);
});

it('draws a crop to the same pixels as the whole picture gave it, prepared for its still or read for the one drawing', function () {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    writeStillBudgetNoise($root . '/Art/noise.png', 37, 29);
    // Each crop and where it is stretched to, in whole pixels: inside the picture, against its far corner, and the whole of it.
    $parts = [[[5, 3, 17, 11], [2, 4, 31, 19]], [[20, 18, 17, 11], [36, 2, 23, 40]], [null, [3, 26, 28, 17]]];
    $canvas = new PresentationCanvas(64, 48, array_map(static fn(array $part, int $index): CanvasImage => new CanvasImage("part-{$index}",
        'Art/noise.png', new CanvasRectangle(...$part[1]), $index, $part[0] === null ? null : new SpriteSourceRect(...$part[0])),
        $parts, array_keys($parts)));
    $surface = static function (): GdImage {
        $image = imagecreatetruecolor(64, 48);
        imagefilledrectangle($image, 0, 0, 63, 47, 0x203040);

        return $image;
    };
    // The whole picture sampled at each crop's own offset and blended over the background.
    $picture = imagecreatefrompng($root . '/Art/noise.png');
    $expected = $surface();
    foreach ($parts as [$crop, [$left, $top, $width, $height]]) {
        [$x, $y, $cropWidth, $cropHeight] = $crop ?? [0, 0, 37, 29];
        $stretched = imagecreatetruecolor($width, $height);
        imagealphablending($stretched, false);
        imagecopyresampled($stretched, $picture, 0, 0, $x, $y, $width, $height, $cropWidth, $cropHeight);
        imagecopy($expected, $stretched, $left, $top, 0, 0, $width, $height);
    }
    $prepared = new CanvasStillRaster($root);
    $unprepared = new CanvasStillRaster($root);
    $drawn = $surface();
    $unprepared->drawCanvas($drawn, $canvas, 'canvas');

    expect(readStillBudgetPixels($prepared->rasterize($canvas, 0x203040)))->toBe(readStillBudgetPixels($expected))
        ->and(readStillBudgetPixels($drawn))->toBe(readStillBudgetPixels($expected))
        // And the same again from the regions the first still left prepared.
        ->and(readStillBudgetPixels($prepared->rasterize($canvas, 0x203040)))->toBe(readStillBudgetPixels($expected))
        ->and([...$prepared->diagnostics, ...$unprepared->diagnostics])->toBe([]);
});

it('draws a picture the Engine does not read as it did, charged nothing, beside the pictures it admits', function () {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    writeStillBudgetPicture($root . '/Art/flat.png', [200, 10, 20], 10, 10);
    $gif = imagecreate(10, 10);
    imagecolorallocate($gif, 10, 190, 30);
    imagegif($gif, $root . '/Art/flat.gif');
    $canvas = new PresentationCanvas(20, 10, [
        new CanvasImage('read', 'Art/flat.png', new CanvasRectangle(0, 0, 10, 10)),
        new CanvasImage('unread', 'Art/flat.gif', new CanvasRectangle(10, 0, 10, 10), sourceRect: new SpriteSourceRect(2, 2, 4, 4)),
    ]);
    $raster = new CanvasStillRaster($root);
    $image = $raster->rasterize($canvas, 0x000000);

    expect(fn() => CanvasImagePreflight::inspectCanvasResources([$canvas], $root))->toThrow(RuntimeException::class, 'readable PNG')
        ->and([stillBudgetPixel($image, 5, 5), stillBudgetPixel($image, 15, 5)])->toBe([[200, 10, 20], [10, 190, 30]])
        ->and($raster->diagnostics)->toBe([]);
});

it('refuses a still whose crops fit and whose treated variants do not, and admits it untreated or in a treatment that changes nothing', function (string $raster) {
    $project = makeTemporaryProject('ichiloto-still-budget-');
    $rgb = [201, 11, 21];
    writeStillBudgetPicture($project . '/assets/Art/wide.png', $rgb);
    // The whole picture is one region of 33 MiB with its guard, and the same again for each variant of it.
    $drawing = static fn(?CanvasColorTransform $treatment): PresentationCanvas => new PresentationCanvas(48, 48, groups: [
        new CanvasGroup('part', 0, 0, new PresentationCanvas(48, 48, [new CanvasImage('art', 'Art/wide.png', new CanvasRectangle(0, 0, 48, 48))]),
            colorTransform: $treatment)]);
    [$plain, $plainDiagnostics] = drawStillBudgetDrawing($raster, $project, $drawing(null));
    [$unchanged, $unchangedDiagnostics] = drawStillBudgetDrawing($raster, $project, $drawing(createStillBudgetTreatment(1, 0)));
    $refusal = null;
    try {
        drawStillBudgetDrawing($raster, $project, $drawing(createStillBudgetTreatment(0.5, 0)));
    } catch (CanvasImageBudgetExceeded $refusal) {
    }

    expect(countStillBudgetPixels($plain, $rgb))->toBe(48 * 48)
        ->and(readStillBudgetPixels($unchanged))->toBe(readStillBudgetPixels($plain))
        ->and([...$plainDiagnostics, ...$unchangedDiagnostics])->toBe([])
        ->and($refusal?->getMessage())->toContain('Canvas prepared regions exceed the native 64 MiB / 4096-region limit')
        ->toContain('prepared regions: 2/4096, 69011200/67108864 bytes')
        ->toContain('Art/wide.png:0,0,4096,2100=34505600 bytes')
        ->toContain(':color:');
})->with([CanvasStillRaster::class, FieldStillRaster::class, SceneFrameRaster::class]);

it('charges a crop one variant for each distinct treatment it is drawn in, a group\'s own first and its parent\'s after', function () {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    writeStillBudgetPicture($root . '/Art/wide.png', [201, 11, 21]);
    // A crop of 4000 x 1700 is a region of 26 MiB with its guard: the crop and one variant fit 64 MiB, and a second variant does not.
    $crop = new PresentationCanvas(16, 16, [new CanvasImage('art', 'Art/wide.png', new CanvasRectangle(0, 0, 16, 16),
        sourceRect: new SpriteSourceRect(0, 0, 4000, 1700))]);
    $group = static fn(string $id, int $x, PresentationCanvas $canvas, ?CanvasColorTransform $treatment): CanvasGroup => new CanvasGroup(
        $id, $x, 0, $canvas, colorTransform: $treatment);
    $inverted = createStillBudgetTreatment(-1, 1);
    $dimmed = createStillBudgetTreatment(0.5, 0);
    // Turned over within a group that is dimmed, or within one that is turned over again.
    $nested = static fn(CanvasColorTransform $outer): CanvasGroup => $group('outer', 0,
        new PresentationCanvas(16, 16, groups: [$group('inner', 0, $crop, $inverted)]), $outer);
    $raster = new CanvasStillRaster($root);
    $shown = static function (CanvasGroup ...$groups) use ($raster): array {
        $image = $raster->rasterize(new PresentationCanvas(32, 16, groups: $groups), 0x000000);

        return [stillBudgetPixel($image, 8, 8), stillBudgetPixel($image, 24, 8)];
    };

    // Two drawings in one treatment are one variant.
    expect($shown($group('first', 0, $crop, $dimmed), $group('second', 16, $crop, $dimmed)))->toBe([[101, 6, 11], [101, 6, 11]])
        // Dimmed after it is turned over, the crop is drawn as in the one treatment that does both: one variant.
        ->and($shown($nested($dimmed), $group('direct', 16, $crop, createStillBudgetTreatment(-0.5, 0.5))))->toBe([[27, 122, 117], [27, 122, 117]])
        // Turned over twice it is the crop as it is, which costs no variant beside the dimmed one.
        ->and($shown($nested($inverted), $group('dimmed', 16, $crop, $dimmed)))->toBe([[201, 11, 21], [101, 6, 11]])
        // Turned over after it is dimmed is another treatment, and a second variant.
        ->and(fn() => $shown($nested($dimmed), $group('other', 16, $crop, createStillBudgetTreatment(-0.5, 1))))
        ->toThrow(CanvasImageBudgetExceeded::class, 'prepared regions: 3/4096, 81873792/67108864 bytes')
        ->and(fn() => $shown($group('first', 0, $crop, $dimmed), $group('second', 16, $crop, $inverted)))
        ->toThrow(CanvasImageBudgetExceeded::class, 'prepared regions: 3/4096, 81873792/67108864 bytes')
        ->and($raster->diagnostics)->toBe([]);
});

it('charges an image faded toward its bottom edge a variant of its crop, and refuses a still that variant takes over the limit', function () {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    $rgb = [201, 11, 21];
    writeStillBudgetPicture($root . '/Art/wide.png', $rgb);
    $canvas = static fn(?CanvasImageBottomFade $fade): PresentationCanvas => new PresentationCanvas(48, 48, [
        new CanvasImage('art', 'Art/wide.png', new CanvasRectangle(0, 0, 48, 48), bottomFade: $fade)]);
    $raster = new CanvasStillRaster($root);

    $refusal = null;
    try {
        $raster->rasterize($canvas(new CanvasImageBottomFade([0.5, 0.5], 0.25)), 0x000000);
    } catch (CanvasImageBudgetExceeded $refusal) {
    }

    expect($refusal?->getMessage())->toContain('prepared regions: 2/4096, 69011200/67108864 bytes')
        ->toContain(':fade:')
        ->and(countStillBudgetPixels($raster->rasterize($canvas(null), 0x000000), $rgb))->toBe(48 * 48)
        ->and($raster->diagnostics)->toBe([]);
});

it('charges a composite in a treated group its finished surface, and nothing more in a group without a treatment', function () {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    writeStillBudgetPicture($root . '/Art/wide.png', [201, 11, 21]);
    // The crop and its variant take 52 MiB of regions; the treated surface of a 2048 x 2048 composite, guard included, is 16 MiB more.
    $drawing = static fn(bool $composite): PresentationCanvas => new PresentationCanvas(16, 16, [new CanvasImage('art', 'Art/wide.png',
        new CanvasRectangle(0, 0, 16, 16), sourceRect: new SpriteSourceRect(0, 0, 4000, 1700))],
        composites: $composite ? [new CanvasComposite('surface', 2048, 2048, new CanvasRectangle(0, 0, 16, 16), [])] : []);
    $still = static fn(bool $composite, ?CanvasColorTransform $treatment): PresentationCanvas => new PresentationCanvas(16, 16, groups: [
        new CanvasGroup('part', 0, 0, $drawing($composite), colorTransform: $treatment)]);
    $dimmed = createStillBudgetTreatment(0.5, 0);
    $raster = new CanvasStillRaster($root);
    $raster->prepareCanvases([$still(false, $dimmed)]);
    $raster->prepareCanvases([$still(true, null)]);

    $refusal = null;
    try {
        $raster->prepareCanvases([$still(true, $dimmed)]);
    } catch (CanvasImageBudgetExceeded $refusal) {
    }

    expect($refusal?->getMessage())->toContain('prepared regions: 3/4096, 71425344/67108864 bytes')
        ->toContain('composite:')
        ->toContain('=16842816 bytes');
});

it('draws art the Engine does not read in a treated group as it did, charged nothing, and charges the art beside it its variant', function () {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    writeStillBudgetPicture($root . '/Art/flat.png', [201, 11, 21], 10, 10);
    $gif = imagecreate(10, 10);
    imagecolorallocate($gif, 11, 191, 31);
    imagegif($gif, $root . '/Art/flat.gif');
    $drawing = new PresentationCanvas(40, 10, [
        new CanvasImage('read', 'Art/flat.png', new CanvasRectangle(0, 0, 10, 10)),
        new CanvasImage('unread', 'Art/flat.gif', new CanvasRectangle(10, 0, 10, 10)),
        new CanvasImage('absent', 'Art/absent.png', new CanvasRectangle(20, 0, 10, 10)),
        new CanvasImage('outside', 'Art/flat.png', new CanvasRectangle(30, 0, 10, 10), sourceRect: new SpriteSourceRect(6, 6, 8, 8)),
    ]);
    $treated = new PresentationCanvas(40, 10, groups: [new CanvasGroup('turned', 0, 0, $drawing, colorTransform: createStillBudgetTreatment(-1, 1))]);
    $charged = [];
    $inspect = static fn(?Closure $canInspect): array => PresentationSession::runForSession(CanvasStillRaster::supports(...),
        static fn(): array => CanvasImagePreflight::inspectCanvasResources([$treated], $root, $canInspect));
    $raster = new CanvasStillRaster($root);
    $plain = new CanvasStillRaster($root);
    $image = $raster->rasterize($treated, 0x000000);
    $plain->rasterize($drawing, 0x000000);

    // The Engine refuses the group whole; given only the picture it reads, it charges that crop and its treated variant.
    expect(fn() => $inspect(null))->toThrow(RuntimeException::class)
        ->and(array_sum($inspect(static fn(CanvasImage $image): bool => $image->id === 'read')['regions']))->toBe(2 * 14 * 14 * 4)
        ->and([stillBudgetPixel($image, 5, 5), stillBudgetPixel($image, 15, 5), stillBudgetPixel($image, 25, 5), stillBudgetPixel($image, 35, 5)])
        ->toBe([[54, 244, 234], [244, 64, 224], [0, 0, 0], [0, 0, 0]])
        // What is left out is named as it is for the same drawing in no group: the group's name aside, the same words.
        ->and($raster->diagnostics)->toHaveCount(2)
        ->and(str_replace('canvas group turned', 'canvas', $raster->diagnostics))->toBe($plain->diagnostics);
});

it('admits a still\'s canvases together whatever their number, where the Engine refuses as many in one frame', function () {
    $root = reserveTemporaryPath('ichiloto-still-budget-');
    writeStillBudgetPicture($root . '/Art/flat.png', [201, 11, 21], 10, 10);
    // More canvases, each one drawing, than the Engine lets one frame hold.
    $canvases = array_fill(0, 1100, new PresentationCanvas(10, 10, [new CanvasImage('art', 'Art/flat.png', new CanvasRectangle(0, 0, 10, 10))]));
    $raster = new CanvasStillRaster($root);
    $raster->prepareCanvases($canvases);

    expect(fn() => CanvasImagePreflight::inspectCanvases($canvases, $root))->toThrow(InvalidArgumentException::class)
        ->and(stillBudgetPixel($raster->rasterize($canvases[0], 0x000000), 5, 5))->toBe([201, 11, 21])
        ->and($raster->diagnostics)->toBe([]);
});
