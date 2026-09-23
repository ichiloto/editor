<?php

declare(strict_types=1);

it('offers a confirmed one-time identity migration with cancellation and saved undo redo', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    $source = (string) file_get_contents($path);
    $source = preg_replace("/^[^\S\r\n]*'id' => 'Kaelion',\R/m", '', $source);
    file_put_contents($path, $source);
    // This case has no legacy references to repair, so the single-file edit stays deferred.
    $skit = $root . '/assets/Data/Skits/breakfast-banter.php';
    file_put_contents($skit, str_replace("'speaker' => 'Kaelion'", "'speaker' => 'Narrator'", (string) file_get_contents($skit)));
    expect((require $path)['data'])->not->toHaveKey('id');
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'actors');
    setEditorProperty($editor, 'databaseFocus', 'database_settings');
    $fields = callEditorMethod($editor, 'getDatabaseActorSettingsFields');
    $index = array_search('id', array_column($fields, 'field'), true);
    setEditorProperty($editor, 'databaseSelectedSettingIndex', $index);
    $workspace = getEditorProperty($editor, 'workspace');
    $actor = $workspace->actorDatabase->getActors()[0];

    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'isDatabaseEditing'))->toBeFalse()
        ->and(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeTrue();
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect($actor->hasDefinitionId())->toBeFalse();

    callEditorMethod($editor, 'dispatchInput', "\r");
    callEditorMethod($editor, 'dispatchInput', "\033[B");
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'statusMessage'))->toBe('Actor identity frozen. Save the project to write it.');
    expect($actor->getDefinitionId())->toBe('Kaelion')
        ->and(file_get_contents($path))->toBe($source);
    $actor->save();
    expect((require $path)['data']['id'])->toBe('Kaelion');
    callEditorMethod($editor, 'performUndo');
    $actor->save();
    expect(file_get_contents($path))->toBe($source);
    callEditorMethod($editor, 'performRedo');
    $actor->save();
    expect((require $path)['data']['id'])->toBe('Kaelion');
});

it('repairs actor references through the palette only after confirmation and preserves migration undo redo', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    file_put_contents($path, str_replace("'id' => 'Kaelion'", "'id' => 'Aria Vale'", (string) file_get_contents($path)));
    $system = $root . '/assets/Data/system.php';
    file_put_contents($system, "<?php return ['startingParty' => ['Kaelion']];\n");
    $originalActor = file_get_contents($path);
    $originalSystem = file_get_contents($system);
    $editor = deletionEditor($root);
    $openRepair = static function () use ($editor): void {
        $items = callEditorMethod($editor, 'buildPaletteItems');
        $item = array_values(array_filter($items, static fn($item) => $item->label === 'Repair Actor Identities and References'))[0];
        ($item->action)();
    };
    $openRepair();
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeTrue()
        ->and(file_get_contents($system))->toBe($originalSystem);
    $entries = getEditorProperty($editor, 'eventOptionDialogEntries');
    expect($entries[1]['description'])->toContain('Writes now, not on Save')
        ->and(array_column($entries, 'label'))->toContain('assets/Data/system.php');
    callEditorMethod($editor, 'dispatchInput', "\033[B");
    callEditorMethod($editor, 'dispatchInput', "\033[B");
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeTrue()
        ->and(file_get_contents($system))->toBe($originalSystem);
    callEditorMethod($editor, 'dispatchInput', "\033[A");
    callEditorMethod($editor, 'dispatchInput', "\033[A");
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(file_get_contents($system))->toBe($originalSystem);

    $openRepair();
    callEditorMethod($editor, 'dispatchInput', "\033[B");
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect((require $system)['startingParty'])->toBe(['Aria Vale'])
        ->and(file_get_contents($path))->toBe($originalActor);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect(file_get_contents($system))->toBe($originalSystem);
    callEditorMethod($editor, 'dispatchInput', "\x19");
    expect((require $system)['startingParty'])->toBe(['Aria Vale']);

    $migratedSystem = file_get_contents($system);
    file_put_contents($system, $migratedSystem . "// External author edit.\n");
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect(getEditorProperty($editor, 'statusMessage'))->toContain('changed outside')
        ->and(getEditorProperty($editor, 'history')->canUndo())->toBeTrue()
        ->and(file_get_contents($system))->toBe($migratedSystem . "// External author edit.\n");
    file_put_contents($system, $migratedSystem);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect(file_get_contents($system))->toBe($originalSystem);
});

it('retains earlier saved editor commands across actor migration undo and redo', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    file_put_contents($path, str_replace("'id' => 'Kaelion'", "'id' => 'Aria Vale'", (string) file_get_contents($path)));
    $system = $root . '/assets/Data/system.php';
    file_put_contents($system, "<?php return ['startingParty' => ['Kaelion']];\n");
    $editor = deletionEditor($root);
    $before = getEditorProperty($editor, 'workspace');
    $actor = $before->actorDatabase->getActors()[0];
    $rename = new \Ichiloto\Editor\History\GenericCommand('Rename actor',
        static fn() => $actor->setField('name', 'Current Display Name'),
        static fn() => $actor->setField('name', 'Kaelion'),
    );
    $rename->execute();
    callEditorMethod($editor, 'recordCommand', $rename);
    $actor->save();
    callEditorMethod($editor, 'openActorReferenceMigration');
    callEditorMethod($editor, 'dispatchInput', "\033[B");
    callEditorMethod($editor, 'dispatchInput', "\r");
    $after = getEditorProperty($editor, 'workspace');
    expect($after)->not->toBe($before)
        ->and((require $system)['startingParty'])->toBe(['Aria Vale']);

    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect(getEditorProperty($editor, 'workspace'))->toBe($before);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($actor->getName())->toBe('Kaelion');
    $actor->save();
    callEditorMethod($editor, 'dispatchInput', "\x19");
    expect($actor->getName())->toBe('Current Display Name');
    $actor->save();
    callEditorMethod($editor, 'dispatchInput', "\x19");
    expect(getEditorProperty($editor, 'workspace'))->toBe($after)
        ->and((require $system)['startingParty'])->toBe(['Aria Vale']);
});

it('refuses project actor migration while unrelated edits are pending without saving or discarding them', function (): void {
    $root = makeTemporaryProject();
    $editor = deletionEditor($root);
    $workspace = getEditorProperty($editor, 'workspace');
    $database = $workspace->skillDatabase;
    $database->addSkill('Pending skill');
    $database->setField(0, 'cost', 123);
    callEditorMethod($editor, 'openActorReferenceMigration');
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('pending edits')
        ->and($database->isDirty())->toBeTrue()
        ->and(is_file($database->path))->toBeFalse();
});

it('explains malformed explicit ids without offering a migration or opening input', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    file_put_contents($path, str_replace("'id' => 'Kaelion'", "'id' => ''", (string) file_get_contents($path)));
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'actors');
    setEditorProperty($editor, 'databaseFocus', 'database_settings');
    $fields = callEditorMethod($editor, 'getDatabaseActorSettingsFields');
    $index = array_search('id', array_column($fields, 'field'), true);
    expect($fields[$index]['editable'])->toBeFalse()
        ->and($fields[$index]['displayDefault'])->toContain('Malformed explicit id')
        ->and($fields[$index]['actorIdentityMigration'])->toBeNull();
    setEditorProperty($editor, 'databaseSelectedSettingIndex', $index);
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'isDatabaseEditing'))->toBeFalse()
        ->and(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeFalse();
});
