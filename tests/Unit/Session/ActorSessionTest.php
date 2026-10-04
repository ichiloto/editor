<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Actors through the editor session, authored by the same actor service as
 * the terminal editor: the same rows and previews, each edit one undo step,
 * choices about the panes recording nothing, and a source-preserving save.
 * Synthetic fixtures only.
 */

/** The row with a field id in an actor read. */
function actorSessionRow(array $read, string $fieldId): array
{
    return array_find($read['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $fieldId)
        ?? throw new RuntimeException("No actor row {$fieldId}.");
}

/** Writes a legacy actor authored without an id into a fixture project. */
function writeLegacyActor(string $root, string $name): string
{
    $path = $root . '/assets/Data/Actors/' . $name . '.php';
    file_put_contents($path, <<<PHP
        <?php

        use Ichiloto\Engine\Entities\Character;

        return [
          'class' => Character::class,
          'data' => [
            'name' => '{$name}',
            'level' => 1,
          ]
        ];

        PHP);

    return $path;
}

it('lists actors with what the category can do, and reads an actor\'s rows and previews', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $actors = $session->listDatabaseRecords('actors');
    $read = $session->readDatabaseRecord('actors', 0);

    expect($actors)->toMatchArray([
        'editable' => true, 'dirty' => false, 'canCreate' => true, 'canDuplicate' => false, 'canDelete' => true, 'canReorder' => false,
        'records' => ['Kaelion'],
    ])
        ->and($read)->toMatchArray(['frame' => [], 'frameLabel' => null, 'editable' => true, 'listNoun' => null])
        ->and(actorSessionRow($read, 'name'))->toMatchArray(['kind' => 'text', 'value' => 'Kaelion'])
        ->and(actorSessionRow($read, 'class')['kind'])->toBe('options')
        ->and(actorSessionRow($read, 'attackSkill'))->toMatchArray(['kind' => 'reference', 'reference' => 'attack_skills', 'noneLabel' => '(Built-in attack)'])
        ->and(actorSessionRow($read, 'id')['kind'])->toBe('info')
        ->and(array_column($read['rows'], 'label'))->toContain('Resolved Stats', 'Optimize Preview')
        ->and(fn() => $session->readDatabaseRecord('actors', 4))->toThrow(SessionRefusal::class, 'actors has no record 4.')
        ->and(fn() => $session->readDatabaseRecord('actors', 0, [0]))->toThrow(SessionRefusal::class, 'Actors have no command frames.');
});

it('applies an actor row as one undo step that names the actors, and saves only what changed', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);
    $name = actorSessionRow($session->readDatabaseRecord('actors', 0), 'name');

    expect($session->applyDatabaseRecord('actors', 0, $name['key'], 'Kaelion the Bold'))->toBe(['changed' => true, 'records' => ['Kaelion the Bold']])
        ->and($session->applyDatabaseRecord('actors', 0, $name['key'], 'Kaelion the Bold')['changed'])->toBeFalse()
        ->and($session->listDatabaseRecords('actors')['dirty'])->toBeTrue();

    $undone = $session->undo();
    expect($undone['databases'])->toBe(['actors'])
        ->and($session->listDatabaseRecords('actors')['records'])->toBe(['Kaelion'])
        ->and($session->listDatabaseRecords('actors')['dirty'])->toBeFalse();

    $session->redo();
    expect($session->saveDatabase('actors')['saved'])->toBeTrue()
        ->and(file_get_contents($path))->toBe(str_replace("'name' => 'Kaelion',", "'name' => 'Kaelion the Bold',", $original))
        ->and($session->listDatabaseRecords('actors')['dirty'])->toBeFalse()
        ->and($session->saveDatabase('actors')['saved'])->toBeFalse();
});

it('keeps choices about the actor panes out of the project and out of the history', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $slot = actorSessionRow($session->readDatabaseRecord('actors', 0), '__actor_optimize_slot');
    $other = array_values(array_filter($slot['options'], static fn(string $option): bool => $option !== $slot['value']))[0];

    expect($session->applyDatabaseRecord('actors', 0, $slot['key'], $other)['changed'])->toBeFalse()
        ->and(actorSessionRow($session->readDatabaseRecord('actors', 0), '__actor_optimize_slot')['value'])->toBe($other)
        ->and($session->listDatabaseRecords('actors')['dirty'])->toBeFalse()
        ->and($session->undo()['label'])->toBeNull();
});

it('creates and deletes actors as undo steps, and refuses the operations a file per actor cannot keep', function () {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);

    $created = $session->createDatabaseRecord('actors');
    expect($created)->toBe(['index' => 1, 'records' => ['Kaelion', 'New Actor']]);

    $deleted = $session->deleteDatabaseRecord('actors', 0);
    expect($deleted)->toBe(['index' => 0, 'records' => ['New Actor']]);

    $session->undo();
    expect($session->listDatabaseRecords('actors')['records'])->toBe(['Kaelion', 'New Actor'])
        ->and(fn() => $session->duplicateDatabaseRecord('actors', 0))->toThrow(SessionRefusal::class, 'Actors cannot be duplicated')
        ->and(fn() => $session->moveDatabaseRecord('actors', 0, 'down'))->toThrow(SessionRefusal::class, 'file order')
        ->and(fn() => $session->addDatabaseItem('actors', 0, ['frame' => []]))->toThrow(SessionRefusal::class, 'no list');

    $session->saveDatabase('actors');
    expect(is_file($root . '/assets/Data/Actors/NewActor.php'))->toBeTrue()
        ->and(is_file($root . '/assets/Data/Actors/Kaelion.php'))->toBeTrue();
});

it('offers the one-time identity freeze of an actor authored without an id as the row\'s action', function () {
    $root = makeTemporaryProject();
    // A project whose only identity repair is this actor's: no skit names
    // actors by the names a repair would rewrite.
    removeDirectoryRecursively($root . '/assets/Data/Skits');
    $path = writeLegacyActor($root, 'Mira');
    $session = EditorSession::open($root);
    $index = array_search('Mira', $session->listDatabaseRecords('actors')['records'], true);
    $id = actorSessionRow($session->readDatabaseRecord('actors', $index), 'id');

    expect($id)->toMatchArray(['kind' => 'action', 'action' => 'Freeze "Mira" as the permanent id'])
        ->and($session->applyDatabaseRecord('actors', $index, $id['key'], '')['changed'])->toBeTrue()
        ->and(actorSessionRow($session->readDatabaseRecord('actors', $index), 'id'))->toMatchArray(['kind' => 'info', 'value' => 'Mira']);

    $session->saveDatabase('actors');
    expect(file_get_contents($path))->toContain("'id' => 'Mira'");
});

it('refuses an identity freeze that needs other files repaired at once, naming where that is done', function () {
    $root = makeTemporaryProject();
    writeLegacyActor($root, 'Mira');
    writeLegacyActor($root, 'Tobin');
    $session = EditorSession::open($root);
    $index = array_search('Mira', $session->listDatabaseRecords('actors')['records'], true);
    $id = actorSessionRow($session->readDatabaseRecord('actors', $index), 'id');

    expect(fn() => $session->applyDatabaseRecord('actors', $index, $id['key'], ''))
        ->toThrow(SessionRefusal::class, 'other file(s) repaired at once')
        ->and($session->listDatabaseRecords('actors')['dirty'])->toBeFalse();
});

it('serves actors over the line protocol', function () {
    $root = makeTemporaryProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]));
    $request(1, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);

    $read = $request(2, 'database.record', ['category' => 'actors', 'index' => 0, 'frame' => []])['result'];
    $applied = $request(3, 'database.apply', ['category' => 'actors', 'index' => 0, 'key' => actorSessionRow($read, 'description')['key'], 'value' => 'A swordsman.'])['result'];
    $undone = $request(4, 'history.undo')['result'];

    expect($request(5, 'database.records', ['category' => 'actors'])['result']['records'])->toBe(['Kaelion'])
        ->and($applied['changed'])->toBeTrue()
        ->and($undone['databases'])->toBe(['actors']);
});
