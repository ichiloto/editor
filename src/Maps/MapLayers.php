<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapLayer;

/** Editable layers and their file-set boundary. Layer ids survive renaming. */
final class MapLayers
{
    public const string EVENT = 'event';
    public const string BASE = 'tile';
    private array $layers = [];
    /** Original on-disk members, including layers removed or renamed in memory. */
    private array $baselineSources = [];

    public function __construct(
        private readonly string $directory,
        public private(set) bool $legacy,
        array $layers,
        EditableGrid $events,
        string $eventPath,
    ) {
        foreach ($layers as $layer) {
            $this->layers[$layer['id']] = $layer;
        }
        $this->layers[self::EVENT] = [
            'id' => self::EVENT, 'name' => 'Events', 'order' => null, 'decoration' => false,
            'path' => $eventPath, 'grid' => $events,
        ];
        $this->captureBaseline();
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
        return new self($directory, $set->legacy, $layers, new EditableGrid($eventText, (string) file_get_contents($eventPath)), $eventPath);
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
        )];
    }

    public function restoreSnapshot(array $snapshot): void
    {
        $this->legacy = $snapshot['legacy'];
        $this->layers = array_map(static fn(array $layer): array => [...$layer, 'grid' => EditableGrid::createFromSnapshot($layer['grid'])], $snapshot['layers']);
    }

    public function resize(int $width, int $height): void
    {
        foreach ($this->layers as $layer) {
            $layer['grid']->resize($width, $height);
        }
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
            MapGridSource::readFile($path);
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
