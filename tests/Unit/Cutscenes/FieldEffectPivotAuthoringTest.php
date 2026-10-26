<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordFieldCodec;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;

/** A replaceable synthetic image and independent terminal sequence, with source worth preserving. */
function makeFieldPivotProject(): array
{
    $root = effectProject();
    $file = $root . '/assets/Animations/field-pivot/field-pivot.timeline.php';
    mkdir(dirname($file), 0777, true);
    file_put_contents($file, <<<'PHP'
<?php
// Keep the terminal presentation and this authored source.
$glyph = <<<'GLYPH'
*
GLYPH;
return ['presentations' => [
    'terminal' => ['fps' => 4, 'lengthFrames' => 2, 'tracks' => [
        ['id' => 'spark', 'type' => 'glyph', 'keyframes' => [
            ['frame' => 0, 'duration' => 2, 'content' => $glyph],
        ]],
    ]],
    'graphical' => ['fps' => 4, 'lengthFrames' => 2, 'tracks' => [
        ['id' => 'spark', 'type' => 'image', 'asset' => 'Graphics/Effects/dusk-slash.png',
            'sheet' => ['columns' => 2, 'rows' => 1],
            'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]],
        ],
    ]],
]];
PHP);

    return [$root, $file];
}

function openFieldPivotSession(string $root): array
{
    $session = EditorSession::open($root);
    $index = array_search('field-pivot', $session->listDatabaseRecords('cutscenes/effect')['records'], true);
    $session->selectCutscenePresentation('cutscenes/effect', $index, 'graphical');

    return [$session, $index];
}

it('sends consumer defaults through the actual timeline request without authoring them', function () {
    [$root, $file] = makeFieldPivotProject();
    $source = file_get_contents($file);
    $input = fopen('php://memory', 'r');
    $output = fopen('php://memory', 'w+');
    $diagnostics = fopen('php://memory', 'w+');
    $host = new SessionHost($input, $output, $diagnostics);
    $request = static fn(string $method, array $params): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    try {
        expect($request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');
        $category = 'cutscenes/effect';
        $index = array_search('field-pivot', $request('database.records', ['category' => $category])['result']['records'], true);
        expect($request('cutscenes.presentation', ['category' => $category, 'index' => $index, 'presentation' => 'graphical']))->toHaveKey('result');
        $art = $request('cutscenes.timeline', ['category' => $category, 'index' => $index])['result']['tracks'][0]['art'];
        expect($art['pivot'])->toBe('')
            ->and($art['pivotKey']['field'])->toBe('track0Pivot')
            ->and($art['pivotDefaults'])->toBe(['field' => ['x' => 0.5, 'y' => 1.0], 'battle' => ['x' => 0.5, 'y' => 0.5]])
            ->and(file_get_contents($file))->toBe($source);
    } finally {
        $host->run();
        fclose($input);
        fclose($output);
        fclose($diagnostics);
    }
});

it('describes consumer-owned pivot defaults without changing the field shape or summon defaults', function () {
    $effect = CutsceneSchemas::effectTrackList()->fieldsFor(['type' => 'image']);
    $pivot = array_find($effect, static fn(RecordField $field): bool => $field->key === 'pivot');
    $attachment = array_find($effect, static fn(RecordField $field): bool => $field->key === 'attachment');
    $summon = array_find(CutsceneSchemas::imageTrackFields(), static fn(RecordField $field): bool => $field->key === 'pivot');

    expect($pivot->codec)->toBe(RecordFieldCodec::NORMALIZED_POINT)
        ->and($pivot->removeWhenEmpty)->toBeTrue()
        ->and($pivot->label)->toContain('field 0.5, 1', 'battle 0.5, 0.5')
        ->and($attachment->label)->toContain('battle only')
        ->and($summon->displayDefault)->toBe('0.5, 0.5')
        ->and(array_column(CutsceneSchemas::effectTrackList()->fieldsFor(['type' => 'glyph']), 'key'))->not->toContain('pivot');
});

it('uses the existing GUI pivot key for source-preserving field edits, undo, clear and reopen', function () {
    [$root, $file] = makeFieldPivotProject();
    $source = file_get_contents($file);
    $original = require $file;
    [$session, $index] = openFieldPivotSession($root);
    $art = static fn(): array => $session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art'];
    $key = $art()['pivotKey'];

    expect($art()['pivot'])->toBe('')
        ->and($art()['pivotDefaults'])->toBe(['field' => ['x' => 0.5, 'y' => 1.0], 'battle' => ['x' => 0.5, 'y' => 0.5]])
        ->and($art())->toMatchArray(['asset' => 'Graphics/Effects/dusk-slash.png', 'columns' => 2, 'rows' => 1]);
    $session->saveDatabase('cutscenes/effect');
    expect(file_get_contents($file))->toBe($source);
    $session->applyDatabaseRecord('cutscenes/effect', $index, $key, '0.25, 1');
    expect($art()['pivot'])->toBe('0.25, 1')->and(file_get_contents($file))->toBe($source);
    expect($art()['pivotDefaults']['field'])->toBe(['x' => 0.5, 'y' => 1.0]);
    $session->undo();
    expect($art()['pivot'])->toBe('')->and($session->listUnsavedChanges())->toBe([]);
    $session->redo();
    $session->saveDatabase('cutscenes/effect');
    $saved = require $file;
    expect($saved['presentations']['graphical']['tracks'][0]['pivot'])->toBe(['x' => 0.25, 'y' => 1.0])
        ->and($saved['presentations']['graphical']['tracks'][0])->not->toHaveKey('attachment')
        ->and($saved['presentations']['terminal'])->toBe($original['presentations']['terminal'])
        ->and(file_get_contents($file))->toContain('// Keep the terminal presentation', '$glyph =', "'content' => $" . 'glyph');
    [$reopened, $reopenedIndex] = openFieldPivotSession($root);
    expect($reopened->describeCutsceneTimeline('cutscenes/effect', $reopenedIndex)['tracks'][0]['art']['pivot'])->toBe('0.25, 1');

    $session->applyDatabaseRecord('cutscenes/effect', $index, $key, '');
    expect($art()['pivot'])->toBe('')->and($art()['pivotDefaults']['battle'])->toBe(['x' => 0.5, 'y' => 0.5]);
    $session->saveDatabase('cutscenes/effect');
    expect(require $file)->toBe($original);
    $session->undo();
    expect($art()['pivot'])->toBe('0.25, 1');
    $session->selectCutscenePresentation('cutscenes/effect', $index, 'terminal');
    expect($session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0])->not->toHaveKey('art');
});

it('refuses malformed, nonfinite and out-of-range pivot edits without mutation or history', function () {
    [$root, $file] = makeFieldPivotProject();
    [$session, $index] = openFieldPivotSession($root);
    $source = file_get_contents($file);
    $key = $session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art']['pivotKey'];
    $session->applyDatabaseRecord('cutscenes/effect', $index, $key, '0, 1');
    foreach (['-0.01, 1', '0.5, 1.01', '0.5', '0, 1, 0', 'left, top', 'NaN, 0', '0, INF', '1e999, 0'] as $value) {
        expect(fn() => $session->applyDatabaseRecord('cutscenes/effect', $index, $key, $value))
            ->toThrow(SessionRefusal::class, 'two numbers from 0 to 1');
    }
    expect($session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art']['pivot'])->toBe('0, 1')
        ->and(file_get_contents($file))->toBe($source);
    $session->undo();
    expect($session->listUnsavedChanges())->toBe([]);
});

it('refuses an expression-backed pivot before writing or recording an undo step', function () {
    [$root, $file] = makeFieldPivotProject();
    file_put_contents($file, str_replace("'sheet' =>", "'pivot' => ['x' => 1 / 2, 'y' => 1], 'sheet' =>", file_get_contents($file)));
    $source = file_get_contents($file);
    [$session, $index] = openFieldPivotSession($root);
    $key = $session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art']['pivotKey'];

    expect(fn() => $session->applyDatabaseRecord('cutscenes/effect', $index, $key, '0.25, 1'))
        ->toThrow(SessionRefusal::class, 'cannot be rewritten safely')
        ->and($session->listUnsavedChanges())->toBe([])
        ->and(file_get_contents($file))->toBe($source)
        ->and($session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art']['pivot'])->toBe('0.5, 1');
});

it('keeps graphical pivots while terminal authoring edits only its selected sequence', function () {
    [$root, $file] = makeFieldPivotProject();
    [$session, $index] = openFieldPivotSession($root);
    $key = $session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art']['pivotKey'];
    $session->applyDatabaseRecord('cutscenes/effect', $index, $key, '0.5, 1');
    $session->saveDatabase('cutscenes/effect');
    $graphical = (require $file)['presentations']['graphical'];

    $editor = cutscenesEditor($root, 160, 50);
    callEditorMethod($editor, 'switchCutsceneType', CutsceneType::EFFECT);
    callEditorMethod($editor, 'selectCutsceneById', 'field-pivot');
    setCutsceneField($editor, 'fps', '8');
    $asset = libraryOf($editor)->find(CutsceneType::EFFECT, 'field-pivot');
    $asset->save();
    expect((require $file)['presentations']['graphical'])->toBe($graphical)
        ->and((require $file)['presentations']['terminal']['fps'])->toBe(8);
});

it('passes explicit and omitted pivots to the shared compiler without inventing field battler attachments', function () {
    [$root, $file] = makeFieldPivotProject();
    $data = require $file;
    $library = new EffectTimelineLibrary($root . '/assets');
    foreach ([false, true] as $battle) {
        $compiled = $library->compile('field-pivot', $data, $battle, EffectPresentation::GRAPHICAL);
        expect($compiled->playbackSegments[0]['drawCommands'][0]['payload'])->not->toHaveKey('pivot');
    }
    $data['presentations']['graphical']['tracks'][0]['pivot'] = ['x' => 0.25, 'y' => 1];
    $compiled = $library->compile('field-pivot', $data, false, EffectPresentation::GRAPHICAL);
    expect($compiled->playbackSegments[0]['drawCommands'][0]['payload']['pivot'])->toBe(['x' => 0.25, 'y' => 1]);
    $data['presentations']['graphical']['tracks'][0]['attachment'] = 'ground';
    expect(fn() => $library->compile('field-pivot', $data, false, EffectPresentation::GRAPHICAL))->toThrow(InvalidArgumentException::class);
});
