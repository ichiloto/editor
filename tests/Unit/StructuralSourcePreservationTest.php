<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;

/**
 * Adding and removing an inventory record without rewriting anything else.
 *
 * Each item, weapon and armor is a record file of its own, numbered in the
 * order menus list them. Changing the shape of a category writes or deletes
 * that one file: every other record, in every inventory category, keeps its
 * own bytes.
 */

/**
 * Opens one inventory category.
 */
function structuralDatabase(string $root, string $category): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category));
}

it('adds a record as a file of its own, numbered last, leaving every other file alone', function () {
    $root = makeTemporaryProject('ichiloto-structural-');
    $before = sourceHashTree($root);

    $items = structuralDatabase($root, 'items');
    $index = $items->addRecord();
    $label = $items->getEntryLabels()[$index] ?? '';
    $items->save();

    $after = sourceHashTree($root);

    expect(array_keys(array_diff_key($after, $before)))->toBe(['assets/Data/Items/0003-item-new-item.php'])
        ->and(array_diff_assoc(array_intersect_key($after, $before), $before))->toBe([])
        ->and(structuralDatabase($root, 'items')->getEntryLabels())->toBe(['S-Potion', 'Antidote', $label]);
});

it('removes a record by removing its file, leaving its neighbours alone', function () {
    $root = makeTemporaryProject('ichiloto-structural-');
    $before = sourceHashTree($root);

    $items = structuralDatabase($root, 'items');
    $removed = $items->removeRecord(0);
    $items->save();

    $after = sourceHashTree($root);

    expect($removed?->get('name'))->toBe('S-Potion')
        ->and(array_keys(array_diff_key($before, $after)))->toBe(['assets/Data/Items/0001-s-potion.php'])
        ->and(array_diff_assoc($after, $before))->toBe([])
        ->and(structuralDatabase($root, 'items')->getEntryLabels())->toBe(['Antidote'])
        // The other inventory categories are untouched.
        ->and(structuralDatabase($root, 'weapons')->getEntryLabels())->toBe(['Wooden Sword']);
});

it('puts a deleted record back exactly, bytes and all', function () {
    $root = makeTemporaryProject('ichiloto-structural-');
    $before = sourceHashTree($root);

    $items = structuralDatabase($root, 'items');
    $removed = $items->removeRecord(1);

    // Undone before the save ever happens: nothing on disk changes, and the
    // record goes back where it came from.
    $items->insertRecord(1, $removed);
    $items->save();

    expect(sourceHashTree($root))->toBe($before);
});

it('survives add, save, delete, save, and reload in one sitting', function () {
    $root = makeTemporaryProject('ichiloto-structural-');
    $before = sourceHashTree($root);

    $armors = structuralDatabase($root, 'armors');
    $index = $armors->addRecord();
    $label = $armors->getEntryLabels()[$index] ?? '';
    $armors->save();

    $reloaded = structuralDatabase($root, 'armors');
    expect($reloaded->getEntryLabels())->toContain($label);

    $reloaded->removeRecord(intval(array_search($label, $reloaded->getEntryLabels(), true)));
    $reloaded->save();

    // Back to exactly the project it started as.
    expect(sourceHashTree($root))->toBe($before)
        ->and(structuralDatabase($root, 'armors')->getEntryLabels())->not->toContain($label);
});
