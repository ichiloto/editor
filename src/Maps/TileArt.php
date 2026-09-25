<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Tiles\GraphicalTileDefinition;

/** Cell-art mutations share ProjectMap's source, transaction and history boundaries. */
trait TileArt
{
    public function getCellTileArt(string $layer, int $column, int $row): array
    {
        $path = $this->getTileArtPath($layer, $column, $row);
        $name = $this->layers->getLayer($layer)['name'];
        $definition = $this->getLayerTileDefinitions()[$name] ?? null;
        $glyph = $this->getLayerSymbol($layer, $column, $row);
        $inherited = $definition?->symbols[$glyph] ?? null;
        $override = $definition?->hasCellOverride($column, $row) ?? false;
        $index = $override ? $definition->getSourceIndex($glyph, $column, $row) : null;
        return ['layer' => $name, 'symbol' => $glyph,
            'asset' => $definition?->asset ?? ($this->getMapDataField($path)['asset'] ?? ($this->editableData['tiles2d']['asset'] ?? null)),
            'inherited' => $inherited === null ? null : $definition->sources[$inherited]->toArray(),
            'override' => $index === null ? null : $definition->sources[$index]->toArray()];
    }

    /** Applies one override without retargeting any inherited atlas unless explicitly confirmed. */
    public function setCellTileArt(string $layer, int $column, int $row, array $source, ?string $asset = null, bool $confirmAssetChange = false): void
    {
        $this->assertEditable();
        $path = $this->getTileArtPath($layer, $column, $row);
        $current = $this->getCellTileArt($layer, $column, $row);
        $asset ??= $current['asset'];
        if ($asset === null || ! in_array($asset, ReferenceCatalog::getPngAssets(dirname($this->getMapsRoot(), 2)), true)) {
            throw new MapSourceRefusal('Choose an existing PNG inside this project\'s assets through the asset picker.');
        }
        if ($current['asset'] !== null && $current['asset'] !== $asset && ! $confirmAssetChange) {
            throw new MapSourceRefusal('Changing this layer atlas affects every crop on the layer; explicit confirmation is required.');
        }
        $definition = $this->getMapDataField($path) ?? [];
        $cells = $definition['cells'] ?? [];
        $index = count($cells);
        foreach ($cells as $key => $cell) {
            if ($cell['column'] === $column && $cell['row'] === $row) {
                $index = $key;
                break;
            }
        }
        if (isset($cells[$index])) {
            // Keep authored key order; source verification compares exact PHP values.
            $cells[$index]['source'] = array_replace(array_intersect_key($cells[$index]['source'], $source), $source);
        } else {
            $cells[$index] = ['column' => $column, 'row' => $row, 'source' => $source];
        }
        $definition['cells'] = $cells;
        if ($asset !== $current['asset']) {
            $this->assertTileArtSourceEditable([...$path, 'asset']);
            $definition['asset'] = $asset;
        }
        $this->writeTileArtDefinition($path, $definition, inspectAsset: true);
    }

    public function removeCellTileArt(string $layer, int $column, int $row): void
    {
        $this->assertEditable();
        $path = $this->getTileArtPath($layer, $column, $row);
        if ($this->getCellTileArt($layer, $column, $row)['override'] === null) {
            return;
        }
        $definition = $this->getMapDataField($path);
        $definition['cells'] = array_values(array_filter($definition['cells'],
            static fn(array $cell): bool => $cell['column'] !== $column || $cell['row'] !== $row));
        if ($definition['cells'] === []) {
            unset($definition['cells']);
        }
        $this->writeTileArtDefinition($path, ($definition['symbols'] ?? []) === [] && ! isset($definition['cells']) ? null : $definition);
    }

    private function getTileArtPath(string $layer, int $column, int $row): array
    {
        $target = $this->layers->getLayer($layer);
        if ($target['id'] === MapLayers::EVENT || ! isset($target['grid']->cells[$row][$column])) {
            throw new MapSourceRefusal('Tile art needs an existing gameplay or decoration cell, not Events or a position outside its row.');
        }
        return $this->layers->legacy ? ['tiles2d'] : ['tiles2d', 'layers', $target['name']];
    }

    private function writeTileArtDefinition(array $path, ?array $definition, bool $inspectAsset = false): void
    {
        $this->assertTileArtSourceEditable([...$path, 'cells'], true);
        if ($definition === null) { $this->assertTileArtSourceEditable($path, true); }
        $next = $this->editableData;
        if ($this->layers->legacy) {
            if ($definition === null) { unset($next['tiles2d']); }
            else { $next['tiles2d'] = $definition; }
        } else {
            $name = $path[2];
            if ($definition === null) {
                unset($next['tiles2d']['layers'][$name]);
                if ($next['tiles2d']['layers'] === []) {
                    $this->assertTileArtSourceEditable(['tiles2d'], true);
                    unset($next['tiles2d']);
                }
            } else {
                $next['tiles2d']['layers'][$name] = $definition;
            }
        }
        $set = $this->getLayerSet();
        $definitions = isset($next['tiles2d']) ? GraphicalTileDefinition::getForLayers($next['tiles2d'], $set, $this->mapId) : [];
        if ($inspectAsset) {
            $resolved = $definitions[$this->layers->legacy ? 'terrain' : $path[2]];
            foreach ($resolved->sources as $crop) {
                PngAssetPreflight::inspect(dirname($this->getMapsRoot()), $resolved->asset, $crop);
            }
        }
        // Coverage can be incomplete while authoring; ProjectMap::save checks all decoration together.
        $this->writeData($next);
    }

    /** Refuse opaque target containers even when the general writer could replace a variable. */
    private function assertTileArtSourceEditable(array $path, bool $recursive = false): void
    {
        if ($this->dataDocument === null) {
            throw new MapSourceRefusal($this->dataSourceIssue ?? 'The map data source cannot be preserved.');
        }
        $document = PhpArraySourceDocument::parse($this->proposedDataSource());
        $node = $document->root();
        $walked = [];
        foreach ($path as $key) {
            $this->assertTileArtSourceNode($node, $walked);
            $walked[] = $key;
            $node = $node->entryFor($key)?->value;
            if ($node === null) { return; }
        }
        if (end($path) === 'cells' && $recursive && $node->kind === SourceNode::ARRAY && $node->entries !== [] && ! $node->isList) {
            throw new MapSourceRefusal('Tile-art cells require implicit list entries so insertion and removal preserve source ownership.');
        }
        $this->assertTileArtSourceNode($node, $path, $recursive);
    }

    private function assertTileArtSourceNode(SourceNode $node, array $path, bool $recursive = false): void
    {
        if (in_array($node->kind, [SourceNode::VARIABLE, SourceNode::EXPRESSION], true) || $node->hasOpaqueKey) {
            throw new MapSourceRefusal(sprintf('%s: tile art at %s requires literal data; opaque expressions, variables and keys are not rewritten.',
                $this->mapId, PhpArraySourceDocument::describePath($path)));
        }
        if ($recursive) {
            foreach ($node->entries as $index => $entry) {
                $this->assertTileArtSourceNode($entry->value, [...$path, $entry->key ?? $index], true);
            }
        }
    }
}
