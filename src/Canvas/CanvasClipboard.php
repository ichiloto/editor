<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Rendering\Tilesets\TileId;

/**
 * Copies, cuts and pastes a rectangle of a map layer the one way every
 * interface does: the layer's glyphs with their styles, and the tiles that
 * move with that layer, cell for cell ({@see Clipboard}). A cut and a paste
 * are each one undo step, and a pasted glyph that could be several pieces is
 * reported unresolved, as painting does. Like {@see CanvasEditor}, nothing
 * here knows a cursor, a status line or a dialog; refusals are thrown.
 */
final class CanvasClipboard
{
    /**
     * The block of a layer between two corners, with the tiles that move
     * with that layer, stored in the clipboard.
     *
     * @param bool $withTiles Whether the tiles that move with the layer travel too; an NPC layer's do not.
     * @throws MapSourceRefusal When a tile layer that moves with the layer cannot be read.
     */
    public static function copy(ProjectMap $map, string $layerId, int $x, int $y, int $width, int $height, Clipboard $clipboard,
        bool $withTiles = true): void
    {
        $rows = $styles = [];
        for ($row = 0; $row < $height; $row++) {
            for ($column = 0; $column < $width; $column++) {
                $rows[$row][$column] = $map->getLayerSymbol($layerId, $x + $column, $y + $row);
                $styles[$row][$column] = $map->getLayerCellStyle($layerId, $x + $column, $y + $row);
            }
        }
        $tiles = $withTiles ? $map->readTileEntries($map->getTileLayersMovingWith($layerId), $x, $y, $width, $height) : [];
        $clipboard->store($rows, $layerId, $styles, $tiles);
    }

    /**
     * Copies the block into the clipboard, then clears its glyphs and the
     * tiles that went with them, as one undo step.
     *
     * @return array{command: ?\Ichiloto\Editor\History\Command, changed: int}
     * @throws MapSourceRefusal When the map cannot take the change; nothing is written then.
     */
    public static function cut(ProjectMap $map, string $layerId, int $x, int $y, int $width, int $height, Clipboard $clipboard,
        bool $withTiles = true): array
    {
        self::copy($map, $layerId, $x, $y, $width, $height, $clipboard, $withTiles);
        $writes = array_map(static fn(array $cell): array => ['x' => $cell['x'], 'y' => $cell['y'], 'symbol' => ' ', 'color' => null],
            ToolGeometry::rectangleFilled($x, $y, $x + $width - 1, $y + $height - 1));
        $clears = array_map(static fn(array $tiles): array => array_map(
            static fn(array $cell): array => ['entry' => (string) TileId::EMPTY] + $cell, $tiles),
            $clipboard->projectTiles($x, $y, $map->getWidth(), $map->getHeight()));
        $applied = self::applyWithTiles($map, $layerId, $writes, 'Cut selection', $clears);

        return ['command' => $applied['command'], 'changed' => $applied['changed']];
    }

    /**
     * Stamps the clipboard with its top-left cell at a map cell, as one undo
     * step: its glyphs on the layer it came from, and its tiles on the tile
     * layers that move with that layer.
     *
     * @param array<string, ?string> $choices The role key chosen for a glyph that could be several pieces, or null for no tiles.
     * @return array{command: ?\Ichiloto\Editor\History\Command, changed: int, unresolved: array<string, list<PieceRole>>}
     * @throws MapSourceRefusal When the clipboard is empty or from another layer, or the map cannot take the change.
     */
    public static function paste(ProjectMap $map, string $layerId, Clipboard $clipboard, int $x, int $y, array $choices = []): array
    {
        if ($clipboard->isEmpty()) {
            throw new MapSourceRefusal('The clipboard is empty: select part of the map and copy it first.');
        }
        if ($clipboard->layer !== $layerId) {
            $source = array_find($map->getLayers(), static fn(array $layer): bool => $layer['id'] === $clipboard->layer);
            throw new MapSourceRefusal($source === null
                ? 'The clipboard holds a block from another layer; choose that layer before pasting.'
                : sprintf('The clipboard holds a block from the %s layer; choose it before pasting.', MapLayers::formatLabel((string) $source['name'])));
        }
        $tiles = array_intersect_key($clipboard->projectTiles($x, $y, $map->getWidth(), $map->getHeight()),
            array_flip($map->getTileLayersMovingWith($layerId)));

        return self::applyWithTiles($map, $layerId, $clipboard->project($x, $y, $map->getWidth(), $map->getHeight()),
            'Paste selection', $tiles, $choices);
    }

    /**
     * Writes glyphs and the tiles that follow them as one undo step, the
     * edit's own tiles winning on their layers.
     *
     * @param array<int, array{x: int, y: int, symbol: string, color?: string|null, style?: array{prefix: string, suffix: string}}> $writes
     * @param array<string, list<array{x: int, y: int, entry: string}>> $tiles
     * @param array<string, ?string> $choices
     * @return array{command: ?\Ichiloto\Editor\History\Command, changed: int, unresolved: array<string, list<PieceRole>>}
     */
    private static function applyWithTiles(ProjectMap $map, string $layerId, array $writes, string $label, array $tiles,
        array $choices = []): array
    {
        $plan = CanvasEditor::plan($map, $layerId, $writes, $choices, false, array_keys($tiles));
        if ($plan !== null && $plan['unresolved'] !== []) {
            return ['command' => null, 'changed' => 0, 'unresolved' => $plan['unresolved']];
        }
        $applied = CanvasEditor::apply($map, $layerId, $plan['writes'] ?? $writes, $label, [...($plan['tiles'] ?? []), ...$tiles]);

        return ['command' => $applied['command'], 'changed' => $applied['changed'], 'unresolved' => []];
    }
}
