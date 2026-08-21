<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * The bargain the whole editor rests on, held across every category at once.
 *
 * Individual suites prove each category authors what it claims to. What is
 * checked here is the property that has to hold everywhere: that looking at
 * a project changes nothing, that saving changes only what was edited, and
 * that an undone edit leaves the bytes it started with. A category added
 * later is held to the same bargain without anyone remembering to add it.
 */

/**
 * Prepares a project every category can be opened against.
 *
 * @return string The project root.
 */
function integrityProject(): string
{
    $root = makeTemporaryProject('ichiloto-integrity-');

    foreach (['states', 'troops'] as $bare) {
        $path = $root . '/assets/Data/' . $bare . '.php';

        if (! is_file($path)) {
            file_put_contents($path, "<?php\n\nreturn [];\n");
        }
    }

    file_put_contents($root . '/assets/Data/knowledge.php', <<<'PHP'
    <?php

    return [
      'recordTypes' => ['creature'],
      'subjects' => [
        ['id' => 'creature.rat', 'recordType' => 'creature', 'displayName' => 'Rat', 'quickCard' => 'A rat.'],
      ],
      'reports' => [
        ['id' => 'report.rat', 'subject' => 'creature.rat', 'title' => 'Rats', 'summary' => 'They are about.'],
      ],
    ];
    PHP);

    file_put_contents($root . '/assets/Data/permanent-growth.php', <<<'PHP'
    <?php

    return [
      ['id' => 'growth.vigour', 'stat' => 'attack', 'amount' => 3, 'sourceType' => 'landmark', 'sourceId' => 'spring'],
    ];
    PHP);

    file_put_contents($root . '/assets/Data/equipment-optimization.php', <<<'PHP'
    <?php

    return [
      'statWeights' => ['attack' => 2],
      'elementOutcomeWeights' => ['defence:*:resist' => 6],
      'excludedAvailabilities' => ['story-controlled'],
    ];
    PHP);

    mkdir($root . '/assets/Graphics/Enemies', 0o777, true);
    file_put_contents($root . '/assets/Graphics/Enemies/blob.txt', "(oo)\n~~~~\n");

    return $root;
}

/**
 * Returns every file's modification time, so a save that rewrote a file with
 * identical bytes is still visible.
 *
 * @return array<string, int>
 */
function sourceTimestamps(string $root): array
{
    $times = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->isFile()) {
            $times[$file->getPathname()] = (int) $file->getMTime();
        }
    }

    ksort($times);

    return $times;
}

/**
 * Opens an editor over a project, sized for a settings pane.
 */
function integrityEditor(string $root): Editor
{
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);

    return $editor;
}

it('changes nothing by opening, browsing, previewing, filtering and validating', function () {
    $root = integrityProject();
    $before = sourceHashTree($root);
    $times = sourceTimestamps($root);
    $editor = integrityEditor($root);

    foreach (DatabaseCatalog::all() as $category) {
        callEditorMethod($editor, 'openDatabaseAtCategory', $category->key);

        // Everything an author does while looking: read the list, read every
        // record's rows, filter the list, and look again.
        callEditorMethod($editor, 'getDatabaseEntryLabels');
        callEditorMethod($editor, 'getDatabaseSettingsFields');

        /** @var \Ichiloto\Editor\UI\ListFilter $filter */
        $filter = getEditorProperty($editor, 'databaseFilter');
        $filter->open();
        $filter->type('a');
        $filter->commit();
        callEditorMethod($editor, 'getDatabaseEntryLabels');
        $filter->clear();
    }

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');

    // Validation reads the whole project, including the engine-backed
    // previews and catalogues.
    new ProjectValidator()->validate($workspace);

    expect($workspace->hasUnsavedChanges())->toBeFalse()
        ->and(sourceHashTree($root))->toBe($before)
        ->and(sourceTimestamps($root))->toBe($times);
});

it('writes nothing when an untouched project is saved wholesale', function () {
    $root = integrityProject();
    $editor = integrityEditor($root);
    $before = sourceHashTree($root);
    $times = sourceTimestamps($root);

    callEditorMethod($editor, 'saveAllAssets');

    // A no-op Save All is not "wrote the same bytes"; it is "wrote nothing",
    // which is what leaves a file's timestamp alone.
    expect(sourceHashTree($root))->toBe($before)
        ->and(sourceTimestamps($root))->toBe($times);
});

it('leaves every unrelated file untouched when one category saves', function () {
    $skipped = [];
    $checked = [];

    foreach (array_keys(RecordSchemaCatalog::all()) as $categoryKey) {
        $root = integrityProject();
        $schema = RecordSchemaCatalog::forKey($categoryKey);
        $database = ProjectRecordDatabase::fromProject($root, $schema);

        if (! $database->isEditable() || $database->getRecords() === []) {
            $skipped[] = $categoryKey;
            continue;
        }

        $before = sourceHashTree($root);
        $index = $database->addRecord();

        if ($index === null) {
            $skipped[] = $categoryKey;
            continue;
        }

        $database->save();
        $changed = array_keys(array_diff_assoc(sourceHashTree($root), $before));

        // A category writes its own backing file and nothing else -- even
        // where several categories share one file, and where a category
        // keeps one file per record under a directory of its own.
        $strays = array_values(array_filter(
            $changed,
            static fn(string $path): bool => $path !== $schema->relativePath
                && ! str_starts_with($path, $schema->relativePath . '/'),
        ));

        expect($strays)->toBe(
            [],
            sprintf('Saving %s also changed %s.', $categoryKey, implode(', ', $strays)),
        );
        expect($changed)->not->toBe([], sprintf('Saving %s wrote nothing at all.', $categoryKey));

        $checked[] = $categoryKey;
    }

    // The matrix is only worth anything if it actually walked the catalogue.
    expect($checked)->toContain('items')
        ->toContain('knowledge_subjects')
        ->toContain('permanent_growth')
        ->toContain('optimize_weights')
        ->and($skipped)->not->toContain('items');
});

it('is byte-identical when every category is opened and saved without an edit', function () {
    $root = integrityProject();
    $before = sourceHashTree($root);
    $times = sourceTimestamps($root);

    foreach (array_keys(RecordSchemaCatalog::all()) as $categoryKey) {
        $database = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($categoryKey));

        foreach (array_keys($database->getRecords()) as $index) {
            $database->getSettingsFields($index);
        }

        expect($database->isDirty())->toBeFalse(sprintf('Browsing %s dirtied it.', $categoryKey));

        // A read-only category refuses a save outright, which is its own
        // guarantee that browsing it cannot write.
        if ($database->isEditable()) {
            $database->save();
        }
    }

    expect(sourceHashTree($root))->toBe($before)
        ->and(sourceTimestamps($root))->toBe($times);
});

it('marks the project dirty on an edit and clean again on a save', function () {
    $root = integrityProject();
    $editor = integrityEditor($root);
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');

    expect($workspace->hasUnsavedChanges())->toBeFalse();

    callEditorMethod($editor, 'openDatabaseAtCategory', 'states');
    callEditorMethod($editor, 'applyDatabaseFieldValue', 'name', 'Renamed State');

    // The quit guard reads exactly this, so an edit that does not reach it
    // is an edit a quit would throw away silently.
    expect($workspace->hasUnsavedChanges())->toBeTrue();

    callEditorMethod($editor, 'saveAllAssets');

    expect($workspace->hasUnsavedChanges())->toBeFalse();
});

it('returns the project to its exact bytes when an edit is undone', function () {
    $root = integrityProject();
    $before = sourceHashTree($root);
    $editor = integrityEditor($root);

    callEditorMethod($editor, 'openDatabaseAtCategory', 'states');
    $fields = array_column(callEditorMethod($editor, 'getDatabaseSettingsFields'), null, 'field');

    // Recorded edits are what an author makes; the unrecorded path is for
    // the editor's own bookkeeping and is deliberately not undoable.
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields['name'], 'Renamed State');
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields['description'], 'Rewritten.');
    callEditorMethod($editor, 'saveAllAssets');

    expect(sourceHashTree($root))->not->toBe($before);

    callEditorMethod($editor, 'performUndo');
    callEditorMethod($editor, 'performUndo');

    callEditorMethod($editor, 'saveAllAssets');

    // Pristine means the bytes, not "an equivalent file": an undo that
    // reformats has still lost what the author wrote.
    expect(sourceHashTree($root))->toBe($before);
});

it('leaves no temporary file behind, whichever category wrote', function () {
    $root = integrityProject();

    foreach (array_keys(RecordSchemaCatalog::all()) as $categoryKey) {
        $database = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($categoryKey));

        if (! $database->isEditable() || $database->addRecord() === null) {
            continue;
        }

        $database->save();
    }

    $strays = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        // An atomic write goes to a neighbouring temporary file and is then
        // renamed over the target; one left behind means a write that did
        // not complete, or one that never cleaned up after itself.
        if ($file->isFile() && preg_match('/\.tmp$|~$|^\.ichiloto/', $file->getFilename()) === 1) {
            $strays[] = $file->getFilename();
        }
    }

    expect($strays)->toBe([]);
});

it('keeps a save all atomic: a failing category does not truncate its file', function () {
    $root = integrityProject();
    $path = $root . '/assets/Data/states.php';
    $database = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('states'));
    $database->addRecord();

    // The directory is made unwritable, so the rename an atomic write ends
    // with cannot happen.
    chmod($root . '/assets/Data', 0o555);

    // The refused write raises a PHP warning of its own on the way out,
    // which is the expected part of this and not a fault of the test.
    set_error_handler(static fn(): bool => true, E_WARNING);

    try {
        $before = (string) file_get_contents($path);

        try {
            $database->save();
        } catch (Throwable $refusal) {
            // A refused save is allowed; a half-written file is not.
            expect($refusal->getMessage())->toContain('Unable to write temporary file');
        }

        expect((string) file_get_contents($path))->toBe($before);
    } finally {
        restore_error_handler();
        chmod($root . '/assets/Data', 0o755);
    }
});
