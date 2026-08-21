<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\PermanentGrowthCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;

/**
 * Writes a project's permanent-growth definitions.
 *
 * @param string $root The project root.
 * @param string $body The list body, as PHP source.
 * @return string The path written.
 */
function writeGrowthDefinitions(string $root, string $body): string
{
    $path = $root . '/' . PermanentGrowthCatalog::RELATIVE_PATH;
    file_put_contents($path, "<?php\n\nreturn [\n" . $body . "\n];\n");

    return $path;
}

/**
 * Opens the permanent-growth category of a project.
 */
function growthDatabase(string $root): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('permanent_growth'));
}

/**
 * Returns a project's issues as "where: message" lines.
 *
 * @return string[] The lines.
 */
function growthIssues(string $root, ?Severity $severity = null): array
{
    $issues = new \Ichiloto\Editor\Validation\ProjectValidator()
        ->validate(ProjectWorkspace::fromProject($root));

    return array_values(array_map(
        static fn($issue): string => $issue->where . ': ' . $issue->message,
        array_filter($issues, static fn($issue): bool => $severity === null || $issue->severity === $severity),
    ));
}

it('authors a definition the runtime grants', function () {
    if (! PermanentGrowthCatalog::isAvailable()) {
        $this->markTestSkipped('The engine permanent-growth contract is not reachable from this checkout.');
    }

    $root = makeTemporaryProject('ichiloto-growth-');
    $path = writeGrowthDefinitions($root, '');

    $database = growthDatabase($root);
    $index = $database->addRecord();

    $database->setField($index, 'id', 'growth.spring-of-vigour');
    $database->setField($index, 'metadata.label', 'The Spring of Vigour');
    $database->setField($index, 'stat', 'maxHp');
    $database->setField($index, 'amount', '25');
    $database->setField($index, 'sourceType', 'landmark');
    $database->setField($index, 'sourceId', 'happyville/spring');
    $database->save();

    $authored = (require $path)[0];

    // The engine builds it, and a ledger grants it: the definition is what
    // the runtime's own contract takes, not a shape of the editor's.
    $modifier = \Ichiloto\Engine\Entities\Stats\PermanentStatModifier::fromArray($authored);
    $ledger = new \Ichiloto\Engine\Entities\Stats\PermanentGrowthLedger();

    expect($ledger->grant($modifier))->toBeTrue()
        ->and($modifier->id)->toBe('growth.spring-of-vigour')
        ->and($modifier->stat)->toBe(\Ichiloto\Engine\Entities\Stats\StatKey::MAX_HP)
        ->and($modifier->amount)->toBe(25)
        ->and($modifier->sourceType)->toBe('landmark')
        ->and($modifier->sourceId)->toBe('happyville/spring')
        // The project's own name for it rides in the metadata the engine
        // reserves for exactly that, so it is never part of identity.
        ->and($modifier->metadata['label'])->toBe('The Spring of Vigour')
        ->and($ledger->totalFor(\Ichiloto\Engine\Entities\Stats\StatKey::MAX_HP))->toBe(25);
});

it('keeps a growth that is a loss, and the metadata around it', function () {
    $root = makeTemporaryProject('ichiloto-growth-');
    $path = writeGrowthDefinitions($root, <<<'PHP'
      [
        'id' => 'growth.the-blight',
        'stat' => 'speed',
        'amount' => -3,
        'sourceType' => 'curse',
        'sourceId' => 'blightwood',
        'metadata' => ['label' => 'The Blight', 'chapter' => 4],
      ],
    PHP);

    $database = growthDatabase($root);

    expect($database->getRecordByIndex(0)?->get('amount'))->toBe(-3)
        ->and($database->getEntryLabels())->toBe(['The Blight']);

    $database->setField(0, 'metadata.note', 'Lifted when the wood is burned.');
    $database->save();

    $authored = (require $path)[0];

    // A signed amount keeps its sign, and metadata the editor does not
    // author is not metadata the editor discards.
    expect($authored['amount'])->toBe(-3)
        ->and($authored['metadata'])->toBe([
            'label' => 'The Blight',
            'chapter' => 4,
            'note' => 'Lifted when the wood is burned.',
        ]);
});

it('reports what the runtime would refuse, in the runtime\'s own words', function () {
    if (! PermanentGrowthCatalog::isAvailable()) {
        $this->markTestSkipped('The engine permanent-growth contract is not reachable from this checkout.');
    }

    $root = makeTemporaryProject('ichiloto-growth-');
    writeGrowthDefinitions($root, <<<'PHP'
      ['id' => '', 'stat' => 'attack', 'amount' => 1, 'sourceType' => 'event', 'sourceId' => 'e1'],
      ['id' => 'growth.bad-stat', 'stat' => 'accuracy', 'amount' => 1, 'sourceType' => 'event', 'sourceId' => 'e1'],
      ['id' => 'growth.bad-amount', 'stat' => 'attack', 'amount' => '4', 'sourceType' => 'event', 'sourceId' => 'e1'],
      ['id' => 'growth.no-source', 'stat' => 'attack', 'amount' => 1, 'sourceType' => '', 'sourceId' => 'e1'],
      ['id' => 'growth.no-source-id', 'stat' => 'attack', 'amount' => 1, 'sourceType' => 'event', 'sourceId' => ''],
      ['id' => 'growth.bad-metadata', 'stat' => 'attack', 'amount' => 1, 'sourceType' => 'e', 'sourceId' => 'e', 'metadata' => 'notes'],
    PHP);

    $errors = implode("\n", growthIssues($root, Severity::ERROR));

    expect($errors)->toContain('Permanent modifier id cannot be empty')
        // Accuracy is not a stat the runtime resolves, so it cannot grow.
        ->toContain('growth.bad-stat')
        ->toContain('growth.bad-amount: Permanent modifier amount must be an integer')
        ->toContain('growth.no-source: Permanent modifier source type and source id cannot be empty')
        ->toContain('growth.no-source-id: Permanent modifier source type and source id cannot be empty')
        ->toContain('Definition 5 carries metadata that is not a set of keys and values');
});

it('separates a repeat from a disagreement about one identity', function () {
    if (! PermanentGrowthCatalog::isAvailable()) {
        $this->markTestSkipped('The engine permanent-growth contract is not reachable from this checkout.');
    }

    $root = makeTemporaryProject('ichiloto-growth-');
    writeGrowthDefinitions($root, <<<'PHP'
      ['id' => 'growth.twice', 'stat' => 'attack', 'amount' => 2, 'sourceType' => 'event', 'sourceId' => 'e1'],
      ['id' => 'growth.twice', 'stat' => 'attack', 'amount' => 2, 'sourceType' => 'event', 'sourceId' => 'e1'],
      ['id' => 'growth.disputed', 'stat' => 'attack', 'amount' => 2, 'sourceType' => 'event', 'sourceId' => 'e1'],
      ['id' => 'growth.disputed', 'stat' => 'attack', 'amount' => 9, 'sourceType' => 'event', 'sourceId' => 'e1'],
    PHP);

    // The runtime grants an identical repeat once and treats the second as
    // already done; two definitions that disagree over one id is the fault
    // it refuses outright.
    expect(implode("\n", growthIssues($root, Severity::WARNING)))
        ->toContain('"growth.twice" is defined more than once with identical content')
        ->and(implode("\n", growthIssues($root, Severity::ERROR)))
        ->toContain('growth.disputed: Permanent modifier id "growth.disputed" has conflicting content');
});

it('leaves earned growth to the runtime, and says what the preview is assuming', function () {
    if (! PermanentGrowthCatalog::isAvailable()) {
        $this->markTestSkipped('The engine permanent-growth contract is not reachable from this checkout.');
    }

    $root = makeTemporaryProject('ichiloto-growth-');
    writeGrowthDefinitions($root, <<<'PHP'
      ['id' => 'growth.vigour', 'stat' => 'attack', 'amount' => 7, 'sourceType' => 'landmark', 'sourceId' => 'spring'],
    PHP);

    // At this engine head nothing but runtime API grants permanent growth:
    // there is no project command for it, so the editor authors definitions
    // and stops there rather than inventing a way to grant one.
    expect(EventInterpreter::COMMAND_TYPES)->not->toContain('permanent_growth')
        ->not->toContain('grant_growth');

    $before = sourceHashTree($root);
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    callEditorMethod($editor, 'openDatabaseAtCategory', 'actors');

    $rowFor = static function (Editor $editor, string $label): array {
        foreach (callEditorMethod($editor, 'getDatabaseActorSettingsFields') as $field) {
            if (($field['label'] ?? null) === $label) {
                return $field;
            }
        }

        return [];
    };
    $attack = static fn(Editor $editor): string => strval($rowFor($editor, '  attack')['value'] ?? '');

    expect($rowFor($editor, '  Assumed Growth')['value'])->toBe('(none earned yet)')
        ->and($rowFor($editor, '  Assumed Growth')['options'])->toContain('growth.vigour')
        ->and($attack($editor))->not->toContain('growth');

    callEditorMethod($editor, 'applyDatabaseFieldValue', '__actor_growth', 'growth.vigour');

    expect($attack($editor))->toContain('+7 growth')
        // Assuming it is a view of the pane. Nothing was granted, nothing
        // was written, and no save payload was touched.
        ->and(sourceHashTree($root))->toBe($before);

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    expect($workspace->actorDatabase->isDirty())->toBeFalse();
});

it('writes nothing when definitions are only browsed', function () {
    $root = makeTemporaryProject('ichiloto-growth-');
    writeGrowthDefinitions($root, <<<'PHP'
      ['id' => 'growth.vigour', 'stat' => 'attack', 'amount' => 7, 'sourceType' => 'landmark', 'sourceId' => 'spring'],
    PHP);
    $before = sourceHashTree($root);

    $database = growthDatabase($root);

    foreach ($database->getRecords() as $index => $record) {
        $database->getSettingsFields($index);
    }

    expect($database->isDirty())->toBeFalse();
    $database->save();

    expect(sourceHashTree($root))->toBe($before);
});

it('adds nothing to a project that defines no growth', function () {
    $root = makeTemporaryProject('ichiloto-growth-');

    expect(implode("\n", growthIssues($root)))->not->toContain('permanent-growth.php')
        ->and(PermanentGrowthCatalog::fromProject($root)->ids())->toBe([]);
});
