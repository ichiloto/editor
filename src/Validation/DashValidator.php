<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Field\DashSettings;
use InvalidArgumentException;

/**
 * Checks the dash a project keeps in system data, and each map's Disable
 * Dashing, by the Engine's own reading (its DashSettings): a section or value
 * the Engine would not read stops the game from loading, or the map from
 * being entered, so it is an error.
 */
final class DashValidator
{
    private const string WHERE = 'assets/Data/system.php: dash';

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $issues = [];
        $system = $workspace->getRecordDatabase('system')?->getRecordByIndex(0)?->toArray();
        if (is_array($system) && array_key_exists(DashSettings::KEY, $system)) {
            try {
                DashSettings::fromArray($system[DashSettings::KEY]);
            } catch (InvalidArgumentException $invalid) {
                $issues[] = Issue::error(self::WHERE, 'The game cannot read its dash: ' . $invalid->getMessage(),
                    sprintf('Set Dash and Dash Speed Multiplier (greater than 1, at most %g) in Database > System, and remove any other dash key.',
                        DashSettings::MAX_SPEED_MULTIPLIER));
            }
        }
        foreach ($workspace->maps as $map) {
            try {
                DashSettings::readMapDisablesDashing($map->getEditableData());
            } catch (InvalidArgumentException $invalid) {
                $issues = [...$issues, ...Issue::attributeToMap([Issue::error(sprintf('%s: %s', $map->mapId, DashSettings::MAP_DISABLE_DASHING),
                    'The map cannot be entered: ' . $invalid->getMessage(), 'Set Disable Dashing in the map\'s Inspector.')], $map->mapId)];
            }
        }

        return $issues;
    }
}
