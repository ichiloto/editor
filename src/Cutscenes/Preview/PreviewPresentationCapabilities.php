<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Closure;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * Drawing features the preview can drive from its own pausable, seekable playhead: every feature the preview's
 * renderer names but one. A feature the renderer does not name is never composed for it, a canvas group's scale
 * (`canvas_group_scale`) among them: the scale is sampled by PHP at the playhead and drawn by the renderer, so
 * the preview passes on exactly what its renderer says of it.
 */
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
