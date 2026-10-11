<?php

declare(strict_types=1);

use Ichiloto\Editor\Maps\SaveRelocationPlanner;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Storage\WorkspaceSave;

/**
 * Saving a map that makes floor solid where the player can stand today moves
 * existing saves off those cells, once the author has seen where: the save
 * writes the map and a declared relocations step together, and undo removes
 * the step. The fixture is {@see mapLineRemovalProject()}: `home`'s floor is
 * reachable, and the project has a save manifest.
 */

/** @return array{string, EditorSession} The project and a session with a wall painted on home's floor at (2, 3). */
function openWalledHomeSession(): array
{
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('home');
    $session->paint('home', $map['revision'], $map['baseLayer'], [[2, 3]], '#');

    return [$root, $session];
}

function readSaveManifest(string $root): array
{
    return require $root . '/assets/Data/save-compatibility.php';
}

it('asks before saving a map that makes reachable floor solid, naming where those saves land', function () {
    [$root, $session] = openWalledHomeSession();
    $before = sourceHashTree($root);

    $question = $session->saveMap('home');

    expect($question)->toMatchArray(['status' => 'question', 'map' => 'home', 'contentVersion' => 1])
        ->and($question['relocations'])->toBe([['map' => 'home', 'cells' => [[2, 3]], 'to' => [2, 4]]])
        ->and($question['moves'])->toBe(['x 2, y 3 to x 2, y 4'])
        ->and(array_column($question['answers'], 'key'))->toBe(['cancel', 'write'])
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->saveMap('home', 'cancel'))->toMatchArray(['status' => 'cancelled'])
        ->and(sourceHashTree($root))->toBe($before);
});

it('saves the map and the relocation step together once confirmed, and undo removes the step', function () {
    [$root, $session] = openWalledHomeSession();
    $manifest = (string) file_get_contents($root . '/assets/Data/save-compatibility.php');
    $question = $session->saveMap('home');

    $saved = $session->saveMap('home', 'write', $question['confirm']);

    expect($saved['relocated'])->toBe(['x 2, y 3 to x 2, y 4'])
        ->and(readSaveManifest($root)['contentVersion'])->toBe(1)
        ->and(readSaveManifest($root)['migrations'])->toBe([['from' => 0, 'to' => 1,
            'relocations' => [['map' => 'home', 'cells' => [[2, 3]], 'to' => [2, 4]]]]])
        ->and(Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest::fromProjectRoot($root)->createMigrationFrom(0))
        ->toBeInstanceOf(Ichiloto\Engine\IO\SaveCompatibility\DeclaredPositionContentMigration::class)
        ->and(describeSaveCompatibilityIssues($root))->toBe([])
        // Saved: nothing is left to move.
        ->and(SaveRelocationPlanner::plan(getPhysicalOccupancyMap($session), $root))->toBeNull();

    $session->undo();
    expect(file_get_contents($root . '/assets/Data/save-compatibility.php'))->toBe($manifest);
});

it('refuses a write the author was not shown', function () {
    [$root, $session] = openWalledHomeSession();
    $before = sourceHashTree($root);

    expect(fn() => $session->saveMap('home', 'write', 'not-the-shown-review'))->toThrow(SessionRefusal::class, 'Review them again')
        ->and(sourceHashTree($root))->toBe($before);
});

it('saves at once when nothing reachable becomes solid, or when the project keeps no save manifest', function () {
    $root = mapLineRemovalProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('home');
    // A floor cell becomes another kind of floor: nothing a save could stand on turns solid.
    $session->paint('home', $map['revision'], $map['baseLayer'], [[2, 3]], ' ');
    expect($session->saveMap('home'))->toHaveKey('saved');

    [$root, $session] = openWalledHomeSession();
    unlink($root . '/assets/Data/save-compatibility.php');
    expect($session->saveMap('home'))->toHaveKey('saved');
});

it('leaves a map that would move saves out of Save All, for its own reviewed save', function () {
    [$root, $session] = openWalledHomeSession();
    $workspace = new ReflectionProperty(EditorSession::class, 'workspace')->getValue($session);
    $result = WorkspaceSave::saveAll($workspace, new Ichiloto\Editor\Backup\BackupWriter(Ichiloto\Editor\Backup\BackupSettings::fromProject($root), $root));

    expect($result->skippedRelocations)->toBe(['home'])
        ->and(getPhysicalOccupancyMap($session)->isDirty())->toBeTrue()
        ->and(readSaveManifest($root)['contentVersion'])->toBe(0);
});

it('reviews the relocation in the terminal editor before Ctrl+S writes both', function () {
    $root = mapLineRemovalProject();
    $editor = mapLineEditor($root);
    getEditorProperty($editor, 'workspace')->getMapByIndex(0)->setTileSymbol(2, 3, '#');

    pressKeys($editor, "\x13");
    $labels = array_column(getEditorProperty($editor, 'eventOptionDialogEntries'), 'label');
    expect(getEditorProperty($editor, 'isEventOptionDialogOpen'))->toBeTrue()
        ->and($labels)->toContain('Save and move saves', 'Move saves on x 2, y 3 to x 2, y 4')
        ->and(readSaveManifest($root)['contentVersion'])->toBe(0);

    pressKeys($editor, "\033[B", "\r");
    expect(readSaveManifest($root)['contentVersion'])->toBe(1)
        ->and(getEditorProperty($editor, 'workspace')->getMapByIndex(0)->isDirty())->toBeFalse();
});
