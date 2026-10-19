<?php

declare(strict_types=1);

use Ichiloto\Editor\Dialogue\DialoguePortraitFields;

/**
 * The terminal editor authors the same dialogue catalogue and line emotions by path and list: images through the
 * shared picker, emotions stepped through with Left/Right. Synthetic fixtures only.
 */

it('steps a skit beat through its speaker\'s emotions and shows the warning row for one it lacks', function () {
    [$root] = createDialogueProject();
    file_put_contents($root . '/assets/Data/Skits/breakfast-banter.php', <<<'PHP'
<?php

return [
  'id' => 'breakfast-banter',
  'title' => 'Breakfast Banter',
  'beats' => [
    ['actor' => 'Kaelion', 'text' => 'Again?'],
    ['speaker' => 'Mom', 'text' => 'Eat first.', 'emotion' => 'Furious'],
  ],
];
PHP);
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'skits');
    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
    $emotion = array_search('beat0Emotion', array_column($fields, 'field'), true);
    expect($fields[$emotion]['options'])->toBe(['Neutral', 'Angry'])->and($fields[$emotion]['value'])->toBe('Neutral')
        ->and(array_column($fields, 'label'))->toContain('Beat 2 Emotion Warning');
    setEditorProperty($editor, 'databaseSelectedSettingIndex', $emotion);
    callEditorMethod($editor, 'adjustDatabaseOptionField', 1);
    expect(getEditorProperty($editor, 'workspace')->getRecordDatabase('skits')->getRecordByIndex(0)?->get('beats')[0]['emotion'])->toBe('Angry');
});

it('adds an emotion to an identity and picks its portrait among the project images', function () {
    [$root, $file] = createDialogueProject();
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, DialoguePortraitFields::CATEGORY);
    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
    setEditorProperty($editor, 'databaseFocus', 'database_settings');
    setEditorProperty($editor, 'databaseSelectedSettingIndex', array_search('emotionList', array_column($fields, 'field'), true));
    callEditorMethod($editor, 'addDatabaseRecordSubItem');
    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
    $portrait = $fields[array_search('emotion0Portrait', array_column($fields, 'field'), true)];
    expect($portrait['reference'])->toBe('png_assets');
    expect(callEditorMethod($editor, 'openReferencePicker', $portrait))->toBeTrue();
    $picker = getEditorProperty($editor, 'referencePicker');
    expect($picker->isOpen())->toBeTrue();

    $database = getEditorProperty($editor, 'workspace')->getRecordDatabase(DialoguePortraitFields::CATEGORY);
    expect(array_column($database->getRecordByIndex(0)?->getEntries($database->schema->findInlineSubList('emotions')), 'name'))->toBe(['New Emotion', 'Angry']);
});
