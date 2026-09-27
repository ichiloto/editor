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
 * The TUI never paints or displays tiles; painting belongs to the GUI
 * editor. It carries these files with their map and changes one only when
 * the map's dimensions change, reading it exactly as the Engine does.
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
     * are cropped and new cells are empty (`0`); kept cells keep their entry,
     * including a named tile half (`42L`). A layer that reads back as
     * its baseline keeps the baseline's bytes; otherwise it is rewritten as a
     * canonical literal nowdoc, keeping its leading comment and marker.
     *
     * @param MapLayerSet $layers The map's terminal layers before the resize.
     * @throws MapSourceRefusal When the layer cannot be read or does not match the map; nothing is changed.
     */
    public static function resize(string $source, string $displayPath, MapLayerSet $layers, int $width, int $height, ?string $baseline = null): string
    {
        try {
            $layer = self::readLayer($source, $displayPath);
            $layer->assertMatches($layers);
        } catch (InvalidArgumentException $error) {
            throw new MapSourceRefusal(sprintf(
                '%s Repair it before resizing the map; nothing was changed.',
                rtrim($error->getMessage(), '.') . '.',
            ), previous: $error);
        }

        $empty = (string)TileId::EMPTY;
        $entries = $layer->getEntries();
        $rows = array_slice($entries, 0, $height);
        foreach ($rows as &$row) {
            $row = array_pad(array_slice($row, 0, $width), $width, $empty);
        }
        unset($row);
        $rows = array_pad($rows, $height, array_fill(0, $width, $empty));

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
