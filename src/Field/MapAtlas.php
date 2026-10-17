<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Closure;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapAtlasDefinition;
use Ichiloto\Engine\Field\MapCategoryCatalog;
use InvalidArgumentException;

/**
 * A map's place in the atlas, as authored under `atlas` (Engine MapAtlasDefinition): whether it is a global map,
 * the actual overworld geography, or a local one, and for a local map the landmark on its parent map that is its
 * entrance there (a house in a town, the town on the world). No coordinates are copied: the parent names a stable
 * landmark, and following parents reaches the global map. The Engine checks every value, chain, cycle and depth;
 * this model only reads and changes the block and finds what names a map or landmark.
 */
final class MapAtlas
{
    public const string KEY = MapAtlasDefinition::DATA_KEY;
    /** The scope a map has when it says nothing: the Engine's first scope, local. */
    public const string LOCAL = 'local';
    public const string GLOBAL = 'global';

    private function __construct() {}

    /**
     * The map's atlas block as authored, empty when it declares none.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException When the block is not an array.
     */
    public static function readBlock(ProjectMap $map): array
    {
        $block = $map->getMapDataField([self::KEY]) ?? [];
        return is_array($block) ? $block : throw new InvalidArgumentException('atlas must be an array; it cannot be edited as rows.');
    }

    /**
     * Sets the scope. Local is what a map is when it says nothing, so choosing it removes the key; a global map has
     * no parent, so becoming global removes the parent.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public static function setScope(array $block, string $scope): array
    {
        if (!in_array($scope, MapAtlasDefinition::SCOPES, true)) {
            throw new InvalidArgumentException('Choose a listed atlas scope.');
        }
        if ($scope === self::LOCAL) { unset($block['scope']); return $block; }
        unset($block['parent']);
        return [...$block, 'scope' => $scope];
    }

    /**
     * Sets or clears the parent entry: a stable landmark on the parent map.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public static function setParent(array $block, ?string $mapId, ?string $landmarkId): array
    {
        if ($mapId === null || $landmarkId === null) { unset($block['parent']); return $block; }
        return [...$block, 'parent' => ['map' => $mapId, 'landmark' => $landmarkId]];
    }

    /** A parent choice's value: the map id and the landmark id, which neither may contain. */
    public static function encodeParent(string $mapId, string $landmarkId): string
    {
        return $mapId . '#' . $landmarkId;
    }

    /** @return array{0: string, 1: string}|null */
    public static function decodeParent(string $value): ?array
    {
        if ($value === '') { return null; }
        $parts = explode('#', $value, 2);
        return count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' ? $parts
            : throw new InvalidArgumentException('Choose a listed parent entry.');
    }

    /**
     * Checks a block through the Engine as the map would stand with it, and returns where the chain reaches the
     * global map: its id and the entry on it, null entry for a global map itself, or null when no global map is
     * above.
     *
     * @param array<string, mixed> $block
     * @param Closure(string): ?ProjectMap $findMap Finds a project map by id, unsaved edits included.
     * @return array{map: string, landmark: ?string}|null
     * @throws InvalidArgumentException With the Engine's reason when the atlas is invalid.
     */
    public static function resolveGlobal(ProjectMap $map, array $block, Closure $findMap, ?MapCategoryCatalog $categories = null): ?array
    {
        $resolve = static function (string $mapId) use ($map, $block, $findMap): array {
            $other = $mapId === $map->mapId ? $map : ($findMap($mapId) ?? throw new InvalidArgumentException("Atlas parent map '{$mapId}' does not exist."));
            $source = MapLandmarks::readResolvedSource($other);
            if ($other === $map) {
                $source['data'][self::KEY] = $block;
                if ($block === []) { unset($source['data'][self::KEY]); }
            }
            return $source;
        };
        MapAtlasDefinition::readMap($block === [] ? [] : [self::KEY => $block]);
        return MapAtlasDefinition::resolveGlobal($map->mapId, $resolve, $categories);
    }

    /**
     * Where maps name a map as their atlas parent, or a landmark on it as their entry: what keeps that landmark from
     * being removed, and what a move or delete of the map leaves behind.
     *
     * @param iterable<ProjectMap> $maps
     * @return list<string>
     */
    public static function describeParentReferences(iterable $maps, string $mapId, ?string $landmarkId = null): array
    {
        $found = [];
        foreach ($maps as $map) {
            $parent = $map->getMapDataField([self::KEY, 'parent']);
            if (!is_array($parent) || ($parent['map'] ?? null) !== $mapId) { continue; }
            if ($landmarkId !== null && ($parent['landmark'] ?? null) !== $landmarkId) { continue; }
            $found[] = sprintf('the atlas parent of %s (entry %s)', $map->mapId, is_string($parent['landmark'] ?? null) ? $parent['landmark'] : '?');
        }
        return $found;
    }
}
