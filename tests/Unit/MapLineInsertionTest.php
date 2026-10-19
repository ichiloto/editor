<?php

declare(strict_types=1);

use Ichiloto\Editor\Maps\TileLayerSource;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * Inserting rows or columns into a map moves everything in its space at or
 * beyond the line, in every file, as one undoable write. The fixture is
 * {@see mapLineProject()}.
 */

it('splices blank rows into every grid, keeping styled cells and the rows that only move', function () {
    $root = mapLineProject();
    $plan = planLineInsertion($root, 'y', 2, 2);
    $plan->apply();
    $home = $root . '/assets/Maps/home';

    expect(MapGridSource::readFile($home . '/layers/01.terrain.map.php'))
        ->toBe("<fg=green>######</>\n#....#\n      \n      \n#.<fg=red>~~</>.#\n#....#\n######")
        ->and(MapGridSource::readFile($home . '/layers/02.detail.deco.php'))
        ->toBe("      \n  d   \n      \n      \n      \n    d \n      ")
        ->and(MapGridSource::readFile($home . '/home.event.php'))
        ->toBe("      \n    W \n      \n      \n      \n D  S \n      ")
        ->and(file_get_contents($home . '/layers/01.terrain.map.php'))->toContain("// keep layers/01.terrain.map.php\n")
        ->and(TileLayerSource::readLayer((string) file_get_contents($home . '/graphics/01.floor.tiles.php'), 'floor')->getEntries())
        ->toBe([
            array_fill(0, 6, '2816'), array_fill(0, 6, '2816'), array_fill(0, 6, '0'), array_fill(0, 6, '0'),
            array_fill(0, 6, '5'), array_fill(0, 6, '2816'), array_fill(0, 6, '2816'),
        ])
        ->and(file_get_contents($home . '/graphics/01.floor.tiles.php'))->toContain('// Painted in the GUI editor.');

    $map = ProjectMap::fromDirectory($root . '/assets/Maps', $home);
    expect($map->getHeight())->toBe(7)
        ->and($map->getEventBounds('D'))->toMatchArray(['x' => 1, 'y' => 5]);
});

it('splices blank columns into every grid, keeping styled runs', function () {
    $root = mapLineProject();
    planLineInsertion($root, 'x', 1, 1)->apply();
    $home = $root . '/assets/Maps/home';

    expect(MapGridSource::readFile($home . '/layers/01.terrain.map.php'))
        ->toBe("<fg=green>#</> <fg=green>#####</>\n# ....#\n# .<fg=red>~~</>.#\n# ....#\n# #####")
        ->and(MapGridSource::readFile($home . '/home.event.php'))
        ->toBe("       \n     W \n       \n  D  S \n       ")
        ->and(TileLayerSource::readLayer((string) file_get_contents($home . '/graphics/01.floor.tiles.php'), 'floor')->getEntries()[2])
        ->toBe(['5', '0', '5', '5', '5', '5', '5'])
        ->and((require $home . '/home.data.php')['npcs'][0])->toMatchArray(['x' => 3, 'y' => 3])
        ->and((require $home . '/home.data.php')['npcs'][0]['wanderArea'])->toBe(['x' => 2, 'y' => 1, 'width' => 3, 'height' => 3]);
});

it('moves points, stretches straddling areas and follows transfers into the map from every file', function () {
    $root = mapLineProject();
    $plan = planLineInsertion($root, 'y', 2, 2);
    $plan->apply();
    $home = require $root . '/assets/Maps/home/home.data.php';
    $town = require $root . '/assets/Maps/town/town.data.php';

    expect($home['npcs'][0])->toMatchArray(['x' => 2, 'y' => 5])
        ->and($home['npcs'][0]['wanderArea'])->toBe(['x' => 1, 'y' => 1, 'width' => 3, 'height' => 5])
        ->and($home['npcs'][1])->toMatchArray(['x' => 4, 'y' => 0])
        // The script moves the player on home, then leaves: only the first move is home's.
        ->and($home['npcs'][1]['script'])->toBe([
            ['type' => 'move_player', 'x' => 1, 'y' => 6],
            ['type' => 'transfer', 'map' => 'town', 'x' => 2, 'y' => 3],
            ['type' => 'move_player', 'x' => 3, 'y' => 3],
        ])
        ->and($home['events']['D']['data']['spawnPoint'])->toBe(['x' => 1, 'y' => 6])
        ->and($home['station'])->toBe(['x' => 3, 'y' => 3])
        ->and($home['tileLayers'])->toBe(['floor' => ['offset' => [0, 3]]])
        ->and($town['events']['A']['data']['spawnPoint'])->toBe(['x' => 2, 'y' => 6])
        ->and($town['events']['B']['data']['spawnPoint'])->toBe(['x' => 2, 'y' => 4])
        // Town's guard transfers into home; what follows is in home's space, one axis at a time.
        ->and($town['npcs'][0]['script'])->toBe([
            ['type' => 'transfer', 'map' => 'home', 'x' => 1, 'y' => 5],
            ['type' => 'move_player', 'x' => 2, 'y' => 6],
            ['type' => 'move_route', 'subject' => 'player', 'waypoints' => [['x' => 5], ['y' => 6]]],
        ])
        ->and((require $root . '/assets/Data/system.php')['startingPositions']['player']['spawnPoint'])->toBe(['x' => 1, 'y' => 6])
        ->and(require $root . '/assets/Events/home-only.php')->toBe([
            ['type' => 'move_player', 'x' => 3, 'y' => 6],
            ['type' => 'camera', 'operation' => 'pan', 'target' => ['kind' => 'position', 'x' => 2, 'y' => 1], 'seconds' => 1.0],
        ])
        ->and(file_get_contents($root . '/assets/Events/home-only.php'))->toStartWith("<?php\n\n// Runs only from home.\n");
});

it('follows a transfer in one parallel lane into every lane and past the block', function () {
    $root = mapLineProject();
    $path = $root . '/assets/Maps/town/town.data.php';
    $town = require $path;
    $town['npcs'][0]['script'] = [
        ['type' => 'parallel', 'lanes' => [
            [['type' => 'wait', 'seconds' => 1.0], ['type' => 'transfer', 'map' => 'home', 'x' => 1, 'y' => 3]],
            ['id' => 'walk', 'commands' => [['type' => 'move_player', 'x' => 2, 'y' => 3]]],
        ]],
        ['type' => 'move_player', 'x' => 1, 'y' => 3],
    ];
    file_put_contents($path, "<?php\n\nreturn " . var_export($town, true) . ";\n");

    $plan = planLineInsertion($root, 'y', 2, 2);
    $plan->apply();
    $script = (require $path)['npcs'][0]['script'];

    // The second lane can run before or after the first lane's transfer.
    expect(describeMapLineHandEdits($plan))->toContain('assets/Maps/town/town.data.php npcs.0.script.0.lanes.1.commands.0.y')
        ->and($script[0]['lanes'][0][1])->toMatchArray(['map' => 'home', 'y' => 5])
        ->and($script[0]['lanes'][1]['commands'][0])->toMatchArray(['y' => 3])
        // Once every lane is done the player is on home.
        ->and($script[1])->toBe(['type' => 'move_player', 'x' => 1, 'y' => 5]);
});

it('reports what it cannot rewrite instead of guessing or flattening it', function () {
    $root = mapLineProject();
    $shared = (string) file_get_contents($root . '/assets/Events/shared.php');
    $plan = planLineInsertion($root, 'y', 2, 2);

    expect(describeMapLineHandEdits($plan))->toEqualCanonicalizing([
        'assets/Maps/home/home.data.php npcs.2.y',
        'assets/Events/shared.php 0.y',
    ])
        ->and(array_column($plan->handEdits, 'reason'))->toContain('it may run on home or town')
        ->and(array_map(static fn(string $path): string => substr($path, strlen($root) + 1), $plan->getChangedPaths()))
        ->not->toContain('assets/Events/shared.php');

    $plan->apply();
    $source = (string) file_get_contents($root . '/assets/Maps/home/home.data.php');
    expect($source)->toContain("'x' => 1, 'y' => 2 + 1]")
        ->and($source)->toContain("// The house.\n")
        ->and(file_get_contents($root . '/assets/Events/shared.php'))->toBe($shared);
});

it('raises the save content version with a declarative map shift step', function () {
    $root = mapLineProject();
    $plan = planLineInsertion($root, 'y', 2, 2);
    expect($plan->notes)->toBe(['Saves move with the map: save content version 1 adds this map shift.']);
    $plan->apply();

    expect(require $root . '/assets/Data/save-compatibility.php')->toBe([
        'contentVersion' => 1,
        'migrations' => [['from' => 0, 'to' => 1, 'mapShifts' => [['map' => 'home', 'axis' => 'y', 'at' => 2, 'by' => 2]]]],
        'aliases' => [],
        'tombstones' => [],
    ])
        ->and(Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest::fromProjectRoot($root)->createMigrationFrom(0))
        ->toBeInstanceOf(Ichiloto\Engine\IO\SaveCompatibility\DeclaredPositionContentMigration::class)
        ->and(describeSaveCompatibilityIssues($root))->toBe([]);
});

it('says existing saves are not migrated when the project has no save manifest', function () {
    $root = mapLineProject();
    unlink($root . '/assets/Data/save-compatibility.php');

    expect(planLineInsertion($root, 'x', 0, 1)->notes)
        ->toBe(['Existing saves are not migrated: the project has no assets/Data/save-compatibility.php.']);
});

it('moves a cutscene cast and script that start on the map, and a finalizer transfer into it', function () {
    $root = mapLineProject();
    $directory = $root . '/assets/Cutscenes/Cinematics/arrival';
    mkdir($directory, 0o777, true);
    file_put_contents($directory . '/arrival.data.php', "<?php\n\nreturn " . var_export([
        'id' => 'arrival', 'name' => 'Arrival', 'startMap' => 'home',
        'cast' => [['id' => 'visitor', 'sprite' => 'v', 'x' => 2, 'y' => 4]],
        'finalizer' => [['type' => 'transfer', 'map' => 'home', 'x' => 1, 'y' => 3]],
    ], true) . ";\n");
    file_put_contents($directory . '/arrival.script.php', "<?php\n\nreturn " . var_export([
        ['type' => 'stage_actor', 'id' => 'extra', 'sprite' => 'e', 'x' => 3, 'y' => 3],
        ['type' => 'transfer', 'map' => 'town', 'x' => 1, 'y' => 1],
        ['type' => 'field_animation', 'animation' => 'spark', 'target' => ['kind' => 'position', 'x' => 1, 'y' => 1]],
    ], true) . ";\n");

    $plan = planLineInsertion($root, 'y', 2, 1);
    $plan->apply();

    expect((require $directory . '/arrival.data.php')['cast'][0])->toMatchArray(['x' => 2, 'y' => 5])
        ->and((require $directory . '/arrival.data.php')['finalizer'][0])->toMatchArray(['x' => 1, 'y' => 4])
        ->and((require $directory . '/arrival.script.php')[0])->toMatchArray(['x' => 3, 'y' => 4])
        ->and((require $directory . '/arrival.script.php')[2]['target'])->toMatchArray(['y' => 1]);
});

it('inserts through the palette after a count and a confirmation, and one undo restores every file', function () {
    $root = mapLineProject();
    $before = sourceHashTree($root . '/assets');
    $editor = mapLineEditor($root);
    $workspace = getEditorProperty($editor, 'workspace');
    setEditorProperty($editor, 'cursorY', 2);

    openMapLinePaletteItem($editor, 'Map: Insert rows above the cursor');
    expect(getEditorProperty($editor, 'modals')->has(Ichiloto\Editor\UI\Modal::MAP_LINES))->toBeTrue();
    pressKeys($editor, "\177", '2', "\r");

    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeTrue()
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
    $entries = getEditorProperty($editor, 'eventOptionDialogEntries');
    $labels = array_column($entries, 'label');
    expect(getEditorProperty($editor, 'eventOptionDialogTitle'))->toBe('Insert 2 rows above row 3 of home')
        ->and($entries[1]['description'])->toBe('Writes now, not on Save. Ctrl+Z restores files.')
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('Writes now, not on Save. Ctrl+Z restores files.')
        ->and($labels)->toContain('assets/Maps/home/layers/01.terrain.map.php')
        ->and($labels)->toContain('assets/Data/system.php')
        ->and($labels)->toContain('Hand edit: assets/Events/shared.php 0.y')
        ->and($labels)->toContain('Saves move with the map: save content version 1 adds this map shift.');

    pressKeys($editor, "\033[B", "\r");
    $after = sourceHashTree($root . '/assets');
    expect($after)->not->toBe($before)
        ->and(getEditorProperty($editor, 'workspace'))->not->toBe($workspace)
        ->and(getEditorProperty($editor, 'workspace')->getMapByIndex(0)->getHeight())->toBe(7)
        ->and(getEditorProperty($editor, 'workspace')->hasUnsavedChanges())->toBeFalse();

    pressKeys($editor, "\x1a");
    expect(sourceHashTree($root . '/assets'))->toBe($before)
        ->and(getEditorProperty($editor, 'workspace'))->toBe($workspace)
        ->and($workspace->getMapByIndex(0)->getHeight())->toBe(5);

    pressKeys($editor, "\x19");
    expect(sourceHashTree($root . '/assets'))->toBe($after)
        ->and(getEditorProperty($editor, 'workspace')->getMapByIndex(0)->getHeight())->toBe(7);
});

it('cancels from the count prompt and from the confirmation without writing', function () {
    $root = mapLineProject();
    $before = sourceHashTree($root . '/assets');
    $editor = mapLineEditor($root);

    openMapLinePaletteItem($editor, 'Map: Insert columns left of the cursor');
    pressKeys($editor, "\033");
    expect(getEditorProperty($editor, 'modals')->has(Ichiloto\Editor\UI\Modal::MAP_LINES))->toBeFalse();

    openMapLinePaletteItem($editor, 'Map: Insert columns left of the cursor');
    pressKeys($editor, "\177", '0', "\r");
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('at least 1');
    pressKeys($editor, "\033");
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});

it('refuses to plan while the workspace has unsaved changes', function () {
    $root = mapLineProject();
    $before = sourceHashTree($root . '/assets');
    $editor = mapLineEditor($root);
    getEditorProperty($editor, 'workspace')->getMapByIndex(0)->setTileSymbol(1, 1, 'x');

    openMapLinePaletteItem($editor, 'Map: Insert rows above the cursor');

    expect(getEditorProperty($editor, 'modals')->has(Ichiloto\Editor\UI\Modal::MAP_LINES))->toBeFalse()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('Save or undo pending edits')
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});

it('offers the insert commands only on a map in Map mode', function () {
    $editor = mapLineEditor(mapLineProject());
    $labels = static fn(): array => array_map(static fn($item): string => $item->label, callEditorMethod($editor, 'buildPaletteItems'));

    expect($labels())->toContain('Map: Insert rows above the cursor')
        ->and($labels())->toContain('Map: Insert columns left of the cursor');
    callEditorMethod($editor, 'setEditingMode', 'event');
    expect($labels())->not->toContain('Map: Insert rows above the cursor');
});

it('reports coordinates in a list built with a spread instead of rewriting the wrong entry', function () {
    $root = mapLineProject();
    $path = $root . '/assets/Maps/home/home.data.php';
    file_put_contents($path, str_replace("  'npcs' => [\n", "  'npcs' => [\n    ...array_map(static fn(int \$y): array => ['id' => \"post-{\$y}\", 'name' => 'Post', 'sprite' => '|', 'x' => 5, 'y' => \$y], [3]),\n", (string) file_get_contents($path)));
    $source = (string) file_get_contents($path);
    $plan = planLineInsertion($root, 'y', 2, 1);
    $plan->apply();

    expect(describeMapLineHandEdits($plan))->toContain('assets/Maps/home/home.data.php npcs.0.y')
        ->and(describeMapLineHandEdits($plan))->toContain('assets/Maps/home/home.data.php npcs.1.y')
        ->and(array_column(array_filter($plan->handEdits, static fn(array $edit): bool => $edit['where'] === 'npcs.1.y'), 'reason'))
        ->toBe(['it is inside an array built with a spread or a computed key'])
        // Everything else in the file still moves.
        ->and((require $path)['events']['D']['data']['spawnPoint'])->toBe(['x' => 1, 'y' => 5])
        ->and(substr((string) file_get_contents($path), 0, strpos($source, "  'events' =>")))
        ->toBe(substr($source, 0, strpos($source, "  'events' =>")));
});

it('validates declarative map shift steps as the Engine reads them', function () {
    $root = mapLineProject();
    file_put_contents($root . '/assets/Data/save-compatibility.php', "<?php\n\nreturn " . var_export([
        'contentVersion' => 2,
        'migrations' => [
            ['from' => 0, 'to' => 1, 'mapShifts' => [['map' => 'home', 'axis' => 'z', 'at' => 0, 'by' => 1]]],
            ['from' => 1, 'to' => 2, 'class' => 'Game\\Save\\Step', 'mapShifts' => [['map' => 'home', 'axis' => 'y', 'at' => 0, 'by' => 1]]],
        ],
        'aliases' => [],
        'tombstones' => [],
    ], true) . ";\n");
    $messages = describeSaveCompatibilityIssues($root);

    expect(implode("\n", $messages))->toContain('axis must be "x" or "y"')
        ->and(implode("\n", $messages))->toContain('must declare either a class or declared position edits');
});

it('validates declarative relocation steps as the Engine reads them', function () {
    $root = mapLineProject();
    $write = static fn(array $migrations, int $version) => file_put_contents($root . '/assets/Data/save-compatibility.php', "<?php\n\nreturn " . var_export([
        'contentVersion' => $version,
        'migrations' => $migrations,
        'aliases' => [],
        'tombstones' => [],
    ], true) . ";\n");

    $write([
        ['from' => 0, 'to' => 1, 'mapShifts' => [['map' => 'home', 'axis' => 'y', 'at' => 2, 'by' => 1]],
            'relocations' => [['map' => 'home', 'cells' => [[3, 4]], 'to' => [3, 5]]]],
        ['from' => 1, 'to' => 2, 'relocations' => [['map' => 'home', 'cells' => [[1, 1]], 'to' => [1, 2]]]],
    ], 2);
    expect(describeSaveCompatibilityIssues($root))->toBe([]);

    $write([
        ['from' => 0, 'to' => 1, 'relocations' => [['map' => 'home', 'cells' => [[3, 4]], 'to' => [3, 4]]]],
        ['from' => 1, 'to' => 2, 'class' => 'Game\\Save\\Step', 'relocations' => [['map' => 'home', 'cells' => [[1, 1]], 'to' => [1, 2]]]],
    ], 2);
    $messages = implode("\n", describeSaveCompatibilityIssues($root));

    expect($messages)->toContain('is one of the cells it moves players off')
        ->and($messages)->toContain('must declare either a class or declared position edits');
});
