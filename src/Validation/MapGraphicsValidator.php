<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Canvas\GlyphTilePlanner;
use Ichiloto\Editor\Canvas\PieceRole;
use Ichiloto\Editor\Canvas\TileGlyphBinding;
use Ichiloto\Editor\Field\WorldObjectAuthoring;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\Maps\TileLayerSource;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Tilesets\AutotileShape;
use Ichiloto\Engine\Rendering\Tilesets\TileCoverage;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Checks a map's graphics as the Engine reads them: its kind (the tileset it
 * names) and the tile layers in `graphics/`, unsaved resizes included.
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
    /** The sheets whose tiles are ground, judged for see-through edges. */
    private const array GROUND_SHEETS = [TilesetSheet::A1, TilesetSheet::A2, TilesetSheet::A3, TilesetSheet::A4, TilesetSheet::A5];

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

        // A map without a kind in a project that has tilesets shows its raw glyphs: the Engine's
        // completeness rule reports that ({@see MapCompletenessCheck}).
        if ($tilesetId === null && $sources === [] && ! is_dir($map->directory . '/' . MapGraphics::DIRECTORY)) {
            return [];
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
     * Warns for what a map's art still lacks, judged by the Editor's own
     * authoring knowledge of its tileset: tiles a tileset piece draws whose
     * glyph is no longer there, cells showing the missing-art placeholder the
     * author chose, and ground that can show the background through
     * see-through edges. The game plays the same, so these never fail
     * validation.
     *
     * What the player would see raw (a gameplay glyph with no graphical
     * presentation, an NPC or world object without field art) is the
     * Engine's rule, reported as errors by {@see MapCompletenessCheck}.
     * Graphics the Engine refuses are {@see validate()}'s to report; only the
     * readable tile layers count here.
     *
     * @return list<Issue>
     */
    public static function validateCoverage(ProjectMap $map): array
    {
        $coverage = self::readCoverage($map);
        if ($coverage === null) {
            return [];
        }
        [$tileset, $layers] = $coverage;

        return [...self::checkStalePieceTiles($map, $tileset, $layers), ...self::checkMissingArtTiles($map, $tileset, $layers),
            ...self::checkSeeThroughGround($map, $tileset, $layers)];
    }

    /**
     * The cells whose glyph still shows in the graphical field, by the
     * Engine's glyph fallback rule ({@see MapGraphics::getShownGlyphCells()}),
     * in row order. Null when the map has no kind, or its kind or tile layers
     * cannot be read; {@see validate()} reports why.
     *
     * @return list<array{x: int, y: int, glyph: string, layer: string}>|null
     */
    public static function listShownGlyphCells(ProjectMap $map): ?array
    {
        return self::readCoverage($map)[2] ?? null;
    }

    /**
     * The map's tileset, its readable tile layers and the cells whose glyph
     * still shows; null when they cannot be read.
     *
     * @return array{0: Tileset, 1: list<MapTileLayer>, 2: list<array{x: int, y: int, glyph: string, layer: string}>}|null
     */
    private static function readCoverage(ProjectMap $map): ?array
    {
        $graphics = self::readGraphics($map);
        if ($graphics === null) {
            return null;
        }

        // A world object that draws covers its glyph cells in the field, as the Engine's presentation applies it.
        $covered = WorldObjectAuthoring::readRestingCoverage($map)['glyphs'] ?? [];
        $shown = array_values(array_filter($graphics->getShownGlyphCells($map->getLayerSet(), $map->getAssetRoot()),
            static fn(array $cell): bool => ! isset($covered[$cell['layer']][$cell['y']][$cell['x']])));

        return [$graphics->tileset, $graphics->layers, $shown];
    }

    /**
     * The map's graphics as the Engine reads them, unsaved edits included:
     * its tileset, its readable tile layers and which gameplay layer each
     * belongs to. Null when the map has no kind, or its kind or layers cannot
     * be read; {@see validate()} reports why.
     */
    public static function readGraphics(ProjectMap $map): ?MapGraphics
    {
        $ignored = [];
        $tileset = is_string($tilesetId = $map->getMapDataField(['tileset'])) ? self::loadTileset($map, $tilesetId, $ignored) : null;
        if ($tileset === null) {
            return null;
        }
        $layers = self::readLayers($map, $map->getTileLayerSources(), $ignored);

        try {
            $owners = MapGraphics::resolveLayerOwners($map->getMapDataField([MapGraphics::SETTINGS_KEY]),
                array_map(static fn(MapTileLayer $layer): string => $layer->name, $layers),
                array_column(array_filter($map->getLayers(), static fn(array $layer): bool =>
                    $layer['id'] !== MapLayers::EVENT && ! $layer['decoration']), 'name'), $tileset, $map->mapId);
        } catch (Throwable) {
            // The terminal layers' and tile layer settings' own checks report why.
            return null;
        }

        return new MapGraphics($tileset, $layers, owners: $owners);
    }

    /**
     * Warns for tiles a tileset piece draws that no glyph on the map accounts
     * for, left behind when their glyph went without them (an edit outside the
     * editor, or before the tileset had the piece). The graphical field then
     * shows what the terminal no longer does. Tiles no piece draws, such as a
     * house's walls over blank cells, are not judged.
     *
     * @param list<MapTileLayer> $layers The readable tile layers.
     * @return list<Issue>
     */
    private static function checkStalePieceTiles(ProjectMap $map, Tileset $tileset, array $layers): array
    {
        $drawn = [];
        foreach ($tileset->pieces as $piece) {
            foreach (PieceRole::readPiece($piece) as $roles) {
                foreach ($roles as $role) {
                    foreach ($role->tiles as $tileLayer => $cells) {
                        foreach ($cells as $cell) {
                            $drawn[$tileLayer][(int) $cell['entry']] = true;
                        }
                    }
                }
            }
        }
        // A tile a glyph-less piece also draws, such as a stone wall cap, may stand on any cell.
        foreach (TileGlyphBinding::findFreeEntries($tileset->pieces) as $tileLayer => $entries) {
            foreach ($entries as $entry => $_) {
                unset($drawn[$tileLayer][(int) $entry]);
            }
        }

        // Every role a glyph on the map could play accounts for its tiles,
        // including those of a piece's blank cells beside it.
        $explained = [];
        $kept = [];
        $entries = [];
        foreach ($layers as $tileLayer) {
            $entries[$tileLayer->name] = $tileLayer->getEntries();
        }
        foreach ($map->getLayers() as $layer) {
            if ($layer['id'] === MapLayers::EVENT || $layer['decoration']) {
                continue;
            }
            $planner = GlyphTilePlanner::fromPieces($tileset->pieces, $layer['name']);
            for ($y = 0; $y < $map->getHeight(); $y++) {
                for ($x = 0; $x < $map->getWidth(); $x++) {
                    if (! $map->hasLayerCell($layer['id'], $x, $y) || ($glyph = $map->getLayerSymbol($layer['id'], $x, $y)) === ' ') {
                        continue;
                    }
                    foreach ($planner->getRoles($glyph) as $role) {
                        foreach ($role->tiles as $tileLayer => $cells) {
                            foreach ($cells as $cell) {
                                $explained[$tileLayer][$y + $cell['dy']][$x + $cell['dx']][(int) $cell['entry']] = true;
                            }
                        }
                        // A matching role explains any underlay it keeps, only at its owned cells.
                        if (array_filter($role->getOwnEntries(), static fn(string $entry, string $tileLayer): bool =>
                            ($entries[$tileLayer][$y][$x] ?? (string) TileId::EMPTY) !== $entry, ARRAY_FILTER_USE_BOTH) !== []) {
                            continue;
                        }
                        foreach ($role->keeps as $tileLayer => $cells) {
                            foreach ($cells as $cell) {
                                [$cellX, $cellY] = [$x + $cell['dx'], $y + $cell['dy']];
                                if ($map->hasLayerCell($layer['id'], $cellX, $cellY)) {
                                    $kept[$tileLayer][$cellY][$cellX] = true;
                                }
                            }
                        }
                    }
                }
            }
        }

        $issues = [];
        foreach ($layers as $layer) {
            $stale = [];
            foreach ($layer->tiles as $y => $row) {
                foreach ($row as $x => $id) {
                    if (isset($drawn[$layer->name][$id]) && ! isset($explained[$layer->name][$y][$x][$id])
                        && ! isset($kept[$layer->name][$y][$x])) {
                        $stale[] = "({$x}, {$y})";
                    }
                }
            }
            if ($stale !== []) {
                $named = array_slice($stale, 0, self::CELLS_NAMED);
                $rest = count($stale) - count($named);
                $issues[] = Issue::warning($map->mapId,
                    sprintf('%d %s on the %s tile layer %s drawn by a tileset piece whose glyph is no longer there: %s%s.',
                        count($stale), count($stale) === 1 ? 'tile' : 'tiles', $layer->name, count($stale) === 1 ? 'is' : 'are',
                        implode(', ', $named), $rest > 0 ? " and {$rest} more" : ''),
                    'Paint the piece\'s glyph back and erase it, so its tiles go with it, or remove the tiles in the GUI editor.');
            }
        }

        return $issues;
    }

    /**
     * Lists, by tile layer, the cells showing the tileset's missing-art
     * placeholder: covered, but with art still to make.
     *
     * @param list<MapTileLayer> $layers The readable tile layers.
     * @return list<Issue>
     */
    private static function checkMissingArtTiles(ProjectMap $map, Tileset $tileset, array $layers): array
    {
        if ($tileset->missingArt === null) {
            return [];
        }
        $issues = [];
        foreach ($layers as $layer) {
            $cells = [];
            foreach ($layer->tiles as $y => $row) {
                foreach ($row as $x => $id) {
                    if ($id === $tileset->missingArt) {
                        $cells[] = "({$x}, {$y})";
                    }
                }
            }
            if ($cells !== []) {
                $named = array_slice($cells, 0, self::CELLS_NAMED);
                $rest = count($cells) - count($named);
                $issues[] = Issue::warning($map->mapId,
                    sprintf('%d %s on the %s tile layer %s the missing-art placeholder: %s%s.',
                        count($cells), count($cells) === 1 ? 'cell' : 'cells', $layer->name, count($cells) === 1 ? 'shows' : 'show',
                        implode(', ', $named), $rest > 0 ? " and {$rest} more" : ''),
                    'Replace it with the art that belongs there once it is known.');
            }
        }

        return $issues;
    }

    /**
     * Warns for cells where ground tiles (the A1 to A5 sheets) leave the background showing: a tile with see-through
     * edges that blend into the ground beneath it, with no ground authored beneath it, shows the background through
     * those edges as a dark seam in the game. Every animation frame counts, by the Engine's own tile coverage. Upper
     * sheet props and world objects are see-through by design and are not judged.
     *
     * @param list<MapTileLayer> $layers The readable tile layers.
     * @return list<Issue>
     */
    private static function checkSeeThroughGround(ProjectMap $map, Tileset $tileset, array $layers): array
    {
        // Tiles from a sheet the Engine cannot use draw nothing, so their glyphs show; checkSheets() says why.
        $usable = array_keys($tileset->getUsableSheets($map->getAssetRoot())['sheets'] ?? []);
        if ($usable === []) {
            return [];
        }
        usort($layers, static fn(MapTileLayer $a, MapTileLayer $b): int => $a->order <=> $b->order);
        $grids = array_map(static fn(MapTileLayer $layer): array => AutotileShape::resolveLayer($layer->tiles), $layers);
        // One reader per pass, so replaced artwork is read as it is now.
        $coverage = new TileCoverage($tileset, $map->getAssetRoot());
        $verdicts = [];
        $cells = [];
        foreach ($grids[0] ?? [] as $y => $row) {
            foreach ($row as $x => $_) {
                $stack = [];
                foreach ($grids as $grid) {
                    $tile = (int) ($grid[$y][$x] ?? TileId::EMPTY);
                    $sheet = $tile === TileId::EMPTY ? null : TileId::getSheet($tile);
                    if (in_array($sheet, self::GROUND_SHEETS, true) && in_array($sheet->value, $usable, true)) {
                        $stack[] = $tile;
                    }
                }
                if ($stack === []) {
                    continue;
                }
                $signature = implode(',', $stack);
                if (! isset($verdicts[$signature])) {
                    $verdicts[$signature] = $coverage->findUncovered($stack);
                    if ($verdicts[$signature]['diagnostic'] !== null) {
                        // The artwork or GD cannot be read: say so once, never guess that the cells are covered.
                        return [Issue::warning($map->mapId, 'Its ground could not be checked for see-through edges: '
                            . $verdicts[$signature]['diagnostic'], 'Install PHP GD, or repair the sheet the tileset names.')];
                    }
                }
                if ($verdicts[$signature]['uncoveredPixels'] > 0) {
                    $cells[] = "({$x}, {$y})";
                }
            }
        }
        if ($cells === []) {
            return [];
        }
        $named = array_slice($cells, 0, self::CELLS_NAMED);
        $rest = count($cells) - count($named);

        return [Issue::warning($map->mapId,
            sprintf('%d %s the background through see-through ground tiles in some animation frame, a dark seam in the game: %s%s.',
                count($cells), count($cells) === 1 ? 'cell can show' : 'cells can show', implode(', ', $named), $rest > 0 ? " and {$rest} more" : ''),
            'Paint ground beneath them on a lower tile layer, such as the map\'s base or the floor they stand on; the game draws nothing under a tile.')];
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
