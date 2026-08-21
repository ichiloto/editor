<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

use Ichiloto\Editor\ProjectDirectoryContext;
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
