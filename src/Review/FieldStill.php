<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas;
use InvalidArgumentException;

/**
 * The graphical field at one moment, as the game's frame composer hands it
 * to a renderer: the map's retained world, the sprites standing on it, the
 * world canvases of compound subjects such as a bed and its occupant or a
 * sign, the field's text layers (the terminal layers of subjects shown by
 * their glyph), the rasters the graphical renderer made of the canvases'
 * labels, and the animation frame its tiles show, for a rectangle of the map.
 * Sprites, canvases and text are placed on map cells, not screen cells.
 */
final readonly class FieldStill
{
    /** @var list<PresentationWorldCanvas> */
    public array $worldCanvases;

    /**
     * @param list<PresentationSprite> $sprites In paint order ({@see PresentationSprite::orderedList()}), on map cells.
     * @param array{x: int, y: int, width: int, height: int} $region The rectangle of the map it shows, in cells.
     * @param list<PresentationTextLayer> $textLayers The field's text layers in the order the frame lists them, on map cells.
     * @param list<PresentationWorldCanvas> $worldCanvases On map cells.
     * @param array<string, string> $labelRasters Each canvas label's raster as PNG bytes, guard included, by layout id
     *     ({@see StillLabelPreparation}).
     */
    public function __construct(
        public PresentationWorld $world,
        public array $sprites,
        public array $region,
        public int $tileFrame = 0,
        public array $textLayers = [],
        array $worldCanvases = [],
        public array $labelRasters = [],
    ) {
        if ($tileFrame < 0) {
            throw new InvalidArgumentException('A still shows a tile frame of 0 or more.');
        }
        if (! array_is_list($textLayers) || array_any($textLayers, static fn(mixed $layer): bool => ! $layer instanceof PresentationTextLayer)) {
            throw new InvalidArgumentException('A still\'s text layers are a list of field text layers.');
        }
        $this->worldCanvases = PresentationWorldCanvas::getOrderedList($worldCanvases);
    }
}
