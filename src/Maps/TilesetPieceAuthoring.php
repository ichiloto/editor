<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Canvas\CanvasEditor;
use Ichiloto\Engine\Field\Doorway;
use Ichiloto\Engine\Field\Enumerations\DoorwayEntrance;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/**
 * The cell-by-cell edits a graphical editor makes to a stamped piece while it
 * is defined over its art: a new piece from a selection of sheet cells, one
 * cell's terminal glyph, one cell's painted door. Each answers the record
 * values the piece's own rows take (Glyph Rows, Painted Doors), so the edit
 * goes through the same source-preserving record path, validation and undo
 * as typing them would; the cells nobody touched keep their authored source
 * byte for byte.
 */
final class TilesetPieceAuthoring
{
    private function __construct()
    {
    }

    /**
     * A new piece's id from its name: lowercase words joined by hyphens,
     * numbered when the tileset already has it.
     *
     * @param list<string> $taken The tileset's piece ids.
     * @throws InvalidArgumentException When the name has no letters or digits.
     */
    public static function createPieceId(string $name, array $taken): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name))), '-');
        if ($base === '') {
            throw new InvalidArgumentException('Name the piece with at least one letter or digit.');
        }
        $id = $base;
        for ($number = 2; in_array($id, $taken, true); $number++) {
            $id = "{$base}-{$number}";
        }

        return $id;
    }

    /**
     * Blank glyph rows over a footprint: a space in every cell, which leaves
     * the map's cell as it is until the author gives the cell a glyph.
     *
     * @return list<string>
     */
    public static function createBlankRows(int $width, int $height): array
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('A piece covers at least one cell.');
        }

        return array_fill(0, $height, str_repeat(' ', $width));
    }

    /**
     * The piece's glyph rows with one cell's glyph replaced, every other cell
     * keeping its authored source. The colour follows the brush contract
     * ({@see CanvasEditor::resolvePaintStyle()}): null keeps the cell's own
     * style, an empty string paints it plain, any other value colours it. A
     * space clears the cell, so stamping leaves the map's cell as it is.
     *
     * @param list<string> $rows The Glyph Rows as authored.
     * @return list<string>
     * @throws InvalidArgumentException When the cell is outside the rows, the glyph is not one terminal cell or its style cannot hold it.
     */
    public static function rewriteGlyph(array $rows, int $x, int $y, string $symbol, ?string $color): array
    {
        if (! isset($rows[$y])) {
            throw new InvalidArgumentException("The piece has no row {$y}.");
        }
        if ($symbol === '' || TerminalText::symbolCount($symbol) !== 1 || TerminalText::displayWidth($symbol) !== 1
            || preg_match('/\A\X\z/u', $symbol) !== 1) {
            throw new InvalidArgumentException('A cell holds one character that fills one terminal cell.');
        }
        $cells = TerminalText::parseSourceCells($rows[$y]);
        if (! isset($cells[$x])) {
            throw new InvalidArgumentException("Row {$y} of the piece has no cell {$x}.");
        }
        [$prefix, $suffix] = CanvasEditor::resolvePaintStyle($symbol, $color, ['prefix' => $cells[$x]['prefix'], 'suffix' => $cells[$x]['suffix']]);
        $cells[$x] = ['symbol' => $symbol, 'prefix' => $prefix, 'suffix' => $suffix];
        // Every cell of the row goes through the Engine's composition: an
        // ordinary glyph keeps its bytes, and a literal <, > or backslash
        // is bounded so it cannot escape a closing tag or join neighbours
        // into markup, whichever cell changed.
        $rows[$y] = implode('', array_map(
            static fn(array $cell): string => TerminalText::composeSourceCell($cell['symbol'], $cell['prefix'], $cell['suffix']),
            $cells,
        ));

        return $rows;
    }

    /**
     * The piece's painted doors with the door at one cell set, its entrance
     * changed, or with null taken away, in the form its Painted Doors row
     * takes ({@see PieceDoorwayCodec}).
     *
     * @param mixed $doorway The piece's authored doorway, or null when it has none.
     */
    public static function setDoor(mixed $doorway, int $x, int $y, ?DoorwayEntrance $entrance): string
    {
        $cells = $doorway === null || $doorway === [] ? [] : Doorway::exportCells(Doorway::readCells($doorway, 'Painted doors'));
        $cells = array_values(array_filter($cells, static fn(array $cell): bool => $cell['x'] !== $x || $cell['y'] !== $y));
        if ($entrance !== null) {
            $cells[] = ['x' => $x, 'y' => $y] + ($entrance === DoorwayEntrance::ON ? [] : ['entrance' => $entrance->value]);
            usort($cells, static fn(array $a, array $b): int => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);
        }

        return PieceDoorwayCodec::encode($cells === [] ? null : $cells);
    }
}
