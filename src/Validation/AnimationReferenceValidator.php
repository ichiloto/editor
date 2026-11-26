<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\ProjectCatalogs;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use InvalidArgumentException;

/** Checks optional presentation references without invalidating gameplay. */
final class AnimationReferenceValidator
{
    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $animations = $workspace->getRecordDatabase('animations')?->getRecords() ?? [];
        $library = ProjectCatalogs::createAnimationLibrary(array_values(array_map(
            static fn($animation): mixed => $animation->toArray(),
            $animations,
        )));
        $ids = array_map(static fn($animation): int => $animation->id, $library->load());
        $summonActions = array_map(
            static fn(SummonCutsceneDefinition $definition): ?string => $definition->linkedActionId,
            $workspace->catalogs->loadSummons(),
        );
        $issues = [];

        foreach ($workspace->getRecordDatabase('skills')?->getRecords() ?? [] as $record) {
            try {
                $skill = SkillRecord::readSkill((array) $record->toArray());
            } catch (InvalidArgumentException) {
                // A record the game cannot read is the skill catalogue's finding.
                continue;
            }

            $where = sprintf('assets/Data/%s/%s.php: %s', SkillCatalog::DIRECTORY, $record->recordId, $skill->name);
            if ($skill->animationId !== null) {
                array_push($issues, ...$this->checkId($skill->animationId, $ids, $where));
                continue;
            }

            // Basic skills use the attacker's weapon. A linked summon has its own cutscene validation.
            if ($skill instanceof BasicSkill || in_array(trim($skill->name), $summonActions, true)) {
                continue;
            }

            $magicEffectType = $skill instanceof MagicSkill ? $skill->effectType : null;
            [$skillName, $legacyDefault] = ActionAnimationResolver::getSkillCandidateNames($skill->name, $magicEffectType);
            $role = ActionAnimationResolver::getSkillRole($skill);
            $current = $library->findByRole($role);
            $legacy = $library->findByName($skillName);
            if ($legacy === null && $current === null) {
                $legacy = $library->findByName($legacyDefault);
            }

            if ($legacy !== null && $legacy !== $current) {
                $issues[] = Issue::warning(
                    $where,
                    $current === null
                        ? sprintf('The %s role has no playable animation. The legacy match was "%s".', $role, $legacy->name)
                        : sprintf('The %s role selects "%s". The legacy match was "%s".', $role, $current->name, $legacy->name),
                    'If this skill should use that animation, choose it in the Animation picker to store its id. For a shared default, assign the role to the intended animation.',
                );
            }
        }

        foreach ($workspace->getRecordDatabase('items')?->getRecords() ?? [] as $item) {
            $id = $item->get('animationId');
            if ($id !== null) {
                array_push($issues, ...$this->checkId($id, $ids, 'assets/Data/items.php: ' . strval($item->get('name'))));
            }
        }

        return [...$issues, ...$this->checkRoles($workspace)];
    }

    /**
     * Checks animation roles as the runtime reads them: a role it does not
     * support makes it skip the whole animation, a role held twice plays
     * neither, and a role the project's battles reach with no animation
     * bound to it plays no effect.
     *
     * @return Issue[]
     */
    private function checkRoles(ProjectWorkspace $workspace): array
    {
        $where = 'assets/Data/animations.php';
        $supported = ActionAnimationResolver::getSupportedRoles();
        $holders = [];
        $issues = [];

        foreach ($workspace->getRecordDatabase('animations')?->getRecords() ?? [] as $animation) {
            $name = strval($animation->get('name'));
            foreach ((array) ($animation->get('roles') ?? []) as $role) {
                if (! in_array($role, $supported, true)) {
                    $issues[] = Issue::error("{$where}: {$name}", sprintf('Its role "%s" is not one the Engine supports, so the whole animation is skipped.', strval($role)),
                        'Choose its roles in the Roles picker, which offers only supported roles.');
                    continue;
                }
                $holders[$role][] = $name;
            }
        }

        foreach ($holders as $role => $names) {
            if (count($names) > 1) {
                $issues[] = Issue::error($where, sprintf('Role %s is bound to %s, so neither plays for it.', $role, implode(' and ', $names)),
                    'Keep the role on one animation.');
            }
        }

        // Every battle reaches the neutral attack (enemies) and the unarmed one
        // (a party member with no weapon), and each weapon type the project's
        // weapons use.
        $reached = ['attack' => 'enemy and unclassified attacks', 'attack-unarmed' => 'attacks with no weapon equipped'];

        foreach ($workspace->getRecordDatabase('weapons')?->getRecords() ?? [] as $weapon) {
            $type = $weapon->get('equipmentType');
            $type = $type instanceof WeaponType ? $type->value : (is_string($type) ? $type : null);

            if ($type !== null && $type !== '') {
                $reached['attack-' . strtolower($type)] ??= sprintf('attacks with a %s such as %s', strtolower($type), strval($weapon->get('name')));
            }
        }

        foreach ($reached as $role => $reason) {
            if (! isset($holders[$role])) {
                $issues[] = Issue::warning($where, sprintf('No animation holds role %s, so %s play no effect.', $role, $reason),
                    'Pick the role in the Roles of the animation that should play.');
            }
        }

        return $issues;
    }

    /** @param int[] $ids @return Issue[] */
    private function checkId(mixed $id, array $ids, string $where): array
    {
        if (is_int($id) && in_array($id, $ids, true)) {
            return [];
        }

        return [Issue::warning(
            $where,
            'Its animationId does not identify an authored animation.',
            'Choose a current Animation in the picker or clear the reference to use automatic selection.',
        )];
    }
}
