<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Battle\CounterAttackRule;
use Ichiloto\Engine\Battle\BattleReactions;
use Ichiloto\Engine\Entities\Effects\SkillEffects\PrepareCounterSkillEffect;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use InvalidArgumentException;

/**
 * Checks every counter attack the project grants, by the Engine's own
 * CounterAttackRule: its shape, and that the skill it names is one a
 * counter may respond with. Actors, enemies, states and non-magic skills
 * may grant one; omitted is no counter. A grant the Engine would refuse
 * stops the battle that reaches it, so it is an error here first.
 */
final class CounterAttackValidator
{
    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $catalog = $workspace->loadSkillCatalog();
        $issues = [];

        foreach ($workspace->actorDatabase->getActors() as $actor) {
            array_push($issues, ...$this->check($actor->getData()['counterAttack'] ?? null, $catalog,
                sprintf('assets/Data/Actors: %s', $actor->getName())));
        }
        foreach (['enemies' => 'assets/Data/Enemies', 'states' => 'assets/Data/states.php', 'skills' => 'assets/Data/Skills'] as $category => $where) {
            foreach ($workspace->getRecordDatabase($category)?->getRecords() ?? [] as $record) {
                array_push($issues, ...$this->check($record->get('counterAttack'), $catalog,
                    sprintf('%s: %s', $where, strval($record->get('name')))));
            }
        }

        $states = [];
        foreach ($workspace->getRecordDatabase('states')?->getRecords() ?? [] as $record) {
            $states[(string) $record->get('id')] = (array) $record->toArray();
        }
        $reactions = new BattleReactions($catalog);
        foreach ($catalog->getSkills() as $skill) {
            foreach ($skill->effects as $effect) {
                if (!$effect instanceof PrepareCounterSkillEffect) { continue; }
                try {
                    $state = $states[$effect->stateId] ?? throw new InvalidArgumentException(
                        'Unknown prepared counter stance: ' . $effect->stateId);
                    $reactions->validatePreparation(new CounterAttackRule($effect->skill), State::fromArray($state));
                } catch (InvalidArgumentException $refused) {
                    $issues[] = Issue::error('assets/Data/Skills: ' . $skill->name, $refused->getMessage(),
                        'Select an available Response Skill and a battle-only Stance State.');
                }
            }
        }
        return $issues;
    }

    /** @return Issue[] */
    private function check(mixed $grant, SkillCatalog $catalog, string $where): array
    {
        try {
            CounterAttackRule::fromArray($grant)?->resolveSkill($catalog);
        } catch (InvalidArgumentException $refused) {
            return [Issue::error($where, $refused->getMessage(), 'Pick its Counter Attack from the list, which offers only skills a counter may use, or choose no counter.')];
        }

        return [];
    }
}
