<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Atatusoft\Termutil\Events\MouseEvent;
use Atatusoft\Termutil\IO\Console\Console;
use Atatusoft\Termutil\IO\Enumerations\Color;
use Atatusoft\Termutil\IO\Mouse\Enumerations\MouseButton;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\DatabaseCategoryDefinition;
use Ichiloto\Editor\Debug\Debug;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\History\PaintStrokeCommand;
use Ichiloto\Editor\Inspector\InputControl;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\IO\InputDecoder;
use Ichiloto\Editor\IO\InputRouter;
use Ichiloto\Editor\IO\KeyBinding;
use Ichiloto\Editor\Runtime\EditorLoop;
use Ichiloto\Editor\Runtime\TerminalHost;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\Status\Toast;
use Ichiloto\Editor\Status\ToastQueue;
use Ichiloto\Editor\UI\AssetsPanel;
use Ichiloto\Editor\UI\CanvasPanel;
use Ichiloto\Editor\UI\CommandPalette;
use Ichiloto\Editor\UI\DatabaseScreen;
use Ichiloto\Editor\UI\InspectorPanel;
use Ichiloto\Editor\UI\Modal;
use Ichiloto\Editor\UI\ModalStack;
use Ichiloto\Editor\UI\PaletteItem;
use Ichiloto\Editor\UI\ScrollWindow;
use Ichiloto\Editor\UI\TextFieldEditor;
use Ichiloto\Editor\UI\TextFieldKeyResult;
use Ichiloto\Editor\Validation\MapValidator;
use Ichiloto\Engine\Animations\AnimationTargetPosition;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Roles\ExperienceCurveGenerator;
use Ichiloto\Engine\Entities\Roles\ParameterCurveGenerator;
use Ichiloto\Engine\Events\Enumerations\ChestType;
use Ichiloto\Engine\Events\Enumerations\LootType;
use Ichiloto\Engine\Quests\QuestObjectiveType;
use RuntimeException;
if (! class_exists(__NAMESPACE__ . chr(92) . 'Animation', false)) { class_alias('Ichiloto' . chr(92) . 'Engine' . chr(92) . 'Animations' . chr(92) . 'Animation', __NAMESPACE__ . chr(92) . 'Animation'); }
if (! class_exists(__NAMESPACE__ . chr(92) . 'AnimationCue', false)) { class_alias('Ichiloto' . chr(92) . 'Engine' . chr(92) . 'Animations' . chr(92) . 'AnimationCue', __NAMESPACE__ . chr(92) . 'AnimationCue'); }
if (! class_exists(__NAMESPACE__ . chr(92) . 'AnimationPlayer', false)) { class_alias('Ichiloto' . chr(92) . 'Engine' . chr(92) . 'Animations' . chr(92) . 'AnimationPlayer', __NAMESPACE__ . chr(92) . 'AnimationPlayer'); }
use Throwable;

/**
 * Launches the Ichiloto terminal editor shell.
 */
final class Editor
{
    /**
     * The per-frame time budget (~60fps). The loop sleeps only the remainder
     * of the budget after real work, so input latency stays at one frame.
     */
    private const int FRAME_BUDGET_MICROSECONDS = 16_666;
    /**
     * The minimum seconds between terminal-size probes (`stty size` forks a
     * subprocess, so it must never run per frame).
     */
    private const float TERMINAL_SIZE_PROBE_INTERVAL_SECONDS = 0.25;
    /**
     * Seconds each animation preview frame stays on screen (matches the
     * engine AnimationPlayer default cadence).
     */
    private const float PREVIEW_SECONDS_PER_FRAME = 0.12;

    private const string FOCUS_ASSETS = 'assets';
    private const string FOCUS_CANVAS = 'canvas';
    private const string FOCUS_INSPECTOR = 'inspector';
    private const string MODE_MAP = 'map';
    private const string MODE_EVENT = 'event';
    private const string DATABASE_CATEGORY_ACTORS = 'actors';
    private const string DATABASE_CATEGORY_CLASSES = 'classes';
    private const string DATABASE_CATEGORY_SKILLS = 'skills';
    private const string DATABASE_CATEGORY_ANIMATIONS = 'animations';
    private const string DATABASE_CATEGORY_SYSTEM = 'system';
    private const string DATABASE_CATEGORY_QUESTS = 'quests';
    private const string DATABASE_FOCUS_CATEGORIES = 'database_categories';
    private const string DATABASE_FOCUS_LIST = 'database_list';
    private const string DATABASE_FOCUS_SETTINGS = 'database_settings';
    private const string DATABASE_FOCUS_FRAMES = 'database_frames';
    private const string DATABASE_FOCUS_PREVIEW = 'database_preview';
    private const int CHARACTER_MAP_COLUMNS = 8;
    private const int WINDOW_HORIZONTAL_PADDING = 1;
    private const string GUARD_ACTION_QUIT = 'quit';
    private const string GUARD_ACTION_RELOAD = 'reload';
    /**
     * The maximum number of undo entries retained per session.
     */
    private const int HISTORY_CAPACITY = 500;

    private bool $isRunning = false;
    private ?ProjectWorkspace $workspace = null;
    /**
     * Decodes buffered terminal bytes into discrete input events.
     */
    private InputDecoder $inputDecoder;
    /**
     * Owns termios/stty state, the alternate screen, mouse reporting, the
     * throttled size probe, and the buffered frame writer.
     */
    private readonly TerminalHost $terminal;
    /**
     * The deadline-budget frame loop driving the session.
     */
    private readonly EditorLoop $loop;
    /**
     * @var array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int}|null Memoized layout for the current terminal size.
     */
    private ?array $cachedLayout = null;
    /**
     * @var array{width: int, height: int}|null The terminal size the cached layout was computed for.
     */
    private ?array $cachedLayoutSize = null;
    private int $selectedAssetIndex = 0;
    private string $focusedPane = self::FOCUS_ASSETS;
    private string $editingMode = self::MODE_MAP;
    private int $cursorX = 0;
    private int $cursorY = 0;
    private int $canvasOffsetX = 0;
    private int $canvasOffsetY = 0;
    private bool $showEventOverlay = false;
    /**
     * The open-modal registry: one source of truth for input dispatch and
     * overlay rendering. The `is*Open` hooks below keep the historic boolean
     * reads/writes working against the stack.
     */
    private readonly ModalStack $modals;
    /**
     * Routes decoded input tokens to the active modal, the mouse
     * interceptor, the base binding table, or the focused pane.
     */
    private readonly InputRouter $inputRouter;
    private bool $isCharacterMapOpen {
        get => $this->modals->has(Modal::CHARACTER_MAP);
        set {
            $value ? $this->modals->push(Modal::CHARACTER_MAP) : $this->modals->remove(Modal::CHARACTER_MAP);
        }
    }
    private bool $isDeleteConfirmationOpen {
        get => $this->modals->has(Modal::DELETE_CONFIRMATION);
        set {
            $value ? $this->modals->push(Modal::DELETE_CONFIRMATION) : $this->modals->remove(Modal::DELETE_CONFIRMATION);
        }
    }
    private bool $isEventTypeDialogOpen {
        get => $this->modals->has(Modal::EVENT_TYPE_DIALOG);
        set {
            $value ? $this->modals->push(Modal::EVENT_TYPE_DIALOG) : $this->modals->remove(Modal::EVENT_TYPE_DIALOG);
        }
    }
    private bool $isDestinationDialogOpen {
        get => $this->modals->has(Modal::DESTINATION_DIALOG);
        set {
            $value ? $this->modals->push(Modal::DESTINATION_DIALOG) : $this->modals->remove(Modal::DESTINATION_DIALOG);
        }
    }
    private bool $isDestinationSpawnSelectionOpen {
        get => $this->modals->has(Modal::DESTINATION_SPAWN_SELECTION);
        set {
            $value ? $this->modals->push(Modal::DESTINATION_SPAWN_SELECTION) : $this->modals->remove(Modal::DESTINATION_SPAWN_SELECTION);
        }
    }
    private bool $isDestinationSpawnConfirmationOpen {
        get => $this->modals->has(Modal::DESTINATION_SPAWN_CONFIRMATION);
        set {
            $value ? $this->modals->push(Modal::DESTINATION_SPAWN_CONFIRMATION) : $this->modals->remove(Modal::DESTINATION_SPAWN_CONFIRMATION);
        }
    }
    private bool $isDatabaseOpen {
        get => $this->modals->has(Modal::DATABASE);
        set {
            $value ? $this->modals->push(Modal::DATABASE) : $this->modals->remove(Modal::DATABASE);
        }
    }
    private bool $isHelpOpen {
        get => $this->modals->has(Modal::HELP);
        set {
            $value ? $this->modals->push(Modal::HELP) : $this->modals->remove(Modal::HELP);
        }
    }
    private bool $isCommandPaletteOpen {
        get => $this->modals->has(Modal::COMMAND_PALETTE);
        set {
            $value ? $this->modals->push(Modal::COMMAND_PALETTE) : $this->modals->remove(Modal::COMMAND_PALETTE);
        }
    }
    /**
     * The command palette model (items, fuzzy query, selection).
     */
    private readonly CommandPalette $commandPalette;
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
    private int $selectedLootIndex = 0;
    private bool $isLootDialogOpen {
        get => $this->modals->has(Modal::LOOT_DIALOG);
        set {
            $value ? $this->modals->push(Modal::LOOT_DIALOG) : $this->modals->remove(Modal::LOOT_DIALOG);
        }
    }
    private ?string $lootDialogMarker = null;
    private ?LootType $lootDialogType = null;
    /**
     * @var string[]|null
     */
    private ?array $lootDialogPath = null;
    /**
     * @var array<int, array{name: string, description: string, icon: string, type: string}>
     */
    private array $lootDialogEntries = [];
    private int $selectedEventOptionIndex = 0;
    private bool $isEventOptionDialogOpen {
        get => $this->modals->has(Modal::EVENT_OPTION_DIALOG);
        set {
            $value ? $this->modals->push(Modal::EVENT_OPTION_DIALOG) : $this->modals->remove(Modal::EVENT_OPTION_DIALOG);
        }
    }
    private ?string $eventOptionDialogMarker = null;
    /**
     * @var string[]|null
     */
    private ?array $eventOptionDialogPath = null;
    private string $eventOptionDialogTitle = 'Options';
    /**
     * @var array<int, array{label: string, value: string, description: string}>
     */
    private array $eventOptionDialogEntries = [];
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
    /**
     * The shared edit buffer behind the inspector's inline field editing.
     */
    private readonly TextFieldEditor $inspectorFieldEditor;
    /**
     * The shared edit buffer behind the Database settings pane editing.
     */
    private readonly TextFieldEditor $databaseFieldEditor;
    /**
     * Legacy views over the inspector field editor; the hooks keep the many
     * existing readers/writers working against the one TextFieldEditor.
     */
    private bool $isInspectorEditing {
        get => $this->inspectorFieldEditor->isActive;
        set {
            if ($value) {
                $this->inspectorFieldEditor->isActive = true;
            } else {
                $this->inspectorFieldEditor->close();
            }
        }
    }
    private string $inspectorEditBuffer {
        get => $this->inspectorFieldEditor->value;
        set {
            $this->inspectorFieldEditor->value = $value;
        }
    }
    private int $inspectorEditCursorIndex {
        get => $this->inspectorFieldEditor->caret;
        set {
            $this->inspectorFieldEditor->caret = $value;
        }
    }
    private int $databaseCategoryIndex = 9;
    private string $databaseFocus = self::DATABASE_FOCUS_LIST;
    private int $databaseSelectedActorIndex = 0;
    private int $databaseSelectedClassIndex = 0;
    private int $databaseSelectedSkillIndex = 0;
    private int $databaseSelectedQuestIndex = 0;
    private int $databaseSelectedAnimationIndex = 0;
    private int $databaseSelectedSettingIndex = 0;
    private int $databaseSelectedFrameIndex = 1;
    private int $databasePreviewCursorX = 0;
    private int $databasePreviewCursorY = 0;
    /**
     * Legacy views over the database field editor; see the inspector hooks.
     */
    private bool $isDatabaseEditing {
        get => $this->databaseFieldEditor->isActive;
        set {
            if ($value) {
                $this->databaseFieldEditor->isActive = true;
            } else {
                $this->databaseFieldEditor->close();
            }
        }
    }
    private string $databaseEditBuffer {
        get => $this->databaseFieldEditor->value;
        set {
            $this->databaseFieldEditor->value = $value;
        }
    }
    private int $databaseEditCursorIndex {
        get => $this->databaseFieldEditor->caret;
        set {
            $this->databaseFieldEditor->caret = $value;
        }
    }
    private string $databaseSelectedPaintSymbol = '*';
    private ?string $databaseSelectedPaintColor = 'white';
    private bool $isDatabasePreviewPlaying = false;
    private int $databasePlaybackFrameIndex = 1;
    /**
     * When the non-blocking animation preview should advance to its next frame.
     */
    private float $databasePlaybackNextFrameAt = 0.0;
    private ?string $databasePlaybackFlashColor = null;
    /**
     * The idle footer message shown once every queued status expires.
     */
    private const string STATUS_IDLE_MESSAGE = 'Ready.';
    /**
     * The snackbar-style toast queue behind the footer status line: typed
     * messages queue instead of overwriting each other.
     */
    private readonly ToastQueue $toasts;
    /**
     * The severity of the status message currently on screen.
     */
    private StatusLevel $statusLevel {
        get => $this->toasts->current()?->level ?? StatusLevel::INFO;
    }
    /**
     * The footer status line, backed by the toast queue. Direct assignment
     * is the legacy path used across the file; the hook keeps every such
     * assignment behaving as an auto-expiring INFO toast so only setStatus()
     * needs to know about levels. Assigning the idle message resets the
     * queue (the boot path).
     */
    private string $statusMessage {
        get => $this->toasts->current()?->message ?? self::STATUS_IDLE_MESSAGE;
        set (string $message) {
            if ($message === self::STATUS_IDLE_MESSAGE) {
                $this->toasts->clear();
                return;
            }

            $this->toasts->push(new Toast($message), microtime(true));
        }
    }
    /**
     * The title of the detail overlay opened with Ctrl+E.
     */
    private string $statusDetailTitle = '';
    /**
     * @var string[] The retained detail lines behind the latest warn/error status.
     */
    private array $statusDetailLines = [];
    private bool $isStatusDetailOpen {
        get => $this->modals->has(Modal::STATUS_DETAIL);
        set {
            $value ? $this->modals->push(Modal::STATUS_DETAIL) : $this->modals->remove(Modal::STATUS_DETAIL);
        }
    }
    /**
     * The undo/redo stack over every recorded editor mutation.
     */
    private CommandHistory $history;
    /**
     * The in-flight mouse-drag stroke; recorded as one command on release.
     */
    private ?PaintStrokeCommand $activeStrokeCommand = null;
    /**
     * Which guarded action ('quit'|'reload') the unsaved-changes prompt confirms.
     */
    private ?string $pendingGuardAction = null;
    private bool $isUnsavedChangesGuardOpen {
        get => $this->modals->has(Modal::UNSAVED_CHANGES_GUARD);
        set {
            $value ? $this->modals->push(Modal::UNSAVED_CHANGES_GUARD) : $this->modals->remove(Modal::UNSAVED_CHANGES_GUARD);
        }
    }
    /**
     * The pending folder-move save awaiting explicit confirmation.
     */
    private bool $isRenameConfirmationOpen {
        get => $this->modals->has(Modal::RENAME_CONFIRMATION);
        set {
            $value ? $this->modals->push(Modal::RENAME_CONFIRMATION) : $this->modals->remove(Modal::RENAME_CONFIRMATION);
        }
    }
    /**
     * @var array{width: int, height: int}|null
     */
    private ?array $lastTerminalSize = null;
    private bool $isFullRenderPending = false;
    /**
     * The three main-shell panels; each carries its own dirty flag so the
     * loop repaints only what an input handler actually touched.
     */
    private readonly AssetsPanel $assetsPanel;
    private readonly CanvasPanel $canvasPanel;
    private readonly InspectorPanel $inspectorPanel;
    /**
     * Whether the footer needs repainting on the next render pass.
     */
    private bool $isFooterDirty = false;
    /**
     * Whether the overlay pass (modal windows / live cursor) needs to run
     * on the next render pass.
     */
    private bool $areOverlaysDirty = false;
    /**
     * Whether only the canvas cursor cell needs repainting (the cursor-move
     * fast path; superseded by the overlay pass when both are set).
     */
    private bool $isCanvasCursorDirty = false;
    /**
     * The Database screen: owns the database pane registry, per-pane dirty
     * state, and the fixed paint order.
     */
    private readonly DatabaseScreen $databaseScreen;

    public function __construct(private readonly string $projectRoot)
    {
        $this->toasts = new ToastQueue();
        $this->commandPalette = new CommandPalette();
        $this->history = new CommandHistory(self::HISTORY_CAPACITY);
        $this->terminal = new TerminalHost(self::TERMINAL_SIZE_PROBE_INTERVAL_SECONDS);
        $this->loop = new EditorLoop(self::FRAME_BUDGET_MICROSECONDS, $this->terminal);
        $this->inspectorFieldEditor = new TextFieldEditor();
        $this->databaseFieldEditor = new TextFieldEditor();
        $this->modals = new ModalStack();
        $this->assetsPanel = new AssetsPanel(
            self::FOCUS_ASSETS,
            function (): void {
                Console::cursor()->hide();
                $this->createAssetWindow()->render();
            },
            $this->handleAssetsPaneInput(...),
            fn(): bool => $this->focusedPane === self::FOCUS_ASSETS,
        );
        $this->canvasPanel = new CanvasPanel(
            self::FOCUS_CANVAS,
            function (): void {
                Console::cursor()->hide();
                $this->createCanvasWindow()->render();
            },
            $this->handleCanvasPaneInput(...),
            fn(): bool => $this->focusedPane === self::FOCUS_CANVAS,
        );
        $this->inspectorPanel = new InspectorPanel(
            self::FOCUS_INSPECTOR,
            function (): void {
                Console::cursor()->hide();
                $this->createInspectorWindow()->render();
            },
            $this->handleInspectorPaneInput(...),
            fn(): bool => $this->focusedPane === self::FOCUS_INSPECTOR,
        );
        $this->databaseScreen = new DatabaseScreen(
            fn(): array => $this->resolveDatabaseLayout($this->resolveLayout()),
            fn(array $layout) => $this->createDatabaseRootWindow($layout)->render(),
            [
                DatabaseScreen::PANE_CATEGORIES => fn(array $layout) => $this->createDatabaseCategoryWindow($layout)->render(),
                DatabaseScreen::PANE_LIST => fn(array $layout) => $this->createDatabaseListWindow($layout)->render(),
                DatabaseScreen::PANE_SETTINGS => fn(array $layout) => $this->createDatabaseSettingsWindow($layout)->render(),
                DatabaseScreen::PANE_CUE => fn(array $layout) => $this->createDatabaseCueWindow($layout)->render(),
                DatabaseScreen::PANE_FRAMES => fn(array $layout) => $this->createDatabaseFramesWindow($layout)->render(),
                DatabaseScreen::PANE_PREVIEW => function (array $layout): void {
                    $this->createDatabasePreviewWindow($layout)->render();
                    $this->renderDatabasePreview($layout);
                },
            ],
            $this->renderDatabaseEditCursor(...),
            fn(): bool => $this->isDatabaseEditing,
        );
        $this->inputRouter = $this->buildInputRouter();

        set_exception_handler(function (Throwable $e) {
            $this->handleException($e);
        });

        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
            // Recoverable diagnostics must never tear down a live editing
            // session — log them and keep running.
            if (in_array($errno, [E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED], true)) {
                Debug::warn("PHP {$errno}: {$errstr} in {$errfile} on line {$errline}");
                return true;
            }

            $this->handleException(new RuntimeException("Error {$errno}: {$errstr} in {$errfile} on line {$errline}"));
            return true;
        });
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
            $this->loop->run(
                fn(): bool => $this->isRunning,
                function (): void {
                    $this->handleInput();
                    $this->update();
                    $this->render();
                },
            );
        } catch (Throwable $e) {
            $this->handleException($e);
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
        $this->selectedEventOptionIndex = 0;
        $this->isEventOptionDialogOpen = false;
        $this->eventOptionDialogMarker = null;
        $this->eventOptionDialogPath = null;
        $this->eventOptionDialogTitle = 'Options';
        $this->eventOptionDialogEntries = [];
        $this->selectedPaintSymbol = ' ';
        $this->activeMousePaintButton = null;
        $this->lastMousePaintPoint = null;
        $this->characterPaletteIndex = 0;
        $this->selectedInspectorFieldIndex = 0;
        $this->selectedEventTypeIndex = 0;
        $this->selectedDestinationIndex = 0;
        $this->selectedLootIndex = 0;
        $this->isLootDialogOpen = false;
        $this->lootDialogMarker = null;
        $this->lootDialogType = null;
        $this->lootDialogPath = null;
        $this->lootDialogEntries = [];
        $this->eventTypeDialogMarker = null;
        $this->destinationDialogPath = null;
        $this->destinationDialogMarker = null;
        $this->destinationSelectionContext = null;
        $this->isInspectorEditing = false;
        $this->inspectorEditBuffer = '';
        $this->inspectorEditCursorIndex = 0;
        $this->databaseCategoryIndex = DatabaseCatalog::indexOf(self::DATABASE_CATEGORY_ACTORS);
        $this->databaseFocus = self::DATABASE_FOCUS_LIST;
        $this->databaseSelectedActorIndex = 0;
        $this->databaseSelectedClassIndex = 0;
        $this->databaseSelectedSkillIndex = 0;
        $this->databaseSelectedQuestIndex = 0;
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
        $this->statusMessage = self::STATUS_IDLE_MESSAGE;
        $this->statusDetailTitle = '';
        $this->statusDetailLines = [];
        $this->isStatusDetailOpen = false;
        $this->history->clear();
        $this->activeStrokeCommand = null;
        $this->pendingGuardAction = null;
        $this->isUnsavedChangesGuardOpen = false;
        $this->isRenameConfirmationOpen = false;
        $this->isHelpOpen = false;
        $this->isCommandPaletteOpen = false;
        $this->commandPalette->close();
        $this->terminal->enterRawMode();
        $this->inputDecoder = new InputDecoder();
        $this->inputDecoder->attach();
        $this->terminal->enterAlternateScreen();

        $size = $this->getTerminalSize();
        $this->terminal->beginConsoleSession($size, "Ichiloto Editor - {$this->workspace->projectName}");

        $this->lastTerminalSize = $size;
        $this->isRunning = true;
        $this->requestFullRender();
    }

    /**
     * Restores the terminal after the editor exits.
     *
     * @return void
     */
    private function shutdown(): void
    {
        $this->isRunning = false;

        $this->terminal->flushBufferedFrames();
        $this->terminal->restoreTerminalSettings();
        $this->terminal->endConsoleSession();
        $this->terminal->leaveAlternateScreen();
    }

    /**
     * Advances editor state that is not owned by a direct input handler.
     *
     * @return void
     */
    private function update(): void
    {
        $this->syncTerminalSizeIfNeeded();
        $this->tickDatabaseAnimationPreview();
        $this->tickStatusExpiry();
    }

    /**
     * Publishes a typed footer status message with optional detail lines.
     *
     * @param string $message The one-line footer message.
     * @param StatusLevel $level The message severity.
     * @param string[] $detailLines Longer content retained for the Ctrl+E detail overlay.
     * @return void
     */
    private function setStatus(string $message, StatusLevel $level = StatusLevel::INFO, array $detailLines = []): void
    {
        $this->toasts->push(new Toast($message, $level, $detailLines), microtime(true));

        if ($detailLines !== []) {
            $this->statusDetailTitle = $level === StatusLevel::ERROR ? 'Error Details' : 'Warnings';
            $this->statusDetailLines = $detailLines;
        }
    }

    /**
     * Publishes an error status, logs the details, and points at the log file.
     *
     * @param Throwable $throwable The failure to surface.
     * @param string $context The action that failed.
     * @return void
     */
    private function setErrorStatus(Throwable $throwable, string $context): void
    {
        Debug::error(sprintf('%s: %s', $context, $throwable->getMessage()));
        $this->setStatus(
            sprintf('%s failed: %s (Ctrl+E for details)', $context, $throwable->getMessage()),
            StatusLevel::ERROR,
            [
                sprintf('%s failed.', $context),
                '',
                $throwable->getMessage(),
                '',
                sprintf('Thrown at %s:%d.', basename($throwable->getFile()), $throwable->getLine()),
                'The full trace was appended to logs/error.log.',
            ],
        );
    }

    /**
     * Advances the toast queue: expires the current status and promotes the
     * next queued one (or the idle message).
     *
     * @return void
     */
    private function tickStatusExpiry(): void
    {
        if (! $this->toasts->tick(microtime(true))) {
            return;
        }

        if (! $this->isDatabaseOpen) {
            $this->renderFooter();
        }
    }

    /**
     * Records an already-applied mutation into the undo history.
     *
     * @param Command $command The applied command.
     * @return void
     */
    private function recordCommand(Command $command): void
    {
        $this->history->record($command);
    }

    /**
     * Undoes the most recent mutation.
     *
     * @return void
     */
    private function performUndo(): void
    {
        $this->finalizeActiveStroke();
        $command = $this->history->undo();

        if (! $command instanceof Command) {
            $this->setStatus('Nothing to undo.');
            $this->renderFooter();
            return;
        }

        $this->clampCursor();
        $this->clampCanvasOffsets();
        $this->clampInspectorSelection();
        $this->setStatus(sprintf('Undid %s.', lcfirst($command->label)), StatusLevel::SUCCESS);
        $this->requestFullRender();
    }

    /**
     * Re-applies the most recently undone mutation.
     *
     * @return void
     */
    private function performRedo(): void
    {
        $command = $this->history->redo();

        if (! $command instanceof Command) {
            $this->setStatus('Nothing to redo.');
            $this->renderFooter();
            return;
        }

        $this->clampCursor();
        $this->clampCanvasOffsets();
        $this->clampInspectorSelection();
        $this->setStatus(sprintf('Redid %s.', lcfirst($command->label)), StatusLevel::SUCCESS);
        $this->requestFullRender();
    }

    /**
     * Checks a token against the undo/redo bindings.
     *
     * Ctrl+Z undoes. Redo listens for Ctrl+Shift+Z where the terminal can
     * report it (CSI-u `122;6u`) and Ctrl+Y everywhere else, because classic
     * terminals collapse Ctrl+Shift+Z into plain Ctrl+Z.
     *
     * @param string $input The decoded input token.
     * @return bool Whether the token was consumed.
     */
    private function handleHistoryShortcut(string $input): bool
    {
        if (str_contains($input, "\033[122;6u") || str_contains($input, "\033[90;6u") || $input === "\x19") {
            $this->performRedo();
            return true;
        }

        if ($input === "\x1a") {
            $this->performUndo();
            return true;
        }

        return false;
    }

    /**
     * Records the in-flight mouse stroke as one undoable command.
     *
     * @return void
     */
    private function finalizeActiveStroke(): void
    {
        $stroke = $this->activeStrokeCommand;
        $this->activeStrokeCommand = null;

        if ($stroke instanceof PaintStrokeCommand && $stroke->hasChanges()) {
            $this->recordCommand($stroke);
        }
    }

    /**
     * Reconciles the editor shell against terminal resize events.
     *
     * @return void
     */
    private function syncTerminalSizeIfNeeded(): void
    {
        $size = $this->terminal->probeSizeThrottled();

        if ($size === null || $this->lastTerminalSize === $size) {
            return;
        }

        $this->terminal->applySize($size);
        $this->lastTerminalSize = $size;
        $this->clampCanvasOffsets();
        $this->requestFullRender();
    }

    /**
     * Marks the editor shell for a full redraw on the next render phase.
     *
     * @return void
     */
    private function requestFullRender(): void
    {
        $this->isFullRenderPending = true;
    }

    /**
     * Handles one tick of editor keyboard input.
     *
     * @return void
     */
    private function handleInput(): void
    {
        foreach ($this->inputDecoder->poll() as $token) {
            $this->dispatchInput($token);

            if (! $this->isRunning) {
                return;
            }
        }
    }

    /**
     * Dispatches one decoded input event to the active context.
     *
     * @param string $input The decoded input token.
     * @return void
     */
    private function dispatchInput(string $input): void
    {
        $this->inputRouter->route($input);
    }

    /**
     * Builds the input router: modal handlers, the global interceptors, and
     * the base-mode binding table, in dispatch order.
     *
     * @return InputRouter
     */
    private function buildInputRouter(): InputRouter
    {
        $router = new InputRouter($this->modals);

        $router->bindModal(Modal::STATUS_DETAIL, $this->handleStatusDetailInput(...));
        $router->bindModal(Modal::UNSAVED_CHANGES_GUARD, $this->handleUnsavedChangesGuardInput(...));
        $router->bindModal(Modal::RENAME_CONFIRMATION, $this->handleRenameConfirmationInput(...));
        $router->bindModal(Modal::COMMAND_PALETTE, $this->handleCommandPaletteInput(...));
        $router->bindModal(Modal::HELP, $this->handleHelpInput(...));
        $router->bindModal(Modal::DATABASE, $this->handleDatabaseInput(...));
        $router->bindModal(Modal::DESTINATION_SPAWN_CONFIRMATION, $this->handleDestinationSpawnConfirmationInput(...));
        $router->bindModal(Modal::DESTINATION_SPAWN_SELECTION, $this->handleDestinationSpawnSelectionInput(...));
        $router->bindModal(Modal::DESTINATION_DIALOG, $this->handleDestinationDialogInput(...));
        $router->bindModal(Modal::LOOT_DIALOG, $this->handleLootDialogInput(...));
        $router->bindModal(Modal::EVENT_OPTION_DIALOG, $this->handleEventOptionDialogInput(...));
        $router->bindModal(Modal::EVENT_TYPE_DIALOG, $this->handleEventTypeDialogInput(...));
        $router->bindModal(Modal::CHARACTER_MAP, $this->handleCharacterMapInput(...));
        $router->bindModal(Modal::DELETE_CONFIRMATION, $this->handleDeleteConfirmationInput(...));

        $router->onStatusDetailShortcut($this->openStatusDetailOverlay(...));
        $router->onDatabaseShortcut($this->openDatabaseWindow(...));
        $router->setMouseInterceptor($this->handleMouseInput(...));
        $router->bindTextEditing(
            fn(): bool => $this->isInspectorEditing,
            $this->handleInspectorEditingInput(...),
        );

        $router->bindBase(
            KeyBinding::exact("\x11", $this->requestQuit(...), 'Ctrl+Q', 'Quit (guards unsaved changes)'),
            KeyBinding::attempt($this->handleHistoryShortcut(...), 'Ctrl+Z / Ctrl+Y', 'Undo / redo the last mutation'),
            KeyBinding::exact("\t", fn() => $this->cycleFocus(1), 'Tab / Shift+Tab', 'Cycle pane focus'),
            KeyBinding::contains("\033[Z", fn() => $this->cycleFocus(-1)),
            KeyBinding::when(
                fn(string $input): bool => $this->isShiftArrow($input, 'up') || $this->isShiftArrow($input, 'left'),
                fn() => $this->cycleFocus(-1),
            ),
            KeyBinding::when(
                fn(string $input): bool => $this->isShiftArrow($input, 'down') || $this->isShiftArrow($input, 'right'),
                fn() => $this->cycleFocus(1),
                'Shift+Arrows',
                'Cycle pane focus',
            ),
            KeyBinding::exact("\x13", $this->saveSelectedMap(...), 'Ctrl+S', 'Save the selected map'),
            KeyBinding::exact("\x01", $this->saveAllAssets(...), 'Ctrl+A', 'Save every dirty map and database'),
            KeyBinding::exact("\x12", $this->requestReload(...), 'Ctrl+R', 'Reload the workspace (guards unsaved changes)'),
            KeyBinding::exact("\x10", $this->openCommandPalette(...), 'Ctrl+P', 'Open the command palette'),
            KeyBinding::exact('?', $this->openHelpOverlay(...), '?', 'Toggle this help overlay'),
        );
        $router->setFallback($this->handleFocusedPaneInput(...));

        return $router;
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
        foreach ([$this->assetsPanel, $this->canvasPanel, $this->inspectorPanel] as $panel) {
            $panel->handleInput($input, $normalizedInput);
        }
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
            $this->finalizeActiveStroke();
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

        // Cursor movement is arrows-only: the historic hjkl aliases stole
        // eight glyphs (h/j/k/l and their shifted forms) from painting.
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
            return;
        }

        // The one enum/step idiom (the quests-pane precedent): Left/Right
        // cycles option fields, toggles booleans, and steps numeric fields.
        if (str_contains($input, "\033[D")) {
            $this->adjustInspectorOptionField(-1);
            return;
        }

        if (str_contains($input, "\033[C")) {
            $this->adjustInspectorOptionField(1);
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
        $this->databaseCategoryIndex = DatabaseCatalog::indexOf(self::DATABASE_CATEGORY_ACTORS);
        $this->databaseFocus = self::DATABASE_FOCUS_LIST;
        $this->databaseSelectedActorIndex = 0;
        $this->databaseSelectedClassIndex = 0;
        $this->databaseSelectedSkillIndex = 0;
        $this->databaseSelectedQuestIndex = 0;
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
        $this->requestFullRender();
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
            $this->requestQuit();
            return;
        }

        if ($this->isDatabaseEditing) {
            $this->handleDatabaseEditingInput($input);
            return;
        }

        if ($this->handleHistoryShortcut($input)) {
            return;
        }

        if ($input === "\033" || $this->inputRouter->isDatabaseKey($input)) {
            // Esc pops exactly one level; Ctrl+D / F2 toggles the screen.
            $this->closeDatabaseWindow();
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
            $this->saveActiveDatabase();
            return;
        }

        if ($input === "\x01") {
            $this->saveAllAssets();
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

        if ($this->isQuestsDatabaseSelected() && $this->isShiftLetterShortcut($input, 'O')) {
            $this->addDatabaseQuestObjective();
            return;
        }

        if ($this->isQuestsDatabaseSelected() && $this->isShiftLetterShortcut($input, 'X')) {
            $this->removeDatabaseQuestObjective();
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
        $this->renderDatabaseArea(true);
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

        if ($this->isClassesDatabaseSelected()) {
            $this->moveDatabaseClassSelection($step);
            return;
        }

        if ($this->isSkillsDatabaseSelected()) {
            $this->moveDatabaseSkillSelection($step);
            return;
        }

        if ($this->isQuestsDatabaseSelected()) {
            $this->moveDatabaseQuestSelection($step);
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
     * Moves the selected class entry.
     *
     * @param int $step The entry step.
     * @return void
     */
    private function moveDatabaseClassSelection(int $step): void
    {
        $classes = $this->workspace?->classDatabase->getClasses() ?? [];

        if ($classes === []) {
            return;
        }

        $nextIndex = max(0, min(count($classes) - 1, $this->databaseSelectedClassIndex + $step));

        if ($nextIndex === $this->databaseSelectedClassIndex) {
            return;
        }

        $this->databaseSelectedClassIndex = $nextIndex;
        $this->databaseSelectedSettingIndex = 0;
        $this->statusMessage = sprintf('Selected class %s.', $classes[$nextIndex]->getName());
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
    }
    /**
     * Moves the selected skill entry.
     *
     * @param int $step The entry step.
     * @return void
     */
    private function moveDatabaseSkillSelection(int $step): void
    {
        $skills = $this->workspace?->skillDatabase->getSkills() ?? [];

        if ($skills === []) {
            return;
        }

        $nextIndex = max(0, min(count($skills) - 1, $this->databaseSelectedSkillIndex + $step));

        if ($nextIndex === $this->databaseSelectedSkillIndex) {
            return;
        }

        $this->databaseSelectedSkillIndex = $nextIndex;
        $this->databaseSelectedSettingIndex = 0;
        $this->statusMessage = sprintf("Selected skill %s.", $skills[$nextIndex]->getName());
        $this->renderDatabasePanes(["list", "settings", "cue", "frames", "preview"]);
    }


    /**
     * Moves the selected quest entry.
     *
     * @param int $step The entry step.
     * @return void
     */
    private function moveDatabaseQuestSelection(int $step): void
    {
        $quests = $this->workspace?->questDatabase->getQuests() ?? [];

        if ($quests === []) {
            return;
        }

        $nextIndex = max(0, min(count($quests) - 1, $this->databaseSelectedQuestIndex + $step));

        if ($nextIndex === $this->databaseSelectedQuestIndex) {
            return;
        }

        $this->databaseSelectedQuestIndex = $nextIndex;
        $this->databaseSelectedSettingIndex = 0;
        $this->statusMessage = sprintf('Selected quest %s.', $quests[$nextIndex]->getName());
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
        $this->selectAsset(max(0, min($maxIndex, $nextIndex)));
    }

    /**
     * Selects an asset by index and resets the per-map view state.
     *
     * @param int $selectedIndex The clamped asset index.
     * @return void
     */
    private function selectAsset(int $selectedIndex): void
    {
        if (! $this->workspace instanceof ProjectWorkspace || $selectedIndex === $this->selectedAssetIndex) {
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
        $this->isCanvasCursorDirty = true;
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
        $this->requestFullRender();
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

        if ($input === "\033") {
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
        $this->requestFullRender();
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
        $this->requestFullRender();
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

        $isEventLayer = $this->editingMode === self::MODE_EVENT;
        $oldSymbol = $isEventLayer
            ? $selectedMap->getEventSymbol($this->cursorX, $this->cursorY)
            : $selectedMap->getTileSymbol($this->cursorX, $this->cursorY);

        if ($isEventLayer) {
            $selectedMap->setEventSymbol($this->cursorX, $this->cursorY, $symbol);
        } else {
            $selectedMap->setTileSymbol($this->cursorX, $this->cursorY, $symbol);
        }

        $newSymbol = $isEventLayer
            ? $selectedMap->getEventSymbol($this->cursorX, $this->cursorY)
            : $selectedMap->getTileSymbol($this->cursorX, $this->cursorY);

        if ($oldSymbol !== $newSymbol) {
            $stroke = new PaintStrokeCommand(
                $selectedMap,
                $isEventLayer ? PaintStrokeCommand::LAYER_EVENT : PaintStrokeCommand::LAYER_TILE,
                $isEventLayer ? 'Event edit' : 'Tile edit',
            );
            $stroke->appendCell($this->cursorX, $this->cursorY, $oldSymbol, $newSymbol);
            $this->recordCommand($stroke);
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
            $this->finalizeActiveStroke();
            $this->activeMousePaintButton = null;
            $this->lastMousePaintPoint = null;
            return true;
        }

        if ($event->isRelease) {
            $this->finalizeActiveStroke();
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
        $isStartingStroke = $this->activeMousePaintButton !== $button || ! is_array($this->lastMousePaintPoint);
        $start = $isStartingStroke
            ? ['x' => $targetX, 'y' => $targetY]
            : $this->lastMousePaintPoint;

        if (
            $isStartingStroke ||
            ! $this->activeStrokeCommand instanceof PaintStrokeCommand ||
            ! $this->activeStrokeCommand->targets($selectedMap)
        ) {
            // A fresh press (or a stroke that crossed onto another map) opens
            // a new coalescing command; the previous one is committed whole.
            $this->finalizeActiveStroke();
            $this->activeStrokeCommand = new PaintStrokeCommand(
                $selectedMap,
                $paintEvents ? PaintStrokeCommand::LAYER_EVENT : PaintStrokeCommand::LAYER_TILE,
                $paintEvents ? 'Event stroke' : 'Paint stroke',
            );
        }

        foreach ($this->interpolatePoints($start['x'], $start['y'], $targetX, $targetY) as $point) {
            $oldSymbol = $paintEvents
                ? $selectedMap->getEventSymbol($point['x'], $point['y'])
                : $selectedMap->getTileSymbol($point['x'], $point['y']);

            if ($paintEvents) {
                $selectedMap->setEventSymbol($point['x'], $point['y'], $symbol);
            } else {
                $selectedMap->setTileSymbol($point['x'], $point['y'], $symbol);
            }

            $newSymbol = $paintEvents
                ? $selectedMap->getEventSymbol($point['x'], $point['y'])
                : $selectedMap->getTileSymbol($point['x'], $point['y']);
            $this->activeStrokeCommand->appendCell($point['x'], $point['y'], $oldSymbol, $newSymbol);
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
     * Requests an editor exit, guarding unsaved changes behind a prompt.
     *
     * @return void
     */
    private function requestQuit(): void
    {
        if ($this->workspace?->hasUnsavedChanges() === true) {
            $this->openUnsavedChangesGuard(self::GUARD_ACTION_QUIT);
            return;
        }

        $this->isRunning = false;
    }

    /**
     * Requests a workspace reload, guarding unsaved changes behind a prompt.
     *
     * @return void
     */
    private function requestReload(): void
    {
        if ($this->workspace?->hasUnsavedChanges() === true) {
            $this->openUnsavedChangesGuard(self::GUARD_ACTION_RELOAD);
            return;
        }

        $this->performReload();
    }

    /**
     * Reloads the whole workspace from disk, discarding in-memory edits.
     *
     * @return void
     */
    private function performReload(): void
    {
        $this->workspace = ProjectWorkspace::fromProject($this->projectRoot);
        $this->selectedAssetIndex = $this->clampSelection($this->selectedAssetIndex);
        $this->clampCursor();
        $this->clampCanvasOffsets();
        $this->history->clear();
        $this->activeStrokeCommand = null;
        $this->setStatus('Workspace refreshed.', StatusLevel::SUCCESS);
        $this->requestFullRender();
    }

    /**
     * Opens the unsaved-changes confirmation for a destructive action.
     *
     * @param string $action One of the GUARD_ACTION_* constants.
     * @return void
     */
    private function openUnsavedChangesGuard(string $action): void
    {
        $this->pendingGuardAction = $action;
        $this->isUnsavedChangesGuardOpen = true;
        $this->setStatus('Unsaved changes — confirm before continuing.', StatusLevel::WARN);
        $this->renderOverlays();
    }

    /**
     * Handles input on the unsaved-changes confirmation.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleUnsavedChangesGuardInput(string $input, string $normalizedInput): void
    {
        // Destructive confirmation: Cancel is the default, so Enter cancels
        // too — discarding work always requires an explicit `y`.
        if (
            $input === "\033" ||
            $input === "\n" ||
            $input === "\r" ||
            $this->isPlainShortcut($normalizedInput, 'n')
        ) {
            $action = $this->pendingGuardAction;
            $this->isUnsavedChangesGuardOpen = false;
            $this->pendingGuardAction = null;
            // The prompt's WARN toast dies with the prompt.
            $this->toasts->dismissCurrent(microtime(true), StatusLevel::WARN);
            $this->setStatus($action === self::GUARD_ACTION_QUIT ? 'Quit cancelled.' : 'Reload cancelled.');
            $this->requestFullRender();
            return;
        }

        if ($this->isPlainShortcut($normalizedInput, 's')) {
            $this->saveAllAssets();

            if ($this->workspace?->hasUnsavedChanges() === true) {
                // Some assets could not be saved (pending folder moves or
                // errors) — keep the guard up so nothing is lost silently.
                $this->renderOverlays();
                return;
            }

            $this->confirmUnsavedChangesGuard();
            return;
        }

        if ($this->isPlainShortcut($normalizedInput, 'y')) {
            $this->confirmUnsavedChangesGuard();
        }
    }

    /**
     * Executes the guarded action after the author confirmed.
     *
     * @return void
     */
    private function confirmUnsavedChangesGuard(): void
    {
        $action = $this->pendingGuardAction;
        $this->isUnsavedChangesGuardOpen = false;
        $this->pendingGuardAction = null;
        // The prompt's WARN toast dies with the prompt.
        $this->toasts->dismissCurrent(microtime(true), StatusLevel::WARN);

        if ($action === self::GUARD_ACTION_QUIT) {
            $this->isRunning = false;
            return;
        }

        $this->performReload();
    }

    /**
     * Opens the detail overlay backing the latest warn/error status.
     *
     * @return void
     */
    private function openStatusDetailOverlay(): void
    {
        if ($this->statusDetailLines === []) {
            $this->setStatus('No recent warnings or errors.');

            if (! $this->isDatabaseOpen) {
                $this->renderFooter();
            }

            return;
        }

        $this->isStatusDetailOpen = true;
        $this->renderOverlays();
    }

    /**
     * Handles input while the status detail overlay is open.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleStatusDetailInput(string $input, string $normalizedInput): void
    {
        if (
            $input === "\033" ||
            $input === "\x05" ||
            $input === "\n" ||
            $input === "\r"
        ) {
            $this->isStatusDetailOpen = false;
            $this->requestFullRender();
        }
    }

    /**
     * Opens the `?` help overlay (generated from the input binding tables).
     *
     * @return void
     */
    private function openHelpOverlay(): void
    {
        $this->isHelpOpen = true;
        $this->renderOverlays();
    }

    /**
     * Handles input while the help overlay is open.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleHelpInput(string $input, string $normalizedInput): void
    {
        if ($input === "\033" || $input === '?' || $input === "\n" || $input === "\r") {
            $this->isHelpOpen = false;
            $this->requestFullRender();
        }
    }

    /**
     * Builds the help overlay body from the live input router configuration,
     * so the documented shortcuts can never drift from the dispatch tables.
     *
     * @return string[]
     */
    private function getHelpLines(): array
    {
        $entries = $this->inputRouter->describeBindings();
        $keyWidth = 0;

        foreach ($entries as $entry) {
            $keyWidth = max($keyWidth, mb_strwidth($entry['key']));
        }

        $lines = ['Global keys'];

        foreach ($entries as $entry) {
            $lines[] = sprintf('  %s  %s', str_pad($entry['key'], $keyWidth), $entry['description']);
        }

        $lines[] = '';
        $lines[] = 'Everywhere: Arrows move/adjust, Enter activates, and Esc';
        $lines[] = 'backs out exactly one level (edit, dialog, screen).';

        return $lines;
    }

    /**
     * Opens the Ctrl+P command palette over the current context.
     *
     * @return void
     */
    private function openCommandPalette(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $this->commandPalette->open($this->buildPaletteItems());
        $this->isCommandPaletteOpen = true;
        $this->renderOverlays();
    }

    /**
     * Closes the command palette and repaints the surface beneath it.
     *
     * @return void
     */
    private function closeCommandPalette(): void
    {
        $this->isCommandPaletteOpen = false;
        $this->commandPalette->close();
        $this->requestFullRender();
    }

    /**
     * Handles input while the command palette is open.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleCommandPaletteInput(string $input, string $normalizedInput): void
    {
        if ($input === "\033") {
            $this->closeCommandPalette();
            $this->statusMessage = 'Command palette closed.';
            return;
        }

        if ($input === "\n" || $input === "\r") {
            $selectedItem = $this->commandPalette->selectedItem();
            $this->closeCommandPalette();

            if ($selectedItem instanceof PaletteItem) {
                ($selectedItem->action)();
            }

            return;
        }

        if (str_contains($input, "\033[A")) {
            $this->commandPalette->moveSelection(-1);
            $this->renderOverlays();
            return;
        }

        if (str_contains($input, "\033[B")) {
            $this->commandPalette->moveSelection(1);
            $this->renderOverlays();
            return;
        }

        if ($input === "\177" || $input === "\010") {
            $this->commandPalette->backspace();
            $this->renderOverlays();
            return;
        }

        if (str_contains($input, "\033") || $input === "\t") {
            return;
        }

        if (preg_match('/^\X$/u', $input) === 1 && $input !== "\n" && $input !== "\r") {
            $this->commandPalette->type($input);
            $this->renderOverlays();
        }
    }

    /**
     * Builds the command palette items: actions, maps, database categories,
     * and the event markers placed on the selected map.
     *
     * @return PaletteItem[]
     */
    private function buildPaletteItems(): array
    {
        $items = [
            new PaletteItem('Save Map', 'Ctrl+S', fn() => $this->saveSelectedMap()),
            new PaletteItem('Save All', 'Ctrl+A', fn() => $this->saveAllAssets()),
            new PaletteItem('Undo', 'Ctrl+Z', fn() => $this->performUndo()),
            new PaletteItem('Redo', 'Ctrl+Y', fn() => $this->performRedo()),
            new PaletteItem('Reload Workspace', 'Ctrl+R', fn() => $this->requestReload()),
            new PaletteItem('Tool: Map Mode', '%', function (): void {
                $this->closeDatabaseIfOpen();
                $this->setEditingMode(self::MODE_MAP);
            }),
            new PaletteItem('Tool: Event Mode', '^', function (): void {
                $this->closeDatabaseIfOpen();
                $this->setEditingMode(self::MODE_EVENT);
            }),
            new PaletteItem('Tool: Character Map', '@', function (): void {
                $this->closeDatabaseIfOpen();
                $this->openCharacterMap();
            }),
            new PaletteItem('Help', '?', fn() => $this->openHelpOverlay()),
            new PaletteItem('Quit', 'Ctrl+Q', fn() => $this->requestQuit()),
        ];

        foreach ($this->workspace?->mapIds ?? [] as $mapIndex => $mapId) {
            $items[] = new PaletteItem(
                sprintf('Map: %s', $mapId),
                '',
                fn() => $this->jumpToMap($mapIndex),
            );
        }

        foreach (DatabaseCatalog::all() as $category) {
            $items[] = new PaletteItem(
                sprintf('Database: %s', $category->label),
                InputRouter::KEY_DATABASE_LABEL,
                fn() => $this->openDatabaseAtCategory($category->key),
            );
        }

        $selectedMap = $this->getSelectedMap();

        if ($selectedMap instanceof ProjectMap) {
            foreach ($selectedMap->getPlacedEventMarkers() as $marker) {
                $bounds = $selectedMap->getEventBounds($marker);
                $items[] = new PaletteItem(
                    sprintf(
                        'Event: %s on %s%s',
                        $marker,
                        $selectedMap->mapId,
                        $bounds === null ? '' : sprintf(' (%d, %d)', $bounds['x'], $bounds['y']),
                    ),
                    '',
                    fn() => $this->jumpToEventMarker($marker),
                );
            }
        }

        return $items;
    }

    /**
     * Closes the Database screen when a palette action targets the shell.
     *
     * @return void
     */
    private function closeDatabaseIfOpen(): void
    {
        if ($this->isDatabaseOpen) {
            $this->closeDatabaseWindow();
        }
    }

    /**
     * Jumps to a map by index (the palette's map navigation).
     *
     * @param int $mapIndex The workspace map index.
     * @return void
     */
    private function jumpToMap(int $mapIndex): void
    {
        $this->closeDatabaseIfOpen();
        $this->selectAsset($this->clampSelection($mapIndex));
    }

    /**
     * Jumps to an event marker on the selected map (the palette's event
     * navigation): event mode, canvas focus, cursor on the marker.
     *
     * @param string $marker The event marker glyph.
     * @return void
     */
    private function jumpToEventMarker(string $marker): void
    {
        $this->closeDatabaseIfOpen();
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        $bounds = $selectedMap->getEventBounds($marker);

        $this->setEditingMode(self::MODE_EVENT);
        $this->setFocusedPane(self::FOCUS_CANVAS, false);

        if ($bounds !== null) {
            $this->cursorX = $bounds['x'];
            $this->cursorY = $bounds['y'];
        }

        $this->clampCursor();
        $this->syncViewportToCursor();
        $this->clampInspectorSelection();
        $this->statusMessage = sprintf('Jumped to event %s at (%d, %d).', $marker, $this->cursorX, $this->cursorY);
        $this->renderSelectionDependentArea();
    }

    /**
     * Opens the Database screen on a specific category (the palette's
     * database navigation).
     *
     * @param string $categoryKey The stable category key.
     * @return void
     */
    private function openDatabaseAtCategory(string $categoryKey): void
    {
        if (! $this->isDatabaseOpen) {
            $this->openDatabaseWindow();
        }

        $this->databaseCategoryIndex = DatabaseCatalog::indexOf($categoryKey);
        $this->databaseFocus = self::DATABASE_FOCUS_LIST;
        $this->databaseSelectedSettingIndex = 0;
        $this->statusMessage = sprintf('%s database selected.', DatabaseCatalog::at($this->databaseCategoryIndex)->label);
        $this->renderDatabaseArea(includeRoot: true);
    }

    /**
     * Saves every dirty map and database in one pass.
     *
     * Maps whose save would move their folder are skipped — the rename flow
     * requires its own explicit confirmation via Ctrl+S on that map.
     *
     * @return void
     */
    private function saveAllAssets(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $savedMaps = 0;
        $savedDatabases = 0;
        $skippedRenames = [];
        $failures = [];
        $validationWarnings = [];
        $mapsById = $this->getMapsById();

        foreach ($this->workspace->maps as $map) {
            if (! $map->isDirty()) {
                continue;
            }

            if ($map->willMoveOnSave()) {
                $skippedRenames[] = $map->mapId;
                continue;
            }

            foreach (MapValidator::validate($map, $mapsById) as $warning) {
                $validationWarnings[] = sprintf('%s: %s', $map->mapId, $warning);
            }

            try {
                $map->save();
                $savedMaps++;
            } catch (Throwable $throwable) {
                Debug::error(sprintf('Save all (%s): %s', $map->mapId, $throwable->getMessage()));
                $failures[] = sprintf('%s: %s', $map->mapId, $throwable->getMessage());
            }
        }

        foreach ($this->getSaveableDatabases() as $label => $database) {
            if (! $database->isDirty()) {
                continue;
            }

            try {
                $database->save();
                $savedDatabases++;
            } catch (Throwable $throwable) {
                Debug::error(sprintf('Save all (%s): %s', $label, $throwable->getMessage()));
                $failures[] = sprintf('%s: %s', $label, $throwable->getMessage());
            }
        }

        $summary = sprintf(
            'Saved %d map%s and %d database%s.',
            $savedMaps,
            $savedMaps === 1 ? '' : 's',
            $savedDatabases,
            $savedDatabases === 1 ? '' : 's',
        );
        $detailLines = [];

        if ($skippedRenames !== []) {
            $detailLines[] = 'Skipped — saving would move the map folder (use Ctrl+S on the map to confirm):';
            $detailLines = [...$detailLines, ...array_map(static fn(string $mapId): string => '  ' . $mapId, $skippedRenames), ''];
        }

        if ($failures !== []) {
            $detailLines = [...$detailLines, 'Failed:', ...array_map(static fn(string $failure): string => '  ' . $failure, $failures), ''];
        }

        if ($validationWarnings !== []) {
            $detailLines = [...$detailLines, 'Validation warnings:', ...array_map(static fn(string $warning): string => '  ' . $warning, $validationWarnings)];
        }

        if ($failures !== []) {
            $this->setStatus($summary . sprintf(' %d failed (Ctrl+E for details).', count($failures)), StatusLevel::ERROR, $detailLines);
        } elseif ($skippedRenames !== [] || $validationWarnings !== []) {
            $this->setStatus($summary . ' See Ctrl+E for skipped saves and warnings.', StatusLevel::WARN, $detailLines);
        } else {
            $this->setStatus($summary, StatusLevel::SUCCESS);
        }

        $this->renderSelectionDependentArea();
    }

    /**
     * Returns every saveable database keyed by display label.
     *
     * @return array<string, ProjectActorDatabase|ProjectClassDatabase|ProjectSkillDatabase|ProjectAnimationDatabase|ProjectSystemDatabase|ProjectQuestDatabase>
     */
    private function getSaveableDatabases(): array
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return [];
        }

        return [
            'Actors' => $this->workspace->actorDatabase,
            'Classes' => $this->workspace->classDatabase,
            'Skills' => $this->workspace->skillDatabase,
            'Quests' => $this->workspace->questDatabase,
            'Animations' => $this->workspace->animationDatabase,
            'System' => $this->workspace->systemDatabase,
        ];
    }

    /**
     * Returns the workspace maps keyed by map id for validation lookups.
     *
     * @return array<string, ProjectMap>
     */
    private function getMapsById(): array
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return [];
        }

        $mapsById = [];

        foreach ($this->workspace->maps as $map) {
            $mapsById[$map->mapId] = $map;
        }

        return $mapsById;
    }

    /**
     * Saves the selected map, confirming first when the save would move the
     * map folder (a display-name/region rename).
     *
     * @return void
     */
    private function saveSelectedMap(): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        if ($selectedMap->willMoveOnSave()) {
            $this->isRenameConfirmationOpen = true;
            $this->setStatus(
                sprintf('Saving will move the map folder to %s — confirm.', $selectedMap->getSaveTarget()['mapId']),
                StatusLevel::WARN,
            );
            $this->renderOverlays();
            return;
        }

        $this->performSaveSelectedMap();
    }

    /**
     * Handles input on the folder-move save confirmation.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleRenameConfirmationInput(string $input, string $normalizedInput): void
    {
        // Destructive confirmation: Cancel is the default, so Enter cancels
        // too — moving the map folder always requires an explicit `y`.
        if (
            $input === "\033" ||
            $input === "\n" ||
            $input === "\r" ||
            $this->isPlainShortcut($normalizedInput, 'n')
        ) {
            $this->isRenameConfirmationOpen = false;
            // The prompt's WARN toast dies with the prompt.
            $this->toasts->dismissCurrent(microtime(true), StatusLevel::WARN);
            $this->setStatus('Save cancelled — the map folder was not moved.');
            $this->requestFullRender();
            return;
        }

        if ($this->isPlainShortcut($normalizedInput, 'y')) {
            $this->isRenameConfirmationOpen = false;
            $this->toasts->dismissCurrent(microtime(true), StatusLevel::WARN);
            $this->performSaveSelectedMap();
        }
    }

    /**
     * Persists the selected map in place — the rest of the workspace stays
     * loaded, so edits on other maps and databases survive the save.
     *
     * @return void
     */
    private function performSaveSelectedMap(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $selectedMap = $this->workspace->getMapByIndex($this->selectedAssetIndex);

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        $warnings = MapValidator::validate($selectedMap, $this->getMapsById());

        try {
            $previousMapId = $selectedMap->mapId;
            $savedMapId = $selectedMap->save();

            if ($savedMapId !== $previousMapId) {
                // The folder moved: swap in a freshly parsed map instance and
                // drop history entries that reference the retired one.
                $mapsRoot = $this->workspace->getMapsRoot();
                $reloadedMap = ProjectMap::fromDirectory(
                    $mapsRoot,
                    $mapsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $savedMapId),
                );
                $this->workspace = $this->workspace->withReplacedMap($this->selectedAssetIndex, $reloadedMap);
                $selectedIndex = array_search($savedMapId, $this->workspace->mapIds, true);
                $this->selectedAssetIndex = is_int($selectedIndex) ? $selectedIndex : 0;
                $this->history->clear();
                $this->activeStrokeCommand = null;
                $this->clampCursor();
                $this->clampCanvasOffsets();
                $this->clampInspectorSelection();
            }

            if ($warnings === []) {
                $this->setStatus(sprintf('Saved %s.', $savedMapId), StatusLevel::SUCCESS);
            } else {
                $this->setStatus(
                    sprintf('Saved %s with %d warning%s (Ctrl+E for details).', $savedMapId, count($warnings), count($warnings) === 1 ? '' : 's'),
                    StatusLevel::WARN,
                    $warnings,
                );
            }
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Save');
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
            $this->setStatus(sprintf('Created %s.', $mapId), StatusLevel::SUCCESS);
            $this->setFocusedPane(self::FOCUS_INSPECTOR);
            $this->beginInspectorEdit();
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Create map');
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
            $this->setStatus(sprintf('Duplicated %s.', $mapId), StatusLevel::SUCCESS);
            $this->renderSelectionDependentArea();
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Duplicate map');
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
        if ($input === "\033") {
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


    private function openLootDialog(string $marker, array $path, string $currentLoot, LootType $lootType): void
    {
        if ($marker === '' || $path === []) {
            $this->statusMessage = 'Unable to open loot picker.';
            $this->renderFooter();
            return;
        }

        $entries = $this->loadLootDialogEntries($lootType);

        if ($entries === []) {
            $this->statusMessage = sprintf('No %s are available.', mb_strtolower($this->getLootTypeLabel($lootType, plural: true)));
            $this->renderFooter();
            return;
        }

        $this->lootDialogEntries = $entries;
        $this->selectedLootIndex = $this->resolveLootSelectionIndex($currentLoot);
        $this->lootDialogMarker = $marker;
        $this->lootDialogType = $lootType;
        $this->lootDialogPath = $path;
        $this->isLootDialogOpen = true;
        $this->statusMessage = sprintf('Choose %s for %s.', mb_strtolower($this->getLootTypeLabel($lootType)), $marker);
        $this->renderSelectionDependentArea();
    }

    private function handleLootDialogInput(string $input, string $normalizedInput): void
    {
        if ($input === chr(27)) {
            $this->closeLootDialog('Loot selection cancelled.');
            return;
        }

        if (str_contains($input, chr(27) . '[A') || $this->isPlainShortcut($normalizedInput, 'k')) {
            $this->moveLootSelection(-1);
            return;
        }

        if (str_contains($input, chr(27) . '[B') || $this->isPlainShortcut($normalizedInput, 'j')) {
            $this->moveLootSelection(1);
            return;
        }

        if ($input === chr(10) || $input === chr(13)) {
            $this->applySelectedLoot();
        }
    }

    private function moveLootSelection(int $step): void
    {
        if ($this->lootDialogEntries === []) {
            return;
        }

        $nextIndex = max(0, min(count($this->lootDialogEntries) - 1, $this->selectedLootIndex + $step));

        if ($nextIndex === $this->selectedLootIndex) {
            return;
        }

        $this->selectedLootIndex = $nextIndex;
        $this->renderOverlays();
    }

    private function applySelectedLoot(): void
    {
        $selectedMap = $this->getSelectedMap();
        $selectedEntry = $this->lootDialogEntries[$this->selectedLootIndex] ?? null;

        if (! $selectedMap instanceof ProjectMap || ! is_array($selectedEntry) || ! is_string($this->lootDialogMarker) || $this->lootDialogMarker === '' || ! is_array($this->lootDialogPath)) {
            $this->closeLootDialog('Unable to set loot.');
            return;
        }

        $marker = $this->lootDialogMarker;
        $path = $this->lootDialogPath;
        $newValue = $selectedEntry['name'];
        $oldValue = $selectedMap->getEventField($marker, $path);
        $selectedMap->setEventField($marker, $path, $newValue);
        $this->recordCommand(new GenericCommand(
            'Loot change',
            static fn() => $selectedMap->setEventField($marker, $path, $newValue),
            static fn() => $selectedMap->setEventField($marker, $path, $oldValue),
        ));
        $lootTypeLabel = $this->getLootTypeLabel($this->lootDialogType ?? LootType::ITEM);
        $this->closeLootDialog(sprintf('Set %s to %s.', mb_strtolower($lootTypeLabel), $selectedEntry['name']));
    }

    private function closeLootDialog(string $statusMessage): void
    {
        $this->isLootDialogOpen = false;
        $this->lootDialogMarker = null;
        $this->lootDialogType = null;
        $this->lootDialogPath = null;
        $this->lootDialogEntries = [];
        $this->selectedLootIndex = 0;
        $this->statusMessage = $statusMessage;
        $this->renderSelectionDependentArea();
    }

    private function openEventOptionDialog(string $marker, array $path, string $title, array $entries, string $currentValue): void
    {
        if ($marker === '' || $path === [] || $entries === []) {
            $this->statusMessage = 'Unable to open option picker.';
            $this->renderFooter();
            return;
        }

        $this->eventOptionDialogMarker = $marker;
        $this->eventOptionDialogPath = $path;
        $this->eventOptionDialogTitle = $title;
        $this->eventOptionDialogEntries = $entries;
        $this->selectedEventOptionIndex = $this->resolveEventOptionSelectionIndex($currentValue);
        $this->isEventOptionDialogOpen = true;
        $this->statusMessage = sprintf('Choose %s for %s.', mb_strtolower($title), $marker);
        $this->renderSelectionDependentArea();
    }

    private function handleEventOptionDialogInput(string $input, string $normalizedInput): void
    {
        if ($input === chr(27)) {
            $this->closeEventOptionDialog('Selection cancelled.');
            return;
        }

        if (str_contains($input, chr(27) . '[A') || $this->isPlainShortcut($normalizedInput, 'k')) {
            $this->moveEventOptionSelection(-1);
            return;
        }

        if (str_contains($input, chr(27) . '[B') || $this->isPlainShortcut($normalizedInput, 'j')) {
            $this->moveEventOptionSelection(1);
            return;
        }

        if ($input === chr(10) || $input === chr(13)) {
            $this->applySelectedEventOption();
        }
    }

    private function moveEventOptionSelection(int $step): void
    {
        if ($this->eventOptionDialogEntries === []) {
            return;
        }

        $nextIndex = max(0, min(count($this->eventOptionDialogEntries) - 1, $this->selectedEventOptionIndex + $step));

        if ($nextIndex === $this->selectedEventOptionIndex) {
            return;
        }

        $this->selectedEventOptionIndex = $nextIndex;
        $this->renderOverlays();
    }

    private function applySelectedEventOption(): void
    {
        $selectedMap = $this->getSelectedMap();
        $selectedEntry = $this->eventOptionDialogEntries[$this->selectedEventOptionIndex] ?? null;

        if (! $selectedMap instanceof ProjectMap || ! is_array($selectedEntry) || ! is_string($this->eventOptionDialogMarker) || $this->eventOptionDialogMarker === '' || ! is_array($this->eventOptionDialogPath)) {
            $this->closeEventOptionDialog('Unable to set option.');
            return;
        }

        $marker = $this->eventOptionDialogMarker;
        $path = $this->eventOptionDialogPath;
        $newValue = $selectedEntry['value'];
        $oldValue = $selectedMap->getEventField($marker, $path);
        $selectedMap->setEventField($marker, $path, $newValue);
        $this->recordCommand(new GenericCommand(
            sprintf('%s change', $this->eventOptionDialogTitle),
            static fn() => $selectedMap->setEventField($marker, $path, $newValue),
            static fn() => $selectedMap->setEventField($marker, $path, $oldValue),
        ));
        $this->closeEventOptionDialog(sprintf('Set %s to %s.', mb_strtolower($this->eventOptionDialogTitle), $selectedEntry['label']));
    }

    private function closeEventOptionDialog(string $statusMessage): void
    {
        $this->isEventOptionDialogOpen = false;
        $this->eventOptionDialogMarker = null;
        $this->eventOptionDialogPath = null;
        $this->eventOptionDialogTitle = 'Options';
        $this->eventOptionDialogEntries = [];
        $this->selectedEventOptionIndex = 0;
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
        if ($input === "\033") {
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
            $this->isPlainShortcut($normalizedInput, 'n')
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

        $marker = $context['marker'];
        $destinationPath = $context['path'];
        $newDestination = $context['destinationMapId'];
        $newSpawnX = $this->cursorX;
        $newSpawnY = $this->cursorY;
        $oldDestination = $sourceMap->getEventField($marker, $destinationPath);
        $oldSpawnX = $sourceMap->getEventField($marker, ['data', 'spawnPoint', 'x']);
        $oldSpawnY = $sourceMap->getEventField($marker, ['data', 'spawnPoint', 'y']);

        $applyDestination = static function (mixed $destination, mixed $spawnX, mixed $spawnY) use ($sourceMap, $marker, $destinationPath): void {
            $sourceMap->setEventField($marker, $destinationPath, $destination);
            $sourceMap->setEventField($marker, ['data', 'spawnPoint', 'x'], $spawnX);
            $sourceMap->setEventField($marker, ['data', 'spawnPoint', 'y'], $spawnY);
        };

        $applyDestination($newDestination, $newSpawnX, $newSpawnY);
        $this->recordCommand(new GenericCommand(
            'Destination change',
            static fn() => $applyDestination($newDestination, $newSpawnX, $newSpawnY),
            static fn() => $applyDestination($oldDestination, $oldSpawnX, $oldSpawnY),
        ));

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
        $this->requestFullRender();
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
        if ($input === "\033") {
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

        $newDefinition = [
            'class' => $definition->className,
            'data' => $eventData,
        ];
        $selectedMap->setEventDefinition($marker, $newDefinition);
        $this->recordCommand(new GenericCommand(
            'Event type change',
            static fn() => $selectedMap->setEventDefinition($marker, $newDefinition),
            static function () use ($selectedMap, $marker, $currentDefinition): void {
                if (is_array($currentDefinition)) {
                    $selectedMap->setEventDefinition($marker, $currentDefinition);
                    return;
                }

                $selectedMap->removeEventDefinition($marker);
            },
        ));
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
        // Destructive confirmation: Cancel is the default, so Enter cancels
        // too — deleting a map always requires an explicit `y`.
        if (
            $input === "\033" ||
            $input === "\n" ||
            $input === "\r" ||
            $this->isPlainShortcut($normalizedInput, 'n')
        ) {
            $this->isDeleteConfirmationOpen = false;
            $this->statusMessage = 'Delete cancelled.';
            $this->renderSelectionDependentArea();
            return;
        }

        if ($this->isPlainShortcut($normalizedInput, 'y')) {
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

            $this->history->clear();
            $this->activeStrokeCommand = null;
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
            $this->setStatus(sprintf('Deleted %s.', $deletedMapId), StatusLevel::SUCCESS);
            $this->renderSelectionDependentArea();
        } catch (Throwable $throwable) {
            $this->isDeleteConfirmationOpen = false;
            $this->setErrorStatus($throwable, 'Delete');
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
        // A full rescan replaces every loaded map object, so retained undo
        // commands would mutate stale instances — drop them.
        $this->history->clear();
        $this->activeStrokeCommand = null;
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

        $this->selectedInspectorFieldIndex = max(0, min(count($fields) - 1, $this->selectedInspectorFieldIndex + $step));
        $this->renderInspectorArea();
    }

    /**
     * Clamps the inspector selection to the visible field list.
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

        if ($this->isChestTypeField($field)) {
            $this->openEventOptionDialog(
                (string) ($field['marker'] ?? ''),
                (array) ($field['path'] ?? []),
                'Chest Type',
                $this->getChestTypeOptionEntries(),
                (string) ($field['value'] ?? ''),
            );
            return;
        }

        if ($this->isLootTypeField($field)) {
            $this->openEventOptionDialog(
                (string) ($field['marker'] ?? ''),
                (array) ($field['path'] ?? []),
                'Loot Type',
                $this->getLootTypeOptionEntries(),
                (string) ($field['value'] ?? ''),
            );
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

        $lootType = $this->resolveLootFieldType($field);

        if ($this->isLootField($field) && $lootType instanceof LootType) {
            $this->openLootDialog(
                (string) ($field['marker'] ?? ''),
                (array) ($field['path'] ?? []),
                (string) ($field['value'] ?? ''),
                $lootType,
            );
            return;
        }

        $control = $this->getInspectorFieldControl($field);

        if ($control instanceof InputControl && $control->type === InputControlType::BOOLEAN) {
            // Booleans toggle in place — there is nothing to type.
            $this->applyInspectorAdjustment($field, $control->adjust((string) ($field['value'] ?? 'false'), 1));
            return;
        }

        $this->beginInspectorEdit();
    }

    /**
     * Adjusts the selected inspector field with the Left/Right idiom:
     * enum fields cycle their options, booleans toggle, and numeric fields
     * step — mirroring the Database settings pane exactly.
     *
     * @param int $step The adjustment direction.
     * @return void
     */
    private function adjustInspectorOptionField(int $step): void
    {
        $fields = $this->getInspectorFields();
        $field = $fields[$this->selectedInspectorFieldIndex] ?? null;

        if (! is_array($field)) {
            return;
        }

        $options = $this->resolveInspectorFieldOptions($field);

        if ($options !== null && $options !== []) {
            $currentValue = (string) ($field['value'] ?? '');
            $optionIndex = array_search($currentValue, $options, true);

            if (! is_int($optionIndex)) {
                $optionIndex = array_search(
                    strtolower($currentValue),
                    array_map(strtolower(...), $options),
                    true,
                );
            }

            $optionIndex = is_int($optionIndex) ? $optionIndex : 0;
            $optionIndex = max(0, min(count($options) - 1, $optionIndex + $step));
            $newValue = (string) $options[$optionIndex];

            if ($newValue === $currentValue) {
                return;
            }

            $this->applyInspectorAdjustment($field, $newValue);
            return;
        }

        $control = $this->getInspectorFieldControl($field);

        if (! $control instanceof InputControl) {
            return;
        }

        if ($control->type === InputControlType::BOOLEAN) {
            $this->applyInspectorAdjustment($field, $control->adjust((string) ($field['value'] ?? 'false'), $step));
            return;
        }

        if (in_array($control->type, [InputControlType::INTEGER, InputControlType::FLOAT], true)) {
            $this->applyInspectorAdjustment($field, $control->adjust((string) ($field['value'] ?? '0'), $step));
        }
    }

    /**
     * Resolves the option list backing an enum-like inspector field.
     *
     * @param array<string, mixed> $field The inspector field descriptor.
     * @return string[]|null
     */
    private function resolveInspectorFieldOptions(array $field): ?array
    {
        $options = $field['options'] ?? null;

        if (is_array($options) && $options !== []) {
            return array_map(strval(...), $options);
        }

        if ($this->isChestTypeField($field)) {
            return array_map(strval(...), array_column($this->getChestTypeOptionEntries(), 'value'));
        }

        if ($this->isLootTypeField($field)) {
            return array_map(strval(...), array_column($this->getLootTypeOptionEntries(), 'value'));
        }

        return null;
    }

    /**
     * Applies an adjusted inspector value with undo recording and feedback.
     *
     * @param array<string, mixed> $field The inspector field descriptor.
     * @param string $rawValue The adjusted raw value.
     * @return void
     */
    private function applyInspectorAdjustment(array $field, string $rawValue): void
    {
        try {
            $this->applyInspectorFieldValue($field, $rawValue);
            $this->clampCursor();
            $this->clampCanvasOffsets();
            $this->clampInspectorSelection();
            $this->setStatus(sprintf('%s updated.', $field['label'] ?? 'Field'), StatusLevel::SUCCESS);
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, sprintf('%s edit', $field['label'] ?? 'Field'));
        }

        $this->renderSelectionDependentArea();
    }

    /**
     * Returns whether the field should open the loot picker.
     *
     * @param array<string, mixed> $field The inspector field descriptor.
     * @return bool
     */
    private function isLootField(array $field): bool
    {
        if (
            ($field['target'] ?? null) !== 'event'
            || (($field['path'] ?? []) != ['data', 'loot'])
        ) {
            return false;
        }

        return $this->isDialogSelectableLootType($this->resolveLootFieldType($field));
    }

    /**
     * Resolves the event loot type for the current inspector field.
     *
     * @param array<string, mixed> $field The inspector field descriptor.
     * @return LootType|null
     */
    private function resolveLootFieldType(array $field): ?LootType
    {
        if (
            ($field['target'] ?? null) !== 'event'
            || (($field['path'] ?? []) != ['data', 'loot'])
        ) {
            return null;
        }

        $marker = (string) ($field['marker'] ?? '');

        if ($marker === '') {
            return null;
        }

        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return null;
        }

        $definition = $selectedMap->getEventDefinition($marker);
        $eventData = is_array($definition) ? ($definition['data'] ?? null) : null;

        if (! is_array($eventData)) {
            return null;
        }

        return $this->resolveLootTypeValue($eventData['lootType'] ?? null);
    }

    /**
     * Resolves a stored loot type into the enum when possible.
     *
     * @param mixed $value The stored loot type value.
     * @return LootType|null
     */
    private function resolveLootTypeValue(mixed $value): ?LootType
    {
        if ($value instanceof LootType) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return LootType::tryFrom($value);
    }

    /**
     * Returns whether the given loot type should use the picker dialog.
     *
     * @param LootType|null $lootType The resolved loot type.
     * @return bool
     */
    private function isDialogSelectableLootType(?LootType $lootType): bool
    {
        return in_array(
            $lootType,
            [
                LootType::ITEM,
                LootType::SKILL,
                LootType::SPELL,
                LootType::WEAPON,
                LootType::ARMOR,
                LootType::ACCESSORY,
            ],
            true,
        );
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
        $fields = $this->getInspectorFields();
        $field = $fields[$this->selectedInspectorFieldIndex] ?? null;
        $control = is_array($field) ? $this->getInspectorFieldControl($field) : null;

        switch ($this->inspectorFieldEditor->handleKey($input, $control)) {
            case TextFieldKeyResult::CANCELLED:
                $this->inspectorFieldEditor->close();
                $this->statusMessage = 'Edit cancelled.';
                $this->renderInspectorArea();
                return;
            case TextFieldKeyResult::SUBMITTED:
                $this->commitInspectorEdit();
                return;
            case TextFieldKeyResult::CHANGED:
                $this->renderInspectorArea();
                return;
            case TextFieldKeyResult::IGNORED:
                return;
        }
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
            $this->setStatus(sprintf('%s updated.', $field['label'] ?? 'Field'), StatusLevel::SUCCESS);
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, sprintf('%s edit', $field['label'] ?? 'Field'));
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
            InputControlType::FLOAT => (float) trim($rawValue),
            InputControlType::BOOLEAN => InputControl::parseBoolean($rawValue),
            default => $rawValue,
        };
        $target = (string) ($field['target'] ?? 'map');

        if ($target === 'map') {
            $fieldName = (string) $field['field'];
            $oldValue = $selectedMap->getMapField($fieldName);
            $selectedMap->setMapField($fieldName, $value);
            $this->recordCommand(new GenericCommand(
                sprintf('%s edit', $field['label'] ?? 'Field'),
                static fn() => $selectedMap->setMapField($fieldName, $value),
                static fn() => $selectedMap->setMapField($fieldName, $oldValue),
            ));
            return;
        }

        if ($target === 'map-size') {
            $snapshotBefore = $selectedMap->captureGridSnapshot();
            $selectedMap->resize(
                (string) ($field['field'] ?? '') === 'width' ? max(1, (int) $value) : $selectedMap->getWidth(),
                (string) ($field['field'] ?? '') === 'height' ? max(1, (int) $value) : $selectedMap->getHeight(),
            );
            $snapshotAfter = $selectedMap->captureGridSnapshot();
            $this->recordCommand(new GenericCommand(
                'Map resize',
                static fn() => $selectedMap->restoreGridSnapshot($snapshotAfter),
                static fn() => $selectedMap->restoreGridSnapshot($snapshotBefore),
            ));
            return;
        }

        if ($target === 'event') {
            $marker = (string) $field['marker'];
            $path = (array) ($field['path'] ?? []);
            $oldValue = $selectedMap->getEventField($marker, $path);
            $selectedMap->setEventField($marker, $path, $value);
            $this->recordCommand(new GenericCommand(
                sprintf('%s edit', $field['label'] ?? 'Event field'),
                static fn() => $selectedMap->setEventField($marker, $path, $value),
                static fn() => $selectedMap->setEventField($marker, $path, $oldValue),
            ));
            return;
        }

        if ($target === 'event-bounds') {
            $bounds = $selectedMap->getEventBounds((string) $field['marker']);

            if ($bounds === null) {
                return;
            }

            $snapshotBefore = $selectedMap->captureGridSnapshot();
            $bounds[(string) $field['field']] = max(0, (int) $value);
            $selectedMap->setEventBounds(
                (string) $field['marker'],
                $bounds['x'],
                $bounds['y'],
                $bounds['width'],
                $bounds['height'],
            );
            $snapshotAfter = $selectedMap->captureGridSnapshot();
            $this->recordCommand(new GenericCommand(
                'Event bounds edit',
                static fn() => $selectedMap->restoreGridSnapshot($snapshotAfter),
                static fn() => $selectedMap->restoreGridSnapshot($snapshotBefore),
            ));
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
     * Returns whether the Classes database is active.
     *
     * @return bool
     */
    private function isClassesDatabaseSelected(): bool
    {
        return $this->getSelectedDatabaseCategoryDefinition()->key === self::DATABASE_CATEGORY_CLASSES;
    }


    /**
     * Returns whether the Skills database is active.
     *
     * @return bool
     */
    private function isSkillsDatabaseSelected(): bool
    {
        return $this->getSelectedDatabaseCategoryDefinition()->key === self::DATABASE_CATEGORY_SKILLS;
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
     * Returns whether the Quests database is active.
     *
     * @return bool
     */
    private function isQuestsDatabaseSelected(): bool
    {
        return $this->getSelectedDatabaseCategoryDefinition()->key === self::DATABASE_CATEGORY_QUESTS;
    }

    /**
     * Returns whether the System database is active.
     *
     * @return bool
     */
    private function isSystemDatabaseSelected(): bool
    {
        return $this->getSelectedDatabaseCategoryDefinition()->key === self::DATABASE_CATEGORY_SYSTEM;
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
     * Returns the selected skill from the project database.
     *
     * @return ProjectSkill|null
     */
    private function getSelectedSkill(): ?ProjectSkill
    {
        if (! $this->isSkillsDatabaseSelected()) {
            return null;
        }

        return $this->workspace?->skillDatabase->getSkillByIndex($this->databaseSelectedSkillIndex);
    }

    /**
     * Returns the selected quest from the project database.
     *
     * @return ProjectQuest|null
     */
    private function getSelectedQuest(): ?ProjectQuest
    {
        if (! $this->isQuestsDatabaseSelected()) {
            return null;
        }

        return $this->workspace?->questDatabase->getQuestByIndex($this->databaseSelectedQuestIndex);
    }

    /**
     * Returns the selected class from the project database.
     *
     * @return ProjectClass|null
     */
    private function getSelectedClass(): ?ProjectClass
    {
        if (! $this->isClassesDatabaseSelected()) {
            return null;
        }

        return $this->workspace?->classDatabase->getClassByIndex($this->databaseSelectedClassIndex);
    }

    /**
     * Returns the selected animation from the project database.
     *
     * @return Animation|null
     */
    private function getSelectedAnimation()
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

        if ($this->isSkillsDatabaseSelected()) {
            $this->createDatabaseSkill();
            return;
        }

        if ($this->isClassesDatabaseSelected()) {
            $this->createDatabaseClass();
            return;
        }

        if ($this->isQuestsDatabaseSelected()) {
            $this->createDatabaseQuest();
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
     * Creates a new class entry in the project database.
     *
     * @return void
     */
    private function createDatabaseClass(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $this->databaseSelectedClassIndex = $this->workspace->classDatabase->addClass();
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseFocus = self::DATABASE_FOCUS_SETTINGS;
        $this->statusMessage = "Created a new class.";
        $this->renderDatabasePanes(["list", "settings", "cue", "frames", "preview"]);
        $this->beginDatabaseEdit();
    }

    /**
     * Creates a new skill entry in the project database.
     *
     * @return void
     */
    private function createDatabaseSkill(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $this->databaseSelectedSkillIndex = $this->workspace->skillDatabase->addSkill();
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseFocus = self::DATABASE_FOCUS_SETTINGS;
        $this->statusMessage = "Created a new skill.";
        $this->renderDatabasePanes(["list", "settings", "cue", "frames", "preview"]);
        $this->beginDatabaseEdit();
    }

    /**
     * Creates a new quest entry in the project database.
     *
     * @return void
     */
    private function createDatabaseQuest(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $this->databaseSelectedQuestIndex = $this->workspace->questDatabase->addQuest();
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseFocus = self::DATABASE_FOCUS_SETTINGS;
        $this->statusMessage = "Created a new quest.";
        $this->renderDatabasePanes(["list", "settings", "cue", "frames", "preview"]);
        $this->beginDatabaseEdit();
    }

    /**
     * Appends an objective to the selected quest and records it for undo.
     *
     * @return void
     */
    private function addDatabaseQuestObjective(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace || ! $this->getSelectedQuest() instanceof ProjectQuest) {
            return;
        }

        $questDatabase = $this->workspace->questDatabase;
        $questIndex = $this->databaseSelectedQuestIndex;
        $objectiveIndex = $questDatabase->addObjective($questIndex);

        if ($objectiveIndex === null) {
            return;
        }

        $objective = $questDatabase->getQuestByIndex($questIndex)?->getObjectives()[$objectiveIndex] ?? [];
        $this->recordCommand(new GenericCommand(
            'Quest objective add',
            static fn() => $questDatabase->insertObjective($questIndex, $objectiveIndex, $objective),
            static fn() => $questDatabase->removeObjective($questIndex, $objectiveIndex),
        ));
        $this->setStatus(sprintf('Objective %d added.', $objectiveIndex + 1), StatusLevel::SUCCESS);
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
    }

    /**
     * Removes the selected quest objective and records it for undo.
     *
     * The objective under the highlighted settings field is removed when one
     * is highlighted; the last objective otherwise.
     *
     * @return void
     */
    private function removeDatabaseQuestObjective(): void
    {
        $quest = $this->getSelectedQuest();

        if (! $this->workspace instanceof ProjectWorkspace || ! $quest instanceof ProjectQuest) {
            return;
        }

        $objectiveCount = count($quest->getObjectives());

        if ($objectiveCount === 0) {
            return;
        }

        $objectiveIndex = $objectiveCount - 1;
        $fields = $this->getDatabaseSettingsFields();
        $selectedField = (string) ($fields[$this->databaseSelectedSettingIndex]['field'] ?? '');

        if (preg_match('/^objective(\d+)/', $selectedField, $matches) === 1) {
            $objectiveIndex = min($objectiveCount - 1, intval($matches[1]));
        }

        $questDatabase = $this->workspace->questDatabase;
        $questIndex = $this->databaseSelectedQuestIndex;
        $removed = $questDatabase->removeObjective($questIndex, $objectiveIndex);

        if ($removed === null) {
            return;
        }

        $this->databaseSelectedSettingIndex = min(
            $this->databaseSelectedSettingIndex,
            max(0, count($this->getDatabaseSettingsFields()) - 1)
        );
        $this->recordCommand(new GenericCommand(
            'Quest objective remove',
            static fn() => $questDatabase->removeObjective($questIndex, $objectiveIndex),
            static fn() => $questDatabase->insertObjective($questIndex, $objectiveIndex, $removed),
        ));
        $this->setStatus(sprintf('Objective %d removed.', $objectiveIndex + 1), StatusLevel::SUCCESS);
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
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
        $this->statusMessage = "Created a new animation.";
        $this->renderDatabasePanes(["list", "settings", "cue", "frames", "preview"]);
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
                $this->setStatus('Actor database saved.', StatusLevel::SUCCESS);
            } elseif ($this->isClassesDatabaseSelected()) {
                $this->workspace->classDatabase->save();
                $this->setStatus('Class database saved.', StatusLevel::SUCCESS);
            } elseif ($this->isSkillsDatabaseSelected()) {
                $this->workspace->skillDatabase->save();
                $this->setStatus('Skill database saved.', StatusLevel::SUCCESS);
            } elseif ($this->isQuestsDatabaseSelected()) {
                $this->workspace->questDatabase->save();
                $this->setStatus('Quest database saved.', StatusLevel::SUCCESS);
            } elseif ($this->isAnimationsDatabaseSelected()) {
                $this->workspace->animationDatabase->save();
                $this->setStatus('Animation database saved.', StatusLevel::SUCCESS);
            } elseif ($this->isSystemDatabaseSelected()) {
                $this->workspace->systemDatabase->save();
                $this->setStatus('System database saved.', StatusLevel::SUCCESS);
            } else {
                $this->setStatus('This database category is not editable yet.', StatusLevel::WARN);
            }
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Database save');
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

        if ($this->isClassesDatabaseSelected()) {
            return $this->getDatabaseClassSettingsFields();
        }

        if ($this->isSkillsDatabaseSelected()) {
            return $this->getDatabaseSkillSettingsFields();
        }

        if ($this->isQuestsDatabaseSelected()) {
            return $this->getDatabaseQuestSettingsFields();
        }

        if ($this->isSystemDatabaseSelected()) {
            return $this->getDatabaseSystemSettingsFields();
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
     * Returns the editable settings fields for the selected class.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDatabaseClassSettingsFields(): array
    {
        $class = $this->getSelectedClass();

        if (! $class instanceof ProjectClass) {
            return [];
        }

        $experienceCurve = $class->getExperienceCurve();

        return [
            [
                'label' => 'Name',
                'value' => $class->getName(),
                'control' => new InputControl(InputControlType::TEXT, $class->getName()),
                'field' => 'name',
            ],
            [
                'label' => 'Description',
                'value' => $class->getDescription(),
                'control' => new InputControl(InputControlType::TEXT, $class->getDescription()),
                'field' => 'description',
            ],
            [
                'label' => 'Initial Level',
                'value' => (string) $class->getInitialLevel(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $class->getInitialLevel()),
                'field' => 'initialLevel',
            ],
            [
                'label' => 'Max Level',
                'value' => (string) $class->getMaxLevel(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $class->getMaxLevel()),
                'field' => 'maxLevel',
            ],
            [
                'label' => 'EXP Base',
                'value' => (string) $experienceCurve['baseValue'],
                'control' => new InputControl(InputControlType::INTEGER, (string) $experienceCurve['baseValue']),
                'field' => 'expBaseValue',
            ],
            [
                'label' => 'EXP Extra',
                'value' => (string) $experienceCurve['extraValue'],
                'control' => new InputControl(InputControlType::INTEGER, (string) $experienceCurve['extraValue']),
                'field' => 'expExtraValue',
            ],
            [
                'label' => 'EXP Accel A',
                'value' => (string) $experienceCurve['accelerationA'],
                'control' => new InputControl(InputControlType::INTEGER, (string) $experienceCurve['accelerationA']),
                'field' => 'expAccelerationA',
            ],
            [
                'label' => 'EXP Accel B',
                'value' => (string) $experienceCurve['accelerationB'],
                'control' => new InputControl(InputControlType::INTEGER, (string) $experienceCurve['accelerationB']),
                'field' => 'expAccelerationB',
            ],
            ...$this->getDatabaseClassBaseValueFields($class),
        ];
    }

    /**
     * Returns the editable settings fields for the selected skill.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDatabaseSkillSettingsFields(): array
    {
        $skill = $this->getSelectedSkill();

        if (! $skill instanceof ProjectSkill) {
            return [];
        }

        $scope = $skill->getScope();
        $invocation = $skill->getInvocation();

        return [
            ['label' => 'Name', 'value' => $skill->getName(), 'control' => new InputControl(InputControlType::TEXT, $skill->getName()), 'field' => 'name'],
            ['label' => 'Description', 'value' => $skill->getDescription(), 'control' => new InputControl(InputControlType::TEXT, $skill->getDescription()), 'field' => 'description'],
            ['label' => 'Type', 'value' => $skill->getType(), 'options' => ['basic', 'special', 'magic'], 'field' => 'type'],
            ['label' => 'Icon', 'value' => $skill->getIcon(), 'control' => new InputControl(InputControlType::TEXT, $skill->getIcon()), 'field' => 'icon'],
            ['label' => 'Cost', 'value' => (string) $skill->getCost(), 'control' => new InputControl(InputControlType::INTEGER, (string) $skill->getCost()), 'field' => 'cost'],
            ['label' => 'Cooldown', 'value' => (string) $skill->getCooldown(), 'control' => new InputControl(InputControlType::INTEGER, (string) $skill->getCooldown()), 'field' => 'cooldown'],
            ['label' => 'Occasion', 'value' => $skill->getOccasion(), 'options' => array_map(static fn(Occasion $occasion): string => $occasion->value, Occasion::cases()), 'field' => 'occasion'],
            ['label' => 'Scope Side', 'value' => (string) ($scope['side'] ?? ItemScopeSide::ENEMY->value), 'options' => array_map(static fn(ItemScopeSide $side): string => $side->value, ItemScopeSide::cases()), 'field' => 'scopeSide'],
            ['label' => 'Scope Number', 'value' => (string) ($scope['number'] ?? ItemScopeNumber::ONE->value), 'options' => array_map(static fn(ItemScopeNumber $number): string => $number->value, ItemScopeNumber::cases()), 'field' => 'scopeNumber'],
            ['label' => 'Scope Status', 'value' => (string) ($scope['status'] ?? ItemScopeStatus::ALIVE->value), 'options' => array_map(static fn(ItemScopeStatus $status): string => $status->value, ItemScopeStatus::cases()), 'field' => 'scopeStatus'],
            ['label' => 'Target Count', 'value' => (string) ($scope['targetCount'] ?? ''), 'control' => new InputControl(InputControlType::INTEGER, (string) ($scope['targetCount'] ?? '')), 'field' => 'scopeTargetCount'],
            ['label' => 'Invoke Text', 'value' => (string) ($invocation['message'] ?? ''), 'control' => new InputControl(InputControlType::TEXT, (string) ($invocation['message'] ?? '')), 'field' => 'invocationMessage'],
            ['label' => 'Invoke Speed', 'value' => (string) ($invocation['speed'] ?? 0), 'control' => new InputControl(InputControlType::INTEGER, (string) ($invocation['speed'] ?? 0)), 'field' => 'invocationSpeed'],
            ['label' => 'Accuracy', 'value' => (string) ($invocation['accuracy'] ?? 0), 'control' => new InputControl(InputControlType::INTEGER, (string) ($invocation['accuracy'] ?? 0)), 'field' => 'invocationAccuracy'],
            ['label' => 'Repeat', 'value' => (string) ($invocation['repeat'] ?? 1), 'control' => new InputControl(InputControlType::INTEGER, (string) ($invocation['repeat'] ?? 1)), 'field' => 'invocationRepeat'],
            ['label' => 'AP Gain', 'value' => (string) ($invocation['apGain'] ?? 10), 'control' => new InputControl(InputControlType::INTEGER, (string) ($invocation['apGain'] ?? 10)), 'field' => 'invocationApGain'],
            ['label' => 'Effect Type', 'value' => $skill->getEffectType() ?? MagicEffectType::DESTRUCTIVE->value, 'options' => array_map(static fn(MagicEffectType $effectType): string => $effectType->value, MagicEffectType::cases()), 'field' => 'effectType'],
        ];
    }
    /**
     * Returns the editable settings fields for the selected quest.
     *
     * Objectives are flattened into per-objective field groups (type,
     * target, quantity, description) so the flat settings pane can edit the
     * nested list; Shift+O / Shift+X add and remove objectives.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDatabaseQuestSettingsFields(): array
    {
        $quest = $this->getSelectedQuest();

        if (! $quest instanceof ProjectQuest) {
            return [];
        }

        $fields = [
            ['label' => 'Id', 'value' => $quest->getId(), 'control' => new InputControl(InputControlType::TEXT, $quest->getId()), 'field' => 'id'],
            ['label' => 'Name', 'value' => $quest->getName(), 'control' => new InputControl(InputControlType::TEXT, $quest->getName()), 'field' => 'name'],
            ['label' => 'Description', 'value' => $quest->getDescription(), 'control' => new InputControl(InputControlType::TEXT, $quest->getDescription()), 'field' => 'description'],
            ['label' => 'Giver', 'value' => $quest->getGiver(), 'control' => new InputControl(InputControlType::TEXT, $quest->getGiver()), 'field' => 'giver'],
            ['label' => 'Reward Gold', 'value' => (string) $quest->getRewardGold(), 'control' => new InputControl(InputControlType::INTEGER, (string) $quest->getRewardGold()), 'field' => 'rewardGold'],
            ['label' => 'Reward EXP', 'value' => (string) $quest->getRewardExperience(), 'control' => new InputControl(InputControlType::INTEGER, (string) $quest->getRewardExperience()), 'field' => 'rewardExperience'],
            ['label' => 'Reward Items', 'value' => $quest->getRewardItemsString(), 'control' => new InputControl(InputControlType::TEXT, $quest->getRewardItemsString()), 'field' => 'rewardItems'],
            ['label' => 'Prereqs', 'value' => $quest->getPrerequisitesString(), 'control' => new InputControl(InputControlType::TEXT, $quest->getPrerequisitesString()), 'field' => 'prerequisites'],
        ];

        foreach ($quest->getObjectives() as $index => $objective) {
            $label = sprintf('Obj %d', $index + 1);
            $type = strval($objective['type'] ?? QuestObjectiveType::TALK_TO->value);
            $target = strval($objective['target'] ?? '');
            $quantity = (string) max(1, intval($objective['quantity'] ?? 1));
            $description = strval($objective['description'] ?? '');
            $fields[] = [
                'label' => $label . ' Type',
                'value' => $type,
                'options' => array_map(static fn(QuestObjectiveType $objectiveType): string => $objectiveType->value, QuestObjectiveType::cases()),
                'field' => sprintf('objective%dType', $index),
            ];
            $fields[] = [
                'label' => $label . ' Target',
                'value' => $target,
                'control' => new InputControl(InputControlType::TEXT, $target),
                'field' => sprintf('objective%dTarget', $index),
            ];
            $fields[] = [
                'label' => $label . ' Qty',
                'value' => $quantity,
                'control' => new InputControl(InputControlType::INTEGER, $quantity),
                'field' => sprintf('objective%dQuantity', $index),
            ];
            $fields[] = [
                'label' => $label . ' Text',
                'value' => $description,
                'control' => new InputControl(InputControlType::TEXT, $description),
                'field' => sprintf('objective%dDescription', $index),
            ];
        }

        return $fields;
    }

    /**
     * Returns the editable class base-value fields.
     *
     * @param ProjectClass $class The selected class.
     * @return array<int, array<string, mixed>>
     */
    private function getDatabaseClassBaseValueFields(ProjectClass $class): array
    {
        $fieldMap = [
            'HP Base' => ['field' => 'totalHpBaseValue', 'curve' => 'totalHp'],
            'MP Base' => ['field' => 'totalMpBaseValue', 'curve' => 'totalMp'],
            'ATK Base' => ['field' => 'attackBaseValue', 'curve' => 'attack'],
            'DEF Base' => ['field' => 'defenceBaseValue', 'curve' => 'defence'],
            'MAT Base' => ['field' => 'magicAttackBaseValue', 'curve' => 'magicAttack'],
            'MDF Base' => ['field' => 'magicDefenceBaseValue', 'curve' => 'magicDefence'],
            'SPD Base' => ['field' => 'speedBaseValue', 'curve' => 'speed'],
        ];
        $fields = [];

        foreach ($fieldMap as $label => $definition) {
            $curve = $class->getParameterCurve($definition['curve']);
            $value = (string) $curve['baseValue'];
            $fields[] = [
                'label' => $label,
                'value' => $value,
                'control' => new InputControl(InputControlType::INTEGER, $value),
                'field' => $definition['field'],
            ];
        }

        return $fields;
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
        $fields = $this->getDatabaseSettingsFields();
        $field = $fields[$this->databaseSelectedSettingIndex] ?? null;
        $control = is_array($field) ? $this->getDatabaseFieldControl($field) : null;

        switch ($this->databaseFieldEditor->handleKey($input, $control)) {
            case TextFieldKeyResult::CANCELLED:
                $this->databaseFieldEditor->close();
                $this->statusMessage = 'Database edit cancelled.';
                $this->renderDatabasePanes(['settings']);
                return;
            case TextFieldKeyResult::SUBMITTED:
                $this->commitDatabaseEdit();
                return;
            case TextFieldKeyResult::CHANGED:
                $this->renderDatabasePanes(['settings']);
                return;
            case TextFieldKeyResult::IGNORED:
                return;
        }
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
            $this->applyDatabaseFieldValueRecorded($field, $this->databaseEditBuffer);
            $this->setStatus(sprintf('%s updated.', $field['label'] ?? 'Field'), StatusLevel::SUCCESS);
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, sprintf('%s edit', $field['label'] ?? 'Field'));
        }

        $this->isDatabaseEditing = false;
        $this->databaseEditBuffer = '';
        $this->databaseEditCursorIndex = 0;
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
    }

    /**
     * Returns the editable settings fields for the project system.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDatabaseSystemSettingsFields(): array
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return [];
        }

        $system = $this->workspace->systemDatabase;

        return [
            [
                'label' => 'Battle Engine',
                'value' => $system->getBattleEngine(),
                'options' => ['traditional', 'active_time'],
                'field' => 'battleEngine',
            ],
            [
                'label' => 'ATB Mode',
                'value' => $system->getAtbMode(),
                'options' => ['wait'],
                'field' => 'atbMode',
            ],
            [
                'label' => 'ATB Base Fill Rate',
                'value' => (string) $system->getAtbBaseFillRate(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $system->getAtbBaseFillRate()),
                'field' => 'atbBaseFillRate',
            ],
            [
                'label' => 'ATB Speed Factor %',
                'value' => (string) $system->getAtbSpeedFactorPercent(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $system->getAtbSpeedFactorPercent()),
                'field' => 'atbSpeedFactorPercent',
            ],
        ];
    }

    /**
     * Applies one database settings value and records it for undo/redo.
     *
     * The command pins the entry identity (category, entry index, frame), so
     * undoing later still edits the right record even after the selection
     * moved elsewhere. Brush symbol/color are editor-local preferences and
     * are deliberately not recorded.
     *
     * @param array<string, mixed> $field The settings field descriptor.
     * @param string $rawValue The raw edited value.
     * @return void
     */
    private function applyDatabaseFieldValueRecorded(array $field, string $rawValue): void
    {
        $fieldId = (string) ($field['field'] ?? '');
        $control = $this->getDatabaseFieldControl($field);
        $oldRawValue = $control instanceof InputControl
            ? $control->rawValue
            : strtolower((string) ($field['value'] ?? ''));
        $identity = [
            'category' => $this->databaseCategoryIndex,
            'actor' => $this->databaseSelectedActorIndex,
            'class' => $this->databaseSelectedClassIndex,
            'skill' => $this->databaseSelectedSkillIndex,
            'quest' => $this->databaseSelectedQuestIndex,
            'animation' => $this->databaseSelectedAnimationIndex,
            'frame' => $this->databaseSelectedFrameIndex,
        ];

        $this->applyDatabaseFieldValue($fieldId, $rawValue);

        if (in_array($fieldId, ['brushSymbol', 'brushColor'], true) || $oldRawValue === $rawValue) {
            return;
        }

        $this->recordCommand(new GenericCommand(
            sprintf('%s edit', $field['label'] ?? 'Database field'),
            fn() => $this->applyDatabaseFieldValueAt($identity, $fieldId, $rawValue),
            fn() => $this->applyDatabaseFieldValueAt($identity, $fieldId, $oldRawValue),
        ));
    }

    /**
     * Applies a database value onto a pinned entry identity, restoring the
     * live selection afterwards.
     *
     * @param array{category: int, actor: int, class: int, skill: int, quest: int, animation: int, frame: int} $identity The pinned selection.
     * @param string $field The field identifier.
     * @param string $rawValue The raw value to apply.
     * @return void
     */
    private function applyDatabaseFieldValueAt(array $identity, string $field, string $rawValue): void
    {
        $liveSelection = [
            $this->databaseCategoryIndex,
            $this->databaseSelectedActorIndex,
            $this->databaseSelectedClassIndex,
            $this->databaseSelectedSkillIndex,
            $this->databaseSelectedQuestIndex,
            $this->databaseSelectedAnimationIndex,
            $this->databaseSelectedFrameIndex,
        ];
        $this->databaseCategoryIndex = $identity['category'];
        $this->databaseSelectedActorIndex = $identity['actor'];
        $this->databaseSelectedClassIndex = $identity['class'];
        $this->databaseSelectedSkillIndex = $identity['skill'];
        $this->databaseSelectedQuestIndex = $identity['quest'];
        $this->databaseSelectedAnimationIndex = $identity['animation'];
        $this->databaseSelectedFrameIndex = $identity['frame'];

        try {
            $this->applyDatabaseFieldValue($field, $rawValue);
        } finally {
            [
                $this->databaseCategoryIndex,
                $this->databaseSelectedActorIndex,
                $this->databaseSelectedClassIndex,
                $this->databaseSelectedSkillIndex,
                $this->databaseSelectedQuestIndex,
                $this->databaseSelectedAnimationIndex,
                $this->databaseSelectedFrameIndex,
            ] = $liveSelection;
        }
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

        if ($this->isClassesDatabaseSelected()) {
            $this->workspace->classDatabase->setField(
                $this->databaseSelectedClassIndex,
                $field,
                in_array($field, ['name', 'description', 'note'], true) ? trim($rawValue) : max(0, intval($rawValue)),
            );

            return;
        }

        if ($this->isSkillsDatabaseSelected()) {
            $value = in_array($field, ["cost", "cooldown", "invocationSpeed", "invocationAccuracy", "invocationRepeat", "invocationApGain"], true)
                ? max(0, intval($rawValue))
                : ($field === "scopeTargetCount" ? $rawValue : trim($rawValue));
            $this->workspace->skillDatabase->setField($this->databaseSelectedSkillIndex, $field, $value);
            return;
        }

        if ($this->isQuestsDatabaseSelected()) {
            $isIntegerField = in_array($field, ['rewardGold', 'rewardExperience'], true)
                || preg_match('/^objective\d+Quantity$/', $field) === 1;
            $value = $isIntegerField ? max(0, intval($rawValue)) : trim($rawValue);
            $this->workspace->questDatabase->setField($this->databaseSelectedQuestIndex, $field, $value);
            return;
        }

        if ($this->isSystemDatabaseSelected()) {
            $value = in_array($field, ['battleEngine', 'atbMode'], true)
                ? trim($rawValue)
                : max(0, intval($rawValue));
            $this->workspace->systemDatabase->setField($field, $value);
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
            $this->applyDatabaseFieldValueRecorded($field, $control->adjust((string) ($field['value'] ?? '0'), $step));
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
        $this->applyDatabaseFieldValueRecorded($field, (string) $options[$optionIndex]);
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
        $animationDatabase = $this->workspace->animationDatabase;
        $animationIndex = $this->databaseSelectedAnimationIndex;
        $frameIndex = $this->databaseSelectedFrameIndex;
        $oldCell = $animation->getFrame($frameIndex)->getCellAt($cellX, $cellY);
        $oldSymbol = $oldCell?->symbol ?? ' ';
        $oldColor = $oldCell?->color;

        $animationDatabase->setFrameCell($animationIndex, $frameIndex, $cellX, $cellY, $symbol, $color);

        $newCell = $animation->getFrame($frameIndex)->getCellAt($cellX, $cellY);

        if (($newCell?->symbol ?? ' ') !== $oldSymbol || ($newCell?->color) !== $oldColor) {
            $this->recordCommand(new GenericCommand(
                'Frame paint',
                static fn() => $animationDatabase->setFrameCell($animationIndex, $frameIndex, $cellX, $cellY, $symbol, $color),
                static fn() => $animationDatabase->setFrameCell($animationIndex, $frameIndex, $cellX, $cellY, $oldSymbol, $oldColor),
            ));
        }

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

        if ($this->isDatabasePreviewPlaying) {
            // Shift+P toggles: a second press stops the running preview.
            $this->stopDatabaseAnimationPreview('Preview stopped.');
            return;
        }

        $this->isDatabasePreviewPlaying = true;
        $this->databasePlaybackFrameIndex = 1;
        $this->databasePlaybackNextFrameAt = microtime(true);
        $this->statusMessage = sprintf('Playing %s.', $animation->name);
        $this->renderDatabasePanes(['preview']);
    }

    /**
     * Advances the non-blocking animation preview from the frame loop.
     *
     * Playback is a state ticked from update() rather than a blocking call so
     * the editor keeps accepting input while an animation plays.
     *
     * @return void
     */
    private function tickDatabaseAnimationPreview(): void
    {
        if (! $this->isDatabasePreviewPlaying) {
            return;
        }

        $animation = $this->getSelectedAnimation();

        if (! $animation instanceof Animation) {
            $this->stopDatabaseAnimationPreview('Preview stopped.');
            return;
        }

        $now = microtime(true);

        if ($now < $this->databasePlaybackNextFrameAt) {
            return;
        }

        if ($this->databasePlaybackFrameIndex > $animation->maxFrames) {
            $this->stopDatabaseAnimationPreview('Preview complete.');
            return;
        }

        $cue = $animation->getCue($this->databasePlaybackFrameIndex);
        $this->databasePlaybackFlashColor = $cue?->flashColor;
        $this->renderDatabasePanes(['preview']);
        $this->databasePlaybackFrameIndex++;
        $this->databasePlaybackNextFrameAt = $now + self::PREVIEW_SECONDS_PER_FRAME;
    }

    /**
     * Ends the animation preview and restores the selected frame.
     *
     * @param string $statusMessage The status line to show.
     * @return void
     */
    private function stopDatabaseAnimationPreview(string $statusMessage): void
    {
        $this->isDatabasePreviewPlaying = false;
        $this->databasePlaybackFlashColor = null;
        $this->databasePlaybackFrameIndex = $this->databaseSelectedFrameIndex;
        $this->statusMessage = $statusMessage;
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
            $stringValue = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_float($value) => InputControl::formatFloat($value),
                default => (string) $value,
            };
            $controlType = match (true) {
                is_bool($value) => InputControlType::BOOLEAN,
                is_int($value) => InputControlType::INTEGER,
                is_float($value) => InputControlType::FLOAT,
                default => InputControlType::TEXT,
            };
            $fields[] = [
                'label' => $label,
                'value' => $stringValue,
                'control' => new InputControl($controlType, $stringValue),
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
        if (! $this->isRunning) {
            return;
        }

        if ($this->isFullRenderPending) {
            $this->isFullRenderPending = false;
            $this->clearDirtyRenderState();
            $this->renderFullScreen();
            return;
        }

        $this->flushDirtyPanels();
    }

    /**
     * Repaints exactly the panels and overlays that input handlers marked
     * dirty this tick. An idle frame writes zero bytes.
     *
     * @return void
     */
    private function flushDirtyPanels(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        if ($this->isDatabaseOpen) {
            $this->databaseScreen->flush();

            if ($this->areOverlaysDirty) {
                $this->drawOverlays();
            }

            $this->clearDirtyRenderState();
            return;
        }

        foreach ([$this->assetsPanel, $this->canvasPanel, $this->inspectorPanel] as $panel) {
            if ($panel->isDirty()) {
                $panel->render();
            }
        }

        if ($this->isFooterDirty) {
            $this->drawFooter();
        }

        if ($this->areOverlaysDirty) {
            $this->drawOverlays();
        } elseif ($this->isCanvasCursorDirty) {
            $this->renderCanvasCursor($this->resolveLayout());
        }

        $this->clearDirtyRenderState();
    }

    /**
     * Clears every per-panel dirty flag (a full-screen redraw supersedes
     * partial repaints).
     *
     * @return void
     */
    private function clearDirtyRenderState(): void
    {
        $this->assetsPanel->clearDirty();
        $this->canvasPanel->clearDirty();
        $this->inspectorPanel->clearDirty();
        $this->isFooterDirty = false;
        $this->areOverlaysDirty = false;
        $this->isCanvasCursorDirty = false;
        $this->databaseScreen->clearDirty();
    }

    /**
     * Draws the full editor shell.
     *
     * @return void
     */
    private function renderFullScreen(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            throw new RuntimeException('The editor workspace is not loaded.');
        }

        Console::cursor()->hide();

        if ($this->isDatabaseOpen) {
            $this->clearScreen();
            $this->drawDatabaseArea(includeRoot: true);
            $this->renderModalOverlays($this->resolveLayout());
            return;
        }

        $this->clearScreen();
        $this->createHeaderWindow()->render();
        $this->createAssetWindow()->render();
        $this->createCanvasWindow()->render();
        $this->createInspectorWindow()->render();
        $this->drawFooter();

        $this->drawOverlays();
    }

    /**
     * Clears the visible screen with a single escape sequence.
     *
     * Never shells out: `system("clear")` forks a subprocess (~5ms) on every
     * call and is the difference between a repaint and a visible flash.
     *
     * @return void
     */
    private function clearScreen(): void
    {
        $this->terminal->clearScreen();
    }

    /**
     * Returns the live terminal size.
     *
     * @return array{width: int, height: int}
     */
    private function getTerminalSize(): array
    {
        return $this->terminal->probeSize();
    }

    /**
     * Resolves the current editor layout metrics.
     *
     * @return array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int}
     */
    private function resolveLayout(): array
    {
        $size = $this->lastTerminalSize ?? $this->getTerminalSize();

        if ($this->cachedLayout !== null && $this->cachedLayoutSize === $size) {
            return $this->cachedLayout;
        }

        $width = max(80, $size['width']);
        $height = max(24, $size['height']);
        $leftWidth = 32;
        $rightWidth = 34;
        $gutter = 1;
        $centerWidth = max(30, $width - $leftWidth - $rightWidth - ($gutter * 4));
        $contentHeight = max(10, $height - 8);

        $this->cachedLayoutSize = $size;

        return $this->cachedLayout = [
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
            help: 'Enter:Insert  Esc:Close',
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
            'Y: Delete',
            'Enter/Esc/N: Cancel (default)',
        ];
        $overlayWidth = min(max(48, mb_strwidth($mapLabel) + 22), max(48, $layout['width'] - 10));
        $overlayHeight = 7;
        $left = max(2, intdiv($layout['width'] - $overlayWidth, 2));
        $top = max(2, intdiv($layout['height'] - $overlayHeight, 2));

        $window = new EditorWindow(
            title: 'Delete Map',
            help: 'Y:Delete  Esc:Cancel',
            position: ['x' => $left, 'y' => $top],
            width: $overlayWidth,
            height: $overlayHeight,
            foregroundColor: Color::LIGHT_BLUE,
            content: $this->fitLines($rows, $this->getWindowContentWidth($overlayWidth), max(1, $overlayHeight - 2)),
        );

        $window->render();
    }

    private function renderLootDialogOverlay(array $layout): void
    {
        $entries = $this->lootDialogEntries;
        $selectedEntry = $entries[$this->selectedLootIndex] ?? null;
        $contentWidth = min(68, max(48, $layout['width'] - 12));
        $contentHeight = min(max(12, count($entries) + 7), max(12, $layout['height'] - 8));
        $availableRows = max(1, $contentHeight - 2);
        $listRows = max(1, $availableRows - 4);
        $rows = $this->buildLootDialogRows($entries, $listRows);
        $left = max(2, intdiv($layout['width'] - $contentWidth, 2));
        $top = max(2, intdiv($layout['height'] - $contentHeight, 2));

        if (is_array($selectedEntry)) {
            $rows[] = '';
            $rows[] = sprintf('Name: %s', $selectedEntry['name']);
            $rows[] = sprintf('Type: %s', $selectedEntry['type']);
            $rows[] = $selectedEntry['description'];
        }

        $window = new EditorWindow(
            title: $this->getLootTypeLabel($this->lootDialogType ?? LootType::ITEM, plural: true),
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

    private function renderEventOptionDialogOverlay(array $layout): void
    {
        $entries = $this->eventOptionDialogEntries;
        $selectedEntry = $entries[$this->selectedEventOptionIndex] ?? null;
        $contentWidth = min(60, max(40, $layout["width"] - 14));
        $contentHeight = min(max(9, count($entries) + 6), max(9, $layout["height"] - 10));
        $availableRows = max(1, $contentHeight - 2);
        $listRows = max(1, $availableRows - 3);
        $rows = $this->buildEventOptionDialogRows($entries, $listRows);
        $left = max(2, intdiv($layout["width"] - $contentWidth, 2));
        $top = max(2, intdiv($layout["height"] - $contentHeight, 2));

        if (is_array($selectedEntry)) {
            $rows[] = "";
            $rows[] = (string) ($selectedEntry["description"] ?? "");
        }

        $window = new EditorWindow(
            title: $this->eventOptionDialogTitle,
            help: "Enter:Select  Esc:Cancel",
            position: ["x" => $left, "y" => $top],
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

    private function loadLootDialogEntries(LootType $lootType): array
    {
        return match ($lootType) {
            LootType::ITEM,
            LootType::WEAPON,
            LootType::ARMOR,
            LootType::ACCESSORY => $this->loadInventoryLootDialogEntries($lootType),
            LootType::SKILL => $this->loadSkillLootDialogEntries(),
            LootType::SPELL => $this->loadSpellLootDialogEntries(),
            default => [],
        };
    }

    /**
     * Loads inventory-backed loot entries for the requested loot type.
     *
     * @param LootType $lootType The loot type being edited.
     * @return array<int, array{name: string, description: string, icon: string, type: string}>
     */
    private function loadInventoryLootDialogEntries(LootType $lootType): array
    {
        $itemsPath = $this->projectRoot . '/assets/Data/items.php';

        if (! is_file($itemsPath)) {
            return [];
        }

        $items = require $itemsPath;

        if (! is_array($items)) {
            return [];
        }

        $entries = [];

        foreach ($items as $item) {
            if (! is_object($item) || ! isset($item->name) || ! $this->matchesLootInventoryType($item, $lootType)) {
                continue;
            }

            $entries[] = [
                'name' => (string) $item->name,
                'description' => (string) ($item->description ?? ''),
                'icon' => trim((string) ($item->icon ?? '')),
                'type' => $this->getLootTypeLabel($lootType),
            ];
        }

        usort($entries, static fn(array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $entries;
    }

    /**
     * Loads skill-based loot entries.
     *
     * @return array<int, array{name: string, description: string, icon: string, type: string}>
     */
    private function loadSkillLootDialogEntries(): array
    {
        $skillsPath = $this->projectRoot . '/assets/Data/skills.php';

        if (! is_file($skillsPath)) {
            return [];
        }

        $skills = require $skillsPath;

        if (! is_array($skills)) {
            return [];
        }

        $entries = [];

        foreach ($skills as $skill) {
            if (! is_object($skill) || ! isset($skill->name)) {
                continue;
            }

            $entries[] = [
                'name' => (string) $skill->name,
                'description' => (string) ($skill->description ?? ''),
                'icon' => trim((string) ($skill->icon ?? '')),
                'type' => 'Skill',
            ];
        }

        usort($entries, static fn(array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $entries;
    }

    /**
     * Loads magic/spell loot entries.
     *
     * @return array<int, array{name: string, description: string, icon: string, type: string}>
     */
    private function loadSpellLootDialogEntries(): array
    {
        $magicPath = $this->projectRoot . '/assets/Data/magic.php';

        if (! is_file($magicPath)) {
            return [];
        }

        $spells = require $magicPath;

        if (! is_array($spells)) {
            return [];
        }

        $entries = [];

        foreach ($spells as $spell) {
            if (! is_object($spell) || ! isset($spell->name)) {
                continue;
            }

            $entries[] = [
                'name' => (string) $spell->name,
                'description' => (string) ($spell->description ?? ''),
                'icon' => trim((string) ($spell->icon ?? '')),
                'type' => 'Spell',
            ];
        }

        usort($entries, static fn(array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $entries;
    }

    /**
     * Returns whether the given inventory object matches the selected loot type.
     *
     * @param object $item The inventory object to inspect.
     * @param LootType $lootType The loot type being edited.
     * @return bool
     */
    private function matchesLootInventoryType(object $item, LootType $lootType): bool
    {
        return match ($lootType) {
            LootType::ITEM => $item instanceof Item
                && ! $item instanceof Weapon
                && ! $item instanceof Armor
                && ! $item instanceof Accessory,
            LootType::WEAPON => $item instanceof Weapon,
            LootType::ARMOR => $item instanceof Armor,
            LootType::ACCESSORY => $item instanceof Accessory,
            default => false,
        };
    }

    private function resolveLootSelectionIndex(string $currentLoot): int
    {
        foreach ($this->lootDialogEntries as $index => $entry) {
            if ($entry['name'] === $currentLoot) {
                return $index;
            }
        }

        return 0;
    }

    private function buildLootDialogRows(array $entries, int $availableRows): array
    {
        $rows = [];
        $selectedRowIndex = 0;
        $groupByType = count(array_unique(array_column($entries, 'type'))) > 1;
        $currentType = null;

        foreach ($entries as $index => $entry) {
            if ($groupByType && $entry['type'] !== $currentType) {
                $currentType = $entry['type'];
                $rows[] = sprintf('[%s]', $currentType === '' ? 'Unknown' : $currentType);
            }

            if ($index === $this->selectedLootIndex) {
                $selectedRowIndex = count($rows);
            }

            $prefix = $index === $this->selectedLootIndex ? '>' : ' ';
            $icon = $entry['icon'] === '' ? '-' : $entry['icon'];
            $rows[] = sprintf('%s %s %s', $prefix, $icon, $entry['name']);
        }

        if (count($rows) <= $availableRows) {
            return $rows;
        }

        $startRow = max(0, min(count($rows) - $availableRows, $selectedRowIndex - intdiv($availableRows, 2)));

        return array_slice($rows, $startRow, $availableRows);
    }


    private function isChestTypeField(array $field): bool
    {
        return ($field['target'] ?? null) === 'event'
            && (($field['path'] ?? []) === ['data', 'chestType']);
    }

    private function isLootTypeField(array $field): bool
    {
        return ($field['target'] ?? null) === 'event'
            && (($field['path'] ?? []) === ['data', 'lootType']);
    }

    private function getChestTypeOptionEntries(): array
    {
        return [
            [
                'label' => 'Common',
                'value' => ChestType::COMMON->value,
                'description' => 'Standard chest presentation for ordinary treasure.',
            ],
            [
                'label' => 'Rare',
                'value' => ChestType::RARE->value,
                'description' => 'Highlights a chest that should feel less common.',
            ],
            [
                'label' => 'Epic',
                'value' => ChestType::EPIC->value,
                'description' => 'Marks a chest carrying high-value treasure.',
            ],
            [
                'label' => 'Legendary',
                'value' => ChestType::LEGENDARY->value,
                'description' => 'Reserved for the most special chest rewards.',
            ],
        ];
    }

    /**
     * Returns the editor-facing label for a loot type.
     *
     * @param LootType $lootType The loot type to label.
     * @param bool $plural Whether to return the plural form.
     * @return string
     */
    private function getLootTypeLabel(LootType $lootType, bool $plural = false): string
    {
        return match ($lootType) {
            LootType::ITEM => $plural ? 'Items' : 'Item',
            LootType::GOLD => 'Gold',
            LootType::EXPERIENCE => 'Experience',
            LootType::SKILL => $plural ? 'Skills' : 'Skill',
            LootType::SPELL => $plural ? 'Spells' : 'Spell',
            LootType::WEAPON => $plural ? 'Weapons' : 'Weapon',
            LootType::ARMOR => 'Armor',
            LootType::ACCESSORY => $plural ? 'Accessories' : 'Accessory',
        };
    }

    private function getLootTypeOptionEntries(): array
    {
        return [
            [
                'label' => 'Item',
                'value' => LootType::ITEM->value,
                'description' => 'Rewards an item from the project item database.',
            ],
            [
                'label' => 'Gold',
                'value' => LootType::GOLD->value,
                'description' => 'Awards currency directly when the chest is opened.',
            ],
            [
                'label' => 'Experience',
                'value' => LootType::EXPERIENCE->value,
                'description' => 'Awards experience directly when claimed.',
            ],
            [
                'label' => 'Skill',
                'value' => LootType::SKILL->value,
                'description' => 'Rewards a learnable skill identifier.',
            ],
            [
                'label' => 'Spell',
                'value' => LootType::SPELL->value,
                'description' => 'Rewards a spell identifier.',
            ],
            [
                'label' => 'Weapon',
                'value' => LootType::WEAPON->value,
                'description' => 'Rewards a weapon identifier.',
            ],
            [
                'label' => 'Armor',
                'value' => LootType::ARMOR->value,
                'description' => 'Rewards an armor identifier.',
            ],
            [
                'label' => 'Accessory',
                'value' => LootType::ACCESSORY->value,
                'description' => 'Rewards an accessory identifier.',
            ],
        ];
    }

    private function resolveEventOptionSelectionIndex(string $currentValue): int
    {
        foreach ($this->eventOptionDialogEntries as $index => $entry) {
            if (($entry["value"] ?? null) === $currentValue) {
                return $index;
            }
        }

        return 0;
    }

    private function buildEventOptionDialogRows(array $entries, int $availableRows): array
    {
        $rows = [];
        $selectedRowIndex = 0;

        foreach ($entries as $index => $entry) {
            if ($index === $this->selectedEventOptionIndex) {
                $selectedRowIndex = count($rows);
            }

            $rows[] = sprintf(
                "%s %s",
                $index === $this->selectedEventOptionIndex ? ">" : " ",
                (string) ($entry["label"] ?? ""),
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
        $categoryWidth = min($maximumCategoryWidth, $categoryContentWidth + 4)
                |> (fn($x) => min(24, $x))
                |> (fn($x) => max(18, $x));
        $listWidth = max(
            $minimumListWidth,
            min(24, $innerWidth - $categoryWidth - $minimumRightWidth - ($gutter * 2))
        );
        $rightWidth = max(30, $innerWidth - $categoryWidth - $listWidth - ($gutter * 2));
        $topHeight = $this->isClassesDatabaseSelected()
            ? 17
            : ($this->isSkillsDatabaseSelected() || $this->isQuestsDatabaseSelected() ? 18 : ($this->isActorsDatabaseSelected() ? 12 : 10));
        $framesWidth = $this->isClassesDatabaseSelected()
            ? 24
            : ($this->isSkillsDatabaseSelected() || $this->isQuestsDatabaseSelected() ? 30 : ($this->isActorsDatabaseSelected() ? 18 : 10));
        $previewWidth = max(20, $rightWidth - $framesWidth - $gutter);
        $previewHeight = max(8, $innerHeight - $topHeight - $gutter);
        $settingsWidth = intdiv($rightWidth - $gutter, 2)
                |> (fn($x) => min(28, $x))
                |> (fn($x) => max(18, $x));
        $cueWidth = $rightWidth - $settingsWidth - $gutter;

        if ($cueWidth < 18) {
            $cueWidth = 18;
            $settingsWidth = max(18, $rightWidth - $cueWidth - $gutter);
        }
        if ($this->isSkillsDatabaseSelected() || $this->isQuestsDatabaseSelected()) {
            $settingsWidth = max(34, min($rightWidth - 22 - $gutter, 38));
            $cueWidth = max(22, $rightWidth - $settingsWidth - $gutter);
            $framesWidth = max(30, min($rightWidth - 24 - $gutter, 34));
            $previewWidth = max(24, $rightWidth - $framesWidth - $gutter);
            if ($cueWidth < 22) {
                $cueWidth = 22;
                $settingsWidth = max(30, $rightWidth - $cueWidth - $gutter);
            }
            if ($previewWidth < 24) {
                $previewWidth = 24;
                $framesWidth = max(24, $rightWidth - $previewWidth - $gutter);
            }
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
        $this->databaseScreen->drawAll($this->resolveDatabaseLayout($layout), true);
    }

    /**
     * Marks the whole Database overlay for repainting on the next render pass.
     *
     * @param bool $includeRoot Whether to include the outer Database frame.
     * @return void
     */
    private function renderDatabaseArea(bool $includeRoot = false): void
    {
        $this->databaseScreen->markAllDirty($includeRoot);
    }

    /**
     * Paints the whole Database overlay immediately (full-redraw path).
     *
     * @param bool $includeRoot Whether to include the outer Database frame.
     * @return void
     */
    private function drawDatabaseArea(bool $includeRoot = false): void
    {
        $this->databaseScreen->drawAll(includeRoot: $includeRoot);
    }

    /**
     * Marks panes whose visual state depends on Database focus.
     *
     * @return void
     */
    private function renderDatabaseFocusDependentArea(): void
    {
        $this->markDatabasePanesDirty(['categories', 'list', 'settings', 'frames', 'preview']);
    }

    /**
     * Queues specific Database panes for repainting on the next render pass.
     *
     * @param string[] $panes The pane identifiers to repaint.
     * @param array<string, int>|null $layout Ignored; the flush recomputes the layout (kept for signature compatibility).
     * @param bool $includeRoot Whether to repaint the outer Database frame.
     * @return void
     */
    private function renderDatabasePanes(array $panes, ?array $layout = null, bool $includeRoot = false): void
    {
        $this->markDatabasePanesDirty($panes, $includeRoot);
    }

    /**
     * Unions panes into the Database dirty set.
     *
     * @param string[] $panes The pane identifiers to queue.
     * @param bool $includeRoot Whether to queue the outer Database frame.
     * @return void
     */
    private function markDatabasePanesDirty(array $panes, bool $includeRoot = false): void
    {
        $this->databaseScreen->markDirty($panes, $includeRoot);
    }

    /**
     * Paints specific Database panes without clearing the full editor.
     *
     * @param string[] $panes The pane identifiers to render.
     * @param array<string, int>|null $layout The optional Database layout.
     * @param bool $includeRoot Whether to render the outer Database frame.
     * @return void
     */
    private function drawDatabasePanes(array $panes, ?array $layout = null, bool $includeRoot = false): void
    {
        $this->databaseScreen->draw($panes, $layout, $includeRoot);
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
        $availableValueWidth = max(1, $settingsContentWidth - mb_strwidth($leftText));
        $visibleStart = max(0, $this->databaseEditCursorIndex - $availableValueWidth + 1);
        $visibleValue = mb_substr($this->databaseEditBuffer, $visibleStart, $availableValueWidth);
        $visibleCursorIndex = max(0, min($this->databaseEditCursorIndex - $visibleStart, mb_strlen($visibleValue)));
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
            help: 'Esc:Close  ?:Help  Ctrl+P:Palette  Ctrl+S:Save',
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
            $dirty = $this->isDatabaseCategoryDirty($category->key) ? ' *' : '';
            $lines[] = $prefix . $category->label . $dirty;
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
            help: $this->isActorsDatabaseSelected() || $this->isClassesDatabaseSelected() || $this->isSkillsDatabaseSelected() || $this->isQuestsDatabaseSelected() || $this->isAnimationsDatabaseSelected() ? "Shift+A:New" : "",
            position: ["x" => $layout["innerX"] + $layout["categoryWidth"] + $layout["gutter"], "y" => $layout["innerY"]],
            width: $layout["listWidth"],
            height: $layout["innerHeight"],
            foregroundColor: $this->resolveDatabasePaneColor(self::DATABASE_FOCUS_LIST),
            content: $this->fitLines(
                $this->getDatabaseListLines(),
                $this->getWindowContentWidth($layout["listWidth"]),
                $layout["innerHeight"] - 2
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
            title: $this->isActorsDatabaseSelected() ? "Collections" : ($this->isClassesDatabaseSelected() ? "Experience Curve" : ($this->isSkillsDatabaseSelected() ? "Effects" : ($this->isQuestsDatabaseSelected() ? "Objectives" : ($this->isSystemDatabaseSelected() ? "Battle Settings" : "SE and Flash Timing")))),
            help: $this->isQuestsDatabaseSelected() ? "Shift+O:Add  Shift+X:Del" : "",
            position: ["x" => $layout["innerX"] + $layout["categoryWidth"] + $layout["listWidth"] + $layout["settingsWidth"] + ($layout["gutter"] * 3), "y" => $layout["innerY"]],
            width: $layout["cueWidth"],
            height: $layout["topHeight"],
            foregroundColor: Color::WHITE,
            content: $this->fitLines(
                $this->getDatabaseCueLines(),
                $this->getWindowContentWidth($layout["cueWidth"]),
                $layout["topHeight"] - 2
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
            title: $this->isActorsDatabaseSelected() ? "Stats" : ($this->isClassesDatabaseSelected() ? "Stat Curves" : ($this->isSkillsDatabaseSelected() ? "Scope" : ($this->isQuestsDatabaseSelected() ? "Rewards" : ($this->isSystemDatabaseSelected() ? "Notes" : "Frames")))),
            help: $this->isActorsDatabaseSelected() || $this->isClassesDatabaseSelected() || $this->isSkillsDatabaseSelected() || $this->isQuestsDatabaseSelected() || $this->isSystemDatabaseSelected() ? "" : "Up/Down:Frame",
            position: ["x" => $layout["innerX"] + $layout["categoryWidth"] + $layout["listWidth"] + ($layout["gutter"] * 2), "y" => $layout["innerY"] + $layout["topHeight"] + $layout["gutter"]],
            width: $layout["framesWidth"],
            height: $layout["previewHeight"],
            foregroundColor: $this->resolveDatabasePaneColor(self::DATABASE_FOCUS_FRAMES),
            content: $this->fitLines(
                $this->getDatabaseFrameLines(),
                $this->getWindowContentWidth($layout["framesWidth"]),
                $layout["previewHeight"] - 2
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
            title: "Preview",
            help: $this->isActorsDatabaseSelected() || $this->isClassesDatabaseSelected() || $this->isSkillsDatabaseSelected() || $this->isQuestsDatabaseSelected() || $this->isSystemDatabaseSelected() ? "" : "Type:Paint  Shift+P:Play",
            position: ["x" => $layout["innerX"] + $layout["categoryWidth"] + $layout["listWidth"] + $layout["framesWidth"] + ($layout["gutter"] * 3), "y" => $layout["innerY"] + $layout["topHeight"] + $layout["gutter"]],
            width: $layout["previewWidth"],
            height: $layout["previewHeight"],
            foregroundColor: $this->resolveDatabasePaneColor(self::DATABASE_FOCUS_PREVIEW),
            content: ($this->isActorsDatabaseSelected() || $this->isClassesDatabaseSelected() || $this->isSkillsDatabaseSelected() || $this->isQuestsDatabaseSelected() || $this->isSystemDatabaseSelected())
                ? $this->fitLines(
                    $this->getDatabasePreviewLines(),
                    $this->getWindowContentWidth($layout["previewWidth"]),
                    $layout["previewHeight"] - 2
                )
                : array_fill(0, max(1, $layout["previewHeight"] - 2), ""),
        );
    }

    /**
     * Returns the list lines for the active Database category.
     *
        if ($this->isSkillsDatabaseSelected()) {
            return $this->getDatabaseSkillCueLines();
        }

     * @return string[]
     */
    private function getDatabaseListLines(): array
    {
        if ($this->isActorsDatabaseSelected()) {
            return $this->getDatabaseActorListLines();
        }

        if ($this->isClassesDatabaseSelected()) {
            return $this->getDatabaseClassListLines();
        }

        if ($this->isSkillsDatabaseSelected()) {
            return $this->getDatabaseSkillListLines();
        }

        if ($this->isQuestsDatabaseSelected()) {
            return $this->getDatabaseQuestListLines();
        }

        if ($this->isSystemDatabaseSelected()) {
            return $this->getDatabaseSystemListLines();
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
     * Returns the class list lines.
     *
     * @return string[]
     */
    private function getDatabaseClassListLines(): array
    {
        $classes = $this->workspace?->classDatabase->getClasses() ?? [];

        if ($classes === []) {
            return ['No classes yet.', '', 'Shift+A to create one.'];
        }

        $lines = [];

        foreach ($classes as $index => $class) {
            $prefix = $index === $this->databaseSelectedClassIndex ? '> ' : '  ';
            $dirty = $class->isDirty() ? ' *' : '';
            $lines[] = sprintf('%s%04d %s%s', $prefix, $class->id, $class->getName(), $dirty);
        }

        return $lines;
    }
    /**
     * Returns the skill list lines.
     *
     * @return string[]
     */
    private function getDatabaseSkillListLines(): array
    {
        $skills = $this->workspace?->skillDatabase->getSkills() ?? [];

        if ($skills === []) {
            return ["No skills yet.", "", "Shift+A to create one."];
        }

        $lines = [];

        foreach ($skills as $index => $skill) {
            $prefix = $index === $this->databaseSelectedSkillIndex ? "> " : "  ";
            $dirty = $skill->isDirty() ? " *" : "";
            $lines[] = sprintf("%s%04d %s%s", $prefix, $skill->id, $skill->getName(), $dirty);
        }

        return $lines;
    }


    /**
     * Returns the quest list lines.
     *
     * @return string[]
     */
    private function getDatabaseQuestListLines(): array
    {
        $quests = $this->workspace?->questDatabase->getQuests() ?? [];

        if ($quests === []) {
            return ['No quests yet.', '', 'Shift+A to create one.'];
        }

        $lines = [];

        foreach ($quests as $index => $quest) {
            $prefix = $index === $this->databaseSelectedQuestIndex ? '> ' : '  ';
            $dirty = $quest->isDirty() ? ' *' : '';
            $lines[] = sprintf('%s%s%s', $prefix, $quest->getName(), $dirty);
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
     * Returns the system list lines.
     *
     * @return string[]
     */
    private function getDatabaseSystemListLines(): array
    {
        $dirty = $this->workspace?->systemDatabase->isDirty() === true ? ' *' : '';

        return [sprintf('> Project System%s', $dirty)];
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
                $availableValueWidth = max(1, $this->getWindowContentWidth($this->resolveDatabaseLayout($this->resolveLayout())["settingsWidth"]) - mb_strwidth(sprintf("%s%s: ", $prefix, $field["label"] ?? "Field")));
                $visibleStart = max(0, $this->databaseEditCursorIndex - $availableValueWidth + 1);
                $value = mb_substr($this->databaseEditBuffer, $visibleStart, $availableValueWidth);
            }

            $lines[] = sprintf('%s%s: %s', $prefix, $field['label'] ?? 'Field', $value);
        }

        // Scroll the pane so the selected field stays visible; the edit
        // cursor row (min(selected, topHeight - 3)) already assumes this
        // window.
        $visibleRows = max(1, $this->resolveDatabaseLayout($this->resolveLayout())['topHeight'] - 2);

        return ScrollWindow::slice($lines, $this->databaseSelectedSettingIndex, $visibleRows);
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

        if ($this->isClassesDatabaseSelected()) {
            return $this->getDatabaseClassExperienceLines();
        }

        if ($this->isSkillsDatabaseSelected()) {
            return $this->getDatabaseSkillCueLines();
        }

        if ($this->isQuestsDatabaseSelected()) {
            return $this->getDatabaseQuestCueLines();
        }

        if ($this->isSystemDatabaseSelected()) {
            return $this->getDatabaseSystemCueLines();
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
     * Returns the system battle summary lines.
     *
     * @return string[]
     */
    private function getDatabaseSystemCueLines(): array
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return ['No system settings loaded.'];
        }

        $system = $this->workspace->systemDatabase;

        return [
            sprintf('Engine: %s', $system->getBattleEngine()),
            sprintf('ATB Mode: %s', $system->getAtbMode()),
            sprintf('Base Fill Rate: %d', $system->getAtbBaseFillRate()),
            sprintf('Speed Factor: %d%%', $system->getAtbSpeedFactorPercent()),
        ];
    }

    /**
     * Returns the class experience summary lines.
     *
     * @return string[]
     */
    private function getDatabaseClassExperienceLines(): array
    {
        $class = $this->getSelectedClass();

        if (! $class instanceof ProjectClass) {
            return ['No class selected.'];
        }

        $curve = $class->getExperienceCurve();

        return [
            sprintf('Base: %d', $curve['baseValue']),
            sprintf('Extra: %d', $curve['extraValue']),
            sprintf('Accel A: %d', $curve['accelerationA']),
            sprintf('Accel B: %d', $curve['accelerationB']),
            '',
            sprintf('Initial Lv: %d', $class->getInitialLevel()),
            sprintf('Max Lv: %d', $class->getMaxLevel()),
            sprintf('Traits: %d', count($class->getTraits())),
        ];
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
     * Returns the quest objective summary lines.
     *
     * @return string[]
     */
    private function getDatabaseQuestCueLines(): array
    {
        $quest = $this->getSelectedQuest();

        if (! $quest instanceof ProjectQuest) {
            return ['No quest selected.'];
        }

        return $quest->getObjectiveSummaryLines();
    }

    /**
     * Returns the skill effect summary lines.
     *
     * @return string[]
     */
    private function getDatabaseSkillCueLines(): array
    {
        $skill = $this->getSelectedSkill();

        if (! $skill instanceof ProjectSkill) {
            return ["No skill selected."];
        }

        return $skill->getEffectSummaryLines();
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

        if ($this->isClassesDatabaseSelected()) {
            return $this->getDatabaseClassCurveLines();
        }

        if ($this->isSystemDatabaseSelected()) {
            return $this->getDatabaseSystemFrameLines();
        }
        if ($this->isSkillsDatabaseSelected()) {
            return $this->getDatabaseSkillFrameLines();
        }

        if ($this->isQuestsDatabaseSelected()) {
            return $this->getDatabaseQuestFrameLines();
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
     * Returns the class curve summary lines.
     *
     * @return string[]
     */
    private function getDatabaseClassCurveLines(): array
    {
        $class = $this->getSelectedClass();

        if (! $class instanceof ProjectClass) {
            return ['No class selected.'];
        }

        $labelMap = [
            'totalHp' => 'HP',
            'totalMp' => 'MP',
            'attack' => 'ATK',
            'defence' => 'DEF',
            'magicAttack' => 'MAT',
            'magicDefence' => 'MDF',
            'speed' => 'SPD',
            'grace' => 'GRC',
            'evasion' => 'EVA',
        ];
        $lines = [];

        foreach ($class->getParameterCurves() as $key => $curve) {
            $lines[] = sprintf(
                '%-3s %d +%d / %d',
                $labelMap[$key] ?? strtoupper($key),
                $curve['baseValue'],
                $curve['extraGrowth'],
                $curve['flatIncrement'],
            );
        }

        return $lines;
    }

    /**
     * Returns the quest reward and prerequisite summary lines.
     *
     * @return string[]
     */
    private function getDatabaseQuestFrameLines(): array
    {
        $quest = $this->getSelectedQuest();

        if (! $quest instanceof ProjectQuest) {
            return ['No quest selected.'];
        }

        return [
            sprintf('Gold: %d', $quest->getRewardGold()),
            sprintf('EXP: %d', $quest->getRewardExperience()),
            sprintf('Items: %s', $quest->getRewardItemsString() === '' ? '-' : $quest->getRewardItemsString()),
            '',
            'Prereqs',
            ...$quest->getPrerequisiteSummaryLines(),
        ];
    }

    /**
     * Returns the skill scope summary lines.
     *
     * @return string[]
     */
    private function getDatabaseSkillFrameLines(): array
    {
        $skill = $this->getSelectedSkill();

        if (! $skill instanceof ProjectSkill) {
            return ["No skill selected."];
        }

        $scope = $skill->getScope();
        $invocation = $skill->getInvocation();

        return [
            sprintf("Side: %s", (string) ($scope["side"] ?? "Enemy")),
            sprintf("Number: %s", (string) ($scope["number"] ?? "One")),
            sprintf("Status: %s", (string) ($scope["status"] ?? "Alive")),
            sprintf("Targets: %s", ($scope["targetCount"] ?? null) === null ? "Auto" : (string) $scope["targetCount"]),
            "",
            sprintf("Occasion: %s", $skill->getOccasion()),
            sprintf("Repeat: %d", (int) ($invocation["repeat"] ?? 1)),
            sprintf("AP Gain: %d", (int) ($invocation["apGain"] ?? 10)),
        ];
    }

    /**
     * Returns system behavior notes.
     *
     * @return string[]
     */
    private function getDatabaseSystemFrameLines(): array
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return ['No system settings loaded.'];
        }

        $system = $this->workspace->systemDatabase;

        if ($system->getBattleEngine() !== 'active_time') {
            return [
                'Traditional turn-based battles.',
                'ATB settings are stored but inactive.',
                'Switch Battle Engine to active_time',
                'to enable gauge-driven turns.',
            ];
        }

        return [
            'Active Time Battle is enabled.',
            'Mode: wait',
            'This first slice uses wait-mode flow',
            'during command selection and resolution.',
        ];
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
                return ["No actor selected."];
            }

            $images = $actor->getImages();
            $battleLines = $actor->getBattleSpriteLines();

            return [
                sprintf("Actor ID: %s", $actor->id),
                sprintf("Field sprites: %d", count($images["field"] ?? [])),
                sprintf("Dialog portraits: %d", count($images["dialog"] ?? [])),
                "",
                "Battle Sprite",
                ...($battleLines !== [] ? $battleLines : ["(no battle sprite configured)"]),
            ];
        }

        if ($this->isClassesDatabaseSelected()) {
            return $this->getDatabaseClassPreviewLines();
        }

        if ($this->isSkillsDatabaseSelected()) {
            return $this->getDatabaseSkillPreviewLines();
        }

        if ($this->isQuestsDatabaseSelected()) {
            return $this->getDatabaseQuestPreviewLines();
        }

        if ($this->isSystemDatabaseSelected()) {
            return $this->getDatabaseSystemPreviewLines();
        }

        return [];
    }

    /**
     * Returns the preview lines for the selected quest.
     *
     * @return string[]
     */
    private function getDatabaseQuestPreviewLines(): array
    {
        $quest = $this->getSelectedQuest();

        if (! $quest instanceof ProjectQuest) {
            return ['No quest selected.'];
        }

        return [
            sprintf('Quest ID: %s', $quest->getId()),
            sprintf('Name: %s', $quest->getName()),
            sprintf('Giver: %s', $quest->getGiver() === '' ? '-' : $quest->getGiver()),
            sprintf('Objectives: %d', count($quest->getObjectives())),
            sprintf('Prereqs: %d', count($quest->getPrerequisites())),
            '',
            'Description',
            $quest->getDescription() === '' ? '(none)' : $quest->getDescription(),
            '',
            'Objectives',
            ...$quest->getObjectiveSummaryLines(),
        ];
    }

    /**
     * Returns the preview lines for the selected skill.
     *
     * @return string[]
     */
    private function getDatabaseSkillPreviewLines(): array
    {
        $skill = $this->getSelectedSkill();

        if (! $skill instanceof ProjectSkill) {
            return ["No skill selected."];
        }

        $scope = $skill->getScope();
        $invocation = $skill->getInvocation();
        $lines = [
            sprintf("Skill ID: %04d", $skill->id),
            sprintf("Name: %s", $skill->getName()),
            sprintf("Type: %s", ucfirst($skill->getType())),
            sprintf("Occasion: %s", $skill->getOccasion()),
            sprintf("Cost: %d MP", $skill->getCost()),
            sprintf("Cooldown: %d", $skill->getCooldown()),
            "",
            sprintf("Scope: %s / %s / %s", (string) ($scope["side"] ?? "Enemy"), (string) ($scope["number"] ?? "One"), (string) ($scope["status"] ?? "Alive")),
            sprintf("Invoke: %s", (string) ($invocation["message"] ?? "")),
            "",
            "Effects",
        ];

        return array_merge($lines, $skill->getEffectSummaryLines());
    }
    /**
     * Returns the preview lines for the system database.
     *
     * @return string[]
     */
    private function getDatabaseSystemPreviewLines(): array
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return ['No system settings loaded.'];
        }

        $system = $this->workspace->systemDatabase;
        $engine = $system->getBattleEngine();

        if ($engine === 'active_time') {
            return [
                'Battle Engine',
                'Active Time Battle',
                '',
                sprintf('Mode: %s', $system->getAtbMode()),
                sprintf('Base Fill Rate: %d', $system->getAtbBaseFillRate()),
                sprintf('Speed Factor: %d%%', $system->getAtbSpeedFactorPercent()),
                '',
                'This engine fills battler gauges',
                'continuously and resolves actions',
                'as battlers become ready.',
            ];
        }


        return [
            'Battle Engine',
            'Traditional Turn-Based',
            '',
            'Battlers act in a queued round order.',
            'ATB settings are ignored until you',
            'switch the project to active_time.',
        ];
    }

    /**
     * Returns the preview lines for the selected class.
     *
     * @return string[]
     */
    private function getDatabaseClassPreviewLines(): array
    {
        $class = $this->getSelectedClass();

        if (! $class instanceof ProjectClass) {
            return ['No class selected.'];
        }

        $experienceCurve = $class->getExperienceCurve();
        $experienceGenerator = new ExperienceCurveGenerator(
            baseValue: $experienceCurve['baseValue'],
            extraValue: $experienceCurve['extraValue'],
            accelerationA: $experienceCurve['accelerationA'],
            accelerationB: $experienceCurve['accelerationB'],
        );
        $hpCurve = $class->getParameterCurve('totalHp');
        $mpCurve = $class->getParameterCurve('totalMp');
        $attackCurve = $class->getParameterCurve('attack');
        $hpGenerator = new ParameterCurveGenerator(1, $hpCurve['baseValue'], $hpCurve['extraGrowth'], $hpCurve['flatIncrement']);
        $mpGenerator = new ParameterCurveGenerator(1, $mpCurve['baseValue'], $mpCurve['extraGrowth'], $mpCurve['flatIncrement']);
        $attackGenerator = new ParameterCurveGenerator(1, $attackCurve['baseValue'], $attackCurve['extraGrowth'], $attackCurve['flatIncrement']);
        $sampleLevels = [1, 10, 25, 50, 99];
        $lines = [
            sprintf('Class ID: %04d', $class->id),
            sprintf('Name: %s', $class->getName()),
            '',
            'Curve Samples',
        ];

        foreach ($sampleLevels as $level) {
            if ($level > $class->getMaxLevel()) {
                continue;
            }

            $lines[] = sprintf(
                'Lv%02d HP%-4d MP%-3d ATK%-3d EXP%-6d',
                $level,
                $hpGenerator->getValue($level),
                $mpGenerator->getValue($level),
                $attackGenerator->getValue($level),
                $experienceGenerator->getValue($level),
            );
        }

        return $lines;
    }

    /**
     * Returns whether a Database category holds unsaved changes.
     *
     * @param string $categoryKey The stable category key.
     * @return bool
     */
    private function isDatabaseCategoryDirty(string $categoryKey): bool
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return false;
        }

        return match ($categoryKey) {
            self::DATABASE_CATEGORY_ACTORS => $this->workspace->actorDatabase->isDirty(),
            self::DATABASE_CATEGORY_CLASSES => $this->workspace->classDatabase->isDirty(),
            self::DATABASE_CATEGORY_SKILLS => $this->workspace->skillDatabase->isDirty(),
            self::DATABASE_CATEGORY_ANIMATIONS => $this->workspace->animationDatabase->isDirty(),
            self::DATABASE_CATEGORY_SYSTEM => $this->workspace->systemDatabase->isDirty(),
            self::DATABASE_CATEGORY_QUESTS => $this->workspace->questDatabase->isDirty(),
            default => false,
        };
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

            $lines[] = $this->formatFieldLine(
                $prefix,
                (string) ($field['label'] ?? 'Field'),
                $value,
                $this->isInspectorFieldInteractive($field),
            );
        }

        // Keep the selected field inside the pane (the shared dialog-scroll
        // algorithm); the edit cursor row formula already assumes it.
        $visibleRows = max(1, $this->resolveLayout()['contentHeight'] - 2);

        return ScrollWindow::slice($lines, $this->selectedInspectorFieldIndex, $visibleRows);
    }

    /**
     * Returns whether an inspector field reacts to Enter or Left/Right.
     *
     * @param array<string, mixed> $field The inspector field descriptor.
     * @return bool
     */
    private function isInspectorFieldInteractive(array $field): bool
    {
        return $this->getInspectorFieldControl($field) instanceof InputControl
            || ($field['editable'] ?? null) === true;
    }

    /**
     * Formats one settings/inspector row, styling visible-but-fixed rows
     * distinctly from editable ones (they drop the `label: value` idiom).
     *
     * @param string $prefix The selection prefix.
     * @param string $label The field label.
     * @param string $value The display value.
     * @param bool $isEditable Whether the row reacts to input.
     * @return string
     */
    private function formatFieldLine(string $prefix, string $label, string $value, bool $isEditable): string
    {
        if (! $isEditable) {
            return rtrim(sprintf('%s%s%s', $prefix, $label, $value === '' ? '' : ' · ' . $value));
        }

        return sprintf('%s%s: %s', $prefix, $label, $value);
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

        $this->canvasPanel->markDirty();
        $this->inspectorPanel->markDirty();
        $this->isFooterDirty = true;
        $this->areOverlaysDirty = true;
    }

    /**
     * Marks the footer for repainting on the next render pass.
     *
     * @return void
     */
    private function renderFooter(): void
    {
        if ($this->isDatabaseOpen) {
            $this->renderDatabaseArea(includeRoot: true);
            return;
        }

        $this->isFooterDirty = true;
    }

    /**
     * Paints the footer (window plus severity-colored status segment).
     *
     * @return void
     */
    private function drawFooter(): void
    {
        Console::cursor()->hide();
        $this->createFooterWindow()->render();
        $this->renderFooterStatusColor();
    }

    /**
     * Repaints the status segment of the footer in its severity color.
     *
     * The footer window itself writes plain text (colored content would break
     * its width math), so the typed color is layered on afterwards.
     *
     * @return void
     */
    private function renderFooterStatusColor(): void
    {
        if ($this->statusLevel === StatusLevel::INFO) {
            return;
        }

        $layout = $this->resolveLayout();
        $contentWidth = $this->getWindowContentWidth($layout['width'] - 2);
        $prefix = sprintf(
            'Cursor: (%d, %d) | Viewport: (%d, %d) | ',
            $this->cursorX,
            $this->cursorY,
            $this->canvasOffsetX,
            $this->canvasOffsetY,
        );
        $prefixWidth = mb_strwidth($prefix);

        if ($prefixWidth >= $contentWidth) {
            return;
        }

        $visibleStatus = mb_strimwidth($this->getFooterStatusText(), 0, $contentWidth - $prefixWidth, '');

        if ($visibleStatus === '') {
            return;
        }

        Console::cursor()->moveTo(
            2 + 1 + self::WINDOW_HORIZONTAL_PADDING + $prefixWidth,
            5 + $layout['contentHeight'] + $layout['gutter'] + 2,
        );
        echo $this->statusLevel->color()->value . $visibleStatus . Color::RESET->value;
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

        $this->inspectorPanel->markDirty();
        $this->isFooterDirty = true;
        $this->areOverlaysDirty = true;
    }

    /**
     * Re-renders the panes affected by selection or mode changes.
     *
     * @return void
     */
    private function renderSelectionDependentArea(): void
    {
        $this->renderFocusDependentArea();
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

        $this->assetsPanel->markDirty();
        $this->canvasPanel->markDirty();
        $this->inspectorPanel->markDirty();
        $this->isFooterDirty = true;
        $this->areOverlaysDirty = true;
    }

    /**
     * Renders whichever safety modal is open (detail, guard, rename confirm).
     *
     * These sit above every other surface, including the Database overlay.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return bool Whether a modal consumed the overlay pass.
     */
    private function renderModalOverlays(array $layout): bool
    {
        if ($this->isStatusDetailOpen) {
            $this->renderStatusDetailOverlay($layout);
            return true;
        }

        if ($this->isUnsavedChangesGuardOpen) {
            $this->renderUnsavedChangesGuardOverlay($layout);
            return true;
        }

        if ($this->isRenameConfirmationOpen) {
            $this->renderRenameConfirmationOverlay($layout);
            return true;
        }

        if ($this->isCommandPaletteOpen) {
            $this->renderCommandPaletteOverlay($layout);
            return true;
        }

        if ($this->isHelpOpen) {
            $this->renderHelpOverlay($layout);
            return true;
        }

        return false;
    }

    /**
     * Renders the `?` help overlay from the live binding tables.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderHelpOverlay(array $layout): void
    {
        $rows = $this->getHelpLines();
        $overlayWidth = min(68, max(52, $layout['width'] - 12));
        $overlayHeight = min(max(9, count($rows) + 2), max(9, $layout['height'] - 4));

        $window = new EditorWindow(
            title: 'Help — Key Bindings',
            help: 'Esc:Close',
            position: [
                'x' => max(2, intdiv($layout['width'] - $overlayWidth, 2)),
                'y' => max(2, intdiv($layout['height'] - $overlayHeight, 2)),
            ],
            width: $overlayWidth,
            height: $overlayHeight,
            foregroundColor: Color::LIGHT_BLUE,
            content: $this->fitLines($rows, $this->getWindowContentWidth($overlayWidth), max(1, $overlayHeight - 2)),
        );

        $window->render();
    }

    /**
     * Renders the Ctrl+P command palette overlay.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderCommandPaletteOverlay(array $layout): void
    {
        $filteredItems = $this->commandPalette->filteredItems();
        $overlayWidth = min(64, max(48, $layout['width'] - 16));
        $overlayHeight = min(18, max(8, $layout['height'] - 6));
        $visibleRows = max(1, $overlayHeight - 2 - 2);
        $rows = [
            sprintf('> %s_', $this->commandPalette->query),
            '',
        ];

        if ($filteredItems === []) {
            $rows[] = '  No matches.';
        } else {
            $itemRows = [];

            foreach ($filteredItems as $index => $item) {
                $prefix = $index === $this->commandPalette->selectedIndex ? '> ' : '  ';
                $hint = $item->hint === '' ? '' : sprintf('  [%s]', $item->hint);
                $itemRows[] = $prefix . $item->label . $hint;
            }

            $rows = [
                ...$rows,
                ...ScrollWindow::slice($itemRows, $this->commandPalette->selectedIndex, $visibleRows),
            ];
        }

        $window = new EditorWindow(
            title: 'Command Palette',
            help: 'Type:Filter  Enter:Run  Esc:Close',
            position: [
                'x' => max(2, intdiv($layout['width'] - $overlayWidth, 2)),
                'y' => max(2, intdiv($layout['height'] - $overlayHeight, 2)),
            ],
            width: $overlayWidth,
            height: $overlayHeight,
            foregroundColor: Color::LIGHT_BLUE,
            content: $this->fitLines($rows, $this->getWindowContentWidth($overlayWidth), max(1, $overlayHeight - 2)),
        );

        $window->render();
    }

    /**
     * Renders the detail overlay behind the latest warn/error status.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderStatusDetailOverlay(array $layout): void
    {
        $overlayWidth = min(72, max(48, $layout['width'] - 12));
        $contentWidth = $this->getWindowContentWidth($overlayWidth);
        $rows = [];

        foreach ($this->statusDetailLines as $line) {
            if (mb_strwidth($line) <= $contentWidth) {
                $rows[] = $line;
                continue;
            }

            foreach (explode("\n", wordwrap($line, max(1, $contentWidth), "\n", true)) as $wrapped) {
                $rows[] = $wrapped;
            }
        }

        $rows[] = '';
        $rows[] = 'Full log: logs/error.log';
        $overlayHeight = min(max(7, count($rows) + 2), max(7, $layout['height'] - 6));

        $window = new EditorWindow(
            title: $this->statusDetailTitle === '' ? 'Details' : $this->statusDetailTitle,
            help: 'Esc:Close',
            position: [
                'x' => max(2, intdiv($layout['width'] - $overlayWidth, 2)),
                'y' => max(2, intdiv($layout['height'] - $overlayHeight, 2)),
            ],
            width: $overlayWidth,
            height: $overlayHeight,
            foregroundColor: $this->statusLevel === StatusLevel::ERROR ? Color::LIGHT_RED : Color::YELLOW,
            content: $this->fitLines($rows, $contentWidth, max(1, $overlayHeight - 2)),
        );

        $window->render();
    }

    /**
     * Renders the unsaved-changes confirmation for quit/reload.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderUnsavedChangesGuardOverlay(array $layout): void
    {
        $actionLabel = $this->pendingGuardAction === self::GUARD_ACTION_QUIT ? 'quit' : 'reload the workspace';
        $dirtyMaps = [];

        foreach ($this->workspace?->maps ?? [] as $map) {
            if ($map->isDirty()) {
                $dirtyMaps[] = '  ' . $map->mapId;
            }
        }

        $dirtyDatabases = [];

        foreach ($this->getSaveableDatabases() as $label => $database) {
            if ($database->isDirty()) {
                $dirtyDatabases[] = '  ' . $label . ' database';
            }
        }

        $rows = [
            sprintf('You have unsaved changes. %s anyway?', ucfirst($actionLabel)),
            '',
            ...array_slice([...$dirtyMaps, ...$dirtyDatabases], 0, 8),
            '',
            'Y: Discard changes and continue',
            'S: Save everything first, then continue',
            'Enter/Esc/N: Cancel (default)',
        ];
        $overlayWidth = min(64, max(50, $layout['width'] - 12));
        $overlayHeight = min(max(9, count($rows) + 2), max(9, $layout['height'] - 6));

        $window = new EditorWindow(
            title: 'Unsaved Changes',
            help: 'Y:Discard  S:Save All  Esc:Cancel',
            position: [
                'x' => max(2, intdiv($layout['width'] - $overlayWidth, 2)),
                'y' => max(2, intdiv($layout['height'] - $overlayHeight, 2)),
            ],
            width: $overlayWidth,
            height: $overlayHeight,
            foregroundColor: Color::YELLOW,
            content: $this->fitLines($rows, $this->getWindowContentWidth($overlayWidth), max(1, $overlayHeight - 2)),
        );

        $window->render();
    }

    /**
     * Renders the folder-move confirmation raised by a renaming save.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderRenameConfirmationOverlay(array $layout): void
    {
        $selectedMap = $this->getSelectedMap();
        $currentMapId = $selectedMap?->mapId ?? 'the current map';
        $targetMapId = $selectedMap instanceof ProjectMap ? $selectedMap->getSaveTarget()['mapId'] : 'a new location';
        $rows = [
            'Saving renames this map, which moves its folder:',
            '',
            sprintf('  From: %s', $currentMapId),
            sprintf('  To:   %s', $targetMapId),
            '',
            'The old folder is deleted after the new one is written.',
            '',
            'Y: Save and move',
            'Enter/Esc/N: Cancel (default)',
        ];
        $overlayWidth = min(64, max(50, $layout['width'] - 12));
        $overlayHeight = min(max(11, count($rows) + 2), max(11, $layout['height'] - 6));

        $window = new EditorWindow(
            title: 'Move Map Folder',
            help: 'Y:Save+Move  Esc:Cancel',
            position: [
                'x' => max(2, intdiv($layout['width'] - $overlayWidth, 2)),
                'y' => max(2, intdiv($layout['height'] - $overlayHeight, 2)),
            ],
            width: $overlayWidth,
            height: $overlayHeight,
            foregroundColor: Color::YELLOW,
            content: $this->fitLines($rows, $this->getWindowContentWidth($overlayWidth), max(1, $overlayHeight - 2)),
        );

        $window->render();
    }

    /**
     * Renders transient overlays and the live cursor.
     *
     * @return void
     */
    private function renderOverlays(): void
    {
        $this->areOverlaysDirty = true;
    }

    /**
     * Paints transient overlays and the live cursor.
     *
     * @return void
     */
    private function drawOverlays(): void
    {
        $layout = $this->resolveLayout();
        Console::cursor()->hide();

        if ($this->renderModalOverlays($layout)) {
            return;
        }

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

        if ($this->isLootDialogOpen) {
            $this->renderLootDialogOverlay($layout);
            return;
        }

        if ($this->isEventOptionDialogOpen) {
            $this->renderEventOptionDialogOverlay($layout);
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
            help: '?:Help  Ctrl+P:Palette  ' . InputRouter::KEY_DATABASE_LABEL . ':Database',
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
                // The selected map (its row sits one below the tree header)
                // stays inside the pane via the shared scroll window.
                ScrollWindow::slice(
                    $this->workspace?->getAssetLines($this->selectedAssetIndex) ?? [],
                    $this->selectedAssetIndex + 1,
                    max(1, $layout['contentHeight'] - 2),
                ),
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
            default => '?:Help  Ctrl+P:Palette  Tab:Pane  Enter:Edit  Ctrl+S:Save  Ctrl+A:Save All  Ctrl+Z:Undo  Ctrl+Y:Redo  Ctrl+Q:Quit',
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
                    $this->getFooterStatusText()
                ),
            ], $contentWidth, 2),
        );
    }

    /**
     * Returns the footer status text, flagging queued toasts behind it.
     *
     * @return string
     */
    private function getFooterStatusText(): string
    {
        $pendingCount = $this->toasts->pendingCount();

        return $this->statusMessage . ($pendingCount > 0 ? sprintf(' (+%d queued)', $pendingCount) : '');
    }

    private function handleException(Throwable $e): void
    {
        // Discard any half-rendered frame so the terminal-restore sequences
        // below reach the terminal directly.
        $this->terminal->discardBufferedFrames();

        Console::disableMouseReporting();
        Console::cursor()->show();
        Console::restoreSettings();
        $this->terminal->leaveAlternateScreen();
        echo Color::RESET->value;
        Debug::error($e);
    }
}
