<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/** Summons and the other cutscene types are edited through the same record RPCs as the Database. Synthetic fixtures only. */

it('lists the cutscene types an interface edits as record categories', function () {
    $hello = EditorSession::open(cutsceneProject())->describeProject();

    expect(array_column($hello['cutscenes'], 'key'))->toBe(['cutscenes/cinematic', 'cutscenes/summon', 'cutscenes/effect'])
        ->and(array_column($hello['cutscenes'], 'label'))->toBe(['Cinematic', 'Summon', 'Effect'])
        ->and(array_unique(array_column($hello['cutscenes'], 'group')))->toBe(['Cutscenes'])
        ->and(array_unique(array_column($hello['databases'], 'group')))->toBe(['Database']);
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

it('saves a summon category as the Database window saves any category', function () {
    $root = cutsceneProject();
    $session = EditorSession::open($root);
    $name = array_find($session->readDatabaseRecord('cutscenes/summon', 0)['rows'], static fn(array $row): bool => trim($row['label']) === 'Name');
    $session->applyDatabaseRecord('cutscenes/summon', 0, $name['key'], 'Lantern Wraith');

    expect($session->listDatabaseRecords('cutscenes/summon'))->toMatchArray(['dirty' => true])
        ->and($session->saveDatabase('cutscenes/summon')['saved'])->toBeTrue()
        ->and((string) file_get_contents($root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.data.php'))->toContain("'Lantern Wraith'")
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('lists a project reference without a map, and a summon\'s own cues from the summon being edited', function () {
    $root = cutsceneProject();
    mkdir($root . '/assets/Graphics/Effects', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Effects/Spark.png', 8, 8);
    $session = EditorSession::open($root);
    $cues = $session->listReferences(null, 'summon_cues', ['category' => 'cutscenes/summon', 'index' => 0]);

    expect(array_column($session->listReferences(null, 'png_assets'), 'value'))->toContain('Graphics/Effects/Spark.png')
        ->and($cues)->not->toBe([])
        ->and(fn() => $session->listReferences(null, 'summon_cues', ['category' => 'cutscenes/summon', 'index' => 9]))
            ->toThrow(SessionRefusal::class);
});

it('describes a summon timeline with the row keys that edit it, and moves a keyframe as one undo step', function () {
    $session = EditorSession::open(cutsceneProject());
    $timeline = $session->describeCutsceneTimeline('cutscenes/summon', 0);
    $keyframe = $timeline['tracks'][0]['keyframes'][0];

    expect($timeline['fps'])->toBeGreaterThan(0)
        ->and($timeline['lengthFrames'])->toBeGreaterThan(0)
        ->and($timeline['tracks'])->not->toBe([])
        ->and($keyframe)->toHaveKeys(['frame', 'duration', 'frameKey', 'durationKey']);

    $session->applyDatabaseRecord('cutscenes/summon', 0, $keyframe['frameKey'], (string) ($keyframe['frame'] + 1));
    expect($session->describeCutsceneTimeline('cutscenes/summon', 0)['tracks'][0]['keyframes'][0]['frame'])->toBe($keyframe['frame'] + 1);
    $session->undo();
    expect($session->describeCutsceneTimeline('cutscenes/summon', 0)['tracks'][0]['keyframes'][0]['frame'])->toBe($keyframe['frame'])
        ->and(fn() => $session->describeCutsceneTimeline('cutscenes/cinematic', 0))->toThrow(SessionRefusal::class);
});

it('previews a summon frame as its terminal presentation draws it, clamped to the timeline', function () {
    $session = EditorSession::open(cutsceneProject());
    $preview = $session->readCutscenePreview('cutscenes/summon', 0, 9999, 40, 10);

    expect($preview['frame'])->toBe($preview['totalFrames'] - 1)
        ->and($preview['lines'])->toHaveCount(10)
        ->and(mb_strlen($preview['lines'][0]))->toBe(40);
});

it('shows an effect\'s graphical sequence with its image track\'s art, and sets the pivot as one undo step', function () {
    $session = EditorSession::open(effectProject());
    $index = array_search('dusk-slash', $session->listDatabaseRecords('cutscenes/effect')['records'], true);

    expect($session->describeCutsceneTimeline('cutscenes/effect', $index)['presentation'])->toBe('terminal')
        ->and($session->selectCutscenePresentation('cutscenes/effect', $index, 'graphical'))->toBe(['presentation' => 'graphical']);
    $art = $session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art'];
    expect($art)->toMatchArray(['asset' => 'Graphics/Effects/dusk-slash.png', 'columns' => 2, 'rows' => 1, 'width' => 32, 'height' => 16])
        ->and($art['attachmentOptions'])->toBe(['center', 'head', 'ground']);

    $session->applyDatabaseRecord('cutscenes/effect', $index, $art['pivotKey'], '0.25, 1');
    expect($session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art']['pivot'])->toBe('0.25, 1');
    $session->undo();
    expect($session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art']['pivot'])->toBe($art['pivot'])
        ->and(fn() => $session->selectCutscenePresentation('cutscenes/summon', 0, 'graphical'))->toThrow(SessionRefusal::class)
        ->and(fn() => $session->selectCutscenePresentation('cutscenes/effect', $index, 'holographic'))->toThrow(SessionRefusal::class);
});

it('creates, duplicates and deletes summons as one undo step each, reaching disk on save', function () {
    $root = cutsceneProject();
    $session = EditorSession::open($root);
    $summons = static fn(): array => $session->listDatabaseRecords('cutscenes/summon');

    expect($summons())->toMatchArray(['canCreate' => true, 'canDuplicate' => true, 'canDelete' => true]);
    $created = $session->createDatabaseRecord('cutscenes/summon', 'Ember Djinn');
    $id = array_find($session->readDatabaseRecord('cutscenes/summon', $created['index'])['rows'], static fn(array $row): bool => trim($row['label']) === 'Id')['value'];
    expect($id)->toBe('ember-djinn')
        ->and($summons()['records'])->toHaveCount(2);

    $session->undo();
    expect($summons()['records'])->toHaveCount(1);
    $session->redo();
    $copy = $session->duplicateDatabaseRecord('cutscenes/summon', 0);
    expect($summons()['records'])->toHaveCount(3);
    $session->saveAll();
    expect(is_file($root . '/assets/Cutscenes/Summons/ember-djinn/ember-djinn.data.php'))->toBeTrue()
        ->and(is_dir($root . '/assets/Cutscenes/Summons/lantern-wisp-copy'))->toBeTrue();

    $session->deleteDatabaseRecord('cutscenes/summon', $copy['index']);
    expect($summons()['records'])->toHaveCount(2);
    $session->saveAll();
    expect(is_dir($root . '/assets/Cutscenes/Summons/lantern-wisp-copy'))->toBeFalse();
});
