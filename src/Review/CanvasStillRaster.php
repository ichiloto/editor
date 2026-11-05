<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use GdImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasLabel;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use InvalidArgumentException;
use RuntimeException;

/**
 * Draws a still of one graphical canvas, such as a menu, dialogue, storybook
 * or modal over a scene, as the graphical renderer paints it: every image,
 * indicator, text layer and label in one order by layer, and at a tie in the
 * renderer's own order (images, composites, indicators, text, labels), each
 * in the order the canvas lists it. Images are cropped, mirrored, rotated, brightened,
 * faded, stretched to their destination and clipped. Text layers paint each
 * cell's background and glyph, centred in the cell, in a monospaced system
 * font fitted to the layer's cell; labels show the graphical renderer's own
 * raster of them.
 *
 * It reads only the canvas the Engine composed, so what it draws is what the
 * game would send a graphical renderer. It is a review picture, not the
 * renderer: glyphs come from a system font through GD, alpha has GD's 7 bits,
 * coordinates are rounded to whole pixels. Coverage dabs and texture strips
 * are supported. Glyph effects are drawn plain. Everything left out is named
 * once in {@see $diagnostics}.
 */
final class CanvasStillRaster
{
    /** The renderer features the raster draws, as a graphical renderer negotiates them. */
    public const array CAPABILITIES = [
        RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_OVERLAY, RendererSessionConfig::CANVAS_CLIP_OPACITY,
        RendererSessionConfig::SPRITE_SOURCE_RECT, RendererSessionConfig::CANVAS_IMAGE_TONE, RendererSessionConfig::CANVAS_IMAGE_FLIP,
        RendererSessionConfig::TEXT_LAYOUT, RendererSessionConfig::CANVAS_LABELS,
        RendererSessionConfig::CANVAS_IMAGE_ROTATION, RendererSessionConfig::CANVAS_TEXTURE_STRIP,
    ];

    /** Fonts tried, in order, when none is given: the graphical renderer's own text font first. */
    private const array SYSTEM_FONTS = [
        '/System/Library/Fonts/Menlo.ttc',
        '/System/Library/Fonts/SFNSMono.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
        '/usr/share/fonts/TTF/DejaVuSansMono.ttf',
        '/usr/share/fonts/dejavu/DejaVuSansMono.ttf',
        'C:\\Windows\\Fonts\\consola.ttf',
    ];

    /** The renderer's paint order for items of one layer. */
    private const array KIND_ORDER = ['image' => 0, 'composite' => 1, 'indicator' => 2, 'text' => 3, 'label' => 4];

    /** @var list<string> What the still could not draw, once each. */
    public private(set) array $diagnostics = [];

    /** @var array<string, GdImage|null> */
    private array $images = [];
    /** @var array<string, float> Font sizes fitted to each cell size. */
    private array $fontSizes = [];

    /**
     * @param array<string, string> $labelRasters Each label's raster from the graphical renderer, by layout id.
     */
    public function __construct(private readonly string $assetRoot, private readonly ?string $fontPath = null,
        private array $labelRasters = [])
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

    /** The first monospaced system font found, the graphical renderer's own text font first. */
    public static function findSystemFont(): ?string
    {
        return array_find(self::SYSTEM_FONTS, static fn(string $path): bool => is_file($path));
    }

    /** @param array<string, string> $rasters Label rasters for the canvases drawn next, by layout id. */
    public function setLabelRasters(array $rasters): void
    {
        $this->labelRasters = $rasters;
    }

    /** A still of a whole canvas at its logical size over a background colour (0xRRGGBB). */
    public function rasterize(PresentationCanvas $canvas, int $background = TerminalPalette::DEFAULT_BACKGROUND): GdImage
    {
        $image = self::createCanvas($canvas->width, $canvas->height);
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, $canvas->width - 1, $canvas->height - 1, self::allocate($image, $background));
        imagealphablending($image, true);
        $this->drawCanvas($image, $canvas, 'canvas');

        return $image;
    }

    /** Draws a canvas into a surface of its size, in the renderer's paint order. */
    public function drawCanvas(GdImage $surface, PresentationCanvas $canvas, string $context): void
    {
        $items = [];
        foreach ([['image', $canvas->images], ['composite', $canvas->composites],
            ['indicator', $canvas->indicators], ['text', $canvas->textLayers],
            ['label', $canvas->labels]] as [$kind, $children]) {
            foreach ($children as $index => $child) {
                $items[] = [$child->layer, self::KIND_ORDER[$kind], $index, $child];
            }
        }
        usort($items, static fn(array $a, array $b): int => array_slice($a, 0, 3) <=> array_slice($b, 0, 3));
        foreach (array_column($items, 3) as $item) {
            match (true) {
                $item instanceof CanvasImage => $this->drawImage($surface, $item, $context),
                $item instanceof CanvasComposite => $this->drawComposite($surface, $item, $context),
                $item instanceof CanvasIndicator => $this->drawIndicator($surface, $item),
                $item instanceof CanvasTextLayer => $this->drawTextLayer($surface, $item, $context),
                default => $this->drawLabel($surface, $item, $context),
            };
        }
    }

    private function drawComposite(GdImage $surface, CanvasComposite $composite, string $context): void
    {
        if (array_any($composite->operations, static fn($operation): bool => !in_array($operation->data['type'], ['coverage_dabs', 'texture_strip'], true))) {
            $this->diagnose(sprintf('The %s draws composites with operations this still leaves out.', $context));
            return;
        }
        $width = $composite->width;
        $height = $composite->height;
        $paint = self::createCanvas($width, $height);
        foreach ($composite->operations as $operation) {
            $layer = self::createCanvas($width, $height);
            if ($operation->textureStrip !== null) {
                $this->drawTextureStrip($layer, $operation);
            } else {
                self::drawCoverageDabs($layer, $operation->data, $width, $height);
            }
            imagecopy($paint, $layer, 0, 0, 0, 0, $width, $height);
        }
        $this->drawPicture($surface, $paint, [0, 0, $width, $height], $composite->destination,
            $composite->clipRect, $composite->opacity, 1, false, false);
    }

    private function drawTextureStrip(GdImage $surface, CanvasCompositeOperation $operation): void
    {
        $data = $operation->data;
        $strip = $operation->textureStrip;
        $picture = $this->readImage($data['asset'], 'canvas texture strip');
        if ($strip === null || $picture === null) {
            return;
        }
        ['x' => $sourceX, 'y' => $sourceY, 'width' => $sourceWidth, 'height' => $sourceHeight] = $data['sourceRect'];
        if ($sourceX + $sourceWidth > imagesx($picture) || $sourceY + $sourceHeight > imagesy($picture)) {
            $this->diagnose(sprintf('Canvas texture strip %s is smaller than its source crop.', $data['asset']));
            return;
        }
        [$left, $top, $right, $bottom] = $strip->getBounds();
        $left = max(0, (int) floor($left));
        $top = max(0, (int) floor($top));
        $right = min(imagesx($surface), (int) ceil($right));
        $bottom = min(imagesy($surface), (int) ceil($bottom));
        $opacity = $data['opacity'];
        imagealphablending($surface, false);
        for ($y = $top; $y < $bottom; $y++) {
            for ($x = $left; $x < $right; $x++) {
                $sample = $strip->getSourcePoint($x + .5, $y + .5);
                if ($sample === null) {
                    continue;
                }
                [$u, $v] = $sample;
                $sampleX = (int) ($u * $sourceWidth);
                $sampleY = (int) ($v * $sourceHeight);
                $pixel = imagecolorat($picture,
                    $sourceX + ($data['flipX'] ? $sourceWidth - 1 - $sampleX : $sampleX),
                    $sourceY + ($data['flipY'] ? $sourceHeight - 1 - $sampleY : $sampleY));
                $coverage = (int) round((127 - ($pixel >> 24)) * $opacity);
                imagesetpixel($surface, $x, $y, ((127 - $coverage) << 24) | ($pixel & 0xFFFFFF));
            }
        }
    }

    private static function drawCoverageDabs(GdImage $surface, array $operation, int $width, int $height): void
    {
        $strongest = str_repeat(chr(0), $width * $height);
        $bands = array_map(static fn(array $band): array => [
            'until' => $band['until'], 'rgb' => TerminalPalette::resolveDescriptor($band['color'], 0),
        ], $operation['bands']);
        $steps = $operation['alphaSteps'];
        $minimum = $operation['minimumAlpha'];
        $falloff = $operation['falloff'];
        $opacity = $operation['opacity'];
        imagealphablending($surface, false);
        foreach ($operation['dabs'] as $dab) {
            [$centerX, $centerY] = $dab['center'];
            $radius = $dab['radius'];
            $left = max(0, (int) floor($centerX - $radius));
            $top = max(0, (int) floor($centerY - $radius));
            $right = min($width, (int) ceil($centerX + $radius));
            $bottom = min($height, (int) ceil($centerY + $radius));
            for ($y = $top; $y < $bottom; $y++) {
                for ($x = $left; $x < $right; $x++) {
                    $distance = hypot($x + .5 - $centerX, $y + .5 - $centerY) / $radius;
                    if ($distance >= 1) { continue; }
                    $alpha = (int) round($steps * $dab['opacity'] * (1 - $distance) ** $falloff);
                    $index = $y * $width + $x;
                    if ($alpha < $minimum || $alpha <= ord($strongest[$index])) { continue; }

                    $strongest[$index] = chr($alpha);
                    $band = array_find($bands, static fn(array $band): bool => $distance < $band['until']);
                    $coverage = (int) round(127 * $alpha / $steps * $opacity);
                    imagesetpixel($surface, $x, $y, ((127 - $coverage) << 24) | $band['rgb']);
                }
            }
        }
    }

    /** Draws one canvas image: cropped, mirrored, brightened, stretched to its destination and clipped. */
    public function drawImage(GdImage $surface, CanvasImage $part, string $context): void
    {
        $source = $this->loadImage($part->asset, 'canvas image');
        if ($source === null) {
            return;
        }
        $rect = $part->sourceRect;
        [$sourceX, $sourceY, $sourceWidth, $sourceHeight] = $rect === null
            ? [0, 0, imagesx($source), imagesy($source)] : [$rect->x, $rect->y, $rect->width, $rect->height];
        if ($sourceX + $sourceWidth > imagesx($source) || $sourceY + $sourceHeight > imagesy($source)) {
            $this->diagnose(sprintf('%s is smaller than the crop image %s of the %s draws from it; the image is left out.',
                $part->asset, $part->id, $context));

            return;
        }
        $rotation = $part->rotation ?? 0;
        if ($rotation === 0) {
            $this->drawPicture($surface, $source, [$sourceX, $sourceY, $sourceWidth, $sourceHeight], $part->destination,
                $part->clipRect, $part->opacity, $part->brightness, $part->flipX, $part->flipY);
            return;
        }
        if (CanvasImage::getRotatedRegionBytes($sourceWidth, $sourceHeight, $rotation) > CanvasImagePreflight::MAX_BYTES) {
            $this->diagnose(sprintf('Rotated image %s of the %s exceeds the renderer region budget.', $part->id, $context));
            return;
        }
        try {
            $destination = $part->getPreparedDestination(imagesx($source), imagesy($source));
        } catch (InvalidArgumentException $failure) {
            $this->diagnose(sprintf('Image %s of the %s cannot be rotated: %s', $part->id, $context, $failure->getMessage()));
            return;
        }
        $crop = self::createCanvas($sourceWidth, $sourceHeight);
        imagealphablending($crop, false);
        imagecopy($crop, $source, 0, 0, $sourceX, $sourceY, $sourceWidth, $sourceHeight);
        if ($part->flipX || $part->flipY) {
            imageflip($crop, $part->flipX && $part->flipY ? IMG_FLIP_BOTH
                : ($part->flipX ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL));
        }
        if ($part->brightness < 1) {
            self::adjustPixels($crop, $part->brightness, 1);
        }
        $rotated = CanvasStillRotation::rotate($crop, $rotation);
        $this->drawPicture($surface, $rotated, [0, 0, imagesx($rotated), imagesy($rotated)], $destination,
            $part->clipRect, $part->opacity, 1, false, false);
    }

    /**
     * Draws one canvas label: the renderer's raster of it, less its guard,
     * filling the label's paint bounds, turned when the label is, clipped and faded.
     */
    public function drawLabel(GdImage $surface, CanvasLabel $label, string $context): void
    {
        $bytes = $this->labelRasters[$label->measurement->layoutId] ?? null;
        $raster = $bytes === null ? false : @imagecreatefromstring($bytes);
        $guard = StillLabelPreparation::RASTER_GUARD;
        if ($raster === false || imagesx($raster) <= 2 * $guard || imagesy($raster) <= 2 * $guard) {
            $this->diagnose(sprintf('Label %s of the %s has no raster from the graphical renderer; it is left out.', $label->id, $context));

            return;
        }
        imagesavealpha($raster, true);
        $turned = $label->rotation === 180;
        $this->drawPicture($surface, $raster, [$guard, $guard, imagesx($raster) - 2 * $guard, imagesy($raster) - 2 * $guard],
            new CanvasRectangle($label->paintBounds->x, $label->paintBounds->y, $label->paintBounds->width, $label->paintBounds->height),
            $label->clipRect, $label->opacity, 1.0, $turned, $turned);
    }

    /** An outline's four edges or an underline's one, inside its bounds, as the renderer strokes them. */
    private function drawIndicator(GdImage $surface, CanvasIndicator $indicator): void
    {
        $b = $indicator->bounds;
        $s = $indicator->strokeWidth;
        $edges = match (true) {
            $indicator->kind === CanvasIndicatorKind::UNDERLINE => [[$b->x, $b->y + $b->height - $s, $b->width, $s]],
            2 * $s >= $b->width || 2 * $s >= $b->height => [[$b->x, $b->y, $b->width, $b->height]],
            default => [[$b->x, $b->y, $b->width, $s], [$b->x, $b->y + $b->height - $s, $b->width, $s],
                [$b->x, $b->y + $s, $s, $b->height - 2 * $s], [$b->x + $b->width - $s, $b->y + $s, $s, $b->height - 2 * $s]],
        };
        $color = self::allocate($surface, TerminalPalette::resolveRgb($indicator->color, TerminalPalette::DEFAULT_FOREGROUND));
        foreach ($edges as [$x, $y, $width, $height]) {
            imagefilledrectangle($surface, (int) round($x), (int) round($y),
                (int) round($x + $width) - 1, (int) round($y + $height) - 1, $color);
        }
    }

    /**
     * Paints a text layer as the renderer does: each cell of a run its
     * background when it names one, and each glyph but a space centred in its
     * cell; then the layer is masked by its clip and faded by its opacity.
     */
    private function drawTextLayer(GdImage $surface, CanvasTextLayer $layer, string $context): void
    {
        if ($layer->glyphEffects !== null) {
            $this->diagnose(sprintf('The %s draws text with glyph effects; the still draws its glyphs plain.', $context));
        }
        $cellWidth = $layer->grid->cellWidth;
        $cellHeight = $layer->grid->cellHeight;
        $width = $layer->grid->columns * $cellWidth;
        $height = $layer->grid->rows * $cellHeight;
        $left = (int) round($layer->x);
        $top = (int) round($layer->y);
        $text = self::createCanvas($width, $height);
        foreach ($layer->runs as $run) {
            foreach (mb_str_split($run->text, 1, 'UTF-8') as $offset => $glyph) {
                $cellLeft = ($run->column + $offset) * $cellWidth;
                $cellTop = $run->row * $cellHeight;
                if ($run->background !== null) {
                    imagefilledrectangle($text, $cellLeft, $cellTop, $cellLeft + $cellWidth - 1, $cellTop + $cellHeight - 1,
                        self::allocate($text, TerminalPalette::resolveRgb($run->background, TerminalPalette::DEFAULT_BACKGROUND)));
                }
                if ($glyph !== ' ') {
                    $this->drawGlyph($text, $glyph, TerminalPalette::resolveRgb($run->foreground, TerminalPalette::DEFAULT_FOREGROUND),
                        $cellLeft, $cellTop, $cellWidth, $cellHeight);
                }
            }
        }
        if ($layer->opacity < 1) {
            self::adjustPixels($text, 1.0, $layer->opacity);
        }
        $clip = $layer->clipRect;
        $maskLeft = $clip === null ? $left : max($left, (int) round($clip->x));
        $maskTop = $clip === null ? $top : max($top, (int) round($clip->y));
        $maskRight = $clip === null ? $left + $width : min($left + $width, (int) round($clip->x + $clip->width));
        $maskBottom = $clip === null ? $top + $height : min($top + $height, (int) round($clip->y + $clip->height));
        if ($maskRight > $maskLeft && $maskBottom > $maskTop) {
            imagecopy($surface, $text, $maskLeft, $maskTop, $maskLeft - $left, $maskTop - $top, $maskRight - $maskLeft, $maskBottom - $maskTop);
        }
    }

    private function drawGlyph(GdImage $image, string $glyph, int $rgb, int $cellLeft, int $cellTop, int $cellWidth, int $cellHeight): void
    {
        $font = $this->fontPath ?? self::findSystemFont();
        if ($font === null || ! is_file($font)) {
            $this->diagnose('No monospaced font was found, so text cells show their colours without their glyphs; give one with --font.');

            return;
        }
        $size = $this->fontSizes["{$cellWidth}x{$cellHeight}"] ??= $this->fitFont($font, $cellWidth, $cellHeight);
        $box = imagettfbbox($size, 0, $font, $glyph);
        $reference = imagettfbbox($size, 0, $font, 'Mg');
        if ($box === false || $reference === false) {
            $this->diagnose(sprintf('The font %s cannot draw glyphs, so text cells show their colours alone.', $font));

            return;
        }
        $glyphWidth = $box[2] - $box[0];
        // Centred across the cell, on a baseline that centres the font's capital and descender height.
        $baseline = $cellTop + (int) round(($cellHeight + (-$reference[5]) - $reference[1]) / 2);
        imagettftext($image, $size, 0, $cellLeft + (int) round(($cellWidth - $glyphWidth) / 2) - $box[0], $baseline,
            self::allocate($image, $rgb), $font, $glyph);
    }

    /** The font size whose character advance and line fit a cell, as the renderer fits its cell font. */
    private function fitFont(string $font, int $cellWidth, int $cellHeight): float
    {
        $box = imagettfbbox(100, 0, $font, '0000000000');
        $line = imagettfbbox(100, 0, $font, 'Mg');
        if ($box === false || $line === false) {
            return min($cellWidth * 0.9, $cellHeight * 0.75);
        }
        $advance = ($box[2] - $box[0]) / 10 / 100;
        $height = ($line[1] - $line[5]) / 100 * 1.2;

        return min($cellWidth * 0.95 / max($advance, 0.01), $cellHeight * 0.95 / max($height, 0.01));
    }

    /**
     * Draws a region of a picture into a surface as the renderer samples one:
     * mirrored, brightened and faded, stretched to its destination, masked by
     * its clip and by the surface.
     *
     * @param array{int, int, int, int} $source The region's x, y, width and height in the picture.
     */
    private function drawPicture(GdImage $surface, GdImage $picture, array $source, CanvasRectangle $destination,
        ?CanvasRectangle $clip, float $opacity, float $brightness, bool $flipX, bool $flipY): void
    {
        [$sourceX, $sourceY, $sourceWidth, $sourceHeight] = $source;
        $drawLeft = (int) round($destination->x);
        $drawTop = (int) round($destination->y);
        $drawWidth = max(1, (int) round($destination->x + $destination->width) - $drawLeft);
        $drawHeight = max(1, (int) round($destination->y + $destination->height) - $drawTop);
        $stretched = self::createCanvas($drawWidth, $drawHeight);
        imagealphablending($stretched, false);
        imagecopyresampled($stretched, $picture, 0, 0, $sourceX, $sourceY, $drawWidth, $drawHeight, $sourceWidth, $sourceHeight);
        if ($flipX && $flipY) {
            imageflip($stretched, IMG_FLIP_BOTH);
        } elseif ($flipX || $flipY) {
            imageflip($stretched, $flipX ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }
        if ($brightness < 1 || $opacity < 1) {
            self::adjustPixels($stretched, $brightness, $opacity);
        }
        $maskLeft = $clip === null ? $drawLeft : max($drawLeft, (int) round($clip->x));
        $maskTop = $clip === null ? $drawTop : max($drawTop, (int) round($clip->y));
        $maskRight = $clip === null ? $drawLeft + $drawWidth : min($drawLeft + $drawWidth, (int) round($clip->x + $clip->width));
        $maskBottom = $clip === null ? $drawTop + $drawHeight : min($drawTop + $drawHeight, (int) round($clip->y + $clip->height));
        if ($maskRight > $maskLeft && $maskBottom > $maskTop) {
            imagecopy($surface, $stretched, $maskLeft, $maskTop, $maskLeft - $drawLeft, $maskTop - $drawTop,
                $maskRight - $maskLeft, $maskBottom - $maskTop);
        }
    }

    /** Scales an image's colour by a brightness and its coverage by an opacity, each in [0, 1], as the renderer prepares them. */
    private static function adjustPixels(GdImage $image, float $brightness, float $opacity): void
    {
        imagealphablending($image, false);
        for ($y = 0, $height = imagesy($image); $y < $height; $y++) {
            for ($x = 0, $width = imagesx($image); $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $alpha = ($pixel >> 24) & 127;
                imagesetpixel($image, $x, $y, (int) imagecolorallocatealpha($image,
                    (int) round((($pixel >> 16) & 255) * $brightness), (int) round((($pixel >> 8) & 255) * $brightness),
                    (int) round(($pixel & 255) * $brightness), 127 - (int) round((127 - $alpha) * $opacity)));
            }
        }
        imagealphablending($image, true);
    }

    /** An asset-root image, read once, or null and a diagnostic when it cannot be read. */
    private function loadImage(string $path, string $role): ?GdImage
    {
        if (!array_key_exists($path, $this->images)) {
            $this->images[$path] = $this->readImage($path, $role);
        }
        return $this->images[$path];
    }

    /** Decode a bounded use of an asset without retaining the full atlas. */
    private function readImage(string $path, string $role): ?GdImage
    {
        $root = realpath($this->assetRoot);
        $file = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $path);
        $bytes = $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) ? @file_get_contents($file) : false;
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($image === false) {
            $this->diagnose(sprintf('The %s %s cannot be read from the project\'s assets; what it draws is left out.', $role, $path));

            return null;
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagesavealpha($image, true);

        return $image;
    }

    private function diagnose(string $message): void
    {
        if (! in_array($message, $this->diagnostics, true)) {
            $this->diagnostics[] = $message;
        }
    }

    /** A transparent true-colour image. */
    private static function createCanvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, max(1, $width) - 1, max(1, $height) - 1, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    private static function allocate(GdImage $image, int $rgb): int
    {
        return (int) imagecolorallocate($image, ($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255);
    }
}
