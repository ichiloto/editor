<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneLaneOverview;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;

/**
 * A `field_animation` names either an effect timeline, which owns its frame
 * rate, or a legacy animation and its pace. Synthetic timelines only.
 */
function writeFieldEffectTimeline(string $root, string $id, string $playback = 'once', int $lengthFrames = 15): void
{
    $directory = $root . '/assets/Animations/' . $id;
    @mkdir($directory, 0o777, true);
    file_put_contents($directory . '/' . $id . '.timeline.php', '<?php return ' . var_export([
        'fps' => 10,
        'lengthFrames' => $lengthFrames,
        'playback' => $playback,
        'tracks' => [['id' => 'glyph', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => $lengthFrames, 'content' => '*']]]],
    ], true) . ';');
}

/** Replaces the harbour cinematic's title card with the given command. */
function writeHarbourCommand(string $root, array $command): void
{
    $script = str_replace(
        "['type' => 'title_card', 'title' => 'HARBOUR LANTERNS', 'seconds' => 0.2],",
        var_export($command, true) . ',',
        harbourCinematicScript(),
    );
    file_put_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php', $script);
}

/** Writes a Common Event script. */
function writeFieldEffectScript(string $root, string $id, array $commands): void
{
    file_put_contents($root . '/assets/Events/' . $id . '.php', "<?php\n\nreturn " . var_export($commands, true) . ";\n");
}

/** @return list<string> */
function fieldEffectIssues(string $root, string $where): array
{
    return array_values(array_map(
        static fn(Issue $issue): string => $issue->message,
        array_filter(
            new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
            static fn(Issue $issue): bool => str_contains($issue->where, $where),
        ),
    ));
}

it('offers the project\'s effect timelines by stable id', function () {
    $root = cutsceneProject();
    writeFieldEffectTimeline($root, 'lantern-glow');
    writeFieldEffectTimeline($root, 'ember');

    expect(new ReferenceCatalog(ProjectWorkspace::fromProject($root))->valuesFor('effects'))->toBe(['ember', 'lantern-glow']);
});

it('edits a field effect in place of a legacy animation and its pace, and back', function () {
    $root = cutsceneProject();
    writeFieldEffectTimeline($root, 'lantern-glow');
    writeHarbourCommand($root, [
        'type' => 'field_animation',
        'animation' => 'Slash',
        'secondsPerFrame' => 0.05,
        'target' => ['kind' => 'staged_actor', 'id' => 'boat-east'],
        'authoring' => ['note' => 'kept'],
    ]);
    $editor = cutscenesEditor($root);
    openCutsceneFrame($editor, 'commandListCommands');

    expect(cutsceneFieldIds($editor))->toContain('command4Effect', 'command4Animation', 'command4SecondsPerFrame');

    setCutsceneField($editor, 'command4Effect', 'lantern-glow');
    $command = libraryOf($editor)->find(CutsceneType::CINEMATIC, 'harbour-lanterns')?->commands()[4] ?? [];

    expect($command)->toBe([
        'type' => 'field_animation',
        'target' => ['kind' => 'staged_actor', 'id' => 'boat-east'],
        'authoring' => ['note' => 'kept'],
        'effect' => 'lantern-glow',
    ])
        ->and(cutsceneFieldIds($editor))->toContain('command4Effect')
        ->and(cutsceneFieldIds($editor))->not->toContain('command4Animation')
        ->and(cutsceneFieldIds($editor))->not->toContain('command4SecondsPerFrame');

    // Clearing the effect returns the command to a legacy animation.
    setCutsceneField($editor, 'command4Effect', '');
    setCutsceneField($editor, 'command4Animation', 'Slash');

    expect(libraryOf($editor)->find(CutsceneType::CINEMATIC, 'harbour-lanterns')?->commands()[4] ?? [])->toBe([
        'type' => 'field_animation',
        'target' => ['kind' => 'staged_actor', 'id' => 'boat-east'],
        'authoring' => ['note' => 'kept'],
        'animation' => 'Slash',
    ]);
});

it('reports a field effect that is missing, loops, or is mixed with a legacy pace', function () {
    $root = cutsceneProject();
    writeFieldEffectTimeline($root, 'tide', 'loop');
    writeHarbourCommand($root, ['type' => 'parallel', 'lanes' => [
        ['id' => 'missing', 'commands' => [['type' => 'field_animation', 'effect' => 'nowhere', 'target' => ['kind' => 'staged_actor', 'id' => 'boat-east']]]],
        ['id' => 'loops', 'commands' => [['type' => 'field_animation', 'effect' => 'tide', 'target' => ['kind' => 'staged_actor', 'id' => 'boat-west']]]],
    ]]);
    writeFieldEffectScript($root, 'mixed-pace', [
        ['type' => 'field_animation', 'effect' => 'tide', 'secondsPerFrame' => 0.1, 'target' => ['kind' => 'player']],
    ]);

    $cinematic = fieldEffectIssues($root, 'cinematic harbour-lanterns');

    expect($cinematic)->toContain(
        'It names the effect "nowhere", which does not exist.',
        'Its effect tide loops in the terminal presentation, and a field_animation waits for its effect to end.',
    )
        ->and(implode("\n", fieldEffectIssues($root, 'mixed-pace')))->toContain('Field effect requires one stable timeline identity and uses its authored frame rate');
});

it('accepts a once-played field effect in cinematics and event scripts', function () {
    $root = cutsceneProject();
    writeFieldEffectTimeline($root, 'lantern-glow');
    writeHarbourCommand($root, ['type' => 'field_animation', 'effect' => 'lantern-glow', 'target' => ['kind' => 'staged_actor', 'id' => 'boat-east']]);
    writeFieldEffectScript($root, 'glow', [['type' => 'field_animation', 'effect' => 'lantern-glow', 'target' => ['kind' => 'player']]]);

    expect(fieldEffectIssues($root, 'harbour-lanterns'))->toBe([])
        ->and(fieldEffectIssues($root, 'glow'))->toBe([]);
});

it('times a field effect by its timeline and marks one it cannot read', function () {
    $root = cutsceneProject();
    writeFieldEffectTimeline($root, 'lantern-glow', lengthFrames: 15);
    $effects = new EffectTimelineLibrary($root . '/assets');
    $glow = ['type' => 'field_animation', 'effect' => 'lantern-glow', 'target' => ['kind' => 'player']];
    $missing = ['type' => 'field_animation', 'effect' => 'nowhere', 'target' => ['kind' => 'player']];

    expect(CutsceneLaneOverview::estimate($glow, $effects))->toBe([1.5, []])
        ->and(CutsceneLaneOverview::estimate($missing, $effects))->toBe([0.0, [CutsceneLaneOverview::UNKNOWN]])
        ->and(CutsceneLaneOverview::estimate($glow))->toBe([0.0, [CutsceneLaneOverview::UNKNOWN]]);
});
