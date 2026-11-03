<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Closure;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/** Drawing features the preview can drive from its own pausable, seekable playhead. */
final readonly class PreviewPresentationCapabilities
{
    /** @param Closure(string): bool $rendererSupports */
    public function __construct(private Closure $rendererSupports) {}

    public function supportsFeature(string $feature): bool
    {
        // Native camera interpolation currently uses a free-running display clock.
        // PHP's sampled camera keeps pause, step, seek and still capture at the requested time.
        return $feature !== RendererSessionConfig::CAMERA_MOTION && ($this->rendererSupports)($feature);
    }
}
