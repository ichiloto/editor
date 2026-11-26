<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\Presentation\TerminalBattleContacts;

function terminalContactProject(): array
{
    $root = cutsceneProject();
    $path = effectTimelinePath($root, 'contact-demo');
    mkdir(dirname($path), 0700, true);
    $source = <<<'SOURCE'
<?php
return [
  'presentations' => [
    'terminal' => [
      'fps' => 24, 'lengthFrames' => 12, 'restFrame' => 4, 'targetCaptureFrame' => 1,
      'terminalTargetContacts' => ['intervalFrames' => 2, 'tracks' => ['burst']],
      'tracks' => [
        ['id' => 'trail', 'type' => 'glyph-lane', 'facing' => 'west',
         'keyframes' => [['frame' => 2, 'duration' => 2, 'payload' => ['head' => [' <'], 'shaft' => [' -']]]]],
        ['id' => 'burst', 'type' => 'glyph', 'presentation' => 'terminal', 'anchor' => 'target',
         'keyframes' => [['frame' => 4, 'duration' => 2, 'content' => '^']]],
        ['id' => 'banner', 'type' => 'glyph', 'presentation' => 'terminal', 'anchor' => 'screen',
         'keyframes' => [['frame' => 0, 'duration' => 6, 'content' => 'DEMO']]],
        ['id' => 'shared', 'type' => 'glyph',
         'keyframes' => [['frame' => 1, 'duration' => 2, 'content' => 'O']]],
      ],
    ],
    'graphical' => [
      // Independent graphical source.
      'fps' => 30, 'lengthFrames' => 12,
      'tracks' => [['id' => 'caption', 'type' => 'text', 'presentation' => 'graphical',
                   'keyframes' => [['frame' => 2, 'duration' => 2, 'content' => 'Demo']]]],
    ],
  ],
];
SOURCE;
    file_put_contents($path, $source);

    return [$root, $path, $source];
}

it('chooses Terminal contact tracks from the current effect and saves only the edited Terminal values', function () {
    [$root, $path, $source] = terminalContactProject();
    $editor = openEffectEditor($root, 'contact-demo');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'contact-demo');
    $trackField = TerminalBattleContacts::FIELD . '.tracks';
    $intervalField = TerminalBattleContacts::FIELD . '.intervalFrames';
    expect(cutsceneField($editor, $trackField))->toMatchArray([
        'reference' => ReferenceCatalog::EFFECT_TERMINAL_CONTACT_TRACKS, 'multi' => true,
    ])->not->toHaveKey('control');
    selectCutsceneField($editor, $trackField);
    pressKeys($editor, "\n");
    $picker = getEditorProperty($editor, 'referencePicker');
    expect($picker->matches())->toBe(['trail', 'burst']);
    // The first row is the currently unselected lane.
    if ($picker->selected() !== 'trail') { pressKeys($editor, "\033[A"); }
    pressKeys($editor, "\n");
    expect($asset->payload()[TerminalBattleContacts::FIELD]['tracks'])->toBe(['burst', 'trail']);

    setCutsceneField($editor, $intervalField, '3');
    setCutsceneField($editor, 'targetCaptureFrame', '0');
    openCutsceneFrame($editor, 'commandListTracks');
    setCutsceneField($editor, 'track0Keyframe0Payloadhead', ' < ');
    setCutsceneField($editor, 'track0Keyframe0Payloadshaft', ' - ');
    expect($asset->save())->toBeTrue();
    $saved = file_get_contents($path);
    expect(explode("'graphical' =>", $saved, 2)[1])->toBe(explode("'graphical' =>", $source, 2)[1])
        ->and($asset->compileEffect(EffectPresentation::TERMINAL, true)->defaults[TerminalBattleContacts::FIELD])
        ->toBe(['intervalFrames' => 3, 'tracks' => ['burst', 'trail'], 'captureFrame' => 0])
        ->and($asset->partner()['presentations']['terminal']['tracks'][0]['keyframes'][0]['payload'])
        ->toBe(['head' => [' < '], 'shaft' => [' - ']]);

    $reopened = openEffectEditor($root, 'contact-demo');
    expect(cutsceneField($reopened, $intervalField)['value'])->toBe('3');
    setCutsceneField($reopened, $intervalField, '');
    $reopenedAsset = libraryOf($reopened)->find(CutsceneType::EFFECT, 'contact-demo');
    expect($reopenedAsset->payload())->not->toHaveKey(TerminalBattleContacts::FIELD);
    callEditorMethod($reopened, 'performUndo');
    expect($reopenedAsset->payload()[TerminalBattleContacts::FIELD]['intervalFrames'])->toBe(3)
        ->and($reopenedAsset->save())->toBeFalse();
});

it('keeps the graphical controls independent and preserves the Terminal source through a graphical save', function () {
    [$root, $path, $source] = terminalContactProject();
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'contact-demo');
    $category = 'cutscenes/effect';
    $session->selectCutscenePresentation($category, $index, EffectPresentation::GRAPHICAL->value);
    $fields = array_column(array_column($session->readDatabaseRecord($category, $index)['rows'], 'key'), 'field');
    expect($fields)->not->toContain(TerminalBattleContacts::FIELD . '.tracks', TerminalBattleContacts::FIELD . '.intervalFrames')
        ->and($session->listReferences(null, ReferenceCatalog::EFFECT_TERMINAL_CONTACT_TRACKS, ['category' => $category, 'index' => $index]))
        ->toBe([]);
    $fps = array_find($session->readDatabaseRecord($category, $index)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'fps');
    expect($session->applyDatabaseRecord($category, $index, $fps['key'], '27')['changed'])->toBeTrue()
        ->and($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($path))->toBe(str_replace("'fps' => 30", "'fps' => 27", $source));
});

it('creates a Terminal glyph lane with native stencil controls and a specific renderer', function () {
    [$root] = terminalContactProject();
    $editor = openEffectEditor($root, 'contact-demo');
    openCutsceneFrame($editor, 'commandListTracks');
    addCutsceneAfter($editor, 'track3Id');
    setCutsceneField($editor, 'track4Id', 'new-lane');
    setCutsceneField($editor, 'track4Type', 'glyph-lane');
    setCutsceneField($editor, 'track4Facing', 'west');
    expect(cutsceneField($editor, 'track4Presentation'))->toMatchArray(['value' => 'terminal', 'editable' => false]);
    addCutsceneAfter($editor, 'track4Id');
    setCutsceneField($editor, 'track4Keyframe0Frame', '2');
    setCutsceneField($editor, 'track4Keyframe0Payloadhead', '»');
    setCutsceneField($editor, 'track4Keyframe0Payloadshaft', '─');
    setCutsceneField($editor, 'track4Keyframe0Payloadreach', '0.5');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'contact-demo');

    expect($asset->save())->toBeTrue();
    $reopened = libraryOf(openEffectEditor($root, 'contact-demo'))->find(CutsceneType::EFFECT, 'contact-demo');
    expect($reopened->payload()['tracks'][4])
        ->toBe(['id' => 'new-lane', 'type' => 'glyph-lane', 'facing' => 'west',
            'keyframes' => [['frame' => 2, 'duration' => 1, 'payload' => ['head' => ['»'], 'shaft' => ['─'], 'reach' => 0.5]]]]);
});
