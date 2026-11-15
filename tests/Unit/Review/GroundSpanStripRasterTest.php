<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Editor\Review\SceneFrameRaster;
use Ichiloto\Engine\Animations\Timelines\EffectGroundSpanOrientation;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * The Editor's CPU raster draws a captured ground span where the Engine's contract puts it: the whole image
 * between the captured caster ground and the farthest selected target's, its rear (the pivot) on the first and
 * its tip on the second, at one scale and one rigid turn, cropped to its sheet cell and mirrored inside it; and,
 * for a span held upright, mirrored left to right when that leaves its top higher. The cases are
 * {@see listGroundSpanStripFixtures()}; their pictures are the fixtures the native renderer is held to.
 */

/**
 * How the contract lays a case's image on its captured grounds, worked out from the contract alone and by no
 * strip: the one scale that makes the image's rear-to-tip line as long as the ground-to-ground line, the one turn
 * that lays the first on the second, and the mirror left to right the image is drawn in. A span held upright is
 * drawn in whichever of its authored mirror and the other leaves the top of its cell higher on the screen, and is
 * not drawn at all (null) where neither leaves it above the bottom.
 *
 * @param array<string, mixed> $contract The case's crop, pivot, tip, orientation, mirrors and captured grounds.
 * @return array{rear: array{float, float}, scale: float, turn: float, flipX: bool}|null
 */
function fitGroundSpanImage(array $contract): ?array
{
    ['width' => $width, 'height' => $height] = $contract['crop'];
    [$casterX, $casterY] = $contract['casterGround'];
    [$targetX, $targetY] = $contract['targetGround'];
    $fit = static function (bool $flipX) use ($contract, $width, $height, $casterX, $casterY, $targetX, $targetY): array {
        $mirror = static fn(array $point): array => [
            ($flipX ? 1 - $point['x'] : $point['x']) * $width,
            ($contract['flipY'] ? 1 - $point['y'] : $point['y']) * $height,
        ];
        [$rearX, $rearY] = $mirror($contract['pivot']);
        [$tipX, $tipY] = $mirror($contract['tip']);

        return ['rear' => [$rearX, $rearY], 'flipX' => $flipX,
            'scale' => hypot($targetX - $casterX, $targetY - $casterY) / hypot($tipX - $rearX, $tipY - $rearY),
            'turn' => atan2($targetY - $casterY, $targetX - $casterX) - atan2($tipY - $rearY, $tipX - $rearX)];
    };
    $authored = $fit($contract['flipX']);
    if (($contract['orientation'] ?? null) !== EffectGroundSpanOrientation::UPRIGHT->value) {
        return $authored;
    }
    // Turned, the cell's downward side points along (-sin, cos) of the screen: its top is higher while cos is above zero.
    $mirrored = $fit(! $contract['flipX']);
    $higher = cos($mirrored['turn']) > cos($authored['turn']) ? $mirrored : $authored;

    return cos($higher['turn']) > 1e-9 ? $higher : null;
}

/**
 * The texel of the image a point of the arena shows under a fit: the point's offset from the caster's ground,
 * turned back and divided by the scale, from the rear. Null off the image.
 *
 * @param array{rear: array{float, float}, scale: float, turn: float, flipX: bool} $fit
 * @return array{0: int, 1: int, 2: float}|null The texel in the unmirrored crop, and how far the point is from the
 *   nearest texel edge; a point on the image's border, within the last bits of the arithmetic, is given with that distance.
 */
function findGroundSpanTexel(array $contract, array $fit, float $x, float $y): ?array
{
    ['width' => $width, 'height' => $height] = $contract['crop'];
    [$casterX, $casterY] = $contract['casterGround'];
    $sourceX = $fit['rear'][0] + (($x - $casterX) * cos($fit['turn']) + ($y - $casterY) * sin($fit['turn'])) / $fit['scale'];
    $sourceY = $fit['rear'][1] + (-($x - $casterX) * sin($fit['turn']) + ($y - $casterY) * cos($fit['turn'])) / $fit['scale'];
    // How far the point is from the nearest line between texels, the image's own border among them.
    $edge = min(abs($sourceX - round($sourceX)), abs($sourceY - round($sourceY)));
    if ($sourceX < 0 || $sourceX >= $width || $sourceY < 0 || $sourceY >= $height) {
        return $edge < 1e-9 && $sourceX > -0.5 && $sourceX < $width + 0.5 && $sourceY > -0.5 && $sourceY < $height + 0.5 ? [0, 0, $edge] : null;
    }

    return [$fit['flipX'] ? $width - 1 - (int) $sourceX : (int) $sourceX, $contract['flipY'] ? $height - 1 - (int) $sourceY : (int) $sourceY, $edge];
}

/** A fixture as its file holds it. */
function readGroundSpanStripFixture(string $file): array
{
    return json_decode((string) file_get_contents(getGroundSpanStripFixtureDirectory() . '/' . $file), true, flags: JSON_THROW_ON_ERROR);
}

/** One case of a fixture, by its id. */
function findGroundSpanStripCase(string $file, string $id): array
{
    return array_find(readGroundSpanStripFixture($file)['cases'], static fn(array $case): bool => $case['id'] === $id)
        ?? throw new RuntimeException("No case {$id} in {$file}.");
}

/**
 * Each fixture's cases the Engine draws, or those it refuses, as a file and an id.
 *
 * @return array<string, array{string, string}>
 */
function listGroundSpanStripCaseIds(bool $drawn): array
{
    $ids = [];
    foreach (array_keys(listGroundSpanStripFixtures()) as $file) {
        foreach (readGroundSpanStripFixture($file)['cases'] as $case) {
            if (isset($case['canvas']) === $drawn) {
                $ids[$case['id']] = [$file, $case['id']];
            }
        }
    }

    return $ids;
}

/** A texel's colour as a row of the fixture writes it. */
function encodeGroundSpanTexel(array $crop, int $x, int $y): string
{
    return vsprintf('%02x%02x%02xff', getGroundSpanStripTexel($crop, $x, $y));
}

it('keeps each fixture as the Engine composes its ground spans and the raster draws them', function (string $file) {
    $directory = getGroundSpanStripFixtureDirectory();
    $atlas = imagecreatefrompng($directory . '/' . GROUND_SPAN_STRIP_ATLAS);
    $expected = createGroundSpanStripAtlas();
    [$width, $height] = GROUND_SPAN_STRIP_ATLAS_SIZE;
    $different = 0;
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $different += (int) (imagecolorat($atlas, $x, $y) !== imagecolorat($expected, $x, $y));
        }
    }

    // A difference is a change to the Engine's composition or to the raster: see writeGroundSpanStripFixtures().
    expect([imagesx($atlas), imagesy($atlas), $different])->toBe([$width, $height, 0])
        ->and(encodeGroundSpanStripFixture(buildGroundSpanStripFixture($directory, listGroundSpanStripFixtures()[$file])))
        ->toBe(file_get_contents($directory . '/' . $file));
})->with([GROUND_SPAN_STRIP_FIXTURE, GROUND_SPAN_UPRIGHT_FIXTURE]);

it('draws the whole image between the captured grounds at one scale and one turn, cropped and mirrored', function (string $file, string $id) {
    $case = findGroundSpanStripCase($file, $id);
    $contract = $case['contract'];
    $composite = $case['canvas']['composites'][0];
    $operation = $composite['operations'][0];
    ['x' => $left, 'y' => $top, 'width' => $width, 'height' => $height] = $composite['destination'];
    $rows = $case['expectedRgbaHexRows'];
    $border = vsprintf('%02x%02x%02xff', GROUND_SPAN_STRIP_BORDER);
    $fit = fitGroundSpanImage($contract);
    expect($fit)->not->toBeNull();
    $painted = $wrong = $onEdge = $leaked = 0;
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $pixel = substr($rows[$y], $x * 8, 8);
            $texel = findGroundSpanTexel($contract, $fit, $left + $x + 0.5, $top + $y + 0.5);
            $painted += (int) ($pixel !== '00000000');
            $leaked += (int) ($pixel === $border);
            if ($texel !== null && $texel[2] < 1e-9) {
                // A pixel centre on a texel edge belongs to either texel by the last bit of the arithmetic.
                $onEdge++;
                continue;
            }
            $wrong += (int) ($pixel !== ($texel === null ? '00000000' : encodeGroundSpanTexel($contract['crop'], $texel[0], $texel[1])));
        }
    }
    // Every pixel of the composite is the texel the contract puts there, or nothing: none is off by a texel, and
    // none shows the atlas outside the crop.
    expect(['wrong' => $wrong, 'on a texel edge' => $onEdge, 'outside the crop' => $leaked])
        ->toBe(['wrong' => 0, 'on a texel edge' => 0, 'outside the crop' => 0])
        ->and($painted)->toBeGreaterThan(300)
        // The mirrors the Engine composed are the contract's: the author's, and for an upright span the higher one.
        ->and([$operation['flipX'], $operation['flipY']])->toBe([$fit['flipX'], $contract['flipY']]);
    if (($contract['orientation'] ?? null) === EffectGroundSpanOrientation::UPRIGHT->value) {
        // Upright: the strip runs forward along the screen, so the top of the image's cell is above its bottom.
        expect($operation['geometry']['axis'][0])->toBeGreaterThan(0.0);
    }

    // The image is drawn at more than twice its size, so the pixel a ground lies in shows the texel of its point.
    $texelAt = static fn(array $point): array => [(int) ($point['x'] * $contract['crop']['width']), (int) ($point['y'] * $contract['crop']['height'])];
    $pixelAt = static function (array $ground) use ($rows, $left, $top, $width, $height): ?string {
        [$x, $y] = [(int) floor($ground[0]) - $left, (int) floor($ground[1]) - $top];

        return $x >= 0 && $x < $width && $y >= 0 && $y < $height ? substr($rows[$y], $x * 8, 8) : null;
    };
    $geometry = $operation['geometry'];
    expect($geometry['length'] / $contract['crop']['width'])->toBeGreaterThan(2.0)
        ->toEqualWithDelta($geometry['height'] / $contract['crop']['height'], 1e-9)
        ->and($geometry['knots'])->toEqual([['u' => 0, 'offset' => 0], ['u' => 1, 'offset' => 0]])
        ->and($pixelAt($contract['targetGround']))->toBe(encodeGroundSpanTexel($contract['crop'], ...$texelAt($contract['tip'])));
    // A caster past the arena's edge has no pixel; its ground is still where the rest of the picture puts the rear.
    if ($pixelAt($contract['casterGround']) !== null) {
        expect($pixelAt($contract['casterGround']))->toBe(encodeGroundSpanTexel($contract['crop'], ...$texelAt($contract['pivot'])));
    } else {
        expect($id)->toBe('clipped-west-ray');
    }
})->with(listGroundSpanStripCaseIds(drawn: true));

it('draws an upright span as the span already drawn for its side: as authored where that is upright, mirrored where it is not', function (string $upright, string $drawn) {
    $held = findGroundSpanStripCase(GROUND_SPAN_UPRIGHT_FIXTURE, $upright);
    $qualified = findGroundSpanStripCase(GROUND_SPAN_STRIP_FIXTURE, $drawn);

    // The same canvas from the Engine, mirrors and all, and so the same picture from the raster.
    expect($held['contract']['casterGround'])->toBe($qualified['contract']['casterGround'])
        ->and($held['canvas'])->toBe($qualified['canvas'])
        ->and($held['expectedRgbaHexRows'])->toBe($qualified['expectedRgbaHexRows']);
})->with([
    'cast west, as drawn' => ['upright-west-ray', 'west-ray'],
    'cast east, where the author mirrored it' => ['upright-east-ray', 'east-ray-mirrored'],
    'cast west with a mirror authored, which upright takes off' => ['upright-west-ray-authored-mirror', 'west-ray'],
]);

it('draws nothing for an upright span neither mirror holds top side up, which the Engine refuses in its words', function (string $file, string $id) {
    $case = findGroundSpanStripCase($file, $id);
    $authored = array_find(listGroundSpanStripFixtures()[$file], static fn(array $entry): bool => $entry['id'] === $id);

    expect($case)->not->toHaveKey('canvas')->not->toHaveKey('expectedRgbaHexRows')
        ->and($case['refusal'])->toContain('upright cannot keep this source top above its bottom at the captured endpoints')
        ->and(fn() => composeGroundSpanStripCase($authored))->toThrow(InvalidArgumentException::class, $case['refusal'])
        // The contract, read alone, has no fit for it either.
        ->and(fitGroundSpanImage($case['contract']))->toBeNull();
})->with(listGroundSpanStripCaseIds(drawn: false));

it('names texture strips among what the still rasters draw, as the Engine asks before composing a ground span', function () {
    expect(CanvasStillRaster::supports(RendererSessionConfig::CANVAS_TEXTURE_STRIP))->toBeTrue()
        ->and(SceneFrameRaster::supports(RendererSessionConfig::CANVAS_TEXTURE_STRIP))->toBeTrue()
        ->and(SceneFrameRaster::supports(RendererSessionConfig::CANVAS_COMPOSITING))->toBeTrue();
});
