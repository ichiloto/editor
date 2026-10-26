<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\Field\MapAtlas;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapAtlasDefinition;
use Ichiloto\Engine\Field\MapAtlasTerrain;
use Ichiloto\Engine\Field\MapTerrainKinds;
use InvalidArgumentException;
use Throwable;

/**
 * The inspector rows that classify a map's own cells for its atlas, the same in the Terminal and a graphical
 * editor: under a Terrain heading, each source layer in the order the map composes them, its other non-blank
 * glyphs' role, and one row per glyph the layer actually holds (blank ground included), each a role the Engine
 * knows or unclassified. Classifying a glyph is all it takes to bind a layer; the map's grids are never copied.
 */
final class MapTerrainFields
{
    public const string TARGET = 'map-terrain';

    private function __construct() {}

    /** @return list<array<string, mixed>> */
    public static function describeMapFields(ProjectMap $map): array
    {
        try {
            $block = MapAtlas::readBlock($map);
            $layers = $map->getLayerSet()->layers;
        } catch (Throwable $error) {
            return [['label' => 'Terrain', 'value' => '', 'editable' => false],
                ['label' => '  ! Read-only', 'value' => $error->getMessage(), 'editable' => false]];
        }
        $terrain = MapAtlas::readTerrain($block);
        $fields = [['label' => 'Terrain', 'value' => '', 'editable' => false]];
        $problem = self::findProblem($map, $block);
        if ($problem !== null) {
            $fields[] = ['label' => '  ! Problem', 'value' => $problem, 'editable' => false];
        }
        $roles = [['label' => '(unclassified)', 'value' => '', 'description' => 'The atlas draws nothing for it.'],
            ...array_map(static fn(string $role): array => ['label' => ucfirst($role), 'value' => $role, 'description' => ''], MapAtlasTerrain::ROLES)];
        $known = [];
        foreach ($layers as $layer) {
            $known[$layer->name] = true;
            $binding = is_array($terrain[$layer->name] ?? null) ? $terrain[$layer->name] : [];
            $symbols = is_array($binding['symbols'] ?? null) ? $binding['symbols'] : [];
            $fields[] = ['label' => sprintf('  Layer %s', $layer->name), 'value' => $layer->decoration ? 'decoration' : 'gameplay', 'editable' => false];
            $fields[] = ['label' => '    Other Glyphs', 'value' => is_string($binding['default'] ?? null) ? $binding['default'] : '',
                'editable' => true, 'choices' => $roles, 'options' => array_column($roles, 'value'),
                'target' => self::TARGET, 'field' => 'default', 'path' => [$layer->name]];
            $counts = [];
            for ($y = 0; $y < $layer->height; $y++) {
                foreach ($layer->getRowGlyphs($y) as $glyph) { $counts[$glyph] = ($counts[$glyph] ?? 0) + 1; }
            }
            // A glyph classified here that the layer no longer holds stays listed, so it can be cleared.
            foreach (array_keys($symbols) as $glyph) { $counts[(string) $glyph] ??= 0; }
            foreach ($counts as $glyph => $count) {
                $glyph = (string) $glyph;
                $fields[] = ['label' => sprintf('    %s · %s', $glyph === ' ' ? '(blank)' : "'" . $glyph . "'",
                        $count === 0 ? 'not in this layer' : sprintf('%d %s', $count, $count === 1 ? 'cell' : 'cells')),
                    'value' => is_string($symbols[$glyph] ?? null) ? $symbols[$glyph] : '', 'editable' => true,
                    'choices' => $roles, 'options' => array_column($roles, 'value'),
                    'target' => self::TARGET, 'field' => 'symbol', 'path' => [$layer->name, $glyph]];
            }
        }
        // A layer classified here that the map no longer has stays listed, so it can be cleared.
        foreach (array_keys($terrain) as $name) {
            if (!isset($known[(string) $name])) {
                $fields[] = ['label' => sprintf('  Layer %s · not in this map', $name), 'value' => '', 'editable' => true,
                    'choices' => [['label' => 'Remove its classifications', 'value' => '', 'description' => '']], 'options' => [''],
                    'target' => self::TARGET, 'field' => 'layer', 'path' => [(string) $name]];
            }
        }
        return $fields;
    }

    /**
     * The atlas block an edit to one of these rows leaves, or null when it changes nothing.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>|null
     */
    public static function applyValue(ProjectMap $map, array $field, mixed $value): ?array
    {
        $block = MapAtlas::readBlock($map);
        $path = array_values(array_map('strval', (array) ($field['path'] ?? [])));
        $role = (string) $value === '' ? null : (string) $value;
        if ($role !== null && !in_array($role, MapAtlasTerrain::ROLES, true)) {
            throw new InvalidArgumentException('Choose a listed terrain role.');
        }
        $next = match ((string) ($field['field'] ?? '')) {
            'symbol' => MapAtlas::setTerrainSymbol($block, $path[0] ?? '', $path[1] ?? '', $role),
            'default' => MapAtlas::setTerrainDefault($block, $path[0] ?? '', $role),
            'layer' => MapAtlas::setTerrainDefault(array_reduce(array_keys(MapAtlas::readTerrain($block)[$path[0] ?? '']['symbols'] ?? []),
                static fn(array $carry, int|string $glyph): array => MapAtlas::setTerrainSymbol($carry, $path[0] ?? '', (string) $glyph, null), $block),
                $path[0] ?? '', null),
            default => $block,
        };
        return $next === $block ? null : $next;
    }

    /**
     * Why the Engine would refuse the atlas's terrain beside the map's source layers as they stand, or null.
     *
     * @param array<string, mixed> $block
     */
    public static function findProblem(ProjectMap $map, array $block): ?string
    {
        try {
            $data = ($block === [] ? [] : [MapAtlas::KEY => $block])
                + array_intersect_key($map->getEditableData(), [MapTerrainKinds::DATA_KEY => true, 'tileset' => true]);
            MapAtlasDefinition::readMap($data);
            MapAtlasTerrain::readMap($data, $map->getLayerSet(), $map->loadTerrainKinds());
            return null;
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        }
    }
}
