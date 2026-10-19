<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\Field\MapAreaAuthoring;
use Ichiloto\Editor\Field\MapAreaRefusal;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Status\StatusLevel;

/**
 * Area mode on the terminal canvas: the map's named areas chosen by keyboard,
 * on the cursor. Space adds or takes away the cursor's cell, `r` marks one
 * corner of a rectangle and then its other, Enter makes the selection an area
 * (asking for its id) or applies it to the area being changed, Enter on an
 * area with nothing selected picks that area up to change, and `n` steps to
 * the next area sharing the cursor's cell. The selection
 * shows in reverse video over the map's own glyphs. The inspector's Areas
 * list renames and deletes areas, as it does in a graphical editor.
 */
trait AreaCanvas
{
    /** @var array<string, array{int, int}> The cells selected for an area, by "x,y". */
    private array $areaSelection = [];
    /** @var array{int, int}|null The first corner of a rectangle being selected. */
    private ?array $areaRectangleAnchor = null;
    /** The area whose cells the selection replaces; null for a new area. */
    private ?string $editedAreaId = null;
    /** The id typed for a new area; null when none is being named. */
    private ?string $areaIdBuffer = null;

    /** Leaves no selection, corner, area or prompt behind. */
    private function resetAreaSelection(): void
    {
        $this->areaSelection = [];
        $this->areaRectangleAnchor = null;
        $this->editedAreaId = null;
        $this->areaIdBuffer = null;
    }

    /** Handles a key in Area mode; false when the canvas's own keys take it. */
    private function handleAreaModeInput(string $input): bool
    {
        $map = $this->getSelectedMap();
        if (! $map instanceof ProjectMap) {
            return false;
        }
        if ($this->areaIdBuffer !== null) {
            $this->handleAreaIdInput($map, $input);

            return true;
        }

        return match (true) {
            $input === ' ' => $this->toggleAreaCell(),
            $input === 'r' || $input === 'R' => $this->markAreaRectangleCorner(),
            $input === "\n" || $input === "\r" => $this->applyAreaSelection($map),
            $input === 'n' || $input === 'N' => $this->pickUpAreaAtCursor($map, step: true),
            $input === 'c' || $input === 'C' => $this->clearAreaSelection('Selection cleared.'),
            str_contains($input, "\033[3~") || $input === "\x7f" => $this->deleteEditedArea($map),
            $input === "\033" => $this->backOutOfAreaSelection(),
            default => false,
        };
    }

    private function toggleAreaCell(): bool
    {
        $key = $this->cursorX . ',' . $this->cursorY;
        if (isset($this->areaSelection[$key])) {
            unset($this->areaSelection[$key]);
        } else {
            $this->areaSelection[$key] = [$this->cursorX, $this->cursorY];
        }
        $this->describeAreaSelection();

        return true;
    }

    private function markAreaRectangleCorner(): bool
    {
        if ($this->areaRectangleAnchor === null) {
            $this->areaRectangleAnchor = [$this->cursorX, $this->cursorY];
            $this->setStatus(sprintf('Rectangle from %d, %d: move to its other corner and press r.', $this->cursorX, $this->cursorY));
            $this->renderCanvasArea();

            return true;
        }
        foreach ($this->readAreaRectangleCells() as [$x, $y]) {
            $this->areaSelection[$x . ',' . $y] = [$x, $y];
        }
        $this->areaRectangleAnchor = null;
        $this->describeAreaSelection();

        return true;
    }

    /** @return list<array{int, int}> The cells from the rectangle's first corner to the cursor. */
    private function readAreaRectangleCells(): array
    {
        if ($this->areaRectangleAnchor === null) {
            return [];
        }
        [$anchorX, $anchorY] = $this->areaRectangleAnchor;
        $cells = [];
        for ($y = min($anchorY, $this->cursorY); $y <= max($anchorY, $this->cursorY); $y++) {
            for ($x = min($anchorX, $this->cursorX); $x <= max($anchorX, $this->cursorX); $x++) {
                $cells[] = [$x, $y];
            }
        }

        return $cells;
    }

    /**
     * Enter: with nothing selected, picks up the area under the cursor; with
     * cells selected, applies them to the area being changed, or asks for a
     * new area's id.
     */
    private function applyAreaSelection(ProjectMap $map): bool
    {
        if ($this->areaSelection === []) {
            return $this->pickUpAreaAtCursor($map, step: false);
        }
        if ($this->editedAreaId !== null) {
            $id = $this->editedAreaId;
            if ($this->recordAreaEdit(fn(MapAreaAuthoring $areas): ?Command => $areas->setArea($id, $this->readAreaSelectionShape()), sprintf('Area %s now covers %%s.', $id))) {
                $this->resetAreaSelection();
            }
            $this->renderCanvasArea();

            return true;
        }
        $this->areaIdBuffer = '';
        $this->setStatus('New area id: (lowercase letters, digits, . _ -; Enter makes it, Esc cancels)');
        $this->renderCanvasArea();

        return true;
    }

    /**
     * Picks up the area under the cursor to change it: the first, or with
     * `step` the one after the area picked up, as several may share a cell.
     */
    private function pickUpAreaAtCursor(ProjectMap $map, bool $step): bool
    {
        $under = $this->findAreasAtCursor($map);
        if ($under === []) {
            $this->setStatus('Select cells first: Space adds the cursor\'s cell, r marks a rectangle\'s corners.', StatusLevel::WARN);
            $this->renderFooter();

            return true;
        }
        $current = $step ? array_search($this->editedAreaId, array_column($under, 'id'), true) : false;
        $area = $under[$current === false ? 0 : ($current + 1) % count($under)];
        $this->resetAreaSelection();
        $this->editedAreaId = $area['id'];
        foreach ($area['cells'] as [$x, $y]) {
            $this->areaSelection[$x . ',' . $y] = [$x, $y];
        }
        $this->setStatus(sprintf('Changing area %s%s: Space and r change its cells, Enter applies, Del deletes it, Esc lets it go.',
            $area['id'], count($under) > 1 ? sprintf(' (1 of %d here; n for the next)', count($under)) : ''));
        $this->renderCanvasArea();

        return true;
    }

    private function handleAreaIdInput(ProjectMap $map, string $input): void
    {
        if ($input === "\033") {
            $this->areaIdBuffer = null;
            $this->setStatus('No area made; the selection is kept.');
        } elseif ($input === "\n" || $input === "\r") {
            $id = trim((string) $this->areaIdBuffer);
            $this->areaIdBuffer = null;
            if ($this->recordAreaEdit(fn(MapAreaAuthoring $areas): ?Command => $areas->createArea($id, $this->readAreaSelectionShape()), sprintf('Made area %s of %%s.', $id))) {
                $this->resetAreaSelection();
            }
        } elseif ($input === "\x7f" || $input === "\x08") {
            $this->areaIdBuffer = mb_substr((string) $this->areaIdBuffer, 0, max(0, mb_strlen((string) $this->areaIdBuffer) - 1));
            $this->setStatus('New area id: ' . $this->areaIdBuffer);
        } elseif (mb_strlen($input) === 1 && ! ctype_cntrl($input)) {
            $this->areaIdBuffer .= $input;
            $this->setStatus('New area id: ' . $this->areaIdBuffer);
        }
        $this->renderCanvasArea();
    }

    private function deleteEditedArea(ProjectMap $map): bool
    {
        $id = $this->editedAreaId ?? ($this->findAreasAtCursor($map)[0]['id'] ?? null);
        if ($id === null) {
            $this->setStatus('No area here to delete.', StatusLevel::WARN);
            $this->renderFooter();

            return true;
        }
        if ($this->recordAreaEdit(static fn(MapAreaAuthoring $areas): ?Command => $areas->deleteArea($id), sprintf('Deleted area %s.', $id))) {
            $this->resetAreaSelection();
        }
        $this->renderCanvasArea();

        return true;
    }

    /** Esc walks one step out: the rectangle corner, then the selection, then Area mode. */
    private function backOutOfAreaSelection(): bool
    {
        if ($this->areaRectangleAnchor !== null) {
            $this->areaRectangleAnchor = null;
            $this->setStatus('Rectangle cancelled.');
            $this->renderCanvasArea();

            return true;
        }
        if ($this->areaSelection !== [] || $this->editedAreaId !== null) {
            return $this->clearAreaSelection('Selection let go.');
        }

        return false;
    }

    private function clearAreaSelection(string $message): bool
    {
        $this->resetAreaSelection();
        $this->setStatus($message);
        $this->renderCanvasArea();

        return true;
    }

    private function describeAreaSelection(): void
    {
        $count = count($this->areaSelection);
        $this->setStatus(sprintf('%d %s selected%s. Enter %s.', $count, $count === 1 ? 'cell' : 'cells',
            $this->editedAreaId === null ? '' : ' for area ' . $this->editedAreaId,
            $this->editedAreaId === null ? 'makes an area of them' : 'applies them'));
        $this->renderCanvasArea();
    }

    /**
     * Runs an area edit and records it, reporting what it did or why it was
     * refused.
     *
     * @param callable(MapAreaAuthoring): ?Command $edit
     * @param string $done The status on success; `%s` stands for the cells.
     * @return bool Whether it was made.
     */
    private function recordAreaEdit(callable $edit, string $done): bool
    {
        $map = $this->getSelectedMap();
        if (! $map instanceof ProjectMap) {
            return false;
        }
        $cells = count($this->areaSelection);
        try {
            $command = $edit(new MapAreaAuthoring($map));
        } catch (MapAreaRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);

            return false;
        }
        if ($command !== null) {
            $this->recordCommand($command);
        }
        $this->setStatus(sprintf($done, sprintf('%d %s', $cells, $cells === 1 ? 'cell' : 'cells')), StatusLevel::INFO);
        $this->renderInspectorArea();

        return true;
    }

    /**
     * The selection as an area shape: a rectangle when it fills one exactly,
     * as the author would write it, otherwise its exact cells in reading order.
     *
     * @return array<string, mixed>
     */
    private function readAreaSelectionShape(): array
    {
        $cells = array_values($this->areaSelection);
        usort($cells, static fn(array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
        if ($cells === []) {
            return ['cells' => []];
        }
        $xs = array_column($cells, 0);
        $ys = array_column($cells, 1);
        $width = max($xs) - min($xs) + 1;
        $height = max($ys) - min($ys) + 1;

        return $width * $height === count($cells)
            ? ['x' => min($xs), 'y' => min($ys), 'width' => $width, 'height' => $height]
            : ['cells' => $cells];
    }

    /**
     * The areas covering the cursor's cell, in the map's order.
     *
     * @return list<array{id: string, cells: list<array{int, int}>}>
     */
    private function findAreasAtCursor(ProjectMap $map): array
    {
        try {
            $areas = MapAreaAuthoring::describeAreas($map);
        } catch (MapAreaRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);

            return [];
        }

        return array_values(array_filter($areas, fn(array $area): bool => in_array([$this->cursorX, $this->cursorY], $area['cells'], true)));
    }

    /**
     * The selection and the rectangle being marked, highlighted over the
     * map's own glyphs, as ProjectMap::renderPreview() takes a footprint.
     *
     * @return array<int, array<int, null>>
     */
    private function getAreaPreviewCells(): array
    {
        $cells = [];
        foreach ([...array_values($this->areaSelection), ...$this->readAreaRectangleCells()] as [$x, $y]) {
            $cells[$y][$x] = null;
        }

        return $cells;
    }
}
