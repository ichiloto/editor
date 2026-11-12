<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneLaneOverview;
use Ichiloto\Editor\Cutscenes\CutsceneOutline;

/** A synthetic stage of the given length, followed by a synthetic power unlock showcase. */
function stageThenUnlocks(float $seconds): array
{
    return [
        ['type' => 'play_stage', 'stage' => 'test-stage-dim', 'seconds' => $seconds],
        ['type' => 'show_power_unlocks', 'abilities' => [['ability' => 'test-dash']]],
    ];
}

it('times a stage by its authored seconds and waits on power unlock notices', function () {
    $overview = CutsceneLaneOverview::of(stageThenUnlocks(2.0));

    expect(CutsceneLaneOverview::describe($overview->totalSeconds, $overview->totalMarks))->toBe('2.0s +input')
        ->and(array_column($overview->rows, 'label'))->toContain('play_stage  test-stage-dim 2s');
});

it('times a stage beside a shorter lane by the longer of the two', function () {
    $overview = CutsceneLaneOverview::of([
        ['type' => 'parallel', 'lanes' => [
            [['type' => 'play_stage', 'stage' => 'test-stage-dim', 'seconds' => 2.0]],
            [['type' => 'wait', 'seconds' => 1.0]],
        ]],
        ['type' => 'show_power_unlocks', 'abilities' => [['ability' => 'test-dash']]],
    ]);

    expect(CutsceneLaneOverview::describe($overview->totalSeconds, $overview->totalMarks))->toBe('2.0s +input')
        ->and(array_column($overview->rows, 'label'))->toContain('play_stage  test-stage-dim 2s');
});

it('reads an instant stage as taking no time, leaving only the notices to wait on', function () {
    $overview = CutsceneLaneOverview::of(stageThenUnlocks(0.0));

    expect(CutsceneLaneOverview::describe($overview->totalSeconds, $overview->totalMarks))->toBe('+input')
        ->and(CutsceneOutline::summarize(stageThenUnlocks(0.0)[0]))->toBe('play_stage  test-stage-dim 0s');
});

it('times a cinematic sound cue by its authored seconds beside a shorter stage', function () {
    $cue = ['type' => 'cinematic_sound', 'sound' => 'test-heartbeat', 'seconds' => 1.22, 'volume' => 0.8];
    $overview = CutsceneLaneOverview::of([
        ['type' => 'parallel', 'lanes' => [
            [$cue],
            [['type' => 'play_stage', 'stage' => 'test-stage-dim', 'seconds' => 1.0]],
        ]],
        ['type' => 'show_power_unlocks', 'abilities' => [['ability' => 'test-dash']]],
    ]);

    expect(CutsceneLaneOverview::describe($overview->totalSeconds, $overview->totalMarks))->toBe('1.22s +input')
        ->and(array_column($overview->rows, 'label'))->toContain('cinematic_sound  test-heartbeat 1.22s');
});

it('reads overview seconds to the hundredth with at least one decimal', function () {
    expect(array_map(static fn(float $seconds): string => CutsceneLaneOverview::describe($seconds, []), [2.0, 1.22, 0.45, 0.125, 0.0]))
        ->toBe(['2.0s', '1.22s', '0.45s', '0.13s', '0.0s'])
        ->and(CutsceneLaneOverview::describe(0.0, [CutsceneLaneOverview::INPUT]))->toBe('+input');
});
