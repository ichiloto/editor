<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\RecordPanes;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Localization\EngineMessageTerms;

/**
 * Terms offers every message the Engine says, from the Engine's own catalog,
 * with its default, what it is for and the placeholders it fills, even where a
 * project's config.php leaves it out; rewording one writes it into the file
 * without disturbing the rest, and choosing its default writes nothing.
 * Synthetic projects only.
 */

function createTermsProject(): string
{
    $root = makeTemporaryProject('editor-terms-');
    file_put_contents($root . '/config.php', "<?php\n// Keep header.\nreturn [\n  'vocab' => ['title' => 'Hello'],\n  'messages' => [/* kept */ 'file' => 'Slot', 'computed' => strtoupper('keep')],\n];\n");

    return $root;
}

/** @return array{0: Ichiloto\Editor\Database\ProjectRecordDatabase, 1: int} The Terms category and a term's index. */
function findTerm(ProjectWorkspace $workspace, string $path): array
{
    $terms = $workspace->getRecordDatabase('terms');
    $index = array_search($path, $terms->getEntryLabels(), true);

    return [$terms, $index === false ? throw new RuntimeException("No term {$path}.") : $index];
}

it('offers every Engine message, with the project\'s own wording where it has one', function () {
    $workspace = ProjectWorkspace::fromProject(createTermsProject());
    $labels = $workspace->getRecordDatabase('terms')->getEntryLabels();

    foreach (array_keys(EngineMessageTerms::listTerms()) as $path) {
        expect($labels)->toContain('messages.' . $path);
    }
    expect(array_count_values($labels)['messages.file'])->toBe(1);
    [$terms, $index] = findTerm($workspace, 'messages.file');
    expect($terms->getRecordByIndex($index)?->get('value'))->toBe('Slot');
});

it('writes a reworded Engine message into config.php, keeps the source, writes nothing for its default, and keeps blank', function () {
    $root = createTermsProject();
    $original = file_get_contents($root . '/config.php');
    $workspace = ProjectWorkspace::fromProject($root);
    [$terms, $index] = findTerm($workspace, 'messages.battle.announcements.attack');

    $terms->setField($index, 'value', '%2');
    expect($workspace->hasUnsavedChanges())->toBeFalse();
    $terms->setField($index, 'value', '%1 attacks!');
    $terms->save();
    $saved = file_get_contents($root . '/config.php');
    expect((require $root . '/config.php')['messages']['battle']['announcements']['attack'])->toBe('%1 attacks!')
        ->and($saved)->toContain('// Keep header.', '/* kept */', "strtoupper('keep')");

    [$terms, $guard] = findTerm(ProjectWorkspace::fromProject($root), 'messages.battle.announcements.guard');
    $terms->setField($guard, 'value', '');
    $terms->save();
    expect((require $root . '/config.php')['messages']['battle']['announcements']['guard'])->toBe('')
        ->and($original)->not->toBe(file_get_contents($root . '/config.php'));
});

it('describes a term as the Engine does, and shows what a battle callout will display', function () {
    $root = createTermsProject();
    $workspace = ProjectWorkspace::fromProject($root);
    [$terms, $index] = findTerm($workspace, 'messages.battle.announcements.item');
    $terms->setField($index, 'value', '%1 uses %2!');
    $lines = implode("\n", RecordPanes::describe($workspace, 'terms', $index)['preview']['lines']);
    $term = EngineMessageTerms::listTerms()['battle.announcements.item'];

    expect($lines)->toContain($term['description'], 'Default: %2', '%1 = Actor name', 'Shows: Actor uses Action!');
    [, $file] = findTerm($workspace, 'messages.file');
    expect(implode("\n", RecordPanes::describe($workspace, 'terms', $file)['preview']['lines']))->not->toContain('Shows:');
});
