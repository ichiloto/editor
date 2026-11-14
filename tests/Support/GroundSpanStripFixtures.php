<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Engine\Animations\Timelines\EffectGroundSpan;
use Ichiloto\Engine\Animations\Timelines\EffectGroundSpanOrientation;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleEffectAnchorSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleEffectTargetGroup;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleGroundSpan;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

/**
 * The captured ground span as each of its consumers draws it, on synthetic input: the Engine composes each
 * case's texture strip from captured grounds, the Editor's CPU raster draws it, and the picture is kept beside
 * the canvas as the fixture the native renderer is held to (`fixtures/ground-span-strip` in the renderer holds
 * the same files). The first fixture holds spans that name no orientation; the second, spans the Engine holds
 * upright, and one it refuses. Nothing here is a game's art or a battle's layout.
 */
const GROUND_SPAN_STRIP_ATLAS = 'ground-span-strip-fixture.png';
const GROUND_SPAN_STRIP_FIXTURE = 'ground-span-strip-fixture.json';
const GROUND_SPAN_UPRIGHT_FIXTURE = 'ground-span-upright-fixture.json';

/** The atlas's size and the colour of every texel outside its crops, which no strip may sample. */
const GROUND_SPAN_STRIP_ATLAS_SIZE = [32, 16];
const GROUND_SPAN_STRIP_BORDER = [200, 5, 220];

/** A wide crop and a tall one, neither square, neither at the atlas's corner. */
const GROUND_SPAN_STRIP_WIDE = ['x' => 3, 'y' => 2, 'width' => 16, 'height' => 4];
const GROUND_SPAN_STRIP_TALL = ['x' => 24, 'y' => 3, 'width' => 4, 'height' => 10];

/** The smallest battle canvas the Engine lays out, one logical pixel to a UI cell, with room below it. */
const GROUND_SPAN_STRIP_ARENA = [BattleScreen::WIDTH + 1, BattleScreen::HEIGHT + 12];

/** Where the fixtures' files are kept. */
function getGroundSpanStripFixtureDirectory(): string
{
    return dirname(__DIR__) . '/Fixtures/GroundSpanStrip';
}

/**
 * The cases: the image's rear (its pivot) and tip as fractions of its crop, its flips, and the captured grounds
 * in arena pixels, fractions throughout. A rear and tip at texel centres, with the image drawn at more than twice
 * its size, put the rear texel under the caster's ground and the tip texel under the target's whatever the ray.
 *
 * @return list<array{id: string, crop: array{x: int, y: int, width: int, height: int}, pivot: array{x: float, y: float},
 *   tip: array{x: float, y: float}, flipX: bool, flipY: bool, caster: array{float, float}, targets: list<array{float, float}>}>
 */
function listGroundSpanStripCases(): array
{
    $wide = GROUND_SPAN_STRIP_WIDE;
    $tall = GROUND_SPAN_STRIP_TALL;
    // Texel (14, 2) of the wide crop is its rear and texel (2, 1) its tip; (1, 8) and (2, 1) of the tall one.
    $wideRear = ['x' => 14.5 / 16, 'y' => 2.5 / 4];
    $wideTip = ['x' => 2.5 / 16, 'y' => 1.5 / 4];

    return [
        ['id' => 'west-ray', 'crop' => $wide, 'pivot' => $wideRear, 'tip' => $wideTip, 'flipX' => false, 'flipY' => false,
            'caster' => [64.375, 30.625], 'targets' => [[33.25, 26.875]]],
        // The same art cast the other way: mirrored it stays upright, unmirrored it is turned right round.
        ['id' => 'east-ray-mirrored', 'crop' => $wide, 'pivot' => $wideRear, 'tip' => $wideTip, 'flipX' => true, 'flipY' => false,
            'caster' => [17.625, 31.25], 'targets' => [[49.5, 25.125]]],
        ['id' => 'east-ray-turned', 'crop' => $wide, 'pivot' => $wideRear, 'tip' => $wideTip, 'flipX' => false, 'flipY' => false,
            'caster' => [17.625, 31.25], 'targets' => [[49.5, 25.125]]],
        // A caster past the arena's right edge: the arena clips the picture and the image keeps its size.
        ['id' => 'clipped-west-ray', 'crop' => $wide, 'pivot' => $wideRear, 'tip' => $wideTip, 'flipX' => false, 'flipY' => false,
            'caster' => [150.5, 20.25], 'targets' => [[104.625, 14.5]]],
        // A tall crop on a steep ray, mirrored top to bottom and clipped by the arena's lower edge.
        ['id' => 'steep-tall-crop', 'crop' => $tall, 'pivot' => ['x' => 1.5 / 4, 'y' => 8.5 / 10], 'tip' => ['x' => 2.5 / 4, 'y' => 1.5 / 10],
            'flipX' => false, 'flipY' => true, 'caster' => [60.5, 44.75], 'targets' => [[38.875, 20.25]]],
        // Three targets and both mirrors, the rear and tip between texel centres: the farthest ground X at the group's depth.
        ['id' => 'group-both-mirrors', 'crop' => $wide, 'pivot' => ['x' => 0.9, 'y' => 0.6], 'tip' => ['x' => 0.2, 'y' => 0.3],
            'flipX' => true, 'flipY' => true, 'caster' => [90.5, 20.125], 'targets' => [[60.25, 36.5], [48.75, 30.25], [55.0, 28.0]]],
    ];
}

/**
 * The upright cases: the Engine's `upright` orientation on the first fixture's art and grounds, so each side a
 * cast can come from has its picture, and an image no mirror holds upright along its ray.
 *
 * @return list<array<string, mixed>> As {@see listGroundSpanStripCases()}, each with its orientation.
 */
function listGroundSpanUprightCases(): array
{
    $upright = EffectGroundSpanOrientation::UPRIGHT->value;
    $wide = ['crop' => GROUND_SPAN_STRIP_WIDE, 'pivot' => ['x' => 14.5 / 16, 'y' => 2.5 / 4], 'tip' => ['x' => 2.5 / 16, 'y' => 1.5 / 4], 'orientation' => $upright];
    $tall = ['crop' => GROUND_SPAN_STRIP_TALL, 'pivot' => ['x' => 1.5 / 4, 'y' => 8.5 / 10], 'tip' => ['x' => 2.5 / 4, 'y' => 1.5 / 10], 'orientation' => $upright];

    return [
        // The first fixture's west ray: the image is upright as drawn, and upright leaves it so.
        ['id' => 'upright-west-ray', ...$wide, 'flipX' => false, 'flipY' => false, 'caster' => [64.375, 30.625], 'targets' => [[33.25, 26.875]]],
        // Its east ray, with no mirror authored: upright mirrors the image where rigid turned it over.
        ['id' => 'upright-east-ray', ...$wide, 'flipX' => false, 'flipY' => false, 'caster' => [17.625, 31.25], 'targets' => [[49.5, 25.125]]],
        // An authored mirror cast west would be turned over; upright takes the mirror back off.
        ['id' => 'upright-west-ray-authored-mirror', ...$wide, 'flipX' => true, 'flipY' => false, 'caster' => [64.375, 30.625], 'targets' => [[33.25, 26.875]]],
        // An authored mirror top to bottom is the author's and stays, on either choice of the other mirror.
        ['id' => 'upright-east-ray-flipped-down', ...$wide, 'flipX' => false, 'flipY' => true, 'caster' => [17.625, 31.25], 'targets' => [[49.5, 25.125]]],
        // A tall image on a steep ray: the mirror that leaves its top higher is the one taken.
        ['id' => 'upright-steep-tall-crop', ...$tall, 'flipX' => false, 'flipY' => false, 'caster' => [60.5, 44.75], 'targets' => [[38.875, 20.25]]],
        // A target straight above the caster: the image stands on its end, its top a little above its bottom. (The
        // odd sixteenths keep every pixel centre off a texel edge, which a rise in quarters puts some exactly on.)
        ['id' => 'upright-vertical-ray', ...$wide, 'flipX' => false, 'flipY' => false, 'caster' => [70.5, 40.25], 'targets' => [[70.5, 12.8125]]],
        // The same ray with the image's rear and tip level in its cell: on its end exactly, neither mirror has its
        // top above its bottom, and the Engine refuses to draw it.
        ['id' => 'upright-vertical-ray-level-image', ...$wide, 'tip' => ['x' => 2.5 / 16, 'y' => 2.5 / 4], 'flipX' => false, 'flipY' => false,
            'caster' => [70.5, 40.25], 'targets' => [[70.5, 12.8125]]],
    ];
}

/** The colour of a crop's texel: its column in red, its row in green, and which crop in blue. Every texel is opaque. */
function getGroundSpanStripTexel(array $crop, int $x, int $y): array
{
    return [20 + 9 * $x, 30 + 15 * $y, $crop === GROUND_SPAN_STRIP_WIDE ? 60 : 160];
}

/** The atlas: the two crops, each texel its own colour, in a border no strip may sample. */
function createGroundSpanStripAtlas(): GdImage
{
    [$width, $height] = GROUND_SPAN_STRIP_ATLAS_SIZE;
    $atlas = imagecreatetruecolor($width, $height);
    imagefilledrectangle($atlas, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($atlas, ...GROUND_SPAN_STRIP_BORDER));
    foreach ([GROUND_SPAN_STRIP_WIDE, GROUND_SPAN_STRIP_TALL] as $crop) {
        for ($y = 0; $y < $crop['height']; $y++) {
            for ($x = 0; $x < $crop['width']; $x++) {
                imagesetpixel($atlas, $crop['x'] + $x, $crop['y'] + $y, (int) imagecolorallocate($atlas, ...getGroundSpanStripTexel($crop, $x, $y)));
            }
        }
    }

    return $atlas;
}

/** A case's capture as the graphical battle holds it: the selected targets' grounds and the caster's. */
function captureGroundSpanStripCase(array $case): BattleEffectAnchorSnapshot
{
    $targets = $bounds = $grounds = [];
    foreach ($case['targets'] as [$x, $y]) {
        $target = new Character('Synthetic target', 1, new Stats(currentHp: 1, totalHp: 1));
        $targets[] = $target;
        // A ground span reads the grounds alone; every body is the same box.
        $bounds[spl_object_id($target)] = new CanvasRectangle(0, 0, 4, 6);
        $grounds[spl_object_id($target)] = new Vector2($x, $y);
    }

    return new BattleEffectAnchorSnapshot(BattleEffectTargetGroup::collectTargets($targets, $bounds, $grounds), new Vector2(...$case['caster']));
}

/** A case's canvas as the Engine composes it: the arena, and the ground span's one texture strip composite. */
function composeGroundSpanStripCase(array $case): PresentationCanvas
{
    [$width, $height] = GROUND_SPAN_STRIP_ARENA;
    $span = [EffectGroundSpan::TIP_FIELD => $case['tip'], ...(isset($case['orientation']) ? [EffectGroundSpan::ORIENTATION_FIELD => $case['orientation']] : [])];
    $composite = GraphicalBattleGroundSpan::compose('ground-span', GROUND_SPAN_STRIP_ATLAS, new SpriteSourceRect(...$case['crop']),
        captureGroundSpanStripCase($case), new BattleCanvasLayout($width, $height, 1, 1),
        [EffectGroundSpan::FIELD => $span, 'pivot' => $case['pivot'], 'flipX' => $case['flipX'], 'flipY' => $case['flipY'], 'depth' => 'behind']);
    if (! $composite instanceof CanvasComposite) {
        throw new LogicException(sprintf('Ground span case %s lies outside the arena.', $case['id']));
    }

    return new PresentationCanvas($width, $height, composites: [$composite]);
}

/**
 * The CPU raster's picture of a canvas's one composite, over nothing: each row of the composite's own surface as
 * straight RGBA hex, a pixel nothing covers as `00000000`.
 *
 * @return list<string>
 */
function rasterizeGroundSpanStripComposite(PresentationCanvas $canvas, string $assetRoot): array
{
    $surface = imagecreatetruecolor($canvas->width, $canvas->height);
    imagealphablending($surface, false);
    imagefilledrectangle($surface, 0, 0, $canvas->width - 1, $canvas->height - 1, (int) imagecolorallocatealpha($surface, 0, 0, 0, 127));
    imagealphablending($surface, true);
    $raster = new CanvasStillRaster($assetRoot);
    $raster->prepareCanvases([$canvas]);
    $raster->drawCanvas($surface, $canvas, 'canvas');
    if ($raster->diagnostics !== []) {
        throw new LogicException('The still left something out: ' . implode(' ', $raster->diagnostics));
    }
    $destination = $canvas->composites[0]->destination;
    $rows = [];
    for ($y = 0; $y < $destination->height; $y++) {
        $row = '';
        for ($x = 0; $x < $destination->width; $x++) {
            $pixel = imagecolorat($surface, (int) $destination->x + $x, (int) $destination->y + $y);
            $coverage = 127 - ($pixel >> 24);
            $row .= $coverage === 0 ? '00000000' : sprintf('%06x%02x', $pixel & 0xFFFFFF, (int) round($coverage * 255 / 127));
        }
        $rows[] = $row;
    }

    return $rows;
}

/**
 * A fixture: every case's inputs, the canvas the Engine composes from them, what the contract places where
 * (the captured grounds and the texels of the image's rear and tip), and the CPU raster's picture of the composite.
 * A case the Engine refuses to compose holds the Engine's refusal in place of a canvas and a picture.
 *
 * @param list<array<string, mixed>> $cases The cases, as {@see listGroundSpanStripCases()} gives them.
 * @return array{version: int, asset: string, cases: list<array<string, mixed>>}
 */
function buildGroundSpanStripFixture(string $assetRoot, array $cases): array
{
    $built = [];
    foreach ($cases as $case) {
        $snapshot = captureGroundSpanStripCase($case);
        $caster = $snapshot->getCasterGround();
        $target = $snapshot->getGroundSpanEndpoint();
        $entry = [
            'id' => $case['id'],
            'contract' => [
                'crop' => $case['crop'], 'pivot' => $case['pivot'], 'tip' => $case['tip'],
                ...(isset($case['orientation']) ? ['orientation' => $case['orientation']] : []),
                'flipX' => $case['flipX'], 'flipY' => $case['flipY'],
                'casterGround' => [$caster->x, $caster->y], 'targetGround' => [$target->x, $target->y],
            ],
        ];
        try {
            $canvas = composeGroundSpanStripCase($case);
            $built[] = [...$entry, 'canvas' => $canvas->toArray(), 'expectedRgbaHexRows' => rasterizeGroundSpanStripComposite($canvas, $assetRoot)];
        } catch (InvalidArgumentException $refusal) {
            $built[] = [...$entry, 'refusal' => $refusal->getMessage()];
        }
    }

    return ['version' => 1, 'asset' => GROUND_SPAN_STRIP_ATLAS, 'cases' => $built];
}

/** Each fixture file's cases, by the file. */
function listGroundSpanStripFixtures(): array
{
    return [GROUND_SPAN_STRIP_FIXTURE => listGroundSpanStripCases(), GROUND_SPAN_UPRIGHT_FIXTURE => listGroundSpanUprightCases()];
}

/** The fixture as its file holds it. */
function encodeGroundSpanStripFixture(array $fixture): string
{
    return json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * Writes the fixtures afresh: each file's cases, drawn from the atlas, which is written only where there is none
 * so the file the renderer's hashes name stays the file. For when the Engine's composition or the raster's
 * picture is changed on purpose; the renderer's copies are then replaced with these.
 */
function writeGroundSpanStripFixtures(): void
{
    $directory = getGroundSpanStripFixtureDirectory();
    if (! is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }
    if (! is_file($directory . '/' . GROUND_SPAN_STRIP_ATLAS)) {
        imagepng(createGroundSpanStripAtlas(), $directory . '/' . GROUND_SPAN_STRIP_ATLAS);
    }
    foreach (listGroundSpanStripFixtures() as $file => $cases) {
        file_put_contents($directory . '/' . $file, encodeGroundSpanStripFixture(buildGroundSpanStripFixture($directory, $cases)));
    }
}
