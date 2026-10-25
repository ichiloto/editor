<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * Measuring a walk through the session: the Engine's own movement, step time
 * and encounter rules over the map as it stands in the session, read-only.
 * Synthetic project only, a 10 x 4 map:
 *
 *   ##########
 *   #..;;;.#.#    `;` is a danger tile; (8, 1) and (8, 2) are walled off
 *   #.##T.N#.#    T is a transfer at (4, 2); N a fixed NPC at (6, 2)
 *   ##########
 */
function createMeasuredProject(string $encounters = "'encounters' => ['troops' => ['Wolves' => 2], 'rate' => 4],"): string
{
    $root = makeTemporaryProject('ichiloto-measure-');
    $maps = $root . '/assets/Maps';
    file_put_contents($maps . '/collisions.php', "<?php\n\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n\n"
        . "return ['.' => CollisionType::NONE, ' ' => CollisionType::NONE, '#' => CollisionType::SOLID, ';' => CollisionType::ENCOUNTER];\n");
    $directory = $maps . '/test-map';
    file_put_contents($directory . '/test-map.map.php', MapGridSource::buildSource("##########\n#..;;;.#.#\n#.##...#.#\n##########", 'ICHILOTO_MAP'));
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource("          \n          \n    T     \n          ", 'ICHILOTO_EVENT_MAP'));
    file_put_contents($directory . '/test-map.data.php', <<<PHP
<?php

return [
    'name' => 'Test Map', 'region' => '', 'triggers' => [],
    'events' => [
        'T' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\TransferPlayerTrigger',
            'data' => ['destinationMap' => 'test-map', 'spawnPoint' => ['x' => 1, 'y' => 1]]],
    ],
    'npcs' => [['name' => 'Guard', 'x' => 6, 'y' => 2, 'dialogue' => [['text' => 'Halt.']]]],
    {$encounters}
];
PHP);

    return $root;
}

it('measures the shortest walk with its steps, seconds, screens and expected encounters', function () {
    $walk = EditorSession::open(createMeasuredProject())->measureWalk('test-map', [[1, 1], [6, 1]]);

    expect($walk)->toMatchArray([
        'map' => 'test-map', 'reachable' => true, 'problem' => null,
        'path' => [[1, 1], [2, 1], [3, 1], [4, 1], [5, 1], [6, 1]],
        'steps' => 5, 'across' => 5, 'down' => 0,
        // RPG Maker MZ's walk: a 48-pixel cell in 16 frames at 60 frames a second.
        'stepSeconds' => 0.2667, 'walkSeconds' => 1.33,
        // The GPUI field at the default screen and zoom shows 28 x 15 cells.
        'view' => ['columns' => 28, 'rows' => 15, 'zoom' => 1.0], 'screens' => 0.18,
        'encounters' => ['rate' => 4, 'everyTile' => false, 'countedSteps' => 3.0, 'expected' => 0.75, 'troops' => ['Wolves']],
    ])->and($walk['legs'])->toHaveCount(1);
});

it('says plainly that only walking time is shown while the game authors no dash speed', function () {
    $walk = EditorSession::open(createMeasuredProject())->measureWalk('test-map', [[1, 1], [6, 1]]);

    expect($walk['dashSeconds'])->toBeNull()
        ->and($walk['dashSpeed'])->toBeNull()
        ->and($walk['dashUnavailable'])->toBe('This game authors no dash speed, so only walking time is shown.');
});

it('walks through waypoints in order, leg by leg', function () {
    $walk = EditorSession::open(createMeasuredProject())->measureWalk('test-map', [[1, 1], [1, 2], [6, 1]]);

    expect($walk['steps'])->toBe(7)
        ->and($walk['across'])->toBe(5)
        ->and($walk['down'])->toBe(2)
        ->and(array_column($walk['legs'], 'steps'))->toBe([1, 6])
        ->and(array_column($walk['legs'], 'expectedEncounters'))->toBe([0.0, 0.75])
        ->and($walk['path'][0])->toBe([1, 1])
        ->and($walk['path'][1])->toBe([1, 2])
        ->and(end($walk['path']))->toBe([6, 1]);
});

it('ends a walk on a transfer, but never goes on from one', function () {
    $session = EditorSession::open(createMeasuredProject());

    expect($session->measureWalk('test-map', [[1, 1], [4, 2]])['steps'])->toBe(4)
        ->and($session->measureWalk('test-map', [[1, 1], [4, 2], [6, 1]]))->toMatchArray(['reachable' => false, 'path' => [],
            'problem' => 'Stepping onto (4, 2) takes the player off the map, so a walk cannot go on from it.']);
});

it('says why a point cannot be stood on or reached', function (array $points, string $problem) {
    $walk = EditorSession::open(createMeasuredProject())->measureWalk('test-map', $points);

    expect($walk)->toMatchArray(['reachable' => false, 'problem' => $problem, 'path' => [], 'steps' => 0, 'legs' => []]);
})->with([
    'a wall' => [[[1, 1], [2, 2]], 'The player cannot stand at (2, 2): it is on a cell the player cannot stand on.'],
    'a fixed NPC' => [[[1, 1], [6, 2]], 'The player cannot stand at (6, 2): it is where an NPC stands.'],
    'outside the map' => [[[1, 1], [12, 1]], 'The player cannot stand at (12, 1): it is outside the map.'],
    'a walled-off pocket' => [[[1, 1], [8, 1]], 'No walk leads from (1, 1) to (8, 1).'],
]);

it('counts no encounters on a map without them', function () {
    $walk = EditorSession::open(createMeasuredProject(''))->measureWalk('test-map', [[1, 1], [6, 1]]);

    expect($walk['encounters'])->toBeNull()
        ->and($walk['legs'][0]['expectedEncounters'])->toBeNull();
});

it('measures the map as the session holds it, unsaved edits included, and changes nothing', function () {
    $root = createMeasuredProject();
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    $revision = $session->readMap('test-map')['revision'];
    // A wall painted across the top row's corridor, unsaved, sends the walk round below it.
    $session->paint('test-map', $revision, $session->readMap('test-map')['baseLayer'], [[2, 1]], '#');
    $walk = $session->measureWalk('test-map', [[1, 1], [6, 1]]);

    expect($walk['reachable'])->toBeFalse()
        ->and($walk['problem'])->toBe('No walk leads from (1, 1) to (6, 1).');
    $session->undo();
    expect($session->measureWalk('test-map', [[1, 1], [6, 1]])['steps'])->toBe(5)
        ->and(sourceHashTree($root))->toBe($before);
});

it('answers map.measure over the session protocol, and refuses what it cannot measure', function () {
    $root = createMeasuredProject();
    $host = new SessionHost(STDIN, STDOUT, fopen('php://memory', 'w+'));
    $host->handle(json_encode(['id' => 1, 'method' => 'hello', 'params' => ['protocol' => SessionHost::PROTOCOL, 'project' => $root]]));
    $answer = $host->handle(json_encode(['id' => 2, 'method' => 'map.measure', 'params' => ['map' => 'test-map', 'points' => [[1, 1], [6, 1]]]]));

    expect($answer['result'])->toMatchArray(['steps' => 5, 'reachable' => true])
        ->and($host->handle(json_encode(['id' => 3, 'method' => 'map.measure', 'params' => ['map' => 'test-map', 'points' => [[1, 1]]]]))['error'])
        ->toMatchArray(['kind' => 'refusal'])
        ->and($host->handle(json_encode(['id' => 4, 'method' => 'map.measure', 'params' => ['map' => 'test-map', 'points' => 'here']]))['error'])
        ->toMatchArray(['kind' => 'request'])
        ->and(fn() => EditorSession::open($root)->measureWalk('elsewhere', [[1, 1], [2, 1]]))->toThrow(SessionRefusal::class, 'There is no map elsewhere.');
    $host->close();
});

it('meets a terrain-limited troop only on its terrain, as the field reads the map\'s kinds', function () {
    // The top row is grove, the bottom meadow; every step counts, only the grove holds Sprites, and a grove's
    // danger tile counts twice on a map that counts every tile.
    $root = terrainKindProject("<fg=green>♣♣♣♣</>\n<fg=green>....</>");
    file_put_contents($root . '/assets/Maps/test-map/layers/04.buildings.map.php', MapGridSource::buildSource("    \n    ", 'AUTHORED'));
    file_put_contents($root . '/assets/Maps/test-map/test-map.event.php', MapGridSource::buildSource("    \n    ", 'EVENTS'));
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($file, str_replace("'tileset' => 'home',", "'tileset' => 'home', 'terrainKinds' => true, 'encounters' => "
        . var_export(['troops' => ['Sprites' => ['weight' => 2, 'terrains' => ['grove']]], 'rate' => 2, 'tiles' => 'any'], true) . ',',
        (string) file_get_contents($file)));
    $session = EditorSession::open($root);

    expect($session->measureWalk('test-map', [[0, 0], [3, 0]])['encounters'])->toMatchArray(['countedSteps' => 6.0, 'expected' => 3.0, 'troops' => ['Sprites']])
        ->and($session->measureWalk('test-map', [[0, 1], [3, 1]])['encounters'])->toMatchArray(['countedSteps' => 0.0, 'expected' => 0.0, 'troops' => []]);
});
