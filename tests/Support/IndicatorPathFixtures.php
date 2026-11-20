<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasColorTransform;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorReferences;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;

/**
 * Qualified indicator paths as each of their consumers draws them, on synthetic input. The Engine's fixture
 * (`indicator-path-fixture.json`, unchanged) holds four frames its model composed and four invalid wire frames;
 * the Editor's CPU raster draws the four, and its pictures are kept beside them as the fixture the native
 * renderer is compared with (`fixtures/indicator-path` in the renderer holds the same files). Each frame is
 * drawn twice: from the Engine's own sprite, which the frames draw reduced, and from a sprite of the frames'
 * own size, which no raster has to resample. Nothing here is a game's art or a battle's layout.
 */
const INDICATOR_PATH_FIXTURE = 'indicator-path-fixture.json';
const INDICATOR_PATH_RASTER_FIXTURE = 'indicator-path-cpu-raster.json';

/** The Engine's fixture as its owner published it (R2, `indicator-path-r2-fixture.json`, kept under the name above). */
const INDICATOR_PATH_FIXTURE_SHA256 = 'e3fafb10dc1e4795815fc8304a84faaa7491df3aa2033d8c09095790d69e412d';

/** The sprite the Engine's frames name, and the one of the frames' own 8 by 8 size drawn in its place. */
const INDICATOR_PATH_ENGINE_SPRITE = 'test-sprite.png';
const INDICATOR_PATH_UNIT_SPRITE = 'indicator-path-unit-sprite.png';

/** What the stills are drawn over (0xRRGGBB): a colour no drawing of the frames has. */
const INDICATOR_PATH_BACKGROUND = 0x101820;

/** Why an indicator path test is skipped: the Engine installed with the Editor predates the contract. */
const ENGINE_INDICATOR_PATH_MISSING = 'The Engine in use has no qualified indicator paths; set ICHILOTO_ENGINE_SRC to one that has.';

/** Whether the Engine in use resolves qualified indicator paths. */
function hasEngineIndicatorPath(): bool
{
    return class_exists(CanvasIndicatorReferences::class);
}

function getIndicatorPathFixtureDirectory(): string
{
    return dirname(__DIR__) . '/Fixtures/IndicatorPath';
}

/** The Engine's fixture as its file holds it. */
function readIndicatorPathFixture(): array
{
    return json_decode((string) file_get_contents(getIndicatorPathFixtureDirectory() . '/' . INDICATOR_PATH_FIXTURE), true, flags: JSON_THROW_ON_ERROR);
}

/** One of the fixture's frames as its wire holds it, by the list it is in and its id. */
function findIndicatorPathWire(string $list, string $id): array
{
    return array_find(readIndicatorPathFixture()[$list], static fn(array $case): bool => $case['id'] === $id)['canvas'];
}

/**
 * The colour of one texel of the unit sprite, or null where it is clear. Every texel is opaque or clear, so no
 * raster's precision of coverage shows; the clear ones lie inside the border an outline covers.
 *
 * @return array{0: int, 1: int, 2: int}|null
 */
function getIndicatorPathUnitTexel(int $x, int $y): ?array
{
    $inside = $x >= 2 && $x <= 5 && $y >= 2 && $y <= 5;

    return $inside && ($x + $y) % 2 === 0 ? null : [20 + 30 * $x, 236 - 25 * $y, 12 + 15 * ($x + $y)];
}

/** The unit sprite: 8 by 8, the size the frames draw their images at. */
function createIndicatorPathUnitSprite(): GdImage
{
    $sprite = imagecreatetruecolor(8, 8);
    imagealphablending($sprite, false);
    imagesavealpha($sprite, true);
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $texel = getIndicatorPathUnitTexel($x, $y);
            imagesetpixel($sprite, $x, $y, $texel === null ? 127 << 24 : ($texel[0] << 16) | ($texel[1] << 8) | $texel[2]);
        }
    }

    return $sprite;
}

/** A frame's wire with every image drawn from another sprite. */
function replaceIndicatorPathSprite(array $wire, string $asset): array
{
    foreach ($wire['images'] ?? [] as $index => $image) {
        $wire['images'][$index]['asset'] = $asset;
    }
    foreach ($wire['groups'] ?? [] as $index => $group) {
        $wire['groups'][$index]['canvas'] = replaceIndicatorPathSprite($group['canvas'], $asset);
    }

    return $wire;
}

/**
 * A canvas of the Engine's wire read into the Engine's model: images, fills, indicators with their paths, and
 * groups. Any other drawing in the wire is refused, so a frame never loses part of itself on the way in. A
 * frame the model refuses is refused here, in the Engine's words.
 */
function createIndicatorPathCanvas(array $wire): PresentationCanvas
{
    $rectangle = static fn(array $area): CanvasRectangle => new CanvasRectangle($area['x'], $area['y'], $area['width'], $area['height']);
    foreach (['textLayers', 'labels'] as $kind) {
        if (($wire[$kind] ?? []) !== []) {
            throw new LogicException(sprintf('The indicator path frames hold images, fills, indicators and groups; this one also holds %s.', $kind));
        }
    }

    return new PresentationCanvas($wire['width'], $wire['height'],
        array_map(CanvasImage::fromArray(...), $wire['images'] ?? []),
        array_map(static fn(array $indicator): CanvasIndicator => new CanvasIndicator($indicator['id'], $indicator['imageId'],
            CanvasIndicatorKind::from($indicator['kind']), $rectangle($indicator['bounds']), $indicator['strokeWidth'],
            PresentationColor::createFromArray($indicator['color']), $indicator['layer'], $indicator['imagePath'] ?? null),
            $wire['indicators'] ?? []),
        composites: array_map(static fn(array $composite): CanvasComposite => new CanvasComposite($composite['id'], $composite['width'],
            $composite['height'], $rectangle($composite['destination']),
            array_map(static fn(array $operation): CanvasCompositeOperation => new CanvasCompositeOperation($operation), $composite['operations']),
            $composite['layer']), $wire['composites'] ?? []),
        groups: array_map(static fn(array $group): CanvasGroup => new CanvasGroup($group['id'], $group['origin']['x'], $group['origin']['y'],
            createIndicatorPathCanvas($group['canvas']), $group['layer'], isset($group['clipRect']) ? $rectangle($group['clipRect']) : null,
            isset($group['colorTransform']) ? new CanvasColorTransform($group['colorTransform']['matrix'], $group['colorTransform']['offset']) : null),
            $wire['groups'] ?? []));
}

/**
 * A still of a canvas over the fixture's background as rows of `rrggbbaa`, one row a line of the picture.
 *
 * @return array{0: list<string>, 1: list<string>} The rows, and what the still left out.
 */
function drawIndicatorPathRows(PresentationCanvas $canvas): array
{
    $raster = new CanvasStillRaster(getIndicatorPathFixtureDirectory());
    $image = $raster->rasterize($canvas, INDICATOR_PATH_BACKGROUND);
    $rows = [];
    for ($y = 0; $y < imagesy($image); $y++) {
        $row = '';
        for ($x = 0; $x < imagesx($image); $x++) {
            $pixel = imagecolorat($image, $x, $y);
            $row .= sprintf('%06x%02x', $pixel & 0xFFFFFF, (int) round((127 - ($pixel >> 24)) * 255 / 127));
        }
        $rows[] = $row;
    }

    return [$rows, $raster->diagnostics];
}

/**
 * The CPU raster's picture of each positive frame, drawn from each sprite, as the file beside the Engine's
 * fixture holds them.
 */
function buildIndicatorPathRasterFixture(): array
{
    $pictures = [];
    foreach (readIndicatorPathFixture()['cases'] as $case) {
        foreach ([INDICATOR_PATH_ENGINE_SPRITE, INDICATOR_PATH_UNIT_SPRITE] as $sprite) {
            $pictures[] = ['id' => $case['id'], 'sprite' => $sprite,
                'expectedRgbaHexRows' => drawIndicatorPathRows(createIndicatorPathCanvas(replaceIndicatorPathSprite($case['canvas'], $sprite)))[0]];
        }
    }

    return [
        'contract' => 'canvas_indicator_image_path',
        'source' => 'The Editor\'s CanvasStillRaster over the frames of ' . INDICATOR_PATH_FIXTURE . ', each image drawn from the named sprite.',
        'background' => ['r' => INDICATOR_PATH_BACKGROUND >> 16, 'g' => (INDICATOR_PATH_BACKGROUND >> 8) & 255, 'b' => INDICATOR_PATH_BACKGROUND & 255],
        'pictures' => $pictures,
    ];
}

function encodeIndicatorPathRasterFixture(array $fixture): string
{
    return json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * Writes the unit sprite and the raster's pictures afresh. For when the raster's picture of these frames is
 * changed on purpose; the renderer's copies are then replaced with these. The Engine's fixture and its sprite
 * are their owner's and are never written here.
 */
function writeIndicatorPathRasterFixture(): void
{
    $directory = getIndicatorPathFixtureDirectory();
    imagepng(createIndicatorPathUnitSprite(), $directory . '/' . INDICATOR_PATH_UNIT_SPRITE);
    file_put_contents($directory . '/' . INDICATOR_PATH_RASTER_FIXTURE, encodeIndicatorPathRasterFixture(buildIndicatorPathRasterFixture()));
}
