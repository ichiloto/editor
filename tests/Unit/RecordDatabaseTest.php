<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Database\PhpValueExporter;
use Ichiloto\Engine\IO\Enumerations\Color;

it('exports scalars, arrays, and enums but refuses objects', function (): void {
    expect(PhpValueExporter::export(['a' => 1, 'b' => [true, null]]))
        ->toBe("[\n  'a' => 1,\n  'b' => [\n    true,\n    NULL,\n  ],\n]");
    expect(PhpValueExporter::export(Color::YELLOW))
        ->toBe('\\' . Color::class . '::YELLOW');
    expect(PhpValueExporter::isExportable(['ok' => Color::RED]))->toBeTrue();
    expect(PhpValueExporter::isExportable(['bad' => new stdClass()]))->toBeFalse();
    expect(PhpValueExporter::findUnexportableClass(['bad' => new stdClass()]))->toBe('stdClass');
});

it('keeps a data file header verbatim and regenerates only the returned value', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Events/dresser-note.php';

    $file = PhpDataFile::load($path);
    expect($file->isEditable())->toBeTrue();
    expect($file->header)->toContain('// A small demo cutscene');

    $file->save([['type' => 'text', 'text' => 'Rewritten.']]);

    $contents = (string) file_get_contents($path);
    expect($contents)->toContain('// A small demo cutscene: reading the note on the dresser.');
    expect($contents)->toContain("return [\n  [\n    'type' => 'text',");
    expect(require $path)->toBe([['type' => 'text', 'text' => 'Rewritten.']]);

    removeDirectoryRecursively($root);
});

it('refuses to rewrite a file whose data carries comments', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/states.php';
    file_put_contents($path, "<?php\n\nreturn [\n  // keep me\n  ['id' => 'poison'],\n];\n");

    $file = PhpDataFile::load($path);

    expect($file->isEditable())->toBeFalse();
    expect($file->readOnlyReason)->toContain('comments inside its data');

    removeDirectoryRecursively($root);
});

it('loads, edits, and saves the states category losslessly', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'states');

    expect($database->isEditable())->toBeTrue();
    expect($database->getEntryLabels())->toBe(['Poison', 'Stun']);
    expect($database->isDirty())->toBeFalse();

    $database->setField(1, 'name', 'Dazed');
    $database->setField(1, 'durationTurns', '4');

    expect($database->isDirty())->toBeTrue();

    $database->save();

    expect($database->isDirty())->toBeFalse();

    $reloaded = loadRecordDatabase($root, 'states');
    expect($reloaded->getEntryLabels())->toBe(['Poison', 'Dazed']);

    // Untouched keys round-trip verbatim.
    $poison = $reloaded->getRecordByIndex(0);
    expect($poison->get('tickFormula'))->toBe('-max(1, intval($target->stats->totalHp * 0.08))');
    expect($poison->get('persistsAfterBattle'))->toBeTrue();

    $stun = $reloaded->getRecordByIndex(1);
    expect($stun->get('durationTurns'))->toBe(4);
    expect($stun->get('preventsAction'))->toBeTrue();

    removeDirectoryRecursively($root);
});

it('drops optional keys when a field is cleared and stores real booleans', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'states');

    $database->setField(1, 'durationTurns', '0');
    $database->setField(1, 'preventsAction', 'false');
    $database->setField(0, 'persistsAfterBattle', 'true');
    $database->save();

    $payload = require $root . '/assets/Data/states.php';

    expect($payload[1])->not->toHaveKey('durationTurns');
    expect($payload[1])->not->toHaveKey('preventsAction');
    expect($payload[0]['persistsAfterBattle'])->toBeTrue();

    removeDirectoryRecursively($root);
});

it('creates and deletes state entries with an undoable round trip', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'states');

    $index = $database->addRecord();
    expect($index)->toBe(2);
    expect($database->getRecordByIndex(2)->get('id'))->toBe('new-state');

    $removed = $database->removeRecord(0);
    expect($removed)->not->toBeNull();
    expect($database->getEntryLabels())->toBe(['Stun', 'New State']);

    $database->insertRecord(0, $removed);
    expect($database->getEntryLabels())->toBe(['Poison', 'Stun', 'New State']);

    removeDirectoryRecursively($root);
});

it('edits troop members through flattened sub-list fields', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'troops');

    expect($database->isEditable())->toBeTrue();
    expect($database->getEntryLabels())->toBe(['Bat x 2', 'Lone Rat']);

    $fields = $database->getSettingsFields(0);
    $fieldIds = array_column($fields, 'field');

    expect($fieldIds)->toContain('name', 'member0Enemy', 'member0Position0', 'member0Position1', 'member1Enemy');

    $database->setField(0, 'member1Enemy', 'Great Wolf');
    $database->setField(0, 'member1Position1', '12');
    $database->save();

    $payload = require $root . '/assets/Data/troops.php';

    expect($payload[0]['enemies'][1]['enemy'])->toBe('Great Wolf');
    expect($payload[0]['enemies'][1]['position'])->toBe([15, 12]);
    // The key the editor never showed survives.
    expect($payload[0])->toHaveKey('events');

    removeDirectoryRecursively($root);
});

it('adds and removes troop members', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'troops');

    expect($database->countSubItems(1))->toBe(1);

    $entryIndex = $database->addSubItem(1);
    expect($entryIndex)->toBe(1);
    expect($database->countSubItems(1))->toBe(2);

    $removed = $database->removeSubItem(1, 1);
    expect($removed)->toBe(['enemy' => 'Regular Bat', 'position' => [15, 7]]);
    expect($database->countSubItems(1))->toBe(1);

    $database->insertSubItem(1, 1, $removed);
    expect($database->countSubItems(1))->toBe(2);

    removeDirectoryRecursively($root);
});

it('browses object-backed inventory categories without offering edits', function (): void {
    $root = makeTemporaryProject();

    $items = loadRecordDatabase($root, 'items');
    $weapons = loadRecordDatabase($root, 'weapons');
    $armors = loadRecordDatabase($root, 'armors');

    expect($items->isEditable())->toBeFalse();
    expect($items->getReadOnlyReason())->toContain('authored as PHP constructor calls');
    expect($items->getEntryLabels())->toBe(['S-Potion', 'Antidote']);
    expect($weapons->getEntryLabels())->toBe(['Wooden Sword']);
    expect($armors->getEntryLabels())->toBe([]);

    // Real values are still readable, and no row offers an editable control.
    $fields = $items->getSettingsFields(0);
    expect(array_column($fields, 'value'))->toContain('S-Potion', '50');

    foreach ($fields as $field) {
        expect($field)->not->toHaveKey('control');
        expect($field)->not->toHaveKey('options');
    }

    removeDirectoryRecursively($root);
});

it('refuses every write path on a read-only category', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'items');
    $before = (string) file_get_contents($root . '/assets/Data/items.php');

    $database->setField(0, 'name', 'Tampered');
    expect($database->addRecord())->toBeNull();
    expect($database->removeRecord(0))->toBeNull();
    expect($database->isDirty())->toBeFalse();
    expect(fn() => $database->save())->toThrow(RuntimeException::class);

    expect((string) file_get_contents($root . '/assets/Data/items.php'))->toBe($before);

    removeDirectoryRecursively($root);
});

it('flattens the config vocab and messages trees into editable terms', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'terms');

    expect($database->isEditable())->toBeTrue();
    expect($database->getEntryLabels())->toBe([
        'vocab.game.new_game',
        'vocab.game.continue',
        'vocab.currency.name',
        'vocab.currency.symbol',
        'messages.file',
        'messages.confirm.quit',
    ]);

    $database->setField(0, 'value', 'Begin');
    $database->setField(2, 'value', 'Zenny');
    $database->save();

    $payload = require $root . '/config.php';

    expect($payload['vocab']['game']['new_game'])->toBe('Begin');
    expect($payload['vocab']['currency']['name'])->toBe('Zenny');
    expect($payload['vocab']['game']['continue'])->toBe('Load Game');
    // The enum elsewhere in the config survives the rewrite as a real case.
    expect($payload['ui']['menu']['selection_color'])->toBe(Color::YELLOW);

    removeDirectoryRecursively($root);
});

it('writes enum cases back as fully-qualified references', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'terms');

    $database->setField(4, 'value', 'Slot');
    $database->save();

    expect((string) file_get_contents($root . '/config.php'))
        ->toContain('\\' . Color::class . '::YELLOW');

    removeDirectoryRecursively($root);
});

it('turns terms read-only when the config carries inline comments', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/config.php';
    $contents = (string) file_get_contents($path);
    file_put_contents($path, str_replace("  'messages' =>", "  // Player-facing copy.\n  'messages' =>", $contents));

    $database = loadRecordDatabase($root, 'terms');

    expect($database->isEditable())->toBeFalse();
    expect($database->getReadOnlyReason())->toContain('comments inside its data');
    // Rows still render, just without controls.
    expect($database->getEntryLabels())->not->toBeEmpty();

    foreach ($database->getSettingsFields(0) as $field) {
        expect($field)->not->toHaveKey('control');
    }

    removeDirectoryRecursively($root);
});

it('lists type tables without evaluating them and explains tilesets', function (): void {
    $root = makeTemporaryProject();

    $types = loadRecordDatabase($root, 'types');
    $tilesets = loadRecordDatabase($root, 'tilesets');

    expect($types->isEditable())->toBeFalse();
    expect($types->getReadOnlyReason())->toContain('PHP enum declarations');
    expect($types->getEntryLabels())->toBe(['equipment.php']);

    expect($tilesets->isEditable())->toBeFalse();
    expect($tilesets->getReadOnlyReason())->toContain('no tileset system');
    expect($tilesets->getEntryLabels())->toBe([]);

    removeDirectoryRecursively($root);
});
