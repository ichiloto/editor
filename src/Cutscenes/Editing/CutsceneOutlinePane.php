<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Editing;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Ichiloto\Editor\Cutscenes\CutsceneOutline;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\ListNavigation;
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

        $next = ListNavigation::step($this->cutsceneTreeCursor, $step, count($rows));

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

    // -- Tree operations -----------------------------------------------------

    /**
     * The row under the cursor when it names an entry a tree operation can
     * move: a command, a track, a keyframe or a cue.
     *
     * @return array{row: array<string, mixed>, listPath: array<int, int|string>, index: int, payload: array<string, mixed>}|null
     */
    private function locateCutsceneTreeEntry(): ?array
    {
        $asset = $this->selectedCutscene();
        $row = $this->visibleCutsceneTreeRows()[$this->cutsceneTreeCursor] ?? null;

        if ($asset === null || $row === null || ! in_array($row['kind'], ['command', 'track', 'keyframe', 'cue'], true)) {
            $this->setStatus('Select a command, track, keyframe or cue row first.', StatusLevel::INFO);

            return null;
        }

        $payload = $asset->payload();
        $location = CutsceneOutline::locate($payload, $row['key']);

        if ($location === null) {
            return null;
        }

        return ['row' => $row, 'listPath' => $location['listPath'], 'index' => $location['index'], 'payload' => $payload];
    }

    /**
     * Moves the selected entry one place up or down within its list.
     */
    private function reorderCutsceneTreeRow(int $delta): void
    {
        $entry = $this->locateCutsceneTreeEntry();

        if ($entry === null) {
            return;
        }

        $list = CutsceneOutline::valueAt($entry['payload'], $entry['listPath']);
        $list = is_array($list) ? array_values($list) : [];
        $from = $entry['index'];
        $to = $from + $delta;

        if (! array_key_exists($from, $list) || $to < 0 || $to >= count($list)) {
            $this->setStatus($delta < 0 ? 'Already first.' : 'Already last.', StatusLevel::INFO);

            return;
        }

        $moved = $list[$from];
        array_splice($list, $from, 1);
        array_splice($list, $to, 0, [$moved]);
        $targetKey = $this->replaceKeyIndex($entry['row']['key'], $to);

        if ($this->mutateCutscenePayload(
            $delta < 0 ? 'Move up' : 'Move down',
            static fn(array $payload): array => CutsceneOutline::withValueAt($payload, $entry['listPath'], $list),
        )) {
            $this->setStatus(sprintf('Moved %s.', $delta < 0 ? 'up' : 'down'), StatusLevel::SUCCESS);
            $this->focusCutsceneTreeKey($targetKey);
        }
    }

    /**
     * Inserts a new entry after the selected row: a wait command, a blank
     * track, keyframe or cue, as the row's list takes.
     */
    private function insertCutsceneTreeRow(): void
    {
        $entry = $this->locateCutsceneTreeEntry();

        if ($entry === null) {
            return;
        }

        $blank = match ($entry['row']['kind']) {
            'track' => ['type' => 'glyph', 'id' => 'track', 'keyframes' => []],
            'keyframe' => ['frame' => 0, 'duration' => 1, 'content' => '', 'position' => [0, 0]],
            'cue' => ['id' => 'cue', 'frame' => 0, 'type' => 'applyEffect'],
            default => ['type' => 'wait', 'seconds' => 0.5],
        };
        $list = CutsceneOutline::valueAt($entry['payload'], $entry['listPath']);
        $list = is_array($list) ? array_values($list) : [];
        $index = $entry['index'];

        if (in_array($entry['row']['kind'], ['track', 'cue'], true)) {
            $blank['id'] = $this->freeOutlineId($list, $blank['id']);
        }

        array_splice($list, $index + 1, 0, [$blank]);
        $targetKey = $this->replaceKeyIndex($entry['row']['key'], $index + 1);

        if ($this->mutateCutscenePayload(
            'Add ' . $entry['row']['kind'],
            static fn(array $payload): array => CutsceneOutline::withValueAt($payload, $entry['listPath'], $list),
        )) {
            $this->setStatus(sprintf('Added a %s after the selected one.', $entry['row']['kind']), StatusLevel::SUCCESS);
            $this->focusCutsceneTreeKey($targetKey);
        }
    }

    /**
     * Inserts a copy of the selected entry right after it.
     */
    private function duplicateCutsceneTreeRow(): void
    {
        $entry = $this->locateCutsceneTreeEntry();

        if ($entry === null) {
            return;
        }

        $list = CutsceneOutline::valueAt($entry['payload'], $entry['listPath']);
        $list = is_array($list) ? array_values($list) : [];
        $index = $entry['index'];

        if (! array_key_exists($index, $list)) {
            return;
        }

        $copy = $list[$index];

        // Identities must stay unique: a track, a cue or a lane carries an id.
        if (is_array($copy) && is_string($copy['id'] ?? null) && in_array($entry['row']['kind'], ['track', 'cue'], true)) {
            $copy['id'] = $this->freeOutlineId($list, $copy['id']);
        }

        array_splice($list, $index + 1, 0, [$copy]);
        $targetKey = $this->replaceKeyIndex($entry['row']['key'], $index + 1);

        if ($this->mutateCutscenePayload(
            'Duplicate ' . $entry['row']['kind'],
            static fn(array $payload): array => CutsceneOutline::withValueAt($payload, $entry['listPath'], $list),
        )) {
            $this->setStatus(sprintf('Duplicated the %s.', $entry['row']['kind']), StatusLevel::SUCCESS);
            $this->focusCutsceneTreeKey($targetKey);
        }
    }

    /**
     * Removes the selected entry, undoably.
     */
    private function removeCutsceneTreeRow(): void
    {
        $entry = $this->locateCutsceneTreeEntry();

        if ($entry === null) {
            return;
        }

        $list = CutsceneOutline::valueAt($entry['payload'], $entry['listPath']);
        $list = is_array($list) ? array_values($list) : [];
        $index = $entry['index'];

        if (! array_key_exists($index, $list)) {
            return;
        }

        array_splice($list, $index, 1);
        $kind = $entry['row']['kind'];

        if ($this->mutateCutscenePayload(
            'Remove ' . $kind,
            static fn(array $payload): array => CutsceneOutline::withValueAt($payload, $entry['listPath'], $list),
        )) {
            $this->setStatus(sprintf('Removed the %s (Ctrl+Z restores it).', $kind), StatusLevel::SUCCESS);
            $this->cutsceneTreeCursor = max(0, min($this->cutsceneTreeCursor, count($this->visibleCutsceneTreeRows()) - 1));
            $this->renderCutscenesArea();
        }
    }

    /**
     * Moves the selected command into the block just above it.
     */
    private function nestCutsceneTreeRow(): void
    {
        $entry = $this->locateCutsceneTreeEntry();

        if ($entry === null || $entry['row']['kind'] !== 'command') {
            if ($entry !== null) {
                $this->setStatus('Only commands nest.', StatusLevel::INFO);
            }

            return;
        }

        $list = CutsceneOutline::valueAt($entry['payload'], $entry['listPath']);
        $list = is_array($list) ? array_values($list) : [];
        $index = $entry['index'];
        $previous = $list[$index - 1] ?? null;

        if ($index === 0 || ! is_array($previous)) {
            $this->setStatus('Nothing above to nest into.', StatusLevel::INFO);

            return;
        }

        $previousPath = [...$entry['listPath'], $index - 1];
        $target = CutsceneOutline::nestingTarget($previous, $previousPath);

        if ($target === null) {
            $this->setStatus(sprintf('%s is not a block; only sequence, parallel, branch and choice take nested commands.', strval($previous['type'] ?? 'That')), StatusLevel::INFO);

            return;
        }

        $moved = $list[$index];
        $targetKey = null;

        if ($this->mutateCutscenePayload('Nest command', static function (array $payload) use ($entry, $index, $target, $moved, &$targetKey): array {
            $list = CutsceneOutline::valueAt($payload, $entry['listPath']);
            $list = is_array($list) ? array_values($list) : [];
            array_splice($list, $index, 1);
            $payload = CutsceneOutline::withValueAt($payload, $entry['listPath'], $list);
            $targetList = CutsceneOutline::valueAt($payload, $target);
            $targetList = is_array($targetList) ? array_values($targetList) : [];
            $targetList[] = $moved;
            $targetKey = count($targetList) - 1;

            return CutsceneOutline::withValueAt($payload, $target, $targetList);
        })) {
            $this->setStatus(sprintf('Nested into the %s above.', strval($previous['type'] ?? 'block')), StatusLevel::SUCCESS);
            $this->focusCutsceneTreeKey($this->keyForPath([...$target, (int) $targetKey]));
        }
    }

    /**
     * Moves the selected command out of its block, to just after it.
     */
    private function unnestCutsceneTreeRow(): void
    {
        $entry = $this->locateCutsceneTreeEntry();

        if ($entry === null || $entry['row']['kind'] !== 'command') {
            if ($entry !== null) {
                $this->setStatus('Only commands un-nest.', StatusLevel::INFO);
            }

            return;
        }

        // Walk up the list path to the command that owns this block.
        $listPath = $entry['listPath'];
        $ownerIndex = null;
        $ownerList = null;

        for ($cut = count($listPath) - 1; $cut >= 1; $cut--) {
            $segment = $listPath[$cut];

            if (! is_int($segment)) {
                continue;
            }

            $prefix = array_slice($listPath, 0, $cut);
            $candidate = CutsceneOutline::valueAt($entry['payload'], $prefix);
            $owner = is_array($candidate) && array_is_list($candidate) ? ($candidate[$segment] ?? null) : null;

            if (is_array($owner) && ! array_is_list($owner) && array_key_exists('type', $owner)) {
                $ownerIndex = $segment;
                $ownerList = $prefix;
                break;
            }
        }

        if ($ownerList === null || $ownerIndex === null) {
            $this->setStatus('Already at the top level.', StatusLevel::INFO);

            return;
        }

        $moved = (CutsceneOutline::valueAt($entry['payload'], $entry['listPath']) ?? [])[$entry['index']] ?? null;

        if (! is_array($moved)) {
            return;
        }

        $index = $entry['index'];

        if ($this->mutateCutscenePayload('Un-nest command', static function (array $payload) use ($entry, $index, $ownerList, $ownerIndex, $moved): array {
            $list = CutsceneOutline::valueAt($payload, $entry['listPath']);
            $list = is_array($list) ? array_values($list) : [];
            array_splice($list, $index, 1);
            $payload = CutsceneOutline::withValueAt($payload, $entry['listPath'], $list);
            $parent = CutsceneOutline::valueAt($payload, $ownerList);
            $parent = is_array($parent) ? array_values($parent) : [];
            array_splice($parent, $ownerIndex + 1, 0, [$moved]);

            return CutsceneOutline::withValueAt($payload, $ownerList, $parent);
        })) {
            $this->setStatus('Moved out of its block.', StatusLevel::SUCCESS);
            $this->focusCutsceneTreeKey($this->keyForPath([...$ownerList, $ownerIndex + 1]));
        }
    }

    /**
     * Moves a keyframe's or cue's frame by one: a timing nudge from the
     * timeline rows, undoable like any field edit. Returns false when the
     * row is not a keyframe or cue, so the key can fold instead.
     */
    private function nudgeCutsceneTreeFrame(int $delta): bool
    {
        $row = $this->visibleCutsceneTreeRows()[$this->cutsceneTreeCursor] ?? null;

        if ($row === null || ! in_array($row['kind'], ['keyframe', 'cue'], true)) {
            return false;
        }

        $entry = $this->locateCutsceneTreeEntry();

        if ($entry === null) {
            return true;
        }

        $path = [...$entry['listPath'], $entry['index'], 'frame'];
        $current = CutsceneOutline::valueAt($entry['payload'], $path);
        $next = max(0, intval($current) + $delta);

        if ($next === intval($current)) {
            $this->setStatus('Already at frame 0.', StatusLevel::INFO);

            return true;
        }

        if ($this->mutateCutscenePayload(
            'Nudge frame',
            static fn(array $payload): array => CutsceneOutline::withValueAt($payload, $path, $next),
        )) {
            $this->setStatus(sprintf('Frame %d.', $next), StatusLevel::SUCCESS);
            $this->renderCutscenesArea();
        }

        return true;
    }

    /**
     * Rewrites the last index of an outline key.
     */
    private function replaceKeyIndex(string $key, int $index): string
    {
        $segments = explode('.', $key);
        $segments[count($segments) - 1] = (string) $index;

        return implode('.', $segments);
    }

    /**
     * The outline key of a payload path: the path without the implicit
     * `commands` of a keyed lane and `then` of a choice option.
     *
     * @param array<int, int|string> $path
     */
    private function keyForPath(array $path): string
    {
        $segments = [];
        $count = count($path);

        foreach ($path as $position => $segment) {
            if ($segment === 'commands' && $position >= 2 && $path[$position - 2] === 'lanes') {
                continue;
            }

            if ($segment === 'then' && $position >= 2 && $path[$position - 2] === 'options') {
                continue;
            }

            $segments[] = (string) $segment;
        }

        unset($count);

        return implode('.', $segments);
    }

    /**
     * Puts the tree cursor on a key after a move, unfolding its ancestors.
     */
    private function focusCutsceneTreeKey(string $key): void
    {
        foreach ($this->cutsceneTreeCollapsed as $collapsed => $_) {
            if (str_starts_with($key, $collapsed . '.')) {
                unset($this->cutsceneTreeCollapsed[$collapsed]);
            }
        }

        foreach ($this->visibleCutsceneTreeRows() as $position => $row) {
            if ($row['key'] === $key) {
                $this->cutsceneTreeCursor = $position;
                break;
            }
        }

        $this->renderCutscenesArea();
    }

    /**
     * A copy's id: the original's with a numeric suffix no sibling uses.
     *
     * @param array<int, mixed> $list
     */
    private function freeOutlineId(array $list, string $id): string
    {
        $taken = [];

        foreach ($list as $sibling) {
            if (is_array($sibling) && is_string($sibling['id'] ?? null)) {
                $taken[] = $sibling['id'];
            }
        }

        $base = preg_replace('/-\d+$/', '', $id) ?? $id;

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = $base . '-' . $suffix;

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        return $id . '-copy';
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
     * @param array<string, int> $layout
     */
    private function createCutsceneTreeWindow(array $layout): EditorWindow
    {
        $rows = $this->visibleCutsceneTreeRows();
        $lines = [];
        $contentWidth = $this->getWindowContentWidth($layout['treeWidth']);

        $preview = $this->cinematicPreview;
        $activeKeys = $preview !== null && ! $preview->isFinished() ? $preview->activeKeys() : [];
        $failedKey = $preview?->failure()['key'] ?? null;
        $selected = $this->selectedCutscene();

        if ($selected?->type === CutsceneType::SUMMON) {
            $activeKeys = $this->activeSummonKeyframeKeys($selected);
        }

        foreach ($rows as $position => $row) {
            $marker = $row['foldable'] ? (isset($this->cutsceneTreeCollapsed[$row['key']]) ? '▸ ' : '▾ ') : '  ';
            $prefix = $position === $this->cutsceneTreeCursor && $this->cutsceneFocus === CutscenesScreen::PANE_TREE ? '> ' : '  ';
            // The running preview marks the commands its lanes are on (▶)
            // and the one it failed at (✗); the tree itself is untouched.
            $state = match (true) {
                $failedKey !== null && $row['key'] === $failedKey => ' ✗',
                in_array($row['key'], $activeKeys, true) => ' ▶',
                default => '',
            };
            $lines[] = $prefix . str_repeat('  ', $row['depth']) . $marker . $row['text'] . $state;
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
            help: $this->fitHelp($layout['treeWidth'], 'Enter:Open  Space:Fold  [ ]:Reorder  < >:Un-nest/Nest  Shift+O:Add  Shift+D:Dup  Del:Remove', 'Enter:Open  Space:Fold  [ ]:Reorder  < >:Nest  Del', 'Enter:Open  Space:Fold  [ ]', 'Enter:Open'),
            position: ['x' => $layout['treeX'], 'y' => $layout['treeY']],
            width: $layout['treeWidth'],
            height: $layout['treeHeight'],
            foregroundColor: $this->resolveCutscenePaneColor(CutscenesScreen::PANE_TREE),
            content: $this->fitLines(array_slice($lines, $scroll), $contentWidth, $contentHeight),
        );
    }
}
