<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Preview\SummonPreviewSession;
use Ichiloto\Editor\Database\SummonAssignmentDiagnostics;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\UI\CutscenesScreen;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * Production verification over disposable copies of Last Legend: first that
 * opening, compiling and previewing every existing summon writes nothing;
 * then that a new summon can be authored end to end without touching any
 * unrelated file. The real checkout is never written.
 */
it('opens, compiles and previews every Last Legend summon read-only, leaving every hash unchanged', function () {
    $root = disposableLastLegend();

    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }

    $before = authoredHashTree($root);
    $editor = cutscenesEditor($root, 160, 50);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::SUMMON);
    $library = libraryOf($editor);
    $ids = $library->ids(CutsceneType::SUMMON);
    expect(count($ids))->toBeGreaterThanOrEqual(4)
        ->and($library->issues(CutsceneType::SUMMON))->toBe([]);

    $report = [];

    foreach ($library->assets(CutsceneType::SUMMON) as $asset) {
        expect($asset->isEditable())->toBeTrue($asset->id . ' is editable')
            ->and($asset->isDirty())->toBeFalse();
        $compiled = $asset->compiledSummon();
        $preview = new SummonPreviewSession($compiled);
        $preview->play();

        for ($tick = 0; $tick < 5000 && ! $preview->isCompleted(); $tick++) {
            $preview->tick($preview->secondsPerFrame());
        }

        expect($preview->isCompleted())->toBeTrue($asset->id . ' completes')
            ->and($preview->frame(60, 20))->toHaveCount(20);
        $report[$asset->id] = ['frames' => $preview->totalFrames(), 'fps' => $preview->fps(), 'cues' => array_column($preview->cueLog(), 'id'), 'tracks' => count($asset->payload()['tracks'] ?? [])];
    }

    // Every summon is walked on screen too: the pane opens each and its
    // rows and preview draw without writing.
    foreach ($ids as $position => $id) {
        callEditorMethod($editor, 'selectCutsceneById', $id);
        setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);
        pressKeys($editor, ' ', ' ', '.');
        expect(getEditorProperty($editor, 'summonPreview'))->not->toBeNull();
        pressKeys($editor, 'x');
    }

    pressKeys($editor, "\033OS");
    expect($library->hasUnsavedChanges())->toBeFalse()
        ->and(authoredHashTree($root))->toBe($before);
    fwrite(STDERR, "\nLast Legend summons (read-only): " . json_encode($report) . "\n");
});

it('merges and splits every committed Last Legend pair exactly, and a no-op save writes nothing', function () {
    $root = disposableLastLegend();

    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }

    $before = authoredHashTree($root);
    $library = \Ichiloto\Editor\Cutscenes\CutsceneLibrary::fromProject($root);
    $pairs = 0;

    foreach (CutsceneType::cases() as $type) {
        foreach ($library->assets($type) as $asset) {
            $pairs++;
            // Editable means the editor proved it can read this pair into one
            // record and write that record back as the same two files.
            expect($asset->isEditable())->toBeTrue($asset->id . ' is editable')
                ->and($asset->readOnlyReason())->toBeNull();

            // Applying its own payload changes nothing, and neither does
            // saving: a pair nobody edited is a pair nobody rewrites.
            $asset->apply($asset->payload());
            expect($asset->isDirty())->toBeFalse($asset->id . ' stays clean through a round trip')
                ->and($asset->save())->toBeFalse($asset->id . ' writes nothing when clean');
        }
    }

    expect($pairs)->toBeGreaterThanOrEqual(4)
        ->and(authoredHashTree($root))->toBe($before);
});

it('authors a new summon in a disposable Last Legend copy end to end, with unrelated hashes unchanged', function () {
    $root = disposableLastLegend();

    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }

    $before = authoredHashTree($root);
    $editor = cutscenesEditor($root, 170, 52);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::SUMMON);
    $workspace = getEditorProperty($editor, 'workspace');
    $actor = $workspace->actorDatabase->getActors()[0];
    $actorName = $actor->getName();
    $skill = $workspace->skillDatabase->getSkills()[0]->getName();

    // 1.–2. Create, name, link a real battle action.
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_LIST);
    pressKeys($editor, 'A');
    selectCutsceneField($editor, 'id');
    pressKeys($editor, "\n");
    setEditorProperty($editor, 'databaseEditBuffer', '');
    setEditorProperty($editor, 'databaseEditCursorIndex', 0);
    typeText($editor, 'ember-moth');
    pressKeys($editor, "\n");
    $asset = libraryOf($editor)->find(CutsceneType::SUMMON, 'ember-moth');
    expect($asset)->not->toBeNull();
    setCutsceneField($editor, 'name', 'Ember Moth');
    setCutsceneField($editor, 'description', 'A moth of embers circles the field once.');
    setCutsceneField($editor, 'moveName', 'Cinder Circuit');
    setCutsceneField($editor, 'linkedActionId', $skill);

    // 8.–9. Availability deliberately omitted; wielders by character, shared.
    setCutsceneField($editor, 'wielders.mode', 'characters');
    setCutsceneField($editor, 'wielders.characters', $actorName);
    setCutsceneField($editor, 'wielders.tenancy', 'shared');
    setCutsceneField($editor, 'fps', '12');
    setCutsceneField($editor, 'lengthFrames', '36');

    // 3.–5. Two tracks, multiline ASCII, several keyframes.
    openCutsceneFrame($editor, 'commandListTracks');
    pressKeys($editor, 'O');
    setCutsceneField($editor, 'track0Id', 'moth');
    setCutsceneField($editor, 'track0Type', 'glyph');
    selectCutsceneField($editor, 'track0Type');
    pressKeys($editor, 'O');
    $art = " .-.\n( o )\n '-'";
    setCutsceneField($editor, 'track0Keyframe0Frame', '0');
    setCutsceneField($editor, 'track0Keyframe0Duration', '12');
    setCutsceneField($editor, 'track0Keyframe0Content', $art);
    setCutsceneField($editor, 'track0Keyframe0Position', '6, 4');
    setCutsceneField($editor, 'track0Keyframe0Color', 'red');
    selectCutsceneField($editor, 'track0Keyframe0Color');
    pressKeys($editor, 'O');
    setCutsceneField($editor, 'track0Keyframe1Frame', '12');
    setCutsceneField($editor, 'track0Keyframe1Duration', '12');
    setCutsceneField($editor, 'track0Keyframe1Content', $art);
    setCutsceneField($editor, 'track0Keyframe1Position', '14, 3');
    selectCutsceneField($editor, 'track0Keyframe1Color');
    pressKeys($editor, 'O');
    setCutsceneField($editor, 'track0Keyframe2Frame', '24');
    setCutsceneField($editor, 'track0Keyframe2Duration', '12');
    setCutsceneField($editor, 'track0Keyframe2Content', $art);
    setCutsceneField($editor, 'track0Keyframe2Position', '22, 4');

    selectCutsceneField($editor, 'track0Keyframe2Position');
    pressKeys($editor, "\033");
    openCutsceneFrame($editor, 'commandListTracks');
    // Shift+O on a track that already has keyframes adds the next track.
    selectCutsceneField($editor, 'track0Type');
    pressKeys($editor, 'O');
    setCutsceneField($editor, 'track1Id', 'caption');
    setCutsceneField($editor, 'track1Type', 'text');
    selectCutsceneField($editor, 'track1Type');
    pressKeys($editor, 'O');
    setCutsceneField($editor, 'track1Keyframe0Frame', '2');
    setCutsceneField($editor, 'track1Keyframe0Duration', '30');
    setCutsceneField($editor, 'track1Keyframe0Content', 'EMBER MOTH');
    setCutsceneField($editor, 'track1Keyframe0Position', '4, 1');
    pressKeys($editor, "\033");

    // 6.–7. A cue, and effect timing bound to it.
    openCutsceneFrame($editor, 'commandListCues');
    pressKeys($editor, 'O');
    setCutsceneField($editor, 'cue0Id', 'strike');
    setCutsceneField($editor, 'cue0Frame', '24');
    setCutsceneField($editor, 'cue0Type', 'applyEffect');
    pressKeys($editor, "\033");
    setCutsceneField($editor, 'effectTiming.mode', 'cue');
    setCutsceneField($editor, 'effectTiming.cueId', 'strike');

    $payload = $asset->payload();
    expect(array_column($payload['tracks'], 'id'))->toBe(['moth', 'caption'])
        ->and(count($payload['tracks'][0]['keyframes']))->toBe(3)
        ->and($payload['tracks'][0]['keyframes'][1]['content'])->toBe($art)
        ->and($payload['cues'][0]['id'])->toBe('strike')
        ->and(array_key_exists('availability', $asset->data()))->toBeFalse();

    // 11. Preview through the Engine session: the cue fires once at 24.
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);
    pressKeys($editor, ' ');
    $preview = getEditorProperty($editor, 'summonPreview');
    expect($preview)->toBeInstanceOf(SummonPreviewSession::class);

    for ($tick = 0; $tick < 200 && ! $preview->isCompleted(); $tick++) {
        $preview->tick($preview->secondsPerFrame());
    }

    expect($preview->isCompleted())->toBeTrue()
        ->and(array_column($preview->cueLog(), 'id'))->toBe(['strike'])
        ->and(implode("\n", $preview->frame(40, 10, 13)))->toContain('( o )');

    // 12. Save the summon.
    pressKeys($editor, "\x13");
    $folder = $root . '/assets/Cutscenes/Summons/ember-moth';
    expect(is_file($folder . '/ember-moth.data.php'))->toBeTrue()
        ->and(is_file($folder . '/ember-moth.timeline.php'))->toBeTrue()
        ->and($asset->isDirty())->toBeFalse();

    // 10. Assign it to the eligible actor from the actor pane, and save that one actor.
    pressKeys($editor, "\033OS");
    openDatabaseCategory($editor, 'actors');
    $summonsField = null;

    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $field) {
        if (($field['field'] ?? null) === 'summons') {
            $summonsField = $field;
        }
    }

    expect($summonsField)->not->toBeNull();
    $current = array_values(array_filter(array_map(trim(...), explode(',', (string) $summonsField['value']))));
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $summonsField, implode(', ', [...$current, 'ember-moth']));
    expect(in_array('ember-moth', $actor->getSummons(), true))->toBeTrue();
    $labels = implode("\n", array_map(static fn(array $field): string => (string) ($field['label'] ?? ''), callEditorMethod($editor, 'getDatabaseSettingsFields')));
    expect($labels)->toContain('✓ ember-moth');
    $actor->save();

    // 13.–15. Reopen, validate, compile.
    $reopened = ProjectWorkspace::fromProject($root);
    $again = $reopened->cutscenes->find(CutsceneType::SUMMON, 'ember-moth');
    expect($again)->not->toBeNull()
        ->and($again->payload()['tracks'])->toBe($asset->payload()['tracks'])
        ->and($again->compiledSummon()->fps)->toBe(12);
    $issues = new ProjectValidator()->validate($reopened);
    $mine = array_values(array_filter($issues, static fn(Issue $issue): bool => str_contains($issue->where . ' ' . $issue->message, 'ember-moth')));
    expect(array_map(static fn(Issue $issue): string => $issue->where . ': ' . $issue->message, $mine))->toBe([]);
    $diagnostics = SummonAssignmentDiagnostics::fromLibrary($reopened->cutscenes);
    $rows = $diagnostics->forActor($actorName, $actor->getClassName(), $reopened->actorDatabase->getActors()[0]->getSummons());
    $mothRow = array_values(array_filter($rows, static fn(array $row): bool => $row['id'] === 'ember-moth'))[0];
    expect($mothRow['problems'])->toBe([]);

    // 17. Only the new summon folder and the one actor file changed.
    $after = authoredHashTree($root);
    $changed = [];

    foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $path) {
        if (($before[$path] ?? null) !== ($after[$path] ?? null)) {
            $changed[] = $path;
        }
    }

    sort($changed);
    expect($changed)->toBe([
        'Cutscenes/Summons/ember-moth/ember-moth.data.php',
        'Cutscenes/Summons/ember-moth/ember-moth.timeline.php',
        'Data/Actors/' . basename($actor->path),
    ]);
    // The actor file changed only by its summons list.
    $actorSource = file_get_contents($actor->path);
    expect($actorSource)->toContain("'ember-moth'");
});
