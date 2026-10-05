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

it('asks before an identity freeze that repairs other files at once, then writes it as one undo step', function () {
    $root = makeTemporaryProject();
    $mira = writeLegacyActor($root, 'Mira');
    writeLegacyActor($root, 'Tobin');
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    [$map] = $session->describeMaps();
    $index = array_search('Mira', $session->listDatabaseRecords('actors')['records'], true);
    $id = actorSessionRow($session->readDatabaseRecord('actors', $index), 'id');
    $question = $session->applyDatabaseRecord('actors', $index, $id['key'], '');

    expect($question['status'])->toBe('question')
        ->and($question['question'])->toContain('Freeze "Mira"')
        ->and(array_column($question['answers'], 'key'))->toBe(['cancel', 'write'])
        ->and($question['answers'][1]['description'])->toContain('assets/Data/Actors/Mira.php', 'Undo restores the files')
        ->and($session->applyDatabaseRecord('actors', $index, $id['key'], '', 'cancel')['changed'])->toBeFalse()
        ->and(fn() => $session->applyDatabaseRecord('actors', $index, $id['key'], '', 'maybe'))->toThrow(SessionRefusal::class, 'Answer write or cancel')
        ->and(sourceHashTree($root))->toBe($before);

    // Pending edits are saved or undone first: the set is written at once.
    $description = actorSessionRow($session->readDatabaseRecord('actors', 0), 'description')['key'];
    $session->applyDatabaseRecord('actors', 0, $description, 'Pending.');
    expect(fn() => $session->applyDatabaseRecord('actors', $index, $id['key'], '', 'write', $question['confirm']))
        ->toThrow(SessionRefusal::class, 'Save or undo pending edits');
    $session->undo();

    // Writing names the plan that was shown; without it, or with another, nothing is written.
    expect(fn() => $session->applyDatabaseRecord('actors', $index, $id['key'], '', 'write'))->toThrow(SessionRefusal::class, 'Review it again')
        ->and(fn() => $session->applyDatabaseRecord('actors', $index, $id['key'], '', 'write', str_repeat('0', 64)))->toThrow(SessionRefusal::class, 'Review it again')
        ->and(sourceHashTree($root))->toBe($before);

    $written = $session->applyDatabaseRecord('actors', $index, $id['key'], '', 'write', $question['confirm']);
    [$reread] = $session->describeMaps();

    expect($written)->toMatchArray(['changed' => true, 'reloaded' => true])
        ->and((string) file_get_contents($mira))->toContain("'id' => 'Mira'")
        ->and(sourceHashTree($root))->not->toBe($before)
        ->and(actorSessionRow($session->readDatabaseRecord('actors', $index), 'id'))->toMatchArray(['kind' => 'info', 'value' => 'Mira'])
        ->and($session->listDatabaseRecords('actors')['dirty'])->toBeFalse()
        // The project was read again; a revision given before names nothing now.
        ->and($reread['revision'])->toBeGreaterThan($map['revision'])
        ->and(fn() => $session->deleteEvent($map['id'], $map['revision'], 'A'))->toThrow(SessionRefusal::class, 'changed since revision');

    $undone = $session->undo();

    expect($undone['label'])->toBe('Migrate actor identities and references')
        ->and($undone['databases'])->toContain('actors')
        ->and($undone['revisions'][$map['id']])->toBeGreaterThan($reread['revision'])
        ->and(sourceHashTree($root))->toBe($before)
        ->and(actorSessionRow($session->readDatabaseRecord('actors', $index), 'id')['kind'])->toBe('action');
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

it('asks and answers a file set written at once over the line protocol', function () {
    $root = makeTemporaryProject();
    writeLegacyActor($root, 'Mira');
    writeLegacyActor($root, 'Tobin');
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]));
    $request(1, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $index = array_search('Mira', $request(2, 'database.records', ['category' => 'actors'])['result']['records'], true);
    $key = actorSessionRow($request(3, 'database.record', ['category' => 'actors', 'index' => $index, 'frame' => []])['result'], 'id')['key'];
    $apply = ['category' => 'actors', 'index' => $index, 'key' => $key, 'value' => ''];

    $asked = $request(4, 'database.apply', $apply)['result'];

    expect($asked['status'])->toBe('question')
        ->and($request(5, 'database.apply', [...$apply, 'answer' => 'write', 'confirm' => $asked['confirm']])['result'])->toMatchArray(['changed' => true, 'reloaded' => true])
        ->and($request(6, 'history.undo')['result']['label'])->toBe('Migrate actor identities and references');
});
