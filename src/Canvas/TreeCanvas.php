<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\TreeBrush;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\PaletteItem;

/**
 * The tree brush in the terminal: plant a forest of every tree type over the
 * canvas selection, or erase the trees standing in it, as one undo step. The
 * layout, trunk collision and canopy layer are {@see TreeBrush}'s, the same
 * as in the GUI editor.
 */
trait TreeCanvas
{
    private function buildTreePaletteItems(): array
    {
        $map = $this->getSelectedMap();
        if ($map === null || $map->getGridSourceIssue() !== null || $this->editingMode !== self::MODE_MAP) {
            return [];
        }

        return [
            new PaletteItem('Map: Draw trees in the selection', '', fn() => $this->drawTreesInSelection(false)),
            new PaletteItem('Map: Erase trees in the selection', '', fn() => $this->drawTreesInSelection(true)),
        ];
    }

    private function drawTreesInSelection(bool $erase): void
    {
        $map = $this->getSelectedMap();
        if ($map === null) {
            return;
        }
        if ($this->canvasSelection === null) {
            $this->setStatus('Select a region of the canvas first; the trees go inside it.', StatusLevel::WARN);
            return;
        }
        $this->finalizeActiveStroke();
        $cells = array_map(static fn(array $cell): array => [$cell['x'], $cell['y']], $this->getSelectionCells($this->canvasSelection));
        try {
            $result = $erase ? TreeBrush::erase($map, $cells) : TreeBrush::draw($map, array_keys(TreeBrush::listTypes($map)), $cells);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            return;
        }
        if ($result['command'] === null) {
            $this->setStatus($erase ? 'No tree stands in the selection.'
                : 'No tree fits there: trunks need open ground clear of events, NPCs and other trunks, and never close a passage.', StatusLevel::WARN);
            return;
        }
        $this->recordCommand($result['command']);
        $this->setStatus($erase ? sprintf('Erased %d trees and freed their trunks. Ctrl+Z undoes it.', $result['removed'])
            : sprintf('Planted %d trees with solid trunks. Ctrl+Z undoes it; Save writes them.', $result['planted']));
        $this->requestFullRender();
    }
}
