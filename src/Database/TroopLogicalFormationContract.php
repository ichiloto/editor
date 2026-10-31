<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Battle\Formation\BattlePosition;
use InvalidArgumentException;

/** Source/save boundary for a troop's renderer-independent combat positions. */
final class TroopLogicalFormationContract
{
    public static function findProblem(array $troop): ?string
    {
        try {
            $positions = [];
            foreach ((array) ($troop['enemies'] ?? []) as $index => $member) {
                $positions[] = is_array($member) && array_key_exists('battlePosition', $member)
                    ? BattlePosition::fromArray($member['battlePosition'], "Troop member {$index} battlePosition") : null;
            }
            BattlePosition::validateDistinct($positions);
        } catch (InvalidArgumentException $error) { return $error->getMessage(); }
        return null;
    }

    public static function checkTroops(array $troops, string $projectRoot = ''): ?string
    {
        foreach ($troops as $troop) {
            if (is_array($troop) && ($problem = self::findProblem($troop)) !== null) { return $problem; }
        }
        return null;
    }
}
