<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Field\Reachability\MapReachabilityReport;
use Ichiloto\Engine\Field\Reachability\ReachabilityProblem;
use Ichiloto\Engine\Field\Reachability\ReachabilityProblemKind;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Throwable;

/**
 * Reports layouts that leave the player stuck or content out of reach, as the
 * Engine's reachability analysis finds them from every way onto each map.
 *
 * Where content stands is the author's; only what a player would meet is
 * reported. Unknown destinations and unreadable maps are left to the checks
 * that own them (doors, references, map reading), so nothing is said twice.
 *
 * Maps with unsaved changes are given to the analysis as they stand in the
 * workspace, so it judges what saving would leave, as every other check
 * reads the workspace.
 */
final class ReachabilityValidator
{
    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $project = $workspace->analyzeReachability();
        $maps = array_column(array_map(static fn(ProjectMap $map): array => ['id' => $map->mapId, 'map' => $map], $workspace->maps), 'map', 'id');
        $untiled = [];
        foreach ($project->reports as $mapId => $report) {
            $map = $maps[(string) $mapId] ?? null;
            $untiled[] = $map === null ? null : self::checkUntiledFloor($map, $report);
        }

        return array_values(array_filter([...array_map(
            static fn(ReachabilityProblem $problem): ?Issue => self::toIssue($problem),
            $project->getAllProblems(),
        ), ...$untiled]));
    }

    /** How many reachable cells a warning names before summing up the rest. */
    private const int CELLS_NAMED = 3;

    /**
     * Reports cells the player can reach that no tile covers in a map with graphics: the field draws nothing
     * there, so the player walks on the dark background, as on a map whose floor was never painted. A map without
     * a kind, or whose graphics cannot be read, is left to the checks that own it.
     */
    private static function checkUntiledFloor(ProjectMap $map, MapReachabilityReport $report): ?Issue
    {
        $mapId = $map->mapId;
        try {
            $layers = $map->getLayerSet();
            $graphics = MapGraphicsValidator::readGraphics($map);
            $usable = $graphics?->tileset->getUsableSheets($map->getAssetRoot())['sheets'] ?? [];
        } catch (Throwable) {
            return null;
        }
        if ($graphics === null || $usable === []) {
            return null;
        }
        $cells = [];
        foreach ($layers->layers[0]->glyphs ?? [] as $y => $row) {
            foreach ($row as $x => $_) {
                if (! $report->isReachable($x, $y)) {
                    continue;
                }
                foreach ($graphics->layers as $layer) {
                    $tile = (int) ($layer->tiles[$y][$x] ?? TileId::EMPTY);
                    if ($tile !== TileId::EMPTY && isset($usable[TileId::getSheet($tile)?->value ?? ''])) {
                        continue 2;
                    }
                }
                $cells[] = "({$x}, {$y})";
            }
        }
        if ($cells === []) {
            return null;
        }
        $named = array_slice($cells, 0, self::CELLS_NAMED);
        $rest = count($cells) - count($named);

        // The player walks on the dark background there: a defect they see, so an error.
        return new Issue(Severity::ERROR, $mapId, sprintf('%d %s the player can reach %s no tile, so the field shows the background there: %s%s.',
            count($cells), count($cells) === 1 ? 'cell' : 'cells', count($cells) === 1 ? 'has' : 'have',
            implode(', ', $named), $rest > 0 ? " and {$rest} more" : ''),
            'Paint floor under them on a tile layer, or close them off if the player should not walk there.', map: $mapId);
    }

    private static function toIssue(ReachabilityProblem $problem): ?Issue
    {
        [$severity, $hint] = match ($problem->kind) {
            ReachabilityProblemKind::UNREADABLE_MAP, ReachabilityProblemKind::UNKNOWN_DESTINATION => [null, ''],
            ReachabilityProblemKind::UNREACHABLE_EVENT => [Severity::ERROR, 'Open a way to it, or move the event where the player can stand.'],
            ReachabilityProblemKind::BLOCKED_ENTRANCE => [Severity::ERROR, 'Point the arrival at open floor the player can stand on.'],
            ReachabilityProblemKind::UNREACHABLE_NPC => [Severity::WARNING, 'Leave a free cell beside it, or move what stands in the way.'],
            ReachabilityProblemKind::NO_ENTRANCE => [Severity::WARNING, 'Fine for a map kept for content still to come; connect it when it is ready.'],
            ReachabilityProblemKind::DETOURED_APPROACH => [Severity::WARNING,
                'Turn the desk and its speaker toward the way the player comes in, move the event to that side, or let it answer from there.'],
        };

        return $severity === null ? null
            : new Issue($severity, $problem->mapId, $problem->message, $hint, map: $problem->mapId === '' ? null : $problem->mapId);
    }
}
