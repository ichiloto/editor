<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\DirectionalSpriteDraft;
use Ichiloto\Editor\Field\ProjectNpc;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;

function createNpcArtProject(): string
{
    $root = makeTemporaryProject('npc-art-');
    mkdir($root . '/assets/Graphics/Npcs', 0777, true);
    $chunk = static fn(string $kind, string $bytes): string => pack('N', strlen($bytes)) . $kind . $bytes . pack('N', crc32($kind . $bytes));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNC5', 64, 64, 8, 6, 0, 0, 0))
        . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xff\xff\xff\xff", 64), 64))) . $chunk('IEND', '');
    foreach (ProjectNpc::DIRECTIONS as $direction) { file_put_contents($root . '/assets/Graphics/Npcs/' . $direction . '.png', $png); }
    file_put_contents($root . '/assets/Maps/test-map/test-map.data.php', "<?php\n// Authored NPCs.\nreturn ['name' => 'Test Map', 'events' => [], 'npcs' => [\n  /* resident */ ['id' => 'resident', 'name' => 'Resident', 'sprite' => '', 'x' => 1, 'y' => 1, 'dialogue' => [['text' => 'Hello']]],\n  /* neighbor */ ['id' => 'neighbor', 'name' => 'Neighbor', 'x' => 2, 'y' => 1],\n], 'custom' => strtoupper('unchanged')];\n");
    return $root;
}

function getNpcArtPoses(): array
{
    $data = [];
    foreach (ProjectNpc::DIRECTIONS as $direction) {
        $data[$direction] = ['asset' => 'Graphics/Npcs/' . $direction . '.png', 'width' => 16, 'height' => 24];
    }
    return $data;
}

it('keeps graphical NPC model save restore reload and duplication source-preserving', function () {
    $root = createNpcArtProject();
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    $original = file_get_contents($map->dataPath);
    $before = $map->captureLayerSnapshot();
    $terminal = $map->renderPreview(20, 10);
    $map->setNpcGraphicalSprites(0, getNpcArtPoses());
    $after = $map->captureLayerSnapshot();
    expect($map->getNpcs()->get(0)->toArray()['sprites2d'])->toEqual(getNpcArtPoses())
        ->and($map->getNpcs()->get(0)->getSprite())->toBe('')
        ->and($map->renderPreview(20, 10))->toBe($terminal);
    $map->save();
    $saved = file_get_contents($map->dataPath);
    expect($saved)->toContain('/* resident */', '/* neighbor */', "strtoupper('unchanged')");
    $map->restoreLayerSnapshot($before);
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe($original);
    $map->restoreLayerSnapshot($after);
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe($saved);
    $reloaded = ProjectMap::fromDirectory($root . '/assets/Maps', $map->directory);
    expect(DirectionalGraphicalSpriteSet::fromArray($reloaded->getNpcs()->get(0)->toArray()['sprites2d'])->south->height)->toBe(24);
    $map->duplicateTo($root . '/assets/Maps/art-copy', 'art-copy', 'Art Copy');
    expect((require $root . '/assets/Maps/art-copy/art-copy.data.php')['npcs'][0]['sprites2d'])->toEqual(getNpcArtPoses());
});

it('preserves sheets and crop metadata while editing one role and refuses implicit representation conversion', function () {
    $root = createNpcArtProject();
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    $data = ['mode' => 'sheet', 'frameWidth' => 16, 'frameHeight' => 16, 'width' => 16, 'height' => 24,
        'frameDurationMs' => 90, 'stepDurationMs' => 180, 'idleFrame' => 1, 'anchor' => 'bottom_center', 'layer' => 3,
        'directions' => array_map(static fn(array $pose): array => ['asset' => $pose['asset'], 'columns' => 4, 'rows' => 4, 'frames' => 12], getNpcArtPoses())];
    $map->setNpcGraphicalSprites(0, $data);
    $map->save();
    $draft = new DirectionalSpriteDraft($data);
    expect(fn() => $draft->setMode('poses'))->toThrow(RuntimeException::class, 'never converted');
    $draft->setField('north', 'directions.north.asset', 'Graphics/Npcs/south.png');
    $map->setNpcGraphicalSprites(0, $draft->getData());
    $map->save();
    $expected = $data;
    $expected['directions']['north']['asset'] = 'Graphics/Npcs/south.png';
    expect((require $map->dataPath)['npcs'][0]['sprites2d'])->toBe($expected);
});

it('refuses incomplete unsafe invalid crop and reserved layer art before changing the map', function (Closure $change) {
    $map = ProjectWorkspace::fromProject(createNpcArtProject())->getMapByIndex(0);
    $before = $map->captureLayerSnapshot();
    expect(fn() => $map->setNpcGraphicalSprites(0, $change(getNpcArtPoses())))->toThrow(Exception::class)
        ->and($map->captureLayerSnapshot())->toBe($before);
})->with([
    fn(array $data) => [],
    function (array $data) { unset($data['north']); return $data; },
    function (array $data) { $data['north']['asset'] = '../outside.png'; return $data; },
    function (array $data) { $data['north']['asset'] = 'Graphics/Npcs/missing.png'; return $data; },
    function (array $data) { $data['north']['layer'] = 1000; return $data; },
    function (array $data) { $data['north']['width'] = 0; return $data; },
    function (array $data) { $data['north']['sourceRect'] = ['x' => 63, 'y' => 0, 'width' => 16, 'height' => 16]; return $data; },
]);

it('refuses opaque sprites without flattening them', function () {
    $root = createNpcArtProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = str_replace("'sprite' => ''", "'sprites2d' => array_merge([], " . var_export(getNpcArtPoses(), true) . "), 'sprite' => ''", file_get_contents($path));
    file_put_contents($path, $source);
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    expect(fn() => $map->setNpcGraphicalSprites(0, getNpcArtPoses()))->toThrow(RuntimeException::class, 'Opaque')
        ->and(file_get_contents($path))->toBe($source);
});

it('removes graphical NPC authoring from the TUI while terminal sprite edits preserve graphical source', function () {
    $root = createNpcArtProject();
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    $map->setNpcGraphicalSprites(0, getNpcArtPoses());
    $map->save();
    $source = file_get_contents($map->dataPath);
    $assets = sourceHashTree($root . '/assets/Graphics');
    $editor = deletionEditor($root);
    $map = callEditorMethod($editor, 'getSelectedMap');
    callEditorMethod($editor, 'setEditingMode', 'npc');
    callEditorMethod($editor, 'selectNpc', 0);
    setEditorProperty($editor, 'focusedPane', 'inspector');
    $fields = callEditorMethod($editor, 'getInspectorFields');
    expect(json_encode($fields))->not->toContain('__npc_art', 'Graphical Sprites', 'sprites2d')
        ->and(method_exists($editor, 'openNpcSpriteArt'))->toBeFalse()
        ->and(renderEditorPlainFrame($editor, 160, 45))->not->toContain('Graphical Sprites');
    $index = array_find_key($fields, static fn(array $field): bool => ($field['field'] ?? null) === 'sprite');
    expect($index)->not->toBeNull();
    setEditorProperty($editor, 'databaseSelectedSettingIndex', $index);
    foreach (["\r", 'N', "\r", "\x13"] as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    $saved = str_replace("'sprite' => ''", "'sprite' => 'N'", $source);
    expect($map->getNpcs()->get(0)->getSprite())->toBe('N')
        ->and(file_get_contents($map->dataPath))->toBe($saved);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(file_get_contents($map->dataPath))->toBe($source);
    foreach (["\x19", "\x13", "\x12"] as $key) {
        callEditorMethod($editor, 'dispatchInput', $key);
    }
    $loaded = callEditorMethod($editor, 'getSelectedMap');
    expect(file_get_contents($loaded->dataPath))->toBe($saved)
        ->and($loaded->getNpcs()->get(0)->toArray()['sprites2d'])->toBe(getNpcArtPoses())
        ->and(sourceHashTree($root . '/assets/Graphics'))->toBe($assets);
});

it('preserves pending NPC art and all map files when the save transaction fails', function () {
    $root = createNpcArtProject();
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    $source = file_get_contents($map->dataPath);
    $map->setNpcGraphicalSprites(0, getNpcArtPoses());
    expect(fn() => $map->save(files: new FailingFileSetOperations(failures: ['move' => [$map->dataPath]])))->toThrow(RuntimeException::class)
        ->and(file_get_contents($map->dataPath))->toBe($source)->and($map->isDirty())->toBeTrue();
    $map->save();
    expect((require $map->dataPath)['npcs'][0]['sprites2d'])->toBe(getNpcArtPoses());
});

it('warns for malformed optional NPC graphics while accepting omission and retaining terminal-only identity', function (mixed $graphics) {
    $root = createNpcArtProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = str_replace("'sprite' => ''", "'sprites2d' => " . var_export($graphics, true) . ", 'sprite' => ''", file_get_contents($path));
    file_put_contents($path, $source);
    $workspace = ProjectWorkspace::fromProject($root);
    $issues = (new \Ichiloto\Editor\Validation\ProjectValidator())->validate($workspace);
    $warnings = array_filter($issues, static fn($issue) => str_contains($issue->message, 'Invalid sprites2d:'));
    expect($warnings)->toHaveCount(1)->and($workspace->getMapByIndex(0)->getNpcs()->get(0)->getId())->toBe('resident');
})->with([[null], [[]], ['invalid']]);

it('patches one literal NPC role asset without rewriting crop comments or other directions', function () {
    $root = createNpcArtProject();
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    $poses = getNpcArtPoses();
    $poses['north']['sourceRect'] = ['x' => 16, 'y' => 0, 'width' => 16, 'height' => 32];
    $map->setNpcGraphicalSprites(0, $poses);
    $map->save();
    $source = str_replace("'sourceRect' =>", "/* retain crop */ 'sourceRect' =>", file_get_contents($map->dataPath));
    file_put_contents($map->dataPath, $source);
    $map = ProjectMap::fromDirectory($root . '/assets/Maps', $map->directory);
    $poses['north']['asset'] = 'Graphics/Npcs/south.png';
    $map->setNpcGraphicalSprites(0, $poses);
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe(str_replace('Graphics/Npcs/north.png', 'Graphics/Npcs/south.png', $source));
});
