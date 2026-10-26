<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\FramePresentationSourceInterface;
use Ichiloto\Engine\Rendering\Presentation\FrameViewportProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas;
use Ichiloto\Engine\Rendering\Presentation\RetainedWorldProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\WorldCanvasProviderInterface;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Rendering\Text\TextPreparationProviderInterface;
use InvalidArgumentException;

/**
 * The editing canvas's frame source: a map as it stands in memory, unsaved
 * edits included, as the field draws it. It supplies the map's world, its
 * world objects' sprites at the chosen variant and preview time, and its
 * signs' boards and labels, to the Engine's own frame composer, so the canvas
 * shares one composition path with the game. It is not a running scene: it
 * owns no game, clock or input, and the whole map is in view.
 */
final class AuthoringMapSource implements FramePresentationSourceInterface, RetainedWorldProviderInterface, WorldCanvasProviderInterface,
    FrameViewportProviderInterface
{
    /** The world identity the canvas draws, as the GUI reads it. */
    public const string WORLD_ID = 'map';

    /**
     * @param list<PresentationSprite> $sprites
     * @param list<PresentationWorldCanvas> $groups
     * @param list<string> $assetPaths Every file the picture depends on, for the canvas to watch.
     * @param list<string> $diagnostics
     */
    private function __construct(
        private readonly PresentationWorld $world,
        private readonly array $sprites,
        private readonly array $groups,
        private readonly int $columns,
        private readonly int $rows,
        private readonly float $scale,
        public readonly array $assetPaths,
        public readonly array $diagnostics,
        public readonly ?string $graphicsIssue,
    ) {
    }

    /**
     * Composes a map at a variant selection and preview time. Signs are laid out through the given provider, the
     * canvas's own renderer client; a sign whose label is not prepared yet shows its glyphs until it is. The previous
     * picture's world is kept while the map composes to the same one: the renderer retains a world by identity, so
     * an unchanged map sends nothing and the window's exchanges settle.
     *
     * @param array<string, string> $variants The variant shown for each object, by id; none is the object's own.
     */
    public static function compose(ProjectMap $map, array $variants, float $seconds, AuthoringSignPlaybacks $signs,
        ?TextPreparationProviderInterface $provider, int $columns, int $rows, ?self $previous = null): self
    {
        if (! is_finite($seconds) || $seconds < 0 || $seconds > 3600) {
            throw new InvalidArgumentException('Preview time requires finite seconds in 0..3600.');
        }
        $issue = null;
        try {
            $graphics = $map->loadGraphics();
        } catch (InvalidArgumentException|\RuntimeException $error) {
            $graphics = null;
            $issue = $error->getMessage();
        }
        $entries = WorldObjectAuthoring::readEntries($map);
        $definitions = WorldObjectAuthoring::validateEntries($map, $entries);
        if (array_diff(array_keys($variants), array_column($definitions, 'id')) !== []) {
            throw new InvalidArgumentException('Preview selection names an unknown map-local object.');
        }
        if (array_any($variants, static fn($id): bool => ! is_string($id))) {
            throw new InvalidArgumentException('Preview variants must name known variant ids.');
        }
        $coverage = $sprites = $groups = $diagnostics = $placed = [];
        $assetPaths = array_values($graphics?->tileset->sheets ?? []);
        $entriesById = array_column($entries, null, 'id');
        foreach ($definitions as $object) {
            $id = 'world-object:' . $map->mapId . ':' . $object->id;
            if ($object->resource?->sign !== null) {
                // A sign covers its cells only once its board and label are drawn, as the field's does.
                $placed[] = $object->id;
                $assetPaths[] = $object->resource->sign->asset;
                $presentation = $signs->getPlayback($object, $entriesById[$object->id] ?? [])->getPresentation($provider);
                if ($presentation !== null) {
                    $groups[] = new PresentationWorldCanvas($id, $object->x, $object->y, $presentation[1], $presentation[0],
                        $object->resource->sign->layer);
                    $coverage = array_replace_recursive($coverage, $object->coverage);
                }
                continue;
            }
            $selected = $variants[$object->id] ?? '';
            $definition = $object->sprites;
            if ($selected !== '') {
                $variant = array_find($object->variants, static fn($variant): bool => $variant['id'] === $selected)
                    ?? throw new InvalidArgumentException('Choose a known world-object variant to preview.');
                $definition = $variant['sprites'];
            }
            $role = $definition === null ? null : new FieldSpriteRole($definition, $map->getAssetRoot(), 'World-object authoring ' . $object->id);
            if ($definition !== null) {
                $assetPaths[] = $definition instanceof FieldPoseAnimation ? $definition->image->asset : $definition->asset;
            }
            try {
                $role?->advance($seconds);
                $frame = $role?->getFrame($object->pivot);
                if ($definition === null || $frame !== null) {
                    $coverage = array_replace_recursive($coverage, $object->coverage);
                } else {
                    $diagnostics[] = $object->id . ': the current selected role cannot be drawn; covered glyphs and tiles remain.';
                }
                if ($frame !== null) {
                    $sprites[] = new PresentationSprite($id, $frame->asset, $object->x, $object->y, $frame->width, $frame->height,
                        $frame->anchor, $frame->layer, $frame->sourceRect, lift: $frame->lift, quarterTurns: $frame->quarterTurns,
                        pivot: $frame->pivot);
                }
            } finally {
                $role?->release();
            }
        }
        $signs->retain($placed);
        $world = PresentationWorld::getFromLayers($map->getLayerSet(), self::WORLD_ID, $graphics, $map->getAssetRoot(), $coverage);
        if ($previous !== null && $previous->world == $world) {
            $world = $previous->world;
        }

        return new self($world, PresentationSprite::orderedList($sprites), $groups, $columns, $rows,
            min($columns / $map->getWidth(), $rows / $map->getHeight()), array_values(array_unique($assetPaths)), $diagnostics, $issue);
    }

    public function getPresentationWorld(): ?PresentationWorld
    {
        return $this->world;
    }

    public function getPresentationSprites(): array
    {
        return $this->sprites;
    }

    public function getPresentationWorldCanvases(): array
    {
        return $this->groups;
    }

    /** The whole map in view at its own origin; the canvas pans and zooms over it without moving the camera. */
    public function getPresentationViewport(ConsolePresentationSnapshot|ConsolePresentationChanges $snapshot, array $sprites, array $tiles = []): ?PresentationViewport
    {
        return new PresentationViewport($this->scale, 0, 0,
            new CanvasRectangle(0, 0, $this->columns * FieldViewport::TILE_SIZE, $this->rows * FieldViewport::TILE_SIZE),
            spriteIds: array_column($sprites, 'id'), worldId: self::WORLD_ID);
    }
}
