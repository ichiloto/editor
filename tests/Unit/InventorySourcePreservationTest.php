<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\PhpSourceDocument;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;

/**
 * Editing an inventory record changes the value asked for in that record's
 * own file and nothing else: not the record's other lines, not its comments
 * or imports, and not any other file in the project.
 */

/**
 * The authored weapon record these tests edit: an import, comments, an emoji
 * icon and a nested map, written the way an author writes one.
 */
const AUTHORED_WEAPON_RECORD = <<<'PHP'
<?php

use Ichiloto\Engine\Entities\Inventory\InventoryItem;

// The blade the smith sells first.
return [
  'class' => InventoryItem::class,
  'data' => [
    'kind' => 'weapon',
    'id' => 'equipment.ember-blade',
    'name' => 'Ember Blade',
    'description' => 'Warm to the touch.', // Not hot.
    'icon' => '🗡️',
    'price' => 300,
    'equipmentType' => 'Sword',
    /* Balanced for the first town. */
    'parameterChanges' => [
      'attack' => 6,
      'speed' => 1,
    ],
    'element' => 'Fire',
  ],
];
PHP;

/**
 * Returns a project whose weapons are the fixture's sword and the authored
 * Ember Blade, and the blade's record file.
 *
 * @return array{0: string, 1: string} The project root and the record file.
 */
function projectWithAuthoredWeapon(): array
{
    $root = makeTemporaryProject('ichiloto-source-');
    $path = $root . '/assets/Data/Weapons/0002-ember-blade.php';
    file_put_contents($path, AUTHORED_WEAPON_RECORD);

    return [$root, $path];
}

/**
 * Opens one inventory category of a project.
 */
function inventoryDatabase(string $root, string $category): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category));
}

/**
 * Returns the lines of an edited file that differ from the original.
 *
 * @return string[] The changed lines.
 */
function changedLines(string $before, string $after): array
{
    $beforeLines = explode("\n", $before);

    return array_values(array_filter(
        explode("\n", $after),
        static fn(string $line, int $index): bool => ($beforeLines[$index] ?? null) !== $line,
        ARRAY_FILTER_USE_BOTH,
    ));
}

it('changes one authored value and leaves every other byte alone', function () {
    [$root, $path] = projectWithAuthoredWeapon();
    $database = inventoryDatabase($root, 'weapons');
    $index = array_search('Ember Blade', $database->getEntryLabels(), true);

    $database->setField($index, 'price', '450');
    $database->save();

    $after = (string) file_get_contents($path);

    expect(substr_count($after, "\n"))->toBe(substr_count(AUTHORED_WEAPON_RECORD, "\n"))
        ->and(changedLines(AUTHORED_WEAPON_RECORD, $after))->toBe(["    'price' => 450,"])
        ->and(inventoryDatabase($root, 'weapons')->getRecordByIndex($index)?->get('price'))->toBe(450);
});

it('edits a nested value in place, keeping the comments around it', function () {
    [$root, $path] = projectWithAuthoredWeapon();
    $database = inventoryDatabase($root, 'weapons');
    $index = array_search('Ember Blade', $database->getEntryLabels(), true);

    $database->setField($index, 'parameterChanges.attack', '9');
    $database->save();

    expect((string) file_get_contents($path))->toBe(str_replace("'attack' => 6,", "'attack' => 9,", AUTHORED_WEAPON_RECORD));
});

it('writes nothing at all when nothing changed', function () {
    [$root] = projectWithAuthoredWeapon();
    $before = sourceHashTree($root);

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

    expect(sourceHashTree($root))->toBe($before);
});

it('leaves every other file in the project byte-identical', function () {
    [$root] = projectWithAuthoredWeapon();
    $before = sourceHashTree($root);

    $database = inventoryDatabase($root, 'items');
    $database->setField(1, 'description', 'Cures poison, and tastes of it.');
    $database->save();

    expect(array_keys(array_diff_assoc(sourceHashTree($root), $before)))->toBe(['assets/Data/Items/0002-antidote.php']);
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
        ->and($inline->source)->toContain('new Item(')
        ->and($inline->source)->toContain('new \Ichiloto\Engine\Entities\Inventory\Items\Item(')
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

it('refuses to regenerate a commented file it cannot edit in place, rather than drop the comments', function () {
    $root = makeTemporaryProject('ichiloto-source-');
    $path = $root . '/assets/Data/states.php';
    file_put_contents($path, "<?php\n\nreturn array_merge(\n  // keep me\n  [['id' => 'poison', 'name' => 'Poison']],\n);\n");
    $file = \Ichiloto\Editor\Database\PhpDataFile::load($path);

    expect($file->isEditable())->toBeFalse()
        ->and($file->readOnlyReason)->toContain('comments inside its data');
});
