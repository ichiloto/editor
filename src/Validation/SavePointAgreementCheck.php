<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Canvas\SavePointMarks;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/**
 * Warns where a save point's three parts disagree: the terminal mark, the
 * collision that makes the cell save, and the art that shows it. A mark or art
 * over a cell that does not save promises a save the player cannot make; a
 * cell that saves with no mark or art is one they cannot find.
 *
 * Which glyphs mark a save point is the project's collision dictionary's to
 * say, and which art shows one is its tileset's: any piece stamped with such a
 * glyph. A map with no graphics has no art to judge.
 *
 * The map plays, saves and loads the same, so every finding is a warning.
 */
final class SavePointAgreementCheck
{
    public const string INERT = 'map.save-point.inert';
    public const string UNMARKED = 'map.save-point.unmarked';
    public const string NO_ART = 'map.save-point.no-art';
    /** How many cells a warning names before summing up the rest. */
    private const int CELLS_NAMED = 3;

    private function __construct()
    {
    }

    /** @return list<Issue> One warning for each kind of disagreement. */
    public static function validate(ProjectMap $map): array
    {
        try {
            [$inert, $unmarked, $withoutArt] = self::findDisagreements($map);
        } catch (MapSourceRefusal|InvalidArgumentException) {
            // The map's own source problem is reported by the checks that read it.
            return [];
        }

        return [
            ...self::buildIssue($map, self::INERT, $inert, 'A save point shows here, but the cell does not save.',
                'Place the save point piece on the cell, or paint the cell as a Save Point in the collision view.'),
            ...self::buildIssue($map, self::UNMARKED, $unmarked, 'This cell saves, but the Terminal shows no save point.',
                'Place the save point piece on the cell, or paint the cell back to its own collision.'),
            ...self::buildIssue($map, self::NO_ART, $withoutArt, 'This cell saves, but the graphical field shows no save point.',
                'Place the save point piece of the map\'s tileset on the cell, adding one to the tileset if it has none.'),
        ];
    }

    /** @return array{0: list<array{0: int, 1: int}>, 1: list<array{0: int, 1: int}>, 2: list<array{0: int, 1: int}>} */
    private static function findDisagreements(ProjectMap $map): array
    {
        $rows = $map->getResolvedCollisionMap();
        $layers = $map->getLayerSet();
        $grid = $layers->getComposedGrid();
        $graphics = MapGraphicsValidator::readGraphics($map);
        $art = [];
        foreach ($graphics?->tileset->pieces ?? [] as $piece) {
            $cells = SavePointMarks::listPieceCells($map, $piece);
            if ($cells === []) {
                continue;
            }
            foreach ($graphics->findPieceStamps($piece) as [$x, $y]) {
                foreach ($cells as [$column, $row]) {
                    $art[$y + $row][$x + $column] = true;
                }
            }
        }

        [$inert, $unmarked, $withoutArt] = [[], [], []];
        foreach ($rows as $y => $row) {
            foreach ($row as $x => $collision) {
                $saves = $collision === CollisionType::SAVE_POINT->value;
                $glyph = TerminalText::stripAnsi($grid[$y][$x] ?? ' ');
                $marked = trim($glyph) !== '' && $map->lookUpGlyphCollision($layers->getGameplayLayerAt($x, $y)->name, $glyph) === CollisionType::SAVE_POINT;
                if (! $saves && ($marked || isset($art[$y][$x]))) {
                    $inert[] = [$x, $y];
                } elseif ($saves && ! $marked) {
                    $unmarked[] = [$x, $y];
                }
                if ($saves && $graphics !== null && ! isset($art[$y][$x])) {
                    $withoutArt[] = [$x, $y];
                }
            }
        }

        return [$inert, $unmarked, $withoutArt];
    }

    /**
     * @param list<array{0: int, 1: int}> $cells
     * @return list<Issue>
     */
    private static function buildIssue(ProjectMap $map, string $code, array $cells, string $message, string $hint): array
    {
        if ($cells === []) {
            return [];
        }
        $named = array_map(static fn(array $cell): string => "({$cell[0]}, {$cell[1]})", array_slice($cells, 0, self::CELLS_NAMED));
        $rest = count($cells) - count($named);

        return [new Issue(Severity::WARNING, $map->mapId, sprintf('%s At %s%s.', $message, implode(', ', $named), $rest > 0 ? " and {$rest} more" : ''),
            $hint, $code, $map->mapId, $cells)];
    }
}
