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

it('authors a summon image track with the effect image fields, compiled per renderer', function () {
    $root = cutsceneProject();
    $file = $root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.timeline.php';
    // A summon with art rests on an authored frame, as reduced motion draws it.
    file_put_contents($file, str_replace(["  'lengthFrames' => 24,", "    ['type' => 'text', 'id' => 'name',"], ["  'lengthFrames' => 24,\n  'restFrame' => 12,", 
        "    ['type' => 'image', 'id' => 'wisp-art', 'asset' => 'Graphics/Summons/Wisp.png', 'sheet' => ['columns' => 2, 'rows' => 1],\n"
        . "      'anchor' => 'target', 'attachment' => 'ground', 'pivot' => ['x' => 0.5, 'y' => 1],\n"
        . "      'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 12, 'sourceFrame' => 1]]],\n"
        . "    ['type' => 'text', 'id' => 'name',"], (string) file_get_contents($file)));
    $session = EditorSession::open($root);
    $track = array_find($session->describeCutsceneTimeline('cutscenes/summon', 0)['tracks'], static fn(array $track): bool => $track['id'] === 'wisp-art');
    $asset = Ichiloto\Editor\Cutscenes\CutsceneLibrary::fromProject($root)->find(Ichiloto\Editor\Cutscenes\CutsceneType::SUMMON, 'lantern-wisp');

    // The record shows the image track's own rows, and its keyframes' sheet cells.
    expect($track['type'])->toBe('image')
        ->and($track['art'])->toMatchArray(['asset' => 'Graphics/Summons/Wisp.png', 'columns' => 2, 'pivot' => '0.5, 1', 'width' => null])
        ->and(array_column($track['keyframes'], 'sourceFrame'))->toBe([0, 1])
        // The terminal never needs the art; the graphical presentation refuses an image that is not there.
        ->and($asset->compiledSummon()->fps)->toBe(12)
        ->and(fn() => $asset->compiledSummon(Ichiloto\Engine\Animations\Timelines\EffectPresentation::GRAPHICAL))->toThrow(RuntimeException::class, 'Graphics/Summons/Wisp.png');

    mkdir($root . '/assets/Graphics/Summons', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Summons/Wisp.png', 16, 8);
    expect($asset->compiledSummon(Ichiloto\Engine\Animations\Timelines\EffectPresentation::GRAPHICAL)->fps)->toBe(12);
});

it('sets how an image track fills its cells, one undo step, keeping the timeline\'s own source', function () {
    $root = cutsceneProject();
    $file = $root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.timeline.php';
    // Written as authors write tracks: the keys run on from the first line and the bracket closes the last.
    file_put_contents($file, str_replace(["  'lengthFrames' => 24,", "    ['type' => 'text', 'id' => 'name',"], ["  'lengthFrames' => 24,\n  'restFrame' => 0,",
        "    ['type' => 'image', 'id' => 'wisp-art', 'asset' => 'Graphics/Summons/Wisp.png', 'sheet' => ['columns' => 2, 'rows' => 1],\n"
        . "      'keyframes' => [['frame' => 0, 'sourceFrame' => 0]]],\n    ['type' => 'text', 'id' => 'name',"], (string) file_get_contents($file)));
    mkdir($root . '/assets/Graphics/Summons', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Summons/Wisp.png', 16, 8);
    $session = EditorSession::open($root);
    $art = fn(): array => array_find($session->describeCutsceneTimeline('cutscenes/summon', 0)['tracks'], static fn(array $track): bool => $track['id'] === 'wisp-art')['art'];

    // Unset reads as the default it falls back to, in brackets.
    expect($art()['fit'])->toBe('(stretch)')
        ->and($art()['fitOptions'])->toBe(['stretch', 'contain']);
    $session->applyDatabaseRecord('cutscenes/summon', 0, $art()['fitKey'], 'contain');
    expect($art()['fit'])->toBe('contain');
    $session->undo();
    expect($art()['fit'])->toBe('(stretch)');
    $session->redo();
    $session->saveAll();

    $written = (string) file_get_contents($file);
    expect($written)->toContain('// The wisp itself.', "'keyframes' => [['frame' => 0, 'sourceFrame' => 0]],\n      'fit' => 'contain'],")
        ->and($session->listUnsavedChanges())->toBe([]);
    $session->applyDatabaseRecord('cutscenes/summon', 0, $art()['fitKey'], '');
    $session->saveAll();
    expect((string) file_get_contents($file))->not->toContain("'fit'")->toContain('// The wisp itself.');
});

it('previews a cinematic as the terminal plays it, and plays, steps, restarts and stops it, writing nothing', function () {
    $root = cutsceneProject();
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    $index = array_search('harbour-lanterns', array_map(strval(...), $session->listDatabaseRecords('cutscenes/cinematic')['records']), true);
    $index = $index === false ? 0 : $index;

    $started = $session->startCinematicPreview($index, 60, 16);
    expect($started)->toMatchArray(['id' => 'harbour-lanterns', 'playing' => false])
        ->and($started['lines'])->not->toBe([])
        ->and($started['failure'])->toBeNull();

    $session->controlCinematicPreview('play');
    $played = $session->controlCinematicPreview('tick', 0.5);
    expect($played['elapsed'])->toBeGreaterThan(0.0);
    $session->controlCinematicPreview('pause');
    $paused = $session->controlCinematicPreview('tick', 0.5);
    expect($paused['elapsed'])->toBe($played['elapsed'])
        ->and($session->controlCinematicPreview('step')['elapsed'])->toBeGreaterThan($played['elapsed'])
        // Asked to keep it, an unchanged cinematic carries on; an edited one starts again.
        ->and($session->startCinematicPreview($index, 60, 16, keep: true)['elapsed'])->toBeGreaterThan($played['elapsed'])
        ->and($session->controlCinematicPreview('restart')['elapsed'])->toBe(0.0)
        ->and(fn() => $session->controlCinematicPreview('rewind'))->toThrow(SessionRefusal::class)
        ->and($session->controlCinematicPreview('step')['elapsed'])->toBeGreaterThan(0.0);
    $name = array_find($session->readDatabaseRecord('cutscenes/cinematic', $index)['rows'], static fn(array $row): bool => trim($row['label']) === 'Name');
    $session->applyDatabaseRecord('cutscenes/cinematic', $index, $name['key'], 'Harbour Lanterns Renamed');
    expect($session->startCinematicPreview($index, 60, 16, keep: true)['elapsed'])->toBe(0.0)
        ->and($session->stopCinematicPreview())->toBe(['stopped' => true])
        ->and(fn() => $session->controlCinematicPreview('play'))->toThrow(SessionRefusal::class)
        ->and(fn() => $session->startCinematicPreview(9, 60, 16))->toThrow(SessionRefusal::class);
    $session->undo();
    expect(sourceHashTree($root))->toBe($before);
});
