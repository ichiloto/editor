<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneHydration;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Preview\EffectPreviewContext;
use Ichiloto\Editor\Cutscenes\Preview\EffectPreviewStage;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\UI\CutscenesScreen;
use Ichiloto\Editor\Validation\EffectValidator;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;

/**
 * An ordinary battle action's staged cut-in: an effect timeline declaring
 * the shared cinematic stage, bound through an animation's source effect,
 * authored with the same stage rows, selectors and undo as every other
 * stage and admitted by battle. Synthetic fixtures and art only.
 */

/** A synthetic staged source sequence carrying a battle-only cue, so only battle admits it. */
function battleStageCutInSequence(): array
{
    return [
        'fps' => 12, 'lengthFrames' => 12, 'restFrame' => 5,
        'stage' => [
            'canvas' => ['width' => 160, 'height' => 90], 'startFrame' => 0, 'restoreFrame' => 10,
            'subjects' => [['id' => 'striker', 'position' => ['x' => 80, 'y' => 80], 'size' => ['width' => 40, 'height' => 60],
                'attachments' => [['id' => 'blade', 'x' => 0.75, 'y' => 0.25]]]],
            'camera' => [
                ['id' => 'wide', 'frame' => 0, 'focus' => ['x' => 80, 'y' => 45], 'zoom' => 1],
                ['id' => 'close', 'frame' => 6, 'focus' => ['x' => 90, 'y' => 40], 'zoom' => 1.5, 'easing' => 'smoothstep'],
            ],
            'covers' => [
                ['id' => 'open', 'frame' => 0, 'color' => 'black', 'opacity' => 1],
                ['id' => 'clear', 'frame' => 2, 'color' => 'black', 'opacity' => 0],
                ['id' => 'exit', 'frame' => 11, 'color' => 'white', 'opacity' => 0],
            ],
        ],
        'tracks' => [['id' => 'striker-art', 'type' => 'image', 'anchor' => 'stage',
            'asset' => 'Graphics/Effects/striker.png', 'sheet' => ['columns' => 1, 'rows' => 1],
            'placement' => ['subject' => 'striker', 'attachment' => 'blade'],
            'keyframes' => [['frame' => 1, 'duration' => 9, 'sourceFrame' => 0]]]],
        'cues' => [['id' => 'rumble', 'frame' => 3, 'type' => 'shake', 'payload' => []]],
    ];
}

/**
 * The battle test project whose unarmed attack plays a staged cut-in as its
 * source effect and an ordinary stroke on its target.
 */
function battleStageProject(bool $paired = false, bool $readOnly = false, bool $bound = true): string
{
    $root = summonBattlePreviewProject();
    $timeline = battleStageCutInSequence();
    if ($paired) {
        $timeline = ['presentations' => [
            'terminal' => ['fps' => 6, 'lengthFrames' => 4, 'tracks' => [['id' => 'callout', 'type' => 'text', 'anchor' => 'caster',
                'keyframes' => [['frame' => 0, 'duration' => 4, 'content' => '!!', 'position' => ['x' => 0, 'y' => -1]]]]],
                'cues' => [['id' => 'tap', 'frame' => 1, 'type' => 'playSound', 'payload' => ['sound' => 'tap']]]],
            'graphical' => $timeline,
        ]];
    }
    @mkdir($root . '/assets/Animations/striking-cut-in', 0o777, true);
    $literal = var_export($timeline, true);
    $source = $readOnly ? "\$timeline = {$literal};\nreturn \$timeline;" : "return {$literal};";
    file_put_contents(battleStagePath($root), "<?php\n// Keep the authored cut-in comment.\n{$source}\n");
    @mkdir($root . '/assets/Animations/dusk-slash', 0o777, true);
    file_put_contents($root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php', duskSlashTimeline());
    @mkdir($root . '/assets/Graphics/Effects', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Effects/striker.png', 12, 16);
    writeTilesetTestPng($root . '/assets/Graphics/Effects/dusk-slash.png', 32, 16);
    file_put_contents($root . '/assets/Data/animations.php', "<?php\n\nreturn " . var_export([
        ['id' => 1, 'name' => 'Striking Cut-in', 'roles' => ['attack', 'attack-unarmed'],
            ...($bound ? ['sourceEffect' => 'striking-cut-in'] : []), 'targetEffect' => 'dusk-slash'],
    ], true) . ";\n");

    return $root;
}

function battleStagePath(string $root): string
{
    return $root . '/assets/Animations/striking-cut-in/striking-cut-in.timeline.php';
}

/** @return int The cut-in's place among the effect records. */
function battleStageIndex(EditorSession $session): int
{
    foreach ($session->listDatabaseRecords('cutscenes/effect')['records'] as $index => $label) {
        if (str_contains(strval($label), 'striking-cut-in')) {
            return $index;
        }
    }
    throw new RuntimeException('No striking-cut-in effect.');
}

/** The rows of one frame of the cut-in, by label. */
function battleStageRows(EditorSession $session, int $index, array $frame = []): array
{
    $rows = [];
    foreach ($session->readDatabaseRecord('cutscenes/effect', $index, $frame)['rows'] as $row) {
        $rows[trim($row['label'])] ??= $row;
    }

    return $rows;
}

it('admits a staged ordinary action effect through battle, which neither its owned stage nor the field would', function (bool $paired) {
    $root = battleStageProject($paired);
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'striking-cut-in');
    $asset->hydrate();
    $timeline = require battleStagePath($root);

    expect($asset->hasStage())->toBeTrue()
        ->and($asset->compileEffect(EffectPresentation::GRAPHICAL, true)->defaults['stage']['subjects'][0]['id'])->toBe('striker')
        ->and($asset->compileEffect(EffectPresentation::TERMINAL, true))->not->toBeNull()
        ->and(fn() => $asset->compileEffect(EffectPresentation::GRAPHICAL, false, forStage: true))->toThrow(RuntimeException::class, 'no cues')
        ->and(fn() => $asset->compileEffect(EffectPresentation::GRAPHICAL, false))->toThrow(RuntimeException::class);
    CutsceneHydration::checkEffect('striking-cut-in', $timeline, $root);

    $workspace = ProjectWorkspace::fromProject($root);
    expect(EffectValidator::findUses($workspace)['striking-cut-in'])->toBe(['battle' => ['assets/Data/animations.php: Striking Cut-in sourceEffect']])
        ->and(EffectValidator::findBattleUses($workspace)['dusk-slash'])->toBe(['assets/Data/animations.php: Striking Cut-in targetEffect'])
        ->and(new EffectValidator()->validate($workspace))->toBe([]);
})->with([false, true]);

it('reports a stage battle refuses at the binding animation, and refuses saving a stage no consumer admits without writing', function () {
    $root = battleStageProject();
    $timeline = require battleStagePath($root);
    $timeline['cadence'] = 'battle_phase';
    unset($timeline['fps']);
    file_put_contents(battleStagePath($root), '<?php return ' . var_export($timeline, true) . ';');
    $issues = new EffectValidator()->validate(ProjectWorkspace::fromProject($root));
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->message)->toContain('striking-cut-in cannot be played in battle for the graphical presentation', 'fixed authored clock')
        ->and($issues[0]->where)->toBe('assets/Data/animations.php: Striking Cut-in sourceEffect');

    $root = battleStageProject(true);
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'striking-cut-in');
    $asset->selectPresentation(EffectPresentation::GRAPHICAL);
    $before = file_get_contents(battleStagePath($root));
    foreach ([
        static function (array &$payload): void { $payload['tracks'][0]['placement']['subject'] = 'unknown'; },
        static function (array &$payload): void { $payload['stage']['camera'][0]['frame'] = 1; },
        static function (array &$payload): void { $payload['cadence'] = 'battle_phase'; unset($payload['fps']); },
    ] as $break) {
        $payload = $asset->payload();
        $break($payload);
        $state = $asset->captureEditState();
        $asset->apply($payload);
        expect(fn() => $asset->save())->toThrow(RuntimeException::class, 'Effect striking-cut-in cannot be played')
            ->and(file_get_contents(battleStagePath($root)))->toBe($before);
        $asset->restoreEditState($state);
    }
});

it('authors camera, covers, subjects and image placement of an ordinary cut-in through the shared stage rows with source-preserving undo', function (bool $paired) {
    $root = battleStageProject($paired);
    $before = require battleStagePath($root);
    $source = file_get_contents(battleStagePath($root));
    $session = EditorSession::open($root);
    $index = battleStageIndex($session);
    if ($paired) {
        $session->selectCutscenePresentation('cutscenes/effect', $index, 'graphical');
    }
    $apply = static fn(array $row, string $value) => $session->applyDatabaseRecord('cutscenes/effect', $index, $row['key'], $value);

    $timeline = $session->describeCutsceneTimeline('cutscenes/effect', $index);
    expect($timeline)->toMatchArray(['ownedStage' => false, 'stageContext' => 'battle', 'canHaveStage' => true])
        ->and($timeline['stage'])->toMatchArray(['canvas' => '160, 90', 'startFrame' => 0, 'restoreFrame' => 10])
        ->and(array_column($timeline['stage']['camera'], 'id'))->toBe(['wide', 'close'])
        ->and(array_column($timeline['stage']['covers'], 'id'))->toBe(['open', 'clear', 'exit'])
        ->and($timeline['stage']['subjects'][0]['points'][0]['id'])->toBe('blade')
        ->and($timeline['tracks'][0]['art'])->toMatchArray(['anchor' => 'stage', 'subject' => 'striker'])
        ->and($timeline['tracks'][0]['art']['pivotDefaults'])->toBe(['stage' => ['x' => 0.5, 'y' => 0.5]]);
    $context = ['category' => 'cutscenes/effect', 'index' => $index];
    expect(array_column($session->listReferences(null, 'stage_subjects', $context), 'value'))->toBe(['striker'])
        ->and(array_column($session->listReferences(null, 'stage_attachments', $context), 'value'))->toBe(['blade']);

    $apply(battleStageRows($session, $index, ['stage.camera'])['Camera key 2 Zoom'], '2');
    $apply(battleStageRows($session, $index, ['stage.covers'])['Cover key 1 Opacity'], '0.5');
    $apply(battleStageRows($session, $index, ['stage.subjects'])['Subject 1 Position'], '70, 82');
    $apply(battleStageRows($session, $index, ['tracks'])['Track 1 Offset'], '3, -4');
    foreach (range(1, 4) as $step) {
        $session->undo();
    }
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents(battleStagePath($root)))->toBe($source);
    foreach (range(1, 4) as $step) {
        $session->redo();
    }
    expect($session->saveAll()['failures'])->toBe([]);

    $saved = require battleStagePath($root);
    $sequence = $paired ? $saved['presentations']['graphical'] : $saved;
    expect($sequence['stage']['camera'][1]['zoom'])->toEqual(2)
        ->and($sequence['stage']['covers'][0]['opacity'])->toEqual(0.5)
        ->and($sequence['stage']['subjects'][0]['position'])->toBe(['x' => 70, 'y' => 82])
        ->and($sequence['tracks'][0]['placement'])->toBe(['subject' => 'striker', 'attachment' => 'blade', 'position' => ['x' => 3, 'y' => -4]])
        ->and($sequence['cues'])->toBe(battleStageCutInSequence()['cues'])
        ->and(file_get_contents(battleStagePath($root)))->toContain('// Keep the authored cut-in comment.');
    if ($paired) {
        expect($saved['presentations']['terminal'])->toBe($before['presentations']['terminal']);
    }
    CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'striking-cut-in')->compileEffect(EffectPresentation::GRAPHICAL, true);
})->with([false, true]);

it('binds the staged effect through the existing animation selector and plays it as the Engine selects the source lane', function () {
    $root = battleStageProject(bound: false);
    $session = EditorSession::open($root);
    $index = battleStageIndex($session);
    expect($session->describeCutsceneTimeline('cutscenes/effect', $index))->toMatchArray(['ownedStage' => true, 'stageContext' => 'stage'])
        ->and(array_column($session->listReferences(null, 'effects'), 'value'))->toContain('striking-cut-in');
    $source = array_find($session->readDatabaseRecord('animations', 0)['rows'], static fn(array $row): bool => $row['label'] === 'Caster Effect');
    $session->applyDatabaseRecord('animations', 0, $source['key'], 'striking-cut-in');
    expect($session->describeCutsceneTimeline('cutscenes/effect', $index))->toMatchArray(['ownedStage' => false, 'stageContext' => 'battle'])
        ->and($session->saveAll()['failures'])->toBe([])
        ->and((require $root . '/assets/Data/animations.php')[0]['sourceEffect'])->toBe('striking-cut-in');

    // The battle preview runs the Engine's own animation selection and command timeline.
    $battle = $session->readEffectBattlePreview($index, 0, authoredFrame: 4);
    expect($battle['lane'])->toBe('source')
        ->and($battle['phase'])->toBe('source')
        ->and(array_column($battle['canvas']['images'] ?? [], 'asset'))->toContain('Graphics/Effects/striker.png');
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'striking-cut-in');
    $plan = new BattleCommandTimeline(new BattleTurnTimings(.1, 1, .1, .1, .1, .1, .1),
        $asset->compileEffect(EffectPresentation::GRAPHICAL, true));
    expect($plan->hasCinematicStage('source'))->toBeTrue()->and($plan->hasCinematicStage('target'))->toBeFalse();

    // The graphical stage canvas the timeline editor shows uses the same battle admission.
    $compiled = $asset->compileEffect(EffectPresentation::GRAPHICAL, true);
    $preview = $session->readCutscenePreview('cutscenes/effect', $index, 4, 24, 3);
    expect($preview['stageCanvasError'])->toBeNull()
        ->and($preview['stageCanvas'])->toBe(EffectPreviewContext::BATTLE->createStage(EffectPresentation::GRAPHICAL)
            ->renderCanvas($compiled, $root . '/assets', 4, 160, 90)->toArray())
        ->and($preview['cues'])->toBe([]);
});

it('previews an independent Terminal sequence of a paired cut-in with battle admission and its own clock', function () {
    $root = battleStageProject(true);
    $session = EditorSession::open($root);
    $index = battleStageIndex($session);
    $session->selectCutscenePresentation('cutscenes/effect', $index, 'terminal');
    $preview = $session->readCutscenePreview('cutscenes/effect', $index, 1, 24, 3);
    expect($session->describeCutsceneTimeline('cutscenes/effect', $index))->toMatchArray(['stageContext' => 'battle', 'canHaveStage' => false, 'stage' => null])
        ->and($preview)->toMatchArray(['totalFrames' => 4, 'fps' => 6, 'stageCanvas' => null, 'stageCanvasError' => null])
        ->and(array_column($preview['cues'], 'id'))->toBe(['tap']);
});

it('refuses source edits on a read-only staged cut-in before writing, while still previewing it', function () {
    $root = battleStageProject(true, true);
    $before = sourceHashTree($root);
    $session = EditorSession::open($root);
    $index = battleStageIndex($session);
    $session->selectCutscenePresentation('cutscenes/effect', $index, 'graphical');
    $zoom = battleStageRows($session, $index, ['stage.camera'])['Camera key 2 Zoom'];
    expect($session->readCutscenePreview('cutscenes/effect', $index, 4, 24, 3)['stageCanvas']['images'])->not->toBe([])
        ->and(fn() => $session->setCutsceneStage('cutscenes/effect', $index, false))->toThrow(SessionRefusal::class, 'read-only')
        ->and(fn() => $session->applyDatabaseRecord('cutscenes/effect', $index, $zoom['key'], '3'))->toThrow(SessionRefusal::class)
        ->and(sourceHashTree($root))->toBe($before);
});

it('previews a bound staged effect in battle in the Terminal Editor, and B moves it to its owned stage and back', function () {
    $root = battleStageProject();
    $editor = cutscenesEditor($root, 160, 50);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::EFFECT);
    callEditorMethod($editor, 'selectCutsceneById', 'striking-cut-in');
    setEditorProperty($editor, 'cutsceneFocus', CutscenesScreen::PANE_PREVIEW);
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'striking-cut-in');
    expect(callEditorMethod($editor, 'createEffectPreviewStage', $asset))->toMatchObject(['forBattle' => true, 'forStage' => false]);
    callEditorMethod($editor, 'startTimelinePreview', false);
    expect(getEditorProperty($editor, 'timelinePreview'))->not->toBeNull()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('in battle');

    pressKeys($editor, 'b');
    expect(callEditorMethod($editor, 'createEffectPreviewStage', $asset))->toMatchObject(['forBattle' => false, 'forStage' => true])
        ->and(getEditorProperty($editor, 'timelinePreview'))->toBeNull()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('no cues');
    // An owned stage has no battle caster to move.
    pressKeys($editor, 'd');
    expect(getEditorProperty($editor, 'isEffectPreviewCasterOnLeft'))->toBeTrue()
        ->and(callEditorMethod($editor, 'createEffectPreviewStage', $asset)->forStage)->toBeTrue();
    pressKeys($editor, 'b');
    expect(callEditorMethod($editor, 'createEffectPreviewStage', $asset)->forBattle)->toBeTrue()
        ->and(getEditorProperty($editor, 'timelinePreview'))->not->toBeNull();
});

it('chooses each consumer by the shared rule and offers only consumers that can play its declaration', function () {
    $root = battleStageProject(bound: false);
    $workspace = ProjectWorkspace::fromProject($root);
    $asset = $workspace->cutscenes->find(CutsceneType::EFFECT, 'striking-cut-in');
    expect(EffectPreviewContext::findDefault($asset, $workspace))->toBe(EffectPreviewContext::STAGE)
        ->and(EffectPreviewContext::findDefault($asset, null))->toBe(EffectPreviewContext::STAGE)
        ->and(EffectPreviewContext::findDefault($workspace->cutscenes->find(CutsceneType::EFFECT, 'dusk-slash'), $workspace))->toBe(EffectPreviewContext::BATTLE)
        ->and(EffectPreviewContext::STAGE->getAlternative(true))->toBe(EffectPreviewContext::BATTLE)
        ->and(EffectPreviewContext::BATTLE->getAlternative(true))->toBe(EffectPreviewContext::STAGE)
        ->and(EffectPreviewContext::BATTLE->getAlternative(false))->toBe(EffectPreviewContext::FIELD)
        ->and(EffectPreviewContext::STAGE->acceptsStageDeclaration(false))->toBeFalse()
        ->and(EffectPreviewContext::FIELD->acceptsStageDeclaration(true))->toBeFalse()
        ->and(EffectPreviewContext::BATTLE->acceptsStageDeclaration(true))->toBeTrue()
        ->and(EffectPreviewContext::BATTLE->createStage(EffectPresentation::TERMINAL))->toBeInstanceOf(EffectPreviewStage::class);
});
