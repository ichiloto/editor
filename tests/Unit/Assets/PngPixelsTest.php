<?php

declare(strict_types=1);

use Ichiloto\Editor\Assets\AssetImport;
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

/** A PNG chunk with its length and checksum. */
function writePngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data) & 0xFFFFFFFF);
}

/**
 * A synthetic PNG of any 8-bit-or-lower form whose rows use the given
 * filters in turn, from one row of samples per image row.
 *
 * @param list<list<int>> $samples Each row's samples, channel by channel.
 * @param list<int> $filters The filter of each row, repeated down the image.
 */
function encodeFilteredPng(int $width, int $depth, int $color, array $samples, array $filters, string $palette = '', ?string $transparency = null): string
{
    $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color];
    $stride = intdiv($width * $depth * $channels + 7, 8);
    $step = max(1, intdiv($depth * $channels, 8));
    $previous = array_fill(0, $stride, 0);
    $data = '';
    foreach ($samples as $y => $values) {
        $row = array_fill(0, $stride, 0);
        foreach ($values as $i => $value) {
            $row[intdiv($i * $depth, 8)] |= $value << (8 - $depth - ($i * $depth) % 8);
        }
        $filter = $filters[$y % count($filters)];
        $data .= chr($filter);
        for ($i = 0; $i < $stride; $i++) {
            [$left, $up, $corner] = [$i >= $step ? $row[$i - $step] : 0, $previous[$i], $i >= $step ? $previous[$i - $step] : 0];
            $estimate = $left + $up - $corner;
            $paeth = abs($estimate - $left) <= abs($estimate - $up) && abs($estimate - $left) <= abs($estimate - $corner)
                ? $left : (abs($estimate - $up) <= abs($estimate - $corner) ? $up : $corner);
            $data .= chr(($row[$i] - [0, $left, $up, ($left + $up) >> 1, $paeth][$filter]) & 0xFF);
        }
        $previous = $row;
    }

    return "\x89PNG\r\n\x1a\n" . writePngChunk('IHDR', pack('NNCCCCC', $width, count($samples), $depth, $color, 0, 0, 0))
        . ($palette === '' ? '' : writePngChunk('PLTE', $palette)) . ($transparency === null ? '' : writePngChunk('tRNS', $transparency))
        . implode('', array_map(static fn(string $part): string => writePngChunk('IDAT', $part), str_split((string) gzcompress($data), 7)))
        . writePngChunk('IEND', '');
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

it('reads every colour type and depth exactly, whatever filter each row uses', function () {
    [$width, $height] = [7, 6];
    $palette = implode('', array_map(static fn(int $i): string => chr($i * 9) . chr(250 - $i) . chr($i * 5), range(0, 15)));
    $forms = [
        // [colour type, depth, transparency, the RGBA of a pixel from its samples]
        [0, 1, null, static fn(array $s): array => [$s[0] * 255, $s[0] * 255, $s[0] * 255, 255]],
        [0, 2, pack('n', 2), static fn(array $s): array => [$s[0] * 85, $s[0] * 85, $s[0] * 85, $s[0] === 2 ? 0 : 255]],
        [0, 4, null, static fn(array $s): array => [$s[0] * 17, $s[0] * 17, $s[0] * 17, 255]],
        [0, 8, pack('n', 40), static fn(array $s): array => [$s[0], $s[0], $s[0], $s[0] === 40 ? 0 : 255]],
        [2, 8, pack('n3', 20, 40, 60), static fn(array $s): array => [...$s, $s === [20, 40, 60] ? 0 : 255]],
        [3, 1, "\x80", static fn(array $s): array => [$s[0] * 9, 250 - $s[0], $s[0] * 5, $s[0] === 0 ? 128 : 255]],
        [3, 2, "\x10\x20", static fn(array $s): array => [$s[0] * 9, 250 - $s[0], $s[0] * 5, [16, 32][$s[0]] ?? 255]],
        [3, 4, null, static fn(array $s): array => [$s[0] * 9, 250 - $s[0], $s[0] * 5, 255]],
        [3, 8, "\x00", static fn(array $s): array => [$s[0] * 9, 250 - $s[0], $s[0] * 5, $s[0] === 0 ? 0 : 255]],
        [4, 8, null, static fn(array $s): array => [$s[0], $s[0], $s[0], $s[1]]],
        [6, 8, null, static fn(array $s): array => $s],
    ];
    foreach ($forms as [$color, $depth, $transparency, $toRgba]) {
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color];
        $top = $color === 3 ? min(15, (1 << $depth) - 1) : (1 << $depth) - 1;
        $sampleAt = static fn(int $x, int $y, int $c): int => $color === 2 && $x === 3 ? [20, 40, 60][$c]
            : ($x * 37 + $y * 23 + $c * 61 + $x * $y * 11) % ($top + 1);
        $samples = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                for ($c = 0; $c < $channels; $c++) {
                    $samples[$y][] = $sampleAt($x, $y, $c);
                }
            }
        }
        foreach ([[0], [1], [2], [3], [4], [0, 1, 2, 3, 4], [4, 3, 2, 1, 0]] as $filters) {
            $bytes = encodeFilteredPng($width, $depth, $color, $samples, $filters, $color === 3 ? $palette : '', $transparency);
            $png = PngPixels::decode($bytes, 'Sheet.png');
            expect([$png->width, $png->height])->toBe([$width, $height]);
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $pixel = array_map(static fn(int $c): int => $sampleAt($x, $y, $c), range(0, $channels - 1));
                    expect($png->getPixel($x, $y))->toBe($toRgba($pixel), "colour type {$color}, depth {$depth}, filters "
                        . implode(',', $filters) . " at {$x}, {$y}");
                }
            }
        }
    }
});

it('refuses image data that does not match its header, saying why', function () {
    $header = writePngChunk('IHDR', pack('NNCCCCC', 2, 2, 8, 6, 0, 0, 0));
    $row = "\0" . str_repeat("\x11", 8);
    $png = static fn(string $data): string => "\x89PNG\r\n\x1a\n" . $header . writePngChunk('IDAT', $data) . writePngChunk('IEND', '');
    expect(fn() => PngPixels::decode($png((string) gzcompress($row)), 'Hut.png'))
        ->toThrow(InvalidArgumentException::class, "Hut.png's pixel data does not fit its size.")
        ->and(fn() => PngPixels::decode($png((string) gzcompress($row . $row . $row)), 'Hut.png'))
        ->toThrow(InvalidArgumentException::class, "Hut.png's pixel data does not fit its size.")
        ->and(fn() => PngPixels::decode($png(substr((string) gzcompress($row . $row), 0, 6)), 'Hut.png'))
        ->toThrow(InvalidArgumentException::class, "Hut.png's pixels cannot be read: their compressed data is damaged.")
        ->and(fn() => PngPixels::decode($png((string) gzcompress($row . "\x05" . substr($row, 1))), 'Hut.png'))
        ->toThrow(InvalidArgumentException::class, 'Hut.png uses an unknown row filter.')
        ->and(fn() => PngPixels::decode(encodeFilteredPng(2, 8, 3, [[0, 2]], [0], "\1\2\3\4\5\6"), 'Hut.png'))
        ->toThrow(InvalidArgumentException::class, 'Hut.png names a colour its palette does not have.');
});

it('reads a large sheet in about its four bytes a pixel, holding no more than a row or two besides', function () {
    [$width, $height] = [1024, 768];
    $rows = '';
    for ($y = 0; $y < $height; $y++) {
        // Paeth rows of varied bytes, so every row is unfiltered against the one above.
        $rows .= "\x04" . substr(str_repeat(hash('sha256', (string) $y, true), intdiv($width * 4, 32) + 1), 0, $width * 4);
    }
    $bytes = "\x89PNG\r\n\x1a\n" . writePngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        . writePngChunk('IDAT', (string) gzcompress($rows)) . writePngChunk('IEND', '');
    unset($rows);
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $png = PngPixels::decode($bytes, 'Sheet.png');
    $used = memory_get_peak_usage() - $before;

    // The RGBA string is 3 MiB and may be copied once as it grows; a slice of inflated data and two rows add
    // little to that. Rows held as integer arrays would take over sixteen times the RGBA.
    expect($png->width)->toBe($width)
        ->and($used)->toBeLessThan(3 * $width * $height * 4);
});

it('refuses an image too large for the game from its header, before reading its pixels', function () {
    $root = makeTemporaryProject('editor-png-import-');
    $source = $root . '/Huge.png';
    // Its pixel data is not even there: only the header can have been read.
    file_put_contents($source, "\x89PNG\r\n\x1a\n" . writePngChunk('IHDR', pack('NNCCCCC', 20000, 20000, 8, 6, 0, 0, 0))
        . writePngChunk('IDAT', 'not image data') . writePngChunk('IEND', ''));

    expect(fn() => AssetImport::plan($root, $source, 'Graphics/Huge.png'))
        ->toThrow(InvalidArgumentException::class, 'Huge.png is 20000 x 20000 pixels; the game draws images of at most 4096 pixels each way.');
});
