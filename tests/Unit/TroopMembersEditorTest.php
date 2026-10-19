<?php

declare(strict_types=1);

/** Composing a troop's members from the terminal editor's Database, with synthetic troops and enemies. */

it('adds a troop member by choosing its enemy, names the troop after its members and clears them, each undoable', function (): void {
    $root = troopPageProject();
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'troops');
    $troops = getEditorProperty($editor, 'workspace')->getRecordDatabase('troops');
    $picker = getEditorProperty($editor, 'referencePicker');

    // Shift+O opens the enemies first; Escape adds nothing.
    callEditorMethod($editor, 'dispatchInput', 'O');
    expect($picker->isOpen())->toBeTrue()
        ->and($troops->countSubItems(0))->toBe(2);
    callEditorMethod($editor, 'dispatchInput', "\x1b");
    expect($troops->countSubItems(0))->toBe(2);

    // Typing narrows the enemies; Enter adds a member fighting the one chosen, with the cursor on it.
    callEditorMethod($editor, 'dispatchInput', 'O');
    foreach (str_split('Wisp') as $letter) {
        callEditorMethod($editor, 'dispatchInput', $letter);
    }
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(array_column($troops->getSubItems(0), 'enemy'))->toBe(['Regular Bat', 'Regular Bat', 'Marsh Wisp'])
        ->and(callEditorMethod($editor, 'getSelectedDatabaseFieldId'))->toBe('member2Enemy');

    callEditorMethod($editor, 'dispatchInput', 'N');
    expect($troops->getEntryLabels())->toBe(['Regular Bat x 2, Marsh Wisp']);

    callEditorMethod($editor, 'dispatchInput', 'C');
    expect($troops->countSubItems(0))->toBe(0);

    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($troops->countSubItems(0))->toBe(3);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($troops->getEntryLabels())->toBe(['Pair']);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect($troops->countSubItems(0))->toBe(2);
});
