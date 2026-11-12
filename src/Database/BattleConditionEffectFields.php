<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\Presentation\BattleConditionEffects;
use InvalidArgumentException;
use Throwable;

/**
 * Shared authoring of the effects a project binds to battle conditions ({@see BattleConditionGroup}). The
 * Engine's {@see BattleConditionEffects} owns the binding shape and the timeline rules; this chooses states,
 * stats and timelines from the project's pickers and checks each choice with those rules before it is written,
 * against the timelines as the editor holds them, unsaved edits included.
 */
final class BattleConditionEffectFields
{
    /**
     * Refuses bindings the Engine's binding boundary refuses. A draft's entries still waiting for a pick are left
     * out of the check; a save checks them all.
     *
     * @throws InvalidArgumentException With the Engine's reason.
     */
    public static function assertShape(BattleConditionGroup $group, mixed $value, string $assetRoot, bool $complete = true): void
    {
        if ($value === null) { return; }
        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf('%s map %s to effect timeline ids.', $group->getConfigPath(),
                $group === BattleConditionGroup::STAT_STAGES ? 'stats and their roles' : 'state ids'));
        }
        $checked = $complete ? $value : self::removeDrafts($group, $value);
        new BattleConditionEffects(new EffectTimelineLibrary($assetRoot), [$group->value => $checked]);
    }

    /**
     * Refuses the bindings an edit adds or changes that a battle could not play: one naming a state the project
     * does not define, or an effect the group's rule refuses for either
     * presentation. Bindings the edit leaves as they were are not checked again, so an unrelated broken binding
     * stays for validation to report rather than blocking other edits.
     *
     * @throws InvalidArgumentException With the Engine's reason.
     */
    public static function assertChangedBindingsValid(BattleConditionGroup $group, mixed $old, mixed $new, ReferenceCatalog $references,
        bool $complete = true): void
    {
        // The Engine's boundary also refuses a stat without stages, wherever it is.
        self::assertShape($group, $new, $references->getAssetRoot(), $complete);
        $states = $group === BattleConditionGroup::STAT_STAGES ? null : $references->valuesFor('states');
        foreach ($group->listBindings($new) as ['subject' => $subject, 'role' => $role, 'effect' => $effect]) {
            if (is_array($old) && array_key_exists($subject, $old) && $group->getBoundEffect($old, $subject, $role) === $effect) { continue; }
            if ($states !== null && $subject !== '' && ! in_array($subject, $states, true)) {
                throw new InvalidArgumentException(sprintf('Unknown state "%s"; choose a project state.', $subject));
            }
            if (is_string($effect) && $effect !== '') {
                $failure = self::findTimelineFailures($group, $effect,
                    static fn(string $id, EffectPresentation $presentation): mixed => $references->loadConditionTimeline($id, $presentation, $group))[0] ?? null;
                if ($failure !== null) { throw new InvalidArgumentException($failure['message'], previous: $failure['error']); }
            }
        }
    }

    /**
     * Why an effect cannot play in a group, for each presentation that refuses it, in words that name the
     * presentation and carry the Engine's own reason.
     *
     * @param \Closure(string, EffectPresentation): mixed $load The group's timeline loader.
     * @return list<array{presentation: EffectPresentation, message: string, error: Throwable}>
     */
    public static function findTimelineFailures(BattleConditionGroup $group, string $effect, \Closure $load): array
    {
        $failures = [];
        foreach (EffectPresentation::cases() as $presentation) {
            try {
                $load($effect, $presentation);
            } catch (Throwable $error) {
                $failures[] = ['presentation' => $presentation, 'error' => $error, 'message' => sprintf('Effect %s cannot play as %s for the %s presentation: %s',
                    $effect, $group->isLooping() ? 'a condition effect' : 'an interception', $presentation->value, $error->getMessage())];
            }
        }

        return $failures;
    }

    /**
     * A draft value without the entries still waiting for a pick: a blank state or stat, a blank effect, or a
     * stat role chosen as none.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function removeDrafts(BattleConditionGroup $group, array $value): array
    {
        $kept = [];
        foreach ($value as $subject => $effect) {
            if ((string) $subject === '') { continue; }
            if ($group !== BattleConditionGroup::STAT_STAGES) {
                if ($effect !== '') { $kept[$subject] = $effect; }
                continue;
            }
            $kept[$subject] = is_array($effect) ? array_filter($effect, static fn(mixed $role): bool => $role !== '' && $role !== null) : $effect;
        }

        return $kept;
    }
}
