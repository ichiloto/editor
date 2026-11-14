<?php

declare(strict_types=1);

use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Editor\Review\SceneFrameRaster;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * The Editor's CPU raster draws a captured ground span where the Engine's contract puts it: the whole image
 * between the captured caster ground and the farthest selected target's, its rear (the pivot) on the first and
 * its tip on the second, at one scale and one rigid turn, cropped to its sheet cell and mirrored inside it. The
 * cases are {@see listGroundSpanStripCases()}; their picture is the fixture the native renderer is held to.
 */

/**
 * The texel of the image a point of the arena shows, worked out from the contract alone and by no strip: the
 * point's offset from the caster's ground is turned back by the one turn that lays the image's rear-to-tip line
 * on the ground-to-ground line, and divided by the one scale that makes the two as long. Null off the image.
 *
 * @param array<string, mixed> $contract The case's crop, pivot, tip, mirrors and captured grounds.
 * @return array{0: int, 1: int, 2: float}|null The texel in the unmirrored crop, and how far the point is from the nearest texel edge.
 */
function findGroundSpanTexel(array $contract, float $x, float $y): ?array
{
    ['width' => $width, 'height' => $height] = $contract['crop'];
    $mirror = static fn(array $point): array => [
        ($contract['flipX'] ? 1 - $point['x'] : $point['x']) * $width,
        ($contract['flipY'] ? 1 - $point['y'] : $point['y']) * $height,
    ];
    [$rearX, $rearY] = $mirror($contract['pivot']);
    [$tipX, $tipY] = $mirror($contract['tip']);
    [$casterX, $casterY] = $contract['casterGround'];
    [$targetX, $targetY] = $contract['targetGround'];
    $scale = hypot($targetX - $casterX, $targetY - $casterY) / hypot($tipX - $rearX, $tipY - $rearY);
    $turn = atan2($targetY - $casterY, $targetX - $casterX) - atan2($tipY - $rearY, $tipX - $rearX);
    $sourceX = $rearX + (($x - $casterX) * cos($turn) + ($y - $casterY) * sin($turn)) / $scale;
    $sourceY = $rearY + (-($x - $casterX) * sin($turn) + ($y - $casterY) * cos($turn)) / $scale;
    if ($sourceX < 0 || $sourceX >= $width || $sourceY < 0 || $sourceY >= $height) {
        return null;
    }
    $edge = min($sourceX - floor($sourceX), ceil($sourceX) - $sourceX, $sourceY - floor($sourceY), ceil($sourceY) - $sourceY);

    return [$contract['flipX'] ? $width - 1 - (int) $sourceX : (int) $sourceX, $contract['flipY'] ? $height - 1 - (int) $sourceY : (int) $sourceY, $edge];
}

/** The fixture as its file holds it. */
function readGroundSpanStripFixture(): array
{
    return json_decode((string) file_get_contents(getGroundSpanStripFixtureDirectory() . '/ground-span-strip-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** A texel's colour as a row of the fixture writes it. */
function encodeGroundSpanTexel(array $crop, int $x, int $y): string
{
    return vsprintf('%02x%02x%02xff', getGroundSpanStripTexel($crop, $x, $y));
}

it('keeps the fixture as the Engine composes each ground span and the raster draws it', function () {
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

    // A difference is a change to the Engine's composition or to the raster: see writeGroundSpanStripFixture().
    expect([imagesx($atlas), imagesy($atlas), $different])->toBe([$width, $height, 0])
        ->and(encodeGroundSpanStripFixture(buildGroundSpanStripFixture($directory)))
        ->toBe(file_get_contents($directory . '/ground-span-strip-fixture.json'));
})->skip(fn (): bool => ! hasEngineGroundSpan(), ENGINE_GROUND_SPAN_MISSING);

it('draws the whole image between the captured grounds at one scale and one turn, cropped and mirrored', function (string $id) {
    $case = array_find(readGroundSpanStripFixture()['cases'], static fn(array $case): bool => $case['id'] === $id);
    $contract = $case['contract'];
    $composite = $case['canvas']['composites'][0];
    ['x' => $left, 'y' => $top, 'width' => $width, 'height' => $height] = $composite['destination'];
    $rows = $case['expectedRgbaHexRows'];
    $border = vsprintf('%02x%02x%02xff', GROUND_SPAN_STRIP_BORDER);
    $painted = $wrong = $onEdge = $leaked = 0;
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $pixel = substr($rows[$y], $x * 8, 8);
            $texel = findGroundSpanTexel($contract, $left + $x + 0.5, $top + $y + 0.5);
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
        ->and($painted)->toBeGreaterThan(400);

    // The image is drawn at more than twice its size, so the pixel a ground lies in shows the texel of its point.
    $texelAt = static fn(array $point): array => [(int) ($point['x'] * $contract['crop']['width']), (int) ($point['y'] * $contract['crop']['height'])];
    $pixelAt = static function (array $ground) use ($rows, $left, $top, $width, $height): ?string {
        [$x, $y] = [(int) floor($ground[0]) - $left, (int) floor($ground[1]) - $top];

        return $x >= 0 && $x < $width && $y >= 0 && $y < $height ? substr($rows[$y], $x * 8, 8) : null;
    };
    $geometry = $composite['operations'][0]['geometry'];
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
})->with(array_column(listGroundSpanStripCases(), 'id'))->skip(fn (): bool => ! hasEngineGroundSpan(), ENGINE_GROUND_SPAN_MISSING);

it('names texture strips among what the still rasters draw, as the Engine asks before composing a ground span', function () {
    expect(CanvasStillRaster::supports(RendererSessionConfig::CANVAS_TEXTURE_STRIP))->toBeTrue()
        ->and(SceneFrameRaster::supports(RendererSessionConfig::CANVAS_TEXTURE_STRIP))->toBeTrue()
        ->and(SceneFrameRaster::supports(RendererSessionConfig::CANVAS_COMPOSITING))->toBeTrue();
});
