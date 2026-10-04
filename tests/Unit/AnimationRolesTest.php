<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\AnimationReferenceValidator;
use Ichiloto\Engine\Animations\ActionAnimationResolver;

/** A throwaway project whose animations are a blade and an impact. */
function writeRoleAnimations(string $root, array $bladeRoles = ['attack-sword'], array $impactRoles = ['attack', 'attack-unarmed']): void
{
    $entry = static fn(int $id, string $name, array $roles): array => [
        'id' => $id, 'name' => $name,
        ...($roles === [] ? [] : ['roles' => $roles]),
        'targetEffect' => $id === 1 ? 'battle-blade-slash' : 'battle-physical-impact',
    ];
    file_put_contents($root . '/assets/Data/animations.php', '<?php return ' . var_export([
        $entry(1, 'Blade Slash', $bladeRoles),
        $entry(2, 'Hit Spark', $impactRoles),
    ], true) . ';');
}

/** An editor over the project, on the Animations database. */
function roleEditor(string $root): Editor
{
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('animations'));

    return $editor;
}

/** @return list<string> The validation messages about animations. */
function roleIssues(string $root): array
{
    return array_map(
        static fn($issue): string => $issue->message,
        new AnimationReferenceValidator()->validate(ProjectWorkspace::fromProject($root)),
    );
}

it('binds and unbinds roles as one list on the record, keeping everything else', function () {
    $root = makeTemporaryProject();
    writeRoleAnimations($root);
    $database = ProjectWorkspace::fromProject($root)->getRecordDatabase('animations');

    $database->setField(0, 'roles', 'attack-sword, attack-dagger');
    $database->setField(1, 'roles', '');
    $database->save();
    $saved = require $root . '/assets/Data/animations.php';

    expect($saved[0]['roles'])->toBe(['attack-sword', 'attack-dagger'])
        ->and($saved[1])->not->toHaveKey('roles')
        ->and($saved[0]['targetEffect'])->toBe('battle-blade-slash');
});

it('refuses a role another animation holds, naming it and changing nothing', function () {
    $root = makeTemporaryProject();
    writeRoleAnimations($root);
    $database = ProjectWorkspace::fromProject($root)->getRecordDatabase('animations');

    expect(fn() => $database->setField(1, 'roles', 'attack, attack-unarmed, attack-sword'))
        ->toThrow(InvalidArgumentException::class, 'Blade Slash already has roles attack-sword; take it off there first.')
        ->and($database->getRecordByIndex(1)->get('roles'))->toBe(['attack', 'attack-unarmed'])
        ->and($database->isDirty())->toBeFalse();
});

it('offers every supported role in the roles picker, naming the animation that holds it', function () {
    $root = makeTemporaryProject();
    writeRoleAnimations($root);
    $catalog = new ReferenceCatalog(ProjectWorkspace::fromProject($root));
    $row = array_find(callEditorMethod(roleEditor($root), 'getDatabaseSettingsFields'), static fn(array $field): bool => ($field['field'] ?? null) === 'roles');

    expect($row)->toMatchArray(['reference' => 'animation_roles', 'multi' => true, 'value' => 'attack-sword'])
        ->and($catalog->valuesFor('animation_roles'))->toBe(ActionAnimationResolver::getSupportedRoles())
        ->and($catalog->labelsFor('animation_roles')['attack-unarmed'])->toBe('attack-unarmed (on Hit Spark)')
        ->and($catalog->labelsFor('animation_roles')['attack-axe'])->toBe('attack-axe');
});

it('takes a role from the picker as one undo step, and leaves no step when refused', function () {
    $root = makeTemporaryProject();
    writeRoleAnimations($root);
    $editor = roleEditor($root);
    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');
    $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('animations');
    $roles = static fn(): array => array_find(callEditorMethod($editor, 'getDatabaseSettingsFields'), static fn(array $field): bool => ($field['field'] ?? null) === 'roles');

    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $roles(), 'attack-sword, attack-axe');
    expect($database->getRecordByIndex(0)->get('roles'))->toBe(['attack-sword', 'attack-axe']);

    $history->undo();
    expect($database->getRecordByIndex(0)->get('roles'))->toBe(['attack-sword']);

    $history->redo();
    $history->undo();
    $steps = new ReflectionProperty(CommandHistory::class, 'undoStack')->getValue($history);
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $roles(), 'attack-sword, attack-unarmed');

    expect($database->getRecordByIndex(0)->get('roles'))->toBe(['attack-sword'])
        ->and(new ReflectionProperty(CommandHistory::class, 'undoStack')->getValue($history))->toBe($steps)
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('Hit Spark already has roles attack-unarmed');
});

it('reports roles the runtime cannot play and roles the project reaches with no animation', function () {
    $root = makeTemporaryProject();
    writeRoleAnimations($root, ['attack-sword', 'attack-lance'], ['attack-sword']);
    $issues = roleIssues($root);

    expect($issues)->toContain(
        'Its role "attack-lance" is not one the Engine supports, so the whole animation is skipped.',
        'Role attack-sword is bound to Blade Slash and Hit Spark, so neither plays for it.',
        'No animation holds role attack, so enemy and unclassified attacks play no effect.',
        'No animation holds role attack-unarmed, so attacks with no weapon equipped play no effect.',
    );

    writeRoleAnimations($root, [], ['attack', 'attack-unarmed']);

    // The fixture's Wooden Sword makes swords a weapon type the battles reach.
    expect(implode("\n", roleIssues($root)))->toContain('No animation holds role attack-sword, so attacks with a sword such as Wooden Sword play no effect.')
        ->and(implode("\n", roleIssues($root)))->not->toContain('role attack,');

    writeRoleAnimations($root);

    expect(array_filter(roleIssues($root), static fn(string $message): bool => str_contains($message, 'role')))->toBe([]);
});
