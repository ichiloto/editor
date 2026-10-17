<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;

/**
 * The TUI edits a summon's stage as the rows it edits everything else with:
 * a Stage row in the graphical sequence, the stage's own rows and lists
 * under it. It draws nothing graphical. Synthetic fixtures only.
 */

it('offers a stage only in the graphical sequence, and adds and removes it as one undo step', function () {
    $editor = cutscenesEditor(stagedSummonProject(), 160, 50);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::SUMMON);
    callEditorMethod($editor, 'selectCutsceneById', 'lantern-wisp');
    $asset = libraryOf($editor)->find(CutsceneType::SUMMON, 'lantern-wisp');

    expect(cutsceneField($editor, '@sequence')['value'])->toBe('Terminal')
        ->and(cutsceneFieldIds($editor))->not->toContain('@stage');

    setCutsceneField($editor, '@sequence', 'graphical');
    expect(cutsceneField($editor, '@stage')['value'])->toBe('none');

    setCutsceneField($editor, '@stage', 'added');
    expect($asset->hasStage())->toBeTrue()
        ->and(cutsceneFieldIds($editor))->toContain('stage.canvas', 'stage.startFrame', 'stage.restoreFrame', 'commandListStage.subjects', 'commandListStage.camera');

    callEditorMethod($editor, 'performUndo');
    expect($asset->hasStage())->toBeFalse()
        ->and($asset->isDirty())->toBeFalse();
});

it('adds a stage subject in its frame, and a named point to the subject under the cursor', function () {
    $editor = cutscenesEditor(stagedSummonProject(), 160, 50);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::SUMMON);
    callEditorMethod($editor, 'selectCutsceneById', 'lantern-wisp');
    setCutsceneField($editor, '@sequence', 'graphical');
    setCutsceneField($editor, '@stage', 'added');

    openCutsceneFrame($editor, 'commandListStage.subjects');
    pressKeys($editor, 'O');
    expect(cutsceneFieldIds($editor))->toContain('subject0Id', 'subject0Position', 'subject0Size');

    $ids = addCutsceneAfter($editor, 'subject0Id');
    expect($ids)->toContain('subject0Attachment0Id');
});
