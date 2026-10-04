<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use Ichiloto\Editor\Backup\BackupSettings;
use Ichiloto\Editor\Backup\BackupWriter;
use Ichiloto\Editor\Canvas\CanvasEditor;
use Ichiloto\Editor\Canvas\PieceRole;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\ConditionEditor;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Database\RecordChange;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\SourceIdentityConflict;
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
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Storage\WorkspaceSave;
use Ichiloto\Editor\Validation\MapValidator;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Closure;
use InvalidArgumentException;
use RuntimeException;

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
    private function __construct(
        ProjectWorkspace $workspace,
        private readonly CommandHistory $history,
        private readonly BackupWriter $backups,
    ) {
        $this->workspace = $workspace;
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
        ];
    }

    /**
     * Every map, by stable id, with what a list shows about it.
     *
     * @return list<array{id: string, name: string, dirty: bool, readOnly: ?string, revision: int}>
     */
    public function describeMaps(): array
    {
        return array_map(static fn(ProjectMap $map): array => [
            'id' => $map->mapId,
            'name' => $map->getDisplayName(),
            'dirty' => $map->isDirty(),
            'readOnly' => $map->getGridSourceIssue(),
            'revision' => $map->stateVersion(),
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
            'revision' => $map->stateVersion(),
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
     * `graphicsIssue` says why.
     *
     * @return array{map: string, revision: int, assetRoot: string, operations: list<array<string, mixed>>, layerIds: array<string, string>, animated: bool, graphicsIssue: ?string}
     * @throws SessionRefusal When the map is unknown or its layers cannot be presented.
     */
    public function readWorld(string $mapId): array
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
            'revision' => $map->stateVersion(),
            'assetRoot' => $map->getAssetRoot(),
            'operations' => $world->getOperations(true, true),
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

        return ['map' => $map->mapId, 'revision' => $map->stateVersion(), 'layers' => $layers];
    }

    /**
     * Sets one tile in cells of a tile layer, `0` erasing, as one undo step
     * ({@see CanvasEditor::setTiles()}). A layer the map does not have yet is
     * created. Tiles never change glyphs or collision.
     *
     * @param list<array{0: int, 1: int}> $cells The cells, as [x, y].
     * @return array{changed: int, revision: int}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or refuses the tile.
     */
    public function paintTiles(string $mapId, int $revision, string $layerName, array $cells, int $tile, string $label = 'Place tiles'): array
    {
        return $this->stampTiles($mapId, $revision, $layerName, array_map(static fn(array $cell): array => [$cell[0], $cell[1], $tile], $cells), $label);
    }

    /**
     * Sets each cell of a tile layer to its own tile as one undo step
     * ({@see CanvasEditor::stampTiles()}): a block chosen in the palette or
     * picked from the map, stamped where the author drags.
     *
     * @param list<array{0: int, 1: int, 2: int}> $cells Each cell as [x, y, tile].
     * @return array{changed: int, revision: int}
     * @throws SessionRefusal When the map is unknown, stale or read-only, or refuses a tile.
     */
    public function stampTiles(string $mapId, int $revision, string $layerName, array $cells, string $label = 'Place tiles'): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }
        try {
            $applied = CanvasEditor::stampTiles($map, $layerName, $cells, $label);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        if ($applied['command'] !== null) {
            $this->history->record($applied['command']);
        }

        return ['changed' => $applied['changed'], 'revision' => $map->stateVersion()];
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

        return ['status' => 'applied', 'changed' => $applied['changed'], 'revision' => $map->stateVersion()];
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

        return ['status' => 'applied', 'revision' => $map->stateVersion(), 'changed' => $result['command'] !== null,
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
            'revision' => $map->stateVersion(),
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
                return ['status' => 'applied', 'revision' => $map->stateVersion(), 'changed' => false];
            }
        }

        $command = $this->runEdit(static fn(): ?Command => ($field['target'] ?? null) === 'map-kind'
            ? $inspector->changeMapKind($map, $value, $cleared > 0 && $answer === 'clear')
            : $inspector->apply($map, $field, $value));

        return ['status' => 'applied', 'revision' => $map->stateVersion(), 'changed' => $command !== null];
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

        return ['marker' => $created['marker'], 'revision' => $map->stateVersion()];
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

        return ['revision' => $map->stateVersion()];
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

        return ['revision' => $map->stateVersion(), 'changed' => $command !== null];
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

        return ['revision' => $map->stateVersion(), 'changed' => $command !== null];
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

        return ['revision' => $map->stateVersion(), 'changed' => $command !== null];
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

        return ['revision' => $map->stateVersion(), 'changed' => $result?->command !== null, 'message' => $result?->summary ?? 'Nothing changed.'];
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
     * A database category's records, as its list shows them, whether they
     * can be edited and have unsaved changes, and which record operations
     * the category takes. The bespoke categories (actors, classes, skills,
     * animations, quests, system) still build their rows in the terminal
     * editor, so they are refused here until those builders are shared.
     *
     * @return array{category: string, editable: bool, readOnly: ?string, dirty: bool, canCreate: bool, canDuplicate: bool, canDelete: bool, canReorder: bool, records: list<string>}
     * @throws SessionRefusal When the category is unknown or not yet served.
     */
    public function listDatabaseRecords(string $category): array
    {
        $database = $this->requireRecordDatabase($category);

        return [
            'category' => $category,
            'editable' => $database->isEditable(),
            'readOnly' => $database->getReadOnlyReason(),
            'dirty' => $database->isDirty(),
            'canCreate' => $database->supportsRecordCreation(),
            'canDuplicate' => $database->duplicateRecordSupported(),
            'canDelete' => $database->supportsRecordDeletion(),
            // Only where the file keeps the order; a move elsewhere is refused with why.
            'canReorder' => $database->supportsDurableReorder(),
            'records' => self::listRecordLabels($database),
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
     * remove act on it.
     *
     * @param array<int|string, mixed> $frame The frame; [] for the record itself.
     * @return array{category: string, index: int, frame: list<int|string>, frameLabel: ?string, editable: bool, readOnly: ?string, rows: list<array<string, mixed>>}
     * @throws SessionRefusal When the category, record or frame is unknown.
     */
    public function readDatabaseRecord(string $category, int $index, array $frame = []): array
    {
        $database = $this->requireRecordDatabase($category);
        $frame = self::requireFrame($frame, 'database.record');
        $fields = $this->collectRecordFields($database, $index, $frame);

        return [
            'category' => $category,
            'index' => $index,
            'frame' => $frame,
            'frameLabel' => $frame === [] ? null : $database->describeFramePath($frame),
            'editable' => $database->isEditable(),
            'readOnly' => $database->getReadOnlyReason(),
            'rows' => array_map(static fn(array $field): array => self::describeRecordRow(
                $database,
                $index,
                $frame,
                $field,
                isset($field['field']) && ($field['editable'] ?? true) !== false ? 'record' : null,
            ), $fields),
        ];
    }

    /**
     * Applies one record row's edit as one undo step. The row is found again
     * by its key among the record's current rows in its frame. A value its
     * field cannot take is refused with what is wrong with it.
     *
     * @param array<string, mixed> $key The row's key, as `database.record` gave it.
     * @return array{changed: bool, records: list<string>} Whether it changed, and the labels afterwards (a rename shows).
     * @throws SessionRefusal When the category or record is unknown or read-only, the row is gone or read-only, or the value is refused.
     */
    public function applyDatabaseRecord(string $category, int $index, array $key, string $value): array
    {
        $database = $this->requireRecordDatabase($category);
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

        $change = $this->changeRecord(static fn(RecordAuthoring $authoring): RecordChange => $authoring->applyField(
            $database, $index, $frame, $fieldId, $value, (string) ($field['label'] ?? 'Database field'),
        ));

        return ['changed' => $change->command !== null, 'records' => self::listRecordLabels($database)];
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
        $database = $this->requireRecordDatabase($category);
        $frame = self::requireFrame($key['frame'] ?? [], 'database.record');
        $fieldId = $key['field'] ?? null;

        if ($fieldId !== null && ! is_string($fieldId)) {
            throw new SessionRefusal('A row key names its field as a string, as database.record gave it.');
        }

        $change = $this->changeRecord(static fn(RecordAuthoring $authoring): RecordChange => $authoring->addItem($database, $index, $frame, $fieldId, $child));

        return ['changed' => $change->command !== null, 'records' => self::listRecordLabels($database)];
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
        $database = $this->requireRecordDatabase($category);
        $frame = self::requireFrame($key['frame'] ?? [], 'database.record');
        $fieldId = $key['field'] ?? null;

        if (! is_string($fieldId)) {
            throw new SessionRefusal('Name the row whose item to remove by its key, as database.record gave it.');
        }

        $change = $this->changeRecord(static fn(RecordAuthoring $authoring): RecordChange => $authoring->removeItem($database, $index, $frame, $fieldId));

        return ['changed' => $change->command !== null, 'records' => self::listRecordLabels($database)];
    }

    /**
     * Creates a blank record at the end of a category, as one undo step.
     *
     * @return array{index: int, records: list<string>} The new record's index, and the labels afterwards.
     * @throws SessionRefusal When the category is unknown, read-only or takes no new records.
     */
    public function createDatabaseRecord(string $category): array
    {
        $database = $this->requireRecordDatabase($category);
        $change = $this->changeRecord(static fn(RecordAuthoring $authoring): RecordChange => $authoring->createRecord($database));

        return ['index' => (int) $change->index, 'records' => self::listRecordLabels($database)];
    }

    /**
     * Duplicates a record below itself under a fresh identity, as one undo step.
     *
     * @return array{index: int, records: list<string>} The copy's index, and the labels afterwards.
     * @throws SessionRefusal When the category or record is unknown or read-only, or the record cannot be duplicated.
     */
    public function duplicateDatabaseRecord(string $category, int $index): array
    {
        $database = $this->requireRecordDatabase($category);
        $change = $this->changeRecord(static fn(RecordAuthoring $authoring): RecordChange => $authoring->duplicateRecord($database, $index));

        return ['index' => (int) $change->index, 'records' => self::listRecordLabels($database)];
    }

    /**
     * Deletes a record as one undo step; the file changes on save.
     *
     * @return array{index: ?int, records: list<string>} The record to select next (null when none is left), and the labels afterwards.
     * @throws SessionRefusal When the category or record is unknown or read-only, or the category keeps its entries.
     */
    public function deleteDatabaseRecord(string $category, int $index): array
    {
        $database = $this->requireRecordDatabase($category);
        $change = $this->changeRecord(static fn(RecordAuthoring $authoring): RecordChange => $authoring->deleteRecord($database, $index));

        return ['index' => $change->index, 'records' => self::listRecordLabels($database)];
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
        $database = $this->requireRecordDatabase($category);
        $step = match ($direction) {
            'up' => -1,
            'down' => 1,
            default => throw new SessionRefusal(sprintf('A record moves "up" or "down", not "%s".', $direction)),
        };
        $change = $this->changeRecord(static fn(RecordAuthoring $authoring): RecordChange => $authoring->moveRecord($database, $index, $step));

        return ['index' => (int) $change->index, 'changed' => $change->command !== null, 'records' => self::listRecordLabels($database)];
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
        $database = $this->requireRecordDatabase($category);
        if (! $database->isEditable()) {
            throw new SessionRefusal(sprintf('Read-only: %s.', $database->getReadOnlyReason() ?? 'this category cannot be written'));
        }
        if (! $database->isDirty()) {
            return ['saved' => false, 'warnings' => [], 'backupFailures' => []];
        }
        $paths = $database->getBackupPaths();
        $failures = $this->backups->isEnabled() && $paths !== [] ? $this->backups->backup(...$paths)['failed'] : [];

        try {
            $database->save();
        } catch (SourceIdentityConflict|SourcePreservationRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }

        return ['saved' => true, 'warnings' => [], 'backupFailures' => array_values($failures)];
    }

    /**
     * Makes one record change, records it, and turns a refusal into the session's.
     *
     * @param callable(RecordAuthoring): RecordChange $change
     * @throws SessionRefusal
     */
    private function changeRecord(callable $change): RecordChange
    {
        try {
            $applied = $change(new RecordAuthoring());
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
    private function collectRecordFields(ProjectRecordDatabase $database, int $index, array $frame): array
    {
        if ($database->getRecordByIndex($index) === null) {
            throw new SessionRefusal(sprintf('%s has no record %d.', $database->schema->key, $index));
        }
        if ($frame !== [] && (! $database->hasCommandFrames() || $database->getFrameCommands($index, $frame) === null)) {
            throw new SessionRefusal(sprintf('%s is no longer there; read the record again.', $database->describeFramePath($frame)));
        }

        return $database->getFrameSettingsFields($index, $frame);
    }

    /** @return list<string> A category's record labels, as its list shows them. */
    private static function listRecordLabels(ProjectRecordDatabase $database): array
    {
        return array_values(array_map('strval', $database->getEntryLabels()));
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
    private static function describeRecordRow(ProjectRecordDatabase $records, int $index, array $frame, array $field, ?string $target): array
    {
        $row = self::describeRow([...$field, 'target' => $target]);

        if (isset($row['key'])) {
            $row['key']['frame'] = $frame;
            $item = $records->locateItem($index, $frame, (string) ($field['field'] ?? ''));

            if ($item !== null) {
                $row['item'] = true;
                $row['itemNoun'] = $item->noun;
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
            'revision' => $map->stateVersion(),
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
            'revision' => $map->stateVersion(),
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
        $editable = is_string($fieldId) && $fieldId !== NpcInspector::ASSIGN_ID_FIELD && ($field['editable'] ?? true) !== false;

        return self::describeRecordRow($records, $index, $frame, $field, $editable ? 'npc' : null);
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

    /** Ends what the session started that would outlive it: a running playtest. */
    public function close(): void
    {
        $this->playtest?->stop();
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
        $before = array_map(static fn(ProjectMap $map): int => $map->stateVersion(), $this->workspace->maps);
        $databases = array_map(static fn(ProjectRecordDatabase $database): string => $database->getContentVersion(), $this->workspace->recordDatabases);
        $command = $step();
        $changed = $revisions = [];
        foreach ($this->workspace->maps as $index => $map) {
            if ($map->stateVersion() !== $before[$index]) {
                $changed[] = $map->mapId;
                $revisions[$map->mapId] = $map->stateVersion();
            }
        }
        $changedDatabases = array_keys(array_filter($this->workspace->recordDatabases,
            static fn(ProjectRecordDatabase $database, string $key): bool => $database->getContentVersion() !== $databases[$key], ARRAY_FILTER_USE_BOTH));

        return ['label' => $command?->label, 'maps' => $changed, 'revisions' => $revisions, 'databases' => array_values(array_map('strval', $changedDatabases))];
    }

    /** @return array<int, array<string, mixed>> */
    private function collectInspectorFields(ProjectMap $map, ?string $marker): array
    {
        $inspector = $this->createMapInspector($map);

        return [...$inspector->getMapFields($map), ...($marker === null ? [] : $inspector->getEventFields($map, $marker))];
    }

    private function createMapInspector(ProjectMap $map): MapInspector
    {
        return new MapInspector(new ReferenceCatalog($this->workspace, $map));
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
            ($field['mapConditions'] ?? false) === true, ($field['conditions'] ?? false) === true => 'conditions',
            ($field['worldWrites'] ?? false) === true => 'writes',
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
                'writes' => (string) ($field['value'] ?? ''),
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
            'list' => is_array($list) && $target !== null ? ['index' => (int) ($list['index'] ?? 0)] : null,
            // Conditions and writes are built a part at a time from these.
            'entries' => match ($kind) {
                'conditions' => ConditionCodec::decodeAll(is_string($field['encoded'] ?? null) ? $field['encoded'] : (string) ($field['value'] ?? '')),
                'writes' => WorldWriteCodec::decodeAll((string) ($field['value'] ?? '')),
                default => null,
            },
            'writeTypes' => $kind === 'writes' && is_array($field['writeTypes'] ?? null) ? array_values($field['writeTypes']) : null,
            // One axis of a coordinate pair under the heading before it.
            'axis' => in_array($field['axis'] ?? null, ['x', 'y'], true) ? $field['axis'] : null,
            'key' => $key,
        ], static fn(mixed $value): bool => $value !== null);
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
        if (! array_any(DatabaseCatalog::all(), static fn($definition): bool => $definition->key === $category)) {
            throw new SessionRefusal(sprintf('There is no database category %s.', $category));
        }

        return $this->workspace->getRecordDatabase($category)
            ?? throw new SessionRefusal(sprintf('The %s database is edited in the terminal editor for now.', $category));
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
    private function requireCurrentMap(string $mapId, int $revision): ProjectMap
    {
        $map = $this->requireMap($mapId);
        if ($map->stateVersion() !== $revision) {
            throw new SessionRefusal(sprintf('%s changed since revision %d; reload it and edit again.', $mapId, $revision));
        }

        return $map;
    }
}
