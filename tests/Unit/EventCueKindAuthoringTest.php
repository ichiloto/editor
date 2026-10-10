<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Inspector\InspectorRefusal;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Events\Triggers\EventCue;
use Ichiloto\Engine\Events\Triggers\EventCueKind;

function createCueKindProject(?string $cueSource = null): string
{
    $root = makeTemporaryProject('editor-cue-kind-');
    $cueField = $cueSource === null ? '' : "'cue' => $cueSource,";
    file_put_contents($root . '/assets/Maps/test-map/test-map.data.php', <<<PHP
    <?php
    // Unrelated authored source must survive cue edits.
    return [
      'name' => 'Cue fixture',
      'region' => '',
      'description' => strtoupper('kept'),
      'triggers' => [],
      'events' => [
        'E' => [
          'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ChestEventTrigger',
          'data' => ['lootType' => 'gold', 'loot' => 2],
          'conditions' => [],
          $cueField
        ],
      ],
    ];
    PHP);

    return $root;
}

function getCueKindFixtureSource(array $extra = []): string
{
    return "[\n        // Independently authored cue presentation.\n        'symbol' => '!',\n        'color' => 'bright-blue',\n        'conditions' => [['type' => 'event', 'name' => 'cue_ready']],\n"
        . implode('', array_map(static fn(string $key, mixed $value): string => '        ' . var_export($key, true) . ' => ' . var_export($value, true) . ",\n", array_keys($extra), array_values($extra)))
        . '      ]';
}

function findCueKindMap(ProjectWorkspace $workspace): ProjectMap
{
    return array_find($workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map')
        ?? throw new RuntimeException('The synthetic cue map is missing.');
}

function getCueKindRow(MapInspector $inspector, ProjectMap $map): array
{
    return array_find($inspector->getEventFields($map, 'E'), static fn(array $row): bool => ($row['path'] ?? null) === ['cue', 'kind'])
        ?? throw new RuntimeException('The cue kind picker is missing.');
}

it('describes an optional constrained cue kind without classifying legacy cues or writing defaults', function (bool $graphical, ?string $kind, bool $hasCue) {
    $root = createCueKindProject($hasCue ? getCueKindFixtureSource($kind === null ? [] : ['kind' => $kind]) : null);
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = findCueKindMap($workspace);
    $before = $map->getEventDefinition('E');
    $hashes = sourceHashTree($root);
    $row = getCueKindRow(new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical), $map);

    expect(array_column($row['choices'], 'value'))->toBe(['', EventCueKind::STORY->value, EventCueKind::ROUTE->value])
        ->and($row['options'])->toBe(['', 'story', 'route'])
        ->and($row['optionLabels'])->toBe(['' => 'Unclassified (clear kind)', 'story' => 'Story', 'route' => 'Route'])
        ->and($row['selectedValue'])->toBe($kind ?? '')
        ->and($row['optional'])->toBeTrue()
        ->and($row)->not->toHaveKey('control')
        ->and($map->getEventDefinition('E'))->toBe($before)
        ->and($map->isDirty())->toBeFalse();
    $map->save();
    expect(sourceHashTree($root))->toBe($hashes);
})->with([false, true])->with([
    'no cue' => [null, false],
    'legacy blue cue' => [null, true],
    'story' => ['story', true],
    'route' => ['route', true],
]);

it('authors each engine cue kind with source-preserving save undo redo and reload', function (bool $graphical, string $kind) {
    $root = createCueKindProject(getCueKindFixtureSource());
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = findCueKindMap($workspace);
    $inspector = new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical);
    $before = $map->getEventDefinition('E');
    $hashes = sourceHashTree($root);
    $change = $inspector->apply($map, getCueKindRow($inspector, $map), $kind);
    $expected = $before;
    $expected['cue']['kind'] = $kind;

    expect($change)->not->toBeNull()
        ->and($map->getEventDefinition('E'))->toBe($expected)
        ->and(sourceHashTree($root))->toBe($hashes);
    $map->save();
    expect(findCueKindMap(ProjectWorkspace::fromProject($root))->getEventDefinition('E'))->toBe($expected)
        ->and(file_get_contents($map->dataPath))->toContain('// Independently authored cue presentation.', "strtoupper('kept')")
        ->and(array_keys(array_diff_assoc(sourceHashTree($root), $hashes)))->toBe(['assets/Maps/test-map/test-map.data.php']);
    $change->undo();
    $map->save();
    expect(sourceHashTree($root))->toBe($hashes)
        ->and(findCueKindMap(ProjectWorkspace::fromProject($root))->getEventDefinition('E'))->toBe($before);
    $change->execute();
    $map->save();
    expect(findCueKindMap(ProjectWorkspace::fromProject($root))->getEventDefinition('E'))->toBe($expected);
})->with([false, true])->with(['story', 'route']);

it('clears only cue kind while retaining symbol color conditions and unrelated source', function (bool $graphical, string $kind) {
    $root = createCueKindProject(getCueKindFixtureSource(['kind' => $kind]));
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = findCueKindMap($workspace);
    $inspector = new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical);
    $before = $map->getEventDefinition('E');
    $hashes = sourceHashTree($root);
    $expected = $before;
    unset($expected['cue']['kind']);
    $change = $inspector->apply($map, getCueKindRow($inspector, $map), '');

    expect($change)->not->toBeNull()
        ->and($map->getEventDefinition('E'))->toBe($expected)
        ->and(EventCue::fromArray($expected['cue'])->kind)->toBeNull();
    $map->save();
    expect(findCueKindMap(ProjectWorkspace::fromProject($root))->getEventDefinition('E'))->toBe($expected)
        ->and(file_get_contents($map->dataPath))->toContain('// Independently authored cue presentation.', "strtoupper('kept')");
    $change->undo();
    $map->save();
    expect(sourceHashTree($root))->toBe($hashes);
    $change->execute();
    $map->save();
    expect(findCueKindMap(ProjectWorkspace::fromProject($root))->getEventDefinition('E'))->toBe($expected);
})->with([false, true])->with(['story', 'route']);

it('keeps clearing omitted kind a no-op and does not materialize cue defaults on authoring', function (bool $graphical) {
    $root = createCueKindProject();
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = findCueKindMap($workspace);
    $inspector = new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical);
    $hashes = sourceHashTree($root);
    $row = getCueKindRow($inspector, $map);

    expect($inspector->apply($map, $row, ''))->toBeNull()
        ->and($map->isDirty())->toBeFalse()
        ->and(sourceHashTree($root))->toBe($hashes);
    $change = $inspector->apply($map, $row, 'route');
    expect($map->getEventField('E', ['cue']))->toBe(['kind' => 'route']);
    $map->save();
    expect(findCueKindMap(ProjectWorkspace::fromProject($root))->getEventField('E', ['cue']))->toBe(['kind' => 'route']);
    $change->undo();
    $map->save();
    expect(sourceHashTree($root))->toBe($hashes);
})->with([false, true]);

it('refuses unknown picker values before changing memory source or dirty state', function (bool $graphical, string $kind) {
    $root = createCueKindProject(getCueKindFixtureSource());
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = findCueKindMap($workspace);
    $inspector = new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical);
    $before = $map->getEventDefinition('E');
    $hashes = sourceHashTree($root);

    expect(fn() => $inspector->apply($map, getCueKindRow($inspector, $map), $kind))->toThrow(InspectorRefusal::class)
        ->and($map->getEventDefinition('E'))->toBe($before)
        ->and($map->isDirty())->toBeFalse()
        ->and(sourceHashTree($root))->toBe($hashes);
})->with([false, true])->with(['unknown', 'STORY', ' route ', 'null', '[]']);

it('refuses changing expression-authored cue kind before mutation', function (bool $graphical) {
    $root = createCueKindProject("['symbol' => '!', 'kind' => strtolower('STORY')]");
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = findCueKindMap($workspace);
    $inspector = new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical);
    $before = $map->getEventDefinition('E');
    $hashes = sourceHashTree($root);

    expect(fn() => $inspector->apply($map, getCueKindRow($inspector, $map), 'route'))->toThrow(MapSourceRefusal::class)
        ->and($map->getEventDefinition('E'))->toBe($before)
        ->and($map->isDirty())->toBeFalse()
        ->and(sourceHashTree($root))->toBe($hashes);
})->with([false, true]);

it('clears expression-authored cue kind with byte-restoring undo after save reload and redo', function (bool $graphical) {
    $root = createCueKindProject("['symbol' => '!', 'kind' => strtolower('STORY')]");
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = findCueKindMap($workspace);
    $inspector = new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical);
    $before = $map->getEventDefinition('E');
    $source = file_get_contents($map->dataPath);
    $hashes = sourceHashTree($root);
    $expected = $before;
    unset($expected['cue']['kind']);
    $change = $inspector->apply($map, getCueKindRow($inspector, $map), '');

    expect($change)->not->toBeNull()
        ->and($map->getEventDefinition('E'))->toBe($expected)
        ->and(sourceHashTree($root))->toBe($hashes);
    $map->save();
    $clearedSource = file_get_contents($map->dataPath);
    expect($clearedSource)->not->toContain("strtolower('STORY')")
        ->and(findCueKindMap(ProjectWorkspace::fromProject($root))->getEventDefinition('E'))->toBe($expected);
    $change->undo();
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe($source)
        ->and(sourceHashTree($root))->toBe($hashes)
        ->and(findCueKindMap(ProjectWorkspace::fromProject($root))->getEventDefinition('E'))->toBe($before);
    $change->execute();
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe($clearedSource)
        ->and(findCueKindMap(ProjectWorkspace::fromProject($root))->getEventDefinition('E'))->toBe($expected);
})->with([false, true]);

it('offers one constrained repair row for malformed authored kinds without flattening them into writable subfields', function (bool $graphical, mixed $kind) {
    $root = createCueKindProject(getCueKindFixtureSource(['kind' => $kind]));
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $map = findCueKindMap($workspace);
    $inspector = new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical);
    $hashes = sourceHashTree($root);
    $before = $map->getEventDefinition('E');
    $rows = array_values(array_filter($inspector->getEventFields($map, 'E'), static fn(array $row): bool => array_slice($row['path'] ?? [], 0, 2) === ['cue', 'kind']));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['path'])->toBe(['cue', 'kind'])
        ->and($rows[0]['value'])->not->toBe('')
        ->and($rows[0])->not->toHaveKey('control')
        ->and($map->getEventDefinition('E'))->toBe($before)
        ->and($map->isDirty())->toBeFalse()
        ->and(sourceHashTree($root))->toBe($hashes);
    $clear = $inspector->apply($map, $rows[0], '');
    $expected = $before;
    unset($expected['cue']['kind']);
    expect($map->getEventDefinition('E'))->toBe($expected);
    $clear->undo();
    expect($map->getEventDefinition('E'))->toBe($before);
})->with([false, true])->with([
    'null' => [null], 'array' => [['story']], 'boolean' => [false], 'integer' => [1],
]);

it('accepts omitted story and route kinds through global project validation', function (?string $kind, string $symbol) {
    $cue = ['symbol' => $symbol, 'color' => 'bright-blue'];
    if ($kind !== null) { $cue['kind'] = $kind; }
    $root = createCueKindProject(var_export($cue, true));
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));

    expect(issuesMentioning($issues, 'event cue'))->toBe([]);
    if ($symbol !== '') {
        expect(EventCue::fromArray($cue)->kind?->value)->toBe($kind);
    }
})->with([null, 'story', 'route'])->with(['!', '']);

it('rejects every invalid authored kind globally even when the cue symbol is blank', function (mixed $kind, string $symbol) {
    $cue = ['symbol' => $symbol, 'kind' => $kind];
    $root = createCueKindProject(var_export($cue, true));
    $hashes = sourceHashTree($root);
    $issues = issuesMentioning(new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)), 'event cue kind');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->where)->toBe('test-map event E')
        ->and(fn() => EventCue::fromArray($cue))->toThrow(InvalidArgumentException::class)
        ->and(sourceHashTree($root))->toBe($hashes);
})->with([
    'unknown' => ['unknown'], 'empty' => [''], 'whitespace' => [' story '],
    'null' => [null], 'integer' => [1], 'float' => [1.5], 'boolean' => [false],
    'array' => [['story']], 'object' => [(object) ['kind' => 'story']],
])->with(['!', '']);
