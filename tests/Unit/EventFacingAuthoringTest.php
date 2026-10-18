<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * An event's optional player facing, authored a heading at a time through the
 * inspector both editors share: written to the map's own source in compass
 * order, removed when none is left, undone to exactly what was there, and
 * checked against the headings the Engine accepts. Synthetic maps only.
 */

function createFacingProject(?string $facingSource = null, string $class = 'ScriptEventTrigger'): string
{
    $root = makeTemporaryProject('editor-facing-');
    $facingField = $facingSource === null ? '' : "'facing' => $facingSource,";
    file_put_contents($root . '/assets/Maps/test-map/test-map.data.php', <<<PHP
    <?php
    // Unrelated authored source must survive facing edits.
    return [
      'name' => 'Facing fixture',
      'region' => '',
      'description' => strtoupper('kept'),
      'triggers' => [],
      'events' => [
        'E' => [
          'class' => 'Ichiloto\\Engine\\Events\\Triggers\\\\$class',
          'data' => ['mode' => 'action', 'reusable' => true, 'scriptId' => 'synthetic-note'],
          // The event's own comment.
          'conditions' => [],
          $facingField
        ],
      ],
    ];
    PHP);

    return $root;
}

function findFacingMap(string $root): ProjectMap
{
    return array_find(ProjectWorkspace::fromProject($root)->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map')
        ?? throw new RuntimeException('The synthetic facing map is missing.');
}

/** @return array<string, array<string, mixed>> The facing rows by heading. */
function listFacingRows(MapInspector $inspector, ProjectMap $map): array
{
    $rows = array_filter($inspector->getEventFields($map, 'E'), static fn(array $row): bool => is_string($row['facingHeading'] ?? null));

    return array_column($rows, null, 'facingHeading');
}

it('offers one choice per heading, none chosen, without writing anything for an event that faces any way', function (bool $graphical) {
    $root = createFacingProject();
    $hashes = sourceHashTree($root);
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = array_find($workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map');
    $rows = listFacingRows(new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical), $map);

    expect(array_keys($rows))->toBe(['North', 'East', 'South', 'West'])
        ->and(array_column($rows, 'value'))->toBe(['false', 'false', 'false', 'false'])
        ->and(array_column($rows, 'label'))->toBe(['Facing North', 'Facing East', 'Facing South', 'Facing West'])
        ->and($map->isDirty())->toBeFalse();
    $map->save();
    expect(sourceHashTree($root))->toBe($hashes);
})->with([false, true]);

it('writes chosen headings in compass order, keeps the source, and undoes to the event as it was', function (bool $graphical) {
    $root = createFacingProject();
    $hashes = sourceHashTree($root);
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = array_find($workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map');
    $inspector = new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical);
    $before = $map->getEventDefinition('E');

    $east = $inspector->apply($map, listFacingRows($inspector, $map)['East'], 'true');
    $north = $inspector->apply($map, listFacingRows($inspector, $map)['North'], 'true');
    $map->save();

    expect($map->getEventDefinition('E')['facing'])->toBe(['North', 'East'])
        ->and(findFacingMap($root)->getEventDefinition('E')['facing'])->toBe(['North', 'East'])
        ->and(file_get_contents($map->dataPath))->toContain('// The event\'s own comment.', "strtoupper('kept')")
        ->and(array_column(listFacingRows($inspector, $map), 'value'))->toBe(['true', 'true', 'false', 'false']);
    $north->undo();
    $east->undo();
    $map->save();
    expect(sourceHashTree($root))->toBe($hashes)
        ->and(findFacingMap($root)->getEventDefinition('E'))->toBe($before);
    $east->execute();
    $map->save();
    expect(findFacingMap($root)->getEventDefinition('E')['facing'])->toBe(['East']);
})->with([false, true]);

it('removes the facing once no heading is left, so the event starts whichever way the player faces', function () {
    $root = createFacingProject("['West']");
    $workspace = ProjectWorkspace::fromProject($root);
    $map = array_find($workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map');
    $inspector = new MapInspector(new ReferenceCatalog($workspace));

    $change = $inspector->apply($map, listFacingRows($inspector, $map)['West'], 'false');
    $map->save();

    expect(findFacingMap($root)->getEventDefinition('E'))->not->toHaveKey('facing');
    $change->undo();
    $map->save();
    expect(findFacingMap($root)->getEventDefinition('E')['facing'])->toBe(['West']);
});

it('names a facing the Engine would refuse instead of offering headings that would rewrite it', function () {
    $root = createFacingProject("['Up']");
    $workspace = ProjectWorkspace::fromProject($root);
    $map = array_find($workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map');
    $rows = array_values(array_filter((new MapInspector(new ReferenceCatalog($workspace)))->getEventFields($map, 'E'),
        static fn(array $row): bool => ($row['path'] ?? null) === ['facing'] || is_string($row['facingHeading'] ?? null)));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['editable'])->toBeFalse()
        ->and(implode("\n", array_map(static fn($issue): string => $issue->message, new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)))))
        ->toContain('Event facing accepts North, East, South and West only.');
});

it('accepts a facing on any trigger kind without calling it an unsupported field', function (string $class) {
    $root = createFacingProject("['North']", $class);
    $messages = array_map(static fn($issue): string => $issue->message, new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)));

    expect(array_filter($messages, static fn(string $message): bool => str_contains($message, 'facing')))->toBe([]);
})->with(['ScriptEventTrigger', 'SleepEventTrigger']);

it('serves the headings to a graphical editor as checkboxes it edits, saves and undoes through the session', function () {
    $root = createFacingProject();
    $mapPath = $root . '/assets/Maps/test-map/test-map.data.php';
    $original = file_get_contents($mapPath);
    $session = Ichiloto\Editor\Session\EditorSession::open($root);
    $view = $session->readInspector('test-map', 'E');
    $rows = array_values(array_filter($view['rows'], static fn(array $row): bool => ($row['key']['path'][0] ?? null) === 'facing'));

    expect(array_column($rows, 'label'))->toBe(['Facing North', 'Facing East', 'Facing South', 'Facing West'])
        ->and(array_unique(array_column($rows, 'kind')))->toBe(['boolean']);
    $session->applyInspector('test-map', $view['revision'], $rows[1]['key'], 'true');
    $session->saveMap('test-map');
    expect((require $mapPath)['events']['E']['facing'])->toBe(['East']);
    $session->undo();
    $session->saveMap('test-map');
    expect(file_get_contents($mapPath))->toBe($original);
});
