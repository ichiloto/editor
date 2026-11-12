<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use GdImage;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposition;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use InvalidArgumentException;

/**
 * Draws one composed scene frame into an image the size of the graphical
 * screen, as a graphical renderer paints the frame: the field's viewport
 * (its retained world, sprites, field text and world canvases, drawn by the
 * field still raster, {@see FieldStillRaster}) at the viewport's offset and
 * zoom, then the screen's text grid (UI the frame leaves to Terminal text,
 * such as a window a game without a menu theme draws in text), then the
 * frame's screen canvas with its overlay composed above it as
 * the Engine composes them ({@see PresentationCanvas::composeOverlay()}):
 * dialogue, notices, cinematic covers, stages and what is lifted above the
 * cover, drawn by the canvas raster ({@see CanvasStillRaster}), which paints
 * a canvas's translated groups at their layers, each in its colour treatment.
 *
 * It reads only what the Engine composed. What the two rasters leave out is
 * named in {@see $diagnostics}.
 */
final class SceneFrameRaster
{
    /**
     * The renderer features the frame raster draws, as a graphical renderer negotiates them: both rasters', and
     * canvas compositing, which the cinematic stage plane and art lifted above its cover need. The canvas raster
     * draws the fills, coverage dabs and texture strips of a composite and names any other operation it leaves out.
     */
    public const array CAPABILITIES = [...FieldStillRaster::CAPABILITIES, ...CanvasStillRaster::CAPABILITIES,
        RendererSessionConfig::CANVAS_COMPOSITING];

    /** @var list<string> What the frames could not draw, once each. */
    public private(set) array $diagnostics = [];

    private readonly FieldStillRaster $field;
    private readonly CanvasStillRaster $canvas;

    public function __construct(string $assetRoot, ?string $fontPath = null)
    {
        $this->field = new FieldStillRaster($assetRoot, $fontPath);
        $this->canvas = new CanvasStillRaster($assetRoot, $fontPath);
    }

    /** Whether the frame raster draws a renderer feature. */
    public static function supports(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }

    /**
     * Draws a frame composed for a graphical screen of the given grid.
     *
     * @throws InvalidArgumentException When the frame's field has no complete text snapshot.
     */
    public function rasterize(SceneFrameComposition $frame, RendererGridConfig $grid): GdImage
    {
        $width = $grid->columns * $grid->cellWidth;
        $height = $grid->rows * $grid->cellHeight;
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, TerminalPalette::DEFAULT_BACKGROUND);
        imagealphablending($image, true);
        if ($frame->snapshot !== null && $frame->viewport !== null && $frame->world !== null) {
            $this->drawField($image, $frame, $grid);
        }
        $this->drawScreenText($image, $frame, $grid);
        $screen = $frame->screenOverlay === null ? $frame->canvas : PresentationCanvas::composeOverlay($frame->canvas, $frame->screenOverlay);
        if ($screen !== null) {
            $this->drawScreen($image, $screen, $width, $height);
        }
        $this->collectDiagnostics();

        return $image;
    }

    /** The viewport's rectangle of the map, drawn at its zoom where the frame places it, inside its clip. */
    private function drawField(GdImage $image, SceneFrameComposition $frame, RendererGridConfig $grid): void
    {
        $viewport = $frame->viewport;
        $cells = new FieldViewport($grid, $viewport->scale);
        $origin = ['x' => $viewport->worldOriginX, 'y' => $viewport->worldOriginY];
        $still = FieldStill::fromComposition($frame, $origin,
            ['x' => $origin['x'], 'y' => $origin['y'], 'width' => $cells->columns, 'height' => $cells->rows]);
        $field = $this->field->rasterize($still);
        $clip = $viewport->clipRect;
        imagesetclip($image, (int) floor($clip->x), (int) floor($clip->y),
            (int) ceil($clip->x + $clip->width) - 1, (int) ceil($clip->y + $clip->height) - 1);
        imagecopyresampled($image, $field, (int) round($viewport->x), (int) round($viewport->y), 0, 0,
            (int) round(imagesx($field) * $viewport->scale), (int) round(imagesy($field) * $viewport->scale), imagesx($field), imagesy($field));
        imagesetclip($image, 0, 0, imagesx($image) - 1, imagesy($image) - 1);
    }

    /** The screen canvas over the field, stretched to the screen when it was composed at another size. */
    private function drawScreen(GdImage $image, PresentationCanvas $screen, int $width, int $height): void
    {
        if ($screen->width === $width && $screen->height === $height) {
            $this->canvas->drawCanvas($image, $screen, 'screen');

            return;
        }
        $surface = imagecreatetruecolor($screen->width, $screen->height);
        imagealphablending($surface, false);
        imagefilledrectangle($surface, 0, 0, $screen->width - 1, $screen->height - 1, imagecolorallocatealpha($surface, 0, 0, 0, 127));
        imagesavealpha($surface, true);
        imagealphablending($surface, true);
        $this->canvas->drawCanvas($surface, $screen, 'screen');
        imagecopyresampled($image, $surface, 0, 0, 0, 0, $width, $height, $screen->width, $screen->height);
    }

    /** The text layers the frame leaves on the screen's text grid, outside the field's viewport, by layer. */
    private function drawScreenText(GdImage $image, SceneFrameComposition $frame, RendererGridConfig $grid): void
    {
        if (! $frame->snapshot instanceof ConsolePresentationSnapshot) {
            return;
        }
        $members = array_flip($frame->viewport->textLayerIds ?? []);
        $layers = array_values(array_filter($frame->snapshot->textLayers,
            static fn(PresentationTextLayer $layer): bool => ! isset($members[$layer->id]) && $layer->runs !== []));
        if ($layers === []) {
            return;
        }
        $this->canvas->drawCanvas($image, new PresentationCanvas(imagesx($image), imagesy($image), textLayers: array_map(
            static fn(PresentationTextLayer $layer): CanvasTextLayer => new CanvasTextLayer($layer->id, $layer->layer, 0, 0, $grid, $layer->runs),
            $layers)), 'screen text');
    }

    private function collectDiagnostics(): void
    {
        foreach ([...$this->field->diagnostics, ...$this->canvas->diagnostics] as $message) {
            $this->diagnose($message);
        }
    }

    private function diagnose(string $message): void
    {
        if (! in_array($message, $this->diagnostics, true)) {
            $this->diagnostics[] = $message;
        }
    }
}
