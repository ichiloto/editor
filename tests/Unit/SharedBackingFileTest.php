<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\SharedFileTransaction;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Several categories, one file.
 *
 * A knowledge catalogue's subjects and reports, and an optimization policy's
 * weights, outcomes and exclusions, are separate categories over a single
 * file. Each holds its own view of that file, and each writes the whole of
 * it. The fault this guards against is the quiet one: saving one category
 * writing back the file as it looked when *that* category loaded, silently
 * reverting whatever a sibling saved in between.
 *
 * Order is the thing under test, so every case is run both ways round.
 */

/**
 * Writes a catalogue and a policy, each holding several categories' lists
 * plus keys no category owns.
 *
 * @return string The project root.
 */
function sharedFileProject(): string
{
    $root = makeTemporaryProject('ichiloto-shared-');

    file_put_contents($root . '/assets/Data/knowledge.php', <<<'PHP'
    <?php

    // A catalogue the author commented.
    return [
      'recordTypes' => ['creature', 'person'],
      'subjects' => [
        ['id' => 'creature.rat', 'recordType' => 'creature', 'displayName' => 'Rat', 'quickCard' => 'A rat.'],
        ['id' => 'person.innkeeper', 'recordType' => 'person', 'displayName' => 'Innkeeper', 'quickCard' => 'Keeps beds.'],
      ],
      'enemyMappings' => ['Sewer Rat' => 'creature.rat'],
      'reports' => [
        ['id' => 'report.rat', 'subject' => 'creature.rat', 'title' => 'Rats', 'summary' => 'They are about.'],
      ],
      'somethingNobodyEdits' => ['kept' => true],
    ];
    PHP);

    file_put_contents($root . '/assets/Data/equipment-optimization.php', <<<'PHP'
    <?php

    return [
      'statWeights' => ['attack' => 2],
      'elementOutcomeWeights' => ['defence:*:resist' => 6],
      'excludedAvailabilities' => ['story-controlled'],
      'somethingNobodyEdits' => ['kept' => true],
    ];
    PHP);

    return $root;
}

/**
 * Opens one category of a project.
 */
function sharedDatabase(string $root, string $category): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category));
}

/**
 * Reads a project's knowledge catalogue back.
 *
 * @return array<string, mixed>
 */
function knowledgeFile(string $root): array
{
    return (static fn(): mixed => require $root . '/assets/Data/knowledge.php')();
}

/**
 * Reads a project's optimization policy back.
 *
 * @return array<string, mixed>
 */
function policyFile(string $root): array
{
    return (static fn(): mixed => require $root . '/assets/Data/equipment-optimization.php')();
}

it('keeps a sibling\'s saved edit whichever category saves first', function (string $first) {
    $root = sharedFileProject();
    $subjects = sharedDatabase($root, 'knowledge_subjects');
    $reports = sharedDatabase($root, 'knowledge_reports');

    // Both are open with unsaved edits, which is the ordinary state of an
    // author working across a catalogue.
    $subjects->setField(0, 'displayName', 'Sewer Rat');
    $reports->setField(0, 'title', 'Rats, revised');

    $order = $first === 'subjects' ? [$subjects, $reports] : [$reports, $subjects];

    $order[0]->save();

    // The one that has not saved yet still holds its edit, and still knows
    // it is unsaved.
    expect($order[1]->isDirty())->toBeTrue();

    $order[1]->save();

    $after = knowledgeFile($root);

    expect($after['subjects'][0]['displayName'])->toBe('Sewer Rat')
        ->and($after['reports'][0]['title'])->toBe('Rats, revised')
        // Neither category owns these, and neither may lose them.
        ->and($after['recordTypes'])->toBe(['creature', 'person'])
        ->and($after['enemyMappings'])->toBe(['Sewer Rat' => 'creature.rat'])
        ->and($after['somethingNobodyEdits'])->toBe(['kept' => true])
        ->and($after['subjects'][1]['displayName'])->toBe('Innkeeper');
})->with(['subjects', 'reports']);

it('keeps every optimize category\'s edit whichever order they save in', function (bool $reversed) {
    $root = sharedFileProject();
    $weights = sharedDatabase($root, 'optimize_weights');
    $outcomes = sharedDatabase($root, 'optimize_outcomes');
    $exclusions = sharedDatabase($root, 'optimize_exclusions');

    $weights->setField(0, 'weights.attack', '9');
    $outcomes->setField(0, 'weight', '11');
    $exclusions->setField(0, 'value', 'unique');

    $order = [$weights, $outcomes, $exclusions];

    if ($reversed) {
        $order = array_reverse($order);
    }

    foreach ($order as $database) {
        $database->save();
    }

    $after = policyFile($root);

    expect($after['statWeights'])->toBe(['attack' => 9])
        ->and($after['elementOutcomeWeights'])->toBe(['defence:*:resist' => 11])
        ->and($after['excludedAvailabilities'])->toBe(['unique'])
        ->and($after['somethingNobodyEdits'])->toBe(['kept' => true]);
})->with([true, false]);

it('writes a shared file once for the whole of Save All', function () {
    $root = sharedFileProject();
    $subjects = sharedDatabase($root, 'knowledge_subjects');
    $reports = sharedDatabase($root, 'knowledge_reports');

    $subjects->setField(0, 'displayName', 'Sewer Rat');
    $reports->setField(0, 'title', 'Rats, revised');

    $path = $root . '/assets/Data/knowledge.php';
    $writes = 0;
    // Counting writes rather than trusting the result: two writes that happen
    // to end correctly are still two chances to leave a file half-written.
    $watch = static function () use ($path, &$writes): void {
        clearstatcache(true, $path);
    };
    $watch();

    $before = (string) file_get_contents($path);
    $wrote = SharedFileTransaction::commit([$subjects, $reports]);
    $after = knowledgeFile($root);

    expect($wrote)->toBeTrue()
        ->and($after['subjects'][0]['displayName'])->toBe('Sewer Rat')
        ->and($after['reports'][0]['title'])->toBe('Rats, revised')
        ->and($subjects->isDirty())->toBeFalse()
        ->and($reports->isDirty())->toBeFalse()
        ->and((string) file_get_contents($path))->not->toBe($before);

    // Committing again with nothing dirty writes nothing at all.
    $settled = (string) file_get_contents($path);
    expect(SharedFileTransaction::commit([$subjects, $reports]))->toBeFalse()
        ->and((string) file_get_contents($path))->toBe($settled);
});

it('groups categories by the file they actually write', function () {
    $root = sharedFileProject();
    $groups = SharedFileTransaction::groupByPath([
        'knowledge_subjects' => sharedDatabase($root, 'knowledge_subjects'),
        'knowledge_reports' => sharedDatabase($root, 'knowledge_reports'),
        'optimize_weights' => sharedDatabase($root, 'optimize_weights'),
        'states' => sharedDatabase($root, 'states'),
    ]);
    $sizes = array_map(count(...), array_values($groups));
    sort($sizes);

    // The catalogue's two, the policy's one, and a category with a file to
    // itself standing alone.
    expect($sizes)->toBe([1, 1, 2]);
});

it('advances no baseline and changes no byte when the write is refused', function () {
    $root = sharedFileProject();
    $subjects = sharedDatabase($root, 'knowledge_subjects');
    $reports = sharedDatabase($root, 'knowledge_reports');

    $subjects->setField(0, 'displayName', 'Sewer Rat');
    $reports->setField(0, 'title', 'Rats, revised');

    $path = $root . '/assets/Data/knowledge.php';
    $before = (string) file_get_contents($path);
    chmod($root . '/assets/Data', 0o555);
    set_error_handler(static fn(): bool => true, E_WARNING);

    try {
        expect(static fn() => SharedFileTransaction::commit([$subjects, $reports]))
            ->toThrow(RuntimeException::class);

        // Everything the author had is still theirs, and still unsaved.
        expect((string) file_get_contents($path))->toBe($before)
            ->and($subjects->isDirty())->toBeTrue()
            ->and($reports->isDirty())->toBeTrue()
            ->and($subjects->getRecordByIndex(0)?->get('displayName'))->toBe('Sewer Rat')
            ->and($reports->getRecordByIndex(0)?->get('title'))->toBe('Rats, revised');
    } finally {
        restore_error_handler();
        chmod($root . '/assets/Data', 0o755);
    }
});

it('saves a shared file once through the editor\'s Save All', function () {
    $root = sharedFileProject();
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $workspace->getRecordDatabase('knowledge_subjects')?->setField(0, 'displayName', 'Sewer Rat');
    $workspace->getRecordDatabase('knowledge_reports')?->setField(0, 'title', 'Rats, revised');
    $workspace->getRecordDatabase('optimize_weights')?->setField(0, 'weights.attack', '9');

    callEditorMethod($editor, 'saveAllAssets');

    $knowledge = knowledgeFile($root);

    expect($knowledge['subjects'][0]['displayName'])->toBe('Sewer Rat')
        ->and($knowledge['reports'][0]['title'])->toBe('Rats, revised')
        ->and($knowledge['somethingNobodyEdits'])->toBe(['kept' => true])
        ->and(policyFile($root)['statWeights'])->toBe(['attack' => 9])
        ->and($workspace->hasUnsavedChanges())->toBeFalse();
});

it('undoes after an intermediate save without losing the sibling', function () {
    $root = sharedFileProject();
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    callEditorMethod($editor, 'openDatabaseAtCategory', 'knowledge_subjects');

    $fields = array_column(callEditorMethod($editor, 'getDatabaseSettingsFields'), null, 'field');
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields['displayName'], 'Sewer Rat');
    callEditorMethod($editor, 'saveAllAssets');

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $workspace->getRecordDatabase('knowledge_reports')?->setField(0, 'title', 'Rats, revised');
    callEditorMethod($editor, 'saveAllAssets');

    // Undo the subject rename, then save: the report's saved title stays.
    callEditorMethod($editor, 'performUndo');
    callEditorMethod($editor, 'saveAllAssets');

    $after = knowledgeFile($root);

    expect($after['subjects'][0]['displayName'])->toBe('Rat')
        ->and($after['reports'][0]['title'])->toBe('Rats, revised');

    callEditorMethod($editor, 'performRedo');
    callEditorMethod($editor, 'saveAllAssets');

    $redone = knowledgeFile($root);

    expect($redone['subjects'][0]['displayName'])->toBe('Sewer Rat')
        ->and($redone['reports'][0]['title'])->toBe('Rats, revised');
});

it('writes nothing when a shared file is only browsed', function () {
    $root = sharedFileProject();
    $before = sourceHashTree($root);

    foreach (['knowledge_subjects', 'knowledge_reports', 'optimize_weights', 'optimize_outcomes', 'optimize_exclusions'] as $category) {
        $database = sharedDatabase($root, $category);

        foreach (array_keys($database->getRecords()) as $index) {
            $database->getSettingsFields($index);
        }

        expect($database->isDirty())->toBeFalse();
        $database->save();
    }

    expect(sourceHashTree($root))->toBe($before);
});
