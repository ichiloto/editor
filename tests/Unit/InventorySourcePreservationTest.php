<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\PhpSourceDocument;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;

/**
 * Copies the real project's authored items.php into a throwaway project.
 *
 * The point of these tests is the actual authored source -- named arguments,
 * fully qualified classes, nested constructors, emoji icons -- not a
 * simplified stand-in, so the file is copied rather than written.
 *
 * @return array{0: string, 1: string}|null The project root and items path.
 */
function projectWithAuthoredInventory(): ?array
{
    $game = dirname(__DIR__, 3) . '/examples/last-legend';

    if (! is_file($game . '/assets/Data/items.php')) {
        return null;
    }

    $root = makeTemporaryProject('ichiloto-source-');
    $path = $root . '/assets/Data/items.php';
    copy($game . '/assets/Data/items.php', $path);

    return [$root, $path];
}

/**
 * Opens one inventory category of a project.
 */
function inventoryDatabase(string $root, string $category): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category));
}

it('changes one authored value and leaves every other byte alone', function () {
    $project = projectWithAuthoredInventory();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = (string) file_get_contents($path);

    $database = inventoryDatabase($root, 'items');
    $database->setField(0, 'price', '55');
    $database->save();

    $after = (string) file_get_contents($path);
    $beforeLines = explode("\n", $before);
    $afterLines = explode("\n", $after);
    $changed = [];

    foreach ($afterLines as $index => $line) {
        if (($beforeLines[$index] ?? null) !== $line) {
            $changed[] = $line;
        }
    }

    // Exactly one line differs, and it is the one asked for.
    expect($afterLines)->toHaveCount(count($beforeLines))
        ->and($changed)->toHaveCount(1)
        ->and(trim($changed[0]))->toBe('price: 55,')
        // The authored spelling of everything else survives.
        ->and($after)->toContain("new \\Ichiloto\\Engine\\Entities\\Inventory\\Items\\Item(")
        ->and($after)->toContain("id: 'item.s-potion',")
        ->and($after)->toContain('use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;')
        ->and($after)->toContain('equipmentType: \Ichiloto\Engine\Entities\Enumerations\WeaponType::SWORD,')
        // The file is still PHP, and still the same list of definitions.
        ->and(PhpSourceDocument::parse($after)->entryCount())->toBe(PhpSourceDocument::parse($before)->entryCount());

    $reloaded = inventoryDatabase($root, 'items');
    expect($reloaded->getRecordByIndex(0)?->get('price'))->toBe(55);
})->group('engine');

it('writes nothing at all when nothing changed', function () {
    $project = projectWithAuthoredInventory();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = (string) file_get_contents($path);
    $modifiedAt = filemtime($path);

    foreach (['items', 'weapons', 'armors'] as $category) {
        $database = inventoryDatabase($root, $category);

        // Reading every field of every record is what an author browsing the
        // category does, and it must not count as an edit.
        foreach ($database->getRecords() as $index => $record) {
            $database->getSettingsFields($index);
        }

        expect($database->isDirty())->toBeFalse();
        $database->save();
    }

    expect((string) file_get_contents($path))->toBe($before)
        ->and(filemtime($path))->toBe($modifiedAt);
})->group('engine');

it('edits a weapon inside its nested constructor without touching the others', function () {
    $project = projectWithAuthoredInventory();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = (string) file_get_contents($path);

    $database = inventoryDatabase($root, 'weapons');
    $database->setField(0, 'parameterChanges.attack', '9');
    $database->save();

    $after = (string) file_get_contents($path);
    $changed = array_values(array_filter(
        array_map(
            static fn(string $line, int $index): ?string => ($line !== (explode("\n", $before)[$index] ?? null)) ? $line : null,
            explode("\n", $after),
            array_keys(explode("\n", $after)),
        ),
        static fn(?string $line): bool => $line !== null,
    ));

    expect($changed)->toHaveCount(1)
        ->and(trim($changed[0]))->toBe('attack: 9,')
        // The nested constructor itself is untouched, not rebuilt.
        ->and($after)->toContain('parameterChanges: new \Ichiloto\Engine\Entities\ParameterChanges(');

    $reloaded = inventoryDatabase($root, 'weapons');
    expect($reloaded->getRecordByIndex(0)?->get('parameterChanges.attack'))->toBe(9);
})->group('engine');

it('leaves every unrelated file in the project byte-identical', function () {
    $project = projectWithAuthoredInventory();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = sourceHashTree($root);

    $database = inventoryDatabase($root, 'items');
    $database->setField(1, 'description', 'A potion that restores rather more HP.');
    $database->save();

    $after = sourceHashTree($root);

    expect(array_keys(array_diff_assoc($after, $before)))->toBe(['assets/Data/items.php']);
})->group('engine');

it('keeps an authored file whose entries are not named-argument calls working as before', function () {
    // The fixture project authors its items positionally, which cannot be
    // patched by argument name. That file still saves through the ordinary
    // writer rather than failing, and the record still changes.
    $root = makeTemporaryProject('ichiloto-source-');
    $path = $root . '/assets/Data/items.php';

    expect((string) file_get_contents($path))->toContain("new Item('S-Potion'");

    $database = inventoryDatabase($root, 'items');
    $database->setField(0, 'price', '77');
    $database->save();

    $reloaded = inventoryDatabase($root, 'items');

    expect($reloaded->getRecordByIndex(0)?->get('price'))->toBe(77);
});

// -- The document itself ---------------------------------------------------

it('reads, replaces, adds and removes one argument at a time', function () {
    $source = <<<'PHP'
    <?php

    // A comment the author wrote.
    use Ichiloto\Engine\Entities\Inventory\Items\Item;

    return [
      // The first potion.
      new Item(
        id: 'item.s-potion',
        name: 'S-Potion',
        price: 50,
      ),
      new \Ichiloto\Engine\Entities\Inventory\Items\Item(id: 'item.antidote', name: 'Antidote', price: 80),
    ];
    PHP;

    $document = PhpSourceDocument::parse($source);

    expect($document->entryCount())->toBe(2)
        ->and($document->entryClasses())->toBe(['Item', '\Ichiloto\Engine\Entities\Inventory\Items\Item'])
        ->and($document->argumentSource(0, 'name'))->toBe("'S-Potion'")
        ->and($document->argumentSource(1, 'price'))->toBe('80')
        ->and($document->argumentSource(0, 'absent'))->toBeNull()
        ->and($document->isEditable(0))->toBeTrue();

    // Replacing keeps the comments and the other entry exactly.
    $replaced = $document->withArgument(0, 'price', '55');
    expect($replaced->source)->toContain('// A comment the author wrote.')
        ->and($replaced->source)->toContain('// The first potion.')
        ->and($replaced->source)->toContain('price: 55,')
        ->and($replaced->source)->toContain("new \\Ichiloto\\Engine\\Entities\\Inventory\\Items\\Item(id: 'item.antidote'");

    // Adding writes in the call's own indentation; removing puts the bytes
    // back exactly as they were.
    $added = $document->withArgument(0, 'sellRateBasisPoints', '2500');
    expect($added->argumentSource(0, 'sellRateBasisPoints'))->toBe('2500')
        ->and($added->source)->toContain("    sellRateBasisPoints: 2500,\n")
        ->and($added->withoutArgument(0, 'sellRateBasisPoints')->source)->toBe($source);

    // A single-line call takes an argument inline.
    $inline = $document->withArgument(1, 'sellable', 'false');
    expect($inline->argumentSource(1, 'sellable'))->toBe('false')
        ->and($inline->source)->toContain("price: 80, sellable: false)");
});

it('refuses an entry it cannot read by argument name rather than rewriting it', function () {
    $source = <<<'PHP'
    <?php

    return [
      new Item('Positional', 'Authored without names', 'i', 10),
      $prebuilt,
    ];
    PHP;

    $document = PhpSourceDocument::parse($source);

    // Both entries hold a position in the list, so record indexes still line
    // up, but neither can be edited by argument name.
    expect($document->entryCount())->toBe(2)
        ->and($document->isEditable(0))->toBeFalse()
        ->and($document->isEditable(1))->toBeFalse()
        ->and($document->argumentSource(0, 'name'))->toBeNull();

    expect(fn() => $document->withArgument(0, 'price', '20'))
        ->toThrow(RuntimeException::class, 'not a constructor call with named arguments');
});

it('adds and removes a whole entry, and puts the file back', function () {
    $source = <<<'PHP'
    <?php

    return [
      new Item(
        id: 'item.one',
        name: 'One',
      ),
    ];
    PHP;

    $document = PhpSourceDocument::parse($source);
    $added = $document->withNewEntry('\Ichiloto\Engine\Entities\Inventory\Items\Item', [
        'id' => "'item.two'",
        'name' => "'Two'",
    ]);

    expect($added->entryCount())->toBe(2)
        ->and($added->argumentSource(1, 'id'))->toBe("'item.two'")
        ->and($added->source)->toContain("  new \\Ichiloto\\Engine\\Entities\\Inventory\\Items\\Item(\n    id: 'item.two',")
        ->and($added->withoutEntry(1)->source)->toBe($source);

    // Removing the first entry leaves a file that still parses.
    $emptied = $added->withoutEntry(0);
    expect($emptied->entryCount())->toBe(1)
        ->and($emptied->argumentSource(0, 'name'))->toBe("'Two'");
});
