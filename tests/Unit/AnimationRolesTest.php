<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\ProjectAnimationDatabase;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\AnimationReferenceValidator;
use Ichiloto\Engine\Animations\ActionAnimationResolver;

/** A throwaway project whose animations are a blade and an impact. */
function writeRoleAnimations(string $root, array $bladeRoles = ['attack-sword'], array $impactRoles = ['attack', 'attack-unarmed']): void
{
    $entry = static fn(int $id, string $name, array $roles): array => [
        'id' => $id, 'name' => $name, 'position' => 'center', 'maxFrames' => 1,
        ...($roles === [] ? [] : ['roles' => $roles]),
        'targetEffect' => $id === 1 ? 'battle-blade-slash' : 'battle-physical-impact',
        'frames' => [], 'cues' => [],
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

/** @return array<string, array<string, mixed>> The role rows keyed by field. */
function roleRows(Editor $editor): array
{
    $rows = [];

    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $field) {
        if (str_starts_with((string) ($field['field'] ?? ''), 'role:')) {
            $rows[(string) $field['field']] = $field;
        }
    }

    return $rows;
}

/** @return list<string> The validation messages about animations. */
function roleIssues(string $root): array
{
    return array_map(
        static fn($issue): string => $issue->message,
        new AnimationReferenceValidator()->validate(ProjectWorkspace::fromProject($root)),
    );
}

it('binds and unbinds roles, writing them over the authored entry and keeping everything else', function () {
    $root = makeTemporaryProject();
    writeRoleAnimations($root);
    $database = ProjectAnimationDatabase::fromProject($root);

    expect($database->getRoles(0))->toBe(['attack-sword'])
        ->and($database->findRoleOwner('attack-unarmed'))->toBe(1)
        ->and($database->setRole(0, 'attack-dagger', true))->toBeNull()
        ->and($database->setRole(1, 'attack-unarmed', false))->toBeNull()
        ->and($database->setRole(1, 'attack', false))->toBeNull();

    $database->save();
    $saved = require $root . '/assets/Data/animations.php';

    expect($saved[0]['roles'])->toBe(['attack-sword', 'attack-dagger'])
        ->and($saved[1])->not->toHaveKey('roles')
        ->and($saved[0]['targetEffect'])->toBe('battle-blade-slash')
        ->and(ProjectAnimationDatabase::fromProject($root)->getRoles(0))->toBe(['attack-sword', 'attack-dagger']);
});

it('refuses a role the Engine does not support or one already bound elsewhere, changing nothing', function () {
    $root = makeTemporaryProject();
    writeRoleAnimations($root);
    $database = ProjectAnimationDatabase::fromProject($root);

    expect($database->setRole(1, 'attack-sword', true))->toBe('Role attack-sword is already bound to Blade Slash; unbind it there first.')
        ->and($database->setRole(0, 'attack-lance', true))->toBe('The Engine has no animation role "attack-lance".')
        ->and($database->getRoles(0))->toBe(['attack-sword'])
        ->and($database->getRoles(1))->toBe(['attack', 'attack-unarmed'])
        ->and($database->isDirty())->toBeFalse();
});

it('offers every supported role as a row, naming the animation that holds it', function () {
    $root = makeTemporaryProject();
    writeRoleAnimations($root);
    $rows = roleRows(roleEditor($root));

    expect(array_keys($rows))->toBe(array_map(static fn(string $role): string => 'role:' . $role, ActionAnimationResolver::getSupportedRoles()))
        ->and($rows['role:attack-sword']['value'])->toBe('Yes')
        ->and($rows['role:attack-sword']['options'])->toBe(['no', 'yes'])
        ->and(trim((string) $rows['role:attack-unarmed']['label']))->toBe('attack-unarmed (on Hit Spark)')
        ->and($rows['role:attack-unarmed']['value'])->toBe('No');
});

it('switches a role on from its row and undoes it, and leaves no undo step when refused', function () {
    $root = makeTemporaryProject();
    writeRoleAnimations($root);
    $editor = roleEditor($root);
    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');
    $database = getEditorProperty($editor, 'workspace')->animationDatabase;

    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', roleRows($editor)['role:attack-axe'], 'yes');
    expect($database->getRoles(0))->toBe(['attack-sword', 'attack-axe']);

    $history->undo();
    expect($database->getRoles(0))->toBe(['attack-sword']);

    $history->redo();
    $history->undo();
    $steps = new ReflectionProperty(CommandHistory::class, 'undoStack')->getValue($history);
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', roleRows($editor)['role:attack-unarmed'], 'yes');

    expect($database->getRoles(0))->toBe(['attack-sword'])
        ->and(new ReflectionProperty(CommandHistory::class, 'undoStack')->getValue($history))->toBe($steps)
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('already bound to Hit Spark');
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
