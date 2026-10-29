<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Battle\CrisisAbilityCatalog;

it('round trips crisis activation and guarded skill variants through the database', function () {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'crisis_abilities');
    $index = $database->addRecord();
    $database->setField($index, 'id', 'synthetic-surge');
    $database->setField($index, 'name', 'Surge');
    $database->setField($index, 'hpBelowPercent', '12.5');
    $database->setField($index, 'states', 'focus');
    $database->addSubItem($index, ['skill' => 'Test technique',
        'conditions' => [['type' => 'variable', 'name' => 'form', 'value' => 'second']]]);
    $database->save();
    $ability = CrisisAbilityCatalog::fromProject($root . '/assets')->getAbility('synthetic-surge');
    expect($ability->hpBelowPercent)->toBe(12.5)->and($ability->states)->toBe(['focus'])
        ->and($ability->variants[0]['conditions'][0]['value'])->toBe('second');
    $reopened = loadRecordDatabase($root, 'crisis_abilities');
    $variantFields = $reopened->getSettingsFields($index);
    expect(array_find($variantFields, static fn(array $field): bool => ($field['field'] ?? '') === 'variant0Skill')['reference'])
        ->toBe('skills');
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

it('refuses malformed activation before writing the crisis source', function () {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'crisis_abilities');
    $index = $database->addRecord();
    $database->setField($index, 'hpBelowPercent', '101');
    $database->addSubItem($index, ['skill' => 'Test technique']);
    expect(fn() => $database->save())->toThrow(\RuntimeException::class)
        ->and(is_file($root . '/assets/Data/crisis-abilities.php'))->toBeFalse();
});

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
