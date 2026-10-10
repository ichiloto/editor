<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Field\Reachability\ProjectReachability;
use Ichiloto\Engine\Field\Reachability\ReachabilityProblem;
use Ichiloto\Engine\Field\Reachability\ReachabilityProblemKind;

/**
 * Reports layouts that leave the player stuck or content out of reach, as the
 * Engine's reachability analysis finds them from every way onto each map.
 *
 * Where content stands is the author's; only what a player would meet is
 * reported. Unknown destinations and unreadable maps are left to the checks
 * that own them (doors, references, map reading), so nothing is said twice.
 */
final class ReachabilityValidator
{
    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $project = ProjectReachability::analyze($workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets');

        return array_values(array_filter(array_map(
            static fn(ReachabilityProblem $problem): ?Issue => self::toIssue($problem),
            $project->getAllProblems(),
        )));
    }

    private static function toIssue(ReachabilityProblem $problem): ?Issue
    {
        return match ($problem->kind) {
            ReachabilityProblemKind::UNREADABLE_MAP, ReachabilityProblemKind::UNKNOWN_DESTINATION => null,
            ReachabilityProblemKind::UNREACHABLE_EVENT => Issue::error($problem->mapId, $problem->message,
                'Open a way to it, or move the event where the player can stand.'),
            ReachabilityProblemKind::BLOCKED_ENTRANCE => Issue::error($problem->mapId, $problem->message,
                'Point the arrival at open floor the player can stand on.'),
            ReachabilityProblemKind::UNREACHABLE_NPC => Issue::warning($problem->mapId, $problem->message,
                'Leave a free cell beside it, or move what stands in the way.'),
            ReachabilityProblemKind::NO_ENTRANCE => Issue::warning($problem->mapId, $problem->message,
                'Fine for a map kept for content still to come; connect it when it is ready.'),
        };
    }
}
