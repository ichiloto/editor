<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Returns the Database list pane's lines with the given category selected.
 *
 * @param string $projectRoot The project.
 * @param string $categoryKey The category to select.
 * @return string[] The lines.
 */
function databaseListLinesFor(string $projectRoot, string $categoryKey): array
{
    $editor = createEditorForTesting($projectRoot);

    // Running the editor loads the workspace; a test stands it up directly.
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($projectRoot));
    callEditorMethod($editor, 'openDatabaseAtCategory', $categoryKey);

    /** @var string[] $lines */
    $lines = callEditorMethod($editor, 'getDatabaseListLines');

    return $lines;
}

it('lists the entries of every schema-driven category', function () {
    $root = makeTemporaryProject();

    foreach (array_keys(RecordSchemaCatalog::all()) as $categoryKey) {
        $text = implode("\n", databaseListLinesFor($root, $categoryKey));

        // Every category backed by a schema shows its project's entries. The
        // placeholder is what these panes used to be.
        expect(str_contains($text, 'Editor coming soon.'))
            ->toBeFalse("The {$categoryKey} pane is still a placeholder.");
    }
});

it('shows a project\'s items by name', function () {
    $lines = databaseListLinesFor(makeTemporaryProject(), 'items');

    expect(implode("\n", $lines))->toContain('S-Potion');
});

it('marks which entry is selected', function () {
    $lines = databaseListLinesFor(makeTemporaryProject(), 'items');

    expect($lines[0] ?? '')->toStartWith('> ');
});

it('says why a category cannot be edited rather than looking broken', function () {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'types');

    // Element and weapon types are PHP enum declarations, not data.
    expect($database->isEditable())->toBeFalse();

    $text = implode("\n", databaseListLinesFor($root, 'types'));

    expect($text)->toContain($database->getReadOnlyReason() ?? 'read only');
});

it('tells an author how to start an empty editable category', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/states.php', "<?php\n\nreturn [];\n");

    $text = implode("\n", databaseListLinesFor($root, 'states'));

    expect($text)->toContain('No States yet.')
        ->and($text)->toContain('Shift+A');
});

it('still lists the categories that had their own pane', function () {
    $root = makeTemporaryProject();

    expect(implode("\n", databaseListLinesFor($root, 'quests')))->not->toContain('coming soon')
        ->and(implode("\n", databaseListLinesFor($root, 'actors')))->not->toContain('coming soon');
});
