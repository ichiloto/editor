<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use FilesystemIterator;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Database\EngineDataBootstrap;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Represents the currently opened Ichiloto project in the editor.
 */
final readonly class ProjectWorkspace
{
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
        public ProjectClassDatabase     $classDatabase,
        public ProjectSkillDatabase     $skillDatabase,
        public ProjectAnimationDatabase $animationDatabase,
        public ProjectSystemDatabase    $systemDatabase,
        public ProjectQuestDatabase     $questDatabase,
        public array                    $recordDatabases = [],
        public ?CutsceneLibrary         $cutscenes = null,
    ) {
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
    public static function fromProject(string $projectRoot): self
    {
        return ProjectDirectoryContext::run(
            $projectRoot,
            static fn(string $canonicalRoot): self => self::loadFromProject($canonicalRoot),
        );
    }

    /**
     * Loads a workspace while the process is scoped to its absolute root.
     *
     * @param string $projectRoot Absolute project root.
     * @return self
     */
    private static function loadFromProject(string $projectRoot): self
    {
        $configPath = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ichiloto.json';

        if (! is_file($configPath)) {
            throw new RuntimeException("ichiloto.json not found in $projectRoot.");
        }

        $config = json_decode((string) file_get_contents($configPath), true);

        if (! is_array($config)) {
            throw new RuntimeException("Unable to parse $configPath.");
        }

        $projectName = (string) ($config['name'] ?? basename($projectRoot));
        $projectId = trim((string) ($config['id'] ?? ''));
        $mainFile = (string) ($config['main'] ?? '');

        // enemies.php constructs BattleRewards, which demands a registered
        // item store; without this the category would report a bootstrap
        // failure instead of the author's enemies.
        EngineDataBootstrap::ensure($projectRoot);

        return new self(
            projectRoot: $projectRoot,
            projectId: $projectId,
            projectName: $projectName,
            mainFile: $mainFile,
            maps: $maps = self::discoverMaps($projectRoot),
            mapIds: array_map(static fn(ProjectMap $map): string => $map->mapId, $maps),
            actorDatabase: ProjectActorDatabase::fromProject($projectRoot),
            classDatabase: ProjectClassDatabase::fromProject($projectRoot),
            skillDatabase: ProjectSkillDatabase::fromProject($projectRoot),
            animationDatabase: ProjectAnimationDatabase::fromProject($projectRoot),
            systemDatabase: ProjectSystemDatabase::fromProject($projectRoot),
            questDatabase: ProjectQuestDatabase::fromProject($projectRoot),
            recordDatabases: array_map(
                static fn(RecordSchema $schema): ProjectRecordDatabase => ProjectRecordDatabase::fromProject($projectRoot, $schema),
                RecordSchemaCatalog::all(),
            ),
            cutscenes: CutsceneLibrary::fromProject($projectRoot),
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
                '',
                basename($selectedMap->dataPath),
                basename($selectedMap->mapPath),
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
            $lines[] = sprintf('%s%s%s', $prefix, $mapId, $dirty);
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
            || $this->classDatabase->isDirty()
            || $this->skillDatabase->isDirty()
            || $this->animationDatabase->isDirty()
            || $this->systemDatabase->isDirty()
            || $this->questDatabase->isDirty()
            || ($this->cutscenes?->hasUnsavedChanges() ?? false);
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
        usort($maps, static fn(ProjectMap $left, ProjectMap $right): int => strcmp($left->mapId, $right->mapId));

        return new self(
            projectRoot: $this->projectRoot,
            projectId: $this->projectId,
            projectName: $this->projectName,
            mainFile: $this->mainFile,
            maps: $maps,
            mapIds: array_map(static fn(ProjectMap $workspaceMap): string => $workspaceMap->mapId, $maps),
            actorDatabase: $this->actorDatabase,
            classDatabase: $this->classDatabase,
            skillDatabase: $this->skillDatabase,
            animationDatabase: $this->animationDatabase,
            systemDatabase: $this->systemDatabase,
            questDatabase: $this->questDatabase,
            recordDatabases: $this->recordDatabases,
            cutscenes: $this->cutscenes,
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

        $previewHeight = max(0, $height - 2);
        $previewLines = $selectedMap->renderPreview($width, $previewHeight, $offsetX, $offsetY, $showEventOverlay, $showNpcOverlay, $selectedNpcIndex, $selectedNpcSprite);

        return [
            sprintf('Preview: %s', $selectedMap->mapId),
            sprintf(
                '%s | %s | %d x %d | view %d,%d',
                $selectedMap->getDisplayName(),
                $selectedMap->getRegion(),
                $selectedMap->getWidth(),
                $selectedMap->getHeight(),
                $offsetX,
                $offsetY,
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
    public function createMap(?string $baseName = null): string
    {
        $mapsRoot = $this->getMapsRoot();
        $baseName = $this->getNextAvailableBaseName($baseName ?? 'new-map');
        $directory = $mapsRoot . DIRECTORY_SEPARATOR . $baseName;
        ProjectMap::createBlank($directory, $baseName, self::humanizeBaseName($baseName));

        return $baseName;
    }

    /**
     * Duplicates the selected map with a smart sibling name.
     *
     * @param int $selectedMapIndex The selected map index.
     * @return string|null The duplicated map id.
     */
    public function duplicateMap(int $selectedMapIndex): ?string
    {
        $selectedMap = $this->getMapByIndex($selectedMapIndex);

        if (! $selectedMap instanceof ProjectMap) {
            return null;
        }

        $parentDirectory = dirname($selectedMap->directory);
        $originalBaseName = basename($selectedMap->directory);
        $baseName = $this->getNextAvailableSiblingBaseName($parentDirectory, $originalBaseName . '-copy');
        $directory = $parentDirectory . DIRECTORY_SEPARATOR . $baseName;
        $selectedMap->duplicateTo($directory, $baseName, self::humanizeBaseName($baseName));

        $mapsRoot = $this->getMapsRoot();
        $relativeDirectory = substr($directory, strlen($mapsRoot) + 1);

        return str_replace(DIRECTORY_SEPARATOR, '/', $relativeDirectory);
    }

    /**
     * Deletes the selected map directory and all associated split files.
     *
     * @param int $selectedMapIndex The selected map index.
     * @return string|null The deleted map id.
     */
    public function deleteMap(int $selectedMapIndex): ?string
    {
        $selectedMap = $this->getMapByIndex($selectedMapIndex);

        if (! $selectedMap instanceof ProjectMap) {
            return null;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($selectedMap->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if ($item->isDir()) {
                if (! @rmdir($path)) {
                    throw new RuntimeException("Failed to remove directory {$path}.");
                }

                continue;
            }

            if (! @unlink($path)) {
                throw new RuntimeException("Failed to remove file {$path}.");
            }
        }

        if (! @rmdir($selectedMap->directory)) {
            throw new RuntimeException("Failed to remove directory {$selectedMap->directory}.");
        }

        return $selectedMap->mapId;
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

        return array_values(array_map(
            static fn(string $directory): ProjectMap => ProjectMap::fromDirectory($mapsRoot, $directory),
            $mapDirectories
        ));
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
}
