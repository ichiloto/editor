<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\EditorWindow;
use Ichiloto\Editor\History\SourceSetCommand;
use Ichiloto\Editor\Inspector\InputControl;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\Maps\LineInsertionPlan;
use Ichiloto\Editor\Maps\LineInsertionPlanner;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\Modal;
use Ichiloto\Editor\UI\PaletteItem;
use Ichiloto\Editor\UI\TextFieldEditor;
use Ichiloto\Editor\UI\TextFieldKeyResult;
use Throwable;

/**
 * Inserting blank rows above the cursor or columns left of it. The count is
 * asked for in the shared numeric field; the plan then lists every file it
 * writes and every coordinate it could not rewrite, and writes them all at
 * once on confirmation, as one undo step that restores every file.
 */
trait LineInsertCanvas
{
    /** @var array{axis: string, at: int, mapId: string}|null */
    private ?array $lineInsertPrompt = null;
    private ?TextFieldEditor $lineInsertCount = null;

    private function buildLineInsertPaletteItems(): array
    {
        $map = $this->getSelectedMap();
        if ($map === null || $map->getGridSourceIssue() !== null || $this->editingMode !== self::MODE_MAP) {
            return [];
        }

        return [
            new PaletteItem('Map: Insert rows above the cursor', '', fn() => $this->openLineInsertPrompt('y')),
            new PaletteItem('Map: Insert columns left of the cursor', '', fn() => $this->openLineInsertPrompt('x')),
        ];
    }

    private function openLineInsertPrompt(string $axis): void
    {
        $map = $this->getSelectedMap();
        if ($map === null || ! $this->canInsertLines()) {
            return;
        }
        $this->finalizeActiveStroke();
        $this->lineInsertPrompt = ['axis' => $axis, 'at' => $axis === 'y' ? $this->cursorY : $this->cursorX, 'mapId' => $map->mapId];
        $this->lineInsertCount = new TextFieldEditor();
        $this->lineInsertCount->open('1');
        $this->modals->push(Modal::LINE_INSERT);
        $this->requestFullRender();
    }

    /** Refuses while other edits are unsaved: the insertion writes now, and undo restores files. */
    private function canInsertLines(): bool
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return false;
        }
        if ($this->workspace->hasUnsavedChanges()) {
            $this->setStatus('Save or undo pending edits before inserting rows or columns. No files were changed.', StatusLevel::WARN);
            return false;
        }

        return true;
    }

    private function closeLineInsertPrompt(): void
    {
        $this->lineInsertPrompt = null;
        $this->lineInsertCount = null;
        $this->modals->remove(Modal::LINE_INSERT);
        $this->requestFullRender();
    }

    private function handleLineInsertPromptInput(string $input): void
    {
        $prompt = $this->lineInsertPrompt;
        if ($prompt === null || $this->lineInsertCount === null) {
            $this->closeLineInsertPrompt();
            return;
        }
        $control = new InputControl(InputControlType::INTEGER, $this->lineInsertCount->value);
        switch ($this->lineInsertCount->handleKey($input, $control)) {
            case TextFieldKeyResult::CANCELLED:
                $this->closeLineInsertPrompt();
                $this->setStatus('Insertion cancelled. No files were changed.');
                return;
            case TextFieldKeyResult::SUBMITTED:
                $count = trim($this->lineInsertCount->value);
                if (! ctype_digit($count) || (int) $count < 1) {
                    $this->setStatus('Enter a whole number of at least 1.', StatusLevel::WARN);
                    return;
                }
                $this->closeLineInsertPrompt();
                $this->openLineInsertConfirmation($prompt['axis'], $prompt['at'], (int) $count);
                return;
            default:
                $this->requestFullRender();
        }
    }

    private function renderLineInsertPrompt(array $layout): void
    {
        $prompt = $this->lineInsertPrompt;
        if ($prompt === null || $this->lineInsertCount === null) {
            return;
        }
        $width = min(68, $layout['width'] - 4);
        $rows = $prompt['axis'] === 'y';
        $lines = [
            sprintf('%s to insert %s %s %d: %s', $rows ? 'Rows' : 'Columns', $rows ? 'above' : 'left of',
                $rows ? 'row' : 'column', $prompt['at'] + 1, $this->lineInsertCount->value),
            sprintf('Everything from %s %d %s moves and the map grows.', $rows ? 'row' : 'column', $prompt['at'] + 1, $rows ? 'down' : 'right'),
            'Spawn points, scripts and saves that name this map follow.',
            'The next step lists every file before anything is written.',
        ];
        new EditorWindow(
            title: $rows ? 'Insert rows' : 'Insert columns',
            help: 'Enter:Plan  Up/Down:Count  Esc:Cancel',
            position: ['x' => max(2, intdiv($layout['width'] - $width, 2)), 'y' => 6],
            width: $width, height: count($lines) + 2,
            content: $this->fitLines($lines, $this->getWindowContentWidth($width), count($lines)),
        )->render();
    }

    /**
     * Plans the insertion and lists what it writes, and what it could not
     * rewrite, for confirmation. Nothing is written yet.
     */
    private function openLineInsertConfirmation(string $axis, int $at, int $count): void
    {
        $map = $this->getSelectedMap();
        if ($map === null || ! $this->canInsertLines()) {
            return;
        }
        try {
            $plan = LineInsertionPlanner::planProject($this->projectRoot, $map->mapId, $axis, $at, $count);
        } catch (Throwable $failure) {
            $this->setErrorStatus($failure, 'Insertion');
            return;
        }
        $paths = array_map(fn(string $path): string => substr($path, strlen($this->projectRoot) + 1), $plan->getChangedPaths());
        $this->optionDialogField = ['lineInsertion' => $plan];
        $this->eventOptionDialogMarker = null;
        $this->eventOptionDialogPath = null;
        $this->eventOptionDialogTitle = 'Insert ' . $plan->insertion->description;
        $this->eventOptionDialogEntries = [
            ['label' => 'Cancel', 'value' => 'cancel', 'description' => 'Leave all files unchanged.'],
            ['label' => sprintf('Write %d files now', count($paths)), 'value' => 'insert',
                'description' => 'Writes now, not on Save. Ctrl+Z restores files.'],
            ...array_map(static fn(string $path): array => [
                'label' => $path, 'value' => 'preview',
                'description' => 'Written by the insertion. Select Write to apply all files.',
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
        $this->setStatus(sprintf('Confirm inserting %s. Writes now, not on Save. Ctrl+Z restores files.', $plan->insertion->description));
        $this->renderSelectionDependentArea();
    }

    private function confirmLineInsertion(LineInsertionPlan $plan, bool $confirmed): void
    {
        if (! $confirmed || ! $this->workspace instanceof ProjectWorkspace) {
            $this->closeEventOptionDialog();
            $this->setStatus('Insertion cancelled. No files were changed.');
            return;
        }
        try {
            $command = new SourceSetCommand('Insert ' . $plan->insertion->noun, 'this insertion', $plan->getSourceSet(), $this->workspace,
                fn(): ?ProjectWorkspace => $this->workspace,
                function (ProjectWorkspace $workspace): void { $this->workspace = $workspace; },
            );
            $command->execute();
            $this->recordCommand($command);
            $handEdits = count($plan->handEdits);
            $this->closeEventOptionDialog();
            // The files are on disk now, so the result reads as saved.
            $this->setStatus(sprintf('Inserted %s.%s Ctrl+Z restores the files.', $plan->insertion->description,
                $handEdits === 0 ? '' : sprintf(' %d %s still need a hand edit.', $handEdits, $handEdits === 1 ? 'coordinate' : 'coordinates')), StatusLevel::SUCCESS);
            $this->requestFullRender();
        } catch (Throwable $failure) {
            $this->closeEventOptionDialog('');
            $this->setErrorStatus($failure, 'Insertion');
        }
    }
}
