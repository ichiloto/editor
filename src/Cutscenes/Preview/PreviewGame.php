<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Game;

/**
 * The Game a preview scene belongs to: an audio manager with no backends and
 * no terminal, title, or loop of its own.
 *
 * The Engine's own headless tests host cinematics the same way. Nothing here
 * reimplements playback; the interpreter, controller, stage, presentation,
 * and audio sessions are the Engine's.
 */
final class PreviewGame extends Game
{
    public function __construct()
    {
        $this->audioManager = new PreviewAudioManager($this);
    }

    public function __destruct()
    {
        // The real Game tears the terminal down here; a preview never set one up.
    }
}

/**
 * An audio manager whose cinematic music sessions run without a backend.
 */
final class PreviewAudioManager extends AudioManager
{
    public function __construct(Game $game)
    {
        parent::__construct($game);
    }

    protected function createBackends(): array
    {
        return [];
    }
}
