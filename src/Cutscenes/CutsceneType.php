<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

/**
 * The two cutscene forms the Cutscenes workspace authors.
 *
 * A Cinematic is a staged story sequence: a command tree the engine's event
 * interpreter runs on the field. A Summon is a frame-driven battle
 * presentation: tracks, keyframes and cues the summon compiler builds and the
 * summon player plays. They share a workspace, an asset layout of one folder
 * with two files per stable id, and nothing else.
 *
 * @package Ichiloto\Editor\Cutscenes
 */
enum CutsceneType: string
{
    case CINEMATIC = 'cinematic';
    case SUMMON = 'summon';

    /**
     * Returns the project-relative folder holding this type's assets.
     */
    public function relativeRoot(): string
    {
        return match ($this) {
            self::CINEMATIC => 'assets/Cutscenes/Cinematics',
            self::SUMMON => 'assets/Cutscenes/Summons',
        };
    }

    /**
     * Returns the suffix of the file paired with `<id>.data.php`.
     */
    public function partnerSuffix(): string
    {
        return match ($this) {
            self::CINEMATIC => '.script.php',
            self::SUMMON => '.timeline.php',
        };
    }

    /**
     * Returns what the paired file holds, for messages.
     */
    public function partnerNoun(): string
    {
        return match ($this) {
            self::CINEMATIC => 'script',
            self::SUMMON => 'timeline',
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
        };
    }
}
