<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use Ichiloto\Editor\Backup\BackupSettings;
use Ichiloto\Editor\Backup\BackupWriter;
use Ichiloto\Editor\Canvas\CanvasEditor;
use Ichiloto\Editor\Canvas\PieceRole;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\MapValidator;

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
