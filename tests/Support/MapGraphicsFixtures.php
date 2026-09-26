<?php

declare(strict_types=1);

use Ichiloto\Engine\Field\MapGridSource;

/**
 * Synthetic RPG Maker tilesets and map tile layers. Nothing here is
 * production art: sheets are tiny generated PNGs with 2-pixel tiles.
 */

/** Writes a minimal valid RGBA PNG of the given size. */
function writeTilesetTestPng(string $path, int $width, int $height): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data
        . pack('N', crc32($type . $data));
    file_put_contents($path, "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        . $chunk('IDAT', (string) gzcompress(str_repeat("\0" . str_repeat("\x70\x90\xB0\xFF", $width), $height)))
        . $chunk('IEND', ''));
}

/**
 * Writes tileset `home` with an A2 sheet (16 x 12 tiles) and a B sheet
 * (16 x 16 tiles) at 2 pixels per tile.
 *
 * @param array<string, string> $sheets Asset-relative PNG paths by sheet name.
 */
function writeTestTileset(string $root, string $id = 'home', ?array $sheets = null): void
{
    $sheets ??= ['A2' => 'Graphics/Tilesets/Home_A2.png', 'B' => 'Graphics/Tilesets/Home_B.png'];
    writeTilesetTestPng($root . '/assets/Graphics/Tilesets/Home_A2.png', 32, 24);
    writeTilesetTestPng($root . '/assets/Graphics/Tilesets/Home_B.png', 32, 32);
    if (! is_dir($root . '/assets/Data/Tilesets')) {
        mkdir($root . '/assets/Data/Tilesets', 0o777, true);
    }
    file_put_contents($root . "/assets/Data/Tilesets/{$id}.php", "<?php\n\nreturn " . var_export(['name' => ucfirst($id), 'sheets' => $sheets], true) . ";\n");
}

/** Writes one tile layer in graphics/ as an authored literal nowdoc. */
function writeTileLayer(string $mapDirectory, string $file, string $body, string $leadingComment = ''): string
{
    if (! is_dir($mapDirectory . '/graphics')) {
        mkdir($mapDirectory . '/graphics', 0o777, true);
    }
    $path = $mapDirectory . '/graphics/' . $file;
    file_put_contents($path, MapGridSource::buildSource($body, 'TILES', $leadingComment));

    return $path;
}

/**
 * The layered test map (4 x 2 cells, or rows of 4 and 2 when ragged) naming
 * tileset `home`, with a floor and an aligned decor tile layer.
 */
function mapGraphicsProject(bool $ragged = false): string
{
    $root = layeredMapProject($ragged);
    $directory = $root . '/assets/Maps/test-map';
    writeTestTileset($root);
    $data = $directory . '/test-map.data.php';
    file_put_contents($data, str_replace("'events' => [],", "'events' => [], 'tileset' => 'home',", (string) file_get_contents($data)));
    writeTileLayer($directory, '01.floor.tiles.php', $ragged ? "2816 2816 2816 2816\n2816 2816" : "2816 2816 2816 2816\n2816 2816 2816 2816",
        "// Painted in the GUI editor.\n");
    writeTileLayer($directory, '02.decor.tiles.php', $ragged ? "0    5  0  0\n0    5" : "0    5  0  0\n0    0  0  5");

    return $root;
}

/**
 * The tile identities of one tile layer, read as the Engine reads them.
 *
 * @return list<list<int>>
 */
function readTileRows(string $path): array
{
    return new Ichiloto\Engine\Field\MapTileLayer('layer', 0, $path, MapGridSource::readFile($path))->tiles;
}
