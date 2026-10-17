<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Closure;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/**
 * Places a map tileset's pieces the one way every interface does. An item
 * piece, such as a tree cluster, is stamped as whole copies side by side and
 * row under row across the area between two corners, as RPG Maker repeats a
 * multi-tile selection. A connected piece, such as a wall, is drawn along the
 * outline of that area (a straight line when the corners share a row or a
 * column) and every cell it touches is reshaped ({@see ConnectedPieceShaper}).
 *
 * Glyphs go on the gameplay layer the piece names, each cell in the style its
 * tileset authored for it, kept byte for byte, or in the fallback colour when
 * it has none; tiles go on the tile layers it names. A placement is one undo
 * step: the command is returned applied and unrecorded, for the caller's
 * history. Like {@see CanvasEditor}, nothing here knows a cursor, a status
 * line or a dialog; refusals are thrown, never shown, and an area that cannot
 * be placed whole changes nothing.
 */
final class PiecePlacer
{
    /**
     * The pieces of the map's tileset, by id in authored order.
     *
     * @return array<string, TilesetPiece>
     * @throws MapSourceRefusal When the map has no tileset, it cannot load, or it has no pieces.
     */
    public static function loadPieces(ProjectMap $map): array
    {
        try {
            $tileset = $map->loadTileset();
        } catch (\Throwable $error) {
            throw new MapSourceRefusal('Pieces are unavailable: ' . $error->getMessage(), previous: $error);
        }
        if ($tileset === null) {
            throw new MapSourceRefusal('This map has no kind yet, so it has no pieces. Set its Kind in the Inspector.');
        }
        if ($tileset->pieces === []) {
            throw new MapSourceRefusal(sprintf('Tileset %s has no pieces. Add them to assets/%s/%s.php.', $tileset->id, Tileset::DIRECTORY, $tileset->id));
        }

        return $tileset->pieces;
    }

    /**
     * The id of the gameplay layer the piece's glyphs go on.
     *
     * @throws MapSourceRefusal When the map has no gameplay layer with that name.
     */
    public static function findLayer(ProjectMap $map, TilesetPiece $piece): string
    {
        foreach ($map->getLayers() as $layer) {
            if ($layer['id'] !== MapLayers::EVENT && ! $layer['decoration'] && $layer['name'] === $piece->layer) {
                return $layer['id'];
            }
        }
        throw new MapSourceRefusal(sprintf('%s goes on the %s layer, which this map does not have. Create that gameplay layer first. Nothing was changed.',
            $piece->name, $piece->layer));
    }

    /**
     * The top-left cells an item piece is stamped at across the area from one
     * corner to the other: whole copies only, so an area smaller than the
     * piece holds one copy, at its top-left.
     *
     * @param array{x: int, y: int} $from
     * @param array{x: int, y: int} $to
     * @return list<array{x: int, y: int}>
     */
    public static function getAreaOrigins(TilesetPiece $piece, array $from, array $to): array
    {
        $span = static function (int $from, int $to, int $size): array {
            [$low, $high] = [min($from, $to), max($from, $to)];
            $starts = [$low];
            for ($start = $low + $size; $start + $size - 1 <= $high; $start += $size) {
                $starts[] = $start;
            }
            return $starts;
        };
        $origins = [];
        foreach ($span($from['y'], $to['y'], $piece->height) as $y) {
            foreach ($span($from['x'], $to['x'], $piece->width) as $x) {
                $origins[] = ['x' => $x, 'y' => $y];
            }
        }

        return $origins;
    }

    /**
     * The cells a connected piece is drawn on between two corners: the
     * outline of the rectangle they make, a straight line when they share a
     * row or a column, one cell when they are the same.
     *
     * @param array{x: int, y: int} $from
     * @param array{x: int, y: int} $to
     * @return list<array{x: int, y: int}>
     */
    public static function getConnectedDrawCells(array $from, array $to): array
    {
        return array_values(ToolGeometry::rectangleOutline($from['x'], $from['y'], $to['x'], $to['y']));
    }

    /**
     * What placing the piece between two corners would write, for a preview:
     * each cell's glyph and the colour it shows in, null where a space leaves
     * the map's cell as it is. A connected piece previews each drawn cell with
     * the glyph of the shape it would take beside what is already there.
     *
     * @param array{x: int, y: int} $from
     * @param array{x: int, y: int} $to
     * @param string|null $fallbackColor The colour of cells the piece leaves unstyled.
     * @return array<int, array<int, array{symbol: string, color: ?string}|null>> Cells by row and column.
     */
    public static function getPreviewCells(ProjectMap $map, TilesetPiece $piece, array $from, array $to, ?string $fallbackColor = null): array
    {
        $cells = [];
        if ($piece->connects !== null) {
            $drawn = self::getConnectedDrawCells($from, $to);
            try {
                $isMemberCell = self::resolveMemberLookup($map, self::findLayer($map, $piece), $piece);
            } catch (MapSourceRefusal) {
                $isMemberCell = static fn(int $x, int $y): bool => false;
            }
            $sources = $piece->getSourceShapeGrid();
            foreach (array_slice(ConnectedPieceShaper::reshapeCells($piece, $drawn, [], $isMemberCell), 0, count($drawn)) as $cell) {
                $shape = (string) $cell['shape'];
                $cells[$cell['y']][$cell['x']] = ['symbol' => $piece->shapes[$shape], 'color' => self::readCellColor($sources[$shape]) ?? $fallbackColor];
            }

            return $cells;
        }
        $sources = $piece->getSourceGrid();
        foreach (self::getAreaOrigins($piece, $from, $to) as ['x' => $x, 'y' => $y]) {
            foreach ($piece->glyphs as $row => $symbols) {
                foreach ($symbols as $column => $symbol) {
                    $cells[$y + $row][$x + $column] = $symbol === ' '
                        ? null
                        : ['symbol' => $symbol, 'color' => self::readCellColor($sources[$row][$column]) ?? $fallbackColor];
                }
            }
        }

        return $cells;
    }

    /**
     * A picture of what the piece draws, for a picker: an item piece's own
     * cells, or a small room of a connected piece's shapes. Each cell holds
     * its glyph and authored colour; a space shows nothing.
     *
     * @return list<list<array{symbol: string, color: ?string}>> Cells by row.
     */
    public static function buildPicture(TilesetPiece $piece): array
    {
        if ($piece->connects !== null) {
            $sources = $piece->getSourceShapeGrid();
            $shape = static fn(string $name): array => ['symbol' => $piece->shapes[$name], 'color' => self::readCellColor($sources[$name])];
            $blank = ['symbol' => ' ', 'color' => null];

            return [
                [$shape('corner'), $shape('horizontal'), $shape('corner')],
                [$shape('vertical'), $blank, $shape('vertical')],
                [$shape('corner'), $shape('horizontal'), $shape('corner')],
            ];
        }
        $sources = $piece->getSourceGrid();

        return array_map(static fn(array $symbols, int $row): array => array_map(
            static fn(string $symbol, int $column): array => ['symbol' => $symbol, 'color' => self::readCellColor($sources[$row][$column])],
            $symbols, array_keys($symbols),
        ), $piece->glyphs, array_keys($piece->glyphs));
    }

    /**
     * Stamps an item piece across the area between two corners as one undo
     * step. Space glyphs and `0` tiles leave their cells as they are, and a
     * glyph the stamp covers takes its own tiles with it, as any glyph edit
     * does ({@see GlyphTilePlanner}). A piece without glyphs, such as a rug,
     * stands for nothing and is laid as authored.
     *
     * @param array{x: int, y: int} $from
     * @param array{x: int, y: int} $to
     * @param string|null $fallbackColor The colour of cells the piece leaves unstyled; null keeps the map cell's own.
     * @return array{command: ?Command, origins: list<array{x: int, y: int}>}
     * @throws MapSourceRefusal When the piece is connected, its layer is missing or a copy falls off the map.
     */
    public static function stampArea(ProjectMap $map, TilesetPiece $piece, array $from, array $to, ?string $fallbackColor,
        string $label = 'Piece stamp'): array
    {
        if ($piece->connects !== null) {
            throw new MapSourceRefusal(sprintf('%s is drawn as connected cells, not stamped. Nothing was changed.', $piece->name));
        }
        $origins = self::getAreaOrigins($piece, $from, $to);
        $sources = $piece->getSourceGrid();
        $writes = $assigned = [];
        foreach ($origins as ['x' => $x, 'y' => $y]) {
            foreach ($piece->glyphs as $row => $symbols) {
                foreach ($symbols as $column => $symbol) {
                    if ($symbol !== ' ') {
                        $writes[] = ['x' => $x + $column, 'y' => $y + $row, 'symbol' => $symbol]
                            + self::resolveCellPaint($sources[$row][$column], $fallbackColor);
                    }
                }
            }
            // Each glyph cell plays its own cell of this piece, never a lookalike.
            foreach (PieceRole::readPiece($piece) as $roles) {
                foreach ($roles as $role) {
                    $assigned[($x + $role->cell[1]) . ',' . ($y + $role->cell[0])] = $role->key;
                }
            }
        }
        $layer = self::findLayer($map, $piece);
        foreach ($origins as ['x' => $x, 'y' => $y]) {
            foreach ($piece->glyphs as $row => $symbols) {
                foreach (array_keys($symbols) as $column) {
                    if (! $map->hasLayerCell($layer, $x + $column, $y + $row)) {
                        throw new MapSourceRefusal(sprintf('%s (%d x %d) does not fit at (%d, %d): the map has no cell at (%d, %d). Nothing was changed.',
                            $piece->name, $piece->width, $piece->height, $x, $y, $x + $column, $y + $row));
                    }
                }
            }
        }
        $tilesBefore = $map->getTileLayerSources();
        if ($writes === []) {
            foreach ($origins as ['x' => $x, 'y' => $y]) {
                $map->writeTileEntries($piece->tiles, $x, $y);
            }
        } else {
            $plan = CanvasEditor::plan($map, $layer, $writes, assigned: $assigned);
            $map->writeTileCells($plan['tiles'] ?? []);
            $writes = $plan['writes'] ?? $writes;
        }
        $tilesAfter = $map->getTileLayerSources();
        [$stroke] = CanvasEditor::writeCells($map, $layer, $writes, $label);

        return [
            'command' => $stroke->hasChanges() || $tilesAfter !== $tilesBefore
                ? CanvasEditor::combineStrokeWithTiles($label, $map, $stroke, $tilesBefore, $tilesAfter)
                : null,
            'origins' => $origins,
        ];
    }

    /**
     * Draws and erases a connected piece's cells as one undo step: writes
     * them, then gives every drawn cell and every member beside a drawn or
     * erased cell the glyph and tiles of its shape. Drawn cells take the
     * fallback colour unless their shape has its own; reshaped cells keep
     * theirs; tiles become `0` where a cell was erased.
     *
     * @param list<array{x: int, y: int}> $drawn
     * @param list<array{x: int, y: int}> $erased
     * @return ?Command The edit, or null when it changed nothing.
     * @throws MapSourceRefusal When the piece is not connected, its layer is missing or a cell is off the map.
     */
    public static function drawConnected(ProjectMap $map, TilesetPiece $piece, array $drawn, array $erased, ?string $fallbackColor,
        string $label = 'Piece draw'): ?Command
    {
        if ($piece->connects === null) {
            throw new MapSourceRefusal(sprintf('%s is stamped whole, not drawn as connected cells. Nothing was changed.', $piece->name));
        }
        $layer = self::findLayer($map, $piece);
        foreach ([...$drawn, ...$erased] as $cell) {
            if (! $map->hasLayerCell($layer, $cell['x'], $cell['y'])) {
                throw new MapSourceRefusal(sprintf('%s cannot reach (%d, %d): the map has no cell there. Nothing was changed.',
                    $piece->name, $cell['x'], $cell['y']));
            }
        }
        $cells = ConnectedPieceShaper::reshapeCells($piece, $drawn, $erased, self::resolveMemberLookup($map, $layer, $piece));
        $drawnKeys = array_flip(array_map(static fn(array $cell): string => "{$cell['x']},{$cell['y']}", $drawn));
        $sources = $piece->getSourceShapeGrid();
        $writes = array_map(static fn(array $cell): array => [
            'x' => $cell['x'],
            'y' => $cell['y'],
            'symbol' => $cell['shape'] === null ? ' ' : $piece->shapes[$cell['shape']],
        ] + ($cell['shape'] === null ? ['color' => null] : self::resolveCellPaint($sources[$cell['shape']],
            isset($drawnKeys["{$cell['x']},{$cell['y']}"]) ? $fallbackColor : null)), $cells);
        // Each shape draws its tiles, and a glyph the wall covers takes its own ({@see GlyphTilePlanner}).
        $tilesBefore = $map->getTileLayerSources();
        $plan = CanvasEditor::plan($map, $layer, $writes);
        $map->writeTileCells($plan['tiles'] ?? []);
        $tilesAfter = $map->getTileLayerSources();
        [$stroke] = CanvasEditor::writeCells($map, $layer, $plan['writes'] ?? $writes, $label);

        return $stroke->hasChanges() || $tilesAfter !== $tilesBefore
            ? CanvasEditor::combineStrokeWithTiles($label, $map, $stroke, $tilesBefore, $tilesAfter)
            : null;
    }

    /**
     * Whether a cell of the layer belongs to the connected piece now: it is on
     * the map and holds one of the piece's glyphs.
     *
     * @return Closure(int, int): bool
     */
    public static function resolveMemberLookup(ProjectMap $map, string $layer, TilesetPiece $piece): Closure
    {
        return static fn(int $x, int $y): bool => $map->hasLayerCell($layer, $x, $y) && $piece->isMember($map->getLayerSymbol($layer, $x, $y));
    }

    /**
     * How one piece cell is painted: in the style its tileset authored for it,
     * kept byte for byte, or with the fallback colour when it has none (null
     * keeps the map cell's own colour).
     *
     * @return array{style: array{prefix: string, suffix: string}}|array{color: string|null}
     */
    public static function resolveCellPaint(string $source, ?string $fallbackColor): array
    {
        $style = self::readCellStyle($source);

        return $style !== null ? ['style' => $style] : ['color' => $fallbackColor];
    }

    /**
     * A piece cell's authored style, or null when it has none.
     *
     * @return array{prefix: string, suffix: string}|null
     */
    private static function readCellStyle(string $source): ?array
    {
        $cell = TerminalText::parseSourceCells($source)[0] ?? null;

        return $cell !== null && ($cell['prefix'] !== '' || $cell['suffix'] !== '')
            ? ['prefix' => $cell['prefix'], 'suffix' => $cell['suffix']]
            : null;
    }

    /** A piece cell's authored foreground, or null when it has none. */
    private static function readCellColor(string $source): ?string
    {
        $style = self::readCellStyle($source);

        return $style === null ? null : ProjectMap::getInnermostForeground($style['prefix']);
    }
}
