<?php

declare(strict_types=1);

use Ichiloto\Editor\Progression\TutorialFields;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Progression\TutorialCatalog;

/**
 * Database > Tutorials: the project's `assets/Data/tutorials.php`, authored in
 * its own source with undo and redo byte for byte, judged by the Engine's
 * TutorialCatalog before a byte is written. Every project here is synthetic.
 */

/** A hand-written guide of two steps: one completed by an outcome, one by a world condition. */
function writeSyntheticTutorials(string $root): string
{
    $path = $root . '/assets/' . TutorialCatalog::FILE;
    file_put_contents($path, <<<'PHP'
<?php

// Synthetic guides, written by hand.
return [
    [
        'id' => 'test.equip',
        'category' => 'battle',
        'title' => 'Equip',
        'start' => 'script',
        'steps' => [
            ['target' => ['screen' => 'menu', 'view' => 'main', 'id' => 'equip'], 'action' => 'confirm',
                'until' => ['outcome' => 'menu.opened', 'subject' => 'equip'], 'say' => 'Open the equipment menu.', 'speaker' => 'tutor'],
            ['target' => ['screen' => 'equip', 'view' => 'slots', 'id' => 'weapon'], 'action' => 'confirm',
                'until' => ['type' => 'switch', 'name' => 'test_equipped']],
        ],
        'replay' => 'practice',
    ],
];
PHP);

    return $path;
}

function tutorialRow(EditorSession $session, int $index, string $field): array
{
    return array_find($session->readDatabaseRecord(TutorialFields::CATEGORY, $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field) ?? throw new RuntimeException('Missing row ' . $field);
}

function applyTutorialRow(EditorSession $session, int $index, string $field, string $value): void
{
    $session->applyDatabaseRecord(TutorialFields::CATEGORY, $index, tutorialRow($session, $index, $field)['key'], $value);
}

it('authors tutorials in their own source, with undo and redo byte for byte', function (): void {
    $root = makeTemporaryProject();
    $path = writeSyntheticTutorials($root);
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    expect(tutorialRow($session, 0, 'step0Action')['reference'])->toBe(\Ichiloto\Editor\Database\ReferenceCatalog::INPUT_ACTIONS)
        ->and(tutorialRow($session, 0, 'step1Until'))->toMatchArray(['kind' => 'conditions', 'conditionLimit' => 1, 'value' => 'switch:test_equipped'])
        ->and(tutorialRow($session, 0, 'step0Untilsubject')['value'])->toBe('equip');

    applyTutorialRow($session, 0, 'title', 'Equipment');
    applyTutorialRow($session, 0, 'step0Say', 'Choose Equip.');
    $session->saveDatabase(TutorialFields::CATEGORY);
    $edited = (string) file_get_contents($path);

    expect($edited)->toContain('// Synthetic guides, written by hand.')
        ->and(TutorialCatalog::fromProject($root . '/assets')->findTutorial('test.equip')?->title)->toBe('Equipment')
        ->and(TutorialCatalog::fromProject($root . '/assets')->findTutorial('test.equip')?->steps[0]->say)->toBe('Choose Equip.');

    foreach (range(1, 2) as $step) { $session->undo(); }
    $session->saveDatabase(TutorialFields::CATEGORY);
    expect(file_get_contents($path))->toBe($original);

    foreach (range(1, 2) as $step) { $session->redo(); }
    $session->saveDatabase(TutorialFields::CATEGORY);
    expect(file_get_contents($path))->toBe($edited);
});

it('keeps a step completed by one thing: an outcome, or one world condition', function (): void {
    $root = makeTemporaryProject();
    $path = writeSyntheticTutorials($root);
    $session = EditorSession::open($root);

    // Choosing a condition for the outcome step drops the outcome and its subject.
    applyTutorialRow($session, 0, 'step0Until', 'switch:test_menu_open');
    // Naming an outcome for the condition step drops the condition.
    applyTutorialRow($session, 0, 'step1Untiloutcome', 'equip.committed');
    $session->saveDatabase(TutorialFields::CATEGORY);
    $steps = (require $path)[0]['steps'];

    expect($steps[0]['until'])->toBe(['type' => 'switch', 'name' => 'test_menu_open'])
        ->and($steps[1]['until'])->toBe(['outcome' => 'equip.committed'])
        ->and(fn() => applyTutorialRow($session, 0, 'step0Until', 'switch:a; switch:b'))->toThrow(SessionRefusal::class, 'one condition');
});

it('refuses tutorials the Engine would refuse, before writing the source', function (string $field, string $value, string $reason): void {
    $root = makeTemporaryProject();
    $path = writeSyntheticTutorials($root);
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    applyTutorialRow($session, 0, $field, $value);
    expect(fn() => $session->saveDatabase(TutorialFields::CATEGORY))->toThrow(SessionRefusal::class, $reason)
        ->and(file_get_contents($path))->toBe($original);
})->with([
    'a line in lower case' => ['step0Say', 'open the menu.', 'sentence case'],
    'an automatic start with no trigger' => ['start', 'automatic', 'at least one condition'],
    'a step with no target view' => ['step1Targetview', '', 'target view'],
]);

it('creates a tutorial on a target the Engine declares, previews its step order and offers it to start_tutorial', function (): void {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);

    $index = $session->createDatabaseRecord(TutorialFields::CATEGORY)['index'];
    $session->saveDatabase(TutorialFields::CATEGORY);
    $created = TutorialCatalog::fromProject($root . '/assets')->findTutorial('new-tutorial');
    $target = $created?->steps[0]->target;
    $declared = \Ichiloto\Engine\Progression\TutorialScreenCatalog::getViews()[$target['screen']][$target['view']] ?? null;

    // Its one step points at a fixed target its view declares, completed by an outcome that view reports.
    expect($declared['targets'] ?? [])->toContain($target['id'])
        ->and(array_keys($declared['outcomes'] ?? []))->toContain($created?->steps[0]->until['outcome'])
        ->and($declared['actions'] ?? [])->toContain($created?->steps[0]->action)
        ->and($created?->category)->toBe($target['screen'])
        ->and($session->readDatabaseRecord(TutorialFields::CATEGORY, $index)['panes'][0]['title'])->toBe('Step Order')
        ->and($session->readDatabaseRecord(TutorialFields::CATEGORY, $index)['panes'][0]['lines'][0])
            ->toStartWith(sprintf('1. %s › %s › %s', $target['screen'], $target['view'], $target['id']));

    // start_tutorial offers what a script may start: a tutorial that starts automatically is left out.
    $references = static fn(): array => array_column($session->listReferences(null, \Ichiloto\Editor\Database\ReferenceCatalog::SCRIPT_TUTORIALS), 'value');
    expect($references())->toBe(['new-tutorial']);
    applyTutorialRow($session, $index, 'trigger', 'switch:test_ready');
    applyTutorialRow($session, $index, 'start', 'automatic');
    expect($references())->toBe([]);
});
