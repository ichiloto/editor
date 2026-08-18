<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\PhpSourceDocument;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;

/**
 * Adding and removing a record without rewriting the file around it.
 *
 * Editing a value was already surgical, but changing the *shape* of the list
 * fell back to the writer that rebuilds the whole returned expression. On a
 * file of authored constructor calls that means every entry reformatted,
 * arguments reordered, defaults nobody wrote written out, and any expression
 * the exporter cannot spell silently replaced -- to add one record.
 *
 * The file used here is the real game's `items.php`: fully qualified class
 * names, imports, nested constructors, enum cases, emoji icons, and items,
 * weapons and armors interleaved in one list.
 */

/**
 * Copies the real authored inventory into a throwaway project.
 *
 * @return array{0: string, 1: string}|null The project root and items path.
 */
function structuralProject(): ?array
{
    $game = gameSourceRoot();

    if ($game === null) {
        return null;
    }

    $root = makeTemporaryProject('ichiloto-structural-');
    $path = $root . '/assets/Data/items.php';
    copy($game . '/assets/Data/items.php', $path);

    return [$root, $path];
}

/**
 * Opens one inventory category.
 */
function structuralDatabase(string $root, string $category): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category));
}

/**
 * Returns the lines of the original that a change rewrote.
 *
 * @return string[] The rewritten lines.
 */
function rewrittenLines(string $before, string $after): array
{
    $beforeLines = explode("\n", $before);
    $afterLines = explode("\n", $after);
    $rewritten = [];

    foreach ($beforeLines as $index => $line) {
        if (($afterLines[$index] ?? null) !== $line) {
            $rewritten[] = $line;
        }
    }

    return $rewritten;
}

it('adds a record without rewriting the entries already in the file', function () {
    $project = structuralProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = (string) file_get_contents($path);
    $entriesBefore = PhpSourceDocument::parse($before)->entryCount();

    $weapons = structuralDatabase($root, 'weapons');
    $index = $weapons->addRecord();
    $label = $weapons->getEntryLabels()[$index] ?? '';
    $weapons->save();

    $after = (string) file_get_contents($path);

    // The list grew by one, and every byte the file had up to its closing
    // bracket is still there, in order: the new entry was inserted, not
    // written by rebuilding the list around it.
    $upToClose = rtrim(substr($before, 0, (int) strrpos($before, '];')));

    expect(PhpSourceDocument::parse($after)->entryCount())->toBe($entriesBefore + 1)
        ->and(str_starts_with($after, $upToClose))->toBeTrue()
        ->and(rewrittenLines($before, $after))->toHaveCount(2)
        // Everything the surgical writer exists to protect.
        ->and($after)->toContain('use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;')
        ->and($after)->toContain("id: 'item.s-potion',")
        ->and($after)->toContain('equipmentType: \Ichiloto\Engine\Entities\Enumerations\WeaponType::SWORD,')
        ->and($after)->toContain('parameterChanges: new \Ichiloto\Engine\Entities\ParameterChanges(');

    $reloaded = structuralDatabase($root, 'weapons');
    expect($reloaded->getEntryLabels())->toContain($label);
})->group('engine');

it('removes a record without rewriting its neighbours', function () {
    $project = structuralProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = (string) file_get_contents($path);
    $entriesBefore = PhpSourceDocument::parse($before)->entryCount();

    $items = structuralDatabase($root, 'items');
    $labels = $items->getEntryLabels();
    $removed = $items->removeRecord(1);
    $items->save();

    $after = (string) file_get_contents($path);

    expect($removed?->get('name'))->toBe($labels[1])
        ->and(PhpSourceDocument::parse($after)->entryCount())->toBe($entriesBefore - 1)
        // The entries either side of it kept their own bytes.
        ->and($after)->toContain("id: 'item.s-potion',")
        ->and($after)->toContain('use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;');

    $reloaded = structuralDatabase($root, 'items');
    expect($reloaded->getEntryLabels())->not->toContain($labels[1])
        ->and($reloaded->getEntryLabels())->toContain($labels[0]);
})->group('engine');

it('leaves the other categories of a shared file alone', function () {
    $project = structuralProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $weaponsBefore = structuralDatabase($root, 'weapons')->getEntryLabels();
    $armorsBefore = structuralDatabase($root, 'armors')->getEntryLabels();

    // Items, weapons and armors are interleaved in one list, so removing an
    // item must not disturb the weapons and armors around it.
    $items = structuralDatabase($root, 'items');
    $items->removeRecord(0);
    $items->save();

    expect(structuralDatabase($root, 'weapons')->getEntryLabels())->toBe($weaponsBefore)
        ->and(structuralDatabase($root, 'armors')->getEntryLabels())->toBe($armorsBefore);
})->group('engine');

it('puts a deleted record back exactly, bytes and all', function () {
    $project = structuralProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = (string) file_get_contents($path);

    $items = structuralDatabase($root, 'items');
    $removed = $items->removeRecord(1);

    // Undone before the save ever happens: the file was never changed, and
    // the record goes back where it came from.
    $items->insertRecord(1, $removed);
    $items->save();

    expect((string) file_get_contents($path))->toBe($before);
})->group('engine');

it('survives add, save, delete, save, and reload in one sitting', function () {
    $project = structuralProject();

    if ($project === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    [$root, $path] = $project;
    $before = (string) file_get_contents($path);
    $entriesBefore = PhpSourceDocument::parse($before)->entryCount();

    $armors = structuralDatabase($root, 'armors');
    $index = $armors->addRecord();
    $label = $armors->getEntryLabels()[$index] ?? '';
    $armors->save();

    expect(PhpSourceDocument::parse((string) file_get_contents($path))->entryCount())->toBe($entriesBefore + 1);

    $reloaded = structuralDatabase($root, 'armors');
    $at = array_search($label, $reloaded->getEntryLabels(), true);
    $reloaded->removeRecord(intval($at));
    $reloaded->save();

    $after = (string) file_get_contents($path);

    // Back to the count it started at, with the original entries still
    // spelled the way the author spelled them.
    expect(PhpSourceDocument::parse($after)->entryCount())->toBe($entriesBefore)
        ->and($after)->toContain("id: 'item.s-potion',")
        ->and(structuralDatabase($root, 'armors')->getEntryLabels())->not->toContain($label);
})->group('engine');

it('refuses a structural edit it cannot make in the source, rather than regenerating', function () {
    // The fixture authors its entries positionally, so an argument cannot be
    // patched by name -- but an entry can still be appended and removed by
    // span, which is what makes the refusal specific rather than blanket.
    $root = makeTemporaryProject('ichiloto-structural-');
    $path = $root . '/assets/Data/items.php';
    $before = (string) file_get_contents($path);

    expect($before)->toContain("new Item('S-Potion'");

    $items = structuralDatabase($root, 'items');
    $items->addRecord();
    $items->save();

    $after = (string) file_get_contents($path);

    expect(PhpSourceDocument::parse($after)->entryCount())->toBe(PhpSourceDocument::parse($before)->entryCount() + 1)
        // The positional entries kept their own spelling.
        ->and($after)->toContain("new Item('S-Potion'");
});
