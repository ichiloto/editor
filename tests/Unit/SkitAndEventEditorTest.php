<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\RecordSchemaCatalog;

it('round-trips the engine condition grammar', function (): void {
    $encoded = 'quest:breakfast-duty:active; !switch:door_open:false; item:S-Potion:3; variable:gold:>=:100';
    $decoded = ConditionCodec::decodeAll($encoded);

    expect($decoded)->toBe([
        ['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'active'],
        ['type' => 'switch', 'name' => 'door_open', 'value' => false, 'negate' => true],
        ['type' => 'item', 'name' => 'S-Potion', 'quantity' => 3],
        ['type' => 'variable', 'name' => 'gold', 'op' => '>=', 'value' => 100],
    ]);

    expect(ConditionCodec::encodeAll($decoded))->toBe($encoded);
    expect(ConditionCodec::decode('nonsense'))->toBeNull();
    expect(ConditionCodec::decode('quest:'))->toBeNull();
});

it('loads, edits, and saves a skit with its beats', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'skits');

    expect($database->isEditable())->toBeTrue();
    expect($database->getEntryLabels())->toBe(['Breakfast Banter']);

    $fields = $database->getSettingsFields(0);
    $byField = array_column($fields, 'value', 'field');

    expect($byField['id'])->toBe('breakfast-banter');
    expect($byField['where'])->toBe('happyville/town-center');
    expect($byField['conditions'])->toBe('quest:breakfast-duty:active');
    expect($byField['beat0Speaker'])->toBe('Liora');
    expect($byField['beat1Text'])->toBe('Nobody outruns Mom before breakfast.');

    $database->setField(0, 'title', 'Morning Banter');
    $database->setField(0, 'beat0Text', 'An errand? Really?');
    $database->setField(0, 'conditions', 'quest:breakfast-duty:completed; !switch:seen:false');
    $database->save();

    $payload = require $root . '/assets/Data/Skits/breakfast-banter.php';

    expect($payload['title'])->toBe('Morning Banter');
    expect($payload['id'])->toBe('breakfast-banter');
    expect($payload['beats'][0]['text'])->toBe('An errand? Really?');
    expect($payload['beats'][0]['speaker'])->toBe('Liora');
    expect($payload['conditions'])->toBe([
        ['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'completed'],
        ['type' => 'switch', 'name' => 'seen', 'value' => false, 'negate' => true],
    ]);

    removeDirectoryRecursively($root);
});

it('adds and removes skit beats', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'skits');

    expect($database->countSubItems(0))->toBe(2);

    $index = $database->addSubItem(0);
    expect($index)->toBe(2);

    $database->setField(0, 'beat2Speaker', 'Drazek');
    $database->setField(0, 'beat2Text', 'I have seen her with a wooden spoon.');
    $database->save();

    $payload = require $root . '/assets/Data/Skits/breakfast-banter.php';
    expect($payload['beats'][2])->toBe(['speaker' => 'Drazek', 'text' => 'I have seen her with a wooden spoon.']);

    $removed = $database->removeSubItem(0, 2);
    expect($removed['speaker'])->toBe('Drazek');
    expect($database->countSubItems(0))->toBe(2);

    removeDirectoryRecursively($root);
});

it('creates a new skit as its own file', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'skits');

    $index = $database->addRecord();
    expect($index)->toBe(1);

    $database->setField(1, 'title', 'Campfire Talk');
    $database->save();

    expect(is_file($root . '/assets/Data/Skits/new-skit.php'))->toBeTrue();

    $payload = require $root . '/assets/Data/Skits/new-skit.php';
    expect($payload['id'])->toBe('new-skit');
    expect($payload['title'])->toBe('Campfire Talk');
    expect($payload['beats'])->toHaveCount(1);

    removeDirectoryRecursively($root);
});

it('lists event scripts and exposes per-command-type fields', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'common_events');

    expect($database->isEditable())->toBeTrue();
    expect($database->getEntryLabels())->toBe(['dresser-note']);

    $byField = array_column($database->getSettingsFields(0), 'value', 'field');

    // The `type` row is shared; the rest of each row set follows the type.
    expect($byField['command0Type'])->toBe('text');
    expect($byField['command0Text'])->toBe('A note is tucked under the lamp.');
    expect($byField['command1Type'])->toBe('record_event');
    expect($byField['command1Name'])->toBe('read_moms_note');
    expect($byField['command2Type'])->toBe('give_gold');
    expect($byField['command2Amount'])->toBe('50');
    expect($byField['command3Type'])->toBe('branch');
    expect($byField['command3Conditions'])->toBe('item:S-Potion');

    // A text command exposes no `amount` row.
    expect($byField)->not->toHaveKey('command0Amount');
});

it('offers every interpreter command type as a cycleable option', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'common_events');

    $typeRow = null;

    foreach ($database->getSettingsFields(0) as $field) {
        if (($field['field'] ?? '') === 'command0Type') {
            $typeRow = $field;
        }
    }

    expect($typeRow)->not->toBeNull();
    expect($typeRow['options'])->toBe(RecordSchemaCatalog::EVENT_COMMAND_TYPES);

    removeDirectoryRecursively($root);
});

it('saves an event script back as a bare command list, header intact', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'common_events');

    $database->setField(0, 'command0Text', 'The lamp flickers.');
    $database->setField(0, 'command2Amount', '75');
    $database->save();

    $path = $root . '/assets/Events/dresser-note.php';
    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('// A small demo cutscene: reading the note on the dresser.');
    // `__scriptId` is an editor-side label, never written.
    expect($contents)->not->toContain('__scriptId');

    $payload = require $path;

    expect(array_is_list($payload))->toBeTrue();
    expect($payload[0]['text'])->toBe('The lamp flickers.');
    expect($payload[2]['amount'])->toBe(75);
    // The nested branch arm the editor does not edit survives untouched.
    expect($payload[3]['then'][0]['text'])->toBe('A shiny coin!');

    removeDirectoryRecursively($root);
});

it('changes a command type and stores the new type verbatim', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'common_events');

    $database->setField(0, 'command1Type', 'accept_quest');
    $database->setField(0, 'command1Id', 'breakfast-duty');
    $database->save();

    $payload = require $root . '/assets/Events/dresser-note.php';

    expect($payload[1]['type'])->toBe('accept_quest');
    expect($payload[1]['id'])->toBe('breakfast-duty');

    removeDirectoryRecursively($root);
});

it('appends and removes event commands', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'common_events');

    expect($database->countSubItems(0))->toBe(4);

    $index = $database->addSubItem(0);
    expect($index)->toBe(4);

    $database->setField(0, 'command4Type', 'transfer');
    $database->setField(0, 'command4Map', 'happyville/home');
    $database->setField(0, 'command4X', '8');
    $database->setField(0, 'command4Y', '4');
    $database->save();

    $payload = require $root . '/assets/Events/dresser-note.php';

    expect($payload[4]['type'])->toBe('transfer');
    expect($payload[4]['map'])->toBe('happyville/home');
    expect($payload[4]['x'])->toBe(8);
    expect($payload[4]['y'])->toBe(4);

    $database->removeSubItem(0, 4);
    expect($database->countSubItems(0))->toBe(4);

    removeDirectoryRecursively($root);
});
