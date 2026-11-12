<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneOutline;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Preview\EffectPreviewStage;
use Ichiloto\Editor\Cutscenes\Preview\TimelinePreviewSession;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;

/**
 * The Engine's whole-field motion track (`field-motion`) in effect timelines:
 * kept as written whatever else is edited, authored through the graphical
 * editor's shared rows, listed and never retyped by the terminal editor, and
 * previewed without imitating what only a graphical battle draws. Synthetic
 * timelines only.
 */
function quakeStrikeTimeline(): string
{
    return <<<'PHP_SOURCE'
<?php

// Quake Strike: a stroke, then the whole field jolts.
return [
  'fps' => 24,
  'lengthFrames' => 6,
  'tracks' => [
    [
      'id' => 'stroke',
      'type' => 'glyph',
      'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => '/', 'position' => ['x' => 0, 'y' => 0]]],
    ],
    // The jolt: logical pixels, held one frame each.
    ['id' => 'impact-shake',   'type' => 'field-motion', 'presentation' => 'graphical',
     'keyframes' => [
       ['frame' => 2, 'duration' => 1, 'payload' => ['x' => -10.25, 'y' => 3.5]], // left
       ['frame' => 3, 'payload' => ['x' => 7.0, 'y' => -2.0]],
       ['frame' => 4, 'visible' => false, 'payload' => ['x' => 40, 'y' => 40]],
       ['frame' => 5, 'duration' => 1, 'zIndex' => 0, 'payload' => ['x' => 0.0, 'y' => 0.0]],
     ]],
    ['id' => 'shade', 'type' => 'tone', 'anchor' => 'target', 'presentation' => 'graphical',
     'keyframes' => [['frame' => 0, 'duration' => 2, 'payload' => ['brightness' => .5]]]],
  ],
  'cues' => [],
];
PHP_SOURCE;
}

/** The same jolt in the graphical sequence of an effect whose terminal sequence is its own. */
function quakeSplitTimeline(): string
{
    return <<<'PHP_SOURCE'
<?php

return [
  'presentations' => [
    'terminal' => [
      'fps' => 12,
      'lengthFrames' => 4,
      'tracks' => [['id' => 'stroke', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 4, 'content' => '/', 'position' => ['x' => 0, 'y' => 0]]]]],
    ],
    'graphical' => [
      'fps' => 24,
      'lengthFrames' => 6,
      'tracks' => [
        ['id' => 'spark', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => '*', 'position' => ['x' => 0, 'y' => 0]]]],
        ['id' => 'impact-shake', 'type' => 'field-motion', /* whole field */ 'keyframes' => [
          ['frame' => 2, 'payload' => ['x' => -10.25, 'y' => 3.5]],
          ['frame' => 3, 'payload' => ['x' => 0.0,    'y' => 0.0]],
        ]],
      ],
    ],
  ],
];
PHP_SOURCE;
}

/** The track's own source text, comments and spacing included. */
function readFieldMotionSource(string $source): string
{
    $start = strpos($source, "['id' => 'impact-shake'");
    $end = strpos($source, ']],', (int) $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($source, (int) $start, (int) $end + 3 - (int) $start);
}

/** A battle test project whose unarmed attack plays Quake Strike on its target. */
function fieldMotionProject(): string
{
    $root = effectBattlePreviewProject();
    foreach (['quake-strike' => quakeStrikeTimeline(), 'quake-split' => quakeSplitTimeline()] as $id => $source) {
        mkdir($root . '/assets/Animations/' . $id, 0o777, true);
        file_put_contents(fieldMotionPath($root, $id), $source);
    }
    file_put_contents($root . '/assets/Data/animations.php', "<?php\n\nreturn " . var_export([
        ['id' => 1, 'name' => 'Quake Strike', 'roles' => ['attack', 'attack-unarmed'], 'targetEffect' => 'quake-strike'],
    ], true) . ";\n");

    return $root;
}

function fieldMotionPath(string $root, string $id = 'quake-strike'): string
{
    return effectTimelinePath($root, $id);
}

/** The terminal editor's Cutscenes screen on one effect of the project. */
function openFieldMotionEditor(string $root, string $id = 'quake-strike'): Editor
{
    return openEffectEditor($root, $id);
}

/** @return array{0: EditorSession, 1: int, 2: Closure(string): array<string, mixed>, 3: Closure(string, string): array<string, mixed>} */
function openFieldMotionSession(string $root, string $id = 'quake-strike'): array
{
    return openEffectTrackSession($root, $id);
}

it('opens a field-motion track as written and lists its samples with their fractional offsets', function () {
    $root = fieldMotionProject();
    $editor = openFieldMotionEditor($root);
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'quake-strike');
    $asset->hydrate();

    expect($asset->readOnlyReason())->toBeNull()
        ->and($asset->payload()['tracks'][1])->toBe(['id' => 'impact-shake', 'type' => 'field-motion', 'presentation' => 'graphical', 'keyframes' => [
            ['frame' => 2, 'duration' => 1, 'payload' => ['x' => -10.25, 'y' => 3.5]],
            ['frame' => 3, 'payload' => ['x' => 7.0, 'y' => -2.0]],
            ['frame' => 4, 'visible' => false, 'payload' => ['x' => 40, 'y' => 40]],
            ['frame' => 5, 'duration' => 1, 'zIndex' => 0, 'payload' => ['x' => 0.0, 'y' => 0.0]],
        ]])
        ->and(array_column(CutsceneOutline::of(CutsceneType::EFFECT, $asset->payload())->rows(), 'text'))->toContain(
            'field-motion impact-shake · 4 keyframes · graphical only',
            'f2 +1  field offset -10.25, 3.5',
            'f3 +1  field offset 7, -2',
            'f4 +1  field offset 40, 40 (disabled)',
            'f5 +1  field offset 0, 0',
        )
        ->and(renderEditorPlainFrame($editor, 160, 50))->toContain('field-motion impact-shake', 'The engine reads this effect as it stands.');

    // The terminal editor names the track and gains no graphical sample form.
    openCutsceneFrame($editor, 'commandListTracks');
    expect(array_values(array_filter(cutsceneFieldIds($editor), static fn(string $id): bool => str_starts_with($id, 'track1'))))
        ->toBe(['track1Id', 'track1Type', 'track1Presentation'])
        ->and(cutsceneField($editor, 'track1Type')['value'])->toBe('field-motion');
});

it('keeps the track byte for byte when other fields and tracks are edited and saved', function () {
    $root = fieldMotionProject();
    $path = fieldMotionPath($root);
    $original = quakeStrikeTimeline();
    $editor = openFieldMotionEditor($root);
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'quake-strike');

    setCutsceneField($editor, 'lengthFrames', '8');
    openCutsceneFrame($editor, 'commandListTracks');
    setCutsceneField($editor, 'track0Keyframe0Content', '\\');
    setCutsceneField($editor, 'track1Id', 'jolt');
    setCutsceneField($editor, 'track1Id', 'impact-shake');

    expect($asset->save())->toBeTrue()
        ->and(file_get_contents($path))->toBe(str_replace(["'lengthFrames' => 6", "'content' => '/'"], ["'lengthFrames' => 8", "'content' => '\\\\'"], $original));

    // Tracks added and removed around it, through the graphical editor's rows.
    [$session, $index, $row] = openFieldMotionSession($root);
    $session->addDatabaseItem('cutscenes/effect', $index, $row('track1Id')['key']);
    $session->removeDatabaseItem('cutscenes/effect', $index, $row('track0Id')['key']);
    expect($session->saveAll()['failures'])->toBe([]);
    $saved = (string) file_get_contents($path);

    expect(readFieldMotionSource($saved))->toBe(readFieldMotionSource($original))
        ->and($saved)->toContain('// The jolt: logical pixels, held one frame each.')
        ->and(array_column((require $path)['tracks'], 'id'))->toBe(['impact-shake', 'track', 'shade']);
});

it('keeps a graphical sequence holding the track as written when the terminal sequence is edited', function () {
    $root = fieldMotionProject();
    $path = fieldMotionPath($root, 'quake-split');
    $editor = openFieldMotionEditor($root, 'quake-split');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'quake-split');

    setCutsceneField($editor, '@sequence', 'terminal');
    setCutsceneField($editor, 'lengthFrames', '5');

    expect($asset->save())->toBeTrue()
        ->and(file_get_contents($path))->toBe(str_replace("'lengthFrames' => 4,", "'lengthFrames' => 5,", quakeSplitTimeline()))
        ->and($asset->compileEffect(EffectPresentation::GRAPHICAL, true)->playbackSegments)->not->toBe([]);
});

it('refuses to retype a track the terminal editor does not author, by cycling or by value', function () {
    $root = fieldMotionProject();
    $editor = openFieldMotionEditor($root);
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'quake-strike');
    $before = $asset->payload();
    openCutsceneFrame($editor, 'commandListTracks');

    setCutsceneField($editor, 'track1Type', 'glyph');
    expect(getEditorProperty($editor, 'statusMessage'))->toContain('A track of type "field-motion" is not edited here, so it keeps that type. Nothing was changed.');

    setEditorProperty($editor, 'statusMessage', '');
    selectCutsceneField($editor, 'track1Type');
    pressKeys($editor, "\033[C");

    expect(getEditorProperty($editor, 'statusMessage'))->toContain('is not edited here')
        ->and($asset->payload())->toBe($before)
        ->and($asset->isDirty())->toBeFalse()
        ->and($asset->save())->toBeFalse()
        ->and(file_get_contents(fieldMotionPath($root)))->toBe(quakeStrikeTimeline());

    // A track it does author still changes type.
    setCutsceneField($editor, 'track0Type', 'text');
    expect($asset->payload()['tracks'][0]['type'])->toBe('text');
});

it('refuses to retype a track of a type neither editor offers, and leaves it as written', function () {
    $root = fieldMotionProject();
    [$session, $index, $row, $apply] = openFieldMotionSession($root);

    expect($row('track2Type')['value'])->toBe('tone')
        ->and(fn() => $apply('track2Type', 'glyph'))
        ->toThrow(SessionRefusal::class, 'A track of type "tone" is not edited here, so it keeps that type. Nothing was changed.')
        ->and($row('track2Type')['value'])->toBe('tone')
        ->and($session->saveAll()['summary'])->not->toContain('1 cutscene')
        ->and(file_get_contents(fieldMotionPath($root)))->toBe(quakeStrikeTimeline());
});

it('lays the samples out on the graphical timeline and edits them through the shared rows, touching only what changed', function () {
    $root = fieldMotionProject();
    $path = fieldMotionPath($root);
    [$session, $index, $row, $apply] = openFieldMotionSession($root);
    $lane = $session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][1];

    expect($lane)->toMatchArray(['id' => 'impact-shake', 'type' => 'field-motion', 'presentation' => 'graphical'])
        ->and(array_column($lane['keyframes'], 'frame'))->toBe([2, 3, 4, 5])
        ->and(array_column($lane['keyframes'], 'duration'))->toBe([1, 1, 1, 1])
        ->and($lane['keyframes'][0]['frameKey'])->toBe($row('track1Keyframe0Frame')['key'])
        ->and($row('track1Type')['options'])->toContain('field-motion')
        ->and($row('track1Keyframe0Payloadx')['value'])->toBe('-10.25')
        ->and($row('track1Keyframe1Duration')['value'])->toBe('1')
        ->and($row('track1Keyframe2Visible')['value'])->toBe('false')
        ->and($row('track1Keyframe3Visible')['value'])->toBe('true');

    $apply('track1Keyframe0Payloadx', '-12.5');
    $apply('track1Keyframe1Payloady', '-2.75');
    $apply('track1Keyframe2Visible', 'true');
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(str_replace(
            ["'x' => -10.25", "'y' => -2.0", "'visible' => false"],
            ["'x' => -12.5", "'y' => -2.75", "'visible' => true"],
            quakeStrikeTimeline(),
        ));

    $session->undo();
    $session->undo();
    $session->undo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(quakeStrikeTimeline());
});

it('authors a new field-motion track and its samples in the graphical editor, as the Engine reads them', function () {
    $root = fieldMotionProject();
    $path = fieldMotionPath($root);
    [$session, $index, $row, $apply] = openFieldMotionSession($root);
    $category = 'cutscenes/effect';
    $record = static fn(string $field): array => array_find($session->readDatabaseRecord($category, $index)['rows'],
        static fn(array $entry): bool => ($entry['key']['field'] ?? null) === $field);

    $session->applyDatabaseRecord($category, $index, $record('lengthFrames')['key'], '8');
    // A sample after the last, and a sample removed.
    $session->addDatabaseItem($category, $index, $row('track1Keyframe3Frame')['key']);
    $apply('track1Keyframe4Frame', '6');
    $apply('track1Keyframe4Duration', '2');
    $apply('track1Keyframe4Payloadx', '1.5');
    $session->removeDatabaseItem($category, $index, $row('track1Keyframe2Frame')['key']);

    // A glyph track turned into a second motion keeps nothing a glyph track read.
    $session->addDatabaseItem($category, $index, $row('track2Id')['key']);
    $apply('track3Id', 'aftershock');
    $apply('track3Type', 'field-motion');
    $session->addDatabaseItem($category, $index, $row('track3Id')['key'], child: true);
    $apply('track3Keyframe0Frame', '7');
    $apply('track3Keyframe0Payloady', '-0.5');

    expect($session->saveAll()['failures'])->toBe([]);
    $tracks = (require $path)['tracks'];

    expect($tracks[1]['keyframes'])->toBe([
        ['frame' => 2, 'duration' => 1, 'payload' => ['x' => -10.25, 'y' => 3.5]],
        ['frame' => 3, 'payload' => ['x' => 7.0, 'y' => -2.0]],
        ['frame' => 5, 'duration' => 1, 'zIndex' => 0, 'payload' => ['x' => 0.0, 'y' => 0.0]],
        ['frame' => 6, 'payload' => ['x' => 1.5, 'y' => 0], 'duration' => 2],
    ])
        ->and($tracks[3])->toBe(['id' => 'aftershock', 'type' => 'field-motion', 'keyframes' => [['frame' => 7, 'payload' => ['x' => 0, 'y' => -0.5]]]])
        ->and(file_get_contents($path))->toContain("['frame' => 2, 'duration' => 1, 'payload' => ['x' => -10.25, 'y' => 3.5]], // left");

    $motion = array_values(array_filter(
        libraryOf(openFieldMotionEditor($root))->find(CutsceneType::EFFECT, 'quake-strike')->compileEffect(EffectPresentation::GRAPHICAL, true)->playbackSegments,
        static fn(array $segment): bool => $segment['layer'] === 'field-motion',
    ));
    expect(array_map(static fn(array $segment): array => [$segment['startFrame'], $segment['drawCommands'][0]['payload']], $motion))->toBe([
        [2, ['x' => -10.25, 'y' => 3.5]], [3, ['x' => 7.0, 'y' => -2.0]], [5, ['x' => 0.0, 'y' => 0.0]], [6, ['x' => 1.5, 'y' => 0.0]],
        [7, ['x' => 0.0, 'y' => -0.5]],
    ]);
});

it('refuses a save the Engine rejects for the track and writes nothing', function () {
    $root = fieldMotionProject();
    [$session, , , $apply] = openFieldMotionSession($root);

    // The Engine draws a field motion in the graphical battle only.
    $apply('track1Presentation', 'all');
    $saved = $session->saveAll();

    expect($saved['failures'])->not->toBe([])
        ->and(file_get_contents(fieldMotionPath($root)))->toBe(quakeStrikeTimeline());
});

it('describes the motion in the terminal preview without imitating it, and never draws a disabled sample as one', function () {
    $root = fieldMotionProject();
    $asset = libraryOf(openFieldMotionEditor($root))->find(CutsceneType::EFFECT, 'quake-strike');
    $graphical = new TimelinePreviewSession($asset->compileEffect(EffectPresentation::GRAPHICAL, true), null);
    $stage = new EffectPreviewStage(EffectPresentation::GRAPHICAL, true);

    expect($stage->describeUndrawn($graphical->activeSegments(2)))->toBe(['field offset -10.25, 3.5'])
        ->and($stage->describeUndrawn($graphical->activeSegments(4)))->toBe(['field offset 40, 40 (disabled)'])
        ->and(implode("\n", $stage->drawFrame($graphical->activeSegments(2), 20, 3)))
        ->toContain(EffectPreviewStage::CASTER_MARKER, EffectPreviewStage::TARGET_MARKER);

    // The terminal sequence never receives the graphical track.
    $terminal = new TimelinePreviewSession($asset->compileEffect(EffectPresentation::TERMINAL, true), null);
    expect(new EffectPreviewStage(EffectPresentation::TERMINAL, true)->describeUndrawn($terminal->activeSegments(2)))->toBe([]);
});

it('previews the effect in battle: a static battlefield with the Engine saying the motion is not applied', function () {
    [$session, $index] = openFieldMotionSession(fieldMotionProject());
    $moving = $session->readEffectBattlePreview($index, 0, authoredFrame: 2);
    $disabled = $session->readEffectBattlePreview($index, 0, authoredFrame: 4);
    $terminal = $session->readEffectBattlePreview($index, 0, presentation: EffectPresentation::TERMINAL, authoredFrame: 2);

    // No canvas group is asked of an editor window that has not negotiated canvas_groups.
    expect($moving['authoredFrames']['target'])->toBe(2)
        ->and($moving['canvas'])->not->toHaveKey('groups')
        ->and($moving['canvas']['images'])->not->toBe([])
        ->and($moving['diagnostics'])->toHaveCount(1)
        ->and($disabled['diagnostics'])->toBe([])
        ->and($terminal['diagnostics'])->toBe([])
        ->and($terminal['terminalLines'])->not->toBe([]);
});

it('moves the battlefield by the authored offset, with no diagnostic, for a preview whose canvas draws canvas groups', function () {
    $root = fieldMotionProject();
    [$session, $index] = openFieldMotionSession($root);
    $samples = (require fieldMotionPath($root))['tracks'][1]['keyframes'];
    $readOrigins = static fn(array $frame): array => array_column($frame['canvas']['groups'] ?? [], 'origin');
    $read = static fn(int $authored, bool $canvasGroups): array => $session->readEffectBattlePreview($index, 0, authoredFrame: $authored, canvasGroups: $canvasGroups);

    $still = $read($samples[0]['frame'], false);
    $moving = $read($samples[0]['frame'], true);
    $next = $read($samples[1]['frame'], true);

    // Everything the still battlefield draws travels in the one group, as drawn; none of it stays behind on the canvas.
    $field = array_column($still['canvas']['images'], 'id');
    expect($readOrigins($moving))->toBe([$samples[0]['payload']])
        ->and($readOrigins($next))->toBe([$samples[1]['payload']])
        ->and($moving['diagnostics'])->toBe([])
        ->and($field)->not->toBe([])
        ->and(array_column($moving['canvas']['groups'][0]['canvas']['images'], 'id'))->toBe($field)
        ->and(array_intersect(array_column($moving['canvas']['images'], 'id'), $field))->toBe([])
        ->and([$moving['canvas']['width'], $moving['canvas']['height']])->toBe([$still['canvas']['width'], $still['canvas']['height']])
        ->and($moving['frame'])->toBe($still['frame']);

    // The same frame asked for without them is the still battlefield and the Engine's word that the motion is not applied,
    // before and after a frame composed with them.
    expect($still['canvas'])->not->toHaveKey('groups')
        ->and($still['canvas']['images'])->not->toBe([])
        ->and($still['diagnostics'])->toHaveCount(1)
        ->and($read($samples[0]['frame'], false))->toBe($still);
});

it('holds the battlefield in place where no enabled sample moves it, and keeps a zero sample as a motion of nothing', function () {
    $root = fieldMotionProject();
    [$session, $index] = openFieldMotionSession($root);
    $samples = (require fieldMotionPath($root))['tracks'][1]['keyframes'];
    $read = static fn(int $authored, bool $canvasGroups = true, bool $reducedMotion = false): array => $session->readEffectBattlePreview(
        $index, 0, $reducedMotion, authoredFrame: $authored, canvasGroups: $canvasGroups);
    $disabled = array_find($samples, static fn(array $sample): bool => ($sample['visible'] ?? true) === false);
    $zero = array_find($samples, static fn(array $sample): bool => $sample['payload'] == ['x' => 0, 'y' => 0]);

    // A frame before the first sample and a disabled sample release the field: the canvas is the one composed without groups.
    foreach ([$samples[0]['frame'] - 1, $disabled['frame']] as $frame) {
        $released = $read($frame);
        expect($released['canvas'])->not->toHaveKey('groups')
            ->and($released['diagnostics'])->toBe([])
            ->and($released)->toBe($read($frame, false));
    }

    // Zero is a sample of its own: the field is grouped at the origin, which a canvas without groups is told it cannot draw.
    expect(array_column($read($zero['frame'])['canvas']['groups'], 'origin'))->toEqual([['x' => 0, 'y' => 0]])
        ->and($read($zero['frame'])['diagnostics'])->toBe([])
        ->and($read($zero['frame'], false)['diagnostics'])->toHaveCount(1);

    // Reduced motion and the terminal arena never move the field, whatever the canvas draws.
    $reduced = $read($samples[0]['frame'], reducedMotion: true);
    $terminal = $session->readEffectBattlePreview($index, 0, presentation: EffectPresentation::TERMINAL,
        authoredFrame: $samples[0]['frame'], canvasGroups: true);
    expect($reduced['canvas'])->not->toHaveKey('groups')
        ->and($reduced['diagnostics'])->toBe([])
        ->and($terminal['canvas'])->toBeNull()
        ->and($terminal['diagnostics'])->toBe([]);
});

it('reads the canvas groups request from the host route, and composes them for that request alone', function () {
    $root = fieldMotionProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array => $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params]));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $index = array_find_key($request('database.records', ['category' => 'cutscenes/effect'])['result']['records'],
        static fn(mixed $label): bool => str_contains(strval($label), 'quake-strike'));
    $frame = (require fieldMotionPath($root))['tracks'][1]['keyframes'][0];
    $preview = static fn(array $features): array => $request('cutscenes.effectBattlePreview',
        ['index' => $index, 'frame' => 0, 'authoredFrame' => $frame['frame'], 'presentation' => 'graphical', ...$features])['result'];

    expect(array_column($preview(['canvasGroups' => true])['canvas']['groups'], 'origin'))->toBe([$frame['payload']])
        ->and($preview(['canvasGroups' => true])['diagnostics'])->toBe([]);

    // Only a true flag asks for them: left out, false or another feature's flag leaves the field still.
    foreach ([[], ['canvasGroups' => false], ['canvasGroups' => 'yes'], ['regionBudget' => true, 'imageRotation' => true]] as $features) {
        $still = $preview($features);
        expect($still['canvas'])->not->toHaveKey('groups')
            ->and($still['diagnostics'])->toHaveCount(1);
    }

    // Another negotiated feature beside them keeps both.
    $both = $preview(['canvasGroups' => true, 'regionBudget' => true]);
    expect($both['canvas']['groups'])->toHaveCount(1)
        ->and($both['diagnostics'])->toBe([]);
});

it('leaves a summon timeline holding the track as written while the Engine refuses it', function () {
    $root = fieldMotionProject();
    $path = $root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.timeline.php';
    $source = str_replace("  ],\n  'cues'", "    ['id' => 'jolt', 'type' => 'field-motion', 'keyframes' => [['frame' => 3, 'payload' => ['x' => 1.5, 'y' => 0]]]],\n  ],\n  'cues'",
        (string) file_get_contents($path));
    file_put_contents($path, $source);
    $editor = cutscenesEditor($root, 160, 50);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::SUMMON);
    callEditorMethod($editor, 'selectCutsceneById', 'lantern-wisp');
    $asset = libraryOf($editor)->find(CutsceneType::SUMMON, 'lantern-wisp');

    $tracks = $asset->payload()['tracks'];
    $jolt = array_search('jolt', array_column($tracks, 'id'), true);

    expect($tracks[$jolt])->toBe(['id' => 'jolt', 'type' => 'field-motion', 'keyframes' => [['frame' => 3, 'payload' => ['x' => 1.5, 'y' => 0]]]])
        ->and(fn() => $asset->hydrate())->toThrow(RuntimeException::class);

    openCutsceneFrame($editor, 'commandListTracks');
    setCutsceneField($editor, "track{$jolt}Type", 'glyph');
    expect(getEditorProperty($editor, 'statusMessage'))->toContain('A track of type "field-motion" is not edited here')
        ->and($asset->isDirty())->toBeFalse();

    setCutsceneField($editor, 'track0Id', 'wisp-light');
    expect(fn() => $asset->save())->toThrow(RuntimeException::class)
        ->and(file_get_contents($path))->toBe($source);
});
