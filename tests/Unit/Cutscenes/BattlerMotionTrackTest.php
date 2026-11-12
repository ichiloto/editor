<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneOutline;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Preview\EffectPreviewStage;
use Ichiloto\Editor\Cutscenes\Preview\TimelinePreviewSession;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Rendering\Presentation\PresentationSession;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * The Engine's battler motion track (`battler-motion`) in effect timelines:
 * kept as written whatever else is edited, authored through the graphical
 * editor's shared rows with the samples a whole-field motion uses, listed and
 * never retyped by the terminal editor, and previewed in battle only for a
 * canvas that draws what the Engine composes it with. Synthetic timelines
 * only.
 */
function recoilStrikeTimeline(): string
{
    return <<<'PHP_SOURCE'
<?php

// Recoil Strike: a stroke, the target is knocked back and the caster lunges.
return [
  'fps' => 24,
  'lengthFrames' => 8,
  'tracks' => [
    [
      'id' => 'stroke',
      'type' => 'glyph',
      'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => '/', 'position' => ['x' => 0, 'y' => 0]]],
    ],
    // The knock back: logical pixels, away from the side the target fights.
    ['id' => 'knock-back',   'type' => 'battler-motion', 'anchor' => 'target', 'space' => 'opponents', 'presentation' => 'graphical',
     'keyframes' => [
       ['frame' => 2, 'duration' => 2, 'payload' => ['x' => -10.25, 'y' => 3.5]], // back
       ['frame' => 4, 'payload' => ['x' => 4.0, 'y' => -2.0]],
       ['frame' => 5, 'visible' => false, 'payload' => ['x' => 40, 'y' => 40]],
       ['frame' => 6, 'duration' => 1, 'zIndex' => 0, 'payload' => ['x' => 0.0, 'y' => 0.0]],
     ]],
    ['id' => 'lunge', 'type' => 'battler-motion', /* across the canvas */ 'anchor' => 'caster',
     'keyframes' => [['frame' => 2, 'payload' => ['x' => -18, 'y' => 0.5]]]],
  ],
  'cues' => [],
];
PHP_SOURCE;
}

const BATTLER_MOTION_CASTER_ART = 'Graphics/FixtureCaster.png';
const BATTLER_MOTION_TROOP_ART = 'Graphics/FixtureTroop.png';

/** The knock back track's own source text, comments and spacing included. */
function readKnockBackSource(string $source): string
{
    $start = strpos($source, "['id' => 'knock-back'");
    $end = strpos($source, ']],', (int) $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($source, (int) $start, (int) $end + 3 - (int) $start);
}

/** A battle test project whose unarmed attack plays Recoil Strike on its target. */
function battlerMotionProject(): string
{
    $root = effectBattlePreviewProject();
    mkdir($root . '/assets/Animations/recoil-strike', 0o777, true);
    file_put_contents(effectTimelinePath($root, 'recoil-strike'), recoilStrikeTimeline());
    file_put_contents($root . '/assets/Data/animations.php', "<?php\n\nreturn " . var_export([
        ['id' => 1, 'name' => 'Recoil Strike', 'roles' => ['attack', 'attack-unarmed'], 'targetEffect' => 'recoil-strike'],
    ], true) . ";\n");
    // Each side has its own artwork, so a frame's battlers are told apart by what they draw.
    writeTilesetTestPng($root . '/assets/' . BATTLER_MOTION_CASTER_ART, 8, 8);
    writeTilesetTestPng($root . '/assets/' . BATTLER_MOTION_TROOP_ART, 8, 8);
    file_put_contents($root . '/assets/Data/Presentation/battlers.php', '<?php return ' . var_export([
        'actors' => ['Kaelion' => ['artwork' => ['image' => BATTLER_MOTION_CASTER_ART]]],
        'enemies' => ['Regular Bat' => ['artwork' => ['image' => BATTLER_MOTION_TROOP_ART]]],
    ], true) . ';');

    return $root;
}

/** The canvas features an authored battler motion is composed with: both, or the battler stays where it stands. */
const BATTLER_MOTION_FEATURES = ['imageRotation' => true, 'clipOpacity' => true];

/**
 * Where a preview frame draws each battler, by side: the caster's body and the troop's bodies in the order drawn.
 *
 * @return array{caster: array<string, int|float>, troop: list<array<string, int|float>>}
 */
function readBattlerPlaces(array $frame): array
{
    $bodies = static fn(string $art): array => array_values(array_column(array_filter($frame['canvas']['images'] ?? [],
        static fn(array $image): bool => $image['asset'] === $art && str_starts_with($image['id'], 'combatant-')), 'destination'));
    $caster = $bodies(BATTLER_MOTION_CASTER_ART);
    expect($caster)->toHaveCount(1);

    return ['caster' => $caster[0], 'troop' => $bodies(BATTLER_MOTION_TROOP_ART)];
}

/** How far a body is drawn from where another frame draws it, and whether it kept its size. */
function measureBattlerOffset(array $from, array $to): array
{
    expect([$to['width'], $to['height']])->toEqual([$from['width'], $from['height']]);

    return ['x' => $to['x'] - $from['x'], 'y' => $to['y'] - $from['y']];
}

it('opens a battler-motion track as written and lists its samples with the battler they move', function () {
    $root = battlerMotionProject();
    $editor = openEffectEditor($root, 'recoil-strike');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'recoil-strike');
    $asset->hydrate();

    expect($asset->readOnlyReason())->toBeNull()
        ->and($asset->payload()['tracks'][1])->toBe(['id' => 'knock-back', 'type' => 'battler-motion', 'anchor' => 'target', 'space' => 'opponents',
            'presentation' => 'graphical', 'keyframes' => [
                ['frame' => 2, 'duration' => 2, 'payload' => ['x' => -10.25, 'y' => 3.5]],
                ['frame' => 4, 'payload' => ['x' => 4.0, 'y' => -2.0]],
                ['frame' => 5, 'visible' => false, 'payload' => ['x' => 40, 'y' => 40]],
                ['frame' => 6, 'duration' => 1, 'zIndex' => 0, 'payload' => ['x' => 0.0, 'y' => 0.0]],
            ]])
        ->and($asset->payload()['tracks'][2])->toBe(['id' => 'lunge', 'type' => 'battler-motion', 'anchor' => 'caster',
            'keyframes' => [['frame' => 2, 'payload' => ['x' => -18, 'y' => 0.5]]]])
        ->and(array_column(CutsceneOutline::of(CutsceneType::EFFECT, $asset->payload())->rows(), 'text'))->toContain(
            'battler-motion knock-back · 4 keyframes · graphical only',
            'f2 +2  target offset -10.25, 3.5 with x toward opponents',
            'f4 +1  target offset 4, -2 with x toward opponents',
            'f5 +1  target offset 40, 40 with x toward opponents (disabled)',
            'f6 +1  target offset 0, 0 with x toward opponents',
            'battler-motion lunge · 1 keyframe',
            'f2 +1  caster offset -18, 0.5',
        )
        ->and(renderEditorPlainFrame($editor, 160, 50))->toContain('battler-motion knock-back', 'battler-motion lunge', 'The engine reads this effect as it stands.');

    // The terminal editor names the tracks and gains no battler, space or sample rows.
    openCutsceneFrame($editor, 'commandListTracks');
    expect(array_values(array_filter(cutsceneFieldIds($editor), static fn(string $id): bool => preg_match('/^track[12]/', $id) === 1)))
        ->toBe(['track1Id', 'track1Type', 'track1Presentation', 'track2Id', 'track2Type', 'track2Presentation'])
        ->and(cutsceneField($editor, 'track1Type')['value'])->toBe('battler-motion');
});

it('keeps the tracks byte for byte when other fields and tracks are edited and saved', function () {
    $root = battlerMotionProject();
    $path = effectTimelinePath($root, 'recoil-strike');
    $original = recoilStrikeTimeline();
    $editor = openEffectEditor($root, 'recoil-strike');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'recoil-strike');

    setCutsceneField($editor, 'lengthFrames', '9');
    openCutsceneFrame($editor, 'commandListTracks');
    setCutsceneField($editor, 'track0Keyframe0Content', '\\');
    setCutsceneField($editor, 'track1Id', 'shove');
    setCutsceneField($editor, 'track1Id', 'knock-back');

    expect($asset->save())->toBeTrue()
        ->and(file_get_contents($path))->toBe(str_replace(["'lengthFrames' => 8", "'content' => '/'"], ["'lengthFrames' => 9", "'content' => '\\\\'"], $original));

    // Tracks added and removed around them, through the graphical editor's rows.
    [$session, $index, $row] = openEffectTrackSession($root, 'recoil-strike');
    $session->addDatabaseItem('cutscenes/effect', $index, $row('track1Id')['key']);
    $session->removeDatabaseItem('cutscenes/effect', $index, $row('track0Id')['key']);
    expect($session->saveAll()['failures'])->toBe([]);
    $saved = (string) file_get_contents($path);

    expect(readKnockBackSource($saved))->toBe(readKnockBackSource($original))
        ->and($saved)->toContain('// The knock back: logical pixels, away from the side the target fights.',
            "['id' => 'lunge', 'type' => 'battler-motion', /* across the canvas */ 'anchor' => 'caster',")
        ->and(array_column((require $path)['tracks'], 'id'))->toBe(['knock-back', 'track', 'lunge']);
});

it('refuses to retype a battler-motion track in the terminal editor, by cycling or by value', function () {
    $root = battlerMotionProject();
    $editor = openEffectEditor($root, 'recoil-strike');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'recoil-strike');
    $before = $asset->payload();
    openCutsceneFrame($editor, 'commandListTracks');

    foreach (['track1Type', 'track2Type'] as $type) {
        setCutsceneField($editor, $type, 'glyph');
        expect(getEditorProperty($editor, 'statusMessage'))->toContain('A track of type "battler-motion" is not edited here, so it keeps that type. Nothing was changed.');

        setEditorProperty($editor, 'statusMessage', '');
        selectCutsceneField($editor, $type);
        pressKeys($editor, "\033[C");
        expect(getEditorProperty($editor, 'statusMessage'))->toContain('is not edited here');
        setEditorProperty($editor, 'statusMessage', '');
    }

    expect($asset->payload())->toBe($before)
        ->and($asset->isDirty())->toBeFalse()
        ->and($asset->save())->toBeFalse()
        ->and(file_get_contents(effectTimelinePath($root, 'recoil-strike')))->toBe(recoilStrikeTimeline());

    // A track it does author still changes type.
    setCutsceneField($editor, 'track0Type', 'text');
    expect($asset->payload()['tracks'][0]['type'])->toBe('text');
});

it('lays the samples out on the graphical timeline and edits the battler, the space and the samples, touching only what changed', function () {
    $root = battlerMotionProject();
    $path = effectTimelinePath($root, 'recoil-strike');
    [$session, $index, $row, $apply] = openEffectTrackSession($root, 'recoil-strike');
    $lanes = $session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'];

    expect($lanes[1])->toMatchArray(['id' => 'knock-back', 'type' => 'battler-motion', 'presentation' => 'graphical'])
        ->and(array_column($lanes[1]['keyframes'], 'frame'))->toBe([2, 4, 5, 6])
        ->and(array_column($lanes[1]['keyframes'], 'duration'))->toBe([2, 1, 1, 1])
        ->and($lanes[1]['keyframes'][0]['frameKey'])->toBe($row('track1Keyframe0Frame')['key'])
        ->and(array_column($lanes[2]['keyframes'], 'frame'))->toBe([2])
        ->and($row('track1Type')['options'])->toContain('field-motion', 'battler-motion')
        // The battler is one of the two the Engine moves; the space reads as the canvas where the file leaves it out.
        ->and($row('track1Anchor'))->toMatchArray(['value' => 'target', 'options' => ['caster', 'target']])
        ->and($row('track1Space'))->toMatchArray(['value' => 'opponents', 'options' => ['', 'canvas', 'opponents']])
        ->and($row('track2Anchor')['value'])->toBe('caster')
        ->and($row('track2Space')['value'])->toBe('canvas')
        ->and($row('track1Keyframe0Payloadx')['value'])->toBe('-10.25')
        ->and($row('track1Keyframe1Duration')['value'])->toBe('1')
        ->and($row('track1Keyframe2Visible')['value'])->toBe('false')
        ->and($row('track2Keyframe0Visible')['value'])->toBe('true');

    $apply('track1Keyframe0Payloadx', '-12.5');
    $apply('track1Anchor', 'caster');
    $apply('track1Space', 'canvas');
    $apply('track1Keyframe2Visible', 'true');
    $apply('track2Keyframe0Payloady', '0.75');
    // The lunge keeps its space left out: an edit beside it writes no default.
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(str_replace(
            ["'x' => -10.25", "'anchor' => 'target'", "'space' => 'opponents'", "'visible' => false", "'y' => 0.5"],
            ["'x' => -12.5", "'anchor' => 'caster'", "'space' => 'canvas'", "'visible' => true", "'y' => 0.75"],
            recoilStrikeTimeline(),
        ))
        ->and((require $path)['tracks'][2])->not->toHaveKey('space');

    foreach (range(1, 5) as $ignored) {
        $session->undo();
    }
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(recoilStrikeTimeline());
});

it('writes a space only where one is chosen, and leaves it out again when it is cleared', function () {
    $root = battlerMotionProject();
    $path = effectTimelinePath($root, 'recoil-strike');
    [$session, , $row, $apply] = openEffectTrackSession($root, 'recoil-strike');

    $apply('track2Space', 'opponents');
    $apply('track1Space', '');
    expect($session->saveAll()['failures'])->toBe([]);
    $tracks = (require $path)['tracks'];

    expect($tracks[2])->toBe(['id' => 'lunge', 'type' => 'battler-motion', 'anchor' => 'caster', 'keyframes' => [['frame' => 2, 'payload' => ['x' => -18, 'y' => 0.5]]],
        'space' => 'opponents'])
        ->and($tracks[1])->not->toHaveKey('space')
        ->and($tracks[1]['keyframes'][0])->toBe(['frame' => 2, 'duration' => 2, 'payload' => ['x' => -10.25, 'y' => 3.5]])
        ->and($row('track1Space')['value'])->toBe('canvas')
        ->and(file_get_contents($path))->toContain("['frame' => 2, 'duration' => 2, 'payload' => ['x' => -10.25, 'y' => 3.5]], // back",
            "/* across the canvas */ 'anchor' => 'caster'");

    $apply('track2Space', '');
    expect($session->saveAll()['failures'])->toBe([])
        ->and((require $path)['tracks'][2])->not->toHaveKey('space');
});

it('authors a new battler-motion track and its samples in the graphical editor, as the Engine reads them', function () {
    $root = battlerMotionProject();
    $path = effectTimelinePath($root, 'recoil-strike');
    [$session, $index, $row, $apply] = openEffectTrackSession($root, 'recoil-strike');
    $category = 'cutscenes/effect';

    // A sample after the last, and a sample removed.
    $session->addDatabaseItem($category, $index, $row('track1Keyframe3Frame')['key']);
    $apply('track1Keyframe4Frame', '7');
    $apply('track1Keyframe4Payloadx', '1.5');
    $session->removeDatabaseItem($category, $index, $row('track1Keyframe2Frame')['key']);

    // A glyph track turned into a battler motion keeps nothing a glyph track read, and names no battler until one is chosen.
    $session->addDatabaseItem($category, $index, $row('track2Id')['key']);
    $apply('track3Id', 'brace');
    $apply('track3Type', 'battler-motion');
    expect($row('track3Anchor')['value'])->toBe('');
    $apply('track3Anchor', 'target');
    $session->addDatabaseItem($category, $index, $row('track3Id')['key'], child: true);
    $apply('track3Keyframe0Frame', '7');
    $apply('track3Keyframe0Payloady', '-0.5');

    expect($session->saveAll()['failures'])->toBe([]);
    $tracks = (require $path)['tracks'];

    expect($tracks[1]['keyframes'])->toBe([
        ['frame' => 2, 'duration' => 2, 'payload' => ['x' => -10.25, 'y' => 3.5]],
        ['frame' => 4, 'payload' => ['x' => 4.0, 'y' => -2.0]],
        ['frame' => 6, 'duration' => 1, 'zIndex' => 0, 'payload' => ['x' => 0.0, 'y' => 0.0]],
        ['frame' => 7, 'payload' => ['x' => 1.5, 'y' => 0]],
    ])
        ->and($tracks[3])->toBe(['id' => 'brace', 'type' => 'battler-motion', 'anchor' => 'target', 'keyframes' => [['frame' => 7, 'payload' => ['x' => 0, 'y' => -0.5]]]])
        ->and(file_get_contents($path))->toContain("['frame' => 2, 'duration' => 2, 'payload' => ['x' => -10.25, 'y' => 3.5]], // back");

    $motion = array_values(array_filter(
        libraryOf(openEffectEditor($root, 'recoil-strike'))->find(CutsceneType::EFFECT, 'recoil-strike')->compileEffect(EffectPresentation::GRAPHICAL, true)->playbackSegments,
        static fn(array $segment): bool => $segment['layer'] === 'battler-motion',
    ));
    $samples = array_map(static fn(array $segment): array => [$segment['startFrame'], $segment['drawCommands'][0]['payload']], $motion);
    usort($samples, static fn(array $one, array $other): int => [$one[0], $one[1]['anchor'], $one[1]['x']] <=> [$other[0], $other[1]['anchor'], $other[1]['x']]);
    expect($samples)->toBe([
        [2, ['x' => -18.0, 'y' => 0.5, 'anchor' => 'caster', 'space' => 'canvas']],
        [2, ['x' => -10.25, 'y' => 3.5, 'anchor' => 'target', 'space' => 'opponents']],
        [4, ['x' => 4.0, 'y' => -2.0, 'anchor' => 'target', 'space' => 'opponents']],
        [6, ['x' => 0.0, 'y' => 0.0, 'anchor' => 'target', 'space' => 'opponents']],
        [7, ['x' => 0.0, 'y' => -0.5, 'anchor' => 'target', 'space' => 'canvas']],
        [7, ['x' => 1.5, 'y' => 0.0, 'anchor' => 'target', 'space' => 'opponents']],
    ]);
});

it('keeps the samples when a motion is turned from a battler\'s into the field\'s and back, as both read the one list', function () {
    $root = battlerMotionProject();
    $path = effectTimelinePath($root, 'recoil-strike');
    [$session, , $row, $apply] = openEffectTrackSession($root, 'recoil-strike');
    $samples = (require $path)['tracks'][1]['keyframes'];

    // The field has no battler and no space: both go, the samples stay. A track given a type only the graphical battle
    // draws is left to that type's own presentation, as every such type is.
    $apply('track1Type', 'field-motion');
    expect($session->saveAll()['failures'])->toBe([])
        ->and((require $path)['tracks'][1])->toBe(['id' => 'knock-back', 'type' => 'field-motion', 'keyframes' => $samples]);

    // Back again it names no battler until one is chosen, and the Engine refuses the save until then.
    $apply('track1Type', 'battler-motion');
    expect($row('track1Anchor')['value'])->toBe('')
        ->and($row('track1Keyframe0Payloadx')['value'])->toBe('-10.25')
        ->and($session->saveAll()['failures'])->not->toBe([]);
    $apply('track1Anchor', 'caster');
    expect($session->saveAll()['failures'])->toBe([])
        ->and((require $path)['tracks'][1])->toBe(['id' => 'knock-back', 'type' => 'battler-motion', 'keyframes' => $samples, 'anchor' => 'caster']);
});

it('refuses a save the Engine rejects for the track and writes nothing', function () {
    $root = battlerMotionProject();
    [$session, , , $apply] = openEffectTrackSession($root, 'recoil-strike');

    // The Engine draws a battler motion in the graphical battle only.
    $apply('track1Presentation', 'all');

    expect($session->saveAll()['failures'])->not->toBe([])
        ->and(file_get_contents(effectTimelinePath($root, 'recoil-strike')))->toBe(recoilStrikeTimeline());
});

it('describes the motion in the terminal preview without imitating it, and never draws a disabled sample as one', function () {
    $root = battlerMotionProject();
    $asset = libraryOf(openEffectEditor($root, 'recoil-strike'))->find(CutsceneType::EFFECT, 'recoil-strike');
    $graphical = new TimelinePreviewSession($asset->compileEffect(EffectPresentation::GRAPHICAL, true), null);
    $stage = new EffectPreviewStage(EffectPresentation::GRAPHICAL, true);
    $describe = static function (int $frame) use ($stage, $graphical): array {
        $lines = $stage->describeUndrawn($graphical->activeSegments($frame));
        sort($lines);

        return $lines;
    };

    expect($describe(2))->toBe(['caster offset -18, 0.5', 'target offset -10.25, 3.5 with x toward opponents'])
        ->and($describe(5))->toBe(['target offset 40, 40 with x toward opponents (disabled)'])
        ->and($describe(7))->toBe([])
        ->and(implode("\n", $stage->drawFrame($graphical->activeSegments(2), 20, 3)))
        ->toContain(EffectPreviewStage::CASTER_MARKER, EffectPreviewStage::TARGET_MARKER);

    // The terminal sequence never receives the graphical tracks.
    $terminal = new TimelinePreviewSession($asset->compileEffect(EffectPresentation::TERMINAL, true), null);
    expect(new EffectPreviewStage(EffectPresentation::TERMINAL, true)->describeUndrawn($terminal->activeSegments(2)))->toBe([]);
});

it('moves the caster and the target by their authored offsets, with no diagnostic, for a preview whose canvas draws rotated and clipped images', function () {
    $root = battlerMotionProject();
    [$session, $index, , $apply] = openEffectTrackSession($root, 'recoil-strike');
    $tracks = (require effectTimelinePath($root, 'recoil-strike'))['tracks'];
    [$knockBack, $lunge] = [$tracks[1]['keyframes'][0], $tracks[2]['keyframes'][0]];
    $read = static fn(int $authored, array $features): array => $session->readEffectBattlePreview($index, 0, ...['authoredFrame' => $authored, ...$features]);

    $still = $read($knockBack['frame'], []);
    $moved = $read($knockBack['frame'], BATTLER_MOTION_FEATURES);
    $from = readBattlerPlaces($still);
    $to = readBattlerPlaces($moved);
    // One of the troop is the target; the party stands east of it, so the side the target fights is to its right.
    $targets = array_keys(array_filter($to['troop'], static fn(array $place, int $member): bool => $place != $from['troop'][$member], ARRAY_FILTER_USE_BOTH));

    expect($moved['targets'])->toHaveCount(1)
        ->and($moved['diagnostics'])->toBe([])
        ->and($from['troop'])->toHaveCount(2)
        ->and($targets)->toHaveCount(1)
        ->and(measureBattlerOffset($from['troop'][$targets[0]], $to['troop'][$targets[0]]))->toEqual($knockBack['payload'])
        // The caster's track leaves its space out: across the canvas, as authored.
        ->and(measureBattlerOffset($from['caster'], $to['caster']))->toEqual($lunge['payload'])
        ->and([$moved['frame'], $moved['canvas']['width'], $moved['canvas']['height']])->toBe([$still['frame'], $still['canvas']['width'], $still['canvas']['height']])
        // Nothing but the battlers a sample names is drawn anywhere else.
        ->and(array_values(array_filter($moved['canvas']['images'], static fn(array $image): bool => ! str_starts_with($image['id'], 'combatant-'))))
        ->toBe(array_values(array_filter($still['canvas']['images'], static fn(array $image): bool => ! str_starts_with($image['id'], 'combatant-'))));

    // The same offsets among opponents: the caster's X turns toward the troop, to its left; the target's across the canvas is as authored.
    $apply('track2Space', 'opponents');
    $apply('track1Space', 'canvas');
    $turned = readBattlerPlaces($read($knockBack['frame'], BATTLER_MOTION_FEATURES));
    expect(measureBattlerOffset($from['caster'], $turned['caster']))->toEqual(['x' => -$lunge['payload']['x'], 'y' => $lunge['payload']['y']])
        ->and(measureBattlerOffset($from['troop'][$targets[0]], $turned['troop'][$targets[0]]))->toEqual($knockBack['payload']);

    // The track turned onto the caster moves the caster by both, and the target by nothing.
    $apply('track1Anchor', 'caster');
    $both = readBattlerPlaces($read($knockBack['frame'], BATTLER_MOTION_FEATURES));
    expect(measureBattlerOffset($from['caster'], $both['caster']))->toEqual(
        ['x' => $knockBack['payload']['x'] - $lunge['payload']['x'], 'y' => $knockBack['payload']['y'] + $lunge['payload']['y']])
        ->and($both['troop'])->toEqual($from['troop']);
});

it('draws the ordinary battlefield with the Engine saying the motion is not applied, for a canvas missing either feature', function () {
    [$session, $index] = openEffectTrackSession(battlerMotionProject(), 'recoil-strike');
    $read = static fn(array $features): array => $session->readEffectBattlePreview($index, 0, ...['authoredFrame' => 2, ...$features]);
    $still = $read([]);

    expect($still['authoredFrames']['target'])->toBe(2)
        ->and($still['diagnostics'])->toHaveCount(1)
        ->and($still['diagnostics'][0])->toContain('battler motion')
        ->and(readBattlerPlaces($still)['troop'])->toHaveCount(2);

    // Either feature alone, and every other feature without them, compose the frame composed with none.
    foreach ([['imageRotation' => true], ['clipOpacity' => true], ['canvasGroups' => true, 'regionBudget' => true, 'coverageDabs' => true, 'textureStrip' => true]] as $features) {
        $frame = $read($features);
        expect($frame['canvas'])->toBe($still['canvas'])
            ->and($frame['diagnostics'])->toBe($still['diagnostics']);
    }

    // Before and after a frame composed with both.
    expect($read(BATTLER_MOTION_FEATURES)['diagnostics'])->toBe([])
        ->and($read([]))->toBe($still);
});

it('leaves a battler where it stands in a gap, at a disabled sample, under reduced motion and in the terminal arena', function () {
    $root = battlerMotionProject();
    [$session, $index] = openEffectTrackSession($root, 'recoil-strike');
    $samples = (require effectTimelinePath($root, 'recoil-strike'))['tracks'][1]['keyframes'];
    $read = static fn(int $authored, array $features = BATTLER_MOTION_FEATURES, bool $reducedMotion = false): array => $session->readEffectBattlePreview(
        $index, 0, $reducedMotion, ...['authoredFrame' => $authored, ...$features]);
    $disabled = array_find($samples, static fn(array $sample): bool => ($sample['visible'] ?? true) === false);
    $zero = array_find($samples, static fn(array $sample): bool => $sample['payload'] == ['x' => 0, 'y' => 0]);
    $last = array_last($samples);

    // Before the first sample, after the last and at a disabled sample no track holds a battler: the frame is the one composed without the features.
    foreach ([$samples[0]['frame'] - 1, $last['frame'] + ($last['duration'] ?? 1), $disabled['frame']] as $frame) {
        $released = $read($frame);
        expect($released['authoredFrames']['target'])->toBe($frame)
            ->and($released['diagnostics'])->toBe([])
            ->and($released)->toBe($read($frame, []));
    }

    // Zero is a sample of its own: the target is held where it stands, which a canvas without the features is told it cannot draw.
    $held = $read($zero['frame']);
    expect($held['diagnostics'])->toBe([])
        ->and(readBattlerPlaces($held))->toEqual(readBattlerPlaces($read($zero['frame'], [])))
        ->and($read($zero['frame'], [])['diagnostics'])->toHaveCount(1);

    // Reduced motion and the terminal arena never move a battler, whatever the canvas draws.
    $reduced = $read($samples[0]['frame'], reducedMotion: true);
    $terminal = $session->readEffectBattlePreview($index, 0,
        ...['presentation' => EffectPresentation::TERMINAL, 'authoredFrame' => $samples[0]['frame'], ...BATTLER_MOTION_FEATURES]);
    expect($reduced['diagnostics'])->toBe([])
        ->and($reduced)->toBe($read($samples[0]['frame'], [], true))
        ->and($terminal['canvas'])->toBeNull()
        ->and($terminal['diagnostics'])->toBe([])
        ->and($terminal['terminalLines'])->not->toBe([]);
});

it('reads the clipped images request from the host route, and composes the motion for a request carrying both flags alone', function () {
    $root = battlerMotionProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array => $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params]));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $index = array_find_key($request('database.records', ['category' => 'cutscenes/effect'])['result']['records'],
        static fn(mixed $label): bool => str_contains(strval($label), 'recoil-strike'));
    $lunge = (require effectTimelinePath($root, 'recoil-strike'))['tracks'][2]['keyframes'][0];
    $preview = static fn(array $features): array => $request('cutscenes.effectBattlePreview',
        ['index' => $index, 'frame' => 0, 'authoredFrame' => $lunge['frame'], 'presentation' => 'graphical', ...$features])['result'];
    $still = $preview([]);

    $moved = $preview(BATTLER_MOTION_FEATURES);
    expect($moved['diagnostics'])->toBe([])
        ->and(measureBattlerOffset(readBattlerPlaces($still)['caster'], readBattlerPlaces($moved)['caster']))->toEqual($lunge['payload']);

    // Only two true flags ask for it: one left out, false or not a boolean leaves the battlers where they stand.
    foreach ([['imageRotation' => true], ['clipOpacity' => true], ['imageRotation' => true, 'clipOpacity' => false], ['imageRotation' => true, 'clipOpacity' => 'yes'],
        ['imageRotation' => 1, 'clipOpacity' => true], ['regionBudget' => true, 'canvasGroups' => true]] as $features) {
        $frame = $preview($features);
        expect($frame['canvas'])->toBe($still['canvas'])
            ->and($frame['diagnostics'])->toBe($still['diagnostics'])->toHaveCount(1);
    }

    // Every other negotiated feature beside them keeps the motion.
    $every = $preview([...BATTLER_MOTION_FEATURES, 'regionBudget' => true, 'canvasGroups' => true, 'coverageDabs' => true, 'textureStrip' => true]);
    expect($every['diagnostics'])->toBe([])
        ->and(readBattlerPlaces($every))->toEqual(readBattlerPlaces($moved));
});

it('composes a frame with no battler motion exactly as before the two features were negotiated for it', function () {
    // An effect that holds no motion: Dusk Slash, with artwork for both sides.
    $root = effectBattlePreviewProject();
    writeTilesetTestPng($root . '/assets/' . BATTLER_MOTION_CASTER_ART, 8, 8);
    writeTilesetTestPng($root . '/assets/' . BATTLER_MOTION_TROOP_ART, 8, 8);
    file_put_contents($root . '/assets/Data/Presentation/battlers.php', '<?php return ' . var_export([
        'actors' => ['Kaelion' => ['artwork' => ['image' => BATTLER_MOTION_CASTER_ART]]],
        'enemies' => ['Regular Bat' => ['artwork' => ['image' => BATTLER_MOTION_TROOP_ART]]],
    ], true) . ';');
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'dusk-slash');
    $total = $session->readEffectBattlePreview($index, 0)['totalFrames'];
    // The Engine's own command preview behind the session's, asked directly: image rotation as the composer argument it
    // was before, in a renderer session that negotiates only what the preview negotiated before.
    $engine = new ReflectionProperty($session, 'effectBattlePreviews')->getValue($session)[EffectPresentation::GRAPHICAL->value]['preview'];
    $command = new ReflectionProperty($engine, 'preview')->getValue($engine);
    $compose = static fn(int $frame, array $negotiated): array => $negotiated === []
        ? $command->getFrameAtIndex($frame, false, true)->toArray()
        : PresentationSession::runForSession(static fn(string $capability): bool => in_array($capability, $negotiated, true),
            static fn(): array => $command->getFrameAtIndex($frame, false, true)->toArray());

    $frames = array_unique([...range(0, $total - 1, 7), $session->readEffectBattlePreview($index, 0, authoredFrame: 0)['frame'],
        $session->readEffectBattlePreview($index, 0, authoredFrame: 1)['frame'], $total - 1]);
    foreach ($frames as $frame) {
        $before = $compose($frame, []);
        $rotated = $session->readEffectBattlePreview($index, $frame, imageRotation: true);
        $both = $session->readEffectBattlePreview($index, $frame, imageRotation: true, clipOpacity: true);
        $every = $session->readEffectBattlePreview($index, $frame, imageRotation: true, clipOpacity: true, regionBudget: true, canvasGroups: true);

        expect(array_intersect_key($rotated, $before))->toBe($before)
            ->and(array_intersect_key($both, $before))->toBe($before)
            ->and(array_intersect_key($every, $before))->toBe($compose($frame, [RendererSessionConfig::CANVAS_REGION_BUDGET, RendererSessionConfig::CANVAS_GROUPS]))
            ->and(readBattlerPlaces($before)['troop'])->toHaveCount(2);
    }
});
