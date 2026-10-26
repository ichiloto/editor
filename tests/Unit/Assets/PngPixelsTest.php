<?php

declare(strict_types=1);

use Ichiloto\Editor\Assets\PngPixels;

/** A small image encoded by GD in the form asked for: truecolour with alpha, truecolour, palette or interlaced. */
function encodeWithGd(string $form): string
{
    $image = imagecreatetruecolor(5, 3);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    for ($y = 0; $y < 3; $y++) {
        for ($x = 0; $x < 5; $x++) {
            imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, 50 * $x, 80 * $y, 200 - 30 * $x, $form === 'rgba' ? ($x + $y) * 10 : 0));
        }
    }
    if ($form === 'palette') {
        imagetruecolortopalette($image, false, 16);
    }
    if ($form === 'interlaced') {
        imageinterlace($image, true);
    }
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('reads every pixel of truecolour, alpha and palette PNGs exactly as GD does', function () {
    foreach (['rgba', 'rgb', 'palette'] as $form) {
        $bytes = encodeWithGd($form);
        $gd = imagecreatefromstring($bytes);
        $png = PngPixels::decode($bytes, $form);
        expect([$png->width, $png->height])->toBe([5, 3]);
        for ($y = 0; $y < 3; $y++) {
            for ($x = 0; $x < 5; $x++) {
                $color = imagecolorsforindex($gd, imagecolorat($gd, $x, $y));
                [$red, $green, $blue, $alpha] = $png->getPixel($x, $y);
                expect([$red, $green, $blue])->toBe([$color['red'], $color['green'], $color['blue']])
                    // GD keeps alpha in 7 bits; the PNG's own 8 bits read within its rounding.
                    ->and(abs((127 - $color['alpha']) * 255 / 127 - $alpha))->toBeLessThan(2.1);
            }
        }
    }
});

it('writes pixels back without loss and lays one image\'s cells over another\'s, nothing else changing', function () {
    $sheet = PngPixels::decode(encodeWithGd('rgba'), 'sheet');
    $again = PngPixels::decode($sheet->encode(), 'again');
    expect($again->hasSamePixels($sheet))->toBeTrue()
        ->and($again->getPixelHash())->toBe($sheet->getPixelHash());

    $art = PngPixels::createBlank(2, 2);
    $art->setPixel(0, 0, [1, 2, 3, 4]);
    $art->setPixel(1, 1, [250, 251, 252, 253]);
    $laid = $sheet->withPixelsFrom($art, 0, 0, 2, 2, 3, 1);
    for ($y = 0; $y < 3; $y++) {
        for ($x = 0; $x < 5; $x++) {
            $inside = $x >= 3 && $y >= 1;
            expect($laid->getPixel($x, $y))->toBe($inside ? $art->getPixel($x - 3, $y - 1) : $sheet->getPixel($x, $y));
        }
    }
    expect($laid->isTransparent(4, 1, 1, 1))->toBeTrue()
        ->and($laid->isTransparent(3, 1, 1, 1))->toBeFalse()
        ->and($sheet->isTransparent(0, 0, 1, 1))->toBeFalse();
});

it('refuses what it cannot read without loss, saying what to do', function () {
    $bytes = encodeWithGd('rgb');
    expect(fn() => PngPixels::decode(encodeWithGd('interlaced'), 'Hut.png'))
        ->toThrow(InvalidArgumentException::class, 'Hut.png is interlaced. Save it without interlacing and try again.')
        ->and(fn() => PngPixels::decode('GIF89a', 'Hut.png'))->toThrow(InvalidArgumentException::class, 'Hut.png is not a PNG image.')
        ->and(fn() => PngPixels::decode(substr($bytes, 0, 40), 'Hut.png'))->toThrow(InvalidArgumentException::class, 'Hut.png is cut short')
        ->and(fn() => PngPixels::decode(substr_replace($bytes, 'X', 20, 1), 'Hut.png'))->toThrow(InvalidArgumentException::class, 'does not match its checksum');
    // A 16-bit header, which would lose detail if reduced.
    $header = pack('NNCCCCC', 1, 1, 16, 6, 0, 0, 0);
    $deep = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $header . pack('N', crc32('IHDR' . $header));
    expect(fn() => PngPixels::decode($deep, 'Deep.png'))->toThrow(InvalidArgumentException::class, 'Deep.png has 16 bits per channel');
});
