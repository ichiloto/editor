<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;

/** Checks optional presentation references without invalidating gameplay. */
final class AnimationReferenceValidator
{
    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $animations = $workspace->animationDatabase->getAnimations();
        $ids = array_map(static fn($animation): int => $animation->id, $animations);
        $names = array_map(static fn($animation): string => $animation->name, $animations);
        $issues = [];

        foreach ($workspace->skillDatabase->getSkills() as $skill) {
            $where = 'assets/Data/skills.php: ' . $skill->getName();
            if ($skill->animationId !== null) {
                array_push($issues, ...$this->checkId($skill->animationId, $ids, $where));
                continue;
            }

            // A basic skill's effect follows the attacker's weapon role, and a
            // summon plays its cutscene; neither is chosen by an animation name.
            if ($skill->getType() === 'basic') {
                continue;
            }

            $magicEffectType = $skill->getType() === 'magic'
                ? MagicEffectType::tryFrom($skill->getEffectType() ?? '') : null;
            $candidates = ActionAnimationResolver::getSkillCandidateNames($skill->getName(), $magicEffectType);
            if (array_intersect($candidates, $names) !== []) {
                $issues[] = Issue::warning(
                    $where,
                    'Animation selection by name is deprecated.',
                    'Choose an Animation in the picker to store its stable id; name fallback remains supported.',
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
        $database = $workspace->animationDatabase;
        $holders = [];
        $issues = [];

        foreach ($database->getAnimations() as $index => $animation) {
            foreach ($database->getRoles($index) as $role) {
                if (! in_array($role, $supported, true)) {
                    $issues[] = Issue::error("{$where}: {$animation->name}", sprintf('Its role "%s" is not one the Engine supports, so the whole animation is skipped.', $role),
                        'Choose its roles in the Roles rows, which offer only supported roles.');
                    continue;
                }
                $holders[$role][] = $animation->name;
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
                    'Switch the role on for the animation that should play.');
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
            'Choose a current Animation in the picker. Gameplay still works; an explicit missing id never falls back by name.',
        )];
    }
}
