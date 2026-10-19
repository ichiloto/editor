<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\PaletteItem;
use Ichiloto\Editor\History\PaintStrokeCommand;
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
     * tileset that goes on the layer being edited, filterable by name. Enter
     * starts placing the highlighted piece. Nothing opens when the map names
     * no tileset or the layer has no pieces to offer, and the status says
     * why, naming the layers that have pieces.
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
        $layer = $this->findPieceCanvasLayer($map);
        $offered = array_filter($pieces, static fn(TilesetPiece $piece): bool => $piece->layer === ($layer['name'] ?? null));
        if ($offered === []) {
            $layers = array_values(array_unique(array_map(static fn(TilesetPiece $piece): string => MapLayers::formatLabel($piece->layer), $pieces)));
            $this->setStatus(sprintf('%s has no pieces for the %s layer. It has pieces for %s; press L to switch layers.',
                $map->loadTileset()?->name ?? 'The tileset', $layer === null ? 'current' : MapLayers::formatLabel($layer['name']),
                implode(', ', $layers)), StatusLevel::WARN);
            $this->renderFooter();
            return;
        }
        $this->finalizeActiveStroke();
        $this->optionDialogField = ['canvasPiece' => true];
        $this->eventOptionDialogMarker = null;
        $this->eventOptionDialogPath = null;
        $this->eventOptionDialogTitle = sprintf('%s piece', MapLayers::formatLabel($layer['name']));
        $this->eventOptionDialogEntries = array_map(static function (TilesetPiece $piece): array {
            $tileLayers = array_keys($piece->connects === null ? $piece->tiles : $piece->shapeTiles);
            return [
                'label' => $piece->name,
                'value' => $piece->id,
                'description' => implode(' · ', array_filter([
                    $piece->connects === null ? sprintf('%d x %d', $piece->width, $piece->height) : 'connected',
                    $tileLayers === [] ? null : 'tiles: ' . implode(', ', $tileLayers),
                ])),
            ];
        }, array_values($offered));
        $this->selectedEventOptionIndex = $this->resolveEventOptionSelectionIndex($this->piecePlacement['piece']->id ?? '');
        $this->isEventOptionDialogOpen = true;
        $this->statusMessage = 'Choose a piece to place.';
        $this->renderSelectionDependentArea();
    }

    /**
     * The gameplay layer pieces go on: the layer Map mode edits, even from
     * Event mode, so choosing a piece there returns to it. Null for a
     * decoration layer, which takes no pieces.
     *
     * @return array<string, mixed>|null
     */
    private function findPieceCanvasLayer(ProjectMap $map): ?array
    {
        $selected = $this->getCanvasLayerState()['selected'];
        $layers = array_values(array_filter($map->getLayers(), static fn(array $layer): bool => $layer['id'] !== MapLayers::EVENT));
        $layer = array_find($layers, static fn(array $candidate): bool => $candidate['id'] === $selected)
            ?? array_find($layers, static fn(array $candidate): bool => $candidate['id'] === $map->getBaseLayerId());

        return $layer === null || $layer['decoration'] ? null : $layer;
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
            return PiecePlacer::loadPieces($map);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            return null;
        }
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
            ? 'Placing %s. Enter stamps and anchors; Enter again fills the area to the cursor, or drag with the mouse. Esc when done.'
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
     * What Enter would write with the cursor as the far corner, for the
     * canvas preview ({@see PiecePlacer::getPreviewCells()}).
     *
     * @return array<int, array<int, string|null>> Cells by row and column.
     */
    private function getPiecePreviewCells(): array
    {
        $placement = $this->getActivePiecePlacement();
        if ($placement === null) {
            return [];
        }
        $cursor = ['x' => $this->cursorX, 'y' => $this->cursorY];

        return array_map(static fn(array $row): array => array_map(static fn(?array $cell): ?string => $cell['symbol'] ?? null, $row),
            PiecePlacer::getPreviewCells($placement['map'], $placement['piece'], $placement['anchor'] ?? $cursor, $cursor));
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
            $this->stampPieceArea($placement['map'], $placement['piece'], $placement['anchor']);
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

        return $this->piecePlacement;
    }


    /**
     * Stamps the piece across the area from the anchor to the cursor (the
     * cursor alone without an anchor) as one undo step
     * ({@see PiecePlacer::stampArea()}), in the brush colour where the piece
     * has none, then anchors at the cursor so the next Enter fills on from
     * there. An area that cannot be stamped whole changes nothing.
     *
     * @param array{x: int, y: int}|null $anchor
     */
    private function stampPieceArea(ProjectMap $map, TilesetPiece $piece, ?array $anchor): void
    {
        $cursor = ['x' => $this->cursorX, 'y' => $this->cursorY];
        $this->finalizeActiveStroke();
        try {
            ['command' => $command, 'origins' => $origins] = PiecePlacer::stampArea($map, $piece, $anchor ?? $cursor, $cursor, $this->selectedPaintColor);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            $this->renderCanvasArea();
            return;
        }
        $this->piecePlacement['anchor'] = $cursor;
        $next = ' Enter fills on from here; Esc drops the anchor.';
        if ($command === null) {
            $this->setStatus(sprintf('%s is already there.', $piece->name) . $next);
            $this->renderCanvasArea();
            return;
        }
        $this->recordCommand($command);
        $this->setStatus(count($origins) === 1
            ? sprintf('Stamped %s at (%d, %d).', $piece->name, $origins[0]['x'], $origins[0]['y']) . $next
            : sprintf('Stamped %d %s across (%d, %d) to (%d, %d).', count($origins), $piece->name,
                $origins[0]['x'], $origins[0]['y'], $this->cursorX, $this->cursorY) . $next);
        $this->renderCanvasArea();
    }


    /**
     * A mouse drag while placing a piece: the press anchors it, the drag
     * previews the area or line to the pointer, and the release draws it and
     * drops the anchor, since the drag was the whole gesture.
     */
    private function dragPieceWithMouse(int $x, int $y, bool $isMotion, bool $isRelease): void
    {
        if ($this->getActivePiecePlacement() === null) {
            return;
        }
        $this->cursorX = $x;
        $this->cursorY = $y;
        if (! $isMotion && ! $isRelease) {
            $this->piecePlacement['anchor'] = ['x' => $x, 'y' => $y];
        }
        if ($isRelease) {
            $this->applyPieceAtCursor();
            if ($this->piecePlacement !== null) {
                $this->piecePlacement['anchor'] = null;
            }
            return;
        }
        $anchor = $this->piecePlacement['anchor'] ?? ['x' => $x, 'y' => $y];
        $this->setStatus(sprintf('%s from (%d, %d) to (%d, %d). Release to draw.', $this->piecePlacement['piece']->name, $anchor['x'], $anchor['y'], $x, $y));
        $this->renderCanvasArea();
    }

    /**
     * Records a glyph stroke and the tile layer change made with it, such as
     * a pasted block, as one undo step.
     *
     * @param array<string, string> $tilesBefore Tile layer sources before the change.
     * @param array<string, string> $tilesAfter Tile layer sources after it.
     */
    private function recordStrokeWithTiles(string $label, ProjectMap $map, PaintStrokeCommand $stroke, array $tilesBefore, array $tilesAfter): void
    {
        $this->recordCommand(CanvasEditor::combineStrokeWithTiles($label, $map, $stroke, $tilesBefore, $tilesAfter));
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
        $cursor = ['x' => $this->cursorX, 'y' => $this->cursorY];
        $cells = PiecePlacer::getConnectedDrawCells($anchor ?? $cursor, $cursor);
        $changed = $this->applyConnectedPiece($map, $piece, $cells, [], 'Piece draw');
        if ($changed === null) {
            return;
        }
        $this->piecePlacement['anchor'] = $cursor;
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
            $layer = PiecePlacer::findLayer($map, $piece);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            $this->renderCanvasArea();
            return;
        }
        if (! PiecePlacer::resolveMemberLookup($map, $layer, $piece)($x, $y)) {
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
     * Draws and erases a connected piece's cells as one undo step
     * ({@see PiecePlacer::drawConnected()}), drawn cells in the brush colour
     * where their shape has none.
     *
     * @param list<array{x: int, y: int}> $drawn
     * @param list<array{x: int, y: int}> $erased
     * @return bool|null Whether anything changed, or null when refused.
     */
    private function applyConnectedPiece(ProjectMap $map, TilesetPiece $piece, array $drawn, array $erased, string $label): ?bool
    {
        $this->finalizeActiveStroke();
        try {
            $command = PiecePlacer::drawConnected($map, $piece, $drawn, $erased, $this->selectedPaintColor, $label);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            $this->renderCanvasArea();
            return null;
        }
        if ($command === null) {
            return false;
        }
        $this->recordCommand($command);

        return true;
    }





    /** The canvas border hint while a piece is being placed, or null when none is. */
    private function getPiecePlacementHelp(int $windowWidth): ?string
    {
        $placement = $this->getActivePiecePlacement();
        if ($placement === null) {
            return null;
        }
        $piece = $placement['piece'];
        $escape = $placement['anchor'] === null ? 'Esc:Done' : 'Esc:Unanchor';
        if ($piece->connects === null) {
            $enter = $placement['anchor'] === null ? 'Enter:Stamp' : 'Enter:Fill';
            return $this->fitHelp(
                $windowWidth,
                sprintf('PIECE %s  %s  Drag:Fill  %s', $piece->name, $enter, $escape),
                sprintf('PIECE %s %s %s', $piece->name, $enter, $escape),
                "PIECE {$enter} {$escape}",
                "{$enter} {$escape}",
                $escape,
            );
        }
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
        }), new PaletteItem('Pieces: Draw tiles for this layer\'s glyphs', 'T', function (): void {
            $this->closeDatabaseIfOpen();
            $this->drawTilesForLayerGlyphs();
        })];
    }
}
