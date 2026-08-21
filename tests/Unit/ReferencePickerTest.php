<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\ReferencePicker;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\ProjectWorkspace;

it('offers what the project defines for a kind of reference', function () {
    $workspace = ProjectWorkspace::fromProject(fixturePath('sample-project'));
    $catalog = new ReferenceCatalog($workspace);

    // An inventory reference is offered -- and stored -- as the stable
    // definition id, and shown as the name an author recognises. The
    // fixture authors no ids, so the engine's legacy id is what identity
    // it has.
    expect($catalog->valuesFor('items'))->toContain('legacy.s-potion')
        ->and($catalog->labelsFor('items')['legacy.s-potion'] ?? null)->toBe('S-Potion (legacy.s-potion)')
        ->and($catalog->valuesFor('troops'))->toContain('Bat x 2')
        ->and($catalog->valuesFor('maps'))->toBe($workspace->mapIds);
});

it('offers nothing for a kind the project has none of', function () {
    // The fixture defines no enemies at all, which is a project a picker has
    // to cope with rather than open empty on.
    $catalog = new ReferenceCatalog(ProjectWorkspace::fromProject(fixturePath('sample-project')));

    expect($catalog->valuesFor('enemies'))->toBe([])
        ->and(new ReferencePicker()->open('f', 'Enemy', 'enemies', $catalog->valuesFor('enemies')))->toBeFalse();
});

it('offers nothing for a kind of reference it does not know', function () {
    $catalog = new ReferenceCatalog(ProjectWorkspace::fromProject(fixturePath('sample-project')));

    expect($catalog->valuesFor('dragons'))->toBe([])
        ->and(ReferenceCatalog::knows('dragons'))->toBeFalse()
        ->and(ReferenceCatalog::knows('enemies'))->toBeTrue();
});

it("offers the current summon's stable timeline cue ids", function () {
    $workspace = ProjectWorkspace::fromProject(cutsceneProject());
    $summon = $workspace->cutscenes?->find(CutsceneType::SUMMON, 'lantern-wisp');
    $catalog = new ReferenceCatalog($workspace, currentCutscene: $summon);

    expect(ReferenceCatalog::knows('summon_cues'))->toBeTrue()
        ->and($catalog->valuesFor('summon_cues'))->toBe(['flare']);
});

it('opens on what the field is already set to', function () {
    $picker = new ReferencePicker();

    expect($picker->open('member.0.enemy', 'Enemy', 'enemies', ['Bat', 'Rat', 'Wolf'], 'Rat'))->toBeTrue()
        ->and($picker->isOpen())->toBeTrue()
        // Opening on the current value means confirming straight away is a
        // no-op rather than a silent change to whatever sorted first.
        ->and($picker->selected())->toBe('Rat');
});

it('refuses to open on nothing', function () {
    $picker = new ReferencePicker();

    expect($picker->open('member.0.enemy', 'Enemy', 'enemies', []))->toBeFalse()
        ->and($picker->isOpen())->toBeFalse();
});

it('moves through the choices and wraps', function () {
    $picker = new ReferencePicker();
    $picker->open('f', 'Enemy', 'enemies', ['Bat', 'Rat', 'Wolf']);

    $picker->move(1);
    expect($picker->selected())->toBe('Rat');

    $picker->move(-2);
    expect($picker->selected())->toBe('Wolf');
});

it('narrows the list as it is typed into', function () {
    $picker = new ReferencePicker();
    $picker->open('f', 'Enemy', 'enemies', ['Regular Bat', 'Sewer Rat', 'Great Wolf', 'Bat Swarm']);

    $picker->type('b');
    $picker->type('a');

    // A long list is reached by typing a few letters rather than scrolling,
    // and matching is loose about case.
    expect($picker->matches())->toBe(['Regular Bat', 'Bat Swarm'])
        ->and($picker->selected())->toBe('Regular Bat');

    $picker->backspace();
    $picker->backspace();

    expect($picker->matches())->toHaveCount(4);
});

it('selects nothing when the filter matches nothing', function () {
    $picker = new ReferencePicker();
    $picker->open('f', 'Enemy', 'enemies', ['Bat', 'Rat']);
    $picker->setFilter('dragon');

    expect($picker->matches())->toBe([])
        ->and($picker->selected())->toBeNull();
});

it('forgets everything when it closes', function () {
    $picker = new ReferencePicker();
    $picker->open('f', 'Enemy', 'enemies', ['Bat']);
    $picker->close();

    expect($picker->isOpen())->toBeFalse()
        ->and($picker->fieldId())->toBe('')
        ->and($picker->matches())->toBe([]);
});

it('declares a troop member as naming an enemy', function () {
    $schema = RecordSchemaCatalog::all()['troops'];
    $memberFields = [];

    foreach ($schema->subList?->fields ?? [] as $field) {
        $memberFields[$field->key] = $field;
    }

    // The rule this exists for: a reference is chosen, never spelled.
    expect($memberFields['enemy']->reference)->toBe('enemies');
});

it('offers a picker instead of a text cursor for a reference field', function () {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'troops');
    $fields = $database->getSettingsFields(0);

    $memberField = null;

    foreach ($fields as $field) {
        if (str_contains((string) ($field['label'] ?? ''), 'Enemy')) {
            $memberField = $field;
        }
    }

    expect($memberField)->not->toBeNull()
        ->and($memberField['reference'] ?? null)->toBe('enemies')
        // No control means the settings pane cannot start typing into it.
        ->and($memberField)->not->toHaveKey('control');
});

it('chooses and clears animation frame sounds from project SFX', function () {
    $root = makeTemporaryProject();
    mkdir($root . '/assets/Audio/SFX', 0o777, true);
    touch($root . '/assets/Audio/SFX/chime.wav');
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    openDatabaseCategory($editor, 'animations');

    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
    $index = array_search('frameSound', array_column($fields, 'field'), true);
    expect($index)->toBeInt();
    $field = $fields[$index];
    expect($field['reference'] ?? null)->toBe('sfx')
        ->and($field['allowsNone'] ?? false)->toBeTrue()
        ->and($field)->not->toHaveKey('control');

    setEditorProperty($editor, 'databaseSelectedSettingIndex', $index);
    setEditorProperty($editor, 'databaseFocus', 'database_settings');
    callEditorMethod($editor, 'dispatchInput', "\n");
    $picker = getEditorProperty($editor, 'referencePicker');
    expect($picker->matches())->toBe(['(No sound)', 'chime']);

    callEditorMethod($editor, 'dispatchInput', "\033[B");
    callEditorMethod($editor, 'dispatchInput', "\n");
    $animation = getEditorProperty($editor, 'workspace')->animationDatabase->getAnimations()[0];
    expect($animation->getCue(1)?->soundEffect)->toBe('chime');

    callEditorMethod($editor, 'dispatchInput', "\n");
    callEditorMethod($editor, 'dispatchInput', "\033[A");
    callEditorMethod($editor, 'dispatchInput', "\n");
    expect($animation->getCue(1))->toBeNull();
});
