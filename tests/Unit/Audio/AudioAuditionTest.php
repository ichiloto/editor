<?php

declare(strict_types=1);

use Ichiloto\Editor\Audio\AudioAudition;
use Ichiloto\Editor\Cutscenes\Preview\PreviewGame;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Audio\Interfaces\AudioBackendInterface;

/**
 * Hearing a music or sound field before choosing it, through the game's own
 * audio. Every test here is silent: the players stand in for real ones and
 * run a quiet command, and the session is only asked for files that do not
 * exist.
 */

/** A player that makes no sound: it runs `sleep` for as long as a test needs it to "play". */
function silentAuditionPlayer(float $seconds): AudioBackendInterface
{
    return new class($seconds) implements AudioBackendInterface {
        /** @var list<string> */
        public array $files = [];

        public function __construct(private float $seconds) {}
        public function getExecutableName(): string { return 'sleep'; }
        public function isAvailable(): bool { return true; }
        public function supportsNativeLooping(): bool { return false; }
        public function supports(string $filePath): bool { return str_ends_with($filePath, '.ogg'); }
        public function supportsSeeking(): bool { return false; }
        public function buildCommand(string $filePath, float $volume, bool $loop, float $startAtSeconds = 0.0): array
        {
            $this->files[] = $filePath;

            return ['sleep', (string) $this->seconds];
        }
    };
}

/** A project with one music track, `theme.ogg`, and one sound the stand-in player cannot play. */
function auditionProject(): string
{
    $root = rememberTemporaryProject(sys_get_temp_dir() . '/ichiloto-audition-' . bin2hex(random_bytes(4)));
    mkdir($root . '/assets/Audio/BGM', 0o777, true);
    mkdir($root . '/assets/Audio/SFX', 0o777, true);
    touch($root . '/assets/Audio/BGM/theme.ogg');
    touch($root . '/assets/Audio/SFX/chime.wav');

    return $root;
}

it('finds a track by the name a field stores, as the game does, and plays it until it ends or is stopped', function () {
    $root = auditionProject();
    $player = silentAuditionPlayer(5);
    $audition = new AudioAudition(new PreviewGame(), $root, [$player]);

    expect($audition->play('bgm', 'theme'))->toBeNull()
        ->and($player->files)->toBe([$root . '/assets/Audio/BGM/theme.ogg'])
        ->and($audition->describePlaying())->toBe(['kind' => 'bgm', 'name' => 'theme']);
    $audition->stop();
    expect($audition->describePlaying())->toBeNull();

    $finishing = new AudioAudition(new PreviewGame(), $root, [silentAuditionPlayer(0)]);
    $finishing->play('bgm', 'theme');
    usleep(300_000);
    expect($finishing->describePlaying())->toBeNull();
});

it('says why a track cannot play: missing, unplayable here, or not chosen', function () {
    $audition = new AudioAudition(new PreviewGame(), auditionProject(), [silentAuditionPlayer(5)]);

    expect($audition->play('bgm', 'missing'))->toBe('missing was not found in assets/Audio/BGM.')
        ->and($audition->play('sfx', 'chime'))->toBe('No audio player on this computer can play chime.wav.')
        ->and($audition->play('bgm', ''))->toBe('Choose a track first.')
        ->and($audition->play('bgm', '../BGM/theme'))->toBe('Choose a track first.')
        ->and($audition->describePlaying())->toBeNull();
});

it('marks music and sound fields as sounds to play, and refuses what is not one', function () {
    // A project with no audio at all, so nothing this session is asked for can be found, let alone heard.
    $session = EditorSession::open(mapGraphicsProject());

    expect(\Ichiloto\Editor\Database\ReferenceCatalog::describeMedia('bgm'))->toBe(['kind' => 'audio', 'root' => 'assets/Audio/BGM'])
        ->and(\Ichiloto\Editor\Database\ReferenceCatalog::describeMedia('sfx'))->toBe(['kind' => 'audio', 'root' => 'assets/Audio/SFX'])
        ->and(fn() => $session->playAudio('maps', 'theme'))->toThrow(SessionRefusal::class, 'not music or a sound effect')
        ->and(fn() => $session->playAudio('bgm', 'missing'))->toThrow(SessionRefusal::class, 'was not found')
        ->and($session->describeAudio())->toBe(['playing' => null])
        ->and($session->stopAudio())->toBe(['playing' => null]);
});
