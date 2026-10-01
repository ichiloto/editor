<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Field\ProjectNpc;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\Maps\TileLayerSource;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Checks a map's graphics as the Engine reads them: its kind (the tileset it
 * names) and the tile layers in `graphics/`, unsaved resizes included. In a
 * project with tilesets every map should have a kind, so one without is
 * warned about.
 *
 * Unusable graphics never stop a map loading; the game shows its terminal
 * glyphs instead. Every file is checked, so one report names every problem
 * rather than only the first the Engine meets.
 *
 * {@see validateCoverage()} reports, apart, what a map with a kind still shows
 * as terminal glyphs in the graphical field.
 */
final class MapGraphicsValidator
{
    private const string GLYPH_FALLBACK = 'The game shows this map\'s terminal glyphs instead of its graphics.';
    /** How many cells a coverage warning names before summing up the rest. */
    private const int CELLS_NAMED = 3;

    private function __construct()
    {
    }

    /**
     * @return list<Issue> The issues found; empty for a map without graphics
     *     in a project without tilesets.
     */
    public static function validate(ProjectMap $map): array
    {
        $sources = $map->getTileLayerSources();
        $tilesetId = $map->getMapDataField(['tileset']);

        if ($tilesetId === null && $sources === [] && ! is_dir($map->directory . '/' . MapGraphics::DIRECTORY)) {
            return glob($map->getAssetRoot() . '/' . Tileset::DIRECTORY . '/*.php') === [] ? [] : [Issue::warning(
                $map->mapId,
                'It has no kind, so it has no tiles or pieces.',
                sprintf('Set its Kind in the Inspector, one of the tilesets in assets/%s. %s', Tileset::DIRECTORY, self::GLYPH_FALLBACK),
            )];
        }

        $issues = [];
        $tileset = self::loadTileset($map, $tilesetId, $issues);
        $layers = self::readLayers($map, $sources, $issues);
        self::checkLayerOffsets($map, $sources, $issues);

        if ($tileset !== null) {
            $issues = [
                ...$issues,
                ...self::checkSheets($map, $tileset),
                ...self::checkProvidedSheets($map, $tileset, $layers),
            ];
        }

        return $issues;
    }

    /**
     * Warns for what a map with a kind still shows as terminal glyphs in the
     * graphical field, by the Engine's glyph fallback rule: cells no tile
     * covers, NPCs without a field sprite, and copies of an NPC's glyph in the
     * map showing under its sprite. These are art still to do, not faults:
     * the game plays the same, so project validation reports them and the
     * pre-save checks do not. Graphics the Engine refuses are
     * {@see validate()}'s to report; only the readable tile layers count here.
     *
     * @return list<Issue>
     */
    public static function validateCoverage(ProjectMap $map): array
    {
        $ignored = [];
        $tileset = is_string($tilesetId = $map->getMapDataField(['tileset'])) ? self::loadTileset($map, $tilesetId, $ignored) : null;
        if ($tileset === null) {
            return [];
        }
        $layers = self::readLayers($map, $map->getTileLayerSources(), $ignored);

        try {
            $layerSet = $map->getLayerSet();
            $owners = MapGraphics::resolveLayerOwners($map->getMapDataField([MapGraphics::SETTINGS_KEY]),
                array_map(static fn(MapTileLayer $layer): string => $layer->name, $layers),
                array_column(array_filter($map->getLayers(), static fn(array $layer): bool =>
                    $layer['id'] !== MapLayers::EVENT && ! $layer['decoration']), 'name'), $tileset, $map->mapId);
        } catch (Throwable) {
            // The terminal layers' and tile layer settings' own checks report why.
            return [];
        }

        $shown = [];
        foreach (new MapGraphics($tileset, $layers, owners: $owners)->getShownGlyphCells($layerSet, $map->getAssetRoot()) as $cell) {
            $shown[$cell['y']][$cell['x']] = $cell;
        }

        $issues = [];
        foreach ($map->getNpcs()->all() as $npc) {
            $name = self::formatNpcName($npc);
            if (! array_key_exists('sprites2d', $npc->toArray())) {
                // An NPC without a terminal sprite draws nothing; its map glyph, if any, is the cell's.
                if ($npc->getVisibleSprite() !== '') {
                    $issues[] = Issue::warning($map->mapId,
                        sprintf('NPC %s has no field sprite, so its glyph %s shows in the graphical field.', $name, $npc->getVisibleSprite()),
                        'Give it an RPG Maker character sheet (sprites2d).');
                }

                continue;
            }

            // A map glyph copying the NPC's own glyph is drawn twice in the
            // terminal and shows under the sprite in the graphical field.
            $cell = $shown[$npc->getY()][$npc->getX()] ?? null;
            if ($cell !== null && $cell['glyph'] === $npc->getVisibleSprite()) {
                unset($shown[$npc->getY()][$npc->getX()]);
                $issues[] = Issue::warning($map->mapId,
                    sprintf('NPC %s stands on a copy of its glyph %s on the %s layer, which shows under its sprite.',
                        $name, $cell['glyph'], $cell['layer']),
                    'Remove the copy from the map if the NPC is always there; otherwise give the cell its tile.');
            }
        }

        $cells = array_merge(...array_values(array_map(array_values(...), $shown)) ?: [[]]);
        if ($cells === []) {
            return $issues;
        }

        if ($layers === []) {
            return [...$issues, Issue::warning($map->mapId,
                sprintf('It has no tiles yet, so all %d of its glyph cells show in the graphical field.', count($cells)),
                'Stamp tileset pieces with P, draw the tiles for glyphs already on a layer with T, or paint tiles in the GUI editor.')];
        }

        $hint = 'Draw them with T on that layer when a tileset piece draws the glyph, or paint them in the GUI editor.';

        $groups = [];
        foreach ($cells as $cell) {
            $groups[$cell['layer'] . "\0" . $cell['glyph']][] = $cell;
        }
        foreach ($groups as $group) {
            $named = array_map(static fn(array $cell): string => "({$cell['x']}, {$cell['y']})", array_slice($group, 0, self::CELLS_NAMED));
            $rest = count($group) - count($named);
            $issues[] = Issue::warning($map->mapId, sprintf('%d %s of %s on the %s layer %s no tile, so the glyph shows in the graphical field: %s%s.',
                count($group), count($group) === 1 ? 'cell' : 'cells', $group[0]['glyph'], $group[0]['layer'],
                count($group) === 1 ? 'has' : 'have', implode(', ', $named), $rest > 0 ? " and {$rest} more" : ''), $hint);
        }

        return $issues;
    }

    private static function formatNpcName(ProjectNpc $npc): string
    {
        $name = trim($npc->getName());
        $id = $npc->getId();

        return match (true) {
            $name === '' => $id ?? 'without a name',
            $id === null => $name,
            default => "{$name} ({$id})",
        };
    }

    /**
     * Checks the map data's tile layer settings as the Engine reads them.
     *
     * @param array<string, string> $sources
     * @param list<Issue> $issues
     */
    private static function checkLayerOffsets(ProjectMap $map, array $sources, array &$issues): void
    {
        $names = [];
        foreach (array_keys($sources) as $path) {
            if (preg_match(MapGraphics::FILENAME_PATTERN, basename($path), $matches) === 1) {
                $names[] = $matches['name'];
            }
        }

        try {
            MapGraphics::readLayersMovingWith($map->getMapDataField([MapGraphics::SETTINGS_KEY]), $names,
                array_column(array_filter($map->getLayers(), static fn(array $layer): bool =>
                    $layer['id'] !== MapLayers::EVENT && ! $layer['decoration']), 'name'), $map->mapId);
        } catch (InvalidArgumentException $error) {
            $issues[] = Issue::error(
                $map->mapId,
                $error->getMessage(),
                sprintf("Name a tile layer in graphics/ and give it 'offset' => [across, down] or 'movesWith' => a gameplay layer. %s", self::GLYPH_FALLBACK),
            );
        }
    }

    /** @param list<Issue> $issues */
    private static function loadTileset(ProjectMap $map, mixed $tilesetId, array &$issues): ?Tileset
    {
        if ($tilesetId === null) {
            $issues[] = Issue::error(
                $map->mapId,
                'It has graphics/ but no kind.',
                sprintf('Set its Kind in the Inspector, one of the tilesets in assets/%s, or remove graphics/. %s', Tileset::DIRECTORY, self::GLYPH_FALLBACK),
            );

            return null;
        }

        if (! is_string($tilesetId)) {
            $issues[] = Issue::error(
                $map->mapId,
                sprintf('Its tileset is %s, not a tileset id.', get_debug_type($tilesetId)),
                sprintf('Name the file of a tileset in assets/%s without .php. %s', Tileset::DIRECTORY, self::GLYPH_FALLBACK),
            );

            return null;
        }

        try {
            return Tileset::load($map->getAssetRoot(), $tilesetId);
        } catch (Throwable $error) {
            $issues[] = Issue::error(
                $map->mapId,
                $error->getMessage(),
                sprintf('Repair or add assets/%s/%s.php. %s', Tileset::DIRECTORY, $tilesetId, self::GLYPH_FALLBACK),
            );

            return null;
        }
    }

    /**
     * Reads every tile layer, reporting each one the Engine would refuse.
     *
     * @param array<string, string> $sources
     * @param list<Issue> $issues
     * @return list<MapTileLayer> The readable layers.
     */
    private static function readLayers(ProjectMap $map, array $sources, array &$issues): array
    {
        $hint = 'Repair the file by hand or in the GUI editor; the TUI does not repair tile layers. ' . self::GLYPH_FALLBACK;

        if (count($sources) > MapGraphics::MAX_LAYERS) {
            $issues[] = Issue::error($map->mapId, sprintf('It has more than %d tile layers.', MapGraphics::MAX_LAYERS), $hint);
        }

        try {
            $layerSet = $map->getLayerSet();
        } catch (Throwable) {
            // The terminal layers' own check reports why they disagree.
            $layerSet = null;
        }

        $layers = $orders = [];

        foreach ($sources as $path => $source) {
            $displayPath = $map->mapId . '/' . MapGraphics::DIRECTORY . '/' . basename($path);

            if (preg_match(MapGraphics::FILENAME_PATTERN, basename($path), $matches) !== 1) {
                $issues[] = Issue::error($map->mapId, "Tile layer {$displayPath} must be named NN.name.tiles.php.", $hint);

                continue;
            }

            if (isset($orders[$matches['order']])) {
                $issues[] = Issue::error($map->mapId, "Tile layer {$displayPath} repeats order {$matches['order']}.", $hint);
            }

            $orders[$matches['order']] = true;

            try {
                $layer = TileLayerSource::readLayer($source, $displayPath);

                if ($layerSet instanceof MapLayerSet) {
                    $layer->assertMatches($layerSet);
                }

                $layers[] = $layer;
            } catch (InvalidArgumentException $error) {
                $issues[] = Issue::error($map->mapId, $error->getMessage(), $hint);
            }
        }

        return $layers;
    }

    /**
     * Warns for each sheet the Engine cannot draw from.
     *
     * @return list<Issue>
     */
    private static function checkSheets(ProjectMap $map, Tileset $tileset): array
    {
        $assetRoot = $map->getAssetRoot();
        $usable = $tileset->getUsableSheets($assetRoot)['sheets'] ?? [];
        $issues = [];

        foreach (array_diff_key($tileset->sheets, $usable) as $sheet => $asset) {
            $layout = TilesetSheet::from((string) $sheet);

            try {
                $size = PngAssetPreflight::inspect($assetRoot, $asset);
                $problem = sprintf(
                    '%s is %dx%d, which does not fit an %s sheet of %d x %d tiles in one even tile size shared by every sheet (%dx%d at 48 pixels).',
                    $asset,
                    $size['width'],
                    $size['height'],
                    $sheet,
                    $layout->getColumns(),
                    $layout->getRows(),
                    $layout->getColumns() * 48,
                    $layout->getRows() * 48,
                );
            } catch (RuntimeException | InvalidArgumentException $error) {
                $problem = $error->getMessage();
            }

            $issues[] = Issue::warning(
                $map->mapId,
                sprintf('Tileset %s sheet %s is unusable: %s', $tileset->id, $sheet, $problem),
                'Tiles from this sheet show their terminal glyphs. Replace the image or correct its path.',
            );
        }

        return $issues;
    }

    /**
     * Warns for tile layers painting from sheets the tileset does not name.
     *
     * @param list<MapTileLayer> $layers
     * @return list<Issue>
     */
    private static function checkProvidedSheets(ProjectMap $map, Tileset $tileset, array $layers): array
    {
        $issues = [];

        foreach ($layers as $layer) {
            $missing = [];

            foreach ($layer->getUsedIds() as $id) {
                $sheet = TileId::getSheet($id)?->value;

                if ($sheet !== null && ! isset($tileset->sheets[$sheet])) {
                    $missing[$sheet] = $sheet;
                }
            }

            if ($missing !== []) {
                sort($missing);
                $issues[] = Issue::warning(
                    $map->mapId,
                    sprintf('Tile layer %s uses sheet%s %s, which tileset %s does not provide.',
                        $layer->path, count($missing) === 1 ? '' : 's', implode(', ', $missing), $tileset->id),
                    sprintf('Those cells show their terminal glyphs. Add the sheet to assets/%s/%s.php, or repaint the cells in the GUI editor.',
                        Tileset::DIRECTORY, $tileset->id),
                );
            }
        }

        return $issues;
    }
}
