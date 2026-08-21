<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneHydration;
use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;

/**
 * A watched run and a skipped run must agree on the audio the cinematic
 * leaves behind, and on who owns field input when it ends. The Engine owns
 * both outcomes: it applies the completion policy and it decides when the
 * field is the player's again. The editor observes them and compares.
 */

/**
 * A cinematic that plays a track, and a finalizer the engine runs at the end
 * of both roads -- a watched run reaches it after the last command, a
 * skipped run reaches it at once.
 *
 * A `late` music command sits after the waiting, where a skip never reaches
 * it: that is the only way the two roads can end on different music, and it
 * is exactly what the comparison exists to catch.
 *
 * @param array<string, mixed> $music The opening cinematic_music fields.
 * @param array<string, mixed>|null $late A later cinematic_music a skip would miss.
 * @param array<string, mixed>|null $finalizerMusic The finalizer's own, when it has one.
 */
function audioCinematic(string $root, array $music, ?array $late = null, ?array $finalizerMusic = null, string $id = 'audio-probe'): \Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition
{
    $script = [
        ['type' => 'cinematic_music', ...$music],
        ['type' => 'wait', 'seconds' => 0.2],
        ['type' => 'narration', 'title' => 'Probe', 'text' => 'The kites cross.', 'seconds' => 0.2],
    ];

    if ($late !== null) {
        $script[] = ['type' => 'cinematic_music', ...$late];
    }

    $finalizer = [];

    if ($finalizerMusic !== null) {
        $finalizer[] = ['type' => 'cinematic_music', ...$finalizerMusic];
    }

    return CutsceneHydration::cinematic(
        [
            'id' => $id,
            'name' => 'Audio Probe',
            'skip' => ['policy' => 'authored'],
            'finalizer' => [
                ...$finalizer,
                ['type' => 'clear_presentation'],
                ['type' => 'record_event', 'name' => 'audio_probe_finalized'],
            ],
        ],
        $script,
        $root,
    );
}

/**
 * Runs the cinematic to the end, and again with an immediate skip.
 *
 * @return array{0: \Ichiloto\Editor\Cutscenes\Preview\PreviewSnapshot, 1: \Ichiloto\Editor\Cutscenes\Preview\PreviewSnapshot, 2: array<int, array{label: string, left: string, right: string}>}
 */
function watchedAndSkipped(string $root, \Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition $definition, ?array $startingMusic = null): array
{
    $options = ['mapId' => 'harbour', 'x' => 1, 'y' => 1, 'autoAdvance' => true];

    if ($startingMusic !== null) {
        $options['startingMusic'] = $startingMusic;
    }

    $watched = CinematicPreviewSession::start($root, $definition, $options);
    $skipped = CinematicPreviewSession::start($root, $definition, $options);

    try {
        $watched->runToCompletion(30.0);
        expect($skipped->skip())->toBeTrue();
        $skipped->runToCompletion(30.0);

        return [$watched->snapshot(), $skipped->snapshot(), $watched->snapshot()->diff($skipped->snapshot())];
    } finally {
        $watched->dispose();
        $skipped->dispose();
    }
}

/**
 * A project with two tracks the engine can resolve.
 */
function audioProject(): string
{
    $root = cutsceneProject();
    mkdir($root . '/assets/Audio/BGM', 0o777, true);
    file_put_contents($root . '/assets/Audio/BGM/dawn-theme.ogg', '');
    file_put_contents($root . '/assets/Audio/BGM/field-theme.ogg', '');

    return $root;
}

it('reports the track a cinematic leaves playing, and agrees between watched and skipped runs', function (string $behaviour, ?string $expectedTrack, bool $expectedRestored) {
    $root = audioProject();
    $music = ['track' => 'dawn-theme', 'loop' => false, 'completionBehavior' => $behaviour];
    $definition = audioCinematic($root, $music, finalizerMusic: $music);
    [$watched, $skipped, $differences] = watchedAndSkipped($root, $definition, ['track' => 'field-theme', 'loop' => true]);

    expect($watched->values['music track'])->toBe($expectedTrack)
        ->and($watched->values['music restored'])->toBe($expectedRestored)
        ->and($skipped->values['music track'])->toBe($expectedTrack)
        ->and($skipped->values['music restored'])->toBe($expectedRestored)
        ->and(array_column($differences, 'label'))->not->toContain('music track')
        ->and(array_column($differences, 'label'))->not->toContain('music restored')
        ->and(array_column($differences, 'label'))->not->toContain('music loops');
})->with([
    // The cinematic's own track keeps playing after it ends.
    'continue' => ['continue', 'assets/Audio/BGM/dawn-theme.ogg', false],
    // Nothing is playing when it ends.
    'stop' => ['stop', null, false],
    // The field's own track comes back.
    'restore previous' => ['restore_previous', 'assets/Audio/BGM/field-theme.ogg', true],
]);

it('reports an audio mismatch when a skip never reaches the command that changes the music', function () {
    $root = audioProject();
    // Watched: the late command replaces the session and stops the music at
    // the end. Skipped: that command is never reached, so the opening
    // request stands and its track keeps playing.
    $definition = audioCinematic(
        $root,
        ['track' => 'dawn-theme', 'loop' => false, 'completionBehavior' => 'continue'],
        late: ['track' => 'dawn-theme', 'loop' => false, 'completionBehavior' => 'stop'],
    );
    [$watched, $skipped, $differences] = watchedAndSkipped($root, $definition, ['track' => 'field-theme', 'loop' => true]);
    $byLabel = array_column($differences, null, 'label');

    expect($watched->values['music track'])->toBeNull()
        ->and($skipped->values['music track'])->toBe('assets/Audio/BGM/dawn-theme.ogg')
        ->and($byLabel)->toHaveKey('music track')
        ->and($byLabel['music track']['left'])->toBe('null')
        ->and($byLabel['music track']['right'])->toBe('assets/Audio/BGM/dawn-theme.ogg');
});

it('reports a restore mismatch when only one road puts the field track back', function () {
    $root = audioProject();
    // Skipped: the opening request restores what the field was playing.
    // Watched: the late command takes over and simply continues.
    $definition = audioCinematic(
        $root,
        ['track' => 'dawn-theme', 'loop' => false, 'completionBehavior' => 'restore_previous'],
        late: ['track' => 'dawn-theme', 'loop' => false, 'completionBehavior' => 'continue'],
    );
    [$watched, $skipped, $differences] = watchedAndSkipped($root, $definition, ['track' => 'field-theme', 'loop' => true]);
    $byLabel = array_column($differences, null, 'label');

    expect($watched->values['music restored'])->toBeFalse()
        ->and($skipped->values['music restored'])->toBeTrue()
        ->and($skipped->values['music track'])->toBe('assets/Audio/BGM/field-theme.ogg')
        ->and($byLabel)->toHaveKey('music restored')
        ->and($byLabel)->toHaveKey('music track');
});

it('gives the field back to the player on both roads, and shows who holds it while one runs', function () {
    $root = audioProject();
    $definition = audioCinematic($root, ['track' => 'dawn-theme', 'loop' => false, 'completionBehavior' => 'stop']);
    $preview = CinematicPreviewSession::start($root, $definition, ['mapId' => 'harbour', 'x' => 1, 'y' => 1]);

    try {
        $preview->step();
        expect($preview->fieldInputOwner())->toBe('cinematic')
            ->and($preview->snapshot()->values['field input'])->toBe('cinematic')
            ->and($preview->snapshot()->values['save available'])->toBeFalse();

        $preview->runToCompletion(30.0);
        expect($preview->fieldInputOwner())->toBe('player')
            ->and($preview->snapshot()->values['field input'])->toBe('player');
    } finally {
        $preview->dispose();
    }

    [$watched, $skipped, $differences] = watchedAndSkipped($root, $definition);

    expect($watched->values['field input'])->toBe('player')
        ->and($skipped->values['field input'])->toBe('player')
        ->and(array_column($differences, 'label'))->not->toContain('field input');
});

it('reports an input-ownership mismatch when one road leaves the field to the cinematic', function () {
    $root = audioProject();
    $definition = audioCinematic($root, ['track' => 'dawn-theme', 'loop' => false, 'completionBehavior' => 'stop']);
    $finished = CinematicPreviewSession::start($root, $definition, ['mapId' => 'harbour', 'x' => 1, 'y' => 1, 'autoAdvance' => true]);
    $running = CinematicPreviewSession::start($root, $definition, ['mapId' => 'harbour', 'x' => 1, 'y' => 1, 'autoAdvance' => true]);

    try {
        $finished->runToCompletion(30.0);
        $running->step();
        $byLabel = array_column($finished->snapshot()->diff($running->snapshot()), null, 'label');

        expect($byLabel)->toHaveKey('field input')
            ->and($byLabel['field input']['left'])->toBe('player')
            ->and($byLabel['field input']['right'])->toBe('cinematic');
    } finally {
        $finished->dispose();
        $running->dispose();
    }
});

it('observes an authored fade-out only as far as the Engine has taken it, and says which state that is', function () {
    // The engine finishes a completion fade from AudioManager::update(),
    // which advances by the global game clock. A preview owns its own clock,
    // so a cinematic that asks for a fade-out ends the run with the fade
    // still in flight -- the track still playing. The snapshot reports the
    // state the engine has actually reached rather than the one it will
    // reach, which is the honest half of a gap named in the handoff.
    $root = audioProject();
    $definition = audioCinematic($root, [
        'track' => 'dawn-theme',
        'loop' => false,
        'fadeOut' => 1.5,
        'completionBehavior' => 'stop',
    ]);
    $preview = CinematicPreviewSession::start($root, $definition, ['mapId' => 'harbour', 'x' => 1, 'y' => 1, 'autoAdvance' => true]);

    try {
        $preview->runToCompletion(30.0);

        expect($preview->status())->toBe(CinematicPreviewSession::STATUS_COMPLETED)
            ->and($preview->audioState()['track'])->toBe('assets/Audio/BGM/dawn-theme.ogg')
            ->and($preview->snapshot()->values['music track'])->toBe('assets/Audio/BGM/dawn-theme.ogg');
    } finally {
        $preview->dispose();
    }

    // With no fade, the same cinematic settles inside the run.
    $immediate = CinematicPreviewSession::start($root, audioCinematic($root, [
        'track' => 'dawn-theme',
        'loop' => false,
        'completionBehavior' => 'stop',
    ]), ['mapId' => 'harbour', 'x' => 1, 'y' => 1, 'autoAdvance' => true]);

    try {
        $immediate->runToCompletion(30.0);
        expect($immediate->audioState()['track'])->toBeNull();
    } finally {
        $immediate->dispose();
    }
});
