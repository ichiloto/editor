<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Battle\BattleReactions;
use Ichiloto\Engine\Entities\Effects\SkillEffects\PrepareResponseWindowSkillEffect;
use InvalidArgumentException;

/**
 * Checks every response-window warning the project's skills author against the skills as they stand, by the
 * Engine's own pre-execution check ({@see PrepareResponseWindowSkillEffect::validate()}): the follow-up it names
 * must be a battle-only skill targeting all living opponents that is not itself a warning, so a warning naming
 * itself is refused too. The skill record reader already refuses a warning record of the wrong shape on save; a
 * follow-up that later goes missing or changes is caught here, before a battle refuses the warning.
 */
final class ResponseWindowValidator
{
    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $catalog = $workspace->loadSkillCatalog();
        $reactions = new BattleReactions($catalog);
        $issues = [];
        foreach ($catalog->getSkills() as $skill) {
            foreach ($skill->effects as $effect) {
                if (! $effect instanceof PrepareResponseWindowSkillEffect) { continue; }
                try {
                    $effect->validate($skill, $reactions);
                } catch (InvalidArgumentException $refused) {
                    $issues[] = Issue::error('assets/Data/Skills: ' . $skill->name, $refused->getMessage(),
                        'Pick its Follow-up Skill from the list, which offers only the skills a warning may schedule.');
                }
            }
        }

        return $issues;
    }
}
