<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * The armor bug, generalised: "Created" must mean the entry exists after a
 * save and a reload, in every category that offers creation — and a category
 * that cannot create must refuse up front, not lose the entry later. This
 * walks every schema so the next category added is held to the same bargain
 * automatically.
 */
it('round-trips a created entry through save and reload in every schema category', function () {
    $root = makeTemporaryProject();

    // The empty-file starting point for the array-backed categories the
    // fixture does not ship.
    foreach (['states', 'troops'] as $bare) {
        $path = $root . '/assets/Data/' . $bare . '.php';

        if (! is_file($path)) {
            file_put_contents($path, "<?php\n\nreturn [];\n");
        }
    }

    // An enemy's constructor loads its sprite, so authoring one needs a
    // sprite to exist -- the same precondition a real project meets.
    mkdir($root . '/assets/Graphics/Enemies', 0o777, true);
    file_put_contents($root . '/assets/Graphics/Enemies/blob.txt', "(oo)\n~~~~\n");

    $created = [];
    $refused = [];

    foreach (array_keys(RecordSchemaCatalog::all()) as $categoryKey) {
        $database = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($categoryKey));

        if (! $database->isEditable()) {
            $refused[] = $categoryKey;
            continue;
        }

        $index = $database->addRecord();

        if ($index === null) {
            // Refusing is legitimate (terms are config leaves); the contract
            // is only that refusal happens here, not after a save.
            $refused[] = $categoryKey;
            continue;
        }

        $label = $database->getEntryLabels()[$index] ?? '';

        // Pest's toContain takes extra needles, not a message, so boolean
        // expectations carry the per-category diagnosis instead.
        expect(trim($label) !== '')
            ->toBeTrue("A created {$categoryKey} entry has no label to select it by.");

        $database->save();

        $reloaded = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($categoryKey));

        expect(in_array($label, $reloaded->getEntryLabels(), true))
            ->toBeTrue("A created {$categoryKey} entry vanished between save and reload.");

        $created[] = $categoryKey;
    }

    // The point of walking the catalog: every category is on one of the two
    // honest paths, and the object-backed four actually create.
    expect($created)->toContain('items')
        ->and($created)->toContain('weapons')
        ->and($created)->toContain('armors')
        ->and($created)->toContain('enemies')
        ->and($created)->toContain('states')
        ->and($created)->toContain('troops')
        ->and($created)->toContain('skits')
        ->and($created)->toContain('common_events')
        ->and($refused)->not->toContain('items');
});

it('loads every category identically from an unrelated working directory', function () {
    $root = makeTemporaryProject();
    $elsewhere = sys_get_temp_dir();
    $previous = (string) getcwd();

    // The enemies regression, generalised: authored files construct engine
    // objects whose asset loading resolves against the process cwd, so a
    // category must not read differently — or go silently read-only —
    // depending on where the process stands.
    $countsFromProject = [];
    $countsFromElsewhere = [];

    try {
        chdir($root);

        foreach (array_keys(RecordSchemaCatalog::all()) as $categoryKey) {
            $database = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($categoryKey));
            $countsFromProject[$categoryKey] = [count($database->getRecords()), $database->isEditable()];
        }

        chdir($elsewhere);

        foreach (array_keys(RecordSchemaCatalog::all()) as $categoryKey) {
            $database = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($categoryKey));
            $countsFromElsewhere[$categoryKey] = [count($database->getRecords()), $database->isEditable()];
        }
    } finally {
        chdir($previous);
    }

    expect($countsFromElsewhere)->toBe($countsFromProject);
});

it('offers the project sprites for an enemy image instead of a path to type', function () {
    $root = makeTemporaryProject();
    mkdir($root . '/assets/Graphics/Enemies', 0o777, true);
    touch($root . '/assets/Graphics/Enemies/bat.txt');
    touch($root . '/assets/Graphics/Enemies/wolf.txt');

    $catalog = new Ichiloto\Editor\Database\ReferenceCatalog(ProjectWorkspace::fromProject($root));

    // The engine loads Graphics/Enemies/<value>.txt, appending the
    // extension itself, so the stems are the values -- exactly what the
    // shipped enemies.php files store.
    expect($catalog->valuesFor('enemy_sprites'))->toBe(['bat', 'wolf']);

    $fields = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('enemies'))
        ->getSettingsFields(0);

    foreach ($fields as $field) {
        if (($field['field'] ?? null) === 'imagePath') {
            expect($field['reference'] ?? null)->toBe('enemy_sprites')
                ->and($field)->not->toHaveKey('control');

            return;
        }
    }

    // The fixture ships no enemies, so index 0 may not exist; the reference
    // declaration is still checkable from the schema.
    $schemaFields = [];

    foreach (RecordSchemaCatalog::forKey('enemies')->fields as $field) {
        $schemaFields[$field->key] = $field->reference;
    }

    expect($schemaFields['imagePath'])->toBe('enemy_sprites');
});
