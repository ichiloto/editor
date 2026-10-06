<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;

/**
 * A summon's graphical sequence may own a cinematic stage: a presentation
 * space of its own in place of the arena, authored through the same record
 * rows, lists, pickers and undo as the rest of the summon. The terminal
 * sequence never has one. Synthetic fixtures and art only.
 */

/** The rows of one frame of the summon, by label. */
function stageRows(EditorSession $session, array $frame = []): array
{
    $rows = [];

    foreach ($session->readDatabaseRecord('cutscenes/summon', 0, $frame)['rows'] as $row) {
        $rows[trim($row['label'])] ??= $row;
    }

    return $rows;
}

it('gives only the graphical sequence a stage, as one undo step, with rows of its own', function () {
    $session = EditorSession::open(stagedSummonProject());
    $session->selectCutscenePresentation('cutscenes/summon', 0, 'graphical');

    expect(stageRows($session))->not->toHaveKey('Stage Canvas');
    expect($session->setCutsceneStage('cutscenes/summon', 0, true))->toBe(['changed' => true, 'stage' => true]);
    $rows = stageRows($session);
    expect($rows['Stage Canvas']['value'])->toBe('1280, 720')
        ->and($rows['Stage Start Frame']['value'])->toBe('0')
        ->and($rows['Stage Restore Frame']['value'])->toBe('23')
        ->and($rows['Stage Background']['value'])->toBe('black')
        ->and($rows['Stage Subjects']['value'])->toBe('0')
        ->and($rows['Stage Camera']['value'])->toBe('1')
        ->and($rows['Stage Covers']['value'])->toBe('0');

    // The terminal sequence never draws a stage.
    $session->selectCutscenePresentation('cutscenes/summon', 0, 'terminal');
    expect(stageRows($session))->not->toHaveKey('Stage Canvas');
    $session->selectCutscenePresentation('cutscenes/summon', 0, 'graphical');

    $session->undo();
    expect(stageRows($session))->not->toHaveKey('Stage Canvas');
    $session->redo();
    expect(stageRows($session))->toHaveKey('Stage Canvas');
    expect($session->setCutsceneStage('cutscenes/summon', 0, false))->toBe(['changed' => true, 'stage' => false])
        ->and(stageRows($session))->not->toHaveKey('Stage Canvas');
});

it('refuses a stage for a summon whose timeline is shared by every renderer', function () {
    $session = EditorSession::open(cutsceneProject());

    expect(fn() => $session->setCutsceneStage('cutscenes/summon', 0, true))->toThrow(SessionRefusal::class, 'separate lantern-wisp');
});

it('authors a stage subject with a named point, places the art on it, and saves what the Engine plays', function () {
    $root = stagedSummonProject();
    $session = EditorSession::open($root);
    $session->setCutsceneStage('cutscenes/summon', 0, true);
    $session->selectCutscenePresentation('cutscenes/summon', 0, 'graphical');
    $apply = static fn(array $row, string $value) => $session->applyDatabaseRecord('cutscenes/summon', 0, $row['key'], $value);

    // A subject: who stands where, in a box of its own.
    $session->addDatabaseItem('cutscenes/summon', 0, ['frame' => ['stage.subjects']]);
    $apply(stageRows($session, ['stage.subjects'])['Subject 1 Id'], 'visitor');
    $apply(stageRows($session, ['stage.subjects'])['Subject 1 Position'], '640, 600');
    $apply(stageRows($session, ['stage.subjects'])['Subject 1 Size'], '300, 500.5');
    // Its chest, a point within that box.
    $session->addDatabaseItem('cutscenes/summon', 0, stageRows($session, ['stage.subjects'])['Subject 1 Id']['key'], true);
    $point = stageRows($session, ['stage.subjects']);
    $session->applyDatabaseRecordValues('cutscenes/summon', 0, [
        ['key' => $point['Subject 1 Point 1 Id']['key'], 'value' => 'chest'],
        ['key' => $point['Subject 1 Point 1 X']['key'], 'value' => '0.5'],
        ['key' => $point['Subject 1 Point 1 Y']['key'], 'value' => '0.35'],
    ], 'Chest point');

    // The art stands on the stage, on the visitor's chest, picked rather than typed.
    $apply(stageRows($session, ['tracks'])['Track 1 Anchor'], 'stage');
    $track = stageRows($session, ['tracks']);
    expect($track)->toHaveKeys(['Track 1 Subject', 'Track 1 Subject Point', 'Track 1 Offset', 'Track 1 Z Index', 'Track 1 Keyframe 1 Opacity'])
        ->not->toHaveKeys(['Track 1 Facing', 'Track 1 Cell Width', 'Track 1 Attachment']);
    $choices = $session->listReferences(null, 'stage_subjects', ['category' => 'cutscenes/summon', 'index' => 0]);
    expect(array_column($choices, 'value'))->toContain('visitor');
    $apply($track['Track 1 Subject'], 'visitor');
    $apply(stageRows($session, ['tracks'])['Track 1 Subject Point'], 'chest');
    $apply(stageRows($session, ['tracks'])['Track 1 Keyframe 1 Opacity'], '0');

    expect($session->saveAll()['failures'])->toBe([]);
    $graphical = (require $root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.timeline.php')['presentations']['graphical'];
    expect(array_keys($graphical))->toBe(['fps', 'lengthFrames', 'restFrame', 'stage', 'tracks', 'cues'])
        ->and($graphical['stage']['subjects'])->toBe([['id' => 'visitor', 'position' => ['x' => 640, 'y' => 600], 'size' => ['width' => 300, 'height' => 500.5],
            'attachments' => [['id' => 'chest', 'x' => 0.5, 'y' => 0.35]]]])
        ->and($graphical['tracks'][0]['anchor'])->toBe('stage')
        ->and($graphical['tracks'][0]['placement'])->toBe(['subject' => 'visitor', 'attachment' => 'chest'])
        ->and($graphical['tracks'][0]['keyframes'][0]['opacity'])->toBe(0.0);

    $compiled = CutsceneLibrary::fromProject($root)->find(CutsceneType::SUMMON, 'lantern-wisp')->compiledSummon(EffectPresentation::GRAPHICAL);
    expect($compiled->defaults['stage']['subjects'][0]['id'])->toBe('visitor');
});

it('names the stage subjects and their points to pick from, and nothing for a summon without one', function () {
    $session = EditorSession::open(stagedSummonProject());
    $context = ['category' => 'cutscenes/summon', 'index' => 0];

    expect($session->listReferences(null, 'stage_subjects', $context))->toBe([]);
    $session->setCutsceneStage('cutscenes/summon', 0, true);
    $session->selectCutscenePresentation('cutscenes/summon', 0, 'graphical');
    $session->addDatabaseItem('cutscenes/summon', 0, ['frame' => ['stage.subjects']]);
    $session->addDatabaseItem('cutscenes/summon', 0, stageRows($session, ['stage.subjects'])['Subject 1 Id']['key'], true);

    expect(array_column($session->listReferences(null, 'stage_subjects', $context), 'value'))->toBe(['subject'])
        ->and($session->listReferences(null, 'stage_attachments', $context))->toBe([['value' => 'point', 'label' => 'point (on subject)']]);
});

it('describes the stage for a timeline editor: subjects with their boxes and points, camera and cover keys, and where stage art is placed', function () {
    $root = stagedSummonProject();
    $session = EditorSession::open($root);
    $session->selectCutscenePresentation('cutscenes/summon', 0, 'graphical');
    $timeline = $session->describeCutsceneTimeline('cutscenes/summon', 0);
    expect($timeline['canHaveStage'])->toBeTrue()
        ->and($timeline['stage'])->toBeNull();

    $session->setCutsceneStage('cutscenes/summon', 0, true);
    $session->addDatabaseItem('cutscenes/summon', 0, ['frame' => ['stage.subjects']]);
    $session->addDatabaseItem('cutscenes/summon', 0, stageRows($session, ['stage.subjects'])['Subject 1 Id']['key'], true);
    $session->applyDatabaseRecord('cutscenes/summon', 0, stageRows($session, ['tracks'])['Track 1 Anchor']['key'], 'stage');
    $session->applyDatabaseRecord('cutscenes/summon', 0, stageRows($session, ['tracks'])['Track 1 Subject']['key'], 'subject');
    $session->applyDatabaseRecord('cutscenes/summon', 0, stageRows($session, ['tracks'])['Track 1 Keyframe 1 Offset']['key'], '4, -2.5');
    $timeline = $session->describeCutsceneTimeline('cutscenes/summon', 0);

    expect($timeline['stage'])->toMatchArray(['canvas' => '1280, 720', 'startFrame' => 0, 'restoreFrame' => 23])
        ->and($timeline['stage']['subjects'])->toHaveCount(1)
        ->and($timeline['stage']['subjects'][0])->toMatchArray(['id' => 'subject', 'position' => ['x' => 0.0, 'y' => 0.0],
            'size' => ['width' => 100.0, 'height' => 100.0], 'pivot' => ['x' => 0.5, 'y' => 1.0]])
        ->and($timeline['stage']['subjects'][0]['points'][0])->toMatchArray(['id' => 'point', 'x' => 0.5, 'y' => 0.5])
        ->and($timeline['stage']['subjects'][0]['points'][0]['xKey']['field'])->toBe('subject0Attachment0X')
        ->and($timeline['stage']['camera'])->toHaveCount(1)
        ->and($timeline['stage']['camera'][0])->toMatchArray(['id' => 'initial', 'frame' => 0])
        ->and($timeline['stage']['covers'])->toBe([])
        ->and($timeline['tracks'][0]['art'])->toMatchArray(['anchor' => 'stage', 'subject' => 'subject',
            'placement' => ['position' => ['x' => 0.0, 'y' => 0.0], 'size' => null, 'pivot' => ['x' => 0.5, 'y' => 0.5]]])
        ->and($timeline['tracks'][0]['art'])->not->toHaveKey('attachmentOptions')
        ->and($timeline['tracks'][0]['keyframes'][0]['offset'])->toBe(['x' => 4.0, 'y' => -2.5]);

    // The terminal sequence never has one.
    $session->selectCutscenePresentation('cutscenes/summon', 0, 'terminal');
    expect($session->describeCutsceneTimeline('cutscenes/summon', 0))->toMatchArray(['canHaveStage' => false, 'stage' => null]);
});
