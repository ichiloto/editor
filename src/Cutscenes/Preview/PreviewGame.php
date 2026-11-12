<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Game;

/**
 * The Game a preview scene belongs to: an audio manager with no backends, a
 * modal manager that delivers notices to the preview's screen
 * ({@see PreviewNoticeManager}), and no terminal, title, or loop of its own.
 *
 * The Engine's own headless tests host cinematics the same way. Nothing here
 * reimplements playback; the interpreter, controller, stage, presentation,
 * and audio sessions are the Engine's.
 */
final class PreviewGame extends Game
{
    /** The Engine's notice queue, delivering to the preview's screen. */
    public readonly PreviewNoticeManager $notices;

    public function __construct()
    {
        $this->audioManager = new PreviewAudioManager($this);
        // This Game's own queue, bound before anything acquires one, as the Engine's per-Game contract asks.
        $this->notices = new PreviewNoticeManager($this);
        $this->bindModalManager($this->notices);
    }

    public function __destruct()
    {
        // The real Game tears the terminal down here; a preview never set one up.
    }
}

/**
 * An audio manager that keeps the engine's own record of what is playing,
 * and plays nothing.
 *
 * The backend is silent but present: without one the engine declines every
 * music request and keeps no track, and the audio state a cinematic leaves
 * behind -- which a watched run and a skipped run must agree on -- could
 * not be observed at all.
 */
final class PreviewAudioManager extends AudioManager
{
    public function __construct(Game $game)
    {
        parent::__construct($game);
    }

    protected function createBackends(): array
    {
        return [new SilentAudioBackend()];
    }
}
