<?php

declare(strict_types=1);

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;

/**
 * The production map lifecycle -- create, duplicate, delete through
 * `ProjectWorkspace` -- carries the same contract as the ordinary save:
 * the triplet installs or vanishes as one, authored source is preserved or
 * the operation is refused by name, and nothing the author owns rides
 * along with a deletion.
 */

/**
 * A workspace over the authored fixture map (use import, comments, enum,
 * require-fed variable, inline require, legacy NPC).
 */
function lifecycleWorkspace(string $root): ProjectWorkspace
{
    return ProjectWorkspace::fromProject($root);
}

// -- Creation -----------------------------------------------------------------

it('creates the complete triplet through the workspace, or nothing at all', function () {
    $root = authoredMapProject();
    $workspace = lifecycleWorkspace($root);

    // The failure: the second member cannot be installed. No file and no
    // folder survives -- the defect was a folder holding only a data file.
    $mapPath = $root . '/assets/Maps/new-map/new-map.map.php';
    $failing = new FailingFileSetOperations(failures: ['move' => [$mapPath]]);

    expect(fn() => $workspace->createMap(files: $failing))->toThrow(FileSetTransactionFailure::class);
    expect(is_dir($root . '/assets/Maps/new-map'))->toBeFalse('no partial map folder remains');

    // The same creation succeeds cleanly, canonically, and reopens
    // identically.
    $baseName = $workspace->createMap();
    $directory = $root . '/assets/Maps/' . $baseName;

    expect(is_file($directory . '/' . $baseName . '.data.php'))->toBeTrue()
        ->and(is_file($directory . '/' . $baseName . '.map.php'))->toBeTrue()
        ->and(is_file($directory . '/' . $baseName . '.event.php'))->toBeTrue();

    $created = ProjectMap::fromDirectory($root . '/assets/Maps', $directory);
    expect($created->isDirty())->toBeFalse()
        ->and($created->dataSourceIssue())->toBeNull()
        ->and($created->getDisplayName())->toBe('New Map');

    // Canonical means already-saved: saving the fresh map writes nothing.
    $before = tripletState($created);
    $created->save();
    expect(tripletState($created))->toBe($before);
});

// -- Duplication --------------------------------------------------------------

it('duplicates through the workspace with authored source preserved, or not at all', function () {
    $root = authoredMapProject();
    $workspace = lifecycleWorkspace($root);
    $index = array_search('test-map', $workspace->mapIds, true);

    // Failure on the third member: no duplicate files, no duplicate folder.
    $eventPath = $root . '/assets/Maps/test-map-copy/test-map-copy.event.php';
    $failing = new FailingFileSetOperations(failures: ['move' => [$eventPath]]);

    expect(fn() => $workspace->duplicateMap($index, $failing))->toThrow(FileSetTransactionFailure::class);
    expect(is_dir($root . '/assets/Maps/test-map-copy'))->toBeFalse('no partial duplicate remains');

    // The clean duplicate carries the authored bytes -- comments, imports,
    // enum expressions, the require-fed variable and the inline require --
    // with only the display name rewritten.
    $duplicatedId = $workspace->duplicateMap($index);
    expect($duplicatedId)->toBe('test-map-copy');
    $data = (string) file_get_contents($root . '/assets/Maps/test-map-copy/test-map-copy.data.php');

    expect($data)->toContain('use Ichiloto\Engine\Core\Enumerations\MovementHeading;')
        ->and($data)->toContain('// The harbour district, hand-annotated.')
        ->and($data)->toContain("\$watchScript = require dirname(__DIR__, 2) . '/Events/harbour-watch.php';")
        ->and($data)->toContain("'script' => require dirname(__DIR__, 2) . '/Events/harbour-watch.php',")
        ->and($data)->toContain('[MovementHeading::NORTH->value]')
        ->and($data)->toContain("'name' => 'Test Map Copy',")
        ->and($data)->not->toContain("'name' => 'Test Map',");

    // And it evaluates as a real map with the new name.
    $duplicate = ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/test-map-copy');
    expect($duplicate->getDisplayName())->toBe('Test Map Copy')
        ->and($duplicate->isDirty())->toBeFalse()
        ->and($duplicate->getEventField('W', ['data', 'script']))->toBe(require $root . '/assets/Events/harbour-watch.php');
});

it('refuses to duplicate a map whose source it cannot preserve, before creating anything', function () {
    $root = makeTemporaryProject('ichiloto-map-opaque-dup-');
    file_put_contents(
        $root . '/assets/Maps/test-map/test-map.data.php',
        "<?php\n\nreturn array_merge(['name' => 'Computed'], ['region' => '']);\n",
    );
    $workspace = lifecycleWorkspace($root);
    $index = array_search('test-map', $workspace->mapIds, true);

    // The defect: duplication silently flattened this to a canonical
    // export. Now it refuses by name, and nothing is created.
    $refusal = null;

    try {
        $workspace->duplicateMap($index);
    } catch (MapSourceRefusal $thrown) {
        $refusal = $thrown;
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->getMessage())->toContain('test-map')
        ->and($refusal->getMessage())->toContain('cannot be duplicated')
        ->and(is_dir($root . '/assets/Maps/test-map-copy'))->toBeFalse('the refusal came before any file or folder');
});

it('carries unsaved edits into a duplicate, source-preserved', function () {
    $root = authoredMapProject();
    $workspace = lifecycleWorkspace($root);
    $index = array_search('test-map', $workspace->mapIds, true);
    $map = $workspace->getMapByIndex($index);
    $map->setMapField('description', 'Edited before the copy.');

    $workspace->duplicateMap($index);
    $data = (string) file_get_contents($root . '/assets/Maps/test-map-copy/test-map-copy.data.php');

    expect($data)->toContain('Edited before the copy.')
        ->and($data)->toContain('// The harbour district, hand-annotated.')
        // The original is still dirty and still unwritten.
        ->and($map->isDirty())->toBeTrue()
        ->and((string) file_get_contents($map->dataPath))->not->toContain('Edited before the copy.');
});

// -- Deletion -----------------------------------------------------------------

it('deletes only the owned triplet, keeps the author\'s own files, and removes the folder only when empty', function () {
    $root = authoredMapProject();
    $notes = $root . '/assets/Maps/test-map/design-notes.md';
    file_put_contents($notes, "The harbour layout sketch.\n");
    $workspace = lifecycleWorkspace($root);
    $index = array_search('test-map', $workspace->mapIds, true);

    // The defect: recursive deletion took the notes with the map.
    $deleted = $workspace->deleteMap($index);

    expect($deleted)->toBe('test-map')
        ->and(is_file($notes))->toBeTrue('the author\'s file survived')
        ->and(is_dir($root . '/assets/Maps/test-map'))->toBeTrue('the folder stays while anything unrelated remains')
        ->and(glob($root . '/assets/Maps/test-map/test-map.*'))->toBe([], 'the owned triplet is gone');

    // A map with nothing else in its folder takes the folder too.
    ProjectMap::createBlank($root . '/assets/Maps/empty-quarter', 'empty-quarter', 'Empty Quarter');
    $workspace = lifecycleWorkspace($root);
    $index = array_search('empty-quarter', $workspace->mapIds, true);
    $workspace->deleteMap($index);
    expect(is_dir($root . '/assets/Maps/empty-quarter'))->toBeFalse();
});

it('restores the complete triplet, bytes and modification times, when a deletion member fails', function (string $member) {
    $root = authoredMapProject();
    $workspace = lifecycleWorkspace($root);
    $index = array_search('test-map', $workspace->mapIds, true);
    $map = $workspace->getMapByIndex($index);
    backdateTriplet($map);
    $before = tripletState($map);

    $path = match ($member) {
        'data' => $map->dataPath,
        'map' => $map->mapPath,
        default => $map->eventPath,
    };
    $failing = new FailingFileSetOperations(failures: ['remove' => [$path]]);

    expect(fn() => $workspace->deleteMap($index, $failing))->toThrow(FileSetTransactionFailure::class);
    expect(tripletState($map))->toBe($before, 'every member is the bytes and mtime it was');
})->with(['data', 'map', 'event']);
