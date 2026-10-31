<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Progression\ProjectWorldAbilities;
use Ichiloto\Editor\Progression\WorldAbilityFields;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;

/**
 * Database > World Powers: the project's `assets/Data/world-abilities.php`,
 * authored in its own source with undo and redo byte for byte, judged by
 * the Engine's WorldAbilityCatalog before a byte is written, and the source
 * every power picker and validation run reads, unsaved edits included.
 * Every project here is a synthetic fixture.
 */

afterEach(function (): void {
    ConfigStore::remove(WorldAbilityCatalog::class);
});

/** Hand-written powers: a sprint granted by a switch, and far sight that needs it, with a first-use prompt. */
function writeAuthoredWorldPowers(string $root): string
{
    $path = $root . '/' . ProjectWorldAbilities::RELATIVE_PATH;
    file_put_contents($path, <<<'PHP'
<?php

// Exploration powers, written by hand.
return [
    [
        'id' => 'sprint',
        'name' => 'Sprint',
        'conditions' => [['type' => 'switch', 'name' => 'sprint_granted']],
    ],
    [
        'id' => 'far-sight',
        'name' => 'Far Sight',
        'description' => 'See hidden passages.',
        'conditions' => [['type' => 'world_ability', 'name' => 'sprint']],
        'firstUse' => ['message' => 'Look closer.', 'hints' => [['action' => 'action', 'label' => 'Inspect']]],
    ],
];
PHP);

    return $path;
}

/** One row of a power, by its field id. */
function worldPowerRow(EditorSession $session, int $index, string $field): array
{
    return array_find($session->readDatabaseRecord(WorldAbilityFields::CATEGORY, $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field) ?? throw new RuntimeException('Missing row ' . $field);
}

function applyWorldPowerRow(EditorSession $session, int $index, string $field, string $value): void
{
    $session->applyDatabaseRecord(WorldAbilityFields::CATEGORY, $index, worldPowerRow($session, $index, $field)['key'], $value);
}

it('authors world powers in their own source, with undo and redo byte for byte', function (): void {
    $root = makeTemporaryProject();
    $path = writeAuthoredWorldPowers($root);
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    expect(worldPowerRow($session, 1, 'conditions')['value'])->toContain('sprint')
        ->and(worldPowerRow($session, 1, 'iconRole')['reference'])->toBe(ReferenceCatalog::ICON_ROLES)
        ->and(worldPowerRow($session, 1, 'firstUse.effect')['reference'])->toBe('effects')
        ->and(worldPowerRow($session, 1, 'firstUseHint0Action')['reference'])->toBe(ReferenceCatalog::INPUT_ACTIONS);

    applyWorldPowerRow($session, 1, 'description', 'See what others miss.');
    applyWorldPowerRow($session, 1, 'firstUse.message', 'Look again.');
    applyWorldPowerRow($session, 0, 'description', 'Move faster.');
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    $edited = (string) file_get_contents($path);

    expect($edited)->toContain('// Exploration powers, written by hand.')
        ->and(require $path)->toBe([
            ['id' => 'sprint', 'name' => 'Sprint', 'conditions' => [['type' => 'switch', 'name' => 'sprint_granted']], 'description' => 'Move faster.'],
            ['id' => 'far-sight', 'name' => 'Far Sight', 'description' => 'See what others miss.',
                'conditions' => [['type' => 'world_ability', 'name' => 'sprint']],
                'firstUse' => ['message' => 'Look again.', 'hints' => [['action' => 'action', 'label' => 'Inspect']]]],
        ])
        ->and(WorldAbilityCatalog::fromProject($root . '/assets')->getAbility('far-sight')->firstUse?->message)->toBe('Look again.');

    foreach (range(1, 3) as $step) { $session->undo(); }
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    expect(file_get_contents($path))->toBe($original);

    foreach (range(1, 3) as $step) { $session->redo(); }
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    expect(file_get_contents($path))->toBe($edited);
});

it('creates the source with the first power, and its id follows its name until something names it', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/' . ProjectWorldAbilities::RELATIVE_PATH;
    $session = EditorSession::open($root);

    $index = $session->createDatabaseRecord(WorldAbilityFields::CATEGORY)['index'];
    applyWorldPowerRow($session, $index, 'name', 'Glide');
    // The picker offers it before it is saved.
    expect(array_column($session->listReferences(null, ReferenceCatalog::WORLD_ABILITIES), 'value'))->toBe(['glide']);
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    expect(require $path)->toBe([['id' => 'glide', 'name' => 'Glide']]);

    // A map event now waits on it: the id stays, and the power cannot be deleted.
    $mapPath = $root . '/assets/Maps/' . ProjectWorkspace::fromProject($root)->mapIds[0];
    $workspace = ProjectWorkspace::fromProject($root);
    $map = $workspace->maps[0];
    $data = require $map->dataPath;
    $data['events']['G'] = ['class' => \Ichiloto\Engine\Events\Triggers\ScriptEventTrigger::class,
        'conditions' => [['type' => 'world_ability', 'name' => 'glide']], 'data' => ['script' => []]];
    file_put_contents($map->dataPath, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    $session = EditorSession::open($root);

    applyWorldPowerRow($session, 0, 'name', 'Soar');
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    expect(require $path)->toBe([['id' => 'glide', 'name' => 'Soar']])
        ->and(fn() => $session->deleteDatabaseRecord(WorldAbilityFields::CATEGORY, 0))->toThrow(SessionRefusal::class, 'map ' . $map->mapId);
});

it('refuses powers the Engine would refuse before writing the source', function (): void {
    $root = makeTemporaryProject();
    $path = writeAuthoredWorldPowers($root);
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    // Sprint waiting on far sight, which waits on sprint, is a cycle.
    applyWorldPowerRow($session, 0, 'conditions', 'world_ability:far-sight');
    expect(fn() => $session->saveDatabase(WorldAbilityFields::CATEGORY))->toThrow(SessionRefusal::class, 'cycle')
        ->and(file_get_contents($path))->toBe($original);
});

it('reads unsaved powers for pickers and validation, as saving would leave them', function (): void {
    $root = makeTemporaryProject();
    writeAuthoredWorldPowers($root);
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord(WorldAbilityFields::CATEGORY)['index'];
    applyWorldPowerRow($session, $index, 'name', 'Glide');
    expect(array_column($session->listReferences(null, ReferenceCatalog::WORLD_ABILITIES), 'value'))->toBe(['sprint', 'far-sight', 'glide']);

    // A cycle saving would refuse: validation reports it, and no power answers a picker until it is fixed.
    applyWorldPowerRow($session, 0, 'conditions', 'world_ability:far-sight');
    $reported = array_values(array_filter($session->validateProject()['issues'],
        static fn(array $issue): bool => $issue['where'] === ProjectWorldAbilities::RELATIVE_PATH));
    expect($session->listReferences(null, ReferenceCatalog::WORLD_ABILITIES))->toBe([])
        ->and($reported)->toHaveCount(1)
        ->and($reported[0]['message'])->toContain('cycle');
});

it('checks a first-use effect plays once on the field, and what unlock conditions name', function (): void {
    $root = makeTemporaryProject();
    file_put_contents($root . '/' . ProjectWorldAbilities::RELATIVE_PATH, "<?php\n\nreturn " . var_export([
        ['id' => 'sprint', 'name' => 'Sprint', 'conditions' => [['type' => 'quest', 'name' => 'no-such-quest']],
            'firstUse' => ['effect' => 'no-such-effect']],
    ], true) . ";\n");
    $messages = static fn(string $where): array => array_values(array_map(static fn($issue): string => $issue->message,
        array_filter(validateProject($root), static fn($issue): bool => str_contains($issue->where, $where))));

    expect(implode("\n", $messages('world power sprint first-use effect')))->toContain('no-such-effect')
        ->and(implode("\n", $messages('world power sprint conditions')))->toContain('no-such-quest');
});


it('picks project semantic actions, including unbound actions and Engine defaults, without installing input', function (): void {
    $root = makeTemporaryProject();
    writeAuthoredWorldPowers($root);
    $input = $root . '/input.php';
    file_put_contents($input, "<?php\nreturn ['inspect' => ['description' => 'Look closely.', 'keys' => []]];\n");
    $before = file_get_contents($input);
    $bindings = \Ichiloto\Engine\IO\InputManager::getBindings();
    $cwd = getcwd();
    $session = EditorSession::open($root);

    $choices = $session->listReferences(null, ReferenceCatalog::INPUT_ACTIONS);
    expect(array_column($choices, 'value'))->toContain('inspect', 'dash')
        ->and(array_find($choices, static fn(array $choice): bool => $choice['value'] === 'inspect')['label'])->toContain('Look closely.')
        ->and(\Ichiloto\Engine\IO\InputManager::getBindings())->toBe($bindings)
        ->and(getcwd())->toBe($cwd)
        ->and(file_get_contents($input))->toBe($before);

    // A changed project's current action vocabulary is used on the next picker request.
    file_put_contents($input, "<?php\nreturn ['salute' => ['description' => 'Greet someone.', 'keys' => []]];\n");
    expect(array_column($session->listReferences(null, ReferenceCatalog::INPUT_ACTIONS), 'value'))->toContain('salute')->not->toContain('inspect');
});

it('edits first-use hints through the shared list and action picker, preserving values and comments through save undo and redo', function (): void {
    $root = makeTemporaryProject();
    $path = writeAuthoredWorldPowers($root);
    file_put_contents($root . '/input.php', "<?php\nreturn ['inspect' => ['keys' => []], 'action' => ['keys' => []]];\n");
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $label = 'Inspect: shelves, doors; “look closer”';
    $originalData = require $path;

    expect(worldPowerRow($session, 1, 'firstUseHintList'))->toMatchArray(['listHeading' => true, 'childNoun' => 'control hint'])
        ->and(worldPowerRow($session, 1, 'firstUseHint0Action'))->toMatchArray(['reference' => ReferenceCatalog::INPUT_ACTIONS]);
    applyWorldPowerRow($session, 1, 'firstUseHint0Action', 'inspect');
    applyWorldPowerRow($session, 1, 'firstUseHint0Label', $label);
    $session->addDatabaseItem(WorldAbilityFields::CATEGORY, 1, worldPowerRow($session, 1, 'firstUseHint0Action')['key']);
    applyWorldPowerRow($session, 1, 'firstUseHint1Action', 'dash');
    applyWorldPowerRow($session, 1, 'firstUseHint1Label', 'Run');
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    $edited = file_get_contents($path);

    expect((require $path)[1]['firstUse'])->toBe(['message' => 'Look closer.', 'hints' => [
        ['action' => 'inspect', 'label' => $label], ['action' => 'dash', 'label' => 'Run'],
    ]])->and($edited)->toContain('// Exploration powers, written by hand.');
    $reopened = EditorSession::open($root);
    expect(worldPowerRow($reopened, 1, 'firstUseHint0Label')['value'])->toBe($label)
        ->and(worldPowerRow($reopened, 1, 'firstUseHint1Action')['value'])->toBe('dash');
    foreach (range(1, 5) as $step) { $session->undo(); }
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    // The existing source writer wraps a changed plain-data list when it grows past its line width.
    // Undo restores its authored data; that local reflow may remain after an intervening save.
    expect(require $path)->toBe($originalData)
        ->and(file_get_contents($path))->toContain('// Exploration powers, written by hand.');
    foreach (range(1, 5) as $step) { $session->redo(); }
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    expect(file_get_contents($path))->toBe($edited);

    $session->removeDatabaseItem(WorldAbilityFields::CATEGORY, 1, worldPowerRow($session, 1, 'firstUseHint1Action')['key']);
    $session->removeDatabaseItem(WorldAbilityFields::CATEGORY, 1, worldPowerRow($session, 1, 'firstUseHint0Action')['key']);
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    expect((require $path)[1]['firstUse'])->toBe(['message' => 'Look closer.']);
});

it('refuses an unknown hint action atomically and preserves an older authored action on unrelated edits', function (): void {
    $root = makeTemporaryProject();
    $path = writeAuthoredWorldPowers($root);
    file_put_contents($root . '/input.php', "<?php\nreturn ['inspect' => ['keys' => []]];\n");
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $before = $session->readDatabaseRecord(WorldAbilityFields::CATEGORY, 1);

    expect(fn() => applyWorldPowerRow($session, 1, 'firstUseHint0Action', 'misspelled'))
        ->toThrow(SessionRefusal::class, 'choose one from the picker')
        ->and($session->readDatabaseRecord(WorldAbilityFields::CATEGORY, 1))->toBe($before)
        ->and(file_get_contents($path))->toBe($original);
    applyWorldPowerRow($session, 1, 'firstUseHint0Label', 'Look');
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    expect((require $path)[1]['firstUse']['hints'])->toBe([['action' => 'action', 'label' => 'Look']]);
});


it('offers the same hint action picker in Terminal and graphical authoring', function (bool $graphical): void {
    $root = makeTemporaryProject();
    writeAuthoredWorldPowers($root);
    file_put_contents($root . '/input.php', "<?php\nreturn ['inspect' => ['keys' => []]];\n");
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $database = $workspace->getRecordDatabase(WorldAbilityFields::CATEGORY);
    $field = array_find($database->getSettingsFields(1), static fn(array $row): bool => ($row['field'] ?? null) === 'firstUseHint0Action');

    expect($field)->toMatchArray(['reference' => ReferenceCatalog::INPUT_ACTIONS])
        ->and($field)->not->toHaveKey('control')
        ->and((new ReferenceCatalog($workspace))->valuesFor(ReferenceCatalog::INPUT_ACTIONS))->toContain('inspect', 'dash');
})->with(['Terminal' => false, 'graphical' => true]);

it('creates a hint-only first-use prompt and drops it when its last hint is removed', function (): void {
    $root = makeTemporaryProject();
    $path = writeAuthoredWorldPowers($root);
    file_put_contents($root . '/input.php', "<?php\nreturn [];\n");
    $session = EditorSession::open($root);
    $session->addDatabaseItem(WorldAbilityFields::CATEGORY, 0, worldPowerRow($session, 0, 'firstUseHintList')['key'], true);
    applyWorldPowerRow($session, 0, 'firstUseHint0Action', 'dash');
    applyWorldPowerRow($session, 0, 'firstUseHint0Label', 'Run');
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    expect((require $path)[0]['firstUse'])->toBe(['hints' => [['action' => 'dash', 'label' => 'Run']]]);
    $session->removeDatabaseItem(WorldAbilityFields::CATEGORY, 0, worldPowerRow($session, 0, 'firstUseHint0Action')['key']);
    $session->saveDatabase(WorldAbilityFields::CATEGORY);
    expect((require $path)[0])->not->toHaveKey('firstUse');
});
