<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use GdImage;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use InvalidArgumentException;
use RuntimeException;

/**
 * Draws a still of the graphical field into an image, as a renderer of the
 * Engine's retained world draws it: the world's layers and the sprites in one
 * paint order by layer, a world layer before a sprite of the same layer; each
 * tile one field cell tall at its own width and offset, composed from its
 * sheet pieces; each glyph cell its background and glyph unless a tile that
 * covers its layer stands on it; each sprite's image pivot on its cell's
 * ground anchor, raised by its lift and turned by its quarter turns, a crop
 * filling its size and a whole image contained in it. Glyphs are drawn in a
 * monospaced system font fitted to the cell.
 *
 * It reads only the presentation the Engine composed, never a map's source,
 * so what it draws is what the game would send a graphical renderer. Motion,
 * bob and display-rate animation are drawn at rest. Art that cannot be read
 * is left out and named in {@see $diagnostics}; its cells keep their glyphs.
 */
final class FieldStillRaster
{
    /** The renderer features the raster draws, as a graphical renderer negotiates them. */
    public const array CAPABILITIES = [
        RendererSessionConfig::FRAME_VIEWPORT, RendererSessionConfig::SPRITE_SOURCE_RECT, RendererSessionConfig::SPRITE_PIVOT,
        RendererSessionConfig::SPRITE_LIFT, RendererSessionConfig::SPRITE_QUARTER_TURNS, RendererSessionConfig::TILE_COVERS,
        RendererSessionConfig::TILE_SHADOWS,
    ];

    /** The most pixels one native-size image holds; a larger view is drawn scaled down, a block of cells at a time. */
    public const int MAX_NATIVE_PIXELS = 50_000_000;

    /** Cells across and down one block when a scaled image is drawn a block at a time. */
    private const int BLOCK_CELLS = 16;

    /** Fonts tried, in order, when none is given: the graphical renderer's own field font first. */
    private const array SYSTEM_FONTS = [
        '/System/Library/Fonts/Menlo.ttc',
        '/System/Library/Fonts/SFNSMono.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
        '/usr/share/fonts/TTF/DejaVuSansMono.ttf',
        '/usr/share/fonts/dejavu/DejaVuSansMono.ttf',
        'C:\\Windows\\Fonts\\consola.ttf',
    ];

    /** @var list<string> What the still could not draw, once each. */
    public private(set) array $diagnostics = [];

    private int $cell = 48;
    /** @var list<array{id: string, layer: int, kind: string, covers: ?string}> */
    private array $layers = [];
    /** @var array<int, array<int, array{glyph: string, foreground: ?array, background: ?array, ownerLayerId: string}>> */
    private array $cells = [];
    /** @var array<string, array<int, array<int, int>>> Each tile-drawing layer's tile index by row and column. */
    private array $tiles = [];
    /** @var array{tileSize: int, sheets: list<string>, tiles: list<array<string, mixed>>}|null */
    private ?array $tileset = null;
    /** @var array<int, GdImage|null> */
    private array $sheets = [];
    /** @var array<int, array<int, GdImage|null>> Each tile's composed frames. */
    private array $composed = [];
    /** @var array<string, array<int, array<int, true>>> The cells tiles cover, by covered layer; '' covers every layer. */
    private array $covered = [];
    /** @var array<string, GdImage|null> */
    private array $images = [];
    private ?float $fontSize = null;

    public function __construct(private readonly string $assetRoot, private readonly ?string $fontPath = null)
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Drawing a graphical still needs the PHP gd extension.');
        }
    }

    /** Whether the raster draws a renderer feature. */
    public static function supports(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }

    /** The first monospaced system font found, the graphical renderer's own field font first. */
    public static function findSystemFont(): ?string
    {
        return array_find(self::SYSTEM_FONTS, static fn(string $path): bool => is_file($path));
    }

    /**
     * Draws a still's region at a scale: 1 is native size, one field cell
     * {@see \Ichiloto\Engine\Rendering\FieldViewport::TILE_SIZE} logical
     * pixels square; a quarter or a half shows an overview.
     *
     * @throws InvalidArgumentException When the scale is not in (0, 1], or a native image would be too large.
     */
    public function rasterize(FieldStill $still, float $scale = 1.0): GdImage
    {
        if (! is_finite($scale) || $scale <= 0 || $scale > 1) {
            throw new InvalidArgumentException('A still is drawn at a scale above 0 and at most 1.');
        }
        $this->prepare($still);
        ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height] = $still->region;
        $pixelWidth = $width * $this->cell;
        $pixelHeight = $height * $this->cell;
        if ($scale === 1.0) {
            if ($pixelWidth * $pixelHeight > self::MAX_NATIVE_PIXELS) {
                throw new InvalidArgumentException(sprintf('A native %dx%d still is too large to draw; choose a region or a smaller scale.',
                    $pixelWidth, $pixelHeight));
            }
            $image = self::createCanvas($pixelWidth, $pixelHeight);
            $this->drawInto($image, $still, $x * $this->cell, $y * $this->cell, $pixelWidth, $pixelHeight);

            return $image;
        }
        $output = self::createCanvas(max(1, (int) round($pixelWidth * $scale)), max(1, (int) round($pixelHeight * $scale)));
        for ($blockY = 0; $blockY < $height; $blockY += self::BLOCK_CELLS) {
            for ($blockX = 0; $blockX < $width; $blockX += self::BLOCK_CELLS) {
                $cellsAcross = min(self::BLOCK_CELLS, $width - $blockX);
                $cellsDown = min(self::BLOCK_CELLS, $height - $blockY);
                $block = self::createCanvas($cellsAcross * $this->cell, $cellsDown * $this->cell);
                $this->drawInto($block, $still, ($x + $blockX) * $this->cell, ($y + $blockY) * $this->cell,
                    $cellsAcross * $this->cell, $cellsDown * $this->cell);
                $left = (int) round($blockX * $this->cell * $scale);
                $top = (int) round($blockY * $this->cell * $scale);
                imagecopyresampled($output, $block, $left, $top, 0, 0,
                    (int) round(($blockX + $cellsAcross) * $this->cell * $scale) - $left,
                    (int) round(($blockY + $cellsDown) * $this->cell * $scale) - $top,
                    $cellsAcross * $this->cell, $cellsDown * $this->cell);
            }
        }

        return $output;
    }

    /** Reads the world's upload: its layers, glyph cells, tiles and the cells those tiles cover. */
    private function prepare(FieldStill $still): void
    {
        $operations = $still->world->getOperations(true, true);
        $definition = $operations[0]['value'] ?? throw new InvalidArgumentException('A still needs its world\'s definition.');
        $this->cell = (int) $definition['cellWidth'];
        $this->tileset = $definition['tileset'] ?? null;
        $this->layers = $this->cells = $this->tiles = $this->covered = $this->sheets = $this->composed = [];
        foreach ($definition['layers'] as $index => $layer) {
            $this->layers[] = ['id' => $layer['id'], 'layer' => (int) $layer['layer'], 'kind' => $layer['kind'],
                'covers' => $layer['coversLayerId'] ?? null, 'index' => $index];
        }
        // Layers of equal number paint in the order listed.
        usort($this->layers, static fn(array $a, array $b): int => [$a['layer'], $a['index']] <=> [$b['layer'], $b['index']]);
        foreach (array_slice($operations, 1) as $operation) {
            foreach ($operation['rows'] ?? [] as $row) {
                foreach ($row['cells'] as $column => $cell) {
                    if ($operation['op'] === 'worldRows') {
                        $this->cells[$row['row']][$column] = $cell;
                    } elseif ($operation['op'] === 'worldTiles') {
                        $this->tiles[$operation['layerId']][$row['row']][$cell['column']] = $cell['tile'];
                    }
                }
            }
        }
        if ($this->tileset === null) {
            return;
        }
        foreach ($this->tileset['sheets'] as $index => $path) {
            $this->sheets[$index] = $this->loadImage($path, 'tileset sheet');
        }
        foreach ($this->layers as $layer) {
            if ($layer['kind'] !== 'tiles') {
                continue;
            }
            foreach ($this->tiles[$layer['id']] ?? [] as $y => $columns) {
                foreach ($columns as $x => $tile) {
                    if ($this->getTileFrame($tile, 0) !== null) {
                        $this->covered[$layer['covers'] ?? ''][$y][$x] = true;
                    }
                }
            }
        }
    }

    /**
     * Draws everything that reaches a rectangle of world pixels into an image
     * of its size, in paint order.
     */
    private function drawInto(GdImage $image, FieldStill $still, int $left, int $top, int $width, int $height): void
    {
        $sprites = $still->sprites;
        $next = 0;
        foreach ($this->layers as $layer) {
            // A world layer paints before a sprite of the same layer.
            for (; $next < count($sprites) && $sprites[$next]->layer < $layer['layer']; $next++) {
                $this->drawSprite($image, $sprites[$next], $left, $top);
            }
            if ($layer['kind'] === 'tiles' || $layer['kind'] === 'shadows') {
                $this->drawTileLayer($image, $layer['id'], $still->tileFrame, $left, $top, $width, $height);
            } else {
                $this->drawGlyphLayer($image, $layer['id'], $left, $top, $width, $height);
            }
        }
        for (; $next < count($sprites); $next++) {
            $this->drawSprite($image, $sprites[$next], $left, $top);
        }
    }

    private function drawTileLayer(GdImage $image, string $layerId, int $tileFrame, int $left, int $top, int $width, int $height): void
    {
        $size = (int) ($this->tileset['tileSize'] ?? $this->cell);
        $ratio = $this->cell / $size;
        // A tile reaches at most one tile beyond its cell.
        [$firstColumn, $lastColumn, $firstRow, $lastRow] = $this->findCellRange($left, $top, $width, $height, 1);
        foreach ($this->tiles[$layerId] ?? [] as $y => $columns) {
            if ($y < $firstRow || $y > $lastRow) {
                continue;
            }
            foreach ($columns as $x => $tile) {
                if ($x < $firstColumn || $x > $lastColumn || ($frame = $this->getTileFrame($tile, $tileFrame)) === null) {
                    continue;
                }
                $definition = $this->tileset['tiles'][$tile];
                $tileWidth = (int) ($definition['width'] ?? $size);
                imagecopyresampled($image, $frame,
                    (int) round($x * $this->cell + ($definition['left'] ?? 0) * $ratio) - $left,
                    (int) round($y * $this->cell + ($definition['top'] ?? 0) * $ratio) - $top,
                    0, 0, (int) round($tileWidth * $ratio), $this->cell, $tileWidth, $size);
            }
        }
    }

    private function drawGlyphLayer(GdImage $image, string $layerId, int $left, int $top, int $width, int $height): void
    {
        [$firstColumn, $lastColumn, $firstRow, $lastRow] = $this->findCellRange($left, $top, $width, $height, 0);
        for ($y = max(0, $firstRow); $y <= $lastRow; $y++) {
            for ($x = max(0, $firstColumn); $x <= $lastColumn; $x++) {
                $cell = $this->cells[$y][$x] ?? null;
                if ($cell === null || $cell['ownerLayerId'] !== $layerId
                    || ($cell['background'] === null && trim($cell['glyph']) === '')
                    || isset($this->covered[''][$y][$x]) || isset($this->covered[$layerId][$y][$x])) {
                    continue;
                }
                $cellLeft = $x * $this->cell - $left;
                $cellTop = $y * $this->cell - $top;
                imagefilledrectangle($image, $cellLeft, $cellTop, $cellLeft + $this->cell - 1, $cellTop + $this->cell - 1,
                    self::allocate($image, TerminalPalette::resolveDescriptor($cell['background'], TerminalPalette::DEFAULT_BACKGROUND)));
                if (trim($cell['glyph']) !== '') {
                    $this->drawGlyph($image, $cell['glyph'],
                        TerminalPalette::resolveDescriptor($cell['foreground'], TerminalPalette::DEFAULT_FOREGROUND), $cellLeft, $cellTop);
                }
            }
        }
    }

    private function drawGlyph(GdImage $image, string $glyph, int $rgb, int $cellLeft, int $cellTop): void
    {
        $font = $this->fontPath ?? self::findSystemFont();
        if ($font === null || ! is_file($font)) {
            $this->diagnose('No monospaced font was found, so glyph cells show their colours without their glyphs; give one with --font.');

            return;
        }
        $size = $this->fontSize ??= $this->fitFont($font);
        $box = imagettfbbox($size, 0, $font, $glyph);
        $reference = imagettfbbox($size, 0, $font, 'Mg');
        if ($box === false || $reference === false) {
            $this->diagnose(sprintf('The font %s cannot draw glyphs, so glyph cells show their colours alone.', $font));

            return;
        }
        $glyphWidth = $box[2] - $box[0];
        // Centred across the cell, on a baseline that centres the font's capital and descender height.
        $baseline = $cellTop + (int) round(($this->cell + (-$reference[5]) - $reference[1]) / 2);
        imagettftext($image, $size, 0, $cellLeft + (int) round(($this->cell - $glyphWidth) / 2) - $box[0], $baseline,
            self::allocate($image, $rgb), $font, $glyph);
    }

    /** The font size whose character advance and line fit the cell, as the renderer fits its field font. */
    private function fitFont(string $font): float
    {
        $box = imagettfbbox(100, 0, $font, '0000000000');
        $line = imagettfbbox(100, 0, $font, 'Mg');
        if ($box === false || $line === false) {
            return $this->cell * 0.6;
        }
        $advance = ($box[2] - $box[0]) / 10 / 100;
        $height = ($line[1] - $line[5]) / 100 * 1.2;

        return min($this->cell * 0.95 / max($advance, 0.01), $this->cell * 0.95 / max($height, 0.01));
    }

    private function drawSprite(GdImage $image, PresentationSprite $sprite, int $left, int $top): void
    {
        $source = $this->loadImage($sprite->asset, 'sprite');
        if ($source === null) {
            return;
        }
        $rect = $sprite->sourceRect;
        [$sourceX, $sourceY, $sourceWidth, $sourceHeight] = $rect === null
            ? [0, 0, imagesx($source), imagesy($source)] : [$rect->x, $rect->y, $rect->width, $rect->height];
        if ($sourceX + $sourceWidth > imagesx($source) || $sourceY + $sourceHeight > imagesy($source)) {
            $this->diagnose(sprintf('%s is smaller than the crop sprite %s draws from it; the sprite is left out.', $sprite->asset, $sprite->id));

            return;
        }
        if ($sprite->quarterTurns > 0) {
            $crop = self::createCanvas($sourceWidth, $sourceHeight, transparent: true);
            imagecopy($crop, $source, 0, 0, $sourceX, $sourceY, $sourceWidth, $sourceHeight);
            // The renderer turns clockwise; imagerotate turns the other way.
            $source = imagerotate($crop, (360 - 90 * $sprite->quarterTurns) % 360, (int) imagecolorallocatealpha($crop, 0, 0, 0, 127));
            if ($source === false) {
                return;
            }
            imagesavealpha($source, true);
            [$sourceX, $sourceY, $sourceWidth, $sourceHeight] = [0, 0, imagesx($source), imagesy($source)];
        }
        $pivotX = $sprite->pivot?->x ?? 0.5;
        $pivotY = $sprite->pivot?->y ?? 1.0;
        $spriteLeft = ($sprite->x + 0.5) * $this->cell - $sprite->width / 2 + (0.5 - $pivotX) * $sprite->width;
        $spriteTop = ($sprite->y + 1) * $this->cell - $sprite->height + (1 - $pivotY) * $sprite->height - $sprite->lift;
        [$drawLeft, $drawTop, $drawWidth, $drawHeight] = [$spriteLeft, $spriteTop, (float) $sprite->width, (float) $sprite->height];
        if ($rect === null) {
            // A whole image is contained in the sprite's size, centred.
            $fit = min($sprite->width / $sourceWidth, $sprite->height / $sourceHeight);
            $drawWidth = $sourceWidth * $fit;
            $drawHeight = $sourceHeight * $fit;
            $drawLeft += ($sprite->width - $drawWidth) / 2;
            $drawTop += ($sprite->height - $drawHeight) / 2;
        }
        imagecopyresampled($image, $source, (int) round($drawLeft) - $left, (int) round($drawTop) - $top, $sourceX, $sourceY,
            max(1, (int) round($drawWidth)), max(1, (int) round($drawHeight)), $sourceWidth, $sourceHeight);
    }

    /** A tile's composed frame at an animation counter, or null when its art cannot be read. */
    private function getTileFrame(int $tile, int $tileFrame): ?GdImage
    {
        $definition = $this->tileset['tiles'][$tile] ?? null;
        if ($definition === null || $definition['frames'] === []) {
            return null;
        }
        $index = $tileFrame % count($definition['frames']);
        if (array_key_exists($index, $this->composed[$tile] ?? [])) {
            return $this->composed[$tile][$index];
        }
        $size = (int) $this->tileset['tileSize'];
        // A tile whose pieces cannot all be read shows no frame at all, so its cell keeps its glyph.
        foreach ($definition['frames'] as $pieces) {
            foreach ($pieces as $piece) {
                $sheet = isset($piece['sheet']) ? ($this->sheets[$piece['sheet']] ?? null) : null;
                if (isset($piece['sheet']) && ($sheet === null || $piece['x'] + $piece['width'] > imagesx($sheet)
                    || $piece['y'] + $piece['height'] > imagesy($sheet))) {
                    return $this->composed[$tile][$index] = null;
                }
            }
        }
        $frame = self::createCanvas((int) ($definition['width'] ?? $size), $size, transparent: true);
        foreach ($definition['frames'][$index] as $piece) {
            if (isset($piece['fill'])) {
                [$red, $green, $blue, $alpha] = $piece['fill'];
                imagefilledrectangle($frame, $piece['left'], $piece['top'], $piece['left'] + $piece['width'] - 1, $piece['top'] + $piece['height'] - 1,
                    (int) imagecolorallocatealpha($frame, $red, $green, $blue, 127 - intdiv($alpha * 127, 255)));
                continue;
            }
            imagecopy($frame, $this->sheets[$piece['sheet']], $piece['left'], $piece['top'], $piece['x'], $piece['y'],
                $piece['width'], $piece['height']);
        }

        return $this->composed[$tile][$index] = $frame;
    }

    /** @return array{int, int, int, int} The first and last columns and rows a pixel rectangle reaches, widened by cells. */
    private function findCellRange(int $left, int $top, int $width, int $height, int $margin): array
    {
        return [intdiv($left, $this->cell) - $margin, intdiv($left + $width - 1, $this->cell) + $margin,
            intdiv($top, $this->cell) - $margin, intdiv($top + $height - 1, $this->cell) + $margin];
    }

    /** An asset-root image, read once, or null and a diagnostic when it cannot be read. */
    private function loadImage(string $path, string $role): ?GdImage
    {
        if (array_key_exists($path, $this->images)) {
            return $this->images[$path];
        }
        $root = realpath($this->assetRoot);
        $file = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $path);
        $bytes = $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) ? @file_get_contents($file) : false;
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($image === false) {
            $this->diagnose(sprintf('The %s %s cannot be read from the project\'s assets; what it draws is left out.', $role, $path));

            return $this->images[$path] = null;
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagesavealpha($image, true);

        return $this->images[$path] = $image;
    }

    private function diagnose(string $message): void
    {
        if (! in_array($message, $this->diagnostics, true)) {
            $this->diagnostics[] = $message;
        }
    }

    /** A true-colour image, filled with the field's background or left transparent. */
    private static function createCanvas(int $width, int $height, bool $transparent = false): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $transparent
            ? (int) imagecolorallocatealpha($image, 0, 0, 0, 127) : self::allocate($image, TerminalPalette::DEFAULT_BACKGROUND));
        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    private static function allocate(GdImage $image, int $rgb): int
    {
        return (int) imagecolorallocate($image, ($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255);
    }
}
