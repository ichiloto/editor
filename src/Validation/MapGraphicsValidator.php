<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

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
 * Checks a map's graphics as the Engine reads them: its tileset and the tile
 * layers in `graphics/`, unsaved resizes included.
 *
 * Unusable graphics never stop a map loading; the game shows its terminal
 * glyphs instead. Every file is checked, so one report names every problem
 * rather than only the first the Engine meets.
 */
final class MapGraphicsValidator
{
    private const string GLYPH_FALLBACK = 'The game shows this map\'s terminal glyphs instead of its graphics.';

    private function __construct()
    {
    }

    /**
     * @return list<Issue> The issues found; empty for a map without graphics.
     */
    public static function validate(ProjectMap $map): array
    {
        $sources = $map->getTileLayerSources();
        $tilesetId = $map->getMapDataField(['tileset']);

        if ($tilesetId === null && $sources === [] && ! is_dir($map->directory . '/' . MapGraphics::DIRECTORY)) {
            return [];
        }

        $issues = [];
        $tileset = self::loadTileset($map, $tilesetId, $issues);
        $layers = self::readLayers($map, $sources, $issues);

        if ($tileset !== null) {
            $issues = [
                ...$issues,
                ...self::checkSheets($map, $tileset),
                ...self::checkProvidedSheets($map, $tileset, $layers),
            ];
        }

        return $issues;
    }

    /** @param list<Issue> $issues */
    private static function loadTileset(ProjectMap $map, mixed $tilesetId, array &$issues): ?Tileset
    {
        if ($tilesetId === null) {
            $issues[] = Issue::error(
                $map->mapId,
                'It has graphics/ but names no tileset.',
                sprintf("Name one in the map data ('tileset' => '<id>', from assets/%s/<id>.php), or remove graphics/. %s", Tileset::DIRECTORY, self::GLYPH_FALLBACK),
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
        $hint = 'Repair the file by hand or in the GUI editor; the TUI never paints tiles. ' . self::GLYPH_FALLBACK;

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
