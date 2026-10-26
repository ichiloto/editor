<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Preview\EffectPreviewStage;
use Ichiloto\Editor\Cutscenes\Preview\TimelinePreviewSession;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\UI\CutscenesScreen;
use Ichiloto\Editor\Validation\EffectValidator;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStage;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStagePresentation;

/** Synthetic authored space, unrelated to any production effect's identity or artwork. */
function ownedStageEffectSequence(): array
{
    return [
        'fps' => 8, 'lengthFrames' => 8, 'restFrame' => 3,
        'stage' => [
            'canvas' => ['width' => 80, 'height' => 40], 'startFrame' => 1, 'restoreFrame' => 6,
            'subjects' => [['id' => 'visitor', 'position' => ['x' => 40, 'y' => 30], 'size' => ['width' => 16, 'height' => 16],
                'attachments' => [['id' => 'crown', 'x' => 0.5, 'y' => 0]]]],
            'camera' => [
                ['id' => 'wide', 'frame' => 0, 'focus' => ['x' => 40, 'y' => 20], 'zoom' => 1],
                ['id' => 'close', 'frame' => 4, 'focus' => ['x' => 44, 'y' => 20], 'zoom' => 2],
            ],
            'covers' => [
                ['id' => 'entry', 'frame' => 0, 'color' => 'black', 'opacity' => 1, 'easing' => 'hold'],
                ['id' => 'clear', 'frame' => 1, 'color' => 'black', 'opacity' => 0],
                ['id' => 'exit', 'frame' => 7, 'color' => 'black', 'opacity' => 0],
            ],
        ],
        'tracks' => [['id' => 'portrait', 'type' => 'image', 'anchor' => 'stage',
            'asset' => 'Graphics/Effects/visitor.png', 'sheet' => ['columns' => 1, 'rows' => 1],
            'placement' => ['subject' => 'visitor', 'attachment' => 'crown'],
            'keyframes' => [['frame' => 1, 'duration' => 5, 'sourceFrame' => 0]]]],
        'cues' => [],
    ];
}

function ownedStageEffectProject(bool $paired = false, bool $readOnly = false): string
{
    $root = makeTemporaryProject('owned-stage-effect-');
    $timeline = ownedStageEffectSequence();
    if ($paired) {
        $timeline = ['presentations' => [
            'terminal' => ['fps' => 8, 'lengthFrames' => 8, 'tracks' => [['id' => 'caption', 'type' => 'text', 'anchor' => 'screen',
                'keyframes' => [['frame' => 0, 'duration' => 8, 'content' => 'A quiet interval', 'position' => ['x' => 0, 'y' => 0]]]]], 'cues' => []],
            'graphical' => $timeline,
        ]];
    }
    mkdir($root . '/assets/Animations/quiet-interval', 0o777, true);
    $literal = var_export($timeline, true);
    $source = $readOnly ? "\$timeline = {$literal};\nreturn \$timeline;" : "return {$literal};";
    file_put_contents($root . '/assets/Animations/quiet-interval/quiet-interval.timeline.php', "<?php\n// Keep the authored stage comment.\n{$source}\n");
    @mkdir($root . '/assets/Graphics/Effects', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Effects/visitor.png', 8, 8);

    return $root;
}

function ownedStageEffectPath(string $root): string
{
    return $root . '/assets/Animations/quiet-interval/quiet-interval.timeline.php';
}

it('admits arbitrary flat and paired owned-stage effects through the Engine without battle or field substitution', function (bool $paired) {
    $root = ownedStageEffectProject($paired);
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'quiet-interval');
    $asset->hydrate();

    expect($asset->isEditable())->toBeTrue()
        ->and($asset->isOwnedStageEffect())->toBeTrue()
        ->and($asset->compileEffect(EffectPresentation::GRAPHICAL, false, forStage: true)->defaults['stage']['subjects'][0]['id'])->toBe('visitor')
        ->and(fn() => $asset->compiledEffect(EffectPresentation::GRAPHICAL, false))->toThrow(RuntimeException::class)
        ->and(fn() => $asset->compiledEffect(EffectPresentation::GRAPHICAL, true))->toThrow(RuntimeException::class)
        ->and(fn() => $asset->compileEffect(EffectPresentation::GRAPHICAL, true, forStage: true))->toThrow(RuntimeException::class)
        ->and(fn() => $asset->compileEffect(EffectPresentation::TERMINAL, true, forStage: true))->toThrow(RuntimeException::class);
})->with([false, true]);

it('refuses invalid owned-stage context data before installing any source', function () {
    $root = ownedStageEffectProject();
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'quiet-interval');
    $before = file_get_contents(ownedStageEffectPath($root));
    foreach ([
        static function (array &$payload): void { $payload['tracks'][0]['placement']['subject'] = 'unknown'; },
        static function (array &$payload): void { $payload['cues'] = [['id' => 'sound', 'frame' => 1, 'type' => 'playSound', 'payload' => ['sound' => 'chime']]]; },
        static function (array &$payload): void { $payload['playback'] = 'loop'; },
        static function (array &$payload): void { $payload['stage']['camera'][0]['frame'] = 1; },
    ] as $break) {
        $payload = $asset->payload();
        $break($payload);
        $state = $asset->captureEditState();
        $asset->apply($payload);
        expect(fn() => $asset->save())->toThrow(RuntimeException::class, 'Effect quiet-interval cannot be played')
            ->and(file_get_contents(ownedStageEffectPath($root)))->toBe($before);
        $asset->restoreEditState($state);
    }
});

it('keeps terminal stage playback independent of graphical decoding and draws no invented subjects', function () {
    $root = ownedStageEffectProject(true);
    unlink($root . '/assets/Graphics/Effects/visitor.png');
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'quiet-interval');
    $terminal = $asset->compileEffect(EffectPresentation::TERMINAL, false, forStage: true);
    $preview = new TimelinePreviewSession($terminal);
    $stage = new EffectPreviewStage(EffectPresentation::TERMINAL, false, forStage: true);

    expect($stage->drawFrame($preview->activeSegments(), 24, 3))->toBe(['A quiet interval        ', str_repeat(' ', 24), str_repeat(' ', 24)])
        ->and($stage->renderCanvas($terminal, $root . '/assets', 2, 80, 40))->toBeNull()
        ->and(fn() => $asset->compileEffect(EffectPresentation::GRAPHICAL, false, forStage: true))->toThrow(RuntimeException::class);
});

it('leaves editable and readonly owned-stage source byte-identical on no-op roundtrips and previews', function (bool $readOnly) {
    $root = ownedStageEffectProject(true, $readOnly);
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'quiet-interval');
    $before = sourceHashTree($root);
    foreach (EffectPresentation::cases() as $presentation) {
        $asset->selectPresentation($presentation);
        $asset->apply($asset->payload());
        $asset->compileEffect($presentation, false, forStage: true);
    }
    expect($asset->isEditable())->toBe(! $readOnly)
        ->and($asset->save())->toBeFalse()
        ->and(sourceHashTree($root))->toBe($before);
})->with([false, true]);

it('authors stage effects through shared rows and selectors with source-preserving save and undo', function (bool $paired) {
    $root = ownedStageEffectProject($paired);
    $before = require ownedStageEffectPath($root);
    $source = file_get_contents(ownedStageEffectPath($root));
    $session = EditorSession::open($root);
    if ($paired) {
        $session->selectCutscenePresentation('cutscenes/effect', 0, 'graphical');
    }
    $rows = $session->readDatabaseRecord('cutscenes/effect', 0, ['tracks'])['rows'];
    $row = array_find($rows, static fn(array $row): bool => str_ends_with($row['label'], 'Subject'));
    $opacity = array_find($rows, static fn(array $row): bool => str_ends_with($row['label'], 'Opacity'));
    expect($row)->not->toBeNull()
        ->and($opacity)->not->toBeNull()
        ->and(array_column($rows, 'label'))->not->toContain('Track 1 Cell Width', 'Track 1 Facing', 'Track 1 Attachment');
    $context = ['category' => 'cutscenes/effect', 'index' => 0];
    expect(array_column($session->listReferences(null, 'stage_subjects', $context), 'value'))->toBe(['visitor'])
        ->and(array_column($session->listReferences(null, 'stage_attachments', $context), 'value'))->toBe(['crown'])
        ->and(array_column($session->listReferences(null, 'png_assets', $context), 'value'))->toContain('Graphics/Effects/visitor.png');
    $session->applyDatabaseRecord('cutscenes/effect', 0, $opacity['key'], '0.5');
    $session->undo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(require ownedStageEffectPath($root))->toBe($before)
        ->and(file_get_contents(ownedStageEffectPath($root)))->toBe($source);
    $session->redo();
    expect($session->saveAll()['failures'])->toBe([]);
    $saved = require ownedStageEffectPath($root);
    $sequence = $paired ? $saved['presentations']['graphical'] : $saved;
    expect($sequence['tracks'][0]['keyframes'][0]['opacity'])->toBe(0.5)
        ->and(file_get_contents(ownedStageEffectPath($root)))->toContain('// Keep the authored stage comment.');
    if ($paired) {
        expect($saved['presentations']['terminal'])->toBe($before['presentations']['terminal']);
    }
})->with([false, true]);

it('projects seek camera covers and reduced motion with the shared Engine stage compositor and no retained scene', function () {
    $root = ownedStageEffectProject();
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'quiet-interval');
    $compiled = $asset->compileEffect(EffectPresentation::GRAPHICAL, false, forStage: true);
    $preview = new TimelinePreviewSession($compiled);
    $stage = new EffectPreviewStage(EffectPresentation::GRAPHICAL, false, forStage: true);
    $definition = CinematicStage::fromArray($compiled->defaults['stage'], 8, 3);
    foreach ([0, 2, 5, 1, 7, 3] as $frame) {
        $preview->seek($frame);
        foreach ([false, true] as $reduced) {
            expect($stage->renderCanvas($compiled, $root . '/assets', $preview->currentFrame(), 160, 80, $reduced)->toArray())
                ->toBe(CinematicStagePresentation::compose($definition->getFrame($frame, $reduced), $compiled->playbackSegments, $root . '/assets', 160, 80)->toArray());
        }
    }
    expect($stage->renderCanvas($compiled, $root . '/assets', 2, 160, 80)->images[0]->destination)
        ->not->toEqual($stage->renderCanvas($compiled, $root . '/assets', 5, 160, 80)->images[0]->destination)
        ->and($stage->renderCanvas($compiled, $root . '/assets', 7, 160, 80)->images)->toBe([])
        ->and($stage->renderCanvas($compiled, $root . '/assets', 7, 160, 80)->composites)->toBe([]);
    $preview->restart();
    expect($preview->currentFrame())->toBe(0)->and($preview->cueLog())->toBe([]);
    $preview->play();
    $preview->tick(2);
    expect($preview->isCompleted())->toBeTrue();
});

it('chooses owned stage in the Terminal Editor even when no project consumer references the effect', function () {
    $root = ownedStageEffectProject();
    $editor = cutscenesEditor($root, 160, 50);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::EFFECT);
    callEditorMethod($editor, 'selectCutsceneById', 'quiet-interval');
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);
    callEditorMethod($editor, 'startTimelinePreview', false);
    $preview = getEditorProperty($editor, 'timelinePreview');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'quiet-interval');
    expect($preview)->toBeInstanceOf(TimelinePreviewSession::class)
        ->and(callEditorMethod($editor, 'createEffectPreviewStage', $asset)->forStage)->toBeTrue()
        ->and(cutsceneFieldIds($editor))->not->toContain('@stage', 'stage.canvas', 'commandListStage.subjects');
    pressKeys($editor, 'b', 'd');
    expect(getEditorProperty($editor, 'timelinePreview'))->toBe($preview)
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('owns its stage');
    pressKeys($editor, '.', 'r', 'x');
    expect(getEditorProperty($editor, 'timelinePreview'))->toBeNull();
});

it('reads replacement stage artwork from the current file rather than freezing its bytes or dimensions', function () {
    $root = ownedStageEffectProject();
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'quiet-interval');
    $stage = new EffectPreviewStage(EffectPresentation::GRAPHICAL, false, forStage: true);
    $first = $stage->renderCanvas($asset->compileEffect(EffectPresentation::GRAPHICAL, false, forStage: true), $root . '/assets', 1, 160, 80);
    writeTilesetTestPng($root . '/assets/Graphics/Effects/visitor.png', 16, 12);
    $replacement = $stage->renderCanvas($asset->compileEffect(EffectPresentation::GRAPHICAL, false, forStage: true), $root . '/assets', 1, 160, 80);

    expect($replacement->images[0]->sourceRect->width)->toBe(16)
        ->and($replacement->images[0]->sourceRect->height)->toBe(12)
        ->and($replacement->images[0]->destination)->toEqual($first->images[0]->destination)
        ->and($asset->isDirty())->toBeFalse();
});

it('validates a referenced stage with independent Engine admission for both presentations', function () {
    $root = ownedStageEffectProject(true);
    file_put_contents($root . '/config.php', "<?php return ['graphics' => ['inn' => ['presentation' => 'quiet-interval']]];\n");
    $workspace = ProjectWorkspace::fromProject($root);
    expect(EffectValidator::findUses($workspace)['quiet-interval'])->toHaveKey('stage')
        ->and(new EffectValidator()->validate($workspace))->toBe([]);
    $timeline = require ownedStageEffectPath($root);
    $timeline['presentations']['terminal']['cues'] = [['id' => 'sound', 'frame' => 1, 'type' => 'playSound', 'payload' => ['sound' => 'chime']]];
    file_put_contents(ownedStageEffectPath($root), '<?php return ' . var_export($timeline, true) . ';');
    expect(new EffectValidator()->validate(ProjectWorkspace::fromProject($root)))->toBe([]);
    $timeline['presentations']['terminal']['cues'][0]['frame'] = 100;
    file_put_contents(ownedStageEffectPath($root), '<?php return ' . var_export($timeline, true) . ';');
    $issues = new EffectValidator()->validate(ProjectWorkspace::fromProject($root));
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->message)->toContain('quiet-interval cannot be played as a stage', 'invalid field presentation cue');
});

it('creates a graphical effect stage through the source-preserving session with no-op undo and save', function (bool $paired) {
    $root = ownedStageEffectProject($paired);
    $timeline = require ownedStageEffectPath($root);
    $sequence = &$timeline;
    if ($paired) { $sequence = &$timeline['presentations']['graphical']; }
    unset($sequence['stage'], $sequence['tracks'][0]['placement']);
    $sequence['tracks'][0]['anchor'] = 'screen';
    unset($sequence);
    file_put_contents(ownedStageEffectPath($root), "<?php\n// Keep the authored stage comment.\nreturn " . var_export($timeline, true) . ";\n");
    $source = file_get_contents(ownedStageEffectPath($root));
    $session = EditorSession::open($root);
    if ($paired) { $session->selectCutscenePresentation('cutscenes/effect', 0, 'graphical'); }
    expect($session->describeCutsceneTimeline('cutscenes/effect', 0))->toMatchArray(['canHaveStage' => true, 'ownedStage' => false, 'stage' => null]);
    expect($session->setCutsceneStage('cutscenes/effect', 0, true))->toBe(['changed' => true, 'stage' => true])
        ->and($session->setCutsceneStage('cutscenes/effect', 0, true))->toBe(['changed' => false, 'stage' => true]);
    $session->undo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents(ownedStageEffectPath($root)))->toBe($source);
    $session->redo();
    // Adding a descriptor does not silently convert an existing track's geometry.
    $preview = $session->readCutscenePreview('cutscenes/effect', 0, 3, 24, 3);
    expect($preview['stageCanvas'])->toBeNull()->and($preview['stageCanvasError'])->toContain('only stage image tracks');
    $rows = $session->readDatabaseRecord('cutscenes/effect', 0, ['tracks'])['rows'];
    $anchor = array_find($rows, static fn(array $row): bool => $row['label'] === 'Track 1 Anchor');
    $session->applyDatabaseRecord('cutscenes/effect', 0, $anchor['key'], 'stage');
    $rows = $session->readDatabaseRecord('cutscenes/effect', 0, ['tracks'])['rows'];
    $size = array_find($rows, static fn(array $row): bool => $row['label'] === 'Track 1 Size');
    $session->applyDatabaseRecord('cutscenes/effect', 0, $size['key'], '16, 16');
    $workspace = new ReflectionProperty(EditorSession::class, 'workspace')->getValue($session);
    $state = $workspace->cutscenes->find(CutsceneType::EFFECT, 'quiet-interval')->captureEditState();
    expect(eval('?>' . $state['sourceTemplates']['timeline']['source']))->toBe($state['sourceTemplates']['timeline']['values']);
    expect($session->saveAll()['failures'])->toBe([]);
    $saved = require ownedStageEffectPath($root);
    $graphical = $paired ? $saved['presentations']['graphical'] : $saved;
    expect($graphical['stage']['subjects'] ?? [])->toBe([])
        ->and($graphical['stage']['camera'][0]['frame'])->toBe(0)
        ->and(file_get_contents(ownedStageEffectPath($root)))->toContain('// Keep the authored stage comment.');
    if ($paired) { expect($saved['presentations']['terminal'])->toBe($timeline['presentations']['terminal']); }
    CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'quiet-interval')->hydrate();
})->with([false, true]);

it('removes an effect stage without erasing tracks and restores it across save and undo', function () {
    $root = ownedStageEffectProject();
    $timeline = require ownedStageEffectPath($root);
    $timeline['tracks'][] = ['id' => 'caption', 'type' => 'text', 'presentation' => 'terminal', 'anchor' => 'screen',
        'keyframes' => [['frame' => 0, 'duration' => 8, 'content' => 'Independent Terminal']]];
    file_put_contents(ownedStageEffectPath($root), '<?php return ' . var_export($timeline, true) . ';');
    $source = file_get_contents(ownedStageEffectPath($root));
    $session = EditorSession::open($root);
    $session->setCutsceneStage('cutscenes/effect', 0, false);
    expect($session->saveAll()['failures'])->not->toBe([])
        ->and(file_get_contents(ownedStageEffectPath($root)))->toBe($source);
    $session->undo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents(ownedStageEffectPath($root)))->toBe($source);
    $row = array_find($session->readDatabaseRecord('cutscenes/effect', 0, ['tracks'])['rows'],
        static fn(array $row): bool => $row['label'] === 'Track 1 Id');
    $session->removeDatabaseItem('cutscenes/effect', 0, $row['key']);
    $session->setCutsceneStage('cutscenes/effect', 0, false);
    expect($session->saveAll()['failures'])->toBe([]);
    $saved = require ownedStageEffectPath($root);
    expect($saved)->not->toHaveKey('stage')->and($saved['tracks'])->toBe([$timeline['tracks'][1]]);
    $session->undo();
    $session->undo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents(ownedStageEffectPath($root)))->toBe($source);
});

it('serves shared stage canvases on the graphical clock and independent Terminal cues without graphical dependencies', function () {
    $root = ownedStageEffectProject(true);
    $timeline = require ownedStageEffectPath($root);
    $timeline['presentations']['terminal']['fps'] = 4;
    $timeline['presentations']['terminal']['lengthFrames'] = 12;
    $timeline['presentations']['terminal']['tracks'][0]['keyframes'][0]['duration'] = 12;
    $timeline['presentations']['terminal']['cues'] = [['id' => 'chime', 'frame' => 10, 'type' => 'playSound', 'payload' => ['sound' => 'chime']]];
    file_put_contents(ownedStageEffectPath($root), '<?php return ' . var_export($timeline, true) . ';');
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    $session->selectCutscenePresentation('cutscenes/effect', 0, 'graphical');
    expect($session->describeCutsceneTimeline('cutscenes/effect', 0))->toMatchArray(['ownedStage' => true, 'canHaveStage' => true]);
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'quiet-interval');
    $compiled = $asset->compileEffect(EffectPresentation::GRAPHICAL, false, forStage: true);
    $stage = new EffectPreviewStage(EffectPresentation::GRAPHICAL, false, forStage: true);
    foreach ([5, 1, 7, 3, 0, 30] as $frame) {
        $preview = $session->readCutscenePreview('cutscenes/effect', 0, $frame, 24, 3);
        expect($preview['stageCanvas'])->toBe($stage->renderCanvas($compiled, $root . '/assets', $frame, 80, 40)->toArray())
            ->and($preview['stageCanvasError'])->toBeNull()
            ->and($preview['totalFrames'])->toBe(8)->and($preview['fps'])->toBe(8)->and($preview['cues'])->toBe([]);
    }
    $session->selectCutscenePresentation('cutscenes/effect', 0, 'terminal');
    expect($session->describeCutsceneTimeline('cutscenes/effect', 0))->toMatchArray(['ownedStage' => true, 'canHaveStage' => false, 'stage' => null]);
    expect($session->saveAll()['failures'])->toBe([])->and(sourceHashTree($root))->toBe($before);
    unlink($root . '/assets/Graphics/Effects/visitor.png');
    $preview = $session->readCutscenePreview('cutscenes/effect', 0, 10, 24, 3);
    expect($preview)->toMatchArray(['frame' => 10, 'totalFrames' => 12, 'fps' => 4, 'stageCanvas' => null, 'stageCanvasError' => null])
        ->and($preview['lines'][0])->toBe('A quiet interval        ')->and($preview['cues'][0]['id'])->toBe('chime');
});

it('refuses source edits on read-only owned stages while still projecting their graphical preview', function () {
    $root = ownedStageEffectProject(true, true);
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    $session->selectCutscenePresentation('cutscenes/effect', 0, 'graphical');
    expect($session->readCutscenePreview('cutscenes/effect', 0, 3, 24, 3)['stageCanvas']['images'])->not->toBe([])
        ->and(fn() => $session->setCutsceneStage('cutscenes/effect', 0, false))->toThrow(SessionRefusal::class, 'read-only')
        ->and(sourceHashTree($root))->toBe($before);
});

it('preserves independent authored Terminal tracks and cues without applying graphical stage consumer policy', function () {
    $root = ownedStageEffectProject(true);
    $timeline = require ownedStageEffectPath($root);
    $timeline['presentations']['terminal']['cues'] = [['id' => 'authored-cue', 'frame' => 1, 'type' => 'showMessage', 'payload' => ['text' => 'An authored cue']]];
    file_put_contents(ownedStageEffectPath($root), '<?php return ' . var_export($timeline, true) . ';');
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'quiet-interval');
    $asset->hydrate();
    $compiled = $asset->compileEffect(EffectPresentation::TERMINAL, false, forStage: true);
    expect($compiled->cueSchedule)->toBe($timeline['presentations']['terminal']['cues']);
    $preview = new TimelinePreviewSession($compiled);
    $preview->play();
    $preview->tick(0.25);
    expect($preview->cueLog()[0])->toBe(['frame' => 1, 'id' => 'authored-cue', 'type' => 'showMessage']);
    $session = EditorSession::open($root);
    $session->selectCutscenePresentation('cutscenes/effect', 0, 'terminal');
    expect($session->readCutscenePreview('cutscenes/effect', 0, 1, 24, 3)['cues'])->toBe($compiled->cueSchedule)
        ->and($session->describeCutsceneTimeline('cutscenes/effect', 0)['stage'])->toBeNull();
});
