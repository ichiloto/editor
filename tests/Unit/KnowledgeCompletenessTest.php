<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\KnowledgeCommandShape;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;

/**
 * The rest of the knowledge catalogue, and the command that acts on it.
 *
 * A catalogue is more than its subjects and reports: it declares the kinds
 * of record a subject can be, and which enemy is a record of which subject.
 * Neither could be authored, so a project could not introduce a record type
 * or map an enemy without leaving the editor.
 *
 * The command had the opposite problem: it offered every field for every
 * operation, so an author could fill in a report for a `discover` that will
 * never read one.
 */

/**
 * Writes a catalogue with every structure the runtime reads.
 *
 * @return string The project root.
 */
function completeKnowledgeProject(): string
{
    $root = makeTemporaryProject('ichiloto-knowledge-full-');

    file_put_contents($root . '/assets/Data/knowledge.php', <<<'PHP'
    <?php

    return [
      'recordTypes' => ['creature', 'person'],
      'subjects' => [
        [
          'id' => 'creature.sewer-rat',
          'recordType' => 'creature',
          'displayName' => 'Sewer Rat',
          'quickCard' => 'A rat.',
          'observations' => ['nests-in-warmth', 'fears-fire'],
        ],
        [
          'id' => 'person.innkeeper',
          'recordType' => 'person',
          'displayName' => 'The Innkeeper',
          'quickCard' => 'Keeps beds.',
        ],
      ],
      'enemyMappings' => ['Sewer Rat' => 'creature.sewer-rat'],
      'reports' => [
        ['id' => 'report.rat-nesting', 'subject' => 'creature.sewer-rat', 'title' => 'Nesting', 'summary' => 'Warm water.'],
        ['id' => 'report.rat-diet', 'subject' => 'creature.sewer-rat', 'title' => 'Diet', 'summary' => 'Everything.'],
        ['id' => 'report.inn-prices', 'subject' => 'person.innkeeper', 'title' => 'Prices', 'summary' => 'Steep.'],
      ],
    ];
    PHP);

    return $root;
}

/**
 * Opens one knowledge category.
 */
function completeKnowledgeDatabase(string $root, string $category): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category));
}

/**
 * Reads the catalogue back.
 *
 * @return array<string, mixed>
 */
function completeKnowledgeFile(string $root): array
{
    return (static fn(): mixed => require $root . '/assets/Data/knowledge.php')();
}

/**
 * Runs a command through validation and returns what it said.
 *
 * @param array<string, mixed> $command The knowledge command.
 * @return string[] The messages.
 */
function knowledgeCommandIssues(string $root, array $command, ?Severity $severity = Severity::ERROR): array
{
    if (! is_dir($root . '/assets/Events')) {
        mkdir($root . '/assets/Events', 0o777, true);
    }

    file_put_contents(
        $root . '/assets/Events/knowledge-probe.php',
        "<?php\n\nreturn " . var_export([$command], true) . ";\n",
    );

    $issues = new \Ichiloto\Editor\Validation\ProjectValidator()
        ->validate(ProjectWorkspace::fromProject($root));

    return array_values(array_map(
        static fn($issue): string => $issue->message,
        array_filter($issues, static fn($issue): bool => $severity === null || $issue->severity === $severity),
    ));
}

// -- The operation shape ---------------------------------------------------

it('covers the engine\'s operations exactly, and invents none', function () {
    // The vocabulary is the engine's; this only says what each of its
    // operations reads. If the two ever drift, that is this test failing
    // rather than an operation quietly becoming unauthorable.
    expect(array_keys(KnowledgeCommandShape::FIELDS))->toBe(KnowledgeProgressService::OPERATIONS)
        ->and(KnowledgeCommandShape::operations())->toBe(KnowledgeProgressService::OPERATIONS);

    foreach (KnowledgeProgressService::OPERATIONS as $operation) {
        expect(KnowledgeCommandShape::fieldNamesFor($operation))
            ->not->toBe([], sprintf('Operation %s has no fields.', $operation));
    }
});

it('asks each operation for exactly the fields the runtime reads', function () {
    // Read straight off KnowledgeProgressService::apply().
    expect(KnowledgeCommandShape::FIELDS)->toBe([
        'discover' => ['subject', 'source'],
        'observe' => ['subject', 'observation', 'source', 'confidence'],
        'unlock_report' => ['subject', 'report', 'source', 'confidence'],
        'amend_report' => ['subject', 'report', 'source', 'confidence'],
        'record_outcome' => ['subject', 'outcome'],
        'withdraw_report' => ['subject', 'report', 'source'],
        'supersede_report' => ['subject', 'report', 'replacement', 'source'],
    ]);
});

it('shows an author only the fields their operation uses', function () {
    $root = completeKnowledgeProject();

    if (! is_dir($root . '/assets/Events')) {
        mkdir($root . '/assets/Events', 0o777, true);
    }

    file_put_contents($root . '/assets/Events/knowledge-probe.php', <<<'PHP'
    <?php

    return [
      ['type' => 'knowledge', 'operation' => 'discover', 'subject' => 'creature.sewer-rat'],
      ['type' => 'knowledge', 'operation' => 'supersede_report', 'subject' => 'creature.sewer-rat'],
    ];
    PHP);

    $events = completeKnowledgeDatabase($root, 'common_events');
    $index = array_search('knowledge-probe', $events->getEntryLabels(), true);
    $fields = array_column($events->getSettingsFields(intval($index)), 'field');
    $namesFor = static fn(int $command): array => array_values(array_filter(
        array_map(
            static fn(string $field): string => (string) preg_replace('/^command' . $command . '/', '', $field),
            array_filter($fields, static fn(string $field): bool => str_starts_with($field, 'command' . $command)),
        ),
        static fn(string $name): bool => $name !== '',
    ));

    // Discovering reads a subject and a source. It never reads a report, so
    // it never offers one.
    expect($namesFor(0))->toContain('Subject')
        ->toContain('Source')
        ->not->toContain('Report')
        ->not->toContain('Confidence')
        ->and($namesFor(1))->toContain('Report')
        ->toContain('Replacement')
        ->not->toContain('Confidence');
});

// -- Record types ----------------------------------------------------------

it('authors the kinds of record a subject can be', function () {
    $root = completeKnowledgeProject();
    $types = completeKnowledgeDatabase($root, 'knowledge_record_types');

    expect($types->getEntryLabels())->toBe(['creature', 'person']);

    $index = $types->addRecord();
    $types->setField($index, 'type', 'practice');
    $types->save();

    $after = completeKnowledgeFile($root);

    expect($after['recordTypes'])->toBe(['creature', 'person', 'practice'])
        // Everything else in the catalogue is untouched.
        ->and($after['subjects'])->toHaveCount(2)
        ->and($after['enemyMappings'])->toBe(['Sewer Rat' => 'creature.sewer-rat']);

    // And a subject can now be that kind, because the picker offers it.
    expect(new ReferenceCatalog(ProjectWorkspace::fromProject($root))->valuesFor('knowledge_record_types'))
        ->toBe(['creature', 'person', 'practice']);
});

// -- Enemy mappings --------------------------------------------------------

it('maps an enemy to a subject with both sides picked', function () {
    $root = completeKnowledgeProject();
    $mappings = completeKnowledgeDatabase($root, 'knowledge_enemy_mappings');

    expect($mappings->getEntryLabels())->toBe(['Sewer Rat · creature.sewer-rat']);

    $references = [];

    foreach ($mappings->getSettingsFields(0) as $field) {
        if (isset($field['reference'])) {
            $references[$field['field']] = $field['reference'];
        }
    }

    // Neither side is typed: an enemy the project does not have and a
    // subject it does not declare are both mappings that never fire.
    expect($references)->toBe(['enemy' => 'enemies', 'subject' => 'knowledge_subjects']);

    $index = $mappings->addRecord();
    $mappings->setField($index, 'enemy', 'Regular Bat');
    $mappings->setField($index, 'subject', 'person.innkeeper');
    $mappings->save();

    expect(completeKnowledgeFile($root)['enemyMappings'])->toBe([
        'Sewer Rat' => 'creature.sewer-rat',
        'Regular Bat' => 'person.innkeeper',
    ]);
});

it('keeps a half-named mapping out of a catalogue that could not load', function () {
    $root = completeKnowledgeProject();
    $mappings = completeKnowledgeDatabase($root, 'knowledge_enemy_mappings');
    $index = $mappings->addRecord();
    $mappings->setField($index, 'enemy', 'Regular Bat');
    $mappings->setField($index, 'subject', '');
    $mappings->save();

    // The runtime requires a stable subject id and refuses the whole
    // catalogue without one, so the unfinished mapping is not written.
    expect(completeKnowledgeFile($root)['enemyMappings'])->toBe(['Sewer Rat' => 'creature.sewer-rat']);
});

// -- Disagreements ---------------------------------------------------------

it('picks a disagreement rather than spelling it', function () {
    $root = completeKnowledgeProject();
    $reports = completeKnowledgeDatabase($root, 'knowledge_reports');

    $reports->addSubItem(0);
    $reports->setField(0, 'disagreement0Report', 'report.rat-diet');
    $reports->save();

    $after = completeKnowledgeFile($root);

    // The file keeps the flat list of ids the runtime reads.
    expect($after['reports'][0]['disagreesWith'])->toBe(['report.rat-diet']);

    $reloaded = completeKnowledgeDatabase($root, 'knowledge_reports');
    $reference = null;

    foreach ($reloaded->getSettingsFields(0) as $field) {
        if (($field['field'] ?? null) === 'disagreement0Report') {
            $reference = $field['reference'] ?? null;
        }
    }

    expect($reference)->toBe('knowledge_reports');

    // Removing the last disagreement removes the key rather than leaving an
    // empty list behind.
    $reloaded->removeSubItem(0, 0);
    $reloaded->save();

    expect(completeKnowledgeFile($root)['reports'][0])->not->toHaveKey('disagreesWith');
});

// -- Command validation ----------------------------------------------------

it('reports a command the runtime would raise on', function () {
    $root = completeKnowledgeProject();

    expect(knowledgeCommandIssues($root, ['type' => 'knowledge', 'operation' => 'teleport']))
        ->toContain('Its knowledge operation "teleport" is not one the runtime performs.');

    expect(knowledgeCommandIssues($root, ['type' => 'knowledge', 'operation' => 'observe', 'subject' => 'creature.sewer-rat']))
        ->toContain('Its knowledge observe command names no observation.');

    expect(knowledgeCommandIssues($root, [
        'type' => 'knowledge',
        'operation' => 'observe',
        'subject' => 'creature.sewer-rat',
        'observation' => 'nests-in-warmth',
        'confidence' => 1.4,
    ]))->toContain('Its knowledge confidence "1.4" is not between 0 and 1.');

    expect(knowledgeCommandIssues($root, [
        'type' => 'knowledge',
        'operation' => 'observe',
        'subject' => 'creature.sewer-rat',
        'observation' => 'swims-in-lava',
    ]))->toContain('The observation "swims-in-lava" is not authored for "creature.sewer-rat".');

    expect(knowledgeCommandIssues($root, [
        'type' => 'knowledge',
        'operation' => 'unlock_report',
        'subject' => 'creature.sewer-rat',
        'report' => 'report.inn-prices',
    ]))->toContain('The report "report.inn-prices" belongs to "person.innkeeper", not to "creature.sewer-rat".');

    expect(knowledgeCommandIssues($root, [
        'type' => 'knowledge',
        'operation' => 'supersede_report',
        'subject' => 'creature.sewer-rat',
        'report' => 'report.rat-diet',
        'replacement' => 'report.rat-diet',
    ]))->toContain('Its knowledge command supersedes a report with itself.');
});

it('says nothing about a command the runtime would perform', function () {
    $root = completeKnowledgeProject();

    foreach ([
        ['operation' => 'discover', 'subject' => 'creature.sewer-rat'],
        ['operation' => 'observe', 'subject' => 'creature.sewer-rat', 'observation' => 'fears-fire', 'confidence' => 0.5],
        ['operation' => 'unlock_report', 'subject' => 'creature.sewer-rat', 'report' => 'report.rat-diet'],
        ['operation' => 'record_outcome', 'subject' => 'creature.sewer-rat', 'outcome' => 'spared'],
        ['operation' => 'withdraw_report', 'subject' => 'creature.sewer-rat', 'report' => 'report.rat-nesting'],
        ['operation' => 'supersede_report', 'subject' => 'creature.sewer-rat', 'report' => 'report.rat-nesting', 'replacement' => 'report.rat-diet'],
    ] as $command) {
        $issues = knowledgeCommandIssues($root, ['type' => 'knowledge', ...$command]);

        expect(implode("\n", $issues))->not->toContain('knowledge')
            ->and(implode("\n", $issues))->not->toContain('observation');
    }
});

it('reports an id the runtime would refuse the whole catalogue over', function () {
    $root = completeKnowledgeProject();
    file_put_contents($root . '/assets/Data/knowledge.php', <<<'PHP'
    <?php

    return [
      'recordTypes' => ['creature', 'creature', ''],
      'subjects' => [
        ['id' => 'Creature.Sewer Rat', 'recordType' => 'creature', 'displayName' => 'Rat', 'quickCard' => 'A rat.'],
      ],
      'enemyMappings' => ['' => 'creature.rat'],
    ];
    PHP);

    $issues = new \Ichiloto\Editor\Validation\ProjectValidator()
        ->validate(ProjectWorkspace::fromProject($root));
    $messages = implode("\n", array_map(static fn($issue): string => $issue->message, $issues));

    expect($messages)->toContain('The subject id "Creature.Sewer Rat" is not a stable id')
        ->toContain('Record type 2 is empty.')
        ->toContain('Record type "creature" is declared more than once.')
        ->toContain('An enemy mapping names no enemy.');
});

it('coordinates the new surfaces with the rest of the catalogue', function () {
    $root = completeKnowledgeProject();
    $types = completeKnowledgeDatabase($root, 'knowledge_record_types');
    $mappings = completeKnowledgeDatabase($root, 'knowledge_enemy_mappings');
    $subjects = completeKnowledgeDatabase($root, 'knowledge_subjects');

    $types->setField(0, 'type', 'beast');
    $mappings->setField(0, 'enemy', 'Sewer Rat Alpha');
    $subjects->setField(0, 'displayName', 'Sewer Rat, revised');

    // Saved in an order that would have lost the earlier edits before the
    // shared-file correction.
    $subjects->save();
    $mappings->save();
    $types->save();

    $after = completeKnowledgeFile($root);

    expect($after['recordTypes'])->toBe(['beast', 'person'])
        ->and($after['enemyMappings'])->toBe(['Sewer Rat Alpha' => 'creature.sewer-rat'])
        ->and($after['subjects'][0]['displayName'])->toBe('Sewer Rat, revised')
        ->and($after['reports'])->toHaveCount(3);
});
