<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Editing;

use Atatusoft\Termutil\IO\Console\Console;
use Atatusoft\Termutil\IO\Enumerations\Color;
use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Database\DatabaseCategoryDefinition;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSubList;
use Ichiloto\Editor\EditorWindow;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\IO\InputRouter;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\UI\CutscenesScreen;
use Ichiloto\Editor\UI\ListFilter;
use Ichiloto\Editor\UI\Modal;
use Ichiloto\Editor\UI\MultilineTextEditor;
use Ichiloto\Editor\UI\SettingsPaneLayout;
use Throwable;

/**
 * The Cutscenes workspace: a first-class screen over the project's
 * cinematics and summons.
 *
 * The screen is its own -- its own key, layout, panes and focus order --
 * but what it edits it edits through the same record pane as every other
 * category: the settings rows, pickers, condition editor, sub-lists and
 * command frames. The workspace hosts that pane the way the Inspector
 * hosts it for a map's NPCs: while the screen is open, the "selected record
 * database" is the selected cutscene type's records, and every edit is
 * written back into the asset and recorded for undo against that asset,
 * so history survives selection, frame and screen changes.
 *
 * @package Ichiloto\Editor\Cutscenes\Editing
 */
trait CutscenesWorkspace
{
    /**
     * The type whose assets the screen shows.
     */
    private CutsceneType $cutsceneType = CutsceneType::CINEMATIC;

    /**
     * The focused pane of the screen.
     */
    private string $cutsceneFocus = CutscenesScreen::PANE_LIST;

    /**
     * The screen's pane registry and dirty state.
     */
    private CutscenesScreen $cutscenesScreen;

    /**
     * The incremental filter over the asset list.
     */
    private ListFilter $cutsceneFilter;

    /**
     * The editor for text with line breaks, shared by both screens.
     */
    private MultilineTextEditor $multilineEditor;

    /**
     * The cursor row of the command tree pane.
     */
    private int $cutsceneTreeCursor = 0;

    /**
     * @var array<string, true> Tree rows folded shut, by path key.
     */
    private array $cutsceneTreeCollapsed = [];

    /**
     * Whether the Cutscenes screen is open, as a modal.
     */
    private bool $isCutscenesOpen {
        get => $this->modals->has(Modal::CUTSCENES);
        set {
            $value ? $this->modals->push(Modal::CUTSCENES) : $this->modals->remove(Modal::CUTSCENES);
        }
    }

    /**
     * Wires the workspace's own state. Called once from the constructor.
     */
    private function bootCutscenesWorkspace(): void
    {
        $this->cutsceneFilter = new ListFilter();
        $this->multilineEditor = new MultilineTextEditor();
        $this->cutscenesScreen = new CutscenesScreen(
            fn(): array => $this->resolveCutscenesLayout($this->resolveLayout()),
            fn(array $layout) => $this->createCutscenesRootWindow($layout)->render(),
            [
                CutscenesScreen::PANE_TYPES => fn(array $layout) => $this->createCutsceneTypesWindow($layout)->render(),
                CutscenesScreen::PANE_LIST => fn(array $layout) => $this->createCutsceneListWindow($layout)->render(),
                CutscenesScreen::PANE_SETTINGS => function (array $layout): void {
                    $this->createCutsceneSettingsWindow($layout)->render();
                    $this->renderMultilineEditorOverlay($layout);
                },
                CutscenesScreen::PANE_TREE => fn(array $layout) => $this->createCutsceneTreeWindow($layout)->render(),
                CutscenesScreen::PANE_PREVIEW => fn(array $layout) => $this->createCutscenePreviewWindow($layout)->render(),
            ],
            $this->renderCutsceneEditCursor(...),
            fn(): bool => $this->isDatabaseEditing && ! $this->multilineEditor->isOpen(),
        );
    }

    // -- Opening and closing ---------------------------------------------------

    /**
     * Opens the workspace, on the type it last showed.
     */
    private function openCutscenesWorkspace(?CutsceneType $type = null): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        if ($this->isDatabaseOpen) {
            // One screen at a time: the two share the record pane's state.
            $this->closeDatabaseWindow();
        }

        $this->isCutscenesOpen = true;

        if ($type !== null) {
            $this->cutsceneType = $type;
        }

        $this->cutsceneFocus = CutscenesScreen::PANE_LIST;
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseCommandFramePath = [];
        $this->isDatabaseEditing = false;
        $this->databaseEditBuffer = '';
        $this->databaseEditCursorIndex = 0;
        $this->cutsceneTreeCursor = 0;
        $this->cutsceneFilter->clear();
        $this->clampCutsceneSelection();
        $this->statusMessage = sprintf('Cutscenes open: %s.', $this->cutsceneType->label());
        $this->renderCutscenesArea(includeRoot: true);
    }

    /**
     * Closes the workspace.
     */
    private function closeCutscenesWorkspace(): void
    {
        $this->isCutscenesOpen = false;
        $this->isDatabaseEditing = false;
        $this->databaseEditBuffer = '';
        $this->databaseEditCursorIndex = 0;
        $this->multilineEditor->close();
        $this->referencePicker->close();
        $this->cutsceneFilter->clear();
        $this->disposeCinematicPreview();
        $this->cutscenePreviewView = 'stage';
        $this->statusMessage = 'Cutscenes closed.';
        $this->requestFullRender();
    }

    /**
     * Opens the workspace on a type, from the palette.
     */
    private function openCutscenesAt(CutsceneType $type): void
    {
        if (! $this->isCutscenesOpen) {
            $this->openCutscenesWorkspace($type);

            return;
        }

        $this->switchCutsceneType($type);
    }

    /**
     * Shows another type's assets.
     */
    private function switchCutsceneType(CutsceneType $type): void
    {
        if ($type === $this->cutsceneType) {
            return;
        }

        $this->leaveCutsceneEditingState();
        $this->disposeCinematicPreview();
        $this->cutscenePreviewView = 'stage';
        $this->cutsceneType = $type;
        $this->cutsceneFilter->clear();
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseCommandFramePath = [];
        $this->cutsceneTreeCursor = 0;
        $this->clampCutsceneSelection();
        $this->setStatus(sprintf('%s cutscenes.', $type->label()), StatusLevel::INFO);
        $this->renderCutscenesArea(includeRoot: true);
    }

    /**
     * Leaves any inline edit, picker or editor open on the settings pane.
     */
    private function leaveCutsceneEditingState(): void
    {
        $this->isDatabaseEditing = false;
        $this->databaseEditBuffer = '';
        $this->databaseEditCursorIndex = 0;
        $this->multilineEditor->close();
        $this->referencePicker->close();
    }

    // -- The library and the selection -------------------------------------

    /**
     * Returns the project's cutscenes, when the project has loaded.
     */
    private function cutsceneLibrary(): ?CutsceneLibrary
    {
        return $this->workspace?->cutscenes;
    }

    /**
     * Returns the records of the type the screen shows.
     */
    private function cutsceneRecords(): ?ProjectRecordDatabase
    {
        return $this->cutsceneLibrary()?->records($this->cutsceneType);
    }

    /**
     * Returns the pseudo-category the record pane works through while the
     * screen is open: a Database category in shape, keyed by type, so every
     * per-category selection index stays its own.
     */
    private function cutsceneCategoryDefinition(): DatabaseCategoryDefinition
    {
        return new DatabaseCategoryDefinition(
            $this->cutsceneType === CutsceneType::CINEMATIC ? CutsceneSchemas::CINEMATICS_KEY : CutsceneSchemas::SUMMONS_KEY,
            $this->cutsceneType->label(),
            $this->cutsceneType === CutsceneType::CINEMATIC
                ? 'Staged story sequences run by the event interpreter.'
                : 'Frame-driven battle presentations built by the summon compiler.',
            true,
        );
    }

    /**
     * Returns the selected asset, or null when the type has none.
     */
    private function selectedCutscene(): ?CutsceneAsset
    {
        $library = $this->cutsceneLibrary();

        if ($library === null) {
            return null;
        }

        $ids = $library->ids($this->cutsceneType);
        $id = $ids[$this->getSelectedRecordIndex()] ?? null;

        return $id === null ? null : $library->find($this->cutsceneType, $id);
    }

    /**
     * Keeps the selection on an existing asset.
     */
    private function clampCutsceneSelection(): void
    {
        $count = count($this->cutsceneLibrary()?->ids($this->cutsceneType) ?? []);
        $this->setSelectedRecordIndex(max(0, min(max(0, $count - 1), $this->getSelectedRecordIndex())));
    }

    /**
     * Selects an asset by id, when the type has it.
     */
    private function selectCutsceneById(string $id): void
    {
        $index = array_search($id, $this->cutsceneLibrary()?->ids($this->cutsceneType) ?? [], true);

        if (is_int($index)) {
            $this->setSelectedRecordIndex($index);
        }
    }

    /**
     * Returns the map a cinematic is staged on -- its start map -- so
     * map-local pickers (NPC ids, event markers) read the right map.
     */
    private function cutsceneReferenceMap(): ?ProjectMap
    {
        $asset = $this->selectedCutscene();

        if ($asset === null || $asset->type !== CutsceneType::CINEMATIC || ! $this->workspace instanceof ProjectWorkspace) {
            return $this->getSelectedMap();
        }

        $startMap = trim(strval($asset->data()['startMap'] ?? ''));

        foreach ($this->workspace->maps as $map) {
            if ($map->mapId === $startMap) {
                return $map;
            }
        }

        return $this->getSelectedMap();
    }

    // -- Input -----------------------------------------------------------------

    /**
     * Handles keyboard input while the workspace is the active modal.
     */
    private function handleCutscenesInput(string $input, string $normalizedInput): void
    {
        if ($input === "\x11") {
            $this->requestQuit();

            return;
        }

        if ($this->multilineEditor->isOpen()) {
            $this->handleMultilineEditorInput($input);

            return;
        }

        if ($this->referencePicker->isOpen()) {
            $this->handleReferencePickerInput($input);

            return;
        }

        if ($this->conditionEditor->isOpen()) {
            $this->handleConditionEditorInput($input);

            return;
        }

        if ($this->affinityEditor->isOpen()) {
            $this->handleAffinityEditorInput($input);

            return;
        }

        if ($this->worldWriteEditor->isOpen()) {
            $this->handleWorldWriteEditorInput($input);

            return;
        }

        if ($this->isDatabaseEditing) {
            $this->handleDatabaseEditingInput($input);

            return;
        }

        if ($this->cutsceneFilter->isCapturing) {
            $this->handleCutsceneFilterInput($input);

            return;
        }

        if ($this->handleHistoryShortcut($input)) {
            return;
        }

        if ($input === "\x07") {
            $this->goToDefinition();

            return;
        }

        if ($input === "\x02") {
            $this->navigateBack();

            return;
        }

        if ($input === '/') {
            $this->openCutsceneFilter();

            return;
        }

        if ($input === "\033" && $this->cutsceneFilter->isActive()) {
            // Esc pops exactly one level: the live filter before the screen.
            $this->cutsceneFilter->clear();
            $this->clampCutsceneSelection();
            $this->setStatus('Filter cleared.');
            $this->renderCutscenesArea();

            return;
        }

        if ($input === "\033" && $this->databaseCommandFramePath !== []) {
            // Esc pops exactly one level: the open frame before the screen.
            $this->leaveCommandFrame();

            return;
        }

        if ($input === "\033" || $this->inputRouter->isCutscenesKey($input)) {
            $this->closeCutscenesWorkspace();

            return;
        }

        if ($this->inputRouter->isDatabaseKey($input)) {
            // Straight across to the Database, never both screens at once.
            $this->closeCutscenesWorkspace();
            $this->openDatabaseWindow();

            return;
        }

        if ($input === '?') {
            $this->openHelpOverlay();

            return;
        }

        if ($input === "\x10") {
            $this->openCommandPalette();

            return;
        }

        if ($input === "\x13") {
            $this->saveSelectedCutscene();

            return;
        }

        if ($input === "\x14") {
            $this->playtestSelectedCinematic();

            return;
        }

        if ($input === "\x01") {
            $this->saveAllAssets();

            return;
        }

        if ($input === "\t") {
            $this->cycleCutsceneFocus(1);

            return;
        }

        if (str_contains($input, "\033[Z") || $this->isShiftArrow($input, 'up') || $this->isShiftArrow($input, 'left')) {
            $this->cycleCutsceneFocus(-1);

            return;
        }

        if ($this->isShiftArrow($input, 'down') || $this->isShiftArrow($input, 'right')) {
            $this->cycleCutsceneFocus(1);

            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_PREVIEW && $this->handleCutscenePreviewInput($input)) {
            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_LIST && str_contains($input, "\033[3~")) {
            $this->openCutsceneDeleteConfirmation();

            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_LIST && $this->isShiftLetterShortcut($input, 'A')) {
            $this->createCutscene();

            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_LIST && $this->isShiftLetterShortcut($input, 'D')) {
            $this->duplicateSelectedCutscene();

            return;
        }

        if ($this->isShiftLetterShortcut($input, 'O')) {
            $this->addCutsceneSubItem();

            return;
        }

        if ($this->isShiftLetterShortcut($input, 'X')) {
            $this->removeCutsceneSubItem(last: true);

            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_SETTINGS && str_contains($input, "\033[3~")) {
            $this->removeCutsceneSubItem(last: false);

            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_SETTINGS && ($input === "\n" || $input === "\r")) {
            $this->beginDatabaseEdit();

            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_TREE && ($input === "\n" || $input === "\r")) {
            $this->openCutsceneTreeRow();

            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_TREE && ($input === ' ' || $input === '-' || $input === '+')) {
            $this->toggleCutsceneTreeRow();

            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_TREE && ($input === '[' || $input === ']')) {
            $this->reorderCutsceneTreeRow($input === '[' ? -1 : 1);

            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_TREE && ($input === '>' || $input === '<')) {
            if ($input === '>') {
                $this->nestCutsceneTreeRow();
            } else {
                $this->unnestCutsceneTreeRow();
            }

            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_TREE && $this->isShiftLetterShortcut($input, 'D')) {
            $this->duplicateCutsceneTreeRow();

            return;
        }

        if ($this->cutsceneFocus === CutscenesScreen::PANE_TREE && str_contains($input, "\033[3~")) {
            $this->removeCutsceneTreeRow();

            return;
        }

        if (str_contains($input, "\033[A")) {
            $this->moveCutsceneSelection(0, -1);

            return;
        }

        if (str_contains($input, "\033[B")) {
            $this->moveCutsceneSelection(0, 1);

            return;
        }

        if (str_contains($input, "\033[D")) {
            if ($this->cutsceneFocus === CutscenesScreen::PANE_SETTINGS) {
                $this->adjustDatabaseOptionField(-1);

                return;
            }

            $this->moveCutsceneSelection(-1, 0);

            return;
        }

        if (str_contains($input, "\033[C")) {
            if ($this->cutsceneFocus === CutscenesScreen::PANE_SETTINGS) {
                $this->adjustDatabaseOptionField(1);

                return;
            }

            $this->moveCutsceneSelection(1, 0);

            return;
        }

        if (str_contains($input, "\033[5~") || str_contains($input, "\033[6~")) {
            $this->moveCutsceneSelection(0, str_contains($input, "\033[5~") ? -10 : 10);
        }
    }

    /**
     * Cycles focus through the screen's panes.
     */
    private function cycleCutsceneFocus(int $step): void
    {
        $order = [
            CutscenesScreen::PANE_TYPES,
            CutscenesScreen::PANE_LIST,
            CutscenesScreen::PANE_SETTINGS,
            CutscenesScreen::PANE_TREE,
            CutscenesScreen::PANE_PREVIEW,
        ];
        $current = array_search($this->cutsceneFocus, $order, true);
        $current = is_int($current) ? $current : 1;
        $next = ($current + $step + count($order)) % count($order);
        $this->cutsceneFocus = $order[$next];
        $this->renderCutscenesArea();
    }

    /**
     * Moves the selection inside the focused pane.
     */
    private function moveCutsceneSelection(int $deltaX, int $deltaY): void
    {
        switch ($this->cutsceneFocus) {
            case CutscenesScreen::PANE_TYPES:
                if ($deltaY !== 0 || $deltaX !== 0) {
                    $types = CutsceneType::cases();
                    $index = array_search($this->cutsceneType, $types, true);
                    $index = is_int($index) ? $index : 0;
                    $step = $deltaY !== 0 ? $deltaY : $deltaX;
                    $this->switchCutsceneType($types[max(0, min(count($types) - 1, $index + ($step > 0 ? 1 : -1)))]);
                }

                return;

            case CutscenesScreen::PANE_LIST:
                $this->moveCutsceneListSelection($deltaY);

                return;

            case CutscenesScreen::PANE_SETTINGS:
                $this->moveDatabaseSettingsSelection($deltaY);

                return;

            case CutscenesScreen::PANE_TREE:
                $this->moveCutsceneTreeCursor($deltaY);

                return;

            case CutscenesScreen::PANE_PREVIEW:
                $this->moveCutscenePreview($deltaX, $deltaY);

                return;
        }
    }

    /**
     * Moves the selected asset, through the filtered list.
     */
    private function moveCutsceneListSelection(int $step): void
    {
        $visible = $this->visibleCutsceneIndexes();

        if ($visible === []) {
            return;
        }

        $position = array_search($this->getSelectedRecordIndex(), $visible, true);
        $position = is_int($position) ? $position : 0;
        $next = max(0, min(count($visible) - 1, $position + $step));

        if ($visible[$next] === $this->getSelectedRecordIndex()) {
            return;
        }

        $this->leaveCutsceneEditingState();
        $this->disposeCinematicPreview();
        $this->cutscenePreviewView = 'stage';
        $this->setSelectedRecordIndex($visible[$next]);
        $this->databaseSelectedSettingIndex = 0;
        $this->cutsceneTreeCursor = 0;
        $this->renderCutscenesArea();
    }

    /**
     * Returns the indexes of the assets the filter lets through, in order.
     *
     * @return int[]
     */
    private function visibleCutsceneIndexes(): array
    {
        $labels = $this->cutsceneEntryLabels();

        if (! $this->cutsceneFilter->isActive()) {
            return array_keys($labels);
        }

        return array_keys($this->cutsceneFilter->apply($labels));
    }

    /**
     * Returns the list labels: the display name, with the id when it
     * differs, by record index.
     *
     * @return array<int, string>
     */
    private function cutsceneEntryLabels(): array
    {
        $labels = [];

        foreach ($this->cutsceneLibrary()?->ids($this->cutsceneType) ?? [] as $index => $id) {
            $asset = $this->cutsceneLibrary()?->find($this->cutsceneType, $id);
            $name = $asset?->name() ?? $id;
            $labels[$index] = $name === $id ? $id : sprintf('%s (%s)', $name, $id);
        }

        return $labels;
    }

    /**
     * Opens the list filter.
     */
    private function openCutsceneFilter(): void
    {
        if ($this->cutsceneEntryLabels() === []) {
            $this->setStatus(sprintf('No %ss to filter.', $this->cutsceneType->noun()), StatusLevel::WARN);
            $this->renderFooter();

            return;
        }

        $this->cutsceneFilter->open();
        $this->cutsceneFocus = CutscenesScreen::PANE_LIST;
        $this->setStatus('Filter: type to narrow, Enter to keep, Esc to clear.');
        $this->renderCutscenesArea();
    }

    /**
     * Handles keys while the filter caret is live.
     */
    private function handleCutsceneFilterInput(string $input): void
    {
        if ($input === "\033") {
            $this->cutsceneFilter->clear();
            $this->clampCutsceneSelection();
            $this->setStatus('Filter cleared.');
            $this->renderCutscenesArea();

            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->cutsceneFilter->commit();
            $this->setStatus($this->cutsceneFilter->query === '' ? 'Filter closed.' : sprintf('Filtering by "%s".', $this->cutsceneFilter->query));
            $this->renderCutscenesArea();

            return;
        }

        if (str_contains($input, "\033[A")) {
            $this->moveCutsceneListSelection(-1);

            return;
        }

        if (str_contains($input, "\033[B")) {
            $this->moveCutsceneListSelection(1);

            return;
        }

        if ($input === "\177" || $input === "\010") {
            $this->cutsceneFilter->backspace();
            $this->syncCutsceneSelectionToFilter();
            $this->renderCutscenesArea();

            return;
        }

        if (preg_match('/^\X$/u', $input) === 1 && ! ctype_cntrl($input)) {
            $this->cutsceneFilter->type($input);
            $this->syncCutsceneSelectionToFilter();
            $this->renderCutscenesArea();
        }
    }

    /**
     * Keeps the selection on a visible asset while the filter narrows.
     */
    private function syncCutsceneSelectionToFilter(): void
    {
        $visible = $this->visibleCutsceneIndexes();

        if ($visible !== [] && ! in_array($this->getSelectedRecordIndex(), $visible, true)) {
            $this->setSelectedRecordIndex($visible[0]);
            $this->databaseSelectedSettingIndex = 0;
        }
    }

    // -- The record pane, hosted -------------------------------------------

    /**
     * Returns the settings rows of the selected asset: inside a frame, the
     * frame's rows; at the root, the record's rows under group headings.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCutsceneSettingsFields(): array
    {
        $records = $this->cutsceneRecords();
        $asset = $this->selectedCutscene();

        if ($records === null || $asset === null) {
            return [];
        }

        $index = $this->getSelectedRecordIndex();
        $fields = $records->getFrameSettingsFields($index, $this->databaseCommandFramePath);

        if ($this->databaseCommandFramePath !== []
            && $records->getFrameCommands($index, $this->databaseCommandFramePath) === null
        ) {
            // The frame no longer resolves; an empty one still does.
            $this->databaseCommandFramePath = [];
            $fields = $records->getFrameSettingsFields($index, []);
        }

        if ($this->databaseCommandFramePath !== []) {
            return $fields;
        }

        return $this->groupCutsceneFields($asset, $fields);
    }

    /**
     * Puts headings between the root rows, and notes what the author should
     * know before editing: a read-only reason, unknown fields preserved.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function groupCutsceneFields(CutsceneAsset $asset, array $fields): array
    {
        $heading = static fn(string $title): array => ['label' => $title, 'value' => '', 'editable' => false];
        $sections = $asset->type === CutsceneType::CINEMATIC
            ? [
                'Identity' => ['id', 'name', 'description', 'version'],
                'Staging' => ['startMap', 'presentation.initial', 'presentation.reducedMotion'],
                'Script' => ['commandListCommands'],
                'Skip' => ['skip.policy', 'checkpoints', 'commandListFinalizer'],
                'Metadata' => ['authoring'],
            ]
            : [
                'Identity' => ['id', 'name', 'description', 'moveName', 'version', 'linkedSummonId', 'linkedActionId', 'tags'],
                'Lore' => ['lore', 'element', 'strengths', 'weaknesses', 'attributes', 'authoring'],
                'Availability' => ['availability.conditions'],
                'Wielders' => ['wielders.mode', 'wielders.roles', 'wielders.characters', 'wielders.tenancy'],
                'Playback' => ['playback.defaultSpeed', 'playback.allowSkip', 'playback.loopPreview', 'transitionIn.type', 'transitionIn.durationMs', 'transitionIn.color', 'transitionOut.type', 'transitionOut.durationMs', 'transitionOut.color', 'effectTiming.mode', 'effectTiming.cueId', 'effectTiming.frame', 'targetPresentation.mode', 'targetPresentation.showCasterNameBanner'],
                'Timeline' => ['formatVersion', 'fps', 'lengthFrames', 'editor', 'commandListTracks', 'commandListCues'],
            ];
        $byId = [];
        $rest = [];

        foreach ($fields as $field) {
            $id = (string) ($field['field'] ?? '');
            $placed = false;

            foreach ($sections as $ids) {
                if (in_array($id, $ids, true)) {
                    $byId[$id] = $field;
                    $placed = true;

                    break;
                }
            }

            if (! $placed) {
                $rest[] = $field;
            }
        }

        $grouped = [];
        $reason = $asset->readOnlyReason();

        if ($reason !== null) {
            $grouped[] = ['label' => '  ! Read-only', 'value' => $reason, 'editable' => false];
        }

        if ($asset->isDeleted()) {
            $grouped[] = ['label' => '  ! Deleted', 'value' => 'removed on the next save; Ctrl+Z restores it', 'editable' => false];
        }

        foreach ($sections as $title => $ids) {
            $rows = [];

            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $rows[] = $byId[$id];
                }
            }

            if ($rows === []) {
                continue;
            }

            $grouped[] = $heading($title);
            $grouped = [...$grouped, ...$rows];
        }

        if ($rest !== []) {
            $grouped[] = $heading($asset->type === CutsceneType::CINEMATIC ? 'Cast' : 'Other');
            $grouped = [...$grouped, ...$rest];
        }

        return $grouped;
    }

    /**
     * Returns the settings pane's width and height while the workspace
     * hosts the record pane.
     *
     * @return array{width: int, rows: int, cursorShown: bool}
     */
    private function cutsceneRecordPaneMetrics(): array
    {
        $layout = $this->resolveCutscenesLayout($this->resolveLayout());

        return [
            'width' => $this->getWindowContentWidth($layout['settingsWidth']),
            'rows' => max(1, $layout['settingsHeight'] - 2),
            'cursorShown' => $this->cutsceneFocus === CutscenesScreen::PANE_SETTINGS,
        ];
    }

    // -- Mutations, recorded against the asset -----------------------------

    /**
     * Runs one change against the selected asset's records, writes it back
     * into the asset, and records it for undo and redo pinned to that asset
     * -- whatever is selected, framed or open when the history fires.
     *
     * @param string $label The history label.
     * @param callable(ProjectRecordDatabase, int): void $mutation The change, against the records and the record index.
     * @return bool True when anything changed.
     */
    private function mutateSelectedCutscene(string $label, callable $mutation): bool
    {
        $library = $this->cutsceneLibrary();
        $asset = $this->selectedCutscene();
        $records = $this->cutsceneRecords();

        if ($library === null || $asset === null || $records === null) {
            return false;
        }

        if (! $asset->isEditable()) {
            $this->setStatus(sprintf('%s is read-only: %s.', ucfirst($asset->type->noun()), $asset->readOnlyReason()), StatusLevel::WARN);
            $this->renderCutscenesArea();

            return false;
        }

        $type = $asset->type;
        $id = $asset->id;
        $before = $this->snapshotCutscene($asset);

        try {
            $mutation($records, $this->getSelectedRecordIndex());
            $records->save();
        } catch (Throwable $throwable) {
            $library->refreshRecords($type);
            $this->setErrorStatus($throwable, $label);
            $this->renderCutscenesArea();

            return false;
        }

        $library->refreshRecords($type);
        $after = $this->snapshotCutscene($asset);

        if ($after === $before) {
            return false;
        }

        $this->recordCommand(new GenericCommand(
            $label,
            fn() => $this->restoreCutsceneSnapshot($type, $id, $after),
            fn() => $this->restoreCutsceneSnapshot($type, $id, $before),
        ));

        return true;
    }

    /**
     * Runs one change against the selected asset's payload as a whole, for
     * the tree operations that move entries between lists, and records it
     * for undo and redo like any other edit.
     *
     * @param callable(array<string, mixed>): (array<string, mixed>|null) $change Returns the new payload, or null for no change.
     */
    private function mutateCutscenePayload(string $label, callable $change): bool
    {
        $library = $this->cutsceneLibrary();
        $asset = $this->selectedCutscene();

        if ($library === null || $asset === null) {
            return false;
        }

        if (! $asset->isEditable()) {
            $this->setStatus(sprintf('%s is read-only: %s.', ucfirst($asset->type->noun()), $asset->readOnlyReason()), StatusLevel::WARN);
            $this->renderCutscenesArea();

            return false;
        }

        $type = $asset->type;
        $id = $asset->id;
        $before = $this->snapshotCutscene($asset);

        try {
            $payload = $change($asset->payload());

            if ($payload === null) {
                return false;
            }

            $asset->apply($payload);
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, $label);
            $this->renderCutscenesArea();

            return false;
        }

        $library->refreshRecords($type);
        $after = $this->snapshotCutscene($asset);

        if ($after === $before) {
            return false;
        }

        $this->recordCommand(new GenericCommand(
            $label,
            fn() => $this->restoreCutsceneSnapshot($type, $id, $after),
            fn() => $this->restoreCutsceneSnapshot($type, $id, $before),
        ));

        return true;
    }

    /**
     * @return array{payload: array<string, mixed>, deleted: bool}
     */
    private function snapshotCutscene(CutsceneAsset $asset): array
    {
        return ['payload' => $asset->payload(), 'deleted' => $asset->isDeleted()];
    }

    /**
     * Puts an asset back to a recorded state, and the screen onto it.
     *
     * @param array{payload: array<string, mixed>, deleted: bool} $snapshot
     */
    private function restoreCutsceneSnapshot(CutsceneType $type, string $id, array $snapshot): void
    {
        $library = $this->cutsceneLibrary();
        $asset = $library?->find($type, $id);

        if ($library === null || $asset === null) {
            return;
        }

        $asset->apply($snapshot['payload']);
        $asset->markDeleted($snapshot['deleted']);
        $library->refreshRecords($type);

        if ($this->isCutscenesOpen) {
            if ($this->cutsceneType !== $type) {
                $this->switchCutsceneType($type);
            }

            $this->clampCutsceneSelection();
            $this->selectCutsceneById($id);
            $this->clampDatabaseSettingSelection();
            $this->renderCutscenesArea();
        }
    }

    /**
     * Applies one record-pane edit to the selected asset, recorded.
     *
     * @param array<string, mixed> $field The settings field descriptor.
     */
    private function applyCutsceneFieldValueRecorded(array $field, string $rawValue): void
    {
        $fieldId = (string) ($field['field'] ?? '');

        if ($fieldId === '' || ($field['editable'] ?? true) === false) {
            return;
        }

        $framePath = $this->databaseCommandFramePath;
        $this->mutateSelectedCutscene(
            sprintf('%s edit', $field['label'] ?? 'Cutscene field'),
            static function (ProjectRecordDatabase $records, int $index) use ($framePath, $fieldId, $rawValue): void {
                $records->setFrameField($index, $framePath, $fieldId, $rawValue);
            },
        );
    }

    /**
     * Adds what the cursor's context calls for: a cast member, a command
     * after the cursor's command in the open frame, a lane, a route step,
     * a keyframe, a cue.
     */
    private function addCutsceneSubItem(): void
    {
        $records = $this->cutsceneRecords();

        if ($records === null) {
            return;
        }

        $framePath = $this->databaseCommandFramePath;
        $selectedId = (string) ($this->getDatabaseSettingsFields()[$this->databaseSelectedSettingIndex]['field'] ?? '');
        $index = $this->getSelectedRecordIndex();
        $added = null;

        $changed = $this->mutateSelectedCutscene('Cutscene add', function (ProjectRecordDatabase $records, int $index) use ($framePath, $selectedId, &$added): void {
            if ($framePath !== []) {
                $nested = $records->frameNestedContext($index, $framePath, $selectedId);

                if ($nested !== null) {
                    $added = $records->addFrameNestedItem($index, $framePath, $nested['parentIndex']);
                    $added = $added === null ? null : ['nested', $nested['parentIndex'], $added];

                    return;
                }

                $subList = $records->schema->commandLists[strval($framePath[0])] ?? null;
                $prefix = $subList instanceof RecordSubList ? preg_quote($subList->prefix, '/') : 'command';

                if (preg_match('/^' . $prefix . '(\d+)Option(\d+)/', $selectedId, $matches) === 1) {
                    $option = $records->addChoiceOption($index, $framePath, intval($matches[1]));
                    $added = $option === null ? null : ['option', intval($matches[1]), $option];

                    return;
                }

                $after = preg_match('/^' . $prefix . '(\d+)/', $selectedId, $matches) === 1 ? intval($matches[1]) : null;
                $command = $records->addFrameCommand($index, $framePath, $after);
                $added = $command === null ? null : ['command', $command];

                return;
            }

            $nested = $records->nestedSubListContext($index, $selectedId);

            if ($nested !== null) {
                $item = $records->addNestedSubItem($index, $nested['parentIndex']);
                $added = $item === null ? null : ['nested', $nested['parentIndex'], $item];

                return;
            }

            $entry = $records->addSubItem($index);
            $added = $entry === null ? null : ['entry', $entry];
        });

        if (! $changed || $added === null) {
            if ($records->schema->subList === null && $framePath === []) {
                $this->setStatus('Open Commands, Finalizer, Tracks or Cues to add to it.', StatusLevel::INFO);
                $this->renderCutscenesArea();
            }

            return;
        }

        // Put the cursor on what was added.
        $fields = $this->getDatabaseSettingsFields();
        $target = null;

        foreach ($fields as $position => $field) {
            $id = (string) ($field['field'] ?? '');
            $matches = match ($added[0]) {
                'command' => preg_match('/^[a-z]+' . $added[1] . 'Type$/', $id) === 1,
                'entry' => preg_match('/^cast' . $added[1] . '[A-Z]/', $id) === 1,
                'option' => preg_match('/^[a-z]+' . $added[1] . 'Option' . $added[2] . 'Text$/', $id) === 1,
                'nested' => preg_match('/^[a-z]+' . $added[1] . '[A-Z][a-z]+' . $added[2] . '[A-Z]/', $id) === 1,
                default => false,
            };

            if ($matches) {
                $target = $position;

                break;
            }
        }

        if ($target !== null) {
            $this->databaseSelectedSettingIndex = $target;
        }

        $this->cutsceneFocus = CutscenesScreen::PANE_SETTINGS;
        $this->setStatus('Added.', StatusLevel::SUCCESS);
        $this->renderCutscenesArea();
    }

    /**
     * Removes what the cursor points at, or the last entry of the cursor's
     * list: a command from the open frame, an option with its arm, a lane, a
     * step, a keyframe, a cue, a cast member.
     */
    private function removeCutsceneSubItem(bool $last): void
    {
        $records = $this->cutsceneRecords();

        if ($records === null) {
            return;
        }

        $framePath = $this->databaseCommandFramePath;
        $selectedId = (string) ($this->getDatabaseSettingsFields()[$this->databaseSelectedSettingIndex]['field'] ?? '');
        $removed = false;

        $changed = $this->mutateSelectedCutscene('Cutscene remove', function (ProjectRecordDatabase $records, int $index) use ($framePath, $selectedId, $last, &$removed): void {
            if ($framePath !== []) {
                $nested = $records->frameNestedContext($index, $framePath, $selectedId);

                if ($nested !== null && ($nested['nestedIndex'] !== null || $last)) {
                    $count = $records->countFrameNestedItems($index, $framePath, $nested['parentIndex']);
                    $at = $last ? $count - 1 : $nested['nestedIndex'];

                    if ($at !== null && $at >= 0) {
                        $removed = $records->removeFrameNestedItem($index, $framePath, $nested['parentIndex'], $at) !== null;
                    }

                    return;
                }

                $subList = $records->schema->commandLists[strval($framePath[0])] ?? null;
                $prefix = $subList instanceof RecordSubList ? preg_quote($subList->prefix, '/') : 'command';

                if (preg_match('/^' . $prefix . '(\d+)Option(\d+)/', $selectedId, $matches) === 1) {
                    $removed = $records->removeChoiceOption($index, $framePath, intval($matches[1]), intval($matches[2])) !== null;

                    return;
                }

                $commands = $records->getFrameCommands($index, $framePath) ?? [];
                $at = $last
                    ? count($commands) - 1
                    : (preg_match('/^' . $prefix . '(\d+)/', $selectedId, $matches) === 1 ? intval($matches[1]) : null);

                if ($at !== null && $at >= 0) {
                    $removed = $records->removeFrameCommand($index, $framePath, $at) !== null;
                }

                return;
            }

            $nested = $records->nestedSubListContext($index, $selectedId);

            if ($nested !== null && ($nested['nestedIndex'] !== null || $last)) {
                $count = $records->countNestedSubItems($index, $nested['parentIndex']);
                $at = $last ? $count - 1 : $nested['nestedIndex'];

                if ($at !== null && $at >= 0) {
                    $removed = $records->removeNestedSubItem($index, $nested['parentIndex'], $at) !== null;
                }

                return;
            }

            $subList = $records->schema->subList;

            if ($subList === null) {
                return;
            }

            $count = $records->countSubItems($index);
            $at = $last
                ? $count - 1
                : (preg_match('/^' . preg_quote($subList->prefix, '/') . '(\d+)/', $selectedId, $matches) === 1 ? intval($matches[1]) : null);

            if ($at !== null && $at >= 0) {
                $removed = $records->removeSubItem($index, $at) !== null;
            }
        });

        if (! $changed || ! $removed) {
            return;
        }

        $this->clampDatabaseSettingSelection();
        $this->setStatus('Removed. Ctrl+Z restores it.', StatusLevel::SUCCESS);
        $this->renderCutscenesArea();
    }

    // -- Create, duplicate, delete, save -----------------------------------

    /**
     * Creates a new asset of the shown type, named and placed by the
     * schema's blank, and selects it.
     */
    private function createCutscene(): void
    {
        $library = $this->cutsceneLibrary();
        $records = $this->cutsceneRecords();

        if ($library === null || $records === null) {
            return;
        }

        $type = $this->cutsceneType;
        $id = $library->freeId($type, strval($records->schema->blank['id'] ?? ('new-' . $type->noun())));
        $payload = $records->schema->blank;
        $payload['id'] = $id;

        try {
            $created = CutsceneAsset::create($type, $id, $library->rootFor($type), $payload, $this->workspace?->projectRoot);
            $library->adopt($created);
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Cutscene creation');
            $this->renderCutscenesArea();

            return;
        }

        $this->recordCommand(new GenericCommand(
            sprintf('Create %s', $type->noun()),
            fn() => $this->restoreCutsceneSnapshot($type, $id, ['payload' => $payload, 'deleted' => false]),
            fn() => $this->restoreCutsceneSnapshot($type, $id, ['payload' => $payload, 'deleted' => true]),
        ));

        $this->leaveCutsceneEditingState();
        $this->cutsceneFilter->clear();
        $this->selectCutsceneById($id);
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseCommandFramePath = [];
        $this->cutsceneFocus = CutscenesScreen::PANE_SETTINGS;
        $this->setStatus(sprintf('Created %s "%s". It reaches disk on save.', $type->noun(), $id), StatusLevel::SUCCESS);
        $this->renderCutscenesArea(includeRoot: true);
    }

    /**
     * Duplicates the selected asset under a free id.
     */
    private function duplicateSelectedCutscene(): void
    {
        $library = $this->cutsceneLibrary();
        $asset = $this->selectedCutscene();

        if ($library === null || $asset === null) {
            return;
        }

        $type = $asset->type;
        $newId = $library->freeId($type, $asset->id . '-copy');

        try {
            $copy = $library->duplicate($type, $asset->id, $newId);
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Cutscene duplication');
            $this->renderCutscenesArea();

            return;
        }

        $payload = $copy->payload();
        $this->recordCommand(new GenericCommand(
            sprintf('Duplicate %s', $type->noun()),
            fn() => $this->restoreCutsceneSnapshot($type, $newId, ['payload' => $payload, 'deleted' => false]),
            fn() => $this->restoreCutsceneSnapshot($type, $newId, ['payload' => $payload, 'deleted' => true]),
        ));

        $this->leaveCutsceneEditingState();
        $this->selectCutsceneById($newId);
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseCommandFramePath = [];
        $this->setStatus(sprintf('Duplicated as "%s". It reaches disk on save.', $newId), StatusLevel::SUCCESS);
        $this->renderCutscenesArea(includeRoot: true);
    }

    /**
     * Asks before deleting the selected asset, naming what references it.
     */
    private function openCutsceneDeleteConfirmation(): void
    {
        $asset = $this->selectedCutscene();

        if ($asset === null) {
            $this->setStatus(sprintf('No %s to delete.', $this->cutsceneType->noun()), StatusLevel::WARN);
            $this->renderFooter();

            return;
        }

        $references = $this->describeCutsceneReferences($asset);
        $this->pendingDatabaseDeletion = [
            'category' => $this->cutsceneCategoryDefinition()->key,
            'index' => $this->getSelectedRecordIndex(),
            'label' => sprintf('%s "%s"', $asset->type->noun(), $asset->name()),
            'references' => $references,
            'cutscene' => [$asset->type->value, $asset->id],
        ];
        $this->isDatabaseEntryDeleteConfirmationOpen = true;
        $this->requestFullRender();
    }

    /**
     * Returns who points at an asset: maps whose triggers launch a
     * cinematic, actors who start with a summon.
     *
     * @return string[]
     */
    private function describeCutsceneReferences(CutsceneAsset $asset): array
    {
        $references = [];

        if ($asset->type === CutsceneType::CINEMATIC) {
            foreach ($this->workspace?->maps ?? [] as $map) {
                foreach ($map->getEventDefinitions() as $marker => $definition) {
                    if (is_array($definition) && strval(($definition['data'] ?? [])['cinematicId'] ?? '') === $asset->id) {
                        $references[] = sprintf('map %s event %s', $map->mapId, strval($marker));
                    }
                }
            }
        } else {
            foreach ($this->workspace?->actorDatabase->getActors() ?? [] as $actor) {
                $summons = $actor->getSummons();

                if (is_array($summons) && in_array($asset->id, array_map(strval(...), array_filter($summons, 'is_scalar')), true)) {
                    $references[] = sprintf('actor %s', $actor->getName());
                }
            }
        }

        return $references;
    }

    /**
     * Marks the selected asset deleted; the folder goes on save.
     */
    private function deleteSelectedCutscene(): void
    {
        $asset = $this->selectedCutscene();

        if ($asset === null) {
            return;
        }

        $this->leaveCutsceneEditingState();
        $changed = $this->mutateSelectedCutscene(
            sprintf('Delete %s %s', $asset->type->noun(), $asset->name()),
            static function (ProjectRecordDatabase $records, int $index): void {
                $records->removeRecord($index);
            },
        );

        if ($changed) {
            $this->clampCutsceneSelection();
            $this->databaseSelectedSettingIndex = 0;
            $this->databaseCommandFramePath = [];
            $this->setStatus(sprintf('Deleted %s. Ctrl+Z restores it; the folder is removed on save.', $asset->name()), StatusLevel::SUCCESS);
        }

        $this->renderCutscenesArea(includeRoot: true);
    }

    /**
     * Saves the selected asset as one paired transaction.
     */
    private function saveSelectedCutscene(): void
    {
        $library = $this->cutsceneLibrary();
        $asset = $this->selectedCutscene() ?? $this->firstDeletedCutscene();

        if ($library === null || $asset === null) {
            $this->setStatus(sprintf('No %s to save.', $this->cutsceneType->noun()), StatusLevel::WARN);
            $this->renderFooter();

            return;
        }

        try {
            $written = $library->save($asset->type, $asset->id, fn(string ...$paths) => $this->backupBeforeSave(...$paths));
            $this->setStatus(
                $written
                    ? sprintf('%s "%s" saved.', ucfirst($asset->type->noun()), $asset->id)
                    : sprintf('%s "%s" is clean; nothing written.', ucfirst($asset->type->noun()), $asset->id),
                StatusLevel::SUCCESS,
            );
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, sprintf('%s save', ucfirst($asset->type->noun())));
        }

        $this->clampCutsceneSelection();
        $this->renderCutscenesArea(includeRoot: true);
    }

    /**
     * Returns a deleted-but-unsaved asset of the shown type, which the
     * list no longer shows but a save still has to remove.
     */
    private function firstDeletedCutscene(): ?CutsceneAsset
    {
        foreach ($this->cutsceneLibrary()?->assets($this->cutsceneType) ?? [] as $asset) {
            if ($asset->isDeleted() && $asset->isDirty()) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Saves every dirty asset of both types, for Save All.
     *
     * @return array{saved: string[], failed: array<string, string>}
     */
    private function saveAllCutscenes(): array
    {
        $library = $this->cutsceneLibrary();

        if ($library === null) {
            return ['saved' => [], 'failed' => []];
        }

        $result = $library->saveAll(fn(string ...$paths) => $this->backupBeforeSave(...$paths));

        if ($this->isCutscenesOpen) {
            $this->clampCutsceneSelection();
            $this->renderCutscenesArea(includeRoot: true);
        }

        return $result;
    }

    // -- Multiline editing -----------------------------------------------------

    /**
     * Opens the multiline editor on a settings field.
     *
     * @param array<string, mixed> $field
     */
    private function openMultilineEditor(array $field): void
    {
        $control = $this->getDatabaseFieldControl($field);
        $this->multilineEditor->open(
            (string) ($field['field'] ?? ''),
            (string) ($field['label'] ?? 'Text'),
            $control?->rawValue ?? '',
        );
        $this->setStatus(sprintf('Editing %s: Enter for a new line, Ctrl+S applies, Esc cancels.', $field['label'] ?? 'text'));
        $this->renderDatabasePanes(['settings']);
    }

    /**
     * Handles keys while the multiline editor is open.
     */
    private function handleMultilineEditorInput(string $input): void
    {
        if ($input === "\033" || $input === "\x1b") {
            $this->multilineEditor->close();
            $this->setStatus('Edit cancelled.');
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($input === "\x13") {
            $fieldId = $this->multilineEditor->fieldId();
            $text = $this->multilineEditor->text();
            $changed = $this->multilineEditor->isChanged();
            $label = $this->multilineEditor->label();
            $this->multilineEditor->close();

            if ($changed) {
                $field = null;

                foreach ($this->getDatabaseSettingsFields() as $candidate) {
                    if (is_array($candidate) && ($candidate['field'] ?? null) === $fieldId) {
                        $field = $candidate;

                        break;
                    }
                }

                if (is_array($field)) {
                    try {
                        $this->applyDatabaseFieldValueRecorded($field, $text);
                        $this->setStatus(sprintf('%s applied.', $label), StatusLevel::SUCCESS);
                    } catch (Throwable $throwable) {
                        $this->setErrorStatus($throwable, $label);
                    }
                }
            } else {
                $this->setStatus(sprintf('%s unchanged.', $label));
            }

            $this->renderDatabasePanes(['settings', 'tree', 'preview']);

            return;
        }

        if ($this->multilineEditor->handle($input)) {
            $this->renderDatabasePanes(['settings']);
        }
    }

    /**
     * Paints the multiline editor over the settings pane when it is open.
     *
     * @param array<string, int> $layout The Cutscenes layout.
     */
    private function renderMultilineEditorOverlay(array $layout): void
    {
        if (! $this->multilineEditor->isOpen()) {
            return;
        }

        $width = max(30, $layout['settingsWidth']);
        $height = max(8, $layout['settingsHeight']);
        $x = $layout['settingsX'];
        $y = $layout['settingsY'];
        $contentWidth = $this->getWindowContentWidth($width);
        $contentHeight = $height - 2;
        $viewport = $this->multilineEditor->viewport($contentWidth, $contentHeight);
        $lines = $viewport['lines'];

        while (count($lines) < $contentHeight) {
            $lines[] = '';
        }

        new EditorWindow(
            title: sprintf('%s (multiline)', $this->multilineEditor->label()),
            help: $this->fitHelp($width, 'Enter:New line  Ctrl+S:Apply  Esc:Cancel', 'Ctrl+S:Apply  Esc:Cancel', 'Ctrl+S:Apply'),
            position: ['x' => $x, 'y' => $y],
            width: $width,
            height: $height,
            foregroundColor: Color::LIGHT_BLUE,
            content: $lines,
        )->render();

        Console::cursor()->show();
        Console::cursor()->moveTo(
            $x + 1 + self::WINDOW_HORIZONTAL_PADDING + $viewport['caretColumn'],
            $y + 1 + $viewport['caretRow'],
        );
    }

    // -- Layout and rendering ----------------------------------------------

    /**
     * Resolves the screen's layout for the terminal size: types and assets
     * down the left, the record pane and the command tree across the top,
     * and the preview along the bottom; narrower terminals stack the tree
     * under the record pane.
     *
     * @param array<string, int> $layout The editor layout.
     * @return array<string, int>
     */
    private function resolveCutscenesLayout(array $layout): array
    {
        $rootX = 2;
        $rootY = 1;
        $rootWidth = $layout['width'] - 2;
        $rootHeight = $layout['height'] - 1;
        $innerX = $rootX + 1;
        $innerY = $rootY + 2;
        $innerWidth = $rootWidth - 2;
        $innerHeight = $rootHeight - 4;
        $gutter = 1;
        $typesWidth = 14;
        $typesHeight = 4;
        $listWidth = max(18, min(26, intdiv($innerWidth, 5)));
        $rightX = $innerX + $typesWidth + $gutter;
        $rightWidth = max(20, $innerWidth - $typesWidth - $gutter);
        $listHeight = max(3, $innerHeight - $typesHeight - $gutter);
        $paneX = $rightX + $listWidth + $gutter;
        $paneWidth = max(20, $rightWidth - $listWidth - $gutter);
        $previewHeight = $this->isCutscenePreviewExpanded()
            ? max(10, intdiv($innerHeight, 2))
            : max(6, min(12, intdiv($innerHeight, 3)));
        $topHeight = max(6, $innerHeight - $previewHeight - $gutter);
        $stacked = $paneWidth < 80;

        if ($stacked) {
            $settingsWidth = $paneWidth;
            $settingsHeight = max(4, intdiv($topHeight * 3, 5));
            $treeX = $paneX;
            $treeY = $innerY + $settingsHeight + $gutter;
            $treeWidth = $paneWidth;
            $treeHeight = max(3, $topHeight - $settingsHeight - $gutter);
        } else {
            $settingsWidth = max(34, intdiv($paneWidth * 11, 20));
            $settingsHeight = $topHeight;
            $treeX = $paneX + $settingsWidth + $gutter;
            $treeY = $innerY;
            $treeWidth = max(20, $paneWidth - $settingsWidth - $gutter);
            $treeHeight = $topHeight;
        }

        return [
            'rootX' => $rootX,
            'rootY' => $rootY,
            'rootWidth' => $rootWidth,
            'rootHeight' => $rootHeight,
            'innerX' => $innerX,
            'innerY' => $innerY,
            'innerWidth' => $innerWidth,
            'innerHeight' => $innerHeight,
            'gutter' => $gutter,
            'typesX' => $innerX,
            'typesY' => $innerY,
            'typesWidth' => $typesWidth,
            'typesHeight' => $typesHeight,
            'listX' => $rightX,
            'listY' => $innerY,
            'listWidth' => $listWidth,
            'listHeight' => $topHeight,
            'settingsX' => $paneX,
            'settingsY' => $innerY,
            'settingsWidth' => $settingsWidth,
            'settingsHeight' => $settingsHeight,
            'treeX' => $treeX,
            'treeY' => $treeY,
            'treeWidth' => $treeWidth,
            'treeHeight' => $treeHeight,
            'previewX' => $rightX,
            'previewY' => $innerY + $topHeight + $gutter,
            'previewWidth' => $rightWidth,
            'previewHeight' => $previewHeight,
            'stacked' => $stacked ? 1 : 0,
            'unusedListHeight' => $listHeight,
        ];
    }

    /**
     * Queues the whole screen for repaint.
     */
    private function renderCutscenesArea(bool $includeRoot = false): void
    {
        $this->cutscenesScreen->markAllDirty($includeRoot);
    }

    /**
     * Paints the screen when it has pending panes. Called from the frame
     * loop where the Database screen flushes.
     */
    private function flushCutscenesScreen(): void
    {
        if ($this->isCutscenesOpen) {
            $this->cutscenesScreen->flush();
        }
    }

    private function resolveCutscenePaneColor(string $pane): Color
    {
        return $this->cutsceneFocus === $pane ? Color::LIGHT_BLUE : Color::WHITE;
    }

    /**
     * @param array<string, int> $layout
     */
    private function createCutscenesRootWindow(array $layout): EditorWindow
    {
        return new EditorWindow(
            title: 'Cutscenes',
            help: 'Esc:Close  ?:Help  Ctrl+P:Palette  Ctrl+S:Save  Ctrl+A:Save All',
            position: ['x' => $layout['rootX'], 'y' => $layout['rootY']],
            width: $layout['rootWidth'],
            height: $layout['rootHeight'],
            foregroundColor: Color::WHITE,
            content: array_fill(0, max(1, $layout['rootHeight'] - 2), ''),
        );
    }

    /**
     * @param array<string, int> $layout
     */
    private function createCutsceneTypesWindow(array $layout): EditorWindow
    {
        $lines = [];

        foreach (CutsceneType::cases() as $type) {
            $dirty = false;

            foreach ($this->cutsceneLibrary()?->assets($type) ?? [] as $asset) {
                if ($asset->isDirty()) {
                    $dirty = true;

                    break;
                }
            }

            $lines[] = ($type === $this->cutsceneType ? '> ' : '  ') . $type->label() . ($dirty ? ' *' : '');
        }

        return new EditorWindow(
            title: 'Types',
            help: '',
            position: ['x' => $layout['typesX'], 'y' => $layout['typesY']],
            width: $layout['typesWidth'],
            height: $layout['typesHeight'],
            foregroundColor: $this->resolveCutscenePaneColor(CutscenesScreen::PANE_TYPES),
            content: $this->fitLines($lines, $this->getWindowContentWidth($layout['typesWidth']), $layout['typesHeight'] - 2),
        );
    }

    /**
     * @param array<string, int> $layout
     */
    private function createCutsceneListWindow(array $layout): EditorWindow
    {
        $library = $this->cutsceneLibrary();
        $labels = $this->cutsceneEntryLabels();
        $visible = $this->visibleCutsceneIndexes();
        $lines = [];
        $selectedRow = 0;

        foreach ($visible as $row => $index) {
            $id = $library?->ids($this->cutsceneType)[$index] ?? '';
            $asset = $library?->find($this->cutsceneType, $id);
            $prefix = $index === $this->getSelectedRecordIndex() ? '> ' : '  ';
            $marks = ($asset?->isDirty() ? ' *' : '') . ($asset !== null && ! $asset->isEditable() ? ' (ro)' : '');
            $lines[] = $prefix . ($labels[$index] ?? $id) . $marks;

            if ($index === $this->getSelectedRecordIndex()) {
                $selectedRow = $row;
            }
        }

        if ($lines === []) {
            $lines[] = $labels === [] ? sprintf('  (no %ss yet)', $this->cutsceneType->noun()) : '  (no matches)';
        }

        $issues = $library?->issues($this->cutsceneType) ?? [];

        if ($issues !== []) {
            $lines[] = '';
            $lines[] = sprintf('  ! %d finding%s (Ctrl+E)', count($issues), count($issues) === 1 ? '' : 's');
        }

        $height = $layout['listHeight'];
        $contentHeight = max(1, $height - 2);
        $scroll = max(0, $selectedRow - $contentHeight + 1);

        return new EditorWindow(
            title: sprintf('%ss%s', $this->cutsceneType->label(), $this->cutsceneFilter->describe()),
            help: $this->fitHelp($layout['listWidth'], 'Shift+A:New  Shift+D:Dup  Del:Delete  /:Filter', 'A:New D:Dup Del /', '/:Filter'),
            position: ['x' => $layout['listX'], 'y' => $layout['listY']],
            width: $layout['listWidth'],
            height: $height,
            foregroundColor: $this->resolveCutscenePaneColor(CutscenesScreen::PANE_LIST),
            content: $this->fitLines(array_slice($lines, $scroll), $this->getWindowContentWidth($layout['listWidth']), $contentHeight),
        );
    }

    /**
     * @param array<string, int> $layout
     */
    private function createCutsceneSettingsWindow(array $layout): EditorWindow
    {
        $asset = $this->selectedCutscene();
        $title = $this->databaseCommandFramePath === []
            ? ($asset === null ? 'Settings' : sprintf('%s: %s', $this->cutsceneType->label(), $asset->id))
            : ($this->cutsceneRecords()?->describeFramePath($this->databaseCommandFramePath)
                ?? ProjectRecordDatabase::describeFrame($this->databaseCommandFramePath));
        $help = match (true) {
            $this->referencePicker->isOpen() => $this->fitHelp($layout['settingsWidth'], 'Enter:Choose  Type:Filter  Esc:Cancel', 'Enter:Choose  Esc:Cancel'),
            $this->isDatabaseEditing => 'Enter:Apply  Esc:Cancel',
            $this->conditionEditor->isOpen() => $this->fitHelp($layout['settingsWidth'], 'a:Add  d:Del  t:Type  n:Name  x:Value  !:Not  Enter:Done  Esc:Cancel', 'a/d:Add/Del  t/n/x:Edit  Enter:Done'),
            $this->databaseCommandFramePath !== [] => $this->fitHelp($layout['settingsWidth'], 'Enter:Edit/Open  Esc:Back  Shift+O:Add  Shift+X/Del:Remove', 'Enter:Open  Esc:Back  Shift+O/Del', 'Esc:Back'),
            default => $this->fitHelp($layout['settingsWidth'], 'Enter:Edit/Open  Shift+O:Add  Shift+X/Del:Remove', 'Enter:Edit  Shift+O/Del', 'Enter:Edit'),
        };

        return new EditorWindow(
            title: $title,
            help: $help,
            position: ['x' => $layout['settingsX'], 'y' => $layout['settingsY']],
            width: $layout['settingsWidth'],
            height: $layout['settingsHeight'],
            foregroundColor: $this->resolveCutscenePaneColor(CutscenesScreen::PANE_SETTINGS),
            content: $this->fitLines(
                $this->getCutsceneSettingsLines(),
                $this->getWindowContentWidth($layout['settingsWidth']),
                $layout['settingsHeight'] - 2,
            ),
        );
    }

    /**
     * Returns the settings pane's lines: a sub-editor's rows while one is
     * open, otherwise the record pane's.
     *
     * @return string[]
     */
    private function getCutsceneSettingsLines(): array
    {
        $fields = $this->getDatabaseSettingsFields();

        if ($fields === []) {
            $library = $this->cutsceneLibrary();

            if ($library === null || $library->ids($this->cutsceneType) === []) {
                return [
                    sprintf('No %ss yet.', $this->cutsceneType->noun()),
                    '',
                    'Shift+A in the list creates one.',
                    '',
                    $this->cutsceneType === CutsceneType::CINEMATIC
                        ? 'A cinematic is a staged story scene: a command tree the engine runs on the field, with its own cast, camera, skip policy and finalizer.'
                        : 'A summon is a frame-driven battle presentation: tracks, keyframes and cues the engine compiles and plays.',
                ];
            }

            return ['Select an entry to edit it.'];
        }

        if ($this->referencePicker->isOpen()) {
            return $this->buildReferencePickerRows();
        }

        if ($this->conditionEditor->isOpen()) {
            return $this->buildConditionEditorRows();
        }

        if ($this->affinityEditor->isOpen()) {
            return $this->buildAffinityEditorRows();
        }

        if ($this->worldWriteEditor->isOpen()) {
            return $this->buildWorldWriteEditorRows();
        }

        return $this->recordPaneLayout($fields)->visibleLines();
    }

    /**
     * Places the caret while a settings field is typed into.
     *
     * @param array<string, int> $layout
     */
    private function renderCutsceneEditCursor(array $layout): void
    {
        $fields = $this->getDatabaseSettingsFields();
        $field = $fields[$this->databaseSelectedSettingIndex] ?? null;

        if (! is_array($field)) {
            Console::cursor()->hide();

            return;
        }

        $label = (string) ($field['label'] ?? 'Field');
        $leftText = sprintf('> %s: ', $label);
        $contentWidth = $this->getWindowContentWidth($layout['settingsWidth']);
        $availableValueWidth = max(1, $contentWidth - mb_strwidth($leftText));
        $visibleStart = max(0, $this->databaseEditCursorIndex - $availableValueWidth + 1);
        $visibleValue = mb_substr($this->databaseEditBuffer, $visibleStart, $availableValueWidth);
        $visibleCursorIndex = max(0, min($this->databaseEditCursorIndex - $visibleStart, mb_strlen($visibleValue)));
        $cursorOffset = min($contentWidth - 1, mb_strwidth($leftText . mb_substr($visibleValue, 0, $visibleCursorIndex)));
        $row = $this->recordPaneLayout($fields)->rowOfField($this->databaseSelectedSettingIndex);

        if ($row === null) {
            Console::cursor()->hide();

            return;
        }

        Console::cursor()->show();
        Console::cursor()->moveTo(
            $layout['settingsX'] + 1 + self::WINDOW_HORIZONTAL_PADDING + $cursorOffset,
            $layout['settingsY'] + 1 + $row,
        );
    }
}
