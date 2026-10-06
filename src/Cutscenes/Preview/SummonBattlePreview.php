<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Battle\BattleTargetPolicy;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPreview;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use InvalidArgumentException;

/**
 * A summon as a battle plays it, frame by frame, for its editors: the
 * Engine's own command preview of the summon's linked action, cast by a
 * member of the given battle the summon's wielder policy allows, at the
 * targets that action's scope takes, with the project's battle pacing.
 * Each frame comes from the production playback, canvas composer and
 * terminal arena, so what is previewed is what the battle draws; nothing
 * is resolved, spent or played aloud. Built once for a summon and battle,
 * then seeked freely, forwards or back.
 */
final class SummonBattlePreview
{
    /** The command's length, in command frames at {@see BattleCommandTimeline::FPS}. */
    public int $totalFrames { get => $this->preview->totalFrames; }

    /**
     * @param string $caster The member who casts it.
     * @param list<string> $targets Whom it is cast at.
     * @param array<string, array{start: int, length: int}> $phases The command's phases, in command frames.
     */
    private function __construct(
        private readonly BattleCommandPreview $preview,
        public readonly string $caster,
        public readonly array $targets,
        public readonly array $phases,
    ) {
    }

    /**
     * @param SummonCompiledCutscene $graphical The summon compiled for the graphical battle.
     * @param SummonCompiledCutscene $terminal The same summon compiled for the terminal arena.
     * @throws InvalidArgumentException When no member may cast it, its action is unknown or it has no one to target.
     */
    public static function create(
        SummonCutsceneDefinition $definition,
        SummonCompiledCutscene $graphical,
        SummonCompiledCutscene $terminal,
        BattleConfig $battle,
        SkillCatalog $skills,
        BattlePacing $pacing,
        BattlePresentationCatalog $catalog,
        string $assetRoot,
    ): self {
        $battlers = $battle->partyRoster->battlers;
        $policy = $definition->wielders;
        $caster = array_find($battlers, static fn($battler): bool => $battler instanceof Character
            && ($policy === null || ($policy->isValid() && $policy->allowsCharacter($battler))))
            ?? throw new InvalidArgumentException(sprintf('No one in the battle test party may call %s; add a member who may.', $definition->name));
        $skill = $skills->findSkill($definition->linkedActionId)
            ?? throw new InvalidArgumentException(sprintf('%s calls the action %s, which no skill defines.', $definition->name, $definition->linkedActionId));
        $action = new SkillBattleAction($skill);
        $opponents = $battle->troop->members->toArray();
        $targets = BattleTargetPolicy::resolveTargets($action->targetScope, $caster, $battlers, $opponents);
        if ($targets === []) {
            throw new InvalidArgumentException(sprintf('%s has no one to target in the battle test.', $definition->name));
        }
        $plan = new BattleCommandTimeline($pacing->getTurnTimings($action), target: $graphical, terminalTarget: $terminal);

        return new self(
            new BattleCommandPreview($battle, $plan, $caster, $targets, BattlePoseRole::SUMMON, $catalog, $assetRoot),
            $caster->name,
            array_map(static fn($target): string => $target->name, $targets),
            $plan->phases,
        );
    }

    /**
     * One command frame as the battle draws it: its phase, the graphical
     * canvas (none without a graphical arena), the terminal arena's lines,
     * the cues at it and those crossed reaching it, and what went wrong
     * presenting it.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException When the frame is outside the command.
     */
    public function readFrame(int $frame, bool $reducedMotion): array
    {
        return $this->preview->getFrameAtIndex($frame, $reducedMotion)->toArray();
    }
}
