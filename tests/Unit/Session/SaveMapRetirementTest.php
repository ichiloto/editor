<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;

/**
 * Retiring a map in the save manifest through the session, and aliasing a
 * saved identity: one source-preserved manifest step or alias, read back as
 * the game reads it, one undo step, undone with a refused change set. The
 * fixture is {@see retirementProject()}: `old-road` is retired into `field`.
 */

/**
 * The synthetic project: the sample project with a room `field`, where the
 * game starts, and a road `old-road` to retire into it. `field` holds water,
 * a grass patch, a chest event and a guard.
 */
function retirementProject(): string
{
    $root = makeTemporaryProject('ichiloto-map-retirement-');
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n"
        . "return ['#' => CollisionType::SOLID, '~' => CollisionType::SOLID, '.' => CollisionType::NONE, ' ' => CollisionType::NONE, ';' => CollisionType::ENCOUNTER];\n");
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn ['startingPositions' => ['player' => "
        . "['destinationMap' => 'field', 'spawnPoint' => ['x' => 1, 'y' => 1], 'spawnSprite' => ['South']]]];\n");
    $maps = [
        'field' => [["########", "#......#", "#.~~.;.#", "#......#", "#......#", "########"],
            ["        ", "      E ", "        ", "        ", "        ", "        "],
            "'events' => ['E' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\ChestEventTrigger', 'data' => ['lootType' => 'item', 'loot' => 'S-Potion']]],\n"
            . "  'npcs' => [['id' => 'guard', 'name' => 'Guard', 'sprite' => 'g', 'x' => 4, 'y' => 3, 'dialogue' => [['text' => 'Halt.']]]],\n"],
        'old-road' => [["######", "#....#", "######"], ["      ", "      ", "      "], "'events' => [],\n"],
    ];
    foreach ($maps as $mapId => [$tiles, $events, $content]) {
        writeRetirementMap($root, $mapId, $tiles, $events, $content);
    }

    return $root;
}

/** Writes one map of the synthetic project from its glyph rows, event rows and data entries. */
function writeRetirementMap(string $root, string $mapId, array $tiles, array $events, string $content): void
{
    $folder = $root . '/assets/Maps/' . $mapId;
    is_dir($folder) || mkdir($folder, 0o777, true);
    file_put_contents("{$folder}/{$mapId}.map.php", "<?php\n\nreturn <<<'ICHILOTO_MAP'\n" . implode("\n", $tiles) . "\nICHILOTO_MAP;\n");
    file_put_contents("{$folder}/{$mapId}.event.php", "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n" . implode("\n", $events) . "\nICHILOTO_EVENT_MAP;\n");
    file_put_contents("{$folder}/{$mapId}.data.php", "<?php\n\nreturn [\n  'name' => '{$mapId}',\n  'region' => '',\n  'triggers' => [],\n  {$content}];\n");
}

/**
 * The synthetic project with a second successor: `reach`, a small field the player reaches from `field` through a
 * door, holding the part of `old-road` split off from `field`.
 */
function splitRetirementProject(): string
{
    $root = retirementProject();
    writeRetirementMap($root, 'field', ["########", "#......#", "#.~~.;.#", "#......#", "#......#", "########"],
        ["        ", "      E ", "        ", "        ", "      D ", "        "],
        "'events' => ['E' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\ChestEventTrigger', 'data' => ['lootType' => 'item', 'loot' => 'S-Potion']],\n"
        . "    'D' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\TransferPlayerTrigger', 'data' => ['destinationMap' => 'reach', 'spawnPoint' => ['x' => 2, 'y' => 1], 'spawnSprite' => ['South']]]],\n"
        . "  'npcs' => [['id' => 'guard', 'name' => 'Guard', 'sprite' => 'g', 'x' => 4, 'y' => 3, 'dialogue' => [['text' => 'Halt.']]]],\n");
    writeRetirementMap($root, 'reach', ["#####", "#...#", "#.~.#", "#####"], ["     ", " A   ", "     ", "     "],
        "'events' => ['A' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\TransferPlayerTrigger', 'data' => ['destinationMap' => 'field', 'spawnPoint' => ['x' => 5, 'y' => 4], 'spawnSprite' => ['West']]]],\n");

    return $root;
}

function readRetirementManifest(string $root): array
{
    return require $root . '/assets/Data/save-compatibility.php';
}

/** The retirement most cases write: old-road's west end lands in field's south-east, everything else in its south-west. */
function retireOldRoad(EditorSession $session): array
{
    return $session->retireMap('old-road', 'field', [1, 4], [['x' => 0, 'y' => 0, 'width' => 3, 'height' => 3, 'to' => [5, 4]]]);
}

it('appends one retirement step that the game reads, keeping the manifest as authored, with undo', function () {
    $root = retirementProject();
    $path = $root . '/assets/Data/save-compatibility.php';
    file_put_contents($path, str_replace('return [', "// Authored by the release team.\nreturn [", (string) file_get_contents($path)));
    $before = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    expect(retireOldRoad($session))->toBe(['from' => 0, 'to' => 1, 'contentVersion' => 1, 'map' => 'old-road', 'into' => 'field'])
        ->and(readRetirementManifest($root)['migrations'])->toBe([['from' => 0, 'to' => 1, 'mapRetirements' => [[
            'map' => 'old-road', 'into' => 'field', 'landings' => [['x' => 0, 'y' => 0, 'width' => 3, 'height' => 3, 'to' => [5, 4]]], 'to' => [1, 4],
        ]]]])
        ->and((string) file_get_contents($path))->toContain('// Authored by the release team.')
        ->and(describeSaveCompatibilityIssues($root))->toBe([]);

    $migration = SaveCompatibilityManifest::fromProjectRoot($root)->createMigrationFrom(0);
    expect($migration->movePlace('old-road', new Vector2(2, 1)))->toEqual(['mapId' => 'field', 'position' => new Vector2(5, 4)])
        ->and($migration->movePlace('old-road', new Vector2(4, 1)))->toEqual(['mapId' => 'field', 'position' => new Vector2(1, 4)]);

    expect($session->undo()['label'])->toBe('Retire map old-road')
        ->and((string) file_get_contents($path))->toBe($before);
    $session->redo();
    expect(readRetirementManifest($root)['contentVersion'])->toBe(1);
});

it('refuses a retirement whose landings cannot be stood on, writing nothing', function (string $into, array $to, ?array $landings, string $reason) {
    $root = retirementProject();
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);

    expect(fn() => $session->retireMap('old-road', $into, $to, $landings))->toThrow(SessionRefusal::class, $reason)
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and($session->undo()['label'])->toBeNull();
})->with([
    'solid' => ['field', [2, 2], null, 'field x 2, y 2 (it is solid)'],
    'an event' => ['field', [1, 4], [['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1, 'to' => [6, 1]]], 'field x 6, y 1 (an event is there)'],
    'an NPC' => ['field', [4, 3], null, 'field x 4, y 3 (an NPC stands there)'],
    'grass' => ['field', [5, 2], null, 'field x 5, y 2 (it is not plain floor (encounter))'],
    'off the map' => ['field', [9, 9], null, 'field x 9, y 9 (it is off the map)'],
    'an unknown destination' => ['nowhere', [1, 1], null, 'There is no map nowhere for the saves on old-road to move into.'],
    'a malformed landing' => ['field', [1, 4], [['x' => 0, 'y' => 0, 'width' => 0, 'height' => 1, 'to' => [5, 4]]], 'landings[0] width must be a positive integer'],
]);

it('lands part of a split retired map on the successor its landing names', function () {
    $root = splitRetirementProject();
    $session = EditorSession::open($root);

    expect($session->retireMap('old-road', 'field', [1, 4], [['x' => 3, 'y' => 0, 'width' => 3, 'height' => 3, 'map' => 'reach', 'to' => [3, 1]]]))
        ->toBe(['from' => 0, 'to' => 1, 'contentVersion' => 1, 'map' => 'old-road', 'into' => 'field'])
        ->and(readRetirementManifest($root)['migrations'][0]['mapRetirements'][0]['landings'])
        ->toBe([['x' => 3, 'y' => 0, 'width' => 3, 'height' => 3, 'map' => 'reach', 'to' => [3, 1]]])
        ->and(describeSaveCompatibilityIssues($root))->toBe([]);

    $migration = SaveCompatibilityManifest::fromProjectRoot($root)->createMigrationFrom(0);
    expect($migration->movePlace('old-road', new Vector2(4, 1)))->toEqual(['mapId' => 'reach', 'position' => new Vector2(3, 1)])
        ->and($migration->movePlace('old-road', new Vector2(1, 1)))->toEqual(['mapId' => 'field', 'position' => new Vector2(1, 4)]);
});

it('refuses a split landing on a missing map, on the retired map or where a save cannot stand on its own map', function (array $landing, string $reason) {
    $root = splitRetirementProject();
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);

    expect(fn() => $session->retireMap('old-road', 'field', [1, 4], [['x' => 3, 'y' => 0, 'width' => 3, 'height' => 3, ...$landing]]))
        ->toThrow(SessionRefusal::class, $reason)
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
})->with([
    'a missing map' => [['map' => 'nowhere', 'to' => [1, 1]], 'nowhere x 1, y 1 (there is no such map)'],
    'the retired map' => [['map' => 'old-road', 'to' => [1, 1]], 'map old-road is the retired map'],
    'water on the other successor' => [['map' => 'reach', 'to' => [2, 2]], 'reach x 2, y 2 (it is solid)'],
    'an event on the other successor' => [['map' => 'reach', 'to' => [1, 1]], 'reach x 1, y 1 (an event is there)'],
]);

it('re-checks a split landing on its own map when a change set commits', function () {
    $root = splitRetirementProject();
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);

    $session->beginChanges();
    $session->retireMap('old-road', 'field', [1, 4], [['x' => 3, 'y' => 0, 'width' => 3, 'height' => 3, 'map' => 'reach', 'to' => [3, 1]]]);
    $map = $session->readMap('reach');
    $session->paint('reach', $map['revision'], $map['baseLayer'], [[3, 1]], '~');
    $refused = $session->commitChanges(relocateSaves: true);

    expect($refused['status'])->toBe('refused')
        ->and($refused['reason'])->toContain('saves on old-road at reach x 3, y 1 (it is solid)')
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});

it('reports a split landing on a map the project no longer has', function () {
    $root = splitRetirementProject();
    $session = EditorSession::open($root);
    $session->retireMap('old-road', 'field', [1, 4], [['x' => 3, 'y' => 0, 'width' => 3, 'height' => 3, 'map' => 'reach', 'to' => [3, 1]]]);
    $path = $root . '/assets/Data/save-compatibility.php';
    file_put_contents($path, str_replace("'map' => 'reach'", "'map' => 'gone'", (string) file_get_contents($path)));

    expect(describeSaveCompatibilityIssues($root))->toBe(['Saves on old-road move into gone, which is not a map in the project.']);
});

it('retires a redrawn map into itself, its landings judged on the layout the session holds', function () {
    $root = retirementProject();
    $session = EditorSession::open($root);

    expect(fn() => $session->retireMap('field', 'field', [2, 2]))->toThrow(SessionRefusal::class, 'field x 2, y 2 (it is solid)');

    $retired = $session->retireMap('field', 'field', [1, 4], [['x' => 0, 'y' => 0, 'width' => 4, 'height' => 2, 'to' => [5, 4]]]);

    expect($retired)->toMatchArray(['map' => 'field', 'into' => 'field'])
        ->and(readRetirementManifest($root)['migrations'][0]['mapRetirements'][0])->toMatchArray(['map' => 'field', 'into' => 'field', 'to' => [1, 4]]);
});

it('judges landings on the destination as the session holds it, unsaved edits included', function () {
    $root = retirementProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('field');
    $session->paint('field', $map['revision'], $map['baseLayer'], [[1, 4]], '~');

    expect(fn() => $session->retireMap('old-road', 'field', [1, 4]))->toThrow(SessionRefusal::class, 'field x 1, y 4 (it is solid)');
});

it('writes a retirement inside a change set, and refuses one whose later edits take a landing, leaving nothing', function () {
    $root = retirementProject();
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);

    $session->beginChanges();
    retireOldRoad($session);
    $map = $session->readMap('field');
    $session->paint('field', $map['revision'], $map['baseLayer'], [[5, 4]], '~');
    $refused = $session->commitChanges(relocateSaves: true);

    expect($refused['status'])->toBe('refused')
        ->and($refused['reason'])->toContain('map retirement landings where saves cannot stand: saves on old-road at field x 5, y 4 (it is solid)')
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([]);

    $session->beginChanges();
    retireOldRoad($session);
    expect($session->commitChanges()['status'])->toBe('saved')
        ->and(readRetirementManifest($root)['contentVersion'])->toBe(1);
});

it('follows a landing through the steps written after it in the same change set', function () {
    $root = retirementProject();
    $session = EditorSession::open($root);

    $session->beginChanges();
    $session->retireMap('old-road', 'field', [5, 3]);
    $question = $session->insertMapLines('field', $session->readMap('field')['revision'], 'x', 0, 1);
    $session->insertMapLines('field', $question['revision'], 'x', 0, 1, 'write', $question['confirm']);

    // The column moves the landing to x 6, y 3 with the floor under it; x 5, y 3 is now the guard's cell.
    expect($session->commitChanges()['status'])->toBe('saved')
        ->and(SaveCompatibilityManifest::fromProjectRoot($root)->createMigrationFrom(1)->movePlace('field', new Vector2(5, 3)))
        ->toEqual(['mapId' => 'field', 'position' => new Vector2(6, 3)]);
});

it('adds a save alias with undo, inside a change set too, and refuses one the game or the catalogue would not take', function () {
    $root = retirementProject();
    $path = $root . '/assets/Data/save-compatibility.php';
    $before = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    expect($session->addSaveAlias('one_shot_events', 'old-road:A', 'field:E'))
        ->toBe(['category' => 'one_shot_events', 'from' => 'old-road:A', 'to' => 'field:E', 'added' => true])
        ->and(readRetirementManifest($root)['aliases'])->toBe(['one_shot_events' => [['from' => 'old-road:A', 'to' => 'field:E']]])
        ->and(describeSaveCompatibilityIssues($root))->toBe([])
        ->and($session->addSaveAlias('one_shot_events', 'old-road:A', 'field:E')['added'])->toBeFalse();
    $session->addSaveAlias('maps', 'old-field', 'field');

    expect(fn() => $session->addSaveAlias('maps', 'old-field', 'old-road'))->toThrow(SessionRefusal::class, 'already sends old-field to field')
        ->and(fn() => $session->addSaveAlias('one_shot_events', 'old-road:B', 'field:Z'))->toThrow(SessionRefusal::class, 'field:Z is not a current one_shot_events identity')
        ->and(fn() => $session->addSaveAlias('one_shot_events', 'old-road:B', 'field'))->toThrow(SessionRefusal::class, 'is not a current one_shot_events identity')
        ->and(fn() => $session->addSaveAlias('places', 'a', 'b'))->toThrow(SessionRefusal::class, 'places is not a saved-content category')
        ->and(fn() => $session->addSaveAlias('maps', 'field', 'field'))->toThrow(SessionRefusal::class, 'self-alias');

    expect($session->undo()['label'])->toBe('Save alias')
        ->and($session->undo()['label'])->toBe('Save alias')
        ->and((string) file_get_contents($path))->toBe($before);

    $session->beginChanges();
    $session->addSaveAlias('maps', 'old-field', 'field');
    expect(readRetirementManifest($root)['aliases'])->toBe(['maps' => [['from' => 'old-field', 'to' => 'field']]]);
    $session->cancelChanges();
    expect((string) file_get_contents($path))->toBe($before);
});

it('reports a retirement into a map the project no longer has', function () {
    $root = retirementProject();
    $session = EditorSession::open($root);
    retireOldRoad($session);
    $path = $root . '/assets/Data/save-compatibility.php';
    file_put_contents($path, str_replace("'into' => 'field'", "'into' => 'gone'", (string) file_get_contents($path)));

    expect(describeSaveCompatibilityIssues($root))->toBe(['Saves on old-road move into gone, which is not a map in the project.']);

    file_put_contents($path, str_replace("'aliases' => [", "'aliases' => ['maps' => [['from' => 'gone', 'to' => 'field']],", (string) file_get_contents($path)));
    expect(describeSaveCompatibilityIssues($root))->toBe([]);
});

it('serves map retirement and save aliases over the session protocol', function () {
    $root = retirementProject();
    $host = new Ichiloto\Editor\Session\SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    expect($request('hello', ['protocol' => Ichiloto\Editor\Session\SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');

    expect($request('saveCompatibility.retireMap', ['map' => 'old-road', 'into' => 'field', 'to' => 'x'])['error']['kind'])->toBe('request')
        ->and($request('saveCompatibility.retireMap', ['map' => 'old-road', 'into' => 'field', 'to' => [1, 4], 'landings' => 'all'])['error']['kind'])->toBe('request')
        ->and($request('saveCompatibility.retireMap', ['map' => 'old-road', 'into' => 'field', 'to' => [2, 2]])['error']['kind'])->toBe('refusal')
        ->and($request('saveCompatibility.retireMap', ['map' => 'old-road', 'into' => 'field', 'to' => [1, 4],
            'landings' => [['x' => 0, 'y' => 0, 'width' => 3, 'height' => 3, 'to' => [5, 4]]]])['result'])
        ->toBe(['from' => 0, 'to' => 1, 'contentVersion' => 1, 'map' => 'old-road', 'into' => 'field'])
        ->and($request('saveCompatibility.alias', ['category' => 'one_shot_events', 'from' => 'old-road:A', 'to' => 'field:E'])['result']['added'])->toBeTrue()
        ->and($request('saveCompatibility.alias', ['category' => 'one_shot_events', 'from' => 'old-road:A'])['error']['kind'])->toBe('request');
});

it('plans no relocations for a map its change set retires into itself, since the retirement moves every save on it', function () {
    $root = retirementProject();
    $session = EditorSession::open($root);
    $redraw = static function () use ($session): void {
        $map = $session->readMap('field');
        // The redrawn layout walls floor the player stands on today.
        $session->paint('field', $map['revision'], $map['baseLayer'], [[2, 1], [3, 1]], '#');
    };

    // Without the retirement the redraw moves the saves on the walled cells.
    $session->beginChanges();
    $redraw();
    expect($session->commitChanges(checkOnly: true)['relocated'])->not->toBe([]);

    // A retirement undone inside the change set no longer covers the map.
    $session->beginChanges();
    $redraw();
    $session->retireMap('field', 'field', [1, 4]);
    $session->undo();
    expect($session->commitChanges(checkOnly: true)['relocated'])->not->toBe([]);

    $session->beginChanges();
    $redraw();
    $session->retireMap('field', 'field', [1, 4]);
    $saved = $session->commitChanges(relocateSaves: true);

    expect($saved['status'])->toBe('saved')
        ->and($saved['relocated'])->toBe([])
        ->and(readRetirementManifest($root)['contentVersion'])->toBe(1)
        ->and(array_map(static fn(array $step): array => array_keys($step), readRetirementManifest($root)['migrations']))
        ->toBe([['from', 'to', 'mapRetirements']]);
});
