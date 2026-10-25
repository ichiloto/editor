<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use InvalidArgumentException;

/**
 * The graphical field at one moment, as the game's frame composer hands it
 * to a renderer: the map's retained world, the sprites standing on it, and
 * the animation frame its tiles show, for a rectangle of the map. Sprites are
 * placed on map cells, not screen cells.
 */
final readonly class FieldStill
{
    /**
     * @param list<PresentationSprite> $sprites In paint order ({@see PresentationSprite::orderedList()}), on map cells.
     * @param array{x: int, y: int, width: int, height: int} $region The rectangle of the map it shows, in cells.
     */
    public function __construct(
        public PresentationWorld $world,
        public array $sprites,
        public array $region,
        public int $tileFrame = 0,
    ) {
        if ($tileFrame < 0) {
            throw new InvalidArgumentException('A still shows a tile frame of 0 or more.');
        }
    }
}
