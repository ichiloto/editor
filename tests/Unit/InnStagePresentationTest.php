<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\ProjectConfig;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * An inn's rest stage is a timeline the Engine admits as a stage of its own,
 * named by a Sleep event, an `inn` command or config's default, and chosen
 * from those timelines only. Synthetic timelines and art only.
 */

/**
 * The sample project with a rest stage timeline, a flicker the field plays
 * (which is no stage), and an inn bed on the test map.
 */
function innStageProject(): string
{
    $root = makeTemporaryProject('editor-inn-stage-');
    @mkdir($root . '/assets/Graphics/Rest', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Rest/sleeper.png', 32, 16);
    @mkdir($root . '/assets/Animations/quiet-night', 0o777, true);
    file_put_contents($root . '/assets/Animations/quiet-night/quiet-night.timeline.php', '<?php return ' . var_export([
        'fps' => 12, 'lengthFrames' => 6, 'restFrame' => 2,
        'stage' => ['canvas' => ['width' => 320, 'height' => 180], 'startFrame' => 0, 'restoreFrame' => 5,
            'camera' => [['id' => 'hold', 'frame' => 0, 'focus' => ['x' => 160, 'y' => 90], 'zoom' => 1, 'easing' => 'hold']],
            'subjects' => [['id' => 'sleeper', 'position' => ['x' => 160, 'y' => 150], 'size' => ['width' => 64, 'height' => 96]]]],
        'tracks' => [['id' => 'sleeper', 'type' => 'image', 'asset' => 'Graphics/Rest/sleeper.png', 'sheet' => ['columns' => 2, 'rows' => 1],
            'anchor' => 'stage', 'placement' => ['subject' => 'sleeper'], 'pivot' => ['x' => .5, 'y' => 1],
            'keyframes' => [['frame' => 0, 'duration' => 3], ['frame' => 3, 'duration' => 2, 'sourceFrame' => 1]]]],
    ], true) . ';');
    @mkdir($root . '/assets/Animations/flicker', 0o777, true);
    file_put_contents($root . '/assets/Animations/flicker/flicker.timeline.php', '<?php return ' . var_export([
        'fps' => 10, 'lengthFrames' => 4,
        'tracks' => [['id' => 'glyph', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 4, 'content' => '*']]]],
    ], true) . ';');
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("  'events' => [\n", "  'events' => [\n    'S' => [\n      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\SleepEventTrigger',\n      'data' => ['confirmDialogue' => ['name' => '', 'text' => 'Rest here?'], 'cost' => 5],\n    ],\n",
        (string) file_get_contents($data)));

    return $root;
}

function innBedMap(ProjectWorkspace $workspace): ProjectMap
{
    return array_find($workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map');
}

/** @return array<string, mixed> The bed's data row at a path. */
function innBedField(MapInspector $inspector, ProjectMap $map, array $path): array
{
    return array_find($inspector->buildEventDataFields('S', $map->getEventDefinition('S') ?? []),
        static fn(array $field): bool => ($field['path'] ?? null) === $path) ?? [];
}

it('offers only the timelines the Engine admits as a stage', function () {
    $catalog = new ReferenceCatalog(ProjectWorkspace::fromProject(innStageProject()));

    expect($catalog->valuesFor('stage_timelines'))->toBe(['quiet-night'])
        ->and($catalog->valuesFor('effects'))->toBe(['flicker', 'quiet-night']);
});

it('offers a Sleep event\'s rest music and stage unset, writes one only when chosen, and removes it when cleared', function () {
    $root = innStageProject();
    $workspace = ProjectWorkspace::fromProject($root);
    $map = innBedMap($workspace);
    $inspector = new MapInspector(new ReferenceCatalog($workspace));

    $stage = innBedField($inspector, $map, ['data', 'presentation']);
    expect($stage)->toMatchArray(['reference' => 'stage_timelines', 'optional' => true, 'value' => ''])
        ->and(innBedField($inspector, $map, ['data', 'bgm']))->toMatchArray(['reference' => 'bgm', 'optional' => true])
        // Offered, not stored: the event holds only what was authored.
        ->and(array_keys($map->getEventDefinition('S')['data']))->toBe(['confirmDialogue', 'cost']);

    $chosen = $inspector->apply($map, $stage, 'quiet-night');
    expect($map->getEventDefinition('S')['data']['presentation'])->toBe('quiet-night');
    $chosen->undo();
    expect($map->getEventDefinition('S')['data'])->not->toHaveKey('presentation');
    $chosen->execute();

    $cleared = $inspector->apply($map, innBedField($inspector, $map, ['data', 'presentation']), '');
    expect($map->getEventDefinition('S')['data'])->not->toHaveKey('presentation');
    $cleared->undo();
    expect($map->getEventDefinition('S')['data']['presentation'])->toBe('quiet-night');
    $cleared->execute();
    // Clearing what is already unset changes nothing.
    expect($inspector->apply($map, innBedField($inspector, $map, ['data', 'presentation']), ''))->toBeNull();

    $map->save();
    expect((require $root . '/assets/Maps/test-map/test-map.data.php')['events']['S']['data'])
        ->toBe(['confirmDialogue' => ['name' => '', 'text' => 'Rest here?'], 'cost' => 5]);
});

it('chooses the project\'s default rest stage in Configuration, writing nothing when left unset', function () {
    $root = innStageProject();
    $workspace = ProjectWorkspace::fromProject($root);
    $database = $workspace->getRecordDatabase('configuration');
    $index = array_search(ProjectConfig::INN_PRESENTATION, $database->getEntryLabels(), true);
    $value = array_find($database->getSettingsFields($index), static fn(array $field): bool => ($field['field'] ?? null) === 'value');
    $before = (string) file_get_contents($root . '/config.php');

    expect($value['reference'] ?? null)->toBe('stage_timelines');
    $database->setField($index, 'value', 'None');
    expect($workspace->config->isDirty())->toBeFalse();

    $database->setField($index, 'value', 'quiet-night');
    $workspace->config->save();
    expect((require $root . '/config.php')['graphics']['inn']['presentation'])->toBe('quiet-night')
        ->and(fn() => $database->setField($index, 'value', 'Not An Id'))->toThrow(InvalidArgumentException::class);
    expect(str_starts_with((string) file_get_contents($root . '/config.php'), substr($before, 0, 20)))->toBeTrue();
});

it('reports a rest stage the Engine would not admit, wherever it is named', function () {
    $root = innStageProject();
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("'cost' => 5]", "'cost' => 5, 'presentation' => 'flicker']", (string) file_get_contents($data)));
    file_put_contents($root . '/assets/Events/inn-keeper.php', "<?php\n\nreturn " . var_export([
        ['type' => 'if', 'condition' => ['switch' => 'late'], 'then' => [
            ['type' => 'inn', 'confirmDialogue' => ['text' => 'A bed?'], 'presentation' => 'flicker'],
        ]],
    ], true) . ";\n");
    $stages = static fn(): array => array_values(array_map(static fn(Issue $issue): string => $issue->where . ': ' . $issue->message . ' ' . $issue->hint,
        array_filter(new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
            static fn(Issue $issue): bool => str_contains($issue->message, 'as a stage'))));

    // One report per timeline, naming its first use and counting the nested inn command's.
    expect($stages())->toHaveCount(1)
        ->and($stages()[0])->toStartWith('map test-map event S: Effect flicker cannot be played as a stage')
        ->toContain('Also used by 1 more.');

    // An admitted stage named anywhere is not reported.
    file_put_contents($data, str_replace("'presentation' => 'flicker'", "'presentation' => 'quiet-night'", (string) file_get_contents($data)));
    file_put_contents($root . '/assets/Events/inn-keeper.php', str_replace("'flicker'", "'quiet-night'", (string) file_get_contents($root . '/assets/Events/inn-keeper.php')));
    expect($stages())->toBe([]);
});
