<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposition;
use InvalidArgumentException;
use LogicException;

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

    /**
     * The graphical field a frame shows: the frame's retained world, sprites,
     * field text and world canvases put back on map cells from the camera's
     * origin, for the rectangle of the map the camera's viewport takes.
     *
     * @param array{x: int, y: int} $origin The camera's origin: the map cell the screen's top-left shows.
     * @param array{x: int, y: int, width: int, height: int} $region The rectangle of the map the viewport shows.
     * @param array<string, string> $labelRasters Each canvas label's raster as PNG bytes, by layout id.
     * @throws LogicException When the frame has no retained world or no complete text snapshot.
     */
    public static function fromComposition(SceneFrameComposition $composition, array $origin, array $region,
        array $labelRasters = []): self
    {
        $world = $composition->world ?? throw new LogicException('A frame without a retained world has no graphical field to draw.');
        $block = ['x' => $region['x'], 'y' => $region['y'], 'right' => $region['x'] + $region['width'],
            'bottom' => $region['y'] + $region['height']];
        $text = [];
        foreach (self::findFieldTextLayers($composition) as $layer) {
            $text[] = new PresentationTextLayer($layer->id, $layer->layer, self::placeRunsOnMap($layer, $origin, $block));
        }

        return new self($world, PresentationSprite::orderedList(array_map(
            static fn(PresentationSprite $sprite): PresentationSprite => self::placeSpriteOnMap($sprite, $origin['x'], $origin['y']),
            $composition->sprites)), $region, $composition->viewport?->tileFrame ?? 0, $text,
            array_map(static fn(PresentationWorldCanvas $canvas): PresentationWorldCanvas => $canvas->createProjected(-$origin['x'], -$origin['y']),
                $composition->worldCanvases), $labelRasters);
    }

    /**
     * The frame's field text layers, as a graphical renderer draws them in
     * the field: the viewport's text members, less the map layers the
     * retained world already draws.
     *
     * @return list<PresentationTextLayer>
     */
    public static function findFieldTextLayers(SceneFrameComposition $composition): array
    {
        $snapshot = $composition->snapshot;
        if (! $snapshot instanceof ConsolePresentationSnapshot) {
            throw new LogicException('A still is composed from a complete text snapshot.');
        }
        $members = array_flip($composition->viewport->textLayerIds ?? []);
        $retained = array_flip($composition->world->textLayerIds ?? []);

        return array_values(array_filter($snapshot->textLayers,
            static fn(PresentationTextLayer $layer): bool => isset($members[$layer->id]) && ! isset($retained[$layer->id])));
    }

    /**
     * A text layer's runs on map cells, kept to the block of the map this
     * camera position takes, so blocks that overlap at the map's edges draw
     * each cell once. A run takes one cell per character, as a graphical
     * renderer lays it.
     *
     * @param array{x: int, y: int} $origin
     * @param array{x: int, y: int, right: int, bottom: int} $block
     * @return list<PresentationTextRun>
     */
    public static function placeRunsOnMap(PresentationTextLayer $layer, array $origin, array $block): array
    {
        $runs = [];
        foreach ($layer->runs as $run) {
            $row = $run->row + $origin['y'];
            $column = $run->column + $origin['x'];
            $first = max($column, $block['x']);
            $last = min($column + mb_strlen($run->text, 'UTF-8'), $block['right']);
            if ($row < $block['y'] || $row >= $block['bottom'] || $first >= $last) {
                continue;
            }
            $runs[] = new PresentationTextRun($row, $first, mb_substr($run->text, $first - $column, $last - $first, 'UTF-8'),
                $run->foreground, $run->background);
        }

        return $runs;
    }

    /** A sprite the composer placed on a screen cell, on the map cell the camera's origin puts it. */
    public static function placeSpriteOnMap(PresentationSprite $sprite, int $originX, int $originY): PresentationSprite
    {
        return new PresentationSprite($sprite->id, $sprite->asset, $sprite->x + $originX, $sprite->y + $originY, $sprite->width,
            $sprite->height, $sprite->anchor, $sprite->layer, $sprite->sourceRect, lift: $sprite->lift,
            quarterTurns: $sprite->quarterTurns, pivot: $sprite->pivot);
    }
}
