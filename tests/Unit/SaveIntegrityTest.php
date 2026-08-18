<?php

declare(strict_types=1);

use Ichiloto\Editor\History\PaintStrokeCommand;
use Ichiloto\Editor\ProjectActorDatabase;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Copies the real game into a disposable directory, when it is present.
 *
 * The game worktree itself is never touched; a missing checkout skips the
 * integration-grade assertions cleanly.
 *
 * @return string|null The disposable project root.
 */
function disposableGameCopy(): ?string
{
    $game = gameSourceRoot();

    if ($game === null || ! is_dir($game . '/assets/Data/Actors')) {
        return null;
    }

    $root = rememberTemporaryProject(sys_get_temp_dir() . '/' . uniqid('save-integrity-', true));
    mkdir($root, 0o777, true);

    // The game's map files construct classes from the project's own
    // namespace, exactly as the console registers when it opens a project.
    static $autoloaderRegistered = [];

    if (! isset($autoloaderRegistered[$root])) {
        $autoloaderRegistered[$root] = true;
        spl_autoload_register(static function (string $class) use ($root): void {
            $prefix = 'Ichiloto\\FinalQuest\\';

            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $path = $root . '/assets/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($path)) {
                require $path;
            }
        });
    }

    // Everything a save could write is copied; the audio, which nothing
    // writes and which weighs more than the rest of the game together, is
    // reached through a link so the copy stays the size of the authored
    // data rather than the size of the soundtrack.
    mkdir($root . '/assets', 0o777, true);

    foreach (scandir($game . '/assets') ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $source = $game . '/assets/' . $entry;

        if ($entry === 'Audio') {
            symlink($source, $root . '/assets/' . $entry);
        } elseif (is_dir($source)) {
            mkdir($root . '/assets/' . $entry, 0o777, true);
            copyDirectoryRecursively($source, $root . '/assets/' . $entry);
        } else {
            copy($source, $root . '/assets/' . $entry);
        }
    }

    foreach (['config.php', 'ichiloto.json', 'input.php', 'composer.json'] as $entry) {
        if (is_file($game . '/' . $entry)) {
            copy($game . '/' . $entry, $root . '/' . $entry);
        }
    }

    return $root;
}

/**
 * Hashes every file under a directory, keyed by relative path.
 *
 * @param string $directory The directory.
 * @return array<string, string> The SHA-256 matrix.
 */
function hashTree(string $directory): array
{
    $hashes = [];
    // Links are followed, so a tree reached through one -- the audio -- is
    // hashed file by file like everything else, and the pin over the whole
    // project keeps its full reach.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $hashes[substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname());
        }
    }

    ksort($hashes);

    return $hashes;
}

it('keeps a deep nested map exactly where it was through load and save', function () {
    $root = makeTemporaryProject();
    $deep = $root . '/assets/Maps/bsa/licensing-facility/administration';
    mkdir($deep, 0o777, true);

    foreach (['data' => "<?php\n\nreturn [\n  'name' => 'Licensing Administration',\n  'region' => 'BSA',\n];\n",
              'map' => "<?php\n\nreturn <<<'ICHILOTO_MAP'\n####\n#  #\n####\nICHILOTO_MAP;\n",
              'event' => "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n    \n    \n    \nICHILOTO_EVENT_MAP;\n"] as $kind => $contents) {
        file_put_contents($deep . '/administration.' . $kind . '.php', $contents);
    }

    $map = ProjectMap::fromDirectory($root . '/assets/Maps', $deep);

    // The name/region-derived path could never equal three segments, which
    // made this map a permanent rename target before.
    expect($map->mapId)->toBe('bsa/licensing-facility/administration')
        ->and($map->willMoveOnSave())->toBeFalse()
        ->and($map->isDirty())->toBeFalse();

    $before = hashTree($deep);
    $map->save();

    expect(hashTree($deep))->toBe($before)
        ->and(is_dir($deep))->toBeTrue();

    // Metadata edits save in place; the id survives reload.
    $map->setMapField('name', 'Licensing HQ');
    $map->save();

    $reloaded = ProjectMap::fromDirectory($root . '/assets/Maps', $deep);

    expect($reloaded->mapId)->toBe('bsa/licensing-facility/administration')
        ->and($reloaded->getDisplayName())->toBe('Licensing HQ');
});

it('never opens the move confirmation from an ordinary save', function () {
    $root = makeTemporaryProject();
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));

    // Metadata that disagrees with the path — the exact state that used to
    // raise the Save+Move prompt.
    $workspace = getEditorProperty($editor, 'workspace');
    $workspace->getMapByIndex(0)->setMapField('name', 'Somewhere Entirely Different');

    callEditorMethod($editor, 'saveSelectedMap');

    expect(getEditorProperty($editor, 'isRenameConfirmationOpen'))->toBeFalse()
        ->and($workspace->getMapByIndex(0)->mapId)->toBe('test-map');
});

it('walks the save-checkpoint sequence exactly as specified', function () {
    [$root, $map] = scratchMapCopy();

    try {
        // Load pristine.
        expect($map->isDirty())->toBeFalse();

        $strokeA = new PaintStrokeCommand($map, PaintStrokeCommand::LAYER_TILE, 'A');
        $strokeA->appendCell(1, 1, $map->getTileSymbol(1, 1), '@');
        $map->setTileSymbol(1, 1, '@');

        // Edit A -> dirty.
        expect($map->isDirty())->toBeTrue();

        // Save while A remains in history -> clean.
        $map->save();
        expect($map->isDirty())->toBeFalse();

        // Edit B -> dirty.
        $strokeB = new PaintStrokeCommand($map, PaintStrokeCommand::LAYER_TILE, 'B');
        $strokeB->appendCell(2, 1, $map->getTileSymbol(2, 1), '%');
        $map->setTileSymbol(2, 1, '%');
        expect($map->isDirty())->toBeTrue();

        // Undo B -> back at the checkpoint -> clean.
        $strokeB->undo();
        expect($map->isDirty())->toBeFalse();

        // Undo A -> before the checkpoint -> dirty.
        $strokeA->undo();
        expect($map->isDirty())->toBeTrue();

        // Redo A -> at the checkpoint again -> clean.
        $strokeA->execute();
        expect($map->isDirty())->toBeFalse();

        // Redo B -> past the checkpoint -> dirty.
        $strokeB->execute();
        expect($map->isDirty())->toBeTrue();
    } finally {
        removeScratchTree($root);
    }
});

it('leaves the checkpoint alone when a save fails', function () {
    [$root, $map] = scratchMapCopy();

    try {
        $map->setTileSymbol(1, 1, '@');
        $mapFile = $root . '/assets/Maps/test-map/test-map.map.php';
        chmod(dirname($mapFile), 0o555);

        set_error_handler(static fn(): bool => true);

        try {
            $map->save();
        } catch (Throwable) {
            // The write failed, which is the point.
        } finally {
            restore_error_handler();
        }

        chmod(dirname($mapFile), 0o755);

        // The baseline did not advance: the map still knows it diverges.
        expect($map->isDirty())->toBeTrue();

        $map->save();
        expect($map->isDirty())->toBeFalse();
    } finally {
        chmod($root . '/assets/Maps/test-map', 0o755);
        removeScratchTree($root);
    }
});

it('treats same-value edits as the nothing they are', function () {
    [$root, $map] = scratchMapCopy();

    try {
        $stroke = new PaintStrokeCommand($map, PaintStrokeCommand::LAYER_TILE, 'noop');
        $original = $map->getTileSymbol(0, 0);
        $stroke->appendCell(0, 0, $original, $original);
        $map->setTileSymbol(0, 0, $original);
        $map->setMapField('name', $map->getDisplayName());
        $map->resize($map->getWidth(), $map->getHeight());

        // No dirty state, and a stroke of unchanged cells records nothing.
        expect($map->isDirty())->toBeFalse()
            ->and($stroke->hasChanges())->toBeFalse();
    } finally {
        removeScratchTree($root);
    }
});

it('saves one edited actor without touching any other actor file', function () {
    $root = disposableGameCopy();

    if ($root === null) {
        expect(true)->toBeTrue();

        return;
    }

    $actorsDirectory = $root . '/assets/Data/Actors';
    $database = ProjectActorDatabase::fromProject($root);
    $before = hashTree($actorsDirectory);

    // A clean database save writes nothing at all.
    $database->save();
    expect(hashTree($actorsDirectory))->toBe($before);

    // Editing Kaelion touches exactly Kaelion.
    $kaelion = null;

    foreach ($database->getActors() as $index => $actor) {
        if ($actor->getName() === 'Kaelion') {
            $kaelion = $index;
        }
    }

    expect($kaelion)->not->toBeNull();

    $kaelionPayloadBefore = require $actorsDirectory . '/Kaelion.php';
    $database->setField($kaelion, 'level', '7');
    $database->save();

    $after = hashTree($actorsDirectory);
    $changed = array_keys(array_diff_assoc($after, $before));

    expect($changed)->toBe(['Kaelion.php']);

    // The sprite survives the neighbouring edit: evaluated data identical
    // apart from the edited field, backslashes and spacing included.
    $kaelionPayloadAfter = require $actorsDirectory . '/Kaelion.php';
    $expected = $kaelionPayloadBefore;
    $expected['data']['level'] = 7;

    expect($kaelionPayloadAfter['data']['images'] ?? null)->toBe($kaelionPayloadBefore['data']['images'] ?? null)
        ->and($kaelionPayloadAfter)->toEqual($expected)
        ->and(ProjectActorDatabase::fromProject($root)->isDirty())->toBeFalse();

    removeDirectoryRecursively($root);
});

it('rewrites nothing anywhere when an untouched project is saved wholesale', function () {
    $root = disposableGameCopy();

    if ($root === null) {
        expect(true)->toBeTrue();

        return;
    }

    $workspace = ProjectWorkspace::fromProject($root);
    $before = hashTree($root . '/assets');

    // Save every saveable thing, exactly as Save All would.
    foreach ($workspace->maps as $map) {
        $map->save();
    }

    $workspace->actorDatabase->save();
    $workspace->classDatabase->save();
    $workspace->skillDatabase->save();
    $workspace->questDatabase->save();
    $workspace->systemDatabase->save();

    foreach ($workspace->recordDatabases as $database) {
        if ($database->isEditable()) {
            $database->save();
        }
    }

    // The holistic pin: an untouched project saved end to end is
    // byte-for-byte the project it was.
    expect(hashTree($root . '/assets'))->toBe($before)
        ->and($workspace->hasUnsavedChanges())->toBeFalse();

    removeDirectoryRecursively($root);
});
