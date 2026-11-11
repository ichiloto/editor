<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposition;

/** One frame of a captured cinematic: the Engine's composition of the scene, with when it was taken. */
final readonly class CapturedFrame
{
    /**
     * @param int $index The frame's number, from 0.
     * @param float $captureSeconds The capture's time at this frame.
     * @param float $sceneSeconds The scene's clock at this frame, which a notice on screen holds.
     * @param string|null $waitKind What waits on the player in this frame: `notice`, `dialogue`, `choice`, or null.
     */
    public function __construct(
        public int $index,
        public float $captureSeconds,
        public float $sceneSeconds,
        public ?string $waitKind,
        public SceneFrameComposition $composition,
    ) {
    }
}
