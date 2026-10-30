<?php

declare(strict_types=1);

use Ichiloto\Editor\Maps\SaveRelocationPlanner;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Storage\WorkspaceSave;

/**
 * Saving a map that makes floor solid where the player can stand today moves
 * existing saves off those cells, once the author has seen where: the save
 * writes the map and a declared relocations step together, and undo removes
 * the step. The fixture is {@see mapLineRemovalProject()}: `home`'s floor is
 * reachable, and the project has a save manifest.
 */

/** @return array{string, EditorSession} The project and a session with a wall painted on home's floor at (1, 2), a dead end beside the bed. */
function openWalledHomeSession(): array
{
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('home');
    $session->paint('home', $map['revision'], $map['baseLayer'], [[1, 2]], '#');

    return [$root, $session];
}

function readSaveManifest(string $root): array
{
    return require $root . '/assets/Data/save-compatibility.php';
}

it('asks before saving a map that makes reachable floor solid, naming where those saves land', function () {
    [$root, $session] = openWalledHomeSession();
    $before = sourceHashTree($root);

    $question = $session->saveMap('home');

    expect($question)->toMatchArray(['status' => 'question', 'map' => 'home', 'contentVersion' => 1])
        ->and($question['relocations'])->toBe([['map' => 'home', 'cells' => [[1, 2]], 'to' => [1, 1]]])
        ->and($question['moves'])->toBe(['x 1, y 2 to x 1, y 1'])
        ->and(array_column($question['answers'], 'key'))->toBe(['cancel', 'write'])
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->saveMap('home', 'cancel'))->toMatchArray(['status' => 'cancelled'])
        ->and(sourceHashTree($root))->toBe($before);
});

it('saves the map and the relocation step together once confirmed, and undo removes the step', function () {
    [$root, $session] = openWalledHomeSession();
    $manifest = (string) file_get_contents($root . '/assets/Data/save-compatibility.php');
    $question = $session->saveMap('home');

    $saved = $session->saveMap('home', 'write', $question['confirm']);

    expect($saved['relocated'])->toBe(['x 1, y 2 to x 1, y 1'])
        ->and(readSaveManifest($root)['contentVersion'])->toBe(1)
        ->and(readSaveManifest($root)['migrations'])->toBe([['from' => 0, 'to' => 1,
            'relocations' => [['map' => 'home', 'cells' => [[1, 2]], 'to' => [1, 1]]]]])
        ->and(Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest::fromProjectRoot($root)->createMigrationFrom(0))
        ->toBeInstanceOf(Ichiloto\Engine\IO\SaveCompatibility\DeclaredPositionContentMigration::class)
        ->and(describeSaveCompatibilityIssues($root))->toBe([])
        // Saved: nothing is left to move.
        ->and(SaveRelocationPlanner::plan(getPhysicalOccupancyMap($session), $root))->toBeNull();

    $session->undo();
    expect(file_get_contents($root . '/assets/Data/save-compatibility.php'))->toBe($manifest);
});

it('refuses a write the author was not shown', function () {
    [$root, $session] = openWalledHomeSession();
    $before = sourceHashTree($root);

    expect(fn() => $session->saveMap('home', 'write', 'not-the-shown-review'))->toThrow(SessionRefusal::class, 'Review them again')
        ->and(sourceHashTree($root))->toBe($before);
});

it('saves at once when nothing reachable becomes solid, or when the project keeps no save manifest', function () {
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('home');
    // A floor cell becomes another kind of floor: nothing a save could stand on turns solid.
    $session->paint('home', $map['revision'], $map['baseLayer'], [[2, 3]], ' ');
    expect($session->saveMap('home'))->toHaveKey('saved');

    [$root, $session] = openWalledHomeSession();
    unlink($root . '/assets/Data/save-compatibility.php');
    expect($session->saveMap('home'))->toHaveKey('saved');
});

it('moves no save off walkable grass or a save point, and moves saves off grass that becomes solid', function () {
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    $paint = static function (array $cells, string $glyph) use ($session): void {
        $map = $session->readMap('home');
        $session->paint('home', $map['revision'], $map['baseLayer'], $cells, $glyph);
    };

    // New grass and a new save point are walkable: nothing to move.
    $paint([[1, 2]], 'g');
    $paint([[1, 1]], 'P');
    expect($session->saveMap('home'))->toHaveKey('saved');

    // Saved grass and save points the map keeps are not new blockers either.
    $paint([[2, 1]], ' ');
    expect($session->saveMap('home'))->toHaveKey('saved');

    // Grass that becomes solid does move the saves on it, past the save point to plain floor.
    $paint([[1, 2]], '#');
    expect($session->saveMap('home')['relocations'])->toBe([['map' => 'home', 'cells' => [[1, 2]], 'to' => [2, 1]]]);
});

it('moves saves off walkable floor no route reaches today, since an older layout may have reached it', function () {
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    $paint = static function (array $cells, string $glyph) use ($session): void {
        $map = $session->readMap('home');
        $session->paint('home', $map['revision'], $map['baseLayer'], $cells, $glyph);
    };
    // Walling in (4, 1) leaves it walkable on disk but cut off.
    $paint([[3, 1], [4, 2]], '#');
    $question = $session->saveMap('home');
    $session->saveMap('home', 'write', $question['confirm']);

    $paint([[4, 1]], '#');
    $relocations = $session->saveMap('home')['relocations'];

    expect(array_merge(...array_column($relocations, 'cells')))->toBe([[4, 1]])
        ->and($relocations[0]['to'])->not->toBe([4, 1]);
});

it('moves saves off floor an NPC now stands on, and lands them on no other NPC', function () {
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    // The lamp moves from (1, 3) onto open floor at (3, 3); the cat still stands at (2, 3).
    $session->moveNpc('home', $session->readMap('home')['revision'], 2, 3, 3);

    expect($session->saveMap('home')['relocations'])->toBe([['map' => 'home', 'cells' => [[3, 3]], 'to' => [4, 2]]]);
});

it('leaves a map that would move saves out of Save All, for its own reviewed save', function () {
    [$root, $session] = openWalledHomeSession();
    $workspace = new ReflectionProperty(EditorSession::class, 'workspace')->getValue($session);
    $result = WorkspaceSave::saveAll($workspace, new Ichiloto\Editor\Backup\BackupWriter(Ichiloto\Editor\Backup\BackupSettings::fromProject($root), $root));

    expect($result->skippedRelocations)->toBe(['home'])
        ->and(getPhysicalOccupancyMap($session)->isDirty())->toBeTrue()
        ->and(readSaveManifest($root)['contentVersion'])->toBe(0);
});

it('reviews the relocation in the terminal editor before Ctrl+S writes both', function () {
    $root = mapLineRemovalProject();
    $editor = mapLineEditor($root);
    getEditorProperty($editor, 'workspace')->getMapByIndex(0)->setTileSymbol(1, 2, '#');

    pressKeys($editor, "\x13");
    $labels = array_column(getEditorProperty($editor, 'eventOptionDialogEntries'), 'label');
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeTrue()
        ->and($labels)->toContain('Save and move saves', 'Move saves on x 1, y 2 to x 1, y 1')
        ->and(readSaveManifest($root)['contentVersion'])->toBe(0);

    pressKeys($editor, "\033[B", "\r");
    expect(readSaveManifest($root)['contentVersion'])->toBe(1)
        ->and(getEditorProperty($editor, 'workspace')->getMapByIndex(0)->isDirty())->toBeFalse();
});

it('writes the relocations of every map saved together as one manifest step', function () {
    $root = mapLineRemovalProject();
    // A door from home's floor into town makes the town's floor reachable too.
    $home = $root . '/assets/Maps/home';
    file_put_contents($home . '/home.event.php', Ichiloto\Engine\Field\MapGridSource::buildSource("      \n T  W \n      \n D  S \n      ", 'AUTHORED'));
    file_put_contents($home . '/home.data.php', str_replace("'events' => [", "'events' => [\n    'T' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\TransferPlayerTrigger',"
        . " 'data' => ['destinationMap' => 'town', 'spawnPoint' => ['x' => 2, 'y' => 1], 'spawnSprite' => ['North']]],", (string) file_get_contents($home . '/home.data.php')));
    $workspace = Ichiloto\Editor\ProjectWorkspace::fromProject($root);
    foreach ($workspace->maps as $map) {
        if (in_array($map->mapId, ['home', 'town'], true)) {
            // Home's wall leaves its door to town reachable, so both maps have floor to land saves on.
            [$x, $y] = $map->mapId === 'home' ? [1, 2] : [3, 1];
            $map->setLayerCell($map->getLayers()[0]['id'], $x, $y, '#');
        }
    }
    $recorded = [];

    Ichiloto\Editor\Storage\SaveSet::commit($root, WorkspaceSave::prepareAll($workspace, static function ($step) use (&$recorded): void { $recorded[] = $step; }));

    $steps = readSaveManifest($root)['migrations'];
    expect($steps)->toHaveCount(1)
        ->and(array_values(array_unique(array_column($steps[0]['relocations'], 'map'))))->toBe(['home', 'town'])
        ->and(readSaveManifest($root)['contentVersion'])->toBe(1)
        ->and($recorded)->toHaveCount(1);
});

it('lands no save inside a bound event its own cell was not already in', function () {
    $root = mapLineRemovalProject();
    $home = $root . '/assets/Maps/home/home.data.php';
    // An automatic event bound to a named area on the floor a relocation would otherwise choose.
    file_put_contents($home, str_replace("'events' => [", "'areas' => [['id' => 'porch', 'area' => ['cells' => [[2, 4]]]]],\n  'events' => [\n    'Z' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\ScriptEventTrigger',"
        . " 'subject' => ['kind' => 'area', 'id' => 'porch'], 'data' => ['mode' => 'auto', 'reusable' => true, 'scriptId' => 'home-only']],",
        (string) file_get_contents($home)));
    $session = EditorSession::open($root);
    $map = $session->readMap('home');
    $session->paint('home', $map['revision'], $map['baseLayer'], [[2, 3]], '#');

    $question = $session->saveMap('home');

    expect($question['relocations'])->toHaveCount(1)
        ->and($question['relocations'][0]['to'])->not->toBe([2, 4]);
});

it('moves saves off floor the change walls off, and lands none in that pocket', function () {
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('home');
    // A wall at (3, 1) closes the north-west corner off behind the water and the bed: floor the player reaches
    // today and no route reaches once saved, so its saves move too, and the wall's own saves land outside it.
    $session->paint('home', $map['revision'], $map['baseLayer'], [[3, 1]], '#');

    expect($session->saveMap('home')['relocations'])->toBe([
        ['map' => 'home', 'cells' => [[1, 1], [1, 2]], 'to' => [1, 4]],
        ['map' => 'home', 'cells' => [[2, 1], [3, 1]], 'to' => [4, 2]],
    ]);
});

it('moves saves off floor an event moved onto, where loading would start it', function () {
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    // The event at (4, 1) moves one cell west onto open floor; the floor it leaves takes the saves.
    $session->moveEvent('home', $session->readMap('home')['revision'], 'W', -1, 0);

    expect($session->saveMap('home')['relocations'])->toBe([['map' => 'home', 'cells' => [[3, 1]], 'to' => [4, 1]]]);
});

it('moves no save off a map the player reaches nowhere, such as one just made and not yet joined to the world', function () {
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    $session->createMap('Far field', null, 160, 120, 'far-field');
    $map = $session->readMap('far-field');
    // Its blank floor is walkable on disk; walling all of it in would otherwise plan a landing for every cell.
    $cells = [];
    for ($y = 0; $y < 120; $y++) {
        for ($x = 0; $x < 160; $x++) {
            $cells[] = [$x, $y];
        }
    }
    $session->paint('far-field', $map['revision'], $map['baseLayer'], $cells, '#');
    $manifest = (string) file_get_contents($root . '/assets/Data/save-compatibility.php');
    $started = microtime(true);
    $saved = $session->saveMap('far-field');

    // Searching a landing for each of its cells took minutes on a map this size.
    expect(microtime(true) - $started)->toBeLessThan(20.0)
        ->and($saved)->toHaveKey('saved')
        ->and((string) file_get_contents($root . '/assets/Data/save-compatibility.php'))->toBe($manifest);
});

it('moves no save off a map a change set made, though something already led to it and its blank files are on disk', function () {
    $root = mapLineRemovalProject();
    // The start already leads to the map the change set will make.
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn ['startingPositions' => ['player' => "
        . "['destinationMap' => 'cellar', 'spawnPoint' => ['x' => 1, 'y' => 1], 'spawnSprite' => ['South']]]];\n");
    $manifest = (string) file_get_contents($root . '/assets/Data/save-compatibility.php');
    $session = EditorSession::open($root);
    $session->beginChanges();
    $session->createMap('Cellar', null, 6, 4, 'cellar');
    $map = $session->readMap('cellar');
    $session->paint('cellar', $map['revision'], $map['baseLayer'], [[0, 0], [1, 0], [2, 0], [3, 0], [4, 0], [5, 0], [0, 1], [5, 1], [0, 2], [5, 2],
        [0, 3], [1, 3], [2, 3], [3, 3], [4, 3], [5, 3]], '#');

    // Whatever else the check finds of the unfinished map, it plans no save onto or off it.
    $outcome = $session->commitChanges(checkOnly: true);
    expect($outcome['relocated'])->toBe([])
        ->and((string) ($outcome['reason'] ?? ''))->not->toContain('Saves')
        ->and((string) file_get_contents($root . '/assets/Data/save-compatibility.php'))->toBe($manifest);
});


/** Current and saved ownership deliberately share territory while individual event identities remain distinct. */
function addSyntheticBoundStoryArea(\Ichiloto\Editor\ProjectMap $map, string $marker = 'Z'): void
{
    $map->setMapDataField(['areas'], [['id' => 'story-floor', 'area' => ['cells' => [[1, 1], [2, 1], [1, 2]]]]]);
    $map->setEventDefinition($marker, [
        'class' => \Ichiloto\Engine\Events\Triggers\ScriptEventTrigger::class,
        'subject' => ['kind' => 'area', 'id' => 'story-floor'],
        'data' => ['mode' => 'auto', 'reusable' => true, 'scriptId' => 'home-only'],
    ]);
}

function savedSyntheticBoundStoryAreaProject(): string
{
    $root = mapLineRemovalProject();
    $path = $root . '/assets/Maps/home/home.data.php';
    $data = require $path;
    $data['areas'] = [['id' => 'story-floor', 'area' => ['cells' => [[1, 1], [2, 1], [1, 2]]]]];
    $data['events']['Z'] = [
        'class' => \Ichiloto\Engine\Events\Triggers\ScriptEventTrigger::class,
        'subject' => ['kind' => 'area', 'id' => 'story-floor'],
        'data' => ['mode' => 'auto', 'reusable' => true, 'scriptId' => 'home-only'],
    ];
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    return $root;
}

it('relocates outside newly added bound areas even when the old origin lies inside the new territory', function () {
    $root = mapLineRemovalProject();
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    $map = getPhysicalOccupancyMap($session);
    addSyntheticBoundStoryArea($map);
    $plan = SaveRelocationPlanner::plan($map, $root);
    $area = \Ichiloto\Engine\Core\CellArea::fromCells([[1, 1], [2, 1], [1, 2]]);
    expect($plan)->not->toBeNull();
    expect(array_merge(...array_column($plan->relocations, 'cells')))->toEqualCanonicalizing([[1, 1], [2, 1], [1, 2]]);
    foreach ($plan->relocations as $move) { expect($area->containsCell(...$move['to']))->toBeFalse(); }
    expect(sourceHashTree($root))->toBe($before);
});

it('lands a save from inside an unchanged event outside every event, as the Engine requires of a landing', function () {
    $root = savedSyntheticBoundStoryAreaProject();
    $session = EditorSession::open($root);
    $map = getPhysicalOccupancyMap($session);
    // The saved event's own floor is the nearest plain floor; the landing still leaves the event.
    $map->setTileSymbol(1, 2, '#');
    $plan = SaveRelocationPlanner::plan($map, $root);
    $area = \Ichiloto\Engine\Core\CellArea::fromCells([[1, 1], [2, 1], [1, 2]]);
    expect($plan)->not->toBeNull()
        ->and(array_merge(...array_column($plan->relocations, 'cells')))->toBe([[1, 2]]);
    foreach ($plan->relocations as $move) {
        expect($area->containsCell(...$move['to']))->toBeFalse()
            ->and(getPhysicalOccupancyMap($session)->getEventMarkerAt(...$move['to']))->toBeNull();
    }
});

it('keeps a newly overlapping event separate from an old event on the same named area', function () {
    $root = savedSyntheticBoundStoryAreaProject();
    $session = EditorSession::open($root);
    $map = getPhysicalOccupancyMap($session);
    addSyntheticBoundStoryArea($map, 'Y');
    $plan = SaveRelocationPlanner::plan($map, $root);
    $area = \Ichiloto\Engine\Core\CellArea::fromCells([[1, 1], [2, 1], [1, 2]]);
    expect($plan)->not->toBeNull();
    expect(array_merge(...array_column($plan->relocations, 'cells')))->toEqualCanonicalizing([[1, 1], [2, 1], [1, 2]]);
    foreach ($plan->relocations as $move) { expect($area->containsCell(...$move['to']))->toBeFalse(); }
});

it('treats replaced behavior under a reused marker as new event ownership', function () {
    $root = savedSyntheticBoundStoryAreaProject();
    $session = EditorSession::open($root);
    $map = getPhysicalOccupancyMap($session);
    $map->setEventField('Z', ['data', 'reusable'], false);
    $plan = SaveRelocationPlanner::plan($map, $root);
    expect($plan)->not->toBeNull();
    foreach ($plan->relocations as $move) {
        expect(in_array($move['to'], [[1, 1], [2, 1], [1, 2]], true))->toBeFalse();
    }
});
