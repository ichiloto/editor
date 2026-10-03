<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use Ichiloto\Editor\Backup\BackupSettings;
use Ichiloto\Editor\Backup\BackupWriter;
use Ichiloto\Editor\Canvas\CanvasEditor;
use Ichiloto\Editor\Canvas\PieceRole;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\Inspector\InspectorRefusal;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\Maps\TilePalette;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\MapValidator;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use InvalidArgumentException;

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
    private ProjectWorkspace $workspace;

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

        $events = [];
        foreach ($map->getEventDefinitions() as $marker => $definition) {
            $area = $map->getEventArea((string) $marker);
            $events[] = [
                'marker' => (string) $marker,
                'type' => EventTypeCatalog::describeClass(is_array($definition) && is_string($definition['class'] ?? null) ? $definition['class'] : null),
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
            'operations' => $world->getOperations(true),
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
     * The inspector rows of a map, and of one of its events when one is
     * named, as an interface lists and edits them. Each row says how it is
     * edited (`kind`) and carries the `key` an edit names it by.
     *
     * Kinds: `text`, `integer`, `float`, `boolean`, `options` (one of
     * `options`), `reference` (one of `references.list` for its
     * `reference`), or `info` for a row that is only read here: headings,
     * counts, and the rows the terminal edits through flows of its own (the
     * map's kind, an event's type, music variant conditions).
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
     * @param array<string, mixed> $key The row's key, as `readInspector` gave it.
     * @return array{revision: int, changed: bool}
     * @throws SessionRefusal When the map is unknown or stale, the row is gone or read-only, or the edit is refused.
     */
    public function applyInspector(string $mapId, int $revision, array $key, string $value): array
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        $marker = is_string($key['marker'] ?? null) ? $key['marker'] : null;
        $field = array_find($this->collectInspectorFields($map, $marker),
            static fn(array $candidate): bool => self::describeKey($candidate) === $key);

        if ($field === null) {
            throw new SessionRefusal('That row is no longer in the inspector; read it again.');
        }
        if (self::describeRow($field)['kind'] === 'info') {
            throw new SessionRefusal(sprintf('%s cannot be edited here.', trim((string) ($field['label'] ?? 'That row'))));
        }

        try {
            $command = $this->createMapInspector($map)->apply($map, $field, $value);
        } catch (InspectorRefusal $refusal) {
            throw new SessionRefusal(implode("\n", [$refusal->getMessage(), ...$refusal->details]), previous: $refusal);
        }
        if ($command !== null) {
            $this->history->record($command);
        }

        return ['revision' => $map->stateVersion(), 'changed' => $command !== null];
    }

    /**
     * The values a reference row is chosen from, with how each reads.
     *
     * @return list<array{value: string, label: string}>
     * @throws SessionRefusal When the map is unknown or the category is not a reference.
     */
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
     * A database category's records, as its list shows them, and whether
     * they can be edited. The bespoke categories (actors, classes, skills,
     * animations, quests, system) still build their rows in the terminal
     * editor, so they are refused here until those builders are shared.
     *
     * @return array{category: string, editable: bool, readOnly: ?string, records: list<string>}
     * @throws SessionRefusal When the category is unknown or not yet served.
     */
    public function listDatabaseRecords(string $category): array
    {
        $database = $this->requireRecordDatabase($category);

        return [
            'category' => $category,
            'editable' => $database->isEditable(),
            'readOnly' => $database->getReadOnlyReason(),
            'records' => array_values(array_map('strval', $database->getEntryLabels())),
        ];
    }

    /**
     * One record's rows, described as the inspector's are.
     *
     * @return array{category: string, index: int, rows: list<array<string, mixed>>}
     * @throws SessionRefusal When the category or record is unknown.
     */
    public function readDatabaseRecord(string $category, int $index): array
    {
        $database = $this->requireRecordDatabase($category);
        if ($database->getRecordByIndex($index) === null) {
            throw new SessionRefusal(sprintf('%s has no record %d.', $category, $index));
        }

        return [
            'category' => $category,
            'index' => $index,
            'rows' => array_map(static fn(array $field): array => self::describeRow([
                ...$field,
                'target' => isset($field['field']) && ($field['editable'] ?? true) !== false ? 'record' : null,
            ]), $database->getSettingsFields($index)),
        ];
    }

    /**
     * Undoes the last change, wherever it was made.
     *
     * @return array{label: ?string, maps: list<string>} What was undone and the maps it changed.
     */
    public function undo(): array
    {
        return $this->traverseHistory(fn(): ?Command => $this->history->undo());
    }

    /**
     * Redoes the last undone change.
     *
     * @return array{label: ?string, maps: list<string>}
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

    /** Whether any map or database has changes not yet saved. */
    public function hasUnsavedChanges(): bool
    {
        return $this->workspace->hasUnsavedChanges();
    }

    /**
     * Runs one history step and names the maps it changed.
     *
     * @param callable(): ?Command $step
     * @return array{label: ?string, maps: list<string>}
     */
    private function traverseHistory(callable $step): array
    {
        $before = array_map(static fn(ProjectMap $map): int => $map->stateVersion(), $this->workspace->maps);
        $command = $step();
        $changed = [];
        foreach ($this->workspace->maps as $index => $map) {
            if ($map->stateVersion() !== $before[$index]) {
                $changed[] = $map->mapId;
            }
        }

        return ['label' => $command?->label, 'maps' => $changed];
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
        $kind = match (true) {
            ($field['editable'] ?? true) === false, $target === null,
            in_array($target, ['map-kind', 'event-type'], true),
            ($field['mapConditions'] ?? false) === true => 'info',
            is_string($field['reference'] ?? null) => 'reference',
            is_array($field['options'] ?? null) => 'options',
            $control !== null => match ($control->type) {
                InputControlType::INTEGER => 'integer',
                InputControlType::FLOAT => 'float',
                InputControlType::BOOLEAN => 'boolean',
                default => 'text',
            },
            default => 'info',
        };

        return array_filter([
            'label' => (string) ($field['label'] ?? ''),
            'value' => (string) ($field['value'] ?? ''),
            'kind' => $kind,
            'options' => $kind === 'options' ? array_values(array_map('strval', $field['options'])) : null,
            'reference' => $kind === 'reference' ? $field['reference'] : null,
            'key' => self::describeKey($field),
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
