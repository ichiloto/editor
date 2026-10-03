<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Preview\EffectPreviewStage;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\UI\CutscenesScreen;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;

/**
 * Standalone effect timelines on the Cutscenes screen: grouped rows over
 * the Engine's own keys, one sequence edited at a time, a flat effect
 * split only when asked, every change undoable, and a preview through the
 * Engine's playhead placed as battle or the field places it.
 */
function effectsEditor(string $root): Editor
{
    $editor = cutscenesEditor($root, 160, 50);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::EFFECT);

    return $editor;
}

function selectEffect(Editor $editor, string $id): void
{
    callEditorMethod($editor, 'selectCutsceneById', $id);
    setEditorProperty($editor, 'databaseCommandFramePath', []);
}

it('groups an effect into its timing, battle impact and timeline, and reads its standing from the Engine', function () {
    $editor = effectsEditor(effectProject());
    selectEffect($editor, 'ember-spark');
    $labels = array_map(static fn(array $field): string => trim((string) ($field['label'] ?? '')), callEditorMethod($editor, 'getDatabaseSettingsFields'));

    expect($labels)->toContain('Identity', 'Sequence', 'Timing', 'Impact (battle)', 'Timeline')
        ->and(cutsceneFieldIds($editor))->toContain('id', 'fps', 'lengthFrames', 'playback', 'effectTiming.mode', 'commandListTracks', 'commandListCues')
        ->and(cutsceneField($editor, '@sequence')['value'])->toBe('Shared')
        ->and(renderEditorPlainFrame($editor, 160, 50))->toContain('The engine reads this effect as it stands.');
});

it('splits a flat effect into two sequences from its Sequence row, and undo puts the flat file back', function () {
    $root = effectProject();
    $editor = effectsEditor($root);
    selectEffect($editor, 'ember-spark');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'ember-spark');
    $flat = $asset->partner();

    setCutsceneField($editor, '@sequence', 'separate');

    expect($asset->hasPresentations())->toBeTrue()
        ->and($asset->partner())->toBe(['presentations' => ['terminal' => $flat, 'graphical' => $flat]])
        ->and(cutsceneField($editor, '@sequence')['value'])->toBe('Terminal');

    callEditorMethod($editor, 'performUndo');

    expect($asset->hasPresentations())->toBeFalse()
        ->and($asset->partner())->toBe($flat)
        ->and($asset->isDirty())->toBeFalse();

    callEditorMethod($editor, 'performRedo');
    expect($asset->hasPresentations())->toBeTrue();
});

it('edits the sequence it shows, and undo returns to that sequence whichever is shown', function () {
    $root = effectProject();
    $editor = effectsEditor($root);
    selectEffect($editor, 'dusk-slash');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'dusk-slash');

    setCutsceneField($editor, '@sequence', 'graphical');
    expect($asset->getPresentationView())->toBe(EffectPresentation::GRAPHICAL)
        ->and(cutsceneField($editor, 'lengthFrames')['value'])->toBe('2');

    setCutsceneField($editor, 'lengthFrames', '3');
    setCutsceneField($editor, '@sequence', 'terminal');

    expect(cutsceneField($editor, 'lengthFrames')['value'])->toBe('4')
        ->and($asset->partner()['presentations']['graphical']['lengthFrames'])->toBe(3);

    callEditorMethod($editor, 'performUndo');

    expect($asset->partner()['presentations']['graphical']['lengthFrames'])->toBe(2)
        ->and($asset->partner()['presentations']['terminal']['lengthFrames'])->toBe(4)
        ->and($asset->getPresentationView())->toBe(EffectPresentation::GRAPHICAL)
        ->and($asset->isDirty())->toBeFalse();
});

it("sets and removes a track's battle facing as an ordinary field, and the Engine still plays it", function () {
    $root = effectProject();
    $editor = effectsEditor($root);
    selectEffect($editor, 'ember-spark');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'ember-spark');

    selectCutsceneField($editor, 'commandListTracks');
    pressKeys($editor, "\n");
    $facing = array_values(array_filter(cutsceneFieldIds($editor), static fn(string $id): bool => str_ends_with(strtolower($id), 'facing')))[0];

    expect(cutsceneField($editor, $facing)['options'])->toBe(['', 'west', 'east']);

    setCutsceneField($editor, $facing, 'west');
    expect($asset->payload()['tracks'][0]['facing'])->toBe('west')
        ->and($asset->compiledEffect(EffectPresentation::TERMINAL, true))->not->toBeNull();

    setCutsceneField($editor, $facing, '');
    expect($asset->payload()['tracks'][0])->not->toHaveKey('facing')
        ->and($asset->isDirty())->toBeFalse();
});

it('adds tracks, keyframes and cues from the timeline rows with the blanks the effect schema owns', function () {
    $root = effectProject();
    $editor = effectsEditor($root);
    selectEffect($editor, 'dusk-slash');
    setCutsceneField($editor, '@sequence', 'graphical');
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_TREE);
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'dusk-slash');
    $keys = array_column(callEditorMethod($editor, 'visibleCutsceneTreeRows'), 'key');

    expect(renderEditorPlainFrame($editor, 160, 50))->toContain('Timeline')
        ->and(implode("\n", array_column(callEditorMethod($editor, 'visibleCutsceneTreeRows'), 'text')))->toContain('faces west', 'sheet frame 1');

    // An image keyframe takes the image keyframe blank, not a glyph one,
    // starting where the selected one ends.
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_SETTINGS);
    setCutsceneField($editor, 'lengthFrames', '3');
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_TREE);
    setEditorProperty($editor, 'cutsceneTreeCursor', array_search('tracks.0.keyframes.1', $keys, true));
    pressKeys($editor, 'O');
    expect($asset->payload()['tracks'][0]['keyframes'][2])->toBe(['frame' => 2, 'sourceFrame' => 0]);

    // A track takes the effect track blank, its keyframe positioned as the Engine reads it.
    setEditorProperty($editor, 'cutsceneTreeCursor', array_search('tracks.0', $keys, true));
    pressKeys($editor, 'O');
    expect($asset->payload()['tracks'][1]['keyframes'][0]['position'])->toBe(['x' => 0, 'y' => 0])
        ->and(array_unique(array_column($asset->payload()['tracks'], 'id')))->toHaveCount(2);

    // The edited sequence still compiles; the terminal one is untouched.
    expect($asset->compiledEffect(EffectPresentation::GRAPHICAL, true)->playbackSegments)->not->toBe([])
        ->and($asset->partner()['presentations']['terminal'])->toBe((eval('?>' . duskSlashTimeline()))['presentations']['terminal']);
});

it('names what plays an effect before deleting it: battle animations, field effects and scripts', function () {
    $root = effectProject();
    $animations = require $root . '/assets/Data/animations.php';
    $animations[0]['targetEffect'] = 'dusk-slash';
    file_put_contents($root . '/assets/Data/animations.php', '<?php return ' . var_export($animations, true) . ';');
    $script = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($script, str_replace("return [\n", "return [\n  ['type' => 'field_animation', 'effect' => 'dusk-slash', 'target' => 'player'],\n", (string) file_get_contents($script)));
    $editor = effectsEditor($root);
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'dusk-slash');

    $references = callEditorMethod($editor, 'describeCutsceneReferences', $asset);

    expect(implode("\n", $references))->toContain('assets/Data/animations.php: ' . $animations[0]['name'] . ' targetEffect')
        ->toContain('cinematic harbour-lanterns')
        ->and(callEditorMethod($editor, 'describeCutsceneReferences', libraryOf($editor)->find(CutsceneType::EFFECT, 'ember-spark')))->toBe([]);
});

it('previews an effect through the Engine playhead, anchored to caster and target, as battle or the field plays it', function () {
    $root = effectProject();
    $editor = effectsEditor($root);
    selectEffect($editor, 'dusk-slash');
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);

    pressKeys($editor, ' ', ' ');
    $preview = getEditorProperty($editor, 'timelinePreview');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'dusk-slash');

    expect($preview)->not->toBeNull()
        ->and($preview->totalFrames())->toBe(4)
        ->and(callEditorMethod($editor, 'isEffectPreviewInBattle', $asset))->toBeTrue();

    // The west-facing stroke is turned toward a target on the right, as the battle turns it.
    $stage = callEditorMethod($editor, 'createEffectPreviewStage', $asset);
    $row = $stage->drawFrame($preview->activeSegments(), 21, 3)[1];
    expect($row)->toBe('     C         \\     ');

    pressKeys($editor, 'd');
    $row = callEditorMethod($editor, 'createEffectPreviewStage', $asset)->drawFrame($preview->activeSegments(), 21, 3)[1];
    expect($row)->toBe('     /         C     ');

    // On the field the effect has one subject; B switches and recompiles.
    pressKeys($editor, 'b');
    // Its facing is battle-only, so the field refuses it, and says why.
    expect(callEditorMethod($editor, 'isEffectPreviewInBattle', $asset))->toBeFalse()
        ->and(getEditorProperty($editor, 'timelinePreview'))->toBeNull()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('facing');

    pressKeys($editor, 'b');
    expect(getEditorProperty($editor, 'timelinePreview'))->not->toBeNull();

    // The graphical sequence: its images are described, not imitated.
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_SETTINGS);
    setCutsceneField($editor, '@sequence', 'graphical');
    expect(getEditorProperty($editor, 'timelinePreview'))->toBeNull();
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);
    pressKeys($editor, ' ', ' ');
    $stage = callEditorMethod($editor, 'createEffectPreviewStage', $asset);

    // With the caster on the right the west stroke is drawn as authored;
    // D puts the caster back on the left and the battle flips it.
    expect($stage->describeUndrawn(getEditorProperty($editor, 'timelinePreview')->activeSegments()))->toBe(['dusk-slash.png frame 0 on target']);
    pressKeys($editor, 'd');

    expect(getEditorProperty($editor, 'timelinePreview')->totalFrames())->toBe(2)
        ->and(callEditorMethod($editor, 'createEffectPreviewStage', $asset)->describeUndrawn(getEditorProperty($editor, 'timelinePreview')->activeSegments()))
            ->toBe(['dusk-slash.png frame 0 flipX on target'])
        ->and(renderEditorPlainFrame($editor, 160, 50))->toContain('dusk-slash.png frame 0');
});

it('draws a flat field effect around its target, leaving image tracks to the graphical renderer', function () {
    $stage = new EffectPreviewStage(EffectPresentation::TERMINAL, false);
    $segments = [
        ['layer' => 'glyph', 'startFrame' => 0, 'endFrame' => 0, 'drawCommands' => [['content' => '*', 'position' => ['x' => 1, 'y' => -1], 'payload' => []]]],
        ['layer' => 'glyph', 'startFrame' => 0, 'endFrame' => 0, 'drawCommands' => [['content' => '!', 'position' => ['x' => 0, 'y' => 0], 'payload' => ['anchor' => 'screen']]]],
        ['layer' => 'image', 'startFrame' => 0, 'endFrame' => 0, 'drawCommands' => [['assetId' => 'Graphics/x.png', 'payload' => ['sourceFrame' => 0]]]],
    ];

    expect($stage->drawFrame($segments, 9, 3))->toBe(['!    *   ', '    T    ', '         '])
        ->and($stage->describeUndrawn($segments))->toBe(['image tracks: graphical only']);
});
