<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\PreviewRendererRelay;
use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Editor\Review\FieldStillRaster;
use Ichiloto\Editor\Review\SceneFrameRaster;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasColorTransform;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageBudgetExceeded;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
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
 * canvases are admitted together by the Engine's own accounting, an image costs the crop it is drawn from, and a
 * still over the limits is refused whole. Everything here is synthetic: flat pictures and pictures of noise.
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
        static fn(): array => CanvasImagePreflight::inspect($canvas->images, $root));
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

    expect(fn() => CanvasImagePreflight::inspect($canvas->images, $root))->toThrow(RuntimeException::class, 'readable PNG')
        ->and([stillBudgetPixel($image, 5, 5), stillBudgetPixel($image, 15, 5)])->toBe([[200, 10, 20], [10, 190, 30]])
        ->and($raster->diagnostics)->toBe([]);
});
