<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Scenes\Arena\BattleTestTroopKeeper;
use RuntimeException;
use Throwable;

/**
 * Keeps a troop composed in a battle test run outside the editor (the
 * Console's `ichiloto battle`): reads the project's troops afresh, adds the
 * troop after the last one, named after its members, through the troops
 * category's own rules ({@see TroopAuthoring::addTroop()}), and saves the
 * category, which keeps the rest of Data/troops.php as it was written.
 */
final readonly class ProjectTroopKeeper implements BattleTestTroopKeeper
{
    public function __construct(private string $projectRoot)
    {
    }

    public function keepTroop(array $members): string
    {
        try {
            $workspace = ProjectWorkspace::fromProject($this->projectRoot);
            $troops = $workspace->getRecordDatabase('troops') ?? throw new RuntimeException('the project has no troops to add to');
            $enemies = array_values(array_map(strval(...), new ReferenceCatalog($workspace)->valuesFor('enemies')));
            $change = new TroopAuthoring()->addTroop($troops, $members, $enemies);
            $troops->save();
            $record = $troops->getRecordByIndex((int) $change->index);
        } catch (RecordRefusal|RuntimeException $refused) {
            throw new RuntimeException($refused->getMessage(), previous: $refused);
        } catch (Throwable $failure) {
            throw new RuntimeException('Data/troops.php could not be written: ' . $failure->getMessage(), previous: $failure);
        }

        return sprintf('Kept as %s in Troops.', $record === null ? 'a new troop' : $troops->getEntryLabel($record));
    }
}
