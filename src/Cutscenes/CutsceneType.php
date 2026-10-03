<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;

/**
 * The timeline forms the Cutscenes workspace authors.
 *
 * A Cinematic is a staged story sequence: a command tree the engine's event
 * interpreter runs on the field. A Summon is a frame-driven battle
 * presentation: tracks, keyframes and cues the summon compiler builds and the
 * summon player plays. An Effect is a standalone effect timeline battles,
 * the field and cinematics play: tracks, keyframes and cues the Engine's
 * effect library compiles. Each lives in one folder named by its stable id;
 * a cinematic and a summon hold two files there, an effect only its timeline.
 *
 * @package Ichiloto\Editor\Cutscenes
 */
enum CutsceneType: string
{
    case CINEMATIC = 'cinematic';
    case SUMMON = 'summon';
    /** A standalone effect timeline, played by battles, the field and cinematics. */
    case EFFECT = 'effect';

    /**
     * Returns the project-relative folder holding this type's assets.
     */
    public function relativeRoot(): string
    {
        return match ($this) {
            self::CINEMATIC => 'assets/Cutscenes/Cinematics',
            self::SUMMON => 'assets/Cutscenes/Summons',
            self::EFFECT => 'assets/' . EffectTimelineLibrary::DIRECTORY,
        };
    }

    /**
     * Returns the suffix of the file paired with `<id>.data.php`.
     */
    public function partnerSuffix(): string
    {
        return match ($this) {
            self::CINEMATIC => '.script.php',
            self::SUMMON, self::EFFECT => '.timeline.php',
        };
    }

    /**
     * Returns what the paired file holds, for messages.
     */
    public function partnerNoun(): string
    {
        return match ($this) {
            self::CINEMATIC => 'script',
            self::SUMMON, self::EFFECT => 'timeline',
        };
    }

    /**
     * Returns the singular noun used in status messages.
     */
    public function noun(): string
    {
        return match ($this) {
            self::CINEMATIC => 'cinematic',
            self::SUMMON => 'summon',
            self::EFFECT => 'effect',
        };
    }

    /**
     * Returns the label shown in the workspace's type switch.
     */
    public function label(): string
    {
        return match ($this) {
            self::CINEMATIC => 'Cinematic',
            self::SUMMON => 'Summon',
            self::EFFECT => 'Effect',
        };
    }

    /**
     * Whether the asset has an `<id>.data.php` beside its partner file. An
     * effect is its timeline alone, as the Engine reads it.
     */
    public function hasDataFile(): bool
    {
        return $this !== self::EFFECT;
    }

    /**
     * Returns the pattern a stable id of this type must match: the Engine's
     * own for effects, which allows no dots.
     */
    public function getIdPattern(): string
    {
        return match ($this) {
            self::EFFECT => EffectTimelineLibrary::ID_PATTERN,
            self::CINEMATIC, self::SUMMON => '/^[a-z0-9][a-z0-9._-]*$/',
        };
    }

    /**
     * Returns the characters an id may hold, for messages.
     */
    public function describeIdCharacters(): string
    {
        return $this === self::EFFECT
            ? 'lowercase letters, digits, "_" and "-"'
            : 'lowercase letters, digits, ".", "_" and "-"';
    }
}
