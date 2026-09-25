<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

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

/** Staged NPC artwork, with roles selected before opening the shared PNG picker. */
trait NpcSpriteCanvas
{
    private const string NPC_ART_FIELD = '__npc_art';
    private ?array $npcArtDialog = null;
    private ?TextFieldEditor $npcArtFieldEditor = null;

    private function openNpcSpriteArt(): void
    {
        $map = $this->getSelectedMap();
        $index = $this->selectedNpcIndex;
        $npc = $index === null ? null : $map?->getNpcs()->get($index);
        if ($npc === null || $this->referencePicker->isOpen()) { return; }
        $data = $npc->toArray()['sprites2d'] ?? null;
        if ($data !== null && ! is_array($data)) {
            $this->setStatus('NPC sprites2d is not an array. Repair its source before authoring art.', StatusLevel::WARN);
            return;
        }
        $this->npcArtDialog = ['map' => $map, 'index' => $index, 'initial' => $npc->toArray(),
            'draft' => new DirectionalSpriteDraft($data), 'direction' => 'south', 'selected' => 0,
            'confirmation' => false, 'error' => ''];
        $this->npcArtFieldEditor = new TextFieldEditor();
        $this->modals->push(Modal::NPC_ART);
        $this->requestFullRender();
    }

    private function closeNpcSpriteArt(): void
    {
        $this->referencePicker->close();
        $this->npcArtDialog = null;
        $this->npcArtFieldEditor?->close();
        $this->modals->remove(Modal::NPC_ART);
        $this->requestFullRender();
    }

    private function getNpcArtFields(): array
    {
        $dialog = $this->npcArtDialog;
        return [
            ['field' => 'mode', 'label' => 'Representation', 'value' => $dialog['draft']->getMode(), 'options' => ['poses', 'sheet']],
            ['field' => 'direction', 'label' => 'Direction / role', 'value' => $dialog['direction'], 'options' => ProjectNpc::DIRECTIONS],
            ...$dialog['draft']->getFields($dialog['direction']),
            ['field' => 'apply', 'label' => 'Apply all four directions', 'value' => ''],
            ['field' => 'remove', 'label' => 'Remove graphical sprites', 'value' => ''],
        ];
    }

    private function handleNpcArtInput(string $input): void
    {
        if ($this->npcArtDialog === null) { return; }
        try {
            $fields = $this->getNpcArtFields();
            $field = $fields[$this->npcArtDialog['selected']];
            if ($this->referencePicker->isOpen()) {
                if ($input === "\033") { $this->referencePicker->close(); }
                elseif ($input === "\r" || $input === "\n") {
                    if (($asset = $this->referencePicker->selected()) !== null) {
                        $this->npcArtDialog['draft']->setField($this->npcArtDialog['direction'], $field['field'], $asset);
                        $this->referencePicker->close();
                    }
                } elseif ($input === "\033[A") { $this->referencePicker->move(-1); }
                elseif ($input === "\033[B") { $this->referencePicker->move(1); }
                elseif ($input === "\177" || $input === "\010") { $this->referencePicker->backspace(); }
                elseif (mb_strlen($input) === 1 && ! ctype_cntrl($input)) { $this->referencePicker->type($input); }
            } elseif ($this->npcArtFieldEditor?->isActive) {
                $result = $this->npcArtFieldEditor->handleKey($input, new InputControl(InputControlType::INTEGER));
                if ($result === TextFieldKeyResult::CANCELLED) { $this->npcArtFieldEditor->close(); }
                elseif ($result === TextFieldKeyResult::SUBMITTED) {
                    $this->npcArtDialog['draft']->setField($this->npcArtDialog['direction'], $field['field'], $this->npcArtFieldEditor->value);
                    $this->npcArtFieldEditor->close();
                }
            } elseif ($this->npcArtDialog['confirmation']) {
                if ($input === "\033") { $this->npcArtDialog['confirmation'] = false; }
                elseif ($input === 'y') { $this->commitNpcSpriteArt(true); }
            } elseif ($input === "\033") { $this->closeNpcSpriteArt(); }
            elseif ($input === "\033[A" || $input === "\033[Z") {
                $this->npcArtDialog['selected'] = ($this->npcArtDialog['selected'] + count($fields) - 1) % count($fields);
            } elseif ($input === "\033[B" || $input === "\t") {
                $this->npcArtDialog['selected'] = ($this->npcArtDialog['selected'] + 1) % count($fields);
            } elseif ($input === 's') { $this->commitNpcSpriteArt(); }
            elseif ($input === 'r') { $this->npcArtDialog['confirmation'] = true; }
            elseif (in_array($input, ["\r", "\n", "\033[C", "\033[D"], true)) {
                if (isset($field['options'])) {
                    $options = $field['options'];
                    $index = array_search($field['value'], $options, true);
                    $value = $options[((int) $index + ($input === "\033[D" ? count($options) - 1 : 1)) % count($options)];
                    if ($field['field'] === 'direction') { $this->npcArtDialog['direction'] = $value; }
                    elseif ($field['field'] === 'mode') { $this->npcArtDialog['draft']->setMode($value); }
                    else { $this->npcArtDialog['draft']->setField($this->npcArtDialog['direction'], $field['field'], $value); }
                } elseif (isset($field['reference'])) {
                    if (! $this->referencePicker->open('npc_art_asset', $field['label'], 'png_assets',
                        $this->referenceCatalog()->valuesFor('png_assets'), $field['value'])) {
                        throw new MapSourceRefusal('No PNG assets found inside this project.');
                    }
                } elseif ($field['field'] === 'apply') { $this->commitNpcSpriteArt(); }
                elseif ($field['field'] === 'remove') { $this->npcArtDialog['confirmation'] = true; }
                else { $this->npcArtFieldEditor->open($field['value']); }
            }
        } catch (\Throwable $error) {
            $this->npcArtDialog['error'] = $error->getMessage();
        }
        $this->requestFullRender();
    }

    private function commitNpcSpriteArt(bool $remove = false): void
    {
        $dialog = $this->npcArtDialog;
        $map = $dialog['map'];
        if ($map !== $this->getSelectedMap() || $map->getNpcs()->get($dialog['index'])?->toArray() !== $dialog['initial']) {
            throw new MapSourceRefusal('The captured map or NPC changed. Cancel and reopen artwork before applying.');
        }
        $before = $map->captureLayerSnapshot();
        $map->setNpcGraphicalSprites($dialog['index'], $remove ? null : $dialog['draft']->getData());
        $after = $map->captureLayerSnapshot();
        if ($before !== $after) {
            $this->recordCommand(new GenericCommand($remove ? 'Remove NPC graphical sprites' : 'NPC graphical sprites',
                function () use ($map, $after, $dialog): void { $map->restoreLayerSnapshot($after); $this->selectNpc($dialog['index']); },
                function () use ($map, $before, $dialog): void { $map->restoreLayerSnapshot($before); $this->selectNpc($dialog['index']); }));
        }
        $this->refreshNpcInspector();
        $this->closeNpcSpriteArt();
        $this->setStatus('NPC artwork staged. Ctrl+S saves; Ctrl+Z undoes. Terminal sprite and interaction stay unchanged.', StatusLevel::SUCCESS);
    }

    private function renderNpcArtDialog(array $layout): void
    {
        $dialog = $this->npcArtDialog;
        $width = min(82, $layout['width'] - 4);
        $inner = $this->getWindowContentWidth($width);
        $lines = [sprintf('%s / %s / %s', $dialog['map']->mapId, $dialog['initial']['id'] ?? $dialog['initial']['name'], $dialog['direction'])];
        $help = 'Arrows:Field Enter:Edit/Choose S:Apply R:Remove Esc:Cancel';
        if ($this->referencePicker->isOpen()) {
            $lines[] = 'Choose ' . $dialog['direction'] . ' PNG: ' . $this->referencePicker->filter();
            $choices = array_map(fn(string $path): string => ($path === $this->referencePicker->selected() ? '> ' : '  ') . $path, $this->referencePicker->matches());
            $lines = [...$lines, ...ScrollWindow::slice($choices, $this->referencePicker->selectedIndex(), 10)];
            if ($choices === []) { $lines[] = 'No matching PNG assets.'; }
            $help = 'Type:Filter Arrows:Choose Enter:Select Esc:Back';
        } elseif ($dialog['confirmation']) {
            $lines[] = 'Remove this NPC\'s complete sprites2d set?';
            $lines[] = 'Terminal glyphs, dialogue, identity and collision stay unchanged.';
            $help = 'Y:Confirm removal Esc:Back';
        } else {
            $rows = [];
            foreach ($this->getNpcArtFields() as $index => $field) {
                $button = in_array($field['field'], ['apply', 'remove'], true);
                $selected = $dialog['selected'] === $index;
                $value = $selected && $this->npcArtFieldEditor?->isActive ? $this->npcArtFieldEditor->value . '|' : ($field['value'] === '' ? '(not set)' : $field['value']);
                $line = $button ? str_pad($field['label'], $inner, ' ', STR_PAD_BOTH) : ($selected ? '> ' : '  ') . $field['label'] . ': ' . $value;
                $rows[] = $button && $selected ? "\033[7m" . $line . "\033[0m" : $line;
            }
            $lines = [...$lines, ...ScrollWindow::slice($rows, $dialog['selected'], max(3, $layout['height'] - 12))];
            $lines[] = 'All four directions need PNG + logical width/height.';
            $lines[] = $dialog['draft']->getMode() === 'sheet' ? 'Size/timing/anchor are shared; asset/grid/frame count belong to each role.' : 'Crop is optional: leave all four crop fields blank to use the whole PNG.';
        }
        if ($dialog['error'] !== '') { $lines = [...$lines, ...explode("\n", wordwrap($dialog['error'], $inner, "\n", true))]; }
        $height = min(count($lines) + 2, $layout['height'] - 4);
        new EditorWindow(title: 'NPC graphical sprites', help: $help,
            position: ['x' => max(2, intdiv($layout['width'] - $width, 2)), 'y' => 3], width: $width,
            height: $height, content: $this->fitLines($lines, $inner, $height - 2))->render();
    }
}
