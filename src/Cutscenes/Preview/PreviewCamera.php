<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

/**
 * The camera of a preview scene: the field's own camera, drawing through the
 * Engine's Console as the game's does, so a preview's picture is taken in an
 * isolated Console capture ({@see \Ichiloto\Engine\IO\Console\Console::capturePresentation()})
 * and the editor's own screen is never written. The field camera's UI
 * lifecycle belongs to the game's UI manager, which a preview scene has none
 * of, so it does nothing here.
 */
final class PreviewCamera extends Camera
{
    public function __construct(SceneInterface $scene, int $width, int $height)
    {
        parent::__construct($scene, max(1, $width), max(1, $height));
    }

    /**
     * Resizes the captured screen.
     */
    public function resize(int $width, int $height): void
    {
        $this->resizeViewport($width, $height);
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function render(): void
    {
    }

    public function erase(): void
    {
    }

    public function resume(): void
    {
    }

    public function suspend(): void
    {
    }

    public function update(): void
    {
    }
}
