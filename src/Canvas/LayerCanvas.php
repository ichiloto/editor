<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\EditorWindow;
use Ichiloto\Editor\History\GenericCommand;
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
    private string $canvasPaintWarning = '';

    private function recordCanvasCropWarning(\Ichiloto\Editor\ProjectMap $map, string $symbol, string $oldSymbol): void
    {
        try {
            $symbols = $map->getLayerTiles2d($this->getActiveCanvasLayer())['symbols'] ?? [];
            if (array_key_exists($symbol, $symbols) || array_key_exists($oldSymbol, $symbols)) {
                $this->canvasPaintWarning = 'Crop mapping on this layer: painted glyph affects graphical artwork (tiles2d is read-only).';
            }
        } catch (\Throwable $error) {
            $this->canvasPaintWarning = $error->getMessage();
        }
    }

    private function getCanvasLayerState(): array
    {
        $map = $this->getSelectedMap();
        $state = $this->canvasLayerStates[$map?->mapId ?? ''] ?? [];
        return $state + ['selected' => $map?->getBaseLayerId() ?? MapLayers::BASE,
            'visibility' => [], 'dim' => false, 'terminal' => false];
    }

    private function getCanvasLayerTitle(): string
    {
        $map = $this->getSelectedMap();
        if ($map === null || $map->isLegacyMap() || $this->editingMode === self::MODE_NPC) {
            return '';
        }
        foreach ($map->getLayers() as $layer) {
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
        $this->finalizeActiveStroke();
        $this->activeMousePaintButton = null;
        $this->lastMousePaintPoint = null;
        $this->canvasToolAnchor = null;
        $this->canvasSelection = null;
        $this->facadeBrush = null;
        $this->canvasPaintWarning = '';
        $this->setCanvasLayerState('selected', $id);
        $this->setCanvasLayerState('terminal', false);
        $this->setEditingMode($id === MapLayers::EVENT ? self::MODE_EVENT : self::MODE_MAP);
    }

    private function cycleCanvasLayer(int $step = 1): void
    {
        $ids = array_column($this->getSelectedMap()?->getLayers() ?? [], 'id');
        if ($ids === []) {
            return;
        }
        $index = (int) array_search($this->getActiveCanvasLayer(), $ids, true);
        $this->selectCanvasLayer($ids[($index + $step + count($ids)) % count($ids)]);
    }

    private function toggleCanvasLayerVisibility(?string $id = null): void
    {
        $id ??= $this->getActiveCanvasLayer();
        $visibility = $this->getCanvasLayerState()['visibility'];
        $visibility[$id] = ! ($visibility[$id] ?? true);
        $this->setCanvasLayerState('visibility', $visibility);
    }

    private function toggleCanvasLayerOption(string $option): void
    {
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
        $items = [
            new PaletteItem('Layers: Create gameplay layer', '', fn() => $this->openLayerPrompt('create')),
            new PaletteItem('Layers: Create decoration layer', '', fn() => $this->openLayerPrompt('decoration')),
            new PaletteItem('Layers: Rename selected layer', '', fn() => $this->openLayerPrompt('rename')),
            new PaletteItem('Layers: Remove selected layer', '', fn() => $this->openLayerPrompt('remove')),
            new PaletteItem('Layers: Terminal preview', 't', fn() => $this->toggleCanvasLayerOption('terminal')),
            new PaletteItem('Layers: Dim inactive layers', 'd', fn() => $this->toggleCanvasLayerOption('dim')),
        ];
        foreach ($map->getLayers() as $layer) {
            $label = sprintf('%s %s (%s)', $layer['order'] === null ? '--' : sprintf('%02d', $layer['order']),
                $layer['name'], $layer['id'] === MapLayers::EVENT ? 'events' : ($layer['decoration'] ? 'decoration' : 'gameplay'));
            $items[] = new PaletteItem('Layer: ' . $label, '', fn() => $this->selectCanvasLayer($layer['id']));
            $items[] = new PaletteItem('Visibility: ' . $label, '', fn() => $this->toggleCanvasLayerVisibility($layer['id']));
        }
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
        if ($map === null) {
            return;
        }
        $this->finalizeActiveStroke();
        $id = $this->getActiveCanvasLayer();
        $layer = array_values(array_filter($map->getLayers(), static fn(array $layer): bool => $layer['id'] === $id))[0] ?? null;
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
        if (($input === "\r" || $input === "\n") && $prompt['action'] !== 'remove' && ! isset($prompt['confirmation'])
            || ($input === 'y' && ($prompt['action'] === 'remove' || isset($prompt['confirmation'])))) {
            $map = $this->getSelectedMap();
            $before = $map->captureLayerSnapshot();
            try {
                if ($prompt['action'] === 'rename' && ! isset($prompt['confirmation'])) {
                    $change = $map->getLayerRenameCollisionChange($prompt['id'], $prompt['name']);
                    if ($change !== null) {
                        $this->layerPrompt['confirmation'] = $change;
                        $this->requestFullRender();
                        return;
                    }
                }
                $id = match ($prompt['action']) {
                    'create', 'decoration' => $map->createLayer($prompt['name'], $prompt['action'] === 'decoration'),
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
        foreach ($this->getSelectedMap()?->getLayers() ?? [] as $layer) {
            if ($layer['name'] === 'buildings' && ! $layer['decoration']) {
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
        $target = array_values(array_filter($map?->getLayers() ?? [],
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
        foreach ($map->getLayers() as $layer) {
            $fields[] = ['label' => ($id === $layer['id'] ? '> ' : '  ') . $layer['name'],
                'value' => ($this->getCanvasLayerState()['visibility'][$layer['id']] ?? true) ? 'visible' : 'hidden', 'editable' => false];
        }
        try {
            $tiles = $map->getLayerTiles2d($id);
        } catch (\Throwable $error) {
            return [...$fields, ['label' => 'tiles2d error', 'value' => $error->getMessage(), 'editable' => false]];
        }
        $fields[] = ['label' => 'tiles2d (read-only)', 'value' => (string) ($tiles['asset'] ?? '(no atlas)'), 'editable' => false];
        foreach ($tiles['symbols'] ?? [] as $symbol => $crop) {
            $fields[] = ['label' => '  ' . $symbol, 'value' => json_encode($crop, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'editable' => false];
        }
        return $fields;
    }
}
