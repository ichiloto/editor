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
