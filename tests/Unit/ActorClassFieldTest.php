<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Builds an unbooted editor over a throwaway copy of the fixture project,
 * with the Database open on the actors category.
 */
function actorClassEditor(string $root): Editor
{
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    callEditorMethod($editor, 'dispatchInput', "\x04");
    setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('actors'));
    setEditorProperty($editor, 'databaseFocus', 'database_settings');

    return $editor;
}

it('offers every project class plus a none sentinel in the actor picker', function () {
    $root = makeTemporaryProject();

    try {
        $editor = actorClassEditor($root);

        expect(callEditorMethod($editor, 'getActorClassOptions'))->toBe(['none', 'Vanguard', 'Oracle']);

        $fields = callEditorMethod($editor, 'getDatabaseActorSettingsFields');
        $classField = null;

        foreach ($fields as $field) {
            if (($field['field'] ?? null) === 'class') {
                $classField = $field;
            }
        }

        expect($classField)->not->toBeNull()
            ->and($classField['label'])->toBe('Class')
            ->and($classField['value'])->toBe('none')
            ->and($classField['options'])->toBe(['none', 'Vanguard', 'Oracle']);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('cycles the class picker with the arrow keys and records undo', function () {
    $root = makeTemporaryProject();

    try {
        $editor = actorClassEditor($root);

        /** @var ProjectWorkspace $workspace */
        $workspace = getEditorProperty($editor, 'workspace');
        $fields = callEditorMethod($editor, 'getDatabaseActorSettingsFields');
        $classIndex = array_search('class', array_column($fields, 'field'), true);
        setEditorProperty($editor, 'databaseSelectedSettingIndex', $classIndex);

        callEditorMethod($editor, 'dispatchInput', "\033[C");

        // The picker writes the class NAME verbatim, not a lowercased slug.
        expect($workspace->actorDatabase->getActorByIndex(0)?->getClassName())->toBe('Vanguard');

        callEditorMethod($editor, 'dispatchInput', "\033[C");

        expect($workspace->actorDatabase->getActorByIndex(0)?->getClassName())->toBe('Oracle');

        callEditorMethod($editor, 'dispatchInput', "\x1a");

        expect($workspace->actorDatabase->getActorByIndex(0)?->getClassName())->toBe('Vanguard');

        callEditorMethod($editor, 'dispatchInput', "\033[D");

        expect($workspace->actorDatabase->getActorByIndex(0)?->getClassName())->toBe('');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('round-trips the class reference through a save into data[class]', function () {
    $root = makeTemporaryProject();

    try {
        $actorPath = $root . '/assets/Data/Actors/Kaelion.php';
        $editor = actorClassEditor($root);

        /** @var ProjectWorkspace $workspace */
        $workspace = getEditorProperty($editor, 'workspace');
        $fields = callEditorMethod($editor, 'getDatabaseActorSettingsFields');
        setEditorProperty($editor, 'databaseSelectedSettingIndex', array_search('class', array_column($fields, 'field'), true));
        callEditorMethod($editor, 'dispatchInput', "\033[C");
        callEditorMethod($editor, 'dispatchInput', "\x13");

        $payload = require $actorPath;

        expect($payload['data']['class'])->toBe('Vanguard')
            // The outer `class` key is the entity FQCN and must survive.
            ->and($payload['class'])->toBe('Ichiloto\\Engine\\Entities\\Character')
            ->and($payload['data']['name'])->toBe('Kaelion');

        // The raw file still declares the FQCN by constant, not by string.
        expect((string) file_get_contents($actorPath))
            ->toContain("'class' => Character::class,")
            ->toContain("'class' => 'Vanguard',");

        // Re-reading through the editor's own loader agrees.
        $reloaded = ProjectActor::fromFile($actorPath);

        expect($reloaded->getClassName())->toBe('Vanguard')
            ->and($reloaded->isDirty())->toBeFalse();
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('drops the class key entirely when the picker returns to none', function () {
    $root = makeTemporaryProject();

    try {
        $actorPath = $root . '/assets/Data/Actors/Kaelion.php';
        $actor = ProjectActor::fromFile($actorPath);
        $actor->setField('class', 'Oracle');
        $actor->save();

        expect((require $actorPath)['data']['class'])->toBe('Oracle');

        $actor = ProjectActor::fromFile($actorPath);
        $actor->setField('class', ProjectActor::CLASS_NONE);
        $actor->save();

        expect(array_key_exists('class', (require $actorPath)['data']))->toBeFalse()
            ->and((require $actorPath)['class'])->toBe('Ichiloto\\Engine\\Entities\\Character');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('accepts the engine role alias when reading an actor class', function () {
    $root = makeTemporaryProject();

    try {
        $actorPath = $root . '/assets/Data/Actors/Aliased.php';
        file_put_contents($actorPath, <<<'PHP'
        <?php

        use Ichiloto\Engine\Entities\Character;

        return [
          'class' => Character::class,
          'data' => [
            'name' => 'Aliased',
            'role' => 'Oracle',
          ]
        ];
        PHP);

        expect(ProjectActor::fromFile($actorPath)->getClassName())->toBe('Oracle');
    } finally {
        removeDirectoryRecursively($root);
    }
});
