<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Rendering\FieldViewport;
use InvalidArgumentException;

/** Explicit authoring variant/time selection, without writes or fabricated game state. */
final class WorldObjectPreview
{
    public static function describeWorld(ProjectMap $map, array $variants = [], float $seconds = 0): array
    {
        if (!is_finite($seconds) || $seconds < 0 || $seconds > 3600) { throw new InvalidArgumentException('Preview time requires finite seconds in 0..3600.'); }
        $issue = null;
        try { $graphics = $map->loadGraphics(); }
        catch (InvalidArgumentException|\RuntimeException $error) { $graphics = null; $issue = $error->getMessage(); }
        $entries = WorldObjectAuthoring::readEntries($map);
        $definitions = WorldObjectAuthoring::validateEntries($map, $entries);
        if (array_diff(array_keys($variants), array_column($definitions, 'id')) !== []) { throw new InvalidArgumentException('Preview selection names an unknown map-local object.'); }
        if (array_any($variants, static fn($id): bool => !is_string($id))) { throw new InvalidArgumentException('Preview variants must name known variant ids.'); }
        $coverage = $sprites = $diagnostics = [];
        foreach ($definitions as $object) {
            $selected = $variants[$object->id] ?? '';
            $definition = $object->sprites;
            if ($selected !== '') {
                $variant = array_find($object->variants, static fn($variant): bool => $variant['id'] === $selected)
                    ?? throw new InvalidArgumentException('Choose a known world-object variant to preview.');
                $definition = $variant['sprites'];
            }
            $role = $definition === null ? null : new FieldSpriteRole($definition, $map->getAssetRoot(), 'World-object authoring ' . $object->id);
            try {
                $role?->advance($seconds);
                $frame = $role?->getFrame($object->pivot);
                if ($definition === null || $frame !== null) {
                    $coverage = array_replace_recursive($coverage, $object->coverage);
                } else { $diagnostics[] = $object->id . ': the current selected role cannot be drawn; covered glyphs and tiles remain.'; }
                if ($frame !== null) {
                    $sprites[] = new PresentationSprite('world-object:' . $map->mapId . ':' . $object->id,
                        $frame->asset, $object->x, $object->y, $frame->width, $frame->height,
                        $frame->anchor, $frame->layer, $frame->sourceRect, lift: $frame->lift,
                        quarterTurns: $frame->quarterTurns, pivot: $frame->pivot);
                }
            } finally { $role?->release(); }
        }
        $world = PresentationWorld::getFromLayers($map->getLayerSet(), 'map', $graphics, $map->getAssetRoot(), $coverage);
        $operations = $world->getOperations(true, true);
        $ordered = PresentationSprite::orderedList($sprites);
        foreach ($ordered as $order => $sprite) {
            $operations[] = ['op' => 'put', 'kind' => 'sprite', 'id' => $sprite->id,
                'value' => [...$sprite->toArray(true, true, true), 'order' => $order]];
        }
        $columns = min(512, $map->getWidth());
        $rows = min(256, $map->getHeight());
        return ['map' => $map->mapId, 'assetRoot' => $map->getAssetRoot(),
            'grid' => ['columns' => $columns, 'rows' => $rows,
                'cellWidth' => FieldViewport::TILE_SIZE, 'cellHeight' => FieldViewport::TILE_SIZE],
            'update' => ['frame' => 1, 'baseGeneration' => 0, 'generation' => 1, 'reset' => true, 'present' => true,
                'operations' => $operations, 'viewport' => ['scale' => min($columns / $map->getWidth(), $rows / $map->getHeight()), 'origin' => ['x' => 0, 'y' => 0],
                    'clipRect' => ['x' => 0, 'y' => 0, 'width' => $columns * FieldViewport::TILE_SIZE,
                        'height' => $rows * FieldViewport::TILE_SIZE],
                    'textLayerIds' => [], 'spriteIds' => array_column($ordered, 'id'), 'worldId' => 'map',
                    'worldOrigin' => ['column' => 0, 'row' => 0]]],
            'graphicsIssue' => $issue, 'diagnostics' => $diagnostics];
    }
}
