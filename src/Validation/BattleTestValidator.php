<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\EngineDataBootstrap;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Scenes\Arena\ProjectBattleTest;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use InvalidArgumentException;
use Throwable;

/**
 * Checks the battle test a project keeps in system data (the Engine's
 * ProjectBattleTest), by the Engine's own reading and problems: an entry
 * it would not read, a troop the project does not have, an arena the battle
 * presentation does not declare, and a party it could not build. Test
 * settings only: normal play never reads them, so these are warnings; a
 * battle test with such a problem refuses to start until it is fixed.
 */
final class BattleTestValidator
{
    private const string WHERE = 'assets/Data/system.php: Battle Test';

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $system = $workspace->getRecordDatabase('system')?->getRecordByIndex(0);
        $entry = $system?->get(ProjectBattleTest::SYSTEM_KEY);
        if ($entry === null) {
            return [];
        }
        try {
            $test = ProjectBattleTest::fromArray(is_array($entry) ? $entry : throw new InvalidArgumentException('it is not an entry.'));
        } catch (InvalidArgumentException $invalid) {
            return [Issue::warning(self::WHERE, 'The battle test cannot be read: ' . $invalid->getMessage(),
                'Set it again from the Battle Test dialog, or remove it to use the starting party.')];
        }

        $issues = [];
        $troops = array_map(static fn($record): string => trim(strval($record->get('name'))),
            $workspace->getRecordDatabase('troops')?->getRecords() ?? []);
        if ($test->troop !== null && !in_array(strtolower($test->troop), array_map(strtolower(...), $troops), true)) {
            $issues[] = Issue::warning(self::WHERE, sprintf('The battle test troop %s is not among the troops.', $test->troop),
                'Choose one of the project\'s troops.');
        }
        $arena = $test->setup?->arena ?? $test->arena;
        if ($arena !== null) {
            try {
                $arenas = BattlePresentationCatalog::loadCode($workspace->projectRoot . '/assets')?->getArenaChoices() ?? [];
            } catch (Throwable) {
                $arenas = null;
            }
            if ($arenas !== null && !array_key_exists($arena, $arenas)) {
                $issues[] = Issue::warning(self::WHERE, sprintf('The battle test arena %s is not one the battle presentation declares.', $arena),
                    'Choose one of its arenas, or none for its default.');
            }
        }
        if ($test->setup === null) {
            return $issues;
        }

        return [...$issues, ...ProjectDirectoryContext::run($workspace->projectRoot, static function () use ($workspace, $test): array {
            EngineDataBootstrap::ensure($workspace->projectRoot);
            $items = ConfigStore::has(ItemStore::class) ? ConfigStore::get(ItemStore::class) : null;
            if (!$items instanceof ItemStore) {
                return [];
            }
            $problems = $test->setup->getProblems($workspace->actorDatabase->createActorStore(), $items,
                $workspace->loadSkillCatalog(), new SummonCutsceneLibrary($workspace->projectRoot . '/assets/Cutscenes/Summons'));

            return array_map(static fn(string $problem): Issue => Issue::warning(self::WHERE, $problem,
                'Fix the member in the Battle Test dialog; the battle test will not start until it can be set up.'), $problems);
        })];
    }
}
