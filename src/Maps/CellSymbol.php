<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Engine\Field\MapCell;
use InvalidArgumentException;

/**
 * Whole-cell text from what an author enters. A map cell is two terminal
 * columns: one two-column glyph fills it as it is, a pair of one-column
 * characters fills it side by side, and a lone one-column character is
 * repeated to fill it, so `#` paints `##` and a space paints a blank cell.
 * The Engine's MapCell decides what makes one cell.
 */
final class CellSymbol
{
    private function __construct()
    {
    }

    /** The whole cell an entry paints; anything past the first cell is ignored. */
    public static function normalize(string $input): string
    {
        $symbols = self::getSymbols($input);
        if ($symbols === []) {
            return MapCell::BLANK;
        }
        $first = $symbols[0];
        foreach ([$first, $first . ($symbols[1] ?? ''), $first . $first] as $candidate) {
            if (self::isCell($candidate)) {
                return $candidate;
            }
        }
        return MapCell::BLANK;
    }

    /** Whether text is exactly one whole map cell. */
    public static function isCell(string $text): bool
    {
        try {
            return count(MapCell::parseRow($text)) === 1;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** Whether one entered character fills half a cell rather than all of it. */
    public static function isHalfCell(string $character): bool
    {
        return ! self::isCell($character) && self::isCell($character . $character);
    }

    /** How a status line names a cell: a blank cell is a space. */
    public static function describe(string $cell): string
    {
        return MapCell::isBlank($cell) ? 'space' : $cell;
    }

    /** @return list<string> Entered characters, without control characters. */
    private static function getSymbols(string $input): array
    {
        if ($input === '' || preg_match_all('/\X/u', $input, $matches) === false) {
            return [];
        }
        return array_values(array_filter($matches[0], static fn(string $symbol): bool => preg_match('/\A[\x00-\x1F\x7F]+\z/', $symbol) !== 1));
    }
}
