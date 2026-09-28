<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Rendering\Tilesets\TileId;

/**
 * Editable layers and their file-set boundary. Layer ids survive renaming.
 * The map's graphical tile layers share the boundary: the TUI never paints
 * single tiles, but writes the tiles of stamped and drawn pieces and resizes,
 * relocates and removes them with the map.
 */
final class MapLayers
{
    public const string EVENT = 'event';
    public const string BASE = 'tile';
    private array $layers = [];
    /** @var array<string, string> Tile layer sources in graphics/, by path. */
    private array $tileSources = [];
    /** Original on-disk members, including layers removed or renamed in memory. */
    private array $baselineSources = [];

    public function __construct(
        private readonly string $directory,
        public private(set) bool $legacy,
        array $layers,
        EditableGrid $events,
        string $eventPath,
        array $tileSources = [],
    ) {
        foreach ($layers as $layer) {
            $this->layers[$layer['id']] = $layer;
        }
        $this->tileSources = $tileSources;
        $this->layers[self::EVENT] = [
            'id' => self::EVENT, 'name' => 'Events', 'order' => null, 'decoration' => false,
            'path' => $eventPath, 'grid' => $events,
        ];
        $this->captureBaseline();
    }

    /**
     * Formats a layer's name for display, so every layer reads alike: the
     * Events layer and gameplay layers named by their files (fixtures,
     * upper-floor) show as Events, Fixtures and Upper Floor. The name itself,
     * which pieces and files use, is unchanged.
     */
    public static function formatLabel(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }

    public static function createFromSource(string $directory, MapLayerSet $set, string $eventPath, string $eventText): self
    {
        $layers = [];
        foreach ($set->layers as $layer) {
            $path = $directory . ($set->legacy ? '/' : '/layers/') . basename($layer->path);
            $layers[] = [
                'id' => $set->legacy ? self::BASE : 'map:' . $layer->order,
                'name' => $layer->name, 'order' => $layer->order, 'decoration' => $layer->decoration,
                'path' => $path,
                'grid' => new EditableGrid($layer->text, (string) file_get_contents($path), $set->legacy),
            ];
        }
        $events = new EditableGrid($eventText, (string) file_get_contents($eventPath));
        $tileSources = [];
        foreach (TileLayerSource::findPaths($directory) as $path) {
            $tileSources[$path] = (string) file_get_contents($path);
        }
        return new self($directory, $set->legacy, $layers, $events, $eventPath, $tileSources);
    }

    /** @return array<string, string> Tile layer sources in graphics/, by path. */
    public function getTileSources(): array
    {
        return $this->tileSources;
    }

    public function getLayers(): array
    {
        $layers = array_values($this->layers);
        usort($layers, static fn(array $a, array $b): int => ($a['order'] ?? PHP_INT_MAX) <=> ($b['order'] ?? PHP_INT_MAX));
        return $layers;
    }

    public function getBaseId(): string
    {
        foreach ($this->getLayers() as $layer) {
            if ($layer['id'] !== self::EVENT && ! $layer['decoration']) {
                return $layer['id'];
            }
        }
        throw new MapSourceRefusal('A map needs at least one gameplay layer.');
    }

    public function getLayer(string $id): array
    {
        $id = $id === self::BASE && ! isset($this->layers[$id]) ? $this->getBaseId() : $id;
        return $this->layers[$id] ?? throw new MapSourceRefusal("Unknown map layer {$id}.");
    }

    public function getBaseGrid(): EditableGrid
    {
        return $this->getGrid($this->getBaseId());
    }

    public function getEventGrid(): EditableGrid
    {
        return $this->getGrid(self::EVENT);
    }

    public function getGrid(string $id): EditableGrid
    {
        return $this->getLayer($id)['grid'];
    }

    public function createLayer(string $name, bool $decoration, ?int $order = null): string
    {
        $this->assertNameAvailable($name);
        $used = array_column($this->getLayers(), 'order');
        if ($order === null) {
            $order = max(array_filter($used, is_int(...))) + 1;
        }
        if ($order < 0 || $order > 99 || in_array($order, $used, true)) {
            throw new MapSourceRefusal('Layer order must be an unused two-digit number (00-99).');
        }
        // Adding the first layer explicitly converts the legacy member in the
        // same transaction; merely opening or saving a legacy map never does.
        if ($this->legacy) {
            $source = $this->getBaseGrid()->getSource();
            $this->layers[self::BASE]['grid'] = new EditableGrid(MapGridSource::parseSource($source, $this->layers[self::BASE]['path']), $source);
            $this->layers[self::BASE]['path'] = $this->buildPath(0, 'terrain', false);
            $this->legacy = false;
        }
        $id = 'map:' . $order;
        $grid = new EditableGrid(implode("\n", array_map(
            static fn(array $row): string => str_repeat(' ', count($row)), $this->getBaseGrid()->cells,
        )));
        $this->layers[$id] = ['id' => $id, 'name' => $name, 'order' => $order, 'decoration' => $decoration,
            'path' => $this->buildPath($order, $name, $decoration), 'grid' => $grid];
        return $id;
    }

    public function renameLayer(string $id, string $name): void
    {
        $layer = $this->getLayer($id);
        if ($layer['name'] === $name) {
            return;
        }
        $this->assertManagedLayer($layer);
        $this->assertNameAvailable($name);
        $this->layers[$layer['id']]['name'] = $name;
        $this->layers[$layer['id']]['path'] = $this->buildPath($layer['order'], $name, $layer['decoration']);
    }

    public function removeLayer(string $id): void
    {
        $layer = $this->getLayer($id);
        $this->assertManagedLayer($layer);
        if (! $layer['decoration'] && count(array_filter($this->layers,
            static fn(array $entry): bool => $entry['id'] !== self::EVENT && ! $entry['decoration'],
        )) === 1) {
            throw new MapSourceRefusal('The last gameplay layer cannot be removed.');
        }
        unset($this->layers[$layer['id']]);
    }

    private function assertManagedLayer(array $layer): void
    {
        if ($this->legacy || $layer['id'] === self::EVENT) {
            throw new MapSourceRefusal('Only authored layers in layers/ can be renamed or removed.');
        }
    }

    private function assertNameAvailable(string $name): void
    {
        if (preg_match(MapLayerSource::FILENAME_PATTERN, '00.' . $name . '.map.php') !== 1) {
            throw new MapSourceRefusal('Use a layer name beginning with a letter, followed by letters, digits, hyphens or underscores.');
        }
        foreach ($this->layers as $layer) {
            if ($layer['id'] !== self::EVENT && $layer['name'] === $name) {
                throw new MapSourceRefusal("Layer {$name} already exists.");
            }
        }
    }

    private function buildPath(int $order, string $name, bool $decoration): string
    {
        return sprintf('%s/layers/%02d.%s.%s.php', $this->directory, $order, $name, $decoration ? 'deco' : 'map');
    }

    public function captureSnapshot(): array
    {
        return ['legacy' => $this->legacy, 'layers' => array_map(
            static fn(array $layer): array => [...$layer, 'grid' => $layer['grid']->captureSnapshot()], $this->layers,
        ), 'tiles' => $this->tileSources];
    }

    public function restoreSnapshot(array $snapshot): void
    {
        $this->legacy = $snapshot['legacy'];
        $this->layers = array_map(static fn(array $layer): array => [...$layer, 'grid' => EditableGrid::createFromSnapshot($layer['grid'])], $snapshot['layers']);
        $this->tileSources = $snapshot['tiles'];
    }

    /**
     * Resizes every layer, tile layers included: a tile layer holds one tile
     * per map cell, so it is cropped or padded like the terminal layers.
     * Every tile layer is resized before anything changes, so one the Engine
     * cannot read refuses the whole resize.
     *
     * @throws MapSourceRefusal When a tile layer cannot be read or does not match the map.
     */
    public function resize(int $width, int $height): void
    {
        $tileSources = [];
        if ($this->tileSources !== []) {
            $set = $this->getLayerSet();
            foreach ($this->tileSources as $path => $source) {
                $tileSources[$path] = TileLayerSource::resize($source, $this->getDisplayPath($path), $set, $width, $height,
                    $this->baselineSources[$path] ?? null);
            }
        }
        foreach ($this->layers as $layer) {
            $layer['grid']->resize($width, $height);
        }
        $this->tileSources = $tileSources;
    }

    /**
     * Writes tile entries into the named tile layers with their top-left
     * cell at (x, y), as one stamped piece: a `0` entry leaves its cell as it
     * was. A layer the map does not have yet is created in `graphics/` with
     * the next order after its tile layers, every cell empty. Every layer is
     * written before anything changes, so one that cannot take the entries
     * refuses them all.
     *
     * @param array<string, list<list<string>>> $tiles Entries by row, keyed by tile layer name.
     * @throws MapSourceRefusal When a layer cannot be read, does not match the map, cannot be created, or the entries fall outside it.
     */
    public function writeTileEntries(array $tiles, int $x, int $y): void
    {
        if ($tiles === []) {
            return;
        }
        $set = $this->getLayerSet();
        $sources = $this->tileSources;
        foreach ($tiles as $name => $rows) {
            $path = $this->findTileLayerPath((string) $name, $sources) ?? $this->buildTileLayerPath((string) $name, $sources);
            $sources[$path] = TileLayerSource::writeEntries($sources[$path] ?? TileLayerSource::createEmpty($set),
                $this->getDisplayPath($path), $set, $x, $y, $rows, $this->baselineSources[$path] ?? null);
        }
        ksort($sources, SORT_STRING);
        $this->tileSources = $sources;
    }

    /**
     * Sets tile entries cell by cell in the named tile layers, `0` included,
     * for a drawn or erased connected piece. A layer the map does not have
     * yet is created as {@see writeTileEntries()} creates one, unless every
     * entry for it is `0`, which an empty layer already holds. Every layer is
     * written before anything changes, so one that cannot take its entries
     * refuses them all.
     *
     * @param array<string, list<array{x: int, y: int, entry: string}>> $cells The entry for each cell, keyed by tile layer name.
     * @throws MapSourceRefusal When a layer cannot be read, does not match the map, cannot be created, or a cell falls outside it.
     */
    public function writeTileCells(array $cells): void
    {
        $set = $this->getLayerSet();
        $sources = $this->tileSources;
        foreach ($cells as $name => $entries) {
            $path = $this->findTileLayerPath((string) $name, $sources);
            if ($path === null) {
                if (array_filter($entries, static fn(array $cell): bool => $cell['entry'] !== (string)TileId::EMPTY) === []) {
                    continue;
                }
                $path = $this->buildTileLayerPath((string) $name, $sources);
            }
            $sources[$path] = TileLayerSource::setCellEntries($sources[$path] ?? TileLayerSource::createEmpty($set),
                $this->getDisplayPath($path), $set, $entries, $this->baselineSources[$path] ?? null);
        }
        ksort($sources, SORT_STRING);
        $this->tileSources = $sources;
    }

    /** @param array<string, string> $tileSources Tile layer sources by path, as {@see getTileSources()} returns them. */
    public function restoreTileSources(array $tileSources): void
    {
        $this->tileSources = $tileSources;
    }

    /**
     * The path of the tile layer with this name, or null when there is none.
     *
     * @param array<string, string> $sources Tile layer sources by path.
     * @throws MapSourceRefusal When more than one tile layer has the name.
     */
    private function findTileLayerPath(string $name, array $sources): ?string
    {
        $paths = array_values(array_filter(array_keys($sources), static fn(string $path): bool =>
            preg_match(MapGraphics::FILENAME_PATTERN, basename($path), $matches) === 1 && $matches['name'] === $name));
        if (count($paths) > 1) {
            throw new MapSourceRefusal(sprintf('Tile layers %s share the name %s; rename one so a piece can name it. Nothing was changed.',
                implode(' and ', array_map($this->getDisplayPath(...), $paths)), $name));
        }

        return $paths[0] ?? null;
    }

    /**
     * The path for a new tile layer, ordered after the map's tile layers.
     *
     * @param array<string, string> $sources Tile layer sources by path.
     * @throws MapSourceRefusal When the map cannot take another tile layer.
     */
    private function buildTileLayerPath(string $name, array $sources): string
    {
        $orders = [];
        foreach (array_keys($sources) as $path) {
            if (preg_match(MapGraphics::FILENAME_PATTERN, basename($path), $matches) === 1) {
                $orders[] = (int) $matches['order'];
            }
        }
        $order = $orders === [] ? 0 : max($orders) + 1;
        if (count($sources) >= MapGraphics::MAX_LAYERS || $order > 99) {
            throw new MapSourceRefusal(sprintf('Tile layer %s cannot be added: a map holds up to %d tile layers, ordered 00 to 99. Nothing was changed.',
                $name, MapGraphics::MAX_LAYERS));
        }
        $path = sprintf('%s/%s/%02d.%s.tiles.php', $this->directory, MapGraphics::DIRECTORY, $order, $name);
        if (preg_match(MapGraphics::FILENAME_PATTERN, basename($path)) !== 1) {
            throw new MapSourceRefusal("'{$name}' is not a tile layer name. Nothing was changed.");
        }

        return $path;
    }

    /** A tile layer path relative to its map, as refusals name it. */
    private function getDisplayPath(string $path): string
    {
        return basename($this->directory) . '/' . MapGraphics::DIRECTORY . '/' . basename($path);
    }

    public function assertDimensions(): void
    {
        $this->getLayerSet()->assertMatchingGrid($this->getEventGrid()->getSymbols(), $this->layers[self::EVENT]['path']);
    }

    public function getLayerSet(?string $renamedId = null, ?string $newName = null): MapLayerSet
    {
        $layers = [];
        foreach ($this->getLayers() as $layer) {
            if ($layer['id'] !== self::EVENT) {
                $layers[] = new MapLayer($layer['id'] === $renamedId ? $newName : $layer['name'],
                    $layer['order'], $layer['decoration'], $layer['path'],
                    MapGridSource::parseSource($layer['grid']->getSource(), $layer['path']));
            }
        }
        return new MapLayerSet($layers, $this->legacy);
    }

    public function assertSourcesUnchanged(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }
        foreach ($this->baselineSources as $path => $source) {
            if (! is_file($path)) {
                throw new MapSourceRefusal("{$path} disappeared after opening; reload before saving.");
            }
            if (dirname($path) !== $this->directory . '/' . MapGraphics::DIRECTORY) {
                // Tile layers are carried as bytes; validation reports one the Engine cannot read.
                MapGridSource::readFile($path);
            }
            if (file_get_contents($path) !== $source) {
                throw new MapSourceRefusal("{$path} changed after opening; reload before saving. Nothing was written.");
            }
        }
        if ($this->baselineSources !== []) {
            $set = MapLayerSource::loadFromDirectory($this->directory);
            $known = array_keys($this->baselineSources);
            foreach ($set->layers as $layer) {
                if (! in_array($layer->path, $known, true)) {
                    throw new MapSourceRefusal("{$layer->path} was added after opening; reload before saving.");
                }
            }
        }
        foreach (TileLayerSource::findPaths($this->directory) as $path) {
            if (! array_key_exists($path, $this->baselineSources)) {
                throw new MapSourceRefusal("{$path} was added after opening; reload before saving.");
            }
        }
    }

    public function getSources(?string $directory = null, ?string $baseName = null): array
    {
        $sources = [];
        foreach ($this->getLayers() as $layer) {
            $path = $layer['path'];
            if ($directory !== null) {
                $path = $layer['id'] === self::EVENT ? "{$directory}/{$baseName}.event.php"
                    : ($this->legacy ? "{$directory}/{$baseName}.map.php" : $directory . '/layers/' . basename($path));
            }
            $sources[$path] = $layer['grid']->getSource();
        }
        foreach ($this->tileSources as $path => $source) {
            $sources[$directory === null ? $path : $directory . '/' . MapGraphics::DIRECTORY . '/' . basename($path)] = $source;
        }
        return $sources;
    }

    public function stageChanges(FileSetTransaction $transaction, ?string $directory = null, ?string $baseName = null, bool $removeOriginals = false): void
    {
        $sources = $this->getSources($directory, $baseName);
        foreach ($sources as $path => $source) {
            if ($directory !== null || ($this->baselineSources[$path] ?? null) !== $source || ! is_file($path)) {
                $transaction->write($path, $source);
            }
        }
        if ($directory === null || $removeOriginals) {
            foreach ($this->baselineSources as $path => $source) {
                if (! isset($sources[$path])) {
                    $transaction->remove($path);
                }
            }
        }
    }

    public function captureBaseline(): void
    {
        $this->baselineSources = [];
        foreach ($this->getSources() as $path => $source) {
            if (is_file($path)) {
                $this->baselineSources[$path] = $source;
            }
        }
    }

    public function getStoredPaths(): array
    {
        return array_keys($this->baselineSources);
    }

}
