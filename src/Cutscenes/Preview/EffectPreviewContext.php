<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\EffectValidator;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;

/**
 * The consumer an effect timeline is previewed in, with that consumer's
 * Engine admission: the field, battle, or a caller-owned stage.
 *
 * The timeline does not choose its consumer; whoever plays it does. A
 * declared graphical `stage` is played by battle when an action animation
 * binds the effect as its source or target, and by a caller such as an inn
 * rest presentation otherwise. The field never plays a stage. Every editor
 * surface asks this one place which consumer to preview and how it compiles.
 */
enum EffectPreviewContext: string
{
    case FIELD = 'field';
    case BATTLE = 'battle';
    case STAGE = 'stage';

    /**
     * The context an effect is previewed in until the author chooses one:
     * battle when a battle animation binds it; for a declared stage, its
     * caller-owned stage otherwise; for any other effect, the field when the
     * field plays it, else battle.
     *
     * @param iterable<CutsceneAsset> $cinematics The cinematics whose scripts may play it on the field.
     */
    public static function findDefault(CutsceneAsset $asset, ?ProjectWorkspace $workspace, iterable $cinematics = []): self
    {
        $declaresStage = $asset->hasStage();
        if (! $workspace instanceof ProjectWorkspace) {
            return $declaresStage ? self::STAGE : self::BATTLE;
        }
        if ($declaresStage) {
            return isset(EffectValidator::findBattleUses($workspace)[$asset->id]) ? self::BATTLE : self::STAGE;
        }

        $uses = EffectValidator::findUses($workspace)[$asset->id] ?? [];
        $fieldScripts = EffectValidator::findScriptUses($workspace, $cinematics)[$asset->id] ?? [];

        return isset($uses['battle']) || (! isset($uses['field']) && $fieldScripts === []) ? self::BATTLE : self::FIELD;
    }

    /** Whether this consumer can play an effect that does, or does not, declare a stage. */
    public function acceptsStageDeclaration(bool $declaresStage): bool
    {
        return match ($this) {
            self::BATTLE => true,
            self::STAGE => $declaresStage,
            self::FIELD => ! $declaresStage,
        };
    }

    /** The other consumer that can play the effect: battle and its stage owner, or battle and the field. */
    public function getAlternative(bool $declaresStage): self
    {
        return $this === self::BATTLE ? ($declaresStage ? self::STAGE : self::FIELD) : self::BATTLE;
    }

    /** Whether this consumer has a battle caster and target around the effect. */
    public function isBattle(): bool
    {
        return $this === self::BATTLE;
    }

    /** Whether the effect is its caller's whole stage, with no battle or field surroundings. */
    public function isOwnedStage(): bool
    {
        return $this === self::STAGE;
    }

    /**
     * Compiles the effect as it stands, unsaved edits included, with this
     * consumer's Engine admission.
     */
    public function compile(CutsceneAsset $asset, EffectPresentation $presentation): CompiledEffectTimeline
    {
        return $asset->compileEffect($presentation, $this->isBattle(), forStage: $this->isOwnedStage());
    }

    /** The terminal stage this consumer places one presentation's frame on. */
    public function createStage(EffectPresentation $presentation, bool $isCasterOnLeft = true): EffectPreviewStage
    {
        return new EffectPreviewStage($presentation, $this->isBattle(), $isCasterOnLeft, forStage: $this->isOwnedStage());
    }

    /** How the context is named to the author. */
    public function describe(): string
    {
        return match ($this) {
            self::FIELD => 'the field',
            self::BATTLE => 'battle',
            self::STAGE => 'its owned stage',
        };
    }
}
