<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Database\RelativePositionFields;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;

/** A row of one entry of the open frame, by its label; entry [i] is a command, [i, j] one of its points. */
function relativeRow(EditorSession $session, array $frame, array $entry, string $name, string $category = 'cutscenes/cinematic', int $index = 0): array
{
    return array_find($session->readDatabaseRecord($category, $index, $frame)['rows'],
        static fn(array $row): bool => ($row['entry'] ?? []) === $entry && ($row['name'] ?? '') === $name)
        ?? throw new RuntimeException(sprintf('Missing row %s at %s', $name, json_encode($entry)));
}

/** A synthetic cinematic on the harbour map whose script is the given commands, with a comment to preserve. */
function writeRelativeScript(string $root, array $commands): string
{
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, "<?php\n// Staging authored by hand.\nreturn " . var_export($commands, true) . ";\n");

    return $path;
}

it('authors a relative waypoint through the session and keeps sparse absolute waypoints working', function () {
    $root = cutsceneProject();
    $path = writeRelativeScript($root, [['type' => 'move_route', 'subject' => 'player', 'secondsPerStep' => 0.2,
        'waypoints' => [['x' => 2], ['y' => 3]]]]);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $frame = ['commands'];

    $kind = relativeRow($session, $frame, [0, 1], 'Relative To');
    expect($kind['value'])->toBe('(map cell)')
        ->and($kind['options'])->toBe(['', ...CinematicCommandSchema::export()['relativePosition']['subjectKinds']]);
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $kind['key'], 'npc');
    $id = relativeRow($session, $frame, [0, 1], 'NPC');
    expect($id['reference'])->toBe('map_npcs')
        ->and(array_column($session->listReferences(null, 'map_npcs', ['category' => 'cutscenes/cinematic', 'index' => 0]), 'value'))->toBe(['keeper']);
    // A subject still waiting for its id is a draft the save completes, never written.
    expect(fn() => $session->saveDatabase('cutscenes/cinematic'))->toThrow(InvalidArgumentException::class, 'requires an id')
        ->and(file_get_contents($path))->toBe($original);

    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $id['key'], 'keeper');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0, 1], 'Offset X')['key'], '1');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0, 1], 'Offset Y')['key'], '-2');
    $session->saveDatabase('cutscenes/cinematic');

    expect((require $path)[0]['waypoints'])->toBe([['x' => 2], ['at' => ['kind' => 'npc', 'id' => 'keeper'], 'offset' => ['x' => 1, 'y' => -2]]])
        ->and(file_get_contents($path))->toContain('// Staging authored by hand.');
    $reopened = EditorSession::open($root);
    expect(relativeRow($reopened, $frame, [0, 1], 'NPC')['value'])->toBe('keeper')
        ->and(relativeRow($reopened, $frame, [0, 1], 'Offset Y')['value'])->toBe('-2')
        ->and(array_filter($reopened->readDatabaseRecord('cutscenes/cinematic', 0, $frame)['rows'],
            static fn(array $row): bool => ($row['entry'] ?? []) === [0, 1] && in_array($row['name'] ?? '', ['X', 'Y'], true)))->toBe([]);

    // A zero offset is the default: clearing both axes leaves the subject alone, never an empty offset.
    foreach (['Offset X', 'Offset Y'] as $axis) {
        $reopened->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($reopened, $frame, [0, 1], $axis)['key'], '0');
    }
    $reopened->saveDatabase('cutscenes/cinematic');
    expect((require $path)[0]['waypoints'][1])->toBe(['at' => ['kind' => 'npc', 'id' => 'keeper']]);

    // Undo returns through every step to the authored absolute route.
    foreach (range(1, 2) as $ignored) { $reopened->undo(); }
    $reopened->saveDatabase('cutscenes/cinematic');
    expect((require $path)[0]['waypoints'][1])->toBe(['at' => ['kind' => 'npc', 'id' => 'keeper'], 'offset' => ['x' => 1, 'y' => -2]]);
});

it('refuses relative waypoints the Engine would refuse and clears coordinates or ids a subject replaces', function () {
    $root = cutsceneProject();
    $path = writeRelativeScript($root, [['type' => 'move_route', 'subject' => 'player',
        'waypoints' => [['at' => ['kind' => 'npc', 'id' => 'keeper']], ['x' => 3, 'y' => 1]]]]);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $frame = ['commands'];

    expect(fn() => $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0, 0], 'Offset X')['key'], '16385'))
        ->toThrow(SessionRefusal::class, '-16384..16384')
        // Clearing the subject would leave a waypoint with no coordinate; it is refused, not invented.
        ->and(fn() => $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0, 0], 'Relative To')['key'], ''))
        ->toThrow(SessionRefusal::class, 'x and/or y')
        ->and($session->undo()['label'])->toBeNull();

    // Choosing a subject for an absolute waypoint drops its independent coordinates; the player needs no id.
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0, 1], 'Relative To')['key'], 'player');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0, 1], 'Offset Y')['key'], '2');
    // Turning the first point toward the player drops the id the player does not take.
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0, 0], 'Relative To')['key'], 'player');
    $session->saveDatabase('cutscenes/cinematic');
    expect((require $path)[0]['waypoints'])->toBe([['at' => ['kind' => 'player']], ['at' => ['kind' => 'player'], 'offset' => ['y' => 2]]]);
    $session->undo();
    $session->undo();
    $session->undo();
    $session->saveDatabase('cutscenes/cinematic');
    expect(require $path)->toBe(eval('?>' . $original));
});

it('offers only the subject kinds each owner can resolve, from the Engine contract', function () {
    $exported = CinematicCommandSchema::export()['relativePosition'];
    expect($exported['fields'])->toBe(['at', 'offset'])
        ->and(RelativePositionFields::getSubjectKinds(cinematic: true, graphical: true))->toBe($exported['subjectKinds'])
        ->and(RelativePositionFields::getSubjectKinds(cinematic: false, graphical: true))->not->toContain('staged_actor')
        ->and(RelativePositionFields::getSubjectKinds(cinematic: true, graphical: false))->not->toContain('world_object');
    $fields = static fn(array $entry, bool $graphical): array => array_map(static fn($field): string => $field->key,
        CutsceneSchemas::stagedActorFields($graphical, $entry));
    $independent = ['id' => 'a', 'at' => ['kind' => 'npc', 'id' => 'keeper']];
    $bound = ['id' => 'a', 'subject' => ['kind' => 'player']];
    expect($fields($independent, false))->toContain('at.kind', 'at.id', 'offset.x', 'offset.y')->not->toContain('x', 'y')
        ->and($fields(['id' => 'a', 'x' => 1, 'y' => 1], false))->toContain('at.kind', 'x', 'y')->not->toContain('offset.x')
        ->and($fields($bound, true))->not->toContain('at.kind', 'offset.x', 'x', 'y')
        ->and($fields($bound, false))->not->toContain('at.kind', 'offset.x');
});

it('previews relative points at their subject on the cinematic start map and refuses placing them by click', function () {
    $root = cutsceneProject();
    $path = writeRelativeScript($root, [
        ['type' => 'move_route', 'subject' => 'player',
            'waypoints' => [['x' => 2], ['at' => ['kind' => 'npc', 'id' => 'keeper'], 'offset' => ['x' => 1, 'y' => 1]], ['y' => 6]]],
        ['type' => 'stage_actor', 'id' => 'guide', 'sprite' => ['G'], 'at' => ['kind' => 'npc', 'id' => 'keeper'], 'offset' => ['x' => -1, 'y' => 1]],
        ['type' => 'stage_actor', 'id' => 'porter', 'sprite' => ['P'], 'x' => 6, 'y' => 3],
        ['type' => 'stage_actor', 'id' => 'echo', 'sprite' => ['E'], 'at' => ['kind' => 'player']],
    ]);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $frame = ['commands'];
    $rows = $session->readDatabaseRecord('cutscenes/cinematic', 0, $frame)['rows'];
    $placements = array_values(array_filter($rows, static fn(array $row): bool => isset($row['mapPlacement'])));
    // An absolute staged actor keeps its typed coordinates; only relative points are previewed.
    [$route, $guide, $echo] = array_map(static fn(array $row): array => $row['mapPlacement'], $placements);

    expect($route['map'])->toBe('harbour')
        ->and(array_column($route['points'], 'point'))->toBe([[2, null], [5, 2], [5, 6]])
        ->and($route['points'][1]['fields'])->toBe([])
        ->and($route['points'][1]['label'])->toContain('+1, +1 from NPC keeper')
        ->and($guide)->toMatchArray(['kind' => 'stage', 'map' => 'harbour', 'issue' => null])
        ->and($guide['points'][0]['point'])->toBe([3, 2])
        ->and($echo['points'][0]['point'])->toBe([null, null])
        ->and($echo['points'][0]['issue'])->toContain('only while it runs');

    $context = ['kind' => 'database', 'category' => 'cutscenes/cinematic', 'index' => 0];
    $revision = $session->readMap('harbour')['revision'];
    expect(fn() => $session->applyMapPlacement($context, $placements[1]['key'], $guide, 'harbour', 0, 7, 3, null, $revision))
        ->toThrow(SessionRefusal::class, 'follows its subject')
        ->and(fn() => $session->applyMapPlacement($context, $placements[0]['key'], $route, 'harbour', 1, 7, 3, [1, 1], $revision))
        ->toThrow(SessionRefusal::class, 'follows its subject');
    // Absolute waypoints around them still place as before.
    $session->applyMapPlacement($context, $placements[0]['key'], $route, 'harbour', 0, 7, 3, [1, 1], $revision);
    $session->saveDatabase('cutscenes/cinematic');
    expect((require $path)[0]['waypoints'])->toBe([['x' => 7], ['at' => ['kind' => 'npc', 'id' => 'keeper'], 'offset' => ['x' => 1, 'y' => 1]], ['y' => 6]])
        ->and(array_slice(require $path, 1))->toBe(array_slice(eval('?>' . $original), 1));
});

it('says why a reusable script cannot show its relative points', function () {
    $root = makeTemporaryProject('ichiloto-relative-common-');
    file_put_contents($root . '/assets/Events/relative-walk.php', "<?php\nreturn " . var_export([['type' => 'move_route', 'subject' => 'player',
        'waypoints' => [['at' => ['kind' => 'marker', 'id' => 'E'], 'offset' => ['y' => 1]]]]], true) . ";\n");
    $session = EditorSession::open($root);
    $index = array_search('relative-walk', $session->listDatabaseRecords('common_events')['records'], true);
    $placement = array_find($session->readDatabaseRecord('common_events', $index)['rows'], static fn(array $row): bool => isset($row['mapPlacement']))['mapPlacement'];
    expect($placement['points'][0]['point'])->toBe([null, null])
        ->and($placement['points'][0]['issue'])->toContain('whichever map calls it')
        ->and($placement['map'] ?? null)->toBeNull();
});

it('authors an independent staged actor and a cast member relative to a subject, refusing incomplete ones at save', function () {
    $root = cutsceneProject();
    $path = writeRelativeScript($root, [['type' => 'stage_actor', 'id' => 'guide', 'sprite' => ['G'], 'x' => 1, 'y' => 2, 'facing' => 'north']]);
    $dataPath = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    $session = EditorSession::open($root);
    $frame = ['commands'];

    $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0], 'Relative To')['key'], 'npc');
    expect(array_column(array_filter($session->readDatabaseRecord('cutscenes/cinematic', 0, $frame)['rows'],
        static fn(array $row): bool => ($row['entry'] ?? []) === [0]), 'name'))->not->toContain('X', 'Y');
    $saved = sourceHashTree($root);
    expect(fn() => $session->saveDatabase('cutscenes/cinematic'))->toThrow(RuntimeException::class, 'requires an id')
        ->and(sourceHashTree($root))->toBe($saved);
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0], 'NPC')['key'], 'keeper');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0], 'Offset X')['key'], '-1');

    // The cast is staged the same way: its members are independent actors too.
    $cast = static fn(string $name): array => array_find($session->readDatabaseRecord('cutscenes/cinematic', 0)['rows'],
        static fn(array $row): bool => ($row['entry'] ?? []) === [1] && ($row['name'] ?? '') === $name);
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $cast('Relative To')['key'], 'staged_actor');
    expect($cast('Staged Actor')['reference'])->toBe('cinematic_cast');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $cast('Staged Actor')['key'], 'boat-east');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $cast('Offset Y')['key'], '2');
    $session->saveDatabase('cutscenes/cinematic');

    expect((require $path)[0])->toBe(['type' => 'stage_actor', 'id' => 'guide', 'sprite' => ['G'], 'facing' => 'north',
        'at' => ['kind' => 'npc', 'id' => 'keeper'], 'offset' => ['x' => -1]])
        ->and(file_get_contents($path))->toContain('// Staging authored by hand.')
        ->and((require $dataPath)['cast'][1])->toBe(['kind' => 'staged_actor', 'id' => 'boat-west', 'sprite' => ['~^~'],
            'at' => ['kind' => 'staged_actor', 'id' => 'boat-east'], 'offset' => ['y' => 2]])
        ->and(file_get_contents($dataPath))->toContain('// Harbour Lanterns: an original test cinematic.');

    // Binding the visual to a real subject drops the position it would inherit, never keeping both.
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0], 'Bind Visual To')['key'], 'player');
    $bound = $session->readDatabaseRecord('cutscenes/cinematic', 0, $frame)['rows'];
    expect(array_column(array_filter($bound, static fn(array $row): bool => ($row['entry'] ?? []) === [0]), 'name'))
        ->not->toContain('Relative To', 'Offset X', 'NPC');
    $session->saveDatabase('cutscenes/cinematic');
    expect((require $path)[0])->not->toHaveKeys(['at', 'offset', 'x', 'y']);
});

it('authors relative staging in the Terminal editor with its list choices and no world objects', function () {
    $root = cutsceneProject();
    $path = writeRelativeScript($root, [['type' => 'stage_actor', 'id' => 'guide', 'sprite' => ['G'], 'x' => 1, 'y' => 2],
        ['type' => 'move_route', 'subject' => 'player', 'waypoints' => [['x' => 3]]]]);
    $library = ProjectWorkspace::fromProject($root)->cutscenes ?? throw new RuntimeException('The Terminal workspace has no cutscenes.');
    $frame = ['commands'];
    $field = static fn(array $entry, string $name): array => array_find($library->records(CutsceneType::CINEMATIC)->getFrameSettingsFields(0, $frame),
        static fn(array $row): bool => ($row['entry'] ?? []) === $entry && ($row['name'] ?? '') === $name)
        ?? throw new RuntimeException('Missing Terminal row ' . $name);
    // Every Terminal edit goes through the library's one change path.
    $apply = static fn(array $entry, string $name, string $value) => $library->changeAsset(CutsceneType::CINEMATIC, 0, $name,
        static fn($records, int $index) => $records->setFrameField($index, $frame, $field($entry, $name)['field'], $value));

    expect($field([0], 'Relative To')['options'] ?? [])->not->toContain('world_object')
        ->and($field([1, 0], 'Relative To')['options'] ?? [])->toContain('marker', 'staged_actor')->not->toContain('world_object');
    $apply([0], 'Relative To', 'party_actor');
    $apply([0], 'Offset Y', '1');
    $apply([1, 0], 'Relative To', 'staged_actor');
    $apply([1, 0], 'Staged Actor', 'guide');
    expect($field([0], 'Party Actor')['value'])->toBe('(leading party actor)');
    $library->save(CutsceneType::CINEMATIC, 'harbour-lanterns');

    expect((require $path)[0])->toBe(['type' => 'stage_actor', 'id' => 'guide', 'sprite' => ['G'], 'at' => ['kind' => 'party_actor'], 'offset' => ['y' => 1]])
        ->and((require $path)[1]['waypoints'])->toBe([['at' => ['kind' => 'staged_actor', 'id' => 'guide']]]);
});

it('leaves relative points to follow their subjects when map lines are inserted, reporting offsets the lines cross', function () {
    $root = mapLineProject();
    $directory = $root . '/assets/Cutscenes/Cinematics/arrival';
    mkdir($directory, 0o777, true);
    $cat = ['kind' => 'npc', 'id' => 'cat'];
    file_put_contents($directory . '/arrival.data.php', "<?php\n\nreturn " . var_export([
        'id' => 'arrival', 'name' => 'Arrival', 'startMap' => 'home',
        'cast' => [['id' => 'visitor', 'sprite' => 'v', 'at' => $cat, 'offset' => ['y' => -2]]],
    ], true) . ";\n");
    $script = [
        ['type' => 'stage_actor', 'id' => 'extra', 'sprite' => 'e', 'at' => $cat, 'offset' => ['x' => 1]],
        ['type' => 'move_route', 'subject' => 'player', 'waypoints' => [['at' => $cat, 'offset' => ['y' => 1]], ['y' => 4]]],
        ['type' => 'stage_actor', 'id' => 'echo', 'sprite' => 'o', 'at' => ['kind' => 'player'], 'offset' => ['y' => 1]],
    ];
    file_put_contents($directory . '/arrival.script.php', "<?php\n\nreturn " . var_export($script, true) . ";\n");

    $plan = planLineInsertion($root, 'y', 2, 1);
    expect(describeMapLineHandEdits($plan))->toContain(
        'assets/Cutscenes/Cinematics/arrival/arrival.data.php cast.0.offset.y',
        'assets/Cutscenes/Cinematics/arrival/arrival.script.php 2.offset.y',
    )->and(describeMapLineHandEdits($plan))->not->toContain(
        'assets/Cutscenes/Cinematics/arrival/arrival.script.php 0.offset.x',
        'assets/Cutscenes/Cinematics/arrival/arrival.script.php 1.waypoints.0.offset.y',
    );
    $plan->apply();

    $saved = require $directory . '/arrival.script.php';
    expect((require $directory . '/arrival.data.php')['cast'][0])->toMatchArray(['at' => $cat, 'offset' => ['y' => -2]])
        ->and((require $directory . '/arrival.data.php')['cast'][0])->not->toHaveKeys(['x', 'y'])
        ->and($saved[0])->toBe($script[0])
        ->and($saved[1]['waypoints'])->toBe([['at' => $cat, 'offset' => ['y' => 1]], ['y' => 5]])
        ->and($saved[2])->toBe($script[2])
        // The subject itself moved, so the points that follow it moved with it.
        ->and((require $root . '/assets/Maps/home/home.data.php')['npcs'][0])->toMatchArray(['x' => 2, 'y' => 4]);
});

it('reports relative subjects that are not on the scene map or its stage', function () {
    $root = cutsceneProject();
    writeRelativeScript($root, [
        ['type' => 'stage_actor', 'id' => 'guide', 'sprite' => ['G'], 'at' => ['kind' => 'npc', 'id' => 'stranger']],
        ['type' => 'stage_actor', 'id' => 'shadow', 'sprite' => ['S'], 'at' => ['kind' => 'staged_actor', 'id' => 'shadow']],
        ['type' => 'move_route', 'subject' => 'player', 'waypoints' => [['at' => ['kind' => 'npc', 'id' => 'keeper'], 'offset' => ['x' => 1]]]],
    ]);
    $messages = array_map(static fn($issue): string => $issue->message, validateProject($root));

    expect($messages)->toContain('It addresses NPC "stranger", which is not on harbour.')
        ->and($messages)->toContain('It addresses staged actor "shadow", which neither the cast nor an earlier stage_actor command defines.')
        ->and(array_filter($messages, static fn(string $message): bool => str_contains($message, 'keeper')))->toBe([]);
});

/** A synthetic map with an event marker, a world object and an NPC whose scripts walk relative to them. */
function writeRelativeMapProject(): array
{
    $root = makeTemporaryProject('ichiloto-relative-map-');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['worldObjects'] = [['id' => 'lamp', 'anchor' => ['x' => 3, 'y' => 2], 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => null]];
    $data['events']['S'] = ['class' => \Ichiloto\Engine\Events\Triggers\StoryTrigger::class, 'data' => ['script' => [
        ['type' => 'move_route', 'subject' => 'player', 'waypoints' => [
            ['at' => ['kind' => 'marker', 'id' => 'E'], 'offset' => ['y' => 1]],
            ['at' => ['kind' => 'world_object', 'id' => 'lamp'], 'offset' => ['x' => -1]],
        ]],
    ]]];
    $data['npcs'] = [['id' => 'sage', 'name' => 'Sage', 'sprite' => 's', 'x' => 8, 'y' => 3,
        'script' => [['type' => 'move_route', 'subject' => 'player', 'waypoints' => [['x' => 2]]]]]];
    file_put_contents($path, "<?php\n// Map staging by hand.\nreturn " . var_export($data, true) . ";\n");

    return [$root, $path];
}

it('authors and previews relative waypoints in map event and NPC scripts on their own map', function () {
    [$root, $path] = writeRelativeMapProject();
    $session = EditorSession::open($root);

    $read = $session->readInspector('test-map', 'S');
    $placement = array_find($read['rows'], static fn(array $row): bool => isset($row['mapPlacement']))['mapPlacement'];
    expect($placement['map'])->toBe('test-map')
        ->and(array_column($placement['points'], 'point'))->toBe([[5, 2], [2, 2]]);
    $offset = array_find($read['rows'], static fn(array $row): bool
        => ($row['key']['path'] ?? []) === ['data', 'script', '0', 'waypoints', '1', 'offset', 'y']);
    $session->applyInspector('test-map', $read['revision'], $offset['key'], '3');

    $npc = $session->readNpc('test-map', 0, ['script']);
    $kind = array_find($npc['rows'], static fn(array $row): bool => ($row['entry'] ?? []) === [0, 0] && ($row['name'] ?? '') === 'Relative To');
    $session->applyNpc('test-map', $npc['revision'], 0, $kind['key'], 'world_object');
    $npc = $session->readNpc('test-map', 0, ['script']);
    $object = array_find($npc['rows'], static fn(array $row): bool => ($row['entry'] ?? []) === [0, 0] && ($row['name'] ?? '') === 'World Object');
    expect($object['reference'])->toBe('map_world_objects')
        ->and(array_column($session->listReferences('test-map', 'map_world_objects'), 'value'))->toBe(['lamp']);
    $session->applyNpc('test-map', $npc['revision'], 0, $object['key'], 'lamp');
    $session->saveMap('test-map');

    $saved = require $path;
    expect($saved['events']['S']['data']['script'][0]['waypoints'][1])->toBe(['at' => ['kind' => 'world_object', 'id' => 'lamp'], 'offset' => ['x' => -1, 'y' => 3]])
        ->and($saved['npcs'][0]['script'][0]['waypoints'])->toBe([['at' => ['kind' => 'world_object', 'id' => 'lamp']]])
        ->and(file_get_contents($path))->toContain('// Map staging by hand.');
    $preview = array_find(EditorSession::open($root)->readNpc('test-map', 0, ['script'])['rows'], static fn(array $row): bool => isset($row['mapPlacement']));
    expect($preview['mapPlacement']['points'][0]['point'])->toBe([3, 2]);
});

it('chooses world objects on the map a cinematic starts on, and refuses ids that are not there', function () {
    $root = cutsceneProject();
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $map['worldObjects'] = [['id' => 'beacon', 'anchor' => ['x' => 6, 'y' => 3], 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => null]];
    file_put_contents($mapPath, "<?php\nreturn " . var_export($map, true) . ";\n");
    $path = writeRelativeScript($root, [['type' => 'stage_actor', 'id' => 'guide', 'sprite' => ['G'], 'x' => 1, 'y' => 1]]);
    $session = EditorSession::open($root);
    $frame = ['commands'];

    $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0], 'Relative To')['key'], 'world_object');
    $object = relativeRow($session, $frame, [0], 'World Object');
    expect(fn() => $session->applyDatabaseRecord('cutscenes/cinematic', 0, $object['key'], 'elsewhere'))
        ->toThrow(SessionRefusal::class, 'Unknown map_world_objects');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $object['key'], 'beacon');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, relativeRow($session, $frame, [0], 'Offset Y')['key'], '1');
    $session->saveDatabase('cutscenes/cinematic');

    expect((require $path)[0])->toMatchArray(['at' => ['kind' => 'world_object', 'id' => 'beacon'], 'offset' => ['y' => 1]])
        ->and(array_find($session->readDatabaseRecord('cutscenes/cinematic', 0, $frame)['rows'],
            static fn(array $row): bool => isset($row['mapPlacement']))['mapPlacement']['points'][0]['point'])->toBe([6, 4]);
});
