<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\UI\CutscenesScreen;
use Ichiloto\Editor\UI\Modal;

/**
 * The Cutscenes workspace: a first-class screen, opened by its own key,
 * over the project's cinematics and summons -- and the record pane it
 * hosts, where every edit is undoable, dirty by content, and written back
 * into paired files without touching a byte it did not change.
 */

it('opens with F4 as its own modal, toggles closed, and never stacks on the Database', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);

    expect(getEditorProperty($editor, 'modals')->active())->toBe(Modal::CUTSCENES)
        ->and(getEditorProperty($editor, 'isDatabaseOpen'))->toBeFalse()
        ->and(getEditorProperty($editor, 'cutsceneType'))->toBe(CutsceneType::CINEMATIC);

    // F4 again closes it; the other F4 encoding opens it too.
    pressKeys($editor, "\033OS");
    expect(getEditorProperty($editor, 'modals')->has(Modal::CUTSCENES))->toBeFalse();
    pressKeys($editor, "\033[14~");
    expect(getEditorProperty($editor, 'modals')->active())->toBe(Modal::CUTSCENES);

    // Ctrl+D from inside goes straight across to the Database, one screen
    // at a time; F4 from the Database comes straight back.
    pressKeys($editor, "\x04");
    expect(getEditorProperty($editor, 'isDatabaseOpen'))->toBeTrue()
        ->and(getEditorProperty($editor, 'modals')->has(Modal::CUTSCENES))->toBeFalse();
    pressKeys($editor, "\033OS");
    expect(getEditorProperty($editor, 'modals')->active())->toBe(Modal::CUTSCENES)
        ->and(getEditorProperty($editor, 'isDatabaseOpen'))->toBeFalse();
});

it('derives its binding for the help overlay and offers both types in the palette', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);
    $bindings = getEditorProperty($editor, 'inputRouter')->describeBindings();
    $described = array_column($bindings, 'description', 'key');

    expect($described)->toHaveKey('F4')
        ->and($described['F4'])->toContain('Cutscenes');

    $labels = array_map(static fn(object $item): string => $item->label, callEditorMethod($editor, 'buildPaletteItems'));

    expect($labels)->toContain('Cutscenes: Cinematic')
        ->toContain('Cutscenes: Summon');
});

it('lists both types, switches between them, and filters the list', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);

    expect(callEditorMethod($editor, 'cutsceneEntryLabels'))->toBe([0 => 'Harbour Lanterns (harbour-lanterns)']);

    // Tab back to the types pane and move down to Summon.
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_TYPES);
    pressKeys($editor, "\033[B");

    expect(getEditorProperty($editor, 'cutsceneType'))->toBe(CutsceneType::SUMMON)
        ->and(callEditorMethod($editor, 'cutsceneEntryLabels'))->toBe([0 => 'Lantern Wisp (lantern-wisp)']);

    pressKeys($editor, "\033[A");
    expect(getEditorProperty($editor, 'cutsceneType'))->toBe(CutsceneType::CINEMATIC);

    // The filter narrows the list and Esc pops it before the screen.
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_LIST);
    pressKeys($editor, '/');
    typeText($editor, 'zzz');
    expect(callEditorMethod($editor, 'visibleCutsceneIndexes'))->toBe([]);
    pressKeys($editor, "\033");
    expect(getEditorProperty($editor, 'cutsceneFilter')->isActive())->toBeFalse()
        ->and(getEditorProperty($editor, 'modals')->active())->toBe(Modal::CUTSCENES);
});

it("chooses a summon's effect cue from its own timeline", function () {
    $editor = cutscenesEditor(cutsceneProject());
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::SUMMON);
    selectCutsceneField($editor, 'effectTiming.cueId');
    $field = cutsceneField($editor, 'effectTiming.cueId');

    expect($field['reference'] ?? null)->toBe('summon_cues')
        ->and($field)->not->toHaveKey('control');

    pressKeys($editor, "\n");
    $picker = getEditorProperty($editor, 'referencePicker');
    expect($picker->matches())->toBe(['(none)', 'flare'])
        ->and($picker->selected())->toBe('flare');

    pressKeys($editor, "\033[A", "\n");
    $asset = libraryOf($editor)->find(CutsceneType::SUMMON, 'lantern-wisp');
    expect($asset->payload()['effectTiming'])->not->toHaveKey('cueId');

    callEditorMethod($editor, 'performUndo');
    expect($asset->payload()['effectTiming']['cueId'])->toBe('flare');
});

it('shows the cinematic grouped on the record pane, with its script and finalizer as frames and its cast as rows', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);
    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
    $labels = array_map(static fn(array $field): string => (string) $field['label'], $fields);
    $ids = array_map(static fn(array $field): string => (string) ($field['field'] ?? ''), $fields);

    expect($labels)->toContain('Identity', 'Staging', 'Script', 'Skip', 'Metadata', 'Cast')
        ->and($ids)->toContain('name', 'startMap', 'skip.policy', 'checkpoints', 'commandListCommands', 'commandListFinalizer', 'cast0Kind', 'cast0Id', 'cast1Id');

    $commands = array_values(array_filter($fields, static fn(array $field): bool => ($field['field'] ?? '') === 'commandListCommands'))[0];
    expect($commands['value'])->toBe('5')
        ->and($commands['frame'])->toBe(['commands']);
});

it('edits a field through the hosted pane, marks the asset dirty by content, and undoes back to clean', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);
    $asset = libraryOf($editor)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');

    selectCutsceneField($editor, 'name');
    pressKeys($editor, "\n");
    expect(getEditorProperty($editor, 'isDatabaseEditing'))->toBeTrue();
    typeText($editor, ' at Dusk');
    pressKeys($editor, "\n");

    expect($asset->name())->toBe('Harbour Lanterns at Dusk')
        ->and($asset->isDirty())->toBeTrue()
        ->and(libraryOf($editor)->hasUnsavedChanges())->toBeTrue();

    callEditorMethod($editor, 'performUndo');
    expect($asset->name())->toBe('Harbour Lanterns')
        ->and($asset->isDirty())->toBeFalse();

    callEditorMethod($editor, 'performRedo');
    expect($asset->name())->toBe('Harbour Lanterns at Dusk')
        ->and($asset->isDirty())->toBeTrue();

    // A same-value edit makes neither dirt nor history.
    callEditorMethod($editor, 'performUndo');
    $history = getEditorProperty($editor, 'history');
    $depthBefore = count(new ReflectionProperty($history, 'undoStack')->getValue($history) ?? []);
    selectCutsceneField($editor, 'name');
    pressKeys($editor, "\n", "\n");
    expect($asset->isDirty())->toBeFalse()
        ->and(count(new ReflectionProperty($history, 'undoStack')->getValue($history) ?? []))->toBe($depthBefore);
});

it('saves a data edit to the data file alone, byte-minimal, and leaves the script untouched', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);
    $dataPath = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    $scriptPath = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    $scriptBefore = (string) file_get_contents($scriptPath);
    $dataBefore = (string) file_get_contents($dataPath);
    $scriptMtime = filemtime($scriptPath);
    touch($scriptPath, $scriptMtime - 100);
    clearstatcache();
    $scriptStamp = filemtime($scriptPath);

    selectCutsceneField($editor, 'description');
    pressKeys($editor, "\n");
    typeText($editor, ' Nobody speaks.');
    pressKeys($editor, "\n", "\x13");
    clearstatcache();

    expect((string) file_get_contents($dataPath))->toBe(str_replace(
        "'description' => 'Two lantern boats cross the harbour at dusk.',",
        "'description' => 'Two lantern boats cross the harbour at dusk. Nobody speaks.',",
        $dataBefore,
    ))
        ->and((string) file_get_contents($scriptPath))->toBe($scriptBefore)
        ->and(filemtime($scriptPath))->toBe($scriptStamp)
        ->and(libraryOf($editor)->find(CutsceneType::CINEMATIC, 'harbour-lanterns')?->isDirty())->toBeFalse();
});

it('opens the command tree as frames: a parallel lane inside the script, bread-crumbed, with a command added and undone', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);
    $asset = libraryOf($editor)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');

    // Open the Commands frame from its row.
    selectCutsceneField($editor, 'commandListCommands');
    pressKeys($editor, "\n");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe(['commands']);

    $ids = array_map(static fn(array $f): string => (string) ($f['field'] ?? ''), callEditorMethod($editor, 'getDatabaseSettingsFields'));
    expect($ids)->toContain('command0Type', 'command2Type', 'command2Lane0Id', 'command2Lane0Commands', 'command2Lane2Commands');

    // Open the third lane's commands: a frame four steps deep.
    selectCutsceneField($editor, 'command2Lane2Commands');
    pressKeys($editor, "\n");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe(['commands', 2, 'lanes', 2, 'commands'])
        ->and(libraryOf($editor)->records(CutsceneType::CINEMATIC)->describeFramePath(['commands', 2, 'lanes', 2, 'commands']))
            ->toBe('Commands › Parallel 3 › Lane 3');

    $frameIds = array_map(static fn(array $f): string => (string) ($f['field'] ?? ''), callEditorMethod($editor, 'getDatabaseSettingsFields'));
    expect($frameIds)->toContain('command0Type', 'command0Title', 'command0Text', 'command0Seconds');

    // Shift+O adds a command after the cursor's; it lands in the lane.
    selectCutsceneField($editor, 'command0Type');
    pressKeys($editor, 'O');
    $lane = $asset->commands()[2]['lanes'][2]['commands'];
    expect(count($lane))->toBe(2)
        ->and($lane[1]['type'])->toBe('wait')
        ->and($asset->isDirty())->toBeTrue();

    callEditorMethod($editor, 'performUndo');
    expect(count($asset->commands()[2]['lanes'][2]['commands']))->toBe(1)
        ->and($asset->isDirty())->toBeFalse();

    // Esc leaves one frame at a time -- the lane back to the script, the
    // script back to the record -- then the screen. Each time the cursor
    // lands on the row the frame belonged to.
    pressKeys($editor, "\033");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe(['commands'])
        ->and(callEditorMethod($editor, 'getDatabaseSettingsFields')[getEditorProperty($editor, 'databaseSelectedSettingIndex')]['field'])->toBe('command2Type');
    pressKeys($editor, "\033");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe([])
        ->and(callEditorMethod($editor, 'getDatabaseSettingsFields')[getEditorProperty($editor, 'databaseSelectedSettingIndex')]['field'])->toBe('commandListCommands');
    pressKeys($editor, "\033");
    expect(getEditorProperty($editor, 'modals')->has(Modal::CUTSCENES))->toBeFalse();
});

it('edits a multi-line narration in the multiline editor, exactly', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);
    $asset = libraryOf($editor)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');

    selectCutsceneField($editor, 'commandListCommands');
    pressKeys($editor, "\n");
    selectCutsceneField($editor, 'command2Lane2Commands');
    pressKeys($editor, "\n");
    selectCutsceneField($editor, 'command0Text');

    // The row shows the first line and a count; Enter opens the editor.
    $row = callEditorMethod($editor, 'getDatabaseSettingsFields')[getEditorProperty($editor, 'databaseSelectedSettingIndex')];
    expect($row['value'])->toBe('The lanterns were lit one by one. ⏎ 1 more line');

    pressKeys($editor, "\n");
    $multiline = getEditorProperty($editor, 'multilineEditor');
    expect($multiline->isOpen())->toBeTrue()
        ->and($multiline->text())->toBe("The lanterns were lit one by one.\nNobody spoke.");

    // Append a line with trailing spaces and a backslash, then apply.
    pressKeys($editor, "\n");
    typeText($editor, 'Only the water \\ moved.  ');
    pressKeys($editor, "\x13");

    expect($multiline->isOpen())->toBeFalse()
        ->and($asset->commands()[2]['lanes'][2]['commands'][0]['text'])->toBe("The lanterns were lit one by one.\nNobody spoke.\nOnly the water \\ moved.  ")
        ->and($asset->isDirty())->toBeTrue();

    // Esc inside the editor cancels without a change.
    selectCutsceneField($editor, 'command0Text');
    pressKeys($editor, "\n");
    typeText($editor, 'x');
    pressKeys($editor, "\033");
    expect($asset->commands()[2]['lanes'][2]['commands'][0]['text'])->toBe("The lanterns were lit one by one.\nNobody spoke.\nOnly the water \\ moved.  ");

    // Saved, the script carries the text as a nowdoc, exactly, and the
    // data file is untouched.
    $dataPath = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    $dataBefore = (string) file_get_contents($dataPath);
    pressKeys($editor, "\x13");
    $script = (string) file_get_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php');

    expect($script)->toContain("<<<'ART'\nThe lanterns were lit one by one.\nNobody spoke.\nOnly the water \\ moved.  \nART;")
        ->and((string) file_get_contents($dataPath))->toBe($dataBefore)
        ->and($asset->isDirty())->toBeFalse();
    $evaluated = (static fn(): mixed => require $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php')();
    expect($evaluated[2]['lanes'][2]['commands'][0]['text'])->toBe("The lanterns were lit one by one.\nNobody spoke.\nOnly the water \\ moved.  ");
});

it('adds a cast member, reorders nothing it did not touch, and saves the data file alone', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);
    $asset = libraryOf($editor)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    $scriptPath = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    $scriptBefore = (string) file_get_contents($scriptPath);

    selectCutsceneField($editor, 'cast1Id');
    pressKeys($editor, 'O');
    expect(count($asset->data()['cast']))->toBe(3)
        ->and($asset->data()['cast'][2]['kind'])->toBe('staged_actor');

    pressKeys($editor, "\x13");
    $data = (static fn(): mixed => require $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php')();
    expect(count($data['cast']))->toBe(3)
        ->and((string) file_get_contents($scriptPath))->toBe($scriptBefore)
        ->and((string) file_get_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php'))
            ->toContain("// Harbour Lanterns: an original test cinematic.");
});

it('creates a cinematic with Shift+A, saves it as a valid pair, and undoes the creation', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_LIST);

    pressKeys($editor, 'A');
    $library = libraryOf($editor);
    $created = $library->find(CutsceneType::CINEMATIC, 'new-cinematic');

    expect($created)->not->toBeNull()
        ->and($created->isNew())->toBeTrue()
        ->and($created->isDirty())->toBeTrue()
        ->and(is_dir($root . '/assets/Cutscenes/Cinematics/new-cinematic'))->toBeFalse()
        ->and(callEditorMethod($editor, 'selectedCutscene')?->id)->toBe('new-cinematic');

    pressKeys($editor, "\x13");
    $folder = $root . '/assets/Cutscenes/Cinematics/new-cinematic';

    expect(is_file($folder . '/new-cinematic.data.php'))->toBeTrue()
        ->and(is_file($folder . '/new-cinematic.script.php'))->toBeTrue()
        ->and($created->isDirty())->toBeFalse();

    // The engine reads what was written.
    $definition = \Ichiloto\Editor\Cutscenes\CutsceneHydration::cinematic(
        (static fn(): mixed => require $folder . '/new-cinematic.data.php')(),
        (static fn(): mixed => require $folder . '/new-cinematic.script.php')(),
        $root,
    );
    expect($definition->id)->toBe('new-cinematic')
        ->and($definition->name)->toBe('New Cinematic');

    // A fresh library discovers it.
    expect(CutsceneLibrary::fromProject($root)->ids(CutsceneType::CINEMATIC))->toBe(['harbour-lanterns', 'new-cinematic']);
});

it('deletes through the confirmation, restores with undo, and removes the folder on save', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_LIST);
    $folder = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns';

    pressKeys($editor, "\033[3~");
    expect(getEditorProperty($editor, 'isDatabaseEntryDeleteConfirmationOpen'))->toBeTrue();
    pressKeys($editor, 'y');

    $library = libraryOf($editor);
    $asset = $library->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    expect($asset->isDeleted())->toBeTrue()
        ->and($library->ids(CutsceneType::CINEMATIC))->toBe([])
        ->and(is_dir($folder))->toBeTrue();

    callEditorMethod($editor, 'performUndo');
    expect($asset->isDeleted())->toBeFalse()
        ->and($library->ids(CutsceneType::CINEMATIC))->toBe(['harbour-lanterns'])
        ->and($asset->isDirty())->toBeFalse();

    callEditorMethod($editor, 'performRedo');
    pressKeys($editor, "\x13");
    expect(is_dir($folder))->toBeFalse()
        ->and($library->ids(CutsceneType::CINEMATIC))->toBe([]);
});

it('saves every dirty cutscene through Save All and guards quitting while one is dirty', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root);
    $library = libraryOf($editor);

    selectCutsceneField($editor, 'name');
    pressKeys($editor, "\n");
    typeText($editor, '!');
    pressKeys($editor, "\n");
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_TYPES);
    pressKeys($editor, "\033[B");
    selectCutsceneField($editor, 'moveName');
    pressKeys($editor, "\n");
    typeText($editor, '!');
    pressKeys($editor, "\n");

    expect($library->hasUnsavedChanges())->toBeTrue();

    pressKeys($editor, "\x11");
    expect(getEditorProperty($editor, 'modals')->has(Modal::UNSAVED_CHANGES_GUARD))->toBeTrue()
        ->and(getEditorProperty($editor, 'isRunning'))->toBeTrue();
    pressKeys($editor, "\033");

    pressKeys($editor, "\x01");
    expect($library->hasUnsavedChanges())->toBeFalse()
        ->and((static fn(): mixed => require $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php')()['name'])->toBe('Harbour Lanterns!')
        ->and((static fn(): mixed => require $root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.data.php')()['moveName'])->toBe('Wisp Flare!');
});

it('renders at the supported minimum and at a wide terminal, and writes nothing when idle', function (int $width, int $height) {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root, $width, $height);
    $layout = callEditorMethod($editor, 'resolveCutscenesLayout', ['width' => $width, 'height' => $height]);

    // Every pane sits inside the root frame.
    foreach (['types', 'list', 'settings', 'tree', 'preview'] as $pane) {
        expect($layout[$pane . 'X'] + $layout[$pane . 'Width'])->toBeLessThanOrEqual($layout['rootX'] + $layout['rootWidth'], $pane . ' width at ' . $width)
            ->and($layout[$pane . 'Y'] + $layout[$pane . 'Height'])->toBeLessThanOrEqual($layout['rootY'] + $layout['rootHeight'], $pane . ' height at ' . $height);
    }

    ob_start();
    callEditorMethod($editor, 'renderFullScreen');
    $frame = (string) ob_get_clean();
    expect(strlen($frame))->toBeGreaterThan(0)
        ->and($frame)->toContain('Cutscenes');

    // An idle tick writes zero bytes.
    ob_start();
    callEditorMethod($editor, 'flushDirtyPanels');
    expect(ob_get_clean())->toBe('');
})->with([[80, 24], [100, 30], [140, 44], [200, 60]]);

it('reports discovery findings for orphaned and misnamed pairs and keeps editing the rest', function () {
    $root = cutsceneProject();
    mkdir($root . '/assets/Cutscenes/Cinematics/lonely', 0o777, true);
    file_put_contents($root . '/assets/Cutscenes/Cinematics/lonely/lonely.data.php', "<?php\n\nreturn ['id' => 'lonely', 'name' => 'Lonely'];\n");
    mkdir($root . '/assets/Cutscenes/Summons/renamed', 0o777, true);
    file_put_contents($root . '/assets/Cutscenes/Summons/renamed/other.data.php', lanternSummonData());
    file_put_contents($root . '/assets/Cutscenes/Summons/renamed/other.timeline.php', lanternSummonTimeline());
    mkdir($root . '/assets/Cutscenes/Summons/mismatch', 0o777, true);
    file_put_contents($root . '/assets/Cutscenes/Summons/mismatch/mismatch.data.php', str_replace("'id' => 'lantern-wisp'", "'id' => 'someone-else'", lanternSummonData()));
    file_put_contents($root . '/assets/Cutscenes/Summons/mismatch/mismatch.timeline.php', lanternSummonTimeline());

    $library = CutsceneLibrary::fromProject($root);
    $cinematicIssues = implode("\n", array_column($library->issues(CutsceneType::CINEMATIC), 'message'));
    $summonIssues = implode("\n", array_column($library->issues(CutsceneType::SUMMON), 'message'));

    expect($library->ids(CutsceneType::CINEMATIC))->toBe(['harbour-lanterns'])
        ->and($cinematicIssues)->toContain('Folder "lonely" is missing lonely.script.php')
        ->and($summonIssues)->toContain('Folder "renamed" holds other.data.php and other.timeline.php, which does not match the folder name')
        ->and($summonIssues)->toContain('the folder is "mismatch" but the data file declares id "someone-else"')
        ->and($library->find(CutsceneType::SUMMON, 'mismatch')?->isEditable())->toBeFalse()
        ->and($library->ids(CutsceneType::SUMMON))->toBe(['lantern-wisp', 'mismatch']);
});

it('hosts the Engine preview on its pane: plays, steps, marks the running commands, jumps to a failure and compares skips', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root, 160, 50);
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);

    // Space starts the Engine preview, playing.
    pressKeys($editor, ' ');
    $preview = getEditorProperty($editor, 'cinematicPreview');
    expect($preview)->toBeInstanceOf(\Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession::class)
        ->and($preview->isPlaying())->toBeTrue()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('Previewing harbour-lanterns');

    // Space pauses; '.' steps one tick; the tree marks the running command.
    pressKeys($editor, ' ');
    expect($preview->isPlaying())->toBeFalse();
    pressKeys($editor, '.', '.', '.', '.');
    expect($preview->elapsed())->toBeGreaterThan(0.3);
    $frame = renderEditorPlainFrame($editor, 160, 50);
    expect($frame)->toContain('▶');

    // The idle tick advances a playing preview by wall-clock time.
    pressKeys($editor, ' ');
    setEditorProperty($editor, 'cutscenePreviewLastTickAt', microtime(true) - 0.5);
    callEditorMethod($editor, 'tickCutscenePreview');
    expect($preview->elapsed())->toBeGreaterThan(0.8);

    // K skips through the authored finalizer; the run completes.
    getEditorProperty($editor, 'toasts')->clear();
    pressKeys($editor, 'k');
    expect(getEditorProperty($editor, 'statusMessage'))->toContain('Skip accepted');
    setEditorProperty($editor, 'cutscenePreviewLastTickAt', microtime(true) - 0.5);

    for ($tick = 0; $tick < 40 && ! $preview->isFinished(); $tick++) {
        setEditorProperty($editor, 'cutscenePreviewLastTickAt', microtime(true) - 0.3);
        callEditorMethod($editor, 'tickCutscenePreview');
    }

    expect($preview->status())->toBe(\Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession::STATUS_COMPLETED)
        ->and(renderEditorPlainFrame($editor, 160, 50))->toContain('completed');

    // C compares a watched run with a skipped one; the difference is the checkpoint.
    pressKeys($editor, 'c');
    expect(getEditorProperty($editor, 'cutscenePreviewView'))->toBe('compare');
    $comparison = getEditorProperty($editor, 'cutscenePreviewComparison');
    expect(array_column($comparison, 'label'))->toContain('checkpoints')
        ->and(renderEditorPlainFrame($editor, 160, 50))->toContain('watched → skipped');

    // V shows the duration overview; Down and Enter open a row in the tree; L cycles views back to the stage.
    pressKeys($editor, 'v');
    expect(renderEditorPlainFrame($editor, 160, 50))->toContain('Commands ≈');
    pressKeys($editor, "\033[B", "\033[B");
    expect(getEditorProperty($editor, 'cutsceneOverviewCursor'))->toBe(2);
    pressKeys($editor, "\n");
    expect(getEditorProperty($editor, 'cutsceneFocus'))->toBe(CutscenesScreen::PANE_TREE)
        ->and(callEditorMethod($editor, 'visibleCutsceneTreeRows')[getEditorProperty($editor, 'cutsceneTreeCursor')]['key'])->toBe('commands.2');
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);
    setEditorProperty($editor, 'databaseCommandFramePath', []);
    pressKeys($editor, 'l');
    expect(getEditorProperty($editor, 'cutscenePreviewView'))->toBe('compare');
    pressKeys($editor, 'l');
    expect(getEditorProperty($editor, 'cutscenePreviewView'))->toBe('stage');

    // A failing command: the preview fails, J jumps the tree and record pane to it.
    $asset = libraryOf($editor)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    $payload = $asset->payload();
    $payload['commands'][2]['lanes'][0]['commands'][0]['actorId'] = 'nobody';
    $asset->apply($payload);
    pressKeys($editor, 'r');
    $preview = getEditorProperty($editor, 'cinematicPreview');

    for ($tick = 0; $tick < 40 && ! $preview->isFinished(); $tick++) {
        setEditorProperty($editor, 'cutscenePreviewLastTickAt', microtime(true) - 0.3);
        callEditorMethod($editor, 'tickCutscenePreview');
    }

    expect($preview->status())->toBe(\Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession::STATUS_FAILED)
        ->and($preview->failure()['key'])->toBe('commands.2.lanes.0.0');
    pressKeys($editor, 'j');
    expect(getEditorProperty($editor, 'cutsceneFocus'))->toBe(CutscenesScreen::PANE_TREE)
        ->and(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe(['commands', 2, 'lanes', 0, 'commands'])
        ->and(renderEditorPlainFrame($editor, 160, 50))->toContain('✗');

    // X closes a finished preview; closing the screen disposes it either way.
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);
    pressKeys($editor, 'x');
    expect(getEditorProperty($editor, 'cinematicPreview'))->toBeNull();
    pressKeys($editor, ' ');
    expect(getEditorProperty($editor, 'cinematicPreview'))->not->toBeNull();
    // F4 closes the screen outright (Esc would pop the open frame first).
    pressKeys($editor, "\033OS");
    expect(getEditorProperty($editor, 'modals')->has(Modal::CUTSCENES))->toBeFalse()
        ->and(getEditorProperty($editor, 'cinematicPreview'))->toBeNull();
});

it('plays a summon through the Engine playback session: frames, keyframe boundaries, cues and the ruler', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root, 150, 45);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::SUMMON);
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);

    // Space compiles the summon as it stands and starts the Engine session.
    pressKeys($editor, ' ');
    $preview = getEditorProperty($editor, 'summonPreview');
    expect($preview)->toBeInstanceOf(\Ichiloto\Editor\Cutscenes\Preview\SummonPreviewSession::class)
        ->and($preview->isPlaying())->toBeTrue()
        ->and($preview->totalFrames())->toBe(24)
        ->and($preview->fps())->toBe(12);

    // Space pauses; '.' and ',' step; '>' and '<' jump keyframe boundaries.
    pressKeys($editor, ' ');
    expect($preview->isPlaying())->toBeFalse();
    pressKeys($editor, '.', '.', '.');
    expect($preview->currentFrame())->toBe(3);
    pressKeys($editor, ',');
    expect($preview->currentFrame())->toBe(2);
    pressKeys($editor, '>');
    expect($preview->currentFrame())->toBe(12);
    pressKeys($editor, '<');
    expect($preview->currentFrame())->toBe(2);

    // The frame at 12 draws the wisp at its second keyframe and names the cue.
    $preview->seek(12);
    $frame = implode("\n", $preview->frame(40, 10));
    expect($frame)->toContain('( )')
        ->and($frame)->toContain('LANTERN WISP')
        ->and(array_column($preview->cuesAt(), 'id'))->toBe(['flare']);

    // The tree marks the active keyframes and the cue on this frame.
    $screen = renderEditorPlainFrame($editor, 150, 45);
    expect($screen)->toContain('▶')
        ->and($screen)->toContain('f12/24')
        ->and($screen)->toContain('cues');

    // Playing through the idle tick crosses the cue exactly once.
    pressKeys($editor, 'r');
    pressKeys($editor, ' ');

    for ($tick = 0; $tick < 60 && ! $preview->isCompleted(); $tick++) {
        setEditorProperty($editor, 'cutscenePreviewLastTickAt', microtime(true) - 0.2);
        callEditorMethod($editor, 'tickCutscenePreview');
    }

    expect($preview->isCompleted())->toBeTrue()
        ->and(array_column($preview->cueLog(), 'id'))->toBe(['flare']);

    // Speed steps through the Engine session's multiplier; O loops; Home/End seek.
    pressKeys($editor, '+');
    expect($preview->speed())->toBe(2.0);
    pressKeys($editor, '-', '-');
    expect($preview->speed())->toBe(0.5);
    pressKeys($editor, 'o');
    expect($preview->isLooping())->toBeTrue();
    pressKeys($editor, "\033[H");
    expect($preview->currentFrame())->toBe(0);
    pressKeys($editor, "\033[F");
    expect($preview->currentFrame())->toBe(23);

    // L flips to the timeline view; X closes the session.
    pressKeys($editor, 'l');
    expect(renderEditorPlainFrame($editor, 150, 45))->toContain('f12–23');
    pressKeys($editor, 'x');
    expect(getEditorProperty($editor, 'summonPreview'))->toBeNull();
});

it('reorders, nests, un-nests, duplicates and removes commands from the tree, each undoable', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root, 160, 50);
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_TREE);
    $asset = libraryOf($editor)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    $types = static fn(array $list): array => array_map(static fn(array $command): string => strval($command['type']), array_values($list));
    $rowKeyAtCursor = static fn() => callEditorMethod($editor, 'visibleCutsceneTreeRows')[getEditorProperty($editor, 'cutsceneTreeCursor')]['key'];

    // Row 1 is the transition (row 0 is the Commands heading). ']' moves it below the camera command.
    setEditorProperty($editor, 'cutsceneTreeCursor', 1);
    pressKeys($editor, ']');
    expect($types($asset->payload()['commands']))->toBe(['camera', 'transition', 'parallel', 'checkpoint', 'title_card'])
        ->and($rowKeyAtCursor())->toBe('commands.1')
        ->and($asset->isDirty())->toBeTrue();

    // '[' brings it back; the history undoes and redoes it.
    pressKeys($editor, '[');
    expect($types($asset->payload()['commands']))->toBe(['transition', 'camera', 'parallel', 'checkpoint', 'title_card']);
    pressKeys($editor, "\x1a");
    expect($types($asset->payload()['commands']))->toBe(['camera', 'transition', 'parallel', 'checkpoint', 'title_card']);
    pressKeys($editor, "\x19");
    expect($types($asset->payload()['commands']))->toBe(['transition', 'camera', 'parallel', 'checkpoint', 'title_card']);

    // '>' on the checkpoint nests it into the parallel block's last lane; '<' brings it out again.
    foreach (callEditorMethod($editor, 'visibleCutsceneTreeRows') as $position => $row) {
        if ($row['key'] === 'commands.3') {
            setEditorProperty($editor, 'cutsceneTreeCursor', $position);
        }
    }

    pressKeys($editor, '>');
    $commands = $asset->payload()['commands'];
    expect($types($commands))->toBe(['transition', 'camera', 'parallel', 'title_card'])
        ->and($types($commands[2]['lanes'][2]['commands']))->toBe(['narration', 'checkpoint'])
        ->and($rowKeyAtCursor())->toBe('commands.2.lanes.2.1');
    pressKeys($editor, '<');
    $commands = $asset->payload()['commands'];
    expect($types($commands))->toBe(['transition', 'camera', 'parallel', 'checkpoint', 'title_card'])
        ->and($types($commands[2]['lanes'][2]['commands']))->toBe(['narration'])
        ->and($rowKeyAtCursor())->toBe('commands.3');

    // Shift+D duplicates the checkpoint after itself; Del removes the copy; undo restores it.
    pressKeys($editor, 'D');
    expect($types($asset->payload()['commands']))->toBe(['transition', 'camera', 'parallel', 'checkpoint', 'checkpoint', 'title_card'])
        ->and($rowKeyAtCursor())->toBe('commands.4');
    pressKeys($editor, "\033[3~");
    expect($types($asset->payload()['commands']))->toBe(['transition', 'camera', 'parallel', 'checkpoint', 'title_card']);
    pressKeys($editor, "\x1a");
    expect($types($asset->payload()['commands']))->toBe(['transition', 'camera', 'parallel', 'checkpoint', 'checkpoint', 'title_card']);

    // The Engine still reads the result, and the rewrite keeps the rest of the source.
    $asset->cinematicDefinition();
    $asset->save();
    $script = file_get_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php');
    expect(substr_count($script, "'type' => 'checkpoint'"))->toBe(2)
        ->and($script)->toContain("'text' => \"The lanterns were lit one by one.\\nNobody spoke.\"");
});

it('reorders and duplicates summon tracks, keyframes and cues from the timeline rows', function () {
    $root = cutsceneProject();
    $editor = cutscenesEditor($root, 160, 50);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::SUMMON);
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_TREE);
    $asset = libraryOf($editor)->find(CutsceneType::SUMMON, 'lantern-wisp');
    $rows = callEditorMethod($editor, 'visibleCutsceneTreeRows');
    $keys = array_column($rows, 'key');

    // The second track moves above the first.
    setEditorProperty($editor, 'cutsceneTreeCursor', array_search('tracks.1', $keys, true));
    pressKeys($editor, '[');
    expect(array_column($asset->payload()['tracks'], 'id'))->toBe(['name', 'wisp']);

    // A keyframe duplicates after itself within its track; a cue copy gets a fresh id.
    $keys = array_column(callEditorMethod($editor, 'visibleCutsceneTreeRows'), 'key');
    setEditorProperty($editor, 'cutsceneTreeCursor', array_search('tracks.1.keyframes.0', $keys, true));
    pressKeys($editor, 'D');
    expect(count($asset->payload()['tracks'][1]['keyframes']))->toBe(3);
    $keys = array_column(callEditorMethod($editor, 'visibleCutsceneTreeRows'), 'key');
    setEditorProperty($editor, 'cutsceneTreeCursor', array_search('cues.0', $keys, true));
    pressKeys($editor, 'D');
    expect(array_column($asset->payload()['cues'], 'id'))->toBe(['flare', 'flare-2']);

    // '+' and '-' on a keyframe row nudge its frame; on a cue row too.
    $keys = array_column(callEditorMethod($editor, 'visibleCutsceneTreeRows'), 'key');
    setEditorProperty($editor, 'cutsceneTreeCursor', array_search('tracks.1.keyframes.0', $keys, true));
    pressKeys($editor, '+', '+', '-');
    expect($asset->payload()['tracks'][1]['keyframes'][0]['frame'])->toBe(1);
    $keys = array_column(callEditorMethod($editor, 'visibleCutsceneTreeRows'), 'key');
    setEditorProperty($editor, 'cutsceneTreeCursor', array_search('cues.1', $keys, true));
    pressKeys($editor, '-');
    expect($asset->payload()['cues'][1]['frame'])->toBe(11);
    pressKeys($editor, "\x1a");
    expect($asset->payload()['cues'][1]['frame'])->toBe(12);

    // Saved through the source-preserving writer, the nowdoc art survives.
    $asset->compiledSummon();
    $asset->save();
    $timeline = file_get_contents($root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.timeline.php');
    expect($timeline)->toContain("\$wisp = <<<'ART'")
        ->and($timeline)->toContain("'id' => 'flare-2'");
});
