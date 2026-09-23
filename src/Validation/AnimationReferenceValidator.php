<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectWorkspace;
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

            $fallback = $skill->getType() === 'magic'
                && in_array($skill->getEffectType(), [MagicEffectType::RESTORATIVE->value, MagicEffectType::BUFF->value], true)
                    ? 'Healing Aura' : 'Hit Spark';
            if (in_array($skill->getName(), $names, true) || in_array($fallback, $names, true)) {
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
