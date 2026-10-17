<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\ProjectActorDatabase;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;

/**
 * An actor's attack style is the weapon they fight with when none is
 * equipped: their own, part of who they are, with no stats. The Actors
 * database edits it as one row, written as the Engine spells it.
 */
function attackStyleEditor(string $root): Editor
{
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('actors'));

    return $editor;
}

/** @return array<string, mixed> */
function attackStyleRow(Editor $editor): array
{
    return array_find(callEditorMethod($editor, 'getDatabaseSettingsFields'),
        static fn(array $field): bool => ($field['field'] ?? null) === 'attackStyle')
        ?? throw new RuntimeException('The Actors database offers no Attack Style row.');
}

it('offers unarmed and every Engine weapon type, and writes the Engine spelling over the authored actor', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    $before = require $path;
    $editor = attackStyleEditor($root);
    $row = attackStyleRow($editor);

    expect($row['options'])->toBe(['unarmed', ...array_map(static fn(WeaponType $type): string => $type->value, WeaponType::cases())])
        ->and($row['value'])->toBe('Unarmed');

    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $row, 'sword');
    getEditorProperty($editor, 'workspace')->actorDatabase->save();
    $saved = require $path;

    expect($saved['data']['attackStyle'])->toBe('Sword')
        ->and(array_diff_key($saved['data'], ['attackStyle' => true]))->toBe($before['data'])
        ->and(attackStyleRow($editor)['value'])->toBe('Sword');
});

it('removes the key for unarmed, undoes and redoes, and refuses a weapon type the Engine does not have', function () {
    $root = makeTemporaryProject();
    $editor = attackStyleEditor($root);
    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');
    $actor = getEditorProperty($editor, 'workspace')->actorDatabase->getActorByIndex(0);

    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', attackStyleRow($editor), 'Dagger');
    expect($actor->getAttackStyle())->toBe('Dagger');

    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', attackStyleRow($editor), 'unarmed');
    expect($actor->getData())->not->toHaveKey('attackStyle');

    $history->undo();
    expect($actor->getAttackStyle())->toBe('Dagger');
    $history->undo();
    expect($actor->getData())->not->toHaveKey('attackStyle');
    $history->redo();
    expect($actor->getAttackStyle())->toBe('Dagger');

    expect(fn() => $actor->setField('attackStyle', 'Lance'))->toThrow(RuntimeException::class, 'The Engine has no weapon type "Lance".')
        ->and($actor->getAttackStyle())->toBe('Dagger');
});

it('reports a written attack style the Engine does not know, and none for a known one in any case', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    $write = static function (string $style) use ($path): void {
        $actor = require $path;
        $actor['data']['attackStyle'] = $style;
        file_put_contents($path, "<?php\n\nreturn " . var_export($actor, true) . ";\n");
    };
    $messages = static fn(): array => array_map(static fn($issue): string => $issue->message,
        new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)));

    $write('Lance');
    expect($messages())->toContain('Its attack style "Lance" is not a weapon type the Engine knows.');

    $write('staff');
    expect(implode("\n", $messages()))->not->toContain('attack style')
        ->and(ProjectActorDatabase::fromProject($root)->getActorByIndex(0)->getAttackStyle())->toBe('staff');
});
