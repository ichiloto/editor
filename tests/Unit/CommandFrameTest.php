<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Writes a nested script shaped like the game's own dresser-note: a choice
 * whose option holds commands, one of which is a branch with its own arm.
 *
 * @param string $root The project root.
 * @return void
 */
function writeNestedScript(string $root): void
{
    file_put_contents($root . '/assets/Events/nested.php', <<<'PHP'
    <?php

    return [
      ['type' => 'text', 'name' => '', 'text' => 'A note is tucked under the lamp.'],
      ['type' => 'choice', 'prompt' => 'Read the note?', 'options' => [
        ['text' => 'Read it', 'then' => [
          ['type' => 'text', 'name' => 'Note', 'text' => 'Feed Whiskers!'],
          ['type' => 'branch', 'conditions' => [['type' => 'switch', 'name' => 'fed']], 'then' => [
            ['type' => 'give_gold', 'amount' => 50],
          ]],
        ]],
        ['text' => 'Leave it', 'then' => [
          ['type' => 'text', 'name' => '', 'text' => 'You leave it.'],
        ]],
      ]],
    ];
    PHP);
}

/**
 * Loads the scripts category of a project with the nested fixture written.
 *
 * @param string $root The project root.
 * @return ProjectRecordDatabase The database.
 */
function nestedScripts(string $root): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('common_events'));
}

/**
 * Returns the record index of the nested fixture script.
 */
function nestedIndex(ProjectRecordDatabase $database): int
{
    $index = array_search('nested', $database->getEntryLabels(), true);

    if (! is_int($index)) {
        throw new RuntimeException('The nested fixture script did not load.');
    }

    return $index;
}

it('shows options and arms as rows to open, not read-only counts', function () {
    $root = makeTemporaryProject();
    writeNestedScript($root);
    $database = nestedScripts($root);

    $byId = [];

    foreach ($database->getFrameSettingsFields(nestedIndex($database), []) as $field) {
        $byId[(string) ($field['field'] ?? '')] = $field;
    }

    expect($byId['command1Option0Text']['value'] ?? null)->toBe('Read it')
        ->and($byId['command1Option0Text'])->toHaveKey('control')
        ->and($byId['command1Option0Then']['frame'] ?? null)->toBe([1, 'options', 0, 'then'])
        ->and($byId['command1Option0Then']['value'] ?? null)->toBe('2')
        ->and($byId['command1Option1Then']['frame'] ?? null)->toBe([1, 'options', 1, 'then'])
        // The old placeholder is gone: nothing here is a count you cannot open.
        ->and($byId)->not->toHaveKey('command1Options');
});

it('reads a frame as the commands the runtime would execute there', function () {
    $root = makeTemporaryProject();
    writeNestedScript($root);
    $database = nestedScripts($root);
    $index = nestedIndex($database);

    $arm = $database->getFrameCommands($index, [1, 'options', 0, 'then']);

    expect($arm)->toHaveCount(2)
        ->and($arm[0]['text'])->toBe('Feed Whiskers!');

    $byId = [];

    foreach ($database->getFrameSettingsFields($index, [1, 'options', 0, 'then']) as $field) {
        $byId[(string) ($field['field'] ?? '')] = $field;
    }

    // Inside the frame, ids restart at command0: the same rows at any depth.
    expect($byId['command0Text']['value'] ?? null)->toBe('Feed Whiskers!')
        ->and($byId['command1Then']['frame'] ?? null)->toBe([1, 'options', 0, 'then', 1, 'then']);
});

it('edits a command deep inside an arm and saves it', function () {
    $root = makeTemporaryProject();
    writeNestedScript($root);
    $database = nestedScripts($root);
    $index = nestedIndex($database);

    // The gold reward inside the branch inside the option.
    $database->setFrameField($index, [1, 'options', 0, 'then', 1, 'then'], 'command0Amount', '75');
    $database->save();

    $reloaded = nestedScripts($root);
    $arm = $reloaded->getFrameCommands(nestedIndex($reloaded), [1, 'options', 0, 'then', 1, 'then']);

    expect($arm[0]['amount'])->toBe(75);
});

it('renames an option without touching its arm', function () {
    $root = makeTemporaryProject();
    writeNestedScript($root);
    $database = nestedScripts($root);
    $index = nestedIndex($database);

    $database->setFrameField($index, [], 'command1Option0Text', 'Read it aloud');

    $commands = $database->getFrameCommands($index, []);

    expect($commands[1]['options'][0]['text'])->toBe('Read it aloud')
        ->and($commands[1]['options'][0]['then'])->toHaveCount(2);
});

it('adds and removes commands inside a frame, and puts them back', function () {
    $root = makeTemporaryProject();
    writeNestedScript($root);
    $database = nestedScripts($root);
    $index = nestedIndex($database);
    $frame = [1, 'options', 1, 'then'];

    $added = $database->addFrameCommand($index, $frame, 0);

    expect($added)->toBe(1)
        ->and($database->getFrameCommands($index, $frame))->toHaveCount(2);

    $removed = $database->removeFrameCommand($index, $frame, 1);

    expect($removed['type'] ?? null)->toBe('text')
        ->and($database->getFrameCommands($index, $frame))->toHaveCount(1);

    $database->insertFrameCommand($index, $frame, 1, $removed);

    expect($database->getFrameCommands($index, $frame))->toHaveCount(2);
});

it('adds and removes a choice option with its whole arm', function () {
    $root = makeTemporaryProject();
    writeNestedScript($root);
    $database = nestedScripts($root);
    $index = nestedIndex($database);

    $optionIndex = $database->addChoiceOption($index, [], 1);

    expect($optionIndex)->toBe(2);

    $removed = $database->removeChoiceOption($index, [], 1, 0);

    // The arm travels with the option, into the undo payload.
    expect($removed['text'])->toBe('Read it')
        ->and($removed['then'])->toHaveCount(2);

    $database->insertChoiceOption($index, [], 1, 0, $removed);

    $commands = $database->getFrameCommands($index, []);

    expect($commands[1]['options'][0]['text'])->toBe('Read it')
        ->and($commands[1]['options'])->toHaveCount(3);
});

it('survives a save and reload with every nesting level intact', function () {
    $root = makeTemporaryProject();
    writeNestedScript($root);
    $database = nestedScripts($root);
    $index = nestedIndex($database);

    $database->setFrameField($index, [], 'command1Option0Text', 'Go on');
    $database->addFrameCommand($index, [1, 'options', 0, 'then'], null);
    $database->save();

    $reloaded = nestedScripts($root);
    $commands = $reloaded->getFrameCommands(nestedIndex($reloaded), []);

    expect($commands[1]['options'][0]['text'])->toBe('Go on')
        ->and($commands[1]['options'][0]['then'])->toHaveCount(3)
        // The untouched branch below kept its conditions.
        ->and($commands[1]['options'][0]['then'][1]['conditions'][0]['name'])->toBe('fed');
});

it('describes a frame as the trail back out of it', function () {
    expect(ProjectRecordDatabase::describeFrame([]))->toBe('Commands')
        ->and(ProjectRecordDatabase::describeFrame([1, 'options', 0, 'then']))->toBe('Commands › Choice 2 › Option 1')
        ->and(ProjectRecordDatabase::describeFrame([1, 'options', 0, 'then', 1, 'then']))
            ->toBe('Commands › Choice 2 › Option 1 › Branch 2 › Then')
        ->and(ProjectRecordDatabase::describeFrame([4, 'else']))->toBe('Commands › Branch 5 › Else');
});

it('walks in and out of frames in the editor, one Esc per level', function () {
    $root = makeTemporaryProject();
    writeNestedScript($root);

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    callEditorMethod($editor, 'openDatabaseAtCategory', 'common_events');

    $database = callEditorMethod($editor, 'getSelectedRecordDatabase');
    callEditorMethod($editor, 'setSelectedRecordIndex', nestedIndex($database));
    setEditorProperty($editor, 'databaseFocus', 'database_settings');

    // Enter on the option's Commands row descends into its arm.
    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $index => $field) {
        if (($field['field'] ?? null) === 'command1Option0Then') {
            setEditorProperty($editor, 'databaseSelectedSettingIndex', $index);
        }
    }

    callEditorMethod($editor, 'dispatchInput', "\n");

    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe([1, 'options', 0, 'then']);

    // Esc pops the frame, not the Database screen.
    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe([])
        ->and(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue();

    // The next Esc closes the screen, exactly as before frames existed.
    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(getEditorProperty($editor, 'isDatabaseOpen'))->toBeFalse();
});

it('adds a command inside the open frame, below the cursor', function () {
    $root = makeTemporaryProject();
    writeNestedScript($root);

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    callEditorMethod($editor, 'openDatabaseAtCategory', 'common_events');

    $database = callEditorMethod($editor, 'getSelectedRecordDatabase');
    $recordIndex = nestedIndex($database);
    callEditorMethod($editor, 'setSelectedRecordIndex', $recordIndex);
    setEditorProperty($editor, 'databaseFocus', 'database_settings');
    setEditorProperty($editor, 'databaseCommandFramePath', [1, 'options', 0, 'then']);
    setEditorProperty($editor, 'databaseSelectedSettingIndex', 0);

    callEditorMethod($editor, 'addDatabaseRecordSubItem');

    $arm = $database->getFrameCommands($recordIndex, [1, 'options', 0, 'then']);

    // Cursor on command 0 -> the new command lands at 1, in this arm only.
    expect($arm)->toHaveCount(3)
        ->and($arm[1]['type'])->toBe('text')
        ->and($database->getFrameCommands($recordIndex, [1, 'options', 1, 'then']))->toHaveCount(1);

    callEditorMethod($editor, 'performUndo');

    expect($database->getFrameCommands($recordIndex, [1, 'options', 0, 'then']))->toHaveCount(2);
});

it('removes an option from the root view and restores it on undo', function () {
    $root = makeTemporaryProject();
    writeNestedScript($root);

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    callEditorMethod($editor, 'openDatabaseAtCategory', 'common_events');

    $database = callEditorMethod($editor, 'getSelectedRecordDatabase');
    $recordIndex = nestedIndex($database);
    callEditorMethod($editor, 'setSelectedRecordIndex', $recordIndex);
    setEditorProperty($editor, 'databaseFocus', 'database_settings');

    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $index => $field) {
        if (($field['field'] ?? null) === 'command1Option0Text') {
            setEditorProperty($editor, 'databaseSelectedSettingIndex', $index);
        }
    }

    callEditorMethod($editor, 'removeDatabaseRecordSubItem');

    $commands = $database->getFrameCommands($recordIndex, []);

    expect($commands[1]['options'])->toHaveCount(1)
        ->and($commands[1]['options'][0]['text'])->toBe('Leave it');

    callEditorMethod($editor, 'performUndo');

    $commands = $database->getFrameCommands($recordIndex, []);

    expect($commands[1]['options'])->toHaveCount(2)
        ->and($commands[1]['options'][0]['text'])->toBe('Read it')
        ->and($commands[1]['options'][0]['then'])->toHaveCount(2);
});

it('keeps an empty arm open so the first command can be added there', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Events/bare.php', <<<'PHP2'
    <?php

    return [
      ['type' => 'choice', 'prompt' => 'Well?', 'options' => [
        ['text' => 'Nothing to say', 'then' => []],
      ]],
    ];
    PHP2);

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    callEditorMethod($editor, 'openDatabaseAtCategory', 'common_events');

    $database = callEditorMethod($editor, 'getSelectedRecordDatabase');
    $index = array_search('bare', $database->getEntryLabels(), true);
    callEditorMethod($editor, 'setSelectedRecordIndex', $index);
    setEditorProperty($editor, 'databaseFocus', 'database_settings');
    setEditorProperty($editor, 'databaseCommandFramePath', [0, 'options', 0, 'then']);
    setEditorProperty($editor, 'databaseSelectedSettingIndex', 0);

    // The empty arm is a place to stand, not a reason to fall back out.
    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe([0, 'options', 0, 'then'])
        ->and($fields[0]['field'] ?? null)->toBe(ProjectRecordDatabase::EMPTY_FRAME_FIELD);

    callEditorMethod($editor, 'addDatabaseRecordSubItem');

    expect($database->getFrameCommands($index, [0, 'options', 0, 'then']))->toHaveCount(1);

    // A frame whose command is gone is still left, as before.
    setEditorProperty($editor, 'databaseCommandFramePath', [7, 'then']);
    callEditorMethod($editor, 'getDatabaseSettingsFields');
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe([]);
});
