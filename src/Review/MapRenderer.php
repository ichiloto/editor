<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use Ichiloto\Editor\Cutscenes\Preview\PreviewField;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\PlayerGraphicalSubject;
use Ichiloto\Engine\Field\PlayerPresentationConfig;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use InvalidArgumentException;
use RuntimeException;

/**
 * Renders a map of a workspace as the game draws it, both ways, from the map
 * as the workspace holds it (unsaved edits included): the game's own field
 * ({@see PreviewField}) loads it with its NPCs and world objects, at rest in a
 * new game's world state, with the player or an actor standing on a cell when
 * asked. The field's camera draws the Terminal picture through the Engine's
 * Console, and the Engine's frame composer composes the graphical field a
 * graphical renderer would be sent, for {@see FieldStillRaster} to draw.
 *
 * A large view is taken a block of the screen at a time, as the camera pans,
 * within the renderer grid's limits; every block is the same field.
 */
final class MapRenderer
{
    /** The most columns and rows one block of the field's screen holds. */
    private const int BLOCK_COLUMNS = 256;
    private const int BLOCK_ROWS = 128;

    public function __construct(private readonly ProjectWorkspace $workspace)
    {
    }

    /**
     * Renders one view.
     *
     * @throws InvalidArgumentException When the view names an unknown map, a region or subject off it, or an unknown actor.
     * @throws RuntimeException When the map cannot be loaded.
     */
    public function render(MapRenderView $view): MapRendering
    {
        $map = array_find($this->workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === $view->mapId)
            ?? throw new InvalidArgumentException(sprintf('There is no map %s.', $view->mapId));
        if (($issue = $map->getGridSourceIssue()) !== null) {
            throw new RuntimeException(sprintf('Map %s cannot be read: %s', $map->mapId, $issue));
        }
        $region = $this->resolveRegion($view, $map);
        $subject = $view->subject;
        if ($subject !== null && ($subject->x >= $map->getWidth() || $subject->y >= $map->getHeight())) {
            throw new InvalidArgumentException(sprintf('x %d, y %d is off map %s, which is %d by %d.',
                $subject->x, $subject->y, $map->mapId, $map->getWidth(), $map->getHeight()));
        }
        $diagnostics = [];
        $appearance = $subject === null ? null : $this->resolveAppearance($subject, $diagnostics);
        $field = PreviewField::openProjectMap($this->workspace->projectRoot, $map,
            $subject === null ? null : new Vector2($subject->x, $subject->y),
            min($region['width'], self::BLOCK_COLUMNS), min($region['height'], self::BLOCK_ROWS), static fn(): float => 0.0,
            $subject->heading ?? MovementHeading::NONE, $appearance);
        try {
            if ($field->mapFailure !== null) {
                throw new RuntimeException(sprintf('Map %s cannot be loaded: %s', $map->mapId, $field->mapFailure));
            }

            return $this->capture($field, $view, $region, $diagnostics);
        } finally {
            $field->dispose();
        }
    }

    /**
     * Takes the field a block at a time across the region: each block's
     * Terminal rows and graphical composition, the sprites placed on map cells.
     *
     * @param array{x: int, y: int, width: int, height: int} $region
     * @param list<string> $diagnostics
     */
    private function capture(PreviewField $field, MapRenderView $view, array $region, array $diagnostics): MapRendering
    {
        [$columns, $rows] = $field->getScreenSize();
        $cells = array_fill(0, $region['height'], array_fill(0, $region['width'], ' '));
        $world = null;
        $tileFrame = 0;
        /** @var array<string, PresentationSprite> $sprites */
        $sprites = [];
        for ($top = $region['y']; $top < $region['y'] + $region['height']; $top += $rows) {
            for ($left = $region['x']; $left < $region['x'] + $region['width']; $left += $columns) {
                $field->holdCameraAt($left, $top);
                $origin = $field->getCameraOrigin();
                $screen = TerminalPicture::readRows($field->frame());
                for ($y = $top; $y < min($top + $rows, $region['y'] + $region['height']); $y++) {
                    for ($x = $left; $x < min($left + $columns, $region['x'] + $region['width']); $x++) {
                        $cells[$y - $region['y']][$x - $region['x']] = $screen[$y - $origin['y']][$x - $origin['x']] ?? ' ';
                    }
                }
                $composition = $field->composeStill(FieldStillRaster::supports(...));
                $world ??= $composition->world;
                $tileFrame = $composition->viewport?->tileFrame ?? $tileFrame;
                // The composer places sprites on screen cells; the camera's origin puts them back on the map's.
                foreach ($composition->sprites as $sprite) {
                    $sprites[$sprite->id] ??= self::placeOnMap($sprite, $origin['x'], $origin['y']);
                }
            }
        }
        if (! $world instanceof PresentationWorld) {
            throw new RuntimeException(sprintf('Map %s has no graphical field to draw: it exceeds the retained world\'s limits.', $view->mapId));
        }
        foreach ($cells as &$row) {
            // A wide symbol cut by the region's right edge keeps its column; its other half is outside.
            if (end($row) === NormalizedRow::CONTINUATION) {
                $row[array_key_last($row)] = ' ';
            }
        }
        unset($row);

        return new MapRendering($view, $region, new TerminalPicture($region['width'], $region['height'], $cells),
            new FieldStill($world, PresentationSprite::orderedList(array_values($sprites)), $region, $tileFrame),
            [...$diagnostics, ...$field->getDiagnostics()]);
    }

    /** @return array{x: int, y: int, width: int, height: int} */
    private function resolveRegion(MapRenderView $view, ProjectMap $map): array
    {
        $region = $view->region ?? ['x' => 0, 'y' => 0, 'width' => $map->getWidth(), 'height' => $map->getHeight()];
        if ($region['x'] + $region['width'] > $map->getWidth() || $region['y'] + $region['height'] > $map->getHeight()) {
            throw new InvalidArgumentException(sprintf('The region x %d, y %d, %d by %d runs off map %s, which is %d by %d.',
                $region['x'], $region['y'], $region['width'], $region['height'], $map->mapId, $map->getWidth(), $map->getHeight()));
        }

        return $region;
    }

    /**
     * The graphical art the subject shows, as a new game gives the player
     * theirs: the project's fixed player art, or the first member of the
     * starting party when the leader's art shows the player; a named actor
     * shows their own.
     *
     * @param list<string> $diagnostics
     */
    private function resolveAppearance(MapRenderSubject $subject, array &$diagnostics): ?CharacterSheet
    {
        return ProjectDirectoryContext::run($this->workspace->projectRoot, function () use ($subject, &$diagnostics): ?CharacterSheet {
            $actorId = $subject->actorId;
            if ($actorId === null) {
                $presentation = PlayerPresentationConfig::load();
                if ($presentation->graphicalSubject !== PlayerGraphicalSubject::PARTY_LEADER) {
                    return $presentation->graphical;
                }
                $party = (array) ($this->workspace->getSystemField('startingParty') ?? []);
                $actorId = is_string($party[0] ?? null) ? $party[0] : null;
                if ($actorId === null) {
                    $diagnostics[] = 'The player shows the party leader\'s art, and the starting party is empty, so the graphical still shows no one there.';

                    return null;
                }
            }
            $store = $this->workspace->actorDatabase->createActorStore();
            $actor = $subject->actorId === null ? $store->requireStartingPartyActor($actorId) : $store->get($actorId)
                ?? throw new InvalidArgumentException(sprintf('There is no actor %s; name one by its id.', $actorId));
            $sheet = $actor->getGraphicalCharacterSheet();
            if ($sheet === null) {
                $diagnostics[] = sprintf('Actor %s has no graphical field art, so the graphical still shows no one there.', $actor->id);
            }

            return $sheet;
        });
    }

    private static function placeOnMap(PresentationSprite $sprite, int $originX, int $originY): PresentationSprite
    {
        return new PresentationSprite($sprite->id, $sprite->asset, $sprite->x + $originX, $sprite->y + $originY, $sprite->width,
            $sprite->height, $sprite->anchor, $sprite->layer, $sprite->sourceRect, lift: $sprite->lift,
            quarterTurns: $sprite->quarterTurns, pivot: $sprite->pivot);
    }
}
