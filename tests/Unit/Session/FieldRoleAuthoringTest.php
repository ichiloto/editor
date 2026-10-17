<?php

declare(strict_types=1);

use Ichiloto\Editor\Actors\ActorAuthoring;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Field\PlayerPresentationFields;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Field\PlayerGraphicalSubject;
use Ichiloto\Engine\Field\PlayerPresentationConfig;

function createFieldRoleProject(): array
{
    $root = makeTemporaryProject('field-role-');
    array_map(unlink(...), glob($root . '/assets/Data/Actors/*.php') ?: []);
    $actor = $root . '/assets/Data/Actors/not-an-identity.php';
    file_put_contents($actor, <<<'PHP'
<?php
use Ichiloto\Engine\Entities\Character;
// Keep comments, expressions and other image roles.
return ['class' => Character::class, 'data' => [
    'id' => 'actor-stable', 'name' => 'Different Display Name', 'level' => 1,
    'future' => abs(7), 'images' => ['face' => 'unrelated-face',
        'field2d' => ['sheet' => 'Graphics/Characters/Cast.png', /* chosen cell */ 'index' => 2, 'layer' => 100]],
]];
PHP);
    $player = $root . '/assets/Data/Entities/player.php';
    file_put_contents($player, <<<'PHP'
<?php
// Terminal art and authored expressions are not graphical ownership.
return ['default' => '@', 'north' => '^', 'south' => 'v', 'east' => '>', 'west' => '<',
    'future' => abs(9),
    'sprites2d' => ['sheet' => 'Graphics/Characters/Cast.png', /* fixed cell */ 'index' => 3, 'layer' => 50]];
PHP);
    writeTilesetTestPng($root . '/assets/Graphics/Characters/Cast.png', 48, 32);
    writeTilesetTestPng($root . '/assets/Graphics/Characters/$Solo.png', 12, 16);
    writeTilesetTestPng($root . '/assets/Graphics/Characters/Uneven.png', 50, 50);

    return [$root, $actor, $player, EditorSession::open($root)];
}

function getFieldRoleRow(EditorSession $session, string $category, string $field): ?array
{
    return array_find($session->readDatabaseRecord($category, 0)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
}

function applyFieldRole(EditorSession $session, string $category, string $field, string $value): array
{
    $row = getFieldRoleRow($session, $category, $field) ?? throw new RuntimeException('Missing row ' . $field);

    return $session->applyDatabaseRecord($category, 0, $row['key'], $value);
}

it('exposes graphical ownership and actor-role pickers only through the graphical session', function () {
    [$root, , , $session] = createFieldRoleProject();
    $terminal = ProjectWorkspace::fromProject($root);
    $actor = $terminal->actorDatabase->getActorByIndex(0);
    expect(array_column(DatabaseCatalog::all(), 'key'))->not->toContain(PlayerPresentationFields::CATEGORY)
        ->and($terminal->getRecordDatabase(PlayerPresentationFields::CATEGORY))->toBeNull()
        ->and(json_encode((new ActorAuthoring())->describeFields($terminal, $actor)))->not->toContain('field2d', 'imagePreview')
        ->and(array_column($session->describeProject()['databases'], 'key'))->toContain(PlayerPresentationFields::CATEGORY);
    foreach (['actors' => 'images.field2d.sheet', PlayerPresentationFields::CATEGORY => 'sprites2d.sheet'] as $category => $field) {
        $row = getFieldRoleRow($session, $category, $field);
        expect($row)->toMatchArray(['kind' => 'reference', 'reference' => 'png_assets', 'media' => ['kind' => 'image', 'root' => 'assets']])
            ->and(array_column($row['imagePreview']['frames'], 'label'))->toBe(['South', 'West', 'East', 'North']);
    }
});

it('preserves legacy fixed art and terminal data across explicit mode changes save reopen undo and redo', function () {
    [$root, , $path, $session] = createFieldRoleProject();
    $source = file_get_contents($path);
    $before = require $path;
    $category = PlayerPresentationFields::CATEGORY;
    expect(getFieldRoleRow($session, $category, 'graphicalSubject')['value'])->toBe(PlayerPresentationFields::LEGACY)
        ->and(PlayerPresentationConfig::fromArray($before)->graphicalSubject)->toBe(PlayerGraphicalSubject::FIXED_PLAYER);
    $sheetKey = getFieldRoleRow($session, $category, 'sprites2d.sheet')['key'];
    applyFieldRole($session, $category, 'graphicalSubject', 'party-leader');
    expect(getFieldRoleRow($session, $category, 'sprites2d.sheet')['kind'])->toBe('info')
        ->and(fn() => $session->applyDatabaseRecord($category, 0, $sheetKey, ''))->toThrow(SessionRefusal::class)
        ->and($session->listDatabaseRecords($category)['unsavedCategories'])->toContain($category);
    $session->saveDatabase($category);
    $after = require $path;
    expect($after['sprites2d'])->toBe($before['sprites2d'])
        ->and(PlayerPresentationConfig::fromArray($after)->graphicalSubject)->toBe(PlayerGraphicalSubject::PARTY_LEADER)
        ->and(PlayerPresentationConfig::fromArray($after)->graphical)->toBeNull();
    unset($after['graphicalSubject']);
    expect($after)->toBe($before)->and(file_get_contents($path))->toContain('// Terminal art', "'future' => abs(9)");
    $session = EditorSession::open($root);
    applyFieldRole($session, $category, 'graphicalSubject', 'fixed-player');
    expect(getFieldRoleRow($session, $category, 'sprites2d.index')['value'])->toBe('3');
    $session->undo();
    expect(getFieldRoleRow($session, $category, 'sprites2d.sheet')['kind'])->toBe('info');
    $session->redo();
    applyFieldRole($session, $category, 'graphicalSubject', PlayerPresentationFields::LEGACY);
    $session->saveDatabase($category);
    expect(require $path)->toBe($before)
        ->and(file_get_contents($path))->toContain('// Terminal art', "'future' => abs(9)", '/* fixed cell */');
});

it('authors the stable actor field role without changing other roles or identity and roundtrips exact literal edits', function () {
    [$root, $path, , $session] = createFieldRoleProject();
    $source = file_get_contents($path);
    $before = (require $path)['data'];
    applyFieldRole($session, 'actors', 'images.field2d.index', '6');
    applyFieldRole($session, 'actors', 'images.field2d.layer', '999');
    expect(file_get_contents($path))->toBe($source);
    $session->undo();
    expect(getFieldRoleRow($session, 'actors', 'images.field2d.layer')['value'])->toBe('100');
    $session->redo();
    $session->saveDatabase('actors');
    expect(file_get_contents($path))->toBe(str_replace(["'index' => 2", "'layer' => 100"], ["'index' => 6", "'layer' => 999"], $source));
    $after = (require $path)['data'];
    unset($after['images']['field2d'], $before['images']['field2d']);
    expect($after)->toBe($before)
        ->and(getFieldRoleRow(EditorSession::open($root), 'actors', 'images.field2d.index')['value'])->toBe('6');
});

it('clears the whole optional sheet and restores its settings through history for each owner', function (string $category, string $prefix) {
    [$root, $actor, $player, $session] = createFieldRoleProject();
    $original = getFieldRoleRow($session, $category, $prefix . '.sheet');
    $key = getFieldRoleRow($session, $category, $prefix . '.index')['key'];
    applyFieldRole($session, $category, $prefix . '.sheet', '');
    expect(getFieldRoleRow($session, $category, $prefix . '.sheet'))->not->toHaveKey('imagePreview')
        ->and(getFieldRoleRow($session, $category, $prefix . '.index'))->toBeNull()
        ->and(fn() => $session->applyDatabaseRecord($category, 0, $key, '0'))->toThrow(SessionRefusal::class);
    $session->undo();
    expect(getFieldRoleRow($session, $category, $prefix . '.sheet'))->toBe($original);
    $session->redo();
    $session->saveDatabase($category);
    expect($category === 'actors' ? (require $actor)['data']['images'] : require $player)
        ->not->toHaveKey($category === 'actors' ? 'field2d' : 'sprites2d');
    $session->undo();
    $session->saveDatabase($category);
    expect(getFieldRoleRow(EditorSession::open($root), $category, $prefix . '.sheet'))->toBe($original);
})->with([['actors', 'images.field2d'], [PlayerPresentationFields::CATEGORY, 'sprites2d']]);

it('refuses invalid mode choices without altering data source or history', function (string $value) {
    [$root, , , $session] = createFieldRoleProject();
    $files = sourceHashTree($root);
    $before = $session->readDatabaseRecord(PlayerPresentationFields::CATEGORY, 0);
    expect(fn() => applyFieldRole($session, PlayerPresentationFields::CATEGORY, 'graphicalSubject', $value))->toThrow(SessionRefusal::class)
        ->and($session->readDatabaseRecord(PlayerPresentationFields::CATEGORY, 0))->toBe($before)
        ->and($session->undo()['label'])->toBeNull()->and(sourceHashTree($root))->toBe($files);
})->with(['', 'Party-Leader', ' party-leader', 'protagonist']);

it('refuses invalid sheets indices layers and identity substitutions before mutation', function (string $field, string $value) {
    [$root, , , $session] = createFieldRoleProject();
    $files = sourceHashTree($root);
    $before = $session->readDatabaseRecord('actors', 0);
    expect(fn() => applyFieldRole($session, 'actors', 'images.field2d.' . $field, $value))->toThrow(SessionRefusal::class)
        ->and($session->readDatabaseRecord('actors', 0))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([])
        ->and($session->undo()['label'])->toBeNull()->and(sourceHashTree($root))->toBe($files);
})->with([['sheet', '../Outside.png'], ['sheet', 'Graphics/Characters/Missing.png'],
    ['sheet', 'Graphics/Characters/Uneven.png'], ['sheet', 'Graphics/Characters/$Solo.png'],
    ['index', '8'], ['index', '-1'], ['index', '1.5'], ['index', ''], ['layer', '-1'], ['layer', '1000'], ['layer', '2147483648']]);

it('does not reset sheet selection metadata when choosing another layout', function () {
    [, , , $session] = createFieldRoleProject();
    applyFieldRole($session, 'actors', 'images.field2d.index', '0');
    applyFieldRole($session, 'actors', 'images.field2d.sheet', 'Graphics/Characters/$Solo.png');
    expect(getFieldRoleRow($session, 'actors', 'images.field2d.index')['options'])->toBe(['0'])
        ->and(getFieldRoleRow($session, 'actors', 'images.field2d.layer')['value'])->toBe('100');
});

it('reads replaceable current assets and diagnoses missing actor art without a substitute or source mutation', function (string $replacement) {
    [$root, , , $session] = createFieldRoleProject();
    $path = $root . '/assets/Graphics/Characters/Cast.png';
    $original = getFieldRoleRow($session, 'actors', 'images.field2d.sheet')['imagePreview'];
    if ($replacement === 'valid') {
        writeTilesetTestPng($path, 96, 64);
    } elseif ($replacement === 'uneven') {
        writeTilesetTestPng($path, 50, 50);
    } elseif ($replacement === 'malformed') {
        file_put_contents($path, 'not PNG');
    } else {
        unlink($path);
    }
    $files = sourceHashTree($root);
    $row = getFieldRoleRow($session, 'actors', 'images.field2d.sheet');
    if ($replacement === 'valid') {
        expect($row['imagePreview']['frames'][0]['sourceRect']['width'])->toBe(2 * $original['frames'][0]['sourceRect']['width']);
    } else {
        expect($row['imagePreview']['frames'])->toBe([])->and($row['imagePreview']['issue'])->not->toBeEmpty();
    }
    expect($row['value'])->toBe('Graphics/Characters/Cast.png')->and($session->undo()['label'])->toBeNull()
        ->and(sourceHashTree($root))->toBe($files);
})->with(['valid', 'uneven', 'malformed', 'missing']);

it('refuses opaque and externally conflicting actor sources before an undo step', function (string $mode) {
    [$root, $path, , $session] = createFieldRoleProject();
    if ($mode === 'external') {
        file_put_contents($path, file_get_contents($path) . "\n// Other author.\n");
    } else {
        file_put_contents($path, str_replace("'index' => 2", "'index' => abs(2)", file_get_contents($path)));
        $session = EditorSession::open($root);
    }
    $files = sourceHashTree($root);
    expect(fn() => applyFieldRole($session, 'actors', 'images.field2d.index', '6'))->toThrow(SessionRefusal::class)
        ->and(getFieldRoleRow($session, 'actors', 'images.field2d.index')['value'])->toBe('2')
        ->and($session->undo()['label'])->toBeNull()->and(sourceHashTree($root))->toBe($files);
})->with(['expression', 'external']);

it('refuses opaque player selector source and can recover by reopening externally edited source', function () {
    [$root, , $path] = createFieldRoleProject();
    file_put_contents($path, str_replace("'future' => abs(9)", "'graphicalSubject' => strtolower('FIXED-PLAYER'), 'future' => abs(9)", file_get_contents($path)));
    $session = EditorSession::open($root);
    $files = sourceHashTree($root);
    expect(fn() => applyFieldRole($session, PlayerPresentationFields::CATEGORY, 'graphicalSubject', 'party-leader'))->toThrow(SessionRefusal::class)
        ->and($session->undo()['label'])->toBeNull()->and(sourceHashTree($root))->toBe($files);
    file_put_contents($path, str_replace("strtolower('FIXED-PLAYER')", "'party-leader'", file_get_contents($path)));
    expect(getFieldRoleRow(EditorSession::open($root), PlayerPresentationFields::CATEGORY, 'sprites2d.sheet')['kind'])->toBe('info');
});

it('serves the player mode and actor role through the existing GUI record and picker protocol', function () {
    [$root] = createFieldRoleProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params = []): array => $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]));
    $hello = $request(1, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    expect($hello)->not->toHaveKey('error');
    $read = $request(2, 'database.record', ['category' => 'actors', 'index' => 0])['result'];
    $row = array_find($read['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'images.field2d.sheet');
    expect($row['reference'])->toBe('png_assets')
        ->and(array_column(EditorSession::open($root)->listReferences(null, 'png_assets'), 'value'))->toContain('Graphics/Characters/Cast.png')
        ->and($request(3, 'database.apply', ['category' => 'actors', 'index' => 0, 'key' => $row['key'], 'value' => ''])['result']['changed'])->toBeTrue()
        ->and($request(4, 'history.undo')['result']['databases'])->toContain('actors');
});

it('refuses externally changed player source during edits and Save All without overwriting it', function (bool $editFirst) {
    [$root, , $path, $session] = createFieldRoleProject();
    $category = PlayerPresentationFields::CATEGORY;
    if ($editFirst) {
        applyFieldRole($session, $category, 'graphicalSubject', 'party-leader');
    }
    file_put_contents($path, str_replace("'index' => 3", "'index' => 4", file_get_contents($path)));
    $source = file_get_contents($path);
    if ($editFirst) {
        expect(fn() => $session->saveDatabase($category))->toThrow(SessionRefusal::class)
            ->and($session->saveAll()['failures'])->not->toBeEmpty()
            ->and($session->hasUnsavedChanges())->toBeTrue();
    } else {
        expect(fn() => applyFieldRole($session, $category, 'graphicalSubject', 'party-leader'))->toThrow(SessionRefusal::class)
            ->and($session->undo()['label'])->toBeNull();
    }
    expect(file_get_contents($path))->toBe($source)
        ->and(getFieldRoleRow(EditorSession::open($root), $category, 'sprites2d.index')['value'])->toBe('4');
})->with([false, true]);

it('binds a previously absent actor role without storing runtime sheet facts or inferred identities', function () {
    [$root, $path] = createFieldRoleProject();
    $before = require $path;
    unset($before['data']['images']['field2d']);
    file_put_contents($path, '<?php return ' . var_export($before, true) . ';');
    $session = EditorSession::open($root);
    expect(getFieldRoleRow($session, 'actors', 'images.field2d.sheet'))->not->toHaveKey('imagePreview')
        ->and(getFieldRoleRow($session, 'actors', 'images.field2d.index'))->toBeNull();
    applyFieldRole($session, 'actors', 'images.field2d.sheet', 'Graphics/Characters/$Solo.png');
    $session->saveAll();
    $after = (require $path)['data'];
    expect($after['images']['field2d'])->toBe(['sheet' => 'Graphics/Characters/$Solo.png'])
        ->and($after['id'])->toBe('actor-stable')->and($after['images']['face'])->toBe('unrelated-face')
        ->and(getFieldRoleRow(EditorSession::open($root), 'actors', 'images.field2d.index')['value'])->toBe('0');
});

it('diagnoses invalid authored selectors without presenting inactive fixed art as a substitute', function (mixed $mode) {
    [$root, , $path] = createFieldRoleProject();
    $data = require $path;
    $data['graphicalSubject'] = $mode;
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    $session = EditorSession::open($root);
    $read = $session->readDatabaseRecord(PlayerPresentationFields::CATEGORY, 0);
    expect(getFieldRoleRow($session, PlayerPresentationFields::CATEGORY, 'sprites2d.sheet'))->toMatchArray(['kind' => 'info', 'label' => 'Inactive Fixed Player Field Sheet'])
        ->and(getFieldRoleRow($session, PlayerPresentationFields::CATEGORY, 'sprites2d.sheet'))->not->toHaveKey('imagePreview')
        ->and(json_encode($read))->toContain('Invalid graphicalSubject');
    expect(getFieldRoleRow($session, PlayerPresentationFields::CATEGORY, 'graphicalSubject')['value'])->not->toBe(PlayerPresentationFields::LEGACY);
    applyFieldRole($session, PlayerPresentationFields::CATEGORY, 'graphicalSubject', 'fixed-player');
    expect(getFieldRoleRow($session, PlayerPresentationFields::CATEGORY, 'sprites2d.sheet')['kind'])->toBe('reference');
})->with([null, 'unknown', 42]);

it('refuses invalid fixed-player art through the normal optional-presentation authoring refusal', function (string $field, string $value) {
    [$root, , , $session] = createFieldRoleProject();
    $files = sourceHashTree($root);
    expect(fn() => applyFieldRole($session, PlayerPresentationFields::CATEGORY, 'sprites2d.' . $field, $value))->toThrow(SessionRefusal::class)
        ->and($session->undo()['label'])->toBeNull()->and(sourceHashTree($root))->toBe($files);
})->with([['sheet', 'Graphics/Characters/Missing.png'], ['sheet', 'Graphics/Characters/Uneven.png'], ['index', '8'], ['layer', '1.5']]);

it('allows an absent player presentation file to be authored without manufacturing terminal data', function () {
    [$root, , $path] = createFieldRoleProject();
    unlink($path);
    $session = EditorSession::open($root);
    applyFieldRole($session, PlayerPresentationFields::CATEGORY, 'graphicalSubject', 'party-leader');
    $saved = $session->saveAll();
    expect($saved['failures'])->toBe([])->and(require $path)->toBe(['graphicalSubject' => 'party-leader']);
});

it('preserves actor roles and the player presentation file when the TUI edits ordinary actor data', function () {
    [$root, $path, $player] = createFieldRoleProject();
    $workspace = ProjectWorkspace::fromProject($root);
    $before = (require $path)['data'];
    $source = file_get_contents($player);
    $authoring = new ActorAuthoring();
    $authoring->applyField($workspace, 0, 'description', 'Terminal-only ordinary edit', 'Description');
    $workspace->actorDatabase->save();
    expect((require $path)['data']['images'])->toBe($before['images'])->and(file_get_contents($player))->toBe($source)
        ->and(fn() => $authoring->applyField($workspace, 0, 'images.field2d.sheet', '', 'Field Sheet'))
        ->toThrow(\Ichiloto\Editor\Database\RecordRefusal::class);
});

it('requires a stable actor identity before field art authoring and never infers it from a sheet or filename', function () {
    [$root, $path] = createFieldRoleProject();
    file_put_contents($path, str_replace("'id' => 'actor-stable', ", '', file_get_contents($path)));
    $session = EditorSession::open($root);
    $row = getFieldRoleRow($session, 'actors', 'images.field2d.sheet');
    $files = sourceHashTree($root);
    expect($row['kind'])->toBe('info')
        ->and($row)->not->toHaveKey('imagePreview')
        ->and(fn() => $session->applyDatabaseRecord('actors', 0, $row['key'], 'Graphics/Characters/$Solo.png'))->toThrow(SessionRefusal::class)
        ->and($session->undo()['label'])->toBeNull()->and(sourceHashTree($root))->toBe($files);
});

it('reads the saved role with the current Engine actor contract', function () {
    [$root, , , $session] = createFieldRoleProject();
    applyFieldRole($session, 'actors', 'images.field2d.index', '0');
    applyFieldRole($session, 'actors', 'images.field2d.sheet', 'Graphics/Characters/$Solo.png');
    $session->saveDatabase('actors');
    $actor = ProjectWorkspace::fromProject($root)->actorDatabase->createActorStore()->get('actor-stable');
    expect($actor->getGraphicalCharacterSheet()->asset)->toBe('Graphics/Characters/$Solo.png')
        ->and($actor->getGraphicalCharacterSheet()->index)->toBe(0)
        ->and($actor->id)->toBe('actor-stable');
});

it('diagnoses malformed actor roles and clears only that optional role without substituting artwork', function () {
    [$root, $path] = createFieldRoleProject();
    $data = require $path;
    $data['data']['images']['field2d'] = 'not a sheet definition';
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');
    $session = EditorSession::open($root);
    $row = getFieldRoleRow($session, 'actors', 'images.field2d.sheet');
    expect($row['imagePreview']['frames'])->toBe([])->and($row['imagePreview']['issue'])->not->toBeEmpty()
        ->and(fn() => applyFieldRole($session, 'actors', 'images.field2d.sheet', 'Graphics/Characters/$Solo.png'))->toThrow(SessionRefusal::class);
    applyFieldRole($session, 'actors', 'images.field2d.sheet', '');
    $session->saveDatabase('actors');
    expect((require $path)['data']['images'])->toBe(['face' => 'unrelated-face']);
    $session->undo();
    expect(getFieldRoleRow($session, 'actors', 'images.field2d.sheet')['imagePreview']['issue'])->toBe($row['imagePreview']['issue']);
});
