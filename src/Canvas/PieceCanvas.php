<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\PaletteItem;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/**
 * Tileset pieces on the terminal canvas: choose a whole item from the map's
 * tileset and stamp it at the cursor. A stamp writes the piece's glyphs on
 * the gameplay layer it names and its tiles on the tile layers it names, as
 * one undo step. The canvas shows and previews only the glyphs; the tiles
 * are never shown or asked for here.
 */
trait PieceCanvas
{
    /** @var array{map: ProjectMap, piece: TilesetPiece}|null The piece being placed, on the map it was chosen for. */
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
        $this->eventOptionDialogEntries = array_map(static fn(TilesetPiece $piece): array => [
            'label' => $piece->name,
            'value' => $piece->id,
            'description' => implode(' · ', array_filter([
                sprintf('%d x %d', $piece->width, $piece->height),
                $piece->layer,
                $piece->tiles === [] ? null : 'tiles: ' . implode(', ', array_keys($piece->tiles)),
            ])),
        ], array_values($pieces));
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
        $this->piecePlacement = ['map' => $map, 'piece' => $piece];
        $this->setStatus(sprintf('Placing %s. Arrows move it, Enter stamps, Esc when done.', $piece->name));
        $this->renderFocusDependentArea();
    }

    /**
     * The placement in force: the piece being placed, while its map is the
     * selected one and the canvas is in Map mode's Normal input.
     *
     * @return array{map: ProjectMap, piece: TilesetPiece}|null
     */
    private function getActivePiecePlacement(): ?array
    {
        $placement = $this->piecePlacement;
        return $placement !== null && $placement['map'] === $this->getSelectedMap()
            && $this->editingMode === self::MODE_MAP && $this->inputMode === self::INPUT_NORMAL
            ? $placement : null;
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
     * glyph leaves the map's cell as it is.
     *
     * @return array<int, array<int, string|null>> Cells by row and column.
     */
    private function getPiecePreviewCells(): array
    {
        $piece = $this->getActivePiecePlacement()['piece'] ?? null;
        if (! $piece instanceof TilesetPiece) {
            return [];
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
     * Stamps the piece at the cursor as one undo step: its glyphs on the
     * gameplay layer it names, in the brush colour, and its tiles on the tile
     * layers it names, creating any the map does not have yet. Space glyphs
     * and `0` tiles leave their cells as they are. The piece is read from
     * the tileset again, so the tileset stays the one source of pieces. A
     * stamp that cannot be made whole changes nothing.
     */
    private function stampPiece(): void
    {
        $placement = $this->getActivePiecePlacement();
        if ($placement === null) {
            return;
        }
        $map = $placement['map'];
        $pieces = $this->loadCanvasPieces($map);
        $piece = $pieces[$placement['piece']->id] ?? null;
        if ($piece === null) {
            if ($pieces !== null) {
                $this->setStatus("{$placement['piece']->name} is no longer in the map's tileset. Press P to choose a piece.", StatusLevel::WARN);
            }
            $this->endPiecePlacement();
            return;
        }
        $this->piecePlacement['piece'] = $piece;
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
        $this->recordCommand(new GenericCommand('Piece stamp',
            static function () use ($stroke, $map, $tilesAfter): void {
                $stroke->execute();
                $map->restoreTileLayerSources($tilesAfter);
            },
            static function () use ($stroke, $map, $tilesBefore): void {
                $stroke->undo();
                $map->restoreTileLayerSources($tilesBefore);
            },
        ));
        $this->setStatus(sprintf('Stamped %s at (%d, %d). Enter stamps again; Esc when done.', $piece->name, $x, $y));
        $this->renderCanvasArea();
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
        $piece = $this->getActivePiecePlacement()['piece'] ?? null;
        return $piece instanceof TilesetPiece ? $this->fitHelp(
            $windowWidth,
            sprintf('PIECE %s  Enter:Stamp  Esc:Done', $piece->name),
            sprintf('PIECE %s Enter:Stamp Esc:Done', $piece->name),
            'PIECE Enter:Stamp Esc:Done',
            'Enter:Stamp Esc:Done',
            'Esc:Done',
        ) : null;
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
