<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\PhpSourceDocument;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\SharedFileTransaction;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Three categories over one list of constructor calls.
 *
 * `items.php` holds items, weapons and armors interleaved, and the editor
 * opens each as its own category. Each used to remember which *absolute*
 * entry of the whole file its records came from, so the moment one category
 * removed an entry, every later position a sibling was holding pointed one
 * entry too far along. Saving items then weapons destroyed a weapon nobody
 * had touched.
 *
 * What a record now remembers is which entry of *its own category* it came
 * from. Where that lands in the file is resolved from the file itself, at
 * the moment of writing, against the same snapshot every sibling is composed
 * against.
 */

/**
 * Copies the real authored inventory into a throwaway project.
 *
 * @return array{0: string, 1: string}|null The project root and items path.
 */
function inventoryTransactionProject(): ?array
{
    $game = gameSourceRoot();

    if ($game === null) {
        return null;
    }

    $root = makeTemporaryProject('ichiloto-inventory-tx-');
    $path = $root . '/assets/Data/items.php';
    copy($game . '/assets/Data/items.php', $path);

    return [$root, $path];
}

/**
 * Returns the stable ids each inventory category holds, read fresh.
 *
 * @return array<string, string[]>
 */
function inventoryIds(string $root): array
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
 * Opens all three inventory categories at once, as the editor holds them.
 *
 * @return array<string, ProjectRecordDatabase>
 */
function inventoryCategories(string $root): array
{
    $open = [];

    foreach (['items', 'weapons', 'armors'] as $category) {
        $open[$category] = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category));
    }

    return $open;
}

it('preserves both structural edits in either save order', function (array $pair, bool $reversed) {
    $project = inventoryTransactionProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root] = $project;
    $before = inventoryIds($root);
    $open = inventoryCategories($root);
    $removed = [];

    foreach ($pair as $category) {
        $removed[$category] = strval($open[$category]->removeRecord(0)?->get('id'));
    }

    $order = $reversed ? array_reverse($pair) : $pair;

    foreach ($order as $category) {
        $open[$category]->save();
    }

    $after = inventoryIds($root);

    foreach (['items', 'weapons', 'armors'] as $category) {
        $expected = $before[$category];

        if (isset($removed[$category])) {
            $expected = array_values(array_diff($expected, [$removed[$category]]));
        }

        // Exactly what was asked for, and nothing else, in every category.
        expect($after[$category])->toBe(
            $expected,
            sprintf('%s is wrong after removing %s.', $category, implode(' and ', $pair)),
        );
    }
})->with([
    [['items', 'weapons']],
    [['items', 'armors']],
    [['weapons', 'armors']],
])->with([false, true])->group('engine');

it('preserves a removal from all three categories at once', function () {
    $project = inventoryTransactionProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = inventoryIds($root);
    $source = (string) file_get_contents($path);
    $open = inventoryCategories($root);
    $removed = [];

    foreach ($open as $category => $database) {
        $removed[$category] = strval($database->removeRecord(0)?->get('id'));
    }

    foreach ($open as $database) {
        $database->save();
    }

    $after = inventoryIds($root);

    foreach ($after as $category => $ids) {
        expect($ids)->toBe(array_values(array_diff($before[$category], [$removed[$category]])));
    }

    // Three entries gone, and everything else spelled as the author spelled it.
    expect(PhpSourceDocument::parse((string) file_get_contents($path))->entryCount())
        ->toBe(PhpSourceDocument::parse($source)->entryCount() - 3)
        ->and((string) file_get_contents($path))->toContain('use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;')
        ->and((string) file_get_contents($path))->toContain('parameterChanges: new \Ichiloto\Engine\Entities\ParameterChanges(');
})->group('engine');

it('composes a field edit in one category with a structural edit in another', function () {
    $project = inventoryTransactionProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = inventoryIds($root);
    $open = inventoryCategories($root);

    $open['items']->setField(0, 'price', '4321');
    $removedWeapon = strval($open['weapons']->removeRecord(0)?->get('id'));
    $addedArmor = null;
    $index = $open['armors']->addRecord();
    $addedArmor = strval($open['armors']->getRecordByIndex($index)?->get('id'));

    foreach (['weapons', 'armors', 'items'] as $category) {
        $open[$category]->save();
    }

    $after = inventoryIds($root);
    $reloadedItems = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('items'));

    expect($reloadedItems->getRecordByIndex(0)?->get('price'))->toBe(4321)
        ->and($after['items'])->toBe($before['items'])
        ->and($after['weapons'])->toBe(array_values(array_diff($before['weapons'], [$removedWeapon])))
        ->and($after['armors'])->toBe([...$before['armors'], $addedArmor])
        ->and((string) file_get_contents($path))->toContain('price: 4321,');
})->group('engine');

it('writes one file once through Save All, with every category folded in', function () {
    $project = inventoryTransactionProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = inventoryIds($root);

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $removedItem = strval($workspace->getRecordDatabase('items')?->removeRecord(0)?->get('id'));
    $removedWeapon = strval($workspace->getRecordDatabase('weapons')?->removeRecord(0)?->get('id'));
    $workspace->getRecordDatabase('armors')?->setField(0, 'price', '999');

    callEditorMethod($editor, 'saveAllAssets');

    $after = inventoryIds($root);

    expect($after['items'])->toBe(array_values(array_diff($before['items'], [$removedItem])))
        ->and($after['weapons'])->toBe(array_values(array_diff($before['weapons'], [$removedWeapon])))
        ->and($after['armors'])->toBe($before['armors'])
        ->and((string) file_get_contents($path))->toContain('price: 999,')
        ->and($workspace->hasUnsavedChanges())->toBeFalse();
})->group('engine');

it('leaves every byte and every category alone when the write is refused', function () {
    $project = inventoryTransactionProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = (string) file_get_contents($path);
    $open = inventoryCategories($root);
    $open['items']->removeRecord(0);
    $open['weapons']->removeRecord(0);

    chmod($root . '/assets/Data', 0o555);
    set_error_handler(static fn(): bool => true, E_WARNING);

    try {
        expect(static fn() => SharedFileTransaction::commit(array_values($open)))
            ->toThrow(RuntimeException::class);

        expect((string) file_get_contents($path))->toBe($before)
            ->and($open['items']->isDirty())->toBeTrue()
            ->and($open['weapons']->isDirty())->toBeTrue();
    } finally {
        restore_error_handler();
        chmod($root . '/assets/Data', 0o755);
    }
})->group('engine');

it('undoes and redoes exactly across an intermediate sibling save', function () {
    $project = inventoryTransactionProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = inventoryIds($root);

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    callEditorMethod($editor, 'openDatabaseAtCategory', 'items');

    $fields = array_column(callEditorMethod($editor, 'getDatabaseSettingsFields'), null, 'field');
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields['price'], '777');
    callEditorMethod($editor, 'saveAllAssets');

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $removedWeapon = strval($workspace->getRecordDatabase('weapons')?->removeRecord(0)?->get('id'));
    callEditorMethod($editor, 'saveAllAssets');

    callEditorMethod($editor, 'performUndo');
    callEditorMethod($editor, 'saveAllAssets');

    $undone = inventoryIds($root);

    // The price edit is undone; the sibling's removal, saved in between,
    // stays removed.
    expect(ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('items'))->getRecordByIndex(0)?->get('price'))
        ->not->toBe(777)
        ->and($undone['weapons'])->toBe(array_values(array_diff($before['weapons'], [$removedWeapon])));

    callEditorMethod($editor, 'performRedo');
    callEditorMethod($editor, 'saveAllAssets');

    expect(ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('items'))->getRecordByIndex(0)?->get('price'))
        ->toBe(777)
        ->and(inventoryIds($root)['weapons'])->toBe($undone['weapons']);
})->group('engine');

it('writes nothing when a grouped save has nothing to do', function () {
    $project = inventoryTransactionProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = sourceHashTree($root);
    $modifiedAt = filemtime($path);
    $open = inventoryCategories($root);

    expect(SharedFileTransaction::commit(array_values($open)))->toBeFalse()
        ->and(sourceHashTree($root))->toBe($before)
        ->and(filemtime($path))->toBe($modifiedAt);
})->group('engine');
