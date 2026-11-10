<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Animations\SourceEffectAnchor;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\Actions\CrisisSkillBattleAction;
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
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use InvalidArgumentException;

/**
 * A battle effect as a battle plays it, frame by frame, for its editors, in
 * one presentation: in an action that actually plays it, found by the
 * Engine's own animation selection among what the battle test party can do
 * (its attacks, skills, magic, items and authored Super Move techniques; a summon is previewed as a summon),
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
     * @param list<array{name: string, side: string, position: array{row: string, lane: int}|null}> $targetOptions
     * @param array<string, array{start: int, length: int}> $phases
     * @param int $laneFrames How many frames the effect itself has, in the lane it plays in.
     */
    private function __construct(
        private readonly BattleCommandPreview $preview,
        private readonly BattleCommandTimeline $plan,
        public readonly string $lane,
        public readonly array $contexts,
        public readonly int $binding,
        public readonly string $caster,
        public readonly array $targets,
        public readonly array $targetOptions,
        public readonly ?int $targetIndex,
        public readonly array $phases,
        public readonly SourceEffectAnchor $sourceEffectAnchor,
        private readonly int $laneFrames,
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
        bool $reducedMotion = false,
        ?int $targetIndex = null,
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
                    if (! $option->action instanceof CrisisSkillBattleAction && ! $skills->isSummonAction($option->action->name)) {
                        $candidates[] = $option->action;
                    }
                }
            }
            // Preview the actor's authored forms without changing their health, states or world flags.
            $superMove = $caster->crisisAbility;
            if ($superMove !== null && $caster->battleCommandLoadout?->allowsCommand(BattleCommandType::ATTACK) !== false) {
                foreach ($superMove->getVariantSkills($skills) as $skill) {
                    $candidates[] = new CrisisSkillBattleAction($skill, $superMove, $caster->actorId);
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
        // The action's own binding sizes the target effect, so each grade previews at its authored scale. A graphical
        // battle starts the caster effect where the animation anchors it, as the battle does; the terminal keeps its
        // own source phase. The command's own target boundary then drives the pose's impact entry too.
        $anchor = $presentation === EffectPresentation::GRAPHICAL ? $animation->sourceEffectAnchor : SourceEffectAnchor::SOURCE;
        $role = BattlePoseRole::getForAction($action);
        // The same stable actor binding used by production supplies the complete post-impact recovery.
        $poses = $presentation === EffectPresentation::GRAPHICAL && $catalog !== null && $catalog->getArenaFor($battle) !== null
            ? ($catalog->actorPoses[$caster->actorId] ?? null) : null;
        $pose = $poses?->getPose($role);
        $allies = $battle->partyRoster->battlers;
        $opponents = $battle->troop->members->toArray();
        $selectable = $action->targetScope->number === ItemScopeNumber::ONE
            ? BattleTargetPolicy::getEligibleTargets($action->targetScope, $caster, $allies, $opponents, $battle->formation, $action->targeting)
            : [];
        if ($targetIndex !== null && !isset($selectable[$targetIndex])) {
            throw new InvalidArgumentException('Choose a target from the current action and formation.');
        }
        $targetIndex ??= $selectable === [] ? null : 0;
        $targets = BattleTargetPolicy::resolveTargets($action->targetScope, $caster, $allies, $opponents,
            $targetIndex === null ? [] : [$selectable[$targetIndex]], formation: $battle->formation, targeting: $action->targeting);
        if ($targets === []) {
            throw new InvalidArgumentException(sprintf('%s has no one to act on in the battle test.', $action->name));
        }

        $graphical = $presentation === EffectPresentation::GRAPHICAL && $catalog !== null
            ? \Ichiloto\Engine\Battle\Presentation\GraphicalBattlePresentation::prepare($battle, $catalog, $assetRoot) : null;
        $travel = !$reducedMotion && $animation->movement->usesTargetRoute()
            ? $graphical?->planActionTravel($caster, $targets, $action, animationId: $animation->id, movement: $animation->movement) : null;
        $actionPoseOverride = $poses?->getAnimationPose($animation->id);
        $flashStep = $travel?->isPossible ? $animation->flashStep : null;
        if ($flashStep !== null && !$flashStep->supportsPose($actionPoseOverride)) {
            throw new InvalidArgumentException('Flash step requires action transit cells and a planted first frame.');
        }
        $movementPoses = !$reducedMotion && $flashStep === null;
        $plan = new BattleCommandTimeline($pacing->getTurnTimings($action), $source, $target, $terminalTarget,
            targetScale: ActionAnimationResolver::getExplicitAnimationScale($action), sourceEffectAnchor: $anchor, actionPose: $pose,
            movementPoses: $movementPoses && ($poses?->getPose(BattlePoseRole::MOVE) !== null
                || $poses?->getPose(BattlePoseRole::RETURNING) !== null), travel: $travel,
            movePose: $movementPoses ? $poses?->getPose(BattlePoseRole::MOVE) : null,
            returnPose: $movementPoses ? $poses?->getPose(BattlePoseRole::RETURNING) : null,
            projectedRange: isset($target->defaults['rangeTiming']) ? $graphical?->measureActionRange($caster, $targets) : null,
            actionPoseOverride: $actionPoseOverride, flashStep: $flashStep);

        return new self(
            new BattleCommandPreview($battle, $plan, $caster, $targets, $role,
                $presentation === EffectPresentation::TERMINAL ? null : $catalog, $assetRoot, $presentation, $graphical),
            $plan,
            $lane,
            array_map(static fn(array $context): array => ['caster' => $context['caster']->name, 'action' => $context['action']->name, 'lane' => $context['lane']], $found),
            $binding,
            $caster->name,
            array_map(static fn($target): string => $target->name, $targets),
            array_map(static fn($target): array => [
                'name' => $target->name,
                'side' => in_array($target, $allies, true) ? 'ally' : 'enemy',
                'position' => $battle->formation->getPosition($target)?->toArray(),
            ], $selectable),
            $targetIndex,
            $plan->phases,
            $anchor,
            intval(($lane === 'source' ? $source : $target)?->defaults['lengthFrames'] ?? 0),
        );
    }

    /**
     * One command frame as the battle draws it.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException When the frame is outside the command.
     */
    public function readFrame(int $frame, bool $reducedMotion, bool $imageRotation = false, bool $coverageDabs = false,
        bool $textureStrip = false, bool $regionBudget = false): array
    {
        $read = fn(): array => $this->preview->getFrameAtIndex($frame, $reducedMotion, $imageRotation, $coverageDabs, $textureStrip)->toArray();

        return $regionBudget
            ? PresentationSession::runForSession(static fn(string $capability): bool => $capability === RendererSessionConfig::CANVAS_REGION_BUDGET, $read)
            : $read();
    }

    public function getTotalFrames(bool $reducedMotion): int
    {
        return $this->preview->getTotalFrames($reducedMotion);
    }

    public function getPlaybackPhases(bool $reducedMotion): array
    {
        return $this->preview->getPlaybackPhases($reducedMotion);
    }

    /**
     * The command frame that draws one of the effect's own frames, in the lane it plays in, wherever the
     * command anchors that lane; a frame before it maps to its first, one after it to its last.
     */
    public function findCommandFrame(int $authoredFrame, bool $reducedMotion = false): int
    {
        // A frame the battle's pacing leaves no time for is drawn where the nearest earlier one is.
        for ($frame = max(0, min($authoredFrame, $this->laneFrames - 1)); $frame >= 0; $frame--) {
            $command = $this->plan->getCommandFrameForAuthoredFrame($this->lane, $frame);
            if ($command !== null) {
                return $this->preview->getPlaybackFrame($command, $reducedMotion);
            }
        }

        throw new InvalidArgumentException(sprintf('The %s effect draws no frame in this command.', $this->lane));
    }
}
