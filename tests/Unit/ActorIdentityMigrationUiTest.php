<?php

declare(strict_types=1);

it('offers a confirmed one-time identity migration with cancellation and saved undo redo', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    $source = (string) file_get_contents($path);
    $source = preg_replace("/^[^\S\r\n]*'id' => 'Kaelion',\R/m", '', $source);
    file_put_contents($path, $source);
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
