<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Battle\BattleTargetPolicy;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPreview;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use InvalidArgumentException;

/**
 * A battle effect as a battle plays it, frame by frame, for its editors, in
 * one presentation: in an action that actually plays it, found by the
 * Engine's own animation selection among what the battle test party can do
 * (its attacks, skills, magic and items; a summon is previewed as a summon),
 * with the animation's other effect beside it, the action's pose and the
 * project's battle pacing. Nothing is invented: an effect no such action
 * plays has no battle preview. Each frame comes from the production
 * playback and presentation; nothing is resolved, spent or played aloud.
 */
final class EffectBattlePreview
{
    /** The command's length, in command frames at {@see BattleCommandTimeline::FPS}. */
    public int $totalFrames { get => $this->preview->totalFrames; }

    /**
     * @param string $lane `source` or `target`: where the effect plays in the action shown.
     * @param list<array{caster: string, action: string, lane: string}> $contexts Every action of the party that plays it.
     * @param int $binding Which of them is shown.
     * @param list<string> $targets
     * @param array<string, array{start: int, length: int}> $phases
     */
    private function __construct(
        private readonly BattleCommandPreview $preview,
        private readonly BattleCommandTimeline $plan,
        public readonly string $lane,
        public readonly array $contexts,
        public readonly int $binding,
        public readonly string $caster,
        public readonly array $targets,
        public readonly array $phases,
    ) {
    }

    /**
     * @param callable(string, EffectPresentation): CompiledEffectTimeline $compile An effect by id, compiled for battle, as it stands.
     * @param BattlePresentationCatalog|null $catalog The battle presentation, which a graphical preview requires.
     * @param int $binding Which of the actions that play the effect to show; the first when it is out of range.
     * @throws InvalidArgumentException When no action of the battle test party plays the effect.
     */
    public static function create(
        string $effectId,
        BattleConfig $battle,
        SkillCatalog $skills,
        AnimationLibrary $animations,
        BattlePacing $pacing,
        ?BattlePresentationCatalog $catalog,
        string $assetRoot,
        EffectPresentation $presentation,
        callable $compile,
        int $binding = 0,
    ): self {
        $state = new GameState();
        $found = [];
        foreach ($battle->partyRoster->battlers as $caster) {
            if (! $caster instanceof Character) {
                continue;
            }
            $candidates = [];
            foreach ([BattleCommandType::ATTACK, BattleCommandType::SKILL, BattleCommandType::MAGIC, BattleCommandType::ITEM] as $type) {
                foreach (BattleCommandCatalog::buildOptions($caster, $battle->party, $type->label(), [], $state) as $option) {
                    // A summon's choreography is previewed as the summon, not through an animation.
                    if (! $skills->isSummonAction($option->action->name)) {
                        $candidates[] = $option->action;
                    }
                }
            }
            foreach (ActionAnimationResolver::findEffectBindings($effectId, $animations, $caster, $candidates) as $match) {
                $found[] = [...$match, 'caster' => $caster];
            }
        }
        if ($found === []) {
            throw new InvalidArgumentException(sprintf('No action of the battle test party plays %s in battle, so it has no battle preview.', $effectId));
        }
        $binding = $binding >= 0 && $binding < count($found) ? $binding : 0;
        ['lane' => $lane, 'animation' => $animation, 'action' => $action, 'caster' => $caster] = $found[$binding];

        // The animation's two effects as the battle plays them in this presentation; a graphical target
        // drawing nothing in the terminal keeps its terminal sequence beside it, as the battle does.
        $source = $animation->sourceEffect === null ? null : $compile($animation->sourceEffect, $presentation);
        $target = $animation->targetEffect === null ? null : $compile($animation->targetEffect, $presentation);
        $terminalTarget = $presentation === EffectPresentation::GRAPHICAL && $target !== null && ! $target->hasTerminalContent
            ? $compile($animation->targetEffect, EffectPresentation::TERMINAL) : null;
        $plan = new BattleCommandTimeline($pacing->getTurnTimings($action), $source, $target, $terminalTarget);
        $targets = BattleTargetPolicy::resolveTargets($action->targetScope, $caster, $battle->partyRoster->battlers, $battle->troop->members->toArray());
        if ($targets === []) {
            throw new InvalidArgumentException(sprintf('%s has no one to act on in the battle test.', $action->name));
        }

        return new self(
            new BattleCommandPreview($battle, $plan, $caster, $targets, BattlePoseRole::getForAction($action),
                $presentation === EffectPresentation::TERMINAL ? null : $catalog, $assetRoot, $presentation),
            $plan,
            $lane,
            array_map(static fn(array $context): array => ['caster' => $context['caster']->name, 'action' => $context['action']->name, 'lane' => $context['lane']], $found),
            $binding,
            $caster->name,
            array_map(static fn($target): string => $target->name, $targets),
            $plan->phases,
        );
    }

    /**
     * One command frame as the battle draws it.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException When the frame is outside the command.
     */
    public function readFrame(int $frame, bool $reducedMotion): array
    {
        return $this->preview->getFrameAtIndex($frame, $reducedMotion)->toArray();
    }

    /**
     * The command frame that draws one of the effect's own frames, in the lane it plays in; a frame
     * before it maps to its first, one after it to its last.
     */
    public function findCommandFrame(int $authoredFrame): int
    {
        $phase = $this->phases[$this->lane];
        $frame = $this->plan->getCommandFrameForAuthoredFrame($this->lane, max(0, $authoredFrame));

        return $frame ?? ($authoredFrame <= 0 ? $phase['start'] : $phase['start'] + $phase['length'] - 1);
    }
}
