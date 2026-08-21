<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Editor\Playtest\PlaytestOverlay;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\UI\CutscenesScreen;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * The opening-structure fixture, built entirely through the editor: a wide
 * staged map, a detached camera, three kites crossing in formation while the
 * camera pans, music and narration during the motion, a signal flare, a
 * title card, a transfer at dawn, cleanup and a finalizer that lands every
 * skip on the same final state. Original content; no file is written by
 * hand.
 */
it('authors an original opening cinematic end to end through the editor, previews it in the Engine, saves, reopens, validates and completes exactly once', function () {
    $root = cutsceneProject();
    writeOpeningMaps($root);
    mkdir($root . '/assets/Audio/BGM', 0o777, true);
    file_put_contents($root . '/assets/Audio/BGM/dawn-theme.ogg', '');
    $editor = cutscenesEditor($root, 170, 52);
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_LIST);

    // 1. Create the cinematic and name it while it is still unsaved.
    pressKeys($editor, 'A');
    selectCutsceneField($editor, 'id');
    pressKeys($editor, "\n");
    setEditorProperty($editor, 'databaseEditBuffer', '');
    setEditorProperty($editor, 'databaseEditCursorIndex', 0);
    typeText($editor, 'dawn-crossing');
    pressKeys($editor, "\n");
    $asset = libraryOf($editor)->find(CutsceneType::CINEMATIC, 'dawn-crossing');
    expect($asset)->not->toBeNull()
        ->and(libraryOf($editor)->find(CutsceneType::CINEMATIC, 'new-cinematic'))->toBeNull();

    setCutsceneField($editor, 'name', 'Dawn Crossing');
    setCutsceneField($editor, 'description', 'Three signal kites cross the night plain in formation and reach the dawn field.');
    setCutsceneField($editor, 'version', '1');

    // 2. Choose the map; hide the field until the fade-in; allow an authored skip.
    setCutsceneField($editor, 'startMap', 'skyfield-night');
    setCutsceneField($editor, 'presentation.initial', 'hidden');
    setCutsceneField($editor, 'skip.policy', 'authored');
    setCutsceneField($editor, 'checkpoints', 'formation-crossed, dawn-arrival');

    // 3. & 4. Declare three staged kites and place them on the map.
    foreach ([['kite-red', '/R\\', 4, 3], ['kite-blue', '/B\\', 4, 6], ['kite-gold', '/G\\', 4, 9]] as $position => [$id, $sprite, $x, $y]) {
        selectCutsceneField($editor, $position === 0 ? 'authoring' : 'cast' . ($position - 1) . 'Y');
        pressKeys($editor, 'O');
        setCutsceneField($editor, 'cast' . $position . 'Id', $id);
        setCutsceneField($editor, 'cast' . $position . 'Sprite', $sprite);
        setCutsceneField($editor, 'cast' . $position . 'X', (string) $x);
        setCutsceneField($editor, 'cast' . $position . 'Y', (string) $y);
    }

    expect(array_column($asset->data()['cast'], 'id'))->toBe(['kite-red', 'kite-blue', 'kite-gold']);

    // 5.–10. The script: fade in, detach the camera, music, then a parallel
    // block with formation movement, a camera pan and narration; a flare; a
    // checkpoint; a title card; the transfer at dawn.
    openCutsceneFrame($editor, 'commandListCommands');
    pressKeys($editor, 'O');
    setCutsceneField($editor, 'command0Type', 'transition');
    setCutsceneField($editor, 'command0Style', 'fade');
    setCutsceneField($editor, 'command0Direction', 'in');
    setCutsceneField($editor, 'command0Seconds', '0.3');

    addCutsceneAfter($editor, 'command0Seconds');
    setCutsceneField($editor, 'command1Type', 'camera');
    setCutsceneField($editor, 'command1Operation', 'detach');

    addCutsceneAfter($editor, 'command1Operation');
    setCutsceneField($editor, 'command2Type', 'cinematic_music');
    setCutsceneField($editor, 'command2Track', 'dawn-theme');
    setCutsceneField($editor, 'command2Loop', 'true');
    setCutsceneField($editor, 'command2CompletionBehavior', 'continue');

    addCutsceneAfter($editor, 'command2CompletionBehavior');
    setCutsceneField($editor, 'command3Type', 'parallel');
    // Shift+O on a parallel block's row adds a lane; three lanes, one per concurrent thread.
    selectCutsceneField($editor, 'command3Type');
    pressKeys($editor, 'O', 'O', 'O');
    setCutsceneField($editor, 'command3Lane0Id', 'formation');
    setCutsceneField($editor, 'command3Lane1Id', 'camera');
    setCutsceneField($editor, 'command3Lane2Id', 'words');
    expect(count($asset->commands()[3]['lanes']))->toBe(3);

    // Lane "formation": three routes, one per kite, run as their own parallel block.
    openCutsceneFrame($editor, 'command3Lane0Commands');
    setCutsceneField($editor, 'command0Type', 'parallel');
    selectCutsceneField($editor, 'command0Type');
    pressKeys($editor, 'O', 'O', 'O');

    foreach (['kite-red', 'kite-blue', 'kite-gold'] as $lane => $kite) {
        setCutsceneField($editor, 'command0Lane' . $lane . 'Id', $kite);
        openCutsceneFrame($editor, 'command0Lane' . $lane . 'Commands');
        setCutsceneField($editor, 'command0Type', 'move_route');
        setCutsceneField($editor, 'command0Subject', 'staged_actor');
        setCutsceneField($editor, 'command0ActorId', $kite);
        setCutsceneField($editor, 'command0SecondsPerStep', '0.1');
        selectCutsceneField($editor, 'command0ActorId');
        pressKeys($editor, 'O');
        setCutsceneField($editor, 'command0Step0Direction', 'right');
        setCutsceneField($editor, 'command0Step0Count', '6');
        pressKeys($editor, "\033");
    }

    pressKeys($editor, "\033");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe(['commands']);

    // Lane "camera": pan to the watcher over the crossing.
    openCutsceneFrame($editor, 'command3Lane1Commands');
    setCutsceneField($editor, 'command0Type', 'camera');
    setCutsceneField($editor, 'command0Operation', 'pan');
    setCutsceneField($editor, 'command0Targetkind', 'position');
    setCutsceneField($editor, 'command0Targetx', '12');
    setCutsceneField($editor, 'command0Targety', '6');
    setCutsceneField($editor, 'command0Seconds', '0.6');
    pressKeys($editor, "\033");

    // Lane "words": narration while the kites move.
    openCutsceneFrame($editor, 'command3Lane2Commands');
    setCutsceneField($editor, 'command0Type', 'narration');
    setCutsceneField($editor, 'command0Title', 'Before dawn');
    setCutsceneField($editor, 'command0Text', "Three kites rose over the plain.\nThe watcher counted them.");
    setCutsceneField($editor, 'command0Seconds', '0.5');
    pressKeys($editor, "\033");

    // After the parallel block: the flare, a checkpoint, the title, the transfer.
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_TREE);
    $rows = callEditorMethod($editor, 'visibleCutsceneTreeRows');
    setEditorProperty($editor, 'cutsceneTreeCursor', array_search('commands.3', array_column($rows, 'key'), true));
    pressKeys($editor, 'O');
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_SETTINGS);
    setEditorProperty($editor, 'databaseCommandFramePath', ['commands']);
    setCutsceneField($editor, 'command4Type', 'field_animation');
    setCutsceneField($editor, 'command4Animation', 'Slash');
    setCutsceneField($editor, 'command4Targetkind', 'staged_actor');
    setCutsceneField($editor, 'command4Targetid', 'kite-gold');
    setCutsceneField($editor, 'command4SecondsPerFrame', '0.05');

    addCutsceneAfter($editor, 'command4SecondsPerFrame');
    setCutsceneField($editor, 'command5Type', 'checkpoint');
    setCutsceneField($editor, 'command5Name', 'formation-crossed');

    addCutsceneAfter($editor, 'command5Name');
    setCutsceneField($editor, 'command6Type', 'title_card');
    setCutsceneField($editor, 'command6Title', 'DAWN CROSSING');
    setCutsceneField($editor, 'command6Seconds', '0.3');

    addCutsceneAfter($editor, 'command6Seconds');
    setCutsceneField($editor, 'command7Type', 'transfer');
    setCutsceneField($editor, 'command7Map', 'skyfield-dawn');
    setCutsceneField($editor, 'command7X', '4');
    setCutsceneField($editor, 'command7Y', '4');

    addCutsceneAfter($editor, 'command7Y');
    setCutsceneField($editor, 'command8Type', 'checkpoint');
    setCutsceneField($editor, 'command8Name', 'dawn-arrival');
    pressKeys($editor, "\033");

    // 11. Cleanup and the finalizer: the same final state by either road.
    openCutsceneFrame($editor, 'commandListFinalizer');
    pressKeys($editor, 'O');
    $finalizer = [
        ['type' => 'move_player', 'x' => '4', 'y' => '4'],
        ['type' => 'transfer', 'map' => 'skyfield-dawn', 'x' => '4', 'y' => '4'],
        ['type' => 'camera', 'operation' => 'attach'],
        ['type' => 'remove_actor', 'actorId' => 'kite-red'],
        ['type' => 'remove_actor', 'actorId' => 'kite-blue'],
        ['type' => 'remove_actor', 'actorId' => 'kite-gold'],
        ['type' => 'clear_presentation'],
        ['type' => 'cinematic_music', 'track' => 'dawn-theme', 'loop' => 'true', 'completionBehavior' => 'stop'],
        ['type' => 'set_switch', 'name' => 'dawn_crossing_done', 'value' => 'true'],
        ['type' => 'record_event', 'name' => 'dawn_crossing_finalized'],
    ];

    foreach ($finalizer as $index => $command) {
        if ($index > 0) {
            $previousIds = cutsceneFieldIds($editor);
            $last = null;

            foreach ($previousIds as $id) {
                if (str_starts_with($id, 'final' . ($index - 1))) {
                    $last = $id;
                }
            }

            addCutsceneAfter($editor, (string) $last);
        }

        foreach ($command as $key => $value) {
            setCutsceneField($editor, 'final' . $index . ucfirst($key), $value);
        }
    }

    pressKeys($editor, "\033");
    expect(array_column($asset->payload()['finalizer'], 'type'))->toBe(array_column($finalizer, 'type'))
        ->and($asset->payload()['finalizer'][8]['value'])->toBeTrue();

    // The Engine reads it as it stands, unsaved.
    $definition = $asset->cinematicDefinition();
    expect($definition->skipPolicy)->toBe('authored')
        ->and(count($definition->cast))->toBe(3);

    // 12. Preview in the Engine: three kites and the camera move while narration shows.
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);
    pressKeys($editor, ' ');
    $preview = getEditorProperty($editor, 'cinematicPreview');
    expect($preview)->toBeInstanceOf(CinematicPreviewSession::class);
    pressKeys($editor, ' ');

    for ($tick = 0; $tick < 6; $tick++) {
        pressKeys($editor, '.');
    }

    $lanes = $preview->lanes();
    $laneKeys = array_column($lanes, 'key');
    expect($laneKeys)->toContain('commands.3.lanes.0.0.lanes.0.0')
        ->and($laneKeys)->toContain('commands.3.lanes.1.0')
        ->and($laneKeys)->toContain('commands.3.lanes.2.0')
        ->and(implode("\n", $preview->frame()))->toContain('Three kites rose');
    $snapshotMidway = $preview->snapshot();
    expect($snapshotMidway->values['staged actors']['kite-red']['x'])->toBeGreaterThan(4)
        ->and($snapshotMidway->values['camera']['followsPlayer'])->toBeFalse();

    expect($preview->runToCompletion(60.0))->toBeTrue()
        ->and($preview->status())->toBe(CinematicPreviewSession::STATUS_COMPLETED)
        ->and($preview->checkpoints())->toBe(['formation-crossed', 'dawn-arrival']);
    $final = $preview->snapshot();
    expect($final->values['map'])->toBe('skyfield-dawn')
        ->and($final->values['player position'])->toBe([4, 4])
        ->and($final->values['staged actors'])->toBe([])
        ->and($final->values['camera']['followsPlayer'])->toBeTrue()
        ->and($final->values['switch dawn_crossing_done'])->toBeTrue()
        ->and($final->values['story events'])->toContain('cinematic:dawn-crossing:completed')
        ->and(array_count_values($final->values['story events'])['cinematic:dawn-crossing:completed'])->toBe(1);

    // 13. Save: both files land, the Engine reads the pair from disk.
    pressKeys($editor, "\x13");
    $folder = $root . '/assets/Cutscenes/Cinematics/dawn-crossing';
    expect(is_file($folder . '/dawn-crossing.data.php'))->toBeTrue()
        ->and(is_file($folder . '/dawn-crossing.script.php'))->toBeTrue()
        ->and($asset->isDirty())->toBeFalse();

    // 14. Close and reopen in a fresh editor.
    pressKeys($editor, "\033OS");
    $reopened = cutscenesEditor($root, 170, 52);
    $again = libraryOf($reopened)->find(CutsceneType::CINEMATIC, 'dawn-crossing');
    expect($again)->not->toBeNull()
        ->and($again->payload()['commands'])->toBe($asset->payload()['commands'])
        ->and($again->data()['cast'])->toBe($asset->data()['cast']);

    // 15. Validate the project: no cutscene findings.
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $cutsceneIssues = array_values(array_filter($issues, static fn(Issue $issue): bool => str_starts_with($issue->where, 'cinematic ')));
    expect(array_map(static fn(Issue $issue): string => $issue->message, $cutsceneIssues))->toBe([]);

    // 16. The playtest route: an overlay whose start map launches the cinematic on arrival.
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn ['party' => ['members' => ['Kaelion']], 'startingPositions' => ['player' => ['destinationMap' => 'harbour', 'spawnPoint' => ['x' => 1, 'y' => 1], 'spawnSprite' => ['^']]]];\n");
    $overlay = PlaytestOverlay::createForCinematic($root, 'skyfield-night', 2, 2, 'dawn-crossing');

    try {
        $mapData = require $overlay->root . '/assets/Maps/skyfield-night/skyfield-night.data.php';
        $eventLayer = require $overlay->root . '/assets/Maps/skyfield-night/skyfield-night.event.php';
        $triggers = array_filter($mapData['events'], static fn(array $event): bool => str_contains($event['class'], 'CinematicEventTrigger'));
        $marker = array_key_first($triggers);
        expect($triggers)->toHaveCount(1)
            ->and($triggers[$marker]['data'])->toBe(['cinematicId' => 'dawn-crossing', 'mode' => 'auto', 'reusable' => false])
            ->and(explode("\n", $eventLayer)[2][2])->toBe($marker)
            ->and(is_link($overlay->root . '/assets/Maps/skyfield-night/skyfield-night.map.php'))->toBeTrue()
            ->and(is_link($overlay->root . '/assets/Maps/skyfield-dawn'))->toBeTrue()
            ->and(is_link($overlay->root . '/assets/Cutscenes'))->toBeTrue();
        // The real project's map is untouched.
        expect(file_get_contents($root . '/assets/Maps/skyfield-night/skyfield-night.event.php'))->not->toContain($marker === ' ' ? 'never' : $marker . ' ');
    } finally {
        $overlay->destroy();
    }

    // 17. & 18. Exactly-once completion, and the session released so a save may follow.
    $played = CinematicPreviewSession::start($root, $again->cinematicDefinition(), ['mapId' => 'skyfield-night', 'x' => 2, 'y' => 2, 'autoAdvance' => true]);

    try {
        $played->runToCompletion(60.0);
        $events = array_count_values($played->snapshot()->values['story events']);
        expect($events['cinematic:dawn-crossing:completed'])->toBe(1)
            ->and($events['dawn_crossing_finalized'])->toBe(1)
            ->and($played->hasActiveSession())->toBeFalse()
            ->and($played->snapshot()->values['save available'])->toBeTrue();
    } finally {
        $played->dispose();
    }

    // Skips before movement, during movement, during narration and after the
    // transfer all reach the same required final state.
    $watched = CinematicPreviewSession::start($root, $again->cinematicDefinition(), ['mapId' => 'skyfield-night', 'x' => 2, 'y' => 2, 'autoAdvance' => true]);
    $watched->runToCompletion(60.0);
    $reference = $watched->snapshot();
    $watched->dispose();
    $required = ['map', 'player position', 'camera', 'staged actors', 'switch dawn_crossing_done', 'status'];

    foreach ([0.0, 0.3, 0.7, 'after_transfer'] as $moment) {
        $skipped = CinematicPreviewSession::start($root, $again->cinematicDefinition(), ['mapId' => 'skyfield-night', 'x' => 2, 'y' => 2, 'autoAdvance' => true]);

        try {
            if ($moment === 'after_transfer') {
                while (! $skipped->isFinished() && $skipped->snapshot()->values['map'] !== 'skyfield-dawn') {
                    $skipped->step();
                }
            } else {
                while (! $skipped->isFinished() && $skipped->elapsed() < $moment) {
                    $skipped->step();
                }
            }

            expect($skipped->skip())->toBeTrue("skip at {$moment}");
            $skipped->runToCompletion(60.0);
            $state = $skipped->snapshot();
            expect($state->values['status'])->toBe(CinematicPreviewSession::STATUS_COMPLETED, "status at {$moment}");

            foreach ($required as $label) {
                expect($state->values[$label])->toBe($reference->values[$label], "{$label} at {$moment}");
            }

            expect(array_count_values($state->values['story events'])['cinematic:dawn-crossing:completed'])->toBe(1)
                ->and(array_count_values($state->values['story events'])['dawn_crossing_finalized'])->toBe(1);
        } finally {
            $skipped->dispose();
        }
    }
});
