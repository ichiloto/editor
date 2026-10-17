<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Battle\CounterAttackRule;
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
