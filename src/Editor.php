<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Atatusoft\Termutil\Events\MouseEvent;
use Closure;
use Atatusoft\Termutil\IO\Console\Console;
use Atatusoft\Termutil\IO\Enumerations\Color;
use Atatusoft\Termutil\IO\Mouse\Enumerations\MouseButton;
use Ichiloto\Editor\Backup\BackupSettings;
use Ichiloto\Editor\Backup\BackupWriter;
use Ichiloto\Editor\Canvas\CanvasTool;
use Ichiloto\Editor\Canvas\Clipboard;
use Ichiloto\Editor\Canvas\ToolGeometry;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\DatabaseCategoryDefinition;
use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\SharedFileTransaction;
use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\QuestReferences;
use Ichiloto\Editor\Database\AffinityEditor;
use Ichiloto\Editor\Database\ConditionEditor;
use Ichiloto\Editor\Field\NpcInspector;
use Ichiloto\Editor\Field\NpcReferences;
use Ichiloto\Editor\Field\ProjectNpc;
use Ichiloto\Editor\Database\WorldWriteEditor;
use Ichiloto\Editor\Database\WorldWriteCodec;
use Ichiloto\Editor\Database\ElementAffinityCodec;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\RecordSubList;
use Ichiloto\Editor\Database\ReferencePicker;
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
use Ichiloto\Editor\Navigation\NavigationEntry;
use Ichiloto\Editor\Playtest\PlaytestLauncher;
use Ichiloto\Editor\Playtest\PlaytestOverlay;
use Ichiloto\Editor\Navigation\NavigationStack;
use Ichiloto\Editor\Runtime\EditorLoop;
use Ichiloto\Editor\Runtime\TerminalHost;
use Ichiloto\Editor\Theme\EditorTheme;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Editor\Status\Toast;
use Ichiloto\Editor\Status\ToastQueue;
use Ichiloto\Editor\UI\AssetsPanel;
use Ichiloto\Editor\UI\CanvasPanel;
use Ichiloto\Editor\UI\CommandPalette;
use Ichiloto\Editor\UI\DatabaseScreen;
use Ichiloto\Editor\UI\InspectorPanel;
use Ichiloto\Editor\UI\ListFilter;
use Ichiloto\Editor\UI\Modal;
use Ichiloto\Editor\UI\ModalStack;
use Ichiloto\Editor\UI\PaletteItem;
use Ichiloto\Editor\UI\ScrollWindow;
use Ichiloto\Editor\UI\SettingsPaneLayout;
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
    private const string MODE_NPC = 'npc';
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
    /**
     * The maximum number of go-to-definition origins retained per session.
     */
    private const int NAVIGATION_CAPACITY = 50;
    /**
     * The brush widths Ctrl+W cycles through. 1 is the historic single cell.
     */
    private const array BRUSH_SIZES = [1, 2, 3, 5];

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
    private EditorTheme $theme;
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
     * @var int|null The selected NPC's position in the map's collection,
     * meaningful in NPC mode.
     */
    private ?int $selectedNpcIndex = null;
    /**
     * @var array{mapIndex: int, npcIndex: int}|null An NPC picked up on the
     * canvas and awaiting a destination tile.
     */
    private ?array $npcMoveInProgress = null;
    private ?NpcInspector $npcInspector = null;
    /**
     * @var array{x: int, y: int}|null The tile a new NPC is being named for;
     * the name typed here is what its stable id derives from, once.
     */
    private ?array $npcCreationInProgress = null;
    private string $npcNameBuffer = '';
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
    /**
     * The row the help overlay scrolls to keep visible.
     */
    private int $helpScrollRow = 0;
    private string $selectedPaintSymbol = ' ';
    /**
     * The active canvas tool. Every tool commits through one
     * PaintStrokeCommand, so shapes and fills undo in a single step.
     */
    private CanvasTool $canvasTool = CanvasTool::BRUSH;
    /**
     * The square brush width in cells (Ctrl+W cycles it).
     */
    private int $canvasBrushSize = 1;
    /**
     * @var array{x: int, y: int}|null The first corner of a two-point tool gesture.
     */
    private ?array $canvasToolAnchor = null;
    /**
     * @var array{x: int, y: int, width: int, height: int}|null The completed rectangular selection.
     */
    private ?array $canvasSelection = null;
    /**
     * The block of symbols lifted by copy/cut, stamped by paste.
     */
    private readonly Clipboard $clipboard;
    /**
     * The incremental `/` filter narrowing the Assets list.
     */
    private readonly ListFilter $assetFilter;
    /**
     * The incremental `/` filter narrowing the Database entry list.
     */
    private readonly ListFilter $databaseFilter;
    /**
     * The incremental `/` filter narrowing whichever picker dialog is open.
     */
    private readonly ListFilter $dialogFilter;
    /**
     * The bounded back-stack behind go-to-definition (Ctrl+G) and Back (Ctrl+B).
     */
    private readonly NavigationStack $navigation;
    /**
     * The opt-in pre-save backup writer.
     */
    private readonly BackupWriter $backups;
    /**
     * @var array{category: string, index: int, label: string}|null The database entry awaiting delete confirmation.
     */
    private ?array $pendingDatabaseDeletion = null;
    private bool $isDatabaseEntryDeleteConfirmationOpen {
        get => $this->modals->has(Modal::DATABASE_ENTRY_DELETE_CONFIRMATION);
        set {
            $value
                ? $this->modals->push(Modal::DATABASE_ENTRY_DELETE_CONFIRMATION)
                : $this->modals->remove(Modal::DATABASE_ENTRY_DELETE_CONFIRMATION);
        }
    }
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
     * @var ReferencePicker Choosing what a reference field points at.
     */
    private readonly ReferencePicker $referencePicker;
    private readonly ConditionEditor $conditionEditor;
    private readonly AffinityEditor $affinityEditor;
    private readonly WorldWriteEditor $worldWriteEditor;
    private const string WORLD_WRITE_NAME_FIELD = '__world_write_name';
    private bool $isWorldWriteNaming = false;
    private string $worldWriteNameBuffer = '';
    private bool $worldWriteNamingValue = false;
    /**
     * @var array<int, int|string> The open command frame, per the runtime's
     * own model: [] is the script, [2,'options',0,'then'] an option's arm.
     */
    private array $databaseCommandFramePath = [];
    /**
     * The settings-field id the picker carries when it was opened to choose
     * an affinity row's element.
     */
    private const string AFFINITY_ELEMENT_FIELD = '__affinity_element';
    /**
     * The settings-field id the picker carries when it was opened to name a
     * condition rather than to set a field.
     */
    private const string CONDITION_NAME_FIELD = '__condition_name';

    /**
     * The picker field id used when the map's NPC list is opened to select
     * one, rather than to fill a field.
     */
    private const string NPC_SELECT_FIELD = '__npc_select';

    /**
     * The row that chooses which natural variant the actor rows edit. It is
     * a view of the pane, not a value the project stores.
     */
    private const string ACTOR_VARIANT_FIELD = '__actor_variant';

    /**
     * @var array<string, string> Which variant each actor's rows are editing.
     */
    private array $actorVariantSelections = [];

    /**
     * The row that chooses which permanent growth the preview assumes the
     * party has earned. Earned growth lives in a save, not in a project, so
     * this is a fixture for looking at and nothing the editor writes.
     */
    private const string ACTOR_GROWTH_FIELD = '__actor_growth';

    /**
     * The row that chooses which kind of slot the Optimize preview fills.
     */
    private const string ACTOR_OPTIMIZE_SLOT_FIELD = '__actor_optimize_slot';

    /**
     * What the growth row reads when the preview assumes nothing was earned.
     */
    private const string NO_ASSUMED_GROWTH = '(none earned yet)';

    /**
     * What the growth row reads when the preview assumes all of it was.
     */
    private const string ALL_ASSUMED_GROWTH = '(everything defined)';

    /**
     * @var array<string, string> Which growth each actor's preview assumes.
     */
    private array $actorGrowthSelections = [];

    /**
     * @var array<string, string> Which slot each actor's Optimize preview fills.
     */
    private array $actorOptimizeSlots = [];

    /**
     * The Inspector row that assigns a stable id to an NPC authored without
     * one; the only time an id is ever written after creation.
     */
    private const string NPC_ASSIGN_ID_FIELD = '__npc_assign_id';
    private bool $isConditionNaming = false;
    private string $conditionNameBuffer = '';
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
    private string $databaseFocus = self::DATABASE_FOCUS_CATEGORIES;
    private int $databaseSelectedActorIndex = 0;
    private int $databaseSelectedClassIndex = 0;
    private int $databaseSelectedSkillIndex = 0;
    private int $databaseSelectedQuestIndex = 0;
    private int $databaseSelectedAnimationIndex = 0;
    /**
     * Selected entry index per schema-driven category, keyed by category key.
     *
     * The hand-written categories each own a field; the Phase 6 categories
     * share this map so adding a category costs a schema, not a field.
     *
     * @var array<string, int>
     */
    private array $databaseSelectedRecordIndexes = [];
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
     * @var bool|null The unsaved state the header was last painted with, so
     * the one always-visible indicator follows the truth on the tick it
     * changes -- after a save as much as after an edit -- without a full
     * repaint.
     */
    private ?bool $paintedHeaderUnsaved = null;
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
        $this->clipboard = new Clipboard();
        $this->assetFilter = new ListFilter();
        $this->databaseFilter = new ListFilter();
        $this->dialogFilter = new ListFilter();
        $this->navigation = new NavigationStack(self::NAVIGATION_CAPACITY);
        $this->backups = new BackupWriter(BackupSettings::fromProject($projectRoot), $projectRoot);
        $this->history = new CommandHistory(self::HISTORY_CAPACITY);
        $this->terminal = new TerminalHost(self::TERMINAL_SIZE_PROBE_INTERVAL_SECONDS);
        $this->theme = EditorTheme::default();
        $this->loop = new EditorLoop(self::FRAME_BUDGET_MICROSECONDS, $this->terminal);
        $this->inspectorFieldEditor = new TextFieldEditor();
        $this->databaseFieldEditor = new TextFieldEditor();
        $this->referencePicker = new ReferencePicker();
        $this->conditionEditor = new ConditionEditor();
        $this->affinityEditor = new AffinityEditor();
        $this->worldWriteEditor = new WorldWriteEditor();
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
        $this->applyProjectTheme();
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
        $this->databaseFocus = self::DATABASE_FOCUS_CATEGORIES;
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
        $this->setStatus(sprintf('Undid %s.', self::asPhrase($command->label)), StatusLevel::SUCCESS);
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
        $this->setStatus(sprintf('Redid %s.', self::asPhrase($command->label)), StatusLevel::SUCCESS);
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
    /**
     * Lowercases a history label's first letter for use mid-sentence,
     * leaving an acronym ("NPC move") as it is.
     *
     * @param string $label The label.
     * @return string The phrase.
     */
    private static function asPhrase(string $label): string
    {
        return preg_match('/^[A-Z]{2,}/', $label) === 1 ? $label : lcfirst($label);
    }

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
        $router->bindModal(Modal::DATABASE_ENTRY_DELETE_CONFIRMATION, $this->handleDatabaseEntryDeleteConfirmationInput(...));
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
            $this->isCapturingTypedText(...),
            $this->handleInlineTextInput(...),
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
            KeyBinding::exact("\x07", $this->goToDefinition(...), 'Ctrl+G', 'Go to definition (actor→class, skill→animation, event→map)'),
            KeyBinding::exact("\x02", $this->navigateBack(...), 'Ctrl+B', 'Back to the previous location'),
            KeyBinding::exact("\x14", $this->startPlaytest(...), 'Ctrl+T', 'Playtest the selected map from the cursor'),
            KeyBinding::when(
                fn(string $input): bool => $input === '/' && $this->focusedPane === self::FOCUS_ASSETS,
                $this->openAssetFilter(...),
                '/',
                'Filter the focused list (Assets, Database entries, pickers)',
            ),
            // Canvas tools. Every binding falls through unless the canvas
            // owns the keyboard, so the control bytes stay free elsewhere.
            KeyBinding::when(
                $this->isCanvasShortcut("\x0e"),
                fn() => $this->cycleCanvasTool(1),
                'Ctrl+N',
                'Canvas: next tool (Brush / Line / Rect / Rect Fill / Select)',
            ),
            KeyBinding::when(
                $this->isCanvasShortcut("\x17"),
                $this->cycleCanvasBrushSize(...),
                'Ctrl+W',
                'Canvas: cycle brush width (1 / 2 / 3 / 5)',
            ),
            // F3 rather than a control byte: every typeable Ctrl+letter is
            // taken by the editor or reserved by the tty driver (Ctrl+O is
            // the discard character on macOS and never reaches us), and a
            // printable glyph must stay paintable. F-keys are the family
            // F2 (Database) already established, in both encodings.
            KeyBinding::when(
                fn(string $input): bool => in_array($input, ["\033OR", "\033[13~"], true)
                    && $this->focusedPane === self::FOCUS_CANVAS
                    && $this->getSelectedMap() instanceof ProjectMap,
                fn() => $this->setEditingMode(
                    $this->editingMode === self::MODE_NPC ? self::MODE_MAP : self::MODE_NPC,
                ),
                'F3',
                'Canvas: toggle NPC mode (place and edit the map\'s NPCs)',
            ),
            KeyBinding::when(
                $this->isCanvasShortcut("\x06"),
                $this->floodFillFromCursor(...),
                'Ctrl+F',
                'Canvas: flood fill from the cursor (one undo step)',
            ),
            KeyBinding::when(
                $this->isCanvasShortcut("\x0b"),
                $this->pickSymbolUnderCursor(...),
                'Ctrl+K',
                'Canvas: eyedropper — pick up the symbol under the cursor',
            ),
            KeyBinding::when(
                $this->isCanvasShortcut("\x0c"),
                $this->copyCanvasSelection(...),
                'Ctrl+L',
                'Canvas: lift (copy) the selection',
            ),
            KeyBinding::when(
                $this->isCanvasShortcut("\x18"),
                $this->cutCanvasSelection(...),
                'Ctrl+X',
                'Canvas: cut the selection (one undo step)',
            ),
            KeyBinding::when(
                $this->isCanvasShortcut("\x15"),
                $this->pasteCanvasClipboard(...),
                'Ctrl+U',
                'Canvas: paste/stamp the clipboard at the cursor',
            ),
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
        // One key, one pane: the pane focused when the key arrives. Offering
        // every panel in turn let a handler that moved focus (creating an
        // NPC lands in the Inspector) hand the same key to the next pane,
        // which then began an edit nobody asked for.
        foreach ([$this->assetsPanel, $this->canvasPanel, $this->inspectorPanel] as $panel) {
            if ($panel->handleInput($input, $normalizedInput)) {
                return;
            }
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
            return;
        }

        if ($input === "\033" && $this->assetFilter->isActive()) {
            $this->clearAssetFilter();
        }
    }

    /**
     * Routes keystrokes captured by an inline text surface.
     *
     * Two surfaces capture the keyboard ahead of the global binding table:
     * the inspector's field editor and the Assets `/` filter caret. Routing
     * them through one gate keeps `?`, `/`, and the Ctrl+letter shortcuts
     * typable inside both.
     *
     * @param string $input The raw input token.
     * @return void
     */
    private function handleInlineTextInput(string $input): void
    {
        if ($this->isInspectorEditing) {
            $this->handleInspectorEditingInput($input);
            return;
        }

        if ($this->assetFilter->isCapturing) {
            $this->handleAssetFilterInput($input);
            return;
        }

        // Every other capture belongs to the focused pane's own handler: the
        // NPC name prompt and the map-local list on the canvas, the hosted
        // record pane's edit, picker and sub-editors in the Inspector.
        $this->handleFocusedPaneInput($input, strtolower($input));
    }

    /**
     * Determines whether typed text is being captured outside the Database
     * screen, so a glyph that is also a shortcut (?, %, ^, @) reaches the
     * text and not the shortcut.
     *
     * The Database screen is a modal and routes its own keys; this covers
     * the same captures where the Inspector hosts a record pane, plus the
     * canvas prompts of NPC mode.
     *
     * @return bool True while something is taking typed text.
     */
    private function isCapturingTypedText(): bool
    {
        if ($this->isInspectorEditing || $this->assetFilter->isCapturing || $this->npcCreationInProgress !== null) {
            return true;
        }

        if ($this->editingMode === self::MODE_NPC && $this->referencePicker->isOpen()) {
            return true;
        }

        return $this->isNpcInspectorHosting() && (
            $this->isDatabaseEditing
            || $this->conditionEditor->isOpen()
            || $this->worldWriteEditor->isOpen()
            || $this->affinityEditor->isOpen()
        );
    }

    /**
     * Opens the Assets list filter caret.
     *
     * @return void
     */
    private function openAssetFilter(): void
    {
        $this->assetFilter->open();
        $this->setStatus('Filter maps: type to narrow, Enter to keep, Esc to clear.');
        $this->renderSelectionDependentArea();
    }

    /**
     * Clears the Assets list filter and restores the full list.
     *
     * @return void
     */
    private function clearAssetFilter(): void
    {
        $this->assetFilter->clear();
        $this->syncSelectionToVisibleAssets();
        $this->setStatus('Map filter cleared.');
        $this->renderSelectionDependentArea();
    }

    /**
     * Handles keystrokes while the Assets filter caret is capturing.
     *
     * @param string $input The raw input token.
     * @return void
     */
    private function handleAssetFilterInput(string $input): void
    {
        if ($input === "\033") {
            $this->clearAssetFilter();
            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->assetFilter->commit();
            $this->setStatus(
                $this->assetFilter->query === ''
                    ? 'Map filter closed.'
                    : sprintf('Filtering maps by "%s".', $this->assetFilter->query),
            );
            $this->renderSelectionDependentArea();
            return;
        }

        if (str_contains($input, "\033[A")) {
            $this->moveSelection(-1);
            return;
        }

        if (str_contains($input, "\033[B")) {
            $this->moveSelection(1);
            return;
        }

        if ($input === "\177" || $input === "\010") {
            $this->assetFilter->backspace();
            $this->syncSelectionToVisibleAssets();
            $this->renderSelectionDependentArea();
            return;
        }

        if (str_contains($input, "\033") || $input === "\t") {
            return;
        }

        if (preg_match('/^\X$/u', $input) !== 1) {
            return;
        }

        $this->assetFilter->type($input);
        $this->syncSelectionToVisibleAssets();
        $this->renderSelectionDependentArea();
    }

    /**
     * Returns the workspace map indexes surviving the Assets filter.
     *
     * @return int[]
     */
    private function getVisibleAssetIndexes(): array
    {
        $mapIds = $this->workspace?->mapIds ?? [];

        if ($mapIds === [] || ! $this->assetFilter->isActive()) {
            return array_keys($mapIds);
        }

        /** @var int[] $indexes */
        $indexes = $this->assetFilter->apply($mapIds);

        return $indexes;
    }

    /**
     * Keeps the selected map inside the filtered list.
     *
     * @return void
     */
    private function syncSelectionToVisibleAssets(): void
    {
        $visible = $this->getVisibleAssetIndexes();

        if ($visible === [] || in_array($this->selectedAssetIndex, $visible, true)) {
            return;
        }

        $this->selectAsset($visible[0]);
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
        // A prompt or list open on the canvas in NPC mode owns every key,
        // the mode glyphs included: a name may contain a % or an @.
        if (
            $this->editingMode === self::MODE_NPC
            && ($this->npcCreationInProgress !== null || $this->referencePicker->isOpen())
            && $this->handleNpcModeInput($input)
        ) {
            return;
        }

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

        if ($this->editingMode === self::MODE_NPC && $this->handleNpcModeInput($input)) {
            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->applyCanvasToolAtCursor();
            return;
        }

        // Esc pops exactly one canvas level: a pending tool anchor first,
        // then the completed selection.
        if ($input === "\033" && $this->cancelCanvasToolState()) {
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
     * Handles Inspector input while it hosts the NPC pane.
     *
     * The keys are the Database settings pane's, because it is the same
     * pane: Enter edits or opens (a picker, a condition or write editor, a
     * command frame), Shift+O / Shift+X add and remove dialogue variants,
     * lines and commands, Esc pops a frame, arrows move and adjust.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleNpcInspectorInput(string $input, string $normalizedInput): void
    {
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

        if ($this->selectedNpcIndex === null) {
            return;
        }

        if ($input === "\033" && $this->databaseCommandFramePath !== []) {
            $this->leaveCommandFrame();

            return;
        }

        if ($input === "\n" || $input === "\r") {
            $current = $this->getInspectorFields()[$this->databaseSelectedSettingIndex] ?? null;

            if (is_array($current) && ($current['field'] ?? null) === self::NPC_ASSIGN_ID_FIELD) {
                $this->assignIdToSelectedNpc();

                return;
            }

            $this->beginDatabaseEdit();

            return;
        }

        if ($this->isShiftLetterShortcut($input, 'O')) {
            $this->addDatabaseNpcSubItem();

            return;
        }

        if ($this->isShiftLetterShortcut($input, 'X') || str_contains($input, "\033[3~")) {
            $this->removeDatabaseNpcSubItem();

            return;
        }

        if (str_contains($input, "\033[A") || str_contains($input, "\033[B")) {
            // Skip group headings: they are labels, not rows to land on.
            $fields = $this->getInspectorFields();
            $step = str_contains($input, "\033[A") ? -1 : 1;
            $index = $this->databaseSelectedSettingIndex;

            do {
                $index += $step;
            } while (isset($fields[$index]) && ($fields[$index]['editable'] ?? null) === false && ! isset($fields[$index]['frame']));

            if (isset($fields[$index])) {
                $this->databaseSelectedSettingIndex = $index;
            }

            $this->requestFullRender();

            return;
        }

        if (str_contains($input, "\033[D") || str_contains($input, "\033[C")) {
            $this->adjustDatabaseOptionField(str_contains($input, "\033[D") ? -1 : 1);

            return;
        }
    }

    /**
     * Adds a sub-item where the Inspector cursor points: a command inside
     * an open frame, an option on a choice, or a dialogue variant / line.
     *
     * @return void
     */
    private function addDatabaseNpcSubItem(): void
    {
        $map = $this->getSelectedMap();
        $index = $this->selectedNpcIndex;

        if (! $map instanceof ProjectMap || $index === null || $this->npcInspector === null) {
            return;
        }

        $records = $this->npcInspector->records();
        $before = $map->getNpcs();
        $selectedId = (string) ($this->getDatabaseSettingsFields()[$this->databaseSelectedSettingIndex]['field'] ?? '');

        $nested = $this->databaseCommandFramePath !== []
            ? $records->frameNestedContext($index, $this->databaseCommandFramePath, $selectedId)
            : null;

        if ($nested !== null) {
            // A route step under a command in the frame.
            $records->addFrameNestedItem($index, $this->databaseCommandFramePath, $nested['parentIndex']);
        } elseif ($this->databaseCommandFramePath !== []) {
            $after = preg_match('/^command(\\d+)/', $selectedId, $m) === 1 ? intval($m[1]) : null;
            $records->addFrameCommand($index, $this->databaseCommandFramePath, $after);
        } elseif (preg_match('/^variant(\\d+)Line/', $selectedId, $m) === 1) {
            $records->addNestedSubItem($index, intval($m[1]));
        } else {
            $records->addSubItem($index);
        }

        $this->npcInspector->commit();
        $this->recordNpcCollectionChange($map, $index, $before, 'NPC add');
    }

    /**
     * Removes the sub-item the Inspector cursor points at.
     *
     * @return void
     */
    private function removeDatabaseNpcSubItem(): void
    {
        $map = $this->getSelectedMap();
        $index = $this->selectedNpcIndex;

        if (! $map instanceof ProjectMap || $index === null || $this->npcInspector === null) {
            return;
        }

        $records = $this->npcInspector->records();
        $before = $map->getNpcs();
        $selectedId = (string) ($this->getDatabaseSettingsFields()[$this->databaseSelectedSettingIndex]['field'] ?? '');

        $nested = $this->databaseCommandFramePath !== []
            ? $records->frameNestedContext($index, $this->databaseCommandFramePath, $selectedId)
            : null;

        if ($nested !== null && $nested['nestedIndex'] !== null) {
            // The step under the cursor; a command row removes the command.
            $records->removeFrameNestedItem($index, $this->databaseCommandFramePath, $nested['parentIndex'], $nested['nestedIndex']);
        } elseif ($this->databaseCommandFramePath !== []) {
            if (preg_match('/^command(\\d+)/', $selectedId, $m) === 1) {
                $records->removeFrameCommand($index, $this->databaseCommandFramePath, intval($m[1]));
            }
        } elseif (preg_match('/^variant(\\d+)Line(\\d+)/', $selectedId, $m) === 1) {
            $records->removeNestedSubItem($index, intval($m[1]), intval($m[2]));
        } elseif (preg_match('/^variant(\\d+)/', $selectedId, $m) === 1) {
            $records->removeSubItem($index, intval($m[1]));
        } else {
            return;
        }

        $this->npcInspector->commit();
        $this->recordNpcCollectionChange($map, $index, $before, 'NPC remove');
    }

    /**
     * Records a structural NPC change as one undo step, when it changed
     * anything.
     *
     * @param ProjectMap $map The map.
     * @param int $index The NPC.
     * @param \Ichiloto\Editor\Field\NpcCollection $before The collection before.
     * @param string $label The history label.
     * @return void
     */
    private function recordNpcCollectionChange(ProjectMap $map, int $index, \Ichiloto\Editor\Field\NpcCollection $before, string $label): void
    {
        $after = $map->getNpcs();
        $this->refreshNpcInspector();

        if ($after->toMapData() === $before->toMapData()) {
            $this->requestFullRender();

            return;
        }

        $this->recordCommand(new GenericCommand(
            $label,
            function () use ($map, $after, $index): void {
                $map->setNpcs($after);
                $this->selectNpc($index);
            },
            function () use ($map, $before, $index): void {
                $map->setNpcs($before);
                $this->selectNpc($index);
            },
        ));
        $this->clampDatabaseSettingSelection();
        $this->requestFullRender();
    }

    /**
     * Determines whether the Inspector is hosting the NPC record pane.
     *
     * @return bool True in NPC mode with the Database closed.
     */
    private function isNpcInspectorHosting(): bool
    {
        return ! $this->isDatabaseOpen && $this->editingMode === self::MODE_NPC;
    }

    /**
     * Returns the selected NPC's record-pane fields, grouped and framed.
     *
     * @return array<int, array<string, mixed>> The field descriptors.
     */
    private function getNpcInspectorFields(): array
    {
        if ($this->npcInspector === null || $this->selectedNpcIndex === null) {
            return [];
        }

        $records = $this->npcInspector->records();
        $fields = $records->getFrameSettingsFields($this->selectedNpcIndex, $this->databaseCommandFramePath);

        if (
            $this->databaseCommandFramePath !== []
            && $records->getFrameCommands($this->selectedNpcIndex, $this->databaseCommandFramePath) === null
        ) {
            // The frame no longer resolves; an empty one still does.
            $this->databaseCommandFramePath = [];
            $fields = $records->getFrameSettingsFields($this->selectedNpcIndex, []);
        }

        if ($this->databaseCommandFramePath !== []) {
            return $fields;
        }

        // Group headings and honest notes, without changing any field id.
        $npc = $this->getSelectedMap()?->getNpcs()->get($this->selectedNpcIndex);
        $grouped = [];
        $group = static fn(string $title): array => ['label' => $title, 'value' => '', 'editable' => false];
        $notes = [];

        if ($npc !== null && $npc->getId() === null) {
            // Legacy entry: nothing can name an id it never had, so giving
            // it one is the one identity write that is safe after creation.
            $notes[] = [
                'label' => '  ! No stable id',
                'value' => 'move_route cannot target it; Enter assigns one from the name',
                'editable' => true,
                'field' => self::NPC_ASSIGN_ID_FIELD,
            ];
        }

        if ($npc !== null && $npc->scriptShadowsDialogue()) {
            $notes[] = ['label' => '  ! Script replaces dialogue', 'value' => 'the game runs the script', 'editable' => false];
        }

        if ($npc !== null && $npc->getUnknownFields() !== []) {
            $notes[] = ['label' => '  Preserved fields', 'value' => implode(', ', $npc->getUnknownFields()), 'editable' => false];
        }

        $sections = [
            'Identity' => ['id', 'name'],
            'Placement' => ['x', 'y'],
            'Appearance' => ['sprite', 'sprites.north', 'sprites.south', 'sprites.east', 'sprites.west'],
            'Movement' => ['movement', 'wanderArea.x', 'wanderArea.y', 'wanderArea.width', 'wanderArea.height'],
            'Visibility' => ['conditions'],
            'Interaction' => ['commandListScript'],
            'Completion Writes' => ['sets'],
        ];
        $byId = [];

        foreach ($fields as $field) {
            $byId[(string) ($field['field'] ?? '')][] = $field;
        }

        foreach ($sections as $title => $ids) {
            $rows = [];

            foreach ($ids as $id) {
                foreach ($byId[$id] ?? [] as $field) {
                    // Wander bounds only matter while wandering; loaded
                    // values are kept, just not shown for a fixed NPC.
                    if (! (str_starts_with($id, 'wanderArea.') && $npc !== null && ! $npc->wanders())) {
                        $rows[] = $field;
                    }
                }

                unset($byId[$id]);
            }

            if ($rows !== []) {
                $grouped[] = $group($title);
                $grouped = [...$grouped, ...$rows];
            }

            if ($title === 'Identity') {
                $grouped = [...$grouped, ...$notes];
            }

            if ($title === 'Interaction') {
                // Everything left is dialogue: variants, their lines, and
                // their frames, each variant under its own heading.
                [$variantRows, $byId] = $this->groupNpcVariantRows($byId);
                $grouped = [...$grouped, ...$variantRows];
            }
        }

        foreach ($byId as $rest) {
            $grouped = [...$grouped, ...$rest];
        }

        return $grouped;
    }

    /**
     * Turns the record pane's variant rows into headed groups: one
     * `Dialogue variant N` heading per variant (with its condition line
     * when it has one), then that variant's rows under short labels --
     * `When`, `Then Set`, `Script Commands`, `Line 1 Speaker`, `Line 1
     * Text` -- so the label no longer eats the pane before the value
     * starts. Field ids are untouched; this is the grouped view's
     * presentation of the record layer's own rows.
     *
     * @param array<string, array<int, array<string, mixed>>> $byId The remaining rows, keyed by field id.
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<int, array<string, mixed>>>} The headed rows, and what was left.
     */
    private function groupNpcVariantRows(array $byId): array
    {
        $singular = ucfirst(\Ichiloto\Editor\Database\RecordSchemaCatalog::mapNpcs()->subList?->singular ?? 'dialogue variant');
        $variants = [];

        foreach ($byId as $id => $rows) {
            if (preg_match('/^variant(\d+)/', $id, $matches) !== 1) {
                continue;
            }

            $variants[intval($matches[1])] = [...($variants[intval($matches[1])] ?? []), ...$rows];
            unset($byId[$id]);
        }

        ksort($variants);
        $headed = [];

        foreach ($variants as $number => $rows) {
            $prefix = sprintf('%s %d ', $singular, $number + 1);
            $when = '';

            foreach ($rows as $row) {
                if (($row['field'] ?? null) === sprintf('variant%dConditions', $number)) {
                    $when = trim((string) ($row['value'] ?? ''));
                }
            }

            // The condition line rides as the heading's value, so it reads
            // "Dialogue variant 2 · when …" and wraps rather than clips.
            $headed[] = [
                'label' => sprintf('%s %d', $singular, $number + 1),
                'value' => $when === '' ? '' : 'when ' . $when,
                'editable' => false,
            ];

            foreach ($rows as $row) {
                $label = (string) ($row['label'] ?? '');

                if (str_starts_with($label, $prefix)) {
                    $row['label'] = substr($label, strlen($prefix));
                }

                $headed[] = $row;
            }
        }

        return [$headed, $byId];
    }

    /**
     * Applies an NPC field edit through the record pane and the map, and
     * records it: the undo restores the whole previous collection, so list
     * position and every other field come back exactly.
     *
     * @param array<string, mixed> $field The field descriptor.
     * @param string $rawValue The raw value.
     * @return void
     */
    private function applyNpcFieldValueRecorded(array $field, string $rawValue): void
    {
        $map = $this->getSelectedMap();
        $index = $this->selectedNpcIndex;

        if (! $map instanceof ProjectMap || $index === null || $this->npcInspector === null) {
            return;
        }

        $before = $map->getNpcs();
        $fieldId = (string) ($field['field'] ?? '');
        $this->npcInspector->records()->setFrameField($index, $this->databaseCommandFramePath, $fieldId, $rawValue);
        $this->npcInspector->commit();
        $after = $map->getNpcs();

        if ($after->toMapData() === $before->toMapData()) {
            // A same-value edit: no history, no dirt.
            $this->refreshNpcInspector();

            return;
        }

        $this->refreshNpcInspector();
        $this->recordCommand(new GenericCommand(
            sprintf('NPC %s edit', $field['label'] ?? 'field'),
            function () use ($map, $after, $index): void {
                $map->setNpcs($after);
                $this->selectNpc($index);
            },
            function () use ($map, $before, $index): void {
                $map->setNpcs($before);
                $this->selectNpc($index);
            },
        ));
    }

    /**
     * Handles the keys NPC mode owns on the canvas.
     *
     * Enter selects the NPC under the cursor, or creates one there; M picks
     * the selected NPC up and the next Enter sets it down; D duplicates; Del
     * removes, reference-safely. Everything else falls through to ordinary
     * cursor movement -- painting is not what this mode is for, so typed
     * glyphs are ignored rather than silently painted under an NPC.
     *
     * @param string $input The raw input.
     * @return bool True when the key was consumed.
     */
    private function handleNpcModeInput(string $input): bool
    {
        $map = $this->getSelectedMap();

        if (! $map instanceof ProjectMap) {
            return false;
        }

        if ($this->referencePicker->isOpen()) {
            // The map's NPC list, opened from the canvas: the picker owns
            // the keys until it closes.
            $this->handleReferencePickerInput($input);

            return true;
        }

        if ($this->npcCreationInProgress !== null) {
            $this->handleNpcNameInput($input);

            return true;
        }

        if ($input === "\n" || $input === "\r") {
            if ($this->npcMoveInProgress !== null) {
                $this->finishNpcMove();

                return true;
            }

            $under = $map->getNpcs()->indexAt($this->cursorX, $this->cursorY);

            if ($under !== null) {
                $this->selectNpc($under);
                $this->setStatus(sprintf('Selected %s.', $this->describeSelectedNpc()));
                $this->renderCanvasArea();

                return true;
            }

            $this->beginNpcCreation();

            return true;
        }

        if ($input === "\033" && $this->npcMoveInProgress !== null) {
            $this->npcMoveInProgress = null;
            $this->setStatus('Move cancelled.');
            $this->renderCanvasArea();

            return true;
        }

        if ($input === 'm' || $input === 'M') {
            $this->beginNpcMove();

            return true;
        }

        if ($input === 'd' || $input === 'D') {
            $this->duplicateSelectedNpc();

            return true;
        }

        if ($input === 'l' || $input === 'L') {
            $this->openNpcList();

            return true;
        }

        if ($input === '[' || $input === ']') {
            $this->selectAdjacentNpc($input === '[' ? -1 : 1);

            return true;
        }

        if (str_contains($input, "\033[3~") || $input === "\x7f") {
            $this->deleteSelectedNpc();

            return true;
        }

        // Cursor movement passes through; a typed glyph does not paint here.
        if (mb_strlen($input) === 1 && ! ctype_cntrl($input)) {
            $this->setStatus('NPC mode does not paint. % for Map mode, ^ for Event mode.', StatusLevel::WARN);
            $this->renderFooter();

            return true;
        }

        return false;
    }

    /**
     * Selects an NPC by its position in the map's collection.
     *
     * @param int|null $index The position, or null for none.
     * @return void
     */
    private function selectNpc(?int $index): void
    {
        $this->selectedNpcIndex = $index;
        $this->selectedInspectorFieldIndex = 0;
        // Row 0 is the Identity heading; the Name is the first editable row.
        $this->databaseSelectedSettingIndex = 2;
        $this->refreshNpcInspector();
    }

    /**
     * Gives an NPC authored without a stable id one, derived from its name
     * and unique on its map, so movement routes can target it.
     *
     * Only an id-less NPC is eligible: an existing id is immutable, since
     * routes and diagnostics that name it would not follow a change.
     *
     * @return void
     */
    private function assignIdToSelectedNpc(): void
    {
        $map = $this->getSelectedMap();
        $index = $this->selectedNpcIndex;
        $npc = $index !== null ? $map?->getNpcs()->get($index) : null;

        if (! $map instanceof ProjectMap || $index === null || $npc === null) {
            return;
        }

        if ($npc->getId() !== null) {
            $this->setStatus(sprintf('%s already has the stable id "%s"; ids do not change.', $npc->getName(), $npc->getId()), StatusLevel::WARN);
            $this->renderFooter();

            return;
        }

        $before = $map->getNpcs();
        $id = $before->uniqueIdFor($npc->getName());
        $map->setNpcs($before->withReplaced($index, $npc->asCopyWithId($id)));
        $this->recordNpcCollectionChange($map, $index, $before, sprintf('Assign NPC id %s', $id));
        $this->setStatus(sprintf('Assigned the stable id "%s" to %s.', $id, $npc->getName()), StatusLevel::SUCCESS);
    }

    /**
     * Opens the map's NPCs as a list to select from.
     *
     * The canvas finds an NPC by walking to it; the list finds it by name
     * or id, typed to narrow, and jumps the cursor there. It is the same
     * picker every reference uses, over the map's own collection.
     *
     * @return void
     */
    private function openNpcList(): void
    {
        $map = $this->getSelectedMap();

        if (! $map instanceof ProjectMap) {
            return;
        }

        $labels = $this->npcListLabels($map);

        if ($labels === []) {
            $this->setStatus('This map has no NPCs yet. Enter on the canvas creates one.', StatusLevel::WARN);
            $this->renderFooter();

            return;
        }

        $current = $this->selectedNpcIndex !== null ? ($labels[$this->selectedNpcIndex] ?? '') : '';
        $this->referencePicker->open(self::NPC_SELECT_FIELD, 'NPCs on this map', 'map_npcs', $labels, $current);
        $this->requestFullRender();
    }

    /**
     * Returns one label per NPC, in collection order: name and stable id,
     * so two NPCs sharing a name still read apart.
     *
     * @param ProjectMap $map The map.
     * @return array<int, string> The labels, indexed like the collection.
     */
    private function npcListLabels(ProjectMap $map): array
    {
        $labels = [];

        foreach ($map->getNpcs()->all() as $index => $npc) {
            $id = $npc->getId();
            $labels[$index] = sprintf(
                '%s  (%s)',
                $npc->getName() === '' ? sprintf('NPC %d', $index + 1) : $npc->getName(),
                $id === null || $id === '' ? 'no id' : $id,
            );
        }

        return $labels;
    }

    /**
     * Selects the NPC picked from the list and puts the cursor on it.
     *
     * @param string|null $label The picked label.
     * @return void
     */
    private function selectNpcFromList(?string $label): void
    {
        $map = $this->getSelectedMap();

        if (! $map instanceof ProjectMap || $label === null) {
            $this->requestFullRender();

            return;
        }

        $index = array_search($label, $this->npcListLabels($map), true);

        if (! is_int($index)) {
            $this->requestFullRender();

            return;
        }

        $this->selectNpcAndJump($index);
    }

    /**
     * Selects the previous or next NPC in collection order, wrapping.
     *
     * @param int $step -1 for previous, 1 for next.
     * @return void
     */
    private function selectAdjacentNpc(int $step): void
    {
        $map = $this->getSelectedMap();

        if (! $map instanceof ProjectMap) {
            return;
        }

        $count = $map->getNpcs()->count();

        if ($count === 0) {
            $this->setStatus('This map has no NPCs yet. Enter on the canvas creates one.', StatusLevel::WARN);
            $this->renderFooter();

            return;
        }

        $index = $this->selectedNpcIndex === null
            ? ($step > 0 ? 0 : $count - 1)
            : (($this->selectedNpcIndex + $step + $count) % $count);

        $this->selectNpcAndJump($index);
    }

    /**
     * Selects an NPC and moves the canvas cursor onto its anchor tile.
     *
     * @param int $index The collection index.
     * @return void
     */
    private function selectNpcAndJump(int $index): void
    {
        $map = $this->getSelectedMap();
        $npc = $map?->getNpcs()->get($index);

        if (! $map instanceof ProjectMap || $npc === null) {
            return;
        }

        $this->selectNpc($index);
        $this->cursorX = max(0, min($map->getWidth() - 1, $npc->getX()));
        $this->cursorY = max(0, min($map->getHeight() - 1, $npc->getY()));
        $this->setStatus(sprintf('Selected %s.', $this->describeSelectedNpc()));
        $this->requestFullRender();
    }

    /**
     * Returns the directional sprite to preview on the canvas, if the
     * Inspector cursor rests on one.
     *
     * A `Facing …` glyph is only ever seen in the game once the NPC turns
     * that way; resting on its row shows it in place, so the author sees
     * what the turn will look like without leaving the editor.
     *
     * @return string|null The sprite as authored, or null for the base sprite.
     */
    private function previewedNpcSprite(): ?string
    {
        if (! $this->isNpcInspectorHosting() || $this->selectedNpcIndex === null || $this->focusedPane !== self::FOCUS_INSPECTOR) {
            return null;
        }

        if ($this->databaseCommandFramePath !== [] || $this->referencePicker->isOpen()) {
            return null;
        }

        $field = $this->getInspectorFields()[$this->databaseSelectedSettingIndex] ?? null;

        if (! is_array($field) || ! str_starts_with((string) ($field['field'] ?? ''), 'sprites.')) {
            return null;
        }

        $sprite = $this->isDatabaseEditing ? $this->databaseEditBuffer : (string) ($field['value'] ?? '');

        return trim($sprite) === '' ? null : $sprite;
    }

    /**
     * Rebuilds the NPC Inspector over the selected map's current NPCs.
     *
     * @return void
     */
    private function refreshNpcInspector(): void
    {
        $map = $this->getSelectedMap();
        $this->npcInspector = $map instanceof ProjectMap ? new NpcInspector($map) : null;

        if ($map instanceof ProjectMap && $this->selectedNpcIndex !== null && $map->getNpcs()->get($this->selectedNpcIndex) === null) {
            $this->selectedNpcIndex = $map->getNpcs()->count() > 0 ? min($this->selectedNpcIndex, $map->getNpcs()->count() - 1) : null;
        }
    }

    /**
     * Describes the selected NPC for a status line.
     *
     * @return string The description.
     */
    private function describeSelectedNpc(): string
    {
        $npc = $this->selectedNpcIndex !== null ? $this->getSelectedMap()?->getNpcs()->get($this->selectedNpcIndex) : null;

        if ($npc === null) {
            return 'no NPC';
        }

        return sprintf('%s (%s) at %d,%d', $npc->getName(), $npc->getId() ?? 'no id', $npc->getX(), $npc->getY());
    }

    /**
     * Opens the name prompt for a new NPC at the cursor.
     *
     * @return void
     */
    private function beginNpcCreation(): void
    {
        $map = $this->getSelectedMap();

        if (! $map instanceof ProjectMap) {
            return;
        }

        // The name is asked for first because the stable id derives from
        // it, once: an id minted from a placeholder would be "new-npc" for
        // every NPC ever created, and ids do not change afterwards.
        $this->npcCreationInProgress = ['x' => $this->cursorX, 'y' => $this->cursorY];
        $this->npcNameBuffer = '';
        $this->setStatus(sprintf('Name the new NPC at (%d, %d); Enter creates it, Esc cancels.', $this->cursorX, $this->cursorY));
        $this->requestFullRender();
    }

    /**
     * Takes the keys of the new-NPC name prompt.
     *
     * @param string $input The raw input.
     * @return void
     */
    private function handleNpcNameInput(string $input): void
    {
        if ($input === "\033" || $input === "\x1b") {
            $this->npcCreationInProgress = null;
            $this->npcNameBuffer = '';
            $this->setStatus('No NPC created.');
            $this->requestFullRender();

            return;
        }

        if ($input === "\n" || $input === "\r") {
            $tile = $this->npcCreationInProgress;
            $name = trim($this->npcNameBuffer);
            $this->npcCreationInProgress = null;
            $this->npcNameBuffer = '';

            if ($tile !== null) {
                $this->createNpcAt($tile['x'], $tile['y'], $name !== '' ? $name : 'New NPC');
            }

            return;
        }

        if ($input === "\x7f" || $input === "\x08") {
            $this->npcNameBuffer = mb_substr($this->npcNameBuffer, 0, max(0, mb_strlen($this->npcNameBuffer) - 1));
            $this->requestFullRender();

            return;
        }

        if (mb_strlen($input) === 1 && ! ctype_cntrl($input)) {
            $this->npcNameBuffer .= $input;
            $this->requestFullRender();
        }
    }

    /**
     * Returns the rows of the new-NPC name prompt, drawn in the Inspector.
     *
     * @return string[] The rows.
     */
    private function buildNpcNamePromptRows(): array
    {
        $tile = $this->npcCreationInProgress ?? ['x' => 0, 'y' => 0];
        $map = $this->getSelectedMap();
        $preview = trim($this->npcNameBuffer) !== '' && $map instanceof ProjectMap
            ? $map->getNpcs()->uniqueIdFor(trim($this->npcNameBuffer))
            : '';

        return [
            sprintf('New NPC at (%d, %d)', $tile['x'], $tile['y']),
            '',
            sprintf('Name: %s', $this->npcNameBuffer),
            $preview !== '' ? sprintf('Id:   %s', $preview) : 'Id:   (derived from the name, once)',
            '',
            '  Enter creates it, Esc cancels.',
        ];
    }

    /**
     * Creates a fixed NPC at a tile under a stable id derived from its name.
     *
     * @param int $x The anchor column.
     * @param int $y The anchor row.
     * @param string $name The display name.
     * @return void
     */
    private function createNpcAt(int $x, int $y, string $name): void
    {
        $map = $this->getSelectedMap();

        if (! $map instanceof ProjectMap) {
            return;
        }

        $collection = $map->getNpcs();
        $id = $collection->uniqueIdFor($name);
        $npc = ProjectNpc::createAt($id, $name, $x, $y);
        $index = $collection->count();
        $before = $collection;
        $after = $collection->withAdded($npc);

        $map->setNpcs($after);
        $this->selectNpc($index);
        $this->recordCommand(new GenericCommand(
            'NPC create',
            function () use ($map, $after, $index): void {
                $map->setNpcs($after);
                $this->selectNpc($index);
            },
            function () use ($map, $before): void {
                $map->setNpcs($before);
                $this->selectNpc(null);
            },
        ));
        $this->setStatus(sprintf('Created %s. Its id is %s.', $name, $id), StatusLevel::SUCCESS);
        $this->focusedPane = self::FOCUS_INSPECTOR;
        $this->requestFullRender();
    }

    /**
     * Picks the selected NPC up; the next Enter on the canvas sets it down.
     *
     * @return void
     */
    private function beginNpcMove(): void
    {
        if ($this->selectedNpcIndex === null) {
            $this->setStatus('Select an NPC first (Enter on it).', StatusLevel::WARN);
            $this->renderFooter();

            return;
        }

        $this->npcMoveInProgress = ['mapIndex' => $this->selectedAssetIndex, 'npcIndex' => $this->selectedNpcIndex];
        $this->setStatus(sprintf('Moving %s — move the cursor and press Enter, or Esc.', $this->describeSelectedNpc()));
        $this->renderFooter();
    }

    /**
     * Sets a picked-up NPC down at the cursor.
     *
     * @return void
     */
    private function finishNpcMove(): void
    {
        $pending = $this->npcMoveInProgress;
        $this->npcMoveInProgress = null;
        $map = $this->getSelectedMap();

        if ($pending === null || ! $map instanceof ProjectMap || $pending['mapIndex'] !== $this->selectedAssetIndex) {
            return;
        }

        $this->moveNpc($pending['npcIndex'], $this->cursorX, $this->cursorY);
    }

    /**
     * Moves an NPC to a tile, recorded for undo.
     *
     * @param int $index The NPC's position.
     * @param int $x The destination column.
     * @param int $y The destination row.
     * @return void
     */
    private function moveNpc(int $index, int $x, int $y): void
    {
        $map = $this->getSelectedMap();
        $collection = $map?->getNpcs();
        $npc = $collection?->get($index);

        if (! $map instanceof ProjectMap || $collection === null || $npc === null) {
            return;
        }

        if ($x < 0 || $y < 0 || $x >= $map->getWidth() || $y >= $map->getHeight()) {
            $this->setStatus(sprintf('%d,%d is outside the map.', $x, $y), StatusLevel::WARN);
            $this->renderFooter();

            return;
        }

        if ($npc->getX() === $x && $npc->getY() === $y) {
            $this->setStatus('Already there.');
            $this->renderFooter();

            return;
        }

        $occupant = $collection->indexAt($x, $y);

        if ($occupant !== null && $occupant !== $index) {
            $this->setStatus(sprintf('%s already stands at %d,%d.', $collection->get($occupant)?->getName() ?? 'An NPC', $x, $y), StatusLevel::WARN);
            $this->renderFooter();

            return;
        }

        $before = $collection;
        $after = $collection->withReplaced($index, $npc->movedTo($x, $y));
        $map->setNpcs($after);
        $this->refreshNpcInspector();
        $this->recordCommand(new GenericCommand(
            'NPC move',
            function () use ($map, $after, $index): void {
                $map->setNpcs($after);
                $this->selectNpc($index);
            },
            function () use ($map, $before, $index): void {
                $map->setNpcs($before);
                $this->selectNpc($index);
            },
        ));
        $this->setStatus(sprintf('Moved %s to %d,%d.', $npc->getName(), $x, $y), StatusLevel::SUCCESS);
        $this->requestFullRender();
    }

    /**
     * Duplicates the selected NPC under a fresh unique id, one tile to the
     * right when that tile is free.
     *
     * @return void
     */
    private function duplicateSelectedNpc(): void
    {
        $map = $this->getSelectedMap();
        $collection = $map?->getNpcs();
        $npc = $this->selectedNpcIndex !== null ? $collection?->get($this->selectedNpcIndex) : null;

        if (! $map instanceof ProjectMap || $collection === null || $npc === null) {
            $this->setStatus('Select an NPC first (Enter on it).', StatusLevel::WARN);
            $this->renderFooter();

            return;
        }

        $id = $collection->uniqueIdFor($npc->getName());
        $copy = $npc->asCopyWithId($id);
        $x = $npc->getX() + $npc->getSpriteWidth();

        if ($x < $map->getWidth() && $collection->indexAt($x, $npc->getY()) === null) {
            $copy = $copy->movedTo($x, $npc->getY());
        }

        $index = $collection->count();
        $before = $collection;
        $after = $collection->withAdded($copy);
        $map->setNpcs($after);
        $this->selectNpc($index);
        $this->recordCommand(new GenericCommand(
            'NPC duplicate',
            function () use ($map, $after, $index): void {
                $map->setNpcs($after);
                $this->selectNpc($index);
            },
            function () use ($map, $before): void {
                $map->setNpcs($before);
                $this->selectNpc(null);
            },
        ));
        $this->setStatus(sprintf('Duplicated as %s (id %s).', $copy->getName(), $id), StatusLevel::SUCCESS);
        $this->requestFullRender();
    }

    /**
     * Deletes the selected NPC, refusing while anything names its id.
     *
     * @return void
     */
    private function deleteSelectedNpc(): void
    {
        $map = $this->getSelectedMap();
        $collection = $map?->getNpcs();
        $index = $this->selectedNpcIndex;
        $npc = $index !== null ? $collection?->get($index) : null;

        if (! $map instanceof ProjectMap || $collection === null || $index === null || $npc === null || ! $this->workspace instanceof ProjectWorkspace) {
            $this->setStatus('Select an NPC first (Enter on it).', StatusLevel::WARN);
            $this->renderFooter();

            return;
        }

        $references = $npc->getId() !== null
            ? new NpcReferences($this->workspace)->describe($map, $npc->getId())
            : [];

        if ($references !== []) {
            // Refusing beats a route or script that silently stops
            // resolving. The list is what the author needs to go fix.
            $this->setStatus(
                sprintf('%s is named by %s — resolve those before deleting.', $npc->getName(), implode(', ', $references)),
                StatusLevel::ERROR,
                array_map(static fn(string $reference): string => '- ' . $reference, $references),
            );
            $this->renderFooter();

            return;
        }

        $before = $collection;
        $after = $collection->withRemoved($index);
        $map->setNpcs($after);
        $this->selectNpc(null);
        $this->recordCommand(new GenericCommand(
            'NPC delete',
            function () use ($map, $after): void {
                $map->setNpcs($after);
                $this->selectNpc(null);
            },
            function () use ($map, $before, $index): void {
                $map->setNpcs($before);
                $this->selectNpc($index);
            },
        ));
        $this->setStatus(sprintf('Deleted %s.', $npc->getName()), StatusLevel::SUCCESS);
        $this->requestFullRender();
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
        if ($this->isNpcInspectorHosting()) {
            $this->handleNpcInspectorInput($input, $normalizedInput);

            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->activateInspectorField();
            return;
        }

        if ($this->isShiftLetterShortcut($input, 'O')) {
            $this->addInspectorListItem();
            return;
        }

        if ($this->isShiftLetterShortcut($input, 'X') || str_contains($input, "\033[3~")) {
            $this->removeInspectorListItem();
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
        $this->databaseFocus = self::DATABASE_FOCUS_CATEGORIES;
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

        // The `/` filter caret captures the keyboard ahead of every screen
        // shortcut, so a query may contain `?`, `/`, or a stray control key.
        if ($this->databaseFilter->isCapturing) {
            $this->handleDatabaseFilterInput($input);
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
            $this->openDatabaseFilter();
            return;
        }

        if ($this->databaseFocus === self::DATABASE_FOCUS_LIST && str_contains($input, "\033[3~")) {
            $this->openDatabaseEntryDeleteConfirmation();
            return;
        }

        if ($input === "\033" && $this->databaseFilter->isActive()) {
            // Esc pops exactly one level: the live filter before the screen.
            $this->clearDatabaseFilter();
            return;
        }

        if ($input === "\033" && $this->databaseCommandFramePath !== []) {
            // Esc pops exactly one level: the open frame before the screen.
            $this->leaveCommandFrame();
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

        // Shift+O/Shift+X are the one sub-list idiom: quest objectives, skit
        // beats, troop members, and event-script commands all use them.
        if ($this->getSelectedRecordDatabase()?->schema->subList !== null && $this->isShiftLetterShortcut($input, 'O')) {
            $this->selectedDatabaseNestedContext() !== null
                ? $this->addDatabaseNestedSubItem()
                : $this->addDatabaseRecordSubItem();
            return;
        }

        if ($this->getSelectedRecordDatabase()?->schema->subList !== null && $this->isShiftLetterShortcut($input, 'X')) {
            $this->selectedDatabaseNestedContext() !== null
                ? $this->removeDatabaseNestedSubItem()
                : $this->removeDatabaseRecordSubItem();
            return;
        }

        if ($this->databaseFocus === self::DATABASE_FOCUS_SETTINGS && str_contains($input, "\033[3~")) {
            $this->removeDatabaseSubItem();
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
            return;
        }

        $this->moveDatabaseRecordSelection($step);
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

        $nextIndex = $this->resolveDatabaseSelectionStep($this->databaseSelectedActorIndex, $step);

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

        $nextIndex = $this->resolveDatabaseSelectionStep($this->databaseSelectedClassIndex, $step);

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

        $nextIndex = $this->resolveDatabaseSelectionStep($this->databaseSelectedSkillIndex, $step);

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

        $nextIndex = $this->resolveDatabaseSelectionStep($this->databaseSelectedQuestIndex, $step);

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

        $nextIndex = $this->resolveDatabaseSelectionStep($this->databaseSelectedAnimationIndex, $step);

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

        // Selection walks the *visible* list, so a `/` filter narrows what
        // the arrows can reach instead of stepping through hidden rows.
        $visible = $this->getVisibleAssetIndexes();

        if ($visible === []) {
            return;
        }

        $position = array_search($this->selectedAssetIndex, $visible, true);
        $position = is_int($position) ? $position : 0;
        $this->selectAsset($visible[max(0, min(count($visible) - 1, $position + $step))]);
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
        $this->npcMoveInProgress = null;
        $this->npcCreationInProgress = null;
        $this->npcNameBuffer = '';

        if ($mode === self::MODE_NPC) {
            // Land on the NPC under the cursor, or the first one, so the
            // Inspector has something to show at once.
            $this->selectedNpcIndex = $this->getSelectedMap()?->getNpcs()->indexAt($this->cursorX, $this->cursorY)
                ?? ($this->getSelectedMap()?->getNpcs()->count() > 0 ? 0 : null);
            $this->refreshNpcInspector();
        }

        $this->statusMessage = match ($mode) {
            self::MODE_EVENT => 'Event mode active.',
            self::MODE_NPC => 'NPC mode active. Enter selects or creates, M moves, D duplicates, L lists, Del removes.',
            default => 'Map mode active.',
        };
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
            $this->adoptPaintSymbol(' ');
            return;
        }

        $reservedSymbols = ['%', '^', '@'];

        if (in_array($symbol, $reservedSymbols, true)) {
            return;
        }

        $this->adoptPaintSymbol($symbol);
    }

    /**
     * Makes a typed glyph the active paint symbol.
     *
     * Under the brush this also paints it, which is the historic behaviour.
     * Under a shape or selection tool it only loads the brush: an anchored
     * gesture must not be interrupted by a stray dab where the cursor
     * happens to sit.
     *
     * @param string $symbol The typed symbol.
     * @return void
     */
    private function adoptPaintSymbol(string $symbol): void
    {
        $this->selectedPaintSymbol = $symbol;

        if ($this->canvasTool !== CanvasTool::BRUSH) {
            $this->setStatus(sprintf(
                'Paint symbol is now %s. %s',
                $symbol === ' ' ? 'space' : $symbol,
                $this->describeCanvasToolUsage(),
            ));
            $this->renderCanvasArea();
            return;
        }

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
            $this->adoptPaintSymbol(' ');
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

        // The brush footprint commits as one stroke, so a wide dab is still
        // a single undo step (width 1 is byte-for-byte the historic path).
        $this->paintCanvasCells(
            $selectedMap,
            ToolGeometry::brush($this->cursorX, $this->cursorY, $this->canvasBrushSize),
            $symbol,
            $isEventLayer ? 'Event edit' : 'Tile edit',
        );

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

        $points = ToolGeometry::expandByBrush(
            $this->interpolatePoints($start['x'], $start['y'], $targetX, $targetY),
            $this->canvasBrushSize,
        );

        foreach ($points as $point) {
            if (
                $point['x'] < 0 ||
                $point['y'] < 0 ||
                $point['x'] >= $selectedMap->getWidth() ||
                $point['y'] >= $selectedMap->getHeight()
            ) {
                continue;
            }

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
        return ToolGeometry::line($startX, $startY, $endX, $endY);
    }

    /**
     * Returns a predicate matching a canvas-only shortcut byte.
     *
     * The predicate refuses the key whenever the canvas does not own the
     * keyboard, so the binding falls through to the focused pane instead of
     * silently eating the keystroke.
     *
     * @param string $key The exact control byte.
     * @return Closure(string, string): bool
     */
    private function isCanvasShortcut(string $key): Closure
    {
        return fn(string $input): bool => $input === $key
            && $this->focusedPane === self::FOCUS_CANVAS
            && $this->getSelectedMap() instanceof ProjectMap;
    }

    /**
     * Returns the layer the canvas tools currently paint on.
     *
     * @return string A PaintStrokeCommand LAYER_* constant.
     */
    private function getActiveCanvasLayer(): string
    {
        return $this->editingMode === self::MODE_EVENT
            ? PaintStrokeCommand::LAYER_EVENT
            : PaintStrokeCommand::LAYER_TILE;
    }

    /**
     * Reads one cell from the active canvas layer.
     *
     * @param ProjectMap $map The map to read.
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @return string
     */
    private function readCanvasSymbol(ProjectMap $map, int $x, int $y): string
    {
        return $this->editingMode === self::MODE_EVENT
            ? $map->getEventSymbol($x, $y)
            : $map->getTileSymbol($x, $y);
    }

    /**
     * Writes one cell onto the active canvas layer.
     *
     * @param ProjectMap $map The map to write.
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $symbol The symbol to write.
     * @return void
     */
    private function writeCanvasSymbol(ProjectMap $map, int $x, int $y, string $symbol): void
    {
        if ($this->editingMode === self::MODE_EVENT) {
            $map->setEventSymbol($x, $y, $symbol);
            return;
        }

        $map->setTileSymbol($x, $y, $symbol);
    }

    /**
     * Applies a set of cell writes as ONE undoable stroke.
     *
     * This is the single commit path behind every canvas tool: a brush dab,
     * a line, a rectangle, a flood fill, a cut, and a paste all land as one
     * PaintStrokeCommand, so each undoes in exactly one Ctrl+Z.
     *
     * @param ProjectMap $map The target map.
     * @param array<int, array{x: int, y: int, symbol: string}> $writes The cells to write.
     * @param string $label The undo/status label.
     * @return int The number of cells that actually changed.
     */
    private function applyCanvasWrites(ProjectMap $map, array $writes, string $label): int
    {
        if ($writes === []) {
            return 0;
        }

        $this->finalizeActiveStroke();
        $stroke = new PaintStrokeCommand($map, $this->getActiveCanvasLayer(), $label);
        $changed = 0;

        foreach ($writes as $write) {
            if ($write['x'] < 0 || $write['y'] < 0 || $write['x'] >= $map->getWidth() || $write['y'] >= $map->getHeight()) {
                continue;
            }

            $oldSymbol = $this->readCanvasSymbol($map, $write['x'], $write['y']);
            $this->writeCanvasSymbol($map, $write['x'], $write['y'], $write['symbol']);
            $newSymbol = $this->readCanvasSymbol($map, $write['x'], $write['y']);
            $stroke->appendCell($write['x'], $write['y'], $oldSymbol, $newSymbol);

            if ($oldSymbol !== $newSymbol) {
                $changed++;
            }
        }

        if ($stroke->hasChanges()) {
            $this->recordCommand($stroke);
        }

        return $changed;
    }

    /**
     * Paints one symbol across a cell list as a single stroke.
     *
     * @param ProjectMap $map The target map.
     * @param array<int, array{x: int, y: int}> $cells The cells to paint.
     * @param string $symbol The symbol to paint.
     * @param string $label The undo/status label.
     * @return int The number of cells that actually changed.
     */
    private function paintCanvasCells(ProjectMap $map, array $cells, string $symbol, string $label): int
    {
        return $this->applyCanvasWrites(
            $map,
            array_map(
                static fn(array $cell): array => ['x' => $cell['x'], 'y' => $cell['y'], 'symbol' => $symbol],
                $cells,
            ),
            $label,
        );
    }

    /**
     * Cycles the active canvas tool.
     *
     * @param int $step The cycle direction.
     * @return void
     */
    private function cycleCanvasTool(int $step): void
    {
        $this->canvasTool = $this->canvasTool->cycle($step);
        $this->canvasToolAnchor = null;

        if ($this->canvasTool !== CanvasTool::SELECT) {
            $this->canvasSelection = null;
        }

        $this->setStatus(sprintf('%s tool. %s', $this->canvasTool->label(), $this->describeCanvasToolUsage()));
        $this->renderCanvasArea();
    }

    /**
     * Cycles the square brush width.
     *
     * @return void
     */
    private function cycleCanvasBrushSize(): void
    {
        $sizes = self::BRUSH_SIZES;
        $index = array_search($this->canvasBrushSize, $sizes, true);
        $index = is_int($index) ? $index : 0;
        $this->canvasBrushSize = $sizes[($index + 1) % count($sizes)];
        $this->setStatus(sprintf('Brush width %d.', $this->canvasBrushSize));
        $this->renderCanvasArea();
    }

    /**
     * Returns the one-line usage hint for the active tool.
     *
     * @return string
     */
    private function describeCanvasToolUsage(): string
    {
        return match ($this->canvasTool) {
            CanvasTool::BRUSH => 'Type or press Enter to paint.',
            CanvasTool::LINE,
            CanvasTool::RECTANGLE,
            CanvasTool::FILLED_RECTANGLE => 'Enter sets the anchor, Enter again draws.',
            CanvasTool::SELECT => 'Enter sets the anchor, Enter again selects; Ctrl+L copy, Ctrl+X cut, Ctrl+U paste.',
        };
    }

    /**
     * Returns the canvas status suffix describing tool, brush, and selection.
     *
     * @return string
     */
    private function describeCanvasToolState(): string
    {
        $state = sprintf('%s %d', $this->canvasTool->label(), $this->canvasBrushSize);

        if (is_array($this->canvasToolAnchor)) {
            $state .= sprintf(' @%d,%d', $this->canvasToolAnchor['x'], $this->canvasToolAnchor['y']);
        }

        if (is_array($this->canvasSelection)) {
            $state .= sprintf(' [%dx%d]', $this->canvasSelection['width'], $this->canvasSelection['height']);
        }

        if (! $this->clipboard->isEmpty()) {
            $state .= sprintf(' clip %dx%d', $this->clipboard->getWidth(), $this->clipboard->getHeight());
        }

        return $state;
    }

    /**
     * Runs the active tool at the cursor (the canvas Enter key).
     *
     * @return void
     */
    private function applyCanvasToolAtCursor(): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        if ($this->canvasTool === CanvasTool::BRUSH) {
            $this->applySelectedPaintSymbol();
            return;
        }

        if (! is_array($this->canvasToolAnchor)) {
            $this->canvasToolAnchor = ['x' => $this->cursorX, 'y' => $this->cursorY];
            $this->setStatus(sprintf(
                '%s anchored at (%d, %d). Move the cursor and press Enter again (Esc cancels).',
                $this->canvasTool->label(),
                $this->cursorX,
                $this->cursorY,
            ));
            $this->renderCanvasArea();
            return;
        }

        $anchor = $this->canvasToolAnchor;
        $this->canvasToolAnchor = null;

        if ($this->canvasTool === CanvasTool::SELECT) {
            $this->completeCanvasSelection($anchor);
            return;
        }

        $cells = match ($this->canvasTool) {
            CanvasTool::LINE => ToolGeometry::line($anchor['x'], $anchor['y'], $this->cursorX, $this->cursorY),
            CanvasTool::RECTANGLE => ToolGeometry::rectangleOutline($anchor['x'], $anchor['y'], $this->cursorX, $this->cursorY),
            CanvasTool::FILLED_RECTANGLE => ToolGeometry::rectangleFilled($anchor['x'], $anchor['y'], $this->cursorX, $this->cursorY),
            default => [],
        };

        // The brush thickens outlines and lines; a filled rectangle is
        // already solid, so widening it would only spill past the corners.
        if ($this->canvasTool !== CanvasTool::FILLED_RECTANGLE) {
            $cells = ToolGeometry::expandByBrush($cells, $this->canvasBrushSize);
        }

        $changed = $this->paintCanvasCells(
            $selectedMap,
            $cells,
            $this->selectedPaintSymbol,
            $this->canvasTool->commandLabel(),
        );

        $this->setStatus(sprintf(
            '%s: %d cell%s painted with %s.',
            $this->canvasTool->label(),
            $changed,
            $changed === 1 ? '' : 's',
            $this->selectedPaintSymbol === ' ' ? 'space' : $this->selectedPaintSymbol,
        ));
        $this->renderCanvasArea();
    }

    /**
     * Completes a rectangular selection between the anchor and the cursor.
     *
     * @param array{x: int, y: int} $anchor The selection anchor.
     * @return void
     */
    private function completeCanvasSelection(array $anchor): void
    {
        [$left, $top, $right, $bottom] = ToolGeometry::normalizeBounds($anchor['x'], $anchor['y'], $this->cursorX, $this->cursorY);
        $this->canvasSelection = [
            'x' => $left,
            'y' => $top,
            'width' => $right - $left + 1,
            'height' => $bottom - $top + 1,
        ];
        $this->setStatus(sprintf(
            'Selected %d x %d at (%d, %d). Ctrl+L copy, Ctrl+X cut, Ctrl+U paste.',
            $this->canvasSelection['width'],
            $this->canvasSelection['height'],
            $left,
            $top,
        ));
        $this->renderCanvasArea();
    }

    /**
     * Cancels the pending tool anchor, then the selection (the canvas Esc).
     *
     * @return bool Whether a level was popped.
     */
    private function cancelCanvasToolState(): bool
    {
        if (is_array($this->canvasToolAnchor)) {
            $this->canvasToolAnchor = null;
            $this->setStatus(sprintf('%s anchor cleared.', $this->canvasTool->label()));
            $this->renderCanvasArea();
            return true;
        }

        if (is_array($this->canvasSelection)) {
            $this->canvasSelection = null;
            $this->setStatus('Selection cleared.');
            $this->renderCanvasArea();
            return true;
        }

        return false;
    }

    /**
     * Flood-fills the contiguous region under the cursor (one undo step).
     *
     * @return void
     */
    private function floodFillFromCursor(): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        $target = $this->readCanvasSymbol($selectedMap, $this->cursorX, $this->cursorY);

        if ($target === $this->selectedPaintSymbol) {
            $this->setStatus('Flood fill skipped: the region already holds that symbol.', StatusLevel::WARN);
            $this->renderFooter();
            return;
        }

        $cells = ToolGeometry::floodFill(
            fn(int $x, int $y): string => $this->readCanvasSymbol($selectedMap, $x, $y),
            $selectedMap->getWidth(),
            $selectedMap->getHeight(),
            $this->cursorX,
            $this->cursorY,
        );
        $changed = $this->paintCanvasCells($selectedMap, $cells, $this->selectedPaintSymbol, 'flood fill');
        $this->setStatus(sprintf(
            'Flood filled %d cell%s with %s.',
            $changed,
            $changed === 1 ? '' : 's',
            $this->selectedPaintSymbol === ' ' ? 'space' : $this->selectedPaintSymbol,
        ));
        $this->renderCanvasArea();
    }

    /**
     * Picks up the symbol under the cursor as the active brush (eyedropper).
     *
     * @return void
     */
    private function pickSymbolUnderCursor(): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        $this->selectedPaintSymbol = $this->readCanvasSymbol($selectedMap, $this->cursorX, $this->cursorY);
        $this->setStatus(sprintf(
            'Picked up %s from (%d, %d).',
            $this->selectedPaintSymbol === ' ' ? 'space' : $this->selectedPaintSymbol,
            $this->cursorX,
            $this->cursorY,
        ));
        $this->renderCanvasArea();
    }

    /**
     * Lifts the selected region into the clipboard.
     *
     * @return void
     */
    private function copyCanvasSelection(): void
    {
        if ($this->captureCanvasSelection() === 0) {
            return;
        }

        $this->setStatus(sprintf(
            'Copied %d x %d. Ctrl+U stamps it at the cursor.',
            $this->clipboard->getWidth(),
            $this->clipboard->getHeight(),
        ));
        $this->renderCanvasArea();
    }

    /**
     * Lifts the selected region into the clipboard and clears it in one
     * undoable stroke.
     *
     * @return void
     */
    private function cutCanvasSelection(): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap || $this->captureCanvasSelection() === 0) {
            return;
        }

        $selection = $this->canvasSelection;
        $cells = ToolGeometry::rectangleFilled(
            $selection['x'],
            $selection['y'],
            $selection['x'] + $selection['width'] - 1,
            $selection['y'] + $selection['height'] - 1,
        );
        $changed = $this->paintCanvasCells($selectedMap, $cells, ' ', 'cut selection');
        $this->setStatus(sprintf(
            'Cut %d x %d (%d cell%s cleared).',
            $this->clipboard->getWidth(),
            $this->clipboard->getHeight(),
            $changed,
            $changed === 1 ? '' : 's',
        ));
        $this->renderCanvasArea();
    }

    /**
     * Copies the selected region's symbols into the clipboard.
     *
     * @return int The number of captured cells.
     */
    private function captureCanvasSelection(): int
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return 0;
        }

        if (! is_array($this->canvasSelection)) {
            $this->setStatus('Nothing selected — Ctrl+N to the Select tool, then Enter twice.', StatusLevel::WARN);
            $this->renderFooter();
            return 0;
        }

        $selection = $this->canvasSelection;
        $rows = [];

        for ($rowIndex = 0; $rowIndex < $selection['height']; $rowIndex++) {
            $row = [];

            for ($columnIndex = 0; $columnIndex < $selection['width']; $columnIndex++) {
                $row[] = $this->readCanvasSymbol($selectedMap, $selection['x'] + $columnIndex, $selection['y'] + $rowIndex);
            }

            $rows[] = $row;
        }

        $this->clipboard->store($rows, $this->getActiveCanvasLayer());

        return $selection['width'] * $selection['height'];
    }

    /**
     * Stamps the clipboard at the cursor as one undoable stroke.
     *
     * @return void
     */
    private function pasteCanvasClipboard(): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        if ($this->clipboard->isEmpty()) {
            $this->setStatus('The clipboard is empty — select a region and press Ctrl+L.', StatusLevel::WARN);
            $this->renderFooter();
            return;
        }

        if ($this->clipboard->layer !== $this->getActiveCanvasLayer()) {
            $this->setStatus(
                sprintf('The clipboard holds a %s-layer block; switch layers before pasting.', $this->clipboard->layer),
                StatusLevel::WARN,
            );
            $this->renderFooter();
            return;
        }

        $writes = $this->clipboard->project(
            $this->cursorX,
            $this->cursorY,
            $selectedMap->getWidth(),
            $selectedMap->getHeight(),
        );
        $changed = $this->applyCanvasWrites($selectedMap, $writes, 'pasted selection');
        $this->setStatus(sprintf(
            'Stamped %d x %d at (%d, %d): %d cell%s changed.',
            $this->clipboard->getWidth(),
            $this->clipboard->getHeight(),
            $this->cursorX,
            $this->cursorY,
            $changed,
            $changed === 1 ? '' : 's',
        ));
        $this->renderCanvasArea();
    }

    /**
     * Launches the game on the selected map, spawning at the cursor.
     *
     * The author's project is never modified: `PlaytestOverlay` builds a
     * temporary root of symlinks with a generated `system.php` and an isolated
     * save directory, and the overlay is torn down when the game exits. The
     * engine offers no starting-map override today, so this is the honest way
     * to do it — see the Phase 6 deferral note in `docs/roadmap.md`.
     *
     * @return void
     */
    private function startPlaytest(): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $this->workspace instanceof ProjectWorkspace || ! $selectedMap instanceof ProjectMap) {
            $this->setStatus('Select a map before starting a playtest.', StatusLevel::WARN);
            $this->renderFooter();
            return;
        }

        if ($selectedMap->isDirty()) {
            // The playtest reads the map through a symlink, so an unsaved
            // edit simply would not appear. Say so rather than confuse.
            $this->setStatus('Save this map (Ctrl+S) before playtesting — the game reads the file on disk.', StatusLevel::WARN);
            $this->renderFooter();
            return;
        }

        $overlay = null;

        try {
            $overlay = PlaytestOverlay::create(
                $this->workspace->projectRoot,
                $selectedMap->mapId,
                $this->cursorX,
                $this->cursorY,
            );
            $launcher = PlaytestLauncher::discover(projectRoot: $this->workspace->projectRoot);

            $this->terminal->suspendForChildProcess();

            try {
                $launcher->run($overlay);
            } finally {
                $this->terminal->resumeAfterChildProcess($this->lastTerminalSize);
            }

            $this->setStatus(
                sprintf('Playtest finished (%s at %d,%d).', $selectedMap->mapId, $this->cursorX, $this->cursorY),
                StatusLevel::SUCCESS,
            );
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Playtest');
        } finally {
            $overlay?->destroy();
        }

        $this->requestFullRender();
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
        // Every remembered location, filter, and lifted block refers to the
        // objects the reload just replaced.
        $this->navigation->clear();
        $this->assetFilter->clear();
        $this->databaseFilter->clear();
        $this->dialogFilter->clear();
        $this->clipboard->clear();
        $this->canvasToolAnchor = null;
        $this->canvasSelection = null;
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
        $this->helpScrollRow = 0;
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
            return;
        }

        // The binding table outgrew a short terminal once the canvas tools
        // landed, so the overlay pages through the shared scroll window.
        if (str_contains($input, "\033[A")) {
            $this->helpScrollRow = max(0, $this->helpScrollRow - 1);
            $this->renderOverlays();
            return;
        }

        if (str_contains($input, "\033[B")) {
            $this->helpScrollRow = min(max(0, count($this->getHelpLines()) - 1), $this->helpScrollRow + 1);
            $this->renderOverlays();
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
        $lines[] = '';
        $lines[] = 'Database';
        $lines[] = '  Shift+A / Del      add or remove an entry';
        $lines[] = '  Shift+O / Shift+X  add or remove an objective, beat,';
        $lines[] = '                     troop member or script command';
        $lines[] = '  Del                the same, on the settings field';
        $lines[] = '                     the cursor is on';
        $lines[] = '  Enter              edit, or open the picker on a field';
        $lines[] = '                     that names another record';
        $lines[] = '';
        $lines[] = 'NPC mode (F3 on the canvas)';
        $lines[] = '  Enter              select the NPC under the cursor, or';
        $lines[] = '                     create one there';
        $lines[] = '  M                  pick up; Enter sets down, Esc cancels';
        $lines[] = '  D                  duplicate under a fresh stable id';
        $lines[] = '  L                  list the map\'s NPCs; type to narrow,';
        $lines[] = '                     Enter selects and jumps to it';
        $lines[] = '  [ / ]              select the previous / next NPC';
        $lines[] = '  Del                delete (refused while anything names';
        $lines[] = '                     its id)';
        $lines[] = '  Tab                edit it in the Inspector, with the';
        $lines[] = '                     Database pane keys';
        $lines[] = '';
        $lines[] = 'World writes (Enter on an After Talking / Then Set field)';
        $lines[] = '  a / d              add or remove a write';
        $lines[] = '  t / T              cycle switch, event, variable, quest';
        $lines[] = '  n                  choose or type what it names';
        $lines[] = '  x                  toggle on/off, set/add, offer/grant';
        $lines[] = '  v                  type a variable value';
        $lines[] = '  Enter / Esc        keep or discard the list';
        $lines[] = '';
        $lines[] = 'Command frames (event scripts)';
        $lines[] = '  Enter              open an option or branch arm';
        $lines[] = '  Esc                back out one frame';
        $lines[] = '  Shift+O / Shift+X  add or remove a command here, or an';
        $lines[] = '                     option when the cursor is on one';
        $lines[] = '';
        $lines[] = 'Elemental wards (Enter on an Elemental Wards field)';
        $lines[] = '  a / d              add or remove a ward';
        $lines[] = '  n                  choose the element';
        $lines[] = '  x / X              cycle Weak, Resist, Null, Absorb';
        $lines[] = '  Enter / Esc        keep or discard the list';
        $lines[] = '';
        $lines[] = 'Conditions (Enter on a Conditions or Prereqs field)';
        $lines[] = '  a / d              add or remove a condition';
        $lines[] = '  t / T              cycle its type';
        $lines[] = '  n                  choose or type what it names';
        $lines[] = '  x / X              cycle the status, value or count';
        $lines[] = '  !                  it has to not hold';
        $lines[] = '  Enter / Esc        keep or discard the list';
        $lines[] = '';
        $lines[] = 'Canvas: ' . $this->describeCanvasToolUsage();
        $lines[] = sprintf('Active tool: %s.', $this->describeCanvasToolState());
        $lines[] = $this->backups->settings->describe();
        $lines[] = $this->theme->describe();

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
            new PaletteItem('Move Map to Derived Path', '', fn() => $this->beginExplicitMapMove()),
            new PaletteItem('Save All', 'Ctrl+A', fn() => $this->saveAllAssets()),
            new PaletteItem('Undo', 'Ctrl+Z', fn() => $this->performUndo()),
            new PaletteItem('Redo', 'Ctrl+Y', fn() => $this->performRedo()),
            new PaletteItem('Playtest Selected Map', 'Ctrl+T', fn() => $this->startPlaytest()),
            new PaletteItem('Reload Workspace', 'Ctrl+R', fn() => $this->requestReload()),
            new PaletteItem('Tool: Map Mode', '%', function (): void {
                $this->closeDatabaseIfOpen();
                $this->setEditingMode(self::MODE_MAP);
            }),
            new PaletteItem('Tool: Event Mode', '^', function (): void {
                $this->closeDatabaseIfOpen();
                $this->setEditingMode(self::MODE_EVENT);
            }),
            new PaletteItem('Tool: NPC Mode', 'F3', function (): void {
                $this->focusedPane = self::FOCUS_CANVAS;
                $this->setEditingMode(self::MODE_NPC);
            }),
            new PaletteItem('Tool: Character Map', '@', function (): void {
                $this->closeDatabaseIfOpen();
                $this->openCharacterMap();
            }),
            new PaletteItem('Help', '?', fn() => $this->openHelpOverlay()),
            new PaletteItem('Go to Definition', 'Ctrl+G', fn() => $this->goToDefinition()),
            new PaletteItem('Back', 'Ctrl+B', fn() => $this->navigateBack()),
            new PaletteItem('Canvas: Flood Fill', 'Ctrl+F', fn() => $this->floodFillFromCursor()),
            new PaletteItem('Canvas: Eyedropper', 'Ctrl+K', fn() => $this->pickSymbolUnderCursor()),
            new PaletteItem('Canvas: Cycle Brush Width', 'Ctrl+W', fn() => $this->cycleCanvasBrushSize()),
            new PaletteItem('Quit', 'Ctrl+Q', fn() => $this->requestQuit()),
        ];

        foreach (CanvasTool::cases() as $tool) {
            $items[] = new PaletteItem(
                sprintf('Canvas Tool: %s', $tool->label()),
                'Ctrl+N',
                function () use ($tool): void {
                    $this->closeDatabaseIfOpen();
                    $this->setFocusedPane(self::FOCUS_CANVAS, false);
                    $this->canvasTool = $tool;
                    $this->canvasToolAnchor = null;
                    $this->setStatus(sprintf('%s tool. %s', $tool->label(), $this->describeCanvasToolUsage()));
                    $this->requestFullRender();
                },
            );
        }

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
        $this->databaseCommandFramePath = [];
        $this->statusMessage = sprintf('%s database selected.', DatabaseCatalog::at($this->databaseCategoryIndex)->label);
        $this->renderDatabaseArea(includeRoot: true);
    }

    /**
     * Returns the selectable entry labels for the active Database category.
     *
     * @return array<int, string> Labels keyed by entry index.
     */
    private function getDatabaseEntryLabels(): array
    {
        if ($this->isActorsDatabaseSelected()) {
            return array_map(
                static fn(ProjectActor $actor): string => $actor->getName(),
                $this->workspace?->actorDatabase->getActors() ?? [],
            );
        }

        if ($this->isClassesDatabaseSelected()) {
            return array_map(
                static fn(ProjectClass $class): string => $class->getName(),
                $this->workspace?->classDatabase->getClasses() ?? [],
            );
        }

        if ($this->isSkillsDatabaseSelected()) {
            return array_map(
                static fn(ProjectSkill $skill): string => $skill->getName(),
                $this->workspace?->skillDatabase->getSkills() ?? [],
            );
        }

        if ($this->isQuestsDatabaseSelected()) {
            return array_map(
                static fn(ProjectQuest $quest): string => $quest->getName(),
                $this->workspace?->questDatabase->getQuests() ?? [],
            );
        }

        if ($this->isAnimationsDatabaseSelected()) {
            return array_map(
                static fn(Animation $animation): string => $animation->name,
                $this->workspace?->animationDatabase->getAnimations() ?? [],
            );
        }

        return $this->getSelectedRecordDatabase()?->getEntryLabels() ?? [];
    }

    /**
     * Returns the entry indexes surviving the Database `/` filter.
     *
     * @return int[]
     */
    private function getVisibleDatabaseEntryIndexes(): array
    {
        $labels = $this->getDatabaseEntryLabels();

        if ($labels === [] || ! $this->databaseFilter->isActive()) {
            return array_keys($labels);
        }

        /** @var int[] $indexes */
        $indexes = $this->databaseFilter->apply($labels);

        return $indexes;
    }

    /**
     * Steps a Database entry selection through the *visible* entries.
     *
     * @param int $currentIndex The current entry index.
     * @param int $step The selection step.
     * @return int The next entry index.
     */
    private function resolveDatabaseSelectionStep(int $currentIndex, int $step): int
    {
        $visible = $this->getVisibleDatabaseEntryIndexes();

        if ($visible === []) {
            return $currentIndex;
        }

        $position = array_search($currentIndex, $visible, true);
        $position = is_int($position) ? $position : 0;

        return $visible[max(0, min(count($visible) - 1, $position + $step))];
    }

    /**
     * Returns the selected entry index for the active Database category.
     *
     * @return int
     */
    private function getSelectedDatabaseEntryIndex(): int
    {
        return match (true) {
            $this->isActorsDatabaseSelected() => $this->databaseSelectedActorIndex,
            $this->isClassesDatabaseSelected() => $this->databaseSelectedClassIndex,
            $this->isSkillsDatabaseSelected() => $this->databaseSelectedSkillIndex,
            $this->isQuestsDatabaseSelected() => $this->databaseSelectedQuestIndex,
            $this->isAnimationsDatabaseSelected() => $this->databaseSelectedAnimationIndex,
            default => $this->getSelectedRecordIndex(),
        };
    }

    /**
     * Sets the selected entry index for the active Database category.
     *
     * @param int $index The entry index.
     * @return void
     */
    private function setSelectedDatabaseEntryIndex(int $index): void
    {
        $index = max(0, $index);

        match (true) {
            $this->isActorsDatabaseSelected() => $this->databaseSelectedActorIndex = $index,
            $this->isClassesDatabaseSelected() => $this->databaseSelectedClassIndex = $index,
            $this->isSkillsDatabaseSelected() => $this->databaseSelectedSkillIndex = $index,
            $this->isQuestsDatabaseSelected() => $this->databaseSelectedQuestIndex = $index,
            $this->isAnimationsDatabaseSelected() => $this->databaseSelectedAnimationIndex = $index,
            default => $this->setSelectedRecordIndex($index),
        };
    }

    /**
     * Opens the Database entry filter caret.
     *
     * @return void
     */
    private function openDatabaseFilter(): void
    {
        if ($this->getDatabaseEntryLabels() === []) {
            $this->setStatus('This category has no entries to filter.', StatusLevel::WARN);
            $this->renderFooter();
            return;
        }

        $this->databaseFilter->open();
        $this->databaseFocus = self::DATABASE_FOCUS_LIST;
        $this->setStatus('Filter entries: type to narrow, Enter to keep, Esc to clear.');
        $this->renderDatabaseArea();
    }

    /**
     * Clears the Database entry filter.
     *
     * @return void
     */
    private function clearDatabaseFilter(): void
    {
        $this->databaseFilter->clear();
        $this->syncSelectionToVisibleDatabaseEntries();
        $this->setStatus('Entry filter cleared.');
        $this->renderDatabaseArea();
    }

    /**
     * Handles keystrokes while the Database filter caret is capturing.
     *
     * @param string $input The raw input token.
     * @return void
     */
    private function handleDatabaseFilterInput(string $input): void
    {
        if ($input === "\033") {
            $this->clearDatabaseFilter();
            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->databaseFilter->commit();
            $this->setStatus(
                $this->databaseFilter->query === ''
                    ? 'Entry filter closed.'
                    : sprintf('Filtering entries by "%s".', $this->databaseFilter->query),
            );
            $this->renderDatabaseArea();
            return;
        }

        if (str_contains($input, "\033[A")) {
            $this->moveDatabaseListSelection(-1);
            return;
        }

        if (str_contains($input, "\033[B")) {
            $this->moveDatabaseListSelection(1);
            return;
        }

        if ($input === "\177" || $input === "\010") {
            $this->databaseFilter->backspace();
            $this->syncSelectionToVisibleDatabaseEntries();
            $this->renderDatabaseArea();
            return;
        }

        if (str_contains($input, "\033") || $input === "\t") {
            return;
        }

        if (preg_match('/^\X$/u', $input) !== 1) {
            return;
        }

        $this->databaseFilter->type($input);
        $this->syncSelectionToVisibleDatabaseEntries();
        $this->renderDatabaseArea();
    }

    /**
     * Keeps the selected Database entry inside the filtered list.
     *
     * @return void
     */
    private function syncSelectionToVisibleDatabaseEntries(): void
    {
        $visible = $this->getVisibleDatabaseEntryIndexes();

        if ($visible === [] || in_array($this->getSelectedDatabaseEntryIndex(), $visible, true)) {
            return;
        }

        $this->setSelectedDatabaseEntryIndex($visible[0]);
        $this->databaseSelectedSettingIndex = 0;
    }

    /**
     * Opens the destructive confirmation for deleting the selected entry.
     *
     * @return void
     */
    private function openDatabaseEntryDeleteConfirmation(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $labels = $this->getDatabaseEntryLabels();
        $index = $this->getSelectedDatabaseEntryIndex();
        $label = $labels[$index] ?? null;

        if ($label === null) {
            $this->setStatus('This category does not support entry deletion yet.', StatusLevel::WARN);
            $this->renderFooter();
            return;
        }

        // A read-only category refuses before the destructive prompt appears,
        // rather than opening a confirmation that could only fail.
        $recordDatabase = $this->getSelectedRecordDatabase();

        if ($recordDatabase instanceof ProjectRecordDatabase && ! $recordDatabase->isEditable()) {
            $this->setStatus($this->describeRecordReadOnly($recordDatabase), StatusLevel::WARN);
            $this->renderFooter();
            return;
        }

        $this->pendingDatabaseDeletion = [
            'category' => $this->getSelectedDatabaseCategoryDefinition()->key,
            'index' => $index,
            'label' => $label,
        ];
        $this->isDatabaseEntryDeleteConfirmationOpen = true;
        $this->setStatus(sprintf('Delete %s? Press y to confirm.', $label), StatusLevel::WARN);
        $this->renderOverlays();
    }

    /**
     * Handles the Database entry delete confirmation.
     *
     * @param string $input The raw input.
     * @param string $normalizedInput The normalized input.
     * @return void
     */
    private function handleDatabaseEntryDeleteConfirmationInput(string $input, string $normalizedInput): void
    {
        // The Phase 4 destructive idiom: Cancel is the default, so Enter
        // cancels and only an explicit `y` deletes.
        if (
            $input === "\033" ||
            $input === "\n" ||
            $input === "\r" ||
            $this->isPlainShortcut($normalizedInput, 'n')
        ) {
            $this->closeDatabaseEntryDeleteConfirmation('Delete cancelled.');
            return;
        }

        if ($this->isPlainShortcut($normalizedInput, 'y')) {
            $this->confirmDatabaseEntryDeletion();
        }
    }

    /**
     * Deletes the pending Database entry, recording it for undo.
     *
     * The delete is in-memory: the asset file (or the rewritten database
     * file) only changes on the next save, which is exactly what makes the
     * undo honest.
     *
     * @return void
     */
    private function confirmDatabaseEntryDeletion(): void
    {
        $pending = $this->pendingDatabaseDeletion;

        if (! is_array($pending) || ! $this->workspace instanceof ProjectWorkspace) {
            $this->closeDatabaseEntryDeleteConfirmation('Delete cancelled.');
            return;
        }

        $workspace = $this->workspace;
        $index = $pending['index'];
        $label = $pending['label'];
        $command = match ($pending['category']) {
            self::DATABASE_CATEGORY_ACTORS => $this->buildDatabaseDeletionCommand(
                sprintf('Delete actor %s', $label),
                fn(): ?object => $workspace->actorDatabase->removeActor($index),
                static fn(object $entry) => $workspace->actorDatabase->insertActor($index, $entry),
            ),
            self::DATABASE_CATEGORY_CLASSES => $this->buildDatabaseDeletionCommand(
                sprintf('Delete class %s', $label),
                fn(): ?object => $workspace->classDatabase->removeClass($index),
                static fn(object $entry) => $workspace->classDatabase->insertClass($index, $entry),
            ),
            self::DATABASE_CATEGORY_SKILLS => $this->buildDatabaseDeletionCommand(
                sprintf('Delete skill %s', $label),
                fn(): ?object => $workspace->skillDatabase->removeSkill($index),
                static fn(object $entry) => $workspace->skillDatabase->insertSkill($index, $entry),
            ),
            self::DATABASE_CATEGORY_QUESTS => $this->buildDatabaseDeletionCommand(
                sprintf('Delete quest %s', $label),
                fn(): ?object => $workspace->questDatabase->removeQuest($index),
                static fn(object $entry) => $workspace->questDatabase->insertQuest($index, $entry),
            ),
            self::DATABASE_CATEGORY_ANIMATIONS => $this->buildDatabaseDeletionCommand(
                sprintf('Delete animation %s', $label),
                fn(): ?object => $workspace->animationDatabase->removeAnimation($index),
                static fn(object $entry) => $workspace->animationDatabase->insertAnimation($index, $entry),
            ),
            default => $this->buildRecordDeletionCommand($pending['category'], $index, $label),
        };

        if (! $command instanceof Command) {
            $this->closeDatabaseEntryDeleteConfirmation($this->describeUndeletableCategory($pending['category']));
            return;
        }

        $this->recordCommand($command);
        $this->setSelectedDatabaseEntryIndex(max(0, $index - 1));
        $this->databaseSelectedSettingIndex = 0;
        $this->syncSelectionToVisibleDatabaseEntries();
        $this->closeDatabaseEntryDeleteConfirmation(
            sprintf('Deleted %s. Ctrl+Z restores it; the file changes on save.', $label),
        );
    }

    /**
     * Builds the deletion command for a schema-driven category.
     *
     * @param string $categoryKey The category key.
     * @param int $index The entry index.
     * @param string $label The entry label.
     * @return Command|null
     */
    private function buildRecordDeletionCommand(string $categoryKey, int $index, string $label): ?Command
    {
        $database = $this->workspace?->getRecordDatabase($categoryKey);

        if (! $database instanceof ProjectRecordDatabase || ! $database->isEditable()) {
            return null;
        }

        return $this->buildDatabaseDeletionCommand(
            sprintf('Delete %s %s', $database->schema->entryNoun, $label),
            static fn(): ?object => $database->removeRecord($index),
            static fn(object $entry) => $database->insertRecord($index, $entry),
        );
    }

    /**
     * Explains why a category refused an entry deletion.
     *
     * @param string $categoryKey The category key.
     * @return string
     */
    private function describeUndeletableCategory(string $categoryKey): string
    {
        $database = $this->workspace?->getRecordDatabase($categoryKey);

        if ($database instanceof ProjectRecordDatabase && ! $database->isEditable()) {
            return sprintf('Read-only: %s.', $database->getReadOnlyReason() ?? 'this category cannot be written');
        }

        return 'This category does not support entry deletion yet.';
    }

    /**
     * Builds the undoable command behind one Database entry deletion.
     *
     * @param string $label The undo label.
     * @param Closure(): ?object $remove Removes the entry and returns it.
     * @param Closure(object): void $restore Puts an entry back at its index.
     * @return Command|null The recorded command, or null when nothing was removed.
     */
    private function buildDatabaseDeletionCommand(string $label, Closure $remove, Closure $restore): ?Command
    {
        $entry = $remove();

        if (! is_object($entry)) {
            return null;
        }

        return new GenericCommand(
            $label,
            static function () use ($remove): void {
                $remove();
            },
            static function () use ($restore, $entry): void {
                $restore($entry);
            },
        );
    }

    /**
     * Closes the Database entry delete confirmation.
     *
     * @param string $statusMessage The footer status message.
     * @return void
     */
    private function closeDatabaseEntryDeleteConfirmation(string $statusMessage): void
    {
        $this->pendingDatabaseDeletion = null;
        $this->isDatabaseEntryDeleteConfirmationOpen = false;
        $this->statusMessage = $statusMessage;
        $this->requestFullRender();
    }

    /**
     * Jumps from the current selection to the thing it references.
     *
     * The destination round trip proved the shape: remember where the author
     * was, move them, and let one key bring them back. This generalizes it —
     * actor→class and skill→animation inside the Database, event→destination
     * map (and the Inspector's destination field) in the main shell.
     *
     * @return void
     */
    private function goToDefinition(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        if ($this->isDatabaseOpen) {
            $this->goToDatabaseDefinition();
            return;
        }

        $this->goToMapDefinition();
    }

    /**
     * Resolves go-to-definition inside the Database screen.
     *
     * @return void
     */
    private function goToDatabaseDefinition(): void
    {
        if ($this->isActorsDatabaseSelected()) {
            $this->goToActorClass();
            return;
        }

        if ($this->isSkillsDatabaseSelected()) {
            $this->goToSkillAnimation();
            return;
        }

        $this->setStatus('Nothing to go to from here.', StatusLevel::WARN);
        $this->renderFooter();
    }

    /**
     * Jumps from the selected actor to the class it references.
     *
     * @return void
     */
    private function goToActorClass(): void
    {
        $actor = $this->getSelectedActor();

        if (! $actor instanceof ProjectActor) {
            return;
        }

        $className = $actor->getClassName();

        if ($className === '') {
            $this->setStatus(sprintf('%s has no class assigned.', $actor->getName()), StatusLevel::WARN);
            $this->renderFooter();
            return;
        }

        $classIndex = $this->findDatabaseClassIndex($className);

        if ($classIndex === null) {
            $this->setStatus(
                sprintf('No class named "%s" in assets/Data/classes.php.', $className),
                StatusLevel::WARN,
            );
            $this->renderFooter();
            return;
        }

        $this->pushNavigationOrigin(sprintf('actor %s', $actor->getName()));
        $this->databaseFilter->clear();
        $this->databaseCategoryIndex = DatabaseCatalog::indexOf(self::DATABASE_CATEGORY_CLASSES);
        $this->databaseSelectedClassIndex = $classIndex;
        $this->databaseFocus = self::DATABASE_FOCUS_LIST;
        $this->databaseSelectedSettingIndex = 0;
        $this->setStatus(sprintf('Went to class %s (Ctrl+B goes back).', $className), StatusLevel::SUCCESS);
        $this->renderDatabaseArea(includeRoot: true);
    }

    /**
     * Jumps from the selected skill to the animation sharing its name.
     *
     * Skills carry no explicit animation id yet (the engine models a magic
     * *effect type*, not a database reference), so the hop resolves by name:
     * the skill name first, then its effect type.
     *
     * @return void
     */
    private function goToSkillAnimation(): void
    {
        $skill = $this->getSelectedSkill();

        if (! $skill instanceof ProjectSkill) {
            return;
        }

        $candidates = array_values(array_filter([$skill->getName(), $skill->getEffectType() ?? '']));
        $animationIndex = null;
        $matchedName = '';

        foreach ($candidates as $candidate) {
            $animationIndex = $this->findDatabaseAnimationIndex($candidate);

            if ($animationIndex !== null) {
                $matchedName = $candidate;
                break;
            }
        }

        if ($animationIndex === null) {
            $this->setStatus(
                sprintf('No animation named "%s" — skills carry no animation reference yet.', $skill->getName()),
                StatusLevel::WARN,
            );
            $this->renderFooter();
            return;
        }

        $this->pushNavigationOrigin(sprintf('skill %s', $skill->getName()));
        $this->databaseFilter->clear();
        $this->databaseCategoryIndex = DatabaseCatalog::indexOf(self::DATABASE_CATEGORY_ANIMATIONS);
        $this->databaseSelectedAnimationIndex = $animationIndex;
        $this->databaseFocus = self::DATABASE_FOCUS_LIST;
        $this->databaseSelectedSettingIndex = 0;
        $this->setStatus(sprintf('Went to animation %s (Ctrl+B goes back).', $matchedName), StatusLevel::SUCCESS);
        $this->renderDatabaseArea(includeRoot: true);
    }

    /**
     * Resolves go-to-definition in the main shell (event → destination map).
     *
     * @return void
     */
    private function goToMapDefinition(): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        $marker = $this->getFocusedEventMarker();
        $destination = $this->resolveGoToDestinationMapId($selectedMap, $marker);

        if ($destination === null) {
            $this->setStatus(
                'Nothing to go to — put the cursor on a transporter event, or select its Destination field.',
                StatusLevel::WARN,
            );
            $this->renderFooter();
            return;
        }

        $destinationIndex = array_search($destination, $this->workspace?->mapIds ?? [], true);

        if (! is_int($destinationIndex)) {
            $this->setStatus(sprintf('Destination map "%s" no longer exists.', $destination), StatusLevel::WARN);
            $this->renderFooter();
            return;
        }

        $this->pushNavigationOrigin(sprintf('%s (%d, %d)', $selectedMap->mapId, $this->cursorX, $this->cursorY));
        $spawnPoint = is_string($marker) && $marker !== ''
            ? $this->resolveConfiguredSpawnPoint($selectedMap, $marker)
            : ['x' => 0, 'y' => 0];
        $this->assetFilter->clear();
        $this->selectedAssetIndex = $destinationIndex;
        $this->cursorX = $spawnPoint['x'];
        $this->cursorY = $spawnPoint['y'];
        $this->canvasOffsetX = 0;
        $this->canvasOffsetY = 0;
        $this->selectedInspectorFieldIndex = 0;
        $this->setFocusedPane(self::FOCUS_CANVAS, false);
        $this->clampCursor();
        $this->syncViewportToCursor();
        $this->clampInspectorSelection();
        $this->setStatus(sprintf('Went to %s (Ctrl+B goes back).', $destination), StatusLevel::SUCCESS);
        $this->renderSelectionDependentArea();
    }

    /**
     * Resolves the destination map id a go-to-definition should follow.
     *
     * @param ProjectMap $selectedMap The selected map.
     * @param string|null $marker The focused event marker.
     * @return string|null
     */
    private function resolveGoToDestinationMapId(ProjectMap $selectedMap, ?string $marker): ?string
    {
        if ($this->focusedPane === self::FOCUS_INSPECTOR) {
            $fields = $this->getInspectorFields();
            $field = $fields[$this->selectedInspectorFieldIndex] ?? null;

            if (is_array($field) && $this->isDestinationMapField($field)) {
                $value = trim((string) ($field['value'] ?? ''));

                if ($value !== '') {
                    return $value;
                }
            }
        }

        if (! is_string($marker) || $marker === '') {
            return null;
        }

        $definition = $selectedMap->getEventDefinition($marker);
        $destination = is_array($definition) ? ($definition['data']['destination'] ?? null) : null;

        return is_string($destination) && $destination !== '' ? $destination : null;
    }

    /**
     * Returns the class index whose name matches (case-insensitively).
     *
     * @param string $className The class name to find.
     * @return int|null
     */
    private function findDatabaseClassIndex(string $className): ?int
    {
        foreach ($this->workspace?->classDatabase->getClasses() ?? [] as $index => $class) {
            if (mb_strtolower($class->getName()) === mb_strtolower($className)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Returns the animation index whose name matches (case-insensitively).
     *
     * @param string $animationName The animation name to find.
     * @return int|null
     */
    private function findDatabaseAnimationIndex(string $animationName): ?int
    {
        if (trim($animationName) === '') {
            return null;
        }

        foreach ($this->workspace?->animationDatabase->getAnimations() ?? [] as $index => $animation) {
            if (mb_strtolower($animation->name) === mb_strtolower($animationName)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Remembers the current location so Back can restore it.
     *
     * @param string $label The human-readable origin label.
     * @return void
     */
    private function pushNavigationOrigin(string $label): void
    {
        $this->navigation->push(new NavigationEntry($label, $this->captureNavigationRestorer()));
    }

    /**
     * Captures the current location as a restore closure.
     *
     * @return Closure(): void
     */
    private function captureNavigationRestorer(): Closure
    {
        $snapshot = [
            'databaseOpen' => $this->isDatabaseOpen,
            'databaseCategoryIndex' => $this->databaseCategoryIndex,
            'databaseFocus' => $this->databaseFocus,
            'actor' => $this->databaseSelectedActorIndex,
            'class' => $this->databaseSelectedClassIndex,
            'skill' => $this->databaseSelectedSkillIndex,
            'quest' => $this->databaseSelectedQuestIndex,
            'animation' => $this->databaseSelectedAnimationIndex,
            'setting' => $this->databaseSelectedSettingIndex,
            'assetIndex' => $this->selectedAssetIndex,
            'cursorX' => $this->cursorX,
            'cursorY' => $this->cursorY,
            'canvasOffsetX' => $this->canvasOffsetX,
            'canvasOffsetY' => $this->canvasOffsetY,
            'focusedPane' => $this->focusedPane,
            'editingMode' => $this->editingMode,
            'showEventOverlay' => $this->showEventOverlay,
            'inspectorFieldIndex' => $this->selectedInspectorFieldIndex,
        ];

        return function () use ($snapshot): void {
            $this->databaseCategoryIndex = $snapshot['databaseCategoryIndex'];
            $this->databaseFocus = $snapshot['databaseFocus'];
            $this->databaseSelectedActorIndex = $snapshot['actor'];
            $this->databaseSelectedClassIndex = $snapshot['class'];
            $this->databaseSelectedSkillIndex = $snapshot['skill'];
            $this->databaseSelectedQuestIndex = $snapshot['quest'];
            $this->databaseSelectedAnimationIndex = $snapshot['animation'];
            $this->databaseSelectedSettingIndex = $snapshot['setting'];
            $this->selectedAssetIndex = $snapshot['assetIndex'];
            $this->cursorX = $snapshot['cursorX'];
            $this->cursorY = $snapshot['cursorY'];
            $this->canvasOffsetX = $snapshot['canvasOffsetX'];
            $this->canvasOffsetY = $snapshot['canvasOffsetY'];
            $this->editingMode = $snapshot['editingMode'];
            $this->showEventOverlay = $snapshot['showEventOverlay'];
            $this->selectedInspectorFieldIndex = $snapshot['inspectorFieldIndex'];
            $this->setFocusedPane($snapshot['focusedPane'], false);
            $this->isDatabaseOpen = $snapshot['databaseOpen'];
            $this->clampCursor();
            $this->clampCanvasOffsets();
            $this->clampInspectorSelection();
        };
    }

    /**
     * Returns to the location the last go-to-definition left.
     *
     * @return void
     */
    private function navigateBack(): void
    {
        $entry = $this->navigation->back();

        if (! $entry instanceof NavigationEntry) {
            $this->setStatus('Nothing to go back to.', StatusLevel::WARN);
            $this->renderFooter();
            return;
        }

        $this->setStatus(sprintf('Back to %s.', $entry->label));
        $this->requestFullRender();
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
                $this->backupBeforeSave(...$this->getMapBackupPaths($map));
                $map->save();
                $savedMaps++;
            } catch (Throwable $throwable) {
                Debug::error(sprintf('Save all (%s): %s', $map->mapId, $throwable->getMessage()));
                $failures[] = sprintf('%s: %s', $map->mapId, $throwable->getMessage());
            }
        }

        // Categories sharing one file are saved together, so the file is
        // written once with every dirty part folded in rather than once per
        // category, each rewriting what the last just wrote.
        foreach (SharedFileTransaction::groupByPath($this->getSaveableDatabases()) as $group) {
            $dirty = array_filter($group, static fn(object $database): bool => $database->isDirty());

            if ($dirty === []) {
                continue;
            }

            $label = implode(', ', array_keys($dirty));

            try {
                $backed = [];

                foreach ($dirty as $database) {
                    foreach ($this->getDatabaseBackupPaths($database) as $backupPath) {
                        // One file, one backup, however many categories of
                        // it are being written.
                        $backed[$backupPath] = $backupPath;
                    }
                }

                $this->backupBeforeSave(...array_values($backed));

                $shared = reset($dirty);

                if ($shared instanceof ProjectRecordDatabase && $shared->sharesBackingFile()) {
                    // One write for the file, with every dirty category's
                    // edits composed against one snapshot of it first.
                    SharedFileTransaction::commit(array_values($dirty));
                } else {
                    foreach ($dirty as $database) {
                        $database->save();
                    }
                }

                $savedDatabases += count($dirty);
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
     * Writes pre-overwrite backups of the given paths when backups are on.
     *
     * A backup failure is reported, never thrown: the author asked for a
     * save, and a missing safety copy must not stand in its way.
     *
     * @param string ...$paths The files about to be overwritten.
     * @return void
     */
    private function backupBeforeSave(string ...$paths): void
    {
        if (! $this->backups->isEnabled() || $paths === []) {
            return;
        }

        $result = $this->backups->backup(...$paths);

        if ($result['failed'] === []) {
            return;
        }

        Debug::error(sprintf('Backup failed for: %s', implode(', ', $result['failed'])));
        $this->setStatus(
            sprintf('%d backup%s failed (the save still ran).', count($result['failed']), count($result['failed']) === 1 ? '' : 's'),
            StatusLevel::WARN,
            $result['failed'],
        );
    }

    /**
     * Returns the files a map save overwrites.
     *
     * @param ProjectMap $map The map about to be saved.
     * @return string[]
     */
    private function getMapBackupPaths(ProjectMap $map): array
    {
        return [$map->dataPath, $map->mapPath, $map->eventPath];
    }

    /**
     * Returns the files a database save overwrites.
     *
     * @param object $database The database about to be saved.
     * @return string[]
     */
    private function getDatabaseBackupPaths(object $database): array
    {
        if ($database instanceof ProjectActorDatabase) {
            return array_map(
                static fn(ProjectActor $actor): string => $actor->path,
                $database->getActors(),
            );
        }

        if ($database instanceof ProjectRecordDatabase) {
            return $database->getBackupPaths();
        }

        return property_exists($database, 'path') ? [(string) $database->path] : [];
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

        $databases = [
            'Actors' => $this->workspace->actorDatabase,
            'Classes' => $this->workspace->classDatabase,
            'Skills' => $this->workspace->skillDatabase,
            'Quests' => $this->workspace->questDatabase,
            'Animations' => $this->workspace->animationDatabase,
            'System' => $this->workspace->systemDatabase,
        ];

        // Read-only categories never join Save All: they hold no edits, and
        // asking them to save would raise instead of no-op.
        foreach ($this->workspace->recordDatabases as $categoryKey => $recordDatabase) {
            if ($recordDatabase->isEditable()) {
                $databases[DatabaseCatalog::at(DatabaseCatalog::indexOf($categoryKey))->label] = $recordDatabase;
            }
        }

        return $databases;
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

        // A map's path is its identity; an ordinary save writes in place and
        // never infers a rename from metadata. Moving is its own operation.
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
            $this->performExplicitMapMove();
        }
    }

    /**
     * Starts the explicit map-move flow: current id, proposed id, warning,
     * confirmation. Never reachable from an ordinary save.
     *
     * @return void
     */
    private function beginExplicitMapMove(): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap) {
            return;
        }

        $proposed = $this->deriveProposedMapId($selectedMap);

        if ($proposed === $selectedMap->mapId) {
            $this->setStatus(sprintf('%s already lives at its derived path; nothing to move.', $selectedMap->mapId));
            $this->renderFooter();

            return;
        }

        $this->isRenameConfirmationOpen = true;
        $this->setStatus(
            sprintf(
                'Move %s to %s? References are NOT migrated: doors, quests, saves and one-shot events naming the old id will break — confirm with y.',
                $selectedMap->mapId,
                $proposed,
            ),
            StatusLevel::WARN,
        );
        $this->renderOverlays();
    }

    /**
     * Performs the confirmed move, failing closed on any collision or error.
     *
     * @return void
     */
    private function performExplicitMapMove(): void
    {
        $selectedMap = $this->getSelectedMap();

        if (! $selectedMap instanceof ProjectMap || ! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        try {
            $moved = $selectedMap->moveTo($this->deriveProposedMapId($selectedMap));
            $this->workspace = $this->workspace->withReplacedMap($this->selectedAssetIndex, $moved);
            $this->history->clear();
            $this->setStatus(
                sprintf('Moved to %s. References naming the old id were not migrated.', $moved->mapId),
                StatusLevel::SUCCESS,
            );
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, 'Map move');
        }

        $this->requestFullRender();
    }

    /**
     * Derives the path the map's metadata proposes, for the move flow only.
     *
     * @param ProjectMap $map The map.
     * @return string The proposed id.
     */
    private function deriveProposedMapId(ProjectMap $map): string
    {
        $name = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $map->getDisplayName())));
        $name = trim($name, '-') !== '' ? trim($name, '-') : basename($map->directory);
        $region = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $map->getRegion())));
        $region = trim($region, '-');

        return $region !== '' ? $region . '/' . $name : $name;
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
            $this->backupBeforeSave(...$this->getMapBackupPaths($selectedMap));
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
        if ($this->handleDialogFilterKey($input)) {
            return;
        }

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
     * Handles the shared `/` filter caret inside a picker dialog.
     *
     * Dialogs consume all input by design, so the filter has to live inside
     * each of them — but the query, the ranking, and the Esc-pops-one-level
     * behaviour are shared here.
     *
     * @param string $input The raw input token.
     * @return bool Whether the input was consumed.
     */
    private function handleDialogFilterKey(string $input): bool
    {
        if ($this->dialogFilter->isCapturing) {
            if ($input === "\033") {
                $this->dialogFilter->clear();
                $this->syncDialogSelectionToFilter();
                $this->renderOverlays();
                return true;
            }

            if ($input === "\n" || $input === "\r") {
                $this->dialogFilter->commit();
                $this->renderOverlays();
                return true;
            }

            if ($input === "\177" || $input === "\010") {
                $this->dialogFilter->backspace();
                $this->syncDialogSelectionToFilter();
                $this->renderOverlays();
                return true;
            }

            // Arrows still drive the selection while the caret is open.
            if (str_contains($input, "\033[A") || str_contains($input, "\033[B")) {
                return false;
            }

            if (str_contains($input, "\033") || $input === "\t") {
                return true;
            }

            if (preg_match('/^\X$/u', $input) === 1) {
                $this->dialogFilter->type($input);
                $this->syncDialogSelectionToFilter();
                $this->renderOverlays();
            }

            return true;
        }

        if ($input === '/') {
            $this->dialogFilter->open();
            $this->renderOverlays();
            return true;
        }

        if ($input === "\033" && $this->dialogFilter->isActive()) {
            $this->dialogFilter->clear();
            $this->syncDialogSelectionToFilter();
            $this->renderOverlays();
            return true;
        }

        return false;
    }

    /**
     * Ranks a dialog's entry labels through the shared filter.
     *
     * @param array<int, string> $labels The entry labels keyed by entry index.
     * @return int[] The surviving entry indexes, best match first.
     */
    private function getVisibleDialogIndexes(array $labels): array
    {
        if ($labels === [] || ! $this->dialogFilter->isActive()) {
            return array_keys($labels);
        }

        /** @var int[] $indexes */
        $indexes = $this->dialogFilter->apply($labels);

        return $indexes;
    }

    /**
     * Keeps the open dialog's selection on a visible row.
     *
     * @return void
     */
    private function syncDialogSelectionToFilter(): void
    {
        if ($this->isDestinationDialogOpen) {
            $visible = $this->getVisibleDestinationIndexes();

            if ($visible !== [] && ! in_array($this->selectedDestinationIndex, $visible, true)) {
                $this->selectedDestinationIndex = $visible[0];
            }

            return;
        }

        if ($this->isLootDialogOpen) {
            $visible = $this->getVisibleLootIndexes();

            if ($visible !== [] && ! in_array($this->selectedLootIndex, $visible, true)) {
                $this->selectedLootIndex = $visible[0];
            }

            return;
        }

        if ($this->isEventOptionDialogOpen) {
            $visible = $this->getVisibleEventOptionIndexes();

            if ($visible !== [] && ! in_array($this->selectedEventOptionIndex, $visible, true)) {
                $this->selectedEventOptionIndex = $visible[0];
            }

            return;
        }

        if ($this->isEventTypeDialogOpen) {
            $visible = $this->getVisibleEventTypeIndexes();

            if ($visible !== [] && ! in_array($this->selectedEventTypeIndex, $visible, true)) {
                $this->selectedEventTypeIndex = $visible[0];
            }
        }
    }

    /**
     * Steps a dialog selection through its visible rows.
     *
     * @param int[] $visible The visible entry indexes.
     * @param int $currentIndex The current entry index.
     * @param int $step The selection step.
     * @return int The next entry index.
     */
    private function stepDialogSelection(array $visible, int $currentIndex, int $step): int
    {
        if ($visible === []) {
            return $currentIndex;
        }

        $position = array_search($currentIndex, $visible, true);
        $position = is_int($position) ? $position : 0;

        return $visible[max(0, min(count($visible) - 1, $position + $step))];
    }

    /**
     * Returns the destination entries surviving the dialog filter.
     *
     * @return int[]
     */
    private function getVisibleDestinationIndexes(): array
    {
        return $this->getVisibleDialogIndexes(array_map(
            static fn(array $entry): string => $entry['name'] . ' ' . $entry['mapId'] . ' ' . $entry['region'],
            $this->getDestinationDialogEntries(),
        ));
    }

    /**
     * Returns the loot entries surviving the dialog filter.
     *
     * @return int[]
     */
    private function getVisibleLootIndexes(): array
    {
        return $this->getVisibleDialogIndexes(array_map(
            static fn(array $entry): string => (string) ($entry['name'] ?? ''),
            $this->lootDialogEntries,
        ));
    }

    /**
     * Returns the option entries surviving the dialog filter.
     *
     * @return int[]
     */
    private function getVisibleEventOptionIndexes(): array
    {
        return $this->getVisibleDialogIndexes(array_map(
            static fn(array $entry): string => (string) ($entry['label'] ?? ''),
            $this->eventOptionDialogEntries,
        ));
    }

    /**
     * Returns the event types surviving the dialog filter.
     *
     * @return int[]
     */
    private function getVisibleEventTypeIndexes(): array
    {
        return $this->getVisibleDialogIndexes(array_map(
            static fn(object $definition): string => $definition->label,
            EventTypeCatalog::all(),
        ));
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

        $nextIndex = $this->stepDialogSelection($this->getVisibleDestinationIndexes(), $this->selectedDestinationIndex, $step);

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
        $this->dialogFilter->clear();
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
        if ($this->handleDialogFilterKey($input)) {
            return;
        }

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

        $nextIndex = $this->stepDialogSelection($this->getVisibleLootIndexes(), $this->selectedLootIndex, $step);

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
        $newValue = (string) ($selectedEntry['value'] ?? $selectedEntry['name']);
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
        $this->dialogFilter->clear();
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
        if ($this->handleDialogFilterKey($input)) {
            return;
        }

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

        $nextIndex = $this->stepDialogSelection($this->getVisibleEventOptionIndexes(), $this->selectedEventOptionIndex, $step);

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
        $this->dialogFilter->clear();
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
        if ($this->handleDialogFilterKey($input)) {
            return;
        }

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

        $nextIndex = $this->stepDialogSelection($this->getVisibleEventTypeIndexes(), $this->selectedEventTypeIndex, $step);

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
        $newDefinition = $currentClassName === $definition->className && is_array($currentDefinition)
            ? array_replace_recursive(
                [
                    'class' => $definition->className,
                    'data' => $definition->defaultData,
                    ...$definition->defaultDefinitionFields,
                ],
                $currentDefinition,
            )
            : [
                'class' => $definition->className,
                'data' => $definition->defaultData,
                ...$definition->defaultDefinitionFields,
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
        $this->dialogFilter->clear();
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

        $reference = $this->resolveEventReferenceField($field);

        if (is_array($reference)) {
            $this->openEventReferenceDialog(
                (string) ($field['marker'] ?? ''),
                (array) ($field['path'] ?? []),
                $reference['title'],
                $reference['category'],
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
            $newWidth = (string) ($field['field'] ?? '') === 'width' ? max(1, (int) $value) : $selectedMap->getWidth();
            $newHeight = (string) ($field['field'] ?? '') === 'height' ? max(1, (int) $value) : $selectedMap->getHeight();
            $stranded = $selectedMap->describeNpcsStrandedBy($newWidth, $newHeight);

            if ($stranded !== []) {
                // Refuse rather than clamp, delete, or truncate: the author
                // moves, resizes or removes the NPC, then shrinks.
                $this->setStatus(
                    sprintf('Cannot shrink to %dx%d: %d NPC%s would be stranded (Ctrl+E lists them).', $newWidth, $newHeight, count($stranded), count($stranded) === 1 ? '' : 's'),
                    StatusLevel::ERROR,
                    array_map(static fn(string $line): string => '- ' . $line, $stranded),
                );

                return;
            }

            $snapshotBefore = $selectedMap->captureGridSnapshot();
            $selectedMap->resize($newWidth, $newHeight);
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
     * Returns whether a schema-driven (Phase 6) category is active.
     *
     * @return bool
     */
    private function isRecordDatabaseSelected(): bool
    {
        return $this->getSelectedRecordDatabase() instanceof ProjectRecordDatabase;
    }

    /**
     * Returns the schema-driven database behind the active category.
     *
     * @return ProjectRecordDatabase|null
     */
    private function getSelectedRecordDatabase(): ?ProjectRecordDatabase
    {
        return $this->workspace?->getRecordDatabase($this->getSelectedDatabaseCategoryDefinition()->key);
    }

    /**
     * Returns the selected entry index for the active schema-driven category.
     *
     * @return int
     */
    private function getSelectedRecordIndex(): int
    {
        return $this->databaseSelectedRecordIndexes[$this->getSelectedDatabaseCategoryDefinition()->key] ?? 0;
    }

    /**
     * Sets the selected entry index for the active schema-driven category.
     *
     * @param int $index The entry index.
     * @return void
     */
    private function setSelectedRecordIndex(int $index): void
    {
        if ($index !== $this->getSelectedRecordIndex()) {
            // A frame is an address inside one record's commands; it means
            // nothing on another record.
            $this->databaseCommandFramePath = [];
        }

        $this->databaseSelectedRecordIndexes[$this->getSelectedDatabaseCategoryDefinition()->key] = max(0, $index);
    }

    /**
     * Moves the selected entry in a schema-driven category.
     *
     * @param int $step The selection step.
     * @return void
     */
    private function moveDatabaseRecordSelection(int $step): void
    {
        $database = $this->getSelectedRecordDatabase();

        if (! $database instanceof ProjectRecordDatabase || $database->getRecords() === []) {
            return;
        }

        $nextIndex = $this->resolveDatabaseSelectionStep($this->getSelectedRecordIndex(), $step);

        if ($nextIndex === $this->getSelectedRecordIndex()) {
            return;
        }

        $this->setSelectedRecordIndex($nextIndex);
        $this->databaseSelectedSettingIndex = 0;
        $this->statusMessage = sprintf(
            'Selected %s %s.',
            $database->schema->entryNoun,
            $database->getEntryLabels()[$nextIndex] ?? '',
        );
        $this->renderDatabasePanes(['list', 'settings']);
    }

    /**
     * Creates a new entry in a schema-driven category.
     *
     * @return void
     */
    private function createDatabaseRecord(): void
    {
        $database = $this->getSelectedRecordDatabase();

        if (! $database instanceof ProjectRecordDatabase) {
            return;
        }

        if (! $database->isEditable()) {
            $this->setStatus($this->describeRecordReadOnly($database), StatusLevel::WARN);
            $this->renderDatabaseArea();
            return;
        }

        $index = $database->addRecord();

        if ($index === null) {
            $this->setStatus(
                sprintf('%s entries cannot be created from the editor.', ucfirst($database->schema->entryNoun)),
                StatusLevel::WARN,
            );
            $this->renderDatabaseArea();
            return;
        }

        $this->setSelectedRecordIndex($index);
        $this->databaseSelectedSettingIndex = 0;
        $this->databaseFocus = self::DATABASE_FOCUS_SETTINGS;
        $this->setStatus(sprintf('Created a new %s.', $database->schema->entryNoun), StatusLevel::SUCCESS);
        $this->renderDatabaseArea();
        $this->beginDatabaseEdit();
    }

    /**
     * Appends a sub-list entry (a beat, member, or command) and records it
     * for undo.
     *
     * @return void
     */
    /**
     * Adds where the cursor points: an option to the choice it is on, or a
     * command to the open frame below the cursor's command.
     *
     * @param ProjectRecordDatabase $database The category.
     * @param RecordSubList $subList The sub-list schema.
     * @return void
     */
    private function addDatabaseFrameItem(ProjectRecordDatabase $database, RecordSubList $subList): void
    {
        $recordIndex = $this->getSelectedRecordIndex();
        $framePath = $this->databaseCommandFramePath;
        $selectedId = (string) ($this->getDatabaseSettingsFields()[$this->databaseSelectedSettingIndex]['field'] ?? '');
        $prefix = preg_quote($subList->prefix, '/');

        if (preg_match('/^' . $prefix . '(\\d+)Option(\\d+)/', $selectedId, $matches) === 1) {
            $commandIndex = intval($matches[1]);
            $optionIndex = $database->addChoiceOption($recordIndex, $framePath, $commandIndex);

            if ($optionIndex === null) {
                return;
            }

            $option = ['text' => 'New option', 'then' => []];
            $this->recordCommand(new GenericCommand(
                'Option add',
                static fn() => $database->insertChoiceOption($recordIndex, $framePath, $commandIndex, $optionIndex, $option),
                static fn() => $database->removeChoiceOption($recordIndex, $framePath, $commandIndex, $optionIndex),
            ));
            $this->selectDatabaseFieldById(sprintf('%s%dOption%dText', $subList->prefix, $commandIndex, $optionIndex));
            $this->setStatus(sprintf('Added option %d.', $optionIndex + 1), StatusLevel::SUCCESS);
            $this->renderDatabaseArea();
            $this->beginDatabaseEdit();

            return;
        }

        $afterIndex = preg_match('/^' . $prefix . '(\\d+)/', $selectedId, $matches) === 1 ? intval($matches[1]) : null;
        $commandIndex = $database->addFrameCommand($recordIndex, $framePath, $afterIndex);

        if ($commandIndex === null) {
            return;
        }

        $blank = $subList->blank;
        $this->recordCommand(new GenericCommand(
            sprintf('%s add', ucfirst($subList->singular)),
            static fn() => $database->insertFrameCommand($recordIndex, $framePath, $commandIndex, $blank),
            static fn() => $database->removeFrameCommand($recordIndex, $framePath, $commandIndex),
        ));
        $this->selectDatabaseFieldById(sprintf('%s%dType', $subList->prefix, $commandIndex));
        $this->setStatus(sprintf('Added %s %d.', $subList->singular, $commandIndex + 1), StatusLevel::SUCCESS);
        $this->renderDatabaseArea();
    }

    /**
     * Removes what the cursor points at: an option with its whole arm, or
     * the cursor's command from the open frame.
     *
     * @param ProjectRecordDatabase $database The category.
     * @param RecordSubList $subList The sub-list schema.
     * @return void
     */
    private function removeDatabaseFrameItem(ProjectRecordDatabase $database, RecordSubList $subList): void
    {
        $recordIndex = $this->getSelectedRecordIndex();
        $framePath = $this->databaseCommandFramePath;
        $selectedId = (string) ($this->getDatabaseSettingsFields()[$this->databaseSelectedSettingIndex]['field'] ?? '');
        $prefix = preg_quote($subList->prefix, '/');

        if (preg_match('/^' . $prefix . '(\\d+)Option(\\d+)/', $selectedId, $matches) === 1) {
            $commandIndex = intval($matches[1]);
            $optionIndex = intval($matches[2]);
            $removed = $database->removeChoiceOption($recordIndex, $framePath, $commandIndex, $optionIndex);

            if ($removed === null) {
                return;
            }

            $armCount = count((array) ($removed['then'] ?? []));
            $this->clampDatabaseSettingSelection();
            $this->recordCommand(new GenericCommand(
                'Option remove',
                static fn() => $database->removeChoiceOption($recordIndex, $framePath, $commandIndex, $optionIndex),
                static fn() => $database->insertChoiceOption($recordIndex, $framePath, $commandIndex, $optionIndex, $removed),
            ));
            $this->setStatus(
                $armCount > 0
                    ? sprintf('Removed option %d and its %d commands.', $optionIndex + 1, $armCount)
                    : sprintf('Removed option %d.', $optionIndex + 1),
                StatusLevel::SUCCESS,
            );
            $this->renderDatabaseArea();

            return;
        }

        $commands = $database->getFrameCommands($recordIndex, $framePath) ?? [];
        $commandIndex = preg_match('/^' . $prefix . '(\\d+)/', $selectedId, $matches) === 1
            ? intval($matches[1])
            : count($commands) - 1;
        $removed = $database->removeFrameCommand($recordIndex, $framePath, $commandIndex);

        if ($removed === null) {
            return;
        }

        $this->clampDatabaseSettingSelection();
        $this->recordCommand(new GenericCommand(
            sprintf('%s remove', ucfirst($subList->singular)),
            static fn() => $database->removeFrameCommand($recordIndex, $framePath, $commandIndex),
            static fn() => $database->insertFrameCommand($recordIndex, $framePath, $commandIndex, $removed),
        ));
        $this->setStatus(sprintf('Removed %s %d.', $subList->singular, $commandIndex + 1), StatusLevel::SUCCESS);
        $this->renderDatabaseArea();
    }

    /**
     * Puts the settings cursor on a field by its id.
     *
     * @param string $fieldId The field id.
     * @return void
     */
    private function selectDatabaseFieldById(string $fieldId): void
    {
        foreach ($this->getDatabaseSettingsFields() as $index => $field) {
            if (($field['field'] ?? null) === $fieldId) {
                $this->databaseSelectedSettingIndex = $index;

                return;
            }
        }
    }

    /**
     * Keeps the settings cursor inside the pane after rows disappear.
     *
     * @return void
     */
    private function clampDatabaseSettingSelection(): void
    {
        $this->databaseSelectedSettingIndex = min(
            $this->databaseSelectedSettingIndex,
            max(0, count($this->getDatabaseSettingsFields()) - 1),
        );
    }

    /**
     * Opens a command frame: an option's or a branch arm's own command list.
     *
     * @param array<int, int|string> $framePath The frame to open.
     * @return void
     */
    private function enterCommandFrame(array $framePath): void
    {
        $database = $this->activeRecordDatabase();

        if (! $database instanceof ProjectRecordDatabase || $database->getFrameCommands($this->activeRecordIndex(), $framePath) === null) {
            return;
        }

        $this->databaseCommandFramePath = $framePath;
        $this->databaseSelectedSettingIndex = 0;
        $this->setStatus($database->describeFramePath($framePath) . '.', StatusLevel::INFO);
        $this->renderDatabasePanes(['settings', 'cue']);
    }

    /**
     * Returns the record pane the frame keys act on: the map's NPCs while
     * the Inspector hosts them, otherwise the Database's selected category.
     *
     * @return ProjectRecordDatabase|null The pane.
     */
    private function activeRecordDatabase(): ?ProjectRecordDatabase
    {
        return $this->isNpcInspectorHosting()
            ? $this->npcInspector?->records()
            : $this->getSelectedRecordDatabase();
    }

    /**
     * Returns the record the frame keys act on, in the active pane.
     *
     * @return int The record index.
     */
    private function activeRecordIndex(): int
    {
        return $this->isNpcInspectorHosting()
            ? ($this->selectedNpcIndex ?? 0)
            : $this->getSelectedRecordIndex();
    }

    /**
     * Leaves the open command frame for the one enclosing it.
     *
     * @return void
     */
    private function leaveCommandFrame(): void
    {
        $path = $this->databaseCommandFramePath;

        if ($path === []) {
            return;
        }

        $database = $this->activeRecordDatabase();

        // A record-level list is [key]; an option arm ends
        // [i, 'options', j, 'then']; a branch or variant arm [i, arm].
        $chunk = count($path) === 1 && is_string($path[0])
            ? 1
            : ((($path[count($path) - 3] ?? null) === 'options') ? 4 : 2);
        $enclosingCommand = $chunk === 1 ? null : intval($path[count($path) - $chunk]);
        $this->databaseCommandFramePath = array_slice($path, 0, count($path) - $chunk);
        $this->databaseSelectedSettingIndex = 0;

        // Land back on the row the frame belonged to: the record's own list
        // row, or the command (or sub-list entry) that holds the arm.
        $landing = $chunk === 1
            ? 'commandList' . ucfirst(strval($path[0]))
            : (($this->databaseCommandFramePath === [] ? ($database?->schema->subList?->prefix ?? 'command') : 'command') . $enclosingCommand);

        foreach ($this->getDatabaseSettingsFields() as $index => $field) {
            if (str_starts_with((string) ($field['field'] ?? ''), $landing)) {
                $this->databaseSelectedSettingIndex = $index;
                break;
            }
        }

        $this->setStatus(($database?->describeFramePath($this->databaseCommandFramePath) ?? 'Commands') . '.', StatusLevel::INFO);
        $this->renderDatabasePanes(['settings', 'cue']);
    }

    private function addDatabaseRecordSubItem(): void
    {
        $database = $this->getSelectedRecordDatabase();
        $subList = $database?->schema->subList;

        if (! $database instanceof ProjectRecordDatabase || $subList === null) {
            return;
        }

        if (! $database->isEditable()) {
            $this->setStatus($this->describeRecordReadOnly($database), StatusLevel::WARN);
            $this->renderDatabaseArea();
            return;
        }

        if ($database->hasCommandFrames()) {
            $this->addDatabaseFrameItem($database, $subList);
            return;
        }

        $recordIndex = $this->getSelectedRecordIndex();
        $entryIndex = $database->addSubItem($recordIndex);

        if ($entryIndex === null) {
            return;
        }

        $entry = $database->getRecordByIndex($recordIndex)?->getSubList($subList->key)[$entryIndex] ?? [];

        $this->recordCommand(new GenericCommand(
            sprintf('%s add', ucfirst($subList->singular)),
            static fn() => $database->insertSubItem($recordIndex, $entryIndex, $entry),
            static fn() => $database->removeSubItem($recordIndex, $entryIndex),
        ));

        $this->setStatus(sprintf('Added %s %d.', $subList->singular, $entryIndex + 1), StatusLevel::SUCCESS);
        $this->renderDatabaseArea();
    }

    /**
     * Removes the last sub-list entry and records it for undo.
     *
     * @return void
     */
    private function removeDatabaseRecordSubItem(): void
    {
        $database = $this->getSelectedRecordDatabase();
        $subList = $database?->schema->subList;

        if (! $database instanceof ProjectRecordDatabase || $subList === null) {
            return;
        }

        if (! $database->isEditable()) {
            $this->setStatus($this->describeRecordReadOnly($database), StatusLevel::WARN);
            $this->renderDatabaseArea();
            return;
        }

        if ($database->hasCommandFrames()) {
            $this->removeDatabaseFrameItem($database, $subList);
            return;
        }

        $recordIndex = $this->getSelectedRecordIndex();
        $entryIndex = $database->countSubItems($recordIndex) - 1;

        if ($entryIndex < 0) {
            $this->setStatus(sprintf('No %s to remove.', $subList->singular), StatusLevel::WARN);
            $this->renderFooter();
            return;
        }

        $removed = $database->removeSubItem($recordIndex, $entryIndex);

        if ($removed === null) {
            return;
        }

        $this->recordCommand(new GenericCommand(
            sprintf('%s remove', ucfirst($subList->singular)),
            static fn() => $database->removeSubItem($recordIndex, $entryIndex),
            static fn() => $database->insertSubItem($recordIndex, $entryIndex, $removed),
        ));

        $this->databaseSelectedSettingIndex = 0;
        $this->setStatus(sprintf('Removed %s %d.', $subList->singular, $entryIndex + 1), StatusLevel::SUCCESS);
        $this->renderDatabaseArea();
    }

    /**
     * Returns the nested list owned by the command under the settings cursor.
     *
     * @return array{parentIndex: int, nestedIndex: int|null, list: \Ichiloto\Editor\Database\RecordSubList}|null
     */
    private function selectedDatabaseNestedContext(): ?array
    {
        $database = $this->getSelectedRecordDatabase();

        if (! $database instanceof ProjectRecordDatabase) {
            return null;
        }

        $field = $this->getDatabaseSettingsFields()[$this->databaseSelectedSettingIndex] ?? null;
        $fieldId = is_array($field) ? strval($field['field'] ?? '') : '';

        // Inside a frame the parent is one of the frame's commands.
        return $database->frameNestedContext($this->getSelectedRecordIndex(), $this->databaseCommandFramePath, $fieldId);
    }

    /** Appends a structured nested item, such as a movement-route step. */
    private function addDatabaseNestedSubItem(): void
    {
        $database = $this->getSelectedRecordDatabase();
        $context = $this->selectedDatabaseNestedContext();

        if (! $database instanceof ProjectRecordDatabase || $context === null) {
            return;
        }

        $recordIndex = $this->getSelectedRecordIndex();
        $framePath = $this->databaseCommandFramePath;
        $parentIndex = $context['parentIndex'];
        $nestedIndex = $database->addFrameNestedItem($recordIndex, $framePath, $parentIndex);

        if ($nestedIndex === null) {
            return;
        }

        $entry = $context['list']->blank;

        $this->recordCommand(new GenericCommand(
            sprintf('%s add', ucfirst($context['list']->singular)),
            static fn() => $database->addFrameNestedItem($recordIndex, $framePath, $parentIndex, $entry, $nestedIndex),
            static fn() => $database->removeFrameNestedItem($recordIndex, $framePath, $parentIndex, $nestedIndex),
        ));
        $this->setStatus(
            sprintf('Added %s %d.', $context['list']->singular, $nestedIndex + 1),
            StatusLevel::SUCCESS,
        );
        $this->renderDatabaseArea();
    }

    /** Removes the selected (or last) structured nested item with undo. */
    private function removeDatabaseNestedSubItem(): void
    {
        $database = $this->getSelectedRecordDatabase();
        $context = $this->selectedDatabaseNestedContext();

        if (! $database instanceof ProjectRecordDatabase || $context === null) {
            return;
        }

        $recordIndex = $this->getSelectedRecordIndex();
        $framePath = $this->databaseCommandFramePath;
        $parentIndex = $context['parentIndex'];
        $nestedIndex = $context['nestedIndex']
            ?? ($database->countFrameNestedItems($recordIndex, $framePath, $parentIndex) - 1);

        if ($nestedIndex < 0) {
            $this->setStatus(sprintf('No %s to remove.', $context['list']->singular), StatusLevel::WARN);
            return;
        }

        $removed = $database->removeFrameNestedItem($recordIndex, $framePath, $parentIndex, $nestedIndex);

        if ($removed === null) {
            return;
        }

        $this->recordCommand(new GenericCommand(
            sprintf('%s remove', ucfirst($context['list']->singular)),
            static fn() => $database->removeFrameNestedItem($recordIndex, $framePath, $parentIndex, $nestedIndex),
            static fn() => $database->addFrameNestedItem($recordIndex, $framePath, $parentIndex, $removed, $nestedIndex),
        ));
        $this->databaseSelectedSettingIndex = min(
            $this->databaseSelectedSettingIndex,
            max(0, count($this->getDatabaseSettingsFields()) - 1),
        );
        $this->setStatus(
            sprintf('Removed %s %d.', $context['list']->singular, $nestedIndex + 1),
            StatusLevel::SUCCESS,
        );
        $this->renderDatabaseArea();
    }

    /**
     * Phrases a read-only category's reason for the status line.
     *
     * @param ProjectRecordDatabase $database The read-only database.
     * @return string
     */
    private function describeRecordReadOnly(ProjectRecordDatabase $database): string
    {
        return sprintf(
            '%s is read-only: %s.',
            $this->getSelectedDatabaseCategoryDefinition()->label,
            $database->getReadOnlyReason() ?? 'this category cannot be written',
        );
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
            return;
        }

        $this->createDatabaseRecord();
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
    /**
     * Returns the reward slot the settings cursor is on, if it is on one.
     *
     * @return int|null The slot.
     */
    private function selectedQuestRewardSlot(): ?int
    {
        $field = $this->getDatabaseSettingsFields()[$this->databaseSelectedSettingIndex] ?? null;

        if (is_array($field) && preg_match('/^rewardItem(\d+)$/', strval($field['field'] ?? '')) === 1) {
            return intval(substr(strval($field['field']), strlen('rewardItem')));
        }

        return null;
    }

    /**
     * Determines whether the cursor sits on the quest's reward fields, which
     * is where adding a first reward item should work from.
     *
     * @return bool True when it does.
     */
    private function isQuestRewardListSelected(): bool
    {
        $field = $this->getDatabaseSettingsFields()[$this->databaseSelectedSettingIndex] ?? null;

        return is_array($field)
            && in_array($field['field'] ?? '', ['rewardGold', 'rewardExperience', 'rewardItems'], true);
    }

    /**
     * Adds a reward item slot, below the cursor's slot when it is on one.
     *
     * @return void
     */
    private function addDatabaseQuestRewardItem(): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $questDatabase = $this->workspace->questDatabase;
        $questIndex = $this->databaseSelectedQuestIndex;
        $slot = $questDatabase->addRewardItem($questIndex, $this->selectedQuestRewardSlot());

        if ($slot === null) {
            return;
        }

        $this->recordCommand(new GenericCommand(
            'Reward item add',
            static fn() => $questDatabase->insertRewardItem($questIndex, $slot, 'S-Potion'),
            static fn() => $questDatabase->removeRewardItem($questIndex, $slot),
        ));

        // Land on the new row and open its picker: an unchosen placeholder
        // is not what anyone wanted to add.
        foreach ($this->getDatabaseSettingsFields() as $index => $field) {
            if (($field['field'] ?? null) === sprintf('rewardItem%d', $slot)) {
                $this->databaseSelectedSettingIndex = $index;
                break;
            }
        }

        $this->setStatus(sprintf('Reward item %d added.', $slot + 1), StatusLevel::SUCCESS);
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
        $this->beginDatabaseEdit();
    }

    /**
     * Removes the reward item slot the cursor is on.
     *
     * @return void
     */
    private function removeDatabaseQuestRewardItem(): void
    {
        $slot = $this->selectedQuestRewardSlot();

        if ($slot === null || ! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $questDatabase = $this->workspace->questDatabase;
        $questIndex = $this->databaseSelectedQuestIndex;
        $removed = $questDatabase->removeRewardItem($questIndex, $slot);

        if ($removed === null) {
            return;
        }

        $this->databaseSelectedSettingIndex = min(
            $this->databaseSelectedSettingIndex,
            max(0, count($this->getDatabaseSettingsFields()) - 1)
        );
        $this->recordCommand(new GenericCommand(
            'Reward item remove',
            static fn() => $questDatabase->removeRewardItem($questIndex, $slot),
            static fn() => $questDatabase->insertRewardItem($questIndex, $slot, $removed),
        ));
        $this->setStatus(sprintf('Reward item %d removed (%s).', $slot + 1, $removed), StatusLevel::SUCCESS);
        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
    }

    private function addDatabaseQuestObjective(): void
    {
        if ($this->selectedQuestRewardSlot() !== null || $this->isQuestRewardListSelected()) {
            $this->addDatabaseQuestRewardItem();

            return;
        }

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
        if ($this->selectedQuestRewardSlot() !== null) {
            $this->removeDatabaseQuestRewardItem();

            return;
        }

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
                $this->backupBeforeSave(...$this->getDatabaseBackupPaths($this->workspace->actorDatabase));
                $this->workspace->actorDatabase->save();
                $this->setStatus('Actor database saved.', StatusLevel::SUCCESS);
            } elseif ($this->isClassesDatabaseSelected()) {
                $this->backupBeforeSave(...$this->getDatabaseBackupPaths($this->workspace->classDatabase));
                $this->workspace->classDatabase->save();
                $this->setStatus('Class database saved.', StatusLevel::SUCCESS);
            } elseif ($this->isSkillsDatabaseSelected()) {
                $this->backupBeforeSave(...$this->getDatabaseBackupPaths($this->workspace->skillDatabase));
                $this->workspace->skillDatabase->save();
                $this->setStatus('Skill database saved.', StatusLevel::SUCCESS);
            } elseif ($this->isQuestsDatabaseSelected()) {
                $this->backupBeforeSave(...$this->getDatabaseBackupPaths($this->workspace->questDatabase));
                $this->workspace->questDatabase->save();
                $this->setStatus('Quest database saved.', StatusLevel::SUCCESS);
            } elseif ($this->isAnimationsDatabaseSelected()) {
                $this->backupBeforeSave(...$this->getDatabaseBackupPaths($this->workspace->animationDatabase));
                $this->workspace->animationDatabase->save();
                $this->setStatus('Animation database saved.', StatusLevel::SUCCESS);
            } elseif ($this->isSystemDatabaseSelected()) {
                $this->backupBeforeSave(...$this->getDatabaseBackupPaths($this->workspace->systemDatabase));
                $this->workspace->systemDatabase->save();
                $this->setStatus('System database saved.', StatusLevel::SUCCESS);
            } elseif (($recordDatabase = $this->getSelectedRecordDatabase()) instanceof ProjectRecordDatabase) {
                if (! $recordDatabase->isEditable()) {
                    $this->setStatus($this->describeRecordReadOnly($recordDatabase), StatusLevel::WARN);
                } else {
                    $this->backupBeforeSave(...$this->getDatabaseBackupPaths($recordDatabase));
                    $recordDatabase->save();
                    $this->setStatus(
                        sprintf('%s database saved.', $this->getSelectedDatabaseCategoryDefinition()->label),
                        StatusLevel::SUCCESS,
                    );
                }
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
        if ($this->isNpcInspectorHosting()) {
            // The Inspector hosts the record pane in NPC mode: same fields,
            // pickers, condition and write editors, and command frames as
            // any Database category, over the map's own NPCs.
            return $this->getNpcInspectorFields();
        }

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

        $recordDatabase = $this->getSelectedRecordDatabase();

        if ($recordDatabase instanceof ProjectRecordDatabase) {
            if ($recordDatabase->hasCommandFrames()) {
                $fields = $recordDatabase->getFrameSettingsFields(
                    $this->getSelectedRecordIndex(),
                    $this->databaseCommandFramePath,
                );

                if (
                    $this->databaseCommandFramePath !== []
                    && $recordDatabase->getFrameCommands($this->getSelectedRecordIndex(), $this->databaseCommandFramePath) === null
                ) {
                    // The frame no longer resolves (an undo removed its
                    // command): fall back to the script rather than a void.
                    // An empty frame still resolves, and stays open.
                    $this->databaseCommandFramePath = [];

                    return $recordDatabase->getFrameSettingsFields($this->getSelectedRecordIndex(), []);
                }

                return $fields;
            }

            return $recordDatabase->getSettingsFields($this->getSelectedRecordIndex());
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
    /**
     * The rows that say which actor this is to a save.
     *
     * @param ProjectActor $actor The actor.
     * @return array<int, array<string, mixed>> The rows.
     */
    private function actorIdentityFields(ProjectActor $actor): array
    {
        return [
            ['label' => 'Identity', 'value' => '', 'editable' => false, 'field' => ''],
            [
                'label' => 'Definition Id',
                'value' => $actor->hasDefinitionId() ? $actor->getDefinitionId() : '',
                'control' => new InputControl(InputControlType::TEXT, $actor->hasDefinitionId() ? $actor->getDefinitionId() : ''),
                'field' => 'id',
                // A project that declares no id is resolved by name, which is
                // what strands a save when the actor is renamed.
                'displayDefault' => sprintf('%s (the name; declare an id so a rename keeps saves)', $actor->getName()),
            ],
        ];
    }

    /**
     * The rows for an actor's own nature: the adjustments it makes to its
     * class baseline, and the named variants of that nature.
     *
     * While an actor declares variants the runtime reads the selected
     * variant's adjustments and ignores the fixed ones, so the rows edit
     * whichever set is actually in force.
     *
     * @param ProjectActor $actor The actor.
     * @return array<int, array<string, mixed>> The rows.
     */
    private function actorNatureFields(ProjectActor $actor): array
    {
        $variants = $actor->getNaturalVariants();
        $selected = $this->selectedActorVariantId($actor);
        $rows = [['label' => 'Nature', 'value' => '', 'editable' => false, 'field' => '']];

        if ($variants !== []) {
            $rows[] = [
                'label' => 'Default Variant',
                'value' => (string) $actor->getDefaultNaturalVariantId(),
                'options' => array_keys($variants),
                'field' => 'defaultNaturalVariantId',
            ];
            $rows[] = [
                'label' => 'Editing Variant',
                'value' => $selected ?? '',
                'options' => array_keys($variants),
                'field' => self::ACTOR_VARIANT_FIELD,
            ];
        }

        // Both layers are real: the runtime adds the selected variant on top
        // of the fixed adjustments rather than replacing them, so both are
        // shown and both are editable.
        $inForce = $actor->getNaturalAdjustmentsFor($selected);
        $fixed = $actor->getActorNaturalAdjustments();
        $rows = [...$rows, ...$this->actorAdjustmentRows(
            $variants === [] ? 'Adjustments' : 'Fixed, always applied',
            'actorNaturalAdjustments',
            $fixed,
        )];

        if ($variants !== [] && $selected !== null) {
            $rows = [...$rows, ...$this->actorAdjustmentRows(
                sprintf('Variant %s, added on top', $selected),
                sprintf('naturalVariants.%s', $selected),
                $variants[$selected] ?? [],
            )];
            $rows[] = [
                'label' => '  In force',
                'value' => $this->describeAdjustments($inForce),
                'editable' => false,
                'field' => '',
            ];
        }

        return $rows;
    }

    /**
     * The rows for one layer of an actor's nature.
     *
     * @param string $heading What the layer is.
     * @param string $prefix The payload path the rows write to.
     * @param array<string, int> $adjustments The layer's adjustments.
     * @return array<int, array<string, mixed>> The rows.
     */
    private function actorAdjustmentRows(string $heading, string $prefix, array $adjustments): array
    {
        $rows = [['label' => '  ' . $heading, 'value' => '', 'editable' => false, 'field' => '']];

        foreach (ActorStatPreview::statKeys() as $key) {
            $amount = $adjustments[$key] ?? 0;
            $rows[] = [
                'label' => '    ' . ucfirst(strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $key))),
                'value' => (string) $amount,
                'control' => new InputControl(InputControlType::INTEGER, (string) $amount),
                'field' => $prefix . '.' . $key,
            ];
        }

        return $rows;
    }

    /**
     * Describes what an actor's nature comes to once composed.
     *
     * @param array<string, int> $adjustments The composed adjustments.
     * @return string The description.
     */
    private function describeAdjustments(array $adjustments): string
    {
        $parts = [];

        foreach ($adjustments as $key => $amount) {
            if ($amount !== 0) {
                $parts[] = sprintf('%+d %s', $amount, $key);
            }
        }

        return $parts === [] ? 'nothing adjusted' : implode(', ', $parts);
    }

    /**
     * The read-only rows showing what each stat actually comes to.
     *
     * @param ProjectActor $actor The actor.
     * @return array<int, array<string, mixed>> The rows.
     */
    private function actorStatPreviewFields(ProjectActor $actor): array
    {
        if (! ActorStatPreview::isAvailable()) {
            return [];
        }

        $catalog = PermanentGrowthCatalog::fromProject($this->workspace->projectRoot);
        $assumed = $this->assumedGrowthFor($actor);
        $rows = [
            ['label' => 'Resolved Stats', 'value' => '', 'editable' => false, 'field' => ''],
            [
                // Earned growth is save state. What a preview can do is
                // assume some of it, say that it is assuming, and write
                // nothing.
                'label' => '  Assumed Growth',
                'value' => $assumed,
                'options' => [
                    self::NO_ASSUMED_GROWTH,
                    self::ALL_ASSUMED_GROWTH,
                    ...$catalog->ids(),
                ],
                'field' => self::ACTOR_GROWTH_FIELD,
                'displayDefault' => 'assumed for this preview only; the party earns growth in play',
            ],
        ];

        $permanent = match ($assumed) {
            self::NO_ASSUMED_GROWTH => [],
            self::ALL_ASSUMED_GROWTH => $catalog->totalsFor(),
            default => $catalog->totalsFor([$assumed]),
        };

        foreach (ActorStatPreview::resolve($actor, $this->selectedActorVariantId($actor), $permanent) as $row) {
            $rows[] = [
                'label' => '  ' . $row['stat'],
                'value' => ActorStatPreview::describeRow($row),
                'editable' => false,
                'field' => '',
            ];
        }

        return [...$rows, ...$this->actorOptimizeFields($actor)];
    }

    /**
     * Returns which permanent growth this actor's preview assumes.
     *
     * @param ProjectActor $actor The actor.
     * @return string The selection.
     */
    private function assumedGrowthFor(ProjectActor $actor): string
    {
        return $this->actorGrowthSelections[$actor->getDefinitionId()] ?? self::NO_ASSUMED_GROWTH;
    }

    /**
     * Returns which slot this actor's Optimize preview fills.
     *
     * @param ProjectActor $actor The actor.
     * @return string The semantic slot.
     */
    private function optimizeSlotFor(ProjectActor $actor): string
    {
        $slots = EquipmentOptimizationPolicy::slotKeys();
        $selected = $this->actorOptimizeSlots[$actor->getDefinitionId()] ?? '';

        return in_array($selected, $slots, true) ? $selected : ($slots[0] ?? 'weapon');
    }

    /**
     * The read-only rows showing what Optimize would choose, and why.
     *
     * @param ProjectActor $actor The actor.
     * @return array<int, array<string, mixed>> The rows.
     */
    private function actorOptimizeFields(ProjectActor $actor): array
    {
        if (! $this->workspace instanceof ProjectWorkspace || ! EquipmentOptimizationPolicy::isAvailable()) {
            return [];
        }

        $root = $this->workspace->projectRoot;
        $slot = $this->optimizeSlotFor($actor);
        $rows = [
            ['label' => 'Optimize Preview', 'value' => '', 'editable' => false, 'field' => ''],
            [
                // Which policy is scoring is the first thing to know: a
                // project that has declared none is not being scored by its
                // own rules at all.
                'label' => '  Policy',
                'value' => EquipmentOptimizationPolicy::describeSource($root),
                'editable' => false,
                'field' => '',
            ],
            [
                'label' => '  Slot',
                'value' => $slot,
                'options' => EquipmentOptimizationPolicy::slotKeys(),
                'field' => self::ACTOR_OPTIMIZE_SLOT_FIELD,
            ],
        ];
        $ranked = EquipmentOptimizationPolicy::rank($this->workspace, $actor, $slot);

        if ($ranked === []) {
            $rows[] = [
                'label' => '  (nothing)',
                'value' => 'no equipment this project has fits that slot',
                'editable' => false,
                'field' => '',
            ];

            return $rows;
        }

        foreach ($ranked as $position => $candidate) {
            $rows[] = [
                'label' => sprintf('  %d. %s', $position + 1, $candidate['name']),
                'value' => sprintf(
                    '%d · %s',
                    $candidate['value'],
                    EquipmentOptimizationPolicy::describeRow($candidate),
                ),
                'editable' => false,
                'field' => '',
            ];
        }

        return $rows;
    }

    /**
     * Returns which natural variant the actor rows are editing.
     *
     * @param ProjectActor $actor The actor.
     * @return string|null The variant id, or null when the actor has none.
     */
    private function selectedActorVariantId(ProjectActor $actor): ?string
    {
        $variants = $actor->getNaturalVariants();

        if ($variants === []) {
            return null;
        }

        $selected = $this->actorVariantSelections[$actor->getDefinitionId()] ?? null;

        return $selected !== null && isset($variants[$selected])
            ? $selected
            : ($actor->getDefaultNaturalVariantId() ?? array_key_first($variants));
    }

    private function getDatabaseActorSettingsFields(): array
    {
        $actor = $this->getSelectedActor();

        if (! $actor instanceof ProjectActor) {
            return [];
        }

        return [
            ...$this->actorIdentityFields($actor),
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
                // The character-class reference: a name from
                // assets/Data/classes.php, written to the actor's
                // data['class'] key (the engine's ClassStore hydrates it
                // into a CharacterRole). ←/→ cycles the picker; Ctrl+G jumps
                // to the class entry.
                'label' => 'Class',
                'value' => $actor->getClassName() === '' ? ProjectActor::CLASS_NONE : $actor->getClassName(),
                'options' => $this->getActorClassOptions(),
                'field' => 'class',
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
            ...$this->actorNatureFields($actor),
            ...$this->actorStatPreviewFields($actor),
        ];
    }

    /**
     * Returns the actor class picker options.
     *
     * The list is the project's own class names from
     * `assets/Data/classes.php`, prefixed with the "none" sentinel that
     * clears the reference.
     *
     * @return string[]
     */
    private function getActorClassOptions(): array
    {
        return [
            ProjectActor::CLASS_NONE,
            ...array_map(
                static fn(ProjectClass $class): string => $class->getName(),
                $this->workspace?->classDatabase->getClasses() ?? [],
            ),
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
     * target, quantity, spoiler-safe text, and conditional revealed text) so
     * the flat settings pane can edit the nested list; Shift+O / Shift+X add
     * and remove objectives.
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
            // Derived from the name, so an id is never invented or mistyped.
            ['label' => 'Id', 'value' => $quest->getId(), 'field' => 'id'],
            ['label' => 'Name', 'value' => $quest->getName(), 'control' => new InputControl(InputControlType::TEXT, $quest->getName()), 'field' => 'name'],
            ['label' => 'Description', 'value' => $quest->getDescription(), 'control' => new InputControl(InputControlType::TEXT, $quest->getDescription()), 'field' => 'description'],
            ['label' => 'Giver', 'value' => $quest->getGiver(), 'control' => new InputControl(InputControlType::TEXT, $quest->getGiver()), 'field' => 'giver'],
            ['label' => 'Reward Gold', 'value' => (string) $quest->getRewardGold(), 'control' => new InputControl(InputControlType::INTEGER, (string) $quest->getRewardGold()), 'field' => 'rewardGold'],
            ['label' => 'Reward EXP', 'value' => (string) $quest->getRewardExperience(), 'control' => new InputControl(InputControlType::INTEGER, (string) $quest->getRewardExperience()), 'field' => 'rewardExperience'],
            ['label' => 'Prereqs', 'value' => $quest->getPrerequisitesString(), 'conditions' => true, 'field' => 'prerequisites'],
        ];

        // One row per reward item, picked from the inventory the engine's
        // store resolves them against. Shift+O on a row adds a slot below
        // it; Shift+X or Del removes the one the cursor is on.
        foreach ($quest->getRewardItems() as $slot => $item) {
            $fields[] = [
                'label' => sprintf('Reward Item %d', $slot + 1),
                'value' => $item,
                'reference' => 'inventory',
                'field' => sprintf('rewardItem%d', $slot),
            ];
        }

        foreach ($quest->getObjectives() as $index => $objective) {
            $label = sprintf('Obj %d', $index + 1);
            $type = strval($objective['type'] ?? QuestObjectiveType::TALK_TO->value);
            $target = strval($objective['target'] ?? '');
            $quantity = (string) max(1, intval($objective['quantity'] ?? 1));
            $description = strval($objective['description'] ?? '');
            $revealedDescription = strval($objective['revealedDescription'] ?? '');
            $revealConditions = ConditionCodec::encodeAll((array) ($objective['revealConditions'] ?? []));
            $fields[] = [
                'label' => $label . ' Type',
                'value' => $type,
                'options' => array_map(static fn(QuestObjectiveType $objectiveType): string => $objectiveType->value, QuestObjectiveType::cases()),
                'field' => sprintf('objective%dType', $index),
            ];
            $reference = self::questObjectiveReference($type);
            $targetField = [
                'label' => $label . ' Target',
                'value' => $target,
                'field' => sprintf('objective%dTarget', $index),
            ];

            // What a target may be depends on what the objective asks for, so
            // the picker follows the type: an item to collect, an enemy to
            // defeat, a map to reach.
            if ($reference !== null) {
                $targetField['reference'] = $reference;
            } else {
                $targetField['control'] = new InputControl(InputControlType::TEXT, $target);
            }

            $fields[] = $targetField;
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
            $fields[] = [
                'label' => $label . ' Revealed',
                'value' => $revealedDescription,
                'control' => new InputControl(InputControlType::TEXT, $revealedDescription),
                'field' => sprintf('objective%dRevealedDescription', $index),
            ];
            $fields[] = [
                'label' => $label . ' Reveal When',
                'value' => $revealConditions,
                'conditions' => true,
                'field' => sprintf('objective%dRevealConditions', $index),
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

        // A field that names another record is chosen from, never typed into.
        if (is_string($field['reference'] ?? null) && $this->openReferencePicker($field)) {
            return;
        }

        if (($field['conditions'] ?? false) === true) {
            $this->openConditionEditor($field);

            return;
        }

        if (($field['affinities'] ?? false) === true) {
            $this->openAffinityEditor($field);

            return;
        }

        if (($field['worldWrites'] ?? false) === true) {
            $this->openWorldWriteEditor($field);

            return;
        }

        if (is_array($field['frame'] ?? null)) {
            $this->enterCommandFrame($field['frame']);

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
     * Returns the fullest form of a pane's key hint that its border fits.
     *
     * A hint cut off mid-word ("Shift+X/De") is worse than a shorter one that
     * reads: it looks like a bug and it teaches nothing. Callers pass the
     * forms they would like in order, longest first, and the last is the one
     * that has to fit anywhere.
     *
     * @param int $windowWidth The window's full width.
     * @param string ...$candidates The forms, longest first.
     * @return string The one that fits.
     */
    private function fitHelp(int $windowWidth, string ...$candidates): string
    {
        // EditorWindow spends a corner and a border character before the
        // label, and keeps one for the closing corner.
        $available = max(0, $windowWidth - 3);

        foreach ($candidates as $candidate) {
            if (mb_strwidth($candidate) <= $available) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Determines whether the selected record has a list to add to.
     *
     * @return bool True when it has.
     */
    private function hasDatabaseSubList(): bool
    {
        return $this->isQuestsDatabaseSelected()
            || $this->getSelectedRecordDatabase()?->schema->subList !== null;
    }

    /**
     * Removes whichever kind of sub-item the settings cursor is on.
     *
     * @return void
     */
    private function removeDatabaseSubItem(): void
    {
        if ($this->isQuestsDatabaseSelected()) {
            $this->removeDatabaseQuestObjective();

            return;
        }

        if ($this->getSelectedRecordDatabase()?->schema->subList !== null) {
            $this->selectedDatabaseNestedContext() !== null
                ? $this->removeDatabaseNestedSubItem()
                : $this->removeDatabaseRecordSubItem();
        }
    }

    /**
     * Renames the selected quest, and its id with it where that is safe.
     *
     * @param string $name The new name.
     * @return void
     */
    private function renameSelectedQuest(string $name): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $questDatabase = $this->workspace->questDatabase;
        $questIndex = $this->databaseSelectedQuestIndex;
        $quest = $questDatabase->getQuestByIndex($questIndex);

        if (! $quest instanceof ProjectQuest) {
            return;
        }

        $previousName = $quest->getName();
        $previousId = $quest->getId();
        $isReferenced = new QuestReferences($this->workspace)->exist($previousId);
        $newId = $questDatabase->renameQuest($questIndex, $name, ! $isReferenced);

        $this->recordCommand(new GenericCommand(
            'Quest rename',
            static fn() => $questDatabase->renameQuest($questIndex, $name, ! $isReferenced),
            static function () use ($questDatabase, $questIndex, $previousName, $previousId): void {
                $questDatabase->renameQuest($questIndex, $previousName, false);
                $questDatabase->setField($questIndex, 'id', $previousId);
            },
        ));

        if (is_string($newId)) {
            $this->setStatus(sprintf('Renamed. Its id is now %s.', $newId), StatusLevel::SUCCESS);

            return;
        }

        if ($isReferenced) {
            // Saying so beats an id that silently stops matching its name.
            $this->setStatus(
                sprintf('Renamed. Its id stays %s, which other things point at.', $previousId),
                StatusLevel::INFO
            );
        }
    }

    /**
     * Opens the world-write editor on a field that holds a `sets` list.
     *
     * @param array<string, mixed> $field The settings-pane field descriptor.
     * @return void
     */
    private function openWorldWriteEditor(array $field): void
    {
        $this->worldWriteEditor->open(
            (string) ($field['field'] ?? ''),
            (string) ($field['label'] ?? 'Writes'),
            WorldWriteCodec::decodeAll((string) ($field['value'] ?? '')),
        );
        $this->statusMessage = 'Building writes.';
        $this->renderDatabasePanes(['settings']);
    }

    /**
     * Handles input while world writes are being built.
     *
     * @param string $input The raw input.
     * @return void
     */
    private function handleWorldWriteEditorInput(string $input): void
    {
        if ($this->isWorldWriteNaming) {
            $this->handleWorldWriteNameInput($input);

            return;
        }

        if ($input === "\033" || $input === "\x1b") {
            $this->worldWriteEditor->close();
            $this->statusMessage = 'Writes unchanged.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->commitWorldWrites();

            return;
        }

        match (true) {
            str_contains($input, "\033[A") => $this->worldWriteEditor->move(-1),
            str_contains($input, "\033[B") => $this->worldWriteEditor->move(1),
            $input === 'a' => $this->worldWriteEditor->add(),
            $input === 'd' => $this->worldWriteEditor->remove(),
            $input === 't' => $this->worldWriteEditor->cycleType(1),
            $input === 'T' => $this->worldWriteEditor->cycleType(-1),
            $input === 'x', $input === 'X' => $this->worldWriteEditor->cycleExtra(1),
            $input === 'n' => $this->beginWorldWriteName(),
            $input === 'v' => $this->beginWorldWriteValue(),
            default => null,
        };

        $this->renderDatabasePanes(['settings']);
    }

    /**
     * Starts setting what the selected write names: a quest is picked, the
     * rest are typed.
     *
     * @return void
     */
    private function beginWorldWriteName(): void
    {
        $reference = $this->worldWriteEditor->nameReference();
        $set = $this->worldWriteEditor->selected();

        if ($reference === null || $set === null || ! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        if (! is_string($reference['category'])) {
            $this->isWorldWriteNaming = true;
            $this->worldWriteNameBuffer = strval($set['name'] ?? '');
            $this->worldWriteNamingValue = false;
            $this->statusMessage = sprintf('Naming the %s.', mb_strtolower($reference['label']));

            return;
        }

        $catalog = new ReferenceCatalog($this->workspace, $this->getSelectedMap());
        $values = $catalog->valuesFor($reference['category']);

        if (! $this->referencePicker->open(self::WORLD_WRITE_NAME_FIELD, $reference['label'], $reference['category'], $values, strval($set['name'] ?? ''), $catalog->labelsFor($reference['category']))) {
            $this->setStatus(sprintf('This project defines no %s to choose from.', $reference['category']), StatusLevel::WARN);
        }
    }

    /**
     * Starts typing a variable write's value.
     *
     * @return void
     */
    private function beginWorldWriteValue(): void
    {
        $set = $this->worldWriteEditor->selected();

        if ($set === null || strval($set['type'] ?? '') !== 'variable') {
            return;
        }

        $this->isWorldWriteNaming = true;
        $this->worldWriteNamingValue = true;
        $this->worldWriteNameBuffer = strval($set['value'] ?? '0');
        $this->statusMessage = 'Typing the value.';
    }

    /**
     * Handles input while a write's name or value is being typed.
     *
     * @param string $input The raw input.
     * @return void
     */
    private function handleWorldWriteNameInput(string $input): void
    {
        if ($input === "\033" || $input === "\x1b") {
            $this->isWorldWriteNaming = false;
            $this->worldWriteNameBuffer = '';
            $this->statusMessage = 'Unchanged.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($input === "\n" || $input === "\r") {
            if ($this->worldWriteNamingValue) {
                $this->worldWriteEditor->setValue(trim($this->worldWriteNameBuffer));
            } else {
                $this->worldWriteEditor->setName(trim($this->worldWriteNameBuffer));
            }

            $this->isWorldWriteNaming = false;
            $this->worldWriteNameBuffer = '';
            $this->statusMessage = 'Building writes.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($input === "\x7f" || $input === "\x08") {
            $this->worldWriteNameBuffer = mb_substr($this->worldWriteNameBuffer, 0, max(0, mb_strlen($this->worldWriteNameBuffer) - 1));
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if (mb_strlen($input) === 1 && ! ctype_cntrl($input)) {
            $this->worldWriteNameBuffer .= $input;
            $this->renderDatabasePanes(['settings']);
        }
    }

    /**
     * Stores the writes as the line the record layer decodes.
     *
     * @return void
     */
    private function commitWorldWrites(): void
    {
        $fieldId = $this->worldWriteEditor->fieldId();
        $label = $this->worldWriteEditor->label();
        $encoded = $this->worldWriteEditor->encoded();
        $this->worldWriteEditor->close();

        $field = null;

        foreach ($this->getDatabaseSettingsFields() as $candidate) {
            if (is_array($candidate) && ($candidate['field'] ?? null) === $fieldId) {
                $field = $candidate;
            }
        }

        if (! is_array($field)) {
            return;
        }

        try {
            $this->applyDatabaseFieldValueRecorded($field, $encoded);
            $this->setStatus(sprintf('%s updated.', $label), StatusLevel::SUCCESS);
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, sprintf('%s edit', $label));
        }

        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
    }

    /**
     * Returns the rows shown while writes are being built.
     *
     * @return string[] The rows.
     */
    private function buildWorldWriteEditorRows(): array
    {
        if ($this->isWorldWriteNaming) {
            $reference = $this->worldWriteEditor->nameReference();

            return [
                sprintf('%s: %s', $this->worldWriteNamingValue ? 'Value' : ($reference['label'] ?? 'Name'), $this->worldWriteNameBuffer),
                '',
                '  Enter to accept, Esc to leave it alone.',
            ];
        }

        $rows = $this->worldWriteEditor->rows();
        $lines = [sprintf('%s · %d', $this->worldWriteEditor->label(), count($rows)), ''];

        if ($rows === []) {
            $lines = [...$lines, ...SettingsPaneLayout::wrapProse('  None. Nothing changes when this completes.', $this->recordPaneMetrics()['width'])];
            $lines[] = '';
            $lines = [...$lines, ...SettingsPaneLayout::wrapProse('  a to add a write.', $this->recordPaneMetrics()['width'])];

            return $lines;
        }

        $selectedIndex = $this->worldWriteEditor->selectedIndex();

        foreach ($rows as $index => $row) {
            $lines[] = sprintf('%s%s', $index === $selectedIndex ? '> ' : '  ', $row);
        }

        // Two header lines, then the rows in whatever height the pane has.
        $visibleRows = max(1, $this->recordPaneMetrics()['rows'] - 2);

        return [...array_slice($lines, 0, 2), ...ScrollWindow::slice(array_slice($lines, 2), $selectedIndex, $visibleRows)];
    }

    /**
     * Opens the affinity editor on a field that holds an element map.
     *
     * @param array<string, mixed> $field The settings-pane field descriptor.
     * @return void
     */
    private function openAffinityEditor(array $field): void
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $elements = new ReferenceCatalog($this->workspace, $this->getSelectedMap())->valuesFor('elements');

        if ($elements === []) {
            // No element enum, no rows to build: saying so beats an editor
            // that opens with nothing to pick.
            $this->setStatus(
                'This project defines no element types under assets/Data/Types.',
                StatusLevel::WARN
            );
            $this->renderDatabasePanes(['settings']);

            return;
        }

        $this->affinityEditor->open(
            (string) ($field['field'] ?? ''),
            (string) ($field['label'] ?? 'Elemental'),
            ElementAffinityCodec::decodeAll((string) ($field['value'] ?? '')),
            $elements,
        );

        $this->statusMessage = 'Building elemental wards.';
        $this->renderDatabasePanes(['settings']);
    }

    /**
     * Handles input while affinities are being built.
     *
     * @param string $input The raw input.
     * @return void
     */
    private function handleAffinityEditorInput(string $input): void
    {
        if ($input === "\033" || $input === "\x1b") {
            $this->affinityEditor->close();
            $this->statusMessage = 'Elemental wards unchanged.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->commitAffinities();

            return;
        }

        match (true) {
            str_contains($input, "\033[A") => $this->affinityEditor->move(-1),
            str_contains($input, "\033[B") => $this->affinityEditor->move(1),
            $input === 'a' => $this->affinityEditor->add(),
            $input === 'd' => $this->affinityEditor->remove(),
            $input === 'x' => $this->affinityEditor->cycleEffect(1),
            $input === 'X' => $this->affinityEditor->cycleEffect(-1),
            $input === 'n' => $this->beginAffinityElement(),
            default => null,
        };

        $this->renderDatabasePanes(['settings']);
    }

    /**
     * Opens the element picker for the selected affinity row.
     *
     * @return void
     */
    private function beginAffinityElement(): void
    {
        $row = $this->affinityEditor->selected();

        if ($row === null) {
            return;
        }

        $this->referencePicker->open(
            self::AFFINITY_ELEMENT_FIELD,
            'Element',
            'elements',
            $this->affinityEditor->elements(),
            $row['element'],
        );
    }

    /**
     * Stores the affinities as the line the record layer decodes.
     *
     * @return void
     */
    private function commitAffinities(): void
    {
        $fieldId = $this->affinityEditor->fieldId();
        $label = $this->affinityEditor->label();
        $encoded = $this->affinityEditor->encoded();
        $this->affinityEditor->close();

        $field = null;

        foreach ($this->getDatabaseSettingsFields() as $candidate) {
            if (is_array($candidate) && ($candidate['field'] ?? null) === $fieldId) {
                $field = $candidate;
            }
        }

        if (! is_array($field)) {
            return;
        }

        try {
            $this->applyDatabaseFieldValueRecorded($field, $encoded);
            $this->setStatus(sprintf('%s updated.', $label), StatusLevel::SUCCESS);
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, sprintf('%s edit', $label));
        }

        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
    }

    /**
     * Returns the rows shown while affinities are being built.
     *
     * @return string[] The rows.
     */
    private function buildAffinityEditorRows(): array
    {
        $rows = $this->affinityEditor->describeRows();
        $lines = [sprintf('%s · %d', $this->affinityEditor->label(), count($rows)), ''];

        if ($rows === []) {
            $lines = [...$lines, ...SettingsPaneLayout::wrapProse('  None. This piece is neutral to every element.', $this->recordPaneMetrics()['width'])];
            $lines[] = '';
            $lines = [...$lines, ...SettingsPaneLayout::wrapProse('  a to add a ward.', $this->recordPaneMetrics()['width'])];

            return $lines;
        }

        $selectedIndex = $this->affinityEditor->selectedIndex();

        foreach ($rows as $index => $row) {
            $lines[] = sprintf('%s%s', $index === $selectedIndex ? '> ' : '  ', $row);
        }

        // Two header lines, then the rows in whatever height the pane has.
        $visibleRows = max(1, $this->recordPaneMetrics()['rows'] - 2);

        return [...array_slice($lines, 0, 2), ...ScrollWindow::slice(array_slice($lines, 2), $selectedIndex, $visibleRows)];
    }

    /**
     * Opens the condition editor on a field that holds a condition list.
     *
     * @param array<string, mixed> $field The settings-pane field descriptor.
     * @return void
     */
    private function openConditionEditor(array $field): void
    {
        $this->conditionEditor->open(
            (string) ($field['field'] ?? ''),
            (string) ($field['label'] ?? 'Conditions'),
            ConditionCodec::decodeAll((string) ($field['value'] ?? '')),
        );

        $this->statusMessage = 'Building conditions.';
        $this->renderDatabasePanes(['settings']);
    }

    /**
     * Handles input while conditions are being built.
     *
     * @param string $input The raw input.
     * @return void
     */
    private function handleConditionEditorInput(string $input): void
    {
        if ($this->isConditionNaming) {
            $this->handleConditionNameInput($input);

            return;
        }

        if ($input === "\033" || $input === "\x1b") {
            $this->conditionEditor->close();
            $this->statusMessage = 'Conditions unchanged.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->commitConditions();

            return;
        }

        $handled = match (true) {
            str_contains($input, "\033[A") => $this->conditionEditor->move(-1),
            str_contains($input, "\033[B") => $this->conditionEditor->move(1),
            $input === 'a' => $this->conditionEditor->add(),
            $input === 'd' => $this->conditionEditor->remove(),
            $input === 't' => $this->conditionEditor->cycleType(1),
            $input === 'T' => $this->conditionEditor->cycleType(-1),
            $input === 'x' => $this->conditionEditor->cycleExtra(1),
            $input === 'X' => $this->conditionEditor->cycleExtra(-1),
            $input === '!' => $this->conditionEditor->toggleNegate(),
            $input === 'n' => $this->beginConditionName(),
            default => null,
        };

        unset($handled);
        $this->renderDatabasePanes(['settings']);
    }

    /**
     * Starts setting what the selected condition names.
     *
     * A quest or an item is chosen from the project; a switch, story event or
     * variable is a name the author invents, so that one is typed.
     *
     * @return void
     */
    private function beginConditionName(): void
    {
        $reference = $this->conditionEditor->nameReference();
        $condition = $this->conditionEditor->selected();

        if ($reference === null || $condition === null || ! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        if (! is_string($reference['category'])) {
            $this->isConditionNaming = true;
            $this->conditionNameBuffer = strval($condition['name'] ?? '');
            $this->statusMessage = sprintf('Naming the %s.', mb_strtolower($reference['label']));

            return;
        }

        $catalog = new ReferenceCatalog($this->workspace, $this->getSelectedMap());
        $values = $catalog->valuesFor($reference['category']);

        if (! $this->referencePicker->open(
            self::CONDITION_NAME_FIELD,
            $reference['label'],
            $reference['category'],
            $values,
            strval($condition['name'] ?? ''),
            $catalog->labelsFor($reference['category']),
        )) {
            $this->setStatus(
                sprintf('This project defines no %s to choose from.', str_replace('_', ' ', $reference['category'])),
                StatusLevel::WARN
            );
        }
    }

    /**
     * Handles input while a condition's name is being typed.
     *
     * @param string $input The raw input.
     * @return void
     */
    private function handleConditionNameInput(string $input): void
    {
        if ($input === "\033" || $input === "\x1b") {
            $this->isConditionNaming = false;
            $this->conditionNameBuffer = '';
            $this->statusMessage = 'Name unchanged.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->conditionEditor->setName(trim($this->conditionNameBuffer));
            $this->isConditionNaming = false;
            $this->conditionNameBuffer = '';
            $this->statusMessage = 'Building conditions.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($input === "\x7f" || $input === "\x08") {
            $this->conditionNameBuffer = mb_substr(
                $this->conditionNameBuffer,
                0,
                max(0, mb_strlen($this->conditionNameBuffer) - 1)
            );
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if (mb_strlen($input) === 1 && ! ctype_cntrl($input)) {
            $this->conditionNameBuffer .= $input;
            $this->renderDatabasePanes(['settings']);
        }
    }

    /**
     * Stores the conditions as the line the data file holds.
     *
     * @return void
     */
    private function commitConditions(): void
    {
        $fieldId = $this->conditionEditor->fieldId();
        $label = $this->conditionEditor->label();
        $encoded = $this->conditionEditor->encoded();
        $this->conditionEditor->close();

        $field = null;

        foreach ($this->getDatabaseSettingsFields() as $candidate) {
            if (is_array($candidate) && ($candidate['field'] ?? null) === $fieldId) {
                $field = $candidate;
            }
        }

        if (! is_array($field)) {
            return;
        }

        try {
            $this->applyDatabaseFieldValueRecorded($field, $encoded);
            $this->setStatus(sprintf('%s updated.', $label), StatusLevel::SUCCESS);
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, sprintf('%s edit', $label));
        }

        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
    }

    /**
     * Returns the rows shown while conditions are being built.
     *
     * @return string[] The rows.
     */
    private function buildConditionEditorRows(): array
    {
        $rows = $this->conditionEditor->rows();

        if ($this->isConditionNaming) {
            $reference = $this->conditionEditor->nameReference();

            return [
                sprintf('%s: %s', $reference['label'] ?? 'Name', $this->conditionNameBuffer),
                '',
                '  Enter to accept, Esc to leave it alone.',
            ];
        }

        $lines = [sprintf('%s · %d', $this->conditionEditor->label(), count($rows)), ''];

        if ($rows === []) {
            $lines = [...$lines, ...SettingsPaneLayout::wrapProse('  None. Every condition holds, so this always runs.', $this->recordPaneMetrics()['width'])];
            $lines[] = '';
            $lines = [...$lines, ...SettingsPaneLayout::wrapProse('  a to add one.', $this->recordPaneMetrics()['width'])];

            return $lines;
        }

        $selectedIndex = $this->conditionEditor->selectedIndex();

        foreach ($rows as $index => $row) {
            $lines[] = sprintf('%s%s', $index === $selectedIndex ? '> ' : '  ', $row);
        }

        // Two header lines, then the rows in whatever height the pane has.
        $visibleRows = max(1, $this->recordPaneMetrics()['rows'] - 2);

        return [...array_slice($lines, 0, 2), ...ScrollWindow::slice(array_slice($lines, 2), $selectedIndex, $visibleRows)];
    }

    /**
     * Opens the picker on a field that names another record.
     *
     * @param array<string, mixed> $field The settings-pane field descriptor.
     * @return bool True when the picker opened.
     */
    private function openReferencePicker(array $field): bool
    {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return false;
        }

        $category = (string) ($field['reference'] ?? '');
        $label = (string) ($field['label'] ?? 'Reference');
        $catalog = new ReferenceCatalog($this->workspace, $this->getSelectedMap());
        $values = $catalog->valuesFor($category);
        $labels = $catalog->labelsFor($category);

        $specials = [];

        if (($field['allowsNone'] ?? false) === true) {
            // An optional reference needs a way back to nothing, or setting
            // it once would be a dead end. The row reads as what "nothing"
            // means to the runtime when the schema says so.
            $specials[] = (string) ($field['noneLabel'] ?? '(None)');
        }

        if (is_string($field['blankLabel'] ?? null)) {
            // Where the runtime gives an empty value a meaning of its own (a
            // page with no speaker), distinct from an absent one, that is a
            // row to pick as well.
            $specials[] = $field['blankLabel'];
        }

        // The special rows stand on their own: with nothing else to choose
        // from, clearing a stale reference or picking "no speaker" is still
        // a choice the author must be able to make.
        $values = [...$specials, ...$values];

        $opened = $this->referencePicker->open(
            (string) ($field['field'] ?? ''),
            $label,
            $category,
            $values,
            (string) ($field['value'] ?? ''),
            $labels,
        );

        if (! $opened) {
            // Saying so beats opening an empty list, or worse, quietly doing
            // nothing when the key is pressed.
            $this->setStatus(
                sprintf('This project defines no %s to choose from.', str_replace('_', ' ', $category)),
                StatusLevel::WARN
            );
            $this->renderDatabasePanes(['settings']);

            return true;
        }

        $this->statusMessage = sprintf('Choose %s.', lcfirst($label));
        $this->renderDatabasePanes(['settings']);

        return true;
    }

    /**
     * Drives the reference picker.
     *
     * @param string $input The raw input.
     * @return void
     */
    private function handleReferencePickerInput(string $input): void
    {
        if ($input === "\033" || $input === "\x1b") {
            $this->referencePicker->close();
            $this->statusMessage = 'Selection cancelled.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($input === "\n" || $input === "\r") {
            $this->commitReferenceSelection();

            return;
        }

        if (str_contains($input, "\033[A")) {
            $this->referencePicker->move(-1);
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if (str_contains($input, "\033[B")) {
            $this->referencePicker->move(1);
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($input === "\x7f" || $input === "\x08") {
            $this->referencePicker->backspace();
            $this->renderDatabasePanes(['settings']);

            return;
        }

        // Anything printable narrows the list, so a long list is reached by
        // typing a few letters rather than scrolling.
        if (mb_strlen($input) === 1 && ! ctype_cntrl($input)) {
            $this->referencePicker->type($input);
            $this->renderDatabasePanes(['settings']);
        }
    }

    /**
     * Stores what the picker was left on.
     *
     * @return void
     */
    private function commitReferenceSelection(): void
    {
        $selected = $this->referencePicker->selected();
        $fieldId = $this->referencePicker->fieldId();
        $label = $this->referencePicker->label();
        $this->referencePicker->close();

        if ($fieldId === self::NPC_SELECT_FIELD) {
            $this->selectNpcFromList($selected);

            return;
        }

        if ($fieldId === self::WORLD_WRITE_NAME_FIELD) {
            if ($selected !== null) {
                $this->worldWriteEditor->setName($selected);
            }

            $this->statusMessage = 'Building writes.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($fieldId === self::AFFINITY_ELEMENT_FIELD) {
            // Opened from the affinity editor, which is still the thing
            // being edited; the field itself is written when that closes.
            if ($selected !== null) {
                $this->affinityEditor->setElement($selected);
            }

            $this->statusMessage = 'Building elemental wards.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($fieldId === self::CONDITION_NAME_FIELD) {
            // Opened from the condition editor, which is still the thing
            // being edited; the field itself is written when that closes.
            if ($selected !== null) {
                $this->conditionEditor->setName($selected);
            }

            $this->statusMessage = 'Building conditions.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        if ($selected === null) {
            $this->statusMessage = 'Nothing matched.';
            $this->renderDatabasePanes(['settings']);

            return;
        }

        $field = null;

        foreach ($this->getDatabaseSettingsFields() as $candidate) {
            if (is_array($candidate) && ($candidate['field'] ?? null) === $fieldId) {
                $field = $candidate;
            }
        }

        if (! is_array($field)) {
            return;
        }

        try {
            // The record layer reads the none row as clearing the reference.
            $this->applyDatabaseFieldValueRecorded($field, $selected);
            $this->setStatus(
                $selected === (string) ($field['noneLabel'] ?? '(None)')
                    ? sprintf('%s cleared.', $label)
                    : sprintf('%s set to %s.', $label, $selected),
                StatusLevel::SUCCESS,
            );
        } catch (Throwable $throwable) {
            $this->setErrorStatus($throwable, sprintf('%s selection', $label));
        }

        $this->renderDatabasePanes(['list', 'settings', 'cue', 'frames', 'preview']);
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
        if ($this->isNpcInspectorHosting()) {
            $this->applyNpcFieldValueRecorded($field, $rawValue);

            return;
        }

        $fieldId = (string) ($field['field'] ?? '');
        $control = $this->getDatabaseFieldControl($field);
        // Option values keep their authored case (the actor class picker
        // writes `Vanguard`); matching them is case-insensitive instead.
        $oldRawValue = $control instanceof InputControl
            ? $control->rawValue
            : (string) ($field['value'] ?? '');
        $identity = [
            'category' => $this->databaseCategoryIndex,
            'actor' => $this->databaseSelectedActorIndex,
            'class' => $this->databaseSelectedClassIndex,
            'skill' => $this->databaseSelectedSkillIndex,
            'quest' => $this->databaseSelectedQuestIndex,
            'animation' => $this->databaseSelectedAnimationIndex,
            'frame' => $this->databaseSelectedFrameIndex,
            'records' => $this->databaseSelectedRecordIndexes,
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
     * @param array{category: int, actor: int, class: int, skill: int, quest: int, animation: int, frame: int, records?: array<string, int>} $identity The pinned selection.
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
            $this->databaseSelectedRecordIndexes,
        ];
        $this->databaseCategoryIndex = $identity['category'];
        $this->databaseSelectedActorIndex = $identity['actor'];
        $this->databaseSelectedClassIndex = $identity['class'];
        $this->databaseSelectedSkillIndex = $identity['skill'];
        $this->databaseSelectedQuestIndex = $identity['quest'];
        $this->databaseSelectedAnimationIndex = $identity['animation'];
        $this->databaseSelectedFrameIndex = $identity['frame'];
        $this->databaseSelectedRecordIndexes = $identity['records'] ?? $this->databaseSelectedRecordIndexes;

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
                $this->databaseSelectedRecordIndexes,
            ] = $liveSelection;
        }
    }

    /**
     * Returns an edited actor value as the field's own type.
     *
     * Most actor numbers are quantities that cannot go below zero, but an
     * actor's nature is an adjustment: being slower than the class baseline
     * is a legitimate thing to author, so those keep their sign.
     *
     * @param string $field The field identifier.
     * @param string $rawValue The raw edited value.
     * @return string|int The coerced value.
     */
    private function coerceActorFieldValue(string $field, string $rawValue): string|int
    {
        if (in_array($field, ['name', 'description', 'class', 'id', 'defaultNaturalVariantId'], true)) {
            return trim($rawValue);
        }

        if (str_starts_with($field, 'actorNaturalAdjustments.') || str_starts_with($field, 'naturalVariants.')) {
            return intval(trim($rawValue));
        }

        return max(0, intval($rawValue));
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

        if ($this->isActorsDatabaseSelected() && in_array($field, [self::ACTOR_GROWTH_FIELD, self::ACTOR_OPTIMIZE_SLOT_FIELD], true)) {
            // Both are assumptions the preview makes, not values the project
            // stores: neither marks anything dirty and neither is written.
            $actor = $this->getSelectedActor();

            if ($actor instanceof ProjectActor && $field === self::ACTOR_GROWTH_FIELD) {
                $this->actorGrowthSelections[$actor->getDefinitionId()] = trim($rawValue);
            } elseif ($actor instanceof ProjectActor) {
                $this->actorOptimizeSlots[$actor->getDefinitionId()] = trim($rawValue);
            }

            return;
        }

        if ($this->isActorsDatabaseSelected() && $field === self::ACTOR_VARIANT_FIELD) {
            // Which variant the rows edit is a choice about the pane, not a
            // value the project stores.
            $actor = $this->getSelectedActor();

            if ($actor instanceof ProjectActor) {
                $this->actorVariantSelections[$actor->getDefinitionId()] = trim($rawValue);
            }

            return;
        }

        if ($this->isActorsDatabaseSelected()) {
            $this->workspace->actorDatabase->setField(
                $this->databaseSelectedActorIndex,
                $field,
                $this->coerceActorFieldValue($field, $rawValue),
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
            if ($field === 'name') {
                $this->renameSelectedQuest(trim($rawValue));

                return;
            }

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

        $recordDatabase = $this->getSelectedRecordDatabase();

        if ($recordDatabase instanceof ProjectRecordDatabase) {
            // The schema owns coercion, so no per-category intval/trim rules
            // are needed here.
            if ($recordDatabase->hasCommandFrames()) {
                $recordDatabase->setFrameField(
                    $this->getSelectedRecordIndex(),
                    $this->databaseCommandFramePath,
                    $field,
                    $rawValue,
                );

                return;
            }

            $recordDatabase->setField($this->getSelectedRecordIndex(), $field, $rawValue);
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

        // Options match case-insensitively but apply verbatim: the actor
        // class picker must write `Vanguard`, not `vanguard`, because the
        // engine matches the value against a `name` in classes.php.
        $currentValue = mb_strtolower((string) ($field['value'] ?? ''));
        $normalizedOptions = array_map(static fn(mixed $option): string => mb_strtolower((string) $option), $options);
        $optionIndex = array_search($currentValue, $normalizedOptions, true);
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

        if ($this->isNpcInspectorHosting()) {
            if ($this->selectedNpcIndex === null) {
                return [[
                    'label' => 'NPCs · ' . $selectedMap->getNpcs()->count(),
                    'value' => 'Enter on the canvas to select or create one.',
                    'editable' => false,
                ]];
            }

            return $this->getDatabaseSettingsFields();
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

        if (is_array($eventData)) {
            $fields = $this->decorateEventInspectorFields(
                $marker,
                $this->flattenInspectorFields($eventData, ['data']),
            );
        }

        $rootFields = array_diff_key($definition, ['class' => true, 'data' => true]);
        // Cue is a generic trigger capability, including for definitions
        // created before the field existed. Supplying an empty editor-only
        // default exposes the opt-in without changing the stored event until
        // the author actually edits it.
        $rootFields['cue'] ??= ['symbol' => '', 'color' => 'bright-yellow'];

        return [
            ...$fields,
            ...$this->decorateEventInspectorFields(
                $marker,
                $this->flattenInspectorFields($rootFields, []),
            ),
        ];
    }

    /**
     * Adds event context, pickers, and enum controls to flattened fields.
     *
     * @param array<int, array<string, mixed>> $fields The raw fields.
     * @return array<int, array<string, mixed>>
     */
    private function decorateEventInspectorFields(string $marker, array $fields): array
    {
        $decorated = [];

        foreach ($fields as $field) {
            $field['marker'] = $marker;
            $field['target'] = 'event';

            $path = array_values((array) ($field['path'] ?? []));

            if ($path === ['data', 'mode']) {
                $field['options'] = ['action', 'auto'];
                unset($field['control']);
            }

            $reference = $this->resolveEventReferenceField($field);

            if (is_array($reference)) {
                // A reference is chosen, never spelled: dropping the control
                // is what stops the field being typed into, leaving the
                // picker as the only way to set it.
                $field['reference'] = $reference['category'];
                unset($field['control']);
            }

            $decorated[] = $field;
        }

        return $decorated;
    }

    /**
     * Flattens nested scalar data into editable inspector fields.
     *
     * @param array<string|int, mixed> $data The data to flatten.
     * @param array<int, string> $path The current path.
     * @return array<int, array<string, mixed>>
     */
    private function flattenInspectorFields(array $data, array $path, ?array $list = null): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $segment = (string) $key;
            $nextPath = [...$path, $segment];

            if (is_array($value)) {
                // A list of entries -- a shop's stock, an event's dialogue --
                // is something an author adds to and removes from, so every
                // field inside one remembers which list it belongs to.
                if ($this->isInspectorListValue($nextPath, $value)) {
                    $label = implode(' ', array_map(
                        static fn(string $part): string => ucwords(str_replace(['_', '-'], ' ', $part)),
                        ($nextPath[0] ?? null) === 'data' ? array_slice($nextPath, 1) : $nextPath
                    ));
                    $fields[] = [
                        'label' => sprintf('%s · %d', $label, count($value)),
                        'value' => '',
                        'editable' => false,
                        'list' => [
                            'path' => $nextPath,
                            'index' => max(0, count($value) - 1),
                            'blank' => $this->blankInspectorListEntry($nextPath),
                        ],
                    ];

                    foreach ($value as $index => $entry) {
                        $entryPath = [...$nextPath, (string) $index];
                        $entryList = ['path' => $nextPath, 'index' => (int) $index];

                        $fields = [
                            ...$fields,
                            ...(is_array($entry)
                                ? $this->flattenInspectorFields($entry, $entryPath, $entryList)
                                : $this->flattenInspectorFields([(string) $index => $entry], $nextPath, $entryList)),
                        ];
                    }

                    continue;
                }

                if (array_key_exists('x', $value) && array_key_exists('y', $value) && is_scalar($value['x']) && is_scalar($value['y'])) {
                    $label = implode(' ', array_map(
                        static fn(string $part): string => ucwords(str_replace(['_', '-'], ' ', $part)),
                        ($nextPath[0] ?? null) === 'data' ? array_slice($nextPath, 1) : $nextPath
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

                $fields = [...$fields, ...$this->flattenInspectorFields($value, $nextPath, $list)];
                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $displayPath = ($nextPath[0] ?? null) === 'data'
                ? array_slice($nextPath, 1)
                : $nextPath;
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
            $leaf = [
                'label' => $label,
                'value' => $stringValue,
                'control' => new InputControl($controlType, $stringValue),
                'path' => $nextPath,
            ];

            if (is_array($list)) {
                $leaf['list'] = $list;
            }

            $fields[] = $leaf;
        }

        return $fields;
    }

    /**
     * Distinguishes authored lists from associative configuration blocks.
     *
     * Empty arrays need an explicit known-list name because PHP cannot tell
     * an empty list from an empty map.
     *
     * @param array<int, string> $path The candidate path.
     * @param array<mixed> $value The candidate value.
     */
    private function isInspectorListValue(array $path, array $value): bool
    {
        if (! array_is_list($value) || array_key_exists('x', $value)) {
            return false;
        }

        if ($value !== []) {
            return true;
        }

        return in_array(
            (string) ($path[array_key_last($path)] ?? ''),
            ['conditions', 'sets', 'dialogue', 'items', 'script', 'steps', 'options'],
            true,
        );
    }

    /**
     * Returns the structured first row for an empty inspector list.
     *
     * @param array<int, string> $path The list path.
     * @return array<string, mixed>
     */
    private function blankInspectorListEntry(array $path): array
    {
        return match ((string) ($path[array_key_last($path)] ?? '')) {
            'conditions' => ['type' => 'switch', 'name' => '', 'value' => true],
            'sets' => ['type' => 'switch', 'name' => '', 'value' => true],
            'script' => ['type' => 'text', 'name' => '', 'text' => ''],
            'steps' => ['direction' => 'down', 'count' => 1, 'faceOnly' => false],
            'options' => ['text' => '', 'then' => []],
            'items' => ['item' => '', 'price' => 0],
            default => ['name' => '', 'text' => ''],
        };
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

        if ($this->workspace->hasUnsavedChanges() !== $this->paintedHeaderUnsaved) {
            $this->drawHeader();
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
        $this->drawHeader();
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
        // The shell is three rows of header, a gutter, the content, a gutter
        // and a four-row status window. Taking one row too few for that put
        // the status window's last row past the bottom of the terminal, so a
        // message landed on the footer instead of in the window.
        $contentHeight = max(10, $height - 9);

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
            // The focused pane wears the game's own menu selection color, so
            // the editor reads as part of the product it builds.
            return $this->theme->selectionColor;
        }

        return Color::WHITE;
    }

    /**
     * Adopts the opened project's border pack and selection color.
     *
     * @return void
     */
    private function applyProjectTheme(): void
    {
        $this->theme = EditorTheme::fromProject($this->projectRoot);
        EditorWindow::useBorderPack($this->theme->borderPack);
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

    /**
     * Renders the Database entry delete confirmation.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderDatabaseEntryDeleteConfirmationOverlay(array $layout): void
    {
        $pending = $this->pendingDatabaseDeletion;
        $label = is_array($pending) ? $pending['label'] : 'the selected entry';
        $category = is_array($pending) ? $pending['category'] : 'entry';
        $rows = [
            sprintf('Delete %s from %s?', $label, $category),
            'The entry leaves the editor now; the file changes on the next save.',
            'Ctrl+Z restores it before then.',
            '',
            'Y: Delete',
            'Enter/Esc/N: Cancel (default)',
        ];
        $overlayWidth = min(max(56, mb_strwidth($label) + 30), max(56, $layout['width'] - 10));
        $overlayHeight = 8;

        $window = new EditorWindow(
            title: 'Delete Entry',
            help: 'Y:Delete  Esc:Cancel',
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
            title: $this->getLootTypeLabel($this->lootDialogType ?? LootType::ITEM, plural: true) . $this->dialogFilter->describe(),
            help: 'Enter:Select  /:Filter  Esc:Cancel',
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
            title: $this->eventOptionDialogTitle . $this->dialogFilter->describe(),
            help: "Enter:Select  /:Filter  Esc:Cancel",
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

        foreach ($this->getVisibleEventTypeIndexes() as $index) {
            $definition = $definitions[$index] ?? null;

            if ($definition === null) {
                continue;
            }

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
            title: 'Event Type' . $this->dialogFilter->describe(),
            help: 'Enter:Select  /:Filter  Esc:Cancel',
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
            title: 'Destination' . $this->dialogFilter->describe(),
            help: 'Enter:Select  /:Filter  Esc:Cancel',
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

        foreach ($this->getVisibleDestinationIndexes() as $index) {
            $entry = $entries[$index] ?? null;

            if (! is_array($entry)) {
                continue;
            }

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

            // What a chest stores is the stable definition id, the identity
            // the runtime resolves; the name is what the author reads.
            $identity = InventoryCatalog::definitionId($item->id ?? null, (string) $item->name);

            $entries[] = [
                'name' => (string) $item->name,
                'value' => $identity ?? (string) $item->name,
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
        $current = mb_strtolower(trim($currentLoot));

        if ($current === '') {
            return 0;
        }

        // The stored value may be an id, a display name, or a declared
        // alias, so it is resolved before it is looked for. A value that
        // resolves to nothing leaves the cursor at the top, which is the
        // honest answer for a reference nothing answers to.
        $resolved = $this->workspace instanceof ProjectWorkspace
            ? InventoryCatalog::fromWorkspace($this->workspace)->definitionIdFor($currentLoot)
            : null;

        foreach ($this->lootDialogEntries as $index => $entry) {
            $value = mb_strtolower(trim((string) ($entry['value'] ?? $entry['name'])));

            if ($value === $current || mb_strtolower(trim($entry['name'])) === $current || ($resolved !== null && $value === $resolved)) {
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

        foreach ($this->getVisibleLootIndexes() as $index) {
            $entry = $entries[$index] ?? null;

            if (! is_array($entry)) {
                continue;
            }

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


    /**
     * Returns the list the inspector cursor is inside, if any.
     *
     * @return array{path: array<int, string>, index: int}|null The list.
     */
    private function selectedInspectorList(): ?array
    {
        $fields = $this->getInspectorFields();
        $field = $fields[$this->selectedInspectorFieldIndex] ?? null;

        if (! is_array($field) || ($field['target'] ?? null) !== 'event') {
            return null;
        }

        $list = $field['list'] ?? null;

        return is_array($list) ? $list : null;
    }

    /**
     * Adds an entry to the list the inspector cursor is inside.
     *
     * The new entry is shaped like the one it follows -- the same keys, their
     * values cleared -- because an author adding a second shop line means
     * another line like the first, not an empty hole they have to describe.
     *
     * @return void
     */
    private function addInspectorListItem(): void
    {
        $selectedMap = $this->getSelectedMap();
        $list = $this->selectedInspectorList();
        $marker = $this->selectedEventMarkerForList();

        if (! $selectedMap instanceof ProjectMap || $list === null || $marker === '') {
            $this->setStatus('Nothing here is a list to add to.', StatusLevel::WARN);

            return;
        }

        $entries = $selectedMap->getEventField($marker, $list['path']);
        $entries = is_array($entries) ? array_values($entries) : [];
        $template = $entries[$list['index']] ?? ($list['blank'] ?? ($entries === [] ? '' : end($entries)));
        $position = min(count($entries), $list['index'] + 1);

        array_splice($entries, $position, 0, [self::blankLike($template)]);

        $this->applyInspectorListChange($selectedMap, $marker, $list['path'], $entries, 'Add list entry');
        $this->setStatus(sprintf('Added %s %d.', $this->describeListPath($list['path']), $position + 1), StatusLevel::SUCCESS);
    }

    /**
     * Removes the entry the inspector cursor is inside.
     *
     * @return void
     */
    private function removeInspectorListItem(): void
    {
        $selectedMap = $this->getSelectedMap();
        $list = $this->selectedInspectorList();
        $marker = $this->selectedEventMarkerForList();

        if (! $selectedMap instanceof ProjectMap || $list === null || $marker === '') {
            $this->setStatus('Nothing here is a list entry to remove.', StatusLevel::WARN);

            return;
        }

        $entries = $selectedMap->getEventField($marker, $list['path']);
        $entries = is_array($entries) ? array_values($entries) : [];

        if (! array_key_exists($list['index'], $entries)) {
            return;
        }

        array_splice($entries, $list['index'], 1);

        $this->applyInspectorListChange($selectedMap, $marker, $list['path'], $entries, 'Remove list entry');
        $this->clampInspectorSelection();
        $this->setStatus(sprintf('Removed %s %d.', $this->describeListPath($list['path']), $list['index'] + 1), StatusLevel::SUCCESS);
    }

    /**
     * Writes a changed list back, recording it so it can be undone.
     *
     * @param ProjectMap $map The map.
     * @param string $marker The event marker.
     * @param array<int, string> $path The list's path.
     * @param array<int, mixed> $entries The list as it should be.
     * @param string $label What to call the change.
     * @return void
     */
    private function applyInspectorListChange(ProjectMap $map, string $marker, array $path, array $entries, string $label): void
    {
        $previous = $map->getEventField($marker, $path);
        $previous = is_array($previous) ? array_values($previous) : [];

        $map->setEventField($marker, $path, $entries);
        $this->recordCommand(new GenericCommand(
            $label,
            static fn() => $map->setEventField($marker, $path, $entries),
            static fn() => $map->setEventField($marker, $path, $previous),
        ));
        $this->renderSelectionDependentArea();
    }

    /**
     * Returns the marker of the event the inspector cursor is in.
     *
     * @return string The marker, or an empty string.
     */
    private function selectedEventMarkerForList(): string
    {
        $fields = $this->getInspectorFields();
        $field = $fields[$this->selectedInspectorFieldIndex] ?? null;

        return is_array($field) ? (string) ($field['marker'] ?? '') : '';
    }

    /**
     * Returns an entry shaped like the given one with nothing filled in.
     *
     * @param mixed $template The entry to copy the shape of.
     * @return mixed The blank entry.
     */
    private static function blankLike(mixed $template): mixed
    {
        if (is_array($template)) {
            return array_map(self::blankLike(...), $template);
        }

        return match (true) {
            is_int($template) => 0,
            is_float($template) => 0.0,
            is_bool($template) => false,
            default => '',
        };
    }

    /**
     * Names a list for a status message.
     *
     * @param array<int, string> $path The list's path.
     * @return string The name, in the singular.
     */
    private static function describeListPath(array $path): string
    {
        $leaf = (string) ($path[array_key_last($path)] ?? 'entry');
        $leaf = str_replace(['_', '-'], ' ', $leaf);

        return mb_strtolower(rtrim($leaf, 's'));
    }

    /**
     * Determines whether an event field names another resource.
     *
     * A door's destination and a chest's loot have flows of their own; this
     * covers the rest, so a track, a sound, or a shop's stock is chosen from
     * what the project actually has rather than spelled from memory.
     *
     * @param array<string, mixed> $field The inspector field descriptor.
     * @return array{category: string, title: string}|null The kind of
     *   reference and what to call the picker, or null when the field names
     *   nothing.
     */
    private function resolveEventReferenceField(array $field): ?array
    {
        if (($field['target'] ?? null) !== 'event') {
            return null;
        }

        $path = array_values((array) ($field['path'] ?? []));

        if (($path[0] ?? null) !== 'data' || count($path) < 2) {
            return null;
        }

        $leaf = (string) $path[array_key_last($path)];

        return match (true) {
            $path === ['data', 'scriptId'] => ['category' => 'common_events', 'title' => 'Event Script'],
            $leaf === 'bgm' => ['category' => 'bgm', 'title' => 'Music'],
            $leaf === 'sfx' => ['category' => 'sfx', 'title' => 'Sound Effect'],
            // A shop's stock is data.items.N.item. The leaf alone would also
            // match an unrelated event that happened to call a field "item".
            $leaf === 'item' && ($path[1] ?? null) === 'items'
                => ['category' => 'inventory', 'title' => 'Item'],
            default => null,
        };
    }

    /**
     * Opens the picker for an event field that names another resource.
     *
     * @param string $marker The event marker.
     * @param array<int, string> $path The path within the event definition.
     * @param string $title What to call the picker.
     * @param string $category The kind of reference.
     * @param string $currentValue What the field is set to now.
     * @return void
     */
    private function openEventReferenceDialog(
        string $marker,
        array $path,
        string $title,
        string $category,
        string $currentValue,
    ): void {
        if (! $this->workspace instanceof ProjectWorkspace) {
            return;
        }

        $referenceCatalog = new ReferenceCatalog($this->workspace, $this->getSelectedMap());
        $referenceLabels = $referenceCatalog->labelsFor($category);
        $entries = array_map(
            static fn(string $value): array => [
                'label' => $referenceLabels[$value] ?? $value,
                'value' => $value,
                'description' => '',
            ],
            $referenceCatalog->valuesFor($category),
        );

        if ($entries === []) {
            // Nothing to choose from is worth saying. Opening an empty dialog
            // reads as a broken editor, and falling through to typing would
            // put back the guesswork the picker exists to remove.
            $this->setStatus(
                sprintf('This project has no %s to choose from.', mb_strtolower($title)),
                StatusLevel::WARN
            );
            $this->renderFooter();

            return;
        }

        $this->openEventOptionDialog($marker, $path, $title, $entries, $currentValue);
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

        foreach ($this->getVisibleEventOptionIndexes() as $index) {
            $entry = $entries[$index] ?? null;

            if (! is_array($entry)) {
                continue;
            }

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
    /**
     * What the settings and cue panes may take of the Database's right side.
     */
    private const int DATABASE_SETTINGS_MINIMUM_WIDTH = 34;
    private const int DATABASE_SETTINGS_MAXIMUM_WIDTH = 96;
    private const int DATABASE_CUE_MINIMUM_WIDTH = 22;
    private const int DATABASE_CUE_MAXIMUM_WIDTH = 48;

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
        // The settings pane holds label-and-value lines that truncate, and the
        // cue beside it holds short summaries. So the cue takes what its
        // content needs and the settings pane takes the rest: a wider
        // terminal should widen the pane doing the reading, not the one with
        // room to spare.
        $cueWidth = min(self::DATABASE_CUE_MAXIMUM_WIDTH, max(self::DATABASE_CUE_MINIMUM_WIDTH, intdiv($rightWidth, 3)));
        $settingsWidth = min(
            self::DATABASE_SETTINGS_MAXIMUM_WIDTH,
            max(self::DATABASE_SETTINGS_MINIMUM_WIDTH, $rightWidth - $cueWidth - $gutter)
        );
        $cueWidth = max(self::DATABASE_CUE_MINIMUM_WIDTH, $rightWidth - $settingsWidth - $gutter);

        // Narrow enough that neither pane can have its minimum: share out
        // what there is rather than draw past the edge.
        if ($settingsWidth + $cueWidth + $gutter > $rightWidth) {
            $cueWidth = max(1, intdiv($rightWidth - $gutter, 3));
            $settingsWidth = max(1, $rightWidth - $cueWidth - $gutter);
        }

        if ($this->isSkillsDatabaseSelected() || $this->isQuestsDatabaseSelected()) {
            $framesWidth = max(30, min($rightWidth - 24 - $gutter, 34));
            $previewWidth = max(24, $rightWidth - $framesWidth - $gutter);

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
        if ($this->isNpcInspectorHosting()) {
            // The sub-editors ask for Database panes; in the Inspector the
            // same request means the inspector and canvas.
            $this->requestFullRender();

            return;
        }

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
        $row = $this->recordPaneLayout($fields)->rowOfField($this->databaseSelectedSettingIndex);

        if ($row === null) {
            Console::cursor()->hide();
            return;
        }

        $settingsLeft = $layout['innerX'] + $layout['categoryWidth'] + $layout['listWidth'] + ($layout['gutter'] * 2);
        $settingsTop = $layout['innerY'];
        Console::cursor()->show();
        Console::cursor()->moveTo(
            $settingsLeft + 1 + self::WINDOW_HORIZONTAL_PADDING + $cursorOffset,
            $settingsTop + 1 + $row,
        );
    }

    /**
     * Renders the live cursor for editing a hosted record field in the
     * Inspector: the same caret as the Database settings pane, at the
     * Inspector's own position, on the row the pane layout gives the field.
     *
     * @param array{width: int, height: int, leftWidth: int, rightWidth: int, gutter: int, centerWidth: int, contentHeight: int} $layout The active layout.
     * @return void
     */
    private function renderHostedEditCursor(array $layout): void
    {
        $fields = $this->getDatabaseSettingsFields();
        $field = $fields[$this->databaseSelectedSettingIndex] ?? null;
        $row = $this->recordPaneLayout($fields)->rowOfField($this->databaseSelectedSettingIndex);

        if (! is_array($field) || $row === null || $this->focusedPane !== self::FOCUS_INSPECTOR) {
            Console::cursor()->hide();
            return;
        }

        $leftText = sprintf('> %s: ', (string) ($field['label'] ?? 'Field'));
        $contentWidth = $this->getWindowContentWidth($layout['rightWidth']);
        $availableValueWidth = max(1, $contentWidth - mb_strwidth($leftText));
        $visibleStart = max(0, $this->databaseEditCursorIndex - $availableValueWidth + 1);
        $visibleValue = mb_substr($this->databaseEditBuffer, $visibleStart, $availableValueWidth);
        $visibleCursorIndex = max(0, min($this->databaseEditCursorIndex - $visibleStart, mb_strlen($visibleValue)));
        $cursorOffset = min($contentWidth - 1, mb_strwidth($leftText . mb_substr($visibleValue, 0, $visibleCursorIndex)));
        $inspectorLeft = 2 + $layout['leftWidth'] + $layout['centerWidth'] + ($layout['gutter'] * 2);
        $inspectorTop = 5;
        Console::cursor()->show();
        Console::cursor()->moveTo(
            $inspectorLeft + 1 + self::WINDOW_HORIZONTAL_PADDING + $cursorOffset,
            $inspectorTop + 1 + $row,
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
        $supportsEntries = $this->isActorsDatabaseSelected()
            || $this->isClassesDatabaseSelected()
            || $this->isSkillsDatabaseSelected()
            || $this->isQuestsDatabaseSelected()
            || $this->isAnimationsDatabaseSelected();

        return new EditorWindow(
            // The category dirty marker rides the title so categories whose
            // entries carry no per-entry flag (animations, system) still
            // show unsaved state where the author is looking.
            title: $this->getSelectedDatabaseCategory()
                . ($this->isDatabaseCategoryDirty($this->getSelectedDatabaseCategoryDefinition()->key) ? ' *' : '')
                . $this->databaseFilter->describe(),
            help: $supportsEntries
                ? $this->fitHelp(
                    $layout['listWidth'],
                    'Shift+A:New  /:Filter  Del:Delete',
                    'Shift+A:New  /:Filter  Del',
                    'Shift+A:New  /:Filter',
                    '/:Filter',
                )
                : '',
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
            // Inside a frame the title is the trail back out of it.
            title: $this->databaseCommandFramePath === []
                ? 'General Settings'
                : ($this->getSelectedRecordDatabase()?->describeFramePath($this->databaseCommandFramePath)
                    ?? ProjectRecordDatabase::describeFrame($this->databaseCommandFramePath)),
            help: match (true) {
                $this->referencePicker->isOpen() => $this->fitHelp(
                    $layout['settingsWidth'],
                    'Enter:Choose  Type:Filter  Esc:Cancel',
                    'Enter:Choose  Esc:Cancel',
                    'Enter:Choose',
                ),
                $this->databaseCommandFramePath !== []
                    && ! $this->isDatabaseEditing
                    && ! $this->conditionEditor->isOpen()
                    && ! $this->affinityEditor->isOpen() => $this->fitHelp(
                    $layout['settingsWidth'],
                    'Enter:Edit/Open  Esc:Back  Shift+O:Add  Shift+X/Del:Remove',
                    'Enter:Open  Esc:Back  Shift+O/Del:Add/Del',
                    'Esc:Back  ?:Help',
                ),
                $this->worldWriteEditor->isOpen() => $this->fitHelp(
                    $layout['settingsWidth'],
                    'a:Add  d:Delete  t/T:Type  n:Name  x:Toggle  v:Value  Enter:Done  Esc:Cancel',
                    'a/d:Add/Del  t:Type  n:Name  x:Toggle  Enter:Done',
                    'a/d:Add/Del  t/n/x:Edit  ?:Help',
                    '?:Help',
                ),
                $this->affinityEditor->isOpen() => $this->fitHelp(
                    $layout['settingsWidth'],
                    'a:Add  d:Delete  n:Element  x/X:Effect  Enter:Done  Esc:Cancel',
                    'a/d:Add/Del  n:Element  x:Effect  Enter:Done',
                    'a/d:Add/Del  n/x:Edit  ?:Help',
                    '?:Help',
                ),
                $this->conditionEditor->isOpen() => $this->fitHelp(
                    $layout['settingsWidth'],
                    'a:Add  d:Del  t:Type  n:Name  x:Value  !:Not  Enter:Done  Esc:Cancel',
                    'a:Add  d:Del  t:Type  n:Name  x:Value  !:Not  Enter:Done',
                    'a/d:Add/Del  t:Type  n:Name  x:Value  !:Not',
                    'a/d:Add/Del  t/n/x:Edit  ?:Help',
                    '?:Help',
                ),
                $this->isDatabaseEditing => 'Enter:Apply  Esc:Cancel',
                $this->hasDatabaseSubList() => $this->fitHelp(
                    $layout['settingsWidth'],
                    'Enter:Edit  Shift+O:Add  Shift+X/Del:Remove',
                    'Enter:Edit  Shift+O:Add  Del:Remove',
                    'Enter:Edit  Shift+O/Del:Add/Del',
                    'Enter:Edit  ?:Help',
                    'Enter:Edit',
                ),
                default => 'Enter:Edit',
            },
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
            help: $this->isQuestsDatabaseSelected()
                ? $this->fitHelp($layout['cueWidth'], 'Shift+O:Add  Shift+X:Del', 'Shift+O/X:Add/Del', '?:Help')
                : '',
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

        if ($this->getSelectedRecordDatabase() instanceof ProjectRecordDatabase) {
            return $this->getDatabaseRecordListLines();
        }

        $category = $this->getSelectedDatabaseCategoryDefinition();

        return [
            $category->description,
            '',
            'Editor coming soon.',
        ];
    }

    /**
     * Returns the list lines for a schema-driven category.
     *
     * Every category backed by a record schema lists the same way: the
     * project's entries, marked where one is unsaved, narrowed by the filter,
     * and telling an author how to add the first one when there are none.
     *
     * @return string[]
     */
    private function getDatabaseRecordListLines(): array
    {
        $database = $this->getSelectedRecordDatabase();

        if (! $database instanceof ProjectRecordDatabase) {
            return [];
        }

        $labels = $database->getEntryLabels();
        $category = $this->getSelectedDatabaseCategoryDefinition();

        if ($labels === []) {
            return $database->isEditable()
                ? [sprintf('No %s yet.', $category->label), '', 'Shift+A to create one.']
                : [
                    sprintf('No %s in this project.', strtolower($category->label)),
                    '',
                    $database->getReadOnlyReason() ?? '',
                ];
        }

        $selectedIndex = $this->getSelectedRecordIndex();
        $lines = [];

        foreach ($this->getVisibleDatabaseEntryIndexes() as $index) {
            $label = $labels[$index] ?? null;

            if ($label === null) {
                continue;
            }

            $prefix = $index === $selectedIndex ? '> ' : '  ';
            $dirty = $database->getRecordByIndex($index)?->isDirty() ? ' *' : '';
            $lines[] = sprintf('%s%s%s', $prefix, $label, $dirty);
        }

        if ($lines === []) {
            return ['No matches.'];
        }

        // A read-only category says so once, rather than leaving an author
        // wondering why nothing they type sticks.
        if (! $database->isEditable() && ($reason = $database->getReadOnlyReason()) !== null) {
            $lines[] = '';
            $lines[] = $reason;
        }

        return $lines;
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

        foreach ($this->getVisibleDatabaseEntryIndexes() as $index) {
            $actor = $actors[$index] ?? null;

            if (! $actor instanceof ProjectActor) {
                continue;
            }

            $prefix = $index === $this->databaseSelectedActorIndex ? '> ' : '  ';
            $dirty = $actor->isDirty() ? ' *' : '';
            $lines[] = sprintf('%s%s%s', $prefix, $actor->getName(), $dirty);
        }

        return $lines === [] ? ['No matches.'] : $lines;
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

        foreach ($this->getVisibleDatabaseEntryIndexes() as $index) {
            $class = $classes[$index] ?? null;

            if (! $class instanceof ProjectClass) {
                continue;
            }

            $prefix = $index === $this->databaseSelectedClassIndex ? '> ' : '  ';
            $dirty = $class->isDirty() ? ' *' : '';
            $lines[] = sprintf('%s%04d %s%s', $prefix, $class->id, $class->getName(), $dirty);
        }

        return $lines === [] ? ['No matches.'] : $lines;
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

        foreach ($this->getVisibleDatabaseEntryIndexes() as $index) {
            $skill = $skills[$index] ?? null;

            if (! $skill instanceof ProjectSkill) {
                continue;
            }

            $prefix = $index === $this->databaseSelectedSkillIndex ? "> " : "  ";
            $dirty = $skill->isDirty() ? " *" : "";
            $lines[] = sprintf("%s%04d %s%s", $prefix, $skill->id, $skill->getName(), $dirty);
        }

        return $lines;
    }


    /**
     * Returns what an objective of the given type points at.
     *
     * A flag names a switch or story event the world sets, which is authored
     * text rather than a record, so it stays typed.
     *
     * @param string $type The objective type.
     * @return string|null The kind of reference, or null when it is free text.
     */
    private static function questObjectiveReference(string $type): ?string
    {
        return match (QuestObjectiveType::tryFrom($type)) {
            // Collecting is not limited to consumables: a quest may ask for
            // a weapon or a piece of armor, and the runtime resolves all
            // three from one catalogue.
            QuestObjectiveType::COLLECT => 'inventory',
            QuestObjectiveType::DEFEAT => 'enemies',
            QuestObjectiveType::REACH_MAP => 'maps',
            QuestObjectiveType::TALK_TO => 'actors',
            default => null,
        };
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

        foreach ($this->getVisibleDatabaseEntryIndexes() as $index) {
            $quest = $quests[$index] ?? null;

            if (! $quest instanceof ProjectQuest) {
                continue;
            }

            $prefix = $index === $this->databaseSelectedQuestIndex ? '> ' : '  ';
            $dirty = $quest->isDirty() ? ' *' : '';
            $lines[] = sprintf('%s%s%s', $prefix, $quest->getName(), $dirty);
        }

        return $lines === [] ? ['No matches.'] : $lines;
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
        $isDirty = $this->workspace?->animationDatabase->isDirty() === true;

        foreach ($this->getVisibleDatabaseEntryIndexes() as $index) {
            $animation = $animations[$index] ?? null;

            if (! $animation instanceof Animation) {
                continue;
            }

            $prefix = $index === $this->databaseSelectedAnimationIndex ? '> ' : '  ';
            // The animation database tracks dirtiness per file, not per
            // entry, so the marker is honest about the whole list.
            $lines[] = sprintf('%s%04d %s%s', $prefix, $animation->id, $animation->name, $isDirty ? ' *' : '');
        }

        return $lines === [] ? ['No matches.'] : $lines;
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
     * Returns the record pane's width, height and cursor visibility: the
     * Database settings pane's own, or the Inspector's while it hosts the
     * pane. Every row builder of the pane measures against these.
     *
     * @return array{width: int, rows: int, cursorShown: bool}
     */
    private function recordPaneMetrics(): array
    {
        $layout = $this->resolveLayout();

        if ($this->isNpcInspectorHosting()) {
            return [
                'width' => $this->getWindowContentWidth($layout['rightWidth']),
                'rows' => max(1, $layout['contentHeight'] - 2),
                'cursorShown' => $this->focusedPane === self::FOCUS_INSPECTOR,
            ];
        }

        $database = $this->resolveDatabaseLayout($layout);

        return [
            'width' => $this->getWindowContentWidth($database['settingsWidth']),
            'rows' => max(1, $database['topHeight'] - 2),
            'cursorShown' => $this->databaseFocus === self::DATABASE_FOCUS_SETTINGS,
        ];
    }

    /**
     * Lays out the record pane: each field as its wrapped lines, the field
     * being edited as one horizontally scrolled line, scrolled so the
     * selected field stays in view. The visible lines, the edit caret and
     * anything else that turns a field into a row all read this one layout.
     *
     * @param array<int, array<string, mixed>>|null $fields The fields, or null to read them.
     * @return SettingsPaneLayout The layout.
     */
    private function recordPaneLayout(?array $fields = null): SettingsPaneLayout
    {
        $fields ??= $this->getDatabaseSettingsFields();
        $metrics = $this->recordPaneMetrics();
        $rows = [];

        foreach ($fields as $index => $field) {
            $prefix = $metrics['cursorShown'] && $index === $this->databaseSelectedSettingIndex ? '> ' : '  ';
            $label = (string) ($field['label'] ?? 'Field');
            $value = (string) ($field['value'] ?? '');
            $editing = $this->isDatabaseEditing && $index === $this->databaseSelectedSettingIndex;

            if ($editing) {
                // The edited row keeps its single-line window around the
                // caret, exactly as before, so the caret arithmetic holds.
                $availableValueWidth = max(1, $metrics['width'] - mb_strwidth(sprintf('%s%s: ', $prefix, $label)));
                $visibleStart = max(0, $this->databaseEditCursorIndex - $availableValueWidth + 1);
                $value = mb_substr($this->databaseEditBuffer, $visibleStart, $availableValueWidth);
            }

            $rows[] = [
                'prefix' => $prefix,
                'label' => $label,
                'value' => $value,
                'editable' => $editing || $this->isInspectorFieldInteractive($field),
                'singleLine' => $editing,
            ];
        }

        return SettingsPaneLayout::layout($rows, $metrics['width'], $metrics['rows'], $this->databaseSelectedSettingIndex);
    }

    /**
     * Returns the rows shown while a reference is being chosen.
     *
     * @return string[] The rows.
     */
    private function buildReferencePickerRows(): array
    {
        $matches = $this->referencePicker->matches();
        $selectedIndex = $this->referencePicker->selectedIndex();
        $filter = $this->referencePicker->filter();

        $lines = [
            sprintf('%s: %s', $this->referencePicker->label(), $filter === '' ? 'type to filter' : $filter),
            '',
        ];

        if ($matches === []) {
            $lines[] = '  Nothing matches.';

            return $lines;
        }

        foreach ($matches as $index => $value) {
            $lines[] = sprintf('%s%s', $index === $selectedIndex ? '> ' : '  ', $this->referencePicker->labelFor($value));
        }

        // Two header lines, then the rows in whatever height the pane has.
        $visibleRows = max(1, $this->recordPaneMetrics()['rows'] - 2);

        return [...array_slice($lines, 0, 2), ...ScrollWindow::slice(array_slice($lines, 2), $selectedIndex, $visibleRows)];
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

        if ($this->isNpcInspectorHosting() && $this->npcCreationInProgress !== null) {
            return $this->buildNpcNamePromptRows();
        }

        if ($this->isNpcInspectorHosting() && $this->referencePicker->isOpen() && $this->referencePicker->fieldId() === self::NPC_SELECT_FIELD) {
            // The map's NPC list, opened from the canvas, is drawn where a
            // field's picker would be, whether or not one is selected yet.
            return $this->buildReferencePickerRows();
        }

        if ($this->isNpcInspectorHosting() && $this->selectedNpcIndex !== null) {
            // The hosted pane draws its own sub-editors and edit cursor,
            // exactly as the Database settings pane does.
            return $this->getDatabaseSettingsLines();
        }

        return $this->inspectorPaneLayout($fields)->visibleLines();
    }

    /**
     * Lays out the Inspector's own fields (map metadata, event data): the
     * same wrapped rows and span map as the record pane, with the
     * Inspector's edit buffer on its single edited line.
     *
     * @param array<int, array<string, mixed>>|null $fields The fields, or null to read them.
     * @return SettingsPaneLayout The layout.
     */
    private function inspectorPaneLayout(?array $fields = null): SettingsPaneLayout
    {
        $fields ??= $this->getInspectorFields();
        $layout = $this->resolveLayout();
        $rows = [];

        foreach ($fields as $index => $field) {
            $editing = $this->isInspectorEditing && $index === $this->selectedInspectorFieldIndex;
            $rows[] = [
                'prefix' => $this->focusedPane === self::FOCUS_INSPECTOR && $index === $this->selectedInspectorFieldIndex ? '> ' : '  ',
                'label' => (string) ($field['label'] ?? 'Field'),
                'value' => $editing ? $this->inspectorEditBuffer : (string) ($field['value'] ?? ''),
                'editable' => $editing || $this->isInspectorFieldInteractive($field),
                'singleLine' => $editing,
            ];
        }

        return SettingsPaneLayout::layout(
            $rows,
            $this->getWindowContentWidth($layout['rightWidth']),
            max(1, $layout['contentHeight'] - 2),
            $this->selectedInspectorFieldIndex,
        );
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
            || ($field['editable'] ?? null) === true
            || ! empty($field['options'])
            || isset($field['reference'])
            || ($field['conditions'] ?? false) === true
            || ($field['worldWrites'] ?? false) === true
            || ($field['affinities'] ?? false) === true
            || isset($field['frame']);
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

        if ($this->isDatabaseEntryDeleteConfirmationOpen) {
            $this->renderDatabaseEntryDeleteConfirmationOverlay($layout);
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
        $overlayWidth = min(84, max(52, $layout['width'] - 12));
        $overlayHeight = min(max(9, count($rows) + 2), max(9, $layout['height'] - 4));
        $visibleRows = max(1, $overlayHeight - 2);
        $this->helpScrollRow = max(0, min(count($rows) - 1, $this->helpScrollRow));
        $isScrollable = count($rows) > $visibleRows;
        $rows = ScrollWindow::slice($rows, $this->helpScrollRow, $visibleRows);

        $window = new EditorWindow(
            title: 'Help — Key Bindings',
            help: $isScrollable ? 'Arrows:Scroll  Esc:Close' : 'Esc:Close',
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

        if ($this->isDatabaseEditing && $this->isNpcInspectorHosting()) {
            $this->renderHostedEditCursor($layout);
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

        $row = $this->inspectorPaneLayout($fields)->rowOfField($this->selectedInspectorFieldIndex);

        if ($row === null) {
            Console::cursor()->hide();
            return;
        }

        $inspectorLeft = 2 + $layout['leftWidth'] + $layout['centerWidth'] + ($layout['gutter'] * 2);
        $inspectorTop = 5;
        Console::cursor()->show();
        Console::cursor()->moveTo(
            $inspectorLeft + 1 + self::WINDOW_HORIZONTAL_PADDING + $valueCursorOffset,
            $inspectorTop + 1 + $row
        );
    }

    /**
     * Paints the header and remembers the unsaved state it shows.
     *
     * @return void
     */
    private function drawHeader(): void
    {
        $this->paintedHeaderUnsaved = $this->workspace?->hasUnsavedChanges() === true;
        $this->createHeaderWindow()->render();
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
                // The one always-visible unsaved indicator: dirty markers
                // live on individual rows, but the header answers "does this
                // project have anything unsaved at all?" from every screen.
                sprintf(
                    'Project: %s%s',
                    $this->workspace?->projectName ?? '',
                    $this->workspace?->hasUnsavedChanges() === true ? '  *  unsaved changes (Ctrl+A saves all)' : '',
                ),
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
        $visibleIndexes = $this->getVisibleAssetIndexes();
        $selectedRow = array_search($this->selectedAssetIndex, $visibleIndexes, true);
        $selectedRow = is_int($selectedRow) ? $selectedRow : 0;

        return new EditorWindow(
            title: ($this->focusedPane === self::FOCUS_ASSETS ? 'Assets [Focus]' : 'Assets')
                . $this->assetFilter->describe(),
            help: '/:Filter  Del:Delete',
            position: ['x' => 2, 'y' => 5],
            width: $layout['leftWidth'],
            height: $layout['contentHeight'],
            foregroundColor: $this->resolvePaneColor(self::FOCUS_ASSETS),
            content: $this->fitLines(
                // The selected map (its row sits one below the tree header)
                // stays inside the pane via the shared scroll window.
                ScrollWindow::slice(
                    $this->workspace?->getAssetLines($this->selectedAssetIndex, $this->assetFilter->isActive() ? $visibleIndexes : null) ?? [],
                    $selectedRow + 1,
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
            help: match (true) {
                $this->isDestinationSpawnSelectionOpen => 'Enter:Select Spawn  Esc:Cancel',
                // Longest that fits wins; the overlay (?) has the full table.
                $this->editingMode === self::MODE_NPC && $this->npcCreationInProgress !== null => 'Enter:Create  Esc:Cancel',
                $this->editingMode === self::MODE_NPC => $this->fitHelp(
                    $layout['centerWidth'],
                    'Enter:Select/Create  M:Move  D:Dup  L:List  Del:Delete  F3:Exit',
                    'Enter:Select  M:Move  D:Dup  L:List  F3:Exit',
                    'Enter  M  D  L  Del  F3:Exit',
                    'F3:Exit',
                ),
                default => $this->fitHelp(
                    $layout['centerWidth'],
                    '%:Map  ^:Event  F3:NPC  @:Chars',
                    '%:Map ^:Event F3:NPC @:Chars',
                    '%:Map  ^:Event  @:Chars',
                ),
            },
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
                    $this->editingMode === self::MODE_NPC,
                    $this->selectedNpcIndex,
                    $this->previewedNpcSprite(),
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
            title: ($this->isNpcInspectorHosting() && $this->databaseCommandFramePath !== []
                    ? ($this->npcInspector?->records()->describeFramePath($this->databaseCommandFramePath) ?? 'Inspector')
                    : 'Inspector')
                . ($this->focusedPane === self::FOCUS_INSPECTOR ? ' [Focus]' : ''),
            help: match (true) {
                $this->isInspectorEditing => 'Enter:Apply  Esc:Cancel',
                // Only where there is a list to act on: a hint for keys that
                // would answer "nothing here is a list" is worse than none.
                $this->selectedInspectorList() !== null => $this->fitHelp(
                    $layout['rightWidth'],
                    'Enter:Edit  Shift+O:Add  Shift+X/Del:Remove',
                    'Enter:Edit  Shift+O:Add  Del:Remove',
                    'Enter:Edit  Shift+O/Del:Add/Del',
                    'Enter:Edit',
                ),
                default => 'Enter:Edit',
            },
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
                        'Selected map: %s%s | Focus: %s | Mode: %s | Tool: %s',
                        $selectedMap->mapId,
                        $selectedMap->isDirty() ? ' *' : '',
                        ucfirst($this->focusedPane),
                        $this->editingMode === self::MODE_NPC ? 'NPC' : ucfirst($this->editingMode),
                        $this->describeCanvasToolState(),
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
