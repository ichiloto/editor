<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use FilesystemIterator;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\EngineDataBootstrap;
use Ichiloto\Editor\Database\ProjectRecord;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Database\ProjectCatalogs;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\QuestReferences;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\MasteryFields;
use Ichiloto\Editor\Database\SkillReferences;
use Ichiloto\Editor\Events\ProjectScriptCommands;
use Ichiloto\Editor\Events\ScriptCommandUses;
use Ichiloto\Editor\Maps\MapFolder;
use Ichiloto\Editor\Storage\FileSetOperations;
use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Editor\Storage\FilesystemFileSetOperations;
use Ichiloto\Engine\Core\ProjectFormat;
use Ichiloto\Engine\Entities\Elements\ElementRegistry;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\Reachability\ProjectReachability;
use RecursiveDirectoryIterator;
use InvalidArgumentException;
use LogicException;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Represents the currently opened Ichiloto project in the editor.
 */
final readonly class ProjectWorkspace
{
    /** Preview title and map metadata lines preceding the rendered grid. */
    public const int CANVAS_HEADER_ROWS = 2;
    /** The project's catalogues as this workspace holds them, unsaved edits included. */
    public ProjectCatalogs $catalogs;
    /**
     * @param ProjectMap[] $maps
     * @param array<string, ProjectRecordDatabase> $recordDatabases Schema-driven categories, keyed by category key.
     * @param CutsceneLibrary|null $cutscenes The project's cinematics and summons.
     */
    public function __construct(
        public string                   $projectRoot,
        public string                   $projectId,
        public string                   $projectName,
        public string                   $mainFile,
        public array                    $maps,
        public array                    $mapIds,
        public ProjectActorDatabase     $actorDatabase,
        public array                    $recordDatabases = [],
        public ?CutsceneLibrary         $cutscenes = null,
        public ?ProjectConfig           $config = null,
        public ?MenuPresentationSettings $menuPresentation = null,
        public ?ProjectScriptCommands   $scriptCommands = null,
    ) {
        // A quest's id follows its name until something refers to it, and
        // what refers to a quest lives across the whole project.
        $this->getRecordDatabase('quests')?->useIdentityReferences(
            fn(string $questId): bool => new QuestReferences($this)->exist($questId),
        );
        // An income schedule's id follows its name until a script names it, and stays while one does.
        $incomeUses = fn(string $id): array => ScriptCommandUses::findReferenceUses($this,
            $this->cutscenes?->assets(CutsceneType::CINEMATIC) ?? [],
            ScriptCommandReference::INCOME_SCHEDULE)[$id] ?? [];
        $this->getRecordDatabase('income_schedules')?->useIdentityReferences(fn(string $id): bool => $incomeUses($id) !== []);
        $this->getRecordDatabase('income_schedules')?->useDeletionReferences($incomeUses);
        // Marker roles follow the place categories as their page holds them, unsaved edits included.
        $this->menuPresentation?->useCategoryCatalog(fn(): \Ichiloto\Engine\Field\MapCategoryCatalog => new ReferenceCatalog($this)->getMapCategoryCatalog());
        // A place category stays while a landmark names it.
        $this->getRecordDatabase(\Ichiloto\Editor\Field\MapCategoryFields::CATEGORY)?->useDeletionReferences(
            fn(string $category): array => \Ichiloto\Editor\Field\MapLandmarks::describeCategoryReferences($this->maps, $category),
        );
        // A map role stays while a map is of it.
        $this->getRecordDatabase(\Ichiloto\Editor\Field\MapRoleFields::CATEGORY)?->useDeletionReferences(
            fn(string $role): array => \Ichiloto\Editor\Field\MapRoleFields::describeRoleReferences($this->maps, $role),
        );
        // A place type stays while a map is of it or a tileset gives it an icon.
        $this->getRecordDatabase(\Ichiloto\Editor\Field\PlaceTypeFields::CATEGORY)?->useDeletionReferences(
            fn(string $type): array => \Ichiloto\Editor\Field\PlaceTypeFields::describeTypeReferences($this->maps, array_column(array_map(
                static fn(\Ichiloto\Editor\Database\ProjectRecord $record): array => ['id' => $record->recordId, 'data' => (array) $record->toArray()],
                $this->getRecordDatabase('tilesets')?->getRecords() ?? []), 'data', 'id'), $type),
        );
        // A skill stays while a class, actor, enemy, skill or state names it; skills.retire moves or removes those first.
        $this->getRecordDatabase('skills')?->useDeletionReferences(fn(string $skill): array => $this->describeSkillUses($skill));
        $references = new ReferenceCatalog($this);
        foreach ($this->recordDatabases as $database) { $database->useAuthoringReferences($references); }
        // A reusable script chooses its map-local subjects on the map its callers run it on.
        $this->getRecordDatabase('common_events')?->useRecordReferences(
            static fn(\Ichiloto\Editor\Database\ProjectRecord $record): ReferenceCatalog => $references->createCommonEventContext($record->recordId));
        // A map names its tileset and field resources as their pages hold them, unsaved edits included.
        $this->catalogs = new ProjectCatalogs($this);
        foreach ($this->maps as $map) { $map->useCatalogs($this->catalogs); }
        $this->cutscenes?->useAuthoringReferences($references);
    }

    /**
     * One setting of `system.php` as the System record holds it, unsaved
     * edits included: the starting party, the starting positions.
     */
    public function getSystemField(string $key): mixed
    {
        return $this->getRecordDatabase('system')?->getRecordByIndex(0)?->get($key);
    }

    /**
     * The elements the game knows, as the Types record holds them, unsaved
     * edits included: the list `system.php` declares, or the Engine's
     * defaults when it lists none, read as the Engine's registry reads them.
     *
     * @return list<string> The element identities, in authored order.
     * @throws InvalidArgumentException When the list names an element twice or names an empty one.
     */
    public function getElementIdentities(): array
    {
        $authored = $this->getRecordDatabase('types')?->getRecordByIndex(0)?->get('elements');

        return ElementRegistry::getCanonicalIdentities(is_array($authored) ? $authored : []);
    }

    /**
     * The project's quests as validation and the journal panes read them.
     *
     * @return list<ProjectQuest>
     */
    public function getQuests(): array
    {
        return array_map(
            static fn(ProjectRecord $record): ProjectQuest => new ProjectQuest((array) $record->toArray()),
            array_values($this->getRecordDatabase('quests')?->getRecords() ?? []),
        );
    }

    /**
     * What a project data file holds as the workspace stands: the payload
     * its loaded databases would write to it now, every unsaved edit folded
     * in, including those of an open change set; or null when none holds an
     * unsaved edit for it, so the file on disk is current. A file several
     * categories share (items, weapons and armors) is read once from disk
     * and each dirty category folds its part in, as a save would. Every
     * reader of a catalogue or record by its path reads it through here
     * ({@see \Ichiloto\Editor\Database\ProjectCatalogs}), so an edit made
     * earlier in a session reaches the reads after it before anything is
     * written.
     *
     * @return array{exists: bool, payload: mixed}|null
     */
    public function readCurrentData(string $path): ?array
    {
        $path = ProjectRecordDatabase::canonicalizePath($path);
        $sharing = array_values(array_filter($this->recordDatabases, static fn(ProjectRecordDatabase $database): bool =>
            $database->isDirty() && $database->sharesBackingFile() && ProjectRecordDatabase::canonicalizePath($database->backingFilePath()) === $path));
        if ($sharing !== []) {
            $file = PhpDataFile::load($path, $this->projectRoot);
            $payload = is_array($file->payload) ? $file->payload : [];
            foreach ($sharing as $database) {
                $payload = $database->foldInto($payload);
            }

            return ['exists' => true, 'payload' => $payload];
        }
        foreach ($this->recordDatabases as $database) {
            $current = $database->isDirty() ? $database->readCurrentRecordFile($path) : null;
            if ($current !== null) {
                return $current;
            }
        }
        // A cinematic, summon or effect holds its files as the Cutscenes workspace edits them.
        foreach ($this->cutscenes?->dirtyAssets() ?? [] as $asset) {
            foreach ($asset->paths() as $assetPath) {
                if (ProjectRecordDatabase::canonicalizePath($assetPath) === $path) {
                    return $asset->isDeleted() ? ['exists' => false, 'payload' => null]
                        : ['exists' => true, 'payload' => $assetPath === $asset->partnerPath() ? $asset->partner() : $asset->data()];
                }
            }
        }

        return null;
    }

    /**
     * Returns the schema-driven database for a category key.
     *
     * @param string $categoryKey The Database category key.
     * @return ProjectRecordDatabase|null
     */
    public function getRecordDatabase(string $categoryKey): ?ProjectRecordDatabase
    {
        return $this->recordDatabases[$categoryKey] ?? null;
    }

    /**
     * Says what names a skill, unsaved edits included: classes, actors,
     * enemies, other skills and states, in words.
     *
     * @return list<string>
     */
    public function describeSkillUses(string $skill): array
    {
        $records = [];
        foreach (['classes' => 'Class', 'enemies' => 'Enemy', 'skills' => 'Skill', 'states' => 'State',
            MasteryFields::REGIONS => 'Mastery region', MasteryFields::BRANCHES => 'Mastery branch'] as $category => $noun) {
            foreach ($this->getRecordDatabase($category)?->getRecords() ?? [] as $record) {
                $label = sprintf('%s %s', $noun, strval($record->get('name') ?? $record->get('actor') ?? $record->recordId));
                if ($category === 'skills' && $record->get('name') === $skill) { continue; }
                $records[$label] = $record->toArray();
            }
        }
        foreach ($this->actorDatabase->getActors() as $actor) {
            $records['Actor ' . $actor->getName()] = $actor->getData();
        }

        return SkillReferences::describe($records, $skill);
    }

    /**
     * Reads the project's skill catalogue as the workspace holds it: every
     * skill record, unsaved edits included, as the runtime reads them,
     * identified and classified as the runtime finds them.
     *
     * @return SkillCatalog
     */
    public function loadSkillCatalog(): SkillCatalog
    {
        return $this->catalogs->loadSkillCatalog();
    }

    /**
     * Returns every skill name a reference may use: the Skills records as
     * currently edited, in the order menus list them.
     *
     * @return string[]
     */
    public function getSkillNames(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(ProjectRecord $record): string => trim(strval($record->get('name') ?? '')),
            $this->getRecordDatabase('skills')?->getRecords() ?? [],
        ), static fn(string $name): bool => $name !== '')));
    }

    /**
     * Returns the maps root for the project.
     *
     * @return string
     */
    public function getMapsRoot(): string
    {
        return rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'Maps';
    }

    /**
     * Loads a workspace from the provided project root.
     *
     * @param string $projectRoot The project root.
     * @return self
     */
    public static function fromProject(string $projectRoot, bool $graphical = false): self
    {
        return ProjectDirectoryContext::run(
            $projectRoot,
            static fn(string $canonicalRoot): self => self::loadFromProject($canonicalRoot, $graphical),
        );
    }

    /**
     * Loads a workspace while the process is scoped to its absolute root.
     *
     * @param string $projectRoot Absolute project root.
     * @return self
     */
    private static function loadFromProject(string $projectRoot, bool $graphical): self
    {
        $configPath = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ichiloto.json';

        if (! is_file($configPath)) {
            throw new RuntimeException("ichiloto.json not found in $projectRoot.");
        }

        $config = json_decode((string) file_get_contents($configPath), true);

        if (! is_array($config)) {
            throw new RuntimeException("Unable to parse $configPath.");
        }

        // A project in another format places its contents differently; open
        // nothing rather than misplace it. `ichiloto upgrade` converts it.
        ProjectFormat::assertSupported($config[ProjectFormat::KEY] ?? null);

        $projectName = (string) ($config['name'] ?? basename($projectRoot));
        $projectId = trim((string) ($config['id'] ?? ''));
        $mainFile = (string) ($config['main'] ?? '');

        // enemies.php constructs BattleRewards, which demands a registered
        // item store; without this the category would report a bootstrap
        // failure instead of the author's enemies.
        EngineDataBootstrap::ensure($projectRoot);
        $projectConfig = new ProjectConfig($projectRoot);
        // Each settings file has one owner, shared by every category over it.
        $menuPresentation = new MenuPresentationSettings($projectRoot);
        $settingsFiles = ['config.php' => $projectConfig, MenuPresentationSettings::RELATIVE_PATH => $menuPresentation,
            FieldPresentationSettings::RELATIVE_PATH => new FieldPresentationSettings($projectRoot)];
        // Before any schema is built: the command editor offers, and Engine
        // validation accepts, exactly this project's registered commands.
        $scriptCommands = ProjectScriptCommands::fromProject($projectRoot);
        $scriptCommands->activate();

        return new self(
            projectRoot: $projectRoot,
            projectId: $projectId,
            projectName: $projectName,
            mainFile: $mainFile,
            maps: $maps = self::discoverMaps($projectRoot),
            mapIds: array_map(static fn(ProjectMap $map): string => $map->mapId, $maps),
            actorDatabase: ProjectActorDatabase::fromProject($projectRoot),
            recordDatabases: array_map(
                static fn(RecordSchema $schema): ProjectRecordDatabase => ProjectRecordDatabase::fromProject($projectRoot, $schema,
                    $settingsFiles[$schema->relativePath] ?? null, $graphical),
                RecordSchemaCatalog::all($graphical),
            ),
            cutscenes: CutsceneLibrary::fromProject($projectRoot),
            config: $projectConfig,
            menuPresentation: $menuPresentation,
            scriptCommands: $scriptCommands,
        );
    }

    /**
     * Returns summary lines for the project inspector.
     *
     * @param int $selectedMapIndex The selected map index.
     * @return string[]
     */
    public function getInspectorLines(int $selectedMapIndex = 0): array
    {
        $selectedMap = $this->getMapByIndex($selectedMapIndex);

        return [
            "Project: {$this->projectName}",
            "Root: {$this->projectRoot}",
            "Main: {$this->mainFile}",
            '',
            sprintf('Maps discovered: %d', count($this->mapIds)),
            '',
            ...($selectedMap instanceof ProjectMap ? [
                "Map: {$selectedMap->mapId}",
                "Name: {$selectedMap->getDisplayName()}",
                "Region: {$selectedMap->getRegion()}",
                sprintf('Size: %d x %d', $selectedMap->getWidth(), $selectedMap->getHeight()),
                sprintf('Events: %d', $selectedMap->getEventDefinitionCount()),
                sprintf('Triggers: %d', $selectedMap->getTriggerCount()),
                ...($selectedMap->getGridSourceIssue() === null ? [] : [
                    'Read-only: map source needs repair.',
                    $selectedMap->getGridSourceIssue(),
                ]),
                '',
                basename($selectedMap->dataPath),
                $selectedMap->isLegacyMap() ? basename($selectedMap->mapPath) : 'layers/ (' . (count($selectedMap->getLayers()) - 1) . ' layers)',
                basename($selectedMap->eventPath),
            ] : [
                'No map selected.',
            ]),
        ];
    }

    /**
     * Returns tree lines for the asset sidebar.
     *
     * @param int $selectedMapIndex The selected map index.
     * @param int[]|null $visibleIndexes The map indexes surviving the `/` filter, in display order; null shows every map.
     * @return string[]
     */
    public function getAssetLines(int $selectedMapIndex = 0, ?array $visibleIndexes = null): array
    {
        $lines = ["Maps"];

        if ($this->mapIds === []) {
            $lines[] = "  (none found)";
            return $lines;
        }

        $indexes = $visibleIndexes ?? array_keys($this->mapIds);

        if ($indexes === []) {
            $lines[] = "  (no matches)";
            return $lines;
        }

        foreach ($indexes as $index) {
            $mapId = $this->mapIds[$index] ?? null;

            if ($mapId === null) {
                continue;
            }

            $prefix = $index === $selectedMapIndex ? '> ' : '  ';
            $dirty = $this->maps[$index]->isDirty() ? ' *' : '';
            $readOnly = $this->maps[$index]->getGridSourceIssue() === null ? '' : ' [read-only]';
            $lines[] = sprintf('%s%s%s%s', $prefix, $mapId, $dirty, $readOnly);
        }

        return $lines;
    }

    /**
     * Returns whether any map or database holds unsaved changes.
     *
     * @return bool
     */
    public function hasUnsavedChanges(): bool
    {
        foreach ($this->maps as $map) {
            if ($map->isDirty()) {
                return true;
            }
        }

        foreach ($this->recordDatabases as $database) {
            if ($database->isDirty()) {
                return true;
            }
        }

        return $this->actorDatabase->isDirty()
            || ($this->config?->isDirty() ?? false)
            || ($this->menuPresentation?->isDirty() ?? false)
            || ($this->cutscenes?->hasUnsavedChanges() ?? false);
    }

    /**
     * Analyses where the player can reach as saving would leave the project:
     * maps with unsaved changes are read as this workspace holds them.
     */
    public function analyzeReachability(): ProjectReachability
    {
        $unsaved = [];
        foreach ($this->maps as $map) {
            if ($map->isDirty()) {
                $unsaved[$map->mapId] = ['data' => $map->getEditableData(), 'layers' => $map->getLayerSet(), 'eventLayer' => $map->getEventLayerCells()];
            }
        }

        return ProjectReachability::analyze($this->projectRoot . DIRECTORY_SEPARATOR . 'assets', $unsaved, $this->catalogs->loadTileset(...));
    }

    /**
     * Names every document holding unsaved changes: maps by id, then
     * databases, then cutscenes, so a guard can say what would be lost.
     *
     * @return list<string>
     */
    public function listUnsavedChanges(): array
    {
        $unsaved = [];

        foreach ($this->maps as $map) {
            if ($map->isDirty()) {
                $unsaved[] = $map->mapId;
            }
        }

        foreach ($this->listSaveableDatabases() as $label => $database) {
            if ($database->isDirty()) {
                $unsaved[] = $label . ' database';
            }
        }

        foreach ($this->cutscenes?->dirtyAssets() ?? [] as $asset) {
            $unsaved[] = $asset->type->noun() . ' ' . $asset->id;
        }

        return $unsaved;
    }

    /**
     * Returns every database a save can write, keyed by display label.
     * Read-only record categories are left out: they hold no edits, and
     * asking them to save would raise instead of doing nothing.
     *
     * @return array<string, ProjectActorDatabase|ProjectSettingsFile|ProjectRecordDatabase>
     */
    public function listSaveableDatabases(): array
    {
        $databases = [
            'Actors' => $this->actorDatabase,
        ];

        if ($this->config !== null) {
            $databases['Project configuration'] = $this->config;
        }

        if ($this->menuPresentation !== null) {
            $databases['Menu theme'] = $this->menuPresentation;
        }

        foreach ($this->recordDatabases as $categoryKey => $recordDatabase) {
            if ($recordDatabase->isEditable()) {
                // By its own label: an embedded category (battle art) has no
                // Database list position, and naming it by one replaced the
                // Actors database here, hiding it from Save All.
                $category = DatabaseCatalog::findByKey($categoryKey)
                    ?? throw new LogicException(sprintf('The record category %s is not in the Database catalog.', $categoryKey));
                $databases[$category->label] = $recordDatabase;
            }
        }

        return $databases;
    }

    /**
     * Returns a workspace with one map swapped for a fresh instance, keeping
     * every other loaded object intact (no whole-workspace reload).
     *
     * The map list is re-sorted by map id so a renamed map lands where a
     * full rescan would have placed it.
     *
     * @param int $index The map index being replaced.
     * @param ProjectMap $map The replacement map.
     * @return self
     */
    public function withReplacedMap(int $index, ProjectMap $map): self
    {
        $maps = $this->maps;
        $maps[$index] = $map;

        return $this->withMaps($maps);
    }

    /**
     * Returns a workspace that also holds a map just written to disk, such
     * as a created or duplicated one, keeping every loaded object and its
     * unsaved changes. A map that cannot be read joins read-only, as a full
     * rescan would add it.
     */
    public function withLoadedMap(string $mapId): self
    {
        $directory = $this->getMapsRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mapId);
        try {
            $map = ProjectMap::fromDirectory($this->getMapsRoot(), $directory);
        } catch (Throwable $error) {
            $map = ProjectMap::createReadOnlyFromDirectory($this->getMapsRoot(), $directory, $error->getMessage());
        }
        $maps = array_values(array_filter($this->maps, static fn(ProjectMap $loaded): bool => $loaded->mapId !== $map->mapId));

        return $this->withMaps([...$maps, $map]);
    }

    /** Returns a workspace without a deleted map, keeping every other loaded object. */
    public function withoutMap(string $mapId): self
    {
        return $this->withMaps(array_values(array_filter($this->maps, static fn(ProjectMap $map): bool => $map->mapId !== $mapId)));
    }

    /**
     * The same project with another map list, in map id order as a full
     * rescan lists them.
     *
     * @param list<ProjectMap> $maps
     */
    private function withMaps(array $maps): self
    {
        usort($maps, static fn(ProjectMap $left, ProjectMap $right): int => strcmp($left->mapId, $right->mapId));

        return new self(
            projectRoot: $this->projectRoot,
            projectId: $this->projectId,
            projectName: $this->projectName,
            mainFile: $this->mainFile,
            maps: $maps,
            mapIds: array_map(static fn(ProjectMap $workspaceMap): string => $workspaceMap->mapId, $maps),
            actorDatabase: $this->actorDatabase,
            recordDatabases: $this->recordDatabases,
            cutscenes: $this->cutscenes,
            config: $this->config,
            menuPresentation: $this->menuPresentation,
            scriptCommands: $this->scriptCommands,
        );
    }

    /**
     * Returns a preview of the selected map.
     *
     * @param int $selectedMapIndex The selected map index.
     * @param int $width The preview width.
     * @param int $height The preview height.
     * @param int $offsetX The horizontal preview offset.
     * @param int $offsetY The vertical preview offset.
     * @param bool $showEventOverlay Whether event markers should be rendered.
     * @param array<int, array<int, string|null>> $stampPreview A stamp's footprint, as ProjectMap::renderPreview() takes it.
     * @return string[]
     */
    public function getCanvasLines(
        int $selectedMapIndex,
        int $width,
        int $height,
        int $offsetX = 0,
        int $offsetY = 0,
        bool $showEventOverlay = true,
        bool $showNpcOverlay = false,
        ?int $selectedNpcIndex = null,
        ?string $selectedNpcSprite = null,
        ?array $cursor = null,
        array $layerVisibility = [],
        ?string $activeLayer = null,
        bool $terminalPreview = false,
        bool $dimInactive = false,
        array $stampPreview = [],
    ): array
    {
        $selectedMap = $this->getMapByIndex($selectedMapIndex);

        if (! $selectedMap instanceof ProjectMap) {
            return [
                'No maps found in assets/Maps.',
                '',
                'Use `ichiloto generate:map` or add a map folder to begin.',
            ];
        }

        if ($selectedMap->getGridSourceIssue() !== null) {
            return [
                sprintf('Preview: %s', $selectedMap->mapId),
                'Read-only: repair this map before editing.',
                $selectedMap->getGridSourceIssue(),
            ];
        }

        $previewHeight = max(0, $height - self::CANVAS_HEADER_ROWS);
        $previewLines = $selectedMap->renderPreview($width, $previewHeight, $offsetX, $offsetY, $showEventOverlay, $showNpcOverlay, $selectedNpcIndex, $selectedNpcSprite, $layerVisibility, $activeLayer, $terminalPreview, $dimInactive, $stampPreview);

        return [
            sprintf('Preview: %s', $selectedMap->mapId),
            sprintf(
                '%s | %s | %d x %d | view %d,%d%s',
                $selectedMap->getDisplayName(),
                $selectedMap->getRegion(),
                $selectedMap->getWidth(),
                $selectedMap->getHeight(),
                $offsetX,
                $offsetY,
                $cursor === null ? '' : sprintf(' | cursor %d,%d', $cursor['x'], $cursor['y']),
            ),
            ...$previewLines,
        ];
    }

    /**
     * Returns the map at the specified asset index.
     *
     * @param int $index The selected map index.
     * @return ProjectMap|null
     */
    public function getMapByIndex(int $index): ?ProjectMap
    {
        return $this->maps[$index] ?? null;
    }

    /**
     * Creates a new blank map under the maps root.
     *
     * @param string|null $baseName The preferred base name.
     * @return string The created map id.
     */
    public function createMap(?string $baseName = null, ?FileSetOperations $files = null, ?string $kind = null, int $width = 48,
        int $height = 18): string
    {
        $baseName = $this->getNextAvailableBaseName($baseName ?? 'new-map');

        return $this->createMapAt($baseName, null, $files, $kind, $width, $height);
    }

    /**
     * Creates a new blank map at the id an author chose: a folder path under the maps root, nested inside another
     * map's folder or any other folder ({@see MapFolder}).
     *
     * @param string $mapId The new map's id.
     * @param string|null $displayName Its name; null names it after the id's last segment.
     * @return string The created map id.
     * @throws MapSourceRefusal When the size is no size, the id is not a map folder path or its folder is taken, or
     *     the files cannot be written.
     */
    public function createMapAt(string $mapId, ?string $displayName = null, ?FileSetOperations $files = null, ?string $kind = null,
        int $width = 48, int $height = 18): string
    {
        if ($width < 1 || $height < 1) {
            throw new MapSourceRefusal(sprintf('A map is at least 1 x 1 cells, not %d x %d.', $width, $height));
        }
        $directory = self::requireFreeMapFolder($this->getMapsRoot(), $mapId);
        $baseName = basename($directory);
        ProjectMap::createBlank($directory, $baseName, $displayName ?? self::humanizeBaseName($baseName), $width, $height, $files, $kind);

        return $mapId;
    }

    /**
     * Duplicates the selected map with a smart sibling name, or at the id an author chose ({@see MapFolder}). The
     * copy is named after its id's last segment.
     *
     * @param int $selectedMapIndex The selected map index.
     * @param string|null $mapId The copy's id; null takes the next free `-copy` sibling.
     * @return string|null The duplicated map id.
     * @throws MapSourceRefusal When the map is read-only, the chosen id is not a map folder path or its folder is
     *     taken, or the copy cannot be written.
     */
    public function duplicateMap(int $selectedMapIndex, ?FileSetOperations $files = null, ?string $mapId = null): ?string
    {
        $selectedMap = $this->getMapByIndex($selectedMapIndex);

        if (! $selectedMap instanceof ProjectMap) {
            return null;
        }

        if ($selectedMap->getGridSourceIssue() !== null) {
            throw new MapSourceRefusal("{$selectedMap->mapId} is read-only: {$selectedMap->getGridSourceIssue()}");
        }

        $mapsRoot = $this->getMapsRoot();
        if ($mapId === null) {
            $parentDirectory = dirname($selectedMap->directory);
            $directory = $parentDirectory . DIRECTORY_SEPARATOR
                . $this->getNextAvailableSiblingBaseName($parentDirectory, basename($selectedMap->directory) . '-copy');
        } else {
            $directory = self::requireFreeMapFolder($mapsRoot, $mapId);
        }
        $baseName = basename($directory);
        $selectedMap->duplicateTo($directory, $baseName, self::humanizeBaseName($baseName), $files);

        $relativeDirectory = substr($directory, strlen($mapsRoot) + 1);

        return str_replace(DIRECTORY_SEPARATOR, '/', $relativeDirectory);
    }

    /**
     * Deletes the selected map directory and all associated split files.
     *
     * @param int $selectedMapIndex The selected map index.
     * @return string|null The deleted map id.
     */
    public function deleteMap(int $selectedMapIndex, ?FileSetOperations $files = null): ?string
    {
        $selectedMap = $this->getMapByIndex($selectedMapIndex);

        if (! $selectedMap instanceof ProjectMap) {
            return null;
        }

        if ($selectedMap->getGridSourceIssue() !== null) {
            throw new MapSourceRefusal("{$selectedMap->mapId} is read-only: {$selectedMap->getGridSourceIssue()}");
        }

        // Only the split members are the map's -- its data, grid layers and
        // graphics/ tile layers; deleting a map must not take an author's
        // own notes or assets in the same folder with it. The members go as
        // one transaction -- a failure on any of them puts the removed ones
        // back, bytes and modification times -- and each folder goes only
        // once it is empty.
        $transaction = new FileSetTransaction($selectedMap->directory, $files ?? new FilesystemFileSetOperations());
        $transaction->remove($selectedMap->dataPath);
        foreach ($selectedMap->getStoredGridPaths() as $path) {
            $transaction->remove($path);
        }
        $transaction->commit();

        foreach ([$selectedMap->directory . '/layers', $selectedMap->directory . '/' . MapGraphics::DIRECTORY] as $directory) {
            if (is_dir($directory) && array_diff(scandir($directory) ?: [], ['.', '..']) === []) {
                @rmdir($directory);
            }
        }
        // A nested map's folders up to the maps root go with it while they are empty, as a move leaves them.
        $mapsRoot = $this->getMapsRoot();
        for ($directory = $selectedMap->directory; str_starts_with($directory, $mapsRoot . DIRECTORY_SEPARATOR); $directory = dirname($directory)) {
            if (is_dir($directory) && (array_diff(scandir($directory) ?: [], ['.', '..']) !== [] || ! @rmdir($directory))) {
                break;
            }
        }

        return $selectedMap->mapId;
    }

    /**
     * The folder a map with a chosen id is written to, refused as the map transaction refuses a source it cannot
     * take.
     *
     * @throws MapSourceRefusal When the id is not a map folder path or its folder is taken.
     */
    private static function requireFreeMapFolder(string $mapsRoot, string $mapId): string
    {
        try {
            return MapFolder::requireFreeFolder($mapsRoot, $mapId);
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal($error->getMessage(), previous: $error);
        }
    }

    /**
     * Discovers maps from the folder-per-map layout.
     *
     * @param string $projectRoot The project root.
     * @return ProjectMap[]
     */
    private static function discoverMaps(string $projectRoot): array
    {
        $mapsRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'Maps';

        if (! is_dir($mapsRoot)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($mapsRoot, FilesystemIterator::SKIP_DOTS)
        );

        $mapDirectories = [];

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();

            if (! str_ends_with($filename, '.data.php')) {
                continue;
            }

            $mapDirectory = dirname($file->getPathname());
            $baseName = substr($filename, 0, -9);

            if (basename($mapDirectory) !== $baseName) {
                continue;
            }

            $mapDirectories[$mapDirectory] = $mapDirectory;
        }

        ksort($mapDirectories);

        $maps = [];
        foreach ($mapDirectories as $directory) {
            try {
                $maps[] = ProjectMap::fromDirectory($mapsRoot, $directory);
            } catch (Throwable $error) {
                $maps[] = ProjectMap::createReadOnlyFromDirectory($mapsRoot, $directory, $error->getMessage());
            }
        }

        return $maps;
    }

    /**
     * Returns the next unused root-level map base name.
     *
     * @param string $baseName The preferred base name.
     * @return string
     */
    private function getNextAvailableBaseName(string $baseName): string
    {
        $mapsRoot = $this->getMapsRoot();
        $candidate = $baseName;
        $suffix = 2;

        while (is_dir($mapsRoot . DIRECTORY_SEPARATOR . $candidate)) {
            $candidate = $baseName . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Returns the next unused sibling map base name.
     *
     * @param string $parentDirectory The sibling parent directory.
     * @param string $baseName The preferred base name.
     * @return string
     */
    private function getNextAvailableSiblingBaseName(string $parentDirectory, string $baseName): string
    {
        $candidate = $baseName;
        $suffix = 2;

        while (is_dir($parentDirectory . DIRECTORY_SEPARATOR . $candidate)) {
            $candidate = $baseName . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Converts a kebab-case map name into a display label.
     *
     * @param string $baseName The map base name.
     * @return string
     */
    private static function humanizeBaseName(string $baseName): string
    {
        return ucwords(str_replace('-', ' ', $baseName));
    }

    /**
     * Returns the glyphs the project's collision dictionary gives meaning.
     *
     * `assets/Maps/collisions.php` is the project's authored glyph
     * vocabulary: every key in it is a symbol the game understands, so every
     * one of them belongs in the character map whether or not the current
     * map already uses it. A missing or invalid dictionary yields an empty
     * vocabulary rather than an error, matching preview loading.
     *
     * @return string[]
     */
    public function getCollisionGlyphs(?string $layerName = null): array
    {
        $dictionaryFile = rtrim($this->projectRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'Maps' . DIRECTORY_SEPARATOR . 'collisions.php';

        if (! is_file($dictionaryFile)) {
            return [];
        }

        try {
            $dictionary = (static fn(string $file): mixed => require $file)($dictionaryFile);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($dictionary)) {
            return [];
        }

        $glyphs = [];

        $entries = array_filter($dictionary, static fn(mixed $value): bool => ! is_array($value));
        foreach ($dictionary as $name => $section) {
            if (is_array($section) && ($layerName === null || $name === $layerName)) {
                $entries = array_replace($entries, $section);
            }
        }
        foreach (array_keys($entries) as $key) {
            // PHP normalizes digit-only string keys such as "8" to integers.
            $glyph = (string) $key;

            if ($glyph !== '' && trim($glyph) !== '') {
                $glyphs[$glyph] = $glyph;
            }
        }

        return array_values($glyphs);
    }
}
