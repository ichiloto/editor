<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;

/**
 * Loads a category database against a project.
 *
 * @param string $root The project root.
 * @param string $category The category key.
 * @return ProjectRecordDatabase The database.
 */
function objectCategoryOn(string $root, string $category): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category));
}

it('creates an armor that survives its own save', function () {
    $root = makeTemporaryProject();
    $database = objectCategoryOn($root, 'armors');

    // The report that started this: Shift+A said "Created a new armor",
    // the save said nothing, and the reload had no armor. The blank was an
    // empty array in a category whose entries are Armor objects, so the
    // category filter dropped it between saving and reading back.
    $index = $database->addRecord();

    expect($index)->not->toBeNull();

    $database->setField($index, 'name', 'Iron Cuirass');
    $database->setField($index, 'equipmentType', 'Heavy Armor');
    $database->setField($index, 'parameterChanges.defence', '7');
    $database->save();

    $reloaded = objectCategoryOn($root, 'armors');
    $labels = $reloaded->getEntryLabels();

    expect($labels)->toContain('Iron Cuirass');

    $armor = $reloaded->getRecords()[array_search('Iron Cuirass', $labels, true)]->toArray();

    expect($armor)->toBeInstanceOf(Armor::class)
        ->and($armor->equipmentType)->toBe(ArmorType::HEAVY_ARMOR)
        ->and($armor->parameterChanges->defence)->toBe(7);
});

it('creates a weapon and an item the same way', function () {
    $root = makeTemporaryProject();

    $weapons = objectCategoryOn($root, 'weapons');
    $weapons->setField($weapons->addRecord(), 'name', 'Bronze Axe');
    $weapons->save();

    $items = objectCategoryOn($root, 'items');
    $items->setField($items->addRecord(), 'name', 'Bitterroot Tonic');
    $items->save();

    $reloadedWeapons = objectCategoryOn($root, 'weapons');
    $reloadedItems = objectCategoryOn($root, 'items');

    expect($reloadedWeapons->getEntryLabels())->toContain('Bronze Axe')
        ->and($reloadedItems->getEntryLabels())->toContain('Bitterroot Tonic')
        // Sharing items.php cuts both ways: creating in one category must
        // not disturb the other's entries.
        ->and($reloadedItems->getEntryLabels())->toContain('S-Potion');

    $weapon = $reloadedWeapons->getRecords()[
        array_search('Bronze Axe', $reloadedWeapons->getEntryLabels(), true)
    ]->toArray();

    expect($weapon)->toBeInstanceOf(Weapon::class);
});

it('numbers a second new armor instead of colliding, in name and in identity', function () {
    $database = objectCategoryOn(makeTemporaryProject(), 'armors');

    $first = $database->addRecord();
    $second = $database->addRecord();

    // The name is what an author reads; the id is what saves, aliases and
    // every reference resolve, so both have to be distinct.
    expect($database->getRecords()[$first]->getDisplayValue('name'))->toBe('New Armor')
        ->and($database->getRecords()[$second]->getDisplayValue('name'))->toBe('New Armor 2')
        ->and($database->getRecords()[$first]->get('id'))->toBe('equipment.new-armor')
        ->and($database->getRecords()[$second]->get('id'))->toBe('equipment.new-armor-2');
});

it('offers the equipment slots rather than a spelling test', function () {
    $database = objectCategoryOn(makeTemporaryProject(), 'armors');

    // The fixture ships no armor, which is exactly the state the user was
    // in: the first armor has to come from the editor.
    $fields = $database->getSettingsFields($database->addRecord());
    $byLabel = [];

    foreach ($fields as $field) {
        $byLabel[(string) ($field['label'] ?? '')] = $field;
    }

    // "Heavy Armor" typed with any variation would silently not match the
    // enum; a choice list cannot be misspelled.
    expect($byLabel['Equipment Type']['options'] ?? null)->toContain('Heavy Armor')
        ->and($byLabel['Equipment Type']['options'] ?? null)->toContain('Small Shield');
});

it('sets the equipment type as the enum case, whatever the case typed', function () {
    $root = makeTemporaryProject();
    $database = objectCategoryOn($root, 'armors');
    $index = $database->addRecord();

    $database->setField($index, 'equipmentType', 'heavy armor');

    expect($database->getRecords()[$index]->toArray()->equipmentType)->toBe(ArmorType::HEAVY_ARMOR);
});

it('refuses to create rather than create what the save would drop', function () {
    $root = makeTemporaryProject();

    // A blank that cannot be built (the factory throws), and a blank that is
    // not a member of its own category (the filter rejects it): both must
    // refuse up front. "Created" followed by a silent disappearance on save
    // is the failure mode this guards against.
    $throwing = new Ichiloto\Editor\Database\RecordSchema(
        key: 'armors',
        entryNoun: 'armor',
        storage: Ichiloto\Editor\Database\RecordStorage::LIST_FILE,
        relativePath: 'assets/Data/items.php',
        fields: [],
        identityKey: 'name',
        recordFilter: static fn(mixed $entry): bool => $entry instanceof Armor,
        makeBlank: static function (): object {
            throw new RuntimeException('no blank today');
        },
    );

    $mismatched = new Ichiloto\Editor\Database\RecordSchema(
        key: 'armors',
        entryNoun: 'armor',
        storage: Ichiloto\Editor\Database\RecordStorage::LIST_FILE,
        relativePath: 'assets/Data/items.php',
        fields: [],
        identityKey: 'name',
        recordFilter: static fn(mixed $entry): bool => $entry instanceof Armor,
        makeBlank: static fn(string $name): object => new Item($name, '', '!', 0),
    );

    expect(ProjectRecordDatabase::fromProject($root, $throwing)->addRecord())->toBeNull()
        ->and(ProjectRecordDatabase::fromProject($root, $mismatched)->addRecord())->toBeNull();
});

it('still creates array-backed records exactly as before', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/states.php', "<?php\n\nreturn [];\n");
    $database = objectCategoryOn($root, 'states');

    $index = $database->addRecord();

    expect($index)->not->toBeNull()
        ->and($database->getRecords()[$index]->toArray())->toBeArray();
});
