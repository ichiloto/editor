<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Where a cinematic's preview or playtest starts, the same for every
 * editor: the map event that triggers it when one exists, otherwise its
 * start map (or the map the author has open) at its first open tile.
 */
final class CinematicPreviewOrigin
{
    /**
     * @param ProjectMap|null $openMap The map the author has open, when the cinematic names no start map of its own.
     * @return array{mapId: string|null, x: int, y: int, marker: string|null}
     */
    public static function locate(ProjectWorkspace $workspace, CutsceneAsset $asset, ?string $startMap, ?ProjectMap $openMap = null): array
    {
        foreach ($workspace->maps as $map) {
            foreach ($map->getEventDefinitions() as $marker => $definition) {
                if (! is_array($definition) || ! str_contains(strval($definition['class'] ?? ''), 'CinematicEventTrigger')) {
                    continue;
                }

                $data = is_array($definition['data'] ?? null) ? $definition['data'] : [];

                if (trim(strval($data['cinematicId'] ?? '')) !== $asset->id) {
                    continue;
                }

                // Where the runtime places the marker: its first cell.
                $first = $map->getEventArea((string) $marker)?->firstCell;

                if ($first !== null) {
                    return ['mapId' => $map->mapId, 'x' => (int) $first->x, 'y' => (int) $first->y, 'marker' => (string) $marker];
                }
            }
        }

        $map = null;

        if ($startMap !== null) {
            foreach ($workspace->maps as $candidate) {
                if ($candidate->mapId === $startMap) {
                    $map = $candidate;
                }
            }
        }

        $map ??= $openMap;

        if (! $map instanceof ProjectMap) {
            return ['mapId' => $startMap, 'x' => 1, 'y' => 1, 'marker' => null];
        }

        [$x, $y] = self::findFirstOpenTile($map);

        return ['mapId' => $map->mapId, 'x' => $x, 'y' => $y, 'marker' => null];
    }

    /**
     * The first tile that is not a wall-like glyph, row by row.
     *
     * @return array{0: int, 1: int}
     */
    private static function findFirstOpenTile(ProjectMap $map): array
    {
        for ($y = 0; $y < $map->getHeight(); $y++) {
            for ($x = 0; $x < $map->getWidth(); $x++) {
                if ($map->getTileSymbol($x, $y) === ' ') {
                    return [$x, $y];
                }
            }
        }

        return [0, 0];
    }
}
