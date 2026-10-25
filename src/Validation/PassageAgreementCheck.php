<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;
use Ichiloto\Engine\Field\Validation\MapPassageAgreementRule;
use Ichiloto\Engine\Field\Validation\MapValidationIssue;
use Throwable;

/**
 * Checks that what a map shows agrees with what it lets the player do, by
 * the Engine's rule every tool shares ({@see MapPassageAgreementRule}): solid
 * cells that show only walkable ground, walkable cells that show water, a
 * barrier or an object's art, and world objects whose art leaves most of
 * their footprint bare. This adapter supplies the map as it stands in the
 * workspace, unsaved edits included, and words the findings for an author.
 *
 * Every finding is a warning: artists own composition, so validation never
 * fails over one and nothing refuses a save.
 */
final class PassageAgreementCheck
{
    /** How many cells a finding names before summing up the rest. */
    private const int CELLS_NAMED = 3;

    private function __construct()
    {
    }

    /** @return list<Issue> The warnings found, each naming the map and its rule. */
    public static function validate(ProjectMap $map): array
    {
        $issues = [];
        foreach (self::inspect($map) as $issue) {
            $cells = $issue->cells;
            $named = array_map(static fn(array $cell): string => "({$cell[0]}, {$cell[1]})", array_slice($cells, 0, self::CELLS_NAMED));
            $rest = count($cells) - count($named);
            $message = $cells === [] ? $issue->message : sprintf('%s At %s%s%s.', $issue->message,
                count($cells) === 1 ? '' : sprintf('%d cells: ', count($cells)), implode(', ', $named), $rest > 0 ? " and {$rest} more" : '');
            $issues[] = new Issue(Severity::WARNING, $map->mapId, $message,
                $issue->hint . ' The collision view of the GUI editor marks every cell.', $issue->rule, $map->mapId,
                array_map(static fn(array $cell): array => [$cell[0], $cell[1]], $cells));
        }

        return $issues;
    }

    /**
     * Every cell a finding names, with its rule, for a view that marks them.
     * Null when the map cannot be judged: it has no graphics, or its graphics
     * or collision cannot be read, which other checks report.
     *
     * @return list<array{x: int, y: int, rule: string}>|null
     */
    public static function listFlaggedCells(ProjectMap $map): ?array
    {
        if (MapGraphicsValidator::readGraphics($map) === null) {
            return null;
        }
        $cells = [];
        foreach (self::inspect($map) as $issue) {
            foreach ($issue->cells as [$x, $y]) {
                $cells["{$x},{$y},{$issue->rule}"] = ['x' => $x, 'y' => $y, 'rule' => $issue->rule];
            }
        }

        return array_values($cells);
    }

    /** @return list<MapValidationIssue> */
    private static function inspect(ProjectMap $map): array
    {
        try {
            return MapCompletenessValidator::validatePassageAgreement(
                $map->mapId,
                $map->getEditableData(),
                $map->getEventLayerCells(),
                $map->getLayerSet(),
                $map->getPhysicalOccupancy(),
                MapGraphicsValidator::readGraphics($map),
                $map->getAssetRoot(),
                MapCompletenessCheck::readFieldCatalog($map),
            );
        } catch (Throwable) {
            // The map's own reading checks say why its layers, collision or objects cannot be read.
            return [];
        }
    }
}
