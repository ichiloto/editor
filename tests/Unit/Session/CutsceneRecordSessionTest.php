<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/** Summons and the other cutscene types are edited through the same record RPCs as the Database. Synthetic fixtures only. */

it('lists the cutscene types an interface edits as record categories', function () {
    $hello = EditorSession::open(cutsceneProject())->describeProject();

    expect(array_column($hello['cutscenes'], 'key'))->toBe(['cutscenes/cinematic', 'cutscenes/summon', 'cutscenes/effect'])
        ->and(array_column($hello['cutscenes'], 'label'))->toBe(['Cinematic', 'Summon', 'Effect']);
});

it('reads a summon\'s fields and its tracks, keyframes and cues as frames of its record', function () {
    $session = EditorSession::open(cutsceneProject());
    $records = $session->listDatabaseRecords('cutscenes/summon');
    $record = $session->readDatabaseRecord('cutscenes/summon', 0);

    expect($records['records'])->toHaveCount(1)
        ->and(array_column($record['rows'], 'label'))->toContain('Name');
});

it('edits a summon field as one undo step and saves it to its data file', function () {
    $root = cutsceneProject();
    $session = EditorSession::open($root);
    $name = array_find($session->readDatabaseRecord('cutscenes/summon', 0)['rows'], static fn(array $row): bool => trim($row['label']) === 'Name');
    $file = $root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.data.php';
    $before = (string) file_get_contents($file);

    expect($session->applyDatabaseRecord('cutscenes/summon', 0, $name['key'], 'Lantern Wraith')['changed'])->toBeTrue()
        ->and($session->listUnsavedChanges())->not->toBe([]);
    $session->undo();
    expect(array_find($session->readDatabaseRecord('cutscenes/summon', 0)['rows'], static fn(array $row): bool => trim($row['label']) === 'Name')['value'])
        ->toBe($name['value']);
    $session->redo();
    $session->saveAll();

    expect((string) file_get_contents($file))->toContain("'Lantern Wraith'")
        ->and($session->listUnsavedChanges())->toBe([])
        ->and(fn() => $session->readDatabaseRecord('cutscenes/nothing', 0))->toThrow(SessionRefusal::class);
});
