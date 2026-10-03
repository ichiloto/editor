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
     * Compiles a summon through the engine's own compiler.
     *
     * @param array<string, mixed> $data The data file's array.
     * @param array<string, mixed> $timeline The timeline file's array.
     */
    public static function compileSummon(array $data, array $timeline, ?string $projectRoot = null): SummonCompiledCutscene
    {
        return self::inProject(
            $projectRoot,
            static fn(): SummonCompiledCutscene => new SummonCutsceneCompiler()->compile(
                SummonCutsceneDefinition::fromArrays($data, $timeline),
            ),
        );
    }

    /**
     * Compiles an effect timeline through the Engine's effect library, as
     * battle or the field plays it in one presentation.
     *
     * @param array<string, mixed> $timeline The timeline file's array.
     */
    public static function compileEffect(
        string $id,
        array $timeline,
        EffectPresentation $presentation,
        bool $forBattle,
        ?string $projectRoot = null,
    ): CompiledEffectTimeline {
        return self::inProject(
            $projectRoot,
            static fn(): CompiledEffectTimeline => new EffectTimelineLibrary(self::getAssetRoot($projectRoot))
                ->compile($id, $timeline, $forBattle, $presentation),
        );
    }

    /**
     * Proves an effect timeline can be played: each presentation must
     * compile for battle or for the field, the two places the Engine plays
     * effects. Which one a project uses it in is validation's to check.
     *
     * @param array<string, mixed> $timeline The timeline file's array.
     * @throws RuntimeException Naming each presentation neither accepts, with the Engine's reasons.
     */
    public static function checkEffect(string $id, array $timeline, ?string $projectRoot = null): void
    {
        $failures = [];

        foreach (EffectPresentation::cases() as $presentation) {
            $reasons = [];

            foreach (['battle' => true, 'field' => false] as $context => $forBattle) {
                try {
                    self::compileEffect($id, $timeline, $presentation, $forBattle, $projectRoot);
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
