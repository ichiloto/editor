<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Canvas\TerrainKindPainter;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\CommandGroup;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Field\MapTerrainKinds;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Tilesets\TerrainKind;
use InvalidArgumentException;

/**
 * Converts a glyph-keyed map to its tileset's terrain kinds, explicitly and
 * per map: the author maps each old glyph of a kind layer to a kind, reviews
 * what changes, and the conversion opts the map into kinds
 * (`'terrainKinds' => true`) and paints every mapped cell with its kind, as
 * one source-preserving undo step. Nothing is reinterpreted silently: on a
 * map not yet painted in kinds, every cell whose glyph already names a kind
 * must be mapped too, even to that same kind.
 *
 * Each mapped cell is painted with its kind ({@see TerrainKindPainter}), so its
 * terrain becomes the kind's alone: tiles the old glyph left on the tile
 * layers its gameplay layer owns are cleared, except layers the kind keeps.
 * Glyphs nobody maps stay as they are, with the collision the dictionary gives them.
 *
 * Events, transfers and NPCs are not touched. Saves standing where the map
 * becomes solid are moved through the existing save relocation when the map
 * is saved, and validation checks reachability then, as for any map edit.
 */
final class TerrainKindConversion
{
    private function __construct()
    {
    }

    /**
     * What converting the map would do, changing nothing: each mapped glyph's
     * cells and kind, the glyphs left as they are, those among them that name
     * a kind and so must be mapped before converting (`reread`), and the
     * cells whose collision changes. With no mapping it lists what is there.
     *
     * @param array<string, string> $mapping Kind ids by old plain glyph.
     * @return array{map: string, declares: bool, mappings: list<array{glyph: string, kind: string, name: string, layer: string, cells: int, awaitingArt: bool}>,
     *     unmapped: list<array{glyph: string, layer: string, cells: int}>, reread: list<array{glyph: string, layer: string, cells: int}>, collision: array{changed: int, blocked: int, opened: int, cells: list<array{0: int, 1: int}>}}
     * @throws MapSourceRefusal When the mapping cannot be applied.
     */
    public static function preview(ProjectMap $map, array $mapping): array
    {
        $plan = self::plan($map, $mapping);

        return [
            'map' => $map->mapId,
            'declares' => ! $plan['declared'],
            'mappings' => $plan['mappings'],
            'unmapped' => $plan['unmapped'],
            'reread' => $plan['reread'],
            'collision' => self::compareCollision($map, $plan),
        ];
    }

    /**
     * Converts the map as one undo step.
     *
     * @param array<string, string> $mapping Kind ids by old plain glyph.
     * @return array{command: ?Command, cells: int} The applied, unrecorded edit, and the cells painted.
     * @throws MapSourceRefusal When the mapping is empty, leaves a glyph that names a kind unmapped, or cannot be applied; nothing changes.
     */
    public static function apply(ProjectMap $map, array $mapping, string $label = 'Convert to terrain kinds'): array
    {
        $plan = self::plan($map, $mapping);
        if ($mapping === []) {
            throw new MapSourceRefusal('Map at least one old glyph to a terrain kind. Nothing was changed.');
        }
        if ($plan['reread'] !== []) {
            throw new MapSourceRefusal(sprintf('%s already holds %s, which name terrain kinds; converting would reread them as kinds unseen. '
                . 'Map them too, to the kind they are or to another. Nothing was changed.', $map->mapId,
                implode(', ', array_map(static fn(array $glyph): string => sprintf("'%s' on %s (%d cells)", $glyph['glyph'], $glyph['layer'],
                    $glyph['cells']), $plan['reread']))));
        }
        $commands = [];
        if (! $plan['declared']) {
            $map->setMapDataField([MapTerrainKinds::DATA_KEY], true);
            $commands[] = new GenericCommand($label,
                static fn() => $map->setMapDataField([MapTerrainKinds::DATA_KEY], true),
                static fn() => $map->setMapDataField([MapTerrainKinds::DATA_KEY], null),
            );
        }
        try {
            $painted = 0;
            foreach ($plan['cells'] as $kindId => $cells) {
                $command = TerrainKindPainter::paintCells($map, $plan['kinds'][$kindId], $cells, $label);
                $painted += count($cells);
                if ($command !== null) {
                    $commands[] = $command;
                }
            }
        } catch (MapSourceRefusal $refusal) {
            foreach (array_reverse($commands) as $command) {
                $command->undo();
            }
            throw $refusal;
        }

        return ['command' => $commands === [] ? null : new CommandGroup($label, $commands), 'cells' => $painted];
    }

    /**
     * @param array<string, string> $mapping
     * @return array{declared: bool, kinds: array<string, TerrainKind>, terrain: MapTerrainKinds, cells: array<string, list<array{x: int, y: int}>>,
     *     mappings: list<array<string, mixed>>, unmapped: list<array<string, mixed>>, reread: list<array{glyph: string, layer: string, cells: int}>}
     */
    private static function plan(ProjectMap $map, array $mapping): array
    {
        if ($map->getGridSourceIssue() !== null) {
            throw new MapSourceRefusal(sprintf('%s is read-only: %s', $map->mapId, $map->getGridSourceIssue()));
        }
        $kinds = TerrainKindPainter::readTilesetKinds($map);
        $terrain = new MapTerrainKinds($map->loadTileset());
        try {
            $declared = MapTerrainKinds::isDeclared($map->getEditableData());
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal($error->getMessage(), previous: $error);
        }
        $layerIds = [];
        foreach ($map->getLayers() as $layer) {
            if ($layer['id'] !== MapLayers::EVENT && ! $layer['decoration']) {
                $layerIds[$layer['name']] = $layer['id'];
            }
        }
        $targets = [];
        foreach ($mapping as $glyph => $kindId) {
            $symbols = TerminalText::visibleSymbols((string) $glyph);
            if (count($symbols) !== 1 || TerminalText::stripAnsi($symbols[0]) !== (string) $glyph) {
                throw new MapSourceRefusal(sprintf("'%s' is not one plain glyph; map each old glyph on its own. Nothing was changed.", $glyph));
            }
            $kind = $kinds[$kindId] ?? throw new MapSourceRefusal(sprintf('%s is not one of the tileset\'s terrain kinds (%s). Nothing was changed.',
                $kindId, implode(', ', array_keys($kinds))));
            if (! isset($layerIds[$kind->layer])) {
                throw new MapSourceRefusal(sprintf('%s goes on the %s layer, which this map does not have. Nothing was changed.', $kind->name, $kind->layer));
            }
            $targets[(string) $glyph] = $kind;
        }
        $cells = $counts = $unmapped = $unmappedKinds = [];
        foreach ($terrain->getLayers() as $layerName) {
            if (! isset($layerIds[$layerName])) {
                continue;
            }
            $layerId = $layerIds[$layerName];
            for ($y = 0; $y < $map->getHeight(); $y++) {
                for ($x = 0; $x < $map->getWidth(); $x++) {
                    if (! $map->hasLayerCell($layerId, $x, $y)) {
                        continue;
                    }
                    $glyph = $map->getLayerSymbol($layerId, $x, $y);
                    $kind = $targets[$glyph] ?? null;
                    if ($kind !== null && $kind->layer === $layerName) {
                        $cells[$kind->id][] = ['x' => $x, 'y' => $y];
                        $counts[$glyph] = ($counts[$glyph] ?? 0) + 1;
                    } else {
                        $unmapped[$layerName][$glyph] = ($unmapped[$layerName][$glyph] ?? 0) + 1;
                        if (! $declared && $terrain->getKindsOn($layerName, $glyph) !== []) {
                            $unmappedKinds[$glyph] = $layerName;
                        }
                    }
                }
            }
        }
        $reread = [];
        foreach ($unmappedKinds as $glyph => $layerName) {
            $reread[] = ['glyph' => (string) $glyph, 'layer' => $layerName, 'cells' => $unmapped[$layerName][$glyph]];
        }
        $mappings = [];
        foreach ($targets as $glyph => $kind) {
            $mappings[] = ['glyph' => (string) $glyph, 'kind' => $kind->id, 'name' => $kind->name, 'layer' => $kind->layer,
                'cells' => $counts[$glyph] ?? 0, 'awaitingArt' => $kind->awaitingArt];
        }
        $left = [];
        foreach ($unmapped as $layerName => $glyphs) {
            foreach ($glyphs as $glyph => $count) {
                $left[] = ['glyph' => (string) $glyph, 'layer' => (string) $layerName, 'cells' => $count];
            }
        }

        return ['declared' => $declared, 'kinds' => $kinds, 'terrain' => $terrain, 'cells' => $cells, 'mappings' => $mappings, 'unmapped' => $left,
            'reread' => $reread];
    }

    /**
     * The cells whose collision the conversion changes: on a map without
     * declared occupancy, its glyphs read with the kinds after conversion; on
     * one with it, the kinds' collision where their layer is on top.
     *
     * @param array{declared: bool, kinds: array<string, TerrainKind>, terrain: MapTerrainKinds, cells: array<string, list<array{x: int, y: int}>>} $plan
     * @return array{changed: int, blocked: int, opened: int, cells: list<array{0: int, 1: int}>}
     */
    private static function compareCollision(ProjectMap $map, array $plan): array
    {
        try {
            $before = $map->getResolvedCollisionMap();
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal('The map\'s collision cannot be read: ' . $error->getMessage(), previous: $error);
        }
        $painted = [];
        foreach ($plan['cells'] as $kindId => $cells) {
            foreach ($cells as ['x' => $x, 'y' => $y]) {
                $painted[$y][$x] = $plan['kinds'][$kindId];
            }
        }
        $set = $map->getLayerSet();
        if ($map->hasMapDataField([MapPhysicalOccupancy::DATA_KEY])) {
            $after = $before;
            foreach ($painted as $y => $row) {
                foreach ($row as $x => $kind) {
                    if (TerrainKindPainter::isTopLayer($set, $kind->layer, $x, $y)) {
                        $after[$y][$x] = $kind->collision->value;
                    }
                }
            }
        } else {
            $layers = array_map(static function (MapLayer $layer) use ($painted): MapLayer {
                $rows = [];
                foreach ($layer->grid as $y => $row) {
                    $cells = [];
                    foreach ($row as $x => $cell) {
                        $kind = $painted[$y][$x] ?? null;
                        $cells[] = $kind !== null && $kind->layer === $layer->name ? $kind->piece->grid[0][0] : $cell;
                    }
                    $rows[] = implode('', $cells);
                }
                return new MapLayer($layer->name, $layer->order, $layer->decoration, $layer->path, implode("\n", $rows));
            }, $set->layers);
            try {
                $after = $map->resolveTrialCollision(new MapLayerSet($layers, $set->legacy),
                    [...$map->getEditableData(), MapTerrainKinds::DATA_KEY => true], $plan['terrain']);
            } catch (InvalidArgumentException $error) {
                throw new MapSourceRefusal('The map\'s collision after conversion cannot be resolved: ' . $error->getMessage()
                    . ' Nothing was changed.', previous: $error);
            }
        }
        $changed = [];
        $blocked = $opened = 0;
        foreach ($before as $y => $row) {
            foreach ($row as $x => $value) {
                $next = $after[$y][$x] ?? $value;
                if ($next === $value) {
                    continue;
                }
                $changed[] = [$x, $y];
                $wasBlocking = CollisionType::from($value)->blocksMovement();
                $isBlocking = CollisionType::from($next)->blocksMovement();
                $blocked += ! $wasBlocking && $isBlocking ? 1 : 0;
                $opened += $wasBlocking && ! $isBlocking ? 1 : 0;
            }
        }

        return ['changed' => count($changed), 'blocked' => $blocked, 'opened' => $opened, 'cells' => $changed];
    }
}
