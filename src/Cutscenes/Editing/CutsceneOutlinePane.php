<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Editing;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Ichiloto\Editor\Cutscenes\CutsceneOutline;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\EditorWindow;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\CutscenesScreen;

/**
 * The outline pane of the Cutscenes screen: a cinematic's command tree, a
 * summon's tracks and cues, as rows to walk, fold and jump from.
 *
 * The outline is a projection. Enter on a row opens the frame that holds it
 * in the record pane and puts the cursor on its fields, so every edit still
 * happens in the one authoritative tree; Space folds a block shut or open.
 *
 * @package Ichiloto\Editor\Cutscenes\Editing
 */
trait CutsceneOutlinePane
{
    /**
     * Returns the selected asset's outline, or null without one.
     */
    private function cutsceneOutline(): ?CutsceneOutline
    {
        $asset = $this->selectedCutscene();

        if ($asset === null) {
            return null;
        }

        return CutsceneOutline::of($asset->type, $asset->payload());
    }

    /**
     * Returns the outline rows currently shown.
     *
     * @return array<int, array{depth: int, text: string, frame: array<int, int|string>, index: int|null, key: string, kind: string, foldable: bool, children: bool}>
     */
    private function visibleCutsceneTreeRows(): array
    {
        return $this->cutsceneOutline()?->visibleRows($this->cutsceneTreeCollapsed) ?? [];
    }

    /**
     * Moves the outline cursor.
     */
    private function moveCutsceneTreeCursor(int $step): void
    {
        $rows = $this->visibleCutsceneTreeRows();

        if ($rows === []) {
            return;
        }

        $next = max(0, min(count($rows) - 1, $this->cutsceneTreeCursor + $step));

        if ($next === $this->cutsceneTreeCursor) {
            return;
        }

        $this->cutsceneTreeCursor = $next;
        $this->renderDatabasePanes(['tree']);
    }

    /**
     * Folds the cursor's block shut, or open again.
     */
    private function toggleCutsceneTreeRow(): void
    {
        $rows = $this->visibleCutsceneTreeRows();
        $row = $rows[$this->cutsceneTreeCursor] ?? null;

        if ($row === null || ! $row['foldable']) {
            return;
        }

        if (isset($this->cutsceneTreeCollapsed[$row['key']])) {
            unset($this->cutsceneTreeCollapsed[$row['key']]);
        } else {
            $this->cutsceneTreeCollapsed[$row['key']] = true;
        }

        $this->renderDatabasePanes(['tree']);
    }

    /**
     * Opens the cursor's row in the record pane: the frame that holds it,
     * with the cursor on its first field.
     */
    private function openCutsceneTreeRow(): void
    {
        $rows = $this->visibleCutsceneTreeRows();
        $row = $rows[$this->cutsceneTreeCursor] ?? null;
        $records = $this->cutsceneRecords();

        if ($row === null || $records === null) {
            return;
        }

        $this->leaveCutsceneEditingState();
        $this->databaseCommandFramePath = $row['frame'];
        $this->databaseSelectedSettingIndex = 0;
        $this->cutsceneFocus = CutscenesScreen::PANE_SETTINGS;

        if ($row['index'] !== null) {
            $this->selectCutsceneSettingsRowFor($row);
        }

        $this->setStatus($records->describeFramePath($this->databaseCommandFramePath) . '.', StatusLevel::INFO);
        $this->renderCutscenesArea();
    }

    /**
     * Puts the settings cursor on the row's own fields: a command's Type
     * row, a track's Id row, a keyframe's Frame row, a cue's Id row.
     *
     * @param array{depth: int, text: string, frame: array<int, int|string>, index: int|null, key: string, kind: string, foldable: bool, children: bool} $row
     */
    private function selectCutsceneSettingsRowFor(array $row): void
    {
        $fields = $this->getDatabaseSettingsFields();
        $index = $row['index'];
        $pattern = match ($row['kind']) {
            'track' => '/^track' . $index . 'Id$/',
            'keyframe' => preg_match('/keyframes\.(\d+)$/', $row['key'], $m) === 1 ? '/^track' . $index . 'Keyframe' . $m[1] . 'Frame$/' : null,
            'cue' => '/^cue' . $index . 'Id$/',
            default => '/^(command|final)' . $index . 'Type$/',
        };

        if ($pattern === null) {
            return;
        }

        foreach ($fields as $position => $field) {
            if (preg_match($pattern, (string) ($field['field'] ?? '')) === 1) {
                $this->databaseSelectedSettingIndex = $position;

                return;
            }
        }
    }

    /**
     * Keeps the outline cursor on the command the record pane is editing,
     * when the pane moved to a command.
     */
    private function syncCutsceneTreeCursorToFrame(): void
    {
        $rows = $this->visibleCutsceneTreeRows();
        $frame = $this->databaseCommandFramePath;

        if ($frame === [] || $rows === []) {
            return;
        }

        $selectedId = (string) ($this->getDatabaseSettingsFields()[$this->databaseSelectedSettingIndex]['field'] ?? '');
        $index = preg_match('/^(?:command|final|track|cue)(\d+)/', $selectedId, $m) === 1 ? intval($m[1]) : null;

        foreach ($rows as $position => $row) {
            if ($row['frame'] === $frame && $row['index'] === $index && $row['kind'] !== 'arm') {
                $this->cutsceneTreeCursor = $position;

                return;
            }
        }
    }

    /**
     * Moves inside the preview pane: scrolls its lines.
     */
    private function moveCutscenePreview(int $deltaX, int $deltaY): void
    {
        $this->cutscenePreviewScroll = max(0, $this->cutscenePreviewScroll + $deltaY + $deltaX);
        $this->renderDatabasePanes(['preview']);
    }

    /**
     * The preview pane's scroll offset.
     */
    private int $cutscenePreviewScroll = 0;

    /**
     * @param array<string, int> $layout
     */
    private function createCutsceneTreeWindow(array $layout): EditorWindow
    {
        $rows = $this->visibleCutsceneTreeRows();
        $lines = [];
        $contentWidth = $this->getWindowContentWidth($layout['treeWidth']);

        foreach ($rows as $position => $row) {
            $marker = $row['foldable'] ? (isset($this->cutsceneTreeCollapsed[$row['key']]) ? '▸ ' : '▾ ') : '  ';
            $prefix = $position === $this->cutsceneTreeCursor && $this->cutsceneFocus === CutscenesScreen::PANE_TREE ? '> ' : '  ';
            $lines[] = $prefix . str_repeat('  ', $row['depth']) . $marker . $row['text'];
        }

        if ($lines === []) {
            $lines[] = $this->selectedCutscene() === null ? '  (nothing selected)' : '  (empty)';
        }

        $contentHeight = max(1, $layout['treeHeight'] - 2);
        $scroll = max(0, min(max(0, count($lines) - $contentHeight), $this->cutsceneTreeCursor - intdiv($contentHeight, 2)));
        $asset = $this->selectedCutscene();
        $title = $asset?->type === CutsceneType::SUMMON ? 'Timeline' : 'Command Tree';

        return new EditorWindow(
            title: $title,
            help: $this->fitHelp($layout['treeWidth'], 'Enter:Open  Space:Fold  Up/Down:Move', 'Enter:Open  Space:Fold', 'Enter:Open'),
            position: ['x' => $layout['treeX'], 'y' => $layout['treeY']],
            width: $layout['treeWidth'],
            height: $layout['treeHeight'],
            foregroundColor: $this->resolveCutscenePaneColor(CutscenesScreen::PANE_TREE),
            content: $this->fitLines(array_slice($lines, $scroll), $contentWidth, $contentHeight),
        );
    }

    /**
     * @param array<string, int> $layout
     */
    private function createCutscenePreviewWindow(array $layout): EditorWindow
    {
        return new EditorWindow(
            title: 'Preview',
            help: '',
            position: ['x' => $layout['previewX'], 'y' => $layout['previewY']],
            width: $layout['previewWidth'],
            height: $layout['previewHeight'],
            foregroundColor: $this->resolveCutscenePaneColor(CutscenesScreen::PANE_PREVIEW),
            content: $this->fitLines(
                array_slice($this->cutscenePreviewLines($layout), $this->cutscenePreviewScroll),
                $this->getWindowContentWidth($layout['previewWidth']),
                max(1, $layout['previewHeight'] - 2),
            ),
        );
    }

    /**
     * Returns the preview pane's lines. Replaced by the running preview
     * when one is playing.
     *
     * @param array<string, int> $layout
     * @return string[]
     */
    private function cutscenePreviewLines(array $layout): array
    {
        unset($layout);
        $asset = $this->selectedCutscene();

        if ($asset === null) {
            return ['  Select a cutscene to preview it.'];
        }

        return $this->describeCutsceneStanding($asset);
    }

    /**
     * Returns what the engine makes of the asset as it stands: hydrated, or
     * refused with the engine's reason.
     *
     * @return string[]
     */
    private function describeCutsceneStanding(\Ichiloto\Editor\Cutscenes\CutsceneAsset $asset): array
    {
        try {
            $asset->hydrate();
            $standing = sprintf('  ✓ The engine reads this %s as it stands.', $asset->type->noun());
        } catch (\Throwable $throwable) {
            $standing = '  ✗ ' . $throwable->getMessage();
        }

        return [
            sprintf('  %s · %s%s', $asset->name(), $asset->id, $asset->isDirty() ? ' *' : ''),
            $standing,
        ];
    }
}
