<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Database\ReferenceCatalog;

function createWorldObjectProject(): array
{
    $root = mapGraphicsProject();
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = (string) file_get_contents($file);
    $source = str_replace("'events' => [],", "'events' => [], /* map-owned identity */ 'worldObjects' => [
        ['id' => 'fixture-prop', 'anchor' => ['x' => 1, 'y' => 1],
            'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => null],
    ], 'tileLayers' => ['floor' => ['movesWith' => 'terrain']], 'preserved' => abs(7),", $source);
    file_put_contents($file, $source);
    writeTilesetTestPng($root . '/assets/Graphics/Props/Replaceable.png', 12, 16);
    writeTilesetTestPng($root . '/assets/Graphics/Props/$Walking.png', 12, 16);
    return [$root, $file, EditorSession::open($root)];
}

function applyWorldObjectRow(EditorSession $session, string $field, string $value): array
{
    $view = $session->readWorldObject('test-map', 'fixture-prop');
    $row = array_find($view['rows'], static fn($row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException('Missing world-object row ' . $field);
    return $session->applyWorldObject('test-map', $view['revision'], 'fixture-prop', $row['key'], $value);
}

it('owns objects only in map source, with source-safe history save and reopen', function () {
    [$root, $file, $session] = createWorldObjectProject();
    $before = file_get_contents($file);
    $map = $session->readMap('test-map');
    expect(array_column($map['worldObjects'], 'id'))->toBe(['fixture-prop']);
    $session->createWorldObject('test-map', $map['revision'], 'new-prop', 2, 1);
    expect(file_get_contents($file))->toBe($before)
        ->and($session->undo()['maps'])->toBe(['test-map']);
    expect(array_column($session->readMap('test-map')['worldObjects'], 'id'))->toBe(['fixture-prop']);
    $session->redo();
    applyWorldObjectRow($session, 'anchor.x', '2');
    applyWorldObjectRow($session, 'sprites2d.asset', 'Graphics/Props/Replaceable.png');
    $session->saveMap('test-map');
    expect(file_get_contents($file))->toContain('/* map-owned identity */', "'preserved' => abs(7)")
        ->and(EditorSession::open($root)->readMap('test-map')['worldObjects'][0]['sprites2d'])->toBe(['asset' => 'Graphics/Props/Replaceable.png']);
    $revision = $session->readMap('test-map')['revision'];
    $session->deleteWorldObject('test-map', $revision, 'new-prop');
    $session->undo();
    expect(array_column($session->readMap('test-map')['worldObjects'], 'id'))->toBe(['fixture-prop', 'new-prop']);
});

it('offers typed variants conditions coverage and known resource selections', function () {
    [, , $session] = createWorldObjectProject();
    $view = $session->readWorldObject('test-map', 'fixture-prop');
    $heading = array_find($view['rows'], static fn($row): bool => ($row['key']['field'] ?? null) === 'variantList');
    $session->changeWorldObjectItem('test-map', $view['revision'], 'fixture-prop', $heading['key'], false);
    expect(fn() => $session->saveMap('test-map'))->toThrow(SessionRefusal::class, 'conditions');
    applyWorldObjectRow($session, 'variant0Conditions', 'switch:existing-condition');
    applyWorldObjectRow($session, 'variant0Sprites2dasset', 'Graphics/Props/Replaceable.png');
    $layer = array_find($session->readMap('test-map')['tileLayers']['layers'], static fn($layer): bool => $layer['name'] === 'floor')['owner'];
    applyWorldObjectRow($session, 'covers.layer', $layer);
    $session->placeWorldObject('test-map', $session->readMap('test-map')['revision'], 'fixture-prop', 2, 1, true);
    $object = $session->readMap('test-map')['worldObjects'][0];
    expect($object['variants'][0]['conditions'])->toBe([['type' => 'switch', 'name' => 'existing-condition']])
        ->and($object['covers']['cells'])->toBe([[1, 1], [2, 1]])
        ->and($object['covers']['tileLayers'])->toBe([]);
    $choices = $session->listReferences('test-map', 'world_object_tile_layers', ['id' => 'fixture-prop']);
    expect(array_column($choices, 'value'))->toContain('floor');
    applyWorldObjectRow($session, 'covers.tileLayers', 'floor');
    expect(fn() => applyWorldObjectRow($session, 'covers.tileLayers', 'unknown'))->toThrow(SessionRefusal::class, 'Choose a tile layer');
    $session->saveMap('test-map');
});

it('shares current-file walking roles and explicit absence without changing Terminal or occupancy', function () {
    [$root, , $session] = createWorldObjectProject();
    $before = $session->readMap('test-map');
    $terminal = ProjectWorkspace::fromProject($root)->maps[0]->getLayerSet()->getComposedGrid();
    applyWorldObjectRow($session, 'sprites2d.sheet', 'Graphics/Props/$Walking.png');
    $row = array_find($session->readWorldObject('test-map', 'fixture-prop')['rows'], static fn($row): bool => ($row['key']['field'] ?? null) === 'sprites2d.sheet');
    expect($row['imagePreview']['issue'] ?? null)->toBeNull()
        ->and(count($row['imagePreview']['frames']))->toBe(4);
    applyWorldObjectRow($session, 'sprites2d.sheet', '');
    expect($session->readMap('test-map')['worldObjects'][0]['sprites2d'])->toBeNull()
        ->and($session->readMap('test-map')['occupancy'])->toBe($before['occupancy'])
        ->and($session->readMap('test-map')['layers'])->toBe($before['layers']);
    $session->saveMap('test-map');
    expect(ProjectWorkspace::fromProject($root)->maps[0]->getLayerSet()->getComposedGrid())->toBe($terminal);
});

it('refuses stale revisions ids assets expressions and external source edits before mutation', function () {
    [$root, $file, $session] = createWorldObjectProject();
    $view = $session->readWorldObject('test-map', 'fixture-prop');
    expect(fn() => $session->createWorldObject('test-map', $view['revision'], 'fixture-prop', 1, 1))->toThrow(SessionRefusal::class, 'Duplicate')
        ->and(fn() => $session->createWorldObject('test-map', $view['revision'], 'Invalid Id', 1, 1))->toThrow(SessionRefusal::class, 'stable lowercase')
        ->and(fn() => applyWorldObjectRow($session, 'sprites2d.asset', '../foreign.png'))->toThrow(SessionRefusal::class, 'Choose a current');
    applyWorldObjectRow($session, 'anchor.x', '2');
    expect(fn() => $session->deleteWorldObject('test-map', $view['revision'], 'fixture-prop'))->toThrow(SessionRefusal::class, 'changed since revision');
    file_put_contents($file, str_replace("'preserved' => abs(7)", "'preserved' => abs(8)", (string) file_get_contents($file)));
    expect(fn() => applyWorldObjectRow($session, 'anchor.x', '3'))->toThrow(SessionRefusal::class)
        ->and($session->readMap('test-map')['worldObjects'][0]['anchor']['x'])->toBe(2);
    $source = str_replace("'x' => 1", "'x' => abs(1)", (string) file_get_contents($file));
    file_put_contents($file, $source);
    $reloaded = EditorSession::open($root);
    expect($reloaded->readWorldObject('test-map', 'fixture-prop')['issue'])->toContain('literal')
        ->and(fn() => applyWorldObjectRow($reloaded, 'anchor.x', '3'))->toThrow(SessionRefusal::class)
        ->and(file_get_contents($file))->toBe($source);
});

it('uses Engine coverage and current-frame projection, retaining glyphs for missing roles', function () {
    [$root, , $session] = createWorldObjectProject();
    $layer = $session->readMap('test-map')['layers'][0]['name'];
    applyWorldObjectRow($session, 'covers.layer', $layer);
    applyWorldObjectRow($session, 'sprites2d.asset', 'Graphics/Props/Replaceable.png');
    $preview = $session->readWorldObjectPreview('test-map');
    $sprite = array_find($preview['update']['operations'], static fn($op): bool => ($op['kind'] ?? '') === 'sprite');
    expect($sprite['value']['id'])->toBe('world-object:test-map:fixture-prop')
        ->and($sprite['value']['pivot'])->toBe(['x' => .5, 'y' => 1.0]);
    unlink($root . '/assets/Graphics/Props/Replaceable.png');
    $missing = $session->readWorldObjectPreview('test-map');
    expect($missing['diagnostics'])->toHaveCount(1)
        ->and(array_filter($missing['update']['operations'], static fn($op): bool => ($op['kind'] ?? '') === 'sprite'))->toBe([]);
    writeTilesetTestPng($root . '/assets/Graphics/Props/Replaceable.png', 24, 32);
    expect($session->readWorldObjectPreview('test-map')['diagnostics'])->toBe([]);
});

it('routes real RPC ownership and cinematic object selectors without a global object database', function () {
    [$root, , $session] = createWorldObjectProject();
    $host = new SessionHost(fopen('php://memory', 'r+'), fopen('php://memory', 'r+'), fopen('php://memory', 'r+'));
    $request = static fn(string $method, array $params): array => $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params]));
    $hello = $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    expect(array_column($hello['result']['project']['databases'], 'key'))->not->toContain('world_objects');
    $view = $request('worldObjects.read', ['map' => 'test-map', 'id' => 'fixture-prop'])['result'];
    expect($request('worldObjects.place', ['map' => 'test-map', 'id' => 'fixture-prop', 'revision' => $view['revision'], 'x' => 2, 'y' => 1])['result']['changed'])->toBeTrue();
    expect($request('worldObjects.preview', ['map' => 'test-map'])['result']['update']['reset'])->toBeTrue()
        ->and($request('worldObjects.preview', ['map' => 'test-map', 'seconds' => 'wrong'])['error']['kind'])->toBe('request');
    expect(array_column($session->listReferences('test-map', 'map_world_objects'), 'value'))->toBe(['fixture-prop']);
    $camera = CutsceneSchemas::cinematicCommandVariants(true)['camera'](['operation' => 'focus', 'target' => ['kind' => 'world_object', 'id' => 'fixture-prop']]);
    expect(array_find($camera, static fn($field): bool => $field->key === 'target.id')->reference)->toBe('map_world_objects');
});

it('refuses referenced and opaque cinematic deletions before source history or revision mutation', function () {
    [$root, $file] = createWorldObjectProject();
    $folder = $root . '/assets/Cutscenes/Cinematics/fixture-cutscene';
    mkdir($folder, 0777, true);
    $dataFile = $folder . '/fixture-cutscene.data.php';
    $scriptFile = $folder . '/fixture-cutscene.script.php';
    file_put_contents($dataFile, "<?php return ['id'=>'fixture-cutscene', 'name'=>'Fixture', 'startMap'=>'test-map', 'cast'=>[
        ['kind'=>'staged_actor', 'id'=>'staged', 'subject'=>['kind'=>'world_object','id'=>'fixture-prop'],
        'suppress'=>[['kind'=>'world_object','id'=>'fixture-prop']]],
    ]];");
    file_put_contents($scriptFile, "<?php return [['type'=>'camera', 'operation'=>'focus', 'target'=>['kind'=>'world_object','id'=>'fixture-prop']]];");
    $session = EditorSession::open($root);
    $before = $session->readMap('test-map');
    $source = file_get_contents($file);
    try {
        $session->deleteWorldObject('test-map', $before['revision'], 'fixture-prop');
        $this->fail('Referenced object deletion was not refused.');
    } catch (SessionRefusal $error) {
        expect($error->getMessage())->toContain('fixture-cutscene.cast.0.subject', 'fixture-cutscene.cast.0.suppress.0', 'fixture-cutscene.commands.0.target');
    }
    expect($session->readMap('test-map'))->toBe($before)
        ->and(file_get_contents($file))->toBe($source)
        ->and($session->undo()['maps'])->toBe([]);
    file_put_contents($dataFile, "<?php return ['id'=>'fixture-cutscene', 'name'=>'Fixture', 'startMap'=>'test-map', 'cast'=>[]];");
    file_put_contents($scriptFile, "<?php return array_filter([]);");
    $opaque = EditorSession::open($root);
    expect(fn() => $opaque->deleteWorldObject('test-map', $opaque->readMap('test-map')['revision'], 'fixture-prop'))
        ->toThrow(SessionRefusal::class, 'opaque authored source');
    file_put_contents($scriptFile, "<?php return [['type'=>'wait','seconds'=>0.5]];");
    $safe = EditorSession::open($root);
    $safe->deleteWorldObject('test-map', $safe->readMap('test-map')['revision'], 'fixture-prop');
    expect($safe->readMap('test-map')['worldObjects'])->toBe([]);
    $safe->undo();
    expect(array_column($safe->readMap('test-map')['worldObjects'], 'id'))->toBe(['fixture-prop']);
    $safe->redo();
    $safe->saveMap('test-map');
    expect(EditorSession::open($root)->readMap('test-map')['worldObjects'])->toBe([]);
});

it('reorders explicit variants durably with undo and refuses invalid preview selection', function () {
    [$root, , $session] = createWorldObjectProject();
    foreach (['first', 'second'] as $id) {
        $view = $session->readWorldObject('test-map', 'fixture-prop');
        $heading = array_find($view['rows'], static fn($row): bool => ($row['key']['field'] ?? '') === 'variantList');
        $session->changeWorldObjectItem('test-map', $view['revision'], 'fixture-prop', $heading['key'], false);
        applyWorldObjectRow($session, 'variant0Id', $id);
        applyWorldObjectRow($session, 'variant0Conditions', 'switch:existing-condition');
    }
    $session->moveWorldObjectVariant('test-map', $session->readMap('test-map')['revision'], 'fixture-prop', 'first', -1);
    expect($session->readWorldObject('test-map', 'fixture-prop')['worldObject']['variants'])->toBe(['first', 'second']);
    $session->undo();
    expect($session->readWorldObject('test-map', 'fixture-prop')['worldObject']['variants'])->toBe(['second', 'first']);
    $session->redo();
    $session->saveMap('test-map');
    expect(EditorSession::open($root)->readWorldObject('test-map', 'fixture-prop')['worldObject']['variants'])->toBe(['first', 'second'])
        ->and(fn() => $session->readWorldObjectPreview('test-map', ['fixture-prop'=>true]))->toThrow(SessionRefusal::class, 'known variant ids')
        ->and(fn() => $session->readWorldObjectPreview('test-map', ['fixture-prop'=>'unknown']))->toThrow(SessionRefusal::class, 'known world-object variant');
});

it('preserves known layer references through renames and refuses dangling removal atomically', function () {
    [$root, , $session] = createWorldObjectProject();
    applyWorldObjectRow($session, 'covers.layer', 'terrain');
    applyWorldObjectRow($session, 'covers.tileLayers', 'floor');
    $layer = array_find($session->readMap('test-map')['layers'], static fn($layer): bool => $layer['name'] === 'terrain')['id'];
    $session->renameLayer('test-map', $session->readMap('test-map')['revision'], $layer, 'ground-renamed', true);
    $session->renameTileLayer('test-map', $session->readMap('test-map')['revision'], 'floor', 'floor-renamed');
    $before = $session->readMap('test-map');
    expect($before['worldObjects'][0]['covers'])->toBe(['layer'=>'ground-renamed','cells'=>[[1,1]],'tileLayers'=>['floor-renamed']])
        ->and(fn() => $session->removeTileLayer('test-map', $before['revision'], 'floor-renamed'))->toThrow(SessionRefusal::class)
        ->and(fn() => $session->removeLayer('test-map', $before['revision'], $layer, true))->toThrow(SessionRefusal::class)
        ->and($session->readMap('test-map'))->toBe($before);
    $session->undo();
    expect($session->readMap('test-map')['worldObjects'][0]['covers']['tileLayers'])->toBe(['floor']);
    $session->redo();
    $session->saveMap('test-map');
    expect(EditorSession::open($root)->readMap('test-map')['worldObjects'][0]['covers'])->toBe($before['worldObjects'][0]['covers']);
});

it('moves only owned map coordinates in the existing insertion inventory and refuses clipped anchors', function () {
    [$root] = createWorldObjectProject();
    $map = ProjectWorkspace::fromProject($root)->maps[0];
    expect(fn() => $map->resize(1,1))->toThrow(Ichiloto\Editor\MapSourceRefusal::class)
        ->and($map->getWidth())->toBe(4);
    $data = $map->getMapDataField([]);
    $data['worldObjects'][0]['covers'] = ['layer'=>'terrain','cells'=>[[1,1],[3,1]],'tileLayers'=>['floor']];
    $inventory = new Ichiloto\Editor\Maps\LineInsertionInventory(new Ichiloto\Editor\Maps\LineInsertion('test-map','x',1,2), []);
    $inventory->addMapData('fixture.data.php','test-map',$data);
    $shifts = $inventory->getShifts()['fixture.data.php'];
    expect(array_column($shifts,'path'))->toContain(['worldObjects',0,'anchor','x'], ['worldObjects',0,'covers','cells',0,0], ['worldObjects',0,'covers','cells',1,0])
        ->and(array_column($shifts,'value'))->toBe([3,3,5]);
});

it('keeps graphical subject controls out of Terminal schemas and uses typed camera route references', function () {
    $camera = CutsceneSchemas::cinematicCommandVariants(false)['camera'](['operation'=>'focus','target'=>['kind'=>'npc']]);
    expect(array_find($camera, static fn($field): bool => $field->key === 'target.kind')->options)->not->toContain('world_object');
    $points = CutsceneSchemas::cameraPointList(true)->fieldsFor(['kind'=>'world_object']);
    expect(array_find($points, static fn($field): bool => $field->key === 'id')->reference)->toBe('map_world_objects');
    $effect = CutsceneSchemas::cinematicCommandVariants(true)['field_animation'](['target'=>['kind'=>'world_object']]);
    expect(array_find($effect, static fn($field): bool => $field->key === 'target.id')->reference)->toBe('map_world_objects');
});

it('rechecks reference proof on redo and preserves the redo entry when a new source appears', function () {
    [$root, , $session] = createWorldObjectProject();
    $session->deleteWorldObject('test-map', $session->readMap('test-map')['revision'], 'fixture-prop');
    $session->undo();
    $before = $session->readMap('test-map');
    $folder = $root . '/assets/Cutscenes/Cinematics/external-fixture';
    mkdir($folder,0777,true);
    $file = $folder . '/external-fixture.script.php';
    file_put_contents($file, "<?php return [['type'=>'camera','operation'=>'focus','target'=>['kind'=>'world_object','id'=>'fixture-prop']]];");
    expect(fn() => $session->redo())->toThrow(SessionRefusal::class, 'external-fixture.script.php')
        ->and($session->readMap('test-map'))->toBe($before);
    unlink($file);
    $session->redo();
    expect($session->readMap('test-map')['worldObjects'])->toBe([]);
});
