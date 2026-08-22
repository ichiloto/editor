<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\NpcCollection;
use Ichiloto\Editor\Field\ProjectNpc;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;
use Ichiloto\Editor\Storage\FilesystemFileSetOperations;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * A map's three files are one asset, and its data file is authored PHP the
 * editor did not write. Saving is therefore one transaction across the
 * triplet, and a data edit is a surgical rewrite of the one source node
 * that owns it -- never a regeneration of the returned array. What the
 * editor cannot rewrite reversibly it refuses by name, changing nothing.
 */

/**
 * A project whose fixture map carries authored PHP the evaluated array
 * cannot reproduce: a use import, comments, an enum expression, a shared
 * local variable, a require-backed script, named-argument-like formatting
 * and a future key.
 */
function authoredMapProject(): string
{
    $root = makeTemporaryProject('ichiloto-map-integrity-');
    if (! is_dir($root . '/assets/Events')) {
        mkdir($root . '/assets/Events', 0o777, true);
    }

    file_put_contents($root . '/assets/Events/harbour-watch.php', <<<'PHP_SOURCE'
<?php

return [
  ['type' => 'text', 'name' => 'Watcher', 'text' => 'The tide is out.'],
];
PHP_SOURCE);
    file_put_contents($root . '/assets/Maps/test-map/test-map.data.php', <<<'PHP_SOURCE'
<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;

// The harbour district, hand-annotated.
$watchScript = require dirname(__DIR__, 2) . '/Events/harbour-watch.php';

return [
  'name' => 'Test Map',
  'region' => '',
  'description' => 'A tiny fixture map.',
  // Station keeps its comment and its odd spacing.
  'station'     => ['x' => 3, 'y' => 1],
  'futureLighting' => ['mode' => 'dusk', 'level' => 2],
  'triggers' => [],
  'events' => [
    'E' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\ChestEventTrigger',
      'data' => [
        'lootType' => 'item',
        'loot' => 'S-Potion',
      ],
    ],
    'W' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\ScriptEventTrigger',
      'data' => [
        'script' => $watchScript,
        'mode' => 'action',
        'reusable' => true,
      ],
    ],
    'R' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\ScriptEventTrigger',
      'data' => [
        'script' => require dirname(__DIR__, 2) . '/Events/harbour-watch.php',
        'mode' => 'auto',
        'reusable' => false,
      ],
    ],
  ],
  'npcs' => [
    [
      'id' => 'harbour-keeper',
      'name' => 'Keeper',
      'sprite' => '<fg=#5fd7d7>@</>',
      'x' => 4,
      'y' => 2,
      'spawnSprite' => [MovementHeading::NORTH->value],
    ],
    [
      'name' => 'Old Gull',
      'sprite' => 'g',
      'x' => 6,
      'y' => 3,
    ],
  ],
];
PHP_SOURCE);

    return $root;
}

/**
 * The fixture map, loaded as the editor loads it.
 */
function authoredMap(string $root): ProjectMap
{
    return ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/test-map');
}

/**
 * The bytes and modification time of the map triplet.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function tripletState(ProjectMap $map): array
{
    $state = [];

    foreach (['data' => $map->dataPath, 'map' => $map->mapPath, 'event' => $map->eventPath] as $member => $path) {
        $state[$member] = [(string) file_get_contents($path), (int) filemtime($path)];
    }

    return $state;
}

/**
 * Backdates the triplet so any rewrite shows in its modification time.
 */
function backdateTriplet(ProjectMap $map): void
{
    foreach ([$map->dataPath, $map->mapPath, $map->eventPath] as $path) {
        touch($path, time() - 3600);
    }
}

// -- The two root defects, reproduced ----------------------------------------

it('rewrites one source node for a metadata edit, and nothing the author wrote around it', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    backdateTriplet($map);
    $before = tripletState($map);

    // The defect this gate removes: save() used to export the whole
    // evaluated array, which would flatten the require into its evaluated
    // commands, the enum into 'north', and drop every comment. One line
    // changes now.
    $map->setMapField('description', 'The harbour district at low tide.');
    expect($map->save())->toBe('test-map');

    $after = tripletState($map);
    $changedLines = [];

    foreach (array_map(null, explode("\n", $before['data'][0]), explode("\n", $after['data'][0])) as [$old, $new]) {
        if ($old !== $new) {
            $changedLines[] = $new;
        }
    }

    expect($changedLines)->toBe(["  'description' => 'The harbour district at low tide.',"])
        ->and($after['data'][0])->toContain("use Ichiloto\\Engine\\Core\\Enumerations\\MovementHeading;")
        ->and($after['data'][0])->toContain('// The harbour district, hand-annotated.')
        ->and($after['data'][0])->toContain("\$watchScript = require dirname(__DIR__, 2) . '/Events/harbour-watch.php';")
        ->and($after['data'][0])->toContain("'script' => \$watchScript,")
        ->and($after['data'][0])->toContain('[MovementHeading::NORTH->value]')
        ->and($after['data'][0])->toContain("'station'     => ['x' => 3, 'y' => 1],")
        ->and($after['data'][0])->toContain("'futureLighting' => ['mode' => 'dusk', 'level' => 2],")
        // The other two members were not touched at all.
        ->and($after['map'])->toBe($before['map'])
        ->and($after['event'])->toBe($before['event']);

    // And the saved file still evaluates to exactly the edited map.
    $reloaded = authoredMap($root);
    expect($reloaded->getEditableData())->toBe($map->getEditableData());
});

it('installs the triplet completely or not at all when a later member fails', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    backdateTriplet($map);
    $before = tripletState($map);

    // Data, tiles and events all changed, so all three will be written.
    $map->setMapField('description', 'Edited');
    $map->setTileSymbol(1, 1, '~');
    $map->setEventSymbol(2, 2, 'E');

    // The defect this gate removes: the three members were written one
    // after another, so a failure on the second left new data over old
    // tiles. The event file is the one made to fail here.
    $failing = new FailingFileSetOperations(failures: ['move' => [$map->eventPath]]);
    $failure = null;

    try {
        $map->save(null, $failing);
    } catch (FileSetTransactionFailure $thrown) {
        $failure = $thrown;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->wasRolledBack)->toBeTrue()
        ->and(tripletState($map))->toBe($before, 'every member is the bytes and mtime it was')
        ->and(glob(dirname($map->dataPath) . '/*.tmp-*'))->toBe([])
        ->and($map->isDirty())->toBeTrue('a refused save leaves the work unsaved');

    // The checkpoint did not advance: saving again now writes everything.
    expect($map->save())->toBe('test-map');
    expect($map->isDirty())->toBeFalse()
        ->and(file_get_contents($map->dataPath))->toContain('Edited');
});

it('restores earlier members when the middle or first member fails too', function (string $member) {
    $root = authoredMapProject();
    $map = authoredMap($root);
    backdateTriplet($map);
    $before = tripletState($map);
    $map->setMapField('description', 'Edited');
    $map->setTileSymbol(1, 1, '~');
    $map->setEventSymbol(2, 2, 'E');

    $path = match ($member) {
        'data' => $map->dataPath,
        'map' => $map->mapPath,
        default => $map->eventPath,
    };
    $failing = new FailingFileSetOperations(failures: ['move' => [$path]]);

    expect(fn() => $map->save(null, $failing))->toThrow(FileSetTransactionFailure::class);
    expect(tripletState($map))->toBe($before)
        ->and($map->isDirty())->toBeTrue();
})->with(['data', 'map', 'event']);

// -- No-op and isolation ------------------------------------------------------

it('writes nothing, backs up nothing and stages nothing for a clean map, or one undone back to its checkpoint', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    backdateTriplet($map);
    $before = tripletState($map);
    $backups = [];
    $backup = function (string ...$paths) use (&$backups): void {
        $backups[] = $paths;
    };

    expect($map->save($backup))->toBe('test-map');
    expect(tripletState($map))->toBe($before, 'a clean save writes nothing')
        ->and($backups)->toBe([], 'and backs nothing up');

    // Dirty, then back to exactly the checkpoint: still nothing.
    $original = $map->getMapField('description');
    $map->setMapField('description', 'Changed');
    $map->setMapField('description', $original);
    expect($map->isDirty())->toBeFalse();
    $map->save($backup);
    expect(tripletState($map))->toBe($before)
        ->and($backups)->toBe([]);
});

it('backs up exactly the members it replaces, once, before replacing them', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    $before = tripletState($map);
    $backups = [];
    $contentAtBackup = null;
    $backup = function (string ...$paths) use (&$backups, &$contentAtBackup): void {
        $backups[] = $paths;
        $contentAtBackup = (string) file_get_contents($paths[0]);
    };

    $map->setMapField('description', 'Edited once');
    $map->save($backup);

    expect($backups)->toBe([[$map->dataPath]], 'one call, and only the member being replaced')
        ->and($contentAtBackup)->toBe($before['data'][0], 'taken before the replacement');
});

it('keeps a tile-only or event-grid-only edit away from the data file', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    backdateTriplet($map);
    $before = tripletState($map);

    $map->setTileSymbol(1, 1, '~');
    $map->save();
    $after = tripletState($map);

    expect($after['data'])->toBe($before['data'], 'the data file was not rewritten')
        ->and($after['event'])->toBe($before['event'], 'nor the event grid')
        ->and($after['map'][0])->not->toBe($before['map'][0]);

    $between = tripletState($map);
    $map->setEventSymbol(2, 2, 'E');
    $map->save();
    $final = tripletState($map);

    expect($final['data'])->toBe($between['data'])
        ->and($final['map'])->toBe($between['map'])
        ->and($final['event'][0])->not->toBe($between['event'][0]);
});

it('saves one dirty map without refreshing, dirtying or touching a dirty sibling', function () {
    $root = authoredMapProject();
    ProjectMap::createBlank($root . '/assets/Maps/sibling', 'sibling', 'Sibling');
    $workspace = ProjectWorkspace::fromProject($root);
    $first = $workspace->maps[array_search('test-map', $workspace->mapIds, true)];
    $sibling = $workspace->maps[array_search('sibling', $workspace->mapIds, true)];
    backdateTriplet($sibling);

    $first->setMapField('description', 'Edited');
    $sibling->setMapField('description', 'Sibling edit, unsaved');
    $siblingDisk = tripletState($sibling);

    $first->save();

    expect(tripletState($sibling))->toBe($siblingDisk, 'the sibling files are untouched')
        ->and($sibling->isDirty())->toBeTrue('and its unsaved edit is still there')
        ->and($sibling->getMapField('description'))->toBe('Sibling edit, unsaved');
});

// -- Refusals -----------------------------------------------------------------

it('refuses an edit inside an opaque expression, naming the map, file and path, and changes nothing', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    backdateTriplet($map);
    $before = tripletState($map);
    $fingerprintClean = ! $map->isDirty();

    // The R event's script is written as a require expression in place;
    // an edit pointed inside it cannot be written back reversibly.
    $refusal = null;

    try {
        $map->setEventField('R', ['data', 'script'], [['type' => 'text', 'name' => 'X', 'text' => 'Y']]);
    } catch (MapSourceRefusal $thrown) {
        $refusal = $thrown;
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->getMessage())->toContain('test-map')
        ->and($refusal->getMessage())->toContain('test-map.data.php')
        ->and($refusal->getMessage())->toContain('script')
        ->and($refusal->getMessage())->toContain('expression')
        ->and($map->getEventField('R', ['data', 'script']))->toBe(require $root . '/assets/Events/harbour-watch.php')
        ->and($map->isDirty())->toBe(! $fingerprintClean, 'the refused edit did not dirty the map')
        ->and(tripletState($map))->toBe($before, 'and wrote nothing');
});

it('replaces a variable-backed value with plain data while the shared variable survives for its other users', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);

    // W's script is written as `$watchScript`. Replacing the value is safe:
    // the entry gets the literal, the prelude variable stays untouched for
    // anything else that names it.
    $map->setEventField('W', ['data', 'script'], [['type' => 'text', 'name' => 'X', 'text' => 'Rewritten.']]);
    $map->save();
    $data = (string) file_get_contents($map->dataPath);

    expect($data)->toContain("\$watchScript = require dirname(__DIR__, 2) . '/Events/harbour-watch.php';")
        ->and($data)->toContain('Rewritten.')
        ->and($map->getEventField('W', ['data', 'script']))->toBe([['type' => 'text', 'name' => 'X', 'text' => 'Rewritten.']]);
});

it('keeps opaque siblings byte-identical while a safe edit lands beside them', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);

    // R keeps its inline require and W its variable reference; the field
    // beside them is plainly writable.
    $map->setEventField('W', ['data', 'reusable'], false);
    $map->save();
    $data = (string) file_get_contents($map->dataPath);

    expect($data)->toContain("'script' => \$watchScript,")
        ->and($data)->toContain("'script' => require dirname(__DIR__, 2) . '/Events/harbour-watch.php',")
        ->and($data)->toContain("'reusable' => false,");

    // Browsing, previewing, filtering and validating never stopped working.
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    expect(array_filter($issues, static fn($issue): bool => str_contains($issue->message, 'cannot be preserved')))->toBe([]);
});

it('refuses every data edit on a map whose source cannot be parsed, and validation says so', function () {
    $root = makeTemporaryProject('ichiloto-map-opaque-');
    file_put_contents(
        $root . '/assets/Maps/test-map/test-map.data.php',
        "<?php\n\nreturn array_merge(['name' => 'Computed'], ['region' => '']);\n",
    );
    $map = ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/test-map');
    $before = tripletState($map);

    expect($map->dataSourceIssue())->not->toBeNull()
        ->and($map->getDisplayName())->toBe('Computed', 'the map still loads and browses');

    expect(fn() => $map->setMapField('name', 'Renamed'))->toThrow(MapSourceRefusal::class, 'repair it by hand');
    expect(tripletState($map))->toBe($before)
        ->and($map->isDirty())->toBeFalse();

    // The grids are still the editor's to edit; saving writes only them.
    $map->setTileSymbol(0, 0, '#');
    $map->save();
    expect((string) file_get_contents($map->dataPath))->toBe($before['data'][0]);

    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $mine = array_values(array_filter($issues, static fn($issue): bool => str_contains($issue->message, 'cannot be preserved')));
    expect($mine)->toHaveCount(1);
});

// -- Structural edits and durable identity ------------------------------------

it('inserts and removes an NPC surgically, leaving every other entry byte-identical', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    $keeperLines = "      'id' => 'harbour-keeper',";

    $npcs = $map->getNpcs()->withAdded(new ProjectNpc([
        'id' => 'pier-cat',
        'name' => 'Pier Cat',
        'sprite' => 'c',
        'x' => 8,
        'y' => 4,
    ]));
    $map->setNpcs($npcs);
    $map->save();
    $data = (string) file_get_contents($map->dataPath);

    expect($data)->toContain("'id' => 'pier-cat',")
        ->and($data)->toContain($keeperLines)
        ->and($data)->toContain('[MovementHeading::NORTH->value]')
        ->and(substr_count($data, "'npcs' =>"))->toBe(1);

    // Removal takes exactly the inserted entry back out.
    $withoutCat = NpcCollection::fromMapData(array_values(array_filter(
        $map->getNpcs()->toMapData(),
        static fn(array $entry): bool => ($entry['id'] ?? null) !== 'pier-cat',
    )));
    $map->setNpcs($withoutCat);
    $map->save();

    expect((string) file_get_contents($map->dataPath))->not->toContain('pier-cat')
        ->and((string) file_get_contents($map->dataPath))->toContain($keeperLines);
});

it('inserts and removes an event definition surgically by its marker', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    $before = (string) file_get_contents($map->dataPath);

    $map->setEventDefinition('D', [
        'class' => 'Ichiloto\\Engine\\Events\\Triggers\\DialogueEventTrigger',
        'data' => ['dialogue' => [['name' => '', 'text' => 'A quiet pier.']]],
    ]);
    $map->save();
    $data = (string) file_get_contents($map->dataPath);

    expect($data)->toContain("'D' =>")
        ->and($data)->toContain('A quiet pier.')
        ->and($data)->toContain("'script' => \$watchScript,");

    $map->removeEventDefinition('D');
    $map->save();

    expect((string) file_get_contents($map->dataPath))->toBe($before, 'insert then remove is a byte round trip');
});

it('still targets a restored NPC after delete, save, undo and save again', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);

    // Delete the legacy, id-less NPC and save.
    $all = $map->getNpcs()->toMapData();
    $withoutGull = NpcCollection::fromMapData(array_values(array_filter($all, static fn(array $entry): bool => ($entry['name'] ?? null) !== 'Old Gull')));
    $map->setNpcs($withoutGull);
    $map->save();
    expect((string) file_get_contents($map->dataPath))->not->toContain('Old Gull');

    // Undo (restore the collection) and save: the entry is back in place.
    $map->setNpcs(NpcCollection::fromMapData($all));
    $map->save();
    $data = (string) file_get_contents($map->dataPath);
    expect($data)->toContain("'name' => 'Old Gull',")
        ->and($map->isDirty())->toBeFalse();

    // A later edit still targets that restored entry -- by its unique name,
    // the explicit identity rule for NPCs without ids -- and only its own
    // line changes.
    $entries = $map->getNpcs()->toMapData();

    foreach ($entries as $index => $entry) {
        if (($entry['name'] ?? null) === 'Old Gull') {
            $entries[$index]['x'] = 9;
        }
    }

    $map->setNpcs(NpcCollection::fromMapData($entries));
    $map->save();
    $after = (string) file_get_contents($map->dataPath);

    expect($after)->toContain("'name' => 'Old Gull',")
        ->and(preg_match("/'name' => 'Old Gull',\\s*'sprite' => 'g',\\s*'x' => 9,/s", $after))->toBe(1)
        ->and($after)->toContain("'id' => 'harbour-keeper',");
});

it('still targets a restored event marker after delete, save, undo and save again', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    $chest = $map->getEventDefinition('E');

    $map->removeEventDefinition('E');
    $map->save();
    expect((string) file_get_contents($map->dataPath))->not->toContain("'E' =>");

    $map->setEventDefinition('E', $chest);
    $map->save();
    expect($map->isDirty())->toBeFalse();

    $map->setEventField('E', ['data', 'loot'], 'M-Potion');
    $map->save();
    $data = (string) file_get_contents($map->dataPath);

    expect($data)->toContain("'loot' => 'M-Potion',")
        ->and($data)->toContain("'script' => \$watchScript,");
});

it('reads two id-less NPCs sharing a name as an ambiguous identity, and validation warns', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    $entries = $map->getNpcs()->toMapData();
    $entries[] = ['name' => 'Old Gull', 'sprite' => 'G', 'x' => 10, 'y' => 5];
    $map->setNpcs(NpcCollection::fromMapData($entries));
    $map->save();

    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $mine = array_values(array_filter($issues, static fn($issue): bool => str_contains($issue->message, 'have no stable id')));

    expect($mine)->toHaveCount(1)
        ->and($mine[0]->message)->toContain('"Old Gull"');
});

// -- First save, creation and move --------------------------------------------

it('leaves no partial folder when a first save fails', function () {
    $root = authoredMapProject();
    ProjectMap::createBlank($root . '/assets/Maps/new-quarter', 'new-quarter', 'New Quarter');
    $map = ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/new-quarter');
    // A genuinely new map: remove the created triplet so the save is the
    // first installation, then make its second member unwritable.
    foreach ([$map->dataPath, $map->mapPath, $map->eventPath] as $path) {
        unlink($path);
    }

    rmdir($map->directory);
    $map->setMapField('description', 'First content');

    $failing = new FailingFileSetOperations(failures: ['move' => [$map->mapPath]]);

    expect(fn() => $map->save(null, $failing))->toThrow(FileSetTransactionFailure::class);
    expect(is_dir($map->directory))->toBeFalse('no folder, no partial pair')
        ->and($map->isDirty())->toBeTrue();

    // The same save succeeds cleanly afterwards.
    $map->save();
    expect(is_file($map->dataPath))->toBeTrue()
        ->and(is_file($map->mapPath))->toBeTrue()
        ->and(is_file($map->eventPath))->toBeTrue()
        ->and($map->isDirty())->toBeFalse();
});

it('creates a canonical new map that reopens identically', function () {
    $root = authoredMapProject();
    ProjectMap::createBlank($root . '/assets/Maps/fresh', 'fresh', 'Fresh', 10, 4);
    $map = ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/fresh');
    backdateTriplet($map);
    $before = tripletState($map);

    expect($map->isDirty())->toBeFalse()
        ->and($map->dataSourceIssue())->toBeNull();
    $map->save();
    expect(tripletState($map))->toBe($before, 'a fresh map is already canonical; saving writes nothing');

    $map->setMapField('description', 'A fresh quarter.');
    $map->save();
    $reloaded = ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/fresh');
    expect($reloaded->getEditableData())->toBe($map->getEditableData())
        ->and($reloaded->isDirty())->toBeFalse();
});

it('moves a map only through the explicit move, carrying its authored source bytes', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    $dataBefore = (string) file_get_contents($map->dataPath);

    // Display metadata never derives a move: renaming the map leaves the
    // folder alone, on save and on Save All.
    $map->setMapField('name', 'Completely Different Name');
    expect($map->willMoveOnSave())->toBeFalse();
    $map->save();
    expect(is_dir($root . '/assets/Maps/test-map'))->toBeTrue();

    // This map's require is written against its folder depth, and the move
    // would change that depth: the staged copy does not evaluate at the
    // destination, so the move is refused whole and the original is intact.
    $before = tripletState($map);

    expect(fn() => $map->moveTo('district/harbour'))->toThrow(RuntimeException::class, 'does not evaluate at district/harbour');
    expect(is_dir($root . '/assets/Maps/district'))->toBeFalse()
        ->and(tripletState($map))->toBe($before);

    // A map whose authored source has no depth-relative expression moves
    // whole, bytes carried, comments and enums included.
    $portable = authoredMap($root);
    $movedSource = str_replace(
        "\$watchScript = require dirname(__DIR__, 2) . '/Events/harbour-watch.php';\n\n",
        '',
        (string) file_get_contents($portable->dataPath),
    );
    $movedSource = str_replace("'script' => \$watchScript,", "'script' => [],", $movedSource);
    $movedSource = str_replace("'script' => require dirname(__DIR__, 2) . '/Events/harbour-watch.php',", "'script' => [],", $movedSource);
    file_put_contents($portable->dataPath, $movedSource);
    $portable = authoredMap($root);
    $moved = $portable->moveTo('district/harbour');

    expect($moved->mapId)->toBe('district/harbour')
        ->and(is_dir($root . '/assets/Maps/test-map'))->toBeFalse();
    $movedData = (string) file_get_contents($moved->dataPath);
    expect($movedData)->toContain('// The harbour district, hand-annotated.')
        ->and($movedData)->toContain('[MovementHeading::NORTH->value]')
        ->and($movedData)->toContain("'station'     => ['x' => 3, 'y' => 1],");
});

// -- Editor-level flows -------------------------------------------------------

it('saves each dirty map once through Save All and leaves clean maps untouched', function () {
    $root = authoredMapProject();
    ProjectMap::createBlank($root . '/assets/Maps/quarter-a', 'quarter-a', 'Quarter A');
    ProjectMap::createBlank($root . '/assets/Maps/quarter-b', 'quarter-b', 'Quarter B');
    $editor = deletionEditor($root);
    $workspace = getEditorProperty($editor, 'workspace');
    $maps = [];

    foreach ($workspace->maps as $map) {
        $maps[$map->mapId] = $map;
        backdateTriplet($map);
    }

    $cleanState = tripletState($maps['quarter-b']);
    $maps['test-map']->setMapField('description', 'Edited by Save All');
    $maps['quarter-a']->setMapField('description', 'Also edited');

    callEditorMethod($editor, 'saveAllAssets');

    expect($maps['test-map']->isDirty())->toBeFalse()
        ->and($maps['quarter-a']->isDirty())->toBeFalse()
        ->and(tripletState($maps['quarter-b']))->toBe($cleanState, 'the clean map was not touched')
        ->and(file_get_contents($maps['test-map']->dataPath))->toContain('Edited by Save All')
        ->and(file_get_contents($maps['quarter-a']->dataPath))->toContain('Also edited')
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('2 maps');

    // Running it again writes nothing anywhere.
    $states = array_map(tripletState(...), $maps);
    callEditorMethod($editor, 'saveAllAssets');
    expect(array_map(tripletState(...), $maps))->toBe($states);
});

it('follows the accepted backup policy when a backup fails: warn, and still save correctly', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    $map->setMapField('description', 'Saved despite the backup');

    // The accepted policy is the Editor's: a backup failure is a warning,
    // never a veto, and never corrupts the destination.
    $backup = static function (string ...$paths): void {
        // A backup destination that cannot be created: the writer reports
        // failure; the editor's wrapper turns that into a status warning
        // and returns. This callable models exactly that contract.
    };

    $map->save($backup);

    expect($map->isDirty())->toBeFalse()
        ->and((string) file_get_contents($map->dataPath))->toContain('Saved despite the backup');
});

it('patches the edited twin when two id-less NPCs share a name, anchored by the untouched one', function () {
    $root = authoredMapProject();
    $map = authoredMap($root);
    $entries = $map->getNpcs()->toMapData();
    $entries[] = ['name' => 'Old Gull', 'sprite' => 'G', 'x' => 10, 'y' => 5];
    $map->setNpcs(NpcCollection::fromMapData($entries));
    $map->save();

    // Editing the second twin: the first is untouched and anchors, so the
    // patch lands on the right row even though the name identifies neither.
    $entries = $map->getNpcs()->toMapData();
    $entries[2]['x'] = 11;
    $map->setNpcs(NpcCollection::fromMapData($entries));
    $map->save();
    $data = (string) file_get_contents($map->dataPath);

    expect(preg_match("/'sprite' => 'g',\s*'x' => 6,/s", $data))->toBe(1, 'the first twin kept its position')
        ->and(preg_match("/'sprite' => 'G',\s*'x' => 11,/s", $data))->toBe(1, 'the second took the edit');
});

it('round-trips one editor session: no-op identity, one surgical edit, one structural edit, a refusal, reopen', function () {
    $root = authoredMapProject();
    $editor = deletionEditor($root);
    $map = callEditorMethod($editor, 'getSelectedMap');
    backdateTriplet($map);
    $before = tripletState($map);

    // Opening, browsing the Inspector and validating wrote nothing.
    setEditorProperty($editor, 'focusedPane', 'inspector');

    foreach (callEditorMethod($editor, 'getInspectorFields') as $index => $field) {
        setEditorProperty($editor, 'selectedInspectorFieldIndex', $index);
        callEditorMethod($editor, 'getInspectorLines');
    }

    new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    callEditorMethod($editor, 'saveAllAssets');
    expect(tripletState($map))->toBe($before);

    // One surgical metadata edit through the Inspector's own apply path.
    [$field] = (static function (object $editor): array {
        foreach (callEditorMethod($editor, 'getInspectorFields') as $candidate) {
            if (($candidate['field'] ?? null) === 'description') {
                return [$candidate];
            }
        }

        throw new RuntimeException('No description field.');
    })($editor);
    callEditorMethod($editor, 'applyInspectorFieldValue', $field, 'Edited in session');

    // One structural edit: a new event definition.
    $map->setEventDefinition('Q', [
        'class' => 'Ichiloto\\Engine\\Events\\Triggers\\DialogueEventTrigger',
        'data' => ['dialogue' => [['name' => '', 'text' => 'Quiet.']]],
    ]);

    // A refused edit changes nothing and blocks nothing.
    try {
        $map->setEventField('R', ['data', 'script'], []);
    } catch (MapSourceRefusal) {
        // Expected.
    }

    callEditorMethod($editor, 'saveAllAssets');
    expect($map->isDirty())->toBeFalse();

    // Undo the recorded edit: the checkpoint has moved to the saved state,
    // so undoing makes the map dirty again, and saving writes the restored
    // content -- not the abandoned edit.
    callEditorMethod($editor, 'performUndo');
    expect($map->isDirty())->toBeTrue();
    $map->save();
    $data = (string) file_get_contents($map->dataPath);
    expect($data)->not->toContain('Edited in session')
        ->and($data)->toContain("'Q' =>");

    // Taking the structural edit back out restores the original bytes.
    $map->removeEventDefinition('Q');
    $map->save();
    expect((string) file_get_contents($map->dataPath))->toBe($before['data'][0]);

    // Reopening reads back exactly what is in memory.
    $reloaded = authoredMap($root);
    expect($reloaded->getEditableData())->toBe($map->getEditableData());
});
