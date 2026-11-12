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
use Ichiloto\Engine\Rendering\Launch\PackagedRendererExecutableResolver;
use Ichiloto\Engine\Rendering\Launch\RendererExecutableResolverInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasFrameBudget;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextLayer;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposition;
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
 * graphical renderer would be sent: the retained world, sprites, world
 * canvases and the field's text layers, for {@see FieldStillRaster} to draw.
 * A map with signs has their labels measured and drawn by the installed
 * graphical renderer ({@see StillLabelPreparation}), as the game has them.
 *
 * A large view is taken a block of the screen at a time, as the camera pans,
 * within the renderer grid's limits; every block is the same field.
 */
final class MapRenderer
{
    /** The most columns and rows one block of the field's screen holds. */
    private const int BLOCK_COLUMNS = 256;
    private const int BLOCK_ROWS = 128;

    /** How many times one camera block is composed while its labels are prepared, at most. */
    private const int LABEL_PASSES = 16;

    /**
     * @param RendererExecutableResolverInterface $renderers Where the installed graphical renderer that prepares
     *     labels is found.
     */
    public function __construct(
        private readonly ProjectWorkspace $workspace,
        private readonly RendererExecutableResolverInterface $renderers = new PackagedRendererExecutableResolver(),
    ) {
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
        $labels = null;
        try {
            if ($field->mapFailure !== null) {
                throw new RuntimeException(sprintf('Map %s cannot be loaded: %s', $map->mapId, $field->mapFailure));
            }
            $signs = $field->findSigns();
            $labelFailure = null;
            if ($signs !== []) {
                try {
                    $labels = StillLabelPreparation::start($this->workspace->projectRoot . '/assets', $this->renderers);
                } catch (RuntimeException $failure) {
                    $labelFailure = $failure->getMessage();
                }
            }

            return $this->capture($field, $view, $region, $diagnostics, $labels, $signs, $labelFailure);
        } finally {
            // The field releases its labels before the renderer that prepared them ends.
            try {
                $field->dispose();
            } finally {
                $labels?->dispose();
            }
        }
    }

    /**
     * Takes the field a block at a time across the region: each block's
     * Terminal rows and graphical composition, the sprites placed on map cells.
     * Each sign in the region that the still leaves out is named, with why.
     *
     * @param array{x: int, y: int, width: int, height: int} $region
     * @param list<string> $diagnostics
     * @param array<string, array{id: string, x: int, y: int}> $signs The field's signs, by graphical id.
     */
    private function capture(PreviewField $field, MapRenderView $view, array $region, array $diagnostics,
        ?StillLabelPreparation $labels, array $signs, ?string $labelFailure): MapRendering
    {
        [$columns, $rows] = $field->getScreenSize();
        $cells = array_fill(0, $region['height'], array_fill(0, $region['width'], ' '));
        $world = null;
        $tileFrame = 0;
        /** @var array<string, PresentationSprite> $sprites */
        $sprites = [];
        /** @var array<string, PresentationWorldCanvas> $canvases */
        $canvases = [];
        /** @var array<string, array{layer: int, runs: list<PresentationTextRun>}> $text Each field text layer, by id, in the order first seen. */
        $text = [];
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
                [$composition, $labelFailure] = self::composeBlock($field, $labels, $labelFailure);
                $world ??= $composition->world;
                $tileFrame = $composition->viewport?->tileFrame ?? $tileFrame;
                // The composer places sprites, canvases and text on screen cells; the camera's origin puts them back on the map's.
                foreach ($composition->sprites as $sprite) {
                    $sprites[$sprite->id] ??= FieldStill::placeSpriteOnMap($sprite, $origin['x'], $origin['y']);
                }
                foreach ($composition->worldCanvases as $canvas) {
                    $canvases[$canvas->id] ??= $canvas->createProjected(-$origin['x'], -$origin['y']);
                }
                $block = ['x' => $left, 'y' => $top, 'right' => min($left + $columns, $region['x'] + $region['width']),
                    'bottom' => min($top + $rows, $region['y'] + $region['height'])];
                foreach (FieldStill::findFieldTextLayers($composition) as $layer) {
                    $text[$layer->id] ??= ['layer' => $layer->layer, 'runs' => []];
                    array_push($text[$layer->id]['runs'], ...FieldStill::placeRunsOnMap($layer, $origin, $block));
                }
            }
        }
        $labelRasters = [];
        foreach ($canvases as $canvas) {
            // A world canvas and every canvas its groups draw.
            foreach (CanvasFrameBudget::getCanvases([$canvas->canvas]) as $drawn) {
                foreach ($drawn->labels as $label) {
                    $layoutId = $label->measurement->layoutId;
                    $labelRasters[$layoutId] ??= $labels?->readRaster($layoutId);
                }
            }
        }
        foreach ($signs as $spriteId => $sign) {
            if (! isset($canvases[$spriteId]) && $sign['x'] >= $region['x'] && $sign['x'] < $region['x'] + $region['width']
                && $sign['y'] >= $region['y'] && $sign['y'] < $region['y'] + $region['height']) {
                $diagnostics[] = sprintf('Sign %s at x %d, y %d is left out of the graphical still: %s', $sign['id'], $sign['x'],
                    $sign['y'], $labelFailure ?? 'the graphical renderer could not prepare its label.');
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
            new FieldStill($world, PresentationSprite::orderedList(array_values($sprites)), $region, $tileFrame,
                array_map(static fn(string $id, array $layer): PresentationTextLayer => new PresentationTextLayer($id, $layer['layer'],
                    $layer['runs']), array_keys($text), array_values($text)), array_values($canvases), array_filter($labelRasters)),
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

    /**
     * Composes the field at its camera, again while the labels it asks for
     * are prepared, until a composition asks for no more: the frame then
     * holds every label the renderer could prepare. A renderer that fails
     * leaves the labels out, and its failure is given back.
     *
     * @return array{SceneFrameComposition, ?string} The composition and why labels are left out, if they are.
     */
    private static function composeBlock(PreviewField $field, ?StillLabelPreparation $labels, ?string $labelFailure): array
    {
        if ($labels === null || $labelFailure !== null) {
            return [$field->composeStill(FieldStillRaster::supports(...)), $labelFailure];
        }
        $passes = 0;
        $asked = $labels->requestCount;
        try {
            $composition = $field->composeStill(FieldStillRaster::supports(...), $labels,
                static function () use ($labels, &$asked, &$passes, &$labelFailure): bool {
                    $labels->awaitReplies();
                    if ($labels->requestCount === $asked) {
                        return false;
                    }
                    $asked = $labels->requestCount;
                    if (++$passes < self::LABEL_PASSES) {
                        return true;
                    }
                    $labelFailure = 'its label was still being prepared after ' . self::LABEL_PASSES . ' compositions.';

                    return false;
                });
        } catch (RuntimeException $failure) {
            return [$field->composeStill(FieldStillRaster::supports(...)), $failure->getMessage()];
        }

        return [$composition, $labelFailure];
    }
}
