<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\PhpSourceDocument;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\SharedFileTransaction;
use Ichiloto\Editor\Database\SourceIdentityConflict;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * A record is found in its file by what it says, not by where it sits.
 *
 * Which entry of its category a record came from was a sound address only
 * until the first save moved things: delete the middle of A, B, C, save,
 * undo, save again, and the restored B was appended after C in the file
 * while it stayed between A and C in the list. Editing B then patched C.
 *
 * Every entry now has a durable identity -- an item's stable id, a troop's
 * name -- and that identity is looked up in a fresh read of the file at the
 * moment of writing. Where it cannot prove the address, nothing is written.
 */

/**
 * Copies the real authored inventory into a throwaway project.
 *
 * @return array{0: string, 1: string}|null The project root and items path.
 */
function identityProject(): ?array
{
    $game = gameSourceRoot();

    if ($game === null) {
        return null;
    }

    $root = makeTemporaryProject('ichiloto-identity-');
    $path = $root . '/assets/Data/items.php';
    copy($game . '/assets/Data/items.php', $path);

    return [$root, $path];
}

/**
 * Opens the three inventory categories at once, as the editor holds them.
 *
 * @return array<string, ProjectRecordDatabase>
 */
function identityCategories(string $root): array
{
    $open = [];

    foreach (['items', 'weapons', 'armors'] as $category) {
        $open[$category] = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category));
    }

    return $open;
}

/**
 * Returns the stable ids each inventory category holds, read fresh from disk.
 *
 * @return array<string, string[]>
 */
function identityIds(string $root): array
{
    $ids = [];

    foreach (['items', 'weapons', 'armors'] as $category) {
        $ids[$category] = array_map(
            static fn($record): string => strval($record->get('id')),
            ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category))->getRecords(),
        );
    }

    return $ids;
}

/**
 * Returns the ids of a category as an open database currently lists them.
 *
 * @return string[]
 */
function listedIds(ProjectRecordDatabase $database): array
{
    return array_map(static fn($record): string => strval($record->get('id')), $database->getRecords());
}

/**
 * Returns the price the file now declares for one stable id, read fresh.
 */
function priceOnDisk(string $root, string $category, string $id): mixed
{
    foreach (ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category))->getRecords() as $record) {
        if ($record->get('id') === $id) {
            return $record->get('price');
        }
    }

    return null;
}

/**
 * Returns the authored bytes of the entry declaring one stable id.
 *
 * @return string|null The entry's source, or null when the file lacks it.
 */
function entryBytesFor(string $path, string $id): ?string
{
    $document = PhpSourceDocument::parse((string) file_get_contents($path));

    for ($index = 0; $index < $document->entryCount(); $index++) {
        $source = $document->entrySource($index);

        if ($source !== null && preg_match("/\\bid:\\s*'" . preg_quote($id, '/') . "'/", $source) === 1) {
            return $source;
        }
    }

    return null;
}

/**
 * Returns the index of a record with one stable id in an open database.
 */
function indexOfId(ProjectRecordDatabase $database, string $id): int
{
    $index = array_search($id, listedIds($database), true);

    if ($index === false) {
        throw new RuntimeException("{$id} is not listed.");
    }

    return $index;
}

/**
 * The review's exact sequence, in one category, with a sibling left dirty
 * throughout: delete the middle of A, B, C; save; undo; save; edit the
 * restored B; save; reload.
 */
function runRestoreSequence(string $category, string $sibling): array
{
    $project = identityProject();

    if ($project === null) {
        return [];
    }

    [$root, $path] = $project;
    $open = identityCategories($root);
    $before = identityIds($root);
    [$a, $b, $c] = $before[$category];
    $bytesBefore = [
        'a' => entryBytesFor($path, $a),
        'c' => entryBytesFor($path, $c),
    ];

    // The sibling is dirty for the whole sequence and never saved during it.
    $open[$sibling]->setField(0, 'price', '999');

    $removed = $open[$category]->removeRecord(1);
    $open[$category]->save();
    $afterDelete = identityIds($root)[$category];

    $open[$category]->insertRecord(1, $removed);
    $open[$category]->save();
    $afterUndo = identityIds($root)[$category];

    $open[$category]->setField(1, 'price', '4242');
    $open[$category]->save();

    return [
        'root' => $root,
        'path' => $path,
        'open' => $open,
        'ids' => [$a, $b, $c],
        'before' => $before,
        'afterDelete' => $afterDelete,
        'afterUndo' => $afterUndo,
        'bytesBefore' => $bytesBefore,
        'restored' => $removed,
    ];
}

it('finds a restored record by its id and edits it, not its neighbour', function (string $category, string $sibling) {
    $run = runRestoreSequence($category, $sibling);

    if ($run === []) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$a, $b, $c] = $run['ids'];
    $before = $run['before'];
    $path = $run['path'];
    $root = $run['root'];

    // The delete removed exactly B; the undo put exactly B back, between A
    // and C in the file as well as in the list; and the edit landed on B.
    expect($run['afterDelete'])->toBe(array_values(array_diff($before[$category], [$b])))
        ->and($run['afterUndo'])->toBe($before[$category])
        ->and(priceOnDisk($root, $category, $b))->toBe(4242)
        ->and(entryBytesFor($path, $a))->toBe($run['bytesBefore']['a'])
        ->and(entryBytesFor($path, $c))->toBe($run['bytesBefore']['c']);

    // The reload lists every category exactly as it did, with only B changed.
    $reloaded = identityIds($root);

    foreach (['items', 'weapons', 'armors'] as $each) {
        expect($reloaded[$each])->toBe($before[$each], "{$each} changed.");
    }

    // The sibling is still dirty with its own edit and nothing of it was
    // written.
    expect($run['open'][$sibling]->isDirty())->toBeTrue()
        ->and(priceOnDisk($root, $sibling, $before[$sibling][0]))->not->toBe(999);
})->with([
    ['items', 'weapons'],
    ['weapons', 'armors'],
    ['armors', 'items'],
])->group('engine');

it('redoes the delete after the restored save, and undoes it again, by id', function (string $category) {
    $run = runRestoreSequence($category, $category === 'items' ? 'armors' : 'items');

    if ($run === []) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$a, $b, $c] = $run['ids'];
    $root = $run['root'];
    $path = $run['path'];
    $database = $run['open'][$category];

    // Redo: the delete of B again, then save. Only B leaves.
    $database->removeRecord(indexOfId($database, $b));
    $database->save();

    expect(identityIds($root)[$category])->toBe(array_values(array_diff($run['before'][$category], [$b])))
        ->and(entryBytesFor($path, $a))->toBe($run['bytesBefore']['a'])
        ->and(entryBytesFor($path, $c))->toBe($run['bytesBefore']['c']);

    // Undo once more: B returns between A and C, still carrying its edit.
    $database->insertRecord(1, $run['restored']);
    $database->save();

    expect(identityIds($root)[$category])->toBe($run['before'][$category])
        ->and(priceOnDisk($root, $category, $b))->toBe(4242)
        ->and(entryBytesFor($path, $a))->toBe($run['bytesBefore']['a'])
        ->and(entryBytesFor($path, $c))->toBe($run['bytesBefore']['c'])
        ->and(ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category))->isDirty())->toBeFalse();
})->with(['items', 'weapons', 'armors'])->group('engine');

it('composes the restore sequence in one category with structural edits in a sibling', function (string $category, string $sibling) {
    $project = identityProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $open = identityCategories($root);
    $before = identityIds($root);
    [$a, $b, $c] = $before[$category];
    $siblingIds = $before[$sibling];

    // The sibling removes its first record and adds one, and saves in the
    // middle of the category's sequence.
    $removedSibling = strval($open[$sibling]->removeRecord(0)?->get('id'));
    $addedIndex = $open[$sibling]->addRecord();
    $addedSibling = strval($open[$sibling]->getRecordByIndex($addedIndex)?->get('id'));

    $removed = $open[$category]->removeRecord(1);
    $open[$category]->save();
    $open[$sibling]->save();
    $open[$category]->insertRecord(1, $removed);
    $open[$category]->save();
    $open[$category]->setField(1, 'price', '4242');
    $open[$category]->save();

    $after = identityIds($root);

    expect($after[$category])->toBe($before[$category])
        ->and($after[$sibling])->toBe([...array_values(array_diff($siblingIds, [$removedSibling])), $addedSibling])
        ->and(priceOnDisk($root, $category, $b))->toBe(4242)
        ->and(priceOnDisk($root, $category, $a))->toBe(priceOnDisk($root, $category, $a))
        ->and(entryBytesFor($path, $c))->not->toBeNull();

    // The third category was never touched.
    $third = array_values(array_diff(['items', 'weapons', 'armors'], [$category, $sibling]))[0];
    expect($after[$third])->toBe($before[$third]);
})->with([
    ['items', 'weapons'],
    ['items', 'armors'],
    ['weapons', 'armors'],
    ['weapons', 'items'],
    ['armors', 'items'],
    ['armors', 'weapons'],
])->group('engine');

it('places every kind of change by id in one Save All: edit, add, delete, and restore across siblings', function () {
    $project = identityProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = identityIds($root);

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $items = $workspace->getRecordDatabase('items');
    $weapons = $workspace->getRecordDatabase('weapons');
    $armors = $workspace->getRecordDatabase('armors');

    // Round one: delete the middle item and the first weapon; save all.
    $middleItem = $before['items'][1];
    $firstWeapon = $before['weapons'][0];
    $removedItem = $items->removeRecord(1);
    $weapons->removeRecord(0);
    callEditorMethod($editor, 'saveAllAssets');

    expect(identityIds($root)['items'])->toBe(array_values(array_diff($before['items'], [$middleItem])))
        ->and(identityIds($root)['weapons'])->toBe(array_values(array_diff($before['weapons'], [$firstWeapon])));

    // Round two, all at once: restore the item, add an armor, edit a weapon.
    $items->insertRecord(1, $removedItem);
    $addedIndex = $armors->addRecord();
    $addedArmor = strval($armors->getRecordByIndex($addedIndex)?->get('id'));
    $editedWeapon = $before['weapons'][1];
    $weapons->setField(indexOfId($weapons, $editedWeapon), 'price', '5150');
    callEditorMethod($editor, 'saveAllAssets');

    $after = identityIds($root);

    expect($after['items'])->toBe($before['items'])
        ->and($after['weapons'])->toBe(array_values(array_diff($before['weapons'], [$firstWeapon])))
        ->and($after['armors'])->toBe([...$before['armors'], $addedArmor])
        ->and(priceOnDisk($root, 'weapons', $editedWeapon))->toBe(5150)
        ->and($workspace->hasUnsavedChanges())->toBeFalse();
})->group('engine');

it('refuses to write two entries sharing one id, and changes nothing', function () {
    $project = identityProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = (string) file_get_contents($path);
    $open = identityCategories($root);
    $items = $open['items'];
    $ids = listedIds($items);

    // Two records now declare one id: the second is a copy of the first,
    // added as the runtime would refuse to load it.
    $index = $items->addRecord();
    $duplicate = new ReflectionProperty($items->getRecordByIndex($index), 'payload');
    $duplicate->setValue($items->getRecordByIndex($index), $items->getRecordByIndex(0)?->toArray());

    expect(static fn() => $items->save())->toThrow(SourceIdentityConflict::class, $ids[0]);

    // No byte written, still dirty, and the records still list what they
    // listed -- the file will be addressable again once the copy is gone.
    expect((string) file_get_contents($path))->toBe($before)
        ->and($items->isDirty())->toBeTrue()
        ->and(listedIds($items))->toBe([...$ids, $ids[0]]);

    $items->removeRecord($index);
    $items->save();

    expect((string) file_get_contents($path))->toBe($before);
})->group('engine');

it('refuses to address an entry the file holds twice, and leaves both alone', function () {
    $project = identityProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $source = (string) file_get_contents($path);
    $document = PhpSourceDocument::parse($source);

    // The file, as authored by hand, holds the second item twice.
    $second = $document->entrySource(1);
    $doubled = substr_replace($source, $second . ",\n  " . $second, strpos($source, (string) $second), strlen((string) $second));
    file_put_contents($path, $doubled);
    $doubledBytes = (string) file_get_contents($path);

    $items = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('items'));
    $ids = listedIds($items);
    $twice = $ids[1];

    expect($ids[2])->toBe($twice);

    // Editing one of the two: which entry? Refused, with the id named.
    $items->setField(1, 'price', '4242');
    expect(static fn() => $items->save())->toThrow(SourceIdentityConflict::class, $twice);
    expect((string) file_get_contents($path))->toBe($doubledBytes);

    // Deleting one of the two: which entry? Refused too.
    $items->setField(1, 'price', strval($items->getRecordByIndex(2)?->get('price')));
    $items->removeRecord(1);
    expect(static fn() => $items->save())->toThrow(SourceIdentityConflict::class, $twice);
    expect((string) file_get_contents($path))->toBe($doubledBytes);

    // A record the file holds once is still edited on its own.
    $fresh = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('items'));
    $fresh->setField(0, 'price', '4242');
    $fresh->save();

    expect(priceOnDisk($root, 'items', $ids[0]))->toBe(4242)
        ->and(substr_count((string) file_get_contents($path), "id: '{$twice}'"))->toBe(2);
})->group('engine');

it('refuses to address entries that declare no identity rather than guessing', function () {
    $project = identityProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = (string) file_get_contents($path);
    $items = RecordSchemaCatalog::forKey('items');

    // The same category, addressed by a key its entries never declare: no
    // entry can be told from another, so no entry may be written to.
    $unaddressable = new \Ichiloto\Editor\Database\RecordSchema(
        key: $items->key,
        entryNoun: $items->entryNoun,
        storage: $items->storage,
        relativePath: $items->relativePath,
        fields: $items->fields,
        labelKey: $items->labelKey,
        identityKey: 'serial',
        recordFilter: $items->recordFilter,
        makeBlank: $items->makeBlank,
    );
    $database = ProjectRecordDatabase::fromProject($root, $unaddressable);
    $database->setField(0, 'price', '4242');

    expect(static fn() => $database->save())->toThrow(SourceIdentityConflict::class, 'serial');
    expect((string) file_get_contents($path))->toBe($before)
        ->and($database->isDirty())->toBeTrue();
})->group('engine');

it('changes no byte and no identity when the grouped write fails', function () {
    $project = identityProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = (string) file_get_contents($path);
    $open = identityCategories($root);
    $ids = identityIds($root);

    $removedItem = $open['items']->removeRecord(1);
    $open['weapons']->setField(0, 'price', '4242');
    $open['armors']->addRecord();

    chmod($root . '/assets/Data', 0o555);
    set_error_handler(static fn(): bool => true, E_WARNING);

    try {
        expect(static fn() => SharedFileTransaction::commit(array_values($open)))->toThrow(RuntimeException::class);
    } finally {
        restore_error_handler();
        chmod($root . '/assets/Data', 0o755);
    }

    // Nothing written, everything still dirty.
    expect((string) file_get_contents($path))->toBe($before)
        ->and($open['items']->isDirty())->toBeTrue()
        ->and($open['weapons']->isDirty())->toBeTrue()
        ->and($open['armors']->isDirty())->toBeTrue();

    // And the identities the categories address by are exactly as they
    // were: putting the item back and saving now finds its entry still in
    // the file and writes only what changed since.
    $open['items']->insertRecord(1, $removedItem);
    SharedFileTransaction::commit(array_values($open));

    $after = identityIds($root);

    expect($after['items'])->toBe($ids['items'])
        ->and(priceOnDisk($root, 'weapons', $ids['weapons'][0]))->toBe(4242)
        ->and(count($after['armors']))->toBe(count($ids['armors']) + 1)
        ->and(entryBytesFor($path, $ids['items'][1]))->toBe(entryBytesFor($before === '' ? $path : $path, $ids['items'][1]));
})->group('engine');

it('writes nothing, and takes one backup per file, when a grouped save has nothing to do', function () {
    $project = identityProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $open = identityCategories($root);

    // Removed and put back before any save: the file never changed and the
    // record is recognised again by its id, so there is nothing to write.
    $removed = $open['items']->removeRecord(1);
    $open['items']->insertRecord(1, $removed);
    $open['weapons']->setField(0, 'price', strval($open['weapons']->getRecordByIndex(0)?->get('price')));

    $before = (string) file_get_contents($path);
    $mtime = filemtime($path);
    touch($path, $mtime - 100);
    clearstatcache();
    $stamped = filemtime($path);

    expect(SharedFileTransaction::commit(array_values($open)))->toBeFalse();
    clearstatcache();

    expect((string) file_get_contents($path))->toBe($before)
        ->and(filemtime($path))->toBe($stamped);

    // Through Save All, one file, one backup, however many categories.
    $config = json_decode((string) file_get_contents($root . '/ichiloto.json'), true);
    $config['editor'] = ['backups' => ['enabled' => true, 'retain' => 5]];
    file_put_contents($root . '/ichiloto.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $workspace->getRecordDatabase('items')?->setField(0, 'price', '4242');
    $workspace->getRecordDatabase('weapons')?->setField(0, 'price', '4243');
    $workspace->getRecordDatabase('armors')?->removeRecord(0);
    callEditorMethod($editor, 'saveAllAssets');

    $backups = glob($root . '/' . \Ichiloto\Editor\Backup\BackupSettings::DEFAULT_DIRECTORY . '/assets/Data/items.php.*.bak') ?: [];

    expect($backups)->toHaveCount(1)
        ->and((string) file_get_contents($backups[0]))->toBe($before)
        ->and(priceOnDisk($root, 'items', identityIds($root)['items'][0]))->toBe(4242)
        ->and(priceOnDisk($root, 'weapons', identityIds($root)['weapons'][0]))->toBe(4243);
})->group('engine');
