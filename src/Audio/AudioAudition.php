<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Audio;

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\AudioPlayback;
use Ichiloto\Engine\Audio\Interfaces\AudioBackendInterface;
use Ichiloto\Engine\Core\Game;

/**
 * Plays one music track or sound effect an author picked, to hear it before
 * choosing it: through the Engine's own audio manager, so the file is found
 * and played exactly as the game finds and plays it (the same players, the
 * same extensions guessed for a name without one). One audition plays at a
 * time, once through; another stops it, and so does closing the editor.
 */
final class AudioAudition extends AudioManager
{
    /** The conventional folder under assets/Audio each kind's files are found in, as the game looks for them. */
    private const array FOLDERS = ['bgm' => 'BGM', 'sfx' => 'SFX'];

    private ?AudioPlayback $playback = null;

    /** What is playing, as kind and name, while it plays. */
    private ?array $playing = null;

    /** @var list<AudioBackendInterface>|null The players to use instead of this computer's, as a test gives them. */
    private ?array $players;

    /** @param list<AudioBackendInterface>|null $players The players to use instead of this computer's; tests give silent ones. */
    public function __construct(Game $game, private readonly string $projectRoot, ?array $players = null)
    {
        $this->players = $players;
        parent::__construct($game);
    }

    protected function createBackends(): array
    {
        return $this->players ?? parent::createBackends();
    }

    /**
     * Plays a track or sound by the name a field stores, stopping any other.
     *
     * @param 'bgm'|'sfx' $kind
     * @return string|null Why it cannot play, or null when it plays.
     */
    public function play(string $kind, string $name): ?string
    {
        $this->stop();
        $folder = self::FOLDERS[$kind] ?? null;
        if ($folder === null) {
            return sprintf('%s is not music or a sound effect.', $kind);
        }
        if (trim($name) === '' || str_contains($name, '..')) {
            return 'Choose a track first.';
        }
        $file = $this->resolveAudioPath(rtrim($this->projectRoot, '/') . "/assets/Audio/{$folder}/{$name}", $folder);
        if ($file === null) {
            return sprintf('%s was not found in assets/Audio/%s.', $name, $folder);
        }
        $backend = $this->selectBackend($file);
        if ($backend === null) {
            return sprintf('No audio player on this computer can play %s.', basename($file));
        }
        $this->playback = $this->spawn($backend->buildCommand($file, 1.0, false));
        if ($this->playback === null) {
            return sprintf('%s could not be started.', $backend->getExecutableName());
        }
        $this->playing = ['kind' => $kind, 'name' => $name];

        return null;
    }

    /** Stops the audition, if one is playing. */
    public function stop(): void
    {
        $this->playback?->stop();
        $this->playback = null;
        $this->playing = null;
    }

    /**
     * What is playing, as kind and name; null once it has finished or been stopped.
     *
     * @return array{kind: string, name: string}|null
     */
    public function describePlaying(): ?array
    {
        if ($this->playback !== null && ! $this->playback->isRunning) {
            $this->stop();
        }

        return $this->playing;
    }

    public function shutdown(): void
    {
        $this->stop();
        parent::shutdown();
    }
}
