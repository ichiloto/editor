<?php

declare(strict_types=1);

use Ichiloto\Editor\Progression\TutorialFields;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Progression\TutorialCatalog;
use Ichiloto\Engine\Progression\TutorialProjectReferences;
use Ichiloto\Engine\Progression\TutorialScreenCatalog;
use Ichiloto\Engine\Progression\TutorialStep;

/**
 * Database > Tutorials: the project's `assets/Data/tutorials.php`, authored in
 * its own source with undo and redo byte for byte, picked from what the
 * Engine's TutorialScreenCatalog declares and judged by its TutorialCatalog
 * and TutorialProjectReferences before a byte is written. Every project here
 * is synthetic; the screens and views come from the Engine's declarations.
 */

/** A declared view with fixed targets, and one that draws its targets from the project's actors. */
function declaredTutorialViews(): array
{
    $fixed = $actors = null;
    foreach (TutorialScreenCatalog::getViews() as $screen => $views) {
        foreach ($views as $view => $declared) {
            $fixed ??= $declared['targets'] !== [] ? [$screen, $view, $declared] : null;
            $actors ??= ($declared['targetSource'] ?? null) === 'actor' && is_string(reset($declared['outcomes'])) ? [$screen, $view, $declared] : null;
        }
    }

    return [$fixed ?? throw new RuntimeException('No fixed-target view declared.'), $actors ?? throw new RuntimeException('No actor-backed view declared.')];
}

/** A hand-written guide of two steps on declared views: one completed by an outcome, one by a world condition. */
function writeSyntheticTutorials(string $root): string
{
    [[$screen, $view, $declared], [$actorScreen, $actorView, $actorDeclared]] = declaredTutorialViews();
    $outcome = array_key_first($declared['outcomes']);
    $tutorials = [[
        'id' => 'test.equip', 'category' => 'synthetic', 'title' => 'Equip', 'start' => 'script',
        'steps' => [
            ['target' => ['screen' => $screen, 'view' => $view, 'id' => $declared['targets'][0]], 'action' => $declared['actions'][0],
                'until' => ['outcome' => $outcome, 'subject' => $declared['targets'][0]], 'say' => 'Open this menu.'],
            ['target' => ['screen' => $actorScreen, 'view' => $actorView, 'id' => ($actorDeclared['targetPrefix'] ?? '') . 'Kaelion'],
                'action' => $actorDeclared['actions'][0], 'until' => ['type' => 'switch', 'name' => 'test_equipped']],
        ],
        'replay' => 'practice',
    ]];
    $path = $root . '/assets/' . TutorialCatalog::FILE;
    file_put_contents($path, "<?php\n\n// Synthetic guides, written by hand.\nreturn " . var_export($tutorials, true) . ";\n");

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

    expect(tutorialRow($session, 0, 'step1Until'))->toMatchArray(['kind' => 'conditions', 'conditionLimit' => 1, 'value' => 'switch:test_equipped']);

    applyTutorialRow($session, 0, 'title', 'Equipment');
    applyTutorialRow($session, 0, 'step0Say', 'Choose this one.');
    $session->saveDatabase(TutorialFields::CATEGORY);
    $edited = (string) file_get_contents($path);

    expect($edited)->toContain('// Synthetic guides, written by hand.')
        ->and(TutorialCatalog::fromProject($root . '/assets')->findTutorial('test.equip')?->steps[0]->say)->toBe('Choose this one.');

    foreach (range(1, 2) as $step) { $session->undo(); }
    $session->saveDatabase(TutorialFields::CATEGORY);
    expect(file_get_contents($path))->toBe($original);

    foreach (range(1, 2) as $step) { $session->redo(); }
    $session->saveDatabase(TutorialFields::CATEGORY);
    expect(file_get_contents($path))->toBe($edited);
});

it('offers what the Engine declares for a step, the project ids it resolves among them', function (): void {
    $root = makeTemporaryProject();
    writeSyntheticTutorials($root);
    $session = EditorSession::open($root);
    [[$screen, $view, $declared], [$actorScreen, $actorView, $actorDeclared]] = declaredTutorialViews();
    $actors = new TutorialProjectReferences($root . '/assets')->getIds('actor');

    expect(tutorialRow($session, 0, 'step0Targetscreen')['options'])->toEqualCanonicalizing(array_keys(TutorialScreenCatalog::getViews()))
        ->and(tutorialRow($session, 0, 'step0Targetview')['options'])->toEqualCanonicalizing(array_keys(TutorialScreenCatalog::getViews()[$screen]))
        ->and(tutorialRow($session, 0, 'step0Targetid')['options'])->toEqualCanonicalizing($declared['targets'])
        ->and(tutorialRow($session, 0, 'step0Action')['options'])->toEqualCanonicalizing($declared['actions'])
        ->and(tutorialRow($session, 0, 'step0Untiloutcome')['options'])->toContain(...array_keys($declared['outcomes']))
        ->and(tutorialRow($session, 0, 'step1Targetid')['options'])->toEqualCanonicalizing(
            [...$actorDeclared['targets'], ...array_map(static fn(string $id): string => ($actorDeclared['targetPrefix'] ?? '') . $id, $actors)])
        ->and(tutorialRow($session, 0, 'step0Speaker')['options'])->toContain(...$actors)
        ->and(tutorialRow($session, 0, 'step0Say')['label'])->toContain((string) TutorialStep::MAX_INSTRUCTION_CELLS)
        // A target the view does not declare is refused at the picker.
        ->and(fn() => applyTutorialRow($session, 0, 'step0Targetid', 'not-a-declared-target'))->toThrow(SessionRefusal::class);

    // Moving a step to another view keeps only what that view declares.
    applyTutorialRow($session, 0, 'step0Targetscreen', $actorScreen);
    $step = $session->readDatabaseRecord(TutorialFields::CATEGORY, 0)['rows'];
    $value = static fn(string $field): string => array_find($step, static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)['value'];
    $moved = TutorialScreenCatalog::getViews()[$actorScreen][$value('step0Targetview')] ?? null;
    expect($moved)->not->toBeNull()
        ->and($moved['actions'])->toContain($value('step0Action'))
        ->and(array_keys($moved['outcomes']))->toContain($value('step0Untiloutcome'));
});

it('keeps a step completed by one thing: an outcome, or one world condition', function (): void {
    $root = makeTemporaryProject();
    $path = writeSyntheticTutorials($root);
    $session = EditorSession::open($root);
    [, [, , $actorDeclared]] = declaredTutorialViews();
    $outcome = array_key_first($actorDeclared['outcomes']);

    applyTutorialRow($session, 0, 'step0Until', 'switch:test_menu_open');
    applyTutorialRow($session, 0, 'step1Untiloutcome', $outcome);
    $session->saveDatabase(TutorialFields::CATEGORY);
    $steps = (require $path)[0]['steps'];

    expect($steps[0]['until'])->toBe(['type' => 'switch', 'name' => 'test_menu_open'])
        ->and($steps[1]['until'])->toBe(['outcome' => $outcome])
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
    'a line longer than one line' => ['step0Say', 'Open ' . str_repeat('this ', 20) . 'menu.', 'one line'],
    'an automatic start with no trigger' => ['start', 'automatic', 'at least one condition'],
]);

it('creates a tutorial on a target the Engine declares, previews its step order and offers it to start_tutorial', function (): void {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);

    $index = $session->createDatabaseRecord(TutorialFields::CATEGORY)['index'];
    $session->saveDatabase(TutorialFields::CATEGORY);
    $created = TutorialCatalog::fromProject($root . '/assets')->findTutorial('new-tutorial');
    $target = $created?->steps[0]->target;
    $declared = TutorialScreenCatalog::getViews()[$target['screen']][$target['view']] ?? null;

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

it('picks a step\'s screen and speaker in the Terminal from a filtered list of what they may be', function (): void {
    $root = makeTemporaryProject();
    $path = writeSyntheticTutorials($root);
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, TutorialFields::CATEGORY);
    callEditorMethod($editor, 'setSelectedRecordIndex', 0);
    $database = getEditorProperty($editor, 'workspace')->getRecordDatabase(TutorialFields::CATEGORY);
    $field = static fn(string $id): array => array_column($database->getSettingsFields(0), null, 'field')[$id];
    [, [$actorScreen]] = declaredTutorialViews();

    // Chosen among the Engine's screens, never typed: typing narrows the list and Enter stores the pick.
    callEditorMethod($editor, 'openEntryChoicePicker', $field('step0Targetscreen'));
    $picker = getEditorProperty($editor, 'referencePicker');
    expect($picker->isOpen())->toBeTrue()
        ->and($picker->rows())->toEqualCanonicalizing(array_keys(TutorialScreenCatalog::getViews()));
    $picker->setFilter($actorScreen);
    callEditorMethod($editor, 'commitReferenceSelection');
    expect($field('step0Targetscreen')['value'])->toBe($actorScreen);

    // A speaker may be left empty: its none row is offered first, and picking it clears the speaker.
    applyTutorialRowThroughDatabase($database, 'step0Speaker', 'Kaelion');
    callEditorMethod($editor, 'openEntryChoicePicker', $field('step0Speaker'));
    expect($picker->rows()[0])->toBe('(no speaker)');
    $picker->setFilter('(no speaker)');
    callEditorMethod($editor, 'commitReferenceSelection');
    $database->save();
    expect((require $path)[0]['steps'][0])->not->toHaveKey('speaker');
});

/** Sets one tutorial row through the record database the Terminal edits. */
function applyTutorialRowThroughDatabase(\Ichiloto\Editor\Database\ProjectRecordDatabase $database, string $field, string $value): void
{
    $database->setField(0, $field, $value);
}
