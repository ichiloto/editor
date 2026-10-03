<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Closure;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\History\PaintStrokeCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use InvalidArgumentException;
use RuntimeException;

/**
 * Edits a map's canvas the one way every interface does: glyphs written onto
 * a layer, and the tiles that keep that layer's pieces drawn where its glyphs
 * are (see {@see GlyphTilePlanner}), as one undo step.
 *
 * An edit is two calls. {@see plan()} works out the tiles and reports a glyph
 * that could be several pieces, which the caller asks the author about and
 * plans again with the answer. {@see apply()} writes the glyphs and tiles and
 * returns the command that undoes and redoes them, applied and unrecorded, for
 * the caller's history. Neither call knows a cursor, a focused pane, a status
 * line or a dialog; refusals are returned or thrown, never shown.
 */
final class CanvasEditor
{
    /**
     * Works out the tiles an edit's glyphs need, or why it cannot go ahead.
     *
     * @param array<int, array{x: int, y: int, symbol: string}> $writes The glyphs the edit writes.
     * @param array<string, ?string> $choices The role key chosen for a glyph, or null for no tiles.
     * @param bool $repaint Whether a glyph painted over itself may take another role.
     * @param list<string> $excludedLayers Tile layers the edit sets itself.
     * @return array{tiles: array<string, list<array{x: int, y: int, entry: string}>>, unresolved: array<string, list<PieceRole>>}|null
     *     The tiles and the glyphs still waiting on a choice, or null when the layer has no pieces to follow.
     * @throws MapSourceRefusal When a tile layer the pieces draw on cannot be read.
     */
    public static function plan(ProjectMap $map, string $layerId, array $writes, array $choices = [], bool $repaint = false,
        array $excludedLayers = []): ?array
    {
        $planner = self::loadGlyphTilePlanner($map, $layerId, $excludedLayers);
        if ($planner === null) {
            return null;
        }
        $changes = [];
        foreach ($writes as $write) {
            if ($map->hasLayerCell($layerId, $write['x'], $write['y'])) {
                $changes[] = ['x' => $write['x'], 'y' => $write['y'],
                    'old' => $map->getLayerSymbol($layerId, $write['x'], $write['y']), 'new' => $write['symbol']];
            }
        }

        return $planner->plan(
            $changes,
            static fn(int $x, int $y): ?string => $map->hasLayerCell($layerId, $x, $y) ? $map->getLayerSymbol($layerId, $x, $y) : null,
            self::createTileReader($map),
            $choices,
            $repaint,
        );
    }

    /**
     * Writes glyphs onto a layer and tiles onto the tile layers, and returns
     * the command that undoes and redoes both, already applied. The command
     * is null when nothing changed.
     *
     * @param array<int, array{x: int, y: int, symbol: string, color?: string|null, style?: array{prefix: string, suffix: string}}> $writes
     * @param array<string, list<array{x: int, y: int, entry: string}>> $tiles Tile cells keyed by tile layer name.
     * @return array{command: ?Command, changed: int} The command and the number of glyph cells that changed.
     * @throws MapSourceRefusal When the tile layers cannot take the tiles; nothing is written then.
     */
    public static function apply(ProjectMap $map, string $layerId, array $writes, string $label, array $tiles = []): array
    {
        if ($tiles === []) {
            [$stroke, $changed] = self::writeCells($map, $layerId, $writes, $label);

            return ['command' => $stroke->hasChanges() ? $stroke : null, 'changed' => $changed];
        }
        $tilesBefore = $map->getTileLayerSources();
        $map->writeTileCells($tiles);
        $tilesAfter = $map->getTileLayerSources();
        [$stroke, $changed] = self::writeCells($map, $layerId, $writes, $label);
        $command = $stroke->hasChanges() || $tilesAfter !== $tilesBefore
            ? self::combineStrokeWithTiles($label, $map, $stroke, $tilesBefore, $tilesAfter)
            : null;

        return ['command' => $command, 'changed' => $changed];
    }

    /**
     * Writes cells onto one layer and returns the stroke that undoes them,
     * unrecorded, so a caller can record it alone or as part of a larger
     * step. Cells outside the layer are skipped.
     *
     * @param array<int, array{x: int, y: int, symbol: string, color?: string|null, style?: array{prefix: string, suffix: string}}> $writes The cells to write.
     * @return array{0: PaintStrokeCommand, 1: int} The stroke and the number of cells that actually changed.
     */
    public static function writeCells(ProjectMap $map, string $layer, array $writes, string $label): array
    {
        $stroke = new PaintStrokeCommand($map, $layer, $label);
        $changed = 0;

        foreach ($writes as $write) {
            if (! $map->hasLayerCell($layer, $write['x'], $write['y'])) {
                continue;
            }

            $oldSymbol = $map->getLayerSymbol($layer, $write['x'], $write['y']);
            $oldStyle = $map->getLayerCellStyle($layer, $write['x'], $write['y']);
            [$newPrefix, $newSuffix] = isset($write['style'])
                ? [$write['style']['prefix'], $write['style']['suffix']]
                : self::resolvePaintStyle($write['symbol'], $write['color'] ?? null, $oldStyle);
            $map->setLayerCell($layer, $write['x'], $write['y'], $write['symbol'], $newPrefix, $newSuffix);
            $newSymbol = $map->getLayerSymbol($layer, $write['x'], $write['y']);
            $stroke->appendCell($write['x'], $write['y'], $oldSymbol, $newSymbol, $oldStyle['prefix'], $oldStyle['suffix'],
                $newPrefix, $newSuffix);

            if ($oldSymbol !== $newSymbol || $oldStyle['prefix'] !== $newPrefix || $oldStyle['suffix'] !== $newSuffix) {
                $changed++;
            }
        }

        return [$stroke, $changed];
    }

    /**
     * Resolves the styling bytes a paint writes.
     *
     * The colour directive follows the brush contract: null keeps the
     * cell's existing styling byte-for-byte, an empty string paints without
     * colour, and any other value becomes an `fg=` tag. A space is always
     * uncoloured, so erasing never leaves invisible styling behind.
     *
     * @param array{prefix: string, suffix: string} $oldStyle The cell's current styling.
     * @return array{0: string, 1: string} The prefix and suffix to write.
     */
    public static function resolvePaintStyle(string $symbol, ?string $colorDirective, array $oldStyle): array
    {
        if ($symbol === ' ') {
            return ['', ''];
        }

        if ($colorDirective === null) {
            return [$oldStyle['prefix'], $oldStyle['suffix']];
        }

        if ($colorDirective === '') {
            return ['', ''];
        }

        return [sprintf('<fg=%s>', $colorDirective), '</>'];
    }

    /**
     * One undo step for a glyph stroke and the tile layer change made with it,
     * such as a stamped piece or a pasted block.
     *
     * @param array<string, string> $tilesBefore Tile layer sources before the change.
     * @param array<string, string> $tilesAfter Tile layer sources after it.
     */
    public static function combineStrokeWithTiles(string $label, ProjectMap $map, PaintStrokeCommand $stroke,
        array $tilesBefore, array $tilesAfter): Command
    {
        return new GenericCommand($label,
            static function () use ($stroke, $map, $tilesAfter): void {
                $stroke->execute();
                $map->restoreTileLayerSources($tilesAfter);
            },
            static function () use ($stroke, $map, $tilesBefore): void {
                $stroke->undo();
                $map->restoreTileLayerSources($tilesBefore);
            },
        );
    }

    /** The planner for a gameplay layer's pieces, or null when it has none to follow. */
    public static function loadGlyphTilePlanner(ProjectMap $map, string $layerId, array $excludedLayers = []): ?GlyphTilePlanner
    {
        $layer = array_find($map->getLayers(), static fn(array $candidate): bool => $candidate['id'] === $layerId);
        if ($layer === null || $layer['id'] === MapLayers::EVENT || $layer['decoration']) {
            return null;
        }
        try {
            $pieces = $map->loadTileset()?->pieces ?? [];
        } catch (InvalidArgumentException | RuntimeException) {
            return null;
        }

        return $pieces === [] ? null : GlyphTilePlanner::fromPieces($pieces, $layer['name'], $excludedLayers);
    }

    /**
     * Reads tile entries cell by cell, each layer once: `0` where a layer
     * has no tile, or the map has no such layer.
     *
     * @return Closure(string, int, int): string
     */
    public static function createTileReader(ProjectMap $map): Closure
    {
        $layers = [];

        return static function (string $layer, int $x, int $y) use ($map, &$layers): string {
            $layers[$layer] ??= $map->readTileEntries([$layer], 0, 0, $map->getWidth(), $map->getHeight())[$layer] ?? [];

            return $layers[$layer][$y][$x] ?? (string) TileId::EMPTY;
        };
    }
}
