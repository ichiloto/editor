<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\MapGraphicsValidator;

/**
 * Ground tiles with see-through edges blend into the ground beneath them; with
 * none authored beneath, the game shows the background through them as a dark
 * seam. Project validation warns for every such cell on every map, by the
 * Engine's own tile coverage, and changes nothing. Synthetic tileset art only.
 */

/** Writes a PNG of fully transparent pixels, as a sheet whose tiles all have see-through edges. */
function writeTransparentTestPng(string $path, int $width, int $height): void
{
    $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        . $chunk('IDAT', (string) gzcompress(str_repeat("\0" . str_repeat("\0\0\0\0", $width), $height))) . $chunk('IEND', ''));
}

/** @return list<string> The see-through ground warnings for the test map. */
function findSeeThroughWarnings(string $root): array
{
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);

    return array_values(array_map(static fn($issue): string => $issue->message, array_filter(MapGraphicsValidator::validateCoverage($map),
        static fn($issue): bool => str_contains($issue->message, 'see-through') || str_contains($issue->message, 'could not be checked'))));
}

beforeEach(function () {
    if (! function_exists('imagecreatefrompng')) {
        $this->markTestSkipped('Tile coverage reads sheet pixels through the optional GD extension.');
    }
});

it('warns for every cell whose ground tile leaves the background showing, naming the first and counting the rest', function () {
    $root = mapGraphicsProject();
    writeTransparentTestPng($root . '/assets/Graphics/Tilesets/Home_A2.png', 32, 24);

    expect(findSeeThroughWarnings($root))->toBe(['8 cells can show the background through see-through ground tiles in some animation frame,'
        . ' a dark seam in the game: (0, 0), (1, 0), (2, 0) and 5 more.']);
});

it('is satisfied once ground is authored beneath', function () {
    $root = mapGraphicsProject();
    writeTilesetTestPng($root . '/assets/Graphics/Tilesets/Home_A5.png', 16, 32);
    writeTestTileset($root, sheets: ['A2' => 'Graphics/Tilesets/Home_A2.png', 'A5' => 'Graphics/Tilesets/Home_A5.png',
        'B' => 'Graphics/Tilesets/Home_B.png']);
    writeTransparentTestPng($root . '/assets/Graphics/Tilesets/Home_A2.png', 32, 24);
    writeTileLayer($root . '/assets/Maps/test-map', '00.base.tiles.php', "1536 1536 1536 1536\n1536 1536 1536 1536");

    expect(findSeeThroughWarnings($root))->toBe([]);
});

it('never judges upper-sheet props, which are see-through by design', function () {
    $root = mapGraphicsProject();
    writeTransparentTestPng($root . '/assets/Graphics/Tilesets/Home_B.png', 32, 32);
    writeTileLayer($root . '/assets/Maps/test-map', '01.floor.tiles.php', "0 0 0 0\n0 0 0 0");

    // The decor layer's see-through B tiles stand on cells with no ground at all.
    expect(findSeeThroughWarnings($root))->toBe([]);
});

it('reads the artwork as it is now, so replacing an opaque sheet with a see-through one is noticed', function () {
    $root = mapGraphicsProject();

    expect(findSeeThroughWarnings($root))->toBe([]);
    writeTransparentTestPng($root . '/assets/Graphics/Tilesets/Home_A2.png', 32, 24);
    expect(findSeeThroughWarnings($root))->toHaveCount(1);
});
