<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use Ichiloto\Editor\Backup\BackupSettings;
use Ichiloto\Editor\Backup\BackupWriter;
use Ichiloto\Editor\Canvas\CanvasEditor;
use Ichiloto\Editor\Cutscenes\CutsceneRecordCategory;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\Canvas\PieceRole;
use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\ConditionEditor;
use Ichiloto\Editor\Animations\LegacyAnimationConversion;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Engine\Animations\Timelines\EffectCadence;
use Ichiloto\Editor\Database\ElementAffinityCodec;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordItem;
use Ichiloto\Editor\Database\RecordChange;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\ReferenceCatalog;
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
use Ichiloto\Editor\Field\NpcChange;
use Ichiloto\Editor\Field\NpcInspector;
use Ichiloto\Editor\Field\NpcRefusal;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\Inspector\InspectorListEdit;
use Ichiloto\Editor\Inspector\InspectorRefusal;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Playtest\PlaytestLauncher;
use Ichiloto\Editor\Playtest\PlaytestOverlay;
use Ichiloto\Editor\Playtest\PlaytestRun;
use Ichiloto\Editor\Maps\LayerEditor;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\Maps\MapReferences;
use Ichiloto\Editor\Maps\TilePalette;
use Ichiloto\Editor\History\SourceSetCommand;
use Ichiloto\Editor\History\CommandGroup;
use Ichiloto\Editor\History\SourceSetRequired;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Storage\WorkspaceSave;
use Ichiloto\Editor\Validation\MapValidator;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerBindings;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleFormationBattler;
use Ichiloto\Engine\Battle\Presentation\BattleFormationLayout;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Editor\Database\EngineDataBootstrap;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Scenes\Arena\BattleTestChoices;
use Ichiloto\Engine\Scenes\Arena\BattleTestLoadoutCatalog;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Ichiloto\Engine\Scenes\Arena\ProjectBattleTest;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Closure;
use InvalidArgumentException;
use RuntimeException;
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
    /** The renderer a playtest started from a graphical editor uses: it owns no terminal to hand over. */
    public const string PLAYTEST_RENDERER = 'gpui';

    private ProjectWorkspace $workspace;

    /** The playtest running in the background, or the last one, to report how it ended. */
    private ?PlaytestRun $playtest = null;
    /** The battle test running in its own window, and the troop it fights. */
    private ?PlaytestRun $battleTestRun = null;
    private ?string $battleTestTroop = null;

    /** How actors are authored, with what this editor's actor panes show. */
    private readonly ActorAuthoring $actorAuthoring;

    /**
     * Where each map's revisions count from. A map read afresh, when a file
     * set written at once reloads the project or its undo puts the earlier
     * one back, counts on from every revision given for it before, so one
     * revision never names two states of a map.
     *
     * @var array<string, array{map: WeakReference<ProjectMap>, base: int, last: int}>
     */
    private array $mapRevisions = [];
    private function __construct(
        ProjectWorkspace $workspace,
        private readonly CommandHistory $history,
        private readonly BackupWriter $backups,
    ) {
        $this->workspace = $workspace;
        $this->actorAuthoring = new ActorAuthoring();
    }

    /** Opens the project at a root, as the terminal editor opens it. */
    public static function open(string $projectRoot): self
    {
        $workspace = ProjectWorkspace::fromProject($projectRoot);

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
            ], DatabaseCatalog::all()),
            // Cutscene types, edited through the same record RPCs under their record category keys.
            'cutscenes' => array_map(static fn(CutsceneType $type): array => [
                'key' => $type->getRecordCategory(),
                'label' => $type->label(),
                'description' => $type->describeCategory(),
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
     * Creates a blank map at the maps root, written to disk at once as the
     * terminal editor creates one, named after `$name` (`new-map` when
     * empty) with a numbered suffix when that is taken. Every open map keeps
     * its unsaved changes and the undo history stays.
     *
     * @return array{map: string, maps: list<array<string, mixed>>}
     * @throws SessionRefusal When the kind is unknown, the size is not a size, or the files cannot be written.
     */
    public function createMap(?string $name, ?string $kind, int $width, int $height): array
    {
        if ($kind !== null && ! in_array($kind, array_column($this->listMapKinds(), 'value'), true)) {
            throw new SessionRefusal(sprintf('There is no map kind %s.', $kind));
        }
        $baseName = $name === null || trim($name) === '' ? null : ProjectMap::slugify($name, '');
        if ($baseName === '') {
            throw new SessionRefusal(sprintf('"%s" has no letters or digits to name a map with.', $name));
        }
        try {
            $mapId = $this->workspace->createMap($baseName, kind: $kind, width: $width, height: $height);
        } catch (MapSourceRefusal|RuntimeException $error) {
            throw new SessionRefusal(sprintf('The map was not created: %s', $error->getMessage()), previous: $error);
        }
        $this->workspace = $this->workspace->withLoadedMap($mapId);

        return ['map' => $mapId, 'maps' => $this->describeMaps()];
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
            'tileLayers' => $map->describeTileLayers(),
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
        $glyphLayers = array_values(array_filter($map->getLayers(), static fn(array $layer): bool => $layer['id'] !== MapLayers::EVENT));
        $layerIds = [];
        foreach ($layerSet->layers as $index => $layer) {
            $layerIds[(string) $glyphLayers[$index]['id']] = PresentationLayerPolicy::getMapLayerId($layer);
        }

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

        try {
            $plan = CanvasEditor::plan($map, $layerId, $writes, $choices, true);
            if ($plan !== null && $plan['unresolved'] !== []) {
                $glyph = (string) array_key_first($plan['unresolved']);

                return ['status' => 'question', 'glyph' => $glyph, 'roles' => array_map(
                    static fn(PieceRole $role): array => ['key' => $role->key, 'label' => $role->label],
                    $plan['unresolved'][$glyph],
                )];
            }
            $applied = CanvasEditor::apply($map, $layerId, $writes, $label, $plan['tiles'] ?? []);
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
     * Sets a tile layer's offset across and down in field cells (each -0.5,
     * 0 or 0.5) and the gameplay layer its tiles move with, or none, as one
     * undo step, validated as the Engine reads them.
     *
     * @param array<int, mixed> $offset
     * @return array{status: 'applied', revision: int, changed: bool, layer: ?string}
     * @throws SessionRefusal When the map is unknown, stale or the Engine would refuse the settings.
     */
    public function setTileLayerSettings(string $mapId, int $revision, string $name, array $offset, ?string $movesWith): array
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
     * `destination` (a map from `references.list` for `maps` and a spawn
     * point on it, set together through `setEventDestination`), or `info`
     * for a row that is only read here: headings and counts. A typed row's
     * `raw` is the value its edit starts from. A row inside a list an author
     * adds to and removes from carries `list` (its entry's index), and
     * `addInspectorListEntry`/`removeInspectorListEntry` apply to it.
     *
     * @return array{map: string, revision: int, event: ?string, rows: list<array<string, mixed>>}
     * @throws SessionRefusal When the map or event is unknown.
     */
    public function readInspector(string $mapId, ?string $marker = null): array
    {
        $map = $this->requireMap($mapId);
        if ($marker !== null && $map->getEventDefinition($marker) === null && $map->getEventArea($marker) === null) {
            throw new SessionRefusal(sprintf('%s has no event %s.', $mapId, $marker));
        }

        return [
            'map' => $mapId,
            'revision' => $this->getMapRevision($map),
            'event' => $marker,
            'rows' => array_map(self::describeRow(...), $this->collectInspectorFields($map, $marker)),
        ];
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
     * @param array<string, mixed> $key The row's key, as `readInspector` gave it.
     * @return array{status: 'applied', revision: int, changed: bool}|array{status: 'question', question: string, answers: list<array{key: string, label: string, description: string}>}
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

        $command = $this->runEdit(static fn(): ?Command => ($field['target'] ?? null) === 'map-kind'
            ? $inspector->changeMapKind($map, $value, $cleared > 0 && $answer === 'clear')
            : $inspector->apply($map, $field, $value));

        return ['status' => 'applied', 'revision' => $this->getMapRevision($map), 'changed' => $command !== null];
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
     * one undo step.
     *
     * @return array{revision: int, changed: bool}
     * @throws SessionRefusal When either map is unknown, the map is stale or read-only, the event has no destination, or the spawn point is off the destination.
     */
    public function setEventDestination(string $mapId, int $revision, string $marker, string $destinationMapId, int $x, int $y): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $destination = $this->requireMap($destinationMapId);
        $command = $this->runEdit(fn(): ?Command => $this->createMapInspector($map)->setTransferDestination($map, $marker, $destination, $x, $y));

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
        } catch (EventRefusal|MapSourceRefusal $refusal) {
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

        // A key comes back as the interface stored it, which may not keep
        // its members in the order they were given.
        ksort($key);

        return array_find($this->collectInspectorFields($map, $marker), static function (array $candidate) use ($key): bool {
            $candidateKey = self::describeKey($candidate);
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
                    'description' => sprintf('Its tiles come from %s and would show the wrong art as %s. Glyphs stay. Undo restores them.', $currentLabel, $label)],
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

    public function listReferences(string $mapId, string $category): array
    {
        if (! ReferenceCatalog::knows($category)) {
            throw new SessionRefusal(sprintf('There is no reference category %s.', $category));
        }
        $catalog = new ReferenceCatalog($this->workspace, $this->requireMap($mapId));
        $labels = $catalog->labelsFor($category);

        return array_map(static fn(mixed $value): array => [
            'value' => (string) $value,
            'label' => (string) ($labels[$value] ?? $value),
        ], array_values($catalog->valuesFor($category)));
    }

    /**
     * A troop's graphical formation as an arranger draws it, composed by the
     * Engine's BattleFormationLayout, the placement the battle itself uses:
     * the battle canvas, the project's arenas with the one previewed and its
     * backgrounds, the starting party in its slots, and each member's enemy,
     * battle placement and (once placed) where its feet stand, the bounds its
     * idle art is drawn in, that art, and its body span at battle scale. The
     * arena is a preview only, as in RPG Maker's Troops tab: a troop is placed
     * once for every arena, and choosing one writes nothing.
     *
     * @return array<string, mixed>
     * @throws SessionRefusal When the troop is unknown or the project has no graphical battle.
     */
    public function readTroopFormation(int $index, ?string $arena = null): array
    {
        $record = $this->requireRecordDatabase('troops')->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('troops has no record %d.', $index));
        $catalog = $this->requireBattleLayoutCatalog('arrange troops on');

        $members = $placed = $placedMembers = [];
        foreach ($record->getSubList('enemies') as $memberIndex => $entry) {
            $enemy = is_array($entry) ? (string) ($entry['enemy'] ?? '') : '';
            $placement = is_array($entry) && is_array($entry['graphicalPlacement'] ?? null) ? $entry['graphicalPlacement'] : null;
            $members[$memberIndex] = ['enemy' => $enemy, 'placement' => $placement, 'battler' => null];
            if ($placement === null) {
                continue;
            }
            try {
                $placed[] = ['enemyId' => $enemy, 'slot' => BattlerSlot::fromArray($placement, 'Battle Placement')];
                $placedMembers[] = $memberIndex;
            } catch (\InvalidArgumentException $error) {
                $members[$memberIndex]['issue'] = $error->getMessage();
            }
        }
        [$formation, $view, $clearance] = $this->composeFormation($catalog, $arena, $placed);
        foreach ($formation->enemies as $position => $battler) {
            $members[$placedMembers[$position]]['battler'] = self::describeFormationBattler($battler, $clearance['enemies'][$position]);
        }

        return [...$view, 'members' => array_values($members)];
    }

    /**
     * An enemy as Database > Enemies shows it: its terminal sprite, read the
     * way the game reads it, and its battle art composed by the Engine's
     * BattleFormationLayout at battle scale beside the starting party. Battle
     * layouts define where the party stands, not enemies (troops place those),
     * so the enemy stands opposite a party member, its slot mirrored across
     * the canvas: a size comparison, not a battle position. It stands opposite
     * the first member it stands clear beside by the Engine's formation
     * clearance, else the first it fits on the canvas beside, so a creature
     * taller than the lead's ground line is still shown whole, with what it
     * does not clear. Both read the record as it is now, unsaved edits
     * included. A project without a graphical battle still gets the sprite,
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
            if ($catalog->ui->partySlots === []) {
                throw new SessionRefusal('The battle layout has no party slot to stand an enemy beside.');
            }
            $composed = $firstRefusal = null;
            foreach ($catalog->ui->partySlots as $party) {
                $slot = new BattlerSlot($catalog->ui->width - $party->x, $party->y, $party->width, $party->height);
                try {
                    $candidate = $this->composeFormation($catalog, $arena, [['enemyId' => $name, 'slot' => $slot]]);
                } catch (SessionRefusal $refusal) {
                    $firstRefusal ??= $refusal;
                    continue;
                }
                $composed ??= $candidate;
                if ($candidate[2]['enemies'][0] === []) {
                    $composed = $candidate;
                    break;
                }
            }
            if ($composed === null) {
                throw $firstRefusal;
            }
            [$formation, $view, $clearance] = $composed;
            $preview['formation'] = [...$view, 'members' => [[
                'enemy' => $name,
                'placement' => null,
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
        if (! is_string($imagePath) || trim($imagePath) === '') {
            return ['issue' => 'No sprite is chosen.'];
        }
        $root = $this->workspace->projectRoot;
        $folder = realpath($root . '/assets/Graphics/Enemies');
        $file = realpath($root . '/assets/Graphics/Enemies/' . $imagePath . '.txt');
        if ($folder === false || $file === false || ! str_starts_with($file, $folder . DIRECTORY_SEPARATOR)) {
            return ['issue' => sprintf('Graphics/Enemies/%s.txt is not a sprite in this project.', $imagePath)];
        }

        return ['lines' => array_values(array_map(strval(...), (array) ProjectDirectoryContext::run(
            $root,
            static fn(): array => (array) graphics('Enemies/' . $imagePath),
        )))];
    }

    /**
     * The project's battle presentation, when it lays battles out on a
     * graphical canvas: its presentation code, with the battle art bound as
     * data as it is now, unsaved edits included, so a preview shows the art
     * being set.
     */
    private function requireBattleLayoutCatalog(string $purpose): BattlePresentationCatalog
    {
        $assets = $this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets';
        try {
            $catalog = BattlePresentationCatalog::loadCode($assets);
            $bindings = $catalog === null ? null : $this->readProposedBattlerBindings();
            if ($catalog !== null && $bindings !== null) {
                $catalog = $catalog->bindBattlers(BattlerBindings::getFromArray($bindings, $assets));
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
        $root = $this->workspace->projectRoot;
        $file = PhpDataFile::load($root . DIRECTORY_SEPARATOR . RecordSchemaCatalog::BATTLERS_PATH, $root);
        $payload = is_array($file->payload) ? $file->payload : null;
        foreach (DatabaseCatalog::getEmbedded() as $category) {
            $database = $this->workspace->getRecordDatabase($category->key);
            if ($database !== null && $database->isDirty()) {
                $payload = $database->foldInto($payload ?? []);
            }
        }

        return $payload;
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
     * what every formation view shares: the canvas, the arenas with the one
     * previewed and its backgrounds, and the party in its slots. Also returns
     * the Engine's clearance diagnostics for party and enemies, by position,
     * which include each battler's own.
     *
     * @param list<array{enemyId: string, slot: BattlerSlot}> $enemies
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
        $partyIds = array_values(array_slice($partyIds, 0, count($catalog->ui->partySlots)));

        try {
            $formation = BattleFormationLayout::compose($catalog, $arena, $enemies, $partyIds, $assetRoot);
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
            'arenas' => $arenas,
            'arena' => $chosen === false ? null : (string) $chosen,
            'backgrounds' => array_map(self::describeCanvasImage(...), $formation->backgrounds),
            'party' => $party,
            'partySource' => $partySource,
        ], $clearance];
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
        ];
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
            'dirty' => $database->isDirty(),
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
            'rows' => array_map(static fn(array $field): array => self::describeRecordRow(
                $database,
                $index,
                $frame,
                $field,
                isset($field['field']) ? 'record' : null,
            ), $fields),
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
     * Duplicates a record below itself under a fresh identity, as one undo step.
     *
     * @return array{index: int, records: list<string>} The copy's index, and the labels afterwards.
     * @throws SessionRefusal When the category or record is unknown or read-only, or the record cannot be duplicated.
     */
    public function duplicateDatabaseRecord(string $category, int $index): array
    {
        $database = $this->requireCategory($category);
        $change = $this->changeRecord(static fn(): RecordChange => $database->duplicateRecord($index));

        return ['index' => (int) $change->index, 'records' => $database->getRecordLabels()];
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

    /**
     * Creates an NPC at a tile ({@see NpcAuthoring::create()}): fixed, its
     * stable id derived from its name, a blank name taking the placeholder.
     *
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or the tile is outside it or taken.
     */
    public function createNpc(string $mapId, int $revision, int $x, int $y, string $name): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);

        return $this->applyNpcChange($map, fn(NpcAuthoring $authoring): NpcChange => $authoring->create($map, $x, $y, $name));
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
     * Gives an NPC authored without a stable id one, from its name.
     *
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the NPC is unknown, or it already has an id.
     */
    public function assignNpcId(string $mapId, int $revision, int $index): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);

        return $this->applyNpcChange($map, fn(NpcAuthoring $authoring): NpcChange => $authoring->assignId($map, $index));
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
        $inspector = new NpcInspector($map);
        $npc = $map->getNpcs()->get($index) ?? throw new SessionRefusal(sprintf('%s has no NPC %d.', $mapId, $index));
        $fields = $this->collectNpcFields($inspector, $index, $frame);

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
            'rows' => array_map(static fn(array $field): array => self::describeNpcRow($inspector->records(), $index, $field, $frame), $fields),
        ];
    }

    /**
     * Applies one NPC row's edit as one undo step. The row is found again by
     * its key among the NPC's current rows. A rename carries the id along
     * (`followedId`) unless something names it (`idReferences`).
     *
     * @param array<string, mixed> $key The row's key, as `readNpc` gave it.
     * @return array{revision: int, changed: bool, index: ?int, id: ?string, followedId: ?string, idReferences: list<string>}
     * @throws SessionRefusal When the map is unknown, stale or read-only, the row is gone or read-only, or the edit is refused.
     */
    public function applyNpc(string $mapId, int $revision, int $index, array $key, string $value): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $inspector = new NpcInspector($map);
        $frame = self::requireNpcFrame($key['frame'] ?? []);
        $fieldId = $key['field'] ?? null;
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
        $inspector = new NpcInspector($map);
        $frame = self::requireNpcFrame($key['frame'] ?? []);
        $fieldId = is_string($key['field'] ?? null) ? $key['field'] : '';

        return $this->applyNpcChange($map,
            static fn(NpcAuthoring $authoring): NpcChange => $authoring->addSubItem($inspector, $index, $frame, $fieldId));
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
        $inspector = new NpcInspector($map);
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

        return self::describeRecordRow($records, $index, $frame, $field, $named ? 'npc' : null);
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
     * Saves one map through its transaction, after the warnings validation
     * has for it, which never block a save.
     *
     * @return array{saved: string, warnings: list<string>, backupFailures: list<string>}
     * @throws SessionRefusal When the map is unknown or refuses to save.
     */
    public function saveMap(string $mapId): array
    {
        $map = $this->requireMap($mapId);
        $warnings = MapValidator::validate($map, $this->getMapsById());
        $failures = [];

        try {
            $saved = $map->save(function (string ...$paths) use (&$failures): void {
                if ($this->backups->isEnabled() && $paths !== []) {
                    $failures = $this->backups->backup(...$paths)['failed'];
                }
            });
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }

        return ['saved' => $saved, 'warnings' => array_values($warnings), 'backupFailures' => array_values($failures)];
    }

    /**
     * Plays the game from a map and cell in the background, in its own
     * graphical window, as the terminal editor's playtest does: from a
     * temporary overlay of the project that writes nothing into it, with
     * the author's player settings (volume and mute included) and no saves.
     * The game reads the map from disk, so a map with unsaved changes is
     * refused until it is saved.
     *
     * @return array<string, mixed> The playtest, as {@see describePlaytest()}.
     * @throws SessionRefusal When the map is unknown or unsaved, a playtest is running, or it cannot start.
     */
    public function startPlaytest(string $mapId, int $x, int $y): array
    {
        $map = $this->requireMap($mapId);
        if ($this->playtest?->isRunning() === true) {
            throw new SessionRefusal('A playtest is already running; stop it or close its window first.');
        }
        if ($map->isDirty()) {
            throw new SessionRefusal(sprintf('Save %s before playtesting it; the game reads the map on disk.', $mapId));
        }
        if ($x < 0 || $y < 0 || $x >= $map->getWidth() || $y >= $map->getHeight()) {
            throw new SessionRefusal(sprintf('%d, %d is outside %s.', $x, $y, $mapId));
        }
        $overlay = null;
        try {
            $overlay = PlaytestOverlay::create($this->workspace->projectRoot, $mapId, $x, $y);
            $launcher = PlaytestLauncher::discover(projectRoot: $this->workspace->projectRoot);
            $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ichiloto-playtest-', true) . '.log';
            $this->playtest = $launcher->start($overlay, self::PLAYTEST_RENDERER, $log);
        } catch (RuntimeException $error) {
            $overlay?->destroy();
            throw new SessionRefusal(sprintf('The playtest could not start: %s', $error->getMessage()), previous: $error);
        }

        return $this->describePlaytest();
    }

    /**
     * Whether a playtest is running, where it started, and how the last one
     * ended: its exit code and, when it failed, the end of its output.
     *
     * @return array{running: bool, map: ?string, x: ?int, y: ?int, stopped: bool, exitCode: ?int, log: ?string}
     */
    public function describePlaytest(): array
    {
        $run = $this->playtest;
        $running = $run?->isRunning() ?? false;
        $exitCode = $running ? null : $run?->getExitCode();

        return [
            'running' => $running,
            'map' => $run?->overlay->mapId,
            'x' => $run?->overlay->spawnX,
            'y' => $run?->overlay->spawnY,
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
                $setup = $test->createSetup($actors, $this->readStartingPartyReferences());
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

            return [...$view, 'arena' => $setup->arena, 'members' => $members,
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
     * fought by the battle test's party in its arena. It runs from an
     * overlay of the project carrying the battle test as it is now, unsaved
     * edits included, and writes nothing into the project; anything else a
     * battle reads from disk must be saved first.
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
        $overlay = null;
        try {
            $overlay = PlaytestOverlay::createForBattle($this->workspace->projectRoot, $this->readBattleTestEntry());
            $launcher = PlaytestLauncher::discover(projectRoot: $this->workspace->projectRoot);
            $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ichiloto-battle-test-', true) . '.log';
            $this->battleTestRun = $launcher->startBattle($overlay, self::PLAYTEST_RENDERER, $log, $troop);
            $this->battleTestTroop = $troop;
        } catch (RuntimeException $error) {
            $overlay?->destroy();
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
            'stopped' => $run?->wasStopped() ?? false,
            'exitCode' => $exitCode,
            'log' => $run !== null && ! $running && ! $run->wasStopped() && $exitCode !== 0 ? $run->readLogTail() : null,
        ];
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
            $catalog = BattlePresentationCatalog::loadCode($this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets');
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
     * but System (whose battle test the overlay carries) and every
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
        $this->playtest?->stop();
        $this->battleTestRun?->stop();
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
     * @return array{summary: string, skippedRenames: list<string>, failures: list<string>, warnings: list<string>, backupFailures: list<string>, unsaved: list<string>}
     */
    public function saveAll(): array
    {
        $result = WorkspaceSave::saveAll($this->workspace, $this->backups);

        return [
            'summary' => $result->summary,
            'skippedRenames' => $result->skippedRenames,
            'failures' => $result->failures,
            'warnings' => $result->warnings,
            'backupFailures' => $result->backupFailures,
            'unsaved' => $this->workspace->listUnsavedChanges(),
        ];
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
        $command = $step();
        // A step that put another workspace in place (a file set written at
        // once, or its undo) changed whatever it reloaded: everything.
        $reloaded = $this->workspace !== $workspace;
        $changed = $revisions = [];
        foreach ($this->getMapsById() as $mapId => $map) {
            $revision = $this->getMapRevision($map);
            if ($reloaded || $revision !== ($before[$mapId] ?? null)) {
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

    /** @return array<int, array<string, mixed>> */
    private function collectInspectorFields(ProjectMap $map, ?string $marker): array
    {
        $inspector = $this->createMapInspector($map);

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
        $list = $field['list'] ?? $field['encounterList'] ?? $field['bgmVariantList'] ?? null;

        // The terminal nests a row by indenting its label; an interface is given the depth instead.
        $label = (string) ($field['label'] ?? '');
        $depth = intdiv(strlen($label) - strlen(ltrim($label, ' ')), 2);
        $key = self::describeKey($field);

        return array_filter([
            'label' => trim($label),
            'depth' => $depth,
            // A section heading: a row that only names the rows after it.
            'heading' => $kind === 'info' && (string) ($field['value'] ?? '') === '' && $key === null && $list === null ? true : null,
            'value' => (string) ($field['value'] ?? ''),
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
            // How the choice of nothing reads, where the row says (the Engine's own attack).
            'noneLabel' => $kind === 'reference' && is_string($field['noneLabel'] ?? null) ? $field['noneLabel'] : null,
            'action' => $kind === 'action' ? $field['action'] : null,
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

    /**
     * What names a row for an edit: its target and the field, path, marker
     * and index it edits. Null for a row no edit applies through.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>|null
     */
    private static function describeKey(array $field): ?array
    {
        if (! is_string($field['target'] ?? null)) {
            return null;
        }

        return array_filter([
            'target' => $field['target'],
            'field' => isset($field['field']) ? (string) $field['field'] : null,
            'path' => isset($field['path']) ? array_values(array_map('strval', (array) $field['path'])) : null,
            'marker' => isset($field['marker']) ? (string) $field['marker'] : null,
            'index' => isset($field['index']) ? (int) $field['index'] : null,
            // A list heading has no path of its own; its list's names it.
            'list' => is_array($field['list']['path'] ?? null) ? array_values(array_map('strval', $field['list']['path'])) : null,
        ], static fn(mixed $value): bool => $value !== null);
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

    /** @throws SessionRefusal When the map changed since the revision the caller saw. */
    /**
     * A map's revision as an interface is given it: its own state version,
     * counted on from every revision given for an earlier reading of it.
     */
    private function getMapRevision(ProjectMap $map): int
    {
        $known = $this->mapRevisions[$map->mapId] ?? null;

        if ($known === null || $known['map']->get() !== $map) {
            $known = [
                'map' => WeakReference::create($map),
                'base' => $known === null ? 0 : $known['last'] + 1 - $map->stateVersion(),
                'last' => 0,
            ];
        }

        $known['last'] = $known['base'] + $map->stateVersion();
        $this->mapRevisions[$map->mapId] = $known;

        return $known['last'];
    }

    private function requireCurrentMap(string $mapId, int $revision): ProjectMap
    {
        $map = $this->requireMap($mapId);
        if ($this->getMapRevision($map) !== $revision) {
            throw new SessionRefusal(sprintf('%s changed since revision %d; reload it and edit again.', $mapId, $revision));
        }

        return $map;
    }
}
