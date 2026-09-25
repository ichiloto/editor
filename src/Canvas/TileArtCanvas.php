<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\EditorWindow;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\Inspector\InputControl;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\Modal;
use Ichiloto\Editor\UI\ScrollWindow;
use Ichiloto\Editor\UI\TextFieldEditor;
use Ichiloto\Editor\UI\TextFieldKeyResult;

/** A staged cell-art form over the existing map, reference picker and undo services. */
trait TileArtCanvas
{
    private ?array $tileArtDialog = null;
    private ?TextFieldEditor $tileArtFieldEditor = null;
    private const array TILE_ART_FIELDS = ['x', 'y', 'width', 'height'];
    private const array TILE_ART_ROWS = ['asset', ...self::TILE_ART_FIELDS, 'apply', 'remove'];
    private const array TILE_ART_DEFAULT_SOURCE = ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 16];

    private function openCellTileArt(): void
    {
        $map = $this->getSelectedMap();
        if ($map === null || $this->referencePicker->isOpen()) { return; }
        try {
            if ($this->getCanvasLayerState()['terminal']) {
                throw new MapSourceRefusal('Leave terminal preview before editing tile art.');
            }
            $this->finalizeActiveStroke();
            $id = $this->getActiveCanvasLayer();
            $art = $map->getCellTileArt($id, $this->cursorX, $this->cursorY);
            $this->tileArtDialog = ['map' => $map, 'layer' => $id, 'column' => $this->cursorX, 'row' => $this->cursorY,
                'initial' => $art, 'asset' => $art['asset'], 'source' => $art['override'] ?? $art['inherited'] ?? self::TILE_ART_DEFAULT_SOURCE,
                'selected' => 0, 'confirmation' => null, 'error' => ''];
            $this->tileArtFieldEditor = new TextFieldEditor();
            $this->modals->push(Modal::TILE_ART);
            $this->requestFullRender();
        } catch (\Throwable $error) {
            $this->setErrorStatus($error, 'Tile art');
        }
    }

    private function closeCellTileArt(): void
    {
        $this->referencePicker->close();
        $this->tileArtFieldEditor?->close();
        $this->tileArtDialog = null;
        $this->modals->remove(Modal::TILE_ART);
        $this->requestFullRender();
    }

    private function openTileArtAssetPicker(): void
    {
        if (! $this->referencePicker->open('tile_art_asset', 'Layer PNG', 'png_assets',
            $this->referenceCatalog()->valuesFor('png_assets'), $this->tileArtDialog['asset'] ?? '')) {
            $this->tileArtDialog['error'] = 'No PNG assets found. Add artwork under this project\'s assets, then choose again.';
        }
    }

    private function handleTileArtInput(string $input): void
    {
        if ($this->tileArtDialog === null) { return; }
        if ($this->referencePicker->isOpen()) {
            if ($input === "\033") { $this->referencePicker->close(); }
            elseif ($input === "\r" || $input === "\n") {
                if (($asset = $this->referencePicker->selected()) !== null) {
                    $this->tileArtDialog['asset'] = $asset;
                    $this->referencePicker->close();
                }
            } elseif ($input === "\033[A") { $this->referencePicker->move(-1); }
            elseif ($input === "\033[B") { $this->referencePicker->move(1); }
            elseif ($input === "\177" || $input === "\010") { $this->referencePicker->backspace(); }
            elseif (mb_strlen($input) === 1 && ! ctype_cntrl($input)) { $this->referencePicker->type($input); }
        } elseif ($this->tileArtFieldEditor?->isActive) {
            $result = $this->tileArtFieldEditor->handleKey($input, new InputControl(InputControlType::INTEGER));
            if ($result === TextFieldKeyResult::CANCELLED) { $this->tileArtFieldEditor->close(); }
            elseif ($result === TextFieldKeyResult::SUBMITTED) {
                $value = filter_var($this->tileArtFieldEditor->value, FILTER_VALIDATE_INT);
                if ($value === false) { $this->tileArtDialog['error'] = 'Enter a whole number within the supported integer range.'; }
                else {
                    $key = self::TILE_ART_ROWS[$this->tileArtDialog['selected']];
                    $this->tileArtDialog['source'][$key] = $value;
                    $this->tileArtDialog['error'] = '';
                    $this->tileArtFieldEditor->close();
                }
            }
        } elseif ($this->tileArtDialog['confirmation'] !== null) {
            if ($input === "\033") { $this->tileArtDialog['confirmation'] = null; }
            elseif ($input === 'y') { $this->commitCellTileArt($this->tileArtDialog['confirmation'], true); }
        } elseif ($input === "\033") {
            $this->closeCellTileArt();
        } elseif ($input === "\033[A" || $input === "\033[Z") {
            $this->tileArtDialog['selected'] = ($this->tileArtDialog['selected'] + count(self::TILE_ART_ROWS) - 1) % count(self::TILE_ART_ROWS);
        } elseif ($input === "\033[B" || $input === "\t") {
            $this->tileArtDialog['selected'] = ($this->tileArtDialog['selected'] + 1) % count(self::TILE_ART_ROWS);
        } elseif ($input === 'a') {
            $this->openTileArtAssetPicker();
        } elseif ($input === 's') {
            $this->commitCellTileArt('apply');
        } elseif ($input === 'r') {
            $this->tileArtDialog['confirmation'] = 'remove';
        } elseif ($input === "\r" || $input === "\n") {
            $key = self::TILE_ART_ROWS[$this->tileArtDialog['selected']];
            if ($key === 'asset') { $this->openTileArtAssetPicker(); }
            elseif (in_array($key, self::TILE_ART_FIELDS, true)) { $this->tileArtFieldEditor->open((string) $this->tileArtDialog['source'][$key]); }
            elseif ($key === 'apply') { $this->commitCellTileArt('apply'); }
            else { $this->tileArtDialog['confirmation'] = 'remove'; }
        }
        $this->requestFullRender();
    }

    private function commitCellTileArt(string $action, bool $confirmed = false): void
    {
        $dialog = $this->tileArtDialog;
        $map = $dialog['map'];
        try {
            if ($map !== $this->getSelectedMap() || $map->getCellTileArt($dialog['layer'], $dialog['column'], $dialog['row']) !== $dialog['initial']) {
                throw new MapSourceRefusal('The captured map, layer or cell art changed. Cancel and reopen Tile art.');
            }
            if ($action === 'apply' && $dialog['initial']['asset'] !== null && $dialog['asset'] !== $dialog['initial']['asset'] && ! $confirmed) {
                $this->tileArtDialog['confirmation'] = 'apply';
                return;
            }
            $before = $map->captureLayerSnapshot();
            if ($action === 'remove') { $map->removeCellTileArt($dialog['layer'], $dialog['column'], $dialog['row']); }
            else { $map->setCellTileArt($dialog['layer'], $dialog['column'], $dialog['row'], $dialog['source'], $dialog['asset'], $confirmed); }
            $after = $map->captureLayerSnapshot();
            if ($before !== $after) {
                $this->recordCommand(new GenericCommand('Cell tile art ' . $action,
                    fn() => $map->restoreLayerSnapshot($after), fn() => $map->restoreLayerSnapshot($before)));
            }
            $this->closeCellTileArt();
            $this->setStatus('Cell tile art staged. Ctrl+S saves; Ctrl+Z undoes.', StatusLevel::SUCCESS);
        } catch (\Throwable $error) {
            $this->tileArtDialog['confirmation'] = null;
            $this->tileArtDialog['error'] = $error->getMessage();
        }
    }

    private function renderTileArtDialog(array $layout): void
    {
        $dialog = $this->tileArtDialog;
        $width = min(76, $layout['width'] - 4);
        $inner = $this->getWindowContentWidth($width);
        $lines = [sprintf('%s / %s / cell %d,%d', $dialog['map']->mapId, $dialog['initial']['layer'], $dialog['column'], $dialog['row'])];
        $help = 'Arrows:Field Enter:Edit A:Atlas S:Apply R:Remove Esc:Cancel';
        if ($this->referencePicker->isOpen()) {
            $lines[] = 'Choose layer PNG: ' . $this->referencePicker->filter();
            $choices = array_map(fn(string $path): string => ($path === $this->referencePicker->selected() ? '> ' : '  ') . $path, $this->referencePicker->matches());
            $lines = [...$lines, ...ScrollWindow::slice($choices, $this->referencePicker->selectedIndex(), 9)];
            if ($choices === []) { $lines[] = 'No matching PNG assets.'; }
            $help = 'Type:Filter Arrows:Choose Enter:Select Esc:Back';
        } elseif ($dialog['confirmation'] !== null) {
            $message = $dialog['confirmation'] === 'remove'
                ? 'Remove only this cell override? Its symbol default (if any) will be used again.'
                : sprintf('Change this layer atlas from %s to %s? Every existing symbol and cell crop on this layer will use the new PNG. Other layers and the shared atlas stay unchanged.', $dialog['initial']['asset'], $dialog['asset']);
            $lines = [...$lines, ...explode("\n", wordwrap($message, $inner, "\n", true))];
            $help = 'Y:Confirm Esc:Back';
        } else {
            $lines[] = 'Glyph: ' . var_export($dialog['initial']['symbol'], true) . ' | ' . ($dialog['initial']['override'] !== null ? 'Cell override' : 'Inherited / unmapped');
            $values = ['Layer atlas: ' . ($dialog['asset'] ?? '(choose PNG)')];
            foreach (self::TILE_ART_FIELDS as $index => $key) {
                $editing = $dialog['selected'] === $index + 1 && $this->tileArtFieldEditor?->isActive;
                $values[] = ucfirst($key) . ': ' . ($editing ? $this->tileArtFieldEditor->value . '|' : $dialog['source'][$key]);
            }
            foreach ([...$values, 'Apply override', 'Remove override'] as $index => $value) {
                $button = in_array(self::TILE_ART_ROWS[$index], ['apply', 'remove'], true);
                $line = $button ? str_pad($value, $inner, ' ', STR_PAD_BOTH) : ($index === $dialog['selected'] ? '> ' : '  ') . $value;
                $lines[] = $button && $index === $dialog['selected'] ? "\033[7m" . $line . "\033[0m" : $line;
            }
            $lines[] = 'Crop pixels: X/Y start at zero; width/height must be positive.';
            $lines[] = 'Only artwork changes. Glyphs and collision stay unchanged.';
        }
        if ($dialog['error'] !== '') { $lines = [...$lines, ...explode("\n", wordwrap($dialog['error'], $inner, "\n", true))]; }
        $height = min(count($lines) + 2, $layout['height'] - 4);
        new EditorWindow(title: 'Tile art: selected cell', help: $help, position: ['x' => max(2, intdiv($layout['width'] - $width, 2)), 'y' => 3],
            width: $width, height: $height, content: $this->fitLines($lines, $inner, $height - 2))->render();
    }
}
