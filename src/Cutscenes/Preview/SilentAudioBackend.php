<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Audio\Interfaces\AudioBackendInterface;

/**
 * An audio backend for a preview: available, and silent.
 *
 * A preview pane has no speakers to own, but the engine's `AudioManager`
 * only records what a cinematic asked for when it believes some player is
 * available -- otherwise it declines the request and keeps no track. That
 * would leave the editor unable to observe the audio a cinematic leaves
 * behind, which is part of what a watched run and a skipped run must agree
 * on. So the preview gives the engine a real backend that plays nothing,
 * the way `PreviewCamera` gives it a real camera that draws into a buffer.
 *
 * The preview also leaves `audio.music` off, so the engine never starts a
 * player process; the command below exists only so that a path which did
 * try would run something harmless.
 *
 * @package Ichiloto\Editor\Cutscenes\Preview
 */
final class SilentAudioBackend implements AudioBackendInterface
{
    public function getExecutableName(): string
    {
        return 'true';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function supportsNativeLooping(): bool
    {
        return true;
    }

    public function supports(string $filePath): bool
    {
        return true;
    }

    public function supportsSeeking(): bool
    {
        return true;
    }

    public function buildCommand(string $filePath, float $volume, bool $loop, float $startAtSeconds = 0.0): array
    {
        return ['true'];
    }
}
