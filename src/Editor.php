<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Atatusoft\Termutil\Events\MouseEvent;
use Atatusoft\Termutil\IO\Console\Console;
use Atatusoft\Termutil\IO\Enumerations\Color;
use Atatusoft\Termutil\IO\Enumerations\MouseTrackingMode;
use Atatusoft\Termutil\IO\Mouse\Enumerations\MouseButton;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\DatabaseCategoryDefinition;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\Inspector\InputControl;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Animations\AnimationPlayer;
use Ichiloto\Engine\Animations\AnimationTargetPosition;
use RuntimeException;
use Throwable;

/**
 * Launches the Ichiloto terminal editor shell.
 */
final class Editor
{
    private const string FOCUS_ASSETS = 'assets';
    private const string FOCUS_CANVAS = 'canvas';
    private const string FOCUS_INSPECTOR = 'inspector';
    private const string MODE_MAP = 'map';
    private const string MODE_EVENT = 'event';
    private const string DATABASE_CATEGORY_ACTORS = 'actors';
    private const string DATABASE_CATEGORY_ANIMATIONS = 'animations';
    private const string DATABASE_FOCUS_CATEGORIES = 'database_categories';
    private const string DATABASE_FOCUS_LIST = 'database_list';
    private const string DATABASE_FOCUS_SETTINGS = 'database_settings';
    private const string DATABASE_FOCUS_FRAMES = 'database_frames';
    private const string DATABASE_FOCUS_PREVIEW = 'database_preview';
    private const int CHARACTER_MAP_COLUMNS = 8;
    private const int WINDOW_HORIZONTAL_PADDING = 1;

    private bool $isRunning = false;
    private ?ProjectWorkspace $workspace = null;
    private int $selectedAssetIndex = 0;
    private string $focusedPane = self::FOCUS_ASSETS;
    private string $editingMode = self::MODE_MAP;
    private int $cursorX = 0;
    private int $cursorY = 0;
    private int $canvasOffsetX = 0;
    private int $canvasOffsetY = 0;
    private bool $showEventOverlay = false;
    private bool $isCharacterMapOpen = false;
    private bool $isDeleteConfirmationOpen = false;
    private bool $isEventTypeDialogOpen = false;
    private bool $isDestinationDialogOpen = false;
    private bool $isDestinationSpawnSelectionOpen = false;
    private bool $isDestinationSpawnConfirmationOpen = false;
    private bool $isDatabaseOpen = false;
    private string $selectedPaintSymbol = ' ';
    private ?MouseButton $activeMousePaintButton = null;
    /**
     * @var array{x: int, y: int}|null
     */
    private ?array $lastMousePaintPoint = null;
    private int $characterPaletteIndex = 0;
    private int $selectedInspectorFieldIndex = 0;
    private int $selectedEventTypeIndex = 0;
    private int $selectedDestinationIndex = 0;
    private ?string $eventTypeDialogMarker = null;
    /**
     * @var string[]|null
     */
    private ?array $destinationDialogPath = null;
    private ?string $destinationDialogMarker = null;
    /**
     * @var array{
     *   sourceMapIndex: int,
     *   sourceCursorX: int,
     *   sourceCursorY: int,
     *   sourceCanvasOffsetX: int,
     *   sourceCanvasOffsetY: int,
     *   sourceFocusedPane: string,
     *   sourceEditingMode: string,
     *   sourceShowEventOverlay: bool,
     *   sourceInspectorFieldIndex: int,
     *   marker: string,
     *   path: string[],
     *   destinationMapId: string
     * }|null
     */
    private ?array $destinationSelectionContext = null;
    private bool $isInspectorEditing = false;
    private string $inspectorEditBuffer = '';
    private int $inspectorEditCursorIndex = 0;
    private int $databaseCategoryIndex = 9;
    private string $databaseFocus = self::DATABASE_FOCUS_LIST;
    private int $databaseSelectedActorIndex = 0;
    private int $databaseSelectedAnimationIndex = 0;
    private int $databaseSelectedSettingIndex = 0;
    private int $databaseSelectedFrameIndex = 1;
    private int $databasePreviewCursorX = 0;
    private int $databasePreviewCursorY = 0;
    private bool $isDatabaseEditing = false;
    private string $databaseEditBuffer = '';
    private int $databaseEditCursorIndex = 0;
    private string $databaseSelectedPaintSymbol = '*';
    private ?string $databaseSelectedPaintColor = 'white';
    private bool $isDatabasePreviewPlaying = false;
    private int $databasePlaybackFrameIndex = 1;
    private ?string $databasePlaybackFlashColor = null;
    private string $statusMessage = 'Ready.';
    /**
     * @var array{width: int, height: int}|null
     */
    private ?array $lastTerminalSize = null;
    private string $previousTerminalSettings = '';
    private bool $usesAlternateScreen = false;

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * Starts the editor session.
     *
     * @return void
     */
    public function run(): void
    {
        $this->boot();

        try {
            while ($this->isRunning) {
                $this->renderIfNeeded();
                $this->handleInput();
                usleep(50_000);
            }
        } finally {
            $this->shutdown();
        }
    }

    /**
     * Initializes terminal state and loads the workspace.
     *
     * @return void
     */
    private function boot(): void
    {
        $this->workspace = ProjectWorkspace::fromProject($this->projectRoot);
        $this->selectedAssetIndex = 0;
        $this->focusedPane = self::FOCUS_ASSETS;
        $this->editingMode = self::MODE_MAP;
        $this->cursorX = 0;
        $this->cursorY = 0;
        $this->canvasOffsetX = 0;
        $this->canvasOffsetY = 0;
        $this->showEventOverlay = false;
        $this->isCharacterMapOpen = false;
        $this->isDeleteConfirmationOpen = false;
        $this->isEventTypeDialogOpen = false;
        $this->isDestinationDialogOpen = false;
        $this->isDestinationSpawnSelectionOpen = false;
        $this->isDestinationSpawnConfirmationOpen = false;
        $this->isDatabaseOpen = false;
        $this->selectedPaintSymbol = ' ';
        $this->activeMousePaintButton = null;
        $this->lastMousePaintPoint = null;
        $this->characterPaletteIndex = 0;
        $this->selectedInspectorFieldIndex = 0;
        $this->selectedEventTypeIndex = 0;
        $this->selectedDestinationIndex = 0;
        $this->eventTypeDialogMarker = null;
        $this->destinationDialogPath = null;
        $this->destinationDialogMarker = null;
        $this->destinationSelectionContext = null;
        $this->isInspectorEditing = false;
        $this->inspectorEditBuffer = '';
        $this->inspectorEditCursorIndex = 0;
        $this->databaseCategoryIndex = DatabaseCatalog::indexOf(self::DATABASE_CATEGORY_ANIMATIONS);
        $this->databaseFocus = self::DATABASE_FOCUS_LIST;
        $this->databaseSelectedActorIndex = 0;
        $this->databaseSelectedAnimationIndex = 0;
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseSelectedFrameIndex = 1;
        $this->databasePreviewCursorX = 0;
        $this->databasePreviewCursorY = 0;
        $this->isDatabaseEditing = false;
        $this->databaseEditBuffer = '';
        $this->databaseEditCursorIndex = 0;
        $this->databaseSelectedPaintSymbol = '*';
        $this->databaseSelectedPaintColor = 'white';
        $this->isDatabasePreviewPlaying = false;
        $this->databasePlaybackFrameIndex = 1;
        $this->databasePlaybackFlashColor = null;
        $this->statusMessage = 'Ready.';
        $this->previousTerminalSettings = trim((string) shell_exec('stty -g'));
        shell_exec('stty -icanon -echo -ixon -ixoff min 0 time 1');
        $this->usesAlternateScreen = (string) getenv('TMUX') === '';

        if ($this->usesAlternateScreen) {
            echo "\033[?1049h\033[2J\033[H";
        }

        $size = $this->getTerminalSize();
        Console::saveSettings();
        Console::init([
            'width' => $size['width'],
            'height' => $size['height'],
        ]);
        Console::setName("Ichiloto Editor - {$this->workspace->projectName}");
        Console::enableMouseReporting(MouseTrackingMode::CELL_MOTION_TRACKING);
        Console::cursor()->hide();

        $this->lastTerminalSize = $size;
        $this->isRunning = true;
        $this->render();
    }

    /**
     * Restores the terminal after the editor exits.
     *
     * @return void
     */
    private function shutdown(): void
    {
        $this->isRunning = false;

        if ($this->previousTerminalSettings !== '') {
            shell_exec('stty ' . $this->previousTerminalSettings);
        }

        echo Color::RESET->value;
        Console::disableMouseReporting();
        Console::cursor()->show();
        Console::restoreSettings();

        if ($this->usesAlternateScreen) {
            echo "\033[?1049l";
        }
    }

    /**
     * Re-renders the shell when the terminal size changes.
     *
     * @return void
     */
    private function renderIfNeeded(): void
    {
        $size = $this->getTerminalSize();

        if ($this->lastTerminalSize === $size) {
            return;
        }

        Console::init([
            'width' => $size['width'],
            'height' => $size['height'],
        ]);

        $this->lastTerminalSize = $size;
        $this->clampCanvasOffsets();
        $this->render();
    }

    /**
     * Handles one tick of editor keyboard input.
     *
     * @return void
     */
    private function handleInput(): void
    {
        $input = $this->readInputSequence();

        if ($input === '') {
            return;
        }

        $normalizedInput = strtolower($input);

        if ($this->isDatabaseOpen) {
            $this->handleDatabaseInput($input, $normalizedInput);
            return;
        }

        if ($input === '!') {
            $this->openDatabaseWindow();
            return;
        }

        if ($this->isDestinationSpawnConfirmationOpen) {
            $this->handleDestinationSpawnConfirmationInput($input, $normalizedInput);
            return;
        }

        if ($this->isDestinationSpawnSelectionOpen) {
            $this->handleDestinationSpawnSelectionInput($input, $normalizedInput);
            return;
        }

        if ($this->isDestinationDialogOpen) {
            $this->handleDestinationDialogInput($input, $normalizedInput);
            return;
        }

        if ($this->isEventTypeDialogOpen) {
            $this->handleEventTypeDialogInput($input, $normalizedInput);
            return;
        }

        if ($this->handleMouseInput($input)) {
            return;
        }

        if ($this->isCharacterMapOpen) {
            $this->handleCharacterMapInput($input, $normalizedInput);
            return;
        }

        if ($this->isDeleteConfirmationOpen) {
            $this->handleDeleteConfirmationInput($input, $normalizedInput);
            return;
        }

        if ($this->isInspectorEditing) {
            $this->handleInspectorEditingInput($input);
            return;
        }

        if ($input === "\x11") {
            $this->isRunning = false;
            return;
        }

        if ($input === "\t") {
            $this->cycleFocus(1);
            return;
        }

        if (str_contains($input, "\033[Z")) {
            $this->cycleFocus(-1);
            return;
        }

        if ($this->isShiftArrow($input, 'up') || $this->isShiftArrow($input, 'left')) {
            $this->cycleFocus(-1);
            return;
        }

        if ($this->isShiftArrow($input, 'down') || $this->isShiftArrow($input, 'right')) {
            $this->cycleFocus(1);
            return;
        }

        if ($input === "\x13") {
            $this->saveSelectedMap();
            return;
        }

        if ($input === "\x12") {
            $this->workspace = ProjectWorkspace::fromProject($this->projectRoot);
            $this->selectedAssetIndex = $this->clampSelection($this->selectedAssetIndex);
            $this->clampCursor();
            $this->clampCanvasOffsets();
            $this->statusMessage = 'Workspace refreshed.';
            $this->render();
            return;
        }

        $this->handleFocusedPaneInput($input, $normalizedInput);
    }

    /**
     * Reads one logical input sequence from stdin.
     *
     * This lets multi-byte escape sequences such as arrow keys arrive as a
     * single unit instead of treating the initial ESC byte as a cancel press.
     *
     * @return string
     */
    private function readInputSequence(): string
    {
        $input = fread(STDIN, 32);

        if ($input === false || $input === '') {
            return '';
        }

        if (! str_starts_with($input, "\033")) {
            return $input;
        }

        $sequence = $input;
        $emptyReads = 0;

        for ($attempt = 0; $attempt < 8; $attempt++) {
            if ($this->isCompleteEscapeSequence($sequence)) {
                break;
            }

            usleep(10_000);
            $chunk = fread(STDIN, 32);

            if ($chunk === false || $chunk === '') {
                $emptyReads++;

                if ($emptyReads >= 2) {
                    break;
                }

                continue;
            }

            $emptyReads = 0;
            $sequence .= $chunk;
        }

        return $sequence;
    }

    /**
     * Returns whether the current escape sequence appears complete.
     *
     * @param string $sequence The buffered input sequence.
     * @return bool
     */
    private function isCompleteEscapeSequence(string $sequence): bool
    {
        if ($sequence === "\033") {
            return false;
        }

        return preg_match('/^\033(\[[0-9;?<]*[~A-Za-z]|\[<\d+;\d+;\d+[mM]|O[A-Za-z])$/', $sequence) === 1;
    }

    /**
     * Routes input to the currently focused pane.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleFocusedPaneInput(string $input, string $normalizedInput): void
    {
        match ($this->focusedPane) {
            self::FOCUS_ASSETS => $this->handleAssetsPaneInput($input, $normalizedInput),
            self::FOCUS_CANVAS => $this->handleCanvasPaneInput($input, $normalizedInput),
            self::FOCUS_INSPECTOR => $this->handleInspectorPaneInput($input, $normalizedInput),
            default => null,
        };
    }

    /**
     * Transitions focus between editor panes.
     *
     * The outgoing pane gets a chance to clean up its transient state before
     * the incoming pane prepares its own scoped controls.
     *
     * @param string $pane The pane that should receive focus.
     * @param bool $render Whether to redraw focus-dependent panes afterwards.
     * @return void
     */
    private function setFocusedPane(string $pane, bool $render = true): void
    {
        $validPanes = [self::FOCUS_ASSETS, self::FOCUS_CANVAS, self::FOCUS_INSPECTOR];

        if (! in_array($pane, $validPanes, true) || $pane === $this->focusedPane) {
            return;
        }

        $this->exitFocusedPane($this->focusedPane);
        $this->focusedPane = $pane;
        $this->enterFocusedPane($pane);

        if ($render) {
            $this->renderFocusDependentArea();
        }
    }

    /**
     * Runs pane-specific cleanup when focus leaves a pane.
     *
     * @param string $pane The pane losing focus.
     * @return void
     */
    private function exitFocusedPane(string $pane): void
    {
        if ($pane === self::FOCUS_CANVAS) {
            $this->activeMousePaintButton = null;
            $this->lastMousePaintPoint = null;
            return;
        }

        if ($pane === self::FOCUS_INSPECTOR) {
            $this->isInspectorEditing = false;
            $this->inspectorEditBuffer = '';
            $this->inspectorEditCursorIndex = 0;
        }
    }

    /**
     * Runs pane-specific setup when focus enters a pane.
     *
     * @param string $pane The pane receiving focus.
     * @return void
     */
    private function enterFocusedPane(string $pane): void
    {
        if ($pane === self::FOCUS_CANVAS) {
            $this->clampCursor();
            $this->clampCanvasOffsets();
            return;
        }

        if ($pane === self::FOCUS_INSPECTOR) {
            $this->clampInspectorSelection();
        }
    }

    /**
     * Checks whether a normalized input is a plain one-character shortcut.
     *
     * This prevents terminal escape sequences from being mistaken for command
     * letters like `c`, `y`, or movement keys.
     *
     * @param string $normalizedInput The normalized input sequence.
     * @param string $shortcut The expected shortcut character.
     * @return bool
     */
    private function isPlainShortcut(string $normalizedInput, string $shortcut): bool
    {
        return $normalizedInput === strtolower($shortcut);
    }

    /**
     * Handles input while the Assets pane is focused.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleAssetsPaneInput(string $input, string $normalizedInput): void
    {
        if ($this->isShiftLetterShortcut($input, 'A')) {
            $this->createNewMap();
            return;
        }

        if ($this->isShiftLetterShortcut($input, 'D')) {
            $this->duplicateSelectedMap();
            return;
        }

        if (str_contains($input, "\033[3~")) {
            $this->openDeleteConfirmation();
            return;
        }

        if (str_contains($input, "\033[A") || $this->isPlainShortcut($normalizedInput, 'k')) {
            $this->moveSelection(-1);
            return;
        }

        if (str_contains($input, "\033[B") || $this->isPlainShortcut($normalizedInput, 'j')) {
            $this->moveSelection(1);
        }
    }

    /**
     * Handles input while the Canvas pane is focused.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleCanvasPaneInput(string $input, string $normalizedInput): void
    {
        if (str_contains($input, '%')) {
            $this->setEditingMode(self::MODE_MAP);
            return;
        }

        if (str_contains($input, '^')) {
            $this->setEditingMode(self::MODE_EVENT);
            return;
        }

        if (str_contains($input, '@')) {
            $this->openCharacterMap();
            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->applySelectedPaintSymbol();
            return;
        }

        if (str_contains($input, "\033[A") || $this->isPlainShortcut($normalizedInput, 'k')) {
            $this->moveCursor(0, -1);
            return;
        }

        if (str_contains($input, "\033[B") || $this->isPlainShortcut($normalizedInput, 'j')) {
            $this->moveCursor(0, 1);
            return;
        }

        if (str_contains($input, "\033[D") || $this->isPlainShortcut($normalizedInput, 'h')) {
            $this->moveCursor(-1, 0);
            return;
        }

        if (str_contains($input, "\033[C") || $this->isPlainShortcut($normalizedInput, 'l')) {
            $this->moveCursor(1, 0);
            return;
        }

        if ($this->handleEraseInput($input)) {
            return;
        }

        $this->handleTypedSymbolInput($input);
    }

    /**
     * Handles input while the Inspector pane is focused.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleInspectorPaneInput(string $input, string $normalizedInput): void
    {
        if ($input === "\n" || $input === "\r") {
            $this->activateInspectorField();
            return;
        }

        if (str_contains($input, "\033[A") || $this->isPlainShortcut($normalizedInput, 'k')) {
            $this->moveInspectorSelection(-1);
            return;
        }

        if (str_contains($input, "\033[B") || $this->isPlainShortcut($normalizedInput, 'j')) {
            $this->moveInspectorSelection(1);
        }
    }

    /**
     * Opens the Database overlay.
     *
     * @return void
     */
    private function openDatabaseWindow(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $this->isDatabaseOpen = true;
        $this->databaseCategoryIndex = DatabaseCatalog::indexOf(self::DATABASE_CATEGORY_ANIMATIONS);
        $this->databaseFocus = self::DATABASE_FOCUS_LIST;
        $this->databaseSelectedActorIndex = 0;
        $this->databaseSelectedAnimationIndex = 0;
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseSelectedFrameIndex = 1;
        $this->isDatabaseEditing = false;
        $this->databaseEditBuffer = '';
        $this->databaseEditCursorIndex = 0;
        $this->databasePlaybackFrameIndex = $this->databaseSelectedFrameIndex;
        $this->databasePlaybackFlashColor = null;
        $this->centerDatabasePreviewCursor();
        $this->statusMessage = 'Database open.';
        $this->renderDatabaseArea(includeRoot: true);
    }

    /**
     * Closes the Database overlay.
     *
     * @return void
     */
    private function closeDatabaseWindow(): void
    {
        $this->isDatabaseOpen = false;
        $this->isDatabaseEditing = false;
        $this->databaseEditBuffer = '';
        $this->databaseEditCursorIndex = 0;
        $this->isDatabasePreviewPlaying = false;
        $this->databasePlaybackFlashColor = null;
        $this->statusMessage = 'Database closed.';
        $this->render();
    }

    /**
     * Handles keyboard input while the Database overlay is active.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleDatabaseInput(string $input, string $normalizedInput): void
    {
        if ($input === "\x11") {
            $this->isRunning = false;
            return;
        }

        if ($this->isDatabaseEditing) {
            $this->handleDatabaseEditingInput($input);
            return;
        }

        if ($input === "\033") {
            $this->closeDatabaseWindow();
            return;
        }

        if ($input === "\x13") {
            $this->saveActiveDatabase();
            return;
        }

        if ($input === "\t") {
            $this->cycleDatabaseFocus(1);
            return;
        }

        if (str_contains($input, "\033[Z")) {
            $this->cycleDatabaseFocus(-1);
            return;
        }

        if ($this->isShiftArrow($input, 'up') || $this->isShiftArrow($input, 'left')) {
            $this->cycleDatabaseFocus(-1);
            return;
        }

        if ($this->isShiftArrow($input, 'down') || $this->isShiftArrow($input, 'right')) {
            $this->cycleDatabaseFocus(1);
            return;
        }

        if ($this->databaseFocus === self::DATABASE_FOCUS_LIST && $this->isShiftLetterShortcut($input, 'A')) {
            $this->createDatabaseEntry();
            return;
        }

        if ($this->databaseFocus === self::DATABASE_FOCUS_SETTINGS && ($input === "\n" || $input === "\r")) {
            $this->beginDatabaseEdit();
            return;
        }

        if ($this->databaseFocus === self::DATABASE_FOCUS_PREVIEW && ($input === "\n" || $input === "\r")) {
            $this->paintDatabasePreviewSymbol($this->databaseSelectedPaintSymbol);
            return;
        }

        if ($this->isShiftLetterShortcut($input, 'P')) {
            $this->playDatabaseAnimationPreview();
            return;
        }

        if (str_contains($input, "\033[A")) {
            $this->moveDatabaseSelection(0, -1);
            return;
        }

        if (str_contains($input, "\033[B")) {
            $this->moveDatabaseSelection(0, 1);
            return;
        }

        if (str_contains($input, "\033[D")) {
            if ($this->databaseFocus === self::DATABASE_FOCUS_SETTINGS) {
                $this->adjustDatabaseOptionField(-1);
                return;
            }

            $this->moveDatabaseSelection(-1, 0);
            return;
        }

        if (str_contains($input, "\033[C")) {
            if ($this->databaseFocus === self::DATABASE_FOCUS_SETTINGS) {
                $this->adjustDatabaseOptionField(1);
                return;
            }

            $this->moveDatabaseSelection(1, 0);
            return;
        }

        if ($this->databaseFocus === self::DATABASE_FOCUS_PREVIEW) {
            if ($input === "\177" || $input === "\010") {
                $this->paintDatabasePreviewSymbol(' ');
                return;
            }

            $this->handleDatabaseTypedSymbolInput($input);
        }
    }

    /**
     * Cycles focus between Database panes.
     *
     * @param int $step The focus step direction.
     * @return void
     */
    private function cycleDatabaseFocus(int $step): void
    {
        $paneOrder = [
            self::DATABASE_FOCUS_CATEGORIES,
            self::DATABASE_FOCUS_LIST,
            self::DATABASE_FOCUS_SETTINGS,
            self::DATABASE_FOCUS_FRAMES,
            self::DATABASE_FOCUS_PREVIEW,
        ];
        $currentIndex = array_search($this->databaseFocus, $paneOrder, true);
        $currentIndex = is_int($currentIndex) ? $currentIndex : 0;
        $nextIndex = $currentIndex + $step;

        if ($nextIndex < 0) {
            $nextIndex = count($paneOrder) - 1;
        } elseif ($nextIndex >= count($paneOrder)) {
            $nextIndex = 0;
        }

        $this->databaseFocus = $paneOrder[$nextIndex];
        $this->renderDatabaseFocusDependentArea();
    }

    /**
     * Moves the selection inside the active Database pane.
     *
     * @param int $deltaX The horizontal movement amount.
     * @param int $deltaY The vertical movement amount.
     * @return void
     */
    private function moveDatabaseSelection(int $deltaX, int $deltaY): void
    {
        if ($this->databaseFocus === self::DATABASE_FOCUS_CATEGORIES) {
            $this->moveDatabaseCategorySelection($deltaY);
            return;
        }

        if ($this->databaseFocus === self::DATABASE_FOCUS_LIST) {
            $this->moveDatabaseListSelection($deltaY);
            return;
        }

        if ($this->databaseFocus === self::DATABASE_FOCUS_SETTINGS) {
            $this->moveDatabaseSettingsSelection($deltaY);
            return;
        }

        if ($this->databaseFocus === self::DATABASE_FOCUS_FRAMES) {
            $this->moveDatabaseFrameSelection($deltaY);
            return;
        }

        if ($this->databaseFocus === self::DATABASE_FOCUS_PREVIEW) {
            $this->moveDatabasePreviewCursor($deltaX, $deltaY);
        }
    }

    /**
     * Moves the selected database category.
     *
     * @param int $step The category step.
     * @return void
     */
    private function moveDatabaseCategorySelection(int $step): void
    {
        $categories = DatabaseCatalog::all();
        $nextIndex = max(0, min(count($categories) - 1, $this->databaseCategoryIndex + $step));

        if ($nextIndex === $this->databaseCategoryIndex) {
            return;
        }

        $this->databaseCategoryIndex = $nextIndex;
        $this->databaseSelectedSettingIndex = 0;
        $this->statusMessage = sprintf('%s database selected.', $categories[$nextIndex]->label);
        $this->renderDatabaseArea();
    }

    /**
     * Moves the selected entry in the active Database list.
     *
     * @param int $step The entry step.
     * @return void
     */
    private function moveDatabaseListSelection(int $step): void
    {
        if ($this->isActorsDatabaseSelected()) {
            $this->moveDatabaseActorSelection($step);
            return;
        }

        if ($this->isAnimationsDatabaseSelected()) {
            $this->moveDatabaseAnimationSelection($step);
        }
    }

    /**
     * Moves the selected actor entry.
     *
     * @param int $step The entry step.
     * @return void
     */
    private function moveDatabaseActorSelection(int $step): void
    {
        $actors = $this->workspace?->actorDatabase->getActors() ?? [];

        if ($actors === []) {
            return;
        }

        $nextIndex = max(0, min(count($actors) - 1, $this->databaseSelectedActorIndex + $step));

        if ($nextIndex === $this->databaseSelectedActorIndex) {
            return;
        }

        $this->databaseSelectedActorIndex = $nextIndex;
        $this->databaseSelectedSettingIndex = 0;
        $this->statusMessage = sprintf('Selected actor %s.', $actors[$nextIndex]->getName());
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
    }

    /**
     * Moves the selected animation entry.
     *
     * @param int $step The entry step.
     * @return void
     */
    private function moveDatabaseAnimationSelection(int $step): void
    {
        if (! $this->isAnimationsDatabaseSelected()) {
            return;
        }

        $animations = $this->workspace?->animationDatabase->getAnimations() ?? [];

        if ($animations === []) {
            return;
        }

        $nextIndex = max(0, min(count($animations) - 1, $this->databaseSelectedAnimationIndex + $step));

        if ($nextIndex === $this->databaseSelectedAnimationIndex) {
            return;
        }

        $this->databaseSelectedAnimationIndex = $nextIndex;
        $this->databaseSelectedFrameIndex = 1;
        $this->databaseSelectedSettingIndex = 0;
        $this->databasePlaybackFrameIndex = 1;
        $this->centerDatabasePreviewCursor();
        $this->statusMessage = sprintf('Selected animation %s.', $animations[$nextIndex]->name);
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
    }

    /**
     * Moves the selected settings field.
     *
     * @param int $step The field step.
     * @return void
     */
    private function moveDatabaseSettingsSelection(int $step): void
    {
        $fields = $this->getDatabaseSettingsFields();

        if ($fields === []) {
            return;
        }

        $nextIndex = max(0, min(count($fields) - 1, $this->databaseSelectedSettingIndex + $step));

        if ($nextIndex === $this->databaseSelectedSettingIndex) {
            return;
        }

        $this->databaseSelectedSettingIndex = $nextIndex;
        $this->renderDatabasePanes(['settings']);
    }

    /**
     * Moves the selected frame index.
     *
     * @param int $step The frame step.
     * @return void
     */
    private function moveDatabaseFrameSelection(int $step): void
    {
        $animation = $this->getSelectedAnimation();

        if (! $animation instanceof Animation) {
            return;
        }

        $nextIndex = max(1, min($animation->maxFrames, $this->databaseSelectedFrameIndex + $step));

        if ($nextIndex === $this->databaseSelectedFrameIndex) {
            return;
        }

        $this->databaseSelectedFrameIndex = $nextIndex;
        $this->databasePlaybackFrameIndex = $nextIndex;
        $this->statusMessage = sprintf('Frame #%03d selected.', $nextIndex);
        $this->renderDatabasePanes(['settings', 'cue', 'frames', 'preview']);
    }

    /**
     * Moves the animation preview cursor.
     *
     * @param int $deltaX The horizontal movement amount.
     * @param int $deltaY The vertical movement amount.
     * @return void
     */
    private function moveDatabasePreviewCursor(int $deltaX, int $deltaY): void
    {
        $previewSize = $this->getDatabasePreviewSize();
        $nextX = max(0, min($previewSize['width'] - 1, $this->databasePreviewCursorX + $deltaX));
        $nextY = max(0, min($previewSize['height'] - 1, $this->databasePreviewCursorY + $deltaY));

        if ($nextX === $this->databasePreviewCursorX && $nextY === $this->databasePreviewCursorY) {
            return;
        }

        $this->databasePreviewCursorX = $nextX;
        $this->databasePreviewCursorY = $nextY;
        $this->renderDatabasePanes(['preview']);
    }

    /**
     * Cycles focus between editor panes.
     *
     * @param int $step The focus step direction.
     * @return void
     */
    private function cycleFocus(int $step): void
    {
        $paneOrder = [self::FOCUS_ASSETS, self::FOCUS_CANVAS, self::FOCUS_INSPECTOR];
        $currentIndex = array_search($this->focusedPane, $paneOrder, true);
        $currentIndex = is_int($currentIndex) ? $currentIndex : 0;
        $nextIndex = $currentIndex + $step;

        if ($nextIndex < 0) {
            $nextIndex = count($paneOrder) - 1;
        } elseif ($nextIndex >= count($paneOrder)) {
            $nextIndex = 0;
        }

        $this->setFocusedPane($paneOrder[$nextIndex]);
    }

    /**
     * Checks whether the input matches a shifted arrow key sequence.
     *
     * @param string $input The raw input.
     * @param string $direction The arrow direction.
     * @return bool
     */
    private function isShiftArrow(string $input, string $direction): bool
    {
        $sequences = match ($direction) {
            'up' => ["\033[1;2A", "\033[1;2a"],
            'down' => ["\033[1;2B", "\033[1;2b"],
            'left' => ["\033[1;2D", "\033[1;2d"],
            'right' => ["\033[1;2C", "\033[1;2c"],
            default => [],
        };

        foreach ($sequences as $sequence) {
            if (str_contains($input, $sequence)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether the input is a direct shifted letter shortcut.
     *
     * This keeps terminal escape sequences like arrow keys from being mistaken
     * for uppercase shortcuts such as Shift+A or Shift+D.
     *
     * @param string $input The raw input.
     * @param string $letter The expected uppercase shortcut letter.
     * @return bool
     */
    private function isShiftLetterShortcut(string $input, string $letter): bool
    {
        return $input === $letter;
    }

    /**
     * Routes directional input to the focused pane.
     *
     * @param int $deltaX The horizontal step.
     * @param int $deltaY The vertical step.
     * @return void
     */
    private function handleDirectionalInput(int $deltaX, int $deltaY): void
    {
        if ($this->focusedPane === self::FOCUS_CANVAS) {
            $this->moveCursor($deltaX, $deltaY);
            return;
        }

        if ($this->focusedPane === self::FOCUS_INSPECTOR) {
            $this->moveInspectorSelection($deltaY);
            return;
        }

        $this->moveSelection($deltaY);
    }

    /**
     * Moves the selected asset entry up or down.
     *
     * @param int $step The selection step.
     * @return void
     */
    private function moveSelection(int $step): void
    {
        if (! $this->workspace instanceof ProjectWorkspace || $this->workspace->mapIds === []) {
            return;
        }

        $nextIndex = $this->selectedAssetIndex + $step;
        $maxIndex = count($this->workspace->mapIds) - 1;
        $selectedIndex = max(0, min($maxIndex, $nextIndex));

        if ($selectedIndex === $this->selectedAssetIndex) {
            return;
        }

        $this->selectedAssetIndex = $selectedIndex;
        $this->cursorX = 0;
        $this->cursorY = 0;
        $this->canvasOffsetX = 0;
        $this->canvasOffsetY = 0;
        $this->selectedInspectorFieldIndex = 0;
        $this->isInspectorEditing = false;
        $this->inspectorEditBuffer = '';
        $this->statusMessage = sprintf('Selected %s.', $this->workspace->mapIds[$selectedIndex] ?? 'map');
        $this->renderSelectionDependentArea();
    }

    /**
     * Moves the editing cursor inside the selected map.
     *
     * @param int $deltaX The horizontal movement amount.
     * @param int $deltaY The vertical movement amount.
     * @return void
     */
    private function moveCursor(int $deltaX, int $deltaY): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $selectedMap = $this->workspace->getMapByIndex($this->selectedAssetIndex);

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        $previousOffsetX = $this->canvasOffsetX;
        $previousOffsetY = $this->canvasOffsetY;
        $nextCursorX = max(0, min(max(0, $selectedMap->getWidth() - 1), $this->cursorX + $deltaX));
        $nextCursorY = max(0, min(max(0, $selectedMap->getHeight() - 1), $this->cursorY + $deltaY));

        if ($nextCursorX === $this->cursorX && $nextCursorY === $this->cursorY) {
            return;
        }

        $this->cursorX = $nextCursorX;
        $this->cursorY = $nextCursorY;
        $this->syncViewportToCursor();

        if ($previousOffsetX !== $this->canvasOffsetX || $previousOffsetY !== $this->canvasOffsetY) {
            $this->renderCanvasArea();
            return;
        }

        if ($this->isDestinationSpawnSelectionOpen) {
            $this->renderFooter();
            $this->renderOverlays();
            return;
        }

        if ($this->editingMode === self::MODE_EVENT) {
            $this->clampInspectorSelection();
            $this->renderInspectorArea();
            return;
        }

        $this->renderFooter();
        $this->renderCanvasCursor($this->resolveLayout());
    }

    /**
     * Sets the active editor mode.
     *
     * @param string $mode The new editor mode.
     * @return void
     */
    private function setEditingMode(string $mode): void
    {
        $this->editingMode = $mode;
        $this->showEventOverlay = $mode === self::MODE_EVENT;
        $this->selectedInspectorFieldIndex = 0;
        $this->isInspectorEditing = false;
        $this->inspectorEditBuffer = '';
        $this->statusMessage = $mode === self::MODE_EVENT
            ? 'Event mode active.'
            : 'Map mode active.';
        $this->renderCanvasArea();
    }

    /**
     * Opens the character-map picker.
     *
     * @return void
     */
    private function openCharacterMap(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        if (! $this->workspace->getMapByIndex($this->selectedAssetIndex) instanceof ProjectMap) {
            return;
        }

        $this->isCharacterMapOpen = true;
        $this->characterPaletteIndex = 0;
        $this->statusMessage = 'Character map open.';
        $this->render();
    }

    /**
     * Handles input while the character-map picker is open.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleCharacterMapInput(string $input, string $normalizedInput): void
    {
        if (str_contains($input, "\033[A")) {
            $this->moveCharacterPaletteSelection(0, -1);
            return;
        }

        if (str_contains($input, "\033[B")) {
            $this->moveCharacterPaletteSelection(0, 1);
            return;
        }

        if (str_contains($input, "\033[D")) {
            $this->moveCharacterPaletteSelection(-1, 0);
            return;
        }

        if (str_contains($input, "\033[C")) {
            $this->moveCharacterPaletteSelection(1, 0);
            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->applyCharacterPaletteSelection();
            return;
        }

        if ($this->isPlainShortcut($normalizedInput, 'c') || $input === "\033") {
            $this->closeCharacterMap('Character map closed.');
        }
    }

    /**
     * Moves the active character-map selection.
     *
     * @param int $deltaX The horizontal movement amount.
     * @param int $deltaY The vertical movement amount.
     * @return void
     */
    private function moveCharacterPaletteSelection(int $deltaX, int $deltaY): void
    {
        $palette = $this->getCharacterPalette();

        if ($palette === []) {
            return;
        }

        $columns = self::CHARACTER_MAP_COLUMNS;
        $row = intdiv($this->characterPaletteIndex, $columns);
        $column = $this->characterPaletteIndex % $columns;
        $maxIndex = count($palette) - 1;
        $row = max(0, $row + $deltaY);
        $column = max(0, $column + $deltaX);
        $nextIndex = min($maxIndex, max(0, ($row * $columns) + $column));

        if ($nextIndex === $this->characterPaletteIndex) {
            return;
        }

        $this->characterPaletteIndex = $nextIndex;
        $this->renderOverlays();
    }

    /**
     * Applies the selected character-map glyph to the current cursor location.
     *
     * @return void
     */
    private function applyCharacterPaletteSelection(): void
    {
        $palette = $this->getCharacterPalette();
        $symbol = $palette[$this->characterPaletteIndex] ?? null;

        if ($symbol === null) {
            $this->closeCharacterMap('Character map closed.');
            return;
        }

        $this->selectedPaintSymbol = $symbol;
        $this->replaceCurrentSymbol($symbol);
        $this->isCharacterMapOpen = false;
        $this->statusMessage = sprintf('Placed %s.', $symbol === ' ' ? 'space' : $symbol);
        $this->render();
    }

    /**
     * Closes the character-map picker.
     *
     * @param string $statusMessage The status message to show after closing.
     * @return void
     */
    private function closeCharacterMap(string $statusMessage): void
    {
        $this->isCharacterMapOpen = false;
        $this->statusMessage = $statusMessage;
        $this->render();
    }

    /**
     * Handles direct typing in the canvas.
     *
     * @param string $input The raw input.
     * @return void
     */
    private function handleTypedSymbolInput(string $input): void
    {
        if (str_contains($input, "\033")) {
            return;
        }

        if (preg_match('/^\X/u', $input, $matches) !== 1) {
            return;
        }

        $symbol = $matches[0];

        if ($symbol === "\n" || $symbol === "\r" || $symbol === "\t") {
            return;
        }

        if ($symbol === ' ') {
            $this->selectedPaintSymbol = ' ';
            $this->replaceCurrentSymbol(' ');
            $this->renderCanvasArea();
            return;
        }

        $reservedSymbols = ['%', '^', '@'];

        if (in_array($symbol, $reservedSymbols, true)) {
            return;
        }

        $this->selectedPaintSymbol = $symbol;
        $this->replaceCurrentSymbol($symbol);
        $this->renderCanvasArea();
    }

    /**
     * Handles erase-like keys in the canvas.
     *
     * @param string $input The raw input.
     * @return bool
     */
    private function handleEraseInput(string $input): bool
    {
        if ($input === "\177" || $input === "\010") {
            $this->selectedPaintSymbol = ' ';
            $this->replaceCurrentSymbol(' ');
            $this->renderCanvasArea();
            return true;
        }

        return false;
    }

    /**
     * Replaces the current symbol on the active editing layer.
     *
     * @param string $symbol The replacement symbol.
     * @return void
     */
    private function replaceCurrentSymbol(string $symbol): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $selectedMap = $this->workspace->getMapByIndex($this->selectedAssetIndex);

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        if ($this->editingMode === self::MODE_EVENT) {
            $selectedMap->setEventSymbol($this->cursorX, $this->cursorY, $symbol);
        } else {
            $selectedMap->setTileSymbol($this->cursorX, $this->cursorY, $symbol);
        }

        $this->statusMessage = sprintf(
            '%s mode updated (%d, %d).',
            ucfirst($this->editingMode),
            $this->cursorX,
            $this->cursorY
        );
    }

    /**
     * Paints the currently selected symbol at the active canvas cell.
     *
     * @return void
     */
    private function applySelectedPaintSymbol(): void
    {
        $this->replaceCurrentSymbol($this->selectedPaintSymbol);
        $this->statusMessage = sprintf(
            'Painted %s at (%d, %d).',
            $this->selectedPaintSymbol === ' ' ? 'space' : $this->selectedPaintSymbol,
            $this->cursorX,
            $this->cursorY
        );
        $this->renderCanvasArea();
    }

    /**
     * Handles raw mouse input sequences.
     *
     * @param string $input The raw input.
     * @return bool
     */
    private function handleMouseInput(string $input): bool
    {
        if (preg_match('/\033\[<\d+;\d+;\d+[Mm]/', $input, $matches) !== 1) {
            return false;
        }

        try {
            $event = new MouseEvent($matches[0]);
        } catch (Throwable) {
            return false;
        }

        if (
            ! in_array($this->editingMode, [self::MODE_MAP, self::MODE_EVENT], true) ||
            $this->isCharacterMapOpen ||
            $this->isDeleteConfirmationOpen ||
            $this->isInspectorEditing
        ) {
            $this->activeMousePaintButton = null;
            $this->lastMousePaintPoint = null;
            return true;
        }

        if ($event->isRelease) {
            $this->activeMousePaintButton = null;
            $this->lastMousePaintPoint = null;
            $this->renderCanvasArea();
            return true;
        }

        if (! in_array($event->button, [MouseButton::LEFT_BUTTON, MouseButton::RIGHT_BUTTON], true)) {
            return true;
        }

        return $this->handleCanvasMouseEdit($event);
    }

    /**
     * Handles mouse editing over the canvas preview.
     *
     * @param MouseEvent $event The mouse event.
     * @return bool
     */
    private function handleCanvasMouseEdit(MouseEvent $event): bool
    {
        $layout = $this->resolveLayout();
        $contentWidth = $this->getWindowContentWidth($layout['centerWidth']);
        $previewHeight = max(1, $layout['contentHeight'] - 4);
        $canvasLeft = 2 + $layout['leftWidth'] + $layout['gutter'];
        $canvasTop = 5;
        $mapLeft = $canvasLeft + 1 + self::WINDOW_HORIZONTAL_PADDING;
        $mapTop = $canvasTop + 3;
        $mapRight = $mapLeft + $contentWidth - 1;
        $mapBottom = $mapTop + $previewHeight - 1;

        if ($event->x < $mapLeft || $event->x > $mapRight || $event->y < $mapTop || $event->y > $mapBottom) {
            return true;
        }

        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return true;
        }

        $targetX = $this->canvasOffsetX + ($event->x - $mapLeft);
        $targetY = $this->canvasOffsetY + ($event->y - $mapTop);

        if ($targetX < 0 || $targetX >= $selectedMap->getWidth() || $targetY < 0 || $targetY >= $selectedMap->getHeight()) {
            return true;
        }

        $focusChanged = $this->focusedPane !== self::FOCUS_CANVAS;
        $this->setFocusedPane(self::FOCUS_CANVAS, false);

        if ($this->editingMode === self::MODE_EVENT) {
            $this->handleEventCanvasMouseEdit($selectedMap, $event->button, $targetX, $targetY);
        } else {
            $paintSymbol = $event->button === MouseButton::RIGHT_BUTTON ? ' ' : $this->selectedPaintSymbol;
            $this->paintCanvasStroke($selectedMap, $paintSymbol, $targetX, $targetY, $event->button);
            $this->statusMessage = sprintf(
                'Painted %s at (%d, %d).',
                $paintSymbol === ' ' ? 'space' : $paintSymbol,
                $this->cursorX,
                $this->cursorY
            );
        }

        if ($focusChanged) {
            $this->renderFocusDependentArea();
            return true;
        }

        $this->renderCanvasArea();

        return true;
    }

    /**
     * Handles mouse-driven event placement and selection on the canvas.
     *
     * Left click selects an existing event marker under the cursor and makes it
     * the active brush. Left drag continues placing that marker. Right drag
     * erases event cells.
     *
     * @param ProjectMap $selectedMap The selected map.
     * @param MouseButton $button The active mouse button.
     * @param int $targetX The target x coordinate.
     * @param int $targetY The target y coordinate.
     * @return void
     */
    private function handleEventCanvasMouseEdit(
        ProjectMap $selectedMap,
        MouseButton $button,
        int $targetX,
        int $targetY,
    ): void {
        $clickedMarker = $selectedMap->getEventMarkerAt($targetX, $targetY);
        $isStartingStroke = $this->activeMousePaintButton !== $button || ! is_array($this->lastMousePaintPoint);

        if ($button === MouseButton::LEFT_BUTTON && $clickedMarker !== null) {
            $this->selectedPaintSymbol = $clickedMarker;
        }

        $paintSymbol = $button === MouseButton::RIGHT_BUTTON ? ' ' : $this->selectedPaintSymbol;
        $this->paintCanvasStroke($selectedMap, $paintSymbol, $targetX, $targetY, $button, true);

        if ($button === MouseButton::LEFT_BUTTON && $clickedMarker !== null && $isStartingStroke) {
            $this->statusMessage = sprintf('Selected event %s at (%d, %d).', $clickedMarker, $this->cursorX, $this->cursorY);
            return;
        }

        $this->statusMessage = sprintf(
            '%s event %s at (%d, %d).',
            $paintSymbol === ' ' ? 'Cleared' : 'Placed',
            $paintSymbol === ' ' ? 'cell' : $paintSymbol,
            $this->cursorX,
            $this->cursorY,
        );
    }

    /**
     * Paints a continuous mouse stroke, filling any gaps between reported drag points.
     *
     * @param ProjectMap $selectedMap The selected map.
     * @param string $symbol The symbol to paint.
     * @param int $targetX The target x coordinate.
     * @param int $targetY The target y coordinate.
     * @param MouseButton $button The active mouse button.
     * @return void
     */
    private function paintCanvasStroke(
        ProjectMap $selectedMap,
        string $symbol,
        int $targetX,
        int $targetY,
        MouseButton $button,
        bool $paintEvents = false,
    ): void {
        $start = $this->activeMousePaintButton === $button && is_array($this->lastMousePaintPoint)
            ? $this->lastMousePaintPoint
            : ['x' => $targetX, 'y' => $targetY];

        foreach ($this->interpolatePoints($start['x'], $start['y'], $targetX, $targetY) as $point) {
            if ($paintEvents) {
                $selectedMap->setEventSymbol($point['x'], $point['y'], $symbol);
                continue;
            }

            $selectedMap->setTileSymbol($point['x'], $point['y'], $symbol);
        }

        $this->activeMousePaintButton = $button;
        $this->lastMousePaintPoint = ['x' => $targetX, 'y' => $targetY];
        $this->cursorX = $targetX;
        $this->cursorY = $targetY;
    }

    /**
     * Returns all integer points on a line between two cells using Bresenham interpolation.
     *
     * @param int $startX The starting x coordinate.
     * @param int $startY The starting y coordinate.
     * @param int $endX The ending x coordinate.
     * @param int $endY The ending y coordinate.
     * @return array<int, array{x: int, y: int}>
     */
    private function interpolatePoints(int $startX, int $startY, int $endX, int $endY): array
    {
        $points = [];
        $deltaX = abs($endX - $startX);
        $stepX = $startX < $endX ? 1 : -1;
        $deltaY = -abs($endY - $startY);
        $stepY = $startY < $endY ? 1 : -1;
        $error = $deltaX + $deltaY;

        while (true) {
            $points[] = ['x' => $startX, 'y' => $startY];

            if ($startX === $endX && $startY === $endY) {
                break;
            }

            $doubleError = $error * 2;

            if ($doubleError >= $deltaY) {
                $error += $deltaY;
                $startX += $stepX;
            }

            if ($doubleError <= $deltaX) {
                $error += $deltaX;
                $startY += $stepY;
            }
        }

        return $points;
    }

    /**
     * Saves the selected map folder back to disk.
     *
     * @return void
     */
    private function saveSelectedMap(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $selectedMap = $this->workspace->getMapByIndex($this->selectedAssetIndex);

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        try {
            $savedMapId = $selectedMap->save();
            $this->reloadWorkspaceSelectingMap($savedMapId);
            $this->statusMessage = sprintf('Saved %s.', $savedMapId);
        } catch (Throwable $throwable) {
            $this->statusMessage = $throwable->getMessage();
        }

        $this->renderSelectionDependentArea();
    }

    /**
     * Creates a new blank map and begins editing its display name.
     *
     * @return void
     */
    private function createNewMap(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        try {
            $mapId = $this->workspace->createMap();
            $this->reloadWorkspaceSelectingMap($mapId);
            $this->selectedInspectorFieldIndex = 0;
            $this->statusMessage = sprintf('Created %s.', $mapId);
            $this->setFocusedPane(self::FOCUS_INSPECTOR);
            $this->beginInspectorEdit();
        } catch (Throwable $throwable) {
            $this->statusMessage = $throwable->getMessage();
            $this->renderFooter();
        }
    }

    /**
     * Duplicates the selected map with a smart sibling name.
     *
     * @return void
     */
    private function duplicateSelectedMap(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        try {
            $mapId = $this->workspace->duplicateMap($this->selectedAssetIndex);

            if ($mapId === null) {
                return;
            }

            $this->reloadWorkspaceSelectingMap($mapId);
            $this->statusMessage = sprintf('Duplicated %s.', $mapId);
            $this->renderSelectionDependentArea();
        } catch (Throwable $throwable) {
            $this->statusMessage = $throwable->getMessage();
            $this->renderFooter();
        }
    }

    /**
     * Opens the delete confirmation prompt for the selected map.
     *
     * @return void
     */
    private function openDeleteConfirmation(): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        $this->isDeleteConfirmationOpen = true;
        $this->statusMessage = sprintf('Delete %s?', $selectedMap->mapId);
        $this->renderSelectionDependentArea();
    }

    /**
     * Opens the event type dialog for the selected marker.
     *
     * @param string $marker The selected event marker.
     * @return void
     */
    private function openEventTypeDialog(string $marker): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap || $marker === '') {
            return;
        }

        $definition = $selectedMap->getEventDefinition($marker);
        $className = is_array($definition) ? (string) ($definition['class'] ?? '') : null;

        $this->isEventTypeDialogOpen = true;
        $this->eventTypeDialogMarker = $marker;
        $this->selectedEventTypeIndex = EventTypeCatalog::indexOfClass($className);
        $this->statusMessage = sprintf('Choose an event type for %s.', $marker);
        $this->renderSelectionDependentArea();
    }

    /**
     * Opens the destination-map picker for the selected event field.
     *
     * @param string $marker The selected event marker.
     * @param string[] $path The selected event data path.
     * @param string $currentDestinationMap The currently configured destination map id.
     * @return void
     */
    private function openDestinationDialog(string $marker, array $path, string $currentDestinationMap): void
    {
        if (! $this->workspace instanceof ProjectWorkspace || $marker === '') {
            return;
        }

        $entries = $this->getDestinationDialogEntries();

        if ($entries === []) {
            $this->statusMessage = 'No destinations are available.';
            $this->renderFooter();
            return;
        }

        $this->isDestinationDialogOpen = true;
        $this->destinationDialogMarker = $marker;
        $this->destinationDialogPath = $path;
        $this->selectedDestinationIndex = $this->resolveDestinationSelectionIndex($currentDestinationMap);
        $this->statusMessage = sprintf('Choose a destination for %s.', $marker);
        $this->renderSelectionDependentArea();
    }

    /**
     * Handles destination picker input.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleDestinationDialogInput(string $input, string $normalizedInput): void
    {
        if ($input === "\033" || $this->isPlainShortcut($normalizedInput, 'c')) {
            $this->closeDestinationDialog('Destination selection cancelled.');
            return;
        }

        if (str_contains($input, "\033[A") || $this->isPlainShortcut($normalizedInput, 'k')) {
            $this->moveDestinationSelection(-1);
            return;
        }

        if (str_contains($input, "\033[B") || $this->isPlainShortcut($normalizedInput, 'j')) {
            $this->moveDestinationSelection(1);
            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->applySelectedDestination();
        }
    }

    /**
     * Moves the selected destination row.
     *
     * @param int $step The selection step.
     * @return void
     */
    private function moveDestinationSelection(int $step): void
    {
        $entries = $this->getDestinationDialogEntries();

        if ($entries === []) {
            return;
        }

        $nextIndex = max(0, min(count($entries) - 1, $this->selectedDestinationIndex + $step));

        if ($nextIndex === $this->selectedDestinationIndex) {
            return;
        }

        $this->selectedDestinationIndex = $nextIndex;
        $this->renderOverlays();
    }

    /**
     * Applies the currently selected destination map.
     *
     * @return void
     */
    private function applySelectedDestination(): void
    {
        $entries = $this->getDestinationDialogEntries();
        $selectedEntry = $entries[$this->selectedDestinationIndex] ?? null;

        if (! is_array($selectedEntry)) {
            $this->closeDestinationDialog('Unable to set destination.');
            return;
        }

        $this->beginDestinationSpawnSelection($selectedEntry['mapId']);
    }

    /**
     * Closes the destination picker.
     *
     * @param string $statusMessage The footer status message.
     * @return void
     */
    private function closeDestinationDialog(string $statusMessage): void
    {
        $this->isDestinationDialogOpen = false;
        $this->destinationDialogMarker = null;
        $this->destinationDialogPath = null;
        $this->statusMessage = $statusMessage;
        $this->renderSelectionDependentArea();
    }

    /**
     * Starts the destination spawn-point selection flow on the chosen map.
     *
     * @param string $destinationMapId The selected destination map id.
     * @return void
     */
    private function beginDestinationSpawnSelection(string $destinationMapId): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            $this->closeDestinationDialog('Unable to open destination map.');
            return;
        }

        $sourceMapIndex = $this->selectedAssetIndex;
        $sourceMap = $this->workspace->getMapByIndex($sourceMapIndex);
        $marker = $this->destinationDialogMarker;
        $path = $this->destinationDialogPath;
        $destinationMapIndex = array_search($destinationMapId, $this->workspace->mapIds, true);

        if (
            ! $sourceMap instanceof ProjectMap ||
            ! is_string($marker) ||
            $marker === '' ||
            ! is_array($path) ||
            ! is_int($destinationMapIndex)
        ) {
            $this->closeDestinationDialog('Unable to open destination map.');
            return;
        }

        $spawnPoint = $this->resolveConfiguredSpawnPoint($sourceMap, $marker);

        $this->destinationSelectionContext = [
            'sourceMapIndex' => $sourceMapIndex,
            'sourceCursorX' => $this->cursorX,
            'sourceCursorY' => $this->cursorY,
            'sourceCanvasOffsetX' => $this->canvasOffsetX,
            'sourceCanvasOffsetY' => $this->canvasOffsetY,
            'sourceFocusedPane' => $this->focusedPane,
            'sourceEditingMode' => $this->editingMode,
            'sourceShowEventOverlay' => $this->showEventOverlay,
            'sourceInspectorFieldIndex' => $this->selectedInspectorFieldIndex,
            'marker' => $marker,
            'path' => $path,
            'destinationMapId' => $destinationMapId,
        ];
        $this->isDestinationDialogOpen = false;
        $this->destinationDialogMarker = null;
        $this->destinationDialogPath = null;
        $this->isDestinationSpawnSelectionOpen = true;
        $this->selectedAssetIndex = $destinationMapIndex;
        $this->showEventOverlay = false;
        $this->cursorX = $spawnPoint['x'];
        $this->cursorY = $spawnPoint['y'];
        $this->canvasOffsetX = 0;
        $this->canvasOffsetY = 0;
        $this->setFocusedPane(self::FOCUS_CANVAS, false);
        $this->clampCursor();
        $this->syncViewportToCursor();
        $this->statusMessage = sprintf('Select a spawn point on %s.', $destinationMapId);
        $this->renderSelectionDependentArea();
    }

    /**
     * Handles input while selecting a destination spawn point.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleDestinationSpawnSelectionInput(string $input, string $normalizedInput): void
    {
        if ($input === "\033" || $this->isPlainShortcut($normalizedInput, 'c')) {
            $this->restoreDestinationSelectionContext('Destination selection cancelled.');
            return;
        }

        if (str_contains($input, "\033[A")) {
            $this->moveCursor(0, -1);
            return;
        }

        if (str_contains($input, "\033[B")) {
            $this->moveCursor(0, 1);
            return;
        }

        if (str_contains($input, "\033[D")) {
            $this->moveCursor(-1, 0);
            return;
        }

        if (str_contains($input, "\033[C")) {
            $this->moveCursor(1, 0);
            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->isDestinationSpawnConfirmationOpen = true;
            $this->statusMessage = sprintf('Confirm spawn point (%d, %d).', $this->cursorX, $this->cursorY);
            $this->renderOverlays();
        }
    }

    /**
     * Handles confirmation input for a selected destination spawn point.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleDestinationSpawnConfirmationInput(string $input, string $normalizedInput): void
    {
        if (
            $input === "\033" ||
            $this->isPlainShortcut($normalizedInput, 'n') ||
            $this->isPlainShortcut($normalizedInput, 'c')
        ) {
            $this->isDestinationSpawnConfirmationOpen = false;
            $this->statusMessage = 'Pick a spawn point or cancel.';
            $this->renderSelectionDependentArea();
            return;
        }

        if ($input === "\n" || $input === "\r" || $this->isPlainShortcut($normalizedInput, 'y')) {
            $this->commitDestinationSpawnSelection();
        }
    }

    /**
     * Commits the selected destination map and spawn point back to the source event.
     *
     * @return void
     */
    private function commitDestinationSpawnSelection(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace || ! is_array($this->destinationSelectionContext)) {
            $this->restoreDestinationSelectionContext('Unable to save destination selection.');
            return;
        }

        $context = $this->destinationSelectionContext;
        $sourceMap = $this->workspace->getMapByIndex($context['sourceMapIndex']);

        if (! $sourceMap instanceof ProjectMap) {
            $this->restoreDestinationSelectionContext('Unable to save destination selection.');
            return;
        }

        $sourceMap->setEventField($context['marker'], $context['path'], $context['destinationMapId']);
        $sourceMap->setEventField($context['marker'], ['data', 'spawnPoint', 'x'], $this->cursorX);
        $sourceMap->setEventField($context['marker'], ['data', 'spawnPoint', 'y'], $this->cursorY);

        $this->restoreDestinationSelectionContext(
            sprintf(
                '%s destination set to %s (%d, %d).',
                $context['marker'],
                $context['destinationMapId'],
                $this->cursorX,
                $this->cursorY,
            ),
        );
    }

    /**
     * Restores the editor state after a destination selection flow ends.
     *
     * @param string $statusMessage The footer status message.
     * @return void
     */
    private function restoreDestinationSelectionContext(string $statusMessage): void
    {
        if (is_array($this->destinationSelectionContext)) {
            $context = $this->destinationSelectionContext;
            $this->selectedAssetIndex = $context['sourceMapIndex'];
            $this->cursorX = $context['sourceCursorX'];
            $this->cursorY = $context['sourceCursorY'];
            $this->canvasOffsetX = $context['sourceCanvasOffsetX'];
            $this->canvasOffsetY = $context['sourceCanvasOffsetY'];
            $this->editingMode = $context['sourceEditingMode'];
            $this->showEventOverlay = $context['sourceShowEventOverlay'];
            $this->selectedInspectorFieldIndex = $context['sourceInspectorFieldIndex'];
            $this->setFocusedPane($context['sourceFocusedPane'], false);
        }

        $this->destinationSelectionContext = null;
        $this->isDestinationSpawnSelectionOpen = false;
        $this->isDestinationSpawnConfirmationOpen = false;
        $this->statusMessage = $statusMessage;
        $this->clampCursor();
        $this->clampCanvasOffsets();
        $this->clampInspectorSelection();
        $this->render();
    }

    /**
     * Resolves the currently configured spawn point for an event.
     *
     * @param ProjectMap $sourceMap The source map.
     * @param string $marker The event marker.
     * @return array{x: int, y: int}
     */
    private function resolveConfiguredSpawnPoint(ProjectMap $sourceMap, string $marker): array
    {
        $definition = $sourceMap->getEventDefinition($marker);
        $spawnPoint = is_array($definition)
            ? ($definition['data']['spawnPoint'] ?? null)
            : null;

        if (
            is_array($spawnPoint) &&
            isset($spawnPoint['x'], $spawnPoint['y']) &&
            is_numeric($spawnPoint['x']) &&
            is_numeric($spawnPoint['y'])
        ) {
            return [
                'x' => (int) $spawnPoint['x'],
                'y' => (int) $spawnPoint['y'],
            ];
        }

        return ['x' => 0, 'y' => 0];
    }

    /**
     * Handles event type dialog input.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleEventTypeDialogInput(string $input, string $normalizedInput): void
    {
        if ($input === "\033" || $this->isPlainShortcut($normalizedInput, 'c')) {
            $this->closeEventTypeDialog('Event type selection cancelled.');
            return;
        }

        if (str_contains($input, "\033[A") || $this->isPlainShortcut($normalizedInput, 'k')) {
            $this->moveEventTypeSelection(-1);
            return;
        }

        if (str_contains($input, "\033[B") || $this->isPlainShortcut($normalizedInput, 'j')) {
            $this->moveEventTypeSelection(1);
            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->applySelectedEventType();
        }
    }

    /**
     * Moves the highlighted event type row.
     *
     * @param int $step The movement step.
     * @return void
     */
    private function moveEventTypeSelection(int $step): void
    {
        $definitions = EventTypeCatalog::all();

        if ($definitions === []) {
            return;
        }

        $nextIndex = max(0, min(count($definitions) - 1, $this->selectedEventTypeIndex + $step));

        if ($nextIndex === $this->selectedEventTypeIndex) {
            return;
        }

        $this->selectedEventTypeIndex = $nextIndex;
        $this->renderOverlays();
    }

    /**
     * Applies the currently selected event type to the focused marker.
     *
     * @return void
     */
    private function applySelectedEventType(): void
    {
        $selectedMap = $this->getSelectedMap();
        $marker = $this->eventTypeDialogMarker;

        if (! $selectedMap instanceof ProjectMap || $marker === null) {
            $this->closeEventTypeDialog('Unable to set event type.');
            return;
        }

        $definition = EventTypeCatalog::at($this->selectedEventTypeIndex);
        $currentDefinition = $selectedMap->getEventDefinition($marker);
        $currentClassName = is_array($currentDefinition) ? (string) ($currentDefinition['class'] ?? '') : '';
        $eventData = $currentClassName === $definition->className && is_array($currentDefinition['data'] ?? null)
            ? $currentDefinition['data']
            : $definition->defaultData;

        $selectedMap->setEventDefinition($marker, [
            'class' => $definition->className,
            'data' => $eventData,
        ]);
        $this->clampInspectorSelection();
        $this->closeEventTypeDialog(sprintf('%s is now a %s event.', $marker, $definition->label));
    }

    /**
     * Closes the event type dialog.
     *
     * @param string $statusMessage The footer status message.
     * @return void
     */
    private function closeEventTypeDialog(string $statusMessage): void
    {
        $this->isEventTypeDialogOpen = false;
        $this->eventTypeDialogMarker = null;
        $this->statusMessage = $statusMessage;
        $this->renderSelectionDependentArea();
    }

    /**
     * Handles delete confirmation input.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleDeleteConfirmationInput(string $input, string $normalizedInput): void
    {
        if (
            $input === "\033" ||
            $this->isPlainShortcut($normalizedInput, 'n') ||
            $this->isPlainShortcut($normalizedInput, 'c')
        ) {
            $this->isDeleteConfirmationOpen = false;
            $this->statusMessage = 'Delete cancelled.';
            $this->renderSelectionDependentArea();
            return;
        }

        if ($input === "\n" || $input === "\r" || $this->isPlainShortcut($normalizedInput, 'y')) {
            $this->deleteSelectedMap();
        }
    }

    /**
     * Deletes the selected map and refreshes the asset browser.
     *
     * @return void
     */
    private function deleteSelectedMap(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $currentIndex = $this->selectedAssetIndex;

        try {
            $deletedMapId = $this->workspace->deleteMap($currentIndex);

            if ($deletedMapId === null) {
                return;
            }

            $this->workspace = ProjectWorkspace::fromProject($this->projectRoot);
            $this->selectedAssetIndex = $this->clampSelection(min($currentIndex, max(0, count($this->workspace->mapIds) - 1)));
            $this->cursorX = 0;
            $this->cursorY = 0;
            $this->canvasOffsetX = 0;
            $this->canvasOffsetY = 0;
            $this->selectedInspectorFieldIndex = 0;
            $this->isInspectorEditing = false;
            $this->inspectorEditBuffer = '';
            $this->isDeleteConfirmationOpen = false;
            $this->statusMessage = sprintf('Deleted %s.', $deletedMapId);
            $this->renderSelectionDependentArea();
        } catch (Throwable $throwable) {
            $this->isDeleteConfirmationOpen = false;
            $this->statusMessage = $throwable->getMessage();
            $this->renderSelectionDependentArea();
        }
    }

    /**
     * Reloads the workspace and selects the requested map id.
     *
     * @param string $mapId The map id to select after reload.
     * @return void
     */
    private function reloadWorkspaceSelectingMap(string $mapId): void
    {
        $this->workspace = ProjectWorkspace::fromProject($this->projectRoot);
        $selectedIndex = array_search($mapId, $this->workspace->mapIds, true);
        $this->selectedAssetIndex = is_int($selectedIndex) ? $selectedIndex : 0;
        $this->cursorX = 0;
        $this->cursorY = 0;
        $this->canvasOffsetX = 0;
        $this->canvasOffsetY = 0;
        $this->clampInspectorSelection();
    }

    /**
     * Returns the selected map.
     *
     * @return ProjectMap|null
     */
    private function getSelectedMap(): ?ProjectMap
    {
        return $this->workspace?->getMapByIndex($this->selectedAssetIndex);
    }

    /**
     * Returns the event marker currently under the cursor.
     *
     * @return string|null
     */
    private function getFocusedEventMarker(): ?string
    {
        $selectedMap = $this->getSelectedMap();

        return $selectedMap instanceof ProjectMap
            ? $selectedMap->getEventMarkerAt($this->cursorX, $this->cursorY)
            : null;
    }

    /**
     * Moves the inspector selection.
     *
     * @param int $step The selection step.
     * @return void
     */
    private function moveInspectorSelection(int $step): void
    {
        $fields = $this->getInspectorFields();

        if ($fields === []) {
            return;
        }

        $nextIndex = max(0, min(count($fields) - 1, $this->selectedInspectorFieldIndex + $step));

        if ($nextIndex === $this->selectedInspectorFieldIndex) {
            return;
        }

        $this->selectedInspectorFieldIndex = $nextIndex;
        $this->renderInspectorArea();
    }

    /**
     * Keeps the inspector selection within the available field list.
     *
     * @return void
     */
    private function clampInspectorSelection(): void
    {
        $fields = $this->getInspectorFields();

        if ($fields === []) {
            $this->selectedInspectorFieldIndex = 0;
            return;
        }

        $this->selectedInspectorFieldIndex = max(0, min(count($fields) - 1, $this->selectedInspectorFieldIndex));
    }

    /**
     * Starts editing the selected inspector field.
     *
     * @return void
     */
    private function beginInspectorEdit(): void
    {
        $fields = $this->getInspectorFields();
        $field = $fields[$this->selectedInspectorFieldIndex] ?? null;

        if (! is_array($field)) {
            return;
        }

        $control = $this->getInspectorFieldControl($field);

        if (! $control instanceof InputControl) {
            return;
        }

        $this->isInspectorEditing = true;
        $this->inspectorEditBuffer = $control->rawValue;
        $this->inspectorEditCursorIndex = mb_strlen($this->inspectorEditBuffer);
        $this->statusMessage = sprintf('Editing %s.', $field['label'] ?? 'field');
        $this->renderInspectorArea();
    }

    /**
     * Activates the selected inspector field.
     *
     * @return void
     */
    private function activateInspectorField(): void
    {
        $fields = $this->getInspectorFields();
        $field = $fields[$this->selectedInspectorFieldIndex] ?? null;

        if (! is_array($field)) {
            return;
        }

        if (($field['target'] ?? null) === 'event-type') {
            $this->openEventTypeDialog((string) ($field['marker'] ?? ''));
            return;
        }

        if ($this->isDestinationMapField($field)) {
            $this->openDestinationDialog(
                (string) ($field['marker'] ?? ''),
                (array) ($field['path'] ?? []),
                (string) ($field['value'] ?? ''),
            );
            return;
        }

        $this->beginInspectorEdit();
    }

    /**
     * Returns whether the field should open the destination picker.
     *
     * @param array<string, mixed> $field The inspector field descriptor.
     * @return bool
     */
    private function isDestinationMapField(array $field): bool
    {
        return ($field['target'] ?? null) === 'event'
            && (($field['path'] ?? []) === ['data', 'destinationMap']);
    }

    /**
     * Handles inline inspector editing input.
     *
     * @param string $input The raw input.
     * @return void
     */
    private function handleInspectorEditingInput(string $input): void
    {
        if ($input === "\033") {
            $this->isInspectorEditing = false;
            $this->inspectorEditBuffer = '';
            $this->inspectorEditCursorIndex = 0;
            $this->statusMessage = 'Edit cancelled.';
            $this->renderInspectorArea();
            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->commitInspectorEdit();
            return;
        }

        $fields = $this->getInspectorFields();
        $field = $fields[$this->selectedInspectorFieldIndex] ?? null;
        $control = is_array($field) ? $this->getInspectorFieldControl($field) : null;

        if ($control instanceof InputControl && $control->type === InputControlType::INTEGER) {
            if (str_contains($input, "\033[A")) {
                $this->adjustInspectorInteger(1);
                return;
            }

            if (str_contains($input, "\033[B")) {
                $this->adjustInspectorInteger(-1);
                return;
            }
        }

        if (str_contains($input, "\033[D")) {
            $this->inspectorEditCursorIndex = max(0, $this->inspectorEditCursorIndex - 1);
            $this->renderInspectorArea();
            return;
        }

        if (str_contains($input, "\033[C")) {
            $this->inspectorEditCursorIndex = min(mb_strlen($this->inspectorEditBuffer), $this->inspectorEditCursorIndex + 1);
            $this->renderInspectorArea();
            return;
        }

        if ($input === "\177" || $input === "\010") {
            if ($this->inspectorEditCursorIndex > 0) {
                $left = mb_substr($this->inspectorEditBuffer, 0, $this->inspectorEditCursorIndex - 1);
                $right = mb_substr($this->inspectorEditBuffer, $this->inspectorEditCursorIndex);
                $this->inspectorEditBuffer = $left . $right;
                $this->inspectorEditCursorIndex--;
            }
            $this->renderInspectorArea();
            return;
        }

        if (str_contains($input, "\033[3~")) {
            if ($this->inspectorEditCursorIndex < mb_strlen($this->inspectorEditBuffer)) {
                $left = mb_substr($this->inspectorEditBuffer, 0, $this->inspectorEditCursorIndex);
                $right = mb_substr($this->inspectorEditBuffer, $this->inspectorEditCursorIndex + 1);
                $this->inspectorEditBuffer = $left . $right;
            }
            $this->renderInspectorArea();
            return;
        }

        if (str_contains($input, "\033")) {
            return;
        }

        if (preg_match('/^\X/u', $input, $matches) !== 1) {
            return;
        }

        $symbol = $matches[0];

        if (! $control instanceof InputControl || ! $control->acceptsTypedInput()) {
            return;
        }

        if (! $control->acceptsSymbol($symbol, $this->inspectorEditBuffer)) {
            return;
        }

        $left = mb_substr($this->inspectorEditBuffer, 0, $this->inspectorEditCursorIndex);
        $right = mb_substr($this->inspectorEditBuffer, $this->inspectorEditCursorIndex);
        $this->inspectorEditBuffer = $left . $symbol . $right;
        $this->inspectorEditCursorIndex += mb_strlen($symbol);
        $this->renderInspectorArea();
    }

    /**
     * Adjusts the current integer inspector field by the given amount.
     *
     * @param int $delta The adjustment amount.
     * @return void
     */
    private function adjustInspectorInteger(int $delta): void
    {
        $fields = $this->getInspectorFields();
        $field = $fields[$this->selectedInspectorFieldIndex] ?? null;
        $control = is_array($field) ? $this->getInspectorFieldControl($field) : null;

        if (! $control instanceof InputControl) {
            return;
        }

        $this->inspectorEditBuffer = $control->adjust($this->inspectorEditBuffer, $delta);
        $this->inspectorEditCursorIndex = mb_strlen($this->inspectorEditBuffer);
        $this->renderInspectorArea();
    }

    /**
     * Commits the current inspector edit.
     *
     * @return void
     */
    private function commitInspectorEdit(): void
    {
        $fields = $this->getInspectorFields();
        $field = $fields[$this->selectedInspectorFieldIndex] ?? null;

        if (! is_array($field) || ! $this->getInspectorFieldControl($field) instanceof InputControl) {
            $this->isInspectorEditing = false;
            $this->inspectorEditBuffer = '';
            $this->inspectorEditCursorIndex = 0;
            $this->renderInspectorArea();
            return;
        }

        try {
            $this->applyInspectorFieldValue($field, $this->inspectorEditBuffer);
            $this->clampCursor();
            $this->clampCanvasOffsets();
            $this->clampInspectorSelection();
            $this->statusMessage = sprintf('%s updated.', $field['label'] ?? 'Field');
        } catch (Throwable $throwable) {
            $this->statusMessage = $throwable->getMessage();
        }

        $this->isInspectorEditing = false;
        $this->inspectorEditBuffer = '';
        $this->inspectorEditCursorIndex = 0;
        $this->renderSelectionDependentArea();
    }

    /**
     * Applies an edited inspector value back to the selected map.
     *
     * @param array<string, mixed> $field The inspector field descriptor.
     * @param string $rawValue The raw edited value.
     * @return void
     */
    private function applyInspectorFieldValue(array $field, string $rawValue): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        $control = $this->getInspectorFieldControl($field);
        $type = $control?->type ?? InputControlType::TEXT;
        $value = match ($type) {
            InputControlType::INTEGER => (int) trim($rawValue),
            default => $rawValue,
        };
        $target = (string) ($field['target'] ?? 'map');

        if ($target === 'map') {
            $selectedMap->setMapField((string) $field['field'], $value);
            return;
        }

        if ($target === 'map-size') {
            $selectedMap->resize(
                (string) ($field['field'] ?? '') === 'width' ? max(1, (int) $value) : $selectedMap->getWidth(),
                (string) ($field['field'] ?? '') === 'height' ? max(1, (int) $value) : $selectedMap->getHeight(),
            );
            return;
        }

        if ($target === 'event') {
            $selectedMap->setEventField((string) $field['marker'], (array) ($field['path'] ?? []), $value);
            return;
        }

        if ($target === 'event-bounds') {
            $bounds = $selectedMap->getEventBounds((string) $field['marker']);

            if ($bounds === null) {
                return;
            }

            $bounds[(string) $field['field']] = max(0, (int) $value);
            $selectedMap->setEventBounds(
                (string) $field['marker'],
                $bounds['x'],
                $bounds['y'],
                $bounds['width'],
                $bounds['height'],
            );
        }
    }

    /**
     * Returns the currently selected database category label.
     *
     * @return string
     */
    private function getSelectedDatabaseCategory(): string
    {
        return $this->getSelectedDatabaseCategoryDefinition()->label;
    }

    /**
     * Returns the currently selected Database category definition.
     *
     * @return DatabaseCategoryDefinition
     */
    private function getSelectedDatabaseCategoryDefinition(): DatabaseCategoryDefinition
    {
        return DatabaseCatalog::at($this->databaseCategoryIndex);
    }

    /**
     * Returns whether the Animations database is active.
     *
     * @return bool
     */
    private function isAnimationsDatabaseSelected(): bool
    {
        return $this->getSelectedDatabaseCategoryDefinition()->key === self::DATABASE_CATEGORY_ANIMATIONS;
    }

    /**
     * Returns whether the Actors database is active.
     *
     * @return bool
     */
    private function isActorsDatabaseSelected(): bool
    {
        return $this->getSelectedDatabaseCategoryDefinition()->key === self::DATABASE_CATEGORY_ACTORS;
    }

    /**
     * Returns the selected actor from the project database.
     *
     * @return ProjectActor|null
     */
    private function getSelectedActor(): ?ProjectActor
    {
        if (! $this->isActorsDatabaseSelected()) {
            return null;
        }

        return $this->workspace?->actorDatabase->getActorByIndex($this->databaseSelectedActorIndex);
    }

    /**
     * Returns the selected animation from the project database.
     *
     * @return Animation|null
     */
    private function getSelectedAnimation(): ?Animation
    {
        if (! $this->isAnimationsDatabaseSelected()) {
            return null;
        }

        return $this->workspace?->animationDatabase->getAnimationByIndex($this->databaseSelectedAnimationIndex);
    }

    /**
     * Creates a new entry in the active Database category.
     *
     * @return void
     */
    private function createDatabaseEntry(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        if ($this->isActorsDatabaseSelected()) {
            $this->createDatabaseActor();
            return;
        }

        if ($this->isAnimationsDatabaseSelected()) {
            $this->createDatabaseAnimation();
        }
    }

    /**
     * Creates a new actor entry in the project database.
     *
     * @return void
     */
    private function createDatabaseActor(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $this->databaseSelectedActorIndex = $this->workspace->actorDatabase->addActor();
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseFocus = self::DATABASE_FOCUS_SETTINGS;
        $this->statusMessage = 'Created a new actor.';
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
        $this->beginDatabaseEdit();
    }

    /**
     * Creates a new animation entry in the project database.
     *
     * @return void
     */
    private function createDatabaseAnimation(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace || ! $this->isAnimationsDatabaseSelected()) {
            return;
        }

        $this->databaseSelectedAnimationIndex = $this->workspace->animationDatabase->addAnimation();
        $this->databaseSelectedFrameIndex = 1;
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseFocus = self::DATABASE_FOCUS_SETTINGS;
        $this->centerDatabasePreviewCursor();
        $this->statusMessage = 'Created a new animation.';
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
        $this->beginDatabaseEdit();
    }

    /**
     * Saves the active Database category.
     *
     * @return void
     */
    private function saveActiveDatabase(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        try {
            if ($this->isActorsDatabaseSelected()) {
                $this->workspace->actorDatabase->save();
                $this->statusMessage = 'Actor database saved.';
            } elseif ($this->isAnimationsDatabaseSelected()) {
                $this->workspace->animationDatabase->save();
                $this->statusMessage = 'Animation database saved.';
            } else {
                $this->statusMessage = 'This database category is not editable yet.';
            }
        } catch (Throwable $throwable) {
            $this->statusMessage = $throwable->getMessage();
        }

        $this->renderDatabaseArea();
    }

    /**
     * Returns the editable settings fields for the selected animation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDatabaseSettingsFields(): array
    {
        if ($this->isActorsDatabaseSelected()) {
            return $this->getDatabaseActorSettingsFields();
        }

        $animation = $this->getSelectedAnimation();

        if (! $animation instanceof Animation) {
            return [];
        }

        $cue = $animation->getCue($this->databaseSelectedFrameIndex);

        return [
            [
                'label' => 'Name',
                'value' => $animation->name,
                'control' => new InputControl(InputControlType::TEXT, $animation->name),
                'field' => 'name',
            ],
            [
                'label' => 'Position',
                'value' => ucfirst($animation->position->value),
                'options' => array_map(
                    static fn(AnimationTargetPosition $position): string => $position->value,
                    AnimationTargetPosition::cases()
                ),
                'field' => 'position',
            ],
            [
                'label' => 'Max Frames',
                'value' => (string) $animation->maxFrames,
                'control' => new InputControl(InputControlType::INTEGER, (string) $animation->maxFrames),
                'field' => 'maxFrames',
            ],
            [
                'label' => 'Brush Symbol',
                'value' => $this->databaseSelectedPaintSymbol === ' ' ? 'Space' : $this->databaseSelectedPaintSymbol,
                'control' => new InputControl(InputControlType::TEXT, $this->databaseSelectedPaintSymbol),
                'field' => 'brushSymbol',
            ],
            [
                'label' => 'Brush Color',
                'value' => ucfirst((string) ($this->databaseSelectedPaintColor ?? 'none')),
                'options' => ['none', 'white', 'red', 'green', 'blue', 'yellow', 'cyan', 'magenta'],
                'field' => 'brushColor',
            ],
            [
                'label' => 'Frame Sound',
                'value' => $cue?->soundEffect ?? '',
                'control' => new InputControl(InputControlType::TEXT, $cue?->soundEffect ?? ''),
                'field' => 'frameSound',
            ],
            [
                'label' => 'Flash Color',
                'value' => ucfirst((string) ($cue?->flashColor ?? 'none')),
                'options' => ['none', 'white', 'red', 'green', 'blue', 'yellow', 'cyan', 'magenta'],
                'field' => 'flashColor',
            ],
            [
                'label' => 'Flash Frames',
                'value' => (string) ($cue?->flashDurationFrames ?? 0),
                'control' => new InputControl(InputControlType::INTEGER, (string) ($cue?->flashDurationFrames ?? 0)),
                'field' => 'flashDurationFrames',
            ],
        ];
    }

    /**
     * Returns the editable settings fields for the selected actor.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDatabaseActorSettingsFields(): array
    {
        $actor = $this->getSelectedActor();

        if (! $actor instanceof ProjectActor) {
            return [];
        }

        return [
            [
                'label' => 'Name',
                'value' => $actor->getName(),
                'control' => new InputControl(InputControlType::TEXT, $actor->getName()),
                'field' => 'name',
            ],
            [
                'label' => 'Description',
                'value' => $actor->getDescription(),
                'control' => new InputControl(InputControlType::TEXT, $actor->getDescription()),
                'field' => 'description',
            ],
            [
                'label' => 'Level',
                'value' => (string) $actor->getLevel(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $actor->getLevel()),
                'field' => 'level',
            ],
            [
                'label' => 'Current Exp',
                'value' => (string) $actor->getCurrentExp(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $actor->getCurrentExp()),
                'field' => 'currentExp',
            ],
            [
                'label' => 'Current HP',
                'value' => (string) $actor->getStat('currentHp'),
                'control' => new InputControl(InputControlType::INTEGER, (string) $actor->getStat('currentHp')),
                'field' => 'currentHp',
            ],
            [
                'label' => 'Current MP',
                'value' => (string) $actor->getStat('currentMp'),
                'control' => new InputControl(InputControlType::INTEGER, (string) $actor->getStat('currentMp')),
                'field' => 'currentMp',
            ],
            [
                'label' => 'Current AP',
                'value' => (string) $actor->getStat('currentAp'),
                'control' => new InputControl(InputControlType::INTEGER, (string) $actor->getStat('currentAp')),
                'field' => 'currentAp',
            ],
        ];
    }

    /**
     * Returns the input control for a database settings field when editable.
     *
     * @param array<string, mixed> $field The field descriptor.
     * @return InputControl|null
     */
    private function getDatabaseFieldControl(array $field): ?InputControl
    {
        $control = $field['control'] ?? null;

        return $control instanceof InputControl ? $control : null;
    }

    /**
     * Starts editing the selected database settings field.
     *
     * @return void
     */
    private function beginDatabaseEdit(): void
    {
        $fields = $this->getDatabaseSettingsFields();
        $field = $fields[$this->databaseSelectedSettingIndex] ?? null;

        if (! is_array($field)) {
            return;
        }

        $control = $this->getDatabaseFieldControl($field);

        if (! $control instanceof InputControl) {
            return;
        }

        $this->isDatabaseEditing = true;
        $this->databaseEditBuffer = $control->rawValue;
        $this->databaseEditCursorIndex = mb_strlen($this->databaseEditBuffer);
        $this->statusMessage = sprintf('Editing %s.', $field['label'] ?? 'field');
        $this->renderDatabasePanes(['settings']);
    }

    /**
     * Handles inline database-field editing.
     *
     * @param string $input The raw input.
     * @return void
     */
    private function handleDatabaseEditingInput(string $input): void
    {
        if ($input === "\033") {
            $this->isDatabaseEditing = false;
            $this->databaseEditBuffer = '';
            $this->databaseEditCursorIndex = 0;
            $this->statusMessage = 'Database edit cancelled.';
            $this->renderDatabasePanes(['settings']);
            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->commitDatabaseEdit();
            return;
        }

        $fields = $this->getDatabaseSettingsFields();
        $field = $fields[$this->databaseSelectedSettingIndex] ?? null;
        $control = is_array($field) ? $this->getDatabaseFieldControl($field) : null;

        if ($control instanceof InputControl && $control->type === InputControlType::INTEGER) {
            if (str_contains($input, "\033[A")) {
                $this->databaseEditBuffer = $control->adjust($this->databaseEditBuffer, 1);
                $this->databaseEditCursorIndex = mb_strlen($this->databaseEditBuffer);
                $this->renderDatabasePanes(['settings']);
                return;
            }

            if (str_contains($input, "\033[B")) {
                $this->databaseEditBuffer = $control->adjust($this->databaseEditBuffer, -1);
                $this->databaseEditCursorIndex = mb_strlen($this->databaseEditBuffer);
                $this->renderDatabasePanes(['settings']);
                return;
            }
        }

        if (str_contains($input, "\033[D")) {
            $this->databaseEditCursorIndex = max(0, $this->databaseEditCursorIndex - 1);
            $this->renderDatabasePanes(['settings']);
            return;
        }

        if (str_contains($input, "\033[C")) {
            $this->databaseEditCursorIndex = min(mb_strlen($this->databaseEditBuffer), $this->databaseEditCursorIndex + 1);
            $this->renderDatabasePanes(['settings']);
            return;
        }

        if ($input === "\177" || $input === "\010") {
            if ($this->databaseEditCursorIndex > 0) {
                $left = mb_substr($this->databaseEditBuffer, 0, $this->databaseEditCursorIndex - 1);
                $right = mb_substr($this->databaseEditBuffer, $this->databaseEditCursorIndex);
                $this->databaseEditBuffer = $left . $right;
                $this->databaseEditCursorIndex--;
            }
            $this->renderDatabasePanes(['settings']);
            return;
        }

        if (str_contains($input, "\033[3~")) {
            if ($this->databaseEditCursorIndex < mb_strlen($this->databaseEditBuffer)) {
                $left = mb_substr($this->databaseEditBuffer, 0, $this->databaseEditCursorIndex);
                $right = mb_substr($this->databaseEditBuffer, $this->databaseEditCursorIndex + 1);
                $this->databaseEditBuffer = $left . $right;
            }
            $this->renderDatabasePanes(['settings']);
            return;
        }

        if (str_contains($input, "\033")) {
            return;
        }

        if (preg_match('/^\X/u', $input, $matches) !== 1) {
            return;
        }

        $symbol = $matches[0];

        if (! $control instanceof InputControl || ! $control->acceptsTypedInput() || ! $control->acceptsSymbol($symbol, $this->databaseEditBuffer)) {
            return;
        }

        $left = mb_substr($this->databaseEditBuffer, 0, $this->databaseEditCursorIndex);
        $right = mb_substr($this->databaseEditBuffer, $this->databaseEditCursorIndex);
        $this->databaseEditBuffer = $left . $symbol . $right;
        $this->databaseEditCursorIndex += mb_strlen($symbol);
        $this->renderDatabasePanes(['settings']);
    }

    /**
     * Commits the current database edit back into the active asset.
     *
     * @return void
     */
    private function commitDatabaseEdit(): void
    {
        $fields = $this->getDatabaseSettingsFields();
        $field = $fields[$this->databaseSelectedSettingIndex] ?? null;

        if (! is_array($field)) {
            $this->isDatabaseEditing = false;
            $this->databaseEditBuffer = '';
            $this->databaseEditCursorIndex = 0;
            $this->renderDatabasePanes(['settings']);
            return;
        }

        try {
            $this->applyDatabaseFieldValue((string) ($field['field'] ?? ''), $this->databaseEditBuffer);
            $this->statusMessage = sprintf('%s updated.', $field['label'] ?? 'Field');
        } catch (Throwable $throwable) {
            $this->statusMessage = $throwable->getMessage();
        }

        $this->isDatabaseEditing = false;
        $this->databaseEditBuffer = '';
        $this->databaseEditCursorIndex = 0;
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
    }

    /**
     * Applies one database settings value.
     *
     * @param string $field The field identifier.
     * @param string $rawValue The raw edited value.
     * @return void
     */
    private function applyDatabaseFieldValue(string $field, string $rawValue): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        if ($this->isActorsDatabaseSelected()) {
            $this->workspace->actorDatabase->setField(
                $this->databaseSelectedActorIndex,
                $field,
                in_array($field, ['name', 'description'], true) ? trim($rawValue) : max(0, intval($rawValue)),
            );

            return;
        }

        match ($field) {
            'name', 'position', 'maxFrames' => $this->workspace->animationDatabase->setField(
                $this->databaseSelectedAnimationIndex,
                $field,
                $field === 'maxFrames' ? max(1, intval($rawValue)) : trim($rawValue)
            ),
            'brushSymbol' => $this->databaseSelectedPaintSymbol = $this->normalizeDatabaseSymbol($rawValue),
            'brushColor' => $this->databaseSelectedPaintColor = $this->normalizeDatabaseColor($rawValue),
            'frameSound', 'flashColor', 'flashDurationFrames' => $this->applyDatabaseCueFieldValue($field, $rawValue),
            default => null,
        };

        $animation = $this->getSelectedAnimation();

        if ($animation instanceof Animation) {
            $this->databaseSelectedFrameIndex = max(1, min($animation->maxFrames, $this->databaseSelectedFrameIndex));
            $this->databasePlaybackFrameIndex = $this->databaseSelectedFrameIndex;
        }

        $this->centerDatabasePreviewCursor();
    }

    /**
     * Applies cue-field edits for the selected frame.
     *
     * @param string $field The field identifier.
     * @param string $rawValue The raw field value.
     * @return void
     */
    private function applyDatabaseCueFieldValue(string $field, string $rawValue): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $animation = $this->getSelectedAnimation();

        if (! $animation instanceof Animation) {
            return;
        }

        $cue = $animation->getCue($this->databaseSelectedFrameIndex) ?? new AnimationCue();
        $soundEffect = $cue->soundEffect;
        $flashColor = $cue->flashColor;
        $flashDurationFrames = $cue->flashDurationFrames;

        if ($field === 'frameSound') {
            $soundEffect = trim($rawValue);
        }

        if ($field === 'flashColor') {
            $flashColor = $this->normalizeDatabaseColor($rawValue);
        }

        if ($field === 'flashDurationFrames') {
            $flashDurationFrames = max(0, intval($rawValue));
        }

        $this->workspace->animationDatabase->setFrameCue(
            $this->databaseSelectedAnimationIndex,
            $this->databaseSelectedFrameIndex,
            $soundEffect,
            $flashColor,
            $flashDurationFrames,
        );
    }

    /**
     * Cycles option-based settings fields with the arrow keys.
     *
     * @param int $step The cycle direction.
     * @return void
     */
    private function adjustDatabaseOptionField(int $step): void
    {
        $fields = $this->getDatabaseSettingsFields();
        $field = $fields[$this->databaseSelectedSettingIndex] ?? null;

        if (! is_array($field)) {
            return;
        }

        $control = $this->getDatabaseFieldControl($field);

        if ($control instanceof InputControl && $control->type === InputControlType::INTEGER) {
            $this->applyDatabaseFieldValue((string) ($field['field'] ?? ''), $control->adjust((string) ($field['value'] ?? '0'), $step));
            $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
            return;
        }

        $options = $field['options'] ?? null;

        if (! is_array($options) || $options === []) {
            return;
        }

        $currentValue = strtolower((string) ($field['value'] ?? ''));
        $currentValue = $currentValue === 'none' ? 'none' : $currentValue;
        $optionIndex = array_search($currentValue, $options, true);
        $optionIndex = is_int($optionIndex) ? $optionIndex : 0;
        $optionIndex = max(0, min(count($options) - 1, $optionIndex + $step));
        $this->applyDatabaseFieldValue((string) ($field['field'] ?? ''), (string) $options[$optionIndex]);
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
    }

    /**
     * Handles typed painting input inside the database preview.
     *
     * @param string $input The raw input.
     * @return void
     */
    private function handleDatabaseTypedSymbolInput(string $input): void
    {
        if (str_contains($input, "\033")) {
            return;
        }

        if (preg_match('/^\X/u', $input, $matches) !== 1) {
            return;
        }

        $symbol = $matches[0];

        if ($symbol === "\n" || $symbol === "\r" || $symbol === "\t") {
            return;
        }

        $reservedSymbols = ['!'];

        if (in_array($symbol, $reservedSymbols, true)) {
            return;
        }

        $this->databaseSelectedPaintSymbol = $this->normalizeDatabaseSymbol($symbol);
        $this->paintDatabasePreviewSymbol($this->databaseSelectedPaintSymbol, true);
    }

    /**
     * Paints the selected symbol onto the current animation frame.
     *
     * @param string $symbol The symbol to paint.
     * @return void
     */
    private function paintDatabasePreviewSymbol(string $symbol, bool $refreshSettings = false): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $animation = $this->getSelectedAnimation();

        if (! $animation instanceof Animation) {
            return;
        }

        $previewSize = $this->getDatabasePreviewSize();
        $origin = AnimationPreviewRenderer::resolveOrigin($animation->position, $previewSize['width'], $previewSize['height']);
        $cellX = $this->databasePreviewCursorX - $origin['x'];
        $cellY = $this->databasePreviewCursorY - $origin['y'];
        $color = trim($symbol) === '' ? null : $this->databaseSelectedPaintColor;

        $this->workspace->animationDatabase->setFrameCell(
            $this->databaseSelectedAnimationIndex,
            $this->databaseSelectedFrameIndex,
            $cellX,
            $cellY,
            $symbol,
            $color,
        );
        $this->databasePlaybackFrameIndex = $this->databaseSelectedFrameIndex;
        $this->statusMessage = sprintf('Animation frame #%03d updated.', $this->databaseSelectedFrameIndex);
        $this->renderDatabasePanes($refreshSettings ? ['settings', 'preview'] : ['preview']);
    }

    /**
     * Plays the selected animation inside the Database preview pane.
     *
     * @return void
     */
    private function playDatabaseAnimationPreview(): void
    {
        $animation = $this->getSelectedAnimation();

        if (! $animation instanceof Animation) {
            return;
        }

        $this->isDatabasePreviewPlaying = true;
        $this->statusMessage = sprintf('Playing %s.', $animation->name);
        (new AnimationPlayer())->play($animation, function (int $frameIndex, \Ichiloto\Engine\Animations\AnimationFrame $frame, ?AnimationCue $cue): void {
            unset($frame);
            $this->databasePlaybackFrameIndex = $frameIndex;
            $this->databasePlaybackFlashColor = $cue?->flashColor;
            $this->renderDatabasePanes(['preview']);
        });
        $this->isDatabasePreviewPlaying = false;
        $this->databasePlaybackFlashColor = null;
        $this->databasePlaybackFrameIndex = $this->databaseSelectedFrameIndex;
        $this->statusMessage = 'Preview complete.';
        $this->renderDatabasePanes(['preview']);
    }

    /**
     * Centers the database preview cursor on the current animation target.
     *
     * @return void
     */
    private function centerDatabasePreviewCursor(): void
    {
        $animation = $this->getSelectedAnimation();
        $previewSize = $this->getDatabasePreviewSize();

        if (! $animation instanceof Animation) {
            $this->databasePreviewCursorX = intdiv($previewSize['width'], 2);
            $this->databasePreviewCursorY = intdiv($previewSize['height'], 2);
            return;
        }

        $origin = AnimationPreviewRenderer::resolveOrigin($animation->position, $previewSize['width'], $previewSize['height']);
        $this->databasePreviewCursorX = $origin['x'];
        $this->databasePreviewCursorY = $origin['y'];
    }

    /**
     * Returns the size of the database preview grid.
     *
     * @return array{width: int, height: int}
     */
    private function getDatabasePreviewSize(): array
    {
        $layout = $this->resolveDatabaseLayout($this->resolveLayout());

        return [
            'width' => max(10, $layout['previewWidth'] - 2 - (self::WINDOW_HORIZONTAL_PADDING * 2)),
            'height' => max(6, $layout['previewHeight'] - 2),
        ];
    }

    /**
     * Normalizes a paint symbol down to one visible grapheme.
     *
     * @param string $symbol The raw symbol.
     * @return string
     */
    private function normalizeDatabaseSymbol(string $symbol): string
    {
        if (trim($symbol) === '') {
            return ' ';
        }

        if (preg_match('/^\X/u', $symbol, $matches) !== 1) {
            return ' ';
        }

        return $matches[0];
    }

    /**
     * Normalizes a stored animation color.
     *
     * @param string|null $color The raw color value.
     * @return string|null
     */
    private function normalizeDatabaseColor(?string $color): ?string
    {
        $normalized = strtolower(trim((string) $color));

        if ($normalized === '' || $normalized === 'none') {
            return null;
        }

        return $normalized;
    }

    /**
     * Builds the inspector field list for the current selection.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getInspectorFields(): array
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return [];
        }

        $fields = [
            [
                'label' => 'Name',
                'value' => $selectedMap->getDisplayName(),
                'control' => new InputControl(InputControlType::TEXT, $selectedMap->getDisplayName()),
                'target' => 'map',
                'field' => 'name',
            ],
            [
                'label' => 'Region',
                'value' => $selectedMap->getRegion(),
                'control' => new InputControl(InputControlType::TEXT, $selectedMap->getRegion()),
                'target' => 'map',
                'field' => 'region',
            ],
            [
                'label' => 'Description',
                'value' => $selectedMap->getDescription(),
                'control' => new InputControl(InputControlType::TEXT, $selectedMap->getDescription()),
                'target' => 'map',
                'field' => 'description',
            ],
            [
                'label' => 'Size',
                'value' => '',
                'editable' => false,
            ],
            [
                'label' => '  X',
                'value' => (string) $selectedMap->getWidth(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $selectedMap->getWidth()),
                'target' => 'map-size',
                'field' => 'width',
            ],
            [
                'label' => '  Y',
                'value' => (string) $selectedMap->getHeight(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $selectedMap->getHeight()),
                'target' => 'map-size',
                'field' => 'height',
            ],
            [
                'label' => 'Events',
                'value' => (string) $selectedMap->getEventDefinitionCount(),
                'editable' => false,
            ],
            [
                'label' => 'Triggers',
                'value' => (string) $selectedMap->getTriggerCount(),
                'editable' => false,
            ],
        ];

        if ($this->editingMode !== self::MODE_EVENT) {
            return $fields;
        }

        $marker = $this->getFocusedEventMarker();

        if ($marker === null) {
            $fields[] = [
                'label' => 'Event',
                'value' => 'Move cursor onto an event marker',
                'editable' => false,
            ];
            return $fields;
        }

        $definition = $selectedMap->getEventDefinition($marker);
        $bounds = $selectedMap->getEventBounds($marker);

        $fields[] = [
            'label' => 'Event',
            'value' => $marker,
            'editable' => false,
        ];

        $fields[] = [
            'label' => 'Type',
            'value' => $this->resolveEventTypeLabel(
                is_array($definition) && is_string($definition['class'] ?? null)
                    ? $definition['class']
                    : null,
            ),
            'editable' => true,
            'target' => 'event-type',
            'marker' => $marker,
        ];

        if ($bounds !== null) {
            $fields[] = [
                'label' => 'Position',
                'value' => '',
                'editable' => false,
            ];
            $fields[] = [
                'label' => '  X',
                'value' => (string) $bounds['x'],
                'control' => new InputControl(InputControlType::INTEGER, (string) $bounds['x']),
                'target' => 'event-bounds',
                'marker' => $marker,
                'field' => 'x',
            ];
            $fields[] = [
                'label' => '  Y',
                'value' => (string) $bounds['y'],
                'control' => new InputControl(InputControlType::INTEGER, (string) $bounds['y']),
                'target' => 'event-bounds',
                'marker' => $marker,
                'field' => 'y',
            ];
            $fields[] = [
                'label' => 'Size',
                'value' => '',
                'editable' => false,
            ];
            $fields[] = [
                'label' => '  X',
                'value' => (string) $bounds['width'],
                'control' => new InputControl(InputControlType::INTEGER, (string) $bounds['width']),
                'target' => 'event-bounds',
                'marker' => $marker,
                'field' => 'width',
            ];
            $fields[] = [
                'label' => '  Y',
                'value' => (string) $bounds['height'],
                'control' => new InputControl(InputControlType::INTEGER, (string) $bounds['height']),
                'target' => 'event-bounds',
                'marker' => $marker,
                'field' => 'height',
            ];
        }

        if ($definition !== null) {
            $fields = [...$fields, ...$this->buildEventDataFields($marker, $definition)];
        }

        return $fields;
    }

    /**
     * Builds editable inspector fields for event data.
     *
     * @param string $marker The event marker.
     * @param array<string, mixed> $definition The event definition.
     * @return array<int, array<string, mixed>>
     */
    private function buildEventDataFields(string $marker, array $definition): array
    {
        $fields = [];
        $eventData = $definition['data'] ?? [];

        if (! is_array($eventData)) {
            return $fields;
        }

        foreach ($this->flattenInspectorFields($eventData, ['data']) as $field) {
            $field['marker'] = $marker;
            $field['target'] = 'event';
            $fields[] = $field;
        }

        return $fields;
    }

    /**
     * Flattens nested scalar data into editable inspector fields.
     *
     * @param array<string|int, mixed> $data The data to flatten.
     * @param array<int, string> $path The current path.
     * @return array<int, array<string, mixed>>
     */
    private function flattenInspectorFields(array $data, array $path): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $segment = (string) $key;
            $nextPath = [...$path, $segment];

            if (is_array($value)) {
                if (array_key_exists('x', $value) && array_key_exists('y', $value) && is_scalar($value['x']) && is_scalar($value['y'])) {
                    $label = implode(' ', array_map(
                        static fn(string $part): string => ucwords(str_replace(['_', '-'], ' ', $part)),
                        array_slice($nextPath, 1)
                    ));
                    $fields[] = [
                        'label' => $label,
                        'value' => '',
                        'editable' => false,
                    ];
                    $fields[] = [
                        'label' => '  X',
                        'value' => (string) $value['x'],
                        'control' => new InputControl(
                            is_int($value['x']) ? InputControlType::INTEGER : InputControlType::TEXT,
                            (string) $value['x'],
                        ),
                        'path' => [...$nextPath, 'x'],
                    ];
                    $fields[] = [
                        'label' => '  Y',
                        'value' => (string) $value['y'],
                        'control' => new InputControl(
                            is_int($value['y']) ? InputControlType::INTEGER : InputControlType::TEXT,
                            (string) $value['y'],
                        ),
                        'path' => [...$nextPath, 'y'],
                    ];
                    continue;
                }

                $fields = [...$fields, ...$this->flattenInspectorFields($value, $nextPath)];
                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $displayPath = array_slice($nextPath, 1);
            $label = implode(' ', array_map(
                static fn(string $part): string => ctype_digit($part)
                    ? '#' . ((int) $part + 1)
                    : ucwords(str_replace(['_', '-'], ' ', $part)),
                $displayPath
            ));
            $stringValue = (string) $value;
            $fields[] = [
                'label' => $label,
                'value' => $stringValue,
                'control' => new InputControl(
                    is_int($value) ? InputControlType::INTEGER : InputControlType::TEXT,
                    $stringValue,
                ),
                'path' => $nextPath,
            ];
        }

        return $fields;
    }

    /**
     * Draws the editor shell.
     *
     * @return void
     */
    private function render(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            throw new RuntimeException('The editor workspace is not loaded.');
        }

        Console::cursor()->hide();

        if ($this->isDatabaseOpen) {
            Console::clear();
            $this->renderDatabaseArea(includeRoot: true);
            return;
        }

        Console::clear();
        $this->createHeaderWindow()->render();
        $this->createAssetWindow()->render();
        $this->createCanvasWindow()->render();
        $this->createInspectorWindow()->render();
        $this->createFooterWindow()->render();

        $this->renderOverlays();
    }

    /**
     * Returns the live terminal size.
     *
     * @return array{width: int, height: int}
     */
    private function getTerminalSize(): array
    {
        $width = 80;
        $height = 24;
        $sttySize = trim((string) shell_exec('stty size 2>/dev/null'));

        if (preg_match('/^(\d+)\s+(\d+)$/', $sttySize, $matches) === 1) {
            $height = (int) $matches[1];
            $width = (int) $matches[2];
        }

        return [
            'width' => max(80, $width),
            'height' => max(24, $height),
        ];
    }

    /**
     * Resolves the current editor layout metrics.
     *
     * @return array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int}
     */
    private function resolveLayout(): array
    {
        $size = $this->lastTerminalSize ?? $this->getTerminalSize();
        $width = max(80, $size['width']);
        $height = max(24, $size['height']);
        $leftWidth = 32;
        $rightWidth = 34;
        $gutter = 1;
        $centerWidth = max(30, $width - $leftWidth - $rightWidth - ($gutter * 4));
        $contentHeight = max(10, $height - 8);

        return [
            'width' => $width,
            'height' => $height,
            'leftWidth' => $leftWidth,
            'rightWidth' => $rightWidth,
            'gutter' => $gutter,
            'centerWidth' => $centerWidth,
            'contentHeight' => $contentHeight,
        ];
    }

    /**
     * Keeps the selected asset index within the current map list.
     *
     * @param int $index The index to clamp.
     * @return int
     */
    private function clampSelection(int $index): int
    {
        if (! $this->workspace instanceof ProjectWorkspace || $this->workspace->mapIds === []) {
            return 0;
        }

        return max(0, min(count($this->workspace->mapIds) - 1, $index));
    }

    /**
     * Clamps the current canvas offsets against the selected map dimensions.
     *
     * @return void
     */
    private function clampCanvasOffsets(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            $this->canvasOffsetX = 0;
            $this->canvasOffsetY = 0;
            return;
        }

        $selectedMap = $this->workspace->getMapByIndex($this->selectedAssetIndex);

        if (! $selectedMap instanceof ProjectMap) {
            $this->canvasOffsetX = 0;
            $this->canvasOffsetY = 0;
            return;
        }

        $layout = $this->resolveLayout();
        $viewportWidth = max(1, $layout['centerWidth'] - 2);
        $viewportHeight = max(1, $layout['contentHeight'] - 4);
        $this->canvasOffsetX = max(0, min(max(0, $selectedMap->getWidth() - $viewportWidth), $this->canvasOffsetX));
        $this->canvasOffsetY = max(0, min(max(0, $selectedMap->getHeight() - $viewportHeight), $this->canvasOffsetY));
    }

    /**
     * Clamps the current editing cursor against the selected map.
     *
     * @return void
     */
    private function clampCursor(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            $this->cursorX = 0;
            $this->cursorY = 0;
            return;
        }

        $selectedMap = $this->workspace->getMapByIndex($this->selectedAssetIndex);

        if (! $selectedMap instanceof ProjectMap) {
            $this->cursorX = 0;
            $this->cursorY = 0;
            return;
        }

        $this->cursorX = max(0, min(max(0, $selectedMap->getWidth() - 1), $this->cursorX));
        $this->cursorY = max(0, min(max(0, $selectedMap->getHeight() - 1), $this->cursorY));
    }

    /**
     * Keeps the current cursor inside the visible viewport.
     *
     * @return void
     */
    private function syncViewportToCursor(): void
    {
        $layout = $this->resolveLayout();
        $viewportWidth = max(1, $layout['centerWidth'] - 2);
        $viewportHeight = max(1, $layout['contentHeight'] - 4);

        if ($this->cursorX < $this->canvasOffsetX) {
            $this->canvasOffsetX = $this->cursorX;
        } elseif ($this->cursorX >= $this->canvasOffsetX + $viewportWidth) {
            $this->canvasOffsetX = $this->cursorX - $viewportWidth + 1;
        }

        if ($this->cursorY < $this->canvasOffsetY) {
            $this->canvasOffsetY = $this->cursorY;
        } elseif ($this->cursorY >= $this->canvasOffsetY + $viewportHeight) {
            $this->canvasOffsetY = $this->cursorY - $viewportHeight + 1;
        }
    }

    /**
     * Pads or truncates a set of lines to the available content height.
     *
     * @param string[] $lines The lines to fit.
     * @param int $availableWidth The available content width.
     * @param int $availableLines The available content height.
     * @return string[]
     */
    private function fitLines(array $lines, int $availableWidth, int $availableLines): array
    {
        $lines = array_map(
            static fn(string $line): string => mb_strimwidth($line, 0, $availableWidth, ''),
            array_slice($lines, 0, $availableLines)
        );

        return array_pad($lines, $availableLines, '');
    }

    /**
     * Resolves the usable content width inside a padded editor window.
     *
     * @param int $windowWidth The full window width including borders.
     * @return int
     */
    private function getWindowContentWidth(int $windowWidth): int
    {
        return max(1, $windowWidth - 2 - (self::WINDOW_HORIZONTAL_PADDING * 2));
    }

    /**
     * Resolves the current pane color.
     *
     * @param string|null $pane The pane identifier to compare against focus.
     * @return Color
     */
    private function resolvePaneColor(?string $pane = null): Color
    {
        if ($pane !== null && $this->focusedPane === $pane) {
            return Color::LIGHT_BLUE;
        }

        return Color::WHITE;
    }

    /**
     * Renders the live editing cursor when the canvas has focus.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderCanvasCursor(array $layout): void
    {
        if (
            $this->focusedPane !== self::FOCUS_CANVAS ||
            $this->isCharacterMapOpen ||
            $this->isDeleteConfirmationOpen ||
            $this->activeMousePaintButton !== null
        ) {
            Console::cursor()->hide();
            return;
        }

        $previewRow = $this->cursorY - $this->canvasOffsetY;
        $previewColumn = $this->cursorX - $this->canvasOffsetX;
        $previewHeight = max(1, $layout['contentHeight'] - 4);
        $previewWidth = $this->getWindowContentWidth($layout['centerWidth']);

        if ($previewRow < 0 || $previewRow >= $previewHeight || $previewColumn < 0 || $previewColumn >= $previewWidth) {
            Console::cursor()->hide();
            return;
        }

        $canvasLeft = 2 + $layout['leftWidth'] + $layout['gutter'];
        $canvasTop = 5;
        Console::cursor()->show();
        Console::cursor()->moveTo(
            $canvasLeft + 1 + self::WINDOW_HORIZONTAL_PADDING + $previewColumn,
            $canvasTop + 3 + $previewRow
        );
    }

    /**
     * Renders the character-map overlay.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderCharacterMapOverlay(array $layout): void
    {
        $palette = $this->getCharacterPalette();
        $rows = [];

        foreach (array_chunk($palette, self::CHARACTER_MAP_COLUMNS) as $rowIndex => $rowSymbols) {
            $cells = [];

            foreach ($rowSymbols as $columnIndex => $symbol) {
                $index = ($rowIndex * self::CHARACTER_MAP_COLUMNS) + $columnIndex;
                $label = $index === $this->characterPaletteIndex
                    ? sprintf('[%s]', $symbol === ' ' ? '␠' : $symbol)
                    : sprintf(' %s ', $symbol === ' ' ? '␠' : $symbol);
                $cells[] = $label;
            }

            $rows[] = implode(' ', $cells);
        }

        $overlayWidth = min(max(24, $layout['centerWidth'] - 6), max(24, $layout['width'] - 10));
        $overlayHeight = min(max(5, count($rows) + 2), max(5, $layout['height'] - 8));
        $left = max(2, intdiv($layout['width'] - $overlayWidth, 2));
        $top = max(2, intdiv($layout['height'] - $overlayHeight, 2));

        $window = new EditorWindow(
            title: 'Character Map',
            help: 'Enter:Insert  C:Close',
            position: ['x' => $left, 'y' => $top],
            width: $overlayWidth,
            height: $overlayHeight,
            foregroundColor: Color::LIGHT_BLUE,
            content: $this->fitLines($rows, max(1, $overlayWidth - 2), max(1, $overlayHeight - 2)),
        );

        $window->render();
    }

    /**
     * Renders the delete confirmation overlay.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderDeleteConfirmationOverlay(array $layout): void
    {
        $selectedMap = $this->getSelectedMap();
        $mapLabel = $selectedMap instanceof ProjectMap ? $selectedMap->mapId : 'the selected map';
        $rows = [
            sprintf('Delete %s?', $mapLabel),
            'This removes the map folder and all associated split files.',
            '',
            'Enter/Y: Delete',
            'Esc/N: Cancel',
        ];
        $overlayWidth = min(max(48, mb_strwidth($mapLabel) + 22), max(48, $layout['width'] - 10));
        $overlayHeight = 7;
        $left = max(2, intdiv($layout['width'] - $overlayWidth, 2));
        $top = max(2, intdiv($layout['height'] - $overlayHeight, 2));

        $window = new EditorWindow(
            title: 'Delete Map',
            help: 'Enter:Delete  Esc:Cancel',
            position: ['x' => $left, 'y' => $top],
            width: $overlayWidth,
            height: $overlayHeight,
            foregroundColor: Color::LIGHT_BLUE,
            content: $this->fitLines($rows, $this->getWindowContentWidth($overlayWidth), max(1, $overlayHeight - 2)),
        );

        $window->render();
    }

    /**
     * Renders the event type picker overlay.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderEventTypeDialogOverlay(array $layout): void
    {
        $definitions = EventTypeCatalog::all();
        $selectedDefinition = EventTypeCatalog::at($this->selectedEventTypeIndex);
        $rows = [];

        foreach ($definitions as $index => $definition) {
            $rows[] = sprintf(
                '%s %s',
                $index === $this->selectedEventTypeIndex ? '>' : ' ',
                $definition->label,
            );
        }

        $rows[] = '';
        $rows[] = $selectedDefinition->description;

        $overlayWidth = min(56, max(40, mb_strwidth($selectedDefinition->description) + 6));
        $overlayWidth = min($overlayWidth, max(40, $layout['width'] - 10));
        $overlayHeight = min(max(8, count($rows) + 2), max(8, $layout['height'] - 8));
        $left = max(2, intdiv($layout['width'] - $overlayWidth, 2));
        $top = max(2, intdiv($layout['height'] - $overlayHeight, 2));

        $window = new EditorWindow(
            title: 'Event Type',
            help: 'Enter:Select  Esc:Cancel',
            position: ['x' => $left, 'y' => $top],
            width: $overlayWidth,
            height: $overlayHeight,
            foregroundColor: Color::LIGHT_BLUE,
            content: $this->fitLines(
                $rows,
                $this->getWindowContentWidth($overlayWidth),
                max(1, $overlayHeight - 2),
            ),
        );

        $window->render();
    }

    /**
     * Renders the destination picker overlay.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderDestinationDialogOverlay(array $layout): void
    {
        $entries = $this->getDestinationDialogEntries();
        $selectedEntry = $entries[$this->selectedDestinationIndex] ?? null;
        $contentWidth = min(64, max(44, $layout['width'] - 12));
        $contentHeight = min(max(10, count($entries) + 5), max(10, $layout['height'] - 8));
        $availableRows = max(1, $contentHeight - 2);
        $listRows = max(1, $availableRows - 2);
        $rows = $this->buildDestinationDialogRows($entries, $listRows);
        $left = max(2, intdiv($layout['width'] - $contentWidth, 2));
        $top = max(2, intdiv($layout['height'] - $contentHeight, 2));

        if (is_array($selectedEntry)) {
            $rows[] = '';
            $rows[] = sprintf('Map ID: %s', $selectedEntry['mapId']);
        }

        $window = new EditorWindow(
            title: 'Destination',
            help: 'Enter:Select  Esc:Cancel',
            position: ['x' => $left, 'y' => $top],
            width: $contentWidth,
            height: $contentHeight,
            foregroundColor: Color::LIGHT_BLUE,
            content: $this->fitLines(
                $rows,
                $this->getWindowContentWidth($contentWidth),
                $availableRows,
            ),
        );

        $window->render();
    }

    /**
     * Renders the destination spawn-point instructions overlay.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderDestinationSpawnSelectionOverlay(array $layout): void
    {
        $mapId = is_array($this->destinationSelectionContext)
            ? $this->destinationSelectionContext['destinationMapId']
            : 'destination map';
        $window = new EditorWindow(
            title: 'Select Spawn Point',
            help: 'Enter:Confirm  Esc:Cancel',
            position: ['x' => 4, 'y' => 1],
            width: min(54, max(40, $layout['width'] - 8)),
            height: 5,
            foregroundColor: Color::LIGHT_BLUE,
            content: [
                sprintf('Choose a spawn point on %s.', $mapId),
                sprintf('Current point: (%d, %d)', $this->cursorX, $this->cursorY),
                '',
            ],
        );

        $window->render();
    }

    /**
     * Renders the confirmation dialog for the selected spawn point.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderDestinationSpawnConfirmationOverlay(array $layout): void
    {
        $mapId = is_array($this->destinationSelectionContext)
            ? $this->destinationSelectionContext['destinationMapId']
            : 'destination map';
        $window = new EditorWindow(
            title: 'Confirm Spawn Point',
            help: 'Enter:Apply  Esc:Back',
            position: [
                'x' => max(2, intdiv($layout['width'] - 50, 2)),
                'y' => max(2, intdiv($layout['height'] - 7, 2)),
            ],
            width: 50,
            height: 7,
            foregroundColor: Color::LIGHT_BLUE,
            content: [
                sprintf('Map: %s', $mapId),
                sprintf('Spawn Point: (%d, %d)', $this->cursorX, $this->cursorY),
                '',
                'Apply this destination and return to the source event?',
                '',
            ],
        );

        $window->render();
    }

    /**
     * Returns grouped destination entries for the picker.
     *
     * @return array<int, array{region: string, name: string, mapId: string}>
     */
    private function getDestinationDialogEntries(): array
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return [];
        }

        $entries = [];

        foreach ($this->workspace->maps as $map) {
            $entries[] = [
                'region' => $map->getRegion(),
                'name' => $map->getDisplayName(),
                'mapId' => $map->mapId,
            ];
        }

        return $entries;
    }

    /**
     * Resolves the selected destination index for a current map id.
     *
     * @param string $currentDestinationMap The currently stored destination map id.
     * @return int
     */
    private function resolveDestinationSelectionIndex(string $currentDestinationMap): int
    {
        foreach ($this->getDestinationDialogEntries() as $index => $entry) {
            if ($entry['mapId'] === $currentDestinationMap) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * Builds visible destination dialog rows with region headers.
     *
     * @param array<int, array{region: string, name: string, mapId: string}> $entries The selectable destinations.
     * @param int $availableRows The available content rows.
     * @return string[]
     */
    private function buildDestinationDialogRows(array $entries, int $availableRows): array
    {
        $rows = [];
        $selectedRowIndex = 0;
        $currentRegion = null;

        foreach ($entries as $index => $entry) {
            if ($entry['region'] !== $currentRegion) {
                $currentRegion = $entry['region'];
                $rows[] = sprintf('[%s]', $currentRegion === '' ? 'Unknown' : $currentRegion);
            }

            if ($index === $this->selectedDestinationIndex) {
                $selectedRowIndex = count($rows);
            }

            $rows[] = sprintf(
                '%s %s',
                $index === $this->selectedDestinationIndex ? '>' : ' ',
                $entry['name'],
            );
        }

        if (count($rows) <= $availableRows) {
            return $rows;
        }

        $startRow = max(0, min(count($rows) - $availableRows, $selectedRowIndex - intdiv($availableRows, 2)));

        return array_slice($rows, $startRow, $availableRows);
    }

    /**
     * Resolves a stored trigger class into a user-facing event type label.
     *
     * @param string|null $className The stored trigger class name.
     * @return string
     */
    private function resolveEventTypeLabel(?string $className): string
    {
        if ($className === null || $className === '') {
            return 'Unset';
        }

        foreach (EventTypeCatalog::all() as $definition) {
            if ($definition->className === $className) {
                return $definition->label;
            }
        }

        return basename(str_replace('\\', '/', $className));
    }

    /**
     * Resolves the Database overlay layout.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The base editor layout.
     * @return array<string, int>
     */
    private function resolveDatabaseLayout(array $layout): array
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
        $minimumListWidth = 20;
        $minimumRightWidth = 34;
        $categoryContentWidth = max(
            mb_strwidth('Tab:Next Pane'),
            ...array_map(
                static fn(DatabaseCategoryDefinition $category): int => mb_strwidth($category->label) + 2,
                DatabaseCatalog::all()
            )
        );
        $maximumCategoryWidth = max(
            18,
            $innerWidth - $minimumListWidth - $minimumRightWidth - ($gutter * 2)
        );
        $categoryWidth = max(
            18,
            min(24, min($maximumCategoryWidth, $categoryContentWidth + 4))
        );
        $listWidth = max(
            $minimumListWidth,
            min(24, $innerWidth - $categoryWidth - $minimumRightWidth - ($gutter * 2))
        );
        $rightWidth = max(30, $innerWidth - $categoryWidth - $listWidth - ($gutter * 2));
        $topHeight = $this->isActorsDatabaseSelected() ? 12 : 10;
        $framesWidth = $this->isActorsDatabaseSelected() ? 18 : 10;
        $previewWidth = max(20, $rightWidth - $framesWidth - $gutter);
        $previewHeight = max(8, $innerHeight - $topHeight - $gutter);
        $settingsWidth = max(18, min(28, intdiv($rightWidth - $gutter, 2)));
        $cueWidth = $rightWidth - $settingsWidth - $gutter;

        if ($cueWidth < 18) {
            $cueWidth = 18;
            $settingsWidth = max(18, $rightWidth - $cueWidth - $gutter);
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
            'categoryWidth' => $categoryWidth,
            'listWidth' => $listWidth,
            'rightWidth' => $rightWidth,
            'topHeight' => $topHeight,
            'framesWidth' => $framesWidth,
            'previewWidth' => $previewWidth,
            'previewHeight' => $previewHeight,
            'settingsWidth' => $settingsWidth,
            'cueWidth' => $cueWidth,
        ];
    }

    /**
     * Renders the Database overlay.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The base editor layout.
     * @return void
     */
    private function renderDatabaseOverlay(array $layout): void
    {
        $databaseLayout = $this->resolveDatabaseLayout($layout);
        $this->renderDatabasePanes(
            ['categories', 'list', 'settings', 'cue', 'frames', 'preview'],
            $databaseLayout,
            true
        );
    }

    /**
     * Re-renders the active Database overlay.
     *
     * @param bool $includeRoot Whether to include the outer Database frame.
     * @return void
     */
    private function renderDatabaseArea(bool $includeRoot = false): void
    {
        $layout = $this->resolveDatabaseLayout($this->resolveLayout());
        $this->renderDatabasePanes(
            ['categories', 'list', 'settings', 'cue', 'frames', 'preview'],
            $layout,
            $includeRoot
        );
    }

    /**
     * Re-renders panes whose visual state depends on Database focus.
     *
     * @return void
     */
    private function renderDatabaseFocusDependentArea(): void
    {
        $layout = $this->resolveDatabaseLayout($this->resolveLayout());
        $this->renderDatabasePanes(['categories', 'list', 'settings', 'frames', 'preview'], $layout);
    }

    /**
     * Re-renders specific Database panes without clearing the full editor.
     *
     * @param string[] $panes The pane identifiers to render.
     * @param array<string, int>|null $layout The optional Database layout.
     * @param bool $includeRoot Whether to render the outer Database frame.
     * @return void
     */
    private function renderDatabasePanes(array $panes, ?array $layout = null, bool $includeRoot = false): void
    {
        $layout ??= $this->resolveDatabaseLayout($this->resolveLayout());
        $uniquePanes = array_values(array_unique($panes));
        Console::cursor()->hide();

        if ($includeRoot) {
            $this->createDatabaseRootWindow($layout)->render();
        }

        if (in_array('categories', $uniquePanes, true)) {
            $this->createDatabaseCategoryWindow($layout)->render();
        }

        if (in_array('list', $uniquePanes, true)) {
            $this->createDatabaseListWindow($layout)->render();
        }

        if (in_array('settings', $uniquePanes, true)) {
            $this->createDatabaseSettingsWindow($layout)->render();
        }

        if (in_array('cue', $uniquePanes, true)) {
            $this->createDatabaseCueWindow($layout)->render();
        }

        if (in_array('frames', $uniquePanes, true)) {
            $this->createDatabaseFramesWindow($layout)->render();
        }

        if (in_array('preview', $uniquePanes, true)) {
            $this->createDatabasePreviewWindow($layout)->render();
            $this->renderDatabasePreview($layout);
        }

        if ($this->isDatabaseEditing && in_array('settings', $uniquePanes, true)) {
            $this->renderDatabaseEditCursor($layout);
            return;
        }

        Console::cursor()->hide();
    }

    /**
     * Renders the animation preview content inside the preview window.
     *
     * @param array<string, int> $layout The Database layout.
     * @return void
     */
    private function renderDatabasePreview(array $layout): void
    {
        if (! $this->isAnimationsDatabaseSelected()) {
            return;
        }

        $animation = $this->getSelectedAnimation();

        if (! $animation instanceof Animation) {
            return;
        }

        $previewLeft = $layout['innerX'] + $layout['categoryWidth'] + $layout['listWidth'] + $layout['framesWidth'] + ($layout['gutter'] * 4);
        $previewTop = $layout['innerY'] + $layout['topHeight'] + $layout['gutter'];
        $previewWidth = max(10, $layout['previewWidth'] - 2 - (self::WINDOW_HORIZONTAL_PADDING * 2));
        $previewHeight = $this->getDatabasePreviewSize()['height'];
        $frameIndex = $this->isDatabasePreviewPlaying ? $this->databasePlaybackFrameIndex : $this->databaseSelectedFrameIndex;
        $preview = AnimationPreviewRenderer::build($animation, $frameIndex, $previewWidth, $previewHeight);

        foreach ($preview['cells'] as $cell) {
            $x = $previewLeft + 1 + self::WINDOW_HORIZONTAL_PADDING + $cell['x'];
            $y = $previewTop + 1 + $cell['y'];
            Console::cursor()->moveTo($x, $y);
            echo $this->resolveAnimationColor($cell['color'])->value . $cell['symbol'] . Color::RESET->value;
        }

        if ($this->databaseFocus === self::DATABASE_FOCUS_PREVIEW && ! $this->isDatabasePreviewPlaying) {
            Console::cursor()->moveTo(
                $previewLeft + 1 + self::WINDOW_HORIZONTAL_PADDING + $this->databasePreviewCursorX,
                $previewTop + 1 + $this->databasePreviewCursorY,
            );
            echo Color::LIGHT_BLUE->value . '▣' . Color::RESET->value;
        }
    }

    /**
     * Renders the live cursor for editing Database settings.
     *
     * @param array<string, int> $layout The Database layout.
     * @return void
     */
    private function renderDatabaseEditCursor(array $layout): void
    {
        $fields = $this->getDatabaseSettingsFields();
        $field = $fields[$this->databaseSelectedSettingIndex] ?? null;

        if (! is_array($field)) {
            Console::cursor()->hide();
            return;
        }

        $label = (string) ($field['label'] ?? 'Field');
        $leftText = sprintf('> %s: ', $label);
        $settingsContentWidth = $this->getWindowContentWidth($layout['settingsWidth']);
        $maxValueWidth = max(0, $settingsContentWidth - mb_strwidth($leftText));
        $visibleValue = mb_strimwidth($this->databaseEditBuffer, 0, $maxValueWidth, '');
        $visibleCursorIndex = min($this->databaseEditCursorIndex, mb_strlen($visibleValue));
        $cursorOffset = min(
            $settingsContentWidth - 1,
            mb_strwidth($leftText . mb_substr($visibleValue, 0, $visibleCursorIndex))
        );
        $settingsLeft = $layout['innerX'] + $layout['categoryWidth'] + $layout['listWidth'] + ($layout['gutter'] * 2);
        $settingsTop = $layout['innerY'];
        Console::cursor()->show();
        Console::cursor()->moveTo(
            $settingsLeft + 1 + self::WINDOW_HORIZONTAL_PADDING + $cursorOffset,
            $settingsTop + 1 + min($this->databaseSelectedSettingIndex, max(0, $layout['topHeight'] - 3)),
        );
    }

    /**
     * Creates the Database root window.
     *
     * @param array<string, int> $layout The Database layout.
     * @return EditorWindow
     */
    private function createDatabaseRootWindow(array $layout): EditorWindow
    {
        return new EditorWindow(
            title: 'Database',
            help: 'Esc:Close  Ctrl+S:Save',
            position: ['x' => $layout['rootX'], 'y' => $layout['rootY']],
            width: $layout['rootWidth'],
            height: $layout['rootHeight'],
            foregroundColor: Color::WHITE,
            content: array_fill(0, max(1, $layout['rootHeight'] - 2), ''),
        );
    }

    /**
     * Creates the Database category window.
     *
     * @param array<string, int> $layout The Database layout.
     * @return EditorWindow
     */
    private function createDatabaseCategoryWindow(array $layout): EditorWindow
    {
        $lines = [];

        foreach (DatabaseCatalog::all() as $index => $category) {
            $prefix = $index === $this->databaseCategoryIndex ? '> ' : '  ';
            $lines[] = $prefix . $category->label;
        }

        return new EditorWindow(
            title: 'Categories',
            help: 'Tab:Next Pane',
            position: ['x' => $layout['innerX'], 'y' => $layout['innerY']],
            width: $layout['categoryWidth'],
            height: $layout['innerHeight'],
            foregroundColor: $this->resolveDatabasePaneColor(self::DATABASE_FOCUS_CATEGORIES),
            content: $this->fitLines(
                $lines,
                $this->getWindowContentWidth($layout['categoryWidth']),
                $layout['innerHeight'] - 2
            ),
        );
    }

    /**
     * Creates the Database animation list window.
     *
     * @param array<string, int> $layout The Database layout.
     * @return EditorWindow
     */
    private function createDatabaseListWindow(array $layout): EditorWindow
    {
        return new EditorWindow(
            title: $this->getSelectedDatabaseCategory(),
            help: $this->isActorsDatabaseSelected() || $this->isAnimationsDatabaseSelected() ? 'Shift+A:New' : '',
            position: ['x' => $layout['innerX'] + $layout['categoryWidth'] + $layout['gutter'], 'y' => $layout['innerY']],
            width: $layout['listWidth'],
            height: $layout['innerHeight'],
            foregroundColor: $this->resolveDatabasePaneColor(self::DATABASE_FOCUS_LIST),
            content: $this->fitLines(
                $this->getDatabaseListLines(),
                $this->getWindowContentWidth($layout['listWidth']),
                $layout['innerHeight'] - 2
            ),
        );
    }

    /**
     * Creates the Database settings window.
     *
     * @param array<string, int> $layout The Database layout.
     * @return EditorWindow
     */
    private function createDatabaseSettingsWindow(array $layout): EditorWindow
    {
        return new EditorWindow(
            title: 'General Settings',
            help: $this->isDatabaseEditing ? 'Enter:Apply  Esc:Cancel' : 'Enter:Edit',
            position: ['x' => $layout['innerX'] + $layout['categoryWidth'] + $layout['listWidth'] + ($layout['gutter'] * 2), 'y' => $layout['innerY']],
            width: $layout['settingsWidth'],
            height: $layout['topHeight'],
            foregroundColor: $this->resolveDatabasePaneColor(self::DATABASE_FOCUS_SETTINGS),
            content: $this->fitLines(
                $this->getDatabaseSettingsLines(),
                $this->getWindowContentWidth($layout['settingsWidth']),
                $layout['topHeight'] - 2
            ),
        );
    }

    /**
     * Creates the cue summary window.
     *
     * @param array<string, int> $layout The Database layout.
     * @return EditorWindow
     */
    private function createDatabaseCueWindow(array $layout): EditorWindow
    {
        return new EditorWindow(
            title: $this->isActorsDatabaseSelected() ? 'Collections' : 'SE and Flash Timing',
            help: '',
            position: ['x' => $layout['innerX'] + $layout['categoryWidth'] + $layout['listWidth'] + $layout['settingsWidth'] + ($layout['gutter'] * 3), 'y' => $layout['innerY']],
            width: $layout['cueWidth'],
            height: $layout['topHeight'],
            foregroundColor: Color::WHITE,
            content: $this->fitLines(
                $this->getDatabaseCueLines(),
                $this->getWindowContentWidth($layout['cueWidth']),
                $layout['topHeight'] - 2
            ),
        );
    }

    /**
     * Creates the frame list window.
     *
     * @param array<string, int> $layout The Database layout.
     * @return EditorWindow
     */
    private function createDatabaseFramesWindow(array $layout): EditorWindow
    {
        return new EditorWindow(
            title: $this->isActorsDatabaseSelected() ? 'Stats' : 'Frames',
            help: $this->isActorsDatabaseSelected() ? '' : 'Up/Down:Frame',
            position: ['x' => $layout['innerX'] + $layout['categoryWidth'] + $layout['listWidth'] + ($layout['gutter'] * 2), 'y' => $layout['innerY'] + $layout['topHeight'] + $layout['gutter']],
            width: $layout['framesWidth'],
            height: $layout['previewHeight'],
            foregroundColor: $this->resolveDatabasePaneColor(self::DATABASE_FOCUS_FRAMES),
            content: $this->fitLines(
                $this->getDatabaseFrameLines(),
                $this->getWindowContentWidth($layout['framesWidth']),
                $layout['previewHeight'] - 2
            ),
        );
    }

    /**
     * Creates the animation preview window.
     *
     * @param array<string, int> $layout The Database layout.
     * @return EditorWindow
     */
    private function createDatabasePreviewWindow(array $layout): EditorWindow
    {
        return new EditorWindow(
            title: 'Preview',
            help: $this->isActorsDatabaseSelected() ? '' : 'Type:Paint  Shift+P:Play',
            position: ['x' => $layout['innerX'] + $layout['categoryWidth'] + $layout['listWidth'] + $layout['framesWidth'] + ($layout['gutter'] * 3), 'y' => $layout['innerY'] + $layout['topHeight'] + $layout['gutter']],
            width: $layout['previewWidth'],
            height: $layout['previewHeight'],
            foregroundColor: $this->resolveDatabasePaneColor(self::DATABASE_FOCUS_PREVIEW),
            content: $this->isActorsDatabaseSelected()
                ? $this->fitLines(
                    $this->getDatabasePreviewLines(),
                    $this->getWindowContentWidth($layout['previewWidth']),
                    $layout['previewHeight'] - 2
                )
                : array_fill(0, max(1, $layout['previewHeight'] - 2), ''),
        );
    }

    /**
     * Returns the list lines for the active Database category.
     *
     * @return string[]
     */
    private function getDatabaseListLines(): array
    {
        if ($this->isActorsDatabaseSelected()) {
            return $this->getDatabaseActorListLines();
        }

        if ($this->isAnimationsDatabaseSelected()) {
            return $this->getDatabaseAnimationListLines();
        }

        $category = $this->getSelectedDatabaseCategoryDefinition();

        return [
            $category->description,
            '',
            'Editor coming soon.',
        ];
    }

    /**
     * Returns the actor list lines.
     *
     * @return string[]
     */
    private function getDatabaseActorListLines(): array
    {
        $actors = $this->workspace?->actorDatabase->getActors() ?? [];

        if ($actors === []) {
            return ['No actors yet.', '', 'Shift+A to create one.'];
        }

        $lines = [];

        foreach ($actors as $index => $actor) {
            $prefix = $index === $this->databaseSelectedActorIndex ? '> ' : '  ';
            $dirty = $actor->isDirty() ? ' *' : '';
            $lines[] = sprintf('%s%s%s', $prefix, $actor->getName(), $dirty);
        }

        return $lines;
    }

    /**
     * Returns the animation list lines.
     *
     * @return string[]
     */
    private function getDatabaseAnimationListLines(): array
    {
        $animations = $this->workspace?->animationDatabase->getAnimations() ?? [];

        if ($animations === []) {
            return ['No animations yet.', '', 'Shift+A to create one.'];
        }

        $lines = [];

        foreach ($animations as $index => $animation) {
            $prefix = $index === $this->databaseSelectedAnimationIndex ? '> ' : '  ';
            $lines[] = sprintf('%s%04d %s', $prefix, $animation->id, $animation->name);
        }

        return $lines;
    }

    /**
     * Returns the current Database settings lines.
     *
     * @return string[]
     */
    private function getDatabaseSettingsLines(): array
    {
        $fields = $this->getDatabaseSettingsFields();

        if ($fields === []) {
            $category = $this->getSelectedDatabaseCategoryDefinition();

            if (! $category->isImplemented) {
                return [
                    'Planned Editor',
                    '',
                    $category->description,
                    '',
                    'This section will live in the Database window too.',
                ];
            }

            return ['Select an entry to edit settings.'];
        }

        $lines = [];

        foreach ($fields as $index => $field) {
            $prefix = $this->databaseFocus === self::DATABASE_FOCUS_SETTINGS && $index === $this->databaseSelectedSettingIndex ? '> ' : '  ';
            $value = (string) ($field['value'] ?? '');

            if ($this->isDatabaseEditing && $index === $this->databaseSelectedSettingIndex) {
                $value = $this->databaseEditBuffer;
            }

            $lines[] = sprintf('%s%s: %s', $prefix, $field['label'] ?? 'Field', $value);
        }

        return $lines;
    }

    /**
     * Returns the cue summary lines for the active Database entry.
     *
     * @return string[]
     */
    private function getDatabaseCueLines(): array
    {
        if ($this->isActorsDatabaseSelected()) {
            return $this->getDatabaseActorCollectionLines();
        }

        $animation = $this->getSelectedAnimation();

        if (! $animation instanceof Animation) {
            $category = $this->getSelectedDatabaseCategoryDefinition();

            if (! $category->isImplemented) {
                return ['No timing data yet.'];
            }

            return ['No animation selected.'];
        }

        $lines = ['No.  SE        Flash'];
        $hasCue = false;

        for ($frameIndex = 1; $frameIndex <= $animation->maxFrames; $frameIndex++) {
            $cue = $animation->getCue($frameIndex);

            if (! $cue instanceof AnimationCue || $cue->isEmpty()) {
                continue;
            }

            $hasCue = true;
            $lines[] = sprintf(
                '#%03d  %-8s %s',
                $frameIndex,
                $cue->soundEffect !== '' ? $cue->soundEffect : '-',
                $cue->flashColor !== null
                    ? sprintf('%s (%d)', ucfirst($cue->flashColor), $cue->flashDurationFrames)
                    : '-'
            );
        }

        if (! $hasCue) {
            $lines[] = '';
            $lines[] = 'No cues on this animation.';
        }

        return $lines;
    }

    /**
     * Returns the actor collection summary lines.
     *
     * @return string[]
     */
    private function getDatabaseActorCollectionLines(): array
    {
        $actor = $this->getSelectedActor();

        if (! $actor instanceof ProjectActor) {
            return ['No actor selected.'];
        }

        $abilities = $actor->getAbilities();
        $magic = $actor->getMagic();

        return [
            'Abilities',
            sprintf('Learned: %d', count($abilities['learned'] ?? [])),
            sprintf('Learnables: %d', count($abilities['learnables'] ?? [])),
            sprintf('Sort: %s', (string) ($abilities['sortOrder'] ?? 'A-Z')),
            '',
            'Magic',
            sprintf('Learned: %d', count($magic['learned'] ?? [])),
            sprintf('Learnables: %d', count($magic['learnables'] ?? [])),
            sprintf('Sort: %s', (string) ($magic['sortOrder'] ?? 'A-Z')),
        ];
    }

    /**
     * Returns the frame list lines.
     *
     * @return string[]
     */
    private function getDatabaseFrameLines(): array
    {
        if ($this->isActorsDatabaseSelected()) {
            return $this->getDatabaseActorStatLines();
        }

        $animation = $this->getSelectedAnimation();

        if (! $animation instanceof Animation) {
            $category = $this->getSelectedDatabaseCategoryDefinition();

            return $category->isImplemented ? ['-'] : ['No entry frames.'];
        }

        $lines = [];

        for ($frameIndex = 1; $frameIndex <= $animation->maxFrames; $frameIndex++) {
            $prefix = $frameIndex === $this->databaseSelectedFrameIndex ? '> ' : '  ';
            $lines[] = sprintf('%s#%03d', $prefix, $frameIndex);
        }

        return $lines;
    }

    /**
     * Returns the actor stat summary lines.
     *
     * @return string[]
     */
    private function getDatabaseActorStatLines(): array
    {
        $actor = $this->getSelectedActor();

        if (! $actor instanceof ProjectActor) {
            return ['No actor selected.'];
        }

        return [
            sprintf('HP %d/%d', $actor->getStat('currentHp'), $actor->getStat('totalHp')),
            sprintf('MP %d/%d', $actor->getStat('currentMp'), $actor->getStat('totalMp')),
            sprintf('AP %d/%d', $actor->getStat('currentAp'), $actor->getStat('totalAp')),
            sprintf('ATK %d', $actor->getStat('attack')),
            sprintf('DEF %d', $actor->getStat('defence')),
            sprintf('MAT %d', $actor->getStat('magicAttack')),
            sprintf('MDF %d', $actor->getStat('magicDefence')),
            sprintf('SPD %d', $actor->getStat('speed')),
            sprintf('GRC %d', $actor->getStat('grace')),
            sprintf('EVA %d', $actor->getStat('evasion')),
            sprintf('ACC %d', $actor->getStat('accuracy')),
            sprintf('CRT %d', $actor->getStat('critical')),
        ];
    }

    /**
     * Returns the preview lines for the active Database entry.
     *
     * @return string[]
     */
    private function getDatabasePreviewLines(): array
    {
        if ($this->isActorsDatabaseSelected()) {
            $actor = $this->getSelectedActor();

            if (! $actor instanceof ProjectActor) {
                return ['No actor selected.'];
            }

            $images = $actor->getImages();
            $battleLines = $actor->getBattleSpriteLines();

            return [
                sprintf('Actor ID: %s', $actor->id),
                sprintf('Field sprites: %d', count($images['field'] ?? [])),
                sprintf('Dialog portraits: %d', count($images['dialog'] ?? [])),
                '',
                'Battle Sprite',
                ...($battleLines !== [] ? $battleLines : ['(no battle sprite configured)']),
            ];
        }

        return [];
    }

    /**
     * Resolves the Database pane border color.
     *
     * @param string $pane The pane identifier.
     * @return Color
     */
    private function resolveDatabasePaneColor(string $pane): Color
    {
        return $this->databaseFocus === $pane ? Color::LIGHT_BLUE : Color::WHITE;
    }

    /**
     * Resolves the termutil color for a stored animation color name.
     *
     * @param string|null $color The stored color name.
     * @return Color
     */
    private function resolveAnimationColor(?string $color): Color
    {
        return match (strtolower((string) $color)) {
            'red' => Color::LIGHT_RED,
            'green' => Color::LIGHT_GREEN,
            'blue' => Color::LIGHT_BLUE,
            'yellow' => Color::YELLOW,
            'cyan' => Color::LIGHT_CYAN,
            'magenta' => Color::LIGHT_PURPLE,
            default => Color::WHITE,
        };
    }

    /**
     * Returns the active character palette.
     *
     * @return string[]
     */
    private function getCharacterPalette(): array
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return [];
        }

        $selectedMap = $this->workspace->getMapByIndex($this->selectedAssetIndex);

        return $selectedMap instanceof ProjectMap ? $selectedMap->getCharacterPalette() : [];
    }

    /**
     * Returns the formatted inspector lines.
     *
     * @return string[]
     */
    private function getInspectorLines(): array
    {
        $fields = $this->getInspectorFields();

        if ($fields === []) {
            return ['No selection.'];
        }

        $lines = [];

        foreach ($fields as $index => $field) {
            $prefix = $this->focusedPane === self::FOCUS_INSPECTOR && $index === $this->selectedInspectorFieldIndex ? '> ' : '  ';
            $value = (string) ($field['value'] ?? '');

            if ($this->isInspectorEditing && $index === $this->selectedInspectorFieldIndex) {
                $value = $this->inspectorEditBuffer;
            }

            $lines[] = sprintf('%s%s: %s', $prefix, $field['label'] ?? 'Field', $value);
        }

        return $lines;
    }

    /**
     * Returns the input control for an inspector field if it is editable.
     *
     * @param array<string, mixed> $field The inspector field descriptor.
     * @return InputControl|null
     */
    private function getInspectorFieldControl(array $field): ?InputControl
    {
        $control = $field['control'] ?? null;

        return $control instanceof InputControl ? $control : null;
    }

    /**
     * Re-renders the canvas section without clearing the full shell.
     *
     * @return void
     */
    private function renderCanvasArea(): void
    {
        if ($this->isDatabaseOpen) {
            $this->renderDatabaseArea(includeRoot: true);
            return;
        }

        Console::cursor()->hide();
        $this->createCanvasWindow()->render();
        $this->createInspectorWindow()->render();
        $this->renderFooter();
        $this->renderOverlays();
    }

    /**
     * Re-renders the footer only.
     *
     * @return void
     */
    private function renderFooter(): void
    {
        if ($this->isDatabaseOpen) {
            $this->renderDatabaseArea(includeRoot: true);
            return;
        }

        Console::cursor()->hide();
        $this->createFooterWindow()->render();
    }

    /**
     * Re-renders the inspector and dependent status area.
     *
     * @return void
     */
    private function renderInspectorArea(): void
    {
        if ($this->isDatabaseOpen) {
            $this->renderDatabaseArea(includeRoot: true);
            return;
        }

        Console::cursor()->hide();
        $this->createInspectorWindow()->render();
        $this->renderFooter();
        $this->renderOverlays();
    }

    /**
     * Re-renders the panes affected by selection or mode changes.
     *
     * @return void
     */
    private function renderSelectionDependentArea(): void
    {
        if ($this->isDatabaseOpen) {
            $this->renderDatabaseArea(includeRoot: true);
            return;
        }

        Console::cursor()->hide();
        $this->createAssetWindow()->render();
        $this->createCanvasWindow()->render();
        $this->createInspectorWindow()->render();
        $this->renderFooter();
        $this->renderOverlays();
    }

    /**
     * Re-renders the panes affected by focus changes.
     *
     * @return void
     */
    private function renderFocusDependentArea(): void
    {
        if ($this->isDatabaseOpen) {
            $this->renderDatabaseArea(includeRoot: true);
            return;
        }

        Console::cursor()->hide();
        $this->createAssetWindow()->render();
        $this->createCanvasWindow()->render();
        $this->createInspectorWindow()->render();
        $this->renderFooter();
        $this->renderOverlays();
    }

    /**
     * Renders transient overlays and the live cursor.
     *
     * @return void
     */
    private function renderOverlays(): void
    {
        $layout = $this->resolveLayout();
        Console::cursor()->hide();

        if ($this->isDatabaseOpen) {
            $this->renderDatabaseOverlay($layout);
            return;
        }

        if ($this->isCharacterMapOpen) {
            $this->renderCharacterMapOverlay($layout);
            return;
        }

        if ($this->isDeleteConfirmationOpen) {
            $this->renderDeleteConfirmationOverlay($layout);
            return;
        }

        if ($this->isEventTypeDialogOpen) {
            $this->renderEventTypeDialogOverlay($layout);
            return;
        }

        if ($this->isDestinationDialogOpen) {
            $this->renderDestinationDialogOverlay($layout);
            return;
        }

        if ($this->isDestinationSpawnConfirmationOpen) {
            $this->renderDestinationSpawnConfirmationOverlay($layout);
            return;
        }

        if ($this->isDestinationSpawnSelectionOpen) {
            $this->renderDestinationSpawnSelectionOverlay($layout);
            $this->renderCanvasCursor($layout);
            return;
        }

        if ($this->isInspectorEditing) {
            $this->renderInspectorEditCursor($layout);
            return;
        }

        $this->renderCanvasCursor($layout);
    }

    /**
     * Renders the live text cursor for inspector editing.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderInspectorEditCursor(array $layout): void
    {
        if ($this->focusedPane !== self::FOCUS_INSPECTOR) {
            Console::cursor()->hide();
            return;
        }

        $fields = $this->getInspectorFields();
        $field = $fields[$this->selectedInspectorFieldIndex] ?? null;

        if (! is_array($field)) {
            Console::cursor()->hide();
            return;
        }

        $prefix = '> ';
        $label = (string) ($field['label'] ?? 'Field');
        $leftText = sprintf('%s%s: ', $prefix, $label);
        $valueCursorOffset = mb_strwidth($leftText . mb_substr($this->inspectorEditBuffer, 0, $this->inspectorEditCursorIndex));
        $contentWidth = $this->getWindowContentWidth($layout['rightWidth']);

        if ($valueCursorOffset > $contentWidth - 1) {
            $valueCursorOffset = $contentWidth - 1;
        }

        $inspectorLeft = 2 + $layout['leftWidth'] + $layout['centerWidth'] + ($layout['gutter'] * 2);
        $inspectorTop = 5;
        $row = min($this->selectedInspectorFieldIndex, max(0, $layout['contentHeight'] - 3));
        Console::cursor()->show();
        Console::cursor()->moveTo(
            $inspectorLeft + 1 + self::WINDOW_HORIZONTAL_PADDING + $valueCursorOffset,
            $inspectorTop + 1 + $row
        );
    }

    /**
     * Creates the header window.
     *
     * @return EditorWindow
     */
    private function createHeaderWindow(): EditorWindow
    {
        $layout = $this->resolveLayout();

        return new EditorWindow(
            title: 'Ichiloto Editor',
            help: '',
            position: ['x' => 2, 'y' => 1],
            width: $layout['width'] - 2,
            height: 3,
            foregroundColor: $this->resolvePaneColor(),
            content: [
                sprintf('Project: %s', $this->workspace?->projectName ?? ''),
            ],
        );
    }

    /**
     * Creates the asset browser window.
     *
     * @return EditorWindow
     */
    private function createAssetWindow(): EditorWindow
    {
        $layout = $this->resolveLayout();
        $contentWidth = $this->getWindowContentWidth($layout['leftWidth']);

        return new EditorWindow(
            title: $this->focusedPane === self::FOCUS_ASSETS ? 'Assets [Focus]' : 'Assets',
            help: 'Tab:Next Pane  Del:Delete',
            position: ['x' => 2, 'y' => 5],
            width: $layout['leftWidth'],
            height: $layout['contentHeight'],
            foregroundColor: $this->resolvePaneColor(self::FOCUS_ASSETS),
            content: $this->fitLines(
                $this->workspace?->getAssetLines($this->selectedAssetIndex) ?? [],
                $contentWidth,
                $layout['contentHeight'] - 2
            ),
        );
    }

    /**
     * Creates the canvas window.
     *
     * @return EditorWindow
     */
    private function createCanvasWindow(): EditorWindow
    {
        $layout = $this->resolveLayout();
        $contentWidth = $this->getWindowContentWidth($layout['centerWidth']);

        return new EditorWindow(
            title: $this->focusedPane === self::FOCUS_CANVAS ? 'Canvas [Focus]' : 'Canvas',
            help: $this->isDestinationSpawnSelectionOpen
                ? 'Enter:Select Spawn  Esc:Cancel'
                : '%:Map  ^:Event  @:Chars',
            position: ['x' => 2 + $layout['leftWidth'] + $layout['gutter'], 'y' => 5],
            width: $layout['centerWidth'],
            height: $layout['contentHeight'],
            foregroundColor: $this->resolvePaneColor(self::FOCUS_CANVAS),
            content: $this->fitLines(
                $this->workspace?->getCanvasLines(
                    $this->selectedAssetIndex,
                    $contentWidth,
                    max(1, $layout['contentHeight'] - 2),
                    $this->canvasOffsetX,
                    $this->canvasOffsetY,
                    $this->showEventOverlay,
                ) ?? [],
                $contentWidth,
                $layout['contentHeight'] - 2
            ),
        );
    }

    /**
     * Creates the inspector window.
     *
     * @return EditorWindow
     */
    private function createInspectorWindow(): EditorWindow
    {
        $layout = $this->resolveLayout();
        $contentWidth = $this->getWindowContentWidth($layout['rightWidth']);

        return new EditorWindow(
            title: $this->focusedPane === self::FOCUS_INSPECTOR ? 'Inspector [Focus]' : 'Inspector',
            help: $this->isInspectorEditing ? 'Enter:Apply  Esc:Cancel' : 'Enter:Edit',
            position: ['x' => 2 + $layout['leftWidth'] + $layout['centerWidth'] + ($layout['gutter'] * 2), 'y' => 5],
            width: $layout['rightWidth'],
            height: $layout['contentHeight'],
            foregroundColor: $this->resolvePaneColor(self::FOCUS_INSPECTOR),
            content: $this->fitLines(
                $this->getInspectorLines(),
                $contentWidth,
                $layout['contentHeight'] - 2
            ),
        );
    }

    /**
     * Creates the footer window.
     *
     * @return EditorWindow
     */
    private function createFooterWindow(): EditorWindow
    {
        $layout = $this->resolveLayout();
        $selectedMap = $this->workspace?->getMapByIndex($this->selectedAssetIndex);
        $contentWidth = $this->getWindowContentWidth($layout['width'] - 2);
        $helpText = match (true) {
            $this->isDestinationSpawnConfirmationOpen => 'Enter:Apply  Esc:Back',
            $this->isDestinationSpawnSelectionOpen => 'Arrows:Move  Enter:Select Spawn  Esc:Cancel',
            default => 'Tab/Shift+Arrows:Pane  Arrows:Move  Enter:Edit  Ctrl+S:Save  Ctrl+Q:Quit',
        };

        return new EditorWindow(
            title: 'Status',
            help: $helpText,
            position: ['x' => 2, 'y' => 5 + $layout['contentHeight'] + $layout['gutter']],
            width: $layout['width'] - 2,
            height: 4,
            foregroundColor: $this->resolvePaneColor(),
            content: $this->fitLines([
                $selectedMap instanceof ProjectMap
                    ? sprintf(
                        'Selected map: %s%s | Focus: %s | Mode: %s',
                        $selectedMap->mapId,
                        $selectedMap->isDirty() ? ' *' : '',
                        ucfirst($this->focusedPane),
                        ucfirst($this->editingMode)
                    )
                    : 'No map is currently selected.',
                sprintf(
                    'Cursor: (%d, %d) | Viewport: (%d, %d) | %s',
                    $this->cursorX,
                    $this->cursorY,
                    $this->canvasOffsetX,
                    $this->canvasOffsetY,
                    $this->statusMessage
                ),
            ], $contentWidth, 2),
        );
    }
}
