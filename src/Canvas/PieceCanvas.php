<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\PaletteItem;
use Ichiloto\Editor\History\PaintStrokeCommand;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/**
 * Tileset pieces on the terminal canvas: choose a whole item from the map's
 * tileset and stamp it at the cursor. A stamp writes the piece's glyphs on
 * the gameplay layer it names and its tiles on the tile layers it names, as
 * one undo step. The canvas shows and previews only the glyphs; the tiles
 * are never shown or asked for here.
 *
 * A connected piece, such as a wall, is drawn instead: Enter draws the cell
 * at the cursor and anchors there, and the next Enter draws a line or a
 * room's outline from the anchor to the cursor. Every draw or erase reshapes
 * the piece's cells it touches (see {@see ConnectedPieceShaper}).
 */
trait PieceCanvas
{
    /**
     * @var array{map: ProjectMap, piece: TilesetPiece, anchor: array{x: int, y: int}|null}|null The piece being placed, on the
     *     map it was chosen for, with the cell a connected piece's next draw starts from.
     */
    private ?array $piecePlacement = null;

    /**
     * Opens the piece picker: one entry per piece of the selected map's
     * tileset, filterable by name. Enter starts placing the highlighted
     * piece. Nothing opens when the map has no pieces to offer.
     */
    private function openPiecePicker(): void
    {
        $map = $this->getSelectedMap();
        if (! $map instanceof ProjectMap || $map->getGridSourceIssue() !== null) {
            return;
        }
        $pieces = $this->loadCanvasPieces($map);
        if ($pieces === null) {
            return;
        }
        $this->finalizeActiveStroke();
        $this->optionDialogField = ['canvasPiece' => true];
        $this->eventOptionDialogMarker = null;
        $this->eventOptionDialogPath = null;
        $this->eventOptionDialogTitle = 'Piece';
        $this->eventOptionDialogEntries = array_map(static function (TilesetPiece $piece): array {
            $tileLayers = array_keys($piece->connects === null ? $piece->tiles : $piece->shapeTiles);
            return [
                'label' => $piece->name,
                'value' => $piece->id,
                'description' => implode(' · ', array_filter([
                    $piece->connects === null ? sprintf('%d x %d', $piece->width, $piece->height) : 'connected',
                    MapLayers::formatLabel($piece->layer),
                    $tileLayers === [] ? null : 'tiles: ' . implode(', ', $tileLayers),
                ])),
            ];
        }, array_values($pieces));
        $this->selectedEventOptionIndex = $this->resolveEventOptionSelectionIndex($this->piecePlacement['piece']->id ?? '');
        $this->isEventOptionDialogOpen = true;
        $this->statusMessage = 'Choose a piece to place.';
        $this->renderSelectionDependentArea();
    }

    /**
     * The pieces of the map's tileset, or null after saying in the status
     * why there are none to offer.
     *
     * @return array<string, TilesetPiece>|null Pieces by id, in authored order.
     */
    private function loadCanvasPieces(ProjectMap $map): ?array
    {
        try {
            $tileset = $map->loadTileset();
        } catch (\Throwable $error) {
            $this->setStatus('Pieces are unavailable: ' . $error->getMessage(), StatusLevel::WARN);
            return null;
        }
        if ($tileset === null) {
            $this->setStatus("This map names no tileset, so it has no pieces. Name one in its data file ('tileset' => '<id>').", StatusLevel::WARN);
            return null;
        }
        if ($tileset->pieces === []) {
            $this->setStatus(sprintf('Tileset %s has no pieces. Add them to assets/%s/%s.php.', $tileset->id, Tileset::DIRECTORY, $tileset->id), StatusLevel::WARN);
            return null;
        }
        return $tileset->pieces;
    }

    /** Starts placing the chosen piece on the selected map, in Map mode. */
    private function choosePiece(string $id): void
    {
        $map = $this->getSelectedMap();
        $pieces = $map instanceof ProjectMap ? $this->loadCanvasPieces($map) : null;
        if ($pieces === null) {
            return;
        }
        $piece = $pieces[$id] ?? null;
        if ($piece === null) {
            $this->setStatus("Piece {$id} is no longer in the map's tileset. Press P to choose again.", StatusLevel::WARN);
            return;
        }
        if ($this->editingMode !== self::MODE_MAP) {
            $this->setEditingMode(self::MODE_MAP);
        }
        $this->facadeBrush = null;
        $this->canvasToolAnchor = null;
        $this->inputMode = self::INPUT_NORMAL;
        $this->setFocusedPane(self::FOCUS_CANVAS, false);
        $this->piecePlacement = ['map' => $map, 'piece' => $piece, 'anchor' => null];
        $this->setStatus(sprintf($piece->connects === null
            ? 'Placing %s. Arrows move it, Enter stamps, Esc when done.'
            : 'Drawing %s. Enter draws at the cursor and anchors there, Del erases, Esc when done.', $piece->name));
        $this->renderFocusDependentArea();
    }

    /**
     * The placement in force: the piece being placed, while its map is the
     * selected one and the canvas is in Map mode's Normal input.
     *
     * @return array{map: ProjectMap, piece: TilesetPiece, anchor: array{x: int, y: int}|null}|null
     */
    private function getActivePiecePlacement(): ?array
    {
        $placement = $this->piecePlacement;
        return $placement !== null && $placement['map'] === $this->getSelectedMap()
            && $this->editingMode === self::MODE_MAP && $this->inputMode === self::INPUT_NORMAL
            ? $placement : null;
    }

    /**
     * Esc while placing: drops a connected piece's anchor when one is set,
     * and otherwise ends placement.
     */
    private function backOutOfPiecePlacement(): void
    {
        $placement = $this->getActivePiecePlacement();
        if ($placement !== null && $placement['anchor'] !== null) {
            $this->piecePlacement['anchor'] = null;
            $this->setStatus(sprintf('Anchor dropped. Enter draws %s at the cursor; Esc when done.', $placement['piece']->name));
            $this->renderCanvasArea();
            return;
        }
        $this->endPiecePlacement('Piece placement ended.');
    }

    private function endPiecePlacement(string $statusMessage = ''): void
    {
        $this->piecePlacement = null;
        if ($statusMessage !== '') {
            $this->setStatus($statusMessage);
        }
        $this->renderCanvasArea();
    }

    /**
     * The piece's footprint with its top-left cell at the cursor, for the
     * canvas preview: a glyph where the piece writes one, null where a space
     * glyph leaves the map's cell as it is. A connected piece previews the
     * cells Enter would draw, each with the glyph of the shape it would take.
     *
     * @return array<int, array<int, string|null>> Cells by row and column.
     */
    private function getPiecePreviewCells(): array
    {
        $placement = $this->getActivePiecePlacement();
        if ($placement === null) {
            return [];
        }
        $piece = $placement['piece'];
        if ($piece->connects !== null) {
            return $this->getConnectedPreviewCells($placement['map'], $piece, $placement['anchor']);
        }
        $cells = [];
        foreach ($piece->glyphs as $row => $symbols) {
            foreach ($symbols as $column => $symbol) {
                $cells[$this->cursorY + $row][$this->cursorX + $column] = $symbol === ' ' ? null : $symbol;
            }
        }
        return $cells;
    }

    /**
     * Enter while placing: stamps an item piece, or draws a connected one.
     * The piece is read from the tileset again, so the tileset stays the one
     * source of pieces.
     */
    private function applyPieceAtCursor(): void
    {
        $placement = $this->reloadPiecePlacement();
        if ($placement === null) {
            return;
        }
        if ($placement['piece']->connects === null) {
            $this->stampPiece($placement['map'], $placement['piece']);
            return;
        }
        $this->drawConnectedPiece($placement['map'], $placement['piece'], $placement['anchor']);
    }

    /**
     * The placement in force with its piece read from the tileset again, or
     * null after ending placement when the piece is gone.
     *
     * @return array{map: ProjectMap, piece: TilesetPiece, anchor: array{x: int, y: int}|null}|null
     */
    private function reloadPiecePlacement(): ?array
    {
        $placement = $this->getActivePiecePlacement();
        if ($placement === null) {
            return null;
        }
        $pieces = $this->loadCanvasPieces($placement['map']);
        $piece = $pieces[$placement['piece']->id] ?? null;
        if ($piece === null) {
            if ($pieces !== null) {
                $this->setStatus("{$placement['piece']->name} is no longer in the map's tileset. Press P to choose a piece.", StatusLevel::WARN);
            }
            $this->endPiecePlacement();
            return null;
        }
        $this->piecePlacement['piece'] = $piece;
        if ($piece->connects === null) {
            $this->piecePlacement['anchor'] = null;
        }

        return $this->piecePlacement;
    }

    /**
     * Stamps the piece at the cursor as one undo step: its glyphs on the
     * gameplay layer it names, in the brush colour, and its tiles on the tile
     * layers it names, creating any the map does not have yet. Space glyphs
     * and `0` tiles leave their cells as they are. A stamp that cannot be
     * made whole changes nothing.
     */
    private function stampPiece(ProjectMap $map, TilesetPiece $piece): void
    {
        $x = $this->cursorX;
        $y = $this->cursorY;
        try {
            $layer = $this->findPieceLayer($map, $piece);
            foreach ($piece->glyphs as $row => $symbols) {
                foreach (array_keys($symbols) as $column) {
                    if (! $map->hasLayerCell($layer, $x + $column, $y + $row)) {
                        throw new MapSourceRefusal(sprintf('%s (%d x %d) does not fit at (%d, %d): the map has no cell at (%d, %d). Nothing was changed.',
                            $piece->name, $piece->width, $piece->height, $x, $y, $x + $column, $y + $row));
                    }
                }
            }
            $this->finalizeActiveStroke();
            $tilesBefore = $map->getTileLayerSources();
            $map->writeTileEntries($piece->tiles, $x, $y);
            $tilesAfter = $map->getTileLayerSources();
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            $this->renderCanvasArea();
            return;
        }
        $writes = [];
        foreach ($piece->glyphs as $row => $symbols) {
            foreach ($symbols as $column => $symbol) {
                if ($symbol !== ' ') {
                    $writes[] = ['x' => $x + $column, 'y' => $y + $row, 'symbol' => $symbol, 'color' => $this->selectedPaintColor];
                }
            }
        }
        [$stroke] = $this->writeCanvasCells($map, $layer, $writes, 'Piece stamp');
        if (! $stroke->hasChanges() && $tilesAfter === $tilesBefore) {
            $this->setStatus(sprintf('%s is already at (%d, %d).', $piece->name, $x, $y));
            $this->renderCanvasArea();
            return;
        }
        $this->recordPieceCommand('Piece stamp', $map, $stroke, $tilesBefore, $tilesAfter);
        $this->setStatus(sprintf('Stamped %s at (%d, %d). Enter stamps again; Esc when done.', $piece->name, $x, $y));
        $this->renderCanvasArea();
    }

    /**
     * Records a piece's glyph stroke and tile layer change as one undo step.
     *
     * @param array<string, string> $tilesBefore Tile layer sources before the change.
     * @param array<string, string> $tilesAfter Tile layer sources after it.
     */
    private function recordPieceCommand(string $label, ProjectMap $map, PaintStrokeCommand $stroke, array $tilesBefore, array $tilesAfter): void
    {
        $this->recordCommand(new GenericCommand($label,
            static function () use ($stroke, $map, $tilesAfter): void {
                $stroke->execute();
                $map->restoreTileLayerSources($tilesAfter);
            },
            static function () use ($stroke, $map, $tilesBefore): void {
                $stroke->undo();
                $map->restoreTileLayerSources($tilesBefore);
            },
        ));
    }

    /**
     * Draws a connected piece: the cell at the cursor without an anchor,
     * otherwise the line or room outline from the anchor to the cursor. The
     * anchor then moves to the cursor, so the next draw continues from there.
     *
     * @param array{x: int, y: int}|null $anchor
     */
    private function drawConnectedPiece(ProjectMap $map, TilesetPiece $piece, ?array $anchor): void
    {
        $cells = $this->getConnectedDrawCells($anchor);
        $changed = $this->applyConnectedPiece($map, $piece, $cells, [], 'Piece draw');
        if ($changed === null) {
            return;
        }
        $this->piecePlacement['anchor'] = ['x' => $this->cursorX, 'y' => $this->cursorY];
        $drew = $changed
            ? sprintf('Drew %d %s %s.', count($cells), mb_strtolower($piece->name), count($cells) === 1 ? 'cell' : 'cells')
            : sprintf('%s is already drawn there.', $piece->name);
        $this->setStatus($drew . ($anchor === null
            ? ' Anchor set; move and press Enter to draw a line or a room.'
            : ' Enter draws on from here; Esc drops the anchor.'));
        $this->renderCanvasArea();
    }

    /**
     * Erases the connected piece's cell under the cursor and reshapes the
     * cells beside it. A cell that is not part of the piece is left alone.
     */
    private function eraseConnectedPieceCell(): void
    {
        $placement = $this->reloadPiecePlacement();
        if ($placement === null || $placement['piece']->connects === null) {
            return;
        }
        $map = $placement['map'];
        $piece = $placement['piece'];
        $x = $this->cursorX;
        $y = $this->cursorY;
        try {
            $layer = $this->findPieceLayer($map, $piece);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            $this->renderCanvasArea();
            return;
        }
        if (! $this->resolveConnectedMemberLookup($map, $layer, $piece)($x, $y)) {
            $this->setStatus(sprintf('(%d, %d) is not part of a %s, so nothing was erased.', $x, $y, mb_strtolower($piece->name)));
            $this->renderCanvasArea();
            return;
        }
        if ($this->applyConnectedPiece($map, $piece, [], [['x' => $x, 'y' => $y]], 'Piece erase') !== null) {
            $this->setStatus(sprintf('Erased the %s cell at (%d, %d).', mb_strtolower($piece->name), $x, $y));
            $this->renderCanvasArea();
        }
    }

    /**
     * Draws and erases a connected piece's cells as one undo step: writes
     * them, then gives every drawn cell and every member beside a drawn or
     * erased cell the glyph and tiles of its shape. Glyphs go on the piece's
     * gameplay layer, drawn cells in the brush colour and reshaped cells
     * keeping theirs; tiles go on the piece's tile layers, `0` where a cell
     * was erased. A cell beyond the map refuses the whole change.
     *
     * @param list<array{x: int, y: int}> $drawn
     * @param list<array{x: int, y: int}> $erased
     * @return bool|null Whether anything changed, or null when refused.
     */
    private function applyConnectedPiece(ProjectMap $map, TilesetPiece $piece, array $drawn, array $erased, string $label): ?bool
    {
        try {
            $layer = $this->findPieceLayer($map, $piece);
            foreach ([...$drawn, ...$erased] as $cell) {
                if (! $map->hasLayerCell($layer, $cell['x'], $cell['y'])) {
                    throw new MapSourceRefusal(sprintf('%s cannot reach (%d, %d): the map has no cell there. Nothing was changed.',
                        $piece->name, $cell['x'], $cell['y']));
                }
            }
            $cells = ConnectedPieceShaper::reshapeCells($piece, $drawn, $erased, $this->resolveConnectedMemberLookup($map, $layer, $piece));
            $tiles = [];
            foreach ($piece->shapeTiles as $tileLayer => $entries) {
                foreach ($cells as $cell) {
                    $tiles[$tileLayer][] = ['x' => $cell['x'], 'y' => $cell['y'],
                        'entry' => $cell['shape'] === null ? (string) TileId::EMPTY : $entries[$cell['shape']]];
                }
            }
            $this->finalizeActiveStroke();
            $tilesBefore = $map->getTileLayerSources();
            $map->writeTileCells($tiles);
            $tilesAfter = $map->getTileLayerSources();
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            $this->renderCanvasArea();
            return null;
        }
        $drawnKeys = array_flip(array_map(static fn(array $cell): string => "{$cell['x']},{$cell['y']}", $drawn));
        $writes = array_map(fn(array $cell): array => [
            'x' => $cell['x'],
            'y' => $cell['y'],
            'symbol' => $cell['shape'] === null ? ' ' : $piece->shapes[$cell['shape']],
            'color' => isset($drawnKeys["{$cell['x']},{$cell['y']}"]) ? $this->selectedPaintColor : null,
        ], $cells);
        [$stroke] = $this->writeCanvasCells($map, $layer, $writes, $label);
        if (! $stroke->hasChanges() && $tilesAfter === $tilesBefore) {
            return false;
        }
        $this->recordPieceCommand($label, $map, $stroke, $tilesBefore, $tilesAfter);

        return true;
    }

    /**
     * The cells Enter would draw: the cursor's cell without an anchor,
     * otherwise the outline of the rectangle with the anchor and the cursor
     * as opposite corners, which is a straight line when they share a row or
     * a column.
     *
     * @param array{x: int, y: int}|null $anchor
     * @return list<array{x: int, y: int}>
     */
    private function getConnectedDrawCells(?array $anchor): array
    {
        $anchor ??= ['x' => $this->cursorX, 'y' => $this->cursorY];

        return array_values(ToolGeometry::rectangleOutline($anchor['x'], $anchor['y'], $this->cursorX, $this->cursorY));
    }

    /**
     * The cells Enter would draw, each with the glyph of the shape it would
     * take, for the canvas preview.
     *
     * @param array{x: int, y: int}|null $anchor
     * @return array<int, array<int, string|null>> Cells by row and column.
     */
    private function getConnectedPreviewCells(ProjectMap $map, TilesetPiece $piece, ?array $anchor): array
    {
        $drawn = $this->getConnectedDrawCells($anchor);
        try {
            $isMemberCell = $this->resolveConnectedMemberLookup($map, $this->findPieceLayer($map, $piece), $piece);
        } catch (MapSourceRefusal) {
            $isMemberCell = static fn(int $x, int $y): bool => false;
        }
        $preview = [];
        foreach (array_slice(ConnectedPieceShaper::reshapeCells($piece, $drawn, [], $isMemberCell), 0, count($drawn)) as $cell) {
            $preview[$cell['y']][$cell['x']] = $piece->shapes[(string) $cell['shape']];
        }

        return $preview;
    }

    /**
     * Whether a cell of the layer belongs to the connected piece now: it is
     * on the map and holds one of the piece's glyphs.
     *
     * @return \Closure(int, int): bool
     */
    private function resolveConnectedMemberLookup(ProjectMap $map, string $layer, TilesetPiece $piece): \Closure
    {
        return static fn(int $x, int $y): bool => $map->hasLayerCell($layer, $x, $y) && $piece->isMember($map->getLayerSymbol($layer, $x, $y));
    }

    /**
     * The id of the gameplay layer the piece's glyphs go on.
     *
     * @throws MapSourceRefusal When the map has no gameplay layer with that name.
     */
    private function findPieceLayer(ProjectMap $map, TilesetPiece $piece): string
    {
        foreach ($map->getLayers() as $layer) {
            if ($layer['id'] !== MapLayers::EVENT && ! $layer['decoration'] && $layer['name'] === $piece->layer) {
                return $layer['id'];
            }
        }
        throw new MapSourceRefusal(sprintf('%s goes on the %s layer, which this map does not have. Create it (Ctrl+P, Layers: Create gameplay layer) first. Nothing was changed.',
            $piece->name, $piece->layer));
    }

    /** The canvas border hint while a piece is being placed, or null when none is. */
    private function getPiecePlacementHelp(int $windowWidth): ?string
    {
        $placement = $this->getActivePiecePlacement();
        if ($placement === null) {
            return null;
        }
        $piece = $placement['piece'];
        if ($piece->connects === null) {
            return $this->fitHelp(
                $windowWidth,
                sprintf('PIECE %s  Enter:Stamp  Esc:Done', $piece->name),
                sprintf('PIECE %s Enter:Stamp Esc:Done', $piece->name),
                'PIECE Enter:Stamp Esc:Done',
                'Enter:Stamp Esc:Done',
                'Esc:Done',
            );
        }
        $escape = $placement['anchor'] === null ? 'Esc:Done' : 'Esc:Unanchor';
        return $this->fitHelp(
            $windowWidth,
            sprintf('PIECE %s  Enter:Draw  Del:Erase  %s', $piece->name, $escape),
            sprintf('PIECE %s Enter:Draw Del:Erase %s', $piece->name, $escape),
            "PIECE Enter:Draw Del:Erase {$escape}",
            "Enter:Draw Del:Erase {$escape}",
            $escape,
        );
    }

    /** @return list<PaletteItem> */
    private function buildPiecePaletteItems(): array
    {
        $map = $this->getSelectedMap();
        if (! $map instanceof ProjectMap || $map->getGridSourceIssue() !== null) {
            return [];
        }
        return [new PaletteItem('Pieces: Choose a piece to place', 'P', function (): void {
            $this->closeDatabaseIfOpen();
            $this->openPiecePicker();
        })];
    }
}
