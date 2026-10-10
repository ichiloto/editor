<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\StagedActorFields;
use Ichiloto\Editor\Field\CharacterSheetPreview;
use Ichiloto\Editor\Session\EditorSession;

/** Each descriptor route receives the actual current entry, including nested command envelopes. */
function createStagedSheetPreviewProject(string $owner, int $width = 48, int $height = 32): array
{
    $root = cutsceneProject();
    writeTilesetTestPng($root . '/assets/Graphics/Characters/People.png', $width, $height);
    $sprites = ['sheet' => 'Graphics/Characters/People.png', 'index' => 5, 'layer' => 120];
    $actor = ['id' => 'walker', 'sprite' => ['@'], 'x' => 1, 'y' => 2, 'sprites2d' => $sprites];
    $wrapped = $owner === 'envelope';
    $stage = $wrapped ? ['type' => 'stage_actor', 'actor' => $actor] : ['type' => 'stage_actor', ...$actor];
    $command = ['type' => 'sequence', 'commands' => [$stage]];
    $metadata = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    $data = require $metadata;
    $data['cast'] = [['kind' => 'staged_actor', ...$actor]];
    $data['skip'] = ['policy' => 'forbidden'];
    file_put_contents($metadata, "<?php\n// Current synthetic cast owner.\nreturn " . var_export($data, true) . ";\n");
    $script = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($script, "<?php\n// Current nested command owner.\nreturn " . var_export([$command], true) . ";\n");
    $frame = $owner === 'cast' ? [] : ['commands', 0, 'commands'];
    $stem = $owner === 'cast' ? 'cast0Sprites2d' : ($wrapped ? 'command0Actorsprites2d' : 'command0Sprites2d');

    return [$root, EditorSession::open($root), $frame, $stem, $sprites];
}

function readStagedSheetPreviewRow(EditorSession $session, array $frame, string $field): array
{
    $view = $session->readDatabaseRecord('cutscenes/cinematic', 0, $frame);

    return array_find($view['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException('Missing staged sheet preview row ' . $field);
}

it('shares standing preview crops with staged cast nested commands and actor envelopes', function (string $owner) {
    [$root, $session, $frame, $stem, $sprites] = createStagedSheetPreviewProject($owner);
    $row = readStagedSheetPreviewRow($session, $frame, $stem . 'sheet');
    expect($row)->toMatchArray(['kind' => 'reference', 'reference' => 'png_assets',
        'value' => $sprites['sheet'], 'media' => ['kind' => 'image', 'root' => 'assets']])
        ->and($row['imagePreview'])->toBe(CharacterSheetPreview::describe($sprites, $root . '/assets'))
        ->and($row['imagePreview']['frames'][0]['sourceRect'])->toBe(['x' => 16, 'y' => 16, 'width' => 4, 'height' => 4]);
    $index = readStagedSheetPreviewRow($session, $frame, $stem . 'index');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $index['key'], '7');
    expect(readStagedSheetPreviewRow($session, $frame, $stem . 'sheet')['imagePreview']['frames'][0]['sourceRect']['x'])->toBe(40);
    $session->undo();
    expect(readStagedSheetPreviewRow($session, $frame, $stem . 'sheet')['imagePreview'])->toBe($row['imagePreview']);
    $session->redo();
    $session->saveDatabase('cutscenes/cinematic');
    $reopened = EditorSession::open($root);
    expect(readStagedSheetPreviewRow($reopened, $frame, $stem . 'index')['value'])->toBe('7')
        ->and(readStagedSheetPreviewRow($reopened, $frame, $stem . 'sheet')['imagePreview'])
        ->toBe(readStagedSheetPreviewRow($session, $frame, $stem . 'sheet')['imagePreview']);
})->with(['cast', 'inline', 'envelope']);

it('refreshes staged preview dimensions from the correct current workspace and reports file issues', function (string $owner) {
    [$root, $session, $frame, $stem] = createStagedSheetPreviewProject($owner);
    [$otherRoot, $other, $otherFrame, $otherStem] = createStagedSheetPreviewProject($owner, 96, 64);
    $png = $root . '/assets/Graphics/Characters/People.png';
    expect(readStagedSheetPreviewRow($session, $frame, $stem . 'sheet')['imagePreview']['frames'][0]['sourceRect']['width'])->toBe(4)
        ->and(readStagedSheetPreviewRow($other, $otherFrame, $otherStem . 'sheet')['imagePreview']['frames'][0]['sourceRect']['width'])->toBe(8);
    writeTilesetTestPng($png, 144, 96);
    expect(readStagedSheetPreviewRow($session, $frame, $stem . 'sheet')['imagePreview']['frames'][0]['sourceRect']['width'])->toBe(12);
    file_put_contents($png, 'malformed replacement');
    $files = sourceHashTree($root);
    $preview = readStagedSheetPreviewRow($session, $frame, $stem . 'sheet')['imagePreview'];
    expect($preview['frames'])->toBe([])->and($preview['issue'])->not->toBeEmpty()
        ->and($session->listUnsavedChanges())->toBe([])->and(sourceHashTree($root))->toBe($files)
        ->and(readStagedSheetPreviewRow($other, $otherFrame, $otherStem . 'sheet')['imagePreview']['frames'][0]['sourceRect']['width'])->toBe(8);
})->with(['cast', 'envelope']);

it('reports an absent explicit staged asset root instead of inventing one from an owner path', function () {
    $entry = ['type' => 'stage_actor', 'actor' => ['sprites2d' => ['sheet' => 'People.png']]];
    $fields = [['sourceKey' => 'actor.sprites2d.sheet', 'value' => 'People.png'],
        ['sourceKey' => 'actor.sprites2d.asset', 'value' => '']];
    $described = StagedActorFields::describeFields($entry, $fields, null);
    expect($described[0]['imagePreview']['frames'])->toBe([])
        ->and($described[0]['imagePreview']['issue'])->toContain('no project asset root')
        ->and($described[1])->not->toHaveKey('imagePreview');
});
