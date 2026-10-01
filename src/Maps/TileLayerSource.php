<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use InvalidArgumentException;

/**
 * A map's graphical tile layer files in `graphics/`, as the TUI keeps them.
 *
 * The TUI never displays tiles or paints single tiles; painting belongs to
 * the GUI editor. It carries these files with their map and changes one only
 * when the map's dimensions change or a tileset piece is stamped or drawn,
 * reading it exactly as the Engine does.
 */
final class TileLayerSource
{
    private const string PREFERRED_MARKER = 'TILES';

    private function __construct()
    {
    }

    /**
     * Every tile layer file the Engine would read for a map, in name order.
     *
     * @return list<string>
     */
    public static function findPaths(string $mapDirectory): array
    {
        $directory = $mapDirectory . '/' . MapGraphics::DIRECTORY;
        $paths = is_dir($directory) ? (glob($directory . '/*.tiles.php') ?: []) : [];
        sort($paths);

        return $paths;
    }

    /**
     * Reads a tile layer as the Engine does.
     *
     * @throws InvalidArgumentException When the source is not a literal nowdoc of tile identities.
     */
    public static function readLayer(string $source, string $displayPath): MapTileLayer
    {
        $name = preg_match(MapGraphics::FILENAME_PATTERN, basename($displayPath), $matches) === 1 ? $matches['name'] : basename($displayPath);
        $order = (int) ($matches['order'] ?? 0);

        return new MapTileLayer($name, $order, $displayPath, MapGridSource::parseSource($source, $displayPath));
    }

    /**
     * Resizes a tile layer with its map: cells and rows beyond the new size
     * are cropped and new cells are empty (`0`); kept cells keep their entry.
     * A layer that reads back as its baseline keeps the baseline's bytes;
     * otherwise it is rewritten as a canonical literal nowdoc, keeping its
     * leading comment and marker.
     *
     * @param MapLayerSet $layers The map's terminal layers before the resize.
     * @throws MapSourceRefusal When the layer cannot be read or does not match the map; nothing is changed.
     */
    public static function resize(string $source, string $displayPath, MapLayerSet $layers, int $width, int $height, ?string $baseline = null): string
    {
        $layer = self::readMatchingLayer($source, $displayPath, $layers, 'resizing the map');

        $empty = (string)TileId::EMPTY;
        $entries = $layer->getEntries();
        $rows = array_slice($entries, 0, $height);
        foreach ($rows as &$row) {
            $row = array_pad(array_slice($row, 0, $width), $width, $empty);
        }
        unset($row);
        $rows = array_pad($rows, $height, array_fill(0, $width, $empty));

        return self::rewrite($rows, $entries, $source, $displayPath, $baseline);
    }

    /**
     * Inserts empty (`0`) rows before row `$at` (axis `y`) or empty cells
     * before column `$at` of every row that reaches it (axis `x`), as
     * {@see EditableGrid::insertLines()} inserts them into the terminal
     * layers. The result is rewritten as {@see resize()} rewrites a layer.
     *
     * @param MapLayerSet $layers The map's terminal layers before the insertion.
     * @throws MapSourceRefusal When the layer cannot be read or does not match the map; nothing is changed.
     */
    public static function insertLines(string $source, string $displayPath, MapLayerSet $layers, string $axis, int $at, int $count, ?string $baseline = null): string
    {
        $layer = self::readMatchingLayer($source, $displayPath, $layers, 'inserting rows or columns');

        $empty = (string)TileId::EMPTY;
        $entries = $layer->getEntries();
        $rows = $entries;
        if ($axis === 'y') {
            $beside = $rows[$at] ?? $rows[$at - 1] ?? [];
            array_splice($rows, $at, 0, array_fill(0, $count, array_fill(0, count($beside), $empty)));
        } else {
            foreach ($rows as &$row) {
                if (count($row) >= $at) {
                    array_splice($row, $at, 0, array_fill(0, $count, $empty));
                }
            }
            unset($row);
        }

        return self::rewrite($rows, $entries, $source, $displayPath, $baseline);
    }

    /**
     * Writes a piece's tile entries into a tile layer with its top-left cell
     * at (x, y). A `0` entry leaves its cell as it was; every other entry
     * replaces the cell's entry with that whole tile. The result is
     * rewritten as {@see resize()} rewrites a layer.
     *
     * @param list<list<string>> $rows Tile entries by row, as a piece holds them.
     * @param MapLayerSet $layers The map's terminal layers.
     * @throws MapSourceRefusal When the layer cannot be read, does not match the map, or the entries fall outside it; nothing is changed.
     */
    public static function writeEntries(string $source, string $displayPath, MapLayerSet $layers, int $x, int $y, array $rows, ?string $baseline = null): string
    {
        $cells = [];
        foreach ($rows as $row => $entries) {
            foreach ($entries as $column => $entry) {
                $cells[] = ['x' => $x + $column, 'y' => $y + $row, 'entry' => $entry];
            }
        }

        return self::writeCellEntries($source, $displayPath, $layers, $cells, 'stamping a piece', $baseline, keepsEmptyCells: true);
    }

    /**
     * Sets each listed cell's entry, `0` included, so a connected piece can
     * give every cell it draws or reshapes its shape's tile and clear the
     * cells it erases. The result is rewritten as {@see resize()} rewrites a
     * layer.
     *
     * @param list<array{x: int, y: int, entry: string}> $cells The entry for each cell.
     * @param MapLayerSet $layers The map's terminal layers.
     * @throws MapSourceRefusal When the layer cannot be read, does not match the map, or a cell falls outside it; nothing is changed.
     */
    public static function setCellEntries(string $source, string $displayPath, MapLayerSet $layers, array $cells, ?string $baseline = null): string
    {
        return self::writeCellEntries($source, $displayPath, $layers, $cells, 'drawing a piece', $baseline, keepsEmptyCells: false);
    }

    /**
     * Writes entries into a layer's cells, refusing them all when one cell
     * is outside the layer.
     *
     * @param list<array{x: int, y: int, entry: string}> $cells The entry for each cell.
     * @param bool $keepsEmptyCells Whether a `0` entry leaves its cell as it was instead of emptying it.
     */
    private static function writeCellEntries(string $source, string $displayPath, MapLayerSet $layers, array $cells, string $action, ?string $baseline, bool $keepsEmptyCells): string
    {
        $layer = self::readMatchingLayer($source, $displayPath, $layers, $action);

        $entries = $layer->getEntries();
        $written = $entries;
        foreach ($cells as $cell) {
            if (! isset($entries[$cell['y']][$cell['x']])) {
                throw new MapSourceRefusal(sprintf('%s has no cell at (%d, %d); nothing was changed.', $displayPath, $cell['x'], $cell['y']));
            }
            if (! $keepsEmptyCells || $cell['entry'] !== (string)TileId::EMPTY) {
                $written[$cell['y']][$cell['x']] = $cell['entry'];
            }
        }

        return self::rewrite($written, $entries, $source, $displayPath, $baseline);
    }

    /**
     * Reads a tile layer's entries by row, as a layer that must match the map.
     *
     * @param MapLayerSet $layers The map's terminal layers.
     * @return list<list<string>>
     * @throws MapSourceRefusal When it cannot be read or does not match the map.
     */
    public static function readEntries(string $source, string $displayPath, MapLayerSet $layers, string $action): array
    {
        return self::readMatchingLayer($source, $displayPath, $layers, $action)->getEntries();
    }

    /**
     * Reads a tile layer that must match the map before it changes.
     *
     * @throws MapSourceRefusal When it cannot be read or does not match the map.
     */
    private static function readMatchingLayer(string $source, string $displayPath, MapLayerSet $layers, string $action): MapTileLayer
    {
        try {
            $layer = self::readLayer($source, $displayPath);
            $layer->assertMatches($layers);
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal(sprintf(
                '%s Repair it before %s; nothing was changed.',
                rtrim($error->getMessage(), '.') . '.',
                $action,
            ), previous: $error);
        }

        return $layer;
    }

    /**
     * A new tile layer for the map: every cell empty (`0`), each row as wide
     * as the map's row.
     *
     * @param MapLayerSet $layers The map's terminal layers.
     */
    public static function createEmpty(MapLayerSet $layers): string
    {
        $body = implode("\n", array_map(
            static fn(array $row): string => implode(' ', array_fill(0, count($row), (string)TileId::EMPTY)),
            $layers->getComposedGrid(),
        ));

        return MapGridSource::buildSource($body, self::PREFERRED_MARKER);
    }

    /**
     * The source for a layer's new entries. Unchanged entries keep the
     * source's bytes and entries that read back as the baseline keep the
     * baseline's; otherwise the layer is rewritten as a canonical literal
     * nowdoc, keeping its leading comment and marker.
     *
     * @param list<list<string>> $rows The entries the layer should hold.
     * @param list<list<string>> $entries The entries the source holds.
     */
    private static function rewrite(array $rows, array $entries, string $source, string $displayPath, ?string $baseline): string
    {
        if ($rows === $entries) {
            return $source;
        }

        if ($baseline !== null) {
            try {
                if (self::readLayer($baseline, $displayPath)->getEntries() === $rows) {
                    return $baseline;
                }
            } catch (InvalidArgumentException) {
                // A baseline the Engine cannot read is never restored by a resize.
            }
        }

        $body = implode("\n", array_map(static fn(array $row): string => implode(' ', $row), $rows));

        return MapGridSource::buildSource($body, self::getMarker($source), self::getLeadingComment($source));
    }

    /** The nowdoc marker the author chose, or the preferred one. */
    private static function getMarker(string $source): string
    {
        return preg_match("/<<<[ \\t]*'([A-Za-z_][A-Za-z0-9_]*)'/", $source, $matches) === 1
            ? $matches[1]
            : self::PREFERRED_MARKER;
    }

    /** Comments between the open tag and `return`, so a rewrite keeps them. */
    private static function getLeadingComment(string $source): string
    {
        $comment = '';
        foreach (token_get_all($source) as $index => $token) {
            if ($index === 0) {
                continue;
            }
            if (! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                break;
            }
            $comment .= $token[1];
        }
        $comment = trim($comment);

        return $comment === '' ? '' : $comment . "\n";
    }
}
