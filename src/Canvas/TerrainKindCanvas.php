<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\PaletteItem;
use Ichiloto\Engine\Rendering\Tilesets\TerrainKind;
use Ichiloto\Engine\Rendering\Tilesets\TerrainPassage;

/**
 * Terrain kinds on the terminal canvas: K opens a picker of the kinds the
 * map's tileset paints on the layer being edited, each with its glyph, its
 * passage, its encounters and whether its art is still to be drawn. Choosing
 * one paints it like an item piece: Enter paints the cursor's cell and
 * anchors there, the next Enter fills the area to the cursor, a mouse drag
 * fills the area it covers, Esc ends. Painting writes the kind's glyph in its
 * colour and its tiles ({@see TerrainKindPainter}); the canvas shows only the
 * glyph. The picker opens on the kind under the cursor.
 */
trait TerrainKindCanvas
{
    /**
     * Opens the kind picker for the layer being edited. Nothing opens when
     * the map is not painted in kinds or the layer has none, and the status
     * says why.
     */
    private function openKindPicker(): void
    {
        $map = $this->getSelectedMap();
        if (! $map instanceof ProjectMap || $map->getGridSourceIssue() !== null) {
            return;
        }
        $kinds = $this->loadCanvasKinds($map);
        if ($kinds === null) {
            return;
        }
        $layer = $this->findPieceCanvasLayer($map);
        $offered = array_filter($kinds, static fn(TerrainKind $kind): bool => $kind->layer === ($layer['name'] ?? null));
        if ($offered === []) {
            $layers = array_values(array_unique(array_map(static fn(TerrainKind $kind): string => MapLayers::formatLabel($kind->layer), $kinds)));
            $this->setStatus(sprintf('The %s layer has no terrain kinds. Kinds are painted on %s; press L to switch layers.',
                $layer === null ? 'current' : MapLayers::formatLabel($layer['name']), implode(', ', $layers)), StatusLevel::WARN);
            $this->renderFooter();
            return;
        }
        $this->finalizeActiveStroke();
        $this->optionDialogField = ['canvasKind' => true];
        $this->eventOptionDialogMarker = null;
        $this->eventOptionDialogPath = null;
        $this->eventOptionDialogTitle = sprintf('%s kind', MapLayers::formatLabel($layer['name']));
        $this->eventOptionDialogEntries = array_map(static fn(TerrainKind $kind): array => [
            'label' => sprintf('%s  %s', $kind->symbol, $kind->name),
            'value' => $kind->id,
            'description' => self::describeKind($kind, $kinds),
        ], array_values($offered));
        $under = TerrainKindPainter::findKindAt($map, (string) $layer['id'], $this->cursorX, $this->cursorY);
        $this->selectedEventOptionIndex = $this->resolveEventOptionSelectionIndex(
            $this->piecePlacement['kind']->id ?? $under?->id ?? '');
        $this->isEventOptionDialogOpen = true;
        $this->statusMessage = 'Choose a terrain kind to paint.';
        $this->renderSelectionDependentArea();
    }

    /**
     * What a picker row says about a kind: its passage, encounters, ground and
     * whether its art is still to be drawn.
     *
     * @param array<string, TerrainKind> $kinds
     */
    private static function describeKind(TerrainKind $kind, array $kinds): string
    {
        return implode(' · ', array_filter([
            $kind->passage === TerrainPassage::SOLID ? 'solid' : 'walk',
            $kind->passage === TerrainPassage::SOLID ? null : ($kind->encounters === null ? 'no encounters' : "{$kind->encounters} encounters"),
            $kind->ground === null ? null : 'on ' . ($kinds[$kind->ground]->name ?? $kind->ground),
            $kind->awaitingArt ? 'awaiting art' : null,
        ]));
    }

    /**
     * The kinds the map is painted in, or null after saying in the status why
     * there are none to paint.
     *
     * @return array<string, TerrainKind>|null
     */
    private function loadCanvasKinds(ProjectMap $map): ?array
    {
        try {
            return TerrainKindPainter::loadKinds($map);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            return null;
        }
    }

    /** Starts painting the chosen kind on the selected map, in Map mode. */
    private function chooseKind(string $id): void
    {
        $map = $this->getSelectedMap();
        $kinds = $map instanceof ProjectMap ? $this->loadCanvasKinds($map) : null;
        if ($kinds === null) {
            return;
        }
        $kind = $kinds[$id] ?? null;
        if ($kind === null) {
            $this->setStatus("Terrain kind {$id} is no longer in the map's tileset. Press K to choose again.", StatusLevel::WARN);
            return;
        }
        if ($this->editingMode !== self::MODE_MAP) {
            $this->setEditingMode(self::MODE_MAP);
        }
        $this->facadeBrush = null;
        $this->canvasToolAnchor = null;
        $this->inputMode = self::INPUT_NORMAL;
        $this->setFocusedPane(self::FOCUS_CANVAS, false);
        $this->piecePlacement = ['map' => $map, 'piece' => $kind->piece, 'anchor' => null, 'kind' => $kind];
        $this->setStatus(sprintf('Painting %s. Enter paints and anchors; Enter again fills the area to the cursor, or drag with the mouse. Esc when done.%s',
            $kind->name, $kind->awaitingArt ? ' Its art is still to be drawn, so it shows its glyph graphically.' : ''));
        $this->renderFocusDependentArea();
    }

    /**
     * Paints the kind across the area from the anchor to the cursor (the
     * cursor alone without an anchor) as one undo step, then anchors at the
     * cursor so the next Enter fills on from there.
     *
     * @param array{x: int, y: int}|null $anchor
     */
    private function paintKindArea(ProjectMap $map, TerrainKind $kind, ?array $anchor): void
    {
        $cursor = ['x' => $this->cursorX, 'y' => $this->cursorY];
        $this->finalizeActiveStroke();
        try {
            ['command' => $command, 'cells' => $cells] = TerrainKindPainter::paintArea($map, $kind, $anchor ?? $cursor, $cursor, 'Paint ' . $kind->name);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            $this->renderCanvasArea();
            return;
        }
        $this->piecePlacement['anchor'] = $cursor;
        $next = ' Enter fills on from here; Esc drops the anchor.';
        if ($command === null) {
            $this->setStatus(sprintf('%s is already there.', $kind->name) . $next);
            $this->renderCanvasArea();
            return;
        }
        $this->recordCommand($command);
        $this->setStatus(($cells === 1 ? sprintf('Painted %s at (%d, %d).', $kind->name, $this->cursorX, $this->cursorY)
            : sprintf('Painted %d cells of %s to (%d, %d).', $cells, $kind->name, $this->cursorX, $this->cursorY)) . $next);
        $this->renderCanvasArea();
    }

    /**
     * The placement in force with its kind read from the tileset again, or
     * null after ending placement when the kind is gone.
     *
     * @return array{map: ProjectMap, piece: \Ichiloto\Engine\Rendering\Tilesets\TilesetPiece, anchor: array{x: int, y: int}|null, kind: TerrainKind}|null
     */
    private function reloadKindPlacement(array $placement): ?array
    {
        $kinds = $this->loadCanvasKinds($placement['map']);
        $kind = $kinds[$placement['kind']->id] ?? null;
        if ($kind === null) {
            if ($kinds !== null) {
                $this->setStatus("{$placement['kind']->name} is no longer in the map's tileset. Press K to choose a kind.", StatusLevel::WARN);
            }
            $this->endPiecePlacement();
            return null;
        }
        $this->piecePlacement['kind'] = $kind;
        $this->piecePlacement['piece'] = $kind->piece;

        return $this->piecePlacement;
    }

    /** @return list<PaletteItem> */
    private function buildKindPaletteItems(): array
    {
        $map = $this->getSelectedMap();
        if (! $map instanceof ProjectMap || $map->getGridSourceIssue() !== null) {
            return [];
        }
        return [new PaletteItem('Terrain: Choose a terrain kind to paint', 'K', function (): void {
            $this->closeDatabaseIfOpen();
            $this->openKindPicker();
        })];
    }
}
