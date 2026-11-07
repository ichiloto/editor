<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\MenuPresentationSettings;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Battle\CrisisAbilityCatalog;

it('round trips crisis activation and guarded skill variants through the database', function () {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Test technique', 'Strikes.', '', 2, 0));
    $database = loadRecordDatabase($root, 'crisis_abilities');
    $index = $database->addRecord();
    $database->setField($index, 'id', 'synthetic-surge');
    $database->setField($index, 'name', 'Surge');
    $database->setField($index, 'hpBelowPercent', '12.5');
    $database->setField($index, 'states', 'focus');
    $database->setField($index, 'variant0Conditions', 'variable:form:==:first');
    $database->addSubItem($index, ['skill' => 'Test technique',
        'conditions' => [['type' => 'variable', 'name' => 'form', 'value' => 'second']]]);
    $database->save();
    $ability = CrisisAbilityCatalog::fromProject($root . '/assets')->getAbility('synthetic-surge');
    expect($ability->hpBelowPercent)->toBe(12.5)->and($ability->states)->toBe(['focus'])
        ->and($ability->variants[0]['conditions'][0]['value'])->toBe('first')
        ->and($ability->variants[1]['conditions'][0]['value'])->toBe('second');
    $reopened = loadRecordDatabase($root, 'crisis_abilities');
    $variantFields = $reopened->getSettingsFields($index);
    expect(array_find($variantFields, static fn(array $field): bool => ($field['field'] ?? '') === 'variant0Skill')['reference'])
        ->toBe('crisis_skills');
});

it('round trips the optional crisis acquisition icon through the theme role picker', function (): void {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Test technique', 'Strikes.', '', 2, 0));
    $database = loadRecordDatabase($root, 'crisis_abilities');
    $index = $database->addRecord();
    $database->setField($index, 'id', 'synthetic-surge');
    $database->save();

    $theme = $root . '/' . MenuPresentationSettings::RELATIVE_PATH;
    if (!is_dir(dirname($theme))) {
        mkdir(dirname($theme), 0777, true);
    }
    file_put_contents($theme, '<?php return ' . var_export([
        'schema' => 'ichiloto.menu/1',
        'icons' => ['progression.surge' => 'Graphics/Icons/Surge.png'],
    ], true) . ';');

    $session = EditorSession::open($root);
    $category = 'crisis_abilities';
    $row = array_find($session->readDatabaseRecord($category, $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'iconRole');
    expect($row)->toMatchArray(['reference' => ReferenceCatalog::ICON_ROLES, 'noneLabel' => '(actor portrait)'])
        ->and(array_column($session->listReferences(null, ReferenceCatalog::ICON_ROLES), 'value'))
        ->toContain('progression.surge');

    $session->applyDatabaseRecord($category, $index, $row['key'], 'progression.surge');
    $session->saveDatabase($category);
    $load = static fn() => CrisisAbilityCatalog::fromProject($root . '/assets')->getAbility('synthetic-surge');
    expect($load()->iconRole)->toBe('progression.surge');

    $session->applyDatabaseRecord($category, $index, $row['key'], '');
    $session->saveDatabase($category);
    expect($load()->toArray())->not->toHaveKey('iconRole');
});

it('offers a variant only the skills the Engine accepts for a crisis technique', function () {
    $root = makeTemporaryProject();
    writeSkillRecords($root,
        new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Field only', 'Mends.', '', 2, 0,
            occasion: \Ichiloto\Engine\Entities\Enumerations\Occasion::MENU_SCREEN),
        new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Last stand', 'Strikes.', '', 2, 0));
    // A menu-only skill is one the Engine refuses as a crisis technique, so it is never offered.
    expect(new ReferenceCatalog(ProjectWorkspace::fromProject($root))->valuesFor('crisis_skills'))->toBe(['Last stand']);
});

it('binds an actor using a crisis resource picker and preserves the reference through save', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/crisis-abilities.php', '<?php return ' . var_export([
        ['id' => 'synthetic-surge', 'name' => 'Surge', 'hpBelowPercent' => 10,
            'variants' => [['skill' => 'Test technique']]],
    ], true) . ';');
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    callEditorMethod($editor, 'openDatabaseAtCategory', 'actors');
    $row = array_find(callEditorMethod($editor, 'getDatabaseSettingsFields'),
        static fn(array $field): bool => ($field['field'] ?? '') === 'crisisAbility');
    expect($row)->toMatchArray(['reference' => 'crisis_abilities', 'allowsNone' => true])
        ->and($row)->not->toHaveKey('control');
    $workspace = getEditorProperty($editor, 'workspace');
    expect(new ReferenceCatalog($workspace)->valuesFor('crisis_abilities'))->toBe(['synthetic-surge']);
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $row, 'synthetic-surge');
    $workspace->actorDatabase->save();
    expect(ProjectWorkspace::fromProject($root)->actorDatabase->getActorByIndex(0)->getData()['crisisAbility'])
        ->toBe('synthetic-surge');
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $row, '');
    $workspace->actorDatabase->save();
    expect(ProjectWorkspace::fromProject($root)->actorDatabase->getActorByIndex(0)->getData())->not->toHaveKey('crisisAbility');
});

it('starts a new crisis ability on a battle-usable skill, and says what the project lacks without one', function () {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Field only', 'Mends.', '', 2, 0,
        occasion: \Ichiloto\Engine\Entities\Enumerations\Occasion::MENU_SCREEN));
    expect(fn() => loadRecordDatabase($root, 'crisis_abilities')->requireNewRecord())
        ->toThrow(\Ichiloto\Editor\Database\RecordRefusal::class, 'battle-usable basic or special skill');

    writeSkillRecords($root, new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Field only', 'Mends.', '', 2, 0,
        occasion: \Ichiloto\Engine\Entities\Enumerations\Occasion::MENU_SCREEN),
        new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Last stand', 'Strikes.', '', 2, 0));
    $database = loadRecordDatabase($root, 'crisis_abilities');
    $database->addRecord();
    $database->save();
    $abilities = CrisisAbilityCatalog::fromProject($root . '/assets')->getAbilities();
    expect(array_column(array_merge(...array_values(array_map(static fn($ability): array => $ability->variants, $abilities))), 'skill'))
        ->toBe(['Last stand']);
});

it('refuses malformed activation before writing the crisis source', function () {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Test technique', 'Strikes.', '', 2, 0));
    $database = loadRecordDatabase($root, 'crisis_abilities');
    $index = $database->addRecord();
    $database->setField($index, 'hpBelowPercent', '101');
    $database->addSubItem($index, ['skill' => 'Test technique']);
    expect(fn() => $database->save())->toThrow(\RuntimeException::class)
        ->and(is_file($root . '/assets/Data/crisis-abilities.php'))->toBeFalse();
});

it('blocks indistinguishable crisis variants on save and reports them in existing source', function (
    string $first, array $firstConditions, string $message,
) {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Test technique', 'Strikes.', '', 2, 0));
    $database = loadRecordDatabase($root, 'crisis_abilities');
    $index = $database->addRecord();
    $database->setField($index, 'id', 'synthetic-surge');
    $database->setField($index, 'variant0Conditions', $first);
    $secondConditions = [
        ['type' => 'switch', 'name' => 'transformed'],
        ['type' => 'event', 'name' => 'awake'],
    ];
    $database->addSubItem($index, ['skill' => 'Test technique', 'conditions' => $secondConditions]);
    $path = $root . '/assets/Data/crisis-abilities.php';
    expect(fn() => $database->save())->toThrow(RuntimeException::class, $message)
        ->and(is_file($path))->toBeFalse();

    file_put_contents($path, '<?php return ' . var_export([
        ['id' => 'synthetic-surge', 'name' => 'Surge', 'hpBelowPercent' => 10,
            'variants' => [
                ['skill' => 'Test technique', 'conditions' => $firstConditions],
                ['skill' => 'Test technique', 'conditions' => $secondConditions],
            ]],
    ], true) . ';');
    $original = file_get_contents($path);
    $reopened = loadRecordDatabase($root, 'crisis_abilities');
    $reopened->setField(0, 'name', 'Updated Surge');
    expect(fn() => $reopened->save())->toThrow(RuntimeException::class, $message)
        ->and(file_get_contents($path))->toBe($original);

    $issues = new \Ichiloto\Editor\Validation\ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    expect(array_any($issues, static fn($issue): bool => str_contains($issue->message, $message)))->toBeTrue();
})->with([
    'unconditional variant' => ['', [], 'variant 1 requires conditions'],
    'same conditions in a different order' => [
        'event:awake; switch:transformed',
        [['name' => 'awake', 'type' => 'event'], ['type' => 'switch', 'name' => 'transformed']],
        'variants 1 and 2 have identical conditions',
    ],
    'explicit default switch value' => [
        'event:awake; switch:transformed:true',
        [['type' => 'event', 'name' => 'awake'], ['type' => 'switch', 'name' => 'transformed', 'value' => true]],
        'variants 1 and 2 have identical conditions',
    ],
]);

it('stores state identities while labelling choices with their current display names', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/states.php', '<?php return ' . var_export([
        ['id' => 'focus', 'name' => 'Focused Presence'],
    ], true) . ';');
    $references = new ReferenceCatalog(ProjectWorkspace::fromProject($root));
    expect($references->valuesFor('states'))->toBe(['focus'])
        ->and($references->labelsFor('states'))->toBe(['focus' => 'Focused Presence (focus)']);
});

it('reports unresolved crisis skills through current project validation', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/crisis-abilities.php', '<?php return ' . var_export([
        ['id' => 'synthetic-surge', 'name' => 'Surge', 'hpBelowPercent' => 10,
            'variants' => [['skill' => 'Missing technique']]],
    ], true) . ';');
    $workspace = ProjectWorkspace::fromProject($root);
    $issues = new \Ichiloto\Editor\Validation\ProjectValidator()->validate($workspace);
    expect(array_any($issues, static fn($issue): bool => str_contains($issue->message, 'Missing technique')))->toBeTrue();
});
