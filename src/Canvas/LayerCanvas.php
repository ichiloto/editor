<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\EditorWindow;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\UI\Modal;
use Ichiloto\Editor\UI\PaletteItem;
use Ichiloto\Editor\Status\StatusLevel;

/** Layer-specific shell behavior, sharing the existing paint and history paths. */
trait LayerCanvas
{
    private array $canvasLayerStates = [];
    private ?array $layerPrompt = null;
    private ?array $facadeBrush = null;

    private function getTerminalCanvasLayers(): array
    {
        return array_values(array_filter($this->getSelectedMap()?->getLayers() ?? [],
            static fn(array $layer): bool => ! $layer['decoration']));
    }

    private function getCanvasLayerState(): array
    {
        $map = $this->getSelectedMap();
        $state = $this->canvasLayerStates[$map?->mapId ?? ''] ?? [];
        return $state + ['selected' => $map?->getBaseLayerId() ?? MapLayers::BASE,
            'visibility' => [], 'dim' => false];
    }

    private function getCanvasLayerTitle(): string
    {
        $map = $this->getSelectedMap();
        if ($map === null || $map->isLegacyMap() || $this->editingMode === self::MODE_NPC) {
            return '';
        }
        foreach ($this->getTerminalCanvasLayers() as $layer) {
            if ($layer['id'] === $this->getActiveCanvasLayer()) {
                return ' [' . $layer['name'] . ']';
            }
        }
        return '';
    }

    private function setCanvasLayerState(string $key, mixed $value): void
    {
        $this->canvasLayerStates[$this->getSelectedMap()?->mapId ?? ''][$key] = $value;
        $this->renderCanvasArea();
    }

    private function selectCanvasLayer(string $id): void
    {
        if (! in_array($id, array_column($this->getTerminalCanvasLayers(), 'id'), true)) {
            $this->setStatus('Graphical layers are preserved separately and are not editable in the terminal canvas.', StatusLevel::WARN);
            return;
        }
        $this->finalizeActiveStroke();
        $this->activeMousePaintButton = null;
        $this->lastMousePaintPoint = null;
        $this->canvasToolAnchor = null;
        $this->canvasSelection = null;
        $this->facadeBrush = null;
        $this->piecePlacement = null;
        $this->setCanvasLayerState('selected', $id);
        $this->setEditingMode($id === MapLayers::EVENT ? self::MODE_EVENT : self::MODE_MAP);
    }

    /**
     * Opens the layer picker: the map's gameplay layers and Events, filterable
     * by name, with the active layer selected. Enter makes the highlighted
     * layer the one the canvas edits.
     */
    private function openCanvasLayerPicker(): void
    {
        $layers = $this->getTerminalCanvasLayers();
        if ($layers === []) {
            return;
        }
        $visibility = $this->getCanvasLayerState()['visibility'];
        $active = $this->getActiveCanvasLayer();
        $entries = array_map(fn(array $layer): array => [
            'label' => $layer['id'] === MapLayers::EVENT ? 'Events' : $layer['name'],
            'value' => $layer['id'],
            'description' => implode(' · ', array_filter([
                $layer['id'] === MapLayers::EVENT ? 'event markers' : 'gameplay',
                ($visibility[$layer['id']] ?? true) ? 'visible' : 'hidden',
                $layer['id'] === $active ? 'editing' : null,
            ])),
        ], $layers);
        $this->finalizeActiveStroke();
        $this->optionDialogField = ['canvasLayer' => true];
        $this->eventOptionDialogMarker = null;
        $this->eventOptionDialogPath = null;
        $this->eventOptionDialogTitle = 'Layer';
        $this->eventOptionDialogEntries = $entries;
        $this->selectedEventOptionIndex = $this->resolveEventOptionSelectionIndex($active);
        $this->isEventOptionDialogOpen = true;
        $this->statusMessage = 'Choose the layer to edit.';
        $this->renderSelectionDependentArea();
    }

    private function cycleCanvasLayer(int $step = 1): void
    {
        $ids = array_column($this->getTerminalCanvasLayers(), 'id');
        if ($ids === []) {
            return;
        }
        $index = (int) array_search($this->getActiveCanvasLayer(), $ids, true);
        $this->selectCanvasLayer($ids[($index + $step + count($ids)) % count($ids)]);
    }

    private function toggleCanvasLayerVisibility(?string $id = null): void
    {
        $id ??= $this->getActiveCanvasLayer();
        if (! in_array($id, array_column($this->getTerminalCanvasLayers(), 'id'), true)) {
            return;
        }
        $visibility = $this->getCanvasLayerState()['visibility'];
        $visibility[$id] = ! ($visibility[$id] ?? true);
        $this->setCanvasLayerState('visibility', $visibility);
    }

    private function toggleCanvasLayerOption(string $option): void
    {
        if ($option !== 'dim') {
            return;
        }
        $this->finalizeActiveStroke();
        $this->inputMode = self::INPUT_NORMAL;
        $this->setCanvasLayerState($option, ! $this->getCanvasLayerState()[$option]);
    }

    private function buildLayerPaletteItems(): array
    {
        $map = $this->getSelectedMap();
        if ($map === null || $map->getGridSourceIssue() !== null) {
            return [];
        }
        // Choosing a layer has its own picker (L); the palette keeps one entry
        // for it instead of a Layer and a Visibility entry per layer.
        $items = [
            new PaletteItem('Layers: Choose the layer to edit', 'L', fn() => $this->openCanvasLayerPicker()),
            new PaletteItem('Layers: Create gameplay layer', '', fn() => $this->openLayerPrompt('create')),
            new PaletteItem('Layers: Rename selected layer', '', fn() => $this->openLayerPrompt('rename')),
            new PaletteItem('Layers: Remove selected layer', '', fn() => $this->openLayerPrompt('remove')),
            new PaletteItem('Layers: Dim inactive layers', 'd', fn() => $this->toggleCanvasLayerOption('dim')),
        ];
        foreach (FacadeCatalogue::discover($this->workspace->projectRoot) as $path) {
            try {
                foreach (FacadeCatalogue::load($path) as $index => $rows) {
                    $items[] = new PaletteItem(sprintf('Facade: %s #%d (%dx%d)', basename($path), $index + 1,
                        max(array_map(count(...), $rows)), count($rows)), '', fn() => $this->selectFacadeBrush($path, $index));
                }
            } catch (\Throwable $error) {
                $items[] = new PaletteItem('Facade: ' . basename($path) . ' (unreadable)', '', fn() => $this->setStatus($error->getMessage(), StatusLevel::WARN));
            }
        }
        return $items;
    }

    private function openLayerPrompt(string $action): void
    {
        $map = $this->getSelectedMap();
        if ($map === null || ! in_array($action, ['create', 'rename', 'remove'], true)) {
            return;
        }
        $this->finalizeActiveStroke();
        $id = $this->getActiveCanvasLayer();
        $layer = array_values(array_filter($this->getTerminalCanvasLayers(), static fn(array $layer): bool => $layer['id'] === $id))[0] ?? null;
        $this->layerPrompt = ['action' => $action, 'id' => $id, 'name' => $action === 'rename' ? ($layer['name'] ?? '') : ''];
        $this->modals->push(Modal::LAYER_EDIT);
        $this->requestFullRender();
    }

    private function handleLayerPromptInput(string $input): void
    {
        if ($input === "\033") {
            $this->layerPrompt = null;
            $this->modals->remove(Modal::LAYER_EDIT);
            $this->requestFullRender();
            return;
        }
        $prompt = $this->layerPrompt;
        if ($prompt === null) {
            return;
        }
        if (! in_array($prompt['action'], ['create', 'rename', 'remove'], true)) {
            $this->setStatus('Only gameplay layers can be managed in the terminal canvas.', StatusLevel::WARN);
            return;
        }
        if (($input === "\r" || $input === "\n") && $prompt['action'] !== 'remove' && ! isset($prompt['confirmation'])
            || ($input === 'y' && ($prompt['action'] === 'remove' || isset($prompt['confirmation'])))) {
            $map = $this->getSelectedMap();
            try {
                if ($map === null || ! in_array($prompt['id'], array_column($this->getTerminalCanvasLayers(), 'id'), true)) {
                    throw new MapSourceRefusal('This layer is not available in the terminal canvas. Cancel and select a gameplay layer.');
                }
                $before = $map->captureLayerSnapshot();
                if ($prompt['action'] === 'rename' && ! isset($prompt['confirmation'])) {
                    $change = $map->getLayerRenameCollisionChange($prompt['id'], $prompt['name']);
                    if ($change !== null) {
                        $this->layerPrompt['confirmation'] = $change;
                        $this->requestFullRender();
                        return;
                    }
                }
                $id = match ($prompt['action']) {
                    'create' => $map->createLayer($prompt['name']),
                    'rename' => (function () use ($map, $prompt): string {
                        $map->renameLayer($prompt['id'], $prompt['name'], isset($prompt['confirmation']));
                        return $prompt['id'];
                    })(),
                    'remove' => (function () use ($map, $prompt): string {
                        $map->removeLayer($prompt['id']);
                        return $map->getBaseLayerId();
                    })(),
                };
                $after = $map->captureLayerSnapshot();
                $this->recordCommand(new GenericCommand('Layer ' . $prompt['action'],
                    fn() => $map->restoreLayerSnapshot($after), fn() => $map->restoreLayerSnapshot($before)));
                $this->selectCanvasLayer($id);
                $this->layerPrompt = null;
                $this->modals->remove(Modal::LAYER_EDIT);
                $this->setStatus('Layer change staged. Ctrl+S saves the complete file set.');
            } catch (\Throwable $error) {
                $this->setStatus($error->getMessage(), StatusLevel::WARN);
            }
        } elseif (isset($prompt['confirmation'])) {
            return;
        } elseif ($input === "\177" || $input === "\010") {
            $this->layerPrompt['name'] = mb_substr($prompt['name'], 0, -1);
        } elseif (preg_match('/\A[a-zA-Z0-9_-]\z/', $input) === 1) {
            $this->layerPrompt['name'] .= $input;
        }
        $this->requestFullRender();
    }

    private function renderLayerPrompt(array $layout): void
    {
        $width = min(68, $layout['width'] - 4);
        $remove = $this->layerPrompt['action'] === 'remove';
        $confirmation = $this->layerPrompt['confirmation'] ?? null;
        $message = $confirmation ?? ($remove ? 'Remove this layer and its painted cells?' : 'Name: ' . $this->layerPrompt['name']);
        $lines = [...explode("\n", wordwrap($message, max(1, $this->getWindowContentWidth($width)), "\n", true)),
            'Rename keeps its numeric order. Undo restores the layer.',
            'New layers use the next free order; legacy terrain becomes 00.',
            'Collision lookup uses the layer name, not its order.'];
        new EditorWindow(
            title: 'Layer: ' . $this->layerPrompt['action'],
            help: $confirmation !== null ? 'Y:Confirm collision change  Esc:Cancel' : ($remove ? 'Y:Remove  Esc:Cancel' : 'Enter:Apply  Esc:Cancel'),
            position: ['x' => max(2, intdiv($layout['width'] - $width, 2)), 'y' => 6],
            width: $width, height: count($lines) + 2,
            content: $this->fitLines($lines, $this->getWindowContentWidth($width), count($lines)),
        )->render();
    }

    private function selectFacadeBrush(string $path, int $index): void
    {
        foreach ($this->getTerminalCanvasLayers() as $layer) {
            if ($layer['name'] === 'buildings') {
                $this->selectCanvasLayer($layer['id']);
                $this->facadeBrush = ['path' => $path, 'index' => $index, 'mapId' => $this->getSelectedMap()->mapId];
                $this->canvasTool = CanvasTool::BRUSH;
                $this->inputMode = self::INPUT_NORMAL;
                $this->focusedPane = self::FOCUS_CANVAS;
                $this->setStatus('Facade brush selected. Enter stamps; i enables mouse painting; b returns to glyphs.');
                return;
            }
        }
        $this->setStatus('Create a gameplay buildings layer before selecting a facade brush.', StatusLevel::WARN);
    }

    private function stampFacadeBrush(): void
    {
        if ($this->facadeBrush === null) {
            return;
        }
        $map = $this->getSelectedMap();
        $target = array_values(array_filter($this->getTerminalCanvasLayers(),
            fn(array $layer): bool => $layer['id'] === $this->getActiveCanvasLayer()))[0] ?? null;
        if ($map?->mapId !== $this->facadeBrush['mapId'] || $this->editingMode !== self::MODE_MAP
            || ($target['name'] ?? null) !== 'buildings' || ($target['decoration'] ?? true)) {
            $this->facadeBrush = null;
            $this->setStatus('Select the facade brush again on a gameplay buildings layer.', StatusLevel::WARN);
            return;
        }
        try {
            $rows = FacadeCatalogue::load($this->facadeBrush['path'])[$this->facadeBrush['index']] ?? null;
            if ($rows === null) {
                throw new \RuntimeException('This facade no longer exists in its catalogue. Select it again.');
            }
            $writes = [];
            foreach ($rows as $y => $row) {
                foreach ($row as $x => $cell) {
                    $writes[] = ['x' => $this->cursorX + $x, 'y' => $this->cursorY + $y,
                        'symbol' => $cell['symbol'], 'style' => ['prefix' => $cell['prefix'], 'suffix' => $cell['suffix']]];
                }
            }
            $this->applyCanvasWrites($map, $writes, 'Facade stamp');
            $this->renderCanvasArea();
        } catch (\Throwable $error) {
            $this->setStatus($error->getMessage(), StatusLevel::WARN);
        }
    }

    private function getLayerInspectorFields(): array
    {
        $map = $this->getSelectedMap();
        if ($map === null) {
            return [];
        }
        $id = $this->getActiveCanvasLayer();
        $fields = [];
        foreach ($this->getTerminalCanvasLayers() as $layer) {
            $fields[] = ['label' => ($id === $layer['id'] ? '> ' : '  ') . $layer['name'],
                'value' => ($this->getCanvasLayerState()['visibility'][$layer['id']] ?? true) ? 'visible' : 'hidden', 'editable' => false];
        }
        return $fields;
    }
}
