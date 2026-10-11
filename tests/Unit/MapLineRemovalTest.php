<?php

declare(strict_types=1);

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLinePlanner;
use Ichiloto\Editor\Maps\TileLayerSource;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * Removing rows or columns from a map moves everything beyond them back, in
 * every file, as one undoable write, and refuses while anything authored
 * stands on a removed line. Saves standing there land on a reachable cell.
 * The fixture is {@see mapLineRemovalProject()}.
 */

it('splices rows out of every grid, keeping styled cells and the rows that only move', function () {
    $root = mapLineRemovalProject();
    planLineRemoval($root, 'y', 2, 1)->apply();
    $home = $root . '/assets/Maps/home';

    expect(MapGridSource::readFile($home . '/layers/01.terrain.map.php'))->toBe("<fg=green>######</>\n#....#\n#....#\n#..###")
        ->and(MapGridSource::readFile($home . '/layers/02.detail.deco.php'))->toBe("      \n  d   \n    d \n      ")
        ->and(MapGridSource::readFile($home . '/home.event.php'))->toBe("      \n    W \n D  S \n      ")
        ->and(file_get_contents($home . '/layers/01.terrain.map.php'))->toContain("// keep layers/01.terrain.map.php\n")
        ->and(TileLayerSource::readLayer((string) file_get_contents($home . '/graphics/01.floor.tiles.php'), 'floor')->getEntries())
        ->toBe(array_fill(0, 4, array_fill(0, 6, '2816')))
        ->and(file_get_contents($home . '/graphics/01.floor.tiles.php'))->toContain('// Painted in the GUI editor.');

    $map = ProjectMap::fromDirectory($root . '/assets/Maps', $home);
    expect($map->getHeight())->toBe(4)
        ->and($map->getEventBounds('D'))->toMatchArray(['x' => 1, 'y' => 2]);
});

it('moves what is beyond the removed lines back, shrinks straddling areas and follows transfers into the map', function () {
    $root = mapLineRemovalProject();
    $plan = planLineRemoval($root, 'y', 2, 1);
    $plan->apply();
    $home = require $root . '/assets/Maps/home/home.data.php';
    $town = require $root . '/assets/Maps/town/town.data.php';

    expect($home['npcs'][0])->toMatchArray(['x' => 2, 'y' => 2])
        ->and($home['npcs'][0]['wanderArea'])->toBe(['x' => 1, 'y' => 1, 'width' => 3, 'height' => 2])
        ->and($home['npcs'][1])->toMatchArray(['x' => 4, 'y' => 0])
        ->and($home['npcs'][1]['script'][0])->toBe(['type' => 'move_player', 'x' => 1, 'y' => 3])
        // After the transfer the script is on town, which keeps its rows.
        ->and($home['npcs'][1]['script'][2])->toBe(['type' => 'move_player', 'x' => 3, 'y' => 3])
        ->and($home['events']['D']['data']['spawnPoint'])->toBe(['x' => 1, 'y' => 3])
        ->and($town['events']['A']['data']['spawnPoint'])->toBe(['x' => 2, 'y' => 3])
        ->and($town['events']['B']['data']['spawnPoint'])->toBe(['x' => 2, 'y' => 4])
        ->and($town['npcs'][0]['script'])->toBe([
            ['type' => 'transfer', 'map' => 'home', 'x' => 1, 'y' => 2],
            ['type' => 'move_player', 'x' => 2, 'y' => 3],
            ['type' => 'move_route', 'subject' => 'player', 'waypoints' => [['x' => 5], ['y' => 3]]],
        ])
        ->and((require $root . '/assets/Data/system.php')['startingPositions']['player']['spawnPoint'])->toBe(['x' => 1, 'y' => 3])
        ->and((require $root . '/assets/Events/home-only.php')[0])->toBe(['type' => 'move_player', 'x' => 3, 'y' => 3])
        ->and(describeMapLineHandEdits($plan))->toEqualCanonicalizing([
            'assets/Maps/home/home.data.php npcs.2.y',
            'assets/Events/shared.php 0.y',
        ]);
});

it('refuses to remove a line an event marker stands on, writing nothing', function () {
    $root = mapLineRemovalProject();
    $before = sourceHashTree($root . '/assets');

    expect(fn() => planLineRemoval($root, 'y', 3, 1))
        ->toThrow(MapSourceRefusal::class, 'Move these off the removed rows first; nothing was changed: event D at x 1, y 3; event S at x 4, y 3.')
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});

it('refuses to remove lines that NPCs, spawn points, starting positions or scripts stand on, naming each one', function () {
    $root = mapLineRemovalProject();
    $before = sourceHashTree($root . '/assets');

    try {
        planLineRemoval($root, 'y', 4, 1);
        $this->fail('The removal should be refused.');
    } catch (RuntimeException $refusal) {
        expect($refusal->getMessage())->toStartWith('Remove 1 row from row 5 of home is refused; nothing was changed.')
            ->and($refusal->getMessage())->toContain('assets/Maps/home/home.data.php events.D.data.spawnPoint.y (it stands on a removed line)')
            ->and($refusal->getMessage())->toContain('assets/Maps/town/town.data.php events.A.data.spawnPoint.y')
            ->and($refusal->getMessage())->toContain('assets/Data/system.php startingPositions.player.spawnPoint.y')
            ->and($refusal->getMessage())->toContain('assets/Events/home-only.php 0.y');
    }
    expect(fn() => planLineRemoval($root, 'x', 2, 1))->toThrow(RuntimeException::class, 'assets/Maps/home/home.data.php npcs.0.x (it stands on a removed line)')
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});

it('moves spans beyond removed lines back, shrinks straddling ones and loses only spans wholly removed', function () {
    $removal = new Ichiloto\Editor\Maps\LineRemoval('home', 'y', 2, 2);

    expect($removal->getShiftedSpan(0, 2))->toBe([0, 2])
        ->and($removal->getShiftedSpan(1, 2))->toBe([1, 1])
        ->and($removal->getShiftedSpan(1, 5))->toBe([1, 3])
        ->and($removal->getShiftedSpan(3, 3))->toBe([2, 2])
        ->and($removal->getShiftedSpan(4, 1))->toBe([2, 1])
        ->and($removal->getShiftedSpan(2, 2))->toBeNull()
        ->and($removal->getShiftedCoordinate(3))->toBeNull()
        ->and($removal->getShiftedCoordinate(5))->toBe(3)
        ->and($removal->getShiftedCoordinate(1))->toBe(1);
});

it('refuses lines outside the map and a removal that would leave it empty', function () {
    $root = mapLineRemovalProject();

    expect(fn() => planLineRemoval($root, 'y', 4, 2))->toThrow(MapSourceRefusal::class, 'home has no rows 5 to 6 to remove')
        ->and(fn() => planLineRemoval($root, 'x', 0, 6))->toThrow(MapSourceRefusal::class, 'would leave home empty');
});

it('refuses a removal through a world object, which is drawn whole, and moves one beyond it back', function () {
    $inventory = new Ichiloto\Editor\Maps\MapLineInventory(new Ichiloto\Editor\Maps\LineRemoval('home', 'y', 2, 1), []);
    $inventory->addMapData('home.data.php', 'home', ['worldObjects' => [
        ['id' => 'shelf', 'resource' => 'shelf', 'anchor' => ['x' => 3, 'y' => 1], 'covers' => ['layer' => 'terrain', 'cells' => [[3, 1], [3, 2]]]],
        ['id' => 'stool', 'resource' => 'stool', 'anchor' => ['x' => 1, 'y' => 3], 'covers' => ['layer' => 'terrain', 'cells' => [[1, 3]]]],
    ]]);

    expect($inventory->getBlocked()['home.data.php'])->toBe([
        ['path' => ['worldObjects', 0, 'covers', 'cells', 1, 1], 'reason' => 'world object shelf covers a cell on a removed line'],
    ])
        ->and($inventory->getShifts()['home.data.php'])->toBe([
            ['path' => ['worldObjects', 1, 'anchor', 'y'], 'value' => 2],
            ['path' => ['worldObjects', 1, 'covers', 'cells', 0, 1], 'value' => 2],
        ]);
});

it('lands saves standing on removed lines on the reachable cell nearest them, in the map after the removal', function () {
    $root = mapLineRemovalProject();
    $plan = planLineRemoval($root, 'y', 2, 1);

    expect($plan->edit->landing)->toBe([2, 1])
        ->and($plan->notes)->toBe([
            'Saves standing on the removed lines land at x 2, y 1.',
            'Saves move with the map: save content version 1 adds this map removal.',
        ]);
    $plan->apply();

    expect(require $root . '/assets/Data/save-compatibility.php')->toBe([
        'contentVersion' => 1,
        'migrations' => [['from' => 0, 'to' => 1, 'mapRemovals' => [['map' => 'home', 'axis' => 'y', 'at' => 2, 'count' => 1, 'to' => [2, 1]]]]],
        'aliases' => [],
        'tombstones' => [],
    ])
        ->and(Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest::fromProjectRoot($root)->createMigrationFrom(0))
        ->toBeInstanceOf(Ichiloto\Engine\IO\SaveCompatibility\DeclaredPositionContentMigration::class)
        ->and(describeSaveCompatibilityIssues($root))->toBe([]);
});

it('lands saves on the author\'s cell when it is reachable, and refuses one that is not', function () {
    $root = mapLineRemovalProject();

    expect(planLineRemoval($root, 'y', 2, 1, [3, 2])->edit->landing)->toBe([3, 2])
        ->and(fn() => planLineRemoval($root, 'y', 2, 1, [0, 1]))
        ->toThrow(RuntimeException::class, 'Saves cannot land at x 0, y 1: the player cannot reach that cell.');
});

it('says existing saves are not migrated, and needs no landing, when the project has no save manifest', function () {
    $root = mapLineRemovalProject();
    unlink($root . '/assets/Data/save-compatibility.php');
    unlink($root . '/assets/Maps/collisions.php');
    $plan = planLineRemoval($root, 'y', 2, 1);

    expect($plan->notes)->toBe(['Existing saves are not migrated: the project has no assets/Data/save-compatibility.php.'])
        ->and($plan->edit->landing)->toBeNull();
});

it('reports relative route steps that may cross the edited lines for a hand check', function () {
    $root = mapLineRemovalProject();
    $path = $root . '/assets/Events/home-only.php';
    file_put_contents($path, str_replace("return [\n", "return [\n  ['type' => 'move_route', 'subject' => 'player', 'steps' => [['direction' => 'down', 'count' => 2]]],\n",
        (string) file_get_contents($path)));

    expect(array_column(planLineRemoval($root, 'y', 2, 1)->handEdits, 'reason', 'where'))
        ->toMatchArray(['0.steps' => 'its relative steps may cross the removed lines; check where the route ends'])
        ->and(array_column(MapLinePlanner::planInsertion($root, 'home', 'y', 2, 1)->handEdits, 'reason', 'where'))
        ->toMatchArray(['0.steps' => 'its relative steps may cross the inserted lines; check where the route ends']);
});

it('removes through the palette after a count and a confirmation, and one undo restores every file', function () {
    $root = mapLineRemovalProject();
    $before = sourceHashTree($root . '/assets');
    $editor = mapLineEditor($root);
    setEditorProperty($editor, 'cursorY', 2);

    openMapLinePaletteItem($editor, 'Map: Remove rows from the cursor down');
    expect(getEditorProperty($editor, 'modals')->has(Ichiloto\Editor\UI\Modal::MAP_LINES))->toBeTrue();
    pressKeys($editor, "\r");

    $labels = array_column(getEditorProperty($editor, 'eventOptionDialogEntries'), 'label');
    expect(getEditorProperty($editor, 'eventOptionDialogTitle'))->toBe('Remove 1 row from row 3 of home')
        ->and($labels)->toContain('Saves standing on the removed lines land at x 2, y 1.')
        ->and($labels)->toContain('Hand edit: assets/Events/shared.php 0.y')
        ->and(sourceHashTree($root . '/assets'))->toBe($before);

    pressKeys($editor, "\033[B", "\r");
    expect(getEditorProperty($editor, 'workspace')->getMapByIndex(0)->getHeight())->toBe(4)
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('Removed 1 row from row 3 of home.');

    pressKeys($editor, "\x1a");
    expect(sourceHashTree($root . '/assets'))->toBe($before)
        ->and(getEditorProperty($editor, 'workspace')->getMapByIndex(0)->getHeight())->toBe(5);
});

it('reports a refused removal in the status line without opening a confirmation', function () {
    $root = mapLineRemovalProject();
    $before = sourceHashTree($root . '/assets');
    $editor = mapLineEditor($root);
    setEditorProperty($editor, 'cursorY', 4);

    openMapLinePaletteItem($editor, 'Map: Remove rows from the cursor down');
    pressKeys($editor, "\r");

    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('is refused; nothing was changed.')
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});
