<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\FieldResourceFields;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;

/**
 * The cutscene project's harbour with what a scene rebuild replaces: an
 * inspection event B, a standing NPC that stood in for the object, a common
 * event that duplicated the scene, and a cinematic whose staged actor stands
 * on a literal cell. The field catalogue has no resources yet.
 */
function atomicSceneProject(): string
{
    $root = cutsceneProject();
    is_dir($root . '/assets/Data/Presentation') || mkdir($root . '/assets/Data/Presentation', 0o777, true);
    file_put_contents($root . '/assets/Data/Presentation/field.php', "<?php\n\n// Field presentation.\nreturn ['cues' => [], 'resources' => []];\n");
    writeTilesetTestPng($root . '/assets/Graphics/Props/Lamp.png', 12, 16);
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $map['events']['B'] = ['class' => Ichiloto\Engine\Events\Triggers\DialogueEventTrigger::class, 'data' => ['dialogue' => [['name' => '', 'text' => 'A lamp.']]]];
    $map['npcs'][] = ['id' => 'lamp-stand-in', 'name' => 'Lamp', 'sprite' => 'L', 'x' => 12, 'y' => 4];
    file_put_contents($mapPath, "<?php\n\nreturn " . var_export($map, true) . ";\n");
    $eventPath = $root . '/assets/Maps/harbour/harbour.event.php';
    $rows = array_fill(0, 8, str_repeat(' ', 20));
    $rows[4] = substr_replace($rows[4], 'B', 10, 1);
    file_put_contents($eventPath, "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n" . implode("\n", $rows) . "\nICHILOTO_EVENT_MAP;\n");
    file_put_contents($root . '/assets/Events/lamp-duplicate.php', "<?php\n\nreturn [['type' => 'text', 'message' => 'The lamp hums.']];\n");
    writeRelativeScriptForAtomicTest($root);

    return $root;
}

/** The cinematic's script: one staged actor on a literal cell. */
function writeRelativeScriptForAtomicTest(string $root): void
{
    file_put_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php',
        "<?php\n// Staging authored by hand.\nreturn " . var_export([['type' => 'stage_actor', 'id' => 'guide', 'sprite' => ['G'], 'x' => 1, 'y' => 2]], true) . ";\n");
}

/** The cinematic row at an entry, by name. */
function atomicCinematicRow(EditorSession $session, string $name): array
{
    return array_find($session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands'])['rows'],
        static fn(array $row): bool => ($row['entry'] ?? []) === [0] && ($row['name'] ?? '') === $name)
        ?? throw new RuntimeException('Missing cinematic row ' . $name);
}

/** Makes every edit of the rebuild inside the session's open change set. */
function applyAtomicRebuild(EditorSession $session): void
{
    $session->createDatabaseRecord(FieldResourceFields::CATEGORY);
    $index = count($session->listDatabaseRecords(FieldResourceFields::CATEGORY)['records']) - 1;
    $resource = static fn(string $field): array => array_find($session->readDatabaseRecord(FieldResourceFields::CATEGORY, $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field) ?? throw new RuntimeException('Missing resource row ' . $field);
    foreach (['id' => 'lamp', 'name' => 'Lamp', 'sprites2d.asset' => 'Graphics/Props/Lamp.png'] as $field => $value) {
        $session->applyDatabaseRecord(FieldResourceFields::CATEGORY, $index, $resource($field)['key'], $value);
    }

    // The resource is usable at once, though nothing has been written.
    $session->createWorldObject('harbour', $session->readMap('harbour')['revision'], 'lamp-1', 10, 3, 'lamp', null);
    $session->setEventSubject('harbour', $session->readMap('harbour')['revision'], 'B', 'lamp-1');
    $npc = array_search('lamp-stand-in', array_column($session->readMap('harbour')['npcs'], 'id'), true);
    $session->deleteNpc('harbour', $session->readMap('harbour')['revision'], (int) $npc);

    $session->applyDatabaseRecord('cutscenes/cinematic', 0, atomicCinematicRow($session, 'Relative To')['key'], 'world_object');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, atomicCinematicRow($session, 'World Object')['key'], 'lamp-1');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, atomicCinematicRow($session, 'Offset Y')['key'], '1');

    $duplicate = array_search('lamp-duplicate', $session->listDatabaseRecords('common_events')['records'], true);
    expect($duplicate)->toBeInt();
    $session->deleteDatabaseRecord('common_events', $duplicate);
}

it('commits a scene rebuild as one change set: a new resource, its object, a bound event, relative staging and removals', function () {
    $root = atomicSceneProject();
    $session = EditorSession::open($root);
    $session->beginChanges();
    applyAtomicRebuild($session);

    $outcome = $session->commitChanges();

    $map = require $root . '/assets/Maps/harbour/harbour.data.php';
    expect($outcome['status'])->toBe('saved', json_encode($outcome['issues']))
        ->and(array_keys(FieldPresentationCatalog::load($root . '/assets')->resources))->toBe(['lamp'])
        ->and(array_column($map['worldObjects'], 'resource', 'id'))->toBe(['lamp-1' => 'lamp'])
        ->and($map['events']['B']['subject'])->toBe(['kind' => 'world_object', 'id' => 'lamp-1'])
        ->and(array_column($map['npcs'], 'id'))->not->toContain('lamp-stand-in')
        ->and((require $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php')[0])
            ->toMatchArray(['at' => ['kind' => 'world_object', 'id' => 'lamp-1'], 'offset' => ['y' => 1]])
        ->and(is_file($root . '/assets/Events/lamp-duplicate.php'))->toBeFalse()
        ->and(is_file($root . '/assets/Events/dresser-note.php'))->toBeTrue()
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('refuses the same rebuild when it leaves a defect, writing no category file and no map', function () {
    $root = atomicSceneProject();
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);
    $session->beginChanges();
    applyAtomicRebuild($session);
    // A marker painted with no event: the Engine refuses the map.
    $map = $session->readMap('harbour');
    $session->paint('harbour', $map['revision'], 'event', [[2, 5]], 'Z');

    $outcome = $session->commitChanges();

    expect($outcome['status'])->toBe('refused')
        ->and(array_column($outcome['issues'], 'code'))->toContain('event.unmapped')
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('keeps a landmark anchored to an event once the event is bound to a world object, renaming it in the same change set', function () {
    [$root, $file, $session] = createLandmarkProject("'landmarks' => [['id' => 'trial', 'kind' => 'landmark', 'anchor' => ['event' => 'E']]],");
    $session->beginChanges();
    $session->setEventSubject('test-map', $session->readMap('test-map')['revision'], 'E', 'old-tower');

    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'name', 'index' => 0], 'Lamp Trial');
    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'id', 'index' => 0], 'lamp-trial');
    $outcome = $session->commitChanges();

    $data = require $file;
    expect($outcome['status'])->toBe('saved')
        ->and(array_filter($outcome['issues'], static fn(array $issue): bool => str_contains($issue['message'], 'missing event')))->toBe([])
        ->and($data['events']['E']['subject'])->toBe(['kind' => 'world_object', 'id' => 'old-tower'])
        ->and($data['landmarks'][0])->toMatchArray(['id' => 'lamp-trial', 'anchor' => ['event' => 'E']])
        ->and(json_encode($data['landmarks'][0]['name']))->toContain('Lamp Trial');
});

it('names every document a change set wrote, and the files each wrote', function () {
    $root = atomicSceneProject();
    $session = EditorSession::open($root);
    $session->beginChanges();
    applyAtomicRebuild($session);

    $outcome = $session->commitChanges();

    expect($outcome['status'])->toBe('saved', json_encode($outcome['issues']))
        ->and($outcome['saved'])->toBe(['harbour'])
        ->and($outcome['written'])->toBe([
            'common_events' => ['assets/Events/lamp-duplicate.php'],
            'field_resources' => ['assets/Data/Presentation/field.php'],
            'cinematic harbour-lanterns' => ['assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php'],
            'map harbour' => ['assets/Maps/harbour/harbour.data.php', 'assets/Maps/harbour/harbour.event.php'],
        ])
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('writes nothing when the last document of a change set would not read back, and says so', function () {
    $root = atomicSceneProject();
    // A value the map's file makes anew each time it is read: the staged
    // data can never read back as the map the editor holds.
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    file_put_contents($mapPath, str_replace("return array (", "return array (\n  'readAt' => hrtime(true),", (string) file_get_contents($mapPath)));
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);
    $session->beginChanges();
    applyAtomicRebuild($session);

    $outcome = $session->commitChanges();

    expect($outcome['status'])->toBe('refused')
        ->and($outcome['blocking'])->toBe(0)
        ->and($outcome['reason'])->toStartWith('Nothing was written: map harbour: ')
        ->and($outcome['reason'])->toContain('would not read back as the edited map')
        ->and($outcome['saved'])->toBe([])
        ->and($outcome['written'])->toBe([])
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('writes a change set\'s documents and the save relocations its map needs together, and undo removes the step', function () {
    $root = atomicSceneProject();
    // The quay is where the game starts, so saves may stand on its floor.
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n"
        . "return ['#' => CollisionType::SOLID, '=' => CollisionType::SOLID, ' ' => CollisionType::NONE];\n");
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn ['startingPositions' => ['player' => "
        . "['destinationMap' => 'harbour', 'spawnPoint' => ['x' => 5, 'y' => 3], 'spawnSprite' => ['South']]]];\n");
    $manifestPath = $root . '/assets/Data/save-compatibility.php';
    $manifest = (string) file_get_contents($manifestPath);
    $session = EditorSession::open($root);
    $session->beginChanges();
    applyAtomicRebuild($session);
    $map = $session->readMap('harbour');
    $session->paint('harbour', $map['revision'], $map['baseLayer'], [[6, 4]], '#');

    $outcome = $session->commitChanges(relocateSaves: true);

    expect($outcome)->toMatchArray(['status' => 'saved', 'saved' => ['harbour'], 'relocated' => ['x 6, y 4 to x 6, y 5']])
        ->and(array_keys($outcome['written']))->toBe(['common_events', 'field_resources', 'cinematic harbour-lanterns', 'save relocations for harbour', 'map harbour'])
        ->and((require $manifestPath)['migrations'])->toBe([['from' => 0, 'to' => 1,
            'relocations' => [['map' => 'harbour', 'cells' => [[6, 4]], 'to' => [6, 5]]]]])
        ->and($session->listUnsavedChanges())->toBe([]);

    $session->undo();
    expect(file_get_contents($manifestPath))->toBe($manifest);
});
