<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneCompiler;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use RuntimeException;
use Throwable;

/**
 * The engine reading a cutscene's authored arrays, from the editor.
 *
 * The engine owns what a cinematic or a summon is: `CinematicDefinition`
 * validates metadata, cast, finalizer and command tree; the summon compiler
 * turns a definition and timeline into the frames the player shows. The
 * editor never duplicates either. It hands the arrays it holds -- saved or
 * not -- to those same classes, and what they refuse, the editor reports in
 * their words and declines to write.
 *
 * @package Ichiloto\Editor\Cutscenes
 */
final class CutsceneHydration
{
    /**
     * Hydrates a cinematic through the engine, throwing the engine's own
     * reason when it refuses.
     *
     * @param array<string, mixed> $data The data file's array.
     * @param array<int|string, mixed> $script The script file's array.
     */
    public static function cinematic(array $data, array $script, ?string $projectRoot = null): CinematicDefinition
    {
        return self::inProject(
            $projectRoot,
            static fn(): CinematicDefinition => CinematicDefinition::fromArrays($data, $script),
        );
    }

    /**
     * Hydrates a summon through the engine.
     *
     * @param array<string, mixed> $data The data file's array.
     * @param array<string, mixed> $timeline The timeline file's array.
     */
    public static function summon(array $data, array $timeline, ?string $projectRoot = null): SummonCutsceneDefinition
    {
        return self::inProject(
            $projectRoot,
            static fn(): SummonCutsceneDefinition => SummonCutsceneDefinition::fromArrays($data, $timeline),
        );
    }

    /**
     * Compiles a summon through the engine's own compiler, as one renderer
     * plays it: the terminal never needs the graphical art, and the
     * graphical presentation checks its images against the project's assets.
     *
     * @param array<string, mixed> $data The data file's array.
     * @param array<string, mixed> $timeline The timeline file's array.
     */
    public static function compileSummon(array $data, array $timeline, ?string $projectRoot = null,
        EffectPresentation $presentation = EffectPresentation::TERMINAL): SummonCompiledCutscene
    {
        return self::inProject(
            $projectRoot,
            static fn(): SummonCompiledCutscene => new SummonCutsceneCompiler(assetRoot: self::getAssetRoot($projectRoot))->compile(
                SummonCutsceneDefinition::fromArrays($data, $timeline),
                $presentation,
            ),
        );
    }

    /**
     * Compiles an effect timeline through the Engine's effect library, as
     * battle, the field or an owned stage plays it in one presentation.
     *
     * @param array<string, mixed> $timeline The timeline file's array.
     */
    public static function compileEffect(
        string $id,
        array $timeline,
        EffectPresentation $presentation,
        bool $forBattle,
        ?string $projectRoot = null,
        bool $forStage = false,
    ): CompiledEffectTimeline {
        // A paired Terminal sequence owns its cues and tracks, not the
        // graphical consumer's stage/audio lifecycle.
        if ($forStage && ! $forBattle && $presentation === EffectPresentation::TERMINAL && isset($timeline['presentations'])) {
            try {
                return self::compileEffect($id, $timeline, $presentation, false, $projectRoot);
            } catch (RuntimeException $fieldFailure) {
                try {
                    return self::compileEffect($id, $timeline, $presentation, true, $projectRoot);
                } catch (RuntimeException $battleFailure) {
                    throw new RuntimeException(sprintf('Independent Terminal timeline is not admitted: %s; %s',
                        $fieldFailure->getMessage(), $battleFailure->getMessage()), previous: $battleFailure);
                }
            }
        }
        return self::inProject(
            $projectRoot,
            static fn(): CompiledEffectTimeline => new EffectTimelineLibrary(self::getAssetRoot($projectRoot))
                ->compile($id, $timeline, $forBattle, $presentation, forStage: $forStage),
        );
    }

    /**
     * Proves an effect timeline can be played: each presentation must
     * compile in an admitted context. An authored stage owns its space;
     * battle/field admission cannot substitute for that ownership.
     *
     * @param array<string, mixed> $timeline The timeline file's array.
     * @throws RuntimeException Naming each presentation neither accepts, with the Engine's reasons.
     */
    public static function checkEffect(string $id, array $timeline, ?string $projectRoot = null): void
    {
        $failures = [];

        foreach (EffectPresentation::cases() as $presentation) {
            $reasons = [];

            $contexts = self::isOwnedStage($timeline) ? ['stage'] : ['battle', 'field'];
            foreach ($contexts as $context) {
                try {
                    self::compileEffect($id, $timeline, $presentation, $context === 'battle', $projectRoot, $context === 'stage');
                    $reasons = [];
                    break;
                } catch (RuntimeException $exception) {
                    $reasons[] = sprintf('%s: %s', $context, $exception->getMessage());
                }
            }

            if ($reasons !== []) {
                $failures[] = sprintf('the %s presentation plays nowhere (%s)', $presentation->value, implode('; ', $reasons));
            }
        }

        if ($failures !== []) {
            throw new RuntimeException(sprintf('Effect %s cannot be played: %s.', $id, implode('; ', $failures)));
        }
    }

    /** Stage ownership belongs to the complete asset, not the tab being inspected. */
    public static function isOwnedStage(array $timeline): bool
    {
        $graphical = $timeline['presentations'][EffectPresentation::GRAPHICAL->value] ?? $timeline;

        return is_array($graphical) && array_key_exists('stage', $graphical);
    }

    /** The asset root the Engine resolves an effect's images against. */
    private static function getAssetRoot(?string $projectRoot): string
    {
        return rtrim($projectRoot ?? (string) getcwd(), '/') . '/assets';
    }

    /**
     * Returns whether the engine's cutscene classes are reachable from this
     * checkout at all.
     */
    public static function isAvailable(): bool
    {
        return class_exists(CinematicDefinition::class) && class_exists(SummonCutsceneDefinition::class);
    }

    /**
     * Runs an engine call inside the project directory, so anything the
     * engine resolves relative to the working directory resolves against
     * the project, and turns any refusal into a plain exception carrying the
     * engine's message.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private static function inProject(?string $projectRoot, callable $operation): mixed
    {
        try {
            if ($projectRoot !== null && is_dir($projectRoot)) {
                return ProjectDirectoryContext::run($projectRoot, static fn(): mixed => $operation());
            }

            return $operation();
        } catch (Throwable $throwable) {
            throw new RuntimeException($throwable->getMessage(), 0, $throwable);
        }
    }
}
