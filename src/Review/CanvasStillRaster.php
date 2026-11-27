<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use GdImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasColorTransform;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGroup;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageBottomFade;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageBudgetExceeded;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicator;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasIndicatorKind;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasLabel;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use InvalidArgumentException;
use RuntimeException;

/**
 * Draws a still of one graphical canvas, such as a menu, dialogue, storybook
 * or modal over a scene, as the graphical renderer paints it: every image,
 * indicator, text layer, label and group in one order by layer, and at a tie
 * in the renderer's own order (images, composites, indicators, text, labels,
 * groups), each in the order the canvas lists it. Images are cropped, mirrored, rotated, brightened,
 * faded, stretched to their destination and clipped. Text layers paint each
 * cell's background and glyph, centred in the cell, in a monospaced system
 * font fitted to the layer's cell; labels show the graphical renderer's own
 * raster of them. A group paints its local canvas as one drawing at the
 * group's origin ({@see paintLocalCanvas()}), groups within it included, in
 * the group's colour treatment when it has one. A scaled group draws everything
 * in its canvas at the place and size the scale gives it, never at the
 * canvas's own size to be enlarged after ({@see CanvasStillTransform}).
 *
 * It reads only the canvas the Engine composed, so what it draws is what the
 * game would send a graphical renderer. It is a review picture, not the
 * renderer: glyphs come from a system font through GD, alpha has GD's 7 bits,
 * coordinates are rounded to whole pixels. A composite's source-over fills, through
 * every mask the Engine's composite contract gives a fill ({@see CanvasStillMask}), its coverage dabs and its
 * texture strips are drawn; a composite holding any other operation is left out whole. Glyph effects are drawn
 * plain. Everything left out is named once in {@see $diagnostics}.
 *
 * A still's canvases are admitted together before any of them is drawn, as the Engine admits a frame's for a
 * renderer with the region budget ({@see prepareCanvases()}): an image costs the crop it is drawn from and each
 * changed variant of it the still draws, a group's colour treatment of it among them, and a still whose images
 * exceed the Engine's limits is refused whole, with the Engine's own account of them.
 */
final class CanvasStillRaster
{
    /**
     * The renderer features the raster draws, as a graphical renderer negotiates them. The region budget is the
     * terms its images are admitted and held on ({@see prepareCanvases()}); the Engine composes a group's colour
     * treatment only for a renderer that names it beside the treatment. An indicator may name the image or
     * composite it marks by a path of groups from its still's root canvas: the Engine follows every path when the
     * still's canvases are admitted and refuses the still whole when one leads nowhere, and the indicator is
     * drawn where its own bounds put it, in the colour treatment of the group it is in. A group's scale reaches
     * everything the group draws: images, composites, indicators, text cells and their glyphs, labels, clips and
     * the groups within it, whose own scales multiply with it; the Engine charges the group's images in the pixels
     * of its canvas whatever the factor.
     */
    public const array CAPABILITIES = [
        RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_REGION_BUDGET,
        RendererSessionConfig::CANVAS_OVERLAY, RendererSessionConfig::CANVAS_CLIP_OPACITY,
        RendererSessionConfig::SPRITE_SOURCE_RECT, RendererSessionConfig::CANVAS_IMAGE_TONE, RendererSessionConfig::CANVAS_IMAGE_FLIP,
        RendererSessionConfig::TEXT_LAYOUT, RendererSessionConfig::CANVAS_LABELS,
        RendererSessionConfig::CANVAS_IMAGE_ROTATION, RendererSessionConfig::CANVAS_COVERAGE_DABS,
        RendererSessionConfig::CANVAS_TEXTURE_STRIP, RendererSessionConfig::CANVAS_TEXTURE_STRIP_CLIP,
        RendererSessionConfig::CANVAS_IMAGE_BOTTOM_FADE, RendererSessionConfig::CANVAS_GROUPS,
        RendererSessionConfig::CANVAS_GROUP_COLOR, RendererSessionConfig::CANVAS_INDICATOR_IMAGE_PATH,
        RendererSessionConfig::CANVAS_GROUP_SCALE,
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
    private const array KIND_ORDER = ['image' => 0, 'composite' => 1, 'indicator' => 2, 'text' => 3, 'label' => 4, 'group' => 5];

    /** The most colours a colour treatment remembers the result for at one time ({@see treatColors()}). */
    private const int TREATED_COLOR_MEMORY = 4096;

    /** @var list<string> What the still could not draw, once each. */
    public private(set) array $diagnostics = [];

    /**
     * The regions the stills' images are drawn from, each a crop of a picture or the whole of one, by picture and
     * crop, the least recently prepared first ({@see prepareCanvases()}).
     *
     * @var array<string, GdImage>
     */
    private array $regions = [];
    /** @var array<string, float> Font sizes fitted to each cell size. */
    private array $fontSizes = [];
    /**
     * Where the canvas being drawn lies on the surface: on the surface's own corner at its own size for a
     * canvas drawn whole, and for a local canvas wherever its groups' origins and scales put it, read from the
     * corner of the surface that holds the part of it that shows ({@see paintPlacedCanvas()}).
     */
    private CanvasStillTransform $transform;

    /**
     * @param array<string, string> $labelRasters Each label's raster from the graphical renderer, by layout id.
     */
    public function __construct(private readonly string $assetRoot, private readonly ?string $fontPath = null,
        private array $labelRasters = [])
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Drawing a graphical still needs the PHP gd extension.');
        }
        $this->transform = CanvasStillTransform::createIdentity();
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

    /**
     * A still of a whole canvas at its logical size over a background colour (0xRRGGBB).
     *
     * @throws CanvasImageBudgetExceeded When the canvas's images exceed the Engine's budget for one frame.
     */
    public function rasterize(PresentationCanvas $canvas, int $background = TerminalPalette::DEFAULT_BACKGROUND): GdImage
    {
        $this->prepareCanvases([$canvas]);
        $image = self::createCanvas($canvas->width, $canvas->height);
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, $canvas->width - 1, $canvas->height - 1, self::allocate($image, $background));
        imagealphablending($image, true);
        $this->drawCanvas($image, $canvas, 'canvas');

        return $image;
    }

    /**
     * Admits the canvases one still draws, together, and prepares the regions their images are drawn from. A
     * raster that draws canvases itself ({@see drawCanvas()}, {@see paintLocalCanvas()}) gives every canvas of
     * its still here first.
     *
     * The canvases are admitted whole, groups and all, by the Engine's own accounting
     * ({@see CanvasImagePreflight::inspectCanvasResources()}) for a renderer of this raster's features. With the
     * region budget among them an image costs the crop it is drawn from and one variant for each distinct way that
     * crop is drawn changed: toned, mirrored, turned, faded toward its bottom edge, or in the colour treatment of
     * the groups it is drawn in, a group's own first and its parent's after. A composite costs its finished
     * surface once for each such treatment, a treatment that changes nothing costs nothing, and a picture a
     * composite operation uses whole still costs all of itself. A still over the Engine's limits is refused before
     * any of it is drawn, with the Engine's account of what exceeds them.
     *
     * The raster then holds no more than was charged: each picture is decoded once, the crops the canvases draw
     * are cut from it, and the picture is let go; a variant is made for the drawing that needs it and kept by
     * nothing. Regions prepared for earlier stills stay while they fit the same budget beside this still's, the
     * least recently prepared released first. A drawing the Engine cannot account for, because a picture of it
     * is missing or is not one the Engine reads, is charged nothing and kept nowhere: its picture is read for
     * each drawing ({@see findRegion()}), and left out and named when it cannot be.
     *
     * @param list<PresentationCanvas> $canvases Every canvas the still draws; the canvases their groups draw are found here.
     * @throws CanvasImageBudgetExceeded When the canvases' images exceed the Engine's budget for one frame.
     */
    public function prepareCanvases(array $canvases): void
    {
        $images = [];
        // Named by reference at every level: an arrow function would hand the collector a copy of the list.
        $inspect = function () use ($canvases, &$images): array {
            return CanvasImagePreflight::inspectCanvasResources($canvases, $this->assetRoot,
                function (CanvasImage|CanvasComposite $drawing) use (&$images): bool {
                    $charged = $this->canInspect($drawing);
                    if ($charged && $drawing instanceof CanvasImage) {
                        // The crops prepared are the crops the Engine charged.
                        $images[] = $drawing;
                    }

                    return $charged;
                });
        };
        PresentationSession::runForSession(self::supports(...), static fn(): array => PngAssetPreflight::runWithSnapshot($inspect));
        $crops = [];
        foreach ($images as $image) {
            $key = self::getRegionKey($image);
            if (isset($this->regions[$key])) {
                // Drawn again, so released after every region this still does not draw.
                $region = $this->regions[$key];
                unset($this->regions[$key]);
                $this->regions[$key] = $region;
            } else {
                $crops[$image->asset][$key] = $image->sourceRect;
            }
        }
        foreach ($crops as $asset => $regions) {
            $picture = $this->decodeImage($asset);
            foreach ($picture === null ? [] : $regions as $key => $crop) {
                $region = self::cutRegion($picture, $crop);
                if ($region !== null) {
                    $this->regions[$key] = $region;
                }
            }
        }
        $held = array_sum(array_map(self::measureRegion(...), $this->regions));
        foreach ($this->regions as $key => $region) {
            // This still's regions are the last, and the Engine charged each more than it holds.
            if ($held <= CanvasImagePreflight::MAX_BYTES) {
                break;
            }
            $held -= self::measureRegion($region);
            unset($this->regions[$key]);
        }
    }

    /**
     * Whether the Engine can account for a drawing: every picture it draws from is one the Engine reads from
     * the project's assets, and each crop lies inside its picture. The drawing is asked about alone, in no group.
     *
     * @throws CanvasImageBudgetExceeded When the drawing alone exceeds the Engine's budget.
     */
    private function canInspect(CanvasImage|CanvasComposite $drawing): bool
    {
        try {
            $drawing instanceof CanvasImage ? CanvasImagePreflight::inspect([$drawing], $this->assetRoot)
                : CanvasImagePreflight::inspect([], $this->assetRoot, [$drawing]);

            return true;
        } catch (CanvasImageBudgetExceeded $refusal) {
            throw $refusal;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** Draws a canvas into a surface of its size, in the renderer's paint order. */
    public function drawCanvas(GdImage $surface, PresentationCanvas $canvas, string $context): void
    {
        $items = [];
        foreach ([['image', $canvas->images], ['composite', $canvas->composites],
            ['indicator', $canvas->indicators], ['text', $canvas->textLayers],
            ['label', $canvas->labels], ['group', $canvas->groups]] as [$kind, $children]) {
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
                $item instanceof CanvasGroup => $this->paintGroup($surface, $item, $context),
                default => $this->drawLabel($surface, $item, $context),
            };
        }
    }

    /**
     * Paints a local canvas as one drawing with its top-left corner at a whole pixel of the canvas being drawn:
     * a world canvas on its field. The local canvas keeps its own geometry and is masked by its own rectangle,
     * by a clip of the canvas being drawn when one is given, and by the surface ({@see paintPlacedCanvas()}).
     */
    public function paintLocalCanvas(GdImage $surface, PresentationCanvas $canvas, int $left, int $top, ?CanvasRectangle $clip,
        string $context, ?CanvasColorTransform $colorTransform = null): void
    {
        $this->paintPlacedCanvas($surface, $canvas, $this->transform->place($left, $top), $clip, $context, $colorTransform);
    }

    /**
     * Paints a group's canvas where the group's origin and scale put it ({@see CanvasStillTransform::nestGroup()}),
     * inside the group's clip and in the group's colour treatment.
     */
    private function paintGroup(GdImage $surface, CanvasGroup $group, string $context): void
    {
        $this->paintPlacedCanvas($surface, $group->canvas, $this->transform->nestGroup($group), $group->clipRect,
            sprintf('%s group %s', $context, $group->id), $group->colorTransform);
    }

    /**
     * Paints a local canvas as one drawing where a place of its own puts it on the surface. The local canvas
     * keeps its own geometry and is masked by its own rectangle as that place shows it, by a clip of the canvas
     * being drawn when one is given, and by the surface, so the clips of every canvas it is nested in intersect.
     * The clip is the canvas being drawn's own: it stays where that canvas has it whatever the local canvas's
     * scale. A corner off the surface stays there: what does not show is not drawn, and only the part that
     * shows takes memory.
     *
     * Everything in the local canvas is drawn into that part at the place and size it has on the surface, so
     * a scaled canvas is never drawn at its own size and enlarged. A colour treatment is applied once to the
     * flattened part, after everything in the local canvas is drawn and before the part is blended onto the
     * canvas being drawn ({@see treatColors()}). A group within the local canvas is treated the same way when
     * it is flattened, so its own treatment comes first and this one follows.
     */
    private function paintPlacedCanvas(GdImage $surface, PresentationCanvas $canvas, CanvasStillTransform $placed,
        ?CanvasRectangle $clip, string $context, ?CanvasColorTransform $colorTransform): void
    {
        $visible = [max(0, $placed->getX(0)), max(0, $placed->getY(0)),
            min(imagesx($surface), $placed->getX($canvas->width)), min(imagesy($surface), $placed->getY($canvas->height))];
        if ($clip !== null) {
            [$clipLeft, $clipTop, $clipRight, $clipBottom] = $this->transform->getEdges($clip);
            $visible = [max($visible[0], $clipLeft), max($visible[1], $clipTop), min($visible[2], $clipRight), min($visible[3], $clipBottom)];
        }
        [$visibleLeft, $visibleTop, $visibleRight, $visibleBottom] = $visible;
        if ($visibleRight <= $visibleLeft || $visibleBottom <= $visibleTop) {
            return;
        }
        $part = self::createCanvas($visibleRight - $visibleLeft, $visibleBottom - $visibleTop);
        $outer = $this->transform;
        $this->transform = $placed->rebase($visibleLeft, $visibleTop);
        try {
            $this->drawCanvas($part, $canvas, $context);
        } finally {
            $this->transform = $outer;
        }
        if ($colorTransform !== null) {
            self::treatColors($part, $colorTransform);
        }
        imagecopy($surface, $part, $visibleLeft, $visibleTop, 0, 0, $visibleRight - $visibleLeft, $visibleBottom - $visibleTop);
    }

    /**
     * Gives a flattened drawing a group's colour treatment: every pixel's straight colour, each channel taken to
     * 0..1, goes through the Engine's own calculation ({@see CanvasColorTransform::transformRgb()}) and back to
     * the nearest of GD's 256 steps. Coverage is never changed, and a pixel nothing covers is left as it is, so
     * no offset can give it a colour.
     */
    private static function treatColors(GdImage $image, CanvasColorTransform $transform): void
    {
        // Flat artwork repeats few colours; the memory of them is emptied before it can outgrow the drawing.
        $treated = [];
        imagealphablending($image, false);
        for ($y = 0, $height = imagesy($image); $y < $height; $y++) {
            for ($x = 0, $width = imagesx($image); $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $transparency = $pixel & 0x7F000000;
                if ($transparency === 0x7F000000) {
                    continue;
                }
                $rgb = $pixel & 0xFFFFFF;
                if (! isset($treated[$rgb])) {
                    if (count($treated) >= self::TREATED_COLOR_MEMORY) {
                        $treated = [];
                    }
                    [$red, $green, $blue] = $transform->transformRgb(($rgb >> 16) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255);
                    $treated[$rgb] = ((int) round($red * 255) << 16) | ((int) round($green * 255) << 8) | (int) round($blue * 255);
                }
                imagesetpixel($image, $x, $y, $transparency | $treated[$rgb]);
            }
        }
        imagealphablending($image, true);
    }

    /**
     * Paints a composite: its operations in order onto a surface of its own, each blended over the ones before
     * it, and that surface stretched to the composite's destination. A composite is drawn whole or not at all:
     * one holding an operation the still does not draw (an image, a stroke, a world overview, or a fill blended
     * by `screen`) is left out and named, since the operations after it would be blended against the wrong picture.
     */
    private function drawComposite(GdImage $surface, CanvasComposite $composite, string $context): void
    {
        if (array_any($composite->operations, static function (CanvasCompositeOperation $operation): bool {
            $data = $operation->data;

            return match ($data['type']) {
                'coverage_dabs', 'texture_strip' => false,
                'fill' => $data['blend'] !== 'source_over',
                default => true,
            };
        })) {
            $this->diagnose(sprintf('The %s draws composites with operations this still leaves out.', $context));
            return;
        }
        $width = $composite->width;
        $height = $composite->height;
        $paint = self::createCanvas($width, $height);
        // Each picture the composite's masks read is decoded once for the composite, however many fills it masks.
        $maskPictures = [];
        foreach ($composite->operations as $operation) {
            $layer = self::createCanvas($width, $height);
            match ($operation->data['type']) {
                'fill' => $this->drawFill($layer, $operation->data, $maskPictures),
                'texture_strip' => $this->drawTextureStrip($layer, $operation),
                'coverage_dabs' => self::drawCoverageDabs($layer, $operation->data, $width, $height),
            };
            imagecopy($paint, $layer, 0, 0, 0, 0, $width, $height);
        }
        $this->drawPicture($surface, $paint, [0, 0, $width, $height], $composite->destination,
            $composite->clipRect, $composite->opacity, 1, false, false);
    }

    /**
     * Paints a fill onto a clear surface of its composite: its brush over its destination, each pixel covered
     * by as much of the pixel as the destination holds, by the fill's opacity, and by what the fill's masks let
     * through at the pixel's centre ({@see CanvasStillMask}). A fill whose mask reads a picture that cannot be
     * had is left out, and the picture is named.
     *
     * @param array<string, mixed> $operation The fill, as `CanvasCompositeOperation::$data` holds it.
     * @param array<string, GdImage> $maskPictures The pictures the composite's masks have read so far, by their path.
     */
    private function drawFill(GdImage $surface, array $operation, array &$maskPictures): void
    {
        $mask = null;
        if ($operation['masks'] !== []) {
            $mask = CanvasStillMask::prepare($operation['masks'], function (string $asset) use (&$maskPictures): ?GdImage {
                return $maskPictures[$asset] ??= $this->readImage($asset, 'canvas mask image');
            });
            if ($mask === null) {
                return;
            }
        }
        ['x' => $left, 'y' => $top, 'width' => $width, 'height' => $height] = $operation['destination'];
        $right = $left + $width;
        $bottom = $top + $height;
        $endX = min(imagesx($surface), (int) ceil($right));
        $endY = min(imagesy($surface), (int) ceil($bottom));
        $startX = max(0, (int) floor($left));
        $brush = $operation['brush'];
        $solid = $brush['type'] === 'solid' ? TerminalPalette::resolveDescriptor($brush['color'], 0) : null;
        $stops = array_map(static function (array $stop): array {
            $rgb = TerminalPalette::resolveDescriptor($stop['color'], 0);
            $opacity = $stop['opacity'];

            return [$stop['offset'], $opacity, ($rgb >> 16) * $opacity,
                (($rgb >> 8) & 255) * $opacity, ($rgb & 255) * $opacity];
        }, $brush['stops'] ?? []);
        $position = match ($brush['type']) {
            'linear' => self::getLinearGradientPosition($brush['start'], $brush['end']),
            'radial' => static fn(float $x, float $y): float => hypot(
                ($x - $brush['center'][0]) / $brush['radius'][0], ($y - $brush['center'][1]) / $brush['radius'][1]),
            'solid' => null,
        };
        imagealphablending($surface, false);
        for ($y = max(0, (int) floor($top)); $y < $endY; $y++) {
            $rowOpacity = (min($y + 1, $bottom) - max($y, $top)) * $operation['opacity'];
            for ($x = $startX; $x < $endX; $x++) {
                $opacity = (min($x + 1, $right) - max($x, $left)) * $rowOpacity;
                if ($mask !== null) {
                    $opacity *= $mask->getCoverage($x + .5, $y + .5);
                }
                $pixel = $position === null
                    ? ((127 - (int) round(127 * $opacity)) << 24) | $solid
                    : self::getGradientPixel($stops, $position($x + .5, $y + .5), $opacity);
                imagesetpixel($surface, $x, $y, $pixel);
            }
        }
    }

    private static function getLinearGradientPosition(array $start, array $end): \Closure
    {
        $dx = $end[0] - $start[0];
        $dy = $end[1] - $start[1];
        $length = hypot($dx, $dy);
        $ux = $dx / $length;
        $uy = $dy / $length;

        return static fn(float $x, float $y): float => (($x - $start[0]) * $ux + ($y - $start[1]) * $uy) / $length;
    }

    /** Interpolate premultiplied stops, then convert to GD's straight colour and inverse alpha. */
    private static function getGradientPixel(array $stops, float $position, float $opacity): int
    {
        $position = min(1.0, max(0.0, $position));
        $upper = 1;
        while ($position > $stops[$upper][0]) {
            $upper++;
        }
        [$from, $fromAlpha, $fromRed, $fromGreen, $fromBlue] = $stops[$upper - 1];
        [$to, $toAlpha, $toRed, $toGreen, $toBlue] = $stops[$upper];
        $t = ($position - $from) / ($to - $from);
        $alpha = $fromAlpha + ($toAlpha - $fromAlpha) * $t;
        $coverage = (int) round(127 * $alpha * $opacity);
        if ($coverage === 0) {
            return 127 << 24;
        }
        $red = (int) round(($fromRed + ($toRed - $fromRed) * $t) / $alpha);
        $green = (int) round(($fromGreen + ($toGreen - $fromGreen) * $t) / $alpha);
        $blue = (int) round(($fromBlue + ($toBlue - $fromBlue) * $t) / $alpha);

        return ((127 - $coverage) << 24) | ($red << 16) | ($green << 8) | $blue;
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
                $factor = $strip->getClipCoverage($x + .5, $y + .5);
                if ($factor === 0.0) {
                    continue;
                }
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
                $coverage = (int) round((127 - ($pixel >> 24)) * $opacity * $factor);
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
        $region = $this->findRegion($part, $context);
        if ($region === null) {
            return;
        }
        $width = imagesx($region);
        $height = imagesy($region);
        $rotation = $part->rotation ?? 0;
        if ($rotation === 0) {
            $this->drawPicture($surface, $region, [0, 0, $width, $height], $part->destination,
                $part->clipRect, $part->opacity, $part->brightness, $part->flipX, $part->flipY, $part->bottomFade);
            return;
        }
        if (CanvasImage::getRotatedRegionBytes($width, $height, $rotation) > CanvasImagePreflight::MAX_BYTES) {
            $this->diagnose(sprintf('Rotated image %s of the %s exceeds the renderer region budget.', $part->id, $context));
            return;
        }
        try {
            $destination = $part->getPreparedDestination($width, $height);
        } catch (InvalidArgumentException $failure) {
            $this->diagnose(sprintf('Image %s of the %s cannot be rotated: %s', $part->id, $context, $failure->getMessage()));
            return;
        }
        $crop = self::createCanvas($width, $height);
        imagealphablending($crop, false);
        imagecopy($crop, $region, 0, 0, 0, 0, $width, $height);
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
     * The region an image is drawn from: the one prepared for its still ({@see prepareCanvases()}), or one read
     * for this drawing alone and kept by nothing, when the image was not prepared. An image whose picture cannot
     * be read, or is smaller than its crop, has none and is named.
     */
    private function findRegion(CanvasImage $part, string $context): ?GdImage
    {
        $prepared = $this->regions[self::getRegionKey($part)] ?? null;
        if ($prepared !== null) {
            return $prepared;
        }
        $picture = $this->readImage($part->asset, 'canvas image');
        if ($picture === null) {
            return null;
        }
        $region = self::cutRegion($picture, $part->sourceRect);
        if ($region === null) {
            $this->diagnose(sprintf('%s is smaller than the crop image %s of the %s draws from it; the image is left out.',
                $part->asset, $part->id, $context));
        }

        return $region;
    }

    /** What names an image's region: its picture and its crop of it. */
    private static function getRegionKey(CanvasImage $image): string
    {
        return $image->asset . "\0" . ($image->sourceRect === null ? '' : implode(',', $image->sourceRect->toArray()));
    }

    /** A crop of a picture as an image of its own, the picture itself when there is no crop, or null when the crop runs off it. */
    private static function cutRegion(GdImage $picture, ?SpriteSourceRect $crop): ?GdImage
    {
        if ($crop === null) {
            return $picture;
        }
        if ($crop->x + $crop->width > imagesx($picture) || $crop->y + $crop->height > imagesy($picture)) {
            return null;
        }
        $region = imagecreatetruecolor($crop->width, $crop->height);
        imagealphablending($region, false);
        imagecopy($region, $picture, 0, 0, $crop->x, $crop->y, $crop->width, $crop->height);
        imagesavealpha($region, true);

        return $region;
    }

    /** The bytes a region holds: four for each of its pixels, as the Engine counts a decoded picture. */
    private static function measureRegion(GdImage $region): int
    {
        return imagesx($region) * imagesy($region) * 4;
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
            [$left, $top, $right, $bottom] = [$this->transform->getX($x), $this->transform->getY($y),
                $this->transform->getX($x + $width), $this->transform->getY($y + $height)];
            // An edge a scale leaves thinner than half a pixel covers none.
            if ($right > $left && $bottom > $top) {
                imagefilledrectangle($surface, $left, $top, $right - 1, $bottom - 1, $color);
            }
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
        // The grid's corner is taken to its pixel and the cells are laid from it, each edge at its own pixel, so
        // neighbouring cells meet at any scale; at the canvas's own size every cell is its grid's size.
        $left = $this->transform->getX($layer->x);
        $top = $this->transform->getY($layer->y);
        $width = $this->transform->getSpan($layer->grid->columns * $cellWidth);
        $height = $this->transform->getSpan($layer->grid->rows * $cellHeight);
        if ($width < 1 || $height < 1) {
            return;
        }
        $text = self::createCanvas($width, $height);
        foreach ($layer->runs as $run) {
            $cellTop = $this->transform->getSpan($run->row * $cellHeight);
            $cellBottom = $this->transform->getSpan(($run->row + 1) * $cellHeight);
            foreach (mb_str_split($run->text, 1, 'UTF-8') as $offset => $glyph) {
                $cellLeft = $this->transform->getSpan(($run->column + $offset) * $cellWidth);
                $cellRight = $this->transform->getSpan(($run->column + $offset + 1) * $cellWidth);
                if ($cellRight <= $cellLeft || $cellBottom <= $cellTop) {
                    continue;
                }
                if ($run->background !== null) {
                    imagefilledrectangle($text, $cellLeft, $cellTop, $cellRight - 1, $cellBottom - 1,
                        self::allocate($text, TerminalPalette::resolveRgb($run->background, TerminalPalette::DEFAULT_BACKGROUND)));
                }
                if ($glyph !== ' ') {
                    $this->drawGlyph($text, $glyph, TerminalPalette::resolveRgb($run->foreground, TerminalPalette::DEFAULT_FOREGROUND),
                        $cellLeft, $cellTop, $cellRight - $cellLeft, $cellBottom - $cellTop,
                        $this->transform->factor * $cellWidth, $this->transform->factor * $cellHeight);
                }
            }
        }
        if ($layer->opacity < 1) {
            self::adjustPixels($text, 1.0, $layer->opacity);
        }
        $this->copyClipped($surface, $text, $left, $top, $width, $height, $layer->clipRect);
    }

    /**
     * Draws a glyph centred in a cell of the surface, in the font size fitted to the cell as the canvas being
     * drawn shows it: the grid's cell at the canvas's own size, and the scaled cell under a scale.
     */
    private function drawGlyph(GdImage $image, string $glyph, int $rgb, int $cellLeft, int $cellTop, int $cellWidth, int $cellHeight,
        float $fittedWidth, float $fittedHeight): void
    {
        $font = $this->fontPath ?? self::findSystemFont();
        if ($font === null || ! is_file($font)) {
            $this->diagnose('No monospaced font was found, so text cells show their colours without their glyphs; give one with --font.');

            return;
        }
        $size = $this->fontSizes["{$fittedWidth}x{$fittedHeight}"] ??= $this->fitFont($font, $fittedWidth, $fittedHeight);
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
    private function fitFont(string $font, float $cellWidth, float $cellHeight): float
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
     * mirrored, brightened and faded, stretched to its destination where the
     * canvas being drawn has it on the surface, masked by its clip and by the
     * surface. The region is sampled once, straight to the size it shows at.
     * A destination whose edges come to the same pixel along either axis covers
     * no pixel and nothing is drawn of it, an image, a composite and a label
     * alike, as an indicator's edge and a text cell that thin draw nothing.
     *
     * @param array{int, int, int, int} $source The region's x, y, width and height in the picture.
     */
    private function drawPicture(GdImage $surface, GdImage $picture, array $source, CanvasRectangle $destination,
        ?CanvasRectangle $clip, float $opacity, float $brightness, bool $flipX, bool $flipY,
        ?CanvasImageBottomFade $bottomFade = null): void
    {
        [$sourceX, $sourceY, $sourceWidth, $sourceHeight] = $source;
        [$drawLeft, $drawTop, $drawRight, $drawBottom] = $this->transform->getEdges($destination);
        $drawWidth = $drawRight - $drawLeft;
        $drawHeight = $drawBottom - $drawTop;
        if ($drawWidth < 1 || $drawHeight < 1) {
            return;
        }
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
        if ($bottomFade !== null) {
            self::applyBottomFade($stretched, $bottomFade);
        }
        $this->copyClipped($surface, $stretched, $drawLeft, $drawTop, $drawWidth, $drawHeight, $clip);
    }

    /**
     * Copies what was drawn for a rectangle of the surface onto it, masked by a clip of the canvas being drawn,
     * where that canvas has the clip on the surface, and by the surface.
     */
    private function copyClipped(GdImage $surface, GdImage $drawn, int $left, int $top, int $width, int $height, ?CanvasRectangle $clip): void
    {
        [$clipLeft, $clipTop, $clipRight, $clipBottom] = $clip === null ? [$left, $top, $left + $width, $top + $height]
            : $this->transform->getEdges($clip);
        $maskLeft = max(0, $left, $clipLeft);
        $maskTop = max(0, $top, $clipTop);
        $maskRight = min(imagesx($surface), $left + $width, $clipRight);
        $maskBottom = min(imagesy($surface), $top + $height, $clipBottom);
        if ($maskRight > $maskLeft && $maskBottom > $maskTop) {
            imagecopy($surface, $drawn, $maskLeft, $maskTop, $maskLeft - $left, $maskTop - $top, $maskRight - $maskLeft, $maskBottom - $maskTop);
        }
    }

    private static function applyBottomFade(GdImage $image, CanvasImageBottomFade $fade): void
    {
        imagealphablending($image, false);
        $width = imagesx($image);
        $height = imagesy($image);
        for ($x = 0; $x < $width; $x++) {
            $start = $fade->getStart(($x + .5) / $width);
            for ($y = max(0, (int) ceil($start * $height - .5)); $y < $height; $y++) {
                $pixel = imagecolorat($image, $x, $y);
                $coverage = (127 - ($pixel >> 24)) * $fade->getCoverage($start, ($y + .5) / $height);
                imagesetpixel($image, $x, $y, ((127 - (int) round($coverage)) << 24) | ($pixel & 0xffffff));
            }
        }
        imagealphablending($image, true);
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

    /** A picture of the project's assets, decoded whole for one use and kept by nothing, or null and a diagnostic when it cannot be read. */
    private function readImage(string $path, string $role): ?GdImage
    {
        $image = $this->decodeImage($path);
        if ($image === null) {
            $this->diagnose(sprintf('The %s %s cannot be read from the project\'s assets; what it draws is left out.', $role, $path));
        }

        return $image;
    }

    /** A picture of the project's assets, decoded whole, or null when it cannot be read. */
    private function decodeImage(string $path): ?GdImage
    {
        $root = realpath($this->assetRoot);
        $file = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $path);
        $bytes = $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) ? @file_get_contents($file) : false;
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($image === false) {
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
