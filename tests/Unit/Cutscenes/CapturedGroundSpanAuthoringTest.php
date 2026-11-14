<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectGroundSpan;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\Formation\BattleTargeting;
use Ichiloto\Engine\Battle\Formation\BattleTargetShape;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattleEffectAnchorSnapshot;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextureStrip;

/**
 * The Engine's captured ground span (`groundSpan`) on an effect timeline's image track: kept byte for byte
 * while untouched, authored through the graphical editor's shared rows by the names and choices the Engine
 * exports, left as written by the Terminal editor, judged by the Engine at save in the Engine's words, and
 * previewed in battle as the Engine composes it, with the Engine's own inspection of the troop previewed against.
 * Synthetic timelines only.
 */

/** The graphical editor's session on an effect's graphical sequence, with its record rows beside its track rows. */
function openGroundSpanSession(string $root, string $id): array
{
    [$session, $index, $row, $apply] = openEffectTrackSession($root, $id);
    $session->selectCutscenePresentation('cutscenes/effect', $index, EffectPresentation::GRAPHICAL->value);
    $find = static fn(string $field, array $frame): ?array => array_find($session->readDatabaseRecord('cutscenes/effect', $index, $frame)['rows'],
        static fn(array $entry): bool => ($entry['key']['field'] ?? null) === $field);
    $setRecord = static fn(string $field, string $value): array => $session->applyDatabaseRecord('cutscenes/effect', $index,
        $find($field, [])['key'] ?? throw new RuntimeException("No row {$field}."), $value);

    return [$session, $index, $row, $apply, $find, $setRecord];
}

/** What a save was refused with, whether the session refuses it outright or reports it among the save's failures. */
function readSaveRefusal(EditorSession $session): string
{
    try {
        return implode(' ', array_map(static fn(mixed $failure): string => (string) json_encode($failure), $session->saveAll()['failures']));
    } catch (SessionRefusal $refusal) {
        return $refusal->getMessage();
    }
}

/**
 * The rows of an effect's first track that edit a field the Engine lists as conflicting with a ground span, by
 * their ids, in row order.
 *
 * @return list<string>
 */
function listGroundSpanConflictRows(EditorSession $session, int $index): array
{
    $owners = implode('|', array_map(ProjectRecordDatabase::getFieldToken(...), EffectGroundSpan::exportContract()['conflictingTrackFields']));
    $ids = array_map(static fn(array $row): string => (string) ($row['key']['field'] ?? ''), $session->readDatabaseRecord('cutscenes/effect', $index, ['tracks'])['rows']);

    return array_values(array_filter($ids, static fn(string $id): bool => preg_match('/^track0(' . $owners . ')/', $id) === 1));
}

/**
 * The Engine's capture for the command a session last previewed in the graphical battle, at one of its frames:
 * the command preview behind the session's, and the caster's ground and the selected targets' as held.
 *
 * @return array{0: \Ichiloto\Engine\Battle\Presentation\BattleCommandPreview, 1: BattleEffectAnchorSnapshot}
 */
function readGroundSpanCapture(EditorSession $session, int $frame, bool $reducedMotion): array
{
    $preview = new ReflectionProperty($session, 'effectBattlePreviews')->getValue($session)[EffectPresentation::GRAPHICAL->value]['preview'];
    $command = new ReflectionProperty($preview, 'preview')->getValue($preview);
    $playback = new ReflectionMethod($command, 'createPlayback')->invoke($command, $reducedMotion, static function (): void {});
    $playback->update($frame / BattleCommandTimeline::FPS);

    return [$command, $playback->getCapturedAnchorSnapshot('snapshot') ?? throw new RuntimeException('The command captured nothing.')];
}

/** The texture strip composite of a preview frame, when it has one. */
function findGroundSpanComposite(array $frame): ?array
{
    return array_find($frame['canvas']['composites'] ?? [],
        static fn(array $composite): bool => ($composite['operations'][0]['type'] ?? null) === 'texture_strip');
}

/** Ground Lance cast by a line shot at two enemies of one lane, each standing at the canvas column given. */
function groundLanceLineProject(array $columns): string
{
    $root = groundLanceProject();
    writeSkillRecords($root, new BasicSkill('Piercing shot', '', '', 0, 0, occasion: Occasion::BATTLE_SCREEN, animationId: 1,
        battleTargeting: new BattleTargeting(BattleTargetShape::LINE, ignoreCover: true)));
    $actorPath = $root . '/assets/Data/Actors/Kaelion.php';
    $actor = require $actorPath;
    $actor['data']['attackSkill'] = 'Piercing shot';
    file_put_contents($actorPath, '<?php return ' . var_export($actor, true) . ';');
    $troopPath = $root . '/assets/Data/troops.php';
    $troops = require $troopPath;
    $troops[0]['enemies'] = array_map(static fn(int|float $column, int $at): array => ['enemy' => 'Regular Bat', 'position' => [15, 7 + $at],
        'battlePosition' => ['row' => $at === 0 ? 'front' : 'back', 'lane' => 0],
        'graphicalPlacement' => ['x' => $column, 'depth' => 220, 'width' => 275, 'height' => 190]], $columns, array_keys($columns));
    file_put_contents($troopPath, '<?php return ' . var_export($troops, true) . ';');

    return $root;
}

it('opens a declared ground span with the rows that edit it, and saves it untouched byte for byte', function () {
    $root = groundLanceProject();
    $path = effectTimelinePath($root, 'ground-lance');
    [$session, $index, $row, , $find] = openGroundSpanSession($root, 'ground-lance');
    $art = $session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art'];
    $contract = EffectGroundSpan::exportContract();

    expect($row('track0GroundSpantip'))->toMatchArray(['label' => 'Track 1 Ground Span Tip (empty: no ground span)', 'value' => '0.1328125, 0.46875'])
        // The orientation is chosen among the Engine's own, and reads as the Engine's default while the file names none.
        ->and($row('track0GroundSpanorientation'))->toMatchArray(['label' => 'Track 1 Ground Span Orientation',
            'value' => $contract['defaultOrientation'], 'kind' => 'options', 'options' => ['', ...$contract['orientations']]])
        ->and($row('track0AnchorLifetime')['value'])->toBe('target-capture')
        ->and($row('track0AnchorLifetime')['options'])->toContain('', 'effect-start', 'target-capture')->not->toContain('follow')
        ->and($find($contract['captureField'], []))->toMatchArray(['label' => 'Target Capture Frame', 'value' => '2'])
        // No row is offered for a field the Engine refuses beside a ground span, while the track holds none of them.
        ->and(listGroundSpanConflictRows($session, $index))->toBe([])
        // The image-cell picker's art names the tip and the orientation beside the pivot, each with the row key that edits it.
        ->and($art)->toMatchArray(['pivot' => '0.8828125, 0.53125', 'groundSpanTip' => '0.1328125, 0.46875', 'groundSpanTipKey' => $row('track0GroundSpantip')['key'],
            'groundSpanOrientation' => $contract['defaultOrientation'], 'groundSpanOrientationKey' => $row('track0GroundSpanorientation')['key'],
            'groundSpanOrientationOptions' => $contract['orientations']])
        ->and($session->listUnsavedChanges())->toBe([])
        ->and($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(groundLanceTimeline());
});

it('leaves a ground span as written in the Terminal editor, which has no row for it and keeps the rows it has', function () {
    $root = groundLanceProject();
    $path = effectTimelinePath($root, 'ground-lance');
    $editor = openEffectEditor($root, 'ground-lance');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'ground-lance');
    $asset->hydrate();

    expect($asset->readOnlyReason())->toBeNull()
        ->and($asset->partner()['presentations']['graphical']['tracks'][0]['groundSpan'])->toBe(['tip' => ['x' => 0.1328125, 'y' => 0.46875]])
        ->and($asset->partner()['presentations']['graphical']['targetCaptureFrame'])->toBe(2);

    // The terminal sequence, then the graphical one: edits beside the span, in the rows the Terminal editor has.
    setCutsceneField($editor, 'lengthFrames', '5');
    setCutsceneField($editor, '@sequence', 'graphical');
    setCutsceneField($editor, 'restFrame', '4');
    expect(cutsceneFieldIds($editor))->not->toContain('targetCaptureFrame');
    openCutsceneFrame($editor, 'commandListTracks');
    $trackRows = array_values(array_filter(cutsceneFieldIds($editor), static fn(string $id): bool => str_starts_with($id, 'track0') && ! str_contains($id, 'Keyframe')));
    expect($trackRows)->toContain('track0Pivot', 'track0Attachment', 'track0Facing', 'track0Toward', 'track0Cellswidth')
        ->not->toContain('track0GroundSpantip', 'track0GroundSpanorientation', 'track0AnchorLifetime', 'track0Span');
    setCutsceneField($editor, 'track0Id', 'lane-light');

    expect($asset->save())->toBeTrue()
        ->and(file_get_contents($path))->toBe(str_replace(["'lengthFrames' => 4", "'restFrame' => 3", "'id' => 'lane-art'"],
            ["'lengthFrames' => 5", "'restFrame' => 4", "'id' => 'lane-light'"], groundLanceTimeline()))
        ->and($asset->compileEffect(EffectPresentation::GRAPHICAL, true)->playbackSegments[0]['drawCommands'][0]['payload']['groundSpan'])
        ->toBe(['tip' => ['x' => 0.1328125, 'y' => 0.46875]]);
});

it('moves the tip and edits beside it touching only what changed, and undo and redo restore the bytes', function () {
    $root = groundLanceProject();
    $path = effectTimelinePath($root, 'ground-lance');
    [$session, , $row, $apply, , $setRecord] = openGroundSpanSession($root, 'ground-lance');
    $edited = str_replace(["['tip' => ['x' => 0.1328125, 'y' => 0.46875]]", "'pivot' => ['x' => 0.8828125, 'y' => 0.53125]", "'targetCaptureFrame' => 2", "'duration' => 8"],
        ["['tip' => ['x' => 0.25, 'y' => 0.375]]", "'pivot' => ['x' => 0.9375, 'y' => 0.5]", "'targetCaptureFrame' => 3", "'duration' => 7"], groundLanceTimeline());

    expect($apply('track0GroundSpantip', '0.25, 0.375')['changed'])->toBeTrue()
        ->and($row('track0GroundSpantip')['value'])->toBe('0.25, 0.375');
    $apply('track0Pivot', '0.9375, 0.5');
    $setRecord('targetCaptureFrame', '3');
    $apply('track0Keyframe0Duration', '7');
    expect($session->saveAll()['failures'])->toBe([])
        // The comments, the spacing and the two keys on one line are the author's, and stay.
        ->and(file_get_contents($path))->toBe($edited);

    foreach (range(1, 4) as $ignored) {
        $session->undo();
    }
    expect($row('track0GroundSpantip')['value'])->toBe('0.1328125, 0.46875')
        ->and($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(groundLanceTimeline());

    foreach (range(1, 4) as $ignored) {
        $session->redo();
    }
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe($edited);
});

it('refuses a tip that is not a point of the image, changing nothing', function (string $tip) {
    $root = groundLanceProject();
    [$session, , $row, $apply] = openGroundSpanSession($root, 'ground-lance');

    expect(fn() => $apply('track0GroundSpantip', $tip))->toThrow(SessionRefusal::class, 'two numbers from 0 to 1')
        ->and($row('track0GroundSpantip')['value'])->toBe('0.1328125, 0.46875')
        ->and($session->listUnsavedChanges())->toBe([]);
})->with(['1.5, 0.5', '0.5', 'left, middle', '-0.1, 0.5']);

it('chooses which way up the span holds its image among the Engine\'s orientations, touching only that key', function () {
    $root = groundLanceProject();
    $path = effectTimelinePath($root, 'ground-lance');
    [$session, $index, $row, $apply, $find] = openGroundSpanSession($root, 'ground-lance');
    $span = static fn(): array => (require $path)['presentations']['graphical']['tracks'][0]['groundSpan'];
    $tip = ['x' => 0.1328125, 'y' => 0.46875];

    expect($apply('track0GroundSpanorientation', 'upright')['changed'])->toBeTrue()
        ->and($session->saveAll()['failures'])->toBe([])
        // One key joins the span the author wrote; the line, its spacing and its comment stay.
        ->and(file_get_contents($path))->toBe(str_replace("['tip' => ['x' => 0.1328125, 'y' => 0.46875]]",
            "['tip' => ['x' => 0.1328125, 'y' => 0.46875], 'orientation' => 'upright']", groundLanceTimeline()))
        ->and($row('track0GroundSpanorientation')['value'])->toBe('upright')
        ->and($session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art']['groundSpanOrientation'])->toBe('upright');
    $session->undo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(groundLanceTimeline());
    $session->redo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and($span())->toBe(['tip' => $tip, 'orientation' => 'upright']);

    // The Engine's default may be named, and the empty choice leaves the key out again.
    $apply('track0GroundSpanorientation', 'rigid');
    expect($session->saveAll()['failures'])->toBe([])
        ->and($span())->toBe(['tip' => $tip, 'orientation' => 'rigid']);
    $apply('track0GroundSpanorientation', '');
    expect($session->saveAll()['failures'])->toBe([])
        ->and($span())->toBe(['tip' => $tip])
        ->and($row('track0GroundSpanorientation')['value'])->toBe(EffectGroundSpan::exportContract()['defaultOrientation']);

    // An orientation the Engine does not have is the Engine's to refuse, and the file stays.
    $written = (string) file_get_contents($path);
    $apply('track0GroundSpanorientation', 'sideways');
    expect(readSaveRefusal($session))->toContain('groundSpan.orientation must be rigid or upright.')
        ->and(file_get_contents($path))->toBe($written);
    $session->undo();

    // The span is its tip: cleared, its orientation goes with it and the row for one is offered no longer.
    $apply('track0GroundSpanorientation', 'upright');
    $apply('track0GroundSpantip', '');
    expect($session->saveAll()['failures'])->toBe([])
        ->and((require $path)['presentations']['graphical']['tracks'][0])->not->toHaveKey('groundSpan')
        ->and($find('track0GroundSpanorientation', ['tracks']))->toBeNull();
});

it('turns the ground span off by clearing its tip and on again by naming one, the key going and returning whole', function () {
    $root = groundLanceProject();
    $path = effectTimelinePath($root, 'ground-lance');
    [$session, $index, $row, $apply, $find] = openGroundSpanSession($root, 'ground-lance');
    $track = static fn(): array => (require $path)['presentations']['graphical']['tracks'][0];

    $apply('track0GroundSpantip', '');
    expect($session->saveAll()['failures'])->toBe([])
        ->and($track())->not->toHaveKey('groundSpan')
        ->and($track()['pivot'])->toBe(['x' => 0.8828125, 'y' => 0.53125])
        ->and(file_get_contents($path))->not->toContain('groundSpan')
        ->toContain('// The lane: its rear on the caster\'s ground, its tip on the farthest target\'s.', '/* measured on the sheet */',
            "'targetCaptureFrame' => 2, // the targets and the caster's ground are held from here")
        // Off, the tip's row stays empty where the track can still take a span, and the rows a span leaves no place for are back.
        ->and($row('track0GroundSpantip')['value'])->toBe('')
        ->and(listGroundSpanConflictRows($session, $index))->toContain('track0Facing', 'track0Toward', 'track0Cellswidth', 'track0Span')
        ->and($session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art']['groundSpanTip'])->toBe('');

    $apply('track0GroundSpantip', '0.0625, 0.75');
    expect($session->saveAll()['failures'])->toBe([])
        ->and($track()['groundSpan'])->toBe(['tip' => ['x' => 0.0625, 'y' => 0.75]])
        ->and(listGroundSpanConflictRows($session, $index))->toBe([]);

    $session->undo();
    $session->undo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(groundLanceTimeline());
});

it('offers a row for a field the Engine refuses beside a ground span only while the track holds it, the Engine naming each', function () {
    $root = groundLanceProject();
    $path = effectTimelinePath($root, 'ground-lance');
    // Written by hand beside the span: two of the fields the Engine lists as conflicting with one.
    $conflicted = str_replace("'depth' => 'behind',", "'depth' => 'behind', 'facing' => 'west', 'span' => true,", groundLanceTimeline());
    file_put_contents($path, $conflicted);
    [$session, $index, , $apply] = openGroundSpanSession($root, 'ground-lance');

    expect(listGroundSpanConflictRows($session, $index))->toBe(['track0Facing', 'track0Span']);
    $apply('track0Keyframe0Duration', '7');
    expect(readSaveRefusal($session))->toContain('tracks[lane-art].groundSpan: conflicting track fields: span, facing. The captured endpoints own placement.')
        ->and(file_get_contents($path))->toBe($conflicted);

    // Each row clears its field and goes; the Engine then names what is left, and admits the track once nothing is.
    $apply('track0Span', 'false');
    expect(listGroundSpanConflictRows($session, $index))->toBe(['track0Facing'])
        ->and(readSaveRefusal($session))->toContain('conflicting track fields: facing.')->not->toContain('span, facing');
    $apply('track0Facing', '');
    expect(listGroundSpanConflictRows($session, $index))->toBe([])
        ->and($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(str_replace("'duration' => 8", "'duration' => 7", groundLanceTimeline()));
});

it('authors a ground span on a plain image track through the shared rows, the Engine judging it at save in its own words', function () {
    $root = effectBattlePreviewProject();
    $path = effectTimelinePath($root, 'dusk-slash');
    $original = (string) file_get_contents($path);
    [$session, $index, $row, $apply, $find, $setRecord] = openGroundSpanSession($root, 'dusk-slash');

    // The tip is offered once the track holds every value the Engine requires of a track that has a span.
    expect($find('track0GroundSpantip', ['tracks']))->toBeNull();
    foreach (EffectGroundSpan::exportContract()['requiredTrackValues'] as $field => $value) {
        expect($find('track0GroundSpantip', ['tracks']))->toBeNull();
        $apply('track0' . ProjectRecordDatabase::getFieldToken($field), $value);
    }
    expect($row('track0GroundSpantip')['value'])->toBe('')
        ->and($find('track0GroundSpanorientation', ['tracks']))->toBeNull();
    $apply('track0Pivot', '0.9, 0.5');
    $apply('track0GroundSpantip', '0.1, 0.5');

    // The track still has its facing, whose row stays to clear it, and the capture frame is missing: the Engine names each.
    expect(listGroundSpanConflictRows($session, $index))->toBe(['track0Facing'])
        ->and(readSaveRefusal($session))->toContain('tracks[stroke-art].groundSpan: conflicting track fields: facing. The captured endpoints own placement.')
        ->and(file_get_contents($path))->toBe($original);
    $apply('track0Facing', '');
    expect(readSaveRefusal($session))->toContain('tracks[stroke-art].groundSpan: requires targetCaptureFrame.')
        ->and(file_get_contents($path))->toBe($original);
    $setRecord('targetCaptureFrame', '0');

    expect($session->saveAll()['failures'])->toBe([]);
    $saved = require $path;
    expect($saved['presentations']['terminal'])->toBe((eval('?>' . $original))['presentations']['terminal'])
        ->and($saved['presentations']['graphical']['targetCaptureFrame'])->toBe(0)
        ->and($saved['presentations']['graphical']['tracks'][0])->toBe([
            'id' => 'stroke-art', 'type' => 'image', 'asset' => 'Graphics/Effects/dusk-slash.png', 'sheet' => ['columns' => 2, 'rows' => 1],
            'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]],
            'anchor' => 'target-group', 'attachment' => 'ground', 'anchorLifetime' => 'target-capture', 'fit' => 'contain',
            'pivot' => ['x' => 0.9, 'y' => 0.5], 'groundSpan' => ['tip' => ['x' => 0.1, 'y' => 0.5]],
        ]);

    // What the Engine refuses of a ground span afterwards is refused the same way, and the saved file stays.
    $written = (string) file_get_contents($path);
    foreach ([
        [['track0GroundSpantip' => '0.9, 0.5'], 'tracks[stroke-art].groundSpan: source tip must differ from its rear pivot.'],
        [['track0Keyframe1Positionx' => '1', 'track0Keyframe1Positiony' => '1'], 'groundSpan endpoints cannot be shifted by keyframe offsets.'],
        [['targetCaptureFrame' => '1'], 'snapshot-bound images must start at or after targetCaptureFrame.'],
        // An emptied capture frame leaves the key out of the file, which the span cannot do without.
        [['targetCaptureFrame' => ''], 'tracks[stroke-art].groundSpan: requires targetCaptureFrame.'],
        [['track0Fit' => 'stretch'], 'tracks[stroke-art].groundSpan: fit must be contain.'],
        [['track0Pivot' => ''], 'tracks[stroke-art].groundSpan: pivot must declare the measured source rear.'],
        [['track0AnchorLifetime' => 'effect-start'], 'tracks[stroke-art].groundSpan: anchorLifetime must be target-capture.'],
        [['track0Attachment' => 'head'], 'tracks[stroke-art].groundSpan: attachment must be ground.'],
    ] as [$edits, $reason]) {
        foreach ($edits as $field => $value) {
            str_starts_with($field, 'track') ? $apply($field, $value) : $setRecord($field, $value);
        }
        expect(readSaveRefusal($session))->toContain($reason)
            ->and(file_get_contents($path))->toBe($written);
        foreach ($edits as $ignored) {
            $session->undo();
        }
    }
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe($written);
});

it('previews the ground span as the Engine composes it, only for a canvas that draws texture strips and composites, and draws a still of it', function (bool $reducedMotion) {
    $root = groundLanceProject();
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'ground-lance');
    $refusal = 'Renderer requires compositing and texture-strip support for a captured ground span.';

    $without = $session->readEffectBattlePreview($index, 0, $reducedMotion, authoredFrame: 5);
    $with = $session->readEffectBattlePreview($index, 0, $reducedMotion, authoredFrame: 5, textureStrip: true);
    $uncomposited = $session->readEffectBattlePreview($index, 0, $reducedMotion, authoredFrame: 5, textureStrip: true, compositing: false);
    $again = $session->readEffectBattlePreview($index, 0, $reducedMotion, authoredFrame: 5);

    // The span is composed for the request whose canvas draws both, and for no other: nothing stands in for it.
    expect(findGroundSpanComposite($without))->toBeNull()
        ->and($without['diagnostics'])->toBe([$refusal])
        ->and(findGroundSpanComposite($uncomposited))->toBeNull()
        ->and($uncomposited['diagnostics'])->toBe([$refusal])
        ->and($again)->toBe($without)
        ->and($with['diagnostics'])->toBe([]);
    $composite = findGroundSpanComposite($with);
    expect($composite)->not->toBeNull();
    $operation = $composite['operations'][0];
    $strip = new CanvasTextureStrip($operation['geometry']);

    // The Engine's own capture for this command: the caster's ground and the farthest selected target's.
    [$command, $snapshot] = readGroundSpanCapture($session, $with['frame'], $reducedMotion);
    $rear = $snapshot->getCasterGround();
    $tip = $snapshot->getGroundSpanEndpoint();
    [$rearU, $rearV] = $strip->getSourcePoint($rear->x - $composite['destination']['x'], $rear->y - $composite['destination']['y']);
    [$tipU, $tipV] = $strip->getSourcePoint($tip->x - $composite['destination']['x'], $tip->y - $composite['destination']['y']);

    expect([$rearU, $rearV])->toEqualWithDelta([0.8828125, 0.53125], 1e-9)
        ->and([$tipU, $tipV])->toEqualWithDelta([0.1328125, 0.46875], 1e-9)
        // One uniform scale over the whole 64 by 16 image, on two straight knots.
        ->and($operation['sourceRect'])->toBe(['x' => 0, 'y' => 0, 'width' => 64, 'height' => 16])
        ->and($strip->length / 64)->toEqualWithDelta($strip->height / 16, 1e-9)
        // The rear is the centre of texel (56, 8) and the tip of texel (8, 7): 48 texels along and one across.
        ->and($strip->length / 64)->toEqualWithDelta(hypot($tip->x - $rear->x, $tip->y - $rear->y) / hypot(48, 1), 1e-9)
        ->and($strip->knots)->toBe([['u' => 0.0, 'offset' => 0.0], ['u' => 1.0, 'offset' => 0.0]]);

    // A still of the same frame: the CPU raster draws the rear's texel on the caster's ground and the tip's on the target's.
    $raster = new CanvasStillRaster($root . '/assets');
    $still = $raster->rasterize($command->getFrameAtIndex($with['frame'], $reducedMotion, textureStrip: true)->canvas);
    $pixelAt = static function (Vector2 $ground) use ($still): array {
        $pixel = imagecolorat($still, (int) floor($ground->x), (int) floor($ground->y));

        return [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255];
    };
    expect($pixelAt($rear))->toBe(getGroundLanceTexel(56, 8))
        ->and($pixelAt($tip))->toBe(getGroundLanceTexel(8, 7))
        ->and($raster->diagnostics)->toBe([])
        ->and($session->listUnsavedChanges())->toBe([])
        ->and(file_get_contents(effectTimelinePath($root, 'ground-lance')))->toBe(groundLanceTimeline());
})->with([false, true]);

it('previews an image drawn the other way round turned over when rigid and upright when the span is upright, a still showing each', function () {
    $root = groundLanceProject();
    [$session, $index, , $apply] = openGroundSpanSession($root, 'ground-lance');
    // The same image with its rear and tip changed about: drawn for a cast toward the other side.
    $apply('track0Pivot', '0.1328125, 0.46875');
    $apply('track0GroundSpantip', '0.8828125, 0.53125');
    $read = static function () use ($session, $index, $root): array {
        $frame = $session->readEffectBattlePreview($index, 0, authoredFrame: 5, textureStrip: true);
        $composite = findGroundSpanComposite($frame) ?? throw new RuntimeException('The frame has no ground span.');
        $operation = $composite['operations'][0];
        [$command, $snapshot] = readGroundSpanCapture($session, $frame['frame'], false);
        $still = new CanvasStillRaster($root . '/assets')->rasterize($command->getFrameAtIndex($frame['frame'], false, textureStrip: true)->canvas);
        $showsAt = static function (Vector2 $ground) use ($still): array {
            $pixel = imagecolorat($still, (int) floor($ground->x), (int) floor($ground->y));

            return [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255];
        };
        $sourceAt = static fn(Vector2 $ground): array => new CanvasTextureStrip($operation['geometry'])
            ->getSourcePoint($ground->x - $composite['destination']['x'], $ground->y - $composite['destination']['y']);

        return ['diagnostics' => $frame['diagnostics'], 'axis' => $operation['geometry']['axis'], 'flips' => [$operation['flipX'], $operation['flipY']],
            'scale' => $operation['geometry']['length'] / 64, 'height' => $operation['geometry']['height'] / 16,
            'rear' => $sourceAt($snapshot->getCasterGround()), 'tip' => $sourceAt($snapshot->getGroundSpanEndpoint()),
            'rearTexel' => $showsAt($snapshot->getCasterGround()), 'tipTexel' => $showsAt($snapshot->getGroundSpanEndpoint())];
    };

    // Rigid, the image is turned right round to lay its rear on the caster: the strip runs back along the screen.
    $rigid = $read();
    expect($rigid['diagnostics'])->toBe([])
        ->and($rigid['axis'][0])->toBeLessThan(0.0)
        ->and($rigid['flips'])->toBe([false, false])
        ->and($rigid['rear'])->toEqualWithDelta([0.1328125, 0.46875], 1e-9)
        ->and($rigid['tip'])->toEqualWithDelta([0.8828125, 0.53125], 1e-9)
        ->and([$rigid['rearTexel'], $rigid['tipTexel']])->toBe([getGroundLanceTexel(8, 7), getGroundLanceTexel(56, 8)]);

    // Upright, the Engine mirrors the image instead: top side up, the same texels on the same grounds, at the same scale.
    $apply('track0GroundSpanorientation', 'upright');
    $upright = $read();
    expect($upright['diagnostics'])->toBe([])
        ->and($upright['axis'][0])->toBeGreaterThan(0.0)
        ->and($upright['flips'])->toBe([true, false])
        // The strip's own column runs the mirrored way: the rear's column is its distance from the image's other edge.
        ->and($upright['rear'])->toEqualWithDelta([1 - 0.1328125, 0.46875], 1e-9)
        ->and($upright['tip'])->toEqualWithDelta([1 - 0.8828125, 0.53125], 1e-9)
        ->and([$upright['rearTexel'], $upright['tipTexel']])->toBe([getGroundLanceTexel(8, 7), getGroundLanceTexel(56, 8)])
        ->and($upright['scale'])->toEqualWithDelta($rigid['scale'], 1e-9)
        ->and($upright['height'])->toEqualWithDelta($upright['scale'], 1e-9);

    // An image whose rear-to-tip line runs down its cell cannot be held top side up along this ground: the frame that
    // would draw it carries the Engine's refusal and no span, and nothing is drawn in its place.
    $apply('track0Pivot', '0.5, 0.1');
    $apply('track0GroundSpantip', '0.5, 0.9');
    $refused = $session->readEffectBattlePreview($index, 0, authoredFrame: 5, textureStrip: true);
    expect(findGroundSpanComposite($refused))->toBeNull()
        ->and($refused['diagnostics'])->toBe(['groundSpan.orientation upright cannot keep this source top above its bottom at the captured endpoints. Use a compatible source or the explicit rigid orientation.'])
        ->and(file_get_contents(effectTimelinePath($root, 'ground-lance')))->toBe(groundLanceTimeline());
});

it('tells the Engine\'s inspection of the troop previewed against on every frame, before the frame that would draw the span', function () {
    // Where the caster stands at rest decides which two columns are equally far on either side of it.
    $measuring = EditorSession::open(groundLanceLineProject([300, 500]));
    $frame = $measuring->readEffectBattlePreview(findEffectIndex($measuring, 'ground-lance'), 0, reducedMotion: true, authoredFrame: 5)['frame'];
    [, $snapshot] = readGroundSpanCapture($measuring, $frame, true);
    $caster = $snapshot->getCasterGround()->x;

    $session = EditorSession::open(groundLanceLineProject([$caster - 150, $caster + 150]));
    $index = findEffectIndex($session, 'ground-lance');
    $tie = 'Captured ground span has opposite equally far endpoints.';
    $first = $session->readEffectBattlePreview($index, 0, reducedMotion: true, textureStrip: true);
    $drawn = $session->readEffectBattlePreview($index, 0, reducedMotion: true, authoredFrame: 5, textureStrip: true);
    $moving = $session->readEffectBattlePreview($index, 0, textureStrip: true);
    $terminal = $session->readEffectBattlePreview($index, 0, reducedMotion: true, presentation: EffectPresentation::TERMINAL);

    // At rest the two selected targets are equally far on opposite sides: told on the first frame, once, in the Engine's words.
    expect($first['targets'])->toBe(['Regular Bat', 'Regular Bat'])
        ->and($first['phase'])->not->toBe('target')
        ->and($first['diagnostics'])->toBe([$tie])
        // The frame that would draw the span fails for the same reason, which is still told once, and draws no span.
        ->and($drawn['diagnostics'])->toBe([$tie])
        ->and(findGroundSpanComposite($drawn))->toBeNull()
        // With motion the caster advances before the capture, so the same troop is no tie: the inspection is of what is previewed.
        ->and($moving['diagnostics'])->toBe([])
        // The Terminal sequence draws no ground span and is told nothing about one.
        ->and($terminal['diagnostics'])->toBe([]);

    // A troop the Engine can span is told nothing, and an effect with no ground span is not inspected for one.
    $plain = EditorSession::open(effectBattlePreviewProject());
    expect($measuring->readEffectBattlePreview(findEffectIndex($measuring, 'ground-lance'), 0, reducedMotion: true, textureStrip: true)['diagnostics'])->toBe([])
        ->and($plain->readEffectBattlePreview(findEffectIndex($plain, 'dusk-slash'), 0, reducedMotion: true)['diagnostics'])->toBe([]);
});

it('reads the compositing choice from the host route beside the other drawing features, composing unless the canvas declines', function () {
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array => $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params]));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => groundLanceProject()]);
    $index = array_find_key($request('database.records', ['category' => 'cutscenes/effect'])['result']['records'],
        static fn(mixed $label): bool => str_contains(strval($label), 'ground-lance'));
    $preview = static fn(array $features): array => $request('cutscenes.effectBattlePreview',
        ['index' => $index, 'frame' => 0, 'authoredFrame' => 5, 'presentation' => 'graphical', 'textureStrip' => true, ...$features])['result'];

    expect(findGroundSpanComposite($preview([])))->not->toBeNull()
        ->and(findGroundSpanComposite($preview(['compositing' => true])))->not->toBeNull()
        ->and(findGroundSpanComposite($preview(['compositing' => false])))->toBeNull()
        ->and($preview(['compositing' => false])['diagnostics'])->toBe(['Renderer requires compositing and texture-strip support for a captured ground span.']);
});

it('keeps a summon timeline\'s ground span as written through edits beside it, with no row of its own', function () {
    $root = summonBattlePreviewProject();
    $path = $root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.timeline.php';
    $source = str_replace(["  'restFrame' => 12,", "'anchor' => 'target', 'attachment' => 'ground', 'pivot' => ['x' => 0.5, 'y' => 1],"], [
        "  'restFrame' => 12,\n  'targetCaptureFrame' => 0, // held from the first frame",
        "'anchor' => 'target-group', 'attachment' => 'ground', 'anchorLifetime' => 'target-capture', 'fit' => 'contain',\n"
        . "      'pivot' => ['x' => 0.875, 'y' => 0.5],  'groundSpan' => ['tip' => ['x' => 0.125, 'y' => 0.5], 'orientation' => 'upright'], /* rear to tip */",
    ], (string) file_get_contents($path));
    file_put_contents($path, $source);
    $session = EditorSession::open($root);
    $rows = static fn(): array => $session->readDatabaseRecord('cutscenes/summon', 0, ['tracks'])['rows'];
    $row = static fn(string $field): ?array => array_find($rows(), static fn(array $entry): bool => ($entry['key']['field'] ?? null) === $field);
    $art = array_find(array_keys($rows()), static fn(int $at): bool => ($rows()[$at]['value'] ?? null) === 'wisp-art');
    $track = intval(preg_replace('/\D/', '', strval($rows()[$art]['key']['field'])));

    // A summon's image rows are a summon's: the span has none, and the file says what it said.
    expect($row("track{$track}GroundSpantip"))->toBeNull()
        ->and($row("track{$track}GroundSpanorientation"))->toBeNull()
        ->and($session->listUnsavedChanges())->toBe([])
        ->and($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe($source);

    $session->applyDatabaseRecord('cutscenes/summon', 0, $row("track{$track}Id")['key'], 'wisp-lane');
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(str_replace("'id' => 'wisp-art'", "'id' => 'wisp-lane'", $source));
    $session->undo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe($source);
});
