<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Database\PhpValueExporter;
use Ichiloto\Engine\Field\Doorway;
use Ichiloto\Engine\Field\Enumerations\DoorwayEntrance;
use InvalidArgumentException;

/**
 * A piece's painted doors ({@see \Ichiloto\Engine\Rendering\Tilesets\TilesetPiece::$doorway}) as one line an
 * author reads and types: each door's cell from the piece's top-left cell, then `front` for a door entered from
 * the cell before it, separated by semicolons: `2,4; 4,4 front`. Stored as the Engine reads it, with an entrance
 * on the opening left out.
 */
final class PieceDoorwayCodec
{
    private function __construct()
    {
    }

    /**
     * @return list<array{x: int, y: int, entrance?: string}> Empty for an empty line.
     * @throws InvalidArgumentException When the line does not name distinct door cells.
     */
    public static function decode(string $encoded): array
    {
        $cells = [];
        foreach (explode(';', $encoded) as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            if (preg_match('/\A(-?\d+)\s*,\s*(-?\d+)(?:\s+([a-z]+))?\z/', $part, $match) !== 1) {
                throw new InvalidArgumentException("Painted doors are cells such as `2,4` or `2,4 front`, separated by semicolons; `{$part}` is not one.");
            }
            $cells[] = ['x' => (int) $match[1], 'y' => (int) $match[2]] + (isset($match[3]) ? ['entrance' => $match[3]] : []);
        }

        return $cells === [] ? [] : Doorway::exportCells(Doorway::readCells($cells, 'Painted doors'));
    }

    /** Displays an authored doorway as typed, or as authored when it cannot be read, so its row can repair it. */
    public static function encode(mixed $cells): string
    {
        if ($cells === null || $cells === []) { return ''; }
        try {
            return implode('; ', array_map(static fn(array $cell): string => "{$cell['x']},{$cell['y']}"
                . ($cell['entrance'] === DoorwayEntrance::ON ? '' : ' ' . $cell['entrance']->value), Doorway::readCells($cells, 'Painted doors')));
        } catch (InvalidArgumentException) {
            return PhpValueExporter::export($cells);
        }
    }
}
