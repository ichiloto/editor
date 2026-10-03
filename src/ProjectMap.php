<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Field\NpcCollection;
use Ichiloto\Editor\Field\ProjectNpc;
use Ichiloto\Editor\History\TracksPersistedState;
use Ichiloto\Editor\Storage\FileSetOperations;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;
use Ichiloto\Editor\Storage\FilesystemFileSetOperations;
use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Console\SgrStyleState;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Editor\Maps\EditableGrid;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\Maps\TileLayerSettings;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Represents a discovered project map and its split source files.
 */
final class ProjectMap
{
    use \Ichiloto\Editor\Field\NpcSpriteArt;
    use TracksPersistedState;

    private MapLayers $layers;

    /**
     * @var array<string, mixed>
     */
    private array $editableData;

    /**
     * Memoized widest-row width; invalidated when the grid dimensions change.
     */
    private ?int $cachedWidth = null;

    /**
     * The data file's authored source, when the editor can rewrite it in
     * place. Null when the source cannot be parsed for preservation, in
     * which case `$dataSourceIssue` says why and data edits are refused.
     */
    private ?PhpArraySourceDocument $dataDocument = null;

    /**
     * Why the data file cannot be preserved, or null when it can.
     */
    private ?string $dataSourceIssue = null;

    /**
     * The data file's raw bytes when its source cannot be parsed for
     * preservation, so a grid-only save still knows the data member is
     * untouched and a reload adopts the same bytes.
     */
    private ?string $unparsedDataSource = null;

    /**
     * @var array<string, mixed> The data array the document corresponds to:
     * what the file held at load, or at the last successful save.
     */
    private array $loadedData;
    private string $baselineDataSource;

    /**
     * The tile payload as of the last load or save, so a save can tell a
     * changed grid from an untouched one without comparing against a file
     * an author may have written in another form entirely.
     */
    private string $baselineMapPayload;

    /**
     * The event-layer payload as of the last load or save.
     */
    private string $baselineEventPayload;

    /**
     * @param string[] $tileLines
     * @param string[] $eventLines
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $mapId,
        public readonly string $directory,
        public readonly string $dataPath,
        public readonly string $mapPath,
        public readonly string $eventPath,
        public readonly array $data,
        public readonly array $tileLines,
        public readonly array $eventLines,
        ?string $dataSource = null,
        private readonly ?string $gridSourceIssue = null,
        ?MapLayers $layers = null,
    ) {
        $this->editableData = $data;
        $this->layers = $layers ?? new MapLayers($directory, true, [[
            'id' => MapLayers::BASE, 'name' => 'terrain', 'order' => 0, 'decoration' => false,
            'path' => $mapPath, 'grid' => new EditableGrid(implode("\n", $tileLines), legacyTags: true),
        ]], new EditableGrid(implode("\n", $eventLines)), $eventPath);
        $this->loadedData = $data;
        $this->adoptDataSource($dataSource ?? "<?php\n\nreturn " . self::exportPhpValue($data) . ";\n");
        $this->baselineDataSource = $this->dataDocument?->source ?? (string) $this->unparsedDataSource;
        $this->baselineMapPayload = $this->buildMapPayload();
        $this->baselineEventPayload = $this->buildEventPayload();
    }

    /**
     * Adopts a data-file source as the one edits are rewritten into.
     *
     * A source the parser cannot hold is not a loading error: the map stays
     * browsable and its grids stay editable, but every data edit is refused
     * with the reason until the file is repaired by hand.
     */
    private function adoptDataSource(string $source): void
    {
        try {
            $this->dataDocument = PhpArraySourceDocument::parse($source);
            $this->dataSourceIssue = null;
            $this->unparsedDataSource = null;
        } catch (SourceUnreadable $unreadable) {
            $this->dataDocument = null;
            $this->dataSourceIssue = rtrim($unreadable->getMessage(), '.');
            $this->unparsedDataSource = $source;
        }
    }

    /**
     * Loads a project map from the given map directory.
     *
     * @param string $mapsRoot The maps root path.
     * @param string $directory The map directory.
     * @return self
     */
    public static function fromDirectory(string $mapsRoot, string $directory): self
    {
        $baseName = basename($directory);
        $dataPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php';
        $mapPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.map.php';
        $eventPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.event.php';

        if (! is_file($dataPath) || (! is_file($mapPath) && ! is_dir($directory . '/layers')) || ! is_file($eventPath)) {
            throw new RuntimeException("Map files are incomplete in {$directory}.");
        }

        $relativeDirectory = substr($directory, strlen($mapsRoot) + 1);
        $mapId = str_replace(DIRECTORY_SEPARATOR, '/', $relativeDirectory);

        // Grid PHP is data, not executable map-building code. Refuse it before
        // evaluating even the separate data member of an incomplete map.
        try {
            $set = MapLayerSource::loadFromDirectory($directory, $mapId);
            $eventText = self::readGridSource($eventPath, $mapId);
            if (! $set->legacy) {
                $set->assertMatchingGrid(MapLayer::parseGrid($eventText), $mapId . '/' . basename($eventPath));
            }
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal($error->getMessage(), previous: $error);
        }
        $mapText = $set->layers[0]->text;
        $data = require $dataPath;

        if (! is_array($data)) {
            throw new RuntimeException("Map files could not be parsed in {$directory}.");
        }

        return new self(
            mapId: $mapId,
            directory: $directory,
            dataPath: $dataPath,
            mapPath: $mapPath,
            eventPath: $eventPath,
            data: $data,
            tileLines: self::splitMapText($mapText),
            eventLines: self::splitMapText($eventText),
            dataSource: (string) file_get_contents($dataPath),
            layers: MapLayers::createFromSource($directory, $set, $eventPath, $eventText),
        )->withLoadedBaseline();
    }

    /** Keeps an invalid map discoverable without evaluating or rewriting its files. */
    public static function createReadOnlyFromDirectory(string $mapsRoot, string $directory, string $issue): self
    {
        $baseName = basename($directory);
        $mapId = str_replace(DIRECTORY_SEPARATOR, '/', substr($directory, strlen($mapsRoot) + 1));

        return new self(
            mapId: $mapId,
            directory: $directory,
            dataPath: $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php',
            mapPath: $directory . DIRECTORY_SEPARATOR . $baseName . '.map.php',
            eventPath: $directory . DIRECTORY_SEPARATOR . $baseName . '.event.php',
            data: [],
            tileLines: [],
            eventLines: [],
            gridSourceIssue: $issue,
        )->withLoadedBaseline();
    }

    public function getGridSourceIssue(): ?string
    {
        return $this->gridSourceIssue;
    }

    private function assertEditable(): void
    {
        if ($this->gridSourceIssue !== null) {
            throw new MapSourceRefusal("{$this->mapId} is read-only: {$this->gridSourceIssue}");
        }
    }

    /** Reads one canonical grid, refusing executable or generated source. */
    private static function readGridSource(string $path, string $mapId): string
    {
        try {
            return MapGridSource::readFile($path, $mapId . '/' . basename($path));
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal(sprintf(
                '%s The map cannot be loaded or saved. Repair it as a literal nowdoc, then retry; nothing was changed.',
                $error->getMessage(),
            ), previous: $error);
        }
    }

    /** Refuses a source changed on disk after this map was opened. */
    private function assertGridSourcesCanonical(): void
    {
        try {
            $this->layers->assertSourcesUnchanged();
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal($error->getMessage() . ' Nothing was written.', previous: $error);
        }
    }

    /**
     * Adopts the just-loaded content as the saved baseline.
     *
     * @return self This map.
     */
    private function withLoadedBaseline(): self
    {
        $this->captureBaseline();

        return $this;
    }

    /**
     * Returns the display name for the map.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return (string) ($this->editableData['name'] ?? basename($this->directory));
    }

    /**
     * Returns the display region for the map.
     *
     * @return string
     */
    public function getRegion(): string
    {
        return (string) ($this->editableData['region'] ?? 'Unknown');
    }

    /**
     * Returns the map description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return (string) ($this->editableData['description'] ?? '');
    }

    /**
     * Returns the number of tile rows.
     *
     * @return int
     */
    public function getHeight(): int
    {
        return count($this->layers->getBaseGrid()->cells);
    }

    /**
     * Returns the widest tile row after formatting tags are stripped.
     *
     * @return int
     */
    public function getWidth(): int
    {
        if ($this->cachedWidth !== null) {
            return $this->cachedWidth;
        }

        $width = 0;

        foreach ($this->layers->getBaseGrid()->cells as $row) {
            $width = max($width, count($row));
        }

        return $this->cachedWidth = $width;
    }

    /**
     * Returns whether the map has unsaved changes.
     *
     * @return bool
     */


    /**
     * Returns the number of declared event definitions.
     *
     * @return int
     */
    public function getEventDefinitionCount(): int
    {
        $events = $this->editableData['events'] ?? [];

        return is_array($events) ? count($events) : 0;
    }

    /**
     * Returns the number of configured triggers.
     *
     * @return int
     */
    public function getTriggerCount(): int
    {
        $triggers = $this->editableData['triggers'] ?? [];

        return is_array($triggers) ? count($triggers) : 0;
    }

    /**
     * Returns the editable map data.
     *
     * @return array<string, mixed>
     */
    public function getEditableData(): array
    {
        return $this->editableData;
    }

    public function getLayers(): array
    {
        return array_map(static function (array $layer): array {
            unset($layer['grid']);
            return $layer;
        }, $this->layers->getLayers());
    }

    public function getBaseLayerId(): string
    {
        return $this->layers->getBaseId();
    }

    public function isLegacyMap(): bool
    {
        return $this->layers->legacy;
    }

    public function getLayerSymbol(string $layer, int $x, int $y): string
    {
        return $this->layers->getGrid($layer)->cells[$y][$x]['symbol'] ?? ' ';
    }

    public function hasLayerCell(string $layer, int $x, int $y): bool
    {
        return isset($this->layers->getGrid($layer)->cells[$y][$x]);
    }

    public function getLayerCellStyle(string $layer, int $x, int $y): array
    {
        $cell = $this->layers->getGrid($layer)->cells[$y][$x] ?? [];
        return ['prefix' => $cell['prefix'] ?? '', 'suffix' => $cell['suffix'] ?? ''];
    }

    public function getLayerColor(string $layer, int $x, int $y): ?string
    {
        return self::getInnermostForeground($this->getLayerCellStyle($layer, $x, $y)['prefix']);
    }

    public function setLayerCell(string $layer, int $x, int $y, string $symbol, string $prefix = '', string $suffix = ''): void
    {
        $this->assertEditable();
        $grid = $this->layers->getGrid($layer);
        if (isset($grid->cells[$y][$x])) {
            $grid->cells[$y][$x] = ['symbol' => self::normalizeSymbol($symbol), 'prefix' => $prefix, 'suffix' => $suffix];
            $this->touchState();
        }
    }

    public function createLayer(string $name, bool $decoration = false, ?int $order = null): string
    {
        $id = '';
        $this->changeLayers(static function (MapLayers $layers) use ($name, $decoration, $order, &$id): void {
            $id = $layers->createLayer($name, $decoration, $order);
            $layers->assertDimensions();
        });

        return $id;
    }

    /**
     * Renames a layer. The tile layers that move with a gameplay layer keep
     * moving with it under its new name.
     *
     * @throws MapSourceRefusal When the rename is refused, or changes collisions unconfirmed.
     */
    public function renameLayer(string $id, string $name, bool $confirmCollisionChange = false): void
    {
        if (! $confirmCollisionChange) {
            $this->assertCollisionsUnchanged($this->countRenameCollisionChanges($id, $name), 'Renaming');
        }
        $layer = $this->layers->getLayer($id);
        $this->changeLayers(static fn(MapLayers $layers) => $layers->renameLayer($id, $name),
            static fn(mixed $settings): mixed => $layer['decoration'] ? $settings : TileLayerSettings::renameOwner($settings, $layer['name'], $name));
    }

    /**
     * Moves a layer to another order; a layer holding that order takes this
     * layer's order. Gameplay order decides which glyph sets collision.
     *
     * @throws MapSourceRefusal When the move is refused, or changes collisions unconfirmed.
     */
    public function moveLayer(string $id, int $order, bool $confirmCollisionChange = false): void
    {
        if (! $confirmCollisionChange) {
            $this->assertCollisionsUnchanged($this->countMoveCollisionChanges($id, $order), 'Moving');
        }
        $this->changeLayers(static fn(MapLayers $layers) => $layers->moveLayer($id, $order));
    }

    /**
     * Makes a layer decoration, drawn without collision, or gameplay. Tile
     * layers that moved with a layer made decoration move with none.
     *
     * @throws MapSourceRefusal When the change is refused, or changes collisions unconfirmed.
     */
    public function setLayerDecoration(string $id, bool $decoration, bool $confirmCollisionChange = false): void
    {
        if (! $confirmCollisionChange) {
            $this->assertCollisionsUnchanged($this->countDecorationCollisionChanges($id, $decoration), 'Changing');
        }
        $layer = $this->layers->getLayer($id);
        $this->changeLayers(static fn(MapLayers $layers) => $layers->setLayerDecoration($id, $decoration),
            static fn(mixed $settings): mixed => $decoration && ! $layer['decoration'] ? TileLayerSettings::removeOwner($settings, $layer['name']) : $settings);
    }

    /**
     * Removes a layer and its cells. Tile layers that moved with a removed
     * gameplay layer move with none.
     *
     * @throws MapSourceRefusal When the removal is refused.
     */
    public function removeLayer(string $id): void
    {
        $layer = $this->layers->getLayer($id);
        $this->changeLayers(static fn(MapLayers $layers) => $layers->removeLayer($id),
            static fn(mixed $settings): mixed => $layer['decoration'] ? $settings : TileLayerSettings::removeOwner($settings, $layer['name']));
    }

    /**
     * Applies a change to the layers, and to the tile layer settings that
     * name them, as one change: it is made on a copy of the layers that is
     * adopted only once the settings are written, so a refusal from either
     * leaves the map exactly as it was.
     *
     * @param Closure(MapLayers): mixed $change
     * @param (Closure(mixed): mixed)|null $adjustSettings The tile layer settings the change leaves, given the current ones.
     * @throws MapSourceRefusal When the change or the settings are refused.
     */
    private function changeLayers(Closure $change, ?Closure $adjustSettings = null): void
    {
        $this->assertEditable();
        $layers = clone $this->layers;
        $change($layers);
        if ($adjustSettings !== null) {
            $settings = $this->getMapDataField([MapGraphics::SETTINGS_KEY]);
            $next = $adjustSettings($settings);
            if ($next !== $settings) {
                $this->setMapDataField([MapGraphics::SETTINGS_KEY], $next);
            }
        }
        $this->layers = $layers;
        $this->cachedWidth = null;
        $this->touchState();
    }

    public function getLayerSet(): MapLayerSet
    {
        return $this->layers->getLayerSet();
    }

    public function getStoredGridPaths(): array
    {
        $this->assertGridSourcesCanonical();
        return $this->layers->getStoredPaths();
    }

    /**
     * The map's graphical tile layers in `graphics/`, unsaved resizes and
     * stamped pieces included. The TUI never paints single tiles.
     *
     * @return array<string, string> Source bytes by path.
     */
    public function getTileLayerSources(): array
    {
        return $this->layers->getTileSources();
    }

    /**
     * Writes a stamped piece's tile entries into the named tile layers with
     * their top-left cell at (x, y), creating a tile layer the map does not
     * have yet. A `0` entry leaves its cell as it was.
     *
     * @param array<string, list<list<string>>> $tiles Entries by row, keyed by tile layer name.
     * @throws MapSourceRefusal When a layer cannot take the entries; nothing is changed.
     */
    public function writeTileEntries(array $tiles, int $x, int $y): void
    {
        $this->assertEditable();
        $this->layers->writeTileEntries($tiles, $x, $y, $this->findNewTileLayerPlace(...));
        $this->touchState();
    }

    /**
     * Sets tile entries cell by cell in the named tile layers, `0` included,
     * for a drawn or erased connected piece, creating a tile layer the map
     * does not have yet when it gets a tile.
     *
     * @param array<string, list<array{x: int, y: int, entry: string}>> $cells The entry for each cell, keyed by tile layer name.
     * @throws MapSourceRefusal When a layer cannot take the entries; nothing is changed.
     */
    public function writeTileCells(array $cells): void
    {
        $this->assertEditable();
        $this->layers->writeTileCells($cells, $this->findNewTileLayerPlace(...));
        $this->touchState();
    }

    /**
     * Where a new tile layer goes among the map's tile layers: before the
     * first that belongs to a gameplay layer drawn above the new layer's
     * own, so terrain tiles draw under building tiles and those under
     * fixtures. A layer whose owner is unknown goes last.
     *
     * @param list<string> $names The map's tile layer names, in order.
     * @return string|null The tile layer to place it before, or null for last.
     */
    private function findNewTileLayerPlace(string $name, array $names): ?string
    {
        $gameplay = array_values(array_filter($this->layers->getLayers(), static fn(array $layer): bool =>
            $layer['id'] !== MapLayers::EVENT && ! $layer['decoration']));
        $rank = array_flip(array_column($gameplay, 'name'));
        try {
            $owners = MapGraphics::resolveLayerOwners($this->getMapDataField([MapGraphics::SETTINGS_KEY]), [...$names, $name],
                array_keys($rank), $this->loadTileset(), $this->mapId);
        } catch (InvalidArgumentException | RuntimeException) {
            return null;
        }
        if (($owners[$name] ?? null) === null) {
            return null;
        }
        $own = $rank[$owners[$name]];
        foreach ($names as $existing) {
            $owner = $owners[$existing] ?? null;
            if ($owner !== null && $rank[$owner] > $own) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * The tile layers whose tiles move with a gameplay layer's glyphs: those
     * that belong to it ({@see MapGraphics::resolveLayerOwners()}), named by
     * the map data (`'tileLayers' => ['floor' => ['movesWith' =>
     * 'buildings']]`) or otherwise written only by this layer's tileset
     * pieces. The event layer moves no tiles, and neither does any layer
     * while the settings are invalid, which validation reports.
     *
     * @return list<string> Tile layer names.
     */
    public function getTileLayersMovingWith(string $layerId): array
    {
        $layer = $this->layers->getLayer($layerId);
        $names = $this->layers->getTileLayerNames();
        if ($layer['id'] === MapLayers::EVENT || $layer['decoration'] || $names === []) {
            return [];
        }
        $gameplay = array_column(array_filter($this->layers->getLayers(), static fn(array $candidate): bool =>
            $candidate['id'] !== MapLayers::EVENT && ! $candidate['decoration']), 'name');
        try {
            $tileset = $this->loadTileset();
        } catch (InvalidArgumentException | RuntimeException) {
            // A tileset that cannot load says nothing about its pieces.
            $tileset = null;
        }
        try {
            $owners = MapGraphics::resolveLayerOwners($this->getMapDataField([MapGraphics::SETTINGS_KEY]), $names, $gameplay,
                $tileset, $this->mapId);
        } catch (InvalidArgumentException) {
            return [];
        }

        return array_keys(array_filter($owners, static fn(?string $owner): bool => $owner === $layer['name']));
    }

    /**
     * The names of the map's tile layers, in drawing order.
     *
     * @return list<string>
     */
    public function getTileLayerNames(): array
    {
        return $this->layers->getTileLayerNames();
    }

    /**
     * Reads the entries of the named tile layers over a rectangle, by row.
     *
     * @param list<string> $names Tile layer names.
     * @return array<string, list<list<string>>> Entries by row, keyed by tile layer name.
     * @throws MapSourceRefusal When a layer cannot be read or does not match the map.
     */
    public function readTileEntries(array $names, int $x, int $y, int $width, int $height): array
    {
        return $this->layers->readTileEntries($names, $x, $y, $width, $height);
    }

    /**
     * Restores the tile layers {@see getTileLayerSources()} returned, for
     * undo and redo.
     *
     * @param array<string, string> $sources Source bytes by path.
     */
    public function restoreTileLayerSources(array $sources): void
    {
        $this->assertEditable();
        $this->layers->restoreTileSources($sources);
        $this->touchState();
    }

    /**
     * The map's tile layers in drawing order, as an editor lists them: each
     * one's name, order, file in the map's folder, and its settings as the
     * Engine reads them: its offset across and down in field cells, the
     * gameplay layer its settings name it `movesWith`, and the gameplay layer
     * it belongs to ({@see MapGraphics::resolveLayerOwners()}). When the
     * settings cannot be read, `issue` says why and no layer has settings.
     *
     * @return array{layers: list<array{name: string, order: int, file: string, offset: ?array{float, float}, movesWith: ?string, owner: ?string}>, issue: ?string}
     */
    public function describeTileLayers(): array
    {
        $layers = $this->layers->getTileLayers();
        $names = array_column($layers, 'name');
        $settings = $this->getMapDataField([MapGraphics::SETTINGS_KEY]);
        $gameplay = $this->getGameplayLayerNames();
        try {
            $tileset = $this->loadTileset();
        } catch (InvalidArgumentException | RuntimeException) {
            // A tileset that cannot load says nothing about its pieces.
            $tileset = null;
        }
        $issue = null;
        try {
            $offsets = MapGraphics::readLayerOffsets($settings, $names, $this->mapId);
            $movesWith = MapGraphics::readLayersMovingWith($settings, $names, $gameplay, $this->mapId);
            $owners = MapGraphics::resolveLayerOwners($settings, $names, $gameplay, $tileset, $this->mapId);
        } catch (InvalidArgumentException $error) {
            $offsets = $movesWith = $owners = [];
            $issue = $error->getMessage();
        }

        return ['layers' => array_map(static fn(array $layer): array => [
            'name' => $layer['name'],
            'order' => $layer['order'],
            'file' => MapGraphics::DIRECTORY . '/' . basename($layer['path']),
            'offset' => $issue === null ? ($offsets[$layer['name']] ?? [0.0, 0.0]) : null,
            'movesWith' => $movesWith[$layer['name']] ?? null,
            'owner' => $owners[$layer['name']] ?? null,
        ], $layers), 'issue' => $issue];
    }

    /**
     * Adds an empty tile layer, placed among the tile layers as a tile layer
     * a piece names is.
     *
     * @throws MapSourceRefusal When the name is taken or invalid, or the map cannot take another tile layer.
     */
    public function createTileLayer(string $name): void
    {
        $this->changeLayers(fn(MapLayers $layers) => $layers->createTileLayer($name, $this->findNewTileLayerPlace(...)));
    }

    /**
     * Renames a tile layer and its settings.
     *
     * @throws MapSourceRefusal When there is no such layer, or the new name is taken or invalid.
     */
    public function renameTileLayer(string $name, string $newName): void
    {
        $this->changeLayers(static fn(MapLayers $layers) => $layers->renameTileLayer($name, $newName),
            static fn(mixed $settings): mixed => TileLayerSettings::renameLayer($settings, $name, $newName));
    }

    /**
     * Moves a tile layer to another drawing order; a tile layer holding that
     * order takes this layer's order.
     *
     * @throws MapSourceRefusal When there is no such layer or the order is not 00-99.
     */
    public function moveTileLayer(string $name, int $order): void
    {
        $this->changeLayers(static fn(MapLayers $layers) => $layers->moveTileLayer($name, $order));
    }

    /**
     * Removes a tile layer, its tiles and its settings.
     *
     * @throws MapSourceRefusal When there is no such layer.
     */
    public function removeTileLayer(string $name): void
    {
        $this->changeLayers(static fn(MapLayers $layers) => $layers->removeTileLayer($name),
            static fn(mixed $settings): mixed => TileLayerSettings::removeLayer($settings, $name));
    }

    /**
     * Sets a tile layer's offset across and down, in field cells, and the
     * gameplay layer its tiles move with, or none. The map's settings must
     * read as the Engine reads them once set.
     *
     * @param array<int, mixed> $offset Across and down, each one of {@see MapGraphics::OFFSET_STEPS}.
     * @throws MapSourceRefusal When there is no such layer, or the Engine would refuse the settings.
     */
    public function setTileLayerSettings(string $name, array $offset, ?string $movesWith): void
    {
        $this->assertEditable();
        $names = array_column($this->layers->getTileLayers(), 'name');
        if (! in_array($name, $names, true)) {
            throw new MapSourceRefusal("There is no tile layer {$name}. Nothing was changed.");
        }
        $settings = $this->getMapDataField([MapGraphics::SETTINGS_KEY]);
        $next = TileLayerSettings::setLayer($settings, $name, $offset, $movesWith);
        try {
            MapGraphics::readLayersMovingWith($next, $names, $this->getGameplayLayerNames(), $this->mapId);
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal(rtrim($error->getMessage(), '.') . '. Nothing was changed.', previous: $error);
        }
        if ($next !== $settings) {
            $this->setMapDataField([MapGraphics::SETTINGS_KEY], $next);
        }
    }

    /** @return list<string> The names of the map's gameplay layers, in order. */
    private function getGameplayLayerNames(): array
    {
        return array_values(array_column(array_filter($this->layers->getLayers(), static fn(array $layer): bool =>
            $layer['id'] !== MapLayers::EVENT && ! $layer['decoration']), 'name'));
    }

    /**
     * Loads the tileset the map data names, or null when it names none.
     *
     * @throws InvalidArgumentException When the named tileset cannot be loaded.
     */
    public function loadTileset(): ?Tileset
    {
        $id = $this->getMapDataField(['tileset']);
        if ($id === null) {
            return null;
        }
        if (! is_string($id)) {
            throw new InvalidArgumentException(sprintf('Its tileset is %s, not a tileset id.', get_debug_type($id)));
        }

        return Tileset::load($this->getAssetRoot(), $id);
    }

    /**
     * The map's graphics as the game loads them, from its current tile
     * layers, unsaved edits included, or null when it has none.
     *
     * @throws InvalidArgumentException When the game would refuse them.
     */
    public function loadGraphics(): ?MapGraphics
    {
        return MapGraphics::fromSources($this->getTileLayerSources(), $this->mapId, $this->getMapDataField(['tileset']),
            $this->getLayerSet(), $this->getAssetRoot(), $this->getMapDataField([MapGraphics::SETTINGS_KEY]));
    }
    /** The project's asset root, where tilesets and their sheets live. */
    public function getAssetRoot(): string
    {
        return dirname($this->getMapsRoot());
    }

    /** Proves the layers compose and resolve collisions as the engine will load them. */
    public function validateLayerContracts(): void
    {
        $this->assertGridsAgree();
        MapCollisionResolver::resolveLayers($this->layers->getLayerSet(), $this->loadCollisionDictionary());
    }

    /**
     * How many cells would resolve to another collision if the layer were
     * renamed: collision lookup uses the layer name ({@see MapCollisionResolver}).
     *
     * @throws MapSourceRefusal When the rename is refused, or collisions cannot be resolved after it.
     */
    public function countRenameCollisionChanges(string $id, string $name): int
    {
        return $this->countCollisionChanges(static fn(MapLayers $layers) => $layers->renameLayer($id, $name));
    }

    /**
     * How many cells would resolve to another collision if the layer moved
     * to the order: the topmost gameplay glyph sets a cell's collision.
     *
     * @throws MapSourceRefusal When the move is refused, or collisions cannot be resolved after it.
     */
    public function countMoveCollisionChanges(string $id, int $order): int
    {
        return $this->countCollisionChanges(static fn(MapLayers $layers) => $layers->moveLayer($id, $order));
    }

    /**
     * How many cells would resolve to another collision if the layer became
     * decoration, which has none, or gameplay.
     *
     * @throws MapSourceRefusal When the change is refused, or collisions cannot be resolved after it.
     */
    public function countDecorationCollisionChanges(string $id, bool $decoration): int
    {
        return $this->countCollisionChanges(static fn(MapLayers $layers) => $layers->setLayerDecoration($id, $decoration));
    }

    /**
     * How many cells would resolve to another collision if the layer were removed.
     *
     * @throws MapSourceRefusal When the removal is refused, or collisions cannot be resolved after it.
     */
    public function countRemovalCollisionChanges(string $id): int
    {
        return $this->countCollisionChanges(static fn(MapLayers $layers) => $layers->removeLayer($id));
    }

    /**
     * Resolves collisions before and after a change tried on a copy of the
     * layers, and counts the cells that differ. Nothing changes.
     *
     * @param Closure(MapLayers): mixed $change
     * @throws MapSourceRefusal When the change is refused, or collisions cannot be resolved.
     */
    private function countCollisionChanges(Closure $change): int
    {
        $this->assertEditable();
        $trial = clone $this->layers;
        $change($trial);
        $dictionary = $this->loadCollisionDictionary();
        try {
            $before = MapCollisionResolver::resolveLayers($this->layers->getLayerSet(), $dictionary);
            $after = MapCollisionResolver::resolveLayers($trial->getLayerSet(), $dictionary);
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal(rtrim($error->getMessage(), '.') . '. Nothing was changed.', previous: $error);
        }
        $changed = 0;
        foreach ($before as $y => $row) {
            foreach ($row as $x => $type) {
                $changed += $type !== ($after[$y][$x] ?? null) ? 1 : 0;
            }
        }

        return $changed;
    }

    /** @throws MapSourceRefusal When an unconfirmed change changes collisions. */
    private function assertCollisionsUnchanged(int $changed, string $action): void
    {
        if ($changed > 0) {
            throw new MapSourceRefusal("{$action} this layer changes resolved collisions. Explicit confirmation is required.");
        }
    }

    /**
     * The project's shared collision dictionary, or none.
     *
     * @return array<int|string, mixed>
     * @throws MapSourceRefusal When it does not return an array.
     */
    private function loadCollisionDictionary(): array
    {
        $path = $this->getMapsRoot() . '/collisions.php';
        $dictionary = is_file($path) ? (static fn(string $path): mixed => require $path)($path) : [];
        if (! is_array($dictionary)) {
            throw new MapSourceRefusal('The collision dictionary must return an array.');
        }

        return $dictionary;
    }

    public function captureLayerSnapshot(): array
    {
        return ['grid' => $this->captureGridSnapshot(), 'data' => $this->editableData,
            'source' => $this->dataDocument === null ? $this->unparsedDataSource : $this->proposedDataSource()];
    }

    public function restoreLayerSnapshot(array $snapshot): void
    {
        $this->restoreGridSnapshot($snapshot['grid']);
        $this->editableData = $this->loadedData = $snapshot['data'];
        $this->adoptDataSource($snapshot['source']);
        $this->touchState();
    }

    /**
     * Builds a merged preview where event markers override map tiles.
     *
     * @param int $width The preview width.
     * @param int $height The preview height.
     * @param int $offsetX The horizontal preview offset.
     * @param int $offsetY The vertical preview offset.
     * @param bool $showEventOverlay Whether event markers should be rendered.
     * @param array<int, array<int, string|null>> $stampPreview A stamp's footprint by row and column,
     *   drawn highlighted over the map and never painted: the glyph it would write, or null where it
     *   leaves the map's cell as it is.
     * @return string[]
     */
    public function renderPreview(
        int $width,
        int $height,
        int $offsetX = 0,
        int $offsetY = 0,
        bool $showEventOverlay = true,
        bool $showNpcOverlay = false,
        ?int $selectedNpcIndex = null,
        ?string $selectedNpcSprite = null,
        array $layerVisibility = [],
        ?string $activeLayer = null,
        bool $terminalPreview = false,
        bool $dimInactive = false,
        array $stampPreview = [],
    ): array
    {
        if ($width < 1 || $height < 1) {
            return [];
        }

        $lines = [];
        $offsetX = max(0, $offsetX);
        $offsetY = max(0, $offsetY);
        if ($terminalPreview) {
            $rows = array_slice($this->getLayerSet()->getComposedGrid(), $offsetY, $height);
            return array_pad(array_map(static fn(array $row): string => rtrim(implode('', array_slice($row, $offsetX, $width))), $rows), $height, '');
        }
        $rowLimit = min($offsetY + $height, max(count($this->layers->getBaseGrid()->cells), count($this->layers->getEventGrid()->getSymbols())));
        $npcCells = $showNpcOverlay ? $this->npcOverlayCells($selectedNpcIndex, $selectedNpcSprite) : [];
        $baseLayerId = $this->layers->getBaseId();

        for ($row = $offsetY; $row < $rowLimit; $row++) {
            $tileRow = [];
            foreach ($this->layers->getLayers() as $layer) {
                if ($layer['decoration']) {
                    continue;
                }
                if (($layerVisibility[$layer['id']] ?? true) === false
                    || ($layer['id'] === MapLayers::EVENT && ! $showEventOverlay)) {
                    continue;
                }
                foreach ($layer['grid']->cells[$row] ?? [] as $x => $cell) {
                    if ($cell['symbol'] !== ' ' || $layer['id'] === $baseLayerId) {
                        $tileRow[$x] = [...$cell, 'dim' => $dimInactive && $activeLayer !== $layer['id']];
                    }
                }
            }
            $mergedSymbols = [];

            for ($column = $offsetX; $column < $offsetX + $width; $column++) {
                if (isset($npcCells[$row][$column])) {
                    // NPCs draw over everything, as they do in the game; the
                    // overlay is derived from map data and never painted.
                    $mergedSymbols[] = $npcCells[$row][$column];
                    continue;
                }

                if (isset($stampPreview[$row][$column])) {
                    // A stamp's footprint shows what it would write, in
                    // reverse video, over whatever the map holds there.
                    $mergedSymbols[] = "\033[7m" . $stampPreview[$row][$column] . "\033[0m";
                    continue;
                }

                $tileCell = $tileRow[$column] ?? null;
                $tileSymbol = is_array($tileCell) ? $tileCell['symbol'] : ' ';
                // A selected map-owned NPC, and a stamp's cell that keeps the map's
                // glyph, mark the existing cell, never a replacement.
                $anchorHighlight = array_key_exists($column, $npcCells[$row] ?? []) || array_key_exists($column, $stampPreview[$row] ?? [])
                    ? "\033[7m" : '';
                if (! $this->layers->legacy && is_array($tileCell)) {
                    $styled = TerminalText::formatStyles($tileCell['prefix'] . $anchorHighlight . $tileSymbol . $tileCell['suffix']);
                    $styled = ($tileCell['dim'] ?? false) ? "\033[2m" . $styled . "\033[0m" : $styled;
                    $mergedSymbols[] = $anchorHighlight === '' ? $styled : $styled . "\033[0m";
                    continue;
                }
                $ansiOpen = is_array($tileCell)
                    ? self::ansiOpenForPrefix((string) ($tileCell['prefix'] ?? ''))
                    : null;
                $dim = (($tileCell['dim'] ?? false) ? "\033[2m" : '') . $anchorHighlight;
                $mergedSymbols[] = $ansiOpen === null && $dim === '' ? $tileSymbol : $dim . $ansiOpen . $tileSymbol . "\033[0m";
            }

            $lines[] = rtrim(implode('', $mergedSymbols));
        }

        return array_pad($lines, $height, '');
    }

    /**
     * The 4-bit ANSI foreground codes for the formatter's colour names.
     * `gray` is the formatter's name for bright black.
     */
    private const string STYLE_CLOSE_TAG = '</>';

    private const array ANSI_FOREGROUNDS = [
        'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33,
        'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37,
        'gray' => 90, 'bright-red' => 91, 'bright-green' => 92,
        'bright-yellow' => 93, 'bright-blue' => 94, 'bright-magenta' => 95,
        'bright-cyan' => 96, 'bright-white' => 97,
    ];

    /**
     * Converts a cell's authored `fg=` prefix into an ANSI opening sequence.
     *
     * Named colours use the 4-bit palette; `#RRGGBB` values use truecolor.
     * Anything the preview cannot express safely renders unstyled rather
     * than guessing.
     *
     * @param string $prefix The cell's styling prefix bytes.
     * @return string|null The opening escape sequence, or null for none.
     */
    private static function ansiOpenForPrefix(string $prefix): ?string
    {
        $value = self::getInnermostForeground($prefix);

        if ($value === null) {
            return null;
        }

        if (isset(self::ANSI_FOREGROUNDS[$value])) {
            return sprintf("\033[%dm", self::ANSI_FOREGROUNDS[$value]);
        }

        if (preg_match('/^#([0-9a-fA-F]{6})$/', $value, $hex) === 1) {
            return sprintf(
                "\033[38;2;%d;%d;%dm",
                hexdec(substr($hex[1], 0, 2)),
                hexdec(substr($hex[1], 2, 2)),
                hexdec(substr($hex[1], 4, 2)),
            );
        }

        return null;
    }

    /**
     * Returns the cells the NPC overlay occupies, row => column => symbol.
     *
     * The engine anchors a sprite at its tile and lets it overhang to the
     * right (NpcManager::eraseNpc clears displayWidth cells), so a
     * two-column emoji owns its anchor and the cell after it. The overhang
     * cell holds an empty string, so the terminal draws the wide glyph in
     * the space it needs rather than a symbol shoved half under it. The
     * selected NPC is drawn with brackets around a one-column sprite, or as
     * itself when wide, since brackets would misalign the row. An explicitly
     * empty sprite contributes only a selected anchor (null), highlighting
     * the underlying map cell without replacing its glyph or neighbours.
     *
     * @param int|null $selectedNpcIndex The NPC to mark selected.
     * @param string|null $selectedNpcSprite A glyph to draw for the selected
     *   NPC in place of its base sprite -- a directional sprite being
     *   previewed -- as authored; it is shown as the terminal would show it.
     * @return array<int, array<int, string|null>> The cells, or selected map-owned anchors.
     */
    private function npcOverlayCells(?int $selectedNpcIndex, ?string $selectedNpcSprite = null): array
    {
        $cells = [];

        foreach ($this->getNpcs()->all() as $index => $npc) {
            $sprite = $npc->getVisibleSprite();
            $columns = $npc->getSpriteWidth();
            $x = $npc->getX();
            $y = $npc->getY();

            if ($index === $selectedNpcIndex && $selectedNpcSprite !== null && trim($selectedNpcSprite) !== '') {
                $sprite = ProjectNpc::visibleGlyph($selectedNpcSprite);
                $columns = max(1, mb_strwidth($sprite));
            }

            if ($sprite === '') {
                if ($index === $selectedNpcIndex) {
                    $cells[$y][$x] = null;
                }
                continue;
            }

            if ($index === $selectedNpcIndex && $columns === 1) {
                $sprite = '[' . $sprite . ']';
                $x = max(0, $x - 1);
                $columns = 3;
            }

            $cells[$y][$x] = $sprite;

            for ($column = 1; $column < $columns; $column++) {
                $cells[$y][$x + $column] = '';
            }
        }

        return $cells;
    }

    /**
     * Returns the editable tile symbol at the given coordinate.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @return string
     */
    public function getTileSymbol(int $x, int $y): string
    {
        return $this->layers->getBaseGrid()->cells[$y][$x]['symbol'] ?? ' ';
    }

    /**
     * Returns the editable event symbol at the given coordinate.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @return string
     */
    public function getEventSymbol(int $x, int $y): string
    {
        return $this->getLayerSymbol(MapLayers::EVENT, $x, $y);
    }

    /**
     * Replaces a tile symbol while preserving its surrounding formatting tags.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $symbol The replacement symbol.
     * @return void
     */
    public function setTileSymbol(int $x, int $y, string $symbol): void
    {
        $this->assertEditable();
        if (! isset($this->layers->getBaseGrid()->cells[$y][$x])) {
            return;
        }

        $this->layers->getBaseGrid()->cells[$y][$x]['symbol'] = self::normalizeSymbol($symbol);
        $this->touchState();
    }

    /**
     * Returns a tile cell's raw styling bytes.
     *
     * The prefix and suffix are the authored formatter tags exactly as the
     * map file holds them, so callers can restore them byte-for-byte.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @return array{prefix: string, suffix: string}
     */
    public function getTileCellStyle(int $x, int $y): array
    {
        $cell = $this->layers->getBaseGrid()->cells[$y][$x] ?? null;

        return [
            'prefix' => is_array($cell) ? (string) ($cell['prefix'] ?? '') : '',
            'suffix' => is_array($cell) ? (string) ($cell['suffix'] ?? '') : '',
        ];
    }

    /**
     * Returns a tile cell's foreground colour, when its prefix declares one.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @return string|null The `fg=` value (a name or `#RRGGBB`), or null.
     */
    public function getTileColor(int $x, int $y): ?string
    {
        return self::getInnermostForeground($this->getTileCellStyle($x, $y)['prefix']);
    }

    /**
     * Returns the `fg=` value a styling prefix renders in. Nested tags
     * override outer ones, so the last declared foreground wins.
     *
     * @param string $prefix The cell's styling prefix bytes.
     * @return string|null The `fg=` value, or null when none is declared.
     */
    private static function getInnermostForeground(string $prefix): ?string
    {
        $state = new SgrStyleState();
        preg_match_all('/\x1b\[[0-9;]*m/', $prefix, $sequences);
        foreach ($sequences[0] as $sequence) {
            $state->apply($sequence);
        }
        $ansi = $state->prefix();
        if (preg_match('/\x1b\[38;2;(\d+);(\d+);(\d+)m/', $ansi, $rgb) === 1) {
            return sprintf('#%02x%02x%02x', (int) $rgb[1], (int) $rgb[2], (int) $rgb[3]);
        }
        foreach (self::ANSI_FOREGROUNDS as $name => $code) {
            if (str_contains($ansi, "\033[{$code}m")) {
                return $name;
            }
        }
        if (preg_match('/\x1b\[38;5;(\d+)m/', $ansi, $indexed) === 1) {
            $index = (int) $indexed[1];
            if ($index < 16) {
                return array_keys(self::ANSI_FOREGROUNDS)[$index];
            }
            if ($index <= 255) {
                if ($index >= 232) {
                    $shade = 8 + ($index - 232) * 10;
                    return sprintf('#%02x%02x%02x', $shade, $shade, $shade);
                }
                $cube = [0, 95, 135, 175, 215, 255];
                $index -= 16;
                return sprintf('#%02x%02x%02x', $cube[intdiv($index, 36)], $cube[intdiv($index % 36, 6)], $cube[$index % 6]);
            }
        }
        if (preg_match_all('/<[^>]*\bfg=([^;>]+)[^>]*>/', $prefix, $matches) < 1) {
            return null;
        }

        return $matches[1][array_key_last($matches[1])];
    }

    /**
     * Replaces a tile cell's symbol and raw styling bytes together.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $symbol The replacement symbol.
     * @param string $prefix The styling prefix bytes, or an empty string.
     * @param string $suffix The styling suffix bytes, or an empty string.
     * @return void
     */
    public function setTileCell(int $x, int $y, string $symbol, string $prefix, string $suffix): void
    {
        $this->assertEditable();
        if (! isset($this->layers->getBaseGrid()->cells[$y][$x])) {
            return;
        }

        $this->layers->getBaseGrid()->cells[$y][$x]['symbol'] = self::normalizeSymbol($symbol);
        $this->layers->getBaseGrid()->cells[$y][$x]['prefix'] = $prefix;
        $this->layers->getBaseGrid()->cells[$y][$x]['suffix'] = $suffix;
        $this->touchState();
    }

    /**
     * Replaces an event symbol.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $symbol The replacement symbol.
     * @return void
     */
    public function setEventSymbol(int $x, int $y, string $symbol): void
    {
        $this->assertEditable();
        if (! $this->hasLayerCell(MapLayers::EVENT, $x, $y)) {
            return;
        }

        $this->layers->getEventGrid()->cells[$y][$x]['symbol'] = self::normalizeSymbol($symbol);
        $this->touchState();
    }

    /**
     * Returns every distinct event marker painted on the grid.
     *
     * @return string[]
     */
    public function getPlacedEventMarkers(): array
    {
        $markers = [];

        foreach ($this->layers->getEventGrid()->getSymbols() as $row) {
            foreach ($row as $symbol) {
                if (trim($symbol) !== '') {
                    $markers[$symbol] = $symbol;
                }
            }
        }

        return array_values($markers);
    }

    /**
     * Captures the editable grid state for undoable whole-grid mutations
     * (resize, event bounds rewrites).
     *
     * @return array{tiles: array, events: array, layers: array}
     */
    public function captureGridSnapshot(): array
    {
        return [
            'tiles' => $this->layers->getBaseGrid()->cells,
            'events' => $this->layers->getEventGrid()->getSymbols(),
            'layers' => $this->layers->captureSnapshot(),
        ];
    }

    /**
     * Restores a previously captured grid snapshot.
     *
     * @param array{tiles: array, events: array, layers: array} $snapshot The captured state.
     * @return void
     */
    public function restoreGridSnapshot(array $snapshot): void
    {
        $this->assertEditable();
        $this->layers->restoreSnapshot($snapshot['layers']);
        $this->cachedWidth = null;
        $this->touchState();
    }

    /**
     * Names the NPCs a resize to the given size would leave outside the map,
     * or with a wander area outside it.
     *
     * The runtime does not clamp: an NPC anchored past the edge is drawn
     * off-map and unreachable, and a wander area past the edge is a promise
     * the engine cannot keep. Nothing here changes anything -- it is what a
     * shrink has to be refused for until the author moves, resizes, or
     * removes the NPC.
     *
     * @param int $width The proposed width.
     * @param int $height The proposed height.
     * @return string[] One line per stranded NPC, empty when the resize is safe.
     */
    public function describeNpcsStrandedBy(int $width, int $height): array
    {
        $stranded = [];

        foreach ($this->getNpcs()->all() as $npc) {
            $label = sprintf('%s (%s)', $npc->getName(), $npc->getId() ?? 'no id');

            if ($npc->getX() >= $width || $npc->getY() >= $height) {
                $stranded[] = sprintf('%s stands at %d,%d', $label, $npc->getX(), $npc->getY());
            }

            $area = $npc->getWanderArea();

            if ($npc->wanders() && $area !== null
                && ($area['x'] + $area['width'] > $width || $area['y'] + $area['height'] > $height)) {
                $stranded[] = sprintf(
                    '%s wanders %d,%d %dx%d',
                    $label,
                    $area['x'],
                    $area['y'],
                    $area['width'],
                    $area['height'],
                );
            }
        }

        return $stranded;
    }

    /**
     * Returns the map's event definitions as they currently stand.
     *
     * `$data` is the loaded payload; this is the live one, edits included.
     *
     * @return array<string, mixed> The events, keyed by marker.
     */
    public function getEventDefinitions(): array
    {
        $events = $this->editableData['events'] ?? [];

        return is_array($events) ? $events : [];
    }

    /**
     * Returns the markers of the map's events, which a cinematic subject
     * reference may name.
     *
     * @return string[] The markers, in map order.
     */
    public function getEventMarkers(): array
    {
        return array_values(array_map(strval(...), array_keys($this->getEventDefinitions())));
    }

    /**
     * Returns the map's NPCs.
     *
     * @return NpcCollection The collection, read fresh from the map data.
     */
    public function getNpcs(): NpcCollection
    {
        return NpcCollection::fromMapData($this->editableData['npcs'] ?? null);
    }

    /**
     * Stores a rewritten NPC collection.
     *
     * The one mutation path for `npcs`: the coordinator never reaches into
     * the array. An unchanged collection is a no-op, so undoing to the saved
     * list is clean by content, not by accident.
     *
     * @param NpcCollection $npcs The collection to store.
     * @return void
     */
    public function setNpcs(NpcCollection $npcs): void
    {
        $this->assertEditable();
        $entries = $npcs->toMapData();

        if (($this->editableData['npcs'] ?? null) === $entries) {
            return;
        }

        if ($entries === [] && ! array_key_exists('npcs', $this->editableData)) {
            return;
        }

        $next = $this->editableData;
        $next['npcs'] = $entries;
        $this->writeData($next);
    }

    /**
     * Returns a top-level map metadata field value.
     *
     * @param string $field The field to read.
     * @return mixed
     */
    public function getMapField(string $field): mixed
    {
        return $this->editableData[$field] ?? null;
    }

    /**
     * Applies a new data array, refusing it when the authored source cannot
     * take the change reversibly.
     *
     * Every data mutation funnels through here. The rewrite is computed
     * against the authored bytes before anything is applied, so a change
     * the source cannot hold -- a value written as an expression, an array
     * behind an unreadable key -- is refused with the map, the file and the
     * path named, and the record, history and dirty state are exactly what
     * they were.
     *
     * @param array<string, mixed> $next The data array as it should now be.
     */
    private function writeData(array $next): void
    {
        if ($next === $this->editableData) {
            // Setting data to what it already is neither dirties nor
            // deserves a history entry at the call site.
            return;
        }

        $this->assertDataPreservable($next);
        $this->editableData = $next;
        $this->touchState();
    }

    /**
     * Proves the authored data source can be rewritten into this array.
     *
     * @param array<string, mixed> $next
     * @throws MapSourceRefusal When it cannot.
     */
    private function assertDataPreservable(array $next): void
    {
        if ($this->dataDocument === null) {
            throw new MapSourceRefusal(sprintf(
                '%s: %s cannot be edited: %s. The file keeps its authored form; repair it by hand, then reload.',
                $this->mapId,
                basename($this->dataPath),
                $this->dataSourceIssue ?? 'its source cannot be preserved',
            ));
        }

        try {
            ArraySourceWriter::rewrite($this->dataDocument, $this->loadedData, $next);
        } catch (SourcePreservationRefusal $refusal) {
            throw new MapSourceRefusal(sprintf(
                '%s: %s — %s Everything else in the file is untouched.',
                $this->mapId,
                basename($this->dataPath),
                rtrim($refusal->getMessage(), '.') . '.',
            ), previous: $refusal);
        }
    }

    /**
     * The data-file source this map's current content should be saved as.
     *
     * @throws MapSourceRefusal When the source cannot take the changes.
     */
    private function proposedDataSource(): string
    {
        if ($this->dataDocument === null) {
            if ($this->editableData === $this->loadedData) {
                throw new RuntimeException(sprintf('%s has no preservable data source to rewrite.', $this->mapId));
            }

            $this->assertDataPreservable($this->editableData);
        }

        try {
            return ArraySourceWriter::rewrite($this->dataDocument, $this->loadedData, $this->editableData)->source;
        } catch (SourcePreservationRefusal $refusal) {
            throw new MapSourceRefusal(sprintf(
                '%s: %s — %s',
                $this->mapId,
                basename($this->dataPath),
                rtrim($refusal->getMessage(), '.') . '.',
            ), previous: $refusal);
        }
    }

    /**
     * Why the data file cannot be preserved by the editor, or null when its
     * authored source round-trips. Validation reports this so the refusals
     * an author meets in the Inspector are visible outside it too.
     */
    public function dataSourceIssue(): ?string
    {
        return $this->dataSourceIssue;
    }

    /**
     * Reads a nested value from the map's own data, unsaved edits included.
     *
     * @param array<int, string> $path The nested data path.
     * @return mixed The value, or null when the path names nothing.
     */
    public function getMapDataField(array $path): mixed
    {
        $reference = $this->editableData;

        foreach ($path as $segment) {
            if (! is_array($reference) || ! array_key_exists($segment, $reference)) {
                return null;
            }

            $reference = $reference[$segment];
        }

        return $reference;
    }

    /**
     * Writes a nested value into the map's own data.
     *
     * A null value removes the key rather than writing an empty one: a map
     * with no background music says nothing about music, and a misleading
     * empty track would be read as one. Nothing is touched, and nothing is
     * dirtied, when the value is already what it should be.
     *
     * @param array<int, string> $path The nested data path.
     * @param mixed $value The value, or null to remove the key.
     * @return void
     */
    public function setMapDataField(array $path, mixed $value): void
    {
        $this->assertEditable();
        if ($path === []) {
            return;
        }

        $exists = $this->hasMapDataField($path);

        if ($value === null ? ! $exists : ($exists && $this->getMapDataField($path) === $value)) {
            // Already exactly this, or already absent: neither a write nor a
            // history entry, so browsing a map can never dirty it.
            return;
        }

        $next = $this->editableData;
        $reference = &$next;
        $last = array_key_last($path);

        foreach ($path as $position => $segment) {
            if ($position === $last) {
                if ($value === null) {
                    unset($reference[$segment]);
                } else {
                    $reference[$segment] = $value;
                }

                break;
            }

            if (! isset($reference[$segment]) || ! is_array($reference[$segment])) {
                if ($value === null) {
                    // Nothing to remove down a path that does not exist.
                    return;
                }

                $reference[$segment] = [];
            }

            $reference = &$reference[$segment];
        }

        unset($reference);
        $this->writeData($next);
    }

    /**
     * Whether the map's data holds this exact path.
     *
     * @param array<int, string> $path The nested data path.
     */
    public function hasMapDataField(array $path): bool
    {
        $reference = $this->editableData;

        foreach ($path as $segment) {
            if (! is_array($reference) || ! array_key_exists($segment, $reference)) {
                return false;
            }

            $reference = $reference[$segment];
        }

        return true;
    }

    /**
     * Reads a nested event field value (the undo counterpart of setEventField).
     *
     * @param string $marker The event marker.
     * @param string[] $path The nested data path.
     * @return mixed
     */
    public function getEventField(string $marker, array $path): mixed
    {
        $reference = $this->editableData['events'][$marker] ?? null;

        foreach ($path as $segment) {
            if (! is_array($reference) || ! array_key_exists($segment, $reference)) {
                return null;
            }

            $reference = $reference[$segment];
        }

        return $reference;
    }

    /**
     * Returns the event marker located at the given coordinate.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @return string|null
     */
    public function getEventMarkerAt(int $x, int $y): ?string
    {
        $symbol = trim($this->getEventSymbol($x, $y));

        return $symbol === '' ? null : $symbol;
    }

    /**
     * Returns the event definition for the given marker.
     *
     * @param string $marker The event marker.
     * @return array<string, mixed>|null
     */
    public function getEventDefinition(string $marker): ?array
    {
        $events = $this->editableData['events'] ?? null;

        if (! is_array($events)) {
            return null;
        }

        $event = $events[$marker] ?? null;

        return is_array($event) ? $event : null;
    }

    /**
     * Returns the cells an event marker occupies, as the runtime triggers
     * it: exactly the painted cells, in any shape, connected or not.
     *
     * @param string $marker The event marker.
     * @return CellArea|null The cells, or null when the marker is not placed.
     */
    public function getEventArea(string $marker): ?CellArea
    {
        $cells = [];

        foreach ($this->layers->getEventGrid()->getSymbols() as $rowIndex => $row) {
            foreach ($row as $columnIndex => $symbol) {
                if ($symbol === $marker) {
                    $cells[] = [$columnIndex, $rowIndex];
                }
            }
        }

        return $cells === [] ? null : CellArea::fromCells($cells);
    }

    /**
     * Returns the bounds of an event marker's cells as x/y/width/height.
     *
     * @param string $marker The event marker.
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    public function getEventBounds(string $marker): ?array
    {
        $bounds = $this->getEventArea($marker)?->bounds;

        return $bounds === null ? null : [
            'x' => $bounds->getX(),
            'y' => $bounds->getY(),
            'width' => $bounds->getWidth(),
            'height' => $bounds->getHeight(),
        ];
    }

    /**
     * Moves every cell of an event marker by an offset, keeping its shape.
     *
     * Refused, leaving the grid unchanged, when a moved cell would leave the
     * event layer or land on another marker's cell.
     *
     * @param string $marker The event marker.
     * @param int $deltaX Columns to move by.
     * @param int $deltaY Rows to move by.
     * @return string|null Why the move was refused, or null when it was made.
     */
    public function moveEventCells(string $marker, int $deltaX, int $deltaY): ?string
    {
        $this->assertEditable();
        $area = $this->getEventArea($marker);

        if ($area === null) {
            return sprintf('Marker %s is not placed.', $marker);
        }

        foreach ($area->cells as [$x, $y]) {
            [$toX, $toY] = [$x + $deltaX, $y + $deltaY];

            if (! $this->hasLayerCell(MapLayers::EVENT, $toX, $toY)) {
                return sprintf('Marker %s would leave the map at (%d, %d).', $marker, $toX, $toY);
            }

            $occupant = $this->getEventSymbol($toX, $toY);

            if ($occupant !== $marker && trim($occupant) !== '') {
                return sprintf('Marker %s would cover marker %s at (%d, %d).', $marker, $occupant, $toX, $toY);
            }
        }

        foreach ($area->cells as [$x, $y]) {
            $this->layers->getEventGrid()->cells[$y][$x]['symbol'] = ' ';
        }

        foreach ($area->cells as [$x, $y]) {
            $this->layers->getEventGrid()->cells[$y + $deltaY][$x + $deltaX]['symbol'] = $marker;
        }

        $this->touchState();

        return null;
    }

    /**
     * Updates a top-level map metadata field.
     *
     * @param string $field The field to update.
     * @param mixed $value The new value.
     * @return void
     */
    public function setMapField(string $field, mixed $value): void
    {
        $this->assertEditable();
        $next = $this->editableData;
        $next[$field] = $value;
        $this->writeData($next);
    }

    /**
     * Resizes the map, its event overlay and its graphical tile layers while
     * preserving existing content. A tile layer the Engine cannot read
     * refuses the resize before anything changes.
     *
     * @param int $width The new map width.
     * @param int $height The new map height.
     * @return void
     */
    public function resize(int $width, int $height): void
    {
        $this->assertEditable();
        $width = max(1, $width);
        $height = max(1, $height);

        if ($width === $this->getWidth() && $height === $this->getHeight()) {
            return;
        }

        $this->layers->resize($width, $height);

        $this->cachedWidth = null;
        $this->touchState();
    }

    /**
     * Inserts `$count` blank rows before row `$at` (axis `y`) or blank
     * columns before column `$at` (axis `x`) into every grid the map has:
     * its terminal layers, its event layer and its tile layers. Only the
     * grids change; the coordinates stored in data files move through the
     * project-wide insertion plan.
     *
     * @throws MapSourceRefusal When the line is outside the map or a tile layer cannot take it; nothing is changed.
     */
    public function insertLines(string $axis, int $at, int $count): void
    {
        $this->assertEditable();
        $size = match ($axis) {
            'x' => $this->getWidth(),
            'y' => $this->getHeight(),
            default => throw new MapSourceRefusal("Insert rows (y) or columns (x), not '{$axis}'."),
        };
        if ($count < 1 || $at < 0 || $at > $size) {
            throw new MapSourceRefusal(sprintf('%s cannot take %d %s at %d; nothing was changed.',
                $this->mapId, $count, $axis === 'y' ? 'rows' : 'columns', $at));
        }

        $this->layers->insertLines($axis, $at, $count);

        $this->cachedWidth = null;
        $this->touchState();
    }

    /**
     * Every grid file's source as the map now holds it: terminal and event
     * layers, and tile layers, keyed by path.
     *
     * @return array<string, string>
     */
    public function getGridSources(): array
    {
        return $this->layers->getSources();
    }

    /**
     * Updates a nested event field.
     *
     * @param string $marker The event marker.
     * @param string[] $path The nested data path.
     * @param mixed $value The replacement value.
     * @return void
     */
    public function setEventField(string $marker, array $path, mixed $value): void
    {
        $this->assertEditable();
        if ($path === []) {
            return;
        }

        if (! isset($this->editableData['events'][$marker]) || ! is_array($this->editableData['events'][$marker])) {
            return;
        }

        $next = $this->editableData;
        $reference = &$next['events'][$marker];

        foreach ($path as $index => $segment) {
            if ($index === array_key_last($path)) {
                $reference[$segment] = $value;
                unset($reference);
                $this->writeData($next);
                return;
            }

            if (! isset($reference[$segment]) || ! is_array($reference[$segment])) {
                $reference[$segment] = [];
            }

            $reference = &$reference[$segment];
        }
    }

    /**
     * Replaces the definition for the given event marker.
     *
     * @param string $marker The event marker.
     * @param array<string, mixed> $definition The replacement definition.
     * @return void
     */
    public function setEventDefinition(string $marker, array $definition): void
    {
        $this->assertEditable();
        $next = $this->editableData;

        if (! isset($next['events']) || ! is_array($next['events'])) {
            $next['events'] = [];
        }

        $next['events'][$marker] = $definition;
        $this->writeData($next);
    }

    /**
     * Removes the definition for the given event marker (the undo counterpart
     * of a first-time setEventDefinition).
     *
     * @param string $marker The event marker.
     * @return void
     */
    public function removeEventDefinition(string $marker): void
    {
        $this->assertEditable();
        if (! isset($this->editableData['events'][$marker])) {
            return;
        }

        $next = $this->editableData;
        unset($next['events'][$marker]);
        $this->writeData($next);
    }

    /**
     * Repaints an event marker as exactly the given rectangle.
     *
     * Refused, leaving the grid unchanged, when the rectangle is empty,
     * leaves the event layer or covers another marker's cell: the bounds an
     * author asks for are the bounds the event gets, never a clamped or
     * overwriting approximation of them.
     *
     * @param string $marker The event marker.
     * @param int $x The left coordinate.
     * @param int $y The top coordinate.
     * @param int $width The marker width.
     * @param int $height The marker height.
     * @return string|null Why the change was refused, or null when it was made.
     */
    public function setEventBounds(string $marker, int $x, int $y, int $width, int $height): ?string
    {
        $this->assertEditable();

        if ($width < 1 || $height < 1) {
            return sprintf('Marker %s needs a size of at least 1x1, not %dx%d.', $marker, $width, $height);
        }

        for ($row = $y; $row < $y + $height; $row++) {
            for ($column = $x; $column < $x + $width; $column++) {
                if (! $this->hasLayerCell(MapLayers::EVENT, $column, $row)) {
                    return sprintf('Marker %s would leave the map at (%d, %d).', $marker, $column, $row);
                }

                $occupant = $this->getEventSymbol($column, $row);

                if ($occupant !== $marker && trim($occupant) !== '') {
                    return sprintf('Marker %s would cover marker %s at (%d, %d).', $marker, $occupant, $column, $row);
                }
            }
        }

        foreach ($this->layers->getEventGrid()->getSymbols() as $rowIndex => $row) {
            foreach ($row as $columnIndex => $symbol) {
                if ($symbol === $marker) {
                    $this->layers->getEventGrid()->cells[$rowIndex][$columnIndex]['symbol'] = ' ';
                }
            }
        }

        for ($row = $y; $row < $y + $height; $row++) {
            for ($column = $x; $column < $x + $width; $column++) {
                $this->layers->getEventGrid()->cells[$row][$column]['symbol'] = $marker;
            }
        }

        $this->touchState();

        return null;
    }

    /**
     * Returns where the next save() will write, so callers can detect and
     * confirm a folder move before any file is touched.
     *
     * @return array{mapId: string, directory: string, dataPath: string, mapPath: string, eventPath: string}
     */
    public function getSaveTarget(): array
    {
        return $this->resolveSaveTarget();
    }

    /**
     * Returns whether saving would move the map folder (the rename flow that
     * deletes the current directory).
     *
     * @return bool
     */
    public function willMoveOnSave(): bool
    {
        return $this->resolveSaveTarget()['directory'] !== $this->directory;
    }

    /**
     * Saves the current map and event overlay using transactional writes.
     *
     * @return string The saved map id.
     */
    public function save(?callable $backup = null, ?FileSetOperations $files = null): string
    {
        $this->assertEditable();
        $target = $this->resolveSaveTarget();
        $moving = $target['directory'] !== $this->directory;
        $this->assertGridSourcesCanonical();

        $tripletExists = is_file($this->dataPath)
            && count($this->layers->getSources()) > 0
            && is_file($this->eventPath);

        if (! $this->isDirty() && ! $moving && is_dir($this->directory) && $tripletExists) {
            // Nothing diverges from the last save: writing would only
            // canonicalize hand-authored formatting and churn mtimes.
            return $target['mapId'];
        }

        $this->validateLayerContracts();

        // Which members actually changed. The tile and event files compare
        // against the grids as of the last save, never against the bytes on
        // disk. Existing grid sources have already passed the literal-nowdoc
        // check, so an edited grid cannot silently flatten authored PHP.
        // A data file the parser cannot hold never reaches the writer unless
        // the on-disk member disappeared: its edits were refused, so its
        // cached source bytes are its own and are restored unchanged.
        $dataSource = $this->dataDocument === null ? (string) $this->unparsedDataSource : $this->proposedDataSource();
        $writeData = ! is_file($this->dataPath)
            || $dataSource !== $this->baselineDataSource;
        $mapPayload = $this->buildMapPayload();
        $writeMap = $this->isDirty();
        $eventPayload = $this->buildEventPayload();
        $writeEvent = $eventPayload !== $this->baselineEventPayload || ! is_file($this->eventPath);

        if (! $moving && ! $writeData && ! $writeMap && ! $writeEvent) {
            // Dirty by fingerprint but identical in content: a same-value
            // round trip. Clean without writing.
            $this->adoptWritten($dataSource, $mapPayload, $eventPayload);

            return $target['mapId'];
        }

        $transaction = new FileSetTransaction($target['directory'], $files ?? new FilesystemFileSetOperations());

        if ($writeData || $moving) {
            $transaction->write($target['dataPath'], $dataSource);
        }
        $this->layers->stageChanges($transaction, $moving ? $target['directory'] : null, $moving ? basename($target['directory']) : null, $moving);
        if ($moving) {
            $transaction->remove($this->dataPath);
        }

        // Stage everything beside its destination, then prove the staged
        // data file evaluates to exactly the map being saved -- run where
        // the game would run it, so relative requires resolve.
        $staged = $transaction->stage();

        if ($writeData || $moving) {
            try {
                $evaluatedFingerprint = null;
                $evaluated = $this->evaluateFile($staged[$target['dataPath']] ?? $target['dataPath'], $evaluatedFingerprint);
            } catch (\Throwable $throwable) {
                $transaction->rollBack();

                throw new RuntimeException(sprintf(
                    '%s: the rewritten %s could not be evaluated (%s); nothing was written.',
                    $this->mapId,
                    basename($target['dataPath']),
                    $throwable->getMessage(),
                ), previous: $throwable);
            }

            if (! is_array($evaluated) || $evaluatedFingerprint !== PhpDataFile::valueFingerprint($this->editableData)) {
                $transaction->rollBack();

                throw new RuntimeException(sprintf(
                    '%s: the rewritten %s would not read back as the edited map; nothing was written.',
                    $this->mapId,
                    basename($target['dataPath']),
                ));
            }
        }

        $transaction->commit($backup);

        if ($this->layers->legacy && is_dir($target['directory'] . '/layers')
            && array_diff(scandir($target['directory'] . '/layers') ?: [], ['.', '..']) === []) {
            @rmdir($target['directory'] . '/layers');
        }

        if ($moving) {
            // The transaction removed the triplet; the folder follows only
            // when nothing else of the author's lives in it.
            if (is_dir($this->directory) && array_diff(scandir($this->directory) ?: [], ['.', '..']) === []) {
                @rmdir($this->directory);
            }

            self::deleteEmptyParentDirectories(dirname($this->directory), $this->getMapsRoot());
        }

        $this->adoptWritten($dataSource, $mapPayload, $eventPayload);

        return $target['mapId'];
    }

    /**
     * Adopts what a save (or a proven no-op) established as the new baseline.
     */
    private function adoptWritten(string $dataSource, string $mapPayload, string $eventPayload): void
    {
        $this->adoptDataSource($dataSource);
        $this->baselineDataSource = $dataSource;
        $this->loadedData = $this->editableData;
        $this->baselineMapPayload = $mapPayload;
        $this->baselineEventPayload = $eventPayload;
        $this->layers->captureBaseline();
        $this->captureBaseline();
    }

    /**
     * Refuses a save whose two grids no longer describe the same map.
     */
    private function assertGridsAgree(): void
    {
        if (! $this->layers->legacy) {
            $this->layers->assertDimensions();
            return;
        }
        if (count($this->layers->getEventGrid()->getSymbols()) !== count($this->layers->getBaseGrid()->cells)) {
            throw new RuntimeException(sprintf(
                '%s: the event layer is %d rows but the map is %d; nothing was written.',
                $this->mapId,
                count($this->layers->getEventGrid()->getSymbols()),
                count($this->layers->getBaseGrid()->cells),
            ));
        }
    }

    /**
     * Evaluates a PHP file as the game would: from the project root, so a
     * `require` written relative to the working directory resolves. A fresh
     * process is required because staged authored files can repeat named
     * declarations already loaded by this Editor process.
     */
    private function evaluateFile(string $path, ?string &$fingerprint = null): mixed
    {
        $fingerprints = [];
        $payloads = $this->evaluateFiles([$path], $fingerprints);
        $fingerprint = $fingerprints[0] ?? null;

        if (! array_key_exists(0, $payloads)) {
            throw new RuntimeException(sprintf('%s returned no isolated result.', basename($path)));
        }

        return $payloads[0];
    }

    /**
     * Evaluates one runtime load unit in order and outside the Editor process.
     *
     * @param list<string> $paths The authored members in runtime load order.
     * @param-out list<string>|null $fingerprints Serialized-value fingerprints.
     * @return list<mixed> Their returned values.
     */
    private function evaluateFiles(array $paths, ?array &$fingerprints = null): array
    {
        $projectRoot = dirname($this->getMapsRoot(), 2);

        return PhpDataFile::evaluateIsolatedFiles(
            $paths,
            is_dir($projectRoot) ? $projectRoot : null,
            $fingerprints,
        );
    }

    /**
     * Creates a blank map folder with default files.
     *
     * @param string $directory The destination map directory.
     * @param string $baseName The base filename.
     * @param string $displayName The map display name.
     * @param int $width The map width.
     * @param int $height The map height.
     * @return void
     */
    public static function createBlank(string $directory, string $baseName, string $displayName, int $width = 48, int $height = 18, ?FileSetOperations $files = null, ?string $kind = null): void
    {
        $blankTileLine = str_repeat(' ', $width);
        $blankEventLine = str_repeat(' ', $width);
        $tileText = implode(PHP_EOL, array_fill(0, $height, $blankTileLine));
        $eventText = implode(PHP_EOL, array_fill(0, $height, $blankEventLine));
        // A map is born with its kind, the tileset its tiles and pieces come from.
        $data = [
            'name' => $displayName,
            'region' => '',
            ...($kind === null ? [] : ['tileset' => $kind]),
            'description' => '',
            'triggers' => [],
            'events' => [],
        ];

        // The triplet is born the way it lives: as one transaction. A member
        // that cannot be installed takes the others -- and the folder this
        // operation created -- back with it, so a refused creation leaves no
        // partial map.
        $transaction = new FileSetTransaction($directory, $files ?? new FilesystemFileSetOperations());
        $transaction->write(
            $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php',
            "<?php\n\nreturn " . self::exportPhpValue($data) . ";\n",
        );
        $transaction->write(
            $directory . DIRECTORY_SEPARATOR . $baseName . '.map.php',
            MapGridSource::buildSource($tileText, 'ICHILOTO_MAP'),
        );
        $transaction->write(
            $directory . DIRECTORY_SEPARATOR . $baseName . '.event.php',
            MapGridSource::buildSource($eventText, 'ICHILOTO_EVENT_MAP'),
        );
        $transaction->commit();
    }

    /**
     * Writes this map into a new directory as a duplicate.
     *
     * @param string $directory The destination map directory.
     * @param string $baseName The destination basename.
     * @param string $displayName The duplicated display name.
     * @return void
     */
    public function duplicateTo(string $directory, string $baseName, string $displayName, ?FileSetOperations $files = null): void
    {
        $this->assertEditable();
        $this->assertGridSourcesCanonical();
        // The copy keeps everything the original authored -- comments,
        // expressions, formatting -- with only the display name rewritten.
        // A source the editor cannot rewrite reversibly refuses the
        // duplication outright: an author copying a map must never get a
        // flattened one, and finding out at the copy is better than
        // finding out in a diff.
        if ($this->dataDocument === null) {
            throw new MapSourceRefusal(sprintf(
                '%s: %s cannot be duplicated: %s. Repair the file by hand, then duplicate.',
                $this->mapId,
                basename($this->dataPath),
                $this->dataSourceIssue ?? 'its source cannot be preserved',
            ));
        }

        $duplicatedData = $this->editableData;
        $duplicatedData['name'] = $displayName;

        try {
            $dataSource = ArraySourceWriter::rewrite($this->dataDocument, $this->loadedData, $duplicatedData)->source;
        } catch (SourcePreservationRefusal $refusal) {
            throw new MapSourceRefusal(sprintf(
                '%s: %s — %s Nothing was duplicated.',
                $this->mapId,
                basename($this->dataPath),
                rtrim($refusal->getMessage(), '.') . '.',
            ), previous: $refusal);
        }

        $transaction = new FileSetTransaction($directory, $files ?? new FilesystemFileSetOperations());
        $duplicatedDataPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php';
        $transaction->write($duplicatedDataPath, $dataSource);
        $this->validateLayerContracts();
        $this->layers->stageChanges($transaction, $directory, $baseName);

        // The staged copy must evaluate to exactly the duplicate's content
        // where it will live, before anything is installed.
        $staged = $transaction->stage();

        try {
            $evaluatedFingerprint = null;
            $evaluated = $this->evaluateFile($staged[$duplicatedDataPath] ?? $duplicatedDataPath, $evaluatedFingerprint);
        } catch (\Throwable $evaluationFailure) {
            $transaction->rollBack();

            throw new RuntimeException(sprintf(
                '%s could not be evaluated at %s (%s); nothing was duplicated.',
                $baseName . '.data.php',
                basename($directory),
                $evaluationFailure->getMessage(),
            ), previous: $evaluationFailure);
        }

        if (! is_array($evaluated) || $evaluatedFingerprint !== PhpDataFile::valueFingerprint($duplicatedData)) {
            $transaction->rollBack();

            throw new RuntimeException(sprintf(
                '%s would not read back as the duplicated map; nothing was duplicated.',
                $baseName . '.data.php',
            ));
        }

        $transaction->commit();
    }

    /**
     * Returns distinct symbols that are useful for the character map.
     *
     * @return string[]
     */
    public function getCharacterPalette(?string $layer = null): array
    {
        $symbols = [];

        foreach (($layer === null ? $this->layers->getBaseGrid() : $this->layers->getGrid($layer))->cells as $row) {
            foreach ($row as $cell) {
                $symbol = $cell['symbol'];

                if (trim($symbol) === '') {
                    continue;
                }

                $symbols[$symbol] = $symbol;
            }
        }

        foreach ($this->layers->getEventGrid()->getSymbols() as $row) {
            foreach ($row as $symbol) {
                if (trim($symbol) === '') {
                    continue;
                }

                $symbols[$symbol] = $symbol;
            }
        }

        foreach ([' ', ';', '~', '+', '-', '=', '|', '/', '\\', '_', '█', '░', '▒', '▓', '🧍', '🏃🏽‍➡️', '@'] as $symbol) {
            $symbols[$symbol] = $symbol;
        }

        return array_values($symbols);
    }

    /**
     * Splits multi-line map text into rows.
     *
     * @param string $text The raw map text.
     * @return string[]
     */
    private static function splitMapText(string $text): array
    {
        return preg_split('/\R/u', rtrim($text, "\r\n")) ?: [];
    }

    /**
     * Normalizes input down to a single visible symbol.
     *
     * @param string $symbol The raw input symbol.
     * @return string
     */
    private static function normalizeSymbol(string $symbol): string
    {
        $symbols = self::toSymbols($symbol);

        return $symbols[0] ?? ' ';
    }

    /**
     * Splits a line into Unicode grapheme symbols.
     *
     * @param string $line The line to split.
     * @return string[]
     */
    private static function toSymbols(string $line): array
    {
        if ($line === '') {
            return [];
        }

        preg_match_all('/\X/u', $line, $matches);

        return $matches[0] ?? [];
    }

    /**
     * The tile file the current grid should be saved as.
     *
     * A changed grid is written as a nowdoc; an untouched canonical source
     * keeps its exact bytes.
     */
    private function buildMapPayload(): string
    {
        return $this->layers->getBaseGrid()->getSource();
    }

    /**
     * The event-layer file the current grid should be saved as.
     */
    private function buildEventPayload(): string
    {
        return $this->layers->getEventGrid()->getSource();
    }

    /**
     * @inheritDoc
     */
    protected function buildPersistedPayload(): string
    {
        // The id is part of what a save persists: a moved map differs from
        // its old self even when every cell matches.
        return $this->mapId
            . "\0" . self::exportPhpValue($this->editableData)
            . "\0" . serialize($this->layers->getSources());
    }

    /**
     * Moves this map to a new stable path — the one explicit way a map's
     * identity changes.
     *
     * Ordinary saves never relocate anything. This writes the map at the new
     * path first, deletes the old directory only after every file landed,
     * and rolls the new copy back if that deletion fails — the map is never
     * left half-moved. References are NOT migrated: doors, quests, saves and
     * event identities that name the old id keep naming it, which is the
     * caller's warning to give.
     *
     * @param string $newRelativeId The new project-relative map id.
     * @return self The relocated map, freshly loaded from its new path; the
     *   caller replaces this instance with it.
     */
    public function moveTo(string $newRelativeId): self
    {
        $this->assertEditable();
        $newRelativeId = trim(str_replace('\\', '/', $newRelativeId), '/ ');

        if ($newRelativeId === '') {
            throw new RuntimeException('A map path cannot be empty.');
        }

        if ($newRelativeId === $this->mapId) {
            return $this;
        }

        $this->assertGridSourcesCanonical();

        $mapsRoot = $this->getMapsRoot();
        $directory = $mapsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newRelativeId);

        if (is_dir($directory)) {
            throw new RuntimeException("Map path {$newRelativeId} already exists.");
        }

        $previousDirectory = $this->directory;
        $previousMapId = $this->mapId;
        $baseName = basename($directory);

        try {
            $transaction = new FileSetTransaction($directory, reserveFolder: true);
            // A move carries the map's content as authored: the data file
            // is the preserved source (unsaved edits rewritten into it, not
            // a regeneration of the whole array), and untouched grids keep
            // their bytes exactly.
            $movedDataPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php';
            $movedMapPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.map.php';
            $movedEventPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.event.php';
            $transaction->write(
                $movedDataPath,
                $this->dataDocument === null ? (string) $this->unparsedDataSource : $this->proposedDataSource(),
            );
            $this->validateLayerContracts();
            $this->layers->stageChanges($transaction, $directory, $baseName, true);
            $transaction->remove($this->dataPath);
            $expectedGrids = $this->layers->getSources($directory, $baseName);
            $sourceDirectoryMetadata = self::captureDirectoryMetadata($previousDirectory);
            $memberDirectoryMetadata = [];
            foreach (['layers', MapGraphics::DIRECTORY] as $member) {
                if (is_dir($previousDirectory . '/' . $member)) {
                    $memberDirectoryMetadata[] = self::captureDirectoryMetadata($previousDirectory . '/' . $member);
                }
            }
            $removedSourceDirectories = [];
            $directoryRestorationFailures = [];

            // Validation runs only after the destination triplet has its
            // exact runtime names and every source member is gone. That is
            // the state the game will load: no preview copies, temporary
            // neighbours, or old member can make an invalid move look valid.
            $transaction->commit(validate: function () use (
                $movedDataPath,
                $movedEventPath,
                $expectedGrids,
                $newRelativeId,
                $mapsRoot,
                $sourceDirectoryMetadata,
                $memberDirectoryMetadata,
                &$removedSourceDirectories,
                &$directoryRestorationFailures,
            ): void {
                $removedSourceDirectories = self::removeEmptySourceDirectories(
                    $memberDirectoryMetadata,
                    $mapsRoot,
                    $sourceDirectoryMetadata,
                );

                try {
                    try {
                        $evaluatedFingerprint = null;
                        $evaluated = $this->evaluateFile($movedDataPath, $evaluatedFingerprint);
                    } catch (\Throwable $evaluationFailure) {
                        throw new RuntimeException(sprintf(
                            '%s does not evaluate at %s (%s) - an expression written against the old folder depth, such as a relative require, must be adjusted in the file first.',
                            basename($movedDataPath),
                            $newRelativeId,
                            $evaluationFailure->getMessage(),
                        ), previous: $evaluationFailure);
                    }

                    if (! is_array($evaluated)
                        || $evaluatedFingerprint !== PhpDataFile::valueFingerprint($this->editableData)
                    ) {
                        throw new RuntimeException(sprintf(
                            '%s would not read back as this map at %s.',
                            basename($movedDataPath),
                            $newRelativeId,
                        ));
                    }

                    // Terminal grids must read back as literal grids; tile
                    // layers are carried as bytes, readable or not, so they
                    // must hold exactly the bytes that were moved.
                    foreach ($expectedGrids as $path => $expectedSource) {
                        if (dirname($path) === dirname($movedDataPath) . '/' . MapGraphics::DIRECTORY
                            ? @file_get_contents($path) !== $expectedSource
                            : MapGridSource::readFile($path) !== MapGridSource::parseSource($expectedSource, $path)) {
                            throw new RuntimeException(basename($path) . ' would not read back as this map at ' . $newRelativeId);
                        }
                    }
                    $set = MapLayerSource::loadFromDirectory(dirname($movedDataPath), $newRelativeId);
                    if (! $set->legacy) {
                        $set->assertMatchingGrid(MapLayer::parseGrid(MapGridSource::readFile($movedEventPath)), $movedEventPath);
                    }
                } catch (\Throwable $validationFailure) {
                    // The file rollback needs the old directories in place.
                    // Recreate only directories this validation removed; the
                    // transaction then restores each original source member.
                    $directoryRestorationFailures = self::recreateDirectories($removedSourceDirectories);

                    throw $validationFailure;
                }
            }, afterRollback: static function () use (
                $sourceDirectoryMetadata,
                &$removedSourceDirectories,
                &$directoryRestorationFailures,
            ): array {
                $directoriesToRestore = $removedSourceDirectories;

                if (! in_array(
                    $sourceDirectoryMetadata['path'],
                    array_column($directoriesToRestore, 'path'),
                    true,
                )) {
                    array_unshift($directoriesToRestore, $sourceDirectoryMetadata);
                }

                return array_values(array_unique([
                    ...$directoryRestorationFailures,
                    ...self::restoreDirectoryMetadata(
                        $directoriesToRestore,
                        $directoryRestorationFailures,
                        array_column($removedSourceDirectories, 'path'),
                    ),
                ]));
            });
        } catch (\Throwable $throwable) {
            if ($throwable instanceof FileSetTransactionFailure && ! $throwable->wasRolledBack) {
                throw new RuntimeException(sprintf(
                    'The move to %s failed and could not be fully rolled back (%s). Restore the named files before editing %s further.',
                    $newRelativeId,
                    $throwable->getMessage(),
                    $previousMapId,
                ), previous: $throwable);
            }

            throw new RuntimeException(sprintf(
                'The move to %s failed and was rolled back (%s). The map is still %s.',
                $newRelativeId,
                $throwable->getMessage(),
                $previousMapId,
            ), previous: $throwable);
        }

        $set = MapLayerSource::loadFromDirectory($directory, $newRelativeId);
        $eventText = MapGridSource::readFile($movedEventPath);
        return new self($newRelativeId, $directory, $movedDataPath, $movedMapPath, $movedEventPath,
            $this->editableData, self::splitMapText($set->layers[0]->text), self::splitMapText($eventText),
            (string) file_get_contents($movedDataPath),
            layers: MapLayers::createFromSource($directory, $set, $movedEventPath, $eventText),
        )->withLoadedBaseline();
    }

    /**
     * Resolves the save target directory, filenames, and map id from the current metadata.
     *
     * @return array{mapId: string, directory: string, dataPath: string, mapPath: string, eventPath: string}
     */
    private function resolveSaveTarget(): array
    {
        // A map that exists on disk keeps its path: the relative path is the
        // map's stable identity -- doors transfer to it, quests reach for it,
        // saves record it. Deriving the path from the display name and region
        // made every map whose metadata did not slug-match its location a
        // permanent rename target: bsa/licensing-facility/administration can
        // never equal region/name, so saving wanted to relocate it and Save
        // All skipped it as a pending move. Name and region are display
        // metadata; where a map lives only changes by an explicit move.
        if (is_dir($this->directory)) {
            $baseName = basename($this->directory);

            return [
                'mapId' => $this->mapId,
                'directory' => $this->directory,
                'dataPath' => $this->directory . DIRECTORY_SEPARATOR . $baseName . '.data.php',
                'mapPath' => $this->directory . DIRECTORY_SEPARATOR . $baseName . '.map.php',
                'eventPath' => $this->directory . DIRECTORY_SEPARATOR . $baseName . '.event.php',
            ];
        }

        $mapsRoot = $this->getMapsRoot();
        $baseName = self::slugify($this->getDisplayName(), basename($this->directory));
        // An empty region must stay empty — falling back to the default slug
        // would silently relocate region-less maps into a "new-map" folder.
        $region = self::slugify($this->getRegion(), '');
        $relativePath = $region !== '' ? $region . DIRECTORY_SEPARATOR . $baseName : $baseName;
        $directory = $mapsRoot . DIRECTORY_SEPARATOR . $relativePath;
        $mapId = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        if (is_dir($directory)) {
            throw new RuntimeException("Map path {$mapId} already exists.");
        }

        return [
            'mapId' => $mapId,
            'directory' => $directory,
            'dataPath' => $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php',
            'mapPath' => $directory . DIRECTORY_SEPARATOR . $baseName . '.map.php',
            'eventPath' => $directory . DIRECTORY_SEPARATOR . $baseName . '.event.php',
        ];
    }

    /**
     * Resolves the maps root from the current map id and directory.
     *
     * @return string
     */
    private function getMapsRoot(): string
    {
        $segments = max(1, count(explode('/', $this->mapId)));

        return dirname($this->directory, $segments);
    }

    /**
     * Removes empty parent directories up to, but not including, the maps root.
     *
     * @param string $directory The starting directory.
     * @param string $mapsRoot The maps root.
     * @return void
     */
    private static function deleteEmptyParentDirectories(string $directory, string $mapsRoot): void
    {
        $mapsRoot = rtrim($mapsRoot, DIRECTORY_SEPARATOR);
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);

        while ($directory !== '' && $directory !== $mapsRoot && str_starts_with($directory, $mapsRoot . DIRECTORY_SEPARATOR)) {
            if (! is_dir($directory) || self::directoryHasContents($directory)) {
                return;
            }

            if (! @rmdir($directory)) {
                return;
            }

            $directory = dirname($directory);
        }
    }

    /**
     * Removes a moved map's emptied member directories (`layers/`,
     * `graphics/`), then its source directory and empty parents.
     *
     * @param list<array{path: string, mode: int, owner: int, group: int, modifiedAt: int, accessedAt: int, device: int, inode: int}> $memberDirectoryMetadata Metadata captured before source members are removed.
     * @param array{path: string, mode: int, owner: int, group: int, modifiedAt: int, accessedAt: int, device: int, inode: int} $sourceDirectoryMetadata
     * @return list<array{path: string, mode: int, owner: int, group: int, modifiedAt: int, accessedAt: int, device: int, inode: int}> The removed directories, leaf first.
     */
    private static function removeEmptySourceDirectories(array $memberDirectoryMetadata, string $mapsRoot, array $sourceDirectoryMetadata): array
    {
        $removed = [];

        foreach ($memberDirectoryMetadata as $metadata) {
            $entries = is_dir($metadata['path']) ? scandir($metadata['path']) : false;

            // A member directory holding anything else is its author's.
            if (is_array($entries) && array_diff($entries, ['.', '..']) === [] && @rmdir($metadata['path'])) {
                $removed[] = $metadata;
            }
        }

        return [
            ...$removed,
            ...self::removeEmptyDirectoryChain($sourceDirectoryMetadata['path'], $mapsRoot, $sourceDirectoryMetadata),
        ];
    }

    /**
     * Removes the empty source directory and its empty parents for a move.
     *
     * The returned leaf-first list carries the directory metadata needed to
     * recreate exactly those directories before a refused installed-state
     * validation is rolled back. The whole candidate chain is inspected
     * before its leaf is removed, so removing a child cannot change the
     * parent timestamp before it is captured.
     *
     * @param array{path: string, mode: int, owner: int, group: int, modifiedAt: int, accessedAt: int, device: int, inode: int} $sourceDirectoryMetadata Metadata captured before source members are removed.
     * @return list<array{path: string, mode: int, owner: int, group: int, modifiedAt: int, accessedAt: int, device: int, inode: int}> The removed directories, leaf first.
     */
    private static function removeEmptyDirectoryChain(
        string $directory,
        string $mapsRoot,
        array $sourceDirectoryMetadata,
    ): array {
        $candidates = [];
        $mapsRoot = rtrim($mapsRoot, DIRECTORY_SEPARATOR);
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $child = null;

        while ($directory !== '' && $directory !== $mapsRoot && str_starts_with($directory, $mapsRoot . DIRECTORY_SEPARATOR)) {
            $metadata = $directory === $sourceDirectoryMetadata['path']
                ? $sourceDirectoryMetadata
                : self::captureDirectoryMetadata($directory);
            $entries = is_dir($directory) ? scandir($directory) : false;

            if (! is_array($entries)) {
                break;
            }

            $entries = array_values(array_diff($entries, ['.', '..']));
            $expectedEntries = $child === null ? [] : [basename($child)];

            if ($entries !== $expectedEntries) {
                break;
            }

            $candidates[] = $metadata;
            $child = $directory;
            $directory = dirname($directory);
        }

        $removed = [];

        foreach ($candidates as $candidate) {
            if (! @rmdir($candidate['path'])) {
                break;
            }

            $removed[] = $candidate;
        }

        return $removed;
    }

    /**
     * Captures the filesystem metadata needed for an exact directory
     * rollback before any member or child is removed.
     *
     * @return array{path: string, mode: int, owner: int, group: int, modifiedAt: int, accessedAt: int, device: int, inode: int}
     */
    private static function captureDirectoryMetadata(string $directory): array
    {
        $metadata = @stat($directory);

        if (! is_array($metadata)) {
            throw new RuntimeException(sprintf('Unable to capture the directory metadata for %s.', $directory));
        }

        return [
            'path' => $directory,
            'mode' => ((int) $metadata['mode']) & 0o7777,
            'owner' => (int) $metadata['uid'],
            'group' => (int) $metadata['gid'],
            'modifiedAt' => (int) $metadata['mtime'],
            'accessedAt' => (int) $metadata['atime'],
            'device' => (int) $metadata['dev'],
            'inode' => (int) $metadata['ino'],
        ];
    }

    /**
     * Recreates a removed directory chain, parents first, for file rollback.
     *
     * Directories are deliberately private while files are restored. Their
     * authored metadata is applied only after file rollback, because those
     * writes change directory timestamps.
     *
     * @param list<array{path: string, mode: int, owner: int, group: int, modifiedAt: int, accessedAt: int, device: int, inode: int}> $directories Leaf-first removed directories.
     * @return list<string> Paths that could not be recreated.
     */
    private static function recreateDirectories(array $directories): array
    {
        $failed = [];
        $blocked = false;

        foreach (array_reverse($directories) as $metadata) {
            $directory = $metadata['path'];

            if ($blocked || is_dir($directory) || ! @mkdir($directory, 0o700)) {
                $failed[] = $directory;
                $blocked = true;
            }
        }

        return $failed;
    }

    /**
     * Restores and verifies the metadata of directories recreated for a
     * refused move. Leaf-first order makes each parent's final timestamp the
     * one it had before its children were removed and restored.
     *
     * @param list<array{path: string, mode: int, owner: int, group: int, modifiedAt: int, accessedAt: int, device: int, inode: int}> $directories Leaf-first directories.
     * @param list<string> $excluded Paths now owned by someone else, which
     *   must be reported without changing their metadata.
     * @param list<string> $recreated Paths recreated by this transaction;
     *   retained paths must still be the original directory object.
     * @return list<string> Paths whose metadata could not be restored exactly.
     */
    private static function restoreDirectoryMetadata(
        array $directories,
        array $excluded = [],
        array $recreated = [],
    ): array
    {
        $failed = [];

        foreach ($directories as $metadata) {
            $directory = $metadata['path'];

            if (in_array($directory, $excluded, true)) {
                $failed[] = $directory;

                continue;
            }

            $current = @stat($directory);

            if (! is_array($current)) {
                $failed[] = $directory;

                continue;
            }

            if (! in_array($directory, $recreated, true)
                && ((int) $current['dev'] !== $metadata['device'] || (int) $current['ino'] !== $metadata['inode'])
            ) {
                $failed[] = $directory;

                continue;
            }

            $restored = true;

            if (PHP_OS_FAMILY !== 'Windows') {
                if ((int) $current['uid'] !== $metadata['owner']) {
                    $restored = @chown($directory, $metadata['owner']);
                }

                if ((int) $current['gid'] !== $metadata['group']) {
                    $restored = @chgrp($directory, $metadata['group']) && $restored;
                }
            }

            if ($restored) {
                $restored = @chmod($directory, $metadata['mode'])
                    && @touch($directory, $metadata['modifiedAt'], $metadata['accessedAt']);
            }

            clearstatcache(true, $directory);
            $actual = @stat($directory);
            $restored = $restored
                && is_array($actual)
                && (((int) $actual['mode']) & 0o7777) === $metadata['mode']
                && (int) $actual['mtime'] === $metadata['modifiedAt']
                && (int) $actual['atime'] === $metadata['accessedAt']
                && (PHP_OS_FAMILY === 'Windows'
                    || ((int) $actual['uid'] === $metadata['owner'] && (int) $actual['gid'] === $metadata['group']));

            if (! $restored) {
                $failed[] = $directory;
            }
        }

        return $failed;
    }

    /**
     * Returns whether a directory still contains files or folders.
     *
     * @param string $directory The directory to inspect.
     * @return bool
     */
    private static function directoryHasContents(string $directory): bool
    {
        $entries = scandir($directory);

        if (! is_array($entries)) {
            return true;
        }

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Converts display text into a filesystem-safe kebab-case slug.
     *
     * @param string $value The display text.
     * @param string $fallback The fallback slug.
     * @return string
     */
    public static function slugify(string $value, string $fallback = 'new-map'): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : $fallback;
    }

    /**
     * Creates a blank tile cell for padded map rows.
     *
     * @return array{symbol: string, prefix: string, suffix: string}
     */
    private static function createBlankTileCell(): array
    {
        return [
            'symbol' => ' ',
            'prefix' => '',
            'suffix' => '',
        ];
    }

    /**
     * Creates a blank tile row.
     *
     * @param int $width The desired row width.
     * @return array<int, array{symbol: string, prefix: string, suffix: string}>
     */
    private static function createBlankTileRow(int $width): array
    {
        $row = [];

        for ($column = 0; $column < $width; $column++) {
            $row[] = self::createBlankTileCell();
        }

        return $row;
    }

    /**
     * Exports a PHP value using the repository's short-array style.
     *
     * @param mixed $value The value to export.
     * @param int $indentLevel The current indentation depth.
     * @return string
     */
    private static function exportPhpValue(mixed $value, int $indentLevel = 0): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('  ', $indentLevel);
        $nextIndent = str_repeat('  ', $indentLevel + 1);
        $isList = array_is_list($value);
        $lines = ['['];

        foreach ($value as $key => $item) {
            $exportedItem = self::exportPhpValue($item, $indentLevel + 1);

            if ($isList) {
                $lines[] = "{$nextIndent}{$exportedItem},";
                continue;
            }

            $exportedKey = var_export($key, true);
            $lines[] = "{$nextIndent}{$exportedKey} => {$exportedItem},";
        }

        $lines[] = "{$indent}]";

        return implode("\n", $lines);
    }
}
