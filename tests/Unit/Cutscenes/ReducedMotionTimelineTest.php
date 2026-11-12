<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneOutline;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Preview\EffectPreviewStage;
use Ichiloto\Editor\Cutscenes\Preview\TimelinePreviewSession;
use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Animations\Timelines\EffectMotionScope;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;

/**
 * The Engine's reduced-motion samples (`reducedMotionSamples`) and a track's
 * motion scope (`motion`) in effect and summon timelines: kept as written
 * whatever else is edited, a clearing sample's `null` included; authored
 * through the graphical editor's shared rows; listed and never changed by the
 * terminal editor; judged by the Engine on every save. Synthetic timelines
 * only.
 */
function heldLanceTimeline(): string
{
    return <<<'PHP_SOURCE'
<?php

// Held Lance: a lance in motion, and drawings held for reduced motion.
return [
  'fps' => 24,
  'lengthFrames' => 12,
  // Reduced motion holds two drawings, then clears.
  'reducedMotionSamples' => [
    ['frame' => 0, 'duration' => 4, 'sourceFrame' => 1],
    ['sourceFrame' => 5, 'frame' => 4, 'duration' => 4], // the strike
    ['frame' => 8, 'duration' => 4, 'sourceFrame' => null],
  ],
  'tracks' => [
    ['id' => 'film', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'Graphics/Effects/held-film.png',
     'keyframes' => [['frame' => 0, 'duration' => 12, 'sourceFrame' => 0]]],
    ['id' => 'lance', 'type' => 'image', 'motion' => 'normal', 'asset' => 'Graphics/Effects/held-lance.png', 'sheet' => ['columns' => 4, 'rows' => 1],
     'keyframes' => [
       ['frame' => 0, 'duration' => 3, 'sourceFrame' => 0],
       ['frame' => 3, 'duration' => 3, 'sourceFrame' => 1],
       ['frame' => 6, 'duration' => 3, 'sourceFrame' => 2],
       ['frame' => 9, 'duration' => 3, 'sourceFrame' => 3],
     ]],
    // Drawn only for reduced motion.
    ['id' => 'still-lance',   'type' => 'image', 'motion' => 'reduced', 'asset' => 'Graphics/Effects/held-still.png', 'sheet' => ['columns' => 4, 'rows' => 1],
     'keyframes' => [
       ['frame' => 0, 'duration' => 4, 'sourceFrame' => 0],
       ['frame' => 4, 'duration' => 4, 'sourceFrame' => 1],
       ['frame' => 8, 'duration' => 4, 'sourceFrame' => 2],
     ]],
    ['id' => 'spark', 'type' => 'glyph', 'motion' => 'all', 'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => '*', 'position' => ['x' => 0, 'y' => 0]]]],
    ['id' => 'stroke', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => '/', 'position' => ['x' => 1, 'y' => 0]]]],
  ],
  'cues' => [],
];
PHP_SOURCE;
}

/** A summon whose one sequence, for every renderer, holds a plan and a drawing for each motion setting. */
function heldWispTimeline(): string
{
    return <<<'PHP_SOURCE'
<?php

return [
  'formatVersion' => 1,
  'fps' => 12,
  'lengthFrames' => 24,
  // Reduced motion holds the wisp, then clears.
  'reducedMotionSamples' => [
    ['frame' => 0, 'duration' => 12, 'sourceFrame' => 3],
    ['frame' => 12, 'duration' => 12, 'sourceFrame' => null], // gone
  ],
  'tracks' => [
    ['type' => 'glyph', 'id' => 'wisp', 'keyframes' => [
      ['frame' => 0, 'duration' => 24, 'content' => '*', 'position' => [10, 5]],
    ]],
    ['type' => 'image', 'id' => 'wisp-art', 'motion' => 'normal', 'asset' => 'Graphics/Summons/Wisp.png', 'sheet' => ['columns' => 2, 'rows' => 1],
      'anchor' => 'target', 'keyframes' => [['frame' => 0, 'duration' => 12, 'sourceFrame' => 0], ['frame' => 12, 'duration' => 12, 'sourceFrame' => 1]]],
    ['type' => 'image', 'id' => 'wisp-still', 'motion' => 'reduced', /* held */ 'asset' => 'Graphics/Summons/Still.png',
      'anchor' => 'target', 'keyframes' => [['frame' => 0, 'duration' => 24, 'sourceFrame' => 0]]],
  ],
  'cues' => [
    ['id' => 'flare', 'frame' => 12, 'type' => 'applyEffect'],
  ],
];
PHP_SOURCE;
}

/** The same summon with separate sequences: the plan and the motion drawings in the graphical one. */
function heldWispPairedTimeline(): string
{
    return <<<'PHP_SOURCE'
<?php

return [
  'formatVersion' => 1,
  'presentations' => [
    'terminal' => [
      'fps' => 12,
      'lengthFrames' => 24,
      'tracks' => [
        ['type' => 'glyph', 'id' => 'wisp', 'keyframes' => [['frame' => 0, 'duration' => 24, 'content' => '*', 'position' => ['x' => 10, 'y' => 5]]]],
      ],
      'cues' => [['id' => 'flare', 'frame' => 12, 'type' => 'applyEffect']],
    ],
    'graphical' => [
      'fps' => 12,
      'lengthFrames' => 24,
      'reducedMotionSamples' => [
        ['frame' => 0, 'duration' => 12, 'sourceFrame' => 3],
        ['frame' => 12, 'duration' => 12, 'sourceFrame' => null], // gone
      ],
      'tracks' => [
        ['type' => 'image', 'id' => 'wisp-art', 'motion' => 'normal', 'asset' => 'Graphics/Summons/Wisp.png', 'sheet' => ['columns' => 2, 'rows' => 1],
          'keyframes' => [['frame' => 0, 'duration' => 12, 'sourceFrame' => 0], ['frame' => 12, 'duration' => 12, 'sourceFrame' => 1]]],
        ['type' => 'image', 'id' => 'wisp-still', 'motion' => 'reduced', /* held */ 'asset' => 'Graphics/Summons/Still.png',
          'keyframes' => [['frame' => 0, 'duration' => 24, 'sourceFrame' => 0]]],
      ],
      'cues' => [['id' => 'flare', 'frame' => 12, 'type' => 'applyEffect']],
    ],
  ],
];
PHP_SOURCE;
}

/** A battle test project whose unarmed attack plays Held Lance on its target, and whose summon holds a plan. */
function reducedMotionProject(?string $summonTimeline = null): string
{
    $root = effectBattlePreviewProject();
    mkdir($root . '/assets/Animations/held-lance', 0o777, true);
    file_put_contents(effectTimelinePath($root, 'held-lance'), heldLanceTimeline());
    foreach (['Effects/held-film' => 16, 'Effects/held-lance' => 64, 'Effects/held-still' => 64, 'Summons/Still' => 8] as $name => $width) {
        writeTilesetTestPng($root . '/assets/Graphics/' . $name . '.png', $width, 16);
    }
    file_put_contents($root . '/assets/Data/animations.php', "<?php\n\nreturn " . var_export([
        ['id' => 1, 'name' => 'Held Lance', 'roles' => ['attack', 'attack-unarmed'], 'targetEffect' => 'held-lance'],
    ], true) . ";\n");
    file_put_contents(heldWispPath($root), $summonTimeline ?? heldWispTimeline());

    return $root;
}

function heldWispPath(string $root): string
{
    return $root . '/assets/Cutscenes/Summons/lantern-wisp/lantern-wisp.timeline.php';
}

/** The terminal editor's Cutscenes screen on the project's summon, at the record's own rows. */
function openHeldWispEditor(string $root): Editor
{
    $editor = cutscenesEditor($root, 160, 50);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::SUMMON);
    callEditorMethod($editor, 'selectCutsceneById', 'lantern-wisp');
    setEditorProperty($editor, 'databaseCommandFramePath', []);

    return $editor;
}

/**
 * The graphical editor's rows of one frame of a timeline record: a row by its field, and an edit of a row by its field.
 *
 * @return array{0: Closure(string): ?array<string, mixed>, 1: Closure(string, string): array<string, mixed>}
 */
function readReducedMotionRows(EditorSession $session, string $category, int $index, array $frame): array
{
    $row = static fn(string $field): ?array => array_find($session->readDatabaseRecord($category, $index, $frame)['rows'],
        static fn(array $entry): bool => ($entry['key']['field'] ?? null) === $field);
    $apply = static fn(string $field, string $value): array => $session->applyDatabaseRecord($category, $index,
        ($row($field) ?? throw new RuntimeException("No row {$field}."))['key'], $value);

    return [$row, $apply];
}

/** The reduced-motion samples of a timeline source, from their key to the list's closing bracket, comments and spacing included. */
function readReducedMotionSource(string $source): string
{
    expect(preg_match("/'reducedMotionSamples' => \\[.*?\\n\\s*\\],/s", $source, $match))->toBe(1);

    return $match[0];
}

/** Which of the held timeline's images a battle preview frame draws: each image's file, and where on its sheet the cell it shows starts. */
function readHeldImages(array $frame, string $marker = 'held-'): array
{
    $images = array_values(array_filter($frame['canvas']['images'] ?? [], static fn(array $image): bool => str_contains($image['asset'], $marker)));

    return array_map(static fn(array $image): array => [basename($image['asset'], '.png'), (int) ($image['sourceRect']['x'] ?? 0)], $images);
}

it('opens a timeline holding reduced-motion samples and motion scopes as written, and lists both in the outline', function () {
    $root = reducedMotionProject();
    $editor = openEffectEditor($root, 'held-lance');
    $effect = libraryOf($editor)->find(CutsceneType::EFFECT, 'held-lance');
    $effect->hydrate();
    $summon = libraryOf($editor)->find(CutsceneType::SUMMON, 'lantern-wisp');
    $summon->hydrate();

    expect($effect->readOnlyReason())->toBeNull()
        ->and($effect->payload()['reducedMotionSamples'])->toBe([
            ['frame' => 0, 'duration' => 4, 'sourceFrame' => 1],
            ['sourceFrame' => 5, 'frame' => 4, 'duration' => 4],
            ['frame' => 8, 'duration' => 4, 'sourceFrame' => null],
        ])
        ->and(array_map(static fn(array $track): ?string => $track['motion'] ?? null, $effect->payload()['tracks']))->toBe([null, 'normal', 'reduced', 'all', null])
        ->and(array_column(CutsceneOutline::of(CutsceneType::EFFECT, $effect->payload())->rows(), 'text'))->toContain(
            'image film · 1 keyframe · graphical only',
            'image lance · 4 keyframes · normal motion only',
            'image still-lance · 3 keyframes · reduced motion only',
            // Every motion setting, named or left out, is no scope to tell.
            'glyph spark · 1 keyframe',
            'glyph stroke · 1 keyframe',
            'Reduced motion (3 samples)',
            'f0 +4  holds frame 1',
            'f4 +4  holds frame 5',
            'f8 +4  clears the picture',
        )
        ->and(renderEditorPlainFrame($editor, 160, 50))->toContain('Reduced motion (3 samples)', 'f8 +4  clears the picture',
            'The engine reads this effect as it stands.')
        ->and($summon->readOnlyReason())->toBeNull()
        ->and(array_column(CutsceneOutline::of(CutsceneType::SUMMON, $summon->payload())->rows(), 'text'))->toContain(
            'image wisp-art · 2 keyframes · normal motion only',
            'image wisp-still · 1 keyframe · reduced motion only',
            'Reduced motion (2 samples)',
            'f0 +12  holds frame 3',
            'f12 +12  clears the picture',
        );
});

it('lists no reduced motion for a timeline that holds no samples, and says what a malformed sample lacks', function () {
    $root = reducedMotionProject();
    $plain = libraryOf(openEffectEditor($root, 'dusk-slash'))->find(CutsceneType::EFFECT, 'dusk-slash');

    expect(array_filter(array_column(CutsceneOutline::of(CutsceneType::EFFECT, $plain->payload())->rows(), 'text'),
        static fn(string $text): bool => str_contains(strtolower($text), 'motion')))->toBe([])
        ->and(CutsceneOutline::describeReducedMotionSample(['frame' => 2, 'duration' => 3]))->toBe('f2 +3  names no source frame')
        ->and(CutsceneOutline::describeReducedMotionSample(['frame' => 'x', 'sourceFrame' => 0]))->toBe('f? +?  holds frame 0');
});

it('gives the terminal editor no rows for the samples or a track\'s motion, and no outline operation that changes a sample', function () {
    $root = reducedMotionProject();
    $editor = openEffectEditor($root, 'held-lance');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'held-lance');
    $before = $asset->payload();

    expect(array_filter(cutsceneFieldIds($editor), static fn(string $id): bool => str_contains(strtolower($id), 'reduced')))->toBe([]);
    openCutsceneFrame($editor, 'commandListTracks');
    expect(array_filter(cutsceneFieldIds($editor), static fn(string $id): bool => str_contains(strtolower($id), 'motion')))->toBe([])
        ->and(cutsceneFieldIds($editor))->toContain('track1Id', 'track1Type', 'track1Presentation');

    // Every tree operation on the heading and on a sample row: move, add, copy, remove, nest, unnest, nudge.
    $rows = callEditorMethod($editor, 'visibleCutsceneTreeRows');
    foreach (['reducedMotionSamples', 'reducedMotionSamples.1', 'reducedMotionSamples.2'] as $key) {
        $position = array_find_key($rows, static fn(array $row): bool => $row['key'] === $key);
        expect($position)->not->toBeNull();
        setEditorProperty($editor, 'cutsceneTreeCursor', $position);
        foreach ([['reorderCutsceneTreeRow', 1], ['reorderCutsceneTreeRow', -1], ['insertCutsceneTreeRow'], ['duplicateCutsceneTreeRow'],
            ['removeCutsceneTreeRow'], ['nestCutsceneTreeRow'], ['unnestCutsceneTreeRow'], ['nudgeCutsceneTreeFrame', 1]] as $operation) {
            callEditorMethod($editor, ...$operation);
        }
        // Enter opens the record's own rows, which hold no row for a sample.
        callEditorMethod($editor, 'openCutsceneTreeRow');
        expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe([])
            ->and(array_filter(cutsceneFieldIds($editor), static fn(string $id): bool => str_contains(strtolower($id), 'reduced')))->toBe([]);
    }

    expect($asset->payload())->toBe($before)
        ->and($asset->isDirty())->toBeFalse()
        ->and($asset->save())->toBeFalse()
        ->and(file_get_contents(effectTimelinePath($root, 'held-lance')))->toBe(heldLanceTimeline());
});

it('keeps the samples and every motion key byte for byte when other fields and tracks are edited and saved', function () {
    $root = reducedMotionProject();
    $path = effectTimelinePath($root, 'held-lance');
    $original = heldLanceTimeline();
    $editor = openEffectEditor($root, 'held-lance');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'held-lance');

    // The terminal editor: a timeline field, a keyframe beside the motion tracks, and a motion track's own id and type row.
    setCutsceneField($editor, 'restFrame', '2');
    openCutsceneFrame($editor, 'commandListTracks');
    setCutsceneField($editor, 'track4Keyframe0Content', '\\');
    setCutsceneField($editor, 'track2Id', 'held');
    setCutsceneField($editor, 'track2Id', 'still-lance');
    setCutsceneField($editor, 'track1Fit', 'contain');
    setCutsceneField($editor, 'track1Fit', '');
    // A track given another type and its own back keeps the motion key neither type reads here.
    setCutsceneField($editor, 'track3Type', 'text');
    expect($asset->payload()['tracks'][3])->toMatchArray(['type' => 'text', 'motion' => 'all']);
    setCutsceneField($editor, 'track3Type', 'glyph');

    expect($asset->save())->toBeTrue();
    $saved = (string) file_get_contents($path);
    expect($saved)->toBe(str_replace(["'content' => '/'", "  'cues' => [],\n"], ["'content' => '\\\\'", "  'cues' => [],\n  'restFrame' => 2,\n"], $original));

    // The graphical editor: a track added before the motion tracks and one removed after them, a field of each motion
    // track, and a sample's neighbour left alone.
    file_put_contents($path, $original);
    [$session, $index, $row, $apply] = openEffectTrackSession($root, 'held-lance');
    $session->addDatabaseItem('cutscenes/effect', $index, $row('track0Id')['key']);
    $session->removeDatabaseItem('cutscenes/effect', $index, $row('track5Id')['key']);
    $apply('track2Depth', 'behind');
    $apply('track3Fit', 'contain');
    $apply('track4Keyframe0Content', '+');
    expect($session->saveAll()['failures'])->toBe([]);
    $saved = (string) file_get_contents($path);
    $tracks = (require $path)['tracks'];

    expect(readReducedMotionSource($saved))->toBe(readReducedMotionSource($original))
        ->and($saved)->toContain('// Reduced motion holds two drawings, then clears.', '// Drawn only for reduced motion.',
            "['sourceFrame' => 5, 'frame' => 4, 'duration' => 4], // the strike", "'sourceFrame' => null],")
        ->and(array_column($tracks, 'id'))->toBe(['film', 'track', 'lance', 'still-lance', 'spark'])
        // Each track keeps the motion key it had, the explicit `all` included, and a track without one gains none.
        ->and(array_map(static fn(array $track): ?string => $track['motion'] ?? null, $tracks))->toBe([null, null, 'normal', 'reduced', 'all'])
        ->and(array_map(static fn(array $track): bool => array_key_exists('motion', $track), $tracks))->toBe([false, false, true, true, true])
        ->and([$tracks[2]['depth'], $tracks[3]['fit']])->toBe(['behind', 'contain'])
        ->and((require $path)['reducedMotionSamples'][2])->toBe(['frame' => 8, 'duration' => 4, 'sourceFrame' => null]);
});

it('keeps a summon\'s samples and motion keys as written, in one sequence and in the graphical one of a pair', function () {
    // One sequence for every renderer: the terminal editor edits the definition and a track.
    $root = reducedMotionProject();
    $editor = openHeldWispEditor($root);
    $summon = libraryOf($editor)->find(CutsceneType::SUMMON, 'lantern-wisp');
    expect(array_filter(cutsceneFieldIds($editor), static fn(string $id): bool => str_contains(strtolower($id), 'reduced')))->toBe([]);
    setCutsceneField($editor, 'moveName', 'Wisp Flash');
    openCutsceneFrame($editor, 'commandListTracks');
    expect(array_filter(cutsceneFieldIds($editor), static fn(string $id): bool => str_contains(strtolower($id), 'motion')))->toBe([]);
    setCutsceneField($editor, 'track0Id', 'wisp-light');

    expect($summon->save())->toBeTrue()
        ->and(file_get_contents(heldWispPath($root)))->toBe(str_replace("'id' => 'wisp', 'keyframes'", "'id' => 'wisp-light', 'keyframes'", heldWispTimeline()));

    // Separate sequences: the graphical editor edits the definition, then each sequence, the terminal one first.
    $root = reducedMotionProject(heldWispPairedTimeline());
    $session = EditorSession::open($root);
    $category = 'cutscenes/summon';
    [$row, $apply] = readReducedMotionRows($session, $category, 0, []);
    $apply('moveName', 'Wisp Flash');
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents(heldWispPath($root)))->toBe(heldWispPairedTimeline());

    foreach (['terminal', 'graphical'] as $sequence) {
        $session->selectCutscenePresentation($category, 0, $sequence);
        $apply('restFrame', '3');
        expect($session->saveAll()['failures'])->toBe([]);
    }
    $saved = (string) file_get_contents(heldWispPath($root));
    $sequences = (require heldWispPath($root))['presentations'];

    expect(readReducedMotionSource($saved))->toBe(readReducedMotionSource(heldWispPairedTimeline()))
        ->and($saved)->toContain("['frame' => 12, 'duration' => 12, 'sourceFrame' => null], // gone", "'motion' => 'reduced', /* held */")
        ->and($sequences['graphical']['reducedMotionSamples'])->toBe([['frame' => 0, 'duration' => 12, 'sourceFrame' => 3], ['frame' => 12, 'duration' => 12, 'sourceFrame' => null]])
        ->and(array_column($sequences['graphical']['tracks'], 'motion'))->toBe(['normal', 'reduced'])
        ->and($sequences['terminal'])->not->toHaveKey('reducedMotionSamples')
        ->and([$sequences['terminal']['restFrame'], $sequences['graphical']['restFrame']])->toBe([3, 3]);
});

it('lays the samples and each track\'s motion out for the graphical editor, by the rows that edit them', function () {
    $root = reducedMotionProject();
    [$session, $index, $track] = openEffectTrackSession($root, 'held-lance');
    $category = 'cutscenes/effect';
    [$sample] = readReducedMotionRows($session, $category, $index, [CutsceneSchemas::REDUCED_MOTION_SAMPLES_KEY]);
    [$record] = readReducedMotionRows($session, $category, $index, []);
    $timeline = $session->describeCutsceneTimeline($category, $index);
    $scopes = array_column(EffectMotionScope::cases(), 'value');

    expect($record('commandListReducedMotionSamples'))->toMatchArray(['label' => 'Reduced Motion Samples', 'value' => '3'])
        ->and([$sample('reducedSample0Frame')['value'], $sample('reducedSample0Duration')['value'], $sample('reducedSample0SourceFrame')['value']])->toBe(['0', '4', '1'])
        ->and($sample('reducedSample1SourceFrame')['value'])->toBe('5')
        ->and($sample('reducedSample2SourceFrame')['value'])->toBe(CutsceneSchemas::REDUCED_MOTION_CLEAR)
        // Every track offers the Engine's motion settings, and the empty choice that leaves the key out.
        ->and($track('track1Motion'))->toMatchArray(['value' => 'normal', 'options' => ['', ...$scopes]])
        ->and(array_map(static fn(int $at): string => $track("track{$at}Motion")['value'], range(0, 4)))
        ->toBe([EffectMotionScope::ALL->value, 'normal', 'reduced', 'all', EffectMotionScope::ALL->value])
        ->and(array_column($timeline['tracks'], 'motion'))->toBe(['all', 'normal', 'reduced', 'all', 'all'])
        ->and($timeline['tracks'][2]['motionKey'])->toBe($track('track2Motion')['key'])
        ->and(array_map(static fn(array $held): array => [$held['frame'], $held['duration'], $held['sourceFrame']], $timeline['reducedMotionSamples']))
        ->toBe([[0, 4, 1], [4, 4, 5], [8, 4, null]])
        ->and($timeline['reducedMotionSamples'][2]['sourceFrameKey'])->toBe($sample('reducedSample2SourceFrame')['key'])
        ->and($timeline['reducedMotionSamples'][1]['frameKey'])->toBe($sample('reducedSample1Frame')['key'])
        // A timeline without samples lays none out.
        ->and($session->describeCutsceneTimeline($category, findEffectIndex($session, 'dusk-slash'))['reducedMotionSamples'])->toBe([]);
});

it('authors a hold and a clear through the source frame row, writing null for a clear and never dropping the key', function () {
    $root = reducedMotionProject();
    $path = effectTimelinePath($root, 'held-lance');
    $authored = (require $path)['reducedMotionSamples'];
    [$session, $index] = openEffectTrackSession($root, 'held-lance');
    $category = 'cutscenes/effect';
    [$sample, $apply] = readReducedMotionRows($session, $category, $index, [CutsceneSchemas::REDUCED_MOTION_SAMPLES_KEY]);
    $read = static fn(): array => $session->describeCutsceneTimeline($category, $index)['reducedMotionSamples'];

    // A hold cleared by an empty value, a hold cleared by the row's own word for it, a clear given a frame, and a hold of frame zero.
    expect($apply('reducedSample0SourceFrame', '')['changed'])->toBeTrue()
        ->and($apply('reducedSample1SourceFrame', strtoupper(CutsceneSchemas::REDUCED_MOTION_CLEAR))['changed'])->toBeTrue()
        ->and($apply('reducedSample2SourceFrame', '9')['changed'])->toBeTrue()
        ->and(array_column($read(), 'sourceFrame'))->toBe([null, null, 9])
        ->and($sample('reducedSample0SourceFrame')['value'])->toBe(CutsceneSchemas::REDUCED_MOTION_CLEAR)
        ->and($apply('reducedSample0SourceFrame', '0')['changed'])->toBeTrue()
        ->and($sample('reducedSample0SourceFrame')['value'])->toBe('0')
        ->and(array_column($read(), 'sourceFrame'))->toBe([0, null, 9]);

    expect($session->saveAll()['failures'])->toBe([]);
    $saved = (string) file_get_contents($path);
    $samples = (require $path)['reducedMotionSamples'];

    // Each sample keeps its three keys in the order it had; only the source frames changed.
    expect($samples)->toBe([
        ['frame' => 0, 'duration' => 4, 'sourceFrame' => 0],
        ['sourceFrame' => null, 'frame' => 4, 'duration' => 4],
        ['frame' => 8, 'duration' => 4, 'sourceFrame' => 9],
    ])
        // In the source only the three values changed, each where it stood, and nothing outside the samples.
        ->and(str_ireplace('=> null', '=> null', readReducedMotionSource($saved)))->toBe(str_replace(
            ["'sourceFrame' => 1]", "'sourceFrame' => 5, 'frame' => 4", "'sourceFrame' => null]"],
            ["'sourceFrame' => 0]", "'sourceFrame' => null, 'frame' => 4", "'sourceFrame' => 9]"], readReducedMotionSource(heldLanceTimeline())))
        ->and(str_replace(readReducedMotionSource($saved), readReducedMotionSource(heldLanceTimeline()), $saved))->toBe(heldLanceTimeline());

    // What each row reads, written back to it, changes nothing: a clear stays null, zero stays zero.
    foreach (range(0, 2) as $at) {
        expect($apply("reducedSample{$at}SourceFrame", $sample("reducedSample{$at}SourceFrame")['value'])['changed'])->toBeFalse();
    }
    // Another row of a clearing sample leaves its null where it is.
    $apply('reducedSample1Duration', '4');
    $apply('reducedSample1Frame', '4');
    expect(array_column($read(), 'sourceFrame'))->toBe([0, null, 9])
        ->and($session->saveAll()['failures'])->toBe([])
        ->and((require $path)['reducedMotionSamples'])->toBe($samples);

    foreach (range(1, 4) as $step) {
        $session->undo();
    }
    expect($session->saveAll()['failures'])->toBe([])
        ->and((require $path)['reducedMotionSamples'])->toBe($authored);
});

it('adds and removes samples in the graphical editor, and takes the key from the file with the last one', function () {
    $root = reducedMotionProject();
    $path = effectTimelinePath($root, 'held-lance');
    [$session, $index] = openEffectTrackSession($root, 'held-lance');
    $category = 'cutscenes/effect';
    $frame = [CutsceneSchemas::REDUCED_MOTION_SAMPLES_KEY];
    [$sample, $apply] = readReducedMotionRows($session, $category, $index, $frame);

    // The last sample split in two: it ends two frames sooner, and a new one clears from there.
    $apply('reducedSample2Duration', '2');
    $apply('reducedSample2SourceFrame', '9');
    $session->addDatabaseItem($category, $index, $sample('reducedSample2Frame')['key']);
    expect([$sample('reducedSample3Frame')['value'], $sample('reducedSample3Duration')['value'], $sample('reducedSample3SourceFrame')['value']])->toBe(['0', '1', '0']);
    $apply('reducedSample3Frame', '10');
    $apply('reducedSample3Duration', '2');
    $apply('reducedSample3SourceFrame', '');
    expect($session->saveAll()['failures'])->toBe([])
        ->and((require $path)['reducedMotionSamples'])->toBe([
            ['frame' => 0, 'duration' => 4, 'sourceFrame' => 1],
            ['sourceFrame' => 5, 'frame' => 4, 'duration' => 4],
            ['frame' => 8, 'duration' => 2, 'sourceFrame' => 9],
            ['frame' => 10, 'duration' => 2, 'sourceFrame' => null],
        ]);
    // The Engine reads the plan it was given.
    $compiled = libraryOf(openEffectEditor($root, 'held-lance'))->find(CutsceneType::EFFECT, 'held-lance')->compileEffect(EffectPresentation::GRAPHICAL, true);
    expect(array_map($compiled->getReducedMotionSampleFrame(...), [0, 5, 9, 10, 11]))->toBe([1, 5, 9, null, null]);

    // Every sample removed: the key goes, and the effect needs the rest picture it had no need of before.
    foreach (range(3, 0) as $at) {
        $session->removeDatabaseItem($category, $index, $sample("reducedSample{$at}Frame")['key']);
    }
    expect($session->describeCutsceneTimeline($category, $index)['reducedMotionSamples'])->toBe([])
        ->and($session->saveAll()['failures'])->toBe([])
        ->and(require $path)->not->toHaveKey('reducedMotionSamples')
        ->and(file_get_contents($path))->toContain("'motion' => 'reduced'", '// Drawn only for reduced motion.');

    // A first sample for a timeline that holds none, from the record's own list row.
    $session->addDatabaseItem($category, $index, ['frame' => $frame]);
    $apply('reducedSample0Duration', '12');
    $apply('reducedSample0SourceFrame', '11');
    expect($session->saveAll()['failures'])->toBe([])
        ->and((require $path)['reducedMotionSamples'])->toBe([['frame' => 0, 'duration' => 12, 'sourceFrame' => 11]]);
});

it('authors a track\'s motion scope, leaves a track that names none without the key, and removes it with the empty choice', function () {
    $root = reducedMotionProject();
    $path = effectTimelinePath($root, 'held-lance');
    [$session, $index, $row, $apply] = openEffectTrackSession($root, 'held-lance');

    // A scope chosen for a track that had none, one changed, one named `all` outright, and two removed.
    $apply('track0Motion', 'normal');
    $apply('track1Motion', 'reduced');
    $apply('track2Motion', 'all');
    $apply('track3Motion', '');
    expect($session->saveAll()['failures'])->toBe([]);
    $tracks = (require $path)['tracks'];

    expect(array_map(static fn(array $track): ?string => $track['motion'] ?? null, $tracks))->toBe(['normal', 'reduced', 'all', null, null])
        ->and(array_key_exists('motion', $tracks[3]))->toBeFalse()
        ->and(array_key_exists('motion', $tracks[4]))->toBeFalse()
        ->and($row('track3Motion')['value'])->toBe(EffectMotionScope::ALL->value)
        // The samples beside them are as written.
        ->and(readReducedMotionSource((string) file_get_contents($path)))->toBe(readReducedMotionSource(heldLanceTimeline()));

    foreach (range(1, 4) as $step) {
        $session->undo();
    }
    expect($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(heldLanceTimeline());
});

it('refuses a save the Engine rejects for the samples or a motion scope, and writes nothing', function (Closure $edit) {
    $root = reducedMotionProject();
    $path = effectTimelinePath($root, 'held-lance');
    [$session, $index, , $track] = openEffectTrackSession($root, 'held-lance');
    [, $sample] = readReducedMotionRows($session, 'cutscenes/effect', $index, [CutsceneSchemas::REDUCED_MOTION_SAMPLES_KEY]);
    [, $record] = readReducedMotionRows($session, 'cutscenes/effect', $index, []);

    $edit($sample, $track, $record);
    $saved = $session->saveAll();

    expect($saved['failures'])->not->toBe([])
        ->and(file_get_contents($path))->toBe(heldLanceTimeline());
})->with([
    'a gap between samples' => [static fn(Closure $sample): array => $sample('reducedSample1Frame', '5')],
    'samples that stop short of the last frame' => [static fn(Closure $sample): array => $sample('reducedSample2Duration', '3')],
    'a source frame past the timeline' => [static fn(Closure $sample): array => $sample('reducedSample0SourceFrame', '12')],
    'a motion-specific track drawn by every renderer' => [static fn(Closure $sample, Closure $track): array => $track('track2Presentation', 'all')],
    'samples on a looping timeline' => [static fn(Closure $sample, Closure $track, Closure $record): array => $record('playback', 'loop')],
]);

it('authors a paired summon\'s plan and motion scopes in its graphical sequence, and offers the terminal sequence no plan', function () {
    $root = reducedMotionProject(heldWispPairedTimeline());
    $terminal = (require heldWispPath($root))['presentations']['terminal'];
    $session = EditorSession::open($root);
    $category = 'cutscenes/summon';
    $frame = [CutsceneSchemas::REDUCED_MOTION_SAMPLES_KEY];
    [$record] = readReducedMotionRows($session, $category, 0, []);
    [$sample, $apply] = readReducedMotionRows($session, $category, 0, $frame);
    [$track, $applyTrack] = readReducedMotionRows($session, $category, 0, ['tracks']);

    $session->selectCutscenePresentation($category, 0, 'terminal');
    expect($record('commandListReducedMotionSamples'))->toBeNull()
        ->and($session->describeCutsceneTimeline($category, 0)['reducedMotionSamples'])->toBe([]);

    $session->selectCutscenePresentation($category, 0, 'graphical');
    expect($record('commandListReducedMotionSamples')['value'])->toBe('2')
        ->and($sample('reducedSample1SourceFrame')['value'])->toBe(CutsceneSchemas::REDUCED_MOTION_CLEAR)
        ->and($track('track1Motion'))->toMatchArray(['value' => 'reduced', 'options' => ['', ...array_column(EffectMotionScope::cases(), 'value')]]);

    $apply('reducedSample0SourceFrame', '');
    $apply('reducedSample1SourceFrame', '20');
    $applyTrack('track0Motion', '');
    expect($session->saveAll()['failures'])->toBe([]);
    $timeline = require heldWispPath($root);

    expect($timeline['presentations']['graphical']['reducedMotionSamples'])->toBe([
        ['frame' => 0, 'duration' => 12, 'sourceFrame' => null], ['frame' => 12, 'duration' => 12, 'sourceFrame' => 20]])
        ->and(array_map(static fn(array $entry): ?string => $entry['motion'] ?? null, $timeline['presentations']['graphical']['tracks']))->toBe([null, 'reduced'])
        ->and($timeline['presentations']['terminal'])->toBe($terminal)
        ->and(array_keys($timeline))->toBe(['formatVersion', 'presentations']);
});

it('authors a summon\'s plan beside its one sequence, where the Engine reads it', function () {
    $root = reducedMotionProject();
    $session = EditorSession::open($root);
    $category = 'cutscenes/summon';
    [$sample, $apply] = readReducedMotionRows($session, $category, 0, [CutsceneSchemas::REDUCED_MOTION_SAMPLES_KEY]);
    [, $applyTrack] = readReducedMotionRows($session, $category, 0, ['tracks']);

    $apply('reducedSample0SourceFrame', '0');
    $apply('reducedSample1SourceFrame', '13');
    $apply('reducedSample1SourceFrame', '');
    $applyTrack('track0Motion', 'normal');
    expect($session->saveAll()['failures'])->toBe([]);
    $timeline = require heldWispPath($root);

    expect($timeline['reducedMotionSamples'])->toBe([['frame' => 0, 'duration' => 12, 'sourceFrame' => 0], ['frame' => 12, 'duration' => 12, 'sourceFrame' => null]])
        ->and(array_map(static fn(array $entry): ?string => $entry['motion'] ?? null, $timeline['tracks']))->toBe(['normal', 'normal', 'reduced'])
        ->and(file_get_contents(heldWispPath($root)))->toContain('// Reduced motion holds the wisp, then clears.', "'sourceFrame' => null], // gone")
        ->and(require dirname(heldWispPath($root)) . '/lantern-wisp.data.php')->not->toHaveKey('reducedMotionSamples');
});

it('previews the held drawings and the reduced-motion tracks in battle through the Engine, for a request that asks for reduced motion', function () {
    $root = reducedMotionProject();
    [$session, $index] = openEffectTrackSession($root, 'held-lance');
    $read = static fn(int $authored, bool $reducedMotion): array => $session->readEffectBattlePreview($index, 0, $reducedMotion, authoredFrame: $authored);

    // With motion: the film and the lance at its own frame; the still drawing made for reduced motion is left out.
    // The lance's and the still drawing's sheets are four 16-pixel cells across.
    expect(readHeldImages($read(1, false)))->toBe([['held-film', 0], ['held-lance', 0]])
        ->and(readHeldImages($read(10, false)))->toBe([['held-film', 0], ['held-lance', 48]])
        // Reduced: each authored frame draws the frame its sample holds (1, then 5), with the reduced track's drawing there.
        ->and(readHeldImages($read(1, true)))->toBe([['held-film', 0], ['held-still', 0]])
        ->and(readHeldImages($read(3, true)))->toBe([['held-film', 0], ['held-still', 0]])
        ->and(readHeldImages($read(6, true)))->toBe([['held-film', 0], ['held-still', 16]])
        // A sample that clears draws none of the effect, at the frame the timeline is at.
        ->and(readHeldImages($read(9, true)))->toBe([])
        ->and($read(9, true)['authoredFrames']['target'])->toBe(9)
        ->and($read(9, true)['frame'])->toBe($read(9, false)['frame'])
        ->and($read(1, true)['diagnostics'])->toBe([]);

    // A sample edited in the session is what the next frame holds.
    [, $apply] = readReducedMotionRows($session, 'cutscenes/effect', $index, [CutsceneSchemas::REDUCED_MOTION_SAMPLES_KEY]);
    $apply('reducedSample2SourceFrame', '8');
    expect(readHeldImages($read(9, true)))->toBe([['held-film', 0], ['held-still', 32]]);
});

it('previews a summon\'s held drawing in battle through the Engine, for a request that asks for reduced motion', function () {
    $session = EditorSession::open(reducedMotionProject());
    $read = static fn(int $authored, bool $reducedMotion): array => readHeldImages(
        $session->readSummonBattlePreview(0, 0, $reducedMotion, authoredFrame: $authored), 'Summons/');

    // The wisp's sheet is two 8-pixel cells across.
    expect($read(2, false))->toBe([['Wisp', 0]])
        ->and($read(14, false))->toBe([['Wisp', 8]])
        ->and($read(2, true))->toBe([['Still', 0]])
        // The second sample clears.
        ->and($read(14, true))->toBe([]);
});

it('plays the terminal editor\'s timeline preview with motion: a track drawn for reduced motion alone is left out', function () {
    $root = reducedMotionProject();
    $asset = libraryOf(openEffectEditor($root, 'held-lance'))->find(CutsceneType::EFFECT, 'held-lance');
    $graphical = new TimelinePreviewSession($asset->compileEffect(EffectPresentation::GRAPHICAL, true), null);
    $stage = new EffectPreviewStage(EffectPresentation::GRAPHICAL, true);
    $drawn = static fn(int $frame): array => array_map(static fn(array $segment): string => $segment['drawCommands'][0]['trackId'], $graphical->activeSegments($frame));

    expect($drawn(1))->toEqualCanonicalizing(['film', 'lance', 'spark', 'stroke'])
        ->and($drawn(9))->toEqualCanonicalizing(['film', 'lance'])
        ->and($stage->describeUndrawn($graphical->activeSegments(9)))->toBe(['held-film.png frame 0 on target', 'held-lance.png frame 3 on target']);

    // The terminal sequence never receives a motion-specific track.
    $terminal = new TimelinePreviewSession($asset->compileEffect(EffectPresentation::TERMINAL, true), null);
    expect(array_map(static fn(array $segment): string => $segment['drawCommands'][0]['trackId'], $terminal->activeSegments(1)))->toBe(['spark', 'stroke']);
});

it('draws a declared stage\'s canvas with motion: the art made for reduced motion alone is left off it', function () {
    $root = makeTemporaryProject('reduced-motion-stage-');
    $art = static fn(string $id, string $motion, string $asset): array => ['id' => $id, 'type' => 'image', 'anchor' => 'stage', 'motion' => $motion,
        'asset' => $asset, 'placement' => ['subject' => 'visitor'], 'keyframes' => [['frame' => 1, 'duration' => 5, 'sourceFrame' => 0]]];
    mkdir($root . '/assets/Animations/quiet-hold', 0o777, true);
    file_put_contents(effectTimelinePath($root, 'quiet-hold'), '<?php return ' . var_export([
        'fps' => 8, 'lengthFrames' => 8, 'restFrame' => 3,
        'reducedMotionSamples' => [['frame' => 0, 'duration' => 6, 'sourceFrame' => 3], ['frame' => 6, 'duration' => 2, 'sourceFrame' => null]],
        'stage' => [
            'canvas' => ['width' => 80, 'height' => 40], 'startFrame' => 1, 'restoreFrame' => 6,
            'subjects' => [['id' => 'visitor', 'position' => ['x' => 40, 'y' => 30], 'size' => ['width' => 16, 'height' => 16]]],
            'camera' => [['id' => 'wide', 'frame' => 0, 'focus' => ['x' => 40, 'y' => 20], 'zoom' => 1]],
            'covers' => [['id' => 'clear', 'frame' => 0, 'color' => 'black', 'opacity' => 0], ['id' => 'exit', 'frame' => 7, 'color' => 'black', 'opacity' => 0]],
        ],
        'tracks' => [$art('portrait', 'normal', 'Graphics/Effects/visitor.png'), $art('still', 'reduced', 'Graphics/Effects/visitor-still.png')],
        'cues' => [],
    ], true) . ';');
    writeTilesetTestPng($root . '/assets/Graphics/Effects/visitor.png', 8, 8);
    writeTilesetTestPng($root . '/assets/Graphics/Effects/visitor-still.png', 8, 8);
    $session = EditorSession::open($root);
    $preview = $session->readCutscenePreview('cutscenes/effect', 0, 3, 24, 3);
    $asset = libraryOf(openEffectEditor($root, 'quiet-hold'))->find(CutsceneType::EFFECT, 'quiet-hold');
    $compiled = $asset->compileEffect(EffectPresentation::GRAPHICAL, false, forStage: true);
    $stage = new EffectPreviewStage(EffectPresentation::GRAPHICAL, false, forStage: true);
    $assets = static fn(?array $canvas): array => array_column($canvas['images'] ?? [], 'asset');

    expect($preview['stageCanvasError'])->toBeNull()
        ->and($assets($preview['stageCanvas']))->toBe(['Graphics/Effects/visitor.png'])
        // The stage asked for with reduced motion draws the other track's art instead.
        ->and($assets($stage->renderCanvas($compiled, $root . '/assets', 3, 80, 40, reducedMotion: true)?->toArray()))->toBe(['Graphics/Effects/visitor-still.png'])
        ->and(array_column(CutsceneOutline::of(CutsceneType::EFFECT, $asset->payload())->rows(), 'text'))
        ->toContain('Reduced motion (2 samples)', 'f0 +6  holds frame 3', 'f6 +2  clears the picture');
});
