<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;

function makeStagedAuthoringProject(): string
{
    $root = cutsceneProject();
    mkdir($root . '/assets/Graphics/Poses', 0777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Poses/Actor.png', 32, 16);
    writeTilesetTestPng($root . '/assets/Graphics/Poses/$Walker.png', 24, 32);
    file_put_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php', <<<'PHP'
<?php
// Keep this authored comment and terminal art.
$terminal = <<<'ART'
 @
/|\
ART;
return [
    'id' => 'harbour-lanterns', 'name' => 'Pose fixture', 'startMap' => 'harbour',
    'cast' => [
        ['kind' => 'staged_actor', 'id' => 'actor', 'sprite' => [$terminal], 'x' => 1, 'y' => 2],
    ],
    'skip' => ['policy' => 'forbidden'],
];
PHP);
    file_put_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php', <<<'PHP'
<?php
// Nested authored commands must keep their structure.
return [
    ['type' => 'sequence', 'commands' => [
        ['type' => 'stage_actor', 'id' => 'actor', 'sprite' => ['@'], 'x' => 1, 'y' => 2],
        ['type' => 'wait', 'seconds' => 0.5],
    ]],
];
PHP);

    return $root;
}

function getStagedAuthoringRow(EditorSession $session, string $field, array $frame = []): array
{
    return array_find($session->readDatabaseRecord('cutscenes/cinematic', 0, $frame)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? '') === $field)
        ?? throw new RuntimeException('Missing staged authoring row ' . $field);
}

function setStagedAuthoringRow(EditorSession $session, string $field, string $value, array $frame = []): array
{
    return $session->applyDatabaseRecord('cutscenes/cinematic', 0, getStagedAuthoringRow($session, $field, $frame)['key'], $value);
}

it('offers graphical controls only to GUI records with engine-owned animation fields and existing pickers', function () {
    $root = makeStagedAuthoringProject();
    $session = EditorSession::open($root);
    $image = getStagedAuthoringRow($session, 'cast0Sprites2dasset');
    expect($image)->toMatchArray(['kind' => 'reference', 'reference' => 'png_assets', 'media' => ['kind' => 'image', 'root' => 'assets']])
        ->and(array_column($session->listReferences(null, 'png_assets'), 'value'))->toContain('Graphics/Poses/Actor.png');
    setStagedAuthoringRow($session, 'cast0Sprites2dasset', 'Graphics/Poses/Actor.png');
    $graphical = CutsceneSchemas::stagedActorFields(true, ['sprites2d' => ['asset' => 'Graphics/Poses/Actor.png']]);
    $keys = array_map(static fn($field): string => $field->key, $graphical);
    foreach (CinematicCommandSchema::export()['stagedPoseAnimation']['fields'] as $key) {
        expect($keys)->toContain('sprites2d.animation.' . $key);
    }
    $terminal = CutsceneSchemas::stagedActorFields();
    expect(array_any($terminal, static fn($field): bool => str_starts_with($field->key, 'sprites2d') || str_starts_with($field->key, 'subject')))->toBeFalse();
});

it('authors a looping pose and crop through GUI history and saves source-preserving runtime data', function () {
    $root = makeStagedAuthoringProject();
    $session = EditorSession::open($root);
    $file = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    $before = file_get_contents($file);
    foreach ([
        'asset' => 'Graphics/Poses/Actor.png', 'cells' => '2, 1', 'sourceRect' => '0, 0, 32, 16',
        'animation.columns' => '2', 'animation.frames' => '0, 1, 1, 0', 'animation.fps' => '4',
        'animation.loop' => 'false', 'animation.restFrame' => '1',
    ] as $key => $value) {
        expect(setStagedAuthoringRow($session, 'cast0Sprites2d' . str_replace('.', '', $key), $value)['changed'])->toBeTrue();
    }
    $session->undo();
    expect(getStagedAuthoringRow($session, 'cast0Sprites2danimationrestFrame')['value'])->toBe('0');
    $session->redo();
    expect(file_get_contents($file))->toBe($before);
    $session->saveDatabase('cutscenes/cinematic');
    $data = require $file;
    $pose = CinematicStageManager::getGraphicalSprites($data['cast'][0]['sprites2d']);
    expect($pose)->toBeInstanceOf(FieldPoseAnimation::class)
        ->and($pose->frames)->toBe([0, 1, 1, 0])->and($pose->loop)->toBeFalse()
        ->and($pose->fps)->toBe(4)->and($pose->restFrame)->toBe(1)
        ->and($pose->getFrame($root . '/assets', 0.25)->sourceRect->x)->toBe(16)
        ->and(file_get_contents($file))->toContain('// Keep this authored comment and terminal art.', '$terminal =', "'sprite' => [$" . 'terminal]');
    $reopened = EditorSession::open($root);
    expect(getStagedAuthoringRow($reopened, 'cast0Sprites2danimationloop')['value'])->toBe('false');
});

it('switches image forms and clears pose animation as atomic undoable edits without changing terminal sprites', function () {
    $root = makeStagedAuthoringProject();
    $session = EditorSession::open($root);
    setStagedAuthoringRow($session, 'cast0Sprites2dasset', 'Graphics/Poses/Actor.png');
    setStagedAuthoringRow($session, 'cast0Sprites2danimationcolumns', '2');
    setStagedAuthoringRow($session, 'cast0Sprites2danimationframes', '0, 1');
    $sprite = getStagedAuthoringRow($session, 'cast0Sprite');
    setStagedAuthoringRow($session, 'cast0Sprites2dsheet', 'Graphics/Poses/$Walker.png');
    expect(getStagedAuthoringRow($session, 'cast0Sprite'))->toBe($sprite)
        ->and(getStagedAuthoringRow($session, 'cast0Sprites2dindex')['kind'])->toBe('integer');
    $session->undo();
    expect(getStagedAuthoringRow($session, 'cast0Sprites2danimationframes')['value'])->toBe('0, 1');
    setStagedAuthoringRow($session, 'cast0Sprites2danimationframes', '');
    $session->saveDatabase('cutscenes/cinematic');
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    expect($asset->data()['cast'][0]['sprites2d'])->toBe(['asset' => 'Graphics/Poses/Actor.png']);
    setStagedAuthoringRow($session, 'cast0Sprites2dasset', '');
    $session->saveDatabase('cutscenes/cinematic');
    expect(CutsceneLibrary::fromProject($root)->find(CutsceneType::CINEMATIC, 'harbour-lanterns')->data()['cast'][0])->not->toHaveKey('sprites2d');
});

it('edits nested stage commands and bindings through the same record paths', function () {
    $root = makeStagedAuthoringProject();
    $session = EditorSession::open($root);
    $frame = [CutsceneAsset::COMMANDS_KEY, 0, 'commands'];
    setStagedAuthoringRow($session, 'command0Sprites2dasset', 'Graphics/Poses/Actor.png', $frame);
    setStagedAuthoringRow($session, 'command0Sprites2danimationfps', '6', $frame);
    setStagedAuthoringRow($session, 'command0Subjectkind', 'player', $frame);
    setStagedAuthoringRow($session, 'command0Replace', 'true', $frame);
    $row = getStagedAuthoringRow($session, 'command0Id', $frame);
    expect($row['childNoun'])->toBe('suppressed subject');
    $session->addDatabaseItem('cutscenes/cinematic', 0, $row['key'], child: true);
    setStagedAuthoringRow($session, 'command0Suppress0Kind', 'npc', $frame);
    $choices = $session->listReferences(null, 'map_npcs', ['category' => 'cutscenes/cinematic', 'index' => 0]);
    expect(array_column($choices, 'value'))->toContain('keeper');
    setStagedAuthoringRow($session, 'command0Suppress0Id', 'keeper', $frame);
    $session->saveDatabase('cutscenes/cinematic');
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    $actor = $asset->commands()[0]['commands'][0];
    CinematicStageManager::validateBinding($actor);
    expect($actor)->toMatchArray(['subject' => ['kind' => 'player'], 'suppress' => [['kind' => 'npc', 'id' => 'keeper']], 'replace' => true])
        ->not->toHaveKeys(['x', 'y', 'collision', 'facing'])
        ->and($actor['sprites2d']['animation']['fps'])->toBe(6);
    setStagedAuthoringRow($session, 'command0Subjectkind', '', $frame);
    $session->undo();
    expect(getStagedAuthoringRow($session, 'command0Suppress0Id', $frame)['value'])->toBe('keeper');
});

it('authors actor-envelope commands without flattening their source or writing ignored inline fields', function () {
    $root = makeStagedAuthoringProject();
    $file = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($file, <<<'PHP'
<?php
return [
    // This command owns its actor envelope.
    ['type' => 'stage_actor', 'actor' => ['id' => 'actor', 'sprite' => ['@'], 'x' => 1, 'y' => 2]],
];
PHP);
    $session = EditorSession::open($root);
    $frame = [CutsceneAsset::COMMANDS_KEY];
    setStagedAuthoringRow($session, 'command0Actorsprites2dasset', 'Graphics/Poses/Actor.png', $frame);
    setStagedAuthoringRow($session, 'command0Actorsprites2danimationcolumns', '2', $frame);
    setStagedAuthoringRow($session, 'command0Actorsprites2danimationframes', '0, 1', $frame);
    setStagedAuthoringRow($session, 'command0Actorsubjectkind', 'npc', $frame);
    setStagedAuthoringRow($session, 'command0Actorsubjectid', 'keeper', $frame);
    $session->addDatabaseItem('cutscenes/cinematic', 0, getStagedAuthoringRow($session, 'command0Actorid', $frame)['key'], child: true);
    setStagedAuthoringRow($session, 'command0Suppress0Kind', 'npc', $frame);
    setStagedAuthoringRow($session, 'command0Suppress0Id', 'keeper', $frame);
    setStagedAuthoringRow($session, 'command0Suppress0Kind', 'player', $frame);
    $session->saveDatabase('cutscenes/cinematic');
    $commands = require $file;
    expect(array_keys($commands[0]))->not->toContain('sprites2d', 'subject', 'suppress', 'actor.suppress')
        ->and($commands[0]['actor']['suppress'])->toBe([['kind' => 'player']])
        ->and($commands[0]['actor']['subject'])->toBe(['kind' => 'npc', 'id' => 'keeper'])
        ->and(file_get_contents($file))->toContain('// This command owns its actor envelope.');
    $pose = CinematicStageManager::getGraphicalSprites($commands[0]['actor']['sprites2d']);
    expect($pose->loop)->toBeTrue()->and($pose->getFrame($root . '/assets', 0.25)->sourceRect->x)->toBe(0);
    $session->removeDatabaseItem('cutscenes/cinematic', 0, getStagedAuthoringRow($session, 'command0Suppress0Kind', $frame)['key']);
    $session->saveDatabase('cutscenes/cinematic');
    expect((require $file)[0]['actor'])->not->toHaveKey('suppress');
});

it('refuses unsupported source expressions before mutation and still edits independent literal fields', function () {
    $root = makeStagedAuthoringProject();
    $file = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    file_put_contents($file, str_replace("'x' => 1, 'y' => 2", "'x' => 1, 'y' => 2, 'sprites2d' => ['asset' => 'Graphics/Poses/Actor.png', 'animation' => ['frames' => [0], 'fps' => 4 * 2]]", file_get_contents($file)));
    $before = file_get_contents($file);
    $session = EditorSession::open($root);
    expect(fn() => setStagedAuthoringRow($session, 'cast0Sprites2danimationfps', '12'))->toThrow(SessionRefusal::class, 'cannot be rewritten safely')
        ->and(file_get_contents($file))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([])
        ->and(getStagedAuthoringRow($session, 'cast0Sprites2danimationfps')['value'])->toBe('8');
    setStagedAuthoringRow($session, 'cast0Sprites2danimationloop', 'false');
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($file))->toContain("'fps' => 4 * 2", "'loop' => false");
});

it('refuses invalid runtime animation values without changing data or history', function () {
    $session = EditorSession::open(makeStagedAuthoringProject());
    setStagedAuthoringRow($session, 'cast0Sprites2dasset', 'Graphics/Poses/Actor.png');
    foreach (['animation.fps' => '0', 'animation.columns' => '65', 'animation.frames' => '9', 'cells' => '1.5, 1', 'sourceRect' => '0, 0, 0, 4'] as $field => $value) {
        expect(fn() => setStagedAuthoringRow($session, 'cast0Sprites2d' . str_replace('.', '', $field), $value))->toThrow(SessionRefusal::class);
    }
    $session->undo();
    expect($session->listUnsavedChanges())->toBe([]);
});

it('keeps graphical data when the terminal workflow edits a terminal field', function () {
    $root = makeStagedAuthoringProject();
    $session = EditorSession::open($root);
    setStagedAuthoringRow($session, 'cast0Sprites2dasset', 'Graphics/Poses/Actor.png');
    setStagedAuthoringRow($session, 'cast0Sprites2danimationfps', '5');
    $session->saveDatabase('cutscenes/cinematic');
    $editor = cutscenesEditor($root, 160, 50);
    $asset = libraryOf($editor)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    $graphics = $asset->data()['cast'][0]['sprites2d'];
    expect(cutsceneFieldIds($editor))->not->toContain('cast0Sprites2dasset', 'cast0Subjectkind');
    setCutsceneField($editor, 'cast0X', '3');
    $asset->save();
    expect($asset->data()['cast'][0]['sprites2d'])->toBe($graphics);
});

it('binds cast artwork to a selected NPC and preserves visibility and transforms through undo', function () {
    $root = makeStagedAuthoringProject();
    $session = EditorSession::open($root);
    setStagedAuthoringRow($session, 'cast0Subjectkind', 'npc');
    $session->undo();
    expect(getStagedAuthoringRow($session, 'cast0X')['value'])->toBe('1')
        ->and(getStagedAuthoringRow($session, 'cast0Y')['value'])->toBe('2');
    $session->redo();
    $npc = getStagedAuthoringRow($session, 'cast0Subjectid');
    expect($npc['reference'])->toBe('map_npcs');
    setStagedAuthoringRow($session, 'cast0Subjectid', 'keeper');
    setStagedAuthoringRow($session, 'cast0Visible', 'false');
    $row = getStagedAuthoringRow($session, 'cast0Id');
    $session->addDatabaseItem('cutscenes/cinematic', 0, $row['key'], child: true);
    $session->removeDatabaseItem('cutscenes/cinematic', 0, getStagedAuthoringRow($session, 'cast0Suppress0Kind')['key']);
    $session->saveDatabase('cutscenes/cinematic');
    $actor = CutsceneLibrary::fromProject($root)->find(CutsceneType::CINEMATIC, 'harbour-lanterns')->data()['cast'][0];
    CinematicStageManager::validateBinding($actor);
    expect($actor)->toMatchArray(['subject' => ['kind' => 'npc', 'id' => 'keeper'], 'visible' => false])
        ->not->toHaveKeys(['x', 'y', 'facing', 'collision', 'suppress']);
});
