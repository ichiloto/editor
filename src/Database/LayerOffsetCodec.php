<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Rendering\Tilesets\TileLayerOffset;
use InvalidArgumentException;

/**
 * Edits a tile layer's drawing offset ({@see TileLayerOffset}) on one line:
 * `x, y` across and down in field cells. Each number is a decimal or a
 * fraction `a/b`, so a third of a row is typed exactly. The Engine reads the
 * result, so a value it would refuse is refused before anything changes.
 */
final class LayerOffsetCodec
{
    private function __construct()
    {
    }

    /**
     * Reads an offset typed as `x, y`.
     *
     * @return array{int|float, int|float} Whole numbers as integers.
     * @throws InvalidArgumentException When it is not two numbers the Engine accepts.
     */
    public static function decode(string $text, string $label): array
    {
        $parts = array_map(trim(...), explode(',', $text));
        if (count($parts) !== 2) {
            throw new InvalidArgumentException(sprintf('%s must be two numbers, across and down, such as 0, -1/3.', $label));
        }
        $values = array_map(static fn(string $part): int|float => self::readNumber($part, $label), $parts);
        $offset = TileLayerOffset::fromArray($values, $label);

        return [self::keepWhole($offset->x), self::keepWhole($offset->y)];
    }

    /** Shows an offset as `x, y`, or the value as it is when it is not one. */
    public static function encode(mixed $value): string
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return ProjectRecord::stringify($value);
        }

        return implode(', ', array_map(static fn(mixed $number): string => ProjectRecord::stringify($number), $value));
    }

    private static function readNumber(string $part, string $label): int|float
    {
        if (preg_match('/\A([+-]?\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)\z/', $part, $match) === 1 && (float) $match[2] !== 0.0) {
            return (float) $match[1] / (float) $match[2];
        }
        if (is_numeric($part)) {
            return $part + 0;
        }
        throw new InvalidArgumentException(sprintf('%s must be two numbers, across and down, such as 0, -1/3; "%s" is not a number.', $label, $part));
    }

    private static function keepWhole(float $value): int|float
    {
        return floor($value) === $value ? (int) $value : $value;
    }
}
