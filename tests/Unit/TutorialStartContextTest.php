<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\ConditionEditor;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Progression\TutorialFields;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Progression\TutorialCatalog;
use Ichiloto\Engine\Progression\TutorialStartContext;

/**
 * Where an automatic tutorial starts: in the field (the default a record may
 * leave unwritten), on the Summons library's list, or at a battle's command
 * window, and the assigned summons condition a library lesson waits on. Every
 * record is synthetic; what each context owns is judged by the Engine's
 * TutorialCatalog and TutorialProjectReferences on save.
 */

/** Three hand-written tutorials: a Summons library lesson, a battle command lesson and a legacy record with no context. */
function writeStartContextTutorials(string $root): string
{
    $tutorials = [
        [
            'id' => 'test.library', 'category' => 'synthetic', 'title' => 'Contracts', 'start' => 'automatic',
            'startContext' => 'summons-library',
            'trigger' => [['type' => 'assigned_summons', 'name' => 'party', 'quantity' => 2]],
            'steps' => [[
                'target' => ['screen' => 'summons', 'view' => 'list', 'id' => 'assigned-contract'],
                'action' => 'confirm', 'until' => ['outcome' => 'summon.opened'], 'say' => 'Open an assigned contract.',
            ]],
            'replay' => 'practice',
        ],
        [
            'id' => 'test.battle', 'category' => 'synthetic', 'title' => 'Summon In Battle', 'start' => 'automatic',
            'startContext' => 'battle-command',
            'steps' => [[
                'target' => ['screen' => 'battle', 'view' => 'command', 'id' => 'summon'],
                'action' => 'action', 'until' => ['outcome' => 'summon.executed'], 'say' => 'Choose Summon.',
            ]],
            'replay' => 'read',
        ],
        [
            'id' => 'test.legacy', 'category' => 'synthetic', 'title' => 'Library', 'start' => 'script',
            'steps' => [[
                'target' => ['screen' => 'summons', 'view' => 'list', 'id' => 'assigned-contract'],
                'action' => 'confirm', 'until' => ['outcome' => 'summon.opened'],
            ]],
            'replay' => 'none',
        ],
    ];
    $path = $root . '/assets/' . TutorialCatalog::FILE;
    file_put_contents($path, "<?php\n\n// Synthetic lessons, written by hand.\nreturn " . var_export($tutorials, true) . ";\n");

    return $path;
}

function startContextRow(EditorSession $session, int $index, string $field): array
{
    return array_find($session->readDatabaseRecord(TutorialFields::CATEGORY, $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field) ?? throw new RuntimeException('Missing row ' . $field);
}

function applyStartContextRow(EditorSession $session, int $index, string $field, string $value): void
{
    $session->applyDatabaseRecord(TutorialFields::CATEGORY, $index, startContextRow($session, $index, $field)['key'], $value);
}

it('round-trips both lesson shapes and a legacy record with no context, byte for byte through undo', function (): void {
    $root = makeTemporaryProject();
    $path = writeStartContextTutorials($root);
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    expect(startContextRow($session, 0, 'startContext'))->toMatchArray(['value' => 'summons-library'])
        ->and(startContextRow($session, 0, 'startContext')['options'])->toBe(['', 'summons-library', 'battle-command'])
        ->and(startContextRow($session, 0, 'trigger'))->toMatchArray(['kind' => 'conditions', 'value' => 'assigned_summons:party:2'])
        ->and(startContextRow($session, 1, 'startContext'))->toMatchArray(['value' => 'battle-command'])
        ->and(startContextRow($session, 1, 'step0Targetscreen'))->toMatchArray(['value' => 'battle'])
        ->and(startContextRow($session, 1, 'step0Untiloutcome')['options'])->toContain('summon.executed')
        // An unwritten context reads as the field it means.
        ->and(startContextRow($session, 2, 'startContext')['value'])->toBe('field');

    foreach ([0, 1, 2] as $index) {
        applyStartContextRow($session, $index, 'title', 'Edited ' . $index);
    }
    $session->saveDatabase(TutorialFields::CATEGORY);
    $saved = require $path;
    $catalog = TutorialCatalog::fromProject($root . '/assets');

    expect(file_get_contents($path))->toContain('// Synthetic lessons, written by hand.')
        ->and($saved[0]['startContext'])->toBe('summons-library')
        ->and($saved[0]['trigger'])->toBe([['type' => 'assigned_summons', 'name' => 'party', 'quantity' => 2]])
        ->and($saved[1]['startContext'])->toBe('battle-command')
        ->and($saved[1])->not->toHaveKey('trigger')
        // A record that never named a context gains none: the field is the Engine's default.
        ->and($saved[2])->not->toHaveKey('startContext')
        ->and($catalog->findTutorial('test.library')?->startContext)->toBe(TutorialStartContext::SUMMONS_LIBRARY)
        ->and($catalog->findTutorial('test.battle')?->startContext)->toBe(TutorialStartContext::BATTLE_COMMAND)
        ->and($catalog->findTutorial('test.legacy')?->startContext)->toBe(TutorialStartContext::FIELD);

    foreach (range(1, 3) as $step) { $session->undo(); }
    $session->saveDatabase(TutorialFields::CATEGORY);
    expect(file_get_contents($path))->toBe($original);
});

it('authors a library start and its assigned summons trigger, and clears the context back to the field', function (): void {
    $root = makeTemporaryProject();
    $path = writeStartContextTutorials($root);
    $session = EditorSession::open($root);

    applyStartContextRow($session, 2, 'trigger', 'assigned_summons:party:3');
    applyStartContextRow($session, 2, 'start', 'automatic');
    applyStartContextRow($session, 2, 'startContext', 'summons-library');
    $session->saveDatabase(TutorialFields::CATEGORY);
    expect((require $path)[2])->toMatchArray([
        'start' => 'automatic', 'startContext' => 'summons-library',
        'trigger' => [['type' => 'assigned_summons', 'name' => 'party', 'quantity' => 3]],
    ]);

    // Choosing the default writes no context at all.
    applyStartContextRow($session, 2, 'startContext', '');
    $session->saveDatabase(TutorialFields::CATEGORY);
    expect((require $path)[2])->not->toHaveKey('startContext')
        ->and(TutorialCatalog::fromProject($root . '/assets')->findTutorial('test.legacy')?->startContext)->toBe(TutorialStartContext::FIELD);
});

it('refuses a context or a lesson shape the Engine would refuse, before writing the source', function (int $index, string $field, string $value, string $reason): void {
    $root = makeTemporaryProject();
    $path = writeStartContextTutorials($root);
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    applyStartContextRow($session, $index, $field, $value);
    expect(fn() => $session->saveDatabase(TutorialFields::CATEGORY))->toThrow(SessionRefusal::class, $reason)
        ->and(file_get_contents($path))->toBe($original);
})->with([
    'a library start from a script' => [0, 'start', 'script', 'valid automatic start context'],
    'a battle lesson practised in the Guide' => [1, 'replay', 'practice', 'Read, Watch or no replay'],
    'a battle lesson completed by anything but the committed action' => [1, 'step0Until', 'switch:test_chosen', 'committed battle action'],
    'a battle lesson off the command window' => [1, 'step0Targetscreen', 'summons', 'owning screen and view'],
    'a library lesson starting off the list' => [0, 'step0Targetview', 'actions', 'owning screen and view'],
    'a Read replay with no written instruction' => [1, 'step0Say', '', 'written instructions for Read replay'],
]);

it('reads a Guide entry from written instructions alone, with no demonstration', function (): void {
    $root = makeTemporaryProject();
    $path = writeStartContextTutorials($root);
    $session = EditorSession::open($root);

    expect(startContextRow($session, 1, 'demo')['value'])->toBe('(none)');
    applyStartContextRow($session, 0, 'replay', 'read');
    $session->saveDatabase(TutorialFields::CATEGORY);

    expect((require $path)[0])->toMatchArray(['replay' => 'read'])->not->toHaveKey('demo');
});

it('refuses an assigned summons condition without its positive quantity or the party, writing nothing', function (string $line, string $reason): void {
    $root = makeTemporaryProject();
    $path = writeStartContextTutorials($root);
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    expect(fn() => applyStartContextRow($session, 0, 'trigger', $line))->toThrow(SessionRefusal::class, $reason);
    $session->saveDatabase(TutorialFields::CATEGORY);
    expect(file_get_contents($path))->toBe($original);
})->with([
    'no quantity' => ['assigned_summons:party', 'least number of assigned summons'],
    'a zero quantity' => ['assigned_summons:party:0', 'whole number above zero'],
    'a fractional quantity' => ['assigned_summons:party:1.5', 'whole number above zero'],
    'a name other than the party' => ['assigned_summons:crew:2', 'requires one of these names [party]'],
]);

it('keeps a hand-written assigned summons condition it cannot carry read-only, and its source as written', function (): void {
    $root = makeTemporaryProject();
    $path = writeStartContextTutorials($root);
    file_put_contents($path, str_replace("'quantity' => 2,", '', (string) file_get_contents($path)));
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    $row = startContextRow($session, 0, 'trigger');
    expect($row['kind'])->toBe('info')
        ->and($row['value'])->toBe('! condition 1 holds a shape the condition editor cannot carry')
        ->and(fn() => $session->applyDatabaseRecord(TutorialFields::CATEGORY, 0, $row['key'], 'assigned_summons:party:2'))
            ->toThrow(SessionRefusal::class);
    expect(file_get_contents($path))->toBe($original);
});

it('reads and writes assigned_summons:party:N through the one condition line', function (): void {
    $condition = ['type' => 'assigned_summons', 'name' => 'party', 'quantity' => 2];

    expect(ConditionCodec::encode($condition))->toBe('assigned_summons:party:2')
        ->and(ConditionCodec::decodeAllStrictly('!assigned_summons:party:4'))
            ->toBe([['type' => 'assigned_summons', 'name' => 'party', 'quantity' => 4, 'negate' => true]])
        // A quantity of one is required, so it is written, never left to a default.
        ->and(ConditionCodec::encode([...$condition, 'quantity' => 1]))->toBe('assigned_summons:party:1')
        ->and(ConditionCodec::findCarryIssue([$condition]))->toBeNull()
        ->and(ConditionCodec::findCarryIssue([['type' => 'assigned_summons', 'name' => 'party']]))->toContain('cannot carry');
});

it('builds an assigned summons condition a part at a time, as the grammar describes it', function (): void {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);
    $grammar = array_column($session->describeWorldStateGrammar()['conditions'], null, 'type')['assigned_summons'];

    expect($grammar)->toMatchArray(['label' => 'Summons Assigned In', 'nameCategory' => ReferenceCatalog::ASSIGNED_SUMMON_SCOPES])
        ->and($grammar['extras'])->toBe([['key' => 'quantity', 'label' => 'At Least', 'kind' => 'integer', 'default' => 1]])
        ->and(array_column($session->listReferences(null, ReferenceCatalog::ASSIGNED_SUMMON_SCOPES), 'value'))->toBe(['party']);

    // The Terminal's condition editor: choosing the type starts it on the party and one summons.
    $editor = new ConditionEditor();
    $editor->open('trigger', 'Trigger', []);
    $editor->add();
    $types = ConditionCodec::types();
    $editor->cycleType(array_search('assigned_summons', $types, true) - array_search('switch', $types, true));
    expect($editor->encoded())->toBe('assigned_summons:party:1')
        ->and($editor->nameReference())->toBe(['label' => 'Summons Assigned In', 'category' => ReferenceCatalog::ASSIGNED_SUMMON_SCOPES]);

    $editor->cycleExtra(1);
    expect($editor->encoded())->toBe('assigned_summons:party:2')
        ->and($editor->rows())->toBe(['Summons Assigned In party are at least 2']);
    foreach (range(1, 3) as $step) { $editor->cycleExtra(-1); }
    expect($editor->encoded())->toBe('assigned_summons:party:1');
});

it('chooses a start context in the Terminal among those the Engine declares', function (): void {
    $root = makeTemporaryProject();
    $path = writeStartContextTutorials($root);
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, TutorialFields::CATEGORY);
    $database = getEditorProperty($editor, 'workspace')->getRecordDatabase(TutorialFields::CATEGORY);

    $database->setField(2, 'start', 'automatic');
    $database->setField(2, 'trigger', 'assigned_summons:party:2');
    $database->setField(2, 'startContext', 'summons-library');
    $database->save();

    $saved = (string) file_get_contents($path);
    expect((require $path)[2])->toMatchArray(['startContext' => 'summons-library']);

    // A context the Engine does not declare never reaches the source.
    $database->setField(2, 'startContext', 'library');
    expect(fn() => $database->save())->toThrow(RuntimeException::class, 'valid automatic start context')
        ->and(file_get_contents($path))->toBe($saved);
});
