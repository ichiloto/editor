<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\Presentation\BattleConditionEffects;
use InvalidArgumentException;
use Throwable;

/**
 * Shared authoring of the contact effects states play when they intercept an attack: the project's
 * `ui.battle.conditions.interceptions`, an effect timeline id by `State::id`. The Engine's
 * {@see BattleConditionEffects} owns the binding shape and the timeline rule; this chooses states and
 * timelines from the project's pickers and checks each choice with that rule before it is written.
 */
final class BattleInterceptionFields
{
    /** The settings-field id prefix of a binding's rows. */
    public const string PREFIX = 'interception';

    /** The bindings as one keyed list: each entry chooses a state and the effect it plays. */
    public static function getBindingList(string $key): RecordSubList
    {
        return new RecordSubList(
            key: $key, prefix: self::PREFIX, singular: 'interception effect',
            fields: [RecordField::reference('state', 'State', 'states'), RecordField::reference('effect', 'Effect', 'effects')],
            blank: ['state' => '', 'effect' => ''], heading: 'Interception Effects', removeWhenEmpty: true,
            keyField: 'state', valueField: 'effect',
        );
    }

    /**
     * Refuses bindings the Engine's binding boundary refuses: anything but a map of non-empty state ids to
     * timeline ids. A draft's rows still waiting for a pick are left out of the check; a save checks them all.
     *
     * @throws InvalidArgumentException With the Engine's reason.
     */
    public static function assertShape(mixed $value, string $assetRoot, bool $complete = true): void
    {
        if ($value === null) { return; }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Interception effects map state ids to effect timeline ids.');
        }
        $checked = $complete ? $value : array_filter($value,
            static fn(mixed $effect, int|string $state): bool => $state !== '' && $effect !== '', ARRAY_FILTER_USE_BOTH);
        new BattleConditionEffects(new EffectTimelineLibrary($assetRoot), ['interceptions' => $checked]);
    }

    /**
     * Refuses the bindings an edit adds or changes that a battle could not play: one naming a state the
     * project does not define, or an effect the Engine's interception rule refuses for either presentation.
     * Bindings the edit leaves as they were are not checked again, so an unrelated broken binding stays
     * for validation to report rather than blocking other edits.
     *
     * @throws InvalidArgumentException With the Engine's reason.
     */
    public static function assertChangedBindingsValid(mixed $old, mixed $new, ReferenceCatalog $references, bool $complete = true): void
    {
        self::assertShape($new, $references->getAssetRoot(), $complete);
        $old = is_array($old) ? $old : [];
        $states = $references->valuesFor('states');
        foreach (is_array($new) ? $new : [] as $state => $effect) {
            $state = (string) $state;
            if (array_key_exists($state, $old) && $old[$state] === $effect) { continue; }
            if ($state !== '' && ! in_array($state, $states, true)) {
                throw new InvalidArgumentException(sprintf('Unknown state "%s"; choose a project state.', $state));
            }
            if (is_string($effect) && $effect !== '') {
                $failure = self::findTimelineFailures($effect, $references->loadInterceptionTimeline(...))[0] ?? null;
                if ($failure !== null) { throw new InvalidArgumentException($failure['message'], previous: $failure['error']); }
            }
        }
    }

    /**
     * Why an effect cannot play as an interception, for each presentation that refuses it, in words that
     * name the presentation and carry the Engine's own reason.
     *
     * @param \Closure(string, EffectPresentation): mixed $load The interception rule's loader.
     * @return list<array{presentation: EffectPresentation, message: string, error: Throwable}>
     */
    public static function findTimelineFailures(string $effect, \Closure $load): array
    {
        $failures = [];
        foreach (EffectPresentation::cases() as $presentation) {
            try {
                $load($effect, $presentation);
            } catch (Throwable $error) {
                $failures[] = ['presentation' => $presentation, 'error' => $error, 'message' => sprintf(
                    'Effect %s cannot play as an interception for the %s presentation: %s', $effect, $presentation->value, $error->getMessage())];
            }
        }

        return $failures;
    }
}
