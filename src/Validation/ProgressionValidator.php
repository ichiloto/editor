<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Progression\ProgressionSettings;
use InvalidArgumentException;

/**
 * Checks the progression rules a project keeps in system data by the
 * Engine's own reading (its ProgressionSettings): a section or value the
 * Engine would not read stops the game from loading, so it is an error.
 */
final class ProgressionValidator
{
    private const string WHERE = 'assets/Data/system.php: progression';

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $system = $workspace->getRecordDatabase('system')?->getRecordByIndex(0)?->toArray();
        if (! is_array($system) || ! array_key_exists('progression', $system)) {
            return [];
        }
        try {
            ProgressionSettings::fromArray($system['progression']);
        } catch (InvalidArgumentException $invalid) {
            return [Issue::error(self::WHERE, 'The game cannot read its progression rules: ' . $invalid->getMessage(),
                'Choose Level Up HP/MP in Database > System (full, increase or none), and remove any other progression key.')];
        }

        return [];
    }
}
