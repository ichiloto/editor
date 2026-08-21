<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Navigation\NavigationEntry;
use Ichiloto\Editor\Navigation\NavigationStack;
use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Builds an unbooted editor with the fixture workspace loaded.
 */
function navigationEditor(): Editor
{
    $editor = createEditorForTesting(fixturePath('sample-project'));
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject(fixturePath('sample-project')));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);

    return $editor;
}

it('pushes and pops navigation entries in LIFO order', function () {
    $stack = new NavigationStack(10);
    $visited = [];

    expect($stack->canGoBack())->toBeFalse()
        ->and($stack->back())->toBeNull();

    foreach (['first', 'second', 'third'] as $label) {
        $stack->push(new NavigationEntry($label, function () use (&$visited, $label): void {
            $visited[] = $label;
        }));
    }

    expect($stack->count())->toBe(3)
        ->and($stack->peek()?->label)->toBe('third');

    $entry = $stack->back();

    expect($entry?->label)->toBe('third')
        ->and($visited)->toBe(['third'])
        ->and($stack->count())->toBe(2);

    $stack->back();
    $stack->back();

    expect($stack->canGoBack())->toBeFalse()
        ->and($visited)->toBe(['third', 'second', 'first']);
});

it('evicts the oldest entries past its bound', function () {
    $stack = new NavigationStack(3);

    foreach (range(1, 7) as $index) {
        $stack->push(new NavigationEntry('entry-' . $index, static fn() => null));
    }

    expect($stack->count())->toBe(3)
        ->and($stack->peek()?->label)->toBe('entry-7');

    $stack->back();
    $stack->back();

    expect($stack->back()?->label)->toBe('entry-5')
        ->and($stack->canGoBack())->toBeFalse();
});

it('never drops below a single retained entry', function () {
    $stack = new NavigationStack(0);
    $stack->push(new NavigationEntry('only', static fn() => null));
    $stack->push(new NavigationEntry('newest', static fn() => null));

    expect($stack->count())->toBe(1)
        ->and($stack->peek()?->label)->toBe('newest');
});

it('goes from an actor to its class and back again', function () {
    $editor = navigationEditor();
    callEditorMethod($editor, 'dispatchInput', "\x04");
    setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('actors'));
    setEditorProperty($editor, 'databaseFocus', 'database_list');

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $workspace->actorDatabase->setField(0, 'class', 'Oracle');

    callEditorMethod($editor, 'dispatchInput', "\x07");

    expect(getEditorProperty($editor, 'databaseCategoryIndex'))->toBe(DatabaseCatalog::indexOf('classes'))
        ->and(getEditorProperty($editor, 'databaseSelectedClassIndex'))->toBe(1);

    /** @var NavigationStack $navigation */
    $navigation = getEditorProperty($editor, 'navigation');

    expect($navigation->count())->toBe(1);

    callEditorMethod($editor, 'dispatchInput', "\x02");

    expect(getEditorProperty($editor, 'databaseCategoryIndex'))->toBe(DatabaseCatalog::indexOf('actors'))
        ->and($navigation->count())->toBe(0);
});

it('warns instead of navigating when an actor has no class', function () {
    $editor = navigationEditor();
    callEditorMethod($editor, 'dispatchInput', "\x04");
    setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('actors'));

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');

    expect($workspace->actorDatabase->getActorByIndex(0)?->getClassName())->toBe('');

    callEditorMethod($editor, 'dispatchInput', "\x07");

    /** @var NavigationStack $navigation */
    $navigation = getEditorProperty($editor, 'navigation');

    expect(getEditorProperty($editor, 'databaseCategoryIndex'))->toBe(DatabaseCatalog::indexOf('actors'))
        ->and($navigation->count())->toBe(0)
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('no class');
});

it('warns when Back has nowhere to go', function () {
    $editor = navigationEditor();
    callEditorMethod($editor, 'dispatchInput', "\x02");

    expect(getEditorProperty($editor, 'statusMessage'))->toContain('Nothing to go back to');
});

it('goes from a transporter event to its destination map and back', function () {
    $root = makeTemporaryProject();

    try {
        // A second map gives the transporter somewhere to land.
        copyDirectoryRecursively(
            $root . '/assets/Maps/test-map',
            (static function (string $path): string {
                mkdir($path, 0777, true);

                return $path;
            })($root . '/assets/Maps/other-map'),
        );

        foreach (glob($root . '/assets/Maps/other-map/test-map.*') ?: [] as $path) {
            rename($path, str_replace('test-map.', 'other-map.', $path));
        }

        $editor = createEditorForTesting($root);
        setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
        setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
        setEditorProperty($editor, 'isRunning', true);
        setEditorProperty($editor, 'focusedPane', 'canvas');
        setEditorProperty($editor, 'editingMode', 'event');

        /** @var ProjectWorkspace $workspace */
        $workspace = getEditorProperty($editor, 'workspace');
        $otherIndex = array_search('other-map', $workspace->mapIds, true);
        $sourceIndex = array_search('test-map', $workspace->mapIds, true);
        setEditorProperty($editor, 'selectedAssetIndex', $sourceIndex);

        $sourceMap = $workspace->getMapByIndex($sourceIndex);
        $sourceMap->setEventDefinition('E', [
            'class' => 'Ichiloto\\Engine\\Events\\Triggers\\Transporter',
            'data' => [
                'destination' => 'other-map',
                'spawnPoint' => ['x' => 3, 'y' => 2],
            ],
        ]);

        $bounds = $sourceMap->getEventBounds('E');
        setEditorProperty($editor, 'cursorX', $bounds['x']);
        setEditorProperty($editor, 'cursorY', $bounds['y']);

        callEditorMethod($editor, 'dispatchInput', "\x07");

        expect(getEditorProperty($editor, 'selectedAssetIndex'))->toBe($otherIndex)
            ->and(getEditorProperty($editor, 'cursorX'))->toBe(3)
            ->and(getEditorProperty($editor, 'cursorY'))->toBe(2);

        callEditorMethod($editor, 'dispatchInput', "\x02");

        expect(getEditorProperty($editor, 'selectedAssetIndex'))->toBe($sourceIndex)
            ->and(getEditorProperty($editor, 'cursorX'))->toBe($bounds['x'])
            ->and(getEditorProperty($editor, 'cursorY'))->toBe($bounds['y']);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('exposes the actor class name the navigation resolves against', function () {
    $actor = ProjectActor::createBlank(sys_get_temp_dir() . '/never-written.php', 'Hero', 'Hero');

    expect($actor->getClassName())->toBe('');

    $actor->setField('class', 'Vanguard');

    expect($actor->getClassName())->toBe('Vanguard');

    $actor->setField('class', ProjectActor::CLASS_NONE);

    expect($actor->getClassName())->toBe('');
});
