<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use Ichiloto\Editor\Backup\BackupSettings;
use Ichiloto\Editor\Backup\BackupWriter;
use Ichiloto\Editor\Canvas\CanvasClipboard;
use Ichiloto\Editor\Canvas\CanvasEditor;
use Ichiloto\Editor\Canvas\PhysicalOccupancyEditor;
use Ichiloto\Editor\Canvas\Clipboard;
use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneRecordCategory;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Cutscenes\Preview\TimelinePreviewSession;
use Ichiloto\Editor\Cutscenes\Preview\EffectPreviewContext;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\Database\EnemySprites;
use Ichiloto\Editor\Canvas\PieceRole;
use Ichiloto\Editor\Canvas\PiecePlacer;
use Ichiloto\Editor\Canvas\PlacedPieces;
use Ichiloto\Editor\Canvas\TerrainKindPainter;
use Ichiloto\Editor\Audio\AudioAudition;
use Ichiloto\Editor\Cutscenes\Preview\PreviewGame;
use Ichiloto\Editor\Canvas\CanvasTool;
use Ichiloto\Editor\Canvas\ToolGeometry;
use Ichiloto\Engine\Events\MapEventDefinition;
use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Rendering\Tilesets\TileAppearance;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Field\DashSettings;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Rendering\Tilesets\TerrainKind;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;
use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\ConditionEditor;
use Ichiloto\Editor\Animations\LegacyAnimationConversion;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\MasteryAdoption;
use Ichiloto\Editor\Database\MasteryAuthoring;
use Ichiloto\Editor\Database\MasteryFields;
use Ichiloto\Editor\Database\SkillReferences;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectCadence;
use Ichiloto\Editor\Database\ElementAffinityCodec;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordItem;
use Ichiloto\Editor\Database\RecordChange;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\MovementRouteFields;
use Ichiloto\Editor\Database\DatabaseCategory;
use Ichiloto\Editor\Database\RecordCategory;
use Ichiloto\Editor\Database\RecordPanes;
use Ichiloto\Editor\Actors\ActorAuthoring;
use Ichiloto\Editor\Actors\ActorCategory;
use Ichiloto\Editor\Database\WorldWriteCodec;
use Ichiloto\Editor\Database\WorldWriteEditor;
use Ichiloto\Editor\Events\EventAuthoring;
use Ichiloto\Editor\Events\EventRefusal;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\Events\EventTypeDefinition;
use Ichiloto\Editor\Field\NpcAuthoring;
use Ichiloto\Editor\Field\MapAreaAuthoring;
use Ichiloto\Editor\Field\MapAreaRefusal;
use Ichiloto\Editor\Field\WorldObjectAuthoring;
use Ichiloto\Editor\Field\WorldObjectTileFields;
use Ichiloto\Editor\Field\FieldSpriteFields;
use Ichiloto\Editor\Field\CharacterSheetPreview;
use Ichiloto\Editor\Field\NpcChange;
use Ichiloto\Editor\Field\NpcInspector;
use Ichiloto\Editor\Field\NpcEventAdoption;
use Ichiloto\Editor\Field\NpcRefusal;
use Ichiloto\Editor\Field\ProjectNpc;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\Inspector\InputControl;
use Ichiloto\Editor\Inspector\InspectorListEdit;
use Ichiloto\Editor\Inspector\InspectorRefusal;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\Inspector\MapLandmarkFields;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\SaveManifestAlias;
use Ichiloto\Editor\Playtest\PlaytestLauncher;
use Ichiloto\Editor\Playtest\PlaytestLaunch;
use Ichiloto\Editor\Playtest\PlaytestRun;
use Ichiloto\Editor\Playtest\PlaytestStart;
use Ichiloto\Editor\Maps\LayerEditor;
use Ichiloto\Editor\Maps\LineRemoval;
use Ichiloto\Editor\Maps\MapFolder;
use Ichiloto\Editor\Maps\MapLinePlan;
use Ichiloto\Editor\Maps\MapLinePlanner;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\Maps\MapReferences;
use Ichiloto\Editor\Maps\TerrainKindConversion;
use Ichiloto\Editor\Maps\TilePalette;
use Ichiloto\Editor\Maps\TreeBrush;
use Ichiloto\Editor\Maps\SaveManifestStep;
use Ichiloto\Editor\Maps\SaveLandingCells;
use Ichiloto\Editor\Maps\SaveMapRetirement;
use Ichiloto\Editor\Maps\SaveMigrationRegistration;
use Ichiloto\Editor\Maps\SaveRelocationPlanner;
use Ichiloto\Editor\History\SourceSetCommand;
use Ichiloto\Editor\History\CommandGroup;
use Ichiloto\Editor\History\SourceSetRequired;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Review\MapRenderer;
use Ichiloto\Editor\Review\MapRendering;
use Ichiloto\Editor\Review\MapRenderView;
use Ichiloto\Editor\Review\MapRenderSubject;
use Ichiloto\Editor\Review\MapReviewWriter;
use Ichiloto\Editor\Review\FieldStillRaster;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;
use Ichiloto\Editor\Storage\SaveSet;
use Ichiloto\Editor\Storage\SaveSetAdoptionFailure;
use Ichiloto\Editor\Storage\SaveSetRefusal;
use Ichiloto\Editor\Storage\WorkspaceSave;
use Ichiloto\Editor\Validation\MapGraphicsValidator;
use Ichiloto\Editor\Validation\PassageAgreementCheck;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\MapValidator;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\SaveCompatibilityValidator;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\IO\Console\TerminalPresentationComposer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleArenaBindings;
use Ichiloto\Engine\Battle\Presentation\BattlerBindings;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\TroopAuthoring;
use Ichiloto\Editor\Database\BattleArenaFields;
use Ichiloto\Engine\Battle\Presentation\BattleFormationBattler;
use Ichiloto\Engine\Battle\Presentation\BattleFormationLayout;
use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;
use Ichiloto\Editor\Database\EngineDataBootstrap;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Scenes\Arena\BattleTestChoices;
use Ichiloto\Engine\Scenes\Arena\BattleTestLoadoutCatalog;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Ichiloto\Engine\Scenes\Arena\ProjectBattleTest;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Editor\Cutscenes\Preview\SummonBattlePreview;
use Ichiloto\Editor\Cutscenes\Preview\EffectBattlePreview;
use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Progression\Mastery\MasteryVocabulary;
use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewOrigin;
use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Editor\Cutscenes\Preview\PreviewField;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Closure;
use InvalidArgumentException;
use Ichiloto\Editor\Field\MapWalkMeasurement;
use Ichiloto\Editor\ProjectConfig;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Field\Reachability\ProjectReachability;
use Ichiloto\Engine\IO\SaveCompatibility\ContentReferenceCategory;
use RuntimeException;
use Throwable;
use WeakReference;

/**
 * One open project as every editor interface edits it: its documents, the
 * undo history across them, and saving. An interface owns what it shows and
 * what is selected; it asks the session to read and change documents, and
 * gets back plain data, a question for the author, or a refusal
 * ({@see SessionRefusal}). Nothing here draws, prompts or reports a status.
 *
 * A change names the revision of the map it was made against; a change made
 * against an older revision is refused, so a late or repeated request never
 * overwrites newer work.
 */
final class EditorSession
{
    use MapPlacementSession;
    use TilesetArtSession;
    use PlacedPieceSession;

    /** The renderer a playtest started from a graphical editor uses: it owns no terminal to hand over. */
    public const string PLAYTEST_RENDERER = 'gpui';

    private ProjectWorkspace $workspace;

    /** @var array<string, \Ichiloto\Editor\Field\AuthoringCanvasHost> Each open map's editing canvas, by map id. */
    private array $authoringCanvases = [];

    /** The playtest running in the background, or the last one, to report how it ended. */
    private ?PlaytestRun $playtest = null;
    /** Where that playtest began. */
    private PlaytestStart $playtestStart = PlaytestStart::CELL;
    /** The battle test running in its own window, and the troop it fights. */
    private ?PlaytestRun $battleTestRun = null;

    /** The block an author last copied or cut, for pasting on its layer. */
    private ?Clipboard $clipboard = null;

    /** The music or sound an author is hearing from a field, once one has been played. */
    private ?AudioAudition $audition = null;
    private ?string $battleTestTroop = null;
    /**
     * The summon battle previews last built, by presentation, and what each was built from, so seeking reuses them until either changes.
     *
     * @var array<string, array{key: string, preview: SummonBattlePreview}>
     */
    private array $summonBattlePreviews = [];
    /**
     * The effect battle previews last built, by presentation, and what each was built from.
     *
     * @var array<string, array{key: string, preview: EffectBattlePreview}>
     */
    private array $effectBattlePreviews = [];
    /** Projects terminal pictures into renderer runs, keeping its parsed styles between frames. */
    private ?TerminalPresentationComposer $terminalComposer = null;
    /** The cinematic previewed as the terminal plays it, and which one it is. */
    private ?CinematicPreviewSession $cinematicPreview = null;
    private ?string $cinematicPreviewId = null;
    /** The cinematic as it stood when its preview started, so an unchanged one keeps playing. */
    private ?string $cinematicPreviewFingerprint = null;
    /**
     * The field an effect is previewed on, where it stands and at what size,
     * and the effect's playhead in seconds. It and the cinematic preview are
     * never open together: each installs the preview's Engine configuration.
     */
    private ?PreviewField $effectField = null;
    private ?string $effectFieldKey = null;
    private float $effectFieldSeconds = 0.0;

    /** How actors are authored, with what this editor's actor panes show. */
    private readonly ActorAuthoring $actorAuthoring;

    /** How a troop's members are composed, as the terminal editor composes them. */
    private readonly TroopAuthoring $troopAuthoring;
    private readonly MasteryAuthoring $masteryAuthoring;

    /**
     * Where each map's revisions count from, with the last revision given
     * and what the map held then. A map read afresh, when a file set written
     * at once reloads the project or its undo puts the earlier one back,
     * keeps that revision while it holds the same content, so a file set
     * that never wrote a map leaves it unchanged; one that holds anything
     * else counts on from it, so one revision never names two states of a
     * map.
     *
     * @var array<string, array{map: WeakReference<ProjectMap>, base: int, last: int, content: string}>
     */
    private array $mapRevisions = [];

    /**
     * The open change set ({@see beginChanges()}): what validation reported
     * when it began, each map's revision then, and how many undo steps came
     * before it. Null when none is open.
     *
     * Requests that write the save manifest at once note what they wrote:
     * `migrationRegistered`, and each map retirement by its step and map.
     *
     * @var array{baseline: list<Issue>, revisions: array<string, int>, steps: int, dropped: int, migrationRegistered?: bool,
     *     retirements?: list<array{from: int, map: string}>}|null
     */
    private ?array $changeSet = null;

    private function __construct(
        ProjectWorkspace $workspace,
        private readonly CommandHistory $history,
        private readonly BackupWriter $backups,
    ) {
        $this->workspace = $workspace;
        $this->actorAuthoring = new ActorAuthoring(graphical: true);
        $this->troopAuthoring = new TroopAuthoring();
        $this->masteryAuthoring = new MasteryAuthoring();
    }

    /** Opens the project at a root, as the terminal editor opens it. */
    public static function open(string $projectRoot): self
    {
        $workspace = ProjectWorkspace::fromProject($projectRoot, graphical: true);

        return new self($workspace, new CommandHistory(),
            new BackupWriter(BackupSettings::fromProject($workspace->projectRoot), $workspace->projectRoot));
    }

    /**
     * The project's name, its maps and its database categories.
     *
     * @return array{name: string, root: string, maps: list<array<string, mixed>>, databases: list<array{key: string, label: string, description: string, implemented: bool}>}
     */
    public function describeProject(): array
    {
        return [
            'name' => $this->workspace->projectName,
            'root' => $this->workspace->projectRoot,
            'maps' => $this->describeMaps(),
            'databases' => array_map(static fn($category): array => [
                'key' => $category->key,
                'label' => $category->label,
                'description' => $category->description,
                'implemented' => $category->isImplemented,
                'group' => 'Database',
            ], DatabaseCatalog::getGraphicalCategories()),
            // Cutscene types, edited through the same record RPCs under their record category keys.
            'cutscenes' => array_map(static fn(CutsceneType $type): array => [
                'key' => $type->getRecordCategory(),
                'label' => $type->label(),
                'description' => $type->describeCategory(),
                'implemented' => true,
                'group' => 'Cutscenes',
            ], CutsceneType::cases()),
        ];
    }

    /**
     * Finds what the project names a text by: maps by id or name, events by
     * marker, type or any value they hold (a chest's loot, a destination, a
     * script), and NPCs by id or name, unsaved edits included. Case is
     * ignored; a query of one character is an event marker, matched exactly,
     * rather than every text that contains it.
     *
     * @return list<array{kind: 'map'|'event'|'npc', map: string, label: string, detail: string, marker?: string, index?: int, x?: int, y?: int}>
     */
    public function searchProject(string $query, int $limit = 100): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $contains = static fn(string $text): bool => mb_stripos($text, $query) !== false;
        $marker = mb_strlen($query) === 1;
        $results = [];

        foreach ($this->getMapsById() as $mapId => $map) {
            $mapId = (string) $mapId;
            $name = $map->getDisplayName();
            if (! $marker && ($contains($mapId) || $contains($name))) {
                $results[] = ['kind' => 'map', 'map' => $mapId, 'label' => $name, 'detail' => $mapId];
            }

            foreach (array_unique([...$map->getEventMarkers(), ...$map->getPlacedEventMarkers()]) as $eventMarker) {
                $definition = $map->getEventDefinition($eventMarker);
                $type = EventTypeCatalog::describeClass(is_string($definition['class'] ?? null) ? $definition['class'] : null);
                $values = [];
                // What the event holds, not the class that plays it.
                $data = is_array($definition['data'] ?? null) ? $definition['data'] : [];
                array_walk_recursive($data, static function (mixed $value) use (&$values): void {
                    if (is_scalar($value)) {
                        $values[] = (string) $value;
                    }
                });
                $found = $marker
                    ? $eventMarker === $query
                    : $contains($type) || array_find($values, $contains) !== null;
                if (! $found) {
                    continue;
                }
                $cell = $map->getEventArea($eventMarker)?->cells[0] ?? null;
                $results[] = ['kind' => 'event', 'map' => $mapId, 'label' => sprintf('%s %s', $eventMarker, $type),
                    'detail' => sprintf('%s · %s', $name, $marker ? 'marker' : (array_find($values, $contains) ?? $type)),
                    'marker' => $eventMarker, ...($cell === null ? [] : ['x' => (int) $cell[0], 'y' => (int) $cell[1]])];
            }

            foreach ($map->getNpcs()->all() as $index => $npc) {
                if (! $marker && $contains($npc->getId()) || $contains($npc->getName())) {
                    $results[] = ['kind' => 'npc', 'map' => $mapId, 'label' => $npc->getName(), 'detail' => sprintf('%s · %s', $name, $npc->getId()),
                        'index' => (int) $index, 'x' => $npc->getX(), 'y' => $npc->getY()];
                }
            }

            if (count($results) >= $limit) {
                return array_slice($results, 0, $limit);
            }
        }

        return $results;
    }

    /**
     * Every map, by stable id, with what a list shows about it.
     *
     * @return list<array{id: string, name: string, dirty: bool, readOnly: ?string, revision: int}>
     */
    public function describeMaps(): array
    {
        return array_map(fn(ProjectMap $map): array => [
            'id' => $map->mapId,
            'name' => $map->getDisplayName(),
            // Maps are listed under their region, so two maps with one name (an inn in each town) stay apart.
            'region' => $map->getAuthoredRegion(),
            'dirty' => $map->isDirty(),
            'readOnly' => $map->getGridSourceIssue(),
            'revision' => $this->getMapRevision($map),
        ], $this->workspace->maps);
    }

    /**
     * The kinds a new map can have: the project's tilesets, which its tiles
     * and pieces come from.
     *
     * @return list<array{value: string, label: string}>
     */
    public function listMapKinds(): array
    {
        $kinds = new ReferenceCatalog($this->workspace)->labelsFor('tilesets');

        return array_map(static fn(string $id, string $name): array => ['value' => $id, 'label' => $name], array_keys($kinds), $kinds);
    }

    /**
     * Creates a blank map, written to disk at once as the terminal editor
     * creates one. Without `$mapId` it goes at the maps root, named after
     * `$name` (`new-map` when empty) with a numbered suffix when that is
     * taken. With one it goes at exactly that folder path, which may nest
     * inside another map's folder ({@see MapFolder}), named `$name` or after
     * the id's last segment. Every open map keeps its unsaved changes and the
     * undo history stays.
     *
     * @param string|null $mapId The new map's id; null derives one from the name.
     * @return array{map: string, maps: list<array<string, mixed>>}
     * @throws SessionRefusal When the kind is unknown, the size is not a size, the id is not a free map folder path, or the files cannot be written.
     */
    public function createMap(?string $name, ?string $kind, int $width, int $height, ?string $mapId = null): array
    {
        if ($kind !== null && ! in_array($kind, array_column($this->listMapKinds(), 'value'), true)) {
            throw new SessionRefusal(sprintf('There is no map kind %s.', $kind));
        }
        $baseName = $name === null || trim($name) === '' ? null : ProjectMap::slugify($name, '');
        if ($mapId === null && $baseName === '') {
            throw new SessionRefusal(sprintf('"%s" has no letters or digits to name a map with.', $name));
        }
        try {
            $mapId = $mapId === null
                ? $this->workspace->createMap($baseName, kind: $kind, width: $width, height: $height)
                : $this->workspace->createMapAt($mapId, $name === null || trim($name) === '' ? null : trim($name), kind: $kind, width: $width, height: $height);
        } catch (MapSourceRefusal|RuntimeException $error) {
            throw new SessionRefusal(sprintf('The map was not created: %s', $error->getMessage()), previous: $error);
        }
        $this->workspace = $this->workspace->withLoadedMap($mapId);

        return ['map' => $mapId, 'maps' => $this->describeMaps()];
    }

    /**
     * Copies the current authored map through the workspace's transactional
     * duplicate operation, beside it under the next free `-copy` name or at
     * the folder path `$to` names ({@see MapFolder}). Unsaved source edits
     * are included in the copy; the original and every other loaded document
     * and undo step remain.
     *
     * @param string|null $to The copy's id; null takes the next free sibling.
     * @return array{map: string, maps: list<array<string, mixed>>}
     * @throws SessionRefusal When the revision is stale, the id is not a free map folder path, or source cannot be copied safely.
     */
    public function duplicateMap(string $mapId, int $revision, ?string $to = null): array
    {
        $this->requireCurrentMap($mapId, $revision);
        try {
            $created = $this->workspace->duplicateMap((int) array_search($mapId, $this->workspace->mapIds, true), mapId: $to)
                ?? throw new RuntimeException('The map is no longer in the workspace.');
        } catch (MapSourceRefusal|RuntimeException $error) {
            throw new SessionRefusal(sprintf('%s was not duplicated: %s', $mapId, $error->getMessage()), previous: $error);
        }
        $this->workspace = $this->workspace->withLoadedMap($created);

        return ['map' => $created, 'maps' => $this->describeMaps()];
    }

    /**
     * Explicitly moves a map after the author reviews that exact
     * destination: its metadata-derived path, or the folder path `$to` names
     * ({@see MapFolder}), which may nest inside another map's folder. The
     * first call asks, naming the destination and its references; the
     * confirming call repeats `$to` and gives the reviewed `$destination`.
     * As in the TUI, references are not migrated and a successful move
     * clears undo history naming retired maps. Ordinary saves never call
     * this operation.
     *
     * @param string|null $destination The destination the author reviewed, repeated to confirm.
     * @param string|null $to The id to move to; null proposes the metadata-derived path.
     * @return array<string, mixed>
     * @throws SessionRefusal When the revision or reviewed path changed, the id is not a free map folder path, or source cannot move safely.
     */
    public function moveMap(string $mapId, int $revision, ?string $destination = null, bool $confirmed = false, ?string $to = null): array
    {
        $this->refuseDuringChangeSet('Moving a map clears the undo history');
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        $proposed = $to ?? $map->getProposedMapId();
        if ($destination !== null && $destination !== $proposed) {
            throw new SessionRefusal($to === null
                ? 'The derived destination changed; review the proposed move again.'
                : 'The reviewed destination is not the id to move to; review the move again.');
        }
        if ($proposed === $mapId) {
            return ['status' => 'unchanged', 'map' => $mapId, 'destination' => $proposed, 'maps' => $this->describeMaps()];
        }
        if ($to !== null) {
            // Refused before the question, so an author never reviews a move that cannot happen.
            try {
                MapFolder::requireFreeFolder($this->workspace->getMapsRoot(), $to);
            } catch (InvalidArgumentException $error) {
                throw new SessionRefusal(sprintf('%s was not moved: %s', $mapId, $error->getMessage()), previous: $error);
            }
        }
        if (! $confirmed) {
            return [
                'status' => 'question', 'map' => $mapId, 'revision' => $revision, 'destination' => $proposed,
                ...($to === null ? [] : ['to' => $to]),
                'references' => new MapReferences($this->workspace)->describe($mapId),
                'warning' => 'References are not migrated: doors, quests, saves and one-shot events naming the old id keep naming it. Moving clears the undo history. The original map folder is removed only after the new map validates.',
            ];
        }
        if ($destination === null) {
            throw new SessionRefusal('Review the proposed destination before confirming the move.');
        }
        try {
            $moved = $map->moveTo($proposed);
        } catch (MapSourceRefusal|RuntimeException $error) {
            throw new SessionRefusal(sprintf('%s was not moved: %s', $mapId, $error->getMessage()), previous: $error);
        }
        $this->workspace = $this->workspace->withReplacedMap((int) array_search($mapId, $this->workspace->mapIds, true), $moved);
        $this->history->clear();

        return ['status' => 'moved', 'previousMap' => $mapId, 'map' => $moved->mapId, 'maps' => $this->describeMaps()];
    }

    /**
     * Reviews a project-wide row or column insertion before writing it as
     * one reversible source set. The plan owns coordinate rewrites and save
     * shifts; both editor interfaces use the same planner and transaction.
     *
     * @return array<string, mixed>
     * @throws SessionRefusal When the map or review is stale, edits are pending, or the plan cannot be applied safely.
     */
    public function insertMapLines(string $mapId, int $revision, string $axis, int $at, int $count, ?string $answer = null, ?string $confirm = null): array
    {
        return $this->editMapLines($mapId, $revision, $answer, $confirm, 'inserted', 'inserting map lines',
            fn(): MapLinePlan => MapLinePlanner::planInsertion($this->workspace->projectRoot, $mapId, $axis, $at, $count));
    }

    /**
     * Reviews a project-wide row or column removal before writing it as one
     * reversible source set, as {@see insertMapLines()} does. Anything
     * authored on a removed line refuses the removal. The review names where
     * saves on the removed lines land; confirming repeats that landing.
     *
     * @param array{int, int}|null $landing Where saves on the removed lines land, after the removal; null proposes one.
     * @return array<string, mixed>
     * @throws SessionRefusal When the map or review is stale, edits are pending, or the removal cannot be applied safely.
     */
    public function removeMapLines(string $mapId, int $revision, string $axis, int $at, int $count, ?array $landing = null, ?string $answer = null, ?string $confirm = null): array
    {
        return $this->editMapLines($mapId, $revision, $answer, $confirm, 'removed', 'removing map lines',
            fn(): MapLinePlan => MapLinePlanner::planRemoval($this->workspace->projectRoot, $mapId, $axis, $at, $count, $landing));
    }

    /**
     * @param callable(): MapLinePlan $plan Plans the edit from the files on disk.
     * @return array<string, mixed>
     * @throws SessionRefusal
     */
    private function editMapLines(string $mapId, int $revision, ?string $answer, ?string $confirm, string $done, string $doing, callable $plan): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        if ($answer === 'cancel') {
            return ['status' => 'cancelled', 'map' => $mapId, 'revision' => $revision, 'changed' => false, 'maps' => $this->describeMaps()];
        }
        if ($this->workspace->hasUnsavedChanges()) {
            throw new SessionRefusal(sprintf('Save or undo pending edits before %s. No files were changed.', $doing));
        }
        try {
            $plan = $plan();
        } catch (InvalidArgumentException|RuntimeException $failure) {
            throw new SessionRefusal($failure->getMessage(), previous: $failure);
        }
        $edit = $plan->edit;
        $paths = array_map(fn(string $path): string => substr($path, strlen($this->workspace->projectRoot) + 1), $plan->getChangedPaths());
        $required = new SourceSetRequired($plan->getSourceSet(), $edit->verb . ' ' . $edit->noun,
            'this map line ' . ($done === 'removed' ? 'removal' : 'insertion'), $edit->verb . ' ' . $edit->description . '?', $paths);
        $result = $this->writeSourceSet($required, $answer, static fn(): array => [], $confirm);
        if (($result['status'] ?? null) === 'question') {
            return [...$result, 'map' => $mapId, 'revision' => $revision, 'axis' => $edit->axis, 'at' => $edit->at, 'count' => $edit->count,
                ...($edit instanceof LineRemoval ? ['landing' => $edit->landing] : []),
                'paths' => $paths, 'handEdits' => $plan->handEdits, 'notes' => $plan->notes,
                'width' => $plan->width, 'height' => $plan->height];
        }

        return ['status' => $done, 'map' => $mapId, 'revision' => $this->getMapRevision($this->requireMap($mapId)),
            'changed' => true, 'maps' => $this->describeMaps()];
    }

    /**
     * Deletes a map's files from disk at once, as the terminal editor does.
     * While something still sends the player there ({@see MapReferences}),
     * nothing is deleted until the author confirms: the answer is a question
     * listing them. The undo history is cleared, since its steps may name
     * the deleted map; every other map keeps its unsaved changes.
     *
     * @return array{status: 'deleted', map: string, maps: list<array<string, mixed>>}|array{status: 'question', map: string, references: list<string>}
     * @throws SessionRefusal When the map is unknown or read-only, or its files cannot be removed.
     */
    public function deleteMap(string $mapId, bool $confirmed = false): array
    {
        $this->refuseDuringChangeSet('Deleting a map clears the undo history');
        $this->requireMap($mapId);
        $references = new MapReferences($this->workspace)->describe($mapId);
        if ($references !== [] && ! $confirmed) {
            return ['status' => 'question', 'map' => $mapId, 'references' => $references];
        }
        try {
            $this->workspace->deleteMap((int) array_search($mapId, $this->workspace->mapIds, true));
        } catch (MapSourceRefusal|RuntimeException $error) {
            throw new SessionRefusal(sprintf('%s was not deleted: %s', $mapId, $error->getMessage()), previous: $error);
        }
        $this->workspace = $this->workspace->withoutMap($mapId);
        $this->history->clear();

        return ['status' => 'deleted', 'map' => $mapId, 'maps' => $this->describeMaps()];
    }


    /**
     * A map as an interface draws it: its layers' glyphs and colours, its
     * events and its NPCs, at its current revision, unsaved changes included.
     *
     * @return array<string, mixed>
     * @throws SessionRefusal When no map has the id.
     */
    public function readMap(string $mapId): array
    {
        $map = $this->requireMap($mapId);
        $width = $map->getWidth();
        $height = $map->getHeight();
        $layers = [];

        foreach ($map->getLayers() as $layer) {
            $rows = [];
            $colors = [];
            for ($y = 0; $y < $height; $y++) {
                $row = [];
                for ($x = 0; $x < $width; $x++) {
                    $row[] = $map->getLayerSymbol($layer['id'], $x, $y);
                    $color = $map->getLayerColor($layer['id'], $x, $y);
                    if ($color !== null) {
                        $colors[] = [$x, $y, $color];
                    }
                }
                $rows[] = $row;
            }
            $layers[] = [
                'id' => $layer['id'],
                'name' => $layer['name'],
                'label' => MapLayers::formatLabel((string) $layer['name']),
                'order' => $layer['order'],
                'decoration' => (bool) $layer['decoration'],
                'event' => $layer['id'] === MapLayers::EVENT,
                'rows' => $rows,
                'colors' => $colors,
            ];
        }

        // Every event the map holds: defined ones in their data order, then
        // markers painted without a definition, which the engine refuses to
        // load until they are given a type or cleared.
        $events = [];
        foreach (array_unique([...$map->getEventMarkers(), ...$map->getPlacedEventMarkers()]) as $marker) {
            $definition = $map->getEventDefinition($marker);
            $area = $map->getEventArea($marker);
            $events[] = [
                'marker' => $marker,
                'type' => EventTypeCatalog::describeClass(is_string($definition['class'] ?? null) ? $definition['class'] : null),
                'defined' => $definition !== null,
                'cells' => $area === null ? [] : array_map(static fn(array $cell): array => [$cell[0], $cell[1]], $area->cells),
            ];
        }

        $npcs = [];
        foreach ($map->getNpcs()->all() as $index => $npc) {
            $npcs[] = [
                'index' => $index,
                'id' => $npc->getId(),
                'name' => $npc->getName(),
                'x' => $npc->getX(),
                'y' => $npc->getY(),
                'sprite' => $npc->getVisibleSprite(),
            ];
        }

        return [
            'id' => $map->mapId,
            'name' => $map->getDisplayName(),
            'width' => $width,
            'height' => $height,
            'revision' => $this->getMapRevision($map),
            'dirty' => $map->isDirty(),
            'readOnly' => $map->getGridSourceIssue(),
            'baseLayer' => $map->getBaseLayerId(),
            'layers' => $layers,
            'events' => $events,
            'npcs' => $npcs,
            'worldObjects' => WorldObjectAuthoring::readEntries($map),
            ...$this->describeMapAreas($map),
            'tileLayers' => $map->describeTileLayers(),
            'physicalOccupancy' => $map->hasMapDataField([MapPhysicalOccupancy::DATA_KEY]),
            'occupancy' => PhysicalOccupancyEditor::readOccupancy($map),
        ];
    }

    /**
     * The map's world as the game uploads it to a graphical renderer: its
     * glyph rows and, when its graphics load, its tileset and tile layers,
     * unsaved edits included. `layerIds` maps each glyph layer's id to its
     * world layer id. Graphics never decide whether a map shows: when the
     * game would refuse them, the world holds glyphs only and
     * `graphicsIssue` says why. Wall shadows come only to an interface whose
     * renderer paints them and asks with `$tileShadows`, as a game renderer
     * negotiates `tile_shadows`; any other receives the world without them.
     *
     * @return array{map: string, revision: int, assetRoot: string, operations: list<array<string, mixed>>, layerIds: array<string, string>, animated: bool, graphicsIssue: ?string}
     * @throws SessionRefusal When the map is unknown or its layers cannot be presented.
     */
    public function readWorld(string $mapId, bool $tileShadows = false): array
    {
        $map = $this->requireMap($mapId);
        $issue = null;
        try {
            $graphics = $map->loadGraphics();
        } catch (InvalidArgumentException|MapSourceRefusal $error) {
            $graphics = null;
            $issue = $error->getMessage();
        }
        try {
            $layerSet = $map->getLayerSet();
            $world = PresentationWorld::getFromLayers($layerSet, 'map', $graphics, $map->getAssetRoot());
        } catch (InvalidArgumentException|MapSourceRefusal $error) {
            throw new SessionRefusal(sprintf('%s cannot be drawn: %s', $mapId, $error->getMessage()), previous: $error);
        }
        $layerIds = $map->getPresentationLayerIds();

        return [
            'map' => $map->mapId,
            'revision' => $this->getMapRevision($map),
            'assetRoot' => $map->getAssetRoot(),
            'operations' => $world->getOperations(true, $tileShadows),
            'layerIds' => $layerIds,
            'animated' => $world->animated,
            'graphicsIssue' => $issue,
        ];
    }
    /**
     * The tile palette of the map's tileset ({@see TilePalette}): each tab's
     * grid of tile identities and the world that draws it.
     *
     * @return array{tileset: string, name: string, assetRoot: string, tabs: list<array{name: string, ids: list<list<int>>, operations: list<array<string, mixed>>}>}
     * @throws SessionRefusal When the map is unknown or has no usable tileset.
     */
    public function readTilePalette(string $mapId): array
    {
        $map = $this->requireMap($mapId);
        try {
            $tileset = $map->loadTileset() ?? throw new SessionRefusal(sprintf('%s names no tileset; choose one before placing tiles.', $mapId));
            $tabs = [];
            foreach (TilePalette::getTabs($tileset) as $tab) {
                $world = TilePalette::buildWorld($tileset, $tab['ids'], $map->getAssetRoot(), 'palette:' . $tab['name']);
                $tabs[] = [...$tab, 'operations' => $world->operations];
            }
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal(sprintf('%s tileset cannot be used: %s', $mapId, $error->getMessage()), previous: $error);
        }

        return ['tileset' => $tileset->id, 'name' => $tileset->name, 'assetRoot' => $map->getAssetRoot(), 'tabs' => $tabs];
    }
    /**
     * A tileset record as an author sees it, unsaved edits included: its
     * sheets as the tile palette lays them out ({@see TilePalette}), the tiles
     * it marks (drawn above characters, tables, the missing-art tile) and
     * those that can be tables ({@see toggleTilesetMark()}), and its
     * pieces, each with its glyph picture and its tiles drawn. A tileset the
     * Engine would refuse still shows the sheets it can, and `issue` says
     * what is wrong; a sheet that cannot be drawn is left out and named.
     *
     * @return array<string, mixed>
     * @throws SessionRefusal When the record is unknown.
     */
    public function readTilesetPreview(int $index): array
    {
        $record = $this->requireRecordDatabase('tilesets')->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('tilesets has no record %d.', $index));
        $assetRoot = rtrim($this->workspace->projectRoot, '/') . '/assets';
        $data = $record->toArray();
        $data = is_array($data) ? $data : (array) $data;
        $issue = null;
        try {
            $tileset = Tileset::fromArray($record->recordId, $data);
        } catch (InvalidArgumentException $error) {
            $issue = $error->getMessage();
            // The sheets alone, so what can be seen still is.
            $sheets = array_filter(is_array($data['sheets'] ?? null) ? $data['sheets'] : [], static fn(mixed $asset, mixed $sheet): bool => is_string($asset)
                && TilesetSheet::tryFrom((string) $sheet) !== null && strtolower(pathinfo($asset, PATHINFO_EXTENSION)) === 'png', ARRAY_FILTER_USE_BOTH);
            $tileset = new Tileset($record->recordId, trim((string) ($data['name'] ?? '')) ?: $record->recordId, $sheets);
        }
        $tabs = [];
        $sheetIssues = \Ichiloto\Editor\Maps\TilesetAuthoring::getSheetIssues($tileset, $assetRoot);
        $unreadable = array_values($sheetIssues);
        // An upper sheet is shown as its image lays it out, sixteen cells across, so a piece as wide as the sheet can
        // be selected in one rectangle and copied cells land where the image has them.
        $images = array_column(\Ichiloto\Editor\Assets\TilesetSheetCells::describeSheets($tileset), 'ids', 'sheet');
        foreach (array_map(static fn(array $tab): array => in_array($tab['name'], ['B', 'C', 'D', 'E'], true)
            ? [...$tab, 'ids' => $images[$tab['name']]] : $tab, TilePalette::getTabs($tileset)) as $tab) {
            try {
                $world = TilePalette::buildWorld($tileset, $tab['ids'], $assetRoot, 'tileset-preview:' . $tab['name']);
                $tabs[] = [...$tab, 'operations' => $world->operations];
            } catch (\Throwable $error) {
                $unreadable[] = sprintf('Tab %s cannot be drawn: %s', $tab['name'], $error->getMessage());
            }
        }
        $pieces = [];
        foreach ($tileset->pieces as $piece) {
            try {
                $world = TilePalette::buildPieceWorld($tileset, $piece, $assetRoot, 'tileset-piece:' . $piece->id);
            } catch (\Throwable $error) {
                $world = null;
                $unreadable[] = sprintf('%s\'s tiles cannot be drawn: %s', $piece->name, $error->getMessage());
            }
            $pieces[] = [
                'id' => $piece->id,
                'name' => $piece->name,
                'layerLabel' => MapLayers::formatLabel($piece->layer),
                'connected' => $piece->connects !== null,
                'width' => $piece->width,
                'height' => $piece->height,
                'tileLayers' => $tileset->getPieceTileLayers($piece),
                'picture' => PiecePlacer::buildPicture($piece),
                'operations' => $world?->operations,
                'connections' => \Ichiloto\Editor\Maps\ConnectedPiecePreview::describe($tileset, $piece, $assetRoot, $sheetIssues),
            ];
        }

        return [
            'palette' => ['tileset' => $tileset->id, 'name' => $tileset->name, 'assetRoot' => $assetRoot, 'tabs' => $tabs],
            'missingArt' => $tileset->missingArt,
            'above' => $tileset->above,
            'tables' => $tileset->tables,
            // What each tile shown reads as at ground level, for validation, and where that comes from.
            'appearance' => (object) \Ichiloto\Editor\Maps\TilesetAuthoring::describeAppearance($tileset,
                array_merge(...array_map(static fn(array $tab): array => array_merge(...$tab['ids']), $tabs) ?: [[]])),
            'appearanceSheets' => (object) array_map(static fn(TileAppearance $class): string => $class->value, $tileset->appearance->sheets),
            // The tiles shown that can be tables, so an interface offers the mark only where it holds.
            'tableTiles' => array_values(array_filter(array_merge(...array_map(static fn(array $tab): array => array_merge(...$tab['ids']), $tabs) ?: [[]]),
                static fn(int $tile): bool => TileId::getSheet($tile) === TilesetSheet::A2)),
            'pieces' => $pieces,
            'authoring' => \Ichiloto\Editor\Maps\TilesetAuthoring::describePieces($data, $this->readDatabaseRecord('tilesets', $index)['rows']),
            // The names a new piece's glyph and tile layers take: those the tileset's pieces and the maps drawn with it use.
            'layerNames' => \Ichiloto\Editor\Maps\TilesetAuthoring::listLayerNames($tileset, $this->listMapsOfTileset($tileset->id)),
            'connectionShapes' => TilesetPiece::LINE_SHAPES,
            'collisionTypes' => PhysicalOccupancyEditor::getCollisionChoices(),
            'issue' => $issue,
            'unreadable' => $unreadable,
        ];
    }

    /**
     * The maps whose data names a tileset, unsaved edits included.
     *
     * @return list<ProjectMap>
     */
    private function listMapsOfTileset(string $tilesetId): array
    {
        $maps = [];
        for ($index = 0; ($map = $this->workspace->getMapByIndex($index)) !== null; $index++) {
            if ($map->getMapDataField(['tileset']) === $tilesetId) {
                $maps[] = $map;
            }
        }

        return $maps;
    }

    /**
     * Sets or removes one explicitly selected piece recipe through its current
     * record field, preserving source and shared undo. Wire cells are integers
     * or null; a null value removes the recipe, never authors an explicit null.
     *
     * @param list<list<int|null>>|null $expected The recipe the caller last read.
     * @param list<list<int|null>>|null $value The new recipe, or null to remove it.
     */
    public function setTilesetOccupancy(int $index, string $tilesetId, string $pieceId, ?array $expected, ?array $value): array
    {
        $record = $this->requireRecordDatabase('tilesets')->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('tilesets has no record %d.', $index));
        if ($record->recordId !== $tilesetId) {
            throw new SessionRefusal('That tileset record changed; read the tileset list again.');
        }
        $data = (array) $record->toArray();
        $piece = is_array($data['pieces'] ?? null) ? ($data['pieces'][$pieceId] ?? null) : null;
        if (! is_array($piece)) {
            throw new SessionRefusal(sprintf('%s is no longer in tileset %s; choose a piece again.', $pieceId, $tilesetId));
        }
        try {
            if ($expected !== null) { \Ichiloto\Editor\Maps\PhysicalFootprintCodec::decodeRows($expected); }
            $current = array_key_exists('occupancy', $piece)
                ? \Ichiloto\Editor\Maps\PhysicalFootprintCodec::exportRows($piece['occupancy']) : null;
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal('The current or expected recipe cannot be compared safely. Repair its Physical Footprint record row first: '
                . $error->getMessage(), previous: $error);
        }
        if ($current !== $expected) {
            throw new SessionRefusal('That physical footprint changed; read the piece again before editing it.');
        }
        try {
            $declaration = $value === null ? null : \Ichiloto\Editor\Maps\PhysicalFootprintCodec::decodeRows($value);
            // Graphical rows, and the effect and doors found by them, do not own logical footprint geometry.
            unset($piece['tiles'], $piece['effect'], $piece['occupancy'], $piece['doorway']);
            if ($declaration !== null) { $piece['occupancy'] = $declaration; }
            TilesetPiece::fromArray($pieceId, $piece, 'Tileset occupancy authoring');
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        $described = array_find(\Ichiloto\Editor\Maps\TilesetAuthoring::describePieces($data,
            $this->readDatabaseRecord('tilesets', $index)['rows']), static fn(array $entry): bool => $entry['id'] === $pieceId);
        $key = $described['occupancyKey'] ?? null;
        if (! is_array($key)) {
            throw new SessionRefusal('That physical footprint row cannot be edited; read the record again.');
        }
        $result = $this->applyDatabaseRecord('tilesets', $index, $key,
            \Ichiloto\Editor\Maps\PhysicalFootprintCodec::encode($declaration));
        if (($result['status'] ?? null) === 'question'
            || (($result['changed'] ?? false) !== true && $current !== $value)) {
            throw new SessionRefusal('The physical footprint was not applied. Resolve its source edit through the Physical Footprint record row first.');
        }

        return [...$result, 'occupancy' => $value];
    }

    /**
     * Marks one tile of a tileset record as drawn above characters or as a
     * table, or takes the mark away when it has it, as one undo step through
     * the record's own Above Characters or Tables field, so every view of
     * the record shows the same list. An autotile is marked by its kind,
     * whatever shape it is, as the Engine reads the list; only A2 autotiles
     * can be tables.
     *
     * @param 'above'|'tables' $mark
     * @return array{changed: bool, marked: bool, records: list<string>}
     * @throws SessionRefusal When the record or mark is unknown, or the tile cannot take the mark.
     */
    public function toggleTilesetMark(int $index, string $mark, int $tile): array
    {
        $record = $this->requireRecordDatabase('tilesets')->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('tilesets has no record %d.', $index));
        $this->assertTileMarkable($mark, $tile);
        $flag = TileId::getFlagId($tile);
        $listed = array_values(array_filter(is_array($record->get($mark)) ? $record->get($mark) : [], is_int(...)));
        $marked = ! array_any($listed, static fn(int $listedTile): bool => TileId::getFlagId($listedTile) === $flag);

        return [...$this->setTilesetMarks($index, $mark, [$tile], $marked), 'marked' => $marked];
    }

    /**
     * Gives several tiles of a tileset record a mark, or takes it from them,
     * as one undo step through the record's Above Characters or Tables
     * field: what a drag across a building's roof does. Tiles already as
     * asked stay as they are.
     *
     * @param 'above'|'tables' $mark
     * @param list<int> $tiles
     * @return array{changed: bool, records: list<string>, tiles: int}
     * @throws SessionRefusal When the record or mark is unknown, or a tile cannot take the mark.
     */
    public function setTilesetMarks(int $index, string $mark, array $tiles, bool $marked): array
    {
        $record = $this->requireRecordDatabase('tilesets')->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('tilesets has no record %d.', $index));
        if ($tiles === []) {
            throw new SessionRefusal('Choose tiles to mark first.');
        }
        $flags = [];
        foreach ($tiles as $tile) {
            $this->assertTileMarkable($mark, $tile);
            $flags[TileId::getFlagId($tile)] = true;
        }
        $listed = array_values(array_filter(is_array($record->get($mark)) ? $record->get($mark) : [], is_int(...)));
        $present = array_map(TileId::getFlagId(...), $listed);
        // Tiles marked already keep their place in the list, and new ones follow.
        $next = $marked
            ? [...$listed, ...array_values(array_diff(array_keys($flags), $present))]
            : array_values(array_filter($listed, static fn(int $listedTile): bool => ! isset($flags[TileId::getFlagId($listedTile)])));
        $result = $this->applyDatabaseRecord('tilesets', $index, ['field' => $mark, 'frame' => []], implode(', ', $next));

        return [...$result, 'tiles' => count($flags)];
    }

    /** @throws SessionRefusal When the mark is not one, or the tile cannot take it. */
    private function assertTileMarkable(string $mark, int $tile): void
    {
        if (! in_array($mark, ['above', 'tables'], true)) {
            throw new SessionRefusal(sprintf('%s is not a tile mark; a tile is marked above characters or as a table.', $mark));
        }
        if ($tile === TileId::EMPTY || ! TileId::isValid($tile)) {
            throw new SessionRefusal(sprintf('%d is not a tile, so it cannot be marked.', $tile));
        }
        if ($mark === 'tables' && TileId::getSheet($tile) !== TilesetSheet::A2) {
            throw new SessionRefusal(sprintf('Only A2 autotiles can be tables; tile %d is on sheet %s.', $tile, TileId::getSheet($tile)?->value ?? '?'));
        }
    }

    /**
     * Sets what tiles or whole sheets of a tileset record show at ground
     * level ({@see \Ichiloto\Engine\Rendering\Tilesets\TilesetAppearance}), or
     * with null takes their own class away so they read by their sheet again,
     * as one undo step through the record's Appearance rows. Validation alone
     * reads the classes; passage never changes. A selection the Engine would
     * refuse, such as one sheet named for two classes, changes nothing.
     *
     * @param list<int|string> $selectors Tile identities and sheet names.
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When the record changed, a selector or class is unknown, or the result would be refused.
     */
    public function setTileAppearance(int $index, string $tilesetId, array $selectors, ?string $appearance): array
    {
        $class = $appearance === null ? null : (TileAppearance::tryFrom($appearance)
            ?? throw new SessionRefusal(sprintf('%s is not a tile appearance; choose %s.', $appearance,
                implode(', ', array_column(TileAppearance::cases(), 'value')))));
        $record = $this->requireRecordDatabase('tilesets')->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('tilesets has no record %d.', $index));
        if ($record->recordId !== $tilesetId) {
            throw new SessionRefusal('That tileset record changed; read the tileset list again.');
        }
        if ($selectors === []) {
            throw new SessionRefusal('Choose tiles or a sheet first.');
        }
        $data = (array) $record->toArray();
        try {
            $fields = \Ichiloto\Editor\Maps\TilesetAuthoring::planAppearance($data, $selectors, $class);
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        if ($fields === []) {
            return ['changed' => false, 'records' => $this->requireCategory('tilesets')->getRecordLabels()];
        }

        return $this->applyDatabaseRecordValues('tilesets', $index, array_map(static fn(string $field, string $value): array
            => ['key' => ['field' => $field, 'frame' => []], 'value' => $value], array_keys($fields), $fields),
            $class === null ? 'Clear tile appearance' : sprintf('Tile appearance: %s', $class->value));
    }

    /**
     * The cells of a map where what it shows disagrees with what its
     * collision lets the player do ({@see PassageAgreementCheck}), unsaved
     * edits included, each with its rule: what a collision view marks.
     * `available` is false for a map without graphics, or whose graphics
     * cannot be read.
     *
     * @return array{map: string, revision: int, available: bool, cells: list<array{0: int, 1: int, 2: string}>}
     * @throws SessionRefusal When the map is unknown.
     */
    public function readPassageAgreement(string $mapId): array
    {
        $map = $this->requireMap($mapId);
        $cells = PassageAgreementCheck::listFlaggedCells($map);

        return [
            'map' => $map->mapId,
            'revision' => $this->getMapRevision($map),
            'available' => $cells !== null,
            'cells' => array_map(static fn(array $cell): array => [$cell['x'], $cell['y'], $cell['rule']], $cells ?? []),
        ];
    }

    /**
     * Measures a walk on a map, unsaved edits included ({@see MapWalkMeasurement}):
     * the shortest walk from the first point through each waypoint to the last,
     * its steps, walking and dashing seconds, the GPUI field screens it crosses
     * and the encounters expected on the way. Read-only: nothing changes.
     *
     * Screens are counted in the field a GPUI session shows at the game's
     * default graphical screen, the battle footprint in GPUI text cells
     * ({@see \Ichiloto\Engine\Core\Game::resolveScreenSize()}), at the
     * project's field zoom, as GameScene builds its field view.
     *
     * @param list<array{0: int, 1: int}> $points
     * @return array<string, mixed>
     * @throws SessionRefusal When the map is unknown or cannot be read as the game reads it.
     */
    public function measureWalk(string $mapId, array $points): array
    {
        $map = $this->requireMap($mapId);
        $zoom = $this->workspace->config?->getRecord(ProjectConfig::FIELD_ZOOM)->get('value');
        // The field falls back to its default zoom for a value it cannot use, and warns there.
        $zoom = (is_int($zoom) || is_float($zoom)) && is_finite((float) $zoom)
            && $zoom >= FieldViewport::MIN_ZOOM && $zoom <= FieldViewport::MAX_ZOOM ? (float) $zoom : FieldViewport::DEFAULT_ZOOM;
        $view = new FieldViewport(new RendererGridConfig(BattleScreen::WIDTH, BattleScreen::HEIGHT,
            RendererRuntimeConfig::GPUI_CELL_WIDTH, RendererRuntimeConfig::GPUI_CELL_HEIGHT), $zoom);
        [$dashSpeed, $dashUnavailable] = $this->readDashSpeed($map);
        try {
            $measurement = new MapWalkMeasurement($map, $view, dashSpeedMultiplier: $dashSpeed, dashUnavailable: $dashUnavailable)
                ->measure($points);
        } catch (InvalidArgumentException|MapSourceRefusal $refusal) {
            throw new SessionRefusal(sprintf('%s cannot be measured: %s', $mapId, $refusal->getMessage()), previous: $refusal);
        }

        return ['map' => $map->mapId, 'revision' => $this->getMapRevision($map), ...$measurement];
    }

    /**
     * How many times faster than walking the player dashes on a map, by the Engine's own reading of the game's
     * System dash and the map's Disable Dashing, unsaved edits included; or null and why the player cannot dash there.
     *
     * @return array{0: ?float, 1: ?string}
     */
    private function readDashSpeed(ProjectMap $map): array
    {
        $system = $this->workspace->getRecordDatabase('system')?->getRecordByIndex(0)?->toArray() ?? [];
        try {
            $dash = DashSettings::fromSystemData($system);
            if (! $dash->enabled) {
                // The measurement's own wording for a game without a dash.
                return [null, null];
            }
            if (DashSettings::readMapDisablesDashing($map->getEditableData())) {
                return [null, 'This map turns dashing off, so only walking time is shown.'];
            }
        } catch (InvalidArgumentException $invalid) {
            return [null, sprintf('The game cannot read its dash (%s), so only walking time is shown.', $invalid->getMessage())];
        }

        return [$dash->speedMultiplier, null];
    }

    /**
     * Each tile layer's tile identities by row, unsaved edits included, in
     * drawing order: what a tile picker reads. A layer that cannot be read
     * says why instead.
     *
     * @return array{map: string, revision: int, layers: list<array{name: string, rows: list<list<int>>, issue: ?string}>}
     * @throws SessionRefusal When the map is unknown.
     */
    public function readTiles(string $mapId): array
    {
        $map = $this->requireMap($mapId);
        $layers = [];
        foreach ($map->getTileLayerNames() as $name) {
            try {
                $rows = $map->readTileEntries([$name], 0, 0, $map->getWidth(), $map->getHeight())[$name] ?? [];
                $layers[] = ['name' => $name, 'rows' => array_map(static fn(array $row): array => array_map(intval(...), $row), $rows),
                    'issue' => null];
            } catch (MapSourceRefusal $refusal) {
                $layers[] = ['name' => $name, 'rows' => [], 'issue' => $refusal->getMessage()];
            }
        }

        return ['map' => $map->mapId, 'revision' => $this->getMapRevision($map), 'layers' => $layers];
    }

    /**
     * Sets one tile in cells of a tile layer, `0` erasing, as one undo step,
     * by {@see stampTiles()}.
     *
     * @param list<array{0: int, 1: int}> $cells The cells, as [x, y].
     * @param array<string, string> $choices The role key chosen for a tile that could stand for several glyphs, by tile entry.
     * @return array{status: 'applied', changed: int, glyphs: int, revision: int}|array{status: 'question', tile: string, roles: list<array{key: string, label: string}>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or refuses the tile.
     */
    public function paintTiles(string $mapId, int $revision, string $layerName, array $cells, int $tile, string $label = 'Place tiles',
        array $choices = []): array
    {
        return $this->stampTiles($mapId, $revision, $layerName, array_map(static fn(array $cell): array => [$cell[0], $cell[1], $tile], $cells),
            $label, $choices);
    }

    /**
     * Sets each cell of a tile layer to its own tile as one undo step
     * ({@see CanvasEditor::stampTiles()}): a block chosen in the palette or
     * picked from the map, stamped where the author drags, or an erase and a
     * stamp together that move it. A layer the map does not have yet is
     * created. A tile that stands for a glyph brings or takes that glyph and
     * its collision with it; a tile that could stand for several is asked
     * about, and nothing changes until the edit is made again with the answer.
     *
     * @param list<array{0: int, 1: int, 2: int}> $cells Each cell as [x, y, tile].
     * @param array<string, string> $choices The role key chosen for a tile that could stand for several glyphs, by tile entry.
     * @return array{status: 'applied', changed: int, glyphs: int, revision: int}|array{status: 'question', tile: string, roles: list<array{key: string, label: string}>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or refuses a tile.
     */
    public function stampTiles(string $mapId, int $revision, string $layerName, array $cells, string $label = 'Place tiles',
        array $choices = []): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        try {
            $applied = CanvasEditor::stampTiles($map, $layerName, $cells, $label, $choices);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($applied['unresolved'] !== []) {
            $tile = (string) array_key_first($applied['unresolved']);

            return ['status' => 'question', 'tile' => $tile, 'roles' => array_map(
                static fn(PieceRole $role): array => ['key' => $role->key, 'label' => $role->label],
                $applied['unresolved'][$tile],
            )];
        }
        if ($applied['command'] !== null) {
            $this->history->record($applied['command']);
        }

        return ['status' => 'applied', 'changed' => $applied['changed'], 'glyphs' => $applied['glyphs'], 'revision' => $this->getMapRevision($map)];
    }

    /**
     * The cells a canvas tool paints, the one way every interface works them
     * out ({@see CanvasTool::getShapeCells()}): a brush stroke's path widened
     * by the brush, or a line or rectangle between two corners. Cells off the
     * map are left out, so an interface previews and paints exactly these.
     *
     * @param array{0: int, 1: int} $from The anchor.
     * @param array{0: int, 1: int} $to The cursor.
     * @param list<array{0: int, 1: int}> $path A brush stroke's cells, in the order dragged; the cursor alone when empty.
     * @return array{cells: list<array{0: int, 1: int}>}
     * @throws SessionRefusal When the map, the tool or the brush width is unknown.
     */
    public function getToolShape(string $mapId, string $tool, array $from, array $to, int $brushSize, array $path = []): array
    {
        $map = $this->requireMap($mapId);
        $canvasTool = CanvasTool::tryFrom($tool);
        if ($canvasTool === null || $canvasTool === CanvasTool::SELECT) {
            throw new SessionRefusal(sprintf('%s is not a painting tool.', $tool));
        }
        if (! in_array($brushSize, CanvasTool::BRUSH_SIZES, true)) {
            throw new SessionRefusal(sprintf('The brush is %s cells wide.', implode(', ', CanvasTool::BRUSH_SIZES)));
        }
        [$anchor, $cursor] = [['x' => $from[0], 'y' => $from[1]], ['x' => $to[0], 'y' => $to[1]]];
        $cells = $canvasTool === CanvasTool::BRUSH && $path !== []
            ? ToolGeometry::expandByBrush(array_map(static fn(array $cell): array => ['x' => $cell[0], 'y' => $cell[1]], $path), $brushSize)
            : $canvasTool->getShapeCells($anchor, $cursor, $brushSize);

        return ['cells' => $this->listCellsOnMap($map, $cells)];
    }

    /**
     * The region a flood fill paints from a cell: the cells joined to it
     * across and down that hold the same glyph on a glyph layer, or the same
     * tile on a tile layer, an autotile counting as its kind whatever shape
     * its neighbours give it. A tile layer the map does not have yet is empty
     * everywhere.
     *
     * @return array{cells: list<array{0: int, 1: int}>}
     * @throws SessionRefusal When the map or the glyph layer is unknown, or not exactly one layer is named.
     */
    public function getFillRegion(string $mapId, int $x, int $y, ?string $layerId, ?string $tileLayer): array
    {
        $map = $this->requireMap($mapId);
        if (($layerId === null) === ($tileLayer === null)) {
            throw new SessionRefusal('Name the glyph layer or the tile layer to fill.');
        }
        if ($layerId !== null) {
            if (! array_any($map->getLayers(), static fn(array $layer): bool => $layer['id'] === $layerId)) {
                throw new SessionRefusal(sprintf('%s has no layer %s.', $mapId, $layerId));
            }
            $identityAt = static fn(int $x, int $y): string => $map->getLayerSymbol($layerId, $x, $y);
        } else {
            try {
                $rows = in_array($tileLayer, $map->getTileLayerNames(), true)
                    ? $map->readTileEntries([$tileLayer], 0, 0, $map->getWidth(), $map->getHeight())[$tileLayer] ?? []
                    : [];
            } catch (MapSourceRefusal $refusal) {
                throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
            }
            $identityAt = static function (int $x, int $y) use ($rows): string {
                $tile = (int) ($rows[$y][$x] ?? TileId::EMPTY);

                return TileId::isAutotile($tile) ? 'kind:' . TileId::getKind($tile) : (string) $tile;
            };
        }

        return ['cells' => $this->listCellsOnMap($map, ToolGeometry::floodFill($identityAt, $map->getWidth(), $map->getHeight(), $x, $y))];
    }

    /**
     * @param array<int, array{x: int, y: int}> $cells
     * @return list<array{0: int, 1: int}>
     */
    private function listCellsOnMap(ProjectMap $map, array $cells): array
    {
        $listed = [];
        foreach ($cells as ['x' => $x, 'y' => $y]) {
            if ($x >= 0 && $y >= 0 && $x < $map->getWidth() && $y < $map->getHeight()) {
                $listed[] = [$x, $y];
            }
        }

        return $listed;
    }

    /**
     * The pieces of the map's tileset an author places ({@see PiecePlacer}),
     * in authored order: each with the gameplay layer its glyphs go on and
     * whether the map has it, whether it is drawn as connected cells rather
     * than stamped whole, its size, a picture of what it draws, its physical
     * recipe and the doors its art paints. A map
     * whose tileset offers none says why in `issue`.
     *
     * @return array{map: string, pieces: list<array<string, mixed>>, issue: ?string}
     * @throws SessionRefusal When the map is unknown.
     */
    public function listPieces(string $mapId): array
    {
        $map = $this->requireMap($mapId);
        try {
            $pieces = PiecePlacer::loadPieces($map);
        } catch (MapSourceRefusal $refusal) {
            return ['map' => $map->mapId, 'pieces' => [], 'issue' => $refusal->getMessage()];
        }
        // Loading the pieces loaded the tileset, so it reads here.
        $tileset = $map->loadTileset();
        $described = [];
        foreach ($pieces as $piece) {
            try {
                PiecePlacer::findLayer($map, $piece);
                $layerIssue = null;
            } catch (MapSourceRefusal $refusal) {
                $layerIssue = $refusal->getMessage();
            }
            $described[] = [
                'id' => $piece->id,
                'name' => $piece->name,
                'layer' => $piece->layer,
                'layerLabel' => MapLayers::formatLabel($piece->layer),
                'layerIssue' => $layerIssue,
                'connected' => $piece->connects !== null,
                'width' => $piece->width,
                'height' => $piece->height,
                'tileLayers' => $tileset?->getPieceTileLayers($piece) ?? [],
                'picture' => PiecePlacer::buildPicture($piece),
                'occupancy' => PhysicalOccupancyEditor::exportRecipe($piece),
                // Where its art paints doors, from its top-left cell; each map's entrances must meet them.
                'doorway' => \Ichiloto\Engine\Field\Doorway::exportCells($piece->doorway),
            ];
        }

        return ['map' => $map->mapId, 'tileset' => $map->getMapDataField(['tileset']), 'pieces' => $described, 'issue' => null];
    }

    /**
     * What placing a piece between two corners would write, for a preview
     * ({@see PiecePlacer::getPreviewCells()}): each cell the piece writes, as
     * [x, y, glyph, colour], cells off the map left out.
     *
     * @param array{0: int, 1: int} $from
     * @param array{0: int, 1: int} $to
     * @param string|null $color The colour of cells the piece leaves unstyled.
     * @return array{cells: list<array{0: int, 1: int, 2: string, 3: ?string}>}
     * @throws SessionRefusal When the map or the piece is unknown.
     */
    public function previewPiece(string $mapId, string $pieceId, array $from, array $to, ?string $color = null): array
    {
        $map = $this->requireMap($mapId);
        $piece = $this->requirePiece($map, $pieceId);
        $cells = [];
        foreach (PiecePlacer::getPreviewCells($map, $piece, ['x' => $from[0], 'y' => $from[1]], ['x' => $to[0], 'y' => $to[1]], $color) as $y => $row) {
            foreach ($row as $x => $cell) {
                if ($cell !== null && $x >= 0 && $y >= 0 && $x < $map->getWidth() && $y < $map->getHeight()) {
                    $cells[] = [$x, $y, $cell['symbol'], $cell['color']];
                }
            }
        }

        return ['cells' => $cells];
    }

    /**
     * Places a piece between two corners as one undo step: an item piece
     * stamped as whole copies across the area, a connected piece drawn along
     * its outline ({@see PiecePlacer}). An area that cannot be placed whole
     * changes nothing. With `$footprint`, each stamped copy also sets the
     * collision its physical footprint gives, in the same step, on a map with
     * its own collision ({@see PlacedPieces::addFootprint()}); without it, or
     * for a piece with no footprint, collision is left as it is.
     *
     * @param array{0: int, 1: int} $from
     * @param array{0: int, 1: int} $to
     * @param string|null $color The colour of cells the piece leaves unstyled; null keeps each cell's own.
     * @return array{status: 'applied', changed: bool, copies: int, cells: int, footprint: int, revision: int}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the piece is unknown, or the placement is refused.
     */
    public function placePiece(string $mapId, int $revision, string $pieceId, array $from, array $to, ?string $color = null,
        bool $footprint = false): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        $piece = $this->requirePiece($map, $pieceId);
        [$from, $to] = [['x' => $from[0], 'y' => $from[1]], ['x' => $to[0], 'y' => $to[1]]];
        try {
            $stamped = 0;
            if ($piece->connects === null) {
                ['command' => $command, 'origins' => $origins] = PiecePlacer::stampArea($map, $piece, $from, $to, $color);
                [$copies, $cells] = [count($origins), 0];
                if ($footprint) {
                    ['command' => $command, 'cells' => $stamped] = PlacedPieces::addFootprint($map, $piece, $command, $origins,
                        sprintf('Place %s', $piece->name));
                }
            } else {
                $drawn = PiecePlacer::getConnectedDrawCells($from, $to);
                $command = PiecePlacer::drawConnected($map, $piece, $drawn, [], $color);
                [$copies, $cells] = [0, count($drawn)];
            }
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($command !== null) {
            $this->history->record($command);
        }

        return ['status' => 'applied', 'changed' => $command !== null, 'copies' => $copies, 'cells' => $cells, 'footprint' => $stamped,
            'revision' => $this->getMapRevision($map)];
    }

    /**
     * Stamps whole copies of a piece with their top-left cells at the listed
     * origins, as one undo step: scattered copies, such as the peaks of a
     * range, placed by a script in one request rather than one per copy.
     * Copies follow the rules of {@see placePiece()}; a connected piece is
     * drawn, not stamped, and is refused.
     *
     * @param list<array{0: int, 1: int}> $origins
     * @return array{status: 'applied', changed: bool, copies: int, cells: int, footprint: int, revision: int}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the piece is unknown or connected, or a copy falls off the map.
     */
    public function placePieceCopies(string $mapId, int $revision, string $pieceId, array $origins, ?string $color = null,
        bool $footprint = false): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        $piece = $this->requirePiece($map, $pieceId);
        $origins = array_map(static fn(array $cell): array => ['x' => $cell[0], 'y' => $cell[1]], $origins);
        $stamped = 0;
        try {
            $command = PiecePlacer::stampOrigins($map, $piece, $origins, $color);
            if ($footprint) {
                ['command' => $command, 'cells' => $stamped] = PlacedPieces::addFootprint($map, $piece, $command, $origins,
                    sprintf('Place %s', $piece->name));
            }
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($command !== null) {
            $this->history->record($command);
        }

        return ['status' => 'applied', 'changed' => $command !== null, 'copies' => count($origins), 'cells' => 0, 'footprint' => $stamped,
            'revision' => $this->getMapRevision($map)];
    }

    /** @throws SessionRefusal When the map's tileset has no piece with the id. */
    private function requirePiece(ProjectMap $map, string $pieceId): TilesetPiece
    {
        try {
            $pieces = PiecePlacer::loadPieces($map);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }

        return $pieces[$pieceId] ?? throw new SessionRefusal(sprintf("%s is no longer in the map's tileset. Choose a piece again.", $pieceId));
    }

    /**
     * The terrain kinds a map's tileset declares, for a kind palette: each
     * kind's glyph in its colour, passage, encounter terrain, ground and
     * atlas role, and whether its art is still to be drawn. `painted` says
     * whether the map is painted in kinds; until it is, `issue` says how to
     * convert it, and no kind can be painted.
     *
     * @return array{map: string, tileset: mixed, painted: bool, kinds: list<array<string, mixed>>, issue: ?string}
     * @throws SessionRefusal When the map is unknown.
     */
    public function listKinds(string $mapId): array
    {
        $map = $this->requireMap($mapId);
        try {
            $kinds = TerrainKindPainter::readTilesetKinds($map);
        } catch (MapSourceRefusal $refusal) {
            return ['map' => $map->mapId, 'tileset' => $map->getMapDataField(['tileset']), 'painted' => false, 'kinds' => [],
                'issue' => $refusal->getMessage()];
        }
        $issue = null;
        try {
            TerrainKindPainter::loadKinds($map);
        } catch (MapSourceRefusal $refusal) {
            $issue = $refusal->getMessage();
        }
        $described = [];
        foreach ($kinds as $kind) {
            try {
                PiecePlacer::findLayer($map, $kind->piece);
                $layerIssue = null;
            } catch (MapSourceRefusal $refusal) {
                $layerIssue = $refusal->getMessage();
            }
            $picture = PiecePlacer::buildPicture($kind->piece);
            $described[] = [
                'id' => $kind->id,
                'name' => $kind->name,
                'layer' => $kind->layer,
                'layerLabel' => MapLayers::formatLabel($kind->layer),
                'layerIssue' => $layerIssue,
                'glyph' => $kind->symbol,
                'color' => $picture[0][0]['color'] ?? null,
                'passage' => $kind->passage->value,
                'encounters' => $kind->encounters,
                'ground' => $kind->ground === null ? null : $kinds[$kind->ground]->name,
                'atlas' => $kind->atlas,
                'awaitingArt' => $kind->awaitingArt,
                'tileLayers' => array_keys($kind->piece->tiles),
            ];
        }

        return ['map' => $map->mapId, 'tileset' => $map->getMapDataField(['tileset']), 'painted' => $issue === null,
            'kinds' => $described, 'issue' => $issue];
    }

    /**
     * What painting a kind between two corners would write, for a preview:
     * each cell as [x, y, glyph, colour] ({@see PiecePlacer::getPreviewCells()}).
     *
     * @param array{0: int, 1: int} $from
     * @param array{0: int, 1: int} $to
     * @return array{cells: list<array{0: int, 1: int, 2: string, 3: ?string}>}
     * @throws SessionRefusal When the map or the kind is unknown, or the map is not painted in kinds.
     */
    public function previewKind(string $mapId, string $kindId, array $from, array $to): array
    {
        $map = $this->requireMap($mapId);
        $kind = $this->requireKind($map, $kindId);
        $cells = [];
        foreach (PiecePlacer::getPreviewCells($map, $kind->piece, ['x' => $from[0], 'y' => $from[1]], ['x' => $to[0], 'y' => $to[1]]) as $y => $row) {
            foreach ($row as $x => $cell) {
                if ($cell !== null && $x >= 0 && $y >= 0 && $x < $map->getWidth() && $y < $map->getHeight()) {
                    $cells[] = [$x, $y, $cell['symbol'], $cell['color']];
                }
            }
        }

        return ['cells' => $cells];
    }

    /**
     * Paints a kind over the area between two corners as one undo step: its
     * glyph and its tiles, and on a map with declared occupancy its collision
     * ({@see TerrainKindPainter::paintArea()}).
     *
     * @param array{0: int, 1: int} $from
     * @param array{0: int, 1: int} $to
     * @return array{status: 'applied', changed: bool, cells: int, revision: int}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the kind is unknown, or the paint is refused.
     */
    public function paintKind(string $mapId, int $revision, string $kindId, array $from, array $to): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        $kind = $this->requireKind($map, $kindId);
        try {
            ['command' => $command, 'cells' => $cells] = TerrainKindPainter::paintArea($map, $kind,
                ['x' => $from[0], 'y' => $from[1]], ['x' => $to[0], 'y' => $to[1]], 'Paint ' . $kind->name);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($command !== null) {
            $this->history->record($command);
        }

        return ['status' => 'applied', 'changed' => $command !== null, 'cells' => $cells, 'revision' => $this->getMapRevision($map)];
    }

    /**
     * Paints a kind on the listed cells as one undo step, as {@see paintKind()} paints an area: for terrain
     * that follows no rectangle, such as a coast or a range, painted by a script in one request per kind.
     *
     * @param list<array{0: int, 1: int}> $cells
     * @return array{status: 'applied', changed: bool, cells: int, revision: int}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the kind is unknown, a cell is off the map, or the paint is refused.
     */
    public function paintKindCells(string $mapId, int $revision, string $kindId, array $cells): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        $kind = $this->requireKind($map, $kindId);
        $unique = [];
        foreach ($cells as [$x, $y]) {
            if ($x < 0 || $y < 0 || $x >= $map->getWidth() || $y >= $map->getHeight()) {
                throw new SessionRefusal(sprintf('x %d, y %d is off %s, which is %d by %d cells.', $x, $y, $mapId, $map->getWidth(), $map->getHeight()));
            }
            $unique["{$x},{$y}"] = ['x' => $x, 'y' => $y];
        }
        try {
            $command = TerrainKindPainter::paintCells($map, $kind, array_values($unique), 'Paint ' . $kind->name);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($command !== null) {
            $this->history->record($command);
        }

        return ['status' => 'applied', 'changed' => $command !== null, 'cells' => count($unique), 'revision' => $this->getMapRevision($map)];
    }

    /**
     * The kind a cell shows in both presentations, for picking it up: on the
     * topmost gameplay layer that shows one there; null when none does.
     *
     * @return array{kind: ?string}
     * @throws SessionRefusal When the map is unknown.
     */
    public function readKindAt(string $mapId, int $x, int $y): array
    {
        $map = $this->requireMap($mapId);
        foreach (array_reverse($map->getLayers()) as $layer) {
            if ($layer['id'] === MapLayers::EVENT || $layer['decoration']) {
                continue;
            }
            $kind = TerrainKindPainter::findKindAt($map, $layer['id'], $x, $y);
            if ($kind !== null) {
                return ['kind' => $kind->id];
            }
        }

        return ['kind' => null];
    }

    /**
     * What converting a map to terrain kinds would do, changing nothing
     * ({@see TerrainKindConversion::preview()}).
     *
     * @param array<string, string> $mapping Kind ids by old plain glyph.
     * @return array<string, mixed>
     * @throws SessionRefusal When the map is unknown or the mapping is refused.
     */
    public function previewKindConversion(string $mapId, array $mapping): array
    {
        try {
            return TerrainKindConversion::preview($this->requireMap($mapId), $mapping);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
    }

    /**
     * Converts a map to terrain kinds as one undo step ({@see TerrainKindConversion::apply()}).
     *
     * @param array<string, string> $mapping Kind ids by old plain glyph.
     * @return array{status: 'applied', changed: bool, cells: int, revision: int}
     * @throws SessionRefusal When the map is unknown or stale, or the conversion is refused.
     */
    public function convertToKinds(string $mapId, int $revision, array $mapping): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        try {
            ['command' => $command, 'cells' => $cells] = TerrainKindConversion::apply($map, $mapping);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($command !== null) {
            $this->history->record($command);
        }

        return ['status' => 'applied', 'changed' => $command !== null, 'cells' => $cells, 'revision' => $this->getMapRevision($map)];
    }

    /** @throws SessionRefusal When the map cannot be painted in kinds, or has no kind with the id. */
    private function requireKind(ProjectMap $map, string $kindId): TerrainKind
    {
        try {
            return TerrainKindPainter::requireKind($map, $kindId);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
    }

    /**
     * The cells of a map whose glyph still shows in the graphical field, by
     * the Engine's glyph fallback rule ({@see MapGraphicsValidator::listShownGlyphCells()}),
     * unsaved edits included: what is left to tile. `available` is false for
     * a map without a kind, or whose kind or tile layers cannot be read.
     *
     * @return array{map: string, revision: int, available: bool, cells: list<array{0: int, 1: int, 2: string, 3: string}>}
     * @throws SessionRefusal When the map is unknown.
     */
    public function readCoverage(string $mapId): array
    {
        $map = $this->requireMap($mapId);
        $cells = MapGraphicsValidator::listShownGlyphCells($map);

        return [
            'map' => $map->mapId,
            'revision' => $this->getMapRevision($map),
            'available' => $cells !== null,
            'cells' => array_map(static fn(array $cell): array => [$cell['x'], $cell['y'], $cell['glyph'], $cell['layer']], $cells ?? []),
        ];
    }

    /**
     * The piece a layer's glyph plays at a cell ({@see CanvasEditor::findPlayedRole()}):
     * what an eyedropper picks up with the glyph, so painting it again draws
     * the same piece. Null role when no piece draws it or its tiles do not tell.
     *
     * @return array{glyph: string, role: ?array{key: string, label: string}}
     * @throws SessionRefusal When the map or layer is unknown.
     */
    public function readPieceAt(string $mapId, string $layerId, int $x, int $y): array
    {
        $map = $this->requireMap($mapId);
        if (! array_any($map->getLayers(), static fn(array $layer): bool => $layer['id'] === $layerId)) {
            throw new SessionRefusal(sprintf('%s has no layer %s.', $mapId, $layerId));
        }
        $role = CanvasEditor::findPlayedRole($map, $layerId, $x, $y);

        return [
            'glyph' => $map->hasLayerCell($layerId, $x, $y) ? $map->getLayerSymbol($layerId, $x, $y) : ' ',
            'role' => $role === null ? null : ['key' => $role->key, 'label' => $role->label],
        ];
    }

    /**
     * Copies a rectangle of a layer into the session's clipboard: its glyphs
     * and styles, and the tiles that move with that layer ({@see CanvasClipboard::copy()}).
     *
     * @return array{layer: string, width: int, height: int}
     * @throws SessionRefusal When the map, layer or rectangle is unknown, or a tile layer cannot be read.
     */
    public function copySelection(string $mapId, string $layerId, int $x, int $y, int $width, int $height): array
    {
        $map = $this->requireMap($mapId);
        $this->requireSelection($map, $layerId, $x, $y, $width, $height);
        try {
            CanvasClipboard::copy($map, $layerId, $x, $y, $width, $height, $this->clipboard ??= new Clipboard());
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }

        return $this->describeClipboard();
    }

    /**
     * Copies a rectangle of a layer into the session's clipboard and clears
     * it, glyphs and the tiles that went with them, as one undo step.
     *
     * @return array{layer: string, width: int, height: int, changed: int, revision: int}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or the layer or rectangle is unknown.
     */
    public function cutSelection(string $mapId, int $revision, string $layerId, int $x, int $y, int $width, int $height): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        $this->requireSelection($map, $layerId, $x, $y, $width, $height);
        try {
            $cut = CanvasClipboard::cut($map, $layerId, $x, $y, $width, $height, $this->clipboard ??= new Clipboard());
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($cut['command'] !== null) {
            $this->history->record($cut['command']);
        }

        return [...$this->describeClipboard(), 'changed' => $cut['changed'], 'revision' => $this->getMapRevision($map)];
    }

    /**
     * Stamps the session's clipboard with its top-left cell at a map cell as
     * one undo step. A glyph that could be several pieces is asked about, and
     * nothing changes until the paste is made again with the answer.
     *
     * @param array<string, ?string> $choices
     * @return array{status: 'applied', changed: int, revision: int}|array{status: 'question', glyph: string, roles: list<array{key: string, label: string}>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the clipboard is empty or from another layer.
     */
    public function pasteSelection(string $mapId, int $revision, string $layerId, int $x, int $y, array $choices = []): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        try {
            $pasted = CanvasClipboard::paste($map, $layerId, $this->clipboard ??= new Clipboard(), $x, $y, $choices);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($pasted['unresolved'] !== []) {
            $glyph = (string) array_key_first($pasted['unresolved']);

            return ['status' => 'question', 'glyph' => $glyph, 'roles' => array_map(
                static fn(PieceRole $role): array => ['key' => $role->key, 'label' => $role->label],
                $pasted['unresolved'][$glyph],
            )];
        }
        if ($pasted['command'] !== null) {
            $this->history->record($pasted['command']);
        }

        return ['status' => 'applied', 'changed' => $pasted['changed'], 'revision' => $this->getMapRevision($map)];
    }

    /**
     * What the session's clipboard holds: the layer it came from and its size; an empty layer when it holds nothing.
     *
     * @return array{layer: string, width: int, height: int}
     */
    public function describeClipboard(): array
    {
        $clipboard = $this->clipboard ?? new Clipboard();

        return ['layer' => $clipboard->layer, 'width' => $clipboard->getWidth(), 'height' => $clipboard->getHeight()];
    }

    /** @throws SessionRefusal When the layer is not the map's, or the rectangle is empty or leaves the map. */
    private function requireSelection(ProjectMap $map, string $layerId, int $x, int $y, int $width, int $height): void
    {
        if (! array_any($map->getLayers(), static fn(array $layer): bool => $layer['id'] === $layerId)) {
            throw new SessionRefusal(sprintf('%s has no layer %s.', $map->mapId, $layerId));
        }
        if ($width < 1 || $height < 1 || $x < 0 || $y < 0 || $x + $width > $map->getWidth() || $y + $height > $map->getHeight()) {
            throw new SessionRefusal(sprintf('A selection of %d x %d at (%d, %d) does not fit %s.', $width, $height, $x, $y, $map->mapId));
        }
    }

    /**
     * Draws the tiles of the glyphs already on a layer as one undo step
     * ({@see CanvasEditor::drawTilesForGlyphs()}): every glyph a piece of the
     * map's kind draws gets that piece's tiles. A glyph that could be several
     * pieces is asked about, and nothing changes until the edit is made again
     * with the answer in `$choices` (a role key, or null for no tiles).
     *
     * @param array<string, ?string> $choices
     * @return array{status: 'applied', cells: int, revision: int}|array{status: 'question', glyph: string, roles: list<array{key: string, label: string}>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the layer is unknown or no piece draws its glyphs.
     */
    public function drawLayerTiles(string $mapId, int $revision, string $layerId, array $choices = []): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        $layer = array_find($map->getLayers(), static fn(array $layer): bool => $layer['id'] === $layerId)
            ?? throw new SessionRefusal(sprintf('%s has no layer %s.', $mapId, $layerId));
        try {
            $drawn = CanvasEditor::drawTilesForGlyphs($map, $layerId, $choices);
        } catch (MapSourceRefusal | InvalidArgumentException | \RuntimeException $refusal) {
            throw new SessionRefusal('Tiles were not drawn: ' . $refusal->getMessage(), previous: $refusal);
        }
        if ($drawn === null) {
            throw new SessionRefusal(sprintf('The %s layer has no pieces in this map\'s kind to draw tiles for.', MapLayers::formatLabel((string) $layer['name'])));
        }
        if ($drawn['unresolved'] !== []) {
            $glyph = (string) array_key_first($drawn['unresolved']);

            return ['status' => 'question', 'glyph' => $glyph, 'roles' => array_map(
                static fn(PieceRole $role): array => ['key' => $role->key, 'label' => $role->label],
                $drawn['unresolved'][$glyph],
            )];
        }
        if ($drawn['command'] !== null) {
            $this->history->record($drawn['command']);
        }

        return ['status' => 'applied', 'cells' => $drawn['cells'], 'revision' => $this->getMapRevision($map)];
    }

    /**
     * Paints one glyph over cells of a layer, with the tiles that follow it,
     * as one undo step. A glyph that could be several pieces is asked about:
     * nothing changes until the edit is made again with the answer in
     * `$choices` (a role key, or null for no tiles).
     *
     * @param list<array{0: int, 1: int}> $cells The cells, as [x, y].
     * @param string|null $color A colour name or `#rrggbb`; null keeps each cell's colour, '' paints none.
     * @param array<string, ?string> $choices
     * @return array{status: 'applied', changed: int, revision: int}|array{status: 'question', glyph: string, roles: list<array{key: string, label: string}>}
     * @throws SessionRefusal When the map is unknown, stale or refuses the edit.
     */
    public function paint(string $mapId, int $revision, string $layerId, array $cells, string $symbol, ?string $color = null,
        array $choices = [], string $label = 'Paint'): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        if (! array_any($map->getLayers(), static fn(array $layer): bool => $layer['id'] === $layerId)) {
            throw new SessionRefusal(sprintf('%s has no layer %s.', $mapId, $layerId));
        }
        $writes = array_map(static fn(array $cell): array => ['x' => (int) $cell[0], 'y' => (int) $cell[1],
            'symbol' => $symbol, 'color' => $layerId === MapLayers::EVENT ? '' : $color], $cells);

        return $this->paintWrites($map, $layerId, $writes, $choices, $label);
    }

    /**
     * Paints a rectangle of glyphs from its rows, one terminal cell per
     * character with its top-left at x, y, and the tiles that follow each
     * glyph, as one undo step: a room's rows rewritten in one edit. A space
     * paints a blank cell. Questions and colours are answered as by
     * {@see paint()}, `$choices` keyed by glyph.
     *
     * @param list<string> $rows The glyph rows, top to bottom.
     * @param array<string, ?string> $choices
     * @return array{status: 'applied', changed: int, revision: int}|array{status: 'question', glyph: string, roles: list<array{key: string, label: string}>}
     * @throws SessionRefusal When the map is unknown, stale or refuses the edit, or a row leaves the map.
     */
    public function paintRegion(string $mapId, int $revision, string $layerId, int $x, int $y, array $rows, ?string $color = null,
        array $choices = [], string $label = 'Paint region'): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        if (! array_any($map->getLayers(), static fn(array $layer): bool => $layer['id'] === $layerId)) {
            throw new SessionRefusal(sprintf('%s has no layer %s.', $mapId, $layerId));
        }
        $writes = [];
        foreach (array_values($rows) as $dy => $row) {
            foreach (mb_str_split($row) as $dx => $symbol) {
                [$cellX, $cellY] = [$x + $dx, $y + $dy];
                if ($cellX < 0 || $cellY < 0 || $cellX >= $map->getWidth() || $cellY >= $map->getHeight()) {
                    throw new SessionRefusal(sprintf('Row %d reaches %d, %d, outside %s (%d x %d); nothing was painted.',
                        $dy + 1, $cellX, $cellY, $mapId, $map->getWidth(), $map->getHeight()));
                }
                $writes[] = ['x' => $cellX, 'y' => $cellY, 'symbol' => $symbol, 'color' => $layerId === MapLayers::EVENT ? '' : $color];
            }
        }

        return $this->paintWrites($map, $layerId, $writes, $choices, $label);
    }

    /**
     * Paints glyph writes on a layer with the tiles that follow them, as one undo step.
     *
     * @param list<array{x: int, y: int, symbol: string, color: ?string}> $writes
     * @param array<string, ?string> $choices
     * @return array{status: 'applied', changed: int, revision: int}|array{status: 'question', glyph: string, roles: list<array{key: string, label: string}>}
     * @throws SessionRefusal When the map refuses the edit.
     */
    private function paintWrites(ProjectMap $map, string $layerId, array $writes, array $choices, string $label): array
    {
        try {
            $plan = CanvasEditor::plan($map, $layerId, $writes, $choices, true);
            if ($plan !== null && $plan['unresolved'] !== []) {
                $glyph = (string) array_key_first($plan['unresolved']);

                return ['status' => 'question', 'glyph' => $glyph, 'roles' => array_map(
                    static fn(PieceRole $role): array => ['key' => $role->key, 'label' => $role->label],
                    $plan['unresolved'][$glyph],
                )];
            }
            $applied = CanvasEditor::apply($map, $layerId, $plan['writes'] ?? $writes, $label, $plan['tiles'] ?? []);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($applied['command'] !== null) {
            $this->history->record($applied['command']);
        }

        return ['status' => 'applied', 'changed' => $applied['changed'], 'revision' => $this->getMapRevision($map)];
    }

    /**
     * Adds an empty glyph layer, gameplay or decoration, at the next order,
     * as one undo step. Layer edits answer as {@see editLayers()} describes.
     *
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}
     * @throws SessionRefusal When the map is unknown, stale or refuses the layer.
     */
    public function createLayer(string $mapId, int $revision, string $name, bool $decoration = false): array
    {
        return $this->editLayers($mapId, $revision, static fn(ProjectMap $map): array => LayerEditor::createLayer($map, $name, $decoration));
    }

    /** Explicit source-preserving conversion, recorded as one shared undo step. */
    public function migratePhysicalOccupancy(string $mapId, int $revision): array
    {
        return $this->editLayers($mapId, $revision, static fn(ProjectMap $map): array =>
            LayerEditor::migratePhysicalOccupancy($map));
    }

    /**
     * @param list<array{0: int, 1: int}> $cells
     * @return array{status: 'applied', changed: int, revision: int}
     */
    public function paintOccupancy(string $mapId, int $revision, array $cells, int $collision, string $label = 'Paint collision'): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        try {
            $applied = PhysicalOccupancyEditor::applyPaint($map, $cells, $collision, $label);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($applied['command'] !== null) {
            $this->history->record($applied['command']);
        }

        return ['status' => 'applied', 'changed' => $applied['changed'], 'revision' => $this->getMapRevision($map)];
    }

    /**
     * The project's tree types for the tree brush, by id.
     *
     * @return array{map: string, types: array<string, string>}
     * @throws SessionRefusal When the map is unknown or the field resources cannot be read.
     */
    public function listTreeTypes(string $mapId): array
    {
        try {
            return ['map' => $mapId, 'types' => TreeBrush::listTypes($this->requireMap($mapId))];
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
    }

    /**
     * Draws the chosen tree types over a region, or erases the trees in it,
     * as one undo step ({@see TreeBrush}).
     *
     * @param list<array{0: int, 1: int}> $cells
     * @param list<string> $types
     * @return array{status: 'applied', planted: int, removed: int, revision: int}
     * @throws SessionRefusal When the map is stale or read-only, has no collision of its own, or the drawing does not fit.
     */
    public function drawTrees(string $mapId, int $revision, array $cells, array $types, bool $erase = false): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        try {
            $result = $erase ? TreeBrush::erase($map, $cells) : TreeBrush::draw($map, $types, $cells);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($result['command'] !== null) {
            $this->history->record($result['command']);
        }

        return ['status' => 'applied', 'planted' => $result['planted'], 'removed' => $result['removed'], 'revision' => $this->getMapRevision($map)];
    }

    /** @return array{cells: list<array{0: int, 1: int}>, map: string, revision: int} */
    public function getOccupancyFillRegion(string $mapId, int $x, int $y, int $revision): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        try {
            $cells = PhysicalOccupancyEditor::getFillRegion($map, $x, $y);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }

        return ['cells' => $cells, 'map' => $mapId, 'revision' => $this->getMapRevision($map)];
    }

    /** @return array{cells: list<array{0: int, 1: int, 2: int}>} */
    public function previewOccupancyPiece(string $mapId, int $revision, string $tilesetId, string $pieceId, array $expected, int $x, int $y): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $piece = $this->requireOccupancyPiece($map, $tilesetId, $pieceId, $expected);
        try {
            $writes = PhysicalOccupancyEditor::getPieceWrites($map, $piece, $x, $y);
        } catch (MapSourceRefusal | InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }

        return ['cells' => array_map(static fn(array $write): array => [$write[0], $write[1], $write[2]->value], $writes)];
    }

    /** @return array{status: 'applied', changed: int, revision: int} */
    public function stampOccupancyPiece(string $mapId, int $revision, string $tilesetId, string $pieceId, array $expected, int $x, int $y): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $piece = $this->requireOccupancyPiece($map, $tilesetId, $pieceId, $expected);
        try {
            $applied = PhysicalOccupancyEditor::applyPiece($map, $piece, $x, $y);
        } catch (MapSourceRefusal | InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        if ($applied['command'] !== null) {
            $this->history->record($applied['command']);
        }

        return ['status' => 'applied', 'changed' => $applied['changed'], 'revision' => $this->getMapRevision($map)];
    }

    private function requireOccupancyPiece(ProjectMap $map, string $tilesetId, string $pieceId, array $expected): TilesetPiece
    {
        if ($map->getMapDataField(['tileset']) !== $tilesetId) {
            throw new SessionRefusal('The map tileset changed. Choose a physical footprint again. Nothing was changed.');
        }
        $piece = $this->requirePiece($map, $pieceId);
        if (PhysicalOccupancyEditor::exportRecipe($piece) !== $expected) {
            throw new SessionRefusal('The physical footprint changed. Reload the pieces before stamping. Nothing was changed.');
        }

        return $piece;
    }

    /**
     * Renames a glyph layer as one undo step. A rename that changes the
     * map's collisions is asked about; it is made when asked again confirmed.
     *
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}|array{status: 'question', question: string}
     * @throws SessionRefusal When the map is unknown, stale or refuses the rename.
     */
    public function renameLayer(string $mapId, int $revision, string $layerId, string $name, bool $confirm = false): array
    {
        return $this->editLayers($mapId, $revision, static fn(ProjectMap $map): array =>
            LayerEditor::renameLayer($map, $layerId, $name, $confirm));
    }

    /**
     * Removes a glyph layer and its cells as one undo step. A removal that
     * changes the map's collisions is asked about first. The layer left is
     * the map's base layer.
     *
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}|array{status: 'question', question: string}
     * @throws SessionRefusal When the map is unknown, stale or refuses the removal.
     */
    public function removeLayer(string $mapId, int $revision, string $layerId, bool $confirm = false): array
    {
        return $this->editLayers($mapId, $revision, static fn(ProjectMap $map): array =>
            LayerEditor::removeLayer($map, $layerId, $confirm));
    }

    /**
     * Moves a glyph layer to an order (00-99), or one step `above` or
     * `below` among the layers, as one undo step; a layer holding that order
     * takes this one's. A move that changes the map's collisions is asked
     * about first.
     *
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}|array{status: 'question', question: string}
     * @throws SessionRefusal When the map is unknown, stale or refuses the move, or not exactly one of order and direction is given.
     */
    public function moveLayer(string $mapId, int $revision, string $layerId, ?int $order, ?string $direction = null, bool $confirm = false): array
    {
        return $this->editLayers($mapId, $revision, static fn(ProjectMap $map): array => LayerEditor::moveLayer($map, $layerId,
            self::resolveOrder($order, $direction, static fn(string $step): int => LayerEditor::findAdjacentLayerOrder($map, $layerId, $step)),
            $confirm));
    }

    /**
     * Makes a glyph layer decoration, drawn without collision, or gameplay,
     * as one undo step. A change that changes the map's collisions is asked
     * about first.
     *
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}|array{status: 'question', question: string}
     * @throws SessionRefusal When the map is unknown, stale or refuses the change.
     */
    public function setLayerDecoration(string $mapId, int $revision, string $layerId, bool $decoration, bool $confirm = false): array
    {
        return $this->editLayers($mapId, $revision, static fn(ProjectMap $map): array =>
            LayerEditor::setLayerDecoration($map, $layerId, $decoration, $confirm));
    }

    /**
     * Adds an empty tile layer as one undo step, placed among the tile
     * layers as one a tileset piece names is. `layer` is its name.
     *
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}
     * @throws SessionRefusal When the map is unknown, stale or refuses the layer.
     */
    public function createTileLayer(string $mapId, int $revision, string $name): array
    {
        return $this->editLayers($mapId, $revision, static fn(ProjectMap $map): array => LayerEditor::createTileLayer($map, $name));
    }

    /**
     * Renames a tile layer and its settings as one undo step.
     *
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}
     * @throws SessionRefusal When the map is unknown, stale or refuses the rename.
     */
    public function renameTileLayer(string $mapId, int $revision, string $name, string $newName): array
    {
        return $this->editLayers($mapId, $revision, static fn(ProjectMap $map): array =>
            LayerEditor::renameTileLayer($map, $name, $newName));
    }

    /**
     * Removes a tile layer, its tiles and its settings as one undo step.
     *
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}
     * @throws SessionRefusal When the map is unknown, stale or has no such tile layer.
     */
    public function removeTileLayer(string $mapId, int $revision, string $name): array
    {
        return $this->editLayers($mapId, $revision, static fn(ProjectMap $map): array => LayerEditor::removeTileLayer($map, $name));
    }

    /**
     * Moves a tile layer to a drawing order (00-99), or one step `above` or
     * `below` among the tile layers, as one undo step; a tile layer holding
     * that order takes this one's.
     *
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}
     * @throws SessionRefusal When the map is unknown, stale or refuses the move, or not exactly one of order and direction is given.
     */
    public function moveTileLayer(string $mapId, int $revision, string $name, ?int $order, ?string $direction = null): array
    {
        return $this->editLayers($mapId, $revision, static fn(ProjectMap $map): array => LayerEditor::moveTileLayer($map, $name,
            self::resolveOrder($order, $direction, static fn(string $step): int => LayerEditor::findAdjacentTileLayerOrder($map, $name, $step))));
    }

    /**
     * Sets a tile layer's offset across and down in field cells (any
     * fraction within the Engine's limit), or null to inherit the one its
     * tileset gives it, and the gameplay layer its tiles move with, or none,
     * as one undo step, validated as the Engine reads them. The map holds an
     * offset only where it differs from the inherited one.
     *
     * @param array<int, mixed>|null $offset
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}
     * @throws SessionRefusal When the map is unknown, stale or the Engine would refuse the settings.
     */
    public function setTileLayerSettings(string $mapId, int $revision, string $name, ?array $offset, ?string $movesWith): array
    {
        return $this->editLayers($mapId, $revision, static fn(ProjectMap $map): array =>
            LayerEditor::setTileLayerSettings($map, $name, $offset, $movesWith));
    }

    /**
     * Makes one layer edit on a map at its current revision and records it.
     * It answers `applied` with the map's new revision, whether anything
     * changed and the layer to work on (a glyph layer's id or a tile layer's
     * name; null after removing a tile layer), or `question` when the edit
     * would change the map's collisions: nothing changed then, and the edit
     * is made by asking again confirmed.
     *
     * @param Closure(ProjectMap): array{command: ?Command, layer: ?string, question: ?string} $edit
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}|array{status: 'question', question: string}
     * @throws SessionRefusal When the map is unknown or stale, or the edit is refused.
     */
    private function editLayers(string $mapId, int $revision, Closure $edit): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        try {
            $result = $edit($map);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($result['question'] !== null) {
            return ['status' => 'question', 'question' => $result['question']];
        }
        if ($result['command'] !== null) {
            $this->history->record($result['command']);
        }

        return ['status' => 'applied', 'revision' => $this->getMapRevision($map), 'changed' => $result['command'] !== null,
            'layer' => $result['layer']];
    }

    /**
     * The order a move names: the order itself, or the order one step in a
     * direction.
     *
     * @param Closure(string): int $findAdjacentOrder
     * @throws SessionRefusal When not exactly one of order and direction is given.
     */
    private static function resolveOrder(?int $order, ?string $direction, Closure $findAdjacentOrder): int
    {
        if (($order === null) === ($direction === null)) {
            throw new SessionRefusal(sprintf("Move a layer to an order, or '%s' or '%s', not both or neither.",
                LayerEditor::ABOVE, LayerEditor::BELOW));
        }

        return $order ?? $findAdjacentOrder($direction);
    }

    /**
     * The inspector rows of a map, and of one of its events when one is
     * named, as an interface lists and edits them. Each row says how it is
     * edited (`kind`) and carries the `key` an edit names it by.
     *
     * Kinds: `text`, `integer`, `float`, `boolean`, `options` (one of
     * `options`, read as the matching `optionLabels` when it has them),
     * `reference` (one of `references.list` for its `reference`),
     * `conditions` (the condition line, `type:name[:extras]` joined by `;`),
     * `writes` (the world-state write line, in the same form),
     * `destination` (a map from `references.list` for `maps` and a spawn
     * point on it, set together through `setEventDestination`), or `info`
     * for a row that is only read here: headings and counts. A typed row's
     * `raw` is the value its edit starts from. A row inside a list an author
     * adds to and removes from carries `list` (its entry's index), and
     * `addInspectorListEntry`/`removeInspectorListEntry` apply to it.
     *
     * An event's record rows (what every trigger reads beside its class and
     * data: its conditions, completion writes, blocked message and cue
     * conditions, and a type's lists: a Dialogue event's pages, a Story
     * Script's inline commands) are keyed with the `frame` they are in, []
     * at the event, and carry `item` when they belong to an entry adding and
     * removing apply to. A row that opens a frame of commands carries that
     * `frame`; reading the event again with it lists that frame's rows
     * alone, edited through the same calls.
     *
     * @param list<int|string> $frame A frame of the event's inline commands; [] for the map and event rows.
     * @return array{map: string, revision: int, event: ?string, frame: list<int|string>, frameLabel: ?string, rows: list<array<string, mixed>>}
     * @throws SessionRefusal When the map, event or frame is unknown.
     */
    public function readInspector(string $mapId, ?string $marker = null, array $frame = []): array
    {
        $frame = self::requireFrame($frame, 'readInspector');
        $map = $this->requireMap($mapId);
        if ($marker !== null && $map->getEventDefinition($marker) === null && $map->getEventArea($marker) === null) {
            throw new SessionRefusal(sprintf('%s has no event %s.', $mapId, $marker));
        }
        if ($frame !== [] && $marker === null) {
            throw new SessionRefusal('A frame of commands belongs to an event; name the event.');
        }

        return [
            'map' => $mapId,
            'revision' => $this->getMapRevision($map),
            'event' => $marker,
            'frame' => $frame,
            'frameLabel' => $frame === [] || $marker === null ? null : $this->createMapInspector($map)->describeEventFrame($map, $marker, $frame),
            'rows' => $this->describePlacementRows(array_map(self::describeRow(...), $this->collectInspectorFields($map, $marker, $frame)),
                ['kind' => 'event', 'map' => $mapId, 'marker' => $marker]),
            // A story event an NPC delivers names that NPC, so an interface can offer `npc.adoptEvent`.
            'deliveredBy' => $marker === null ? null : self::describeDeliveringNpc($map, $marker),
        ];
    }

    /** @return array{npc: string, name: string}|null The NPC an event could move onto as a page, or null. */
    private static function describeDeliveringNpc(ProjectMap $map, string $marker): ?array
    {
        try {
            $plan = NpcEventAdoption::plan($map, $marker);
        } catch (NpcRefusal) {
            return null;
        }

        return ['npc' => $plan->npcId, 'name' => $map->getNpcs()->get($plan->npcIndex)?->getName() ?? $plan->npcId];
    }

    /**
     * Applies one inspector row's edit as one undo step. The row is found
     * again by its key among the map's current rows, so the edit applies
     * exactly as that row would in any interface.
     *
     * Changing the map's kind when it has tiles from its current kind asks
     * first: nothing changes until the edit is made again with `$answer`
     * `clear` (clear its tile layers and change) or `cancel`.
     *
     * When the edit changes other maps too (renaming a landmark renames the
     * atlas entry of every map naming it), `revisions` gives each of those
     * maps' new revision.
     *
     * @param array<string, mixed> $key The row's key, as `readInspector` gave it.
     * @return array{status: 'applied', revision: int, changed: bool, revisions?: array<string, int>}|array{status: 'question', question: string, answers: list<array{key: string, label: string, description: string}>}
     * @throws SessionRefusal When the map is unknown or stale, the row is gone or read-only, or the edit is refused.
     */
    public function applyInspector(string $mapId, int $revision, array $key, string $value, ?string $answer = null): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $field = $this->requireInspectorField($map, $key);
        $kind = self::describeRow($field)['kind'];

        if ($kind === 'info') {
            throw new SessionRefusal(sprintf('%s cannot be edited here.', trim((string) ($field['label'] ?? 'That row'))));
        }

        $inspector = $this->createMapInspector($map);
        $cleared = 0;
        if (($field['target'] ?? null) === 'map-kind') {
            try {
                $cleared = $inspector->countTileLayersClearedBy($map, $value);
            } catch (InspectorRefusal $refusal) {
                throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
            }
            if ($cleared > 0 && $answer === null) {
                return $this->describeMapKindQuestion($map, $value, $cleared);
            }
            if ($answer !== null && ! in_array($answer, ['clear', 'cancel'], true)) {
                throw new SessionRefusal(sprintf('Answer clear or cancel, not %s.', $answer));
            }
            if ($answer === 'cancel') {
                return ['status' => 'applied', 'revision' => $this->getMapRevision($map), 'changed' => false];
            }
        }

        $before = array_map($this->getMapRevision(...), $this->getMapsById());
        $command = $this->runEdit(static fn(): ?Command => ($field['target'] ?? null) === 'map-kind'
            ? $inspector->changeMapKind($map, $value, $cleared > 0 && $answer === 'clear')
            : $inspector->apply($map, $field, $value));
        // A row may change other maps in the same step: a landmark rename carries the atlas entries naming it.
        $others = [];
        foreach ($this->getMapsById() as $otherId => $other) {
            if ($other !== $map && $this->getMapRevision($other) !== ($before[$otherId] ?? null)) {
                $others[(string) $otherId] = $this->getMapRevision($other);
            }
        }

        return ['status' => 'applied', 'revision' => $this->getMapRevision($map), 'changed' => $command !== null,
            ...($others === [] ? [] : ['revisions' => $others])];
    }

    /**
     * The event types an event can be, as `createEvent` and an event's Type
     * row name them: by label.
     *
     * @return list<array{index: int, label: string, description: string, class: string}>
     */
    public function listEventTypes(): array
    {
        return array_map(static fn(int $index, EventTypeDefinition $type): array => [
            'index' => $index,
            'label' => $type->label,
            'description' => $type->description,
            'class' => $type->className,
        ], array_keys(EventTypeCatalog::all()), EventTypeCatalog::all());
    }

    /**
     * Places a new event of a type on cells as one undo step: its marker
     * painted there and its definition written ({@see EventAuthoring}).
     *
     * @param list<array{0: int, 1: int}> $cells The cells it triggers on, as [x, y].
     * @param string $type The type's label, as `listEventTypes` gives it.
     * @param string|null $marker The marker to give it; null takes the first free one.
     * @return array{marker: string, revision: int}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or the cells, type or marker cannot be used.
     */
    public function createEvent(string $mapId, int $revision, array $cells, string $type, ?string $marker = null): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $definition = EventTypeCatalog::findByLabel($type) ?? throw new SessionRefusal(sprintf('There is no event type %s.', $type));
        $created = null;
        $this->runEdit(static function () use ($map, $cells, $definition, $marker, &$created): Command {
            $created = EventAuthoring::createEvent($map, $cells, $definition, $marker);

            return $created['command'];
        });

        return ['marker' => $created['marker'], 'revision' => $this->getMapRevision($map)];
    }

    /**
     * Deletes an event, its cells and its definition together, as one undo
     * step.
     *
     * @return array{revision: int}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or has no such event.
     */
    public function deleteEvent(string $mapId, int $revision, string $marker): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $this->runEdit(static fn(): Command => EventAuthoring::deleteEvent($map, $marker));

        return ['revision' => $this->getMapRevision($map)];
    }

    /**
     * Moves a story event an NPC delivers onto that NPC as its first page,
     * as one undo step ({@see NpcEventAdoption}): the page keeps the event's
     * conditions, writes, cue, action and one-shot completion, the event and
     * its cells go, and a one-shot event's saved completion is aliased to the
     * page so old saves keep it. Quest waymarks that named the event are
     * listed for repointing at the NPC; validation refuses them until then.
     *
     * @param string|null $npcId The NPC to own it; the event's first speaker when null.
     * @return array{revision: int, npc: string, index: int, page: array<string, mixed>, dropped: list<string>, alias: ?array{from: string, to: string}, waymarks: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or the event or NPC cannot take the move.
     */
    public function adoptEventOnNpc(string $mapId, int $revision, string $marker, ?string $npcId = null): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        try {
            $plan = NpcEventAdoption::plan($map, $marker, $npcId, $this->isAliasTarget(ContentReferenceCategory::ONE_SHOT_EVENT, "{$map->mapId}:{$marker}"));
            $alias = $plan->alias === null ? null : SaveManifestAlias::plan($this->workspace->projectRoot, $this->workspace->projectId,
                ContentReferenceCategory::ONE_SHOT_EVENT, $plan->alias['from'], $plan->alias['to']);
        } catch (NpcRefusal|RuntimeException $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        $before = $map->getNpcs();
        $after = $before->withReplaced($plan->npcIndex, new ProjectNpc($plan->entry));
        $map->setNpcs($after);
        try {
            $deleted = EventAuthoring::deleteEvent($map, $marker);
            $alias?->apply();
        } catch (EventRefusal|MapSourceRefusal|RuntimeException $refusal) {
            $map->setNpcs($before);
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        $commands = [new GenericCommand('NPC page', static fn() => $map->setNpcs($after), static fn() => $map->setNpcs($before)), $deleted];
        if ($alias !== null) {
            $commands[] = new GenericCommand('Save alias', static fn() => $alias->apply(), static fn() => $alias->revert());
        }
        $this->history->record(new CommandGroup(sprintf('Move event %s onto %s', $marker, $plan->npcId), $commands));

        return [
            'revision' => $this->getMapRevision($map),
            'npc' => $plan->npcId,
            'index' => $plan->npcIndex,
            'page' => $plan->page,
            'dropped' => $plan->dropped,
            'alias' => $plan->alias,
            'waymarks' => $this->describeEventWaymarks($map->mapId, $marker),
        ];
    }

    /** Whether the save compatibility manifest sends some older identity to this one. */
    private function isAliasTarget(ContentReferenceCategory $category, string $identity): bool
    {
        try {
            $manifest = PhpDataFile::evaluateIsolated($this->workspace->projectRoot . '/' . SaveManifestStep::PATH, $this->workspace->projectRoot);
        } catch (Throwable) {
            return false;
        }
        $aliases = is_array($manifest) ? (array) ($manifest['aliases'][$category->value] ?? []) : [];

        return array_any($aliases, static fn(mixed $alias): bool => is_array($alias) && ($alias['to'] ?? null) === $identity);
    }

    /** @return list<string> The quest objectives and quest starts whose waymarks name a map event. */
    private function describeEventWaymarks(string $mapId, string $marker): array
    {
        $found = [];
        foreach ($this->workspace->getRecordDatabase('quests')?->getRecords() ?? [] as $quest) {
            $data = $quest->toArray();
            foreach ((array) ($data['startWaymarks'] ?? []) as $w => $waymark) {
                if (is_array($waymark) && ($waymark['map'] ?? null) === $mapId && ($waymark['event'] ?? null) === $marker) {
                    $found[] = sprintf('quest %s start waymark %d', strval($data['id'] ?? '?'), intval($w) + 1);
                }
            }
            foreach ((array) ($data['objectives'] ?? []) as $o => $objective) {
                foreach (is_array($objective) ? (array) ($objective['waymarks'] ?? []) : [] as $w => $waymark) {
                    if (is_array($waymark) && ($waymark['map'] ?? null) === $mapId && ($waymark['event'] ?? null) === $marker) {
                        $found[] = sprintf('quest %s objective %d waymark %d', strval($data['id'] ?? '?'), intval($o) + 1, intval($w) + 1);
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Moves every cell of an event by an offset, keeping its shape.
     *
     * @return array{revision: int, changed: bool}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or a cell would leave the map or cover another event.
     */
    public function moveEvent(string $mapId, int $revision, string $marker, int $deltaX, int $deltaY): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $command = $this->runEdit(static fn(): ?Command => EventAuthoring::moveEvent($map, $marker, $deltaX, $deltaY));

        return ['revision' => $this->getMapRevision($map), 'changed' => $command !== null];
    }

    /**
     * Binds an event to what it describes, a world object or a named map
     * area, or unbinds it onto cells of its own, as one undo step
     * ({@see EventAuthoring::bindEventSubject()}). A bound event's area is
     * its subject's, so it moves with the object or the area and cannot be
     * moved or reshaped itself.
     *
     * @param array{kind?: mixed, id?: mixed}|string|null $subject `{kind, id}`, kind one of the Engine's subject kinds;
     *     a bare id names a world object; null unbinds the event.
     * @param list<array{0: int, 1: int}> $cells Where an unbound event triggers, as [x, y]; unused when binding.
     * @return array{revision: int, changed: bool}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the event or subject is unknown, or the
     *     cells cannot take the event.
     */
    public function setEventSubject(string $mapId, int $revision, string $marker, array|string|null $subject, array $cells = []): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        // A bare id names a world object, as it always has.
        [$kind, $id] = is_string($subject) ? [WorldObjectAuthoring::SUBJECT_KIND, $subject] : [$subject['kind'] ?? null, $subject['id'] ?? null];
        if ($subject !== null && (! is_string($kind) || ! is_string($id))) {
            throw new SessionRefusal('A subject is a world object id, or {kind, id} with kind one of ' . implode(', ', MapEventDefinition::SUBJECT_KINDS) . '.');
        }
        $command = $this->runEdit(static fn(): ?Command => $subject === null
            ? EventAuthoring::unbindEventSubject($map, $marker, $cells)
            : EventAuthoring::bindEventSubject($map, $marker, $kind, $id));

        return ['revision' => $this->getMapRevision($map), 'changed' => $command !== null];
    }

    /**
     * The map's named areas, unsaved edits included: each id, its shape as
     * written, the cells it covers, and what names it.
     *
     * @return array{revision: int, areas: list<array{id: string, area: array<string, mixed>, cells: list<array{int, int}>, references: list<string>}>}
     * @throws SessionRefusal When the map is unknown or its areas cannot be read.
     */
    public function readAreas(string $mapId): array
    {
        $map = $this->requireMap($mapId);

        return ['revision' => $this->getMapRevision($map), 'areas' => $this->describeAreas($map)];
    }

    /**
     * Declares a named area: logical territory the map owns, which events
     * bind to as their subject and landmarks anchor to. One undo step.
     *
     * @param string|null $id The area's id; null takes the first free `area-N`, to be renamed.
     * @param array<string, mixed> $area The Engine's CellArea shape: `{cells: [[x, y], ...]}` or `{x, y, width, height}`
     *     ({@see self::readAreaShape()} reads selected cells as one).
     * @return array{revision: int, changed: bool, id: string}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the id is taken or not an area id, or the
     *     area is empty, malformed or off the map.
     */
    public function createArea(string $mapId, int $revision, ?string $id, array $area): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        try {
            $id ??= MapAreaAuthoring::findUnusedId($map);
        } catch (MapAreaRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }

        return [...$this->changeAreas($mapId, $revision, static fn(MapAreaAuthoring $areas): ?Command => $areas->createArea($id, $area)), 'id' => $id];
    }

    /**
     * The area shape a request names: its `area` as written, or its selected
     * `cells`, written as a rectangle when they fill one.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws SessionRefusal When it names neither.
     */
    public static function readAreaShape(array $params): array
    {
        if (is_array($params['area'] ?? null)) {
            return $params['area'];
        }
        $cells = $params['cells'] ?? null;
        if (is_array($cells) && array_all($cells, static fn(mixed $cell): bool => is_array($cell) && is_int($cell[0] ?? null) && is_int($cell[1] ?? null))) {
            return MapAreaAuthoring::createShapeFor(array_map(static fn(array $cell): array => [$cell[0], $cell[1]], $cells));
        }

        throw new SessionRefusal('Name the area as "area" (cells, or x, y, width and height) or as selected "cells".');
    }

    /**
     * Gives a named area a new shape, as one undo step. The events bound to
     * it cover its new cells.
     *
     * @param array<string, mixed> $area The Engine's CellArea shape.
     * @return array{revision: int, changed: bool}
     * @throws SessionRefusal When the map is unknown, stale or read-only, there is no such area, or the area is
     *     empty, malformed or off the map.
     */
    public function setArea(string $mapId, int $revision, string $id, array $area): array
    {
        return $this->changeAreas($mapId, $revision, static fn(MapAreaAuthoring $areas): ?Command => $areas->setArea($id, $area));
    }

    /**
     * Renames a named area, and every event and landmark naming it, as one
     * undo step.
     *
     * @return array{revision: int, changed: bool}
     * @throws SessionRefusal When the map is unknown, stale or read-only, there is no such area, or the new id is
     *     taken or not an area id.
     */
    public function renameArea(string $mapId, int $revision, string $id, string $to): array
    {
        return $this->changeAreas($mapId, $revision, static fn(MapAreaAuthoring $areas): ?Command => $areas->renameArea($id, $to));
    }

    /**
     * Deletes a named area nothing names, as one undo step.
     *
     * @return array{revision: int, changed: bool}
     * @throws SessionRefusal When the map is unknown, stale or read-only, there is no such area, or an event or
     *     landmark names it (the message names them).
     */
    public function deleteArea(string $mapId, int $revision, string $id): array
    {
        return $this->changeAreas($mapId, $revision, static fn(MapAreaAuthoring $areas): ?Command => $areas->deleteArea($id));
    }

    /**
     * @param callable(MapAreaAuthoring): ?Command $change
     * @return array{revision: int, changed: bool}
     */
    private function changeAreas(string $mapId, int $revision, callable $change): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $command = $this->runEdit(static fn(): ?Command => $change(new MapAreaAuthoring($map)));

        return ['revision' => $this->getMapRevision($map), 'changed' => $command !== null];
    }

    /**
     * The map's areas for a map read: an unreadable areas block leaves the
     * rest of the map readable, with its reason in `areasIssue`.
     *
     * @return array{areas: list<array<string, mixed>>, areasIssue?: string}
     */
    private function describeMapAreas(ProjectMap $map): array
    {
        try {
            return ['areas' => $this->describeAreas($map)];
        } catch (SessionRefusal $refusal) {
            return ['areas' => [], 'areasIssue' => $refusal->getMessage()];
        }
    }

    /**
     * @return list<array{id: string, area: array<string, mixed>, cells: list<array{int, int}>, references: list<string>}>
     * @throws SessionRefusal When the areas cannot be read.
     */
    private function describeAreas(ProjectMap $map): array
    {
        try {
            return array_map(static fn(array $area): array => [...$area, 'references' => MapAreaAuthoring::describeReferences($map, $area['id'])],
                MapAreaAuthoring::describeAreas($map));
        } catch (MapAreaRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
    }

    /**
     * Repaints an event as exactly a rectangle.
     *
     * @return array{revision: int, changed: bool}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or the rectangle is empty, leaves the map or covers another event.
     */
    public function setEventBounds(string $mapId, int $revision, string $marker, int $x, int $y, int $width, int $height): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $command = $this->runEdit(static fn(): ?Command => EventAuthoring::setEventBounds($map, $marker, $x, $y, $width, $height));

        return ['revision' => $this->getMapRevision($map), 'changed' => $command !== null];
    }

    /**
     * Sets a transfer event's destination map and the spawn point on it as
     * one undo step, with the heading the player faces there when given;
     * without one the spawn facing stays as it is.
     *
     * @param string|null $heading North, East, South or West, as the Spawn Facing row offers them; null leaves it.
     * @return array{revision: int, changed: bool}
     * @throws SessionRefusal When either map is unknown, the map is stale or read-only, the event has no destination, the spawn point is off the destination, or the heading is not one.
     */
    public function setEventDestination(string $mapId, int $revision, string $marker, string $destinationMapId, int $x, int $y, ?string $heading = null): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $destination = $this->requireMap($destinationMapId);
        $command = $this->runEdit(fn(): ?Command => $this->createMapInspector($map)->setTransferDestination($map, $marker, $destination, $x, $y, $heading));

        return ['revision' => $this->getMapRevision($map), 'changed' => $command !== null];
    }

    /**
     * Adds an entry to the list an inspector row belongs to (a row with
     * `list`), after that row's entry, as one undo step.
     *
     * @param array<string, mixed> $key The row's key, as `readInspector` gave it.
     * @return array{revision: int, changed: bool, message: string}
     * @throws SessionRefusal When the map is unknown or stale, the row is gone or in no list, or the list cannot take an entry.
     */
    public function addInspectorListEntry(string $mapId, int $revision, array $key): array
    {
        return $this->editInspectorList($mapId, $revision, $key,
            static fn(MapInspector $inspector, ProjectMap $map, array $field): ?InspectorListEdit => $inspector->addListEntry($map, $field));
    }

    /**
     * Removes the entry an inspector row belongs to, as one undo step.
     *
     * @param array<string, mixed> $key The row's key, as `readInspector` gave it.
     * @return array{revision: int, changed: bool, message: string}
     * @throws SessionRefusal When the map is unknown or stale, the row is gone or in no list, or the list cannot lose the entry.
     */
    public function removeInspectorListEntry(string $mapId, int $revision, array $key): array
    {
        return $this->editInspectorList($mapId, $revision, $key,
            static fn(MapInspector $inspector, ProjectMap $map, array $field): ?InspectorListEdit => $inspector->removeListEntry($map, $field));
    }

    /**
     * @param array<string, mixed> $key
     * @param Closure(MapInspector, ProjectMap, array<string, mixed>): ?InspectorListEdit $edit
     * @return array{revision: int, changed: bool, message: string}
     */
    private function editInspectorList(string $mapId, int $revision, array $key, Closure $edit): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $field = $this->requireInspectorField($map, $key);
        $inspector = $this->createMapInspector($map);
        $result = null;
        $this->runEdit(static function () use ($edit, $inspector, $map, $field, &$result): ?Command {
            $result = $edit($inspector, $map, $field);

            return $result?->command;
        });

        return ['revision' => $this->getMapRevision($map), 'changed' => $result?->command !== null, 'message' => $result?->summary ?? 'Nothing changed.'];
    }

    /**
     * Runs one edit, records the command it returns, and turns any refusal
     * into the session's.
     *
     * @param callable(): ?Command $edit
     * @throws SessionRefusal
     */
    private function runEdit(callable $edit): ?Command
    {
        try {
            $command = $edit();
        } catch (InspectorRefusal $refusal) {
            throw new SessionRefusal(implode("\n", [$refusal->getMessage(), ...$refusal->details]), previous: $refusal);
        } catch (EventRefusal|MapSourceRefusal|MapAreaRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($command !== null) {
            $this->history->record($command);
        }

        return $command;
    }

    /**
     * The inspector row a key names, among the map's current rows.
     *
     * @param array<string, mixed> $key
     * @return array<string, mixed>
     * @throws SessionRefusal When no current row has the key.
     */
    private function requireInspectorField(ProjectMap $map, array $key): array
    {
        $marker = is_string($key['marker'] ?? null) ? $key['marker'] : null;
        $frame = self::requireFrame($key['frame'] ?? [], 'readInspector');

        // A key comes back as the interface stored it, which may not keep
        // its members in the order they were given.
        ksort($key);

        return array_find($this->collectInspectorFields($map, $marker, $frame), static function (array $candidate) use ($key): bool {
            $candidateKey = MapInspector::describeRowKey($candidate);
            if ($candidateKey !== null) {
                ksort($candidateKey);
            }

            return $candidateKey === $key;
        }) ?? throw new SessionRefusal('That row is no longer in the inspector; read it again.');
    }

    /**
     * What to ask before a kind change clears a map's tiles.
     *
     * @return array{status: 'question', question: string, answers: list<array{key: string, label: string, description: string}>}
     */
    private function describeMapKindQuestion(ProjectMap $map, string $tilesetId, int $layers): array
    {
        $labels = new ReferenceCatalog($this->workspace, $map)->labelsFor('tilesets');
        $current = (string) $map->getMapDataField(['tileset']);
        $currentLabel = $labels[$current] ?? $current;
        $label = $labels[$tilesetId] ?? $tilesetId;

        return [
            'status' => 'question',
            'question' => sprintf('Change %s\'s kind to %s?', $map->getDisplayName(), $label),
            'answers' => [
                ['key' => 'cancel', 'label' => 'Cancel', 'description' => sprintf('Keep its kind, %s, and its tiles.', $currentLabel)],
                ['key' => 'clear', 'label' => sprintf('Clear %d tile %s and change', $layers, $layers === 1 ? 'layer' : 'layers'),
                    'description' => sprintf('Its tiles come from %s and would show the wrong art as %s. Glyphs stay%s. Undo restores them.', $currentLabel, $label,
                        $this->createMapInspector($map)->isKindChangeLeavingTerrainKinds($map, $tilesetId)
                            ? sprintf(', and with no terrain kinds in %s they block or let pass as the collision dictionary says', $label) : '')],
            ],
        ];
    }

    /**
     * The values a reference row is chosen from, with how each reads.
     *
     * @return list<array{value: string, label: string}>
     * @throws SessionRefusal When the map is unknown or the category is not a reference.
     */
    /**
     * The vocabulary conditions and world writes are built from, a part at a
     * time ({@see ConditionEditor::getGrammar()}, {@see WorldWriteEditor::getGrammar()}).
     *
     * @return array{conditions: list<array<string, mixed>>, writes: list<array<string, mixed>>}
     */
    public function describeWorldStateGrammar(): array
    {
        return ['conditions' => ConditionEditor::getGrammar(), 'writes' => WorldWriteEditor::getGrammar()];
    }

    /**
     * Writes conditions or world writes built a part at a time as the one
     * line a conditions or writes row is set to, refusing an entry the
     * engine could not read rather than dropping it.
     *
     * @param 'conditions'|'writes' $codec
     * @param list<mixed> $entries Each an array as a row's `entries` lists them.
     * @param list<string>|null $writeTypes The write types the row allows, when it restricts them.
     * @return array{line: string, descriptions: list<string>}
     * @throws SessionRefusal When an entry has no name, an unknown type or one the row does not allow.
     */
    public function encodeWorldState(string $codec, array $entries, ?array $writeTypes = null): array
    {
        $entries = array_values($entries);
        foreach ($entries as $number => $entry) {
            if (! is_array($entry) || trim((string) ($entry['name'] ?? '')) === '') {
                throw new SessionRefusal(sprintf('Entry %d needs a name.', $number + 1));
            }
        }
        try {
            if ($codec === 'conditions') {
                $line = ConditionCodec::encodeAll($entries);
                $decoded = ConditionCodec::decodeAllStrictly($line);

                return ['line' => $line, 'descriptions' => array_map(ConditionEditor::describe(...), $decoded)];
            }
            if ($codec === 'writes') {
                $line = WorldWriteCodec::encodeAll($entries);
                $decoded = WorldWriteCodec::decodeAllStrictly($line);
                $refused = array_find($decoded, static fn(array $set): bool => $writeTypes !== null && ! in_array($set['type'], $writeTypes, true));
                if ($refused !== null) {
                    throw new SessionRefusal(sprintf('This row cannot write a %s; it allows %s.', $refused['type'], implode(', ', $writeTypes ?? [])));
                }

                return ['line' => $line, 'descriptions' => array_map(WorldWriteCodec::describe(...), $decoded)];
            }
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }

        throw new SessionRefusal(sprintf('There is no %s codec; use conditions or writes.', $codec));
    }

    /**
     * What an elemental affinity row is built from: the elements the game
     * knows, and the named effects with their multipliers, which the
     * terminal cycles and a graphical editor offers beside a typed one.
     *
     * @return array{elements: list<string>, effects: list<array{label: string, multiplier: float}>}
     * @throws SessionRefusal When the project's element list cannot be read.
     */
    public function describeAffinityVocabulary(): array
    {
        try {
            $elements = $this->workspace->getElementIdentities();
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        $effects = [];
        foreach (ElementAffinityCodec::EFFECTS as $label => $multiplier) {
            $effects[] = ['label' => $label, 'multiplier' => $multiplier];
        }

        return ['elements' => $elements, 'effects' => $effects];
    }

    /**
     * Writes elemental affinities built a row at a time as the one line an
     * affinity row is set to, refusing an element the game does not know,
     * one named twice, or a multiplier that is not a number.
     *
     * @param list<mixed> $entries Each `{element, multiplier}`.
     * @return array{line: string, descriptions: list<string>}
     * @throws SessionRefusal When an entry cannot be stored as the Engine reads it.
     */
    public function encodeAffinities(array $entries): array
    {
        $elements = $this->describeAffinityVocabulary()['elements'];
        $affinities = [];
        foreach (array_values($entries) as $number => $entry) {
            $element = is_array($entry) && is_string($entry['element'] ?? null) ? trim($entry['element']) : '';
            $multiplier = is_array($entry) ? ($entry['multiplier'] ?? null) : null;
            if (! in_array($element, $elements, true)) {
                throw new SessionRefusal(sprintf('Entry %d needs an element the game knows: %s.', $number + 1, implode(', ', $elements)));
            }
            if (array_key_exists($element, $affinities)) {
                throw new SessionRefusal(sprintf('%s is listed twice; keep one multiplier for it.', $element));
            }
            if (! is_int($multiplier) && ! is_float($multiplier) || ! is_finite((float) $multiplier)) {
                throw new SessionRefusal(sprintf('%s needs a multiplier: 2 weak, 0.5 resist, 0 null, -1 absorb, or any number between.', $element));
            }
            $affinities[$element] = (float) $multiplier;
        }
        $line = ElementAffinityCodec::encodeAll($affinities);

        return [
            'line' => $line,
            'descriptions' => array_map(
                static fn(string $element, float $multiplier): string => sprintf('%s: %s', $element, ElementAffinityCodec::describe($multiplier)),
                array_keys($affinities),
                array_values($affinities),
            ),
        ];
    }

    /**
     * A summon's or effect's timeline as a timeline editor lays it out: its
     * frames per second and length, each track with its keyframes' frames and
     * durations, and the cues, each value with the record row key that edits
     * it through `database.apply`, as the record grid edits it. A cinematic is
     * a command tree, not a timeline.
     *
     * @return array{fps: int, lengthFrames: int, tracks: list<array<string, mixed>>, cues: list<array<string, mixed>>}
     * @throws SessionRefusal When the record is not a summon or effect the project has.
     */
    public function describeCutsceneTimeline(string $category, int $index): array
    {
        $asset = $this->requireTimelineCutscene($category, $index);
        $tracks = $cues = [];
        foreach ($this->readDatabaseRecord($category, $index, ['tracks'])['rows'] as $row) {
            $field = (string) ($row['key']['field'] ?? '');
            if (preg_match('/^track(\d+)(Id|Type|Presentation)$/', $field, $match) === 1) {
                $tracks[(int) $match[1]][strtolower($match[2])] = (string) $row['value'];
            } elseif (preg_match('/^track(\d+)(Asset|Sheetcolumns|Sheetrows|Fit|Attachment|Pivot|Anchor|Placementsubject|Placementattachment)$/', $field, $match) === 1) {
                // An image track's art, and the keys that edit how it fills its cells and where it sits on its
                // anchor: a battler point, or on the stage a subject and a named point on it.
                $art = ['Asset' => 'asset', 'Sheetcolumns' => 'columns', 'Sheetrows' => 'rows', 'Fit' => 'fit', 'Attachment' => 'attachment', 'Pivot' => 'pivot',
                    'Anchor' => 'anchor', 'Placementsubject' => 'subject', 'Placementattachment' => 'point'][$match[2]];
                $tracks[(int) $match[1]]['art'][$art] = in_array($art, ['columns', 'rows'], true) ? max(1, (int) $row['value']) : (string) ($row['raw'] ?? $row['value']);
                if (in_array($art, ['fit', 'attachment', 'pivot', 'subject', 'point'], true)) {
                    $tracks[(int) $match[1]]['art'][$art . 'Key'] = $row['key'];
                }
                if (in_array($art, ['fit', 'attachment'], true)) {
                    $tracks[(int) $match[1]]['art'][$art . 'Options'] = array_values(array_filter($row['options'] ?? [], static fn(mixed $option): bool => $option !== ''));
                }
            } elseif (preg_match('/^track(\d+)Keyframe(\d+)(Frame|Duration|SourceFrame)$/', $field, $match) === 1) {
                $name = lcfirst($match[3]);
                $tracks[(int) $match[1]]['keyframes'][(int) $match[2]][$name] = (int) $row['value'];
                if ($name !== 'sourceFrame') {
                    $tracks[(int) $match[1]]['keyframes'][(int) $match[2]][$name . 'Key'] = $row['key'];
                }
            }
        }
        foreach ($this->readDatabaseRecord($category, $index, ['cues'])['rows'] as $row) {
            if (preg_match('/^cue(\d+)(Id|Frame|Type)$/', (string) ($row['key']['field'] ?? ''), $match) === 1) {
                $value = $match[2] === 'Frame' ? (int) $row['value'] : (string) $row['value'];
                $cues[(int) $match[1]][strtolower($match[2])] = $value;
                if ($match[2] === 'Frame') {
                    $cues[(int) $match[1]]['frameKey'] = $row['key'];
                }
            }
        }
        // Glyph tracks also have anchors, but those are not image art. Do not
        // project image defaults or an image picker onto terminal-only tracks.
        foreach ($tracks as &$track) {
            if (($track['type'] ?? '') !== 'image') {
                unset($track['art']);
            }
        }
        unset($track);
        $payload = $asset->payload();
        $stageContext = $this->findStageContext($asset);

        return [
            // An effect or summon with separate terminal and graphical sequences names the one shown; others name none.
            'presentation' => $asset->getPresentationView()?->value,
            'fps' => (int) ($payload['fps'] ?? 0),
            'lengthFrames' => (int) ($payload['lengthFrames'] ?? 0),
            'tracks' => array_values(array_map(fn(array $track, int $position): array => self::placeOnStage([...$track,
                'keyframes' => array_values($track['keyframes'] ?? []),
                ...(isset($track['art']) ? ['art' => [...$this->measureTrackArt($track['art']),
                    'pivotDefaults' => self::getTimelinePivotDefaults($asset->type, $payload['tracks'][$position] ?? []),
                ]] : [])], $payload['tracks'][$position] ?? null), $tracks, array_keys($tracks))),
            'cues' => array_values($cues),
            // A declared stage's consumer survives switching to an independent Terminal sequence:
            // battle when an action animation binds the effect, else the caller that owns the stage.
            'ownedStage' => $stageContext?->isOwnedStage() ?? false,
            'stageContext' => $stageContext?->value,
            'canHaveStage' => $asset->getPresentationView() !== EffectPresentation::TERMINAL
                && ($asset->type === CutsceneType::EFFECT || $asset->hasPresentations()),
            'stage' => is_array($payload['stage'] ?? null) ? $this->describeCutsceneStage($category, $index) : null,
        ];
    }

    /**
     * Consumer defaults are preview metadata, never authored values. A shared
     * effect can play in field or battle; the client selects the context it is
     * actually previewing. Stage tracks cannot acquire field/battler geometry.
     *
     * @return array<string, array{x: float, y: float}>
     */
    private static function getTimelinePivotDefaults(CutsceneType $type, array $track): array
    {
        $center = ['x' => 0.5, 'y' => 0.5];
        if (($track['anchor'] ?? null) === 'stage') {
            return ['stage' => $center];
        }

        return [
            ...($type === CutsceneType::EFFECT
                ? ['field' => new \Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot()->toArray()] : []),
            'battle' => $center,
        ];
    }

    /**
     * A stage image's placement in stage units, and each keyframe's offset,
     * as the Engine reads them with their absent defaults, so an editor can
     * find the subject's box under a point of the art. Other tracks are as
     * described.
     *
     * @param array<string, mixed> $described
     * @return array<string, mixed>
     */
    private static function placeOnStage(array $described, mixed $authored): array
    {
        if (! is_array($authored) || ($authored['anchor'] ?? null) !== 'stage' || ! isset($described['art'])) {
            return $described;
        }

        $placement = is_array($authored['placement'] ?? null) ? $authored['placement'] : [];
        $described['art']['placement'] = [
            'position' => self::readStagePoint($placement['position'] ?? null, ['x' => 0, 'y' => 0]),
            'size' => is_array($placement['size'] ?? null) ? ['width' => (float) ($placement['size']['width'] ?? 0), 'height' => (float) ($placement['size']['height'] ?? 0)] : null,
            // An image's own pivot is its cell's centre unless authored.
            'pivot' => self::readStagePoint($authored['pivot'] ?? null, ['x' => 0.5, 'y' => 0.5]),
        ];
        foreach ($described['keyframes'] as $index => $keyframe) {
            $described['keyframes'][$index]['offset'] = self::readStagePoint($authored['keyframes'][$index]['position'] ?? null, ['x' => 0, 'y' => 0]);
        }

        return $described;
    }

    /**
     * @param array{x: int|float, y: int|float} $default
     * @return array{x: float, y: float}
     */
    private static function readStagePoint(mixed $point, array $default): array
    {
        $point = is_array($point) ? $point : $default;

        return ['x' => (float) ($point['x'] ?? $default['x']), 'y' => (float) ($point['y'] ?? $default['y'])];
    }

    /**
     * A summon or effect sequence's stage as a timeline editor lays it out: its canvas
     * and the frames it is shown between, each subject with its named points
     * (where they are in its box), and its camera and cover keys on the
     * clock, each value with the row key that edits it.
     *
     * @return array<string, mixed>
     */
    private function describeCutsceneStage(string $category, int $index): array
    {
        $stage = ['subjects' => [], 'camera' => [], 'covers' => []];
        foreach ($this->readDatabaseRecord($category, $index)['rows'] as $row) {
            $name = ['stage.canvas' => 'canvas', 'stage.startFrame' => 'startFrame', 'stage.restoreFrame' => 'restoreFrame'][(string) ($row['key']['field'] ?? '')] ?? null;
            if ($name !== null) {
                $stage[$name] = $name === 'canvas' ? (string) $row['value'] : (int) $row['value'];
                $stage[$name . 'Key'] = $row['key'];
            }
        }
        foreach ($this->readDatabaseRecord($category, $index, [CutsceneSchemas::STAGE_SUBJECTS_KEY])['rows'] as $row) {
            $field = (string) ($row['key']['field'] ?? '');
            if (preg_match('/^subject(\d+)Id$/', $field, $match) === 1) {
                $stage['subjects'][(int) $match[1]]['id'] = (string) $row['value'];
            } elseif (preg_match('/^subject(\d+)Attachment(\d+)(Id|X|Y)$/', $field, $match) === 1) {
                $name = strtolower($match[3]);
                $stage['subjects'][(int) $match[1]]['points'][(int) $match[2]][$name] = $name === 'id' ? (string) $row['value'] : (float) $row['value'];
                $stage['subjects'][(int) $match[1]]['points'][(int) $match[2]][$name . 'Key'] = $row['key'];
            }
        }
        foreach ([CutsceneSchemas::STAGE_CAMERA_KEY => ['camera', 'camera'], CutsceneSchemas::STAGE_COVERS_KEY => ['cover', 'covers']] as $list => [$prefix, $name]) {
            foreach ($this->readDatabaseRecord($category, $index, [$list])['rows'] as $row) {
                if (preg_match('/^' . $prefix . '(\d+)(Id|Frame)$/', (string) ($row['key']['field'] ?? ''), $match) === 1) {
                    $stage[$name][(int) $match[1]][strtolower($match[2])] = $match[2] === 'Frame' ? (int) $row['value'] : (string) $row['value'];
                    if ($match[2] === 'Frame') {
                        $stage[$name][(int) $match[1]]['frameKey'] = $row['key'];
                    }
                }
            }
        }

        $authored = $this->requireTimelineCutscene($category, $index)->payload()['stage']['subjects'] ?? [];
        foreach ($stage['subjects'] as $position => $subject) {
            // Where the subject's box is, as the Engine reads it: its pivot is its bottom centre unless authored.
            $data = is_array($authored[$position] ?? null) ? $authored[$position] : [];
            $stage['subjects'][$position] += [
                'position' => self::readStagePoint($data['position'] ?? null, ['x' => 0, 'y' => 0]),
                'size' => ['width' => (float) ($data['size']['width'] ?? 0), 'height' => (float) ($data['size']['height'] ?? 0)],
                'pivot' => self::readStagePoint($data['pivot'] ?? null, ['x' => 0.5, 'y' => 1]),
            ];
        }

        return [...$stage,
            'subjects' => array_values(array_map(static fn(array $subject): array => [...$subject, 'points' => array_values($subject['points'] ?? [])], $stage['subjects'])),
            'camera' => array_values($stage['camera']),
            'covers' => array_values($stage['covers'])];
    }

    /**
     * The consumer a declared effect stage is previewed in, by the shared
     * Editor rule: battle when an action animation binds the effect as its
     * source or target, else its owning caller. None without a stage.
     */
    private function findStageContext(CutsceneAsset $asset): ?EffectPreviewContext
    {
        return $asset->type === CutsceneType::EFFECT && $asset->hasStage()
            ? EffectPreviewContext::findDefault($asset, $this->workspace) : null;
    }

    /**
     * One authored frame, with independent Terminal output and, for an
     * effect declaring a graphical stage, the Engine's shared stage canvas
     * compiled with its consumer's admission (its owning caller, or battle
     * for an action's staged cut-in or impact).
     *
     * @return array{frame: int, totalFrames: int, fps: int, lines: list<string>, terminalCanvas: array<string, mixed>, cues: list<array<string, mixed>>}
     * @throws SessionRefusal When the record is not a summon or effect, or its timeline does not compile.
     */
    public function readCutscenePreview(string $category, int $index, int $frame, int $width, int $height): array
    {
        $asset = $this->requireTimelineCutscene($category, $index);
        $stageContext = $this->findStageContext($asset);
        try {
            $compiled = $asset->type === CutsceneType::EFFECT
                ? ($stageContext ?? EffectPreviewContext::FIELD)->compile($asset, EffectPresentation::TERMINAL)
                : $asset->compiledSummon();
        } catch (Throwable $failure) {
            throw new SessionRefusal(sprintf('%s does not compile: %s', $asset->id, $failure->getMessage()), previous: $failure);
        }
        $preview = new TimelinePreviewSession($compiled, $asset->type === CutsceneType::EFFECT ? null : false);
        $requestedFrame = $frame;
        $frame = max(0, min($frame, $preview->totalFrames() - 1));

        $lines = array_values($stageContext?->isOwnedStage()
            ? $stageContext->createStage(EffectPresentation::TERMINAL)->drawFrame($preview->activeSegments($frame), max(1, $width), max(1, $height))
            : $preview->frame(max(1, $width), max(1, $height), $frame));
        $stageCanvas = null;
        $stageCanvasError = null;
        if ($stageContext !== null && $asset->getPresentationView() !== EffectPresentation::TERMINAL) {
            try {
                $graphical = $stageContext->compile($asset, EffectPresentation::GRAPHICAL);
                $preview = new TimelinePreviewSession($graphical);
                $frame = max(0, min($requestedFrame, $preview->totalFrames() - 1));
                $stageCanvas = $stageContext->createStage(EffectPresentation::GRAPHICAL)
                    ->renderCanvas($graphical, $this->workspace->projectRoot . '/assets', $frame,
                        (int) $graphical->defaults['stage']['canvas']['width'], (int) $graphical->defaults['stage']['canvas']['height'])?->toArray();
            } catch (Throwable $failure) {
                // A graphical failure is visible in its tab, never a field
                // fallback or a dependency of independent Terminal playback.
                $stageCanvasError = $failure->getMessage();
            }
        }

        return ['frame' => $frame, 'totalFrames' => $preview->totalFrames(), 'fps' => $preview->fps(),
            'lines' => $lines, 'terminalCanvas' => $this->describeTerminalCanvas($lines, max(1, $width), max(1, $height)),
            'stageCanvas' => $stageCanvas, 'stageCanvasError' => $stageCanvasError,
            'cues' => array_values($preview->cuesAt($frame))];
    }

    /**
     * Starts previewing a cinematic as it stands, unsaved edits included, as
     * the terminal plays it: from the map event that triggers it, else its
     * start map, in an isolated scene with silent audio, the way the
     * Cutscenes workspace previews it. Nothing is written. A preview already
     * running ends first, unless `keep` is asked and it is of this cinematic
     * as it still stands: then it carries on where it is.
     *
     * @return array<string, mixed> The preview, as {@see describeCinematicPreview()}.
     * @throws SessionRefusal When the cinematic is unknown or does not compile.
     */
    public function startCinematicPreview(int $index, int $width, int $height, bool $play = false, bool $keep = false): array
    {
        $type = CutsceneType::CINEMATIC;
        $id = $this->workspace->cutscenes?->ids($type)[$index] ?? null;
        $asset = ($id === null ? null : $this->workspace->cutscenes?->find($type, $id))
            ?? throw new SessionRefusal(sprintf('There is no %s %d.', $type->noun(), $index));
        $fingerprint = hash('xxh128', serialize($asset->payload()));
        if ($keep && $this->cinematicPreview !== null && $this->cinematicPreviewId === $asset->id && $this->cinematicPreviewFingerprint === $fingerprint) {
            if ($this->cinematicPreview->getScreenSize() !== [max(20, $width), max(8, $height)]) {
                $this->cinematicPreview->resize($width, $height);
            }
            return $this->describeCinematicPreview();
        }
        $this->stopCinematicPreview();
        $this->closeEffectField();
        try {
            $definition = $asset->cinematicDefinition();
        } catch (Throwable $failure) {
            throw new SessionRefusal(sprintf('%s does not compile: %s', $asset->id, $failure->getMessage()), previous: $failure);
        }
        $origin = CinematicPreviewOrigin::locate($this->workspace, $asset, $definition->startMap);
        try {
            $this->cinematicPreview = CinematicPreviewSession::start($this->workspace->projectRoot, $definition, [
                'mapId' => $origin['mapId'], 'x' => $origin['x'], 'y' => $origin['y'], 'width' => max(20, $width), 'height' => max(8, $height),
            ]);
        } catch (Throwable $failure) {
            throw new SessionRefusal(sprintf('%s cannot be previewed: %s', $asset->id, $failure->getMessage()), previous: $failure);
        }
        $this->cinematicPreviewId = $asset->id;
        $this->cinematicPreviewFingerprint = $fingerprint;
        if ($play) {
            $this->cinematicPreview->play();
        }

        return $this->describeCinematicPreview();
    }

    /**
     * Plays, pauses, steps, advances while playing, skips, or restarts the
     * cinematic previewed, or answers what it waits on: `confirm` turns the
     * dialogue's page or picks the highlighted option, `choice` moves that
     * highlight by `delta`. An advance is the time since the last one, so the
     * preview keeps real time however often it is asked. An accepted skip
     * plays the authored finalizer, even when the preview was paused.
     *
     * @param 'play'|'pause'|'step'|'tick'|'skip'|'restart'|'confirm'|'choice' $action
     * @return array<string, mixed> The preview, as {@see describeCinematicPreview()}.
     * @throws SessionRefusal When no cinematic is previewed, the action is unknown, or a skip is refused.
     */
    public function controlCinematicPreview(string $action, float $seconds = 0.0, int $delta = 0): array
    {
        $preview = $this->cinematicPreview ?? throw new SessionRefusal('No cinematic is being previewed.');
        if ($action === 'skip') {
            if ($preview->skip()) {
                $preview->play();
            } elseif ($preview->failure() === null) {
                throw new SessionRefusal('Skip refused: ' . ($preview->skipRefusalReason() ?? 'the Engine refused the skip.'));
            }

            // A finalizer failure is the preview's normal failure state, not a policy refusal.
            return $this->describeCinematicPreview();
        }
        match ($action) {
            'play' => $preview->play(),
            'pause' => $preview->pause(),
            'step' => $preview->step(),
            'tick' => $preview->tick(max(0.0, min($seconds, 1.0))),
            'restart' => $this->cinematicPreview = $preview->restart(),
            'confirm' => $preview->confirm(),
            'choice' => $preview->moveChoice($delta),
            default => throw new SessionRefusal(sprintf('A cinematic preview cannot %s.', $action)),
        };

        return $this->describeCinematicPreview();
    }

    /**
     * Ends the cinematic preview, releasing its scene.
     *
     * @return array{stopped: bool} Whether one was running.
     */
    public function stopCinematicPreview(): array
    {
        $stopped = $this->cinematicPreview !== null;
        $this->cinematicPreview?->dispose();
        $this->cinematicPreview = null;
        $this->cinematicPreviewId = null;
        $this->cinematicPreviewFingerprint = null;

        return ['stopped' => $stopped];
    }

    /**
     * One exchange with the editor window's graphical view of the cinematic
     * being previewed ({@see CinematicPreviewSession::exchangeScene()}): the
     * window's renderer event lines in (its READY, frame acknowledgements and
     * rejections, exactly as the game's renderer writes them), the frames it
     * should apply out, in order, each its FRAME payload without envelope.
     *
     * The answer names the current epoch and grid. Feedback for another epoch,
     * or without one, is discarded before it can reach the graphical host.
     *
     * @param list<string> $events
     * @param string|null $sessionId The epoch the window is answering; null permits initial empty discovery.
     * @return array{sessionId: non-empty-string, diagnostics: list<string>, grid: array{columns: int, rows: int, cellWidth: int, cellHeight: int}, messages: list<array{type: string, payload: array<string, mixed>}>}
     * @throws SessionRefusal When no cinematic is previewed or a line is not a renderer event.
     */
    public function exchangeCinematicScene(array $events, ?string $sessionId = null): array
    {
        $preview = $this->cinematicPreview ?? throw new SessionRefusal('No cinematic is being previewed.');
        $grid = $preview->getSceneGrid();
        try {
            return [
                'sessionId' => $preview->getSceneSessionId(),
                'diagnostics' => $preview->getDiagnostics(),
                'grid' => ['columns' => $grid->columns, 'rows' => $grid->rows, 'cellWidth' => $grid->cellWidth, 'cellHeight' => $grid->cellHeight],
                'messages' => $preview->exchangeScene($events, $sessionId),
            ];
        } catch (RendererProtocolException | RendererTransportException $error) {
            throw new SessionRefusal('The graphical preview stopped: ' . $error->getMessage(), previous: $error);
        }
    }

    /**
     * Ends the graphical view of the cinematic being previewed, giving the
     * Terminal picture back the preview's own screen.
     *
     * @return array{detached: bool}
     */
    public function detachCinematicScene(): array
    {
        $this->cinematicPreview?->detachScene();

        return ['detached' => $this->cinematicPreview !== null];
    }

    /**
     * Shows an effect, as edited, unsaved changes included, on the game's
     * field at one of its frames: at the player, where a new game starts
     * (System's player starting position), in an isolated field with silent
     * audio, as a cinematic's field animation presents it. Nothing is
     * written. The field stays open while it stands where it did, so the
     * editor window's graphical view of it ({@see exchangeEffectFieldScene()})
     * carries on; a cinematic preview running ends first.
     *
     * @return array{sessionId: non-empty-string, diagnostics: list<string>, frame: int, totalFrames: int, fps: int, mapId: string, lines: list<string>, issue: string|null}
     * @throws SessionRefusal When the record is not an effect or it does not compile.
     */
    public function showEffectOnField(int $index, int $frame, int $width, int $height): array
    {
        $asset = $this->requireTimelineCutscene(CutsceneType::EFFECT->getRecordCategory(), $index);
        try {
            // The timeline editor's own playhead: the same frames and rate its Terminal preview steps through.
            $timeline = new TimelinePreviewSession($asset->compiledEffect(EffectPresentation::TERMINAL, false), null);
        } catch (Throwable $failure) {
            throw new SessionRefusal(sprintf('%s does not compile: %s', $asset->id, $failure->getMessage()), previous: $failure);
        }
        $start = $this->workspace->getSystemField('startingPositions');
        $player = is_array($start) && is_array($start['player'] ?? null) ? $start['player'] : [];
        $mapId = is_string($player['destinationMap'] ?? null) ? trim($player['destinationMap']) : '';
        $spawn = new Vector2(intval($player['spawnPoint']['x'] ?? 0), intval($player['spawnPoint']['y'] ?? 0));
        $key = implode(':', [$mapId, (int) $spawn->x, (int) $spawn->y, max(20, $width), max(8, $height)]);
        if ($this->effectField === null || $this->effectFieldKey !== $key) {
            $this->stopCinematicPreview();
            $this->closeEffectField();
            $this->effectField = PreviewField::open($this->workspace->projectRoot, $mapId, $spawn, $width, $height,
                fn(): float => $this->effectFieldSeconds);
            $this->effectFieldKey = $key;
        }
        $frame = max(0, min($frame, $timeline->totalFrames() - 1));
        $this->effectFieldSeconds = $frame / $timeline->fps();
        try {
            $this->effectField->showFieldEffect(static fn(EffectPresentation $presentation): CompiledEffectTimeline
                => $asset->compiledEffect($presentation, false), $frame);
        } catch (Throwable $failure) {
            throw new SessionRefusal(sprintf('%s cannot be shown on the field: %s', $asset->id, $failure->getMessage()), previous: $failure);
        }

        return ['frame' => $frame, 'totalFrames' => $timeline->totalFrames(), 'fps' => $timeline->fps(), 'mapId' => $mapId,
            'sessionId' => $this->effectField->sessionId, 'diagnostics' => $this->effectField->getDiagnostics(),
            'lines' => array_values($this->effectField->frame()), 'issue' => $this->effectField->mapFailure];
    }

    /**
     * One exchange with the editor window's graphical view of the field an
     * effect is shown on, as {@see exchangeCinematicScene()} is for a cinematic.
     *
     * @param list<string> $events
     * @param string|null $sessionId The epoch the window is answering; unqualified or stale events are discarded.
     * @return array{sessionId: non-empty-string, diagnostics: list<string>, grid: array{columns: int, rows: int, cellWidth: int, cellHeight: int}, messages: list<array{type: string, payload: array<string, mixed>}>}
     * @throws SessionRefusal When no effect is shown on the field or a line is not a renderer event.
     */
    public function exchangeEffectFieldScene(array $events, ?string $sessionId = null): array
    {
        $field = $this->effectField ?? throw new SessionRefusal('No effect is shown on the field.');
        $grid = $field->getSceneGrid();
        try {
            return [
                'sessionId' => $field->sessionId,
                'diagnostics' => $field->getDiagnostics(),
                'grid' => ['columns' => $grid->columns, 'rows' => $grid->rows, 'cellWidth' => $grid->cellWidth, 'cellHeight' => $grid->cellHeight],
                'messages' => $field->exchangeScene($events, $sessionId),
            ];
        } catch (RendererProtocolException | RendererTransportException $error) {
            throw new SessionRefusal('The graphical preview stopped: ' . $error->getMessage(), previous: $error);
        }
    }

    /**
     * Closes the field an effect is shown on, with its graphical view.
     *
     * @return array{closed: bool} Whether one was open.
     */
    public function closeEffectField(): array
    {
        $closed = $this->effectField !== null;
        $this->effectField?->dispose();
        $this->effectField = null;
        $this->effectFieldKey = null;

        return ['closed' => $closed];
    }

    /**
     * A terminal picture as a renderer paints it: the Engine's styled runs on the
     * picture's own grid, its colours kept and its control codes never counted as
     * text. Cells take the share of the Engine's canvas the battle arena's do.
     *
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private function describeTerminalCanvas(array $lines, int $columns, int $rows): array
    {
        $grid = new RendererGridConfig($columns, $rows, max(1, intdiv(PresentationCanvas::DEFAULT_WIDTH, $columns)),
            max(1, intdiv(PresentationCanvas::DEFAULT_HEIGHT, $rows)));

        return ($this->terminalComposer ??= new TerminalPresentationComposer())->createCanvasFromLines($lines, $grid)->toArray();
    }

    /**
     * The cinematic previewed: which one, its terminal picture now, how long
     * it has run, its status, whether it plays or has finished, and why it
     * stopped or waits, when it does.
     *
     * @return array<string, mixed>
     */
    private function describeCinematicPreview(): array
    {
        $preview = $this->cinematicPreview ?? throw new SessionRefusal('No cinematic is being previewed.');

        $lines = array_values($preview->frame());
        [$columns, $rows] = $preview->getScreenSize();

        return [
            'id' => $this->cinematicPreviewId,
            'sessionId' => $preview->getSceneSessionId(),
            'diagnostics' => $preview->getDiagnostics(),
            'lines' => $lines,
            'terminalCanvas' => $this->describeTerminalCanvas($lines, $columns, $rows),
            'elapsed' => $preview->elapsed(),
            'status' => $preview->status(),
            'playing' => $preview->isPlaying(),
            'finished' => $preview->isFinished(),
            'failure' => $preview->failure()['message'] ?? null,
            'wait' => $preview->waitDescription(),
            'waitingFor' => $preview->getWaitKind(),
        ];
    }
    /**
     * A summon as the battle test plays it, at one command frame: the
     * Engine's command preview of the summon as it stands, unsaved edits
     * included, cast by the first member of the battle test party (else the
     * starting party) its wielder policy allows, at the targets its linked
     * action takes in the battle test troop (else the first troop), over the
     * battle test arena, with the project's battle pacing, in one
     * presentation: graphical, its canvas with the terminal arena's lines
     * from the paired lane, or terminal alone, at the terminal sequence's own
     * cadence, reading no graphical image. A frame of the summon's own
     * timeline may be asked for instead of a command frame; each frame says
     * which of the summon's frames it draws, so the timeline and the battle
     * share one playhead. Enemies and the party are read as
     * saved; nothing is resolved, spent or played aloud, and nothing is
     * written. Seeking reuses the preview until the summon or its battle
     * changes.
     *
     * @return array<string, mixed>
     * @throws SessionRefusal When the summon does not compile, the battle cannot be set up or the frame is outside the command.
     */
    public function readSummonBattlePreview(int $index, int $frame, bool $reducedMotion = false,
        EffectPresentation $presentation = EffectPresentation::GRAPHICAL, ?int $authoredFrame = null): array
    {
        $asset = $this->requireTimelineCutscene(CutsceneType::SUMMON->getRecordCategory(), $index);
        $graphic = $presentation === EffectPresentation::GRAPHICAL;
        try {
            $definition = $asset->summonDefinition();
            // The terminal arena plays the terminal compile alone, which needs no graphical image.
            $graphical = $graphic ? $asset->compiledSummon(EffectPresentation::GRAPHICAL) : null;
            $terminal = $asset->compiledSummon(EffectPresentation::TERMINAL);
        } catch (Throwable $failure) {
            throw new SessionRefusal(sprintf('%s does not compile: %s', $asset->id, $failure->getMessage()), previous: $failure);
        }
        $entry = $this->readBattleTestEntry();
        $troop = $this->readPreviewTroop($entry);
        $config = $this->workspace->config?->getRecord('ui.battle')->get('value');
        $battleUi = is_array($config) ? $config : [];
        $key = hash('xxh128', serialize([$asset->id, $definition->toDataArray(), $graphical?->toArray(), $terminal->toArray(),
            $entry, $this->readStartingPartyReferences(), $troop, $battleUi, $graphic ? $this->readProposedBattlerBindings() : null]));
        if (($this->summonBattlePreviews[$presentation->value]['key'] ?? null) !== $key) {
            // The terminal arena needs no graphical layout, and reads no image.
            $catalog = $graphic ? $this->requireBattleLayoutCatalog('preview summons on') : null;
            $this->summonBattlePreviews[$presentation->value] = ['key' => $key, 'preview' => ProjectDirectoryContext::run($this->workspace->projectRoot,
                fn(): SummonBattlePreview => $this->createSummonBattlePreview($definition, $graphical, $terminal, $entry, $troop,
                    BattlePacing::fromBattleUiConfig($battleUi), $catalog, $presentation))];
        }
        $preview = $this->summonBattlePreviews[$presentation->value]['preview'];
        // A frame of the summon's own timeline is drawn where the battle plays it.
        $frame = $authoredFrame === null ? $frame : $preview->findCommandFrame($authoredFrame);
        try {
            $read = $preview->readFrame(max(0, min($frame, $preview->totalFrames - 1)), $reducedMotion);
        } catch (Throwable $failure) {
            throw new SessionRefusal(sprintf('%s cannot be previewed: %s', $asset->id, $failure->getMessage()), previous: $failure);
        }

        return [...$read, 'totalFrames' => $preview->totalFrames, 'fps' => BattleCommandTimeline::FPS,
            'assetRoot' => $this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets',
            'caster' => $preview->caster, 'targets' => $preview->targets, 'troop' => $troop['name'], 'phases' => $preview->phases];
    }

    /**
     * The battle a summon preview plays in, built the way the battle test
     * builds it, and the summon cast in it.
     *
     * @param array<string, mixed> $entry The battle test as system data holds it.
     * @param array{name: string, data: array<string, mixed>} $troop
     * @throws SessionRefusal When the battle cannot be set up or the summon cannot be cast in it.
     */
    private function createSummonBattlePreview(SummonCutsceneDefinition $definition, ?SummonCompiledCutscene $graphical,
        SummonCompiledCutscene $terminal, array $entry, array $troop, BattlePacing $pacing, ?BattlePresentationCatalog $catalog,
        EffectPresentation $presentation): SummonBattlePreview
    {
        try {
            [$battle, $skills] = $this->createPreviewBattle($entry, $troop);

            return SummonBattlePreview::create($definition, $graphical, $terminal, $battle, $skills, $pacing, $catalog,
                $this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets', $presentation);
        } catch (SessionRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            throw new SessionRefusal(sprintf('%s cannot be previewed in battle: %s', $definition->name, $failure->getMessage()), previous: $failure);
        }
    }

    /**
     * A battle effect as a battle plays it, at one command frame, in one
     * presentation: in an action of the battle test party that the Engine's
     * own animation selection plays it in (its attacks, skills, magic and
     * items; a summon is previewed as a summon), with the animation's other
     * effect beside it, the action's pose and the project's battle pacing.
     * The effects are compiled as they stand, unsaved edits included, and the
     * animations are read as they stand too. Every action that plays the
     * effect is listed, so another can be chosen; a frame of the effect's own
     * timeline may be asked for instead of a command frame. Nothing is
     * resolved, spent or played aloud, and nothing is written.
     *
     * @return array<string, mixed>
     * @throws SessionRefusal When the effect does not compile, no action of the party plays it, or the battle cannot be set up.
     */
    public function readEffectBattlePreview(int $index, int $frame, bool $reducedMotion = false,
        EffectPresentation $presentation = EffectPresentation::GRAPHICAL, ?int $authoredFrame = null, int $binding = 0): array
    {
        $asset = $this->requireTimelineCutscene(CutsceneType::EFFECT->getRecordCategory(), $index);
        $effects = $this->workspace->cutscenes?->assets(CutsceneType::EFFECT) ?? [];
        $animations = array_values(array_map(static fn($record): mixed => $record->toArray(), $this->requireRecordDatabase('animations')->getRecords()));
        $entry = $this->readBattleTestEntry();
        $troop = $this->readPreviewTroop($entry);
        $config = $this->workspace->config?->getRecord('ui.battle')->get('value');
        $battleUi = is_array($config) ? $config : [];
        $graphic = $presentation === EffectPresentation::GRAPHICAL;
        $key = hash('xxh128', serialize([$asset->id, array_map(static fn(CutsceneAsset $effect): array => $effect->payload(), $effects), $animations,
            $entry, $this->readStartingPartyReferences(), $troop, $battleUi, $binding, $graphic ? $this->readProposedBattlerBindings() : null]));
        if (($this->effectBattlePreviews[$presentation->value]['key'] ?? null) !== $key) {
            $catalog = $graphic ? $this->requireBattleLayoutCatalog('preview effects on') : null;
            $this->effectBattlePreviews[$presentation->value] = ['key' => $key, 'preview' => ProjectDirectoryContext::run($this->workspace->projectRoot,
                fn(): EffectBattlePreview => $this->createEffectBattlePreview($asset->id, $animations, $entry, $troop,
                    BattlePacing::fromBattleUiConfig($battleUi), $catalog, $presentation, $binding))];
        }
        $preview = $this->effectBattlePreviews[$presentation->value]['preview'];
        $frame = $authoredFrame === null ? $frame : $preview->findCommandFrame($authoredFrame);
        try {
            $read = $preview->readFrame(max(0, min($frame, $preview->totalFrames - 1)), $reducedMotion);
        } catch (Throwable $failure) {
            throw new SessionRefusal(sprintf('%s cannot be previewed: %s', $asset->id, $failure->getMessage()), previous: $failure);
        }

        return [...$read, 'totalFrames' => $preview->totalFrames, 'fps' => BattleCommandTimeline::FPS,
            'assetRoot' => $this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets', 'lane' => $preview->lane,
            'sourceEffectAnchor' => $preview->sourceEffectAnchor->value, 'contexts' => $preview->contexts, 'binding' => $preview->binding,
            'caster' => $preview->caster, 'targets' => $preview->targets, 'troop' => $troop['name'], 'phases' => $preview->phases];
    }

    /**
     * The battle an effect preview plays in, and the effect played in it.
     *
     * @param list<mixed> $animations The animation records as they stand.
     * @param array<string, mixed> $entry The battle test as system data holds it.
     * @param array{name: string, data: array<string, mixed>} $troop
     * @throws SessionRefusal When the battle cannot be set up or no action of it plays the effect.
     */
    private function createEffectBattlePreview(string $effectId, array $animations, array $entry, array $troop, BattlePacing $pacing,
        ?BattlePresentationCatalog $catalog, EffectPresentation $presentation, int $binding): EffectBattlePreview
    {
        // The animations as authored now; an entry the Engine would not read plays nothing, as in battle.
        $library = AnimationLibrary::createFromAnimations(array_values(array_filter(array_map(static function (mixed $data): ?Animation {
            try {
                return is_array($data) ? Animation::fromArray($data) : null;
            } catch (Throwable) {
                return null;
            }
        }, $animations))));
        $compile = function (string $id, EffectPresentation $presentation): CompiledEffectTimeline {
            $effect = $this->workspace->cutscenes?->find(CutsceneType::EFFECT, $id)
                ?? throw new SessionRefusal(sprintf('The animation plays the effect %s, which does not exist.', $id));

            return $effect->compiledEffect($presentation, true);
        };
        try {
            [$battle, $skills] = $this->createPreviewBattle($entry, $troop);

            return EffectBattlePreview::create($effectId, $battle, $skills, $library, $pacing, $catalog,
                $this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets', $presentation, $compile, $binding);
        } catch (SessionRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $failure) {
            throw new SessionRefusal(sprintf('%s cannot be previewed in battle: %s', $effectId, $failure->getMessage()), previous: $failure);
        }
    }
    /**
     * The battle a preview plays in, built the way the battle test builds it:
     * its party, or the starting party, against its troop, with the skills
     * they know.
     *
     * @param array<string, mixed> $entry The battle test as system data holds it.
     * @param array{name: string, data: array<string, mixed>} $troop
     * @return array{0: BattleConfig, 1: SkillCatalog}
     */
    private function createPreviewBattle(array $entry, array $troop): array
    {
        $actors = $this->workspace->actorDatabase->createActorStore();
        $skills = $this->workspace->loadSkillCatalog();
        $setup = ProjectBattleTest::fromArray($entry)->createSetup($actors, $this->readStartingPartyReferences(), $this->workspace->getSystemField('battle.partySize') ?? \Ichiloto\Engine\Entities\Party::BATTLE_SIZE);
        $party = $setup->createParty($actors, $this->loadItemStore(), $skills);
        $previousEnemies = ConfigStore::has(EnemyStore::class) ? ConfigStore::get(EnemyStore::class) : null;
        try {
            ConfigStore::put(EnemyStore::class, new EnemyStore());

            return [new BattleConfig($party, Troop::fromArray($troop['data']), settings: $setup->getBattleSettings()), $skills];
        } finally {
            $previousEnemies === null ? ConfigStore::remove(EnemyStore::class) : ConfigStore::put(EnemyStore::class, $previousEnemies);
        }
    }

    /**
     * The troop a summon preview is cast at: the battle test's, as the
     * battle test fights it, else the project's first; as it stands now.
     *
     * @param array<string, mixed> $entry The battle test as system data holds it.
     * @return array{name: string, data: array<string, mixed>}
     * @throws SessionRefusal When the project has no troops, or the battle test names one it does not have.
     */
    private function readPreviewTroop(array $entry): array
    {
        $named = is_string($entry['troop'] ?? null) ? trim($entry['troop']) : null;
        foreach ($this->requireRecordDatabase('troops')->getRecords() as $record) {
            $data = $record->toArray();
            $name = is_array($data) ? trim(strval($data['name'] ?? '')) : '';
            if ($name !== '' && ($named === null || $named === $name)) {
                return ['name' => $name, 'data' => $data];
            }
        }

        throw new SessionRefusal($named === null ? 'This project has no troop to cast a summon at.'
            : sprintf('The battle test fights %s, which no troop is named.', $named));
    }
    /**
     * An image track's art with the image's own size, read from the file the
     * track names inside the asset root; none for a file that is missing or
     * not an image, which the track's own validation reports.
     *
     * @param array<string, mixed> $art
     * @return array<string, mixed>
     */
    private function measureTrackArt(array $art): array
    {
        $root = realpath($this->workspace->projectRoot . '/assets');
        $path = $root === false || ($art['asset'] ?? '') === '' ? false : realpath($root . '/' . $art['asset']);
        $size = $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) ? @getimagesize($path) : false;

        return [...$art, 'width' => $size === false ? null : $size[0], 'height' => $size === false ? null : $size[1]];
    }

    /**
     * Chooses which of an effect's or summon's sequences its record shows and
     * edits, terminal or graphical, as the TUI's sequence switch does.
     * Nothing is written; the other sequence stays as it is.
     *
     * @return array{presentation: string}
     * @throws SessionRefusal When the record has no separate sequences, or the sequence is unknown.
     */
    public function selectCutscenePresentation(string $category, int $index, string $presentation): array
    {
        $asset = $this->requireTimelineCutscene($category, $index);
        $chosen = EffectPresentation::tryFrom($presentation)
            ?? throw new SessionRefusal(sprintf('A sequence is terminal or graphical, not %s.', $presentation));
        if (! $asset->hasPresentations()) {
            throw new SessionRefusal(sprintf('%s has one sequence for every renderer.', $asset->id));
        }
        $asset->selectPresentation($chosen);
        $this->workspace->cutscenes?->refreshRecords($asset->type);

        return ['presentation' => $chosen->value];
    }

    /**
     * Gives a summon or effect with one sequence for every renderer a
     * terminal and a graphical sequence, each a copy of it, as one undo step,
     * as the TUI's sequence choice does; the record then edits the terminal
     * one. It reaches disk on save.
     *
     * @return array{changed: bool, presentation: string}
     * @throws SessionRefusal When the record already has them or cannot be written.
     */
    public function separateCutsceneSequences(string $category, int $index): array
    {
        $asset = $this->requireTimelineCutscene($category, $index);
        if ($asset->hasPresentations()) {
            throw new SessionRefusal(sprintf('%s already has a terminal and a graphical sequence.', $asset->id));
        }
        try {
            $command = $this->workspace->cutscenes?->changeAsset($asset->type, $index, sprintf('Separate %s sequences', $asset->type->noun()),
                static function () use ($asset): void {
                    $asset->selectPresentation(EffectPresentation::TERMINAL);
                    $asset->splitIntoPresentations();
                })['command'];
        } catch (RuntimeException $failure) {
            throw new SessionRefusal($failure->getMessage(), previous: $failure);
        }
        if ($command !== null) {
            $this->history->record($command);
        }

        return ['changed' => $command !== null, 'presentation' => EffectPresentation::TERMINAL->value];
    }

    /**
     * Gives a summon's graphical sequence a cinematic stage, or takes it
     * away, as one undo step, as the TUI's Stage row does. It reaches disk
     * on save.
     *
     * @return array{changed: bool, stage: bool}
     * @throws SessionRefusal When the graphical sequence cannot safely own or release a stage.
     */
    public function setCutsceneStage(string $category, int $index, bool $present): array
    {
        $asset = $this->requireTimelineCutscene($category, $index);
        if ($asset->type === CutsceneType::SUMMON && ! $asset->hasPresentations()) {
            throw new SessionRefusal(sprintf('A stage is a summon\'s graphical sequence\'s own; separate %s\'s sequences first.', $asset->id));
        }
        try {
            $command = $this->workspace->cutscenes?->changeAsset($asset->type, $index, $present ? 'Add stage' : 'Remove stage',
                static function () use ($asset, $present): void {
                    $asset->setStage($present);
                })['command'];
        } catch (RuntimeException $failure) {
            throw new SessionRefusal($failure->getMessage(), previous: $failure);
        }
        if ($present && ! $asset->hasStage()) {
            throw new SessionRefusal(sprintf('%s needs at least two frames to restore its stage before it ends.', $asset->id));
        }
        if ($command !== null) {
            $this->history->record($command);
        }

        return ['changed' => $command !== null, 'stage' => $asset->hasStage()];
    }

    /** A summon or effect record's asset; a cinematic is a command tree, not a timeline. */
    private function requireTimelineCutscene(string $category, int $index): CutsceneAsset
    {
        $type = CutsceneType::findByRecordCategory($category);
        if ($type === null || $type === CutsceneType::CINEMATIC) {
            throw new SessionRefusal(sprintf('%s is not a summon or effect category.', $category));
        }
        $id = $this->workspace->cutscenes?->ids($type)[$index] ?? null;

        return ($id === null ? null : $this->workspace->cutscenes?->find($type, $id))
            ?? throw new SessionRefusal(sprintf('There is no %s %d.', $type->noun(), $index));
    }

    /**
     * The choices a reference row offers, read in the context it is edited
     * in: the project's own references need no map, a map's own (its events,
     * regions) are read from the map named, and a cutscene's own (a summon's
     * cues) from the cutscene record named, as `{category, index}`.
     *
     * @param array{category?: mixed, index?: mixed, kind?: mixed, marker?: mixed, frame?: mixed, path?: mixed}|null $record
     *   Database {category,index,frame}, NPC {kind:'npc',index,frame}, or event {kind:'event',marker,path} for a
     *   row of its data, {kind:'event',marker,frame} for one of its record rows.
     * @return list<array{value: string, label: string}>
     * @throws SessionRefusal When the category, map or record is unknown.
     */
    public function listReferences(?string $mapId, string $category, ?array $record = null): array
    {
        if (! ReferenceCatalog::knows($category)) {
            throw new SessionRefusal(sprintf('There is no reference category %s.', $category));
        }
        $cutscene = null;
        $type = is_string($record['category'] ?? null) ? CutsceneType::findByRecordCategory($record['category']) : null;
        if ($type !== null) {
            $id = $this->workspace->cutscenes?->ids($type)[(int) ($record['index'] ?? -1)] ?? null;
            $cutscene = ($id === null ? null : $this->workspace->cutscenes?->find($type, $id))
                ?? throw new SessionRefusal(sprintf('There is no %s %s.', $type->noun(), strval($record['index'] ?? '')));
        }
        // A cinematic names its own maps; the map selected beside it is not where its commands run.
        $catalog = new ReferenceCatalog($this->workspace, $mapId === null || $cutscene?->type === CutsceneType::CINEMATIC ? null : $this->requireMap($mapId), $cutscene);
        if ($category === 'world_object_tile_layers') {
            if ($mapId === null || !is_string($record['id'] ?? null)) { throw new SessionRefusal('A covered tile-layer picker needs its map-local object owner.'); }
            $map = $this->requireMap($mapId);
            $object = array_find(WorldObjectAuthoring::readEntries($map), static fn($entry): bool => $entry['id'] === $record['id'])
                ?? throw new SessionRefusal('That world-object owner no longer exists.');
            return array_map(static fn($layer): array => ['value' => $layer['name'], 'label' => $layer['name']],
                array_values(array_filter($map->describeTileLayers()['layers'], static fn($layer): bool => $layer['owner'] === ($object['covers']['layer'] ?? null))));
        }
        if ($category === 'map_event_markers') {
            // The events of the map a map event condition names.
            if (!is_string($record['map'] ?? null)) { return []; }
            $markers = $catalog->listMapEventMarkers($record['map']);
            return array_map(static fn(string $marker): array => ['value' => $marker, 'label' => $markers['labels'][$marker]], $markers['values']);
        }
        if ($category === 'cinematic_movement_routes' && $record !== null) {
            $catalog = $catalog->createMovementContext($this->getMovementOwnerCommands($mapId, $record));
        }
        $labels = $catalog->labelsFor($category);

        return array_map(static fn(mixed $value): array => [
            'value' => (string) $value,
            'label' => (string) ($labels[$value] ?? $value),
        ], array_values($catalog->valuesFor($category)));
    }

    /** Resolve only the real source owner supplied by the row, never the current selection. */
    private function getMovementOwnerCommands(?string $mapId, array $owner): array
    {
        if (($owner['kind'] ?? '') === 'event') {
            $map = $this->requireMap($mapId ?? throw new SessionRefusal('An event route reference requires its map.'));
            $marker = is_string($owner['marker'] ?? null) ? $owner['marker'] : '';
            $event = $map->getEventDefinition($marker)
                ?? throw new SessionRefusal('That event route owner is no longer present.');
            if (array_key_exists('frame', $owner)) {
                // A row of the event's record rows names its frame, as an NPC's does.
                $records = $this->createMapInspector($map)->createEventRecordPane($marker, $event)->records();
                $frame = self::requireFrame($owner['frame'], 'references.list');
                if ($frame !== [] && $records->getFrameCommands(0, $frame) === null) {
                    throw new SessionRefusal('That route command frame is no longer present.');
                }
                return MovementRouteFields::getOwnerCommands((array) ($records->getRecordByIndex(0)?->toArray() ?? []),
                    MovementRouteFields::getFrameOwnerPath($records->schema, $frame));
            }
            $path = self::requireFrame($owner['path'] ?? [], 'references.list');
            return MovementRouteFields::getOwnerCommands($event, $path);
        }
        if (($owner['kind'] ?? '') === 'npc') {
            $map = $this->requireMap($mapId ?? throw new SessionRefusal('An NPC route reference requires its map.'));
            $database = (new NpcInspector($map, graphical: true))->records();
        } else {
            $category = is_string($owner['category'] ?? null) ? $owner['category'] : '';
            $database = $this->requireRecordDatabase($category);
        }
        $index = filter_var($owner['index'] ?? null, FILTER_VALIDATE_INT);
        $record = $index === false || $index === null ? null : $database->getRecordByIndex($index);
        if ($record === null) { throw new SessionRefusal('That route command owner is no longer present.'); }
        $frame = self::requireFrame($owner['frame'] ?? [], 'references.list');
        if ($frame !== [] && $database->getFrameCommands($index, $frame) === null) {
            throw new SessionRefusal('That route command frame is no longer present.');
        }
        return MovementRouteFields::getOwnerCommands((array) $record->toArray(),
            MovementRouteFields::getFrameOwnerPath($database->schema, $frame));
    }

    /**
     * A troop's graphical formation as an arranger draws it, composed by the
     * Engine's BattleFormationLayout, the placement the battle itself uses:
     * the battle canvas, the project's arenas with the one previewed and its
     * backgrounds, the ground battlers stand on there (its edges, perspective
     * and how much each side's depth is compressed to fit it), the starting
     * party in its slots, and each member's enemy, authored placement (null
     * when the battle arranges it), the place it stands at, where its feet
     * stand on this arena, the bounds its idle art is drawn in, that art, and
     * its body span at battle scale. The arena is a preview only, as in RPG
     * Maker's Troops tab: a troop is placed once for every arena, standing on
     * each arena's own ground, and choosing one writes nothing. With
     * `$moving` (a member, a column and a depth) the formation is composed
     * as moving that member there would leave it ({@see placeTroopMember()}),
     * so a drag shows the battle's own picture as it goes; nothing is written.
     *
     * @param array{0: int, 1: float, 2: float}|null $moving
     * @return array<string, mixed>
     * @throws SessionRefusal When the troop is unknown, the project has no graphical battle, or the move is refused.
     */
    public function readTroopFormation(int $index, ?string $arena = null, ?array $moving = null): array
    {
        $catalog = $this->requireBattleLayoutCatalog('arrange troops on');
        ['members' => $members, 'places' => $places] = $this->readTroopPlaces($index);
        if ($moving !== null) {
            $places = $this->getMovedPlaces($index, ...$moving);
        }
        $enemies = array_map(static fn(array $member, ?BattleStageSlot $place): array => ['enemyId' => $member['enemy'], 'slot' => $place],
            $members, $places);
        [$formation, $view, $clearance] = $this->composeFormation($catalog, $arena, $enemies);
        foreach ($formation->enemies as $position => $battler) {
            $place = $formation->staging->enemyPlaces[$position];
            $members[$position]['place'] = ['x' => $place->x, 'depth' => $place->depth];
            $members[$position]['battler'] = self::describeFormationBattler($battler, $clearance['enemies'][$position]);
        }

        return [...$view, 'members' => $members];
    }

    /**
     * A battle arena as its page shows it: its background on the battle
     * canvas with the ground it declares (or inherits) drawn by its edges,
     * the starting party standing on it, and a troop beside them, all as
     * the Engine stands them, so an author marks the ground by its picture.
     * The troop is the one chosen, else the first with members. It reads
     * the arena as it is now, unsaved edits included. `$ground` holds ground
     * fields and `$footing` footing polygons (by habitat, `land` or `water`)
     * as they stand while being dragged, laid over the arena's own and read
     * by the Engine as authored ones are, so a drag shows the battle's
     * picture as it goes; nothing is written.
     *
     * @param array<string, mixed> $ground
     * @param array<string, mixed> $footing
     * @return array<string, mixed>
     * @throws SessionRefusal When the arena or troop is unknown, the project has no graphical battle, or the ground or footing is refused.
     */
    public function readArenaPreview(int $index, ?int $troop = null, array $ground = [], array $footing = []): array
    {
        $record = $this->requireRecordDatabase(BattleArenaFields::CATEGORY)->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('%s has no record %d.', BattleArenaFields::CATEGORY, $index));
        $catalog = $this->requireBattleLayoutCatalog('preview arenas on', $ground === [] && $footing === [] ? []
            : [(string) $record->get('id') => ['ground' => $ground, 'footing' => $footing]]);
        $troops = $this->requireRecordDatabase('troops');
        $troop ??= array_key_first(array_filter($troops->getRecords(), static fn($entry): bool => $entry->getSubList('enemies') !== []));
        ['members' => $members, 'places' => $places] = $troop === null ? ['members' => [], 'places' => []] : $this->readTroopPlaces($troop);
        $enemies = array_map(static fn(array $member, ?BattleStageSlot $place): array => ['enemyId' => $member['enemy'], 'slot' => $place],
            $members, $places);
        [$formation, $view, $clearance] = $this->composeFormation($catalog, (string) $record->get('id'), $enemies);
        foreach ($formation->enemies as $position => $battler) {
            $members[$position]['battler'] = self::describeFormationBattler($battler, $clearance['enemies'][$position]);
        }
        $troopNames = array_map(static fn($entry): string => (string) $entry->get('name'), $troops->getRecords());

        return [...$view, 'members' => $members, 'troop' => $troop, 'troops' => array_values($troopNames)];
    }

    /**
     * Replaces a battle arena's land or water footing, as one undo step: the
     * polygon battlers of that habitat may plant their feet in, as canvas
     * points in order. No points removes it.
     *
     * @param list<array{x: int|float, y: int|float}> $points
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When the arena is unknown or read-only, or the polygon is refused.
     */
    public function setArenaFooting(int $index, EnemyHabitat $habitat, array $points): array
    {
        $arenas = $this->requireRecordDatabase(BattleArenaFields::CATEGORY);
        $change = $this->changeRecord(static fn(): RecordChange => BattleArenaFields::setFooting($arenas, $index, $habitat, $points,
            sprintf('Set %s footing', $habitat->value)));

        return ['changed' => $change->command !== null, 'records' => $arenas->getEntryLabels()];
    }

    /**
     * Places every member of a troop where the Engine arranges it, written as
     * each member's placement, as one undo step: RPG Maker's Align.
     *
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When the troop is unknown or read-only, or the project has no graphical battle.
     */
    public function alignTroop(int $index): array
    {
        $places = array_map(static fn(BattleStageSlot $place): array => $place->toArray(), $this->arrangeTroop($index, true));

        return $this->placeTroopMembers($index, $places, 'Align members');
    }

    /**
     * Removes every member's placement, as one undo step, so the battle
     * arranges the troop itself from the current art, on every arena.
     *
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When the troop is unknown or read-only.
     */
    public function arrangeTroopAutomatically(int $index): array
    {
        $count = count($this->readTroopPlaces($index)['members']);

        return $this->placeTroopMembers($index, array_fill(0, $count, null), 'Arrange members automatically');
    }

    /**
     * Moves one member to a place, as one undo step. Every other member is
     * placed where it stands now, so one the battle arranged does not move
     * when this one does.
     *
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When the troop or member is unknown, the troop is read-only, or the place is refused.
     */
    public function placeTroopMember(int $index, int $member, float $x, float $depth): array
    {
        return $this->placeTroopMembers($index, array_map(static fn(BattleStageSlot $place): array => $place->toArray(),
            $this->getMovedPlaces($index, $member, $x, $depth)), 'Move member');
    }

    /**
     * Each member's place once one member moves to a column and depth: every
     * other member where it stands now, so one the battle arranged stays put.
     *
     * @return list<BattleStageSlot>
     * @throws SessionRefusal When the troop or member is unknown, or the place is refused.
     */
    private function getMovedPlaces(int $index, int $member, float $x, float $depth): array
    {
        $places = $this->arrangeTroop($index, false);
        $moved = $places[$member] ?? throw new SessionRefusal(sprintf('The troop has no member %d.', $member + 1));
        try {
            $places[$member] = new BattleStageSlot($x, $depth, $moved->width, $moved->height, $moved->displayScale);
        } catch (\InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }

        return $places;
    }

    /**
     * Each member of a troop with the place the battle stands it at: its own
     * placement, or the Engine's arrangement beside the placed ones; with
     * `$all`, the Engine's arrangement for every member.
     *
     * @return list<BattleStageSlot>
     * @throws SessionRefusal When the troop is unknown or the project has no graphical battle.
     */
    private function arrangeTroop(int $index, bool $all): array
    {
        $catalog = $this->requireBattleLayoutCatalog('arrange troops on');
        ['members' => $members, 'places' => $places] = $this->readTroopPlaces($index);
        try {
            return BattleFormationLayout::arrangeTroop($catalog, array_column($members, 'enemy'), $all ? [] : $places,
                $this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets');
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            throw new SessionRefusal('The troop cannot be arranged: ' . $error->getMessage(), previous: $error);
        }
    }

    /**
     * @param list<?array<string, int|float>> $places
     * @return array{changed: bool, records: list<string>}
     */
    private function placeTroopMembers(int $index, array $places, string $label): array
    {
        $troops = $this->requireRecordDatabase('troops');
        $change = $this->changeRecord(fn(): RecordChange => $this->troopAuthoring->placeMembers($troops, $index, $places, $label));

        return ['changed' => $change->command !== null, 'records' => $troops->getEntryLabels()];
    }

    /**
     * A troop's members as authored (enemy, authored placement or null, and
     * why a placement cannot be read) and the place each stands at when it
     * is readable, null for the battle to arrange.
     *
     * @return array{members: list<array<string, mixed>>, places: list<?BattleStageSlot>}
     * @throws SessionRefusal When the troop is unknown.
     */
    private function readTroopPlaces(int $index): array
    {
        $record = $this->requireRecordDatabase('troops')->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('troops has no record %d.', $index));
        $members = $places = [];
        foreach ($record->getSubList('enemies') as $entry) {
            $enemy = is_array($entry) ? (string) ($entry['enemy'] ?? '') : '';
            $placement = is_array($entry) && is_array($entry['graphicalPlacement'] ?? null) ? $entry['graphicalPlacement'] : null;
            $member = ['enemy' => $enemy, 'placement' => $placement, 'place' => null, 'battler' => null];
            $place = null;
            if ($placement !== null) {
                try {
                    $place = BattleStageSlot::fromArray($placement, 'Battle Placement');
                } catch (\InvalidArgumentException $error) {
                    $member['issue'] = $error->getMessage();
                }
            }
            $members[] = $member;
            $places[] = $place;
        }

        return ['members' => $members, 'places' => $places];
    }

    /**
     * A troop as its Troops page shows it, the way RPG Maker's Troops tab
     * does: its members with the enemy each fights, its terminal position
     * and its battle placement; the project's enemies a member can be added
     * from; and its graphical formation over a previewed arena
     * ({@see readTroopFormation()}), or why there is none, so a game with
     * only a terminal battle still composes its troops here. Align places
     * every member where the Engine's arrangement stands it ({@see alignTroop()}).
     * A member being dragged (`$moving`) is drawn where it would stand, unwritten.
     *
     * @param array{0: int, 1: float, 2: float}|null $moving
     * @return array{members: list<array<string, mixed>>, enemies: list<string>, formation: ?array<string, mixed>, formationIssue: ?string, align: array{available: bool, reason: ?string}}
     * @throws SessionRefusal When the troop is unknown.
     */
    public function readTroopPage(int $index, ?string $arena = null, ?array $moving = null): array
    {
        $record = $this->requireRecordDatabase('troops')->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('troops has no record %d.', $index));
        $members = [];
        foreach ($record->getSubList(TroopAuthoring::MEMBERS) as $entry) {
            $position = is_array($entry['position'] ?? null) && array_is_list($entry['position']) ? array_values($entry['position']) : null;
            $members[] = [
                'enemy' => strval($entry['enemy'] ?? ''),
                'position' => $position,
                'placement' => is_array($entry['graphicalPlacement'] ?? null) ? $entry['graphicalPlacement'] : null,
            ];
        }
        $page = [
            'members' => $members,
            'enemies' => $this->listEnemyNames(),
            'formation' => null,
            'formationIssue' => null,
            'align' => ['available' => true, 'reason' => null],
        ];
        try {
            $page['formation'] = $this->readTroopFormation($index, $arena, $moving);
        } catch (SessionRefusal $refusal) {
            $page['formationIssue'] = $refusal->getMessage();
        }

        return $page;
    }

    /**
     * Adds a member fighting one of the project's enemies to a troop, as one
     * undo step ({@see TroopAuthoring::addMember()}): on the terminal field
     * where the Engine stands a new member beside those there, by their
     * sprites as the terminal battle draws them; in the graphical battle
     * arranged beside the placed members until it is placed.
     *
     * @return array{changed: bool, member: int, records: list<string>}
     * @throws SessionRefusal When the troop is unknown or read-only, or the project has no such enemy.
     */
    public function addTroopMember(int $index, string $enemy): array
    {
        $troops = $this->requireRecordDatabase('troops');
        $enemies = $this->listEnemyNames();
        $sprites = new EnemySprites($this->workspace->projectRoot)->readByEnemy($this->workspace->getRecordDatabase('enemies'));
        $change = $this->changeRecord(fn(): RecordChange => $this->troopAuthoring->addMember($troops, $index, $enemy, $enemies, $sprites));

        return ['changed' => true, 'member' => (int) $change->index, 'records' => $troops->getEntryLabels()];
    }

    /**
     * Removes one member of a troop, as one undo step.
     *
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When the troop or member is unknown, or the troop is read-only.
     */
    public function removeTroopMember(int $index, int $member): array
    {
        $troops = $this->requireRecordDatabase('troops');
        $change = $this->changeRecord(fn(): RecordChange => $this->troopAuthoring->removeMember($troops, $index, $member));

        return ['changed' => $change->command !== null, 'records' => $troops->getEntryLabels()];
    }

    /**
     * Removes every member of a troop, as one undo step.
     *
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When the troop is unknown or read-only.
     */
    public function clearTroopMembers(int $index): array
    {
        $troops = $this->requireRecordDatabase('troops');
        $change = $this->changeRecord(fn(): RecordChange => $this->troopAuthoring->clearMembers($troops, $index));

        return ['changed' => $change->command !== null, 'records' => $troops->getEntryLabels()];
    }

    /**
     * Names a troop after its members, as one undo step.
     *
     * @return array{changed: bool, name: string, records: list<string>, note?: string}
     * @throws SessionRefusal When the troop is unknown or read-only, has no member, or the name is refused.
     */
    public function nameTroopAfterMembers(int $index): array
    {
        $troops = $this->requireRecordDatabase('troops');
        $change = $this->changeRecord(fn(): RecordChange => $this->troopAuthoring->nameAfterMembers($troops, $index));
        $record = $troops->getRecordByIndex($index);

        return array_filter([
            'changed' => $change->command !== null,
            'name' => $record === null ? '' : $troops->getEntryLabel($record),
            'records' => $troops->getEntryLabels(),
            'note' => $change->note,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /** @return list<string> The project's enemies, by the name a troop member names them by. */
    private function listEnemyNames(): array
    {
        return array_values(array_map(strval(...), new ReferenceCatalog($this->workspace)->valuesFor('enemies')));
    }

    /**
     * An enemy as Database > Enemies shows it: its terminal sprite, read the
     * way the game reads it, and its battle art composed by the Engine's
     * BattleFormationLayout at battle scale beside the starting party, standing
     * where the battle arranges a troop of this enemy alone on the previewed
     * arena's ground, with what it does not clear. Both read the record as it
     * is now, unsaved edits included. A project without a graphical battle still gets the sprite,
     * with the reason there is no art.
     *
     * @return array<string, mixed>
     * @throws SessionRefusal When the enemy is unknown.
     */
    public function readEnemyPreview(int $index, ?string $arena = null): array
    {
        $record = $this->requireRecordDatabase('enemies')->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('enemies has no record %d.', $index));
        $name = trim((string) $record->get('name'));
        $preview = ['name' => $name, 'sprite' => $this->readEnemySprite($record->get('imagePath')), 'formation' => null, 'formationIssue' => null];

        try {
            $catalog = $this->requireBattleLayoutCatalog('preview enemies at battle scale on');
            // Where the battle stands a troop of this enemy alone, on the previewed arena's ground.
            $composed = $this->composeFormation($catalog, $arena, [['enemyId' => $name, 'slot' => null]]);
            [$formation, $view, $clearance] = $composed;
            $preview['formation'] = [...$view, 'members' => [[
                'enemy' => $name,
                'placement' => null,
                'place' => ['x' => $formation->staging->enemyPlaces[0]->x, 'depth' => $formation->staging->enemyPlaces[0]->depth],
                'battler' => self::describeFormationBattler($formation->enemies[0], $clearance['enemies'][0]),
            ]]];
        } catch (SessionRefusal $refusal) {
            $preview['formationIssue'] = $refusal->getMessage();
        }

        return $preview;
    }

    /**
     * An actor as Database > Actors shows it in battle: its art composed by
     * the Engine's BattleFormationLayout at battle scale in the lead party
     * slot, the rest of the starting party beside it for comparison. It reads
     * the art as it is now, unsaved edits included. An actor without a
     * stable id has no battle art to bind, and says so.
     *
     * @return array{name: string, identity: ?string, formation: ?array<string, mixed>, formationIssue: ?string}
     * @throws SessionRefusal When the actor is unknown.
     */
    public function readActorPreview(int $index, ?string $arena = null): array
    {
        $actor = array_values($this->workspace->actorDatabase->getActors())[$index]
            ?? throw new SessionRefusal(sprintf('actors has no record %d.', $index));
        $identity = $actor->hasDefinitionId() ? $actor->getDefinitionId() : null;
        $preview = ['name' => $actor->getName(), 'identity' => $identity, 'formation' => null, 'formationIssue' => null];
        if ($identity === null) {
            $preview['formationIssue'] = 'This actor has no stable id yet, which its battle art is bound to. Repair actor identities first.';

            return $preview;
        }
        try {
            [, $view] = $this->composeFormation($this->requireBattleLayoutCatalog('preview actors at battle scale on'), $arena, [], $identity);
            $preview['formation'] = [...$view, 'members' => []];
        } catch (SessionRefusal $refusal) {
            $preview['formationIssue'] = $refusal->getMessage();
        }

        return $preview;
    }

    /**
     * An enemy's terminal sprite rows, read by the game's own rule
     * (`Graphics/Enemies/<imagePath>.txt`), never from outside that folder.
     *
     * @return array{lines?: list<string>, issue?: string}
     */
    private function readEnemySprite(mixed $imagePath): array
    {
        return new EnemySprites($this->workspace->projectRoot)->readSprite($imagePath);
    }

    /**
     * The project's battle presentation, when it lays battles out on a
     * graphical canvas: its presentation code, with the battle art bound as
     * data as it is now, unsaved edits included, so a preview shows the art
     * being set. `$drags` lays ground fields and footing polygons over
     * arenas' own, by arena id, as a drag stands them, without writing them.
     *
     * @param array<string, array{ground: array<string, mixed>, footing: array<string, mixed>}> $drags
     */
    private function requireBattleLayoutCatalog(string $purpose, array $drags = []): BattlePresentationCatalog
    {
        $assets = $this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets';
        try {
            $catalog = BattlePresentationCatalog::loadCode($assets);
            $bindings = $catalog === null ? null : $this->readProposedBattlerBindings();
            if ($catalog !== null && $bindings !== null) {
                $catalog = $catalog->bindBattlers(BattlerBindings::getFromArray($bindings, $assets));
            }
            $arenas = $catalog === null ? null : $this->workspace->catalogs->readData(BattleArenaBindings::FILE)['payload'];
            foreach ($drags as $id => ['ground' => $ground, 'footing' => $footing]) {
                if (! is_array($arenas) || ! is_array($arenas['arenas'][$id] ?? null)) {
                    continue;
                }
                foreach (['ground' => $ground, 'footing' => $footing] as $key => $dragged) {
                    $declared = $arenas['arenas'][$id][$key] ?? [];
                    if ($dragged !== []) {
                        $arenas['arenas'][$id][$key] = [...(is_array($declared) ? $declared : []), ...$dragged];
                    }
                }
            }
            if ($catalog !== null && is_array($arenas)) {
                $catalog = $catalog->bindArenas(BattleArenaBindings::getFromArray($arenas));
            }
        } catch (\Throwable $error) {
            throw new SessionRefusal('The battle presentation cannot be read: ' . $error->getMessage(), previous: $error);
        }
        if ($catalog?->ui === null) {
            throw new SessionRefusal(sprintf('This project has no graphical battle layout to %s.', $purpose));
        }

        return $catalog;
    }

    /**
     * The battler bindings file as a save would write it now: the file as
     * saved, with every battle art category's unsaved edits folded in. Null
     * when the project binds no battle art as data.
     *
     * @return array<array-key, mixed>|null
     */
    private function readProposedBattlerBindings(): ?array
    {
        $payload = $this->workspace->catalogs->readData(BattlerBindings::FILE)['payload'];

        return is_array($payload) ? $payload : null;
    }

    /**
     * What converting a legacy cell-frame animation to a timeline would
     * touch: its frames and cues, its current battle bindings, and the
     * battle and field consumers that play it now.
     *
     * @return array<string, mixed> As {@see LegacyAnimationConversion::describe()}.
     * @throws SessionRefusal When the record is unknown or has nothing to convert.
     */
    public function describeAnimationConversion(int $index): array
    {
        try {
            return LegacyAnimationConversion::describe($this->workspace, $this->requireAnimationId($index));
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
    }

    /**
     * Converts a legacy cell-frame animation to a timeline with the timing
     * its consumer needs, asked first and written as one undo step: the new
     * timeline and, when a battle is to play it, the record naming it. The
     * question carries each file as it would be written. Nothing supplies a
     * rate; the author's cadence, ticks and rest frame are the timeline's.
     *
     * @param 'battle_phase'|'fixed' $cadence
     * @param 'sourceEffect'|'targetEffect'|null $binding
     * @return array<string, mixed> The question with `preview` (path to source), or the write's result.
     * @throws SessionRefusal When the conversion cannot be made as asked, edits are pending, or the files cannot be written.
     */
    public function convertAnimation(int $index, string $timelineId, string $cadence, ?int $fps, int $ticksPerFrame,
        int $restFrame, bool $includeFlash, ?string $binding, ?string $answer = null, ?string $confirm = null): array
    {
        $id = $this->requireAnimationId($index);
        try {
            $plan = LegacyAnimationConversion::plan($this->workspace, $id, $timelineId, EffectCadence::parse($cadence),
                $fps, $ticksPerFrame, $restFrame, $includeFlash, $binding);
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        $root = rtrim($this->workspace->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $relative = static fn(string $path): string => str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
        $paths = array_map($relative, $plan->getChangedPaths());
        $name = strval($this->requireRecordDatabase('animations')->getRecordByIndex($index)?->get('name'));
        $required = new SourceSetRequired($plan, sprintf('Convert %s to timeline %s', $name, $timelineId), 'this animation conversion',
            sprintf('Convert %s to the timeline %s, writing %s?', $name, $timelineId, implode(' and ', $paths)), $paths);
        $result = $this->writeSourceSet($required, $answer, fn(): array => $this->requireCategory('animations')->getRecordLabels(), $confirm);
        if ($answer === null) {
            $result['preview'] = array_combine($paths, array_values($plan->getProposedSources()));
        }

        return $result;
    }

    /** @throws SessionRefusal When the animations category has no record there. */
    private function requireAnimationId(int $index): int
    {
        $id = $this->requireRecordDatabase('animations')->getRecordByIndex($index)?->get('id');

        return is_int($id) ? $id : throw new SessionRefusal(sprintf('animations has no record %d with an id.', $index));
    }

    /**
     * Where an actor's or enemy's battle art is set: its record in the
     * battle art category when it has one, and otherwise whether the
     * project's presentation code binds it, which the editor leaves to the
     * code rather than rewriting program-shaped source.
     *
     * @param 'actors'|'enemies' $side
     * @return array{category: string, index: ?int, owner: 'data'|'code'|null, note?: string}
     * @throws SessionRefusal When the side is unknown or the presentation cannot be read.
     */
    public function describeBattlerArt(string $side, string $identity): array
    {
        $category = match ($side) {
            'actors' => 'battler_actors',
            'enemies' => 'battler_enemies',
            default => throw new SessionRefusal(sprintf('Battle art is bound to actors or enemies, not %s.', $side)),
        };
        $database = $this->requireRecordDatabase($category);
        foreach ($database->getRecords() as $index => $record) {
            if ($record->getDisplayValue('identity') === $identity) {
                return ['category' => $category, 'index' => $index, 'owner' => 'data'];
            }
        }
        try {
            $code = BattlePresentationCatalog::loadCode($this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets');
        } catch (\Throwable $error) {
            throw new SessionRefusal('The battle presentation cannot be read: ' . $error->getMessage(), previous: $error);
        }
        $party = $side === 'actors';
        $owned = $code !== null && in_array($identity, [
            ...array_keys($party ? $code->actors : $code->enemies),
            ...array_keys($party ? $code->actorPoses : $code->enemyPoses),
            ...array_keys(($party ? $code->scale?->actors : $code->scale?->enemies) ?? []),
        ], true);

        $described = ['category' => $category, 'index' => null, 'owner' => $owned ? 'code' : null];
        if ($code === null) {
            $described['note'] = 'This project has no graphical battle to set art for.';
        } elseif ($owned) {
            $described['note'] = sprintf('%s binds this art in code, which the editor does not rewrite. Once it moves to %s, it is set here.',
                BattlePresentationCatalog::FILE, BattlerBindings::FILE);
        }

        return $described;
    }

    /**
     * Composes enemies with the starting party over an arena, and describes
     * what every formation view shares: the canvas and the picture of it the
     * battle draws at rest (background and battlers, cropped and scaled as
     * the battle draws them, for the frontend to paint with the renderer),
     * the arenas with the one previewed and its backgrounds, and the party
     * in its slots. Also returns
     * the Engine's clearance diagnostics for party and enemies, by position,
     * which include each battler's own.
     *
     * @param list<array{enemyId: string, slot: ?BattleStageSlot}> $enemies
     * @return array{0: BattleFormationLayout, 1: array<string, mixed>, 2: array{party: list<list<string>>, enemies: list<list<string>>}}
     */
    private function composeFormation(BattlePresentationCatalog $catalog, ?string $arena, array $enemies, ?string $lead = null): array
    {
        $assetRoot = $this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets';
        $names = [];
        foreach ($this->workspace->actorDatabase->getActors() as $actor) {
            $names[$actor->getDefinitionId()] = $actor->getName();
        }
        [$partyIds, $partySource] = $this->readPreviewParty();
        if ($lead !== null) {
            $partyIds = [$lead, ...array_filter($partyIds, static fn(string $id): bool => $id !== $lead)];
        }
        // A theme formation holds so many; without one the battle arranges the whole party.
        $partyIds = array_values($catalog->ui->partySlots === [] ? $partyIds : array_slice($partyIds, 0, count($catalog->ui->partySlots)));

        // Each enemy stands where its habitat lives, as in battle.
        $enemies = array_map(fn(array $entry): array => ['habitat' => $this->readEnemyHabitat($entry['enemyId']), ...$entry], $enemies);
        try {
            $formation = BattleFormationLayout::compose($catalog, $arena, $enemies, $partyIds, $assetRoot);
            // The picture is the battle's own canvas at rest, which the frontend paints with the renderer itself.
            $canvas = $formation->composeCanvas()->toArray();
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            throw new SessionRefusal('The formation cannot be composed: ' . $error->getMessage(), previous: $error);
        }
        $clearance = $formation->getClearanceDiagnostics($assetRoot);
        $party = [];
        foreach ($formation->party as $position => $battler) {
            $party[] = ['name' => $names[$partyIds[$position]] ?? $partyIds[$position], ...self::describeFormationBattler($battler, $clearance['party'][$position])];
        }
        $arenas = [];
        foreach ($formation->arenaChoices as $id => $name) {
            $arenas[] = ['id' => (string) $id, 'name' => $name];
        }
        $chosen = $formation->arena === null ? false : array_search($formation->arena, $catalog->arenas, true);

        return [$formation, [
            'assetRoot' => $assetRoot,
            'canvas' => ['width' => $formation->layout->width, 'height' => $formation->layout->height],
            'picture' => $canvas,
            'arenas' => $arenas,
            'arena' => $chosen === false ? null : (string) $chosen,
            'backgrounds' => array_map(self::describeCanvasImage(...), $formation->backgrounds),
            // Where feet may stand on this arena, so a canvas can show it and turn a dragged point into a place.
            'ground' => [...$formation->staging->ground->toArray(), 'partyCompression' => $formation->staging->partyCompression,
                'enemyCompression' => $formation->staging->enemyCompression],
            // The arena's land and water footing as canvas points; an arena marking neither is land throughout.
            'footing' => ['land' => self::describeFooting($formation->arena?->footing->land ?? []),
                'water' => self::describeFooting($formation->arena?->footing->water ?? [])],
            'party' => $party,
            'partySource' => $partySource,
        ], $clearance];
    }

    /**
     * Where an enemy lives as its record holds it now; land for an enemy
     * the project does not define or a habitat it cannot read, which the
     * enemy's own record reports.
     */
    private function readEnemyHabitat(string $enemy): EnemyHabitat
    {
        $record = array_find($this->requireRecordDatabase('enemies')->getRecords(),
            static fn($entry): bool => $entry->get('name') === $enemy);
        $habitat = $record?->get('habitat');

        return is_string($habitat) ? EnemyHabitat::tryFrom($habitat) ?? EnemyHabitat::LAND : EnemyHabitat::LAND;
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon
     * @return list<array{x: float, y: float}>
     */
    private static function describeFooting(array $polygon): array
    {
        return array_map(static fn(array $point): array => ['x' => $point[0], 'y' => $point[1]], $polygon);
    }

    /**
     * The party a battle preview stands beside its enemies: the battle
     * test's when it sets one, as the battle test will fight with it, else
     * the starting party; and which it is. A battle test that cannot be read
     * is named, not quietly replaced.
     *
     * @return array{0: list<string>, 1: string}
     */
    private function readPreviewParty(): array
    {
        $starting = array_values(array_filter($this->readStartingPartyReferences(), is_string(...)));
        try {
            $setup = ProjectBattleTest::fromArray($this->readBattleTestEntry())->setup;
        } catch (InvalidArgumentException) {
            return [$starting, 'starting party (the battle test cannot be read)'];
        }

        return $setup === null ? [$starting, 'starting party']
            : [array_map(static fn($member): string => $member->actorId, $setup->members), 'battle test'];
    }

    /**
     * @param list<string> $diagnostics What the Engine reports about it in this formation.
     * @return array<string, mixed> Where a battler stands and is drawn, its art, its body at battle scale and what it does not clear.
     */
    private static function describeFormationBattler(BattleFormationBattler $battler, array $diagnostics): array
    {
        $bounds = $battler->bounds;

        return [
            'ground' => ['x' => $battler->ground->x, 'y' => $battler->ground->y],
            'bounds' => ['x' => $bounds->x, 'y' => $bounds->y, 'width' => $bounds->width, 'height' => $bounds->height],
            'image' => $battler->image === null ? null : self::describeCanvasImage($battler->image),
            'bodySpan' => $battler->bodySpan,
            'horizontal' => $battler->horizontal,
            'diagnostics' => array_values(array_map(strval(...), $diagnostics)),
            // What is drawn where it meets the surface, as the area a canvas draws it in, and how far its body rises or sinks.
            'contact' => self::describeContact($battler),
            'offset' => $battler->offset,
        ];
    }

    /** @return array{kind: string, x: float, y: float, width: float, height: float, opacity: float}|null */
    private static function describeContact(BattleFormationBattler $battler): ?array
    {
        $drawn = $battler->contactComposites[0] ?? $battler->contactImages[0] ?? null;
        if ($drawn === null) { return null; }
        $destination = $drawn->destination;

        return ['kind' => $battler->contact->value, 'x' => $destination->x, 'y' => $destination->y,
            'width' => $destination->width, 'height' => $destination->height, 'opacity' => $drawn->opacity];
    }

    /** @return array<string, mixed> An image's asset, where it is drawn and the part of the asset it shows. */
    private static function describeCanvasImage(CanvasImage $image): array
    {
        $destination = $image->destination;

        return array_filter([
            'asset' => $image->asset,
            'x' => $destination->x, 'y' => $destination->y, 'width' => $destination->width, 'height' => $destination->height,
            'source' => $image->sourceRect === null ? null : [
                'x' => $image->sourceRect->x, 'y' => $image->sourceRect->y,
                'width' => $image->sourceRect->width, 'height' => $image->sourceRect->height,
            ],
            'flipX' => $image->flipX ?: null,
            'flipY' => $image->flipY ?: null,
            // Only this part shows, as a sunk body shows only above its surface line.
            'clip' => $image->clipRect === null ? null : ['x' => $image->clipRect->x, 'y' => $image->clipRect->y,
                'width' => $image->clipRect->width, 'height' => $image->clipRect->height],
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * A database category's records, as its list shows them, whether they
     * can be edited and have unsaved changes, and which record operations
     * the category takes.
     *
     * @return array{category: string, editable: bool, readOnly: ?string, dirty: bool, canCreate: bool, canDuplicate: bool, canDelete: bool, canReorder: bool, records: list<string>}
     * @throws SessionRefusal When the category is unknown.
     */
    public function listDatabaseRecords(string $category): array
    {
        $database = $this->requireCategory($category);

        return [
            'category' => $category,
            'editable' => $database->isEditable(),
            'readOnly' => $database->getReadOnlyReason(),
            // What its Save writes is unsaved: its records, or what their pages edit and save with them.
            'dirty' => $this->isCategoryUnsaved($category),
            // Every listed category with something to save, so a list of them can say which.
            'unsavedCategories' => $this->listUnsavedCategories(),
            'canCreate' => $database->supportsRecordCreation(),
            'canDuplicate' => $database->supportsRecordDuplication(),
            'canDelete' => $database->supportsRecordDeletion(),
            // Only where the file keeps the order; a move elsewhere is refused with why.
            'canReorder' => $database->supportsDurableReorder(),
            'records' => $database->getRecordLabels(),
        ];
    }

    /**
     * One record's rows in a frame of its commands, described as the
     * inspector's are, with the `key` an edit names a row by. At the root
     * (`frame` []) the rows are the record's fields and its list's entries;
     * a row that opens a command frame carries that `frame`, read again with
     * it to edit the commands inside. A row of an item the record's lists
     * hold carries `item` and `itemNoun`, and `childNoun` when the item holds
     * a list of its own (a route's steps, a choice's options): add and
     * remove act on it. The heading of a list beside the record's own (a
     * quest's reward items) carries `listHeading`: adding to it adds that
     * list's first entry. `listNoun` names an entry of the list an add with
     * no row goes to. At the record itself, `panes` are the summaries the
     * terminal shows beside it ({@see RecordPanes}): a class's curves, a
     * skill's effects, a quest's rewards, each a title and its lines.
     *
     * @param array<int|string, mixed> $frame The frame; [] for the record itself.
     * @return array{category: string, index: int, frame: list<int|string>, frameLabel: ?string, editable: bool, readOnly: ?string, listNoun: ?string, rows: list<array<string, mixed>>, panes: list<array{title: string, lines: list<string>}>}
     * @throws SessionRefusal When the category, record or frame is unknown.
     */
    public function readDatabaseRecord(string $category, int $index, array $frame = []): array
    {
        $database = $this->requireCategory($category);
        $frame = self::requireFrame($frame, 'database.record');
        $fields = $this->collectRecordFields($database, $index, $frame);

        return [
            'category' => $category,
            'index' => $index,
            'frame' => $frame,
            'frameLabel' => $database->describeFrame($frame),
            'editable' => $database->isEditable(),
            'readOnly' => $database->getReadOnlyReason(),
            // What an entry of the list an add with no row goes to is called:
            // the record's own list, or the open frame's.
            'listNoun' => $database->getListNoun($frame),
            // A row that names a field keeps its key whether or not it can be
            // edited; a read-only one is an `info` row the edit refuses.
            'rows' => $this->describePlacementRows(array_map(static fn(array $field): array => self::describeRecordRow(
                $database,
                $index,
                $frame,
                $field,
                isset($field['field']) ? 'record' : null,
            ), $fields), ['kind' => 'database', 'category' => $category, 'index' => $index]),
            'panes' => $frame === [] ? array_values(RecordPanes::describe($this->workspace, $category, $index)) : [],
        ];
    }

    /**
     * Applies one record row's edit as one undo step. The row is found again
     * by its key among the record's current rows in its frame. A value its
     * field cannot take is refused with what is wrong with it.
     *
     * @param array<string, mixed> $key The row's key, as `database.record` gave it.
     * @return array{changed: bool, records: list<string>, note?: string} Whether it changed, the labels afterwards (a rename shows), and what else it did.
     * @throws SessionRefusal When the category or record is unknown or read-only, the row is gone or read-only, or the value is refused.
     */
    public function applyDatabaseRecord(string $category, int $index, array $key, string $value, ?string $answer = null, ?string $confirm = null): array
    {
        $database = $this->requireCategory($category);
        $frame = self::requireFrame($key['frame'] ?? [], 'database.record');
        $fieldId = $key['field'] ?? null;
        // Field ids are unique within a frame, so the field and frame name the row.
        $field = is_string($fieldId) ? array_find($this->collectRecordFields($database, $index, $frame),
            static fn(array $candidate): bool => ($candidate['field'] ?? null) === $fieldId) : null;

        if (! is_string($fieldId) || $field === null) {
            throw new SessionRefusal('That row is no longer in the record; read it again.');
        }
        if (($field['editable'] ?? true) === false || self::describeRow([...$field, 'target' => 'record'])['kind'] === 'info') {
            throw new SessionRefusal(sprintf('%s cannot be edited here.', trim((string) ($field['label'] ?? 'That row'))));
        }

        try {
            $change = $this->changeRecord(static fn(): RecordChange => $database->applyField(
                $index, $frame, $fieldId, $value, (string) ($field['label'] ?? 'Database field'),
            ));
        } catch (SourceSetRequired $required) {
            return $this->writeSourceSet($required, $answer, fn(): array => $this->requireCategory($category)->getRecordLabels(), $confirm);
        }

        return array_filter(['changed' => $change->command !== null, 'records' => $database->getRecordLabels(), 'note' => $change->note],
            static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Sets several of a record's rows as one action, undone and redone
     * together: what one gesture changes, such as a click that sets the
     * ground point of two images showing the same stance. Each value goes
     * through the record's own field rules, as one row's edit does; if any is
     * refused, those already made are taken back and nothing is recorded.
     * An edit that needs a file set written at once is not made this way.
     *
     * @param list<array{key: array<string, mixed>, value: string}> $changes Each row's key, as `database.record` gave it, and its value.
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When a row is gone or read-only, or a value is refused; nothing is changed.
     */
    public function applyDatabaseRecordValues(string $category, int $index, array $changes, string $label): array
    {
        $database = $this->requireCategory($category);
        $made = [];
        try {
            foreach ($changes as $change) {
                $key = is_array($change) && is_array($change['key'] ?? null) ? $change['key'] : throw new SessionRefusal('Each change needs the row key database.record gave.');
                $frame = self::requireFrame($key['frame'] ?? [], 'database.record');
                $fieldId = $key['field'] ?? null;
                $field = is_string($fieldId) ? array_find($this->collectRecordFields($database, $index, $frame),
                    static fn(array $candidate): bool => ($candidate['field'] ?? null) === $fieldId) : null;
                if (! is_string($fieldId) || $field === null || ($field['editable'] ?? true) === false) {
                    throw new SessionRefusal('That row is no longer in the record, or cannot be edited; read it again.');
                }
                try {
                    $applied = $database->applyField($index, $frame, $fieldId, strval($change['value'] ?? ''), (string) ($field['label'] ?? $label));
                } catch (RecordRefusal $refusal) {
                    throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
                } catch (SourceSetRequired $required) {
                    throw new SessionRefusal(sprintf('%s writes several files at once; set it on its own.', trim((string) ($field['label'] ?? 'That row'))), previous: $required);
                }
                if ($applied->command !== null) {
                    $made[] = $applied->command;
                }
            }
        } catch (SessionRefusal $refusal) {
            foreach (array_reverse($made) as $command) {
                $command->undo();
            }
            throw $refusal;
        }
        if ($made !== []) {
            $this->history->record(new CommandGroup($label, $made));
        }

        return ['changed' => $made !== [], 'records' => $database->getRecordLabels()];
    }

    /**
     * Asks before writing a file set an edit needs at once, then writes it
     * as one undo step: the workspace is reloaded from the written files, so
     * every map and category reads afresh. The question carries the plan's
     * fingerprint, and writing requires it back: a plan made again from
     * other choices or changed files is refused, so what is written is
     * exactly what the author was shown.
     *
     * @param Closure(): list<string> $records The category's labels afterwards.
     * @param string|null $confirm The fingerprint the question gave, when answering write.
     * @return array{status: 'question', question: string, answers: list<array{key: string, label: string, description: string}>, confirm: string}|array{changed: bool, records: list<string>, reloaded?: true}
     * @throws SessionRefusal When an answer is not one offered, the plan is not the one shown, edits are pending, or the files cannot be written.
     */
    private function writeSourceSet(SourceSetRequired $required, ?string $answer, Closure $records, ?string $confirm = null): array
    {
        if ($answer === null) {
            return [
                'status' => 'question',
                'question' => $required->question,
                'answers' => [
                    ['key' => 'cancel', 'label' => 'Cancel', 'description' => 'Leave all files unchanged.'],
                    ['key' => 'write', 'label' => sprintf('Write %d files now', count($required->paths)),
                        'description' => sprintf('Writes %s now, not on Save. Undo restores the files.', implode(', ', $required->paths))],
                ],
                // Answering write names this, so what is written is what was asked about.
                'confirm' => $required->plan->getFingerprint(),
            ];
        }
        if (! in_array($answer, ['write', 'cancel'], true)) {
            throw new SessionRefusal(sprintf('Answer write or cancel, not %s.', $answer));
        }
        if ($answer === 'cancel') {
            return ['changed' => false, 'records' => $records()];
        }
        // The plan made now must be the one the author was shown: the same
        // choices over the same files. Anything else is asked again.
        if ($confirm === null || ! hash_equals($required->plan->getFingerprint(), $confirm)) {
            throw new SessionRefusal(sprintf('The files or choices changed since %s was shown, so nothing was written. Review it again.', $required->subject));
        }
        if ($this->workspace->hasUnsavedChanges()) {
            throw new SessionRefusal(sprintf('Save or undo pending edits before %s. No files were changed.', $required->subject));
        }

        $command = new SourceSetCommand($required->label, $required->subject, $required->plan, $this->workspace,
            fn(): ProjectWorkspace => $this->workspace,
            function (ProjectWorkspace $workspace): void { $this->workspace = $workspace; },
        );

        try {
            $command->execute();
        } catch (RuntimeException $failure) {
            throw new SessionRefusal($failure->getMessage(), previous: $failure);
        }
        $this->history->record($command);

        return ['changed' => true, 'records' => $records(), 'reloaded' => true];
    }

    /**
     * Adds an item at a record row as one undo step: after the item the row
     * belongs to, or with `$child` at the end of what that item holds (a
     * route's steps, a choice's options). A key of `{frame}` alone adds at
     * the end of that frame, or of the record's own list.
     *
     * @param array<string, mixed> $key A row's key, as `database.record` gave it, or `{frame}` alone.
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When the category, record or frame is unknown or read-only, or the row's item cannot take it.
     */
    public function addDatabaseItem(string $category, int $index, array $key, bool $child = false): array
    {
        $database = $this->requireCategory($category);
        $frame = self::requireFrame($key['frame'] ?? [], 'database.record');
        $fieldId = $key['field'] ?? null;

        if ($fieldId !== null && ! is_string($fieldId)) {
            throw new SessionRefusal('A row key names its field as a string, as database.record gave it.');
        }

        $change = $this->changeRecord(static fn(): RecordChange => $database->addItem($index, $frame, $fieldId, $child));

        return ['changed' => $change->command !== null, 'records' => $database->getRecordLabels()];
    }

    /**
     * Removes the item a record row belongs to (an entry or command with
     * everything under it, a route step, line or lane, a choice's option
     * with its arm) as one undo step; a row that belongs to none changes
     * nothing.
     *
     * @param array<string, mixed> $key A row's key, as `database.record` gave it.
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When the category, record or frame is unknown or read-only, or the key names no row.
     */
    public function removeDatabaseItem(string $category, int $index, array $key): array
    {
        $database = $this->requireCategory($category);
        $frame = self::requireFrame($key['frame'] ?? [], 'database.record');
        $fieldId = $key['field'] ?? null;

        if (! is_string($fieldId)) {
            throw new SessionRefusal('Name the row whose item to remove by its key, as database.record gave it.');
        }

        $change = $this->changeRecord(static fn(): RecordChange => $database->removeItem($index, $frame, $fieldId));

        return ['changed' => $change->command !== null, 'records' => $database->getRecordLabels()];
    }

    /**
     * Creates a blank record at the end of a category, as one undo step:
     * for an identity when one is given (an enemy's battle art, made for
     * that enemy), refused when that identity already has one.
     *
     * @return array{index: int, records: list<string>} The new record's index, and the labels afterwards.
     * @throws SessionRefusal When the category is unknown, read-only or takes no new records.
     */
    public function createDatabaseRecord(string $category, ?string $identity = null): array
    {
        $database = $this->requireCategory($category);
        $change = $this->changeRecord(static fn(): RecordChange => $database->createRecord($identity));

        return ['index' => (int) $change->index, 'records' => $database->getRecordLabels()];
    }

    /**
     * Duplicates a record below itself under a fresh identity, as one undo
     * step. A file-identified record requires an unused filename identity; a
     * payload identity is made unique. Without one, the copy gets a unique identity.
     *
     * @return array{index: int, records: list<string>} The copy's index, and the labels afterwards.
     * @throws SessionRefusal When the category or record is unknown or read-only, or the record cannot be duplicated.
     */
    public function duplicateDatabaseRecord(string $category, int $index, ?string $identity = null): array
    {
        $database = $this->requireCategory($category);
        $change = $this->changeRecord(static fn(): RecordChange => $database->duplicateRecord($index, $identity));

        return ['index' => (int) $change->index, 'records' => $database->getRecordLabels()];
    }

    /**
     * Retires a skill from everything that names it, at once and as one undo
     * step: what classes teach, what actors know, can learn, attack or counter
     * with, what enemies use, what skills and states counter with. With a
     * replacement, each moves onto it (a list already holding it drops the old
     * entry) and saves that knew the skill read the replacement; without one,
     * each is removed and saves drop the skill. The skill is then free to
     * delete. Asks before writing, as every file set does.
     *
     * @param string|null $replacement The skill that takes its place, or null to remove it from everything.
     * @return array<string, mixed> The question, or what was written.
     * @throws SessionRefusal When a skill is unknown, the replacement is itself, the two are a spell and an ability, it is a summon's action, or edits are pending.
     */
    public function retireSkill(int $index, ?string $replacement = null, ?string $answer = null, ?string $confirm = null): array
    {
        $this->requireCategory('skills');
        $database = $this->workspace->getRecordDatabase('skills') ?? throw new SessionRefusal('This project has no skills.');
        $record = $database->getRecordByIndex($index) ?? throw new SessionRefusal(sprintf('There is no skill %d.', $index));
        $name = strval($record->get('name'));
        $isMagic = strval($record->get('kind')) === 'magic';
        if ($replacement !== null) {
            $target = array_find($database->getRecords(), static fn($candidate): bool => strval($candidate->get('name')) === $replacement)
                ?? throw new SessionRefusal(sprintf('There is no skill named %s to take its place.', $replacement));
            if ($target === $record) {
                throw new SessionRefusal(sprintf('%s cannot take its own place.', $name));
            }
            if ($isMagic !== (strval($target->get('kind')) === 'magic')) {
                throw new SessionRefusal(sprintf('%s and %s must both be spells or both abilities: saves keep the two apart.', $name, $replacement));
            }
        }
        if ($this->workspace->loadSkillCatalog()->isSummonAction($name)) {
            throw new SessionRefusal(sprintf('%s is a summon\'s action and follows its summon.', $name));
        }
        if ($answer === null && $this->workspace->hasUnsavedChanges()) {
            throw new SessionRefusal(sprintf('Save or undo pending edits before retiring %s. No files were changed.', $name));
        }
        try {
            $plan = SkillReferences::planRetirement($this->workspace->projectRoot, $this->workspace->projectId, $name, $replacement, $isMagic);
        } catch (RuntimeException $failure) {
            throw new SessionRefusal($failure->getMessage(), previous: $failure);
        }
        if ($plan === null) {
            return ['changed' => false, 'records' => $this->requireCategory('skills')->getRecordLabels()];
        }
        $paths = array_map(fn(string $path): string => substr($path, strlen($this->workspace->projectRoot) + 1), $plan->getChangedPaths());
        $required = $replacement === null
            ? new SourceSetRequired($plan, sprintf('Retire %s', $name), 'this skill retirement',
                sprintf('Remove %s from everything that names it? Saves that knew it drop it.', $name), $paths)
            : new SourceSetRequired($plan, sprintf('Retire %s into %s', $name, $replacement), 'this skill retirement',
                sprintf('Move everything that names %s onto %s, and let saves read %s as %s?', $name, $replacement, $name, $replacement), $paths);

        return $this->writeSourceSet($required, $answer, fn(): array => $this->requireCategory('skills')->getRecordLabels(), $confirm);
    }
    /**
     * A Mastery Map as the graphical page draws it: the regions, and each node
     * with its place on the map and what it builds on, and the project's words
     * for the price and level each card names.
     *
     * @return array{regions: list<array{id: string, name: string}>, nodes: list<array<string, mixed>>, issue: ?string,
     *     pointsLabel: string, levelLabel: string}
     * @throws SessionRefusal When the map is unknown.
     */
    public function readMasteryPage(int $index): array
    {
        try {
            // Each card names its price and level in the project's own words for Mastery points and the level.
            $term = fn(string $path): string => $this->workspace->config?->getTermLabel($path) ?? Vocabulary::DEFAULTS[$path];

            return [...$this->masteryAuthoring->readPage($this->requireRecordDatabase(MasteryFields::MAPS),
                $this->workspace->getRecordDatabase(MasteryFields::REGIONS), $this->workspace->getRecordDatabase(MasteryFields::BRANCHES), $index),
                'pointsLabel' => $term(MasteryVocabulary::POINTS), 'levelLabel' => $term('stats.level_short')];
        } catch (RecordRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
    }

    /**
     * Places a node on its map, as fractions (0 to 1) of the map's width and height, as one undo step.
     *
     * @return array{changed: bool, records: list<string>}
     */
    public function placeMasteryNode(int $index, string $node, float $x, float $y): array
    {
        $maps = $this->requireRecordDatabase(MasteryFields::MAPS);
        $change = $this->changeRecord(fn(): RecordChange => $this->masteryAuthoring->placeNode($maps, $index, $node, $x, $y));

        return ['changed' => $change->command !== null, 'records' => $this->requireCategory(MasteryFields::MAPS)->getRecordLabels()];
    }

    /**
     * Makes one node build on another, or stop doing so, as one undo step.
     *
     * @return array{changed: bool, records: list<string>}
     */
    public function toggleMasteryRequirement(int $index, string $node, string $requires): array
    {
        $maps = $this->requireRecordDatabase(MasteryFields::MAPS);
        $change = $this->changeRecord(fn(): RecordChange => $this->masteryAuthoring->toggleRequirement($maps,
            $this->workspace->getRecordDatabase(MasteryFields::REGIONS), $this->workspace->getRecordDatabase(MasteryFields::BRANCHES), $index, $node, $requires));

        return ['changed' => $change->command !== null, 'records' => $this->requireCategory(MasteryFields::MAPS)->getRecordLabels()];
    }

    /**
     * Moves a character's own skills from their actor file onto their Mastery
     * Map, at once and as one undo step: known skills become nodes known from
     * the start, learnables become nodes costing their EXP requirement, skills
     * their role teaches stay with the role, and the actor's lists are emptied.
     * Asks before writing, as every file set does.
     *
     * @return array<string, mixed> The question, or what was written.
     * @throws SessionRefusal When the actor is unknown, no regions are declared, edits are pending, or a file cannot take it.
     */
    public function adoptMasterySkills(string $actorId, ?string $answer = null, ?string $confirm = null): array
    {
        if ($answer === null && $this->workspace->hasUnsavedChanges()) {
            throw new SessionRefusal(sprintf('Save or undo pending edits before moving %s\'s skills. No files were changed.', $actorId));
        }
        try {
            $adoption = MasteryAdoption::plan($this->workspace->projectRoot, $actorId);
        } catch (RuntimeException $failure) {
            throw new SessionRefusal($failure->getMessage(), previous: $failure);
        }
        if ($adoption === null) {
            return ['changed' => false, 'nodes' => []];
        }
        $paths = array_map(fn(string $path): string => substr($path, strlen($this->workspace->projectRoot) + 1), $adoption['plan']->getChangedPaths());
        $required = new SourceSetRequired($adoption['plan'], sprintf('Move %s\'s skills onto their Mastery Map', $actorId), 'this skill move',
            sprintf('Move %s\'s own skills onto their Mastery Map (%s), and empty the actor\'s skill lists?', $actorId,
                $adoption['nodes'] === [] ? 'no new nodes' : implode(', ', $adoption['nodes'])), $paths);
        $result = $this->writeSourceSet($required, $answer, fn(): array => $this->requireCategory(MasteryFields::MAPS)->getRecordLabels(), $confirm);

        return [...$result, 'nodes' => $adoption['nodes']];
    }

    /**
     * Deletes a record as one undo step; the file changes on save.
     *
     * @return array{index: ?int, records: list<string>} The record to select next (null when none is left), and the labels afterwards.
     * @throws SessionRefusal When the category or record is unknown or read-only, or the category keeps its entries.
     */
    public function deleteDatabaseRecord(string $category, int $index): array
    {
        $database = $this->requireCategory($category);
        $change = $this->changeRecord(static fn(): RecordChange => $database->deleteRecord($index));

        return ['index' => $change->index, 'records' => $database->getRecordLabels()];
    }

    /**
     * Moves a record one place up or down, as one undo step, where the file
     * keeps the order. A move past either end changes nothing.
     *
     * @param string $direction "up" or "down".
     * @return array{index: int, changed: bool, records: list<string>} Where the record is now, whether it moved, and the labels afterwards.
     * @throws SessionRefusal When the category or record is unknown or read-only, the direction is neither, or the file would not keep the order (with why).
     */
    public function moveDatabaseRecord(string $category, int $index, string $direction): array
    {
        $database = $this->requireCategory($category);
        $step = match ($direction) {
            'up' => -1,
            'down' => 1,
            default => throw new SessionRefusal(sprintf('A record moves "up" or "down", not "%s".', $direction)),
        };
        $change = $this->changeRecord(static fn(): RecordChange => $database->moveRecord($index, $step));

        return ['index' => (int) $change->index, 'changed' => $change->command !== null, 'records' => $database->getRecordLabels()];
    }

    /**
     * Saves one database category, backing up the files it overwrites
     * first, as the terminal editor's save does. A category with nothing
     * unsaved writes nothing (`saved` false). No database validation runs on
     * save, so there are no warnings yet.
     *
     * @return array{saved: bool, warnings: list<string>, backupFailures: list<string>}
     * @throws SessionRefusal When the category is unknown or read-only, or its source cannot take the save.
     */
    public function saveDatabase(string $category): array
    {
        $this->refuseDuringChangeSet('Saving a database');
        $database = $this->requireCategory($category);
        if (! $database->isEditable()) {
            throw new SessionRefusal(sprintf('Read-only: %s.', $database->getReadOnlyReason() ?? 'this category cannot be written'));
        }
        // What the page edits beside the record (an enemy's battle art) is
        // saved with it, first, so a save it refuses leaves the page unsaved.
        $embedded = ['saved' => false, 'backupFailures' => []];
        foreach (DatabaseCatalog::getEmbedded() as $definition) {
            if (in_array($category, $definition->hosts, true) && $this->workspace->getRecordDatabase($definition->key)?->isDirty()) {
                $saved = $this->saveDatabase($definition->key);
                $embedded = ['saved' => true, 'backupFailures' => [...$embedded['backupFailures'], ...$saved['backupFailures']]];
            }
        }
        if (! $database->isDirty()) {
            return ['saved' => $embedded['saved'], 'warnings' => [], 'backupFailures' => $embedded['backupFailures']];
        }
        $paths = $database->getBackupPaths();
        $failures = $this->backups->isEnabled() && $paths !== [] ? $this->backups->backup(...$paths)['failed'] : [];

        try {
            $database->save();
        } catch (RecordRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }

        return ['saved' => true, 'warnings' => [], 'backupFailures' => [...$embedded['backupFailures'], ...array_values($failures)]];
    }

    /**
     * Whether a category's Save has something to write: its own records, or
     * what their pages edit beside them and save with them (an enemy's
     * battle art, the battle scale), the same set `saveDatabase` writes.
     */
    private function isCategoryUnsaved(string $category): bool
    {
        if ($this->requireCategory($category)->isDirty()) {
            return true;
        }

        return array_any(DatabaseCatalog::getEmbedded(), fn($definition): bool => in_array($category, $definition->hosts, true)
            && ($this->workspace->getRecordDatabase($definition->key)?->isDirty() ?? false));
    }

    /**
     * The listed Database and cutscene categories with something to save.
     *
     * @return list<string>
     */
    private function listUnsavedCategories(): array
    {
        $keys = [
            ...array_map(static fn($category): string => $category->key, DatabaseCatalog::getGraphicalCategories()),
            ...array_map(static fn(CutsceneType $type): string => $type->getRecordCategory(), CutsceneType::cases()),
        ];

        return array_values(array_filter($keys, function (string $key): bool {
            try {
                return $this->isCategoryUnsaved($key);
            } catch (SessionRefusal) {
                // A category this project cannot open has nothing to save.
                return false;
            }
        }));
    }

    /**
     * Makes one record change, records it, and turns a refusal into the session's.
     *
     * @param callable(): RecordChange $change
     * @throws SessionRefusal
     */
    private function changeRecord(callable $change): RecordChange
    {
        try {
            $applied = $change();
        } catch (RecordRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($applied->command !== null) {
            $this->history->record($applied->command);
        }

        return $applied;
    }

    /**
     * A record's rows in a frame, refusing a record or frame that is gone.
     *
     * @param list<int|string> $frame
     * @return array<int, array<string, mixed>>
     * @throws SessionRefusal
     */
    private function collectRecordFields(DatabaseCategory $database, int $index, array $frame): array
    {
        try {
            return $database->getRecordRows($index, $frame);
        } catch (RecordRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
    }

    /**
     * One row of a record pane (a database record's or an NPC's) as plain
     * data: described as an inspector row, its key naming the frame it lives
     * in, with the frame it opens, its exact multi-line text, and the item
     * it belongs to as the record's own rules locate it.
     *
     * @param array<int, int|string> $frame
     * @param array<string, mixed> $field
     * @param string|null $target The key's target, or null for a row no edit applies through.
     * @return array<string, mixed>
     */
    private static function describeRecordRow(ProjectRecordDatabase|DatabaseCategory $records, int $index, array $frame, array $field, ?string $target): array
    {
        $row = self::describeRow([...$field, 'target' => $target]);

        if (isset($row['key'])) {
            $row['key']['frame'] = $frame;
            $item = $records->locateItem($index, $frame, (string) ($field['field'] ?? ''));

            if ($item !== null) {
                $row['item'] = true;
                $row['itemNoun'] = $item->noun;
                if ($item->kind === RecordItem::LIST) {
                    // A list's heading: entries are added to it, never removed with it.
                    $row['listHeading'] = true;
                }
                if ($item->childNoun !== null) {
                    $row['childNoun'] = $item->childNoun;
                }
            }
        }
        if (is_array($field['frame'] ?? null)) {
            $row['frame'] = array_values($field['frame']);
        }
        $control = MapInspector::findControl($field);
        if ($control?->type === InputControlType::MULTILINE) {
            $row['multiline'] = true;
            $row['value'] = $control->rawValue;
        }

        return $row;
    }

    /**
     * A frame of a record's commands, as a request or a row key names it.
     *
     * @param string $reader What gave the frame, for the refusal.
     * @return list<int|string>
     * @throws SessionRefusal When the frame is not a list of indexes and keys.
     */
    private static function requireFrame(mixed $frame, string $reader): array
    {
        if (! is_array($frame) || ! array_is_list($frame) || ! array_all($frame, static fn(mixed $segment): bool => is_int($segment) || is_string($segment))) {
            throw new SessionRefusal(sprintf('A frame must be a list of indexes and keys, as %s gave it.', $reader));
        }

        return $frame;
    }

    /** Reads a transient inspector for the map-owned declaration. */
    public function readWorldObject(string $mapId, string $id): array
    {
        $map = $this->requireMap($mapId);
        $authoring = $this->createWorldObjectAuthoring($map);
        $index = $authoring->getIndex($id);
        $records = $authoring->getRecords();
        $object = WorldObjectAuthoring::readEntries($map)[$index];
        $issue = $authoring->getSourceIssue();
        $fields = $records->getSettingsFields($index);
        foreach ($fields as &$field) {
            $owner = isset($field['entry'][0]) && str_starts_with($field['field'] ?? '', 'variant')
                ? $object['variants'][$field['entry'][0]] : $object;
            $field = FieldSpriteFields::describeFields([$field], $owner, $map->getAssetRoot())[0];
            // A presented tile is a tile identity of the map's tileset, picked from its palette (`tiles.palette`).
            if (str_starts_with($field['field'] ?? '', 'objectTile') && ($field['sourceKey'] ?? null) === 'tile') { $field['tilePicker'] = true; }
            if ($issue !== null) { $field['editable'] = false; }
        }
        unset($field);
        return ['map' => $mapId, 'revision' => $this->getMapRevision($map), 'event' => null,
            'worldObject' => ['id' => $id, 'variants' => array_column($object['variants'] ?? [], 'id'),
                'anchor' => $object['anchor'], 'covers' => $object['covers'] ?? null,
                WorldObjectTileFields::DATA_KEY => $object[WorldObjectTileFields::DATA_KEY] ?? null, 'issue' => $issue],
            'issue' => $issue,
            'rows' => [...array_map(static fn($field): array => self::describeRecordRow($records, $index, [], $field, 'world-object'), $fields),
                // What the object is on the minimap: a map landmark row, applied through the map's inspector.
                ...array_map(self::describeRow(...), new MapLandmarkFields(new ReferenceCatalog($this->workspace, $map))->describeObjectFields($map, $id))]];
    }

    public function createWorldObject(string $mapId, int $revision, string $id, int $x, int $y, ?string $resource = null, ?string $text = null): array
    {
        return $this->changeWorldObject($mapId, $revision, static fn($authoring): ?Command => $authoring->createObject($id, $x, $y, $resource, $text));
    }

    public function deleteWorldObject(string $mapId, int $revision, string $id): array
    {
        return $this->changeWorldObject($mapId, $revision, static fn($authoring): ?Command => $authoring->deleteObject($id));
    }

    public function applyWorldObject(string $mapId, int $revision, string $id, array $key, string $value): array
    {
        if (($key['target'] ?? null) === MapLandmarkFields::OBJECT_TARGET) {
            return $this->applyWorldObjectLandmark($mapId, $revision, $id, $key, $value);
        }
        $field = is_string($key['field'] ?? null) ? $key['field'] : throw new SessionRefusal('Name a current world-object field.');
        return $this->changeWorldObject($mapId, $revision, static fn($authoring): ?Command => $authoring->applyField($id, $field, $value));
    }

    public function changeWorldObjectItem(string $mapId, int $revision, string $id, array $key, bool $remove): array
    {
        $field = is_string($key['field'] ?? null) ? $key['field'] : throw new SessionRefusal('Name a current world-object list row.');
        return $this->changeWorldObject($mapId, $revision, static fn($authoring): ?Command => $authoring->changeItem($id, $field, $remove));
    }

    public function placeWorldObject(string $mapId, int $revision, string $id, int $x, int $y, bool $coverage = false): array
    {
        return $this->changeWorldObject($mapId, $revision, static fn($authoring): ?Command => $authoring->placeObject($id, $x, $y, $coverage));
    }

    /**
     * Sets the graphical tiles a world object presents on one of the map's tile layers, as one undo step: each cell
     * `[x, y, tile]` on a cell the object owns, `0` showing no tile. The terminal, collision and the layer's own
     * tiles are unchanged.
     *
     * @param list<mixed> $cells
     * @return array{changed: bool, revision: int}
     * @throws SessionRefusal When the map is stale or read-only, or the Engine refuses the declaration.
     */
    public function setWorldObjectTiles(string $mapId, int $revision, string $id, string $layer, array $cells): array
    {
        return $this->changeWorldObject($mapId, $revision, static fn($authoring): ?Command => $authoring->setTiles($id, $layer, $cells));
    }

    /**
     * Stops a world object presenting tiles on cells `[x, y]` of a tile layer, as one undo step.
     *
     * @param list<mixed> $cells
     * @return array{changed: bool, revision: int}
     * @throws SessionRefusal When the map is stale or read-only, or a cell presents no tile of the object.
     */
    public function unsetWorldObjectTiles(string $mapId, int $revision, string $id, string $layer, array $cells): array
    {
        return $this->changeWorldObject($mapId, $revision, static fn($authoring): ?Command => $authoring->unsetTiles($id, $layer, $cells));
    }

    /**
     * Moves a world object by a step with what shows it in the terminal, as
     * one undo step: its anchor, the cells it covers, and the glyphs on those
     * cells of its covered layer, with the tiles those glyphs draw. The cells
     * it leaves become blank. Furniture moved this way leaves no glyph
     * behind; an event that describes it moves with `event.move`. Each glyph
     * keeps the piece it plays where it stands, read from its tiles, and a
     * glyph no piece draws there (the object's own art presents it) moves
     * without tiles. A role still in doubt is asked about as painting asks,
     * and answered in `$choices` by glyph.
     *
     * @param array<string, ?string> $choices
     * @return array{changed: bool, revision: int}|array{status: 'question', glyph: string, roles: list<array{key: string, label: string}>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the object is unknown, or a destination cell
     *     leaves the map, holds another glyph or is covered by another object.
     */
    public function moveWorldObject(string $mapId, int $revision, string $id, int $dx, int $dy, array $choices = []): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        if ($dx === 0 && $dy === 0) {
            return ['changed' => false, 'revision' => $this->getMapRevision($map)];
        }
        try {
            $entry = array_find(WorldObjectAuthoring::readEntries($map), static fn(array $entry): bool => ($entry['id'] ?? null) === $id)
                ?? throw new InvalidArgumentException(sprintf('%s has no world object %s.', $mapId, $id));
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }

        $commands = [];
        $layerName = $entry['covers']['layer'] ?? null;
        if (is_string($layerName)) {
            $layerId = array_find($map->getLayers(), static fn(array $layer): bool => $layer['name'] === $layerName)['id']
                ?? throw new SessionRefusal(sprintf('World object %s covers layer %s, which %s does not have.', $id, $layerName, $mapId));
            $from = array_map(static fn(array $cell): array => [(int) $cell[0], (int) $cell[1]], $entry['covers']['cells']);
            $to = array_map(static fn(array $cell): array => [$cell[0] + $dx, $cell[1] + $dy], $from);
            $writes = [];
            $assigned = [];
            foreach ($from as $cell) {
                if (! in_array($cell, $to, true)) {
                    $writes[] = ['x' => $cell[0], 'y' => $cell[1], 'symbol' => ' ', 'color' => ''];
                }
            }
            foreach ($to as $index => [$x, $y]) {
                if ($x < 0 || $y < 0 || $x >= $map->getWidth() || $y >= $map->getHeight()) {
                    throw new SessionRefusal(sprintf('Moving %s by %d, %d reaches %d, %d, outside %s.', $id, $dx, $dy, $x, $y, $mapId));
                }
                if (! in_array([$x, $y], $from, true) && ($held = $map->getLayerSymbol($layerId, $x, $y)) !== ' ') {
                    throw new SessionRefusal(sprintf('%d, %d on the %s layer holds %s; clear it before moving %s there.', $x, $y, $layerName, $held, $id));
                }
                [$fromX, $fromY] = $from[$index];
                $writes[] = ['x' => $x, 'y' => $y, 'symbol' => $map->getLayerSymbol($layerId, $fromX, $fromY),
                    'color' => $map->getLayerColor($layerId, $fromX, $fromY) ?? ''];
                $role = CanvasEditor::findPlayedRole($map, $layerId, $fromX, $fromY);
                if ($role !== null) {
                    $assigned["{$x},{$y}"] = $role->key;
                } else {
                    // A glyph no piece's tiles draw here (the object's own art presents it) moves without tiles.
                    $choices = [$map->getLayerSymbol($layerId, $fromX, $fromY) => null, ...$choices];
                }
            }
            try {
                $plan = CanvasEditor::plan($map, $layerId, $writes, $choices, true, assigned: $assigned);
                if ($plan !== null && $plan['unresolved'] !== []) {
                    $glyph = (string) array_key_first($plan['unresolved']);

                    return ['status' => 'question', 'glyph' => $glyph, 'roles' => array_map(
                        static fn(PieceRole $role): array => ['key' => $role->key, 'label' => $role->label],
                        $plan['unresolved'][$glyph],
                    )];
                }
                $glyphs = CanvasEditor::apply($map, $layerId, $plan['writes'] ?? $writes, 'Move world object', $plan['tiles'] ?? [])['command'];
            } catch (MapSourceRefusal $refusal) {
                throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
            }
            if ($glyphs !== null) {
                $commands[] = $glyphs;
            }
        }
        try {
            $object = $this->createWorldObjectAuthoring($map)->moveObject($id, $dx, $dy);
        } catch (InvalidArgumentException|\RuntimeException $error) {
            foreach (array_reverse($commands) as $command) {
                $command->undo();
            }
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        if ($object !== null) {
            $commands[] = $object;
        }
        if ($commands !== []) {
            $this->history->record(new CommandGroup('Move world object', $commands));
        }

        return ['changed' => $commands !== [], 'revision' => $this->getMapRevision($map)];
    }

    public function moveWorldObjectVariant(string $mapId, int $revision, string $id, string $variantId, int $offset): array
    {
        return $this->changeWorldObject($mapId, $revision, static fn($authoring): ?Command => $authoring->moveVariant($id, $variantId, $offset));
    }

    /**
     * One exchange with a map's editing canvas: the window's answers in, what it should apply out. The canvas draws
     * the map as it stands in memory, unsaved edits included, through the Engine's frame composer, at a variant
     * selection and preview time. One canvas lives per open map at its size; a resize, or the map read afresh when a
     * file set written at once reloads the project, starts a new one, whose new session id tells the window to start
     * again, and answers meant for an earlier canvas are not applied to it.
     *
     * @param list<string> $events The window's answers, as renderer event lines.
     * @param array<string, string> $variants
     * @return array<string, mixed>
     * @throws SessionRefusal When the map is unknown or cannot be composed, or the window reports an error.
     */
    public function exchangeAuthoringCanvas(string $mapId, array $events, array $variants = [], float $seconds = 0, ?string $sessionId = null): array
    {
        $map = $this->requireMap($mapId);
        $host = $this->authoringCanvases[$mapId] ?? null;
        if ($host === null || ! $host->drawsMap($map)) {
            $host?->dispose();
            $host = $this->authoringCanvases[$mapId] = new \Ichiloto\Editor\Field\AuthoringCanvasHost($map, uniqid('canvas-', true));
        }
        try {
            $messages = $host->exchange($sessionId === $host->sessionId ? $events : [], $variants, $seconds);
        } catch (InvalidArgumentException|MapSourceRefusal|\Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        $source = $host->getSource();

        return ['sessionId' => $host->sessionId, 'map' => $map->mapId, 'revision' => $this->getMapRevision($map),
            'assetRoot' => $map->getAssetRoot(), 'layerIds' => $map->getPresentationLayerIds(),
            'grid' => ['columns' => $host->grid->columns, 'rows' => $host->grid->rows,
                'cellWidth' => $host->grid->cellWidth, 'cellHeight' => $host->grid->cellHeight],
            'messages' => $messages, 'assetPaths' => $source?->assetPaths ?? [], 'diagnostics' => $source?->diagnostics ?? [],
            'graphicsIssue' => $source?->graphicsIssue];
    }

    /**
     * Renders a map for review as the game draws it, both ways, as it stands,
     * unsaved edits included: the Terminal field's picture and a still of the
     * graphical field, for the whole map or a region, with the player or an
     * actor on a cell when the view asks ({@see MapRenderer}). Nothing is
     * written.
     *
     * @throws SessionRefusal When the view names an unknown map, actor or cells off the map, or the map cannot be loaded.
     */
    public function renderMapReview(MapRenderView $view): MapRendering
    {
        try {
            return new MapRenderer($this->workspace)->render($view);
        } catch (InvalidArgumentException|RuntimeException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
    }

    /**
     * Renders a map, or a rectangle of it, for review as the game draws it,
     * unsaved edits included, and writes the review into a folder
     * ({@see MapReviewWriter}): the Terminal picture beside the graphical
     * still, on one page that carries its stills. With a cell, the player
     * stands there facing the heading given, for door checks. The project's
     * files never change.
     *
     * @param array{0: int, 1: int, 2: int, 3: int}|null $region x, y, width and height in map cells; null is the whole map.
     * @param array{0: int, 1: int}|null $player The cell the player stands on, or null for nobody.
     * @return array{page: string, files: array<string, string>, diagnostics: list<string>}
     * @throws SessionRefusal When the view cannot be rendered or the folder cannot be written.
     */
    public function writeMapReview(string $mapId, string $directory, ?array $region = null, ?array $player = null, ?string $heading = null): array
    {
        try {
            $subject = $player === null ? null : new MapRenderSubject($player[0], $player[1],
                $heading === null ? \Ichiloto\Engine\Core\Enumerations\MovementHeading::SOUTH : MapRenderSubject::readHeading($heading));
            $view = new MapRenderView($mapId, $region === null ? null : ['x' => $region[0], 'y' => $region[1], 'width' => $region[2], 'height' => $region[3]],
                $subject);
            $rendering = $this->renderMapReview($view);
            $writer = new MapReviewWriter($directory, new FieldStillRaster(rtrim($this->workspace->projectRoot, '/') . '/assets'));
            $written = $writer->writeRendering($rendering);

            return ['page' => $writer->writeReviewPage($view->label), 'files' => $written['files'], 'diagnostics' => $written['diagnostics']];
        } catch (InvalidArgumentException|RuntimeException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
    }

    /**
     * Closes a map's editing canvas, releasing its signs' prepared labels and its renderer client.
     *
     * @return array{closed: bool}
     */
    public function closeAuthoringCanvas(string $mapId): array
    {
        $host = $this->authoringCanvases[$mapId] ?? null;
        $host?->dispose();
        unset($this->authoringCanvases[$mapId]);

        return ['closed' => $host !== null];
    }

    /**
     * Applies a world object's minimap row, found again among its current rows, as one undo step.
     *
     * @param array<string, mixed> $key
     * @return array{changed: bool, revision: int}
     * @throws SessionRefusal When the map is stale, the row is gone or the edit is refused.
     */
    private function applyWorldObjectLandmark(string $mapId, int $revision, string $id, array $key, string $value): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        ksort($key);
        $field = array_find(new MapLandmarkFields(new ReferenceCatalog($this->workspace, $map))->describeObjectFields($map, $id), static function (array $candidate) use ($key): bool {
            $candidateKey = MapInspector::describeRowKey($candidate);
            if ($candidateKey !== null) { ksort($candidateKey); }
            return $candidateKey === $key;
        }) ?? throw new SessionRefusal('That row is no longer in the inspector; read it again.');
        $inspector = $this->createMapInspector($map);
        $command = $this->runEdit(static fn(): ?Command => $inspector->apply($map, $field, $value));

        return ['changed' => $command !== null, 'revision' => $this->getMapRevision($map)];
    }

    private function createWorldObjectAuthoring(ProjectMap $map): WorldObjectAuthoring
    {
        return new WorldObjectAuthoring($map, new ReferenceCatalog($this->workspace, $map));
    }

    private function changeWorldObject(string $mapId, int $revision, callable $edit): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        try { $command = $edit($this->createWorldObjectAuthoring($map)); }
        catch (InvalidArgumentException|\RuntimeException $error) { throw new SessionRefusal($error->getMessage(), previous: $error); }
        if ($command !== null) { $this->history->record($command); }
        return ['changed' => $command !== null, 'revision' => $this->getMapRevision($map)];
    }

    /**
     * Creates a fixed NPC at a tile under the id given, or one derived from its name.
     *
     * @param string|null $id The stable id; null derives one from the name.
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the tile is outside the map or taken, or the id is not one or is taken.
     */
    public function createNpc(string $mapId, int $revision, int $x, int $y, string $name, ?string $id = null): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);

        return $this->applyNpcChange($map, fn(NpcAuthoring $authoring): NpcChange => $authoring->create($map, $x, $y, $name, $id));
    }

    /**
     * Moves an NPC's anchor to a tile; a move to where it stands changes nothing.
     *
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the NPC is unknown, or the tile is outside the map or taken.
     */
    public function moveNpc(string $mapId, int $revision, int $index, int $x, int $y): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);

        return $this->applyNpcChange($map, fn(NpcAuthoring $authoring): NpcChange => $authoring->move($map, $index, $x, $y));
    }

    /**
     * Duplicates an NPC under a fresh id, appended; `index` is the copy's.
     *
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or the NPC is unknown.
     */
    public function duplicateNpc(string $mapId, int $revision, int $index): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);

        return $this->applyNpcChange($map, fn(NpcAuthoring $authoring): NpcChange => $authoring->duplicate($map, $index));
    }

    /**
     * Deletes an NPC nothing names; `index` is null afterwards and `id` the deleted NPC's.
     *
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the NPC is unknown, or something names it (each on a line of its own).
     */
    public function deleteNpc(string $mapId, int $revision, int $index): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);

        return $this->applyNpcChange($map, fn(NpcAuthoring $authoring): NpcChange => $authoring->delete($map, $index));
    }

    /**
     * Gives an NPC authored without a stable id the one given, or one from
     * its name. An existing id changes through the NPC's Id row
     * ({@see applyNpc()}), which refuses while anything names it.
     *
     * @param string|null $id The stable id; null derives one from the name.
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the NPC is unknown, it already has an id, or the id is not one or is taken.
     */
    public function assignNpcId(string $mapId, int $revision, int $index, ?string $id = null): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);

        return $this->applyNpcChange($map, fn(NpcAuthoring $authoring): NpcChange => $authoring->assignId($map, $index, $id));
    }

    /**
     * One NPC's rows in a frame of its commands, described as the
     * inspector's are ({@see readInspector()}), with the `key` an edit names
     * a row by. At the root (`frame` []) the rows are its fields under
     * headings, with its identity notes and dialogue variants; a row that
     * opens a script frame carries that `frame`, read again with it to edit
     * the commands inside. Multi-line text carries `multiline` and its exact
     * text as `value`. Visibility conditions and completion writes are
     * `info` here: they are built in the terminal's own editors for now.
     *
     * @param array<int|string, mixed> $frame The frame; [] for the NPC itself.
     * @return array{map: string, revision: int, index: int, frame: list<int|string>, frameLabel: ?string, npc: array<string, mixed>, rows: list<array<string, mixed>>}
     * @throws SessionRefusal When the map, NPC or frame is unknown.
     */
    public function readNpc(string $mapId, int $index, array $frame = []): array
    {
        $frame = self::requireNpcFrame($frame);
        $map = $this->requireMap($mapId);
        $inspector = new NpcInspector($map, graphical: true, references: new ReferenceCatalog($this->workspace, $map));
        $npc = $map->getNpcs()->get($index) ?? throw new SessionRefusal(sprintf('%s has no NPC %d.', $mapId, $index));
        $fields = $this->collectNpcFields($inspector, $index, $frame);
        $rows = array_map(static fn(array $field): array => self::describeNpcRow($inspector->records(), $index, $field, $frame), $fields);

        return [
            'map' => $mapId,
            'revision' => $this->getMapRevision($map),
            'index' => $index,
            'frame' => $frame,
            'frameLabel' => $frame === [] ? null : $inspector->records()->describeFramePath($frame),
            'npc' => [
                'index' => $index,
                'id' => $npc->getId(),
                'name' => $npc->getName(),
                'x' => $npc->getX(),
                'y' => $npc->getY(),
                'sprite' => $npc->getVisibleSprite(),
            ],
            'rows' => $this->describePlacementRows($frame === [] ? self::insertNpcFieldSheetRow($rows, $npc, $map->getAssetRoot()) : $rows,
                ['kind' => 'npc', 'map' => $mapId, 'index' => $index]),
        ];
    }

    /** The field id of an NPC's graphical character sheet, a row only the graphical editor shows. */
    private const string NPC_FIELD_SHEET = 'sprites2d.sheet';

    /**
     * An NPC's rows with its graphical character sheet after its terminal
     * appearance: a picture picked from the project's images, set through
     * {@see NpcAuthoring::setFieldSheet()}. The terminal editor's rows are
     * its own; graphical art never changes them.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function insertNpcFieldSheetRow(array $rows, ProjectNpc $npc, string $assetRoot): array
    {
        $sprites = $npc->toArray()['sprites2d'] ?? null;
        $row = self::describeRow([
            'label' => '  Field Sheet',
            'field' => self::NPC_FIELD_SHEET,
            'value' => is_array($sprites) && is_string($sprites['sheet'] ?? null) ? $sprites['sheet'] : '',
            'reference' => 'png_assets',
            'noneLabel' => 'None: the glyph shows',
            'target' => 'npc',
        ]);
        $row['key'] = [...$row['key'], 'frame' => []];
        $sheetRows = [];
        if ($sprites !== null) {
            $row['imagePreview'] = CharacterSheetPreview::describe($sprites, $assetRoot);
            if (is_array($sprites) && is_string($sprites['sheet'] ?? null) && $sprites['sheet'] !== '') {
                $characterIndex = $sprites['index'] ?? 0;
                $layer = $sprites['layer'] ?? CharacterSheet::DEFAULT_LAYER;
                $indexValue = is_scalar($characterIndex) ? strval($characterIndex) : '(invalid index)';
                $layerValue = is_scalar($layer) ? strval($layer) : '(invalid layer)';
                $sheetRows = [
                    self::describeRow(['label' => '  Sheet Character Index', 'field' => 'sprites2d.index',
                        'value' => $indexValue, 'target' => 'npc',
                        'options' => CharacterSheetPreview::getIndexOptions($sprites['sheet'])]),
                    self::describeRow(['label' => '  Graphical Layer', 'field' => 'sprites2d.layer',
                        'value' => $layerValue, 'target' => 'npc',
                        'control' => new InputControl(InputControlType::INTEGER, $layerValue)]),
                ];
                foreach ($sheetRows as &$sheetRow) {
                    $sheetRow['key']['frame'] = [];
                }
                unset($sheetRow);
            }
        }
        $appearance = array_keys(array_filter($rows, static fn(array $candidate): bool =>
            in_array($candidate['key']['field'] ?? null, ['sprite', 'sprites.north', 'sprites.south', 'sprites.east', 'sprites.west'], true)));
        $at = $appearance === [] ? count($rows) : max($appearance) + 1;

        return [...array_slice($rows, 0, $at), $row, ...$sheetRows, ...array_slice($rows, $at)];
    }

    /**
     * Applies one NPC row's edit as one undo step. The row is found again by
     * its key among the NPC's current rows. A rename carries an id its old
     * name derived along (`followedId`) unless something names it
     * (`idReferences`). The Id row sets the id itself, refused while
     * anything names the current one.
     *
     * @param array<string, mixed> $key The row's key, as `readNpc` gave it.
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the row is gone or read-only, or the edit is refused.
     */
    public function applyNpc(string $mapId, int $revision, int $index, array $key, string $value): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $inspector = new NpcInspector($map, graphical: true, references: new ReferenceCatalog($this->workspace, $map));
        $frame = self::requireNpcFrame($key['frame'] ?? []);
        $fieldId = $key['field'] ?? null;
        if ($fieldId === self::NPC_FIELD_SHEET && $frame === []) {
            return $this->applyNpcChange($map,
                static fn(NpcAuthoring $authoring): NpcChange => $authoring->setFieldSheet($map, $index, $value === '' ? null : $value));
        }
        if (in_array($fieldId, ['sprites2d.index', 'sprites2d.layer'], true) && $frame === []) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer === false) {
                throw new SessionRefusal('A sheet character index or graphical layer must be an integer.');
            }

            return $this->applyNpcChange($map, static fn(NpcAuthoring $authoring): NpcChange => $fieldId === 'sprites2d.index'
                ? $authoring->setFieldSheetIndex($map, $index, $integer)
                : $authoring->setFieldSheetLayer($map, $index, $integer));
        }
        // Field ids are unique within a frame, so the field and frame name the row.
        $field = is_string($fieldId) ? array_find($this->collectNpcFields($inspector, $index, $frame),
            static fn(array $candidate): bool => ($candidate['field'] ?? null) === $fieldId) : null;

        if ($field === null) {
            throw new SessionRefusal('That row is no longer on the NPC; read it again.');
        }
        if (self::describeNpcRow($inspector->records(), $index, $field, $frame)['kind'] === 'info') {
            throw new SessionRefusal(sprintf('%s cannot be edited here.', trim((string) ($field['label'] ?? 'That row'))));
        }

        return $this->applyNpcChange($map,
            static fn(NpcAuthoring $authoring): NpcChange => $authoring->applyField($inspector, $index, $frame, $field, $value));
    }

    /**
     * Adds an item at an NPC row as one undo step: in a script frame a
     * command after the row's (at the end when the key names no field) or a
     * route step under its route; at the root a line in the row's dialogue
     * variant, or a new variant. The key is a row's, or `{frame}` alone.
     *
     * @param array<string, mixed> $key
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or the NPC or frame is unknown.
     */
    public function addNpcItem(string $mapId, int $revision, int $index, array $key): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $inspector = new NpcInspector($map, graphical: true, references: new ReferenceCatalog($this->workspace, $map));
        $frame = self::requireNpcFrame($key['frame'] ?? []);
        $fieldId = is_string($key['field'] ?? null) ? $key['field'] : '';

        return $this->applyNpcChange($map,
            static fn(NpcAuthoring $authoring): NpcChange => $authoring->addSubItem($inspector, $index, $frame, $fieldId));
    }

    /**
     * Moves the page an NPC row belongs to one place earlier (step -1) or
     * later (step 1), as one undo step: talking runs the first available
     * page. A row of no page changes nothing.
     *
     * @param array<string, mixed> $key A row's key, as `readNpc` gave it.
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or the NPC is unknown.
     */
    public function moveNpcItem(string $mapId, int $revision, int $index, array $key, int $step): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $inspector = new NpcInspector($map, graphical: true, references: new ReferenceCatalog($this->workspace, $map));
        $fieldId = is_string($key['field'] ?? null) ? $key['field'] : '';

        if ($step !== -1 && $step !== 1) {
            throw new SessionRefusal('A page moves one place: step -1 or 1.');
        }

        return $this->applyNpcChange($map,
            static fn(NpcAuthoring $authoring): NpcChange => $authoring->moveSubItem($inspector, $index, $fieldId, $step));
    }

    /**
     * Removes the item an NPC row belongs to (a route step, command,
     * dialogue line or variant) as one undo step; a row that belongs to none
     * changes nothing.
     *
     * @param array<string, mixed> $key A row's key, as `readNpc` gave it.
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or the NPC or frame is unknown.
     */
    public function removeNpcItem(string $mapId, int $revision, int $index, array $key): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $inspector = new NpcInspector($map, graphical: true, references: new ReferenceCatalog($this->workspace, $map));
        $frame = self::requireNpcFrame($key['frame'] ?? []);
        $fieldId = is_string($key['field'] ?? null) ? $key['field'] : '';

        return $this->applyNpcChange($map,
            static fn(NpcAuthoring $authoring): NpcChange => $authoring->removeSubItem($inspector, $index, $frame, $fieldId));
    }

    /**
     * Makes one NPC change, records it, and says what it did.
     *
     * @param callable(NpcAuthoring): NpcChange $change
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the change is refused or the map's source cannot take it.
     */
    private function applyNpcChange(ProjectMap $map, callable $change): array
    {
        try {
            $applied = $change(new NpcAuthoring($this->workspace));
        } catch (NpcRefusal $refusal) {
            throw new SessionRefusal(implode("\n", [$refusal->getMessage(), ...$refusal->details]), previous: $refusal);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($applied->command !== null) {
            $this->history->record($applied->command);
        }

        return [
            'revision' => $this->getMapRevision($map),
            'changed' => $applied->command !== null,
            'index' => $applied->index,
            'id' => $applied->npc?->getId(),
            'followedId' => $applied->followedId,
            'idReferences' => $applied->idReferences,
        ];
    }

    /**
     * An NPC's rows in a frame, refusing an NPC or frame that is gone.
     *
     * @param list<int|string> $frame
     * @return array<int, array<string, mixed>>
     * @throws SessionRefusal
     */
    private function collectNpcFields(NpcInspector $inspector, int $index, array $frame): array
    {
        if ($inspector->map->getNpcs()->get($index) === null) {
            throw new SessionRefusal(sprintf('%s has no NPC %d.', $inspector->map->mapId, $index));
        }

        return $inspector->getFields($index, $frame)
            ?? throw new SessionRefusal(sprintf('%s is no longer there; read the NPC again.', $inspector->records()->describeFramePath($frame)));
    }

    /**
     * One NPC row as plain data, as any record pane's row is
     * ({@see describeRecordRow()}). The id note is read here; assigning an
     * id is its own request.
     *
     * @param array<string, mixed> $field
     * @param list<int|string> $frame
     * @return array<string, mixed>
     */
    private static function describeNpcRow(ProjectRecordDatabase $records, int $index, array $field, array $frame): array
    {
        $fieldId = $field['field'] ?? null;
        // Keyed whether or not it can be edited; a read-only row is `info`.
        $named = is_string($fieldId) && $fieldId !== NpcInspector::ASSIGN_ID_FIELD;

        $row = self::describeRecordRow($records, $index, $frame, $field, $named ? 'npc' : null);

        // A page's own rows (not its lines) can move it: talking runs the first available page, so order is meaning.
        $item = $named && $frame === [] ? $records->locateItem($index, [], (string) $fieldId) : null;
        if ($item?->kind === RecordItem::ENTRY && $item->framePath === [] && $item->childIndex === null && $item->listKey === null) {
            $row['ordered'] = true;
        }

        return $row;
    }

    /**
     * A frame of an NPC's commands, as a request or a row key names it.
     *
     * @return list<int|string>
     * @throws SessionRefusal When the frame is not a list of indexes and keys.
     */
    private static function requireNpcFrame(mixed $frame): array
    {
        return self::requireFrame($frame, 'readNpc');
    }

    /**
     * Undoes the last change, wherever it was made.
     *
     * @return array{label: ?string, maps: list<string>, revisions: array<string, int>, databases: list<string>} What was undone, the maps it changed and their revisions now, and the database categories it changed.
     */
    public function undo(): array
    {
        if ($this->changeSet !== null && $this->history->count() <= $this->changeSet['steps']) {
            throw new SessionRefusal('Undo would reach back before the change set began; cancel the change set instead.');
        }

        return $this->traverseHistory(fn(): ?Command => $this->history->undo());
    }

    /**
     * Redoes the last undone change.
     *
     * @return array{label: ?string, maps: list<string>, revisions: array<string, int>, databases: list<string>}
     */
    public function redo(): array
    {
        return $this->traverseHistory(fn(): ?Command => $this->history->redo());
    }

    /**
     * Saves one map through its transaction, after the checks validation
     * has for it, which never block this save: a map with errors (defects
     * the player would meet) saves as a draft, and validation fails until
     * they are fixed. {@see commitChanges()} is the write that refuses them.
     * When the save would take cells existing saves may stand on, nothing is
     * written until the author has seen where those saves move: the first
     * call asks, and `write` with the question's `confirm` saves the map and
     * the relocation step together.
     *
     * @return array{saved: string, warnings: list<string>, errors: list<array<string, mixed>>, backupFailures: list<string>, relocated: list<string>}
     *     |array{status: 'question', map: string, question: string, answers: list<array{key: string, label: string, description: string}>,
     *         relocations: list<array{map: string, cells: list<array{int, int}>, to: array{int, int}}>, moves: list<string>,
     *         contentVersion: int, confirm: string}
     *     |array{status: 'cancelled', map: string, saved: null}
     * @throws SessionRefusal When the map is unknown or refuses to save, the answer is not write or cancel, or the
     *     relocations changed since they were shown.
     */
    public function saveMap(string $mapId, ?string $answer = null, ?string $confirm = null): array
    {
        $this->refuseDuringChangeSet('Saving a map');
        $map = $this->requireMap($mapId);
        try {
            $relocation = SaveRelocationPlanner::plan($map, $this->workspace->projectRoot);
        } catch (RuntimeException $failure) {
            throw new SessionRefusal($failure->getMessage(), previous: $failure);
        }
        if ($relocation !== null) {
            // Saves standing where the map is now solid or taken by an NPC move with the save, once the author has seen where.
            if ($answer === null) {
                return ['status' => 'question', 'map' => $mapId, 'question' => sprintf('Save %s and move existing saves off cells it makes solid or puts an NPC on?', $mapId),
                    'answers' => [
                        ['key' => 'cancel', 'label' => 'Cancel', 'description' => 'Save nothing.'],
                        ['key' => 'write', 'label' => 'Save and move saves', 'description' => sprintf('Saves the map and writes %s. Undo removes the save step.', SaveManifestStep::PATH)],
                    ],
                    'relocations' => $relocation->relocations, 'moves' => $relocation->describeMoves(), 'contentVersion' => $relocation->contentVersion,
                    'confirm' => $relocation->getFingerprint()];
            }
            if ($answer === 'cancel') {
                return ['status' => 'cancelled', 'map' => $mapId, 'saved' => null];
            }
            if ($answer !== 'write') {
                throw new SessionRefusal(sprintf('Answer write or cancel, not %s.', $answer));
            }
            if ($confirm === null || ! hash_equals($relocation->getFingerprint(), $confirm)) {
                throw new SessionRefusal('The map or the save manifest changed since the save relocations were shown, so nothing was written. Review them again.');
            }
        }
        $issues = MapValidator::validate($map, $this->getMapsById(), MapValidator::readInventoryCatalog($this->workspace));
        $failures = [];
        $step = $relocation?->apply();

        try {
            $saved = $map->save(function (string ...$paths) use (&$failures): void {
                if ($this->backups->isEnabled() && $paths !== []) {
                    $failures = $this->backups->backup(...$paths)['failed'];
                }
            });
        } catch (MapSourceRefusal|FileSetTransactionFailure $refusal) {
            // The map is not saved, so neither is the step that moves saves for it.
            $relocation?->revert();
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($step !== null) {
            $this->history->record($step);
        }

        return ['saved' => $saved, 'warnings' => MapValidator::describeIssues(MapValidator::selectSeverity($issues, Severity::WARNING)),
            'errors' => array_map(static fn(Issue $issue): array => $issue->describe(), MapValidator::selectSeverity($issues, Severity::ERROR)),
            'backupFailures' => array_values($failures), 'relocated' => $relocation?->describeMoves() ?? []];
    }

    /**
     * Plays the game in the background, in its own graphical window, as the terminal editor's playtest does: from the
     * project's own root, writing nothing into it, with its player data in a data root of its own holding the
     * author's player settings (volume and mute included) and none of their saves. It starts on the open map at a cell, or at the game's title with the project's own
     * starting position. The game reads maps from disk, so a playtest is refused while a map it plays from is unsaved:
     * the start map, or, from the title, any map.
     *
     * @param PlaytestStart $start Where the playtest begins; from the title the map and cell are not used.
     * @param string|null $renderer One of {@see describePlaytestOptions()}'s renderers; null is its default.
     * @return array<string, mixed> The playtest, as {@see describePlaytest()}.
     * @throws SessionRefusal When the map is unknown or unsaved, a playtest is running, the renderer cannot be
     *     launched from here, or it cannot start.
     */
    public function startPlaytest(?string $mapId, ?int $x, ?int $y, PlaytestStart $start = PlaytestStart::CELL, ?string $renderer = null): array
    {
        if ($this->playtest?->isRunning() === true) {
            throw new SessionRefusal('A playtest is already running; stop it or close its window first.');
        }
        $options = $this->describePlaytestOptions();
        $renderer ??= $options['defaultRenderer'];
        $offered = array_find($options['renderers'], static fn(array $choice): bool => $choice['id'] === $renderer);
        if ($offered === null) {
            throw new SessionRefusal(sprintf('A playtest cannot use the %s renderer; choose one of %s.', $renderer,
                implode(', ', array_column($options['renderers'], 'id'))));
        }
        if (! $offered['available']) {
            throw new SessionRefusal(sprintf('%s cannot be used for a playtest: %s.', $offered['label'], $offered['reason'] ?? 'it is unavailable'));
        }
        if ($start === PlaytestStart::CELL) {
            if ($mapId === null || $x === null || $y === null) {
                throw new SessionRefusal('Choose the cell to start on, or play from the title.');
            }
            $map = $this->requireMap($mapId);
            if ($map->isDirty()) {
                throw new SessionRefusal(sprintf('Save %s before playtesting it; the game reads the map on disk.', $mapId));
            }
            if ($x < 0 || $y < 0 || $x >= $map->getWidth() || $y >= $map->getHeight()) {
                throw new SessionRefusal(sprintf('%d, %d is outside %s.', $x, $y, $mapId));
            }
        } else {
            $unsaved = array_keys(array_filter($this->getMapsById(), static fn(ProjectMap $map): bool => $map->isDirty()));
            if ($unsaved !== []) {
                throw new SessionRefusal(sprintf('Save %s before playing from the title; the game reads every map on disk.', implode(', ', $unsaved)));
            }
        }
        $launch = null;
        try {
            $launch = $start === PlaytestStart::CELL
                ? PlaytestLaunch::fromCell($this->workspace->projectRoot, (string) $mapId, (int) $x, (int) $y)
                : PlaytestLaunch::fromTitle($this->workspace->projectRoot);
            $launcher = PlaytestLauncher::discover(projectRoot: $this->workspace->projectRoot);
            $this->playtest = $launcher->start($launch, $renderer);
            $this->playtestStart = $start;
        } catch (RuntimeException $error) {
            $launch?->destroy();
            throw new SessionRefusal(sprintf('The playtest could not start: %s', $error->getMessage()), previous: $error);
        }

        return $this->describePlaytest();
    }

    /**
     * Where a playtest from this editor can begin, and the renderers it can launch with. The first is Automatic, the
     * default: the console picks GPUI when it is installed and usable, otherwise the terminal, in a terminal window
     * of its own. Each installed renderer follows, with whether it is usable and why not when it is not. When the
     * console cannot list them, Automatic is still offered and the issue says why.
     *
     * @return array{starts: list<string>, renderers: list<array{id: string, label: string, available: bool, reason: ?string}>,
     *     defaultRenderer: string, issue: ?string}
     */
    public function describePlaytestOptions(): array
    {
        $issue = null;
        try {
            $listed = PlaytestLauncher::discover(projectRoot: $this->workspace->projectRoot)->listRenderers($this->workspace->projectRoot);
        } catch (RuntimeException $error) {
            $listed = ['renderers' => [], 'preferredId' => null];
            $issue = $error->getMessage();
        }
        $preferred = array_find($listed['renderers'], static fn(array $renderer): bool => $renderer['id'] === $listed['preferredId']);
        $automatic = ['id' => PlaytestLauncher::AUTOMATIC,
            'label' => $preferred === null ? 'Automatic' : sprintf('Automatic (%s)', $preferred['displayName']), 'available' => true, 'reason' => null];

        return [
            'starts' => array_map(static fn(PlaytestStart $start): string => $start->value, PlaytestStart::cases()),
            'renderers' => [$automatic, ...array_map(static fn(array $renderer): array => ['id' => $renderer['id'],
                'label' => $renderer['displayName'], 'available' => $renderer['available'], 'reason' => $renderer['reason']], $listed['renderers'])],
            'defaultRenderer' => PlaytestLauncher::AUTOMATIC,
            'issue' => $issue,
        ];
    }

    /**
     * Whether a playtest is running, where it started, and how the last one
     * ended: its exit code and, when it failed, the end of its output.
     *
     * @return array{running: bool, start: ?string, map: ?string, x: ?int, y: ?int, stopped: bool, exitCode: ?int, log: ?string}
     */
    public function describePlaytest(): array
    {
        $run = $this->playtest;
        $running = $run?->isRunning() ?? false;
        $exitCode = $running ? null : $run?->getExitCode();

        return [
            'running' => $running,
            'start' => $run === null ? null : $this->playtestStart->value,
            'map' => $run?->launch->mapId,
            'x' => $run?->launch->x,
            'y' => $run?->launch->y,
            'stopped' => $run?->wasStopped() ?? false,
            'exitCode' => $exitCode,
            // Only a run that ended on its own with an error says why.
            'log' => $run !== null && ! $running && ! $run->wasStopped() && $exitCode !== 0 ? $run->readLogTail() : null,
        ];
    }

    /**
     * Ends a running playtest, game window and all.
     *
     * @return array<string, mixed> The playtest, as {@see describePlaytest()}.
     */
    public function stopPlaytest(): array
    {
        $this->playtest?->stop();

        return $this->describePlaytest();
    }

    /**
     * The project's battle test as the Battle Test dialog edits it, as RPG
     * Maker's Troops > Battle Test does: the troop to preselect, the party
     * and the arena, kept in system data as the Engine's ProjectBattleTest.
     * With no party set the starting party stands in, as it will in the
     * battle, and says so. Each member comes with what it may be given (the
     * Engine's BattleTestChoices, the same as the in-game arena offers) and
     * the setup with the Engine's own problems. Reads the record as it is
     * now, unsaved edits included, or describes a draft the dialog is
     * editing without writing it.
     *
     * @param array<string, mixed>|null $draft An entry to describe instead of the record's.
     * @return array<string, mixed>
     * @throws SessionRefusal When the project has no system settings.
     */
    public function describeBattleTest(?array $draft = null): array
    {
        $entry = $draft ?? $this->readBattleTestEntry();
        $view = [
            'battleTest' => $entry,
            'troops' => array_values(array_map(strval(...), $this->listDatabaseRecords('troops')['records'])),
            'arenas' => $this->readArenaChoices(),
            'actors' => array_map(static fn($actor): array => ['id' => $actor->getDefinitionId(), 'name' => $actor->getName()],
                array_values(array_filter($this->workspace->actorDatabase->getActors(), static fn($actor): bool => $actor->hasDefinitionId()))),
            'maxMembers' => BattleTestSetup::MAX_MEMBERS,
            'troop' => null, 'arena' => null, 'source' => 'starting party', 'members' => [], 'problems' => [], 'issue' => null,
        ];
        try {
            $test = ProjectBattleTest::fromArray($entry);
        } catch (InvalidArgumentException $invalid) {
            return [...$view, 'issue' => 'The battle test cannot be read: ' . $invalid->getMessage()];
        }

        return ProjectDirectoryContext::run($this->workspace->projectRoot, function () use ($view, $test): array {
            $actors = $this->workspace->actorDatabase->createActorStore();
            $items = $this->loadItemStore();
            $skills = $this->workspace->loadSkillCatalog();
            $summons = new SummonCutsceneLibrary($this->workspace->projectRoot . '/assets/Cutscenes/Summons');
            $view = [...$view, 'troop' => $test->troop, 'source' => $test->setup === null ? 'starting party' : 'battle test'];
            try {
                $setup = $test->createSetup($actors, $this->readStartingPartyReferences(), $this->workspace->getSystemField('battle.partySize') ?? \Ichiloto\Engine\Entities\Party::BATTLE_SIZE);
            } catch (InvalidArgumentException $invalid) {
                return [...$view, 'arena' => $test->arena, 'issue' => $invalid->getMessage()];
            }
            $choices = new BattleTestChoices($actors, $items, new BattleTestLoadoutCatalog($skills, $summons));
            $names = array_column($view['actors'], 'name', 'id');
            $members = [];
            foreach ($setup->members as $index => $member) {
                $described = ['actor' => $member->actorId, 'name' => $names[$member->actorId] ?? $member->actorId, 'level' => $member->level,
                    'commands' => $member->commands === null ? null : array_column($member->commands, 'value'),
                    'skills' => $member->skills, 'summons' => $member->summons, 'maxLevel' => null, 'equipment' => [], 'choices' => null];
                if ($actors->get($member->actorId) !== null) {
                    $described['maxLevel'] = $choices->getMaxLevel($member->actorId);
                    $described['equipment'] = array_map(static fn(string $slot): array => ['slot' => $slot, 'item' => $member->equipment[$slot] ?? null,
                        'choices' => $choices->getEquipmentChoices($member->actorId, $slot)], $choices->getSlotNames($member->actorId));
                    $described['choices'] = array_combine(['commands', 'skills', 'magic', 'summons'], array_map(
                        static fn(string $field): array => $choices->getLoadoutChoices($setup, $index, $field), ['commands', 'skills', 'magic', 'summons']));
                }
                $members[] = $described;
            }

            return [...$view, 'arena' => $setup->arena, 'battleSize' => $setup->battleSize, 'members' => $members,
                'problems' => $setup->getProblems($actors, $items, $skills, $summons)];
        });
    }

    /**
     * Sets the project's battle test, as the Battle Test dialog applies it:
     * the whole entry written to system data through the record service in
     * one undo step, read the Engine's way first so a shape it would not
     * read is refused before anything changes. An empty entry removes it.
     *
     * @param array<string, mixed> $battleTest As the Engine's ProjectBattleTest writes it.
     * @return array<string, mixed> The battle test, as {@see describeBattleTest()}.
     * @throws SessionRefusal When the entry is not a battle test.
     */
    public function applyBattleTest(array $battleTest): array
    {
        try {
            $entry = ProjectBattleTest::fromArray($battleTest)->toArray();
        } catch (InvalidArgumentException $invalid) {
            throw new SessionRefusal('That is not a battle test: ' . $invalid->getMessage(), previous: $invalid);
        }
        $row = array_find($this->readDatabaseRecord('system', 0)['rows'],
            static fn(array $row): bool => ($row['key']['field'] ?? null) === ProjectBattleTest::SYSTEM_KEY)
            ?? throw new SessionRefusal('System settings have no battle test.');
        $this->applyDatabaseRecord('system', 0, $row['key'],
            $entry === [] ? '' : (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $this->describeBattleTest();
    }

    /**
     * Plays the battle test in the background in its own graphical window,
     * as `ichiloto battle` does: the named troop, or the battle test's own,
     * fought by the battle test's party in its arena. It runs from the
     * project's own root with the battle test as it is now, unsaved edits
     * included, as its launch, and writes nothing into the project; anything
     * else a battle reads from disk must be saved first.
     *
     * @param int|null $troopIndex The troop to fight; null for the battle test's troop.
     * @return array<string, mixed> The battle test run, as {@see describeBattleTestRun()}.
     * @throws SessionRefusal When no troop is chosen, the setup has problems, something is unsaved, one is running, or it cannot start.
     */
    public function startBattleTest(?int $troopIndex = null): array
    {
        if ($this->battleTestRun?->isRunning() === true) {
            throw new SessionRefusal('A battle test is already running; stop it or close its window first.');
        }
        $troops = $this->requireRecordDatabase('troops');
        $troop = $troopIndex === null
            ? (ProjectBattleTest::fromArray($this->readBattleTestEntry())->troop ?? null)
            : trim(strval(($troops->getRecordByIndex($troopIndex) ?? throw new SessionRefusal(sprintf('troops has no record %d.', $troopIndex)))->get('name')));
        if ($troop === null || $troop === '') {
            throw new SessionRefusal('Choose a troop to fight in the battle test.');
        }
        $unsaved = $this->listBattleUnsavedChanges();
        if ($unsaved !== []) {
            throw new SessionRefusal(sprintf('Save %s first; the battle reads them on disk.', implode(', ', $unsaved)));
        }
        $described = $this->describeBattleTest();
        if ($described['issue'] !== null || $described['problems'] !== []) {
            throw new SessionRefusal("The battle test party cannot be set up:\n" . implode("\n", array_filter([$described['issue'], ...$described['problems']])));
        }
        $launch = null;
        try {
            $launch = PlaytestLaunch::fromBattleTest($this->workspace->projectRoot, [...$this->readBattleTestEntry(), 'troop' => $troop]);
            $launcher = PlaytestLauncher::discover(projectRoot: $this->workspace->projectRoot);
            $this->battleTestRun = $launcher->start($launch, self::PLAYTEST_RENDERER);
            $this->battleTestTroop = $troop;
        } catch (RuntimeException $error) {
            $launch?->destroy();
            throw new SessionRefusal(sprintf('The battle test could not start: %s', $error->getMessage()), previous: $error);
        }

        return $this->describeBattleTestRun();
    }

    /**
     * Whether a battle test is running, the troop it fights, and how the
     * last one ended: its exit code and, when it failed, the end of its output.
     *
     * @return array{running: bool, troop: ?string, stopped: bool, exitCode: ?int, log: ?string}
     */
    public function describeBattleTestRun(): array
    {
        $run = $this->battleTestRun;
        $running = $run?->isRunning() ?? false;
        $exitCode = $running ? null : $run?->getExitCode();

        return [
            'running' => $running,
            'troop' => $run === null ? null : $this->battleTestTroop,
            // Troops composed in the test and kept there, waiting to be added ({@see addKeptBattleTestTroops()}).
            'keptTroops' => $run?->countKeptTroops() ?? 0,
            'stopped' => $run?->wasStopped() ?? false,
            'exitCode' => $exitCode,
            'log' => $run !== null && ! $running && ! $run->wasStopped() && $exitCode !== 0 ? $run->readLogTail() : null,
        ];
    }

    /**
     * Adds the troops a battle test composed and kept, each after the last
     * troop and named after its members, one undo step each, unsaved until
     * Troops is saved ({@see TroopAuthoring::addTroop()}).
     *
     * @return array{changed: bool, names: list<string>, refused: list<string>, records: list<string>}
     */
    public function addKeptBattleTestTroops(): array
    {
        $troops = $this->requireRecordDatabase('troops');
        $enemies = $this->listEnemyNames();
        $names = $refused = [];
        foreach ($this->battleTestRun?->takeKeptTroops() ?? [] as $members) {
            try {
                $change = $this->changeRecord(fn(): RecordChange => $this->troopAuthoring->addTroop($troops, $members, $enemies));
                $names[] = $troops->getEntryLabel($troops->getRecordByIndex((int) $change->index));
            } catch (SessionRefusal $refusal) {
                $refused[] = $refusal->getMessage();
            }
        }

        return ['changed' => $names !== [], 'names' => $names, 'refused' => $refused, 'records' => $troops->getEntryLabels()];
    }

    /**
     * Ends a running battle test, window and all.
     *
     * @return array<string, mixed> The run, as {@see describeBattleTestRun()}.
     */
    public function stopBattleTest(): array
    {
        $this->battleTestRun?->stop();

        return $this->describeBattleTestRun();
    }

    /** The system data's battle test as it stands, unsaved edits included; empty when there is none. */
    private function readBattleTestEntry(): array
    {
        $system = $this->requireRecordDatabase('system')->getRecordByIndex(0)
            ?? throw new SessionRefusal('The project has no system settings.');
        $entry = $system->get(ProjectBattleTest::SYSTEM_KEY);

        return is_array($entry) ? $entry : [];
    }

    /** @return list<mixed> The starting party as system data holds it now, unsaved edits included. */
    private function readStartingPartyReferences(): array
    {
        $starting = $this->workspace->getSystemField('startingParty');

        return is_array($starting) ? array_values($starting) : [];
    }

    /** @return list<array{id: string, name: string}> The battle presentation's arenas; none without one. */
    private function readArenaChoices(): array
    {
        try {
            $catalog = $this->workspace->catalogs->loadBattlePresentation();
        } catch (\Throwable) {
            return [];
        }
        $choices = $catalog?->getArenaChoices() ?? [];

        return array_map(static fn(string|int $id, string $name): array => ['id' => (string) $id, 'name' => $name], array_keys($choices), $choices);
    }

    /** The Engine's item store for this project, as authored data reads it. */
    private function loadItemStore(): ItemStore
    {
        EngineDataBootstrap::ensure($this->workspace->projectRoot);

        $items = ConfigStore::has(ItemStore::class) ? ConfigStore::get(ItemStore::class) : null;

        return $items instanceof ItemStore ? $items
            : throw new SessionRefusal('The project\'s items cannot be read, so a battle test party cannot be set up.');
    }

    /**
     * What a battle reads from disk that has unsaved edits: every database
     * but System (whose battle test the launch carries) and every
     * cutscene, since summons play them. Maps are not part of a battle.
     *
     * @return list<string>
     */
    private function listBattleUnsavedChanges(): array
    {
        $system = $this->workspace->getRecordDatabase('system');
        $unsaved = [];
        foreach ($this->workspace->listSaveableDatabases() as $label => $database) {
            if ($database !== $system && $database->isDirty()) {
                $unsaved[] = $label;
            }
        }
        foreach ($this->workspace->cutscenes?->dirtyAssets() ?? [] as $asset) {
            $unsaved[] = $asset->type->noun() . ' ' . $asset->id;
        }

        return $unsaved;
    }

    /** Ends what the session started that would outlive it: a running playtest or battle test. */
    public function close(): void
    {
        // Changes never committed leave the project as it was.
        if ($this->changeSet !== null) {
            $this->cancelChanges();
        }
        foreach (array_keys($this->authoringCanvases) as $mapId) {
            $this->closeAuthoringCanvas((string) $mapId);
        }
        $this->playtest?->stop();
        $this->battleTestRun?->stop();
        $this->audition?->stop();
    }

    /**
     * Plays a music track or sound effect a field names, once through, to
     * hear it ({@see AudioAudition}): the game's own players find and play
     * the file. Another audition, or stopping, ends it.
     *
     * @param 'bgm'|'sfx' $kind The reference kind of the field.
     * @return array{playing: array{kind: string, name: string}|null}
     * @throws SessionRefusal When the kind is not audio, the file is missing or nothing on this computer can play it.
     */
    public function playAudio(string $kind, string $name): array
    {
        if ((ReferenceCatalog::describeMedia($kind)['kind'] ?? null) !== 'audio') {
            throw new SessionRefusal(sprintf('%s is not music or a sound effect.', $kind));
        }
        $this->audition ??= new AudioAudition(new PreviewGame(), $this->workspace->projectRoot);
        $refusal = $this->audition->play($kind, $name);
        if ($refusal !== null) {
            throw new SessionRefusal($refusal);
        }

        return $this->describeAudio();
    }

    /**
     * Stops the audition, if one is playing.
     *
     * @return array{playing: null}
     */
    public function stopAudio(): array
    {
        $this->audition?->stop();

        return ['playing' => null];
    }

    /**
     * What the audition is playing, as kind and name; null once it has finished.
     *
     * @return array{playing: array{kind: string, name: string}|null}
     */
    public function describeAudio(): array
    {
        return ['playing' => $this->audition?->describePlaying()];
    }

    /** Whether any map or database has changes not yet saved. */
    public function hasUnsavedChanges(): bool
    {
        return $this->workspace->hasUnsavedChanges();
    }

    /**
     * Names every map, database and cutscene with changes not yet saved.
     *
     * @return list<string>
     */
    public function listUnsavedChanges(): array
    {
        return $this->workspace->listUnsavedChanges();
    }

    /**
     * Saves every unsaved map, database and cutscene, as the terminal
     * editor's Save All does, and names what is still unsaved after it: a
     * map whose save would move its folder, or a document that failed.
     *
     * @return array{summary: string, skippedRenames: list<string>, skippedRelocations: list<string>, failures: list<string>, warnings: list<string>, backupFailures: list<string>, unsaved: list<string>}
     */
    public function saveAll(): array
    {
        $this->refuseDuringChangeSet('Saving everything');
        $result = WorkspaceSave::saveAll($this->workspace, $this->backups);

        return [
            'summary' => $result->summary,
            'skippedRenames' => $result->skippedRenames,
            'skippedRelocations' => $result->skippedRelocations,
            'failures' => $result->failures,
            'warnings' => $result->warnings,
            'errors' => $result->errors,
            'backupFailures' => $result->backupFailures,
            'unsaved' => $this->workspace->listUnsavedChanges(),
        ];
    }

    /**
     * Validates the project as it stands in this session, unsaved edits
     * included, by the same checks as `ichiloto validate`.
     *
     * @param list<string>|null $mapIds Only the issues about these maps; null for every issue.
     * @return array{issues: list<array<string, mixed>>, errors: int, warnings: int}
     */
    /**
     * Checks one map as saving it does ({@see MapValidator}), unsaved edits
     * included, without the rest of the project: what an editor shows beside
     * the map. Each issue names the cells it is about, when it names any.
     *
     * @return array{map: string, revision: int, issues: list<array<string, mixed>>, errors: int, warnings: int}
     * @throws SessionRefusal When the map is unknown.
     */
    public function validateMap(string $mapId): array
    {
        $map = $this->requireMap($mapId);
        $issues = MapValidator::validate($map, $this->getMapsById(), MapValidator::readInventoryCatalog($this->workspace));

        return [
            'map' => $map->mapId,
            'revision' => $this->getMapRevision($map),
            'issues' => array_map(static fn(Issue $issue): array => $issue->describe(), $issues),
            'errors' => count(MapValidator::selectSeverity($issues, Severity::ERROR)),
            'warnings' => count(MapValidator::selectSeverity($issues, Severity::WARNING)),
        ];
    }

    public function validateProject(?array $mapIds = null): array
    {
        $issues = new ProjectValidator()->validate($this->workspace);
        if ($mapIds !== null) {
            $issues = array_values(array_filter($issues, static fn(Issue $issue): bool => in_array($issue->map, $mapIds, true)));
        }

        return [
            'issues' => array_map(static fn(Issue $issue): array => $issue->describe(), $issues),
            'errors' => count(MapValidator::selectSeverity($issues, Severity::ERROR)),
            'warnings' => count(MapValidator::selectSeverity($issues, Severity::WARNING)),
        ];
    }

    /**
     * Registers the project's own save migration class as the next step of
     * its save compatibility manifest: the content version rises by exactly
     * one and saves made before it run the class as they load. The step names
     * the class alone, as the Engine reads a step as a class or as declared
     * position edits, never both. The class must load from the project (its
     * own vendor/autoload.php) and implement the Engine's
     * ContentMigrationInterface; the staged manifest is read back as the game
     * reads it before it is written. One undo step. Inside a change set it is
     * undone with the change set, and a change set that also moves saves off
     * map cells is refused whole.
     *
     * @return array{from: int, to: int, class: string, contentVersion: int}
     * @throws SessionRefusal When the class is not named or would not load as a migration, or the manifest cannot be
     *     read or rewritten; nothing is written.
     */
    public function registerSaveMigration(string $class): array
    {
        try {
            $registration = SaveMigrationRegistration::plan($this->workspace->projectRoot, $this->workspace->projectId, $class);
            $plan = $registration['plan'];
            $plan->apply();
        } catch (RuntimeException $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        $this->history->record(new GenericCommand('Register save migration', static fn() => $plan->apply(), static fn() => $plan->revert()));
        if ($this->changeSet !== null) {
            $this->changeSet['migrationRegistered'] = true;
        }

        return ['from' => $registration['from'], 'to' => $registration['to'], 'class' => $registration['class'], 'contentVersion' => $registration['to']];
    }

    /**
     * Retires a map in the save compatibility manifest: one new step whose
     * `mapRetirements` entry moves every save standing on `$map` onto `$into`,
     * at the `to` of the first landing rectangle holding its cell, else at
     * `$to`, and marks `$into` visited for saves that visited `$map`. The
     * content version rises by one. Every landing must be a cell a save can
     * stand on in `$into` as the session holds it, unsaved edits included
     * ({@see SaveLandingCells}): plain floor the player can reach, with no
     * event or NPC on it. `$map` may still exist; delete it afterwards with
     * `map.delete`. A map redrawn under its own ID retires into itself: the
     * landings are then judged on its new layout. The retirement adds no map alias: a one-shot event that
     * moved follows it through {@see addSaveAlias()}.
     *
     * One undo step. Inside a change set it is undone with the change set,
     * and committing re-checks every landing it wrote against the maps as the
     * change set leaves them, followed through the steps written after it.
     *
     * @param array{int, int} $to Where saves no landing holds land on `$into`.
     * @param list<array<string, mixed>>|null $landings Rectangles of `$map` (`x`, `y`, `width`, `height`), each one's `to`
     *     and optional `map`: the successor holding that part of the places, `$into` when left out.
     * @return array{from: int, to: int, contentVersion: int, map: string, into: string}
     * @throws SessionRefusal When the entry is malformed, `$into` is not a map, a landing cannot be
     *     stood on, or the manifest cannot be read or take the step; nothing is written.
     */
    public function retireMap(string $map, string $into, array $to, ?array $landings = null): array
    {
        $entry = ['map' => trim($map), 'into' => trim($into), ...($landings === null ? [] : ['landings' => $landings]), 'to' => $to];
        try {
            $retirement = SaveMapRetirement::readEntry($entry);
        } catch (RuntimeException $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if (! isset($this->getMapsById()[$retirement->into])) {
            throw new SessionRefusal(sprintf('There is no map %s for the saves on %s to move into.', $retirement->into, $retirement->map));
        }
        // Each landing is judged on the map it lands on: `into`, or the successor a landing names.
        $refusals = $this->describeLandingRefusals($this->workspace->analyzeReachability(), $retirement->getLandingPlaces());
        if ($refusals !== []) {
            throw new SessionRefusal(sprintf('Saves on %s cannot land at %s. Choose plain floor the player can reach, with no event or NPC on it.',
                $retirement->map, implode('; ', $refusals)));
        }
        try {
            $planned = SaveMapRetirement::plan($this->workspace->projectRoot, $this->workspace->projectId, $entry);
            $plan = $planned['plan'];
            $plan->apply();
        } catch (RuntimeException $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        $this->history->record(new GenericCommand(sprintf('Retire map %s', $retirement->map), static fn() => $plan->apply(), static fn() => $plan->revert()));
        if ($this->changeSet !== null) {
            $this->changeSet['retirements'][] = ['from' => $planned['from'], 'map' => $retirement->map];
        }

        return ['from' => $planned['from'], 'to' => $planned['to'], 'contentVersion' => $planned['to'], 'map' => $retirement->map, 'into' => $retirement->into];
    }

    /**
     * Adds one alias to the save compatibility manifest, so every save reads
     * the saved identity `$from` as `$to`, whatever its content version: a
     * one-shot event that moved to another map (`old-map:Q` to `new-map:Q`),
     * a renamed quest. `$to`, followed through the aliases already declared,
     * must be a current identity of its category where the category has a
     * catalogue, as validation requires. The staged manifest is read back as
     * the game reads it. One undo step, and inside a change set it is undone
     * with the change set.
     *
     * @param string $category A {@see ContentReferenceCategory} value, such as `one_shot_events`.
     * @return array{category: string, from: string, to: string, added: bool} `added` is false when the manifest already had it.
     * @throws SessionRefusal When the category is unknown, the target is not current, or the game would refuse the
     *     alias; nothing is written.
     */
    public function addSaveAlias(string $category, string $from, string $to): array
    {
        $kind = ContentReferenceCategory::tryFrom(trim($category)) ?? throw new SessionRefusal(sprintf('%s is not a saved-content category; use one of %s.',
            $category, implode(', ', array_map(static fn(ContentReferenceCategory $case): string => $case->value, ContentReferenceCategory::cases()))));
        [$from, $to] = [trim($from), trim($to)];
        if ($from === '' || $to === '') {
            throw new SessionRefusal('Name both the saved identity and the identity it now reads as.');
        }
        $target = $this->followSaveAliases($kind, $to);
        $known = new SaveCompatibilityValidator()->listKnownTargets($this->workspace, $kind);
        if ($known !== null && ! in_array($target, $known, true)) {
            throw new SessionRefusal(sprintf('%s is not a current %s identity, so saves cannot read %s as it. Create it first, or alias to one that exists.',
                $target, $kind->value, $from));
        }
        try {
            $plan = SaveManifestAlias::plan($this->workspace->projectRoot, $this->workspace->projectId, $kind, $from, $to);
            $plan?->apply();
        } catch (RuntimeException $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($plan !== null) {
            $this->history->record(new GenericCommand('Save alias', static fn() => $plan->apply(), static fn() => $plan->revert()));
        }

        return ['category' => $kind->value, 'from' => $from, 'to' => $to, 'added' => $plan !== null];
    }

    /** Where an identity ends through the aliases the manifest already declares for its category. */
    private function followSaveAliases(ContentReferenceCategory $category, string $identity): string
    {
        try {
            $manifest = PhpDataFile::evaluateIsolated($this->workspace->projectRoot . '/' . SaveManifestStep::PATH, $this->workspace->projectRoot);
        } catch (Throwable) {
            return $identity; // Planning the alias reports an unreadable manifest.
        }
        $aliases = [];
        foreach (is_array($manifest) ? (array) ($manifest['aliases'][$category->value] ?? []) : [] as $alias) {
            if (is_array($alias) && is_string($alias['from'] ?? null) && is_string($alias['to'] ?? null)) {
                $aliases[trim($alias['from'])] = trim($alias['to']);
            }
        }
        for ($seen = []; isset($aliases[$identity]) && ! isset($seen[$identity]); $identity = $aliases[$identity]) {
            $seen[$identity] = true;
        }

        return $identity;
    }

    /**
     * Why each save landing cannot be stood on, as the session holds its map.
     *
     * @param list<array{map: string, cell: array{int, int}}> $landings
     * @return list<string> One `field x 3, y 4 (it is solid)` per refused landing; empty when all may be stood on.
     */
    private function describeLandingRefusals(ProjectReachability $reachability, array $landings): array
    {
        $refusals = [];
        $cells = [];
        foreach ($landings as ['map' => $mapId, 'cell' => [$x, $y]]) {
            $map = $this->getMapsById()[$mapId] ?? null;
            $report = $reachability->reports[$mapId] ?? null;
            $reason = match (true) {
                $map === null => 'there is no such map',
                $report === null => sprintf('%s cannot be read as the game reads it', $mapId),
                default => ($cells[$mapId] ??= SaveLandingCells::readMap($map, $report))->findLandingRefusal($x, $y),
            };
            if ($reason !== null) {
                $refusals[] = sprintf('%s x %d, y %d (%s)', $mapId, $x, $y, $reason);
            }
        }

        return $refusals;
    }

    /**
     * The maps a change set retires, by the retirements it wrote that the
     * manifest still declares (an undone one is gone). A retirement moves
     * every save made on its map before the step, a map retired into itself
     * included, onto landings {@see findRetirementRefusal()} judges on the
     * maps the change set leaves, so those maps need no relocations of their
     * own: a later relocation step would plan cells of the old layout for
     * saves that already stand on the landings.
     *
     * @param array{retirements?: list<array{from: int, map: string}>} $changes
     * @return list<string>
     */
    private function listRetiredMaps(array $changes): array
    {
        $retirements = $changes['retirements'] ?? [];
        if ($retirements === []) {
            return [];
        }
        try {
            $manifest = PhpDataFile::evaluateIsolated($this->workspace->projectRoot . '/' . SaveManifestStep::PATH, $this->workspace->projectRoot);
            $retired = [];
            foreach ($retirements as ['from' => $from, 'map' => $map]) {
                if (SaveMapRetirement::findRetirement(is_array($manifest) ? $manifest : [], $from, $map) !== null) {
                    $retired[] = $map;
                }
            }
        } catch (Throwable) {
            // An unreadable manifest refuses the change set through findRetirementRefusal(); its maps plan as any other.
            return [];
        }

        return $retired;
    }

    /**
     * Why the map retirements a change set wrote no longer land on open
     * floor, once its edits and the steps written after them have moved the
     * maps under them; null when every landing may still be stood on.
     *
     * @param array{retirements?: list<array{from: int, map: string}>} $changes
     */
    private function findRetirementRefusal(array $changes): ?string
    {
        $retirements = $changes['retirements'] ?? [];
        if ($retirements === []) {
            return null;
        }
        try {
            $manifest = PhpDataFile::evaluateIsolated($this->workspace->projectRoot . '/' . SaveManifestStep::PATH, $this->workspace->projectRoot);
            $reachability = $this->workspace->analyzeReachability();
            $problems = [];
            foreach ($retirements as ['from' => $from, 'map' => $map]) {
                $landings = SaveMapRetirement::followLandings(is_array($manifest) ? $manifest : [], $from, $map);
                $refused = $this->describeLandingRefusals($reachability, array_map(static fn(array $landing): array
                    => ['map' => $landing['map'], 'cell' => $landing['cell']], $landings));
                if ($refused !== []) {
                    $problems[] = sprintf('saves on %s at %s', $map, implode('; ', $refused));
                }
            }
        } catch (Throwable $failure) {
            return sprintf('The map retirements this change set wrote could not be checked: %s', rtrim($failure->getMessage(), '.') . '.');
        }

        return $problems === [] ? null : sprintf('The change set leaves map retirement landings where saves cannot stand: %s. '
            . 'Keep those cells plain floor the player can reach, or retire the map with other landings.', implode('; ', $problems));
    }

    /**
     * Opens a change set: edits made from here on are written only by
     * {@see commitChanges()}, and only when the project they leave is valid.
     * This is the write path for anyone changing maps without an author
     * reviewing each save (another agent, a script): what the player would
     * meet is refused before it is written. An author's own saves stay
     * drafts ({@see saveMap()}).
     *
     * Edits that write files at once (creating or duplicating a map,
     * inserting or removing rows) may run inside it; a refused or cancelled
     * change set undoes them. Saving, moving or deleting a map is refused
     * while it is open.
     *
     * @return array{status: 'open', errors: int, warnings: int}
     * @throws SessionRefusal When a change set is already open or edits are unsaved.
     */
    public function beginChanges(): array
    {
        if ($this->changeSet !== null) {
            throw new SessionRefusal('A change set is already open; commit or cancel it first.');
        }
        if ($this->workspace->hasUnsavedChanges()) {
            throw new SessionRefusal('Save or undo pending edits before a change set begins, so it holds only its own changes.');
        }
        $baseline = new ProjectValidator()->validate($this->workspace);
        $this->changeSet = [
            'baseline' => $baseline,
            'revisions' => array_map($this->getMapRevision(...), $this->getMapsById()),
            'steps' => $this->history->count(),
            'dropped' => $this->history->countDropped(),
        ];

        return ['status' => 'open', 'errors' => count(MapValidator::selectSeverity($baseline, Severity::ERROR)),
            'warnings' => count(MapValidator::selectSeverity($baseline, Severity::WARNING))];
    }

    /**
     * Writes the open change set when the project it leaves is valid, and
     * otherwise writes nothing and undoes it.
     *
     * Validation runs over the project as saving would leave it. The change
     * set is refused when a map it changed has any error (a defect the player
     * would meet), whether the change set made it or found it there, or when
     * it leaves a new error anywhere else (a door on another map now leading
     * nowhere). Warnings never refuse it.
     * A map retirement the change set wrote is checked again: each landing,
     * moved by the save steps written after it, must still be a cell a save
     * can stand on in the maps the change set leaves. A map it retires, into
     * itself or another map, plans no relocations ({@see listRetiredMaps()}).
     *
     * Everything it left unsaved -- databases, cutscenes, maps and the save
     * relocations its maps need -- is then written as one transaction: every
     * document works out its file and proves the staged bytes read back as
     * what it holds before any file is installed, and a file that cannot be
     * installed puts back the ones already installed. A document that refuses
     * refuses the change set exactly as an error does: nothing is written,
     * and the change set is undone. Should a document fail to take up what it
     * wrote once every file is installed, the change set is saved and
     * `reason` says which, to reload before editing further.
     *
     * @param bool $relocateSaves Whether saves standing where a changed map is now solid may move to open floor;
     *     without it such a change set is refused, naming where they would move.
     * @param bool $checkOnly Whether to only report what committing would find, then undo the change set.
     * @return array{status: 'saved'|'refused'|'checked', maps: list<string>, saved: list<string>, written: array<string, list<string>>,
     *     issues: list<array<string, mixed>>, blocking: int, relocated: list<string>, backupFailures: list<string>,
     *     reason?: ?string, unrestored?: int, unrestoredFiles?: list<string>}
     *     `written` names each document written and the files it wrote or removed, relative to the project root.
     * @throws SessionRefusal When no change set is open.
     */
    public function commitChanges(bool $relocateSaves = false, bool $checkOnly = false): array
    {
        $changes = $this->changeSet ?? throw new SessionRefusal('No change set is open; begin one first.');
        $changed = $this->listChangedMaps($changes);
        $issues = new ProjectValidator()->validate($this->workspace);
        $before = array_fill_keys(array_map(self::identifyIssue(...), $changes['baseline']), true);
        $blocking = array_values(array_filter($issues, static fn(Issue $issue): bool => $issue->severity === Severity::ERROR
            && (in_array($issue->map, $changed, true) || ! isset($before[self::identifyIssue($issue)]))));
        $reported = array_values(array_filter($issues, static fn(Issue $issue): bool => in_array($issue->map, $changed, true)
            || in_array($issue, $blocking, true)));
        $outcome = ['maps' => $changed, 'saved' => [], 'written' => [], 'issues' => array_map(static fn(Issue $issue): array => $issue->describe(), $reported),
            'blocking' => count($blocking), 'relocated' => [], 'backupFailures' => []];

        // A map the change set made holds no save, though its blank files were written when it was made, and a map it
        // retires has every save moved off it by the retirement, onto landings checked again below.
        $made = array_values(array_diff(array_keys($this->getMapsById()), array_keys($changes['revisions'])));
        $unrelocated = [...$made, ...$this->listRetiredMaps($changes)];
        $changedMaps = array_values(array_filter(array_map(fn(string $mapId): ?ProjectMap => $this->getMapsById()[$mapId] ?? null, $changed),
            static fn(?ProjectMap $map): bool => $map?->isDirty() === true && ! in_array($map->mapId, $unrelocated, true)));
        // The change set writes one manifest step for every map that moves saves.
        $relocations = SaveRelocationPlanner::planAll($changedMaps, $this->workspace->projectRoot)?->describeMoves() ?? [];
        $retirementRefusal = $blocking === [] ? $this->findRetirementRefusal($changes) : null;
        $reason = match (true) {
            $blocking !== [] => sprintf('%d error%s the player would meet.', count($blocking), count($blocking) === 1 ? '' : 's'),
            $retirementRefusal !== null => $retirementRefusal,
            // A manifest step is a migration class or declared position edits, never both.
            $relocations !== [] && ($changes['migrationRegistered'] ?? false) => 'This change set registers a save migration class and moves saves off cells a changed map now takes. '
                . 'The Engine has no save migration step that does both, so the change set is not written. Register the migration and change the map in separate change sets.',
            $relocations !== [] && ! $relocateSaves => 'Saves may stand where a changed map is now solid; allow them to move to open floor, or change the map.',
            default => null,
        };
        if ($reason !== null || $checkOnly) {
            $unrestored = $this->rollBackChanges($changes);

            return ['status' => $reason === null ? 'checked' : 'refused', ...$outcome, 'relocated' => $relocations, 'reason' => $reason,
                ...($unrestored > 0 ? ['unrestored' => $unrestored] : [])];
        }

        $unsaved = array_values(array_filter($changed, fn(string $mapId): bool => ($this->getMapsById()[$mapId] ?? null)?->isDirty() === true));
        $backupFailures = [];
        $backup = function (string ...$paths) use (&$backupFailures): void {
            if ($this->backups->isEnabled() && $paths !== []) {
                $backupFailures = $this->backups->backup(...$paths)['failed'];
            }
        };

        try {
            $saves = WorkspaceSave::prepareAll($this->workspace, $relocateSaves ? fn(Command $step) => $this->history->record($step) : null, $unrelocated);
            $written = SaveSet::commit($this->workspace->projectRoot, $saves, $backup);
        } catch (SaveSetAdoptionFailure $partial) {
            // The files are written: the change set is, though the editor's view of it is not current.
            $written = $partial->written;
            $outcome['reason'] = $partial->getMessage();
        } catch (Throwable $refusal) {
            $unrestored = $this->rollBackChanges($changes);
            $files = $refusal instanceof SaveSetRefusal ? $refusal->unrestored : [];

            return ['status' => 'refused', ...$outcome, 'relocated' => $relocations, 'backupFailures' => array_values($backupFailures),
                'reason' => ($files === [] ? 'Nothing was written: ' : 'The change set was not written, and some files could not be put back: ')
                    . $refusal->getMessage(),
                ...($unrestored > 0 ? ['unrestored' => $unrestored] : []),
                ...($files === [] ? [] : ['unrestoredFiles' => array_map($this->describeProjectPath(...), $files)])];
        }

        $this->changeSet = null;
        $outcome['saved'] = $unsaved;
        $outcome['relocated'] = $relocateSaves ? $relocations : [];
        $outcome['backupFailures'] = array_values($backupFailures);
        $outcome['written'] = array_map(fn(array $paths): array => array_map($this->describeProjectPath(...), $paths),
            array_filter($written, static fn(array $paths): bool => $paths !== []));

        return ['status' => 'saved', ...$outcome];
    }

    /**
     * Cancels the open change set, undoing every edit it made, those that
     * wrote files at once included.
     *
     * @return array{status: 'cancelled', unrestored?: int}
     * @throws SessionRefusal When no change set is open.
     */
    public function cancelChanges(): array
    {
        $unrestored = $this->rollBackChanges($this->changeSet ?? throw new SessionRefusal('No change set is open.'));

        return ['status' => 'cancelled', ...($unrestored > 0 ? ['unrestored' => $unrestored] : [])];
    }

    /**
     * The maps a change set changed: every map whose revision moved since it
     * began, and every map it made. A file set written at once moves only the
     * revisions of the maps whose content it changed ({@see getMapRevision()}).
     *
     * @param array{revisions: array<string, int>} $changes
     * @return list<string>
     */
    private function listChangedMaps(array $changes): array
    {
        $changed = [];
        foreach ($this->getMapsById() as $mapId => $map) {
            if (($changes['revisions'][$mapId] ?? null) !== $this->getMapRevision($map)) {
                $changed[] = (string) $mapId;
            }
        }

        return $changed;
    }

    /**
     * Undoes every step of a change set and removes the maps it made, then
     * closes it.
     *
     * @param array{revisions: array<string, int>, steps: int, dropped: int} $changes
     * @return int How many of its earliest steps could no longer be undone, dropped from a full undo history.
     */
    private function rollBackChanges(array $changes): int
    {
        $unrestored = $this->history->countDropped() - $changes['dropped'];
        $this->changeSet = null;
        while ($this->history->count() > max(0, $changes['steps'] - $unrestored)) {
            $this->undo();
        }
        foreach (array_diff(array_keys($this->getMapsById()), array_keys($changes['revisions'])) as $made) {
            $this->deleteMap((string) $made, confirmed: true);
        }

        return $unrestored;
    }

    /** A file of the project as the project names it: relative to its root. */
    private function describeProjectPath(string $path): string
    {
        // A file found through the root's real path names it as well as one found through the root as opened.
        foreach (array_unique([$this->workspace->projectRoot, realpath($this->workspace->projectRoot) ?: $this->workspace->projectRoot]) as $root) {
            $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

            if (str_starts_with($path, $root)) {
                return substr($path, strlen($root));
            }
        }

        return $path;
    }

    /** An issue's identity, to tell an issue found before a change set from one it made. */
    private static function identifyIssue(Issue $issue): string
    {
        return implode("\0", [$issue->severity->value, $issue->where, $issue->message, $issue->code ?? '', $issue->map ?? '']);
    }

    /** Refuses what a change set cannot hold or undo while one is open. */
    private function refuseDuringChangeSet(string $doing): void
    {
        if ($this->changeSet !== null) {
            throw new SessionRefusal(sprintf('%s is not part of a change set; commit or cancel it first.', $doing));
        }
    }

    /**
     * Runs one history step and names the maps and database categories it
     * changed, so an interface reloads what it shows of them.
     *
     * @param callable(): ?Command $step
     * @return array{label: ?string, maps: list<string>, revisions: array<string, int>, databases: list<string>}
     */
    private function traverseHistory(callable $step): array
    {
        $workspace = $this->workspace;
        $before = array_map($this->getMapRevision(...), $this->getMapsById());
        $databases = $this->listDatabaseVersions();
        try { $command = $step(); }
        catch (MapSourceRefusal $error) { throw new SessionRefusal($error->getMessage(), previous: $error); }
        // A step that put another workspace in place (a file set written at
        // once, or its undo) read every category afresh. A map read afresh
        // keeps its revision while it holds what it did, so only the maps
        // the files changed are named.
        $reloaded = $this->workspace !== $workspace;
        $changed = $revisions = [];
        foreach ($this->getMapsById() as $mapId => $map) {
            $revision = $this->getMapRevision($map);
            if ($revision !== ($before[$mapId] ?? null)) {
                $changed[] = (string) $mapId;
                $revisions[$mapId] = $revision;
            }
        }
        $changedDatabases = $reloaded
            ? array_keys($this->listDatabaseVersions())
            : array_keys(array_diff_assoc($this->listDatabaseVersions(), $databases));

        return ['label' => $command?->label, 'maps' => $changed, 'revisions' => $revisions, 'databases' => array_values(array_map('strval', $changedDatabases))];
    }

    /** @return array<string, string> Each category the session edits, by key, with its content version. */
    private function listDatabaseVersions(): array
    {
        $cutscenes = [];
        foreach ($this->workspace->cutscenes === null ? [] : CutsceneType::cases() as $type) {
            $cutscenes[$type->getRecordCategory()] = $this->workspace->cutscenes->records($type)->getContentVersion();
        }

        return [
            'actors' => $this->workspace->actorDatabase->getContentVersion(),
            ...array_map(static fn(ProjectRecordDatabase $database): string => $database->getContentVersion(), $this->workspace->recordDatabases),
            ...$cutscenes,
        ];
    }

    /**
     * The rows an inspector read lists: the map's and its event's, or one
     * frame of the event's inline commands alone.
     *
     * @param list<int|string> $frame
     * @return array<int, array<string, mixed>>
     * @throws SessionRefusal When the frame is gone.
     */
    private function collectInspectorFields(ProjectMap $map, ?string $marker, array $frame = []): array
    {
        $inspector = $this->createMapInspector($map);

        if ($frame !== [] && $marker !== null) {
            return $inspector->getEventFrameFields($map, $marker, $frame)
                ?? throw new SessionRefusal('That frame of commands is no longer on the event; read the event again.');
        }

        return [...$inspector->getMapFields($map), ...($marker === null ? [] : $inspector->getEventFields($map, $marker))];
    }

    private function createMapInspector(ProjectMap $map): MapInspector
    {
        // A session serves graphical editors, which also author what only a
        // graphical renderer shows.
        return new MapInspector(new ReferenceCatalog($this->workspace, $map), graphical: true);
    }

    /**
     * One inspector row as plain data.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function describeRow(array $field): array
    {
        $target = $field['target'] ?? null;
        $control = MapInspector::findControl($field);
        $choices = MapInspector::findChoiceValues($field);
        $kind = match (true) {
            ($field['editable'] ?? true) === false, $target === null => 'info',
            // A row whose edit is one action (freezing an actor's identity),
            // made by applying it with no value.
            is_string($field['action'] ?? null) => 'action',
            ($field['mapConditions'] ?? false) === true, ($field['conditions'] ?? false) === true => 'conditions',
            ($field['worldWrites'] ?? false) === true => 'writes',
            ($field['affinities'] ?? false) === true => 'affinities',
            ($field['destination'] ?? false) === true => 'destination',
            is_string($field['reference'] ?? null) => 'reference',
            $choices !== null => 'options',
            $control !== null => match ($control->type) {
                InputControlType::INTEGER => 'integer',
                InputControlType::FLOAT => 'float',
                InputControlType::BOOLEAN => 'boolean',
                default => 'text',
            },
            default => 'info',
        };
        $list = $field['list'] ?? $field['encounterList'] ?? $field[MapInspector::ARENA_RULE_LIST] ?? $field['bgmVariantList'] ?? $field['landmarkList'] ?? $field['areaList'] ?? $field['speakerList'] ?? null;

        // The terminal nests a row by indenting its label; an interface is given the depth instead.
        $label = (string) ($field['label'] ?? '');
        $depth = intdiv(strlen($label) - strlen(ltrim($label, ' ')), 2);
        $key = MapInspector::describeRowKey($field);

        return array_filter([
            'label' => trim($label),
            'mapPlacement' => $field['mapPlacement'] ?? null,
            // A list entry's row: which entry (and nested entry) it is part of, and its name within it.
            'entry' => is_array($field['entry'] ?? null) ? array_values($field['entry']) : null,
            'entryLabel' => is_string($field['entryLabel'] ?? null) ? $field['entryLabel'] : null,
            // A list whose entries are made on the map (from selected cells), never added here.
            'listAddsOnMap' => ($field['listAddsOnMap'] ?? false) === true ? true : null,
            'name' => is_string($field['name'] ?? null) ? $field['name'] : null,
            'depth' => $depth,
            // A section heading: a row that only names the rows after it.
            'heading' => $kind === 'info' && (string) ($field['value'] ?? '') === '' && $key === null && $list === null ? true : null,
            // Multi-line text is read whole, as it is edited.
            'value' => $control?->type === InputControlType::MULTILINE ? $control->rawValue : (string) ($field['value'] ?? ''),
            'raw' => match ($kind) {
                'conditions' => is_string($field['encoded'] ?? null) ? $field['encoded'] : (string) ($field['value'] ?? ''),
                'writes', 'affinities' => (string) ($field['value'] ?? ''),
                'text', 'integer', 'float', 'boolean' => $control?->rawValue,
                default => null,
            },
            'kind' => $kind,
            'options' => $kind === 'options' ? $choices : null,
            'optionLabels' => $kind === 'options' && is_array($field['choices'] ?? null)
                ? array_values(array_map(static fn(array $choice): string => (string) $choice['label'], $field['choices'])) : null,
            'reference' => match ($kind) {
                'reference' => $field['reference'],
                'destination' => 'maps',
                default => null,
            },
            // The map a map-local reference's choices come from, when its entry names one ('' until it does).
            'referenceMap' => $kind === 'reference' && is_string($field['referenceMap'] ?? null) ? $field['referenceMap'] : null,
            // What the value looks like, when the reference names a picture: shown with the row and in its picker.
            'media' => $kind === 'reference' ? ReferenceCatalog::describeMedia((string) $field['reference']) : null,
            'imagePreview' => is_array($field['imagePreview'] ?? null) ? $field['imagePreview'] : null,
            // How the choice of nothing reads, where the row says (the Engine's own attack).
            'noneLabel' => $kind === 'reference' && is_string($field['noneLabel'] ?? null) ? $field['noneLabel'] : null,
            'action' => $kind === 'action' ? $field['action'] : null,
            // A tile identity of the map's tileset, picked from its palette.
            'tilePicker' => ($field['tilePicker'] ?? false) === true ? true : null,
            // A list picked a member at a time: each pick toggles one member.
            'multi' => $kind === 'reference' && ($field['multi'] ?? false) === true ? true : null,
            'list' => is_array($list) && $target !== null ? ['index' => (int) ($list['index'] ?? 0)] : null,
            // Conditions and writes are built a part at a time from these.
            'entries' => match ($kind) {
                'conditions' => ConditionCodec::decodeAll(is_string($field['encoded'] ?? null) ? $field['encoded'] : (string) ($field['value'] ?? '')),
                'writes' => WorldWriteCodec::decodeAll((string) ($field['value'] ?? '')),
                // Affinities are built a row at a time: an element and its multiplier.
                'affinities' => self::describeAffinityEntries((string) ($field['value'] ?? '')),
                default => null,
            },
            'writeTypes' => $kind === 'writes' && is_array($field['writeTypes'] ?? null) ? array_values($field['writeTypes']) : null,
            // One axis of a coordinate pair under the heading before it.
            'axis' => in_array($field['axis'] ?? null, ['x', 'y'], true) ? $field['axis'] : null,
            // A row about the rows before it: a warning (a line's emotion its speaker lacks) or an
            // error (a chest whose reward the Engine cannot give).
            'tone' => in_array($field['tone'] ?? null, ['warning', 'error'], true) ? $field['tone'] : null,
            // A record row: the frame of commands it opens, and the item adding and removing at it apply to.
            'frame' => is_array($field['frame'] ?? null) && $target !== null ? array_values($field['frame']) : null,
            'item' => ($field['item'] ?? false) === true ? true : null,
            'itemNoun' => is_string($field['itemNoun'] ?? null) ? $field['itemNoun'] : null,
            'listHeading' => ($field['listHeading'] ?? false) === true ? true : null,
            'childNoun' => is_string($field['childNoun'] ?? null) ? $field['childNoun'] : null,
            'ordered' => ($field['ordered'] ?? false) === true ? true : null,
            'multiline' => $control?->type === InputControlType::MULTILINE ? true : null,
            'key' => $key,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * An affinity line as the rows it is built from.
     *
     * @return list<array{element: string, multiplier: float}>
     */
    private static function describeAffinityEntries(string $line): array
    {
        $entries = [];
        foreach (ElementAffinityCodec::decodeAll($line) as $element => $multiplier) {
            $entries[] = ['element' => $element, 'multiplier' => $multiplier];
        }

        return $entries;
    }

    /** @throws SessionRefusal */
    private function requireRecordDatabase(string $category): ProjectRecordDatabase
    {
        // A cutscene type's assets are records too, edited through the library's own record category.
        $cutscene = CutsceneType::findByRecordCategory($category);
        if ($cutscene !== null) {
            return $this->workspace->cutscenes?->records($cutscene)
                ?? throw new SessionRefusal(sprintf('This project has no %s assets to edit.', $cutscene->noun()));
        }
        if (! DatabaseCatalog::knows($category)) {
            throw new SessionRefusal(sprintf('There is no database category %s.', $category));
        }

        return $this->workspace->getRecordDatabase($category)
            ?? throw new SessionRefusal(sprintf('The %s database has no records to edit here.', $category));
    }

    /**
     * A Database category as this session edits it: actors through the
     * actor service, with what this editor's actor panes show, and every
     * schema-driven category through the shared record rules.
     *
     * @throws SessionRefusal When the category is unknown.
     */
    private function requireCategory(string $category): DatabaseCategory
    {
        if ($category === 'actors') {
            return new ActorCategory($this->workspace, $this->actorAuthoring);
        }
        $cutscene = CutsceneType::findByRecordCategory($category);
        if ($cutscene !== null) {
            return new CutsceneRecordCategory($this->workspace->cutscenes
                ?? throw new SessionRefusal(sprintf('This project has no %s assets to edit.', $cutscene->noun())), $cutscene);
        }

        return new RecordCategory($this->requireRecordDatabase($category));
    }

    /** @return array<string, ProjectMap> */
    private function getMapsById(): array
    {
        return array_combine($this->workspace->mapIds, $this->workspace->maps);
    }

    /** @throws SessionRefusal */
    private function requireMap(string $mapId): ProjectMap
    {
        return $this->getMapsById()[$mapId] ?? throw new SessionRefusal(sprintf('There is no map %s.', $mapId));
    }

    /**
     * A map's revision as an interface is given it: its own state version,
     * counted from the last revision given for an earlier reading of it. A
     * reading that holds what that revision named keeps it; any other counts
     * on from it.
     */
    private function getMapRevision(ProjectMap $map): int
    {
        $known = $this->mapRevisions[$map->mapId] ?? null;

        if ($known === null || $known['map']->get() !== $map) {
            $start = $known === null ? null : ($known['content'] === $map->contentFingerprint ? $known['last'] : $known['last'] + 1);
            $known = ['map' => WeakReference::create($map), 'base' => $start === null ? 0 : $start - $map->stateVersion(), 'last' => 0, 'content' => ''];
        }

        $known['last'] = $known['base'] + $map->stateVersion();
        $known['content'] = $map->contentFingerprint;
        $this->mapRevisions[$map->mapId] = $known;

        return $known['last'];
    }

    /** @throws SessionRefusal When the map changed since the revision the caller saw. */
    private function requireCurrentMap(string $mapId, int $revision): ProjectMap
    {
        $map = $this->requireMap($mapId);
        if ($this->getMapRevision($map) !== $revision) {
            throw new SessionRefusal(sprintf('%s changed since revision %d; reload it and edit again.', $mapId, $revision));
        }

        return $map;
    }
}
