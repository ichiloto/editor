<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * Where the player stands to start an event, chosen through the inspector both
 * editors share: on its cells, as events always have, or next to them facing one,
 * for an event placed on an object. Written to the map's own source, dropped
 * when it is the default, undone to exactly what was there, and checked against
 * what the Engine accepts. Synthetic maps only.
 */

function createEngagementProject(?string $engagementSource = null): string
{
    $root = makeTemporaryProject('editor-engagement-');
    $field = $engagementSource === null ? '' : "'engagement' => $engagementSource,";
    file_put_contents($root . '/assets/Maps/test-map/test-map.data.php', <<<PHP
    <?php
    // Unrelated authored source must survive engagement edits.
    return [
      'name' => 'Engagement fixture',
      'region' => '',
      'description' => strtoupper('kept'),
      'triggers' => [],
      'events' => [
        'R' => [
          'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
          'data' => ['mode' => 'action', 'reusable' => true, 'scriptId' => 'synthetic-records'],
          // The event's own comment.
          'facing' => ['North'],
          $field
        ],
      ],
    ];
    PHP);
    // The event layer places R, the event this fixture defines.
    $events = $root . '/assets/Maps/test-map/test-map.event.php';
    file_put_contents($events, str_replace('     E      ', '     R      ', (string) file_get_contents($events)));

    return $root;
}

function findEngagementMap(string $root): ProjectMap
{
    return array_find(ProjectWorkspace::fromProject($root)->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map')
        ?? throw new RuntimeException('The synthetic engagement map is missing.');
}

function getEngagementRow(MapInspector $inspector, ProjectMap $map): array
{
    return array_find($inspector->getEventFields($map, 'R'), static fn(array $row): bool => ($row['path'] ?? null) === ['engagement'])
        ?? throw new RuntimeException('The engagement choice is missing.');
}

it('offers standing on the event or facing it, showing the default without writing it', function (bool $graphical) {
    $root = createEngagementProject();
    $hashes = sourceHashTree($root);
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = array_find($workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map');
    $row = getEngagementRow(new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical), $map);

    expect($row['options'])->toBe(['inside', 'facing'])
        ->and($row['selectedValue'])->toBe('inside')
        ->and($row)->not->toHaveKey('control');
    $map->save();
    expect(sourceHashTree($root))->toBe($hashes);
})->with([false, true]);

it('writes facing to the source, keeps the event\'s headings, and undoes to the event as it was', function (bool $graphical) {
    $root = createEngagementProject();
    $hashes = sourceHashTree($root);
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = array_find($workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map');
    $inspector = new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical);
    $before = $map->getEventDefinition('R');

    $change = $inspector->apply($map, getEngagementRow($inspector, $map), 'facing');
    $map->save();

    expect(findEngagementMap($root)->getEventDefinition('R'))->toBe([...$before, 'engagement' => 'facing'])
        ->and(file_get_contents($map->dataPath))->toContain('// The event\'s own comment.', "strtoupper('kept')");
    $change->undo();
    $map->save();
    expect(sourceHashTree($root))->toBe($hashes);
    $change->execute();
    $map->save();
    expect(findEngagementMap($root)->getEventDefinition('R')['engagement'])->toBe('facing');
})->with([false, true]);

it('drops the key when the author goes back to standing on the event', function () {
    $root = createEngagementProject("'facing'");
    $workspace = ProjectWorkspace::fromProject($root);
    $map = array_find($workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map');
    $inspector = new MapInspector(new ReferenceCatalog($workspace));

    $change = $inspector->apply($map, getEngagementRow($inspector, $map), 'inside');
    $map->save();

    expect(findEngagementMap($root)->getEventDefinition('R'))->not->toHaveKey('engagement');
    $change->undo();
    $map->save();
    expect(findEngagementMap($root)->getEventDefinition('R')['engagement'])->toBe('facing');
});

it('names an engagement the Engine would refuse, and the validator reports it', function () {
    $root = createEngagementProject("'beside'");
    $workspace = ProjectWorkspace::fromProject($root);
    $map = array_find($workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map');
    $row = getEngagementRow(new MapInspector(new ReferenceCatalog($workspace)), $map);
    $messages = array_map(static fn($issue): string => $issue->message, new ProjectValidator()->validate($workspace));

    expect($row['editable'])->toBeFalse()
        ->and(implode("\n", $messages))->toContain('Event engagement must be inside or facing.')
        ->and(array_filter($messages, static fn(string $message): bool => str_contains($message, 'unsupported root field')))->toBe([]);
});

it('serves the choice to a graphical editor as options it edits and saves through the session', function () {
    $root = createEngagementProject();
    $mapPath = $root . '/assets/Maps/test-map/test-map.data.php';
    $original = file_get_contents($mapPath);
    $session = EditorSession::open($root);
    $view = $session->readInspector('test-map', 'R');
    $row = array_find($view['rows'], static fn(array $row): bool => ($row['key']['path'] ?? null) === ['engagement']);

    expect($row['kind'])->toBe('options')
        ->and($row['options'])->toBe(['inside', 'facing'])
        ->and($row['optionLabels'])->toBe(['Stand on it', 'Face it from next to it']);
    $session->applyInspector('test-map', $view['revision'], $row['key'], 'facing');
    $session->saveMap('test-map');
    expect((require $mapPath)['events']['R']['engagement'])->toBe('facing');
    $session->undo();
    $session->saveMap('test-map');
    expect(file_get_contents($mapPath))->toBe($original);
});
