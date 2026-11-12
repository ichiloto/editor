<?php

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\Field\MapLandmarks;
use Ichiloto\Editor\Inspector\InspectorRefusal;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Events\Triggers\PassageEventTrigger;

function createPassageAuthoringProject(): string
{
  $root = makeTemporaryProject('editor-passage-');
  $definition = EventTypeCatalog::findByClass(PassageEventTrigger::class)->buildDefinition(null);
  $occupancy = array_fill(0, 5, array_fill(0, 12, \Ichiloto\Engine\Events\Enumerations\CollisionType::NONE));
  file_put_contents($root . '/assets/Maps/test-map/test-map.data.php', '<?php
  // Preserve this authored expression and comment.
  return ["name" => strtoupper("passage"), "occupancy" => ' . var_export($occupancy, true)
    . ', "events" => ["E" => ' . var_export($definition, true) . ']];');
  file_put_contents($root . '/assets/Maps/test-map/test-map.map.php', "<?php return <<<'MAP'\n############\n#          #\n#          #\n#          #\n############\nMAP;\n");
  return $root;
}

function findPassageAuthoringMap(ProjectWorkspace $workspace): ProjectMap
{
  return array_find($workspace->maps, fn(ProjectMap $map) => $map->mapId === 'test-map');
}

it('offers an opening passage and saves proximity edits through the shared inspector with exact undo', function (bool $graphical) {
  $root = createPassageAuthoringProject();
  $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
  $map = findPassageAuthoringMap($workspace);
  $inspector = new MapInspector(new ReferenceCatalog($workspace), graphical: $graphical);
  $row = array_find($inspector->getEventFields($map, 'E'), fn($row) => ($row['path'] ?? []) === ['cue', 'maxDistance']);
  $before = sourceHashTree($root);
  $edit = $inspector->apply($map, $row, '0');
  expect($map->getEventDefinition('E')['cue']['maxDistance'])->toBe(0);
  $map->save();
  $reloaded = findPassageAuthoringMap(ProjectWorkspace::fromProject($root));
  expect($reloaded->getEventDefinition('E')['cue']['maxDistance'])->toBe(0)
    ->and(file_get_contents($map->dataPath))->toContain('strtoupper("passage")', '// Preserve this authored expression');
  $edit->undo(); $map->save();
  expect(sourceHashTree($root))->toBe($before);
  $edit->execute();
  $clear = $inspector->apply($map, $row, '');
  expect($map->getEventDefinition('E')['cue'])->not->toHaveKey('maxDistance');
  $clear->undo();
  expect($map->getEventDefinition('E')['cue']['maxDistance'])->toBe(0);
  foreach (['-1', '65', '1.5', 'nearby'] as $invalid) {
    expect(fn() => $inspector->apply($map, $row, $invalid))->toThrow(InspectorRefusal::class);
  }
})->with([false, true]);

it('validates passage identity and interaction and offers a passage landmark without a transfer', function () {
  $root = createPassageAuthoringProject();
  $workspace = ProjectWorkspace::fromProject($root);
  $map = findPassageAuthoringMap($workspace);
  $messages = array_map(fn($issue) => $issue->message, new ProjectValidator()->validate($workspace));
  expect(array_filter($messages, fn($message) => str_contains($message, 'no scriptId') || str_contains($message, 'opening passage')))->toBe([]);
  $landmarks = MapLandmarks::readMap($map);
  expect(array_map(fn($kind) => $kind->value, $landmarks->findKindsFor(['event' => 'E'])))->toBe(['passage', 'entrance', 'exit']);
  $map->setEventField('E', ['data', 'reusable'], true);
  $messages = array_map(fn($issue) => $issue->message, new ProjectValidator()->validate($workspace));
  expect(array_filter($messages, fn($message) => str_contains($message, 'reusable false')))->not->toBeEmpty();
});

it('preserves passage access ownership when an author tries to adopt its script onto an NPC', function () {
  $root = createPassageAuthoringProject();
  $map = findPassageAuthoringMap(ProjectWorkspace::fromProject($root));
  $before = sourceHashTree($root);
  expect(fn() => \Ichiloto\Editor\Field\NpcEventAdoption::plan($map, 'E', 'guide'))
    ->toThrow(\Ichiloto\Editor\Field\NpcRefusal::class, 'owns physical access')
    ->and(sourceHashTree($root))->toBe($before);
});
