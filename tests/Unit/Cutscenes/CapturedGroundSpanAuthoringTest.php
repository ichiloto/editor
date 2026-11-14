<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Review\CanvasStillRaster;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextureStrip;

/**
 * The Engine's captured ground span (`groundSpan`) on an effect timeline's image track: kept byte for byte
 * while untouched, authored through the graphical editor's shared rows, left as written by the Terminal editor,
 * judged by the Engine at save in the Engine's words, and previewed in battle as the Engine composes it. Synthetic
 * timelines only.
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

it('opens a declared ground span with the rows that edit it, and saves it untouched byte for byte', function () {
    $root = groundLanceProject();
    $path = effectTimelinePath($root, 'ground-lance');
    [$session, $index, $row, , $find] = openGroundSpanSession($root, 'ground-lance');
    $art = $session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art'];

    expect($row('track0GroundSpantip'))->toMatchArray(['label' => 'Track 1 Ground Span Tip (empty: no ground span)', 'value' => '0.1328125, 0.46875'])
        ->and($row('track0AnchorLifetime')['value'])->toBe('target-capture')
        ->and($row('track0AnchorLifetime')['options'])->toContain('', 'effect-start', 'target-capture')->not->toContain('follow')
        ->and($find('targetCaptureFrame', []))->toMatchArray(['label' => 'Target Capture Frame', 'value' => '2'])
        // The span over the targets conflicts with a ground span, so the track that has one is not offered it.
        ->and($find('track0Span', ['tracks']))->toBeNull()
        // The image-cell picker's art names the tip beside the pivot, each with the row key that edits it.
        ->and($art)->toMatchArray(['pivot' => '0.8828125, 0.53125', 'groundSpanTip' => '0.1328125, 0.46875', 'groundSpanTipKey' => $row('track0GroundSpantip')['key']])
        ->and($session->listUnsavedChanges())->toBe([])
        ->and($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(groundLanceTimeline());
})->skip(fn (): bool => ! hasEngineGroundSpan(), ENGINE_GROUND_SPAN_MISSING);

it('leaves a ground span as written in the Terminal editor, which has no row for it', function () {
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
    expect($trackRows)->toContain('track0Pivot', 'track0Attachment')
        ->not->toContain('track0GroundSpantip', 'track0AnchorLifetime', 'track0Span');
    setCutsceneField($editor, 'track0Id', 'lane-light');

    expect($asset->save())->toBeTrue()
        ->and(file_get_contents($path))->toBe(str_replace(["'lengthFrames' => 4", "'restFrame' => 3", "'id' => 'lane-art'"],
            ["'lengthFrames' => 5", "'restFrame' => 4", "'id' => 'lane-light'"], groundLanceTimeline()))
        ->and($asset->compileEffect(EffectPresentation::GRAPHICAL, true)->playbackSegments[0]['drawCommands'][0]['payload']['groundSpan'])
        ->toBe(['tip' => ['x' => 0.1328125, 'y' => 0.46875]]);
})->skip(fn (): bool => ! hasEngineGroundSpan(), ENGINE_GROUND_SPAN_MISSING);

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
})->skip(fn (): bool => ! hasEngineGroundSpan(), ENGINE_GROUND_SPAN_MISSING);

it('refuses a tip that is not a point of the image, changing nothing', function (string $tip) {
    $root = groundLanceProject();
    [$session, , $row, $apply] = openGroundSpanSession($root, 'ground-lance');

    expect(fn() => $apply('track0GroundSpantip', $tip))->toThrow(SessionRefusal::class, 'two numbers from 0 to 1')
        ->and($row('track0GroundSpantip')['value'])->toBe('0.1328125, 0.46875')
        ->and($session->listUnsavedChanges())->toBe([]);
})->with(['1.5, 0.5', '0.5', 'left, middle', '-0.1, 0.5'])->skip(fn (): bool => ! hasEngineGroundSpan(), ENGINE_GROUND_SPAN_MISSING);

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
        // Off, the row stays empty where the track can still take a span, and the span over the targets is offered again.
        ->and($row('track0GroundSpantip')['value'])->toBe('')
        ->and($find('track0Span', ['tracks']))->not->toBeNull()
        ->and($session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art']['groundSpanTip'])->toBe('');

    $apply('track0GroundSpantip', '0.0625, 0.75');
    expect($session->saveAll()['failures'])->toBe([])
        ->and($track()['groundSpan'])->toBe(['tip' => ['x' => 0.0625, 'y' => 0.75]])
        ->and($find('track0Span', ['tracks']))->toBeNull();

    $session->undo();
    $session->undo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(groundLanceTimeline());
})->skip(fn (): bool => ! hasEngineGroundSpan(), ENGINE_GROUND_SPAN_MISSING);

it('authors a ground span on a plain image track through the shared rows, the Engine judging it at save in its own words', function () {
    $root = effectBattlePreviewProject();
    $path = effectTimelinePath($root, 'dusk-slash');
    $original = (string) file_get_contents($path);
    [$session, , $row, $apply, $find, $setRecord] = openGroundSpanSession($root, 'dusk-slash');

    // The tip is offered once the track is a contained target-group ground image captured with its targets.
    expect($find('track0GroundSpantip', ['tracks']))->toBeNull();
    $apply('track0Anchor', 'target-group');
    $apply('track0Attachment', 'ground');
    $apply('track0Fit', 'contain');
    expect($find('track0GroundSpantip', ['tracks']))->toBeNull()
        ->and($find('track0Span', ['tracks']))->not->toBeNull();
    $apply('track0AnchorLifetime', 'target-capture');
    expect($row('track0GroundSpantip')['value'])->toBe('');
    $apply('track0Pivot', '0.9, 0.5');
    $apply('track0GroundSpantip', '0.1, 0.5');

    // The capture frame is missing and the track still has its facing: each is the Engine's to refuse.
    expect(readSaveRefusal($session))->toContain('target-capture lifetime requires an authored targetCaptureFrame.')
        ->and(file_get_contents($path))->toBe($original);
    $setRecord('targetCaptureFrame', '0');
    expect(readSaveRefusal($session))->toContain('groundSpan requires a captured target-group ground image, contain fit and a measured rear pivot.')
        ->and(file_get_contents($path))->toBe($original);
    $apply('track0Facing', '');

    expect($session->saveAll()['failures'])->toBe([]);
    $saved = require $path;
    expect($saved['presentations']['terminal'])->toBe((eval('?>' . $original))['presentations']['terminal'])
        ->and($saved['presentations']['graphical']['targetCaptureFrame'])->toBe(0)
        ->and($saved['presentations']['graphical']['tracks'][0])->toBe([
            'id' => 'stroke-art', 'type' => 'image', 'asset' => 'Graphics/Effects/dusk-slash.png', 'sheet' => ['columns' => 2, 'rows' => 1],
            'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]],
            'anchor' => 'target-group', 'attachment' => 'ground', 'fit' => 'contain', 'anchorLifetime' => 'target-capture',
            'pivot' => ['x' => 0.9, 'y' => 0.5], 'groundSpan' => ['tip' => ['x' => 0.1, 'y' => 0.5]],
        ]);

    // What the Engine refuses of a ground span afterwards is refused the same way, and the saved file stays.
    $written = (string) file_get_contents($path);
    $ownership = 'groundSpan requires a captured target-group ground image, contain fit and a measured rear pivot.';
    foreach ([
        [['track0GroundSpantip' => '0.9, 0.5'], 'groundSpan source tip must differ from its rear pivot.'],
        [['track0Keyframe1Positionx' => '1', 'track0Keyframe1Positiony' => '1'], 'groundSpan endpoints cannot be shifted by keyframe offsets.'],
        [['targetCaptureFrame' => '1'], 'snapshot-bound images must start at or after targetCaptureFrame.'],
        // An emptied capture frame leaves the key out of the file, which the lifetime cannot do without.
        [['targetCaptureFrame' => ''], 'target-capture lifetime requires an authored targetCaptureFrame.'],
        [['track0Cellswidth' => '2', 'track0Cellsheight' => '1'], $ownership],
        [['track0Facing' => 'east'], $ownership],
        [['track0Fit' => 'stretch'], $ownership],
        [['track0Pivot' => ''], $ownership],
        [['track0AnchorLifetime' => 'effect-start'], $ownership],
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
})->skip(fn (): bool => ! hasEngineGroundSpan(), ENGINE_GROUND_SPAN_MISSING);

it('previews the ground span as the Engine composes it, only for a canvas that draws texture strips, and draws a still of it', function (bool $reducedMotion) {
    $root = groundLanceProject();
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'ground-lance');
    $findStrip = static fn(array $frame): ?array => array_find($frame['canvas']['composites'] ?? [],
        static fn(array $composite): bool => ($composite['operations'][0]['type'] ?? null) === 'texture_strip');

    $without = $session->readEffectBattlePreview($index, 0, $reducedMotion, authoredFrame: 5);
    $with = $session->readEffectBattlePreview($index, 0, $reducedMotion, authoredFrame: 5, textureStrip: true);
    $again = $session->readEffectBattlePreview($index, 0, $reducedMotion, authoredFrame: 5);

    // Texture strips are composed for the request that asks for them and for no other: nothing stands in for the span.
    expect($findStrip($without))->toBeNull()
        ->and($without['diagnostics'])->toBe(['Renderer requires compositing and texture-strip support for a captured ground span.'])
        ->and($again)->toBe($without)
        ->and($with['diagnostics'])->toBe([]);
    $composite = $findStrip($with);
    expect($composite)->not->toBeNull();
    $operation = $composite['operations'][0];
    $strip = new CanvasTextureStrip($operation['geometry']);

    // The Engine's own capture for this command: the caster's ground and the farthest selected target's.
    $preview = new ReflectionProperty($session, 'effectBattlePreviews')->getValue($session)[EffectPresentation::GRAPHICAL->value]['preview'];
    $command = new ReflectionProperty($preview, 'preview')->getValue($preview);
    $playback = new ReflectionMethod($command, 'createPlayback')->invoke($command, $reducedMotion, static function (): void {});
    $playback->update($with['frame'] / BattleCommandTimeline::FPS);
    $snapshot = $playback->getCapturedAnchorSnapshot('snapshot');
    expect($snapshot)->not->toBeNull();
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
})->with([false, true])->skip(fn (): bool => ! hasEngineGroundSpan(), ENGINE_GROUND_SPAN_MISSING);

it('keeps a summon timeline\'s ground span as written through edits beside it, with no row of its own', function () {
    $root = summonBattlePreviewProject();
    $path = $root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.timeline.php';
    $source = str_replace(["  'restFrame' => 12,", "'anchor' => 'target', 'attachment' => 'ground', 'pivot' => ['x' => 0.5, 'y' => 1],"], [
        "  'restFrame' => 12,\n  'targetCaptureFrame' => 0, // held from the first frame",
        "'anchor' => 'target-group', 'attachment' => 'ground', 'anchorLifetime' => 'target-capture', 'fit' => 'contain',\n"
        . "      'pivot' => ['x' => 0.875, 'y' => 0.5],  'groundSpan' => ['tip' => ['x' => 0.125, 'y' => 0.5]], /* rear to tip */",
    ], (string) file_get_contents($path));
    file_put_contents($path, $source);
    $session = EditorSession::open($root);
    $rows = static fn(): array => $session->readDatabaseRecord('cutscenes/summon', 0, ['tracks'])['rows'];
    $row = static fn(string $field): ?array => array_find($rows(), static fn(array $entry): bool => ($entry['key']['field'] ?? null) === $field);
    $art = array_find(array_keys($rows()), static fn(int $at): bool => ($rows()[$at]['value'] ?? null) === 'wisp-art');
    $track = intval(preg_replace('/\D/', '', strval($rows()[$art]['key']['field'])));

    // A summon's image rows are a summon's: the span has none, and the file says what it said.
    expect($row("track{$track}GroundSpantip"))->toBeNull()
        ->and($session->listUnsavedChanges())->toBe([])
        ->and($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe($source);

    $session->applyDatabaseRecord('cutscenes/summon', 0, $row("track{$track}Id")['key'], 'wisp-lane');
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(str_replace("'id' => 'wisp-art'", "'id' => 'wisp-lane'", $source));
    $session->undo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe($source);
})->skip(fn (): bool => ! hasEngineGroundSpan(), ENGINE_GROUND_SPAN_MISSING);
