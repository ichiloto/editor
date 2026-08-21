<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\SummonAssignmentDiagnostics;
use Ichiloto\Editor\ProjectActor;

/**
 * Starting summon assignments on the actor record pane: a multi-pick over
 * the project's summons, a verdict per assignment by the validator's own
 * rules, undo, and a save that writes a clean list.
 */
function actorsEditor(string $root): \Ichiloto\Editor\Editor
{
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'actors');

    return $editor;
}

function actorSettingsField(\Ichiloto\Editor\Editor $editor, string $fieldId): array
{
    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $field) {
        if (($field['field'] ?? null) === $fieldId) {
            return $field;
        }
    }

    throw new RuntimeException('No field ' . $fieldId);
}

function actorSettingsLabels(\Ichiloto\Editor\Editor $editor): array
{
    return array_map(static fn(array $field): string => (string) ($field['label'] ?? ''), callEditorMethod($editor, 'getDatabaseSettingsFields'));
}

it('offers the summons as a multi-pick, judges each assignment, undoes, and saves a clean list', function () {
    $root = cutsceneProject();
    $editor = actorsEditor($root);
    $field = actorSettingsField($editor, 'summons');

    expect($field['reference'])->toBe('summons')
        ->and($field['multi'])->toBeTrue()
        ->and($field['value'])->toBe('');

    // Picking toggles a member in; the verdict row appears beneath.
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $field, 'lantern-wisp');
    $actor = getEditorProperty($editor, 'workspace')->actorDatabase->getActors()[0];
    expect($actor->getSummons())->toBe(['lantern-wisp'])
        ->and(actorSettingsLabels($editor))->toContain('  ✓ lantern-wisp');

    // A missing id and a duplicate are named, not accepted silently.
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', actorSettingsField($editor, 'summons'), 'lantern-wisp, no-such, lantern-wisp');
    $labels = implode("\n", actorSettingsLabels($editor));
    expect($actor->getSummons())->toBe(['lantern-wisp', 'no-such'])
        ->and($labels)->toContain('✗ no-such: It references missing summon "no-such".');

    // Undo walks back through both edits.
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($actor->getSummons())->toBe(['lantern-wisp']);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($actor->getSummons())->toBe([]);

    // Saved as a list, and gone entirely when emptied.
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', actorSettingsField($editor, 'summons'), 'lantern-wisp');
    $actor->save();
    $saved = require $actor->path;
    expect($saved['data']['summons'])->toBe(['lantern-wisp']);
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', actorSettingsField($editor, 'summons'), '');
    $actor->save();
    $saved = require $actor->path;
    expect($saved['data'])->not->toHaveKey('summons');
});

it('applies the wielder policy, story locks and exclusive tenancy exactly as the validator does', function () {
    $diagnostics = new SummonAssignmentDiagnostics([
        'bound' => ['id' => 'bound', 'wielders' => ['mode' => 'characters', 'characters' => ['Ada'], 'tenancy' => 'exclusive'], 'availability' => ['conditions' => [['type' => 'event', 'name' => 'rite']]]],
        'open' => ['id' => 'open'],
        'roles' => ['id' => 'roles', 'wielders' => ['mode' => 'roles', 'roles' => ['Mage']]],
    ]);

    $rows = $diagnostics->forActor('Bea', 'Knight', ['open', 'bound', 'roles', 'open']);
    expect(array_map(SummonAssignmentDiagnostics::describe(...), $rows))->toBe([
        '✓ open',
        '✗ bound: It is not eligible to hold summon "bound". It starts with story-locked summon "bound".',
        '✗ roles: It is not eligible to hold summon "roles".',
        '✗ open: Its summon assignments contain duplicate ids.',
    ]);
    expect($diagnostics->forActor('Ada', 'Mage', ['roles', 'bound'])[0]['problems'])->toBe([])
        ->and($diagnostics->isExclusive('bound'))->toBeTrue()
        ->and($diagnostics->exclusiveConflicts(['bound' => ['Ada', 'Bea'], 'open' => ['Ada', 'Bea']]))->toBe(['bound' => ['Ada', 'Bea']])
        ->and($diagnostics->forActor('Ada', 'Mage', 'not-a-list')[0]['problems'][0]['message'])->toBe('Its summon assignments are malformed.');
});
