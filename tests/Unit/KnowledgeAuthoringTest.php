<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Writes a knowledge catalogue with every list the runtime reads, so a test
 * can prove that editing one of them leaves the others exactly as they were.
 *
 * @param string $root The project root.
 * @return string The catalogue path.
 */
function writeKnowledgeCatalog(string $root): string
{
    $path = $root . '/assets/Data/knowledge.php';
    file_put_contents($path, <<<'PHP'
    <?php

    return [
      'recordTypes' => ['creature', 'person'],
      'subjects' => [
        [
          'id' => 'creature.sewer-rat',
          'recordType' => 'creature',
          'displayName' => 'Sewer Rat',
          'quickCard' => 'A field record for the Sewer Rat.',
          'displayOrder' => 10,
        ],
        [
          'id' => 'person.innkeeper',
          'recordType' => 'person',
          'displayName' => 'The Innkeeper',
          'quickCard' => 'Keeps the beds and the gossip.',
          'tags' => ['townsfolk'],
          'displayOrder' => 20,
          'hidden' => true,
        ],
      ],
      'enemyMappings' => [
        'Sewer Rat' => 'creature.sewer-rat',
      ],
      'reports' => [
        [
          'id' => 'report.rat-nesting',
          'subject' => 'creature.sewer-rat',
          'title' => 'Nesting habits',
          'summary' => 'They nest where the water is warmest.',
          'displayOrder' => 10,
        ],
      ],
    ];
    PHP);

    return $path;
}

/**
 * Opens one knowledge category of a project.
 */
function knowledgeDatabase(string $root, string $category): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category));
}

it('reads each list of the catalogue as its own category', function () {
    $root = makeTemporaryProject('ichiloto-knowledge-');
    writeKnowledgeCatalog($root);

    $subjects = knowledgeDatabase($root, 'knowledge_subjects');
    $reports = knowledgeDatabase($root, 'knowledge_reports');

    expect($subjects->getEntryLabels())->toBe(['Sewer Rat', 'The Innkeeper'])
        ->and($reports->getEntryLabels())->toBe(['Nesting habits'])
        ->and($subjects->getRecordByIndex(1)?->get('recordType'))->toBe('person')
        ->and($subjects->getRecordByIndex(1)?->get('hidden'))->toBeTrue()
        ->and($subjects->getRecordByIndex(1)?->get('tags'))->toBe(['townsfolk'])
        ->and($reports->getRecordByIndex(0)?->get('subject'))->toBe('creature.sewer-rat');
});

it('edits one list and writes every other key back exactly', function () {
    $root = makeTemporaryProject('ichiloto-knowledge-');
    $path = writeKnowledgeCatalog($root);
    $before = require $path;

    $subjects = knowledgeDatabase($root, 'knowledge_subjects');
    $subjects->setField(0, 'quickCard', 'A field record for the Sewer Rat, revised.');
    $subjects->save();

    $after = require $path;

    // The edited list changed, and nothing else in the file did.
    expect($after['subjects'][0]['quickCard'])->toBe('A field record for the Sewer Rat, revised.')
        ->and($after['recordTypes'])->toBe($before['recordTypes'])
        ->and($after['enemyMappings'])->toBe($before['enemyMappings'])
        ->and($after['reports'])->toBe($before['reports'])
        ->and($after['subjects'][1])->toBe($before['subjects'][1]);
});

it('authors a noncombat subject with no enemy anywhere near it', function () {
    $root = makeTemporaryProject('ichiloto-knowledge-');
    $path = writeKnowledgeCatalog($root);

    $subjects = knowledgeDatabase($root, 'knowledge_subjects');
    $index = $subjects->addRecord();

    $subjects->setField($index, 'id', 'practice.tea-ceremony');
    $subjects->setField($index, 'recordType', 'person');
    $subjects->setField($index, 'displayName', 'The Tea Ceremony');
    $subjects->setField($index, 'quickCard', 'Poured for a guest, never for oneself.');
    $subjects->setField($index, 'tags', 'custom, hospitality');
    $subjects->setField($index, 'habitats', 'inns');
    $subjects->setField($index, 'displayOrder', '30');
    $subjects->save();

    $after = require $path;
    $authored = $after['subjects'][2];

    expect($authored['id'])->toBe('practice.tea-ceremony')
        ->and($authored['displayName'])->toBe('The Tea Ceremony')
        ->and($authored['tags'])->toBe(['custom', 'hospitality'])
        ->and($authored['habitats'])->toBe(['inns'])
        // Nothing about it required an enemy, and the mappings are untouched.
        ->and($after['enemyMappings'])->toBe(['Sewer Rat' => 'creature.sewer-rat']);
});

it('authors relationships between subjects, picked rather than spelled', function () {
    $root = makeTemporaryProject('ichiloto-knowledge-');
    $path = writeKnowledgeCatalog($root);

    $subjects = knowledgeDatabase($root, 'knowledge_subjects');
    $subjects->addSubItem(0);
    $subjects->setField(0, 'relationship0Type', 'preyed-on-by');
    $subjects->setField(0, 'relationship0Subject', 'person.innkeeper');
    $subjects->save();

    $after = require $path;

    expect($after['subjects'][0]['relationships'])->toBe([
        ['type' => 'preyed-on-by', 'subject' => 'person.innkeeper'],
    ]);

    // The related subject is chosen from the catalogue, not typed.
    $workspace = ProjectWorkspace::fromProject($root);
    $catalog = new ReferenceCatalog($workspace);

    expect($catalog->valuesFor('knowledge_subjects'))->toBe(['creature.sewer-rat', 'person.innkeeper'])
        ->and($catalog->valuesFor('knowledge_reports'))->toBe(['report.rat-nesting'])
        ->and($catalog->valuesFor('knowledge_record_types'))->toBe(['creature', 'person']);
});

it('authors a report against a subject', function () {
    $root = makeTemporaryProject('ichiloto-knowledge-');
    $path = writeKnowledgeCatalog($root);

    $reports = knowledgeDatabase($root, 'knowledge_reports');
    $index = $reports->addRecord();

    $reports->setField($index, 'id', 'report.rat-diet');
    $reports->setField($index, 'subject', 'creature.sewer-rat');
    $reports->setField($index, 'title', 'What they eat');
    $reports->setField($index, 'summary', 'Everything, given time.');
    $reports->setField($index, 'disagreesWith', 'report.rat-nesting');
    $reports->save();

    $after = require $path;

    expect($after['reports'][1]['id'])->toBe('report.rat-diet')
        ->and($after['reports'][1]['disagreesWith'])->toBe(['report.rat-nesting'])
        // The subjects list is not this category's to rewrite.
        ->and($after['subjects'])->toHaveCount(2);
});

it('hydrates an editor-authored catalogue through the engine', function () {
    if (! class_exists(\Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog::class)) {
        $this->markTestSkipped('The engine knowledge catalogue is not reachable from this checkout.');
    }

    $root = makeTemporaryProject('ichiloto-knowledge-');
    writeKnowledgeCatalog($root);

    $subjects = knowledgeDatabase($root, 'knowledge_subjects');
    $index = $subjects->addRecord();
    $subjects->setField($index, 'id', 'creature.marsh-toad');
    $subjects->setField($index, 'recordType', 'creature');
    $subjects->setField($index, 'displayName', 'Marsh Toad');
    $subjects->setField($index, 'quickCard', 'Louder than it is large.');
    $subjects->setField($index, 'displayOrder', '15');
    $subjects->save();

    $catalog = ProjectDirectoryContext::run(
        $root,
        static fn(): mixed => new \Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog(
            (static fn(): mixed => require $root . '/assets/Data/knowledge.php')(),
        ),
    );

    $subject = $catalog->subject('creature.marsh-toad');

    expect($subject)->not->toBeNull()
        ->and($subject->displayName)->toBe('Marsh Toad')
        ->and($subject->recordType)->toBe('creature')
        // The lists the editor did not touch still hydrate.
        ->and($catalog->subject('person.innkeeper')?->hidden)->toBeTrue()
        ->and($catalog->report('report.rat-nesting')?->subjectId)->toBe('creature.sewer-rat')
        // Display order is what the runtime sorts by.
        ->and(array_map(
            static fn(object $each): string => $each->id,
            $catalog->subjects(),
        ))->toBe(['creature.sewer-rat', 'creature.marsh-toad', 'person.innkeeper']);
});

it('writes nothing when a catalogue is only browsed', function () {
    $root = makeTemporaryProject('ichiloto-knowledge-');
    $path = writeKnowledgeCatalog($root);
    $before = sourceHashTree($root);

    foreach (['knowledge_subjects', 'knowledge_reports'] as $category) {
        $database = knowledgeDatabase($root, $category);

        foreach ($database->getRecords() as $index => $record) {
            $database->getSettingsFields($index);
        }

        expect($database->isDirty())->toBeFalse();
        $database->save();
    }

    expect(sourceHashTree($root))->toBe($before);
});
