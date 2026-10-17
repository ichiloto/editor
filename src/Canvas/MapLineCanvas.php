<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\EditorWindow;
use Ichiloto\Editor\History\SourceSetCommand;
use Ichiloto\Editor\Inspector\InputControl;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\Maps\LineRemoval;
use Ichiloto\Editor\Maps\MapLinePlan;
use Ichiloto\Editor\Maps\MapLinePlanner;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\Modal;
use Ichiloto\Editor\UI\PaletteItem;
use Ichiloto\Editor\UI\TextFieldEditor;
use Ichiloto\Editor\UI\TextFieldKeyResult;
use Throwable;

/**
 * Inserting blank rows above the cursor or columns left of it, and removing
 * rows from the cursor down or columns from it rightward. The count is asked
 * for in the shared numeric field; the plan then lists every file it writes,
 * every coordinate it could not rewrite and, for a removal, where saves on
 * the removed lines land, and writes them all at once on confirmation, as
 * one undo step that restores every file.
 */
trait MapLineCanvas
{
    /** @var array{axis: string, at: int, mapId: string, remove: bool}|null */
    private ?array $mapLinePrompt = null;
    private ?TextFieldEditor $mapLineCount = null;

    private function buildMapLinePaletteItems(): array
    {
        $map = $this->getSelectedMap();
        if ($map === null || $map->getGridSourceIssue() !== null || $this->editingMode !== self::MODE_MAP) {
            return [];
        }

        return [
            new PaletteItem('Map: Insert rows above the cursor', '', fn() => $this->openMapLinePrompt('y', false)),
            new PaletteItem('Map: Insert columns left of the cursor', '', fn() => $this->openMapLinePrompt('x', false)),
            new PaletteItem('Map: Remove rows from the cursor down', '', fn() => $this->openMapLinePrompt('y', true)),
            new PaletteItem('Map: Remove columns from the cursor rightward', '', fn() => $this->openMapLinePrompt('x', true)),
        ];
    }

    private function openMapLinePrompt(string $axis, bool $remove): void
    {
        $map = $this->getSelectedMap();
        if ($map === null || ! $this->canEditMapLines($remove)) {
            return;
        }
        $this->finalizeActiveStroke();
        $this->mapLinePrompt = ['axis' => $axis, 'at' => $axis === 'y' ? $this->cursorY : $this->cursorX, 'mapId' => $map->mapId, 'remove' => $remove];
        $this->mapLineCount = new TextFieldEditor();
        $this->mapLineCount->open('1');
        $this->modals->push(Modal::MAP_LINES);
        $this->requestFullRender();
    }

    /** Refuses while other edits are unsaved: the edit writes now, and undo restores files. */
    private function canEditMapLines(bool $remove): bool
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return false;
        }
        if ($this->workspace->hasUnsavedChanges()) {
            $this->setStatus(sprintf('Save or undo pending edits before %s rows or columns. No files were changed.', $remove ? 'removing' : 'inserting'), StatusLevel::WARN);
            return false;
        }

        return true;
    }

    private function closeMapLinePrompt(): void
    {
        $this->mapLinePrompt = null;
        $this->mapLineCount = null;
        $this->modals->remove(Modal::MAP_LINES);
        $this->requestFullRender();
    }

    private function handleMapLinePromptInput(string $input): void
    {
        $prompt = $this->mapLinePrompt;
        if ($prompt === null || $this->mapLineCount === null) {
            $this->closeMapLinePrompt();
            return;
        }
        $control = new InputControl(InputControlType::INTEGER, $this->mapLineCount->value);
        switch ($this->mapLineCount->handleKey($input, $control)) {
            case TextFieldKeyResult::CANCELLED:
                $this->closeMapLinePrompt();
                $this->setStatus(sprintf('%s cancelled. No files were changed.', $prompt['remove'] ? 'Removal' : 'Insertion'));
                return;
            case TextFieldKeyResult::SUBMITTED:
                $count = trim($this->mapLineCount->value);
                if (! ctype_digit($count) || (int) $count < 1) {
                    $this->setStatus('Enter a whole number of at least 1.', StatusLevel::WARN);
                    return;
                }
                $this->closeMapLinePrompt();
                $this->openMapLineConfirmation($prompt['axis'], $prompt['at'], (int) $count, $prompt['remove']);
                return;
            default:
                $this->requestFullRender();
        }
    }

    private function renderMapLinePrompt(array $layout): void
    {
        $prompt = $this->mapLinePrompt;
        if ($prompt === null || $this->mapLineCount === null) {
            return;
        }
        $width = min(68, $layout['width'] - 4);
        $rows = $prompt['axis'] === 'y';
        $line = sprintf('%s %d', $rows ? 'row' : 'column', $prompt['at'] + 1);
        $lines = $prompt['remove']
            ? [
                sprintf('%s to remove from %s %s: %s', $rows ? 'Rows' : 'Columns', $line, $rows ? 'down' : 'rightward', $this->mapLineCount->value),
                sprintf('Everything beyond them moves %s and the map shrinks.', $rows ? 'up' : 'left'),
                'Anything authored on a removed line refuses the removal.',
                'The next step lists every file before anything is written.',
            ]
            : [
                sprintf('%s to insert %s %s: %s', $rows ? 'Rows' : 'Columns', $rows ? 'above' : 'left of', $line, $this->mapLineCount->value),
                sprintf('Everything from %s %s moves and the map grows.', $line, $rows ? 'down' : 'right'),
                'Spawn points, scripts and saves that name this map follow.',
                'The next step lists every file before anything is written.',
            ];
        new EditorWindow(
            title: ($prompt['remove'] ? 'Remove ' : 'Insert ') . ($rows ? 'rows' : 'columns'),
            help: 'Enter:Plan  Up/Down:Count  Esc:Cancel',
            position: ['x' => max(2, intdiv($layout['width'] - $width, 2)), 'y' => 6],
            width: $width, height: count($lines) + 2,
            content: $this->fitLines($lines, $this->getWindowContentWidth($width), count($lines)),
        )->render();
    }

    /**
     * Plans the edit and lists what it writes, and what it could not
     * rewrite, for confirmation. Nothing is written yet.
     */
    private function openMapLineConfirmation(string $axis, int $at, int $count, bool $remove): void
    {
        $map = $this->getSelectedMap();
        if ($map === null || ! $this->canEditMapLines($remove)) {
            return;
        }
        try {
            $plan = $remove
                ? MapLinePlanner::planRemoval($this->projectRoot, $map->mapId, $axis, $at, $count)
                : MapLinePlanner::planInsertion($this->projectRoot, $map->mapId, $axis, $at, $count);
        } catch (Throwable $failure) {
            $this->setErrorStatus($failure, $remove ? 'Removal' : 'Insertion');
            return;
        }
        $edit = $plan->edit;
        $paths = array_map(fn(string $path): string => substr($path, strlen($this->projectRoot) + 1), $plan->getChangedPaths());
        $this->optionDialogField = ['mapLines' => $plan];
        $this->eventOptionDialogMarker = null;
        $this->eventOptionDialogPath = null;
        $this->eventOptionDialogTitle = $edit->verb . ' ' . $edit->description;
        $this->eventOptionDialogEntries = [
            ['label' => 'Cancel', 'value' => 'cancel', 'description' => 'Leave all files unchanged.'],
            ['label' => sprintf('Write %d files now', count($paths)), 'value' => 'write',
                'description' => 'Writes now, not on Save. Ctrl+Z restores files.'],
            ...array_map(static fn(string $path): array => [
                'label' => $path, 'value' => 'preview',
                'description' => sprintf('Written by the %s. Select Write to apply all files.', $remove ? 'removal' : 'insertion'),
            ], $paths),
            ...array_map(static fn(array $edit): array => [
                'label' => sprintf('Hand edit: %s %s', $edit['file'], $edit['where']), 'value' => 'preview',
                'description' => sprintf('Not rewritten: %s. Move it by hand after writing.', $edit['reason']),
            ], $plan->handEdits),
            ...array_map(static fn(string $note): array => [
                'label' => $note, 'value' => 'preview', 'description' => $note,
            ], $plan->notes),
        ];
        $this->selectedEventOptionIndex = 0;
        $this->dialogFilter->clear();
        $this->isEventOptionDialogOpen = true;
        $this->setStatus(sprintf('Confirm %s %s. Writes now, not on Save. Ctrl+Z restores files.', $remove ? 'removing' : 'inserting', $edit->description));
        $this->renderSelectionDependentArea();
    }

    private function confirmMapLines(MapLinePlan $plan, bool $confirmed): void
    {
        $remove = $plan->edit instanceof LineRemoval;
        if (! $confirmed || ! $this->workspace instanceof ProjectWorkspace) {
            $this->closeEventOptionDialog();
            $this->setStatus(sprintf('%s cancelled. No files were changed.', $remove ? 'Removal' : 'Insertion'));
            return;
        }
        try {
            $command = new SourceSetCommand($plan->edit->verb . ' ' . $plan->edit->noun, $remove ? 'this removal' : 'this insertion', $plan->getSourceSet(), $this->workspace,
                fn(): ?ProjectWorkspace => $this->workspace,
                function (ProjectWorkspace $workspace): void { $this->workspace = $workspace; },
            );
            $command->execute();
            $this->recordCommand($command);
            $handEdits = count($plan->handEdits);
            $this->closeEventOptionDialog();
            // The files are on disk now, so the result reads as saved.
            $this->setStatus(sprintf('%s %s.%s Ctrl+Z restores the files.', $remove ? 'Removed' : 'Inserted', $plan->edit->description,
                $handEdits === 0 ? '' : sprintf(' %d %s still need a hand edit.', $handEdits, $handEdits === 1 ? 'coordinate' : 'coordinates')), StatusLevel::SUCCESS);
            $this->requestFullRender();
        } catch (Throwable $failure) {
            $this->closeEventOptionDialog('');
            $this->setErrorStatus($failure, $remove ? 'Removal' : 'Insertion');
        }
    }
}
