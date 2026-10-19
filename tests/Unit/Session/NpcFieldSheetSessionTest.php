<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Field\NpcInspector;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * An NPC's graphical character sheet, set from the graphical editor's NPC
 * page: a picture picked from the project's images, checked as the game will
 * read it, one undo step, and never one of the terminal's rows.
 */

/** A session on the graphics test map with one NPC and a single-character sheet (3 x 4 frames). */
function npcFieldSheetSession(): array
{
    $root = mapGraphicsProject();
    writeTilesetTestPng($root . '/assets/Graphics/Characters/$Guard.png', 144, 192);
    writeTilesetTestPng($root . '/assets/Graphics/Characters/Odd.png', 50, 50);
    $session = EditorSession::open($root);
    $session->createNpc('test-map', $session->readMap('test-map')['revision'], 2, 1, 'Gate Guard');

    return [$session, 0];
}

function findFieldSheetRow(EditorSession $session, int $index): ?array
{
    return array_find($session->readNpc('test-map', $index)['rows'], static fn(array $row): bool => $row['label'] === 'Field Sheet');
}

/** A literal sheet alongside authored expressions, terminal headings and an independent neighbor. */
function createNpcSheetAuthoringProject(string $asset = 'People.png', int $index = 2): array
{
    $root = mapGraphicsProject();
    writeTilesetTestPng($root . '/assets/Graphics/Characters/People.png', 48, 32);
    writeTilesetTestPng($root . '/assets/Graphics/Characters/$Guard.png', 12, 16);
    file_put_contents($root . '/assets/Maps/collisions.php', <<<'PHP'
<?php
use Ichiloto\Engine\Events\Enumerations\CollisionType;
return ['.' => CollisionType::NONE, 'x' => CollisionType::PASS_THROUGH,
    'buildings' => ['/' => CollisionType::NONE]];
PHP);
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = "<?php\n// Preserve authored NPC and unrelated source.\nreturn [
        'name' => 'Test Map', 'events' => [], 'tileset' => 'home',
        'futureValue' => abs(7),
        'npcs' => [
            ['id' => 'guide', 'name' => 'Guide', 'x' => 2, 'y' => 0,
                'sprite' => 'G', 'sprites' => ['north' => '^', 'south' => 'v', 'east' => '>', 'west' => '<'],
                'sprites2d' => ['sheet' => 'Graphics/Characters/$asset', /* chosen character */ 'index' => $index, 'layer' => 100]],
            ['id' => 'neighbor', 'name' => 'Neighbor', 'sprite' => 'N', 'x' => 3, 'y' => 1],
        ],
    ];\n";
    file_put_contents($path, $source);

    return [$root, $path, EditorSession::open($root)];
}

function getNpcSheetSettingRow(EditorSession $session, string $field): ?array
{
    return array_find($session->readNpc('test-map', 0)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'sprites2d.' . $field);
}

function setNpcSheetSetting(EditorSession $session, string $field, string $value): array
{
    $row = getNpcSheetSettingRow($session, $field) ?? throw new RuntimeException('Missing sheet setting ' . $field);

    return $session->applyNpc('test-map', $session->readMap('test-map')['revision'], 0, $row['key'], $value);
}

it('shows the field sheet as a picture to pick, after the terminal appearance', function () {
    [$session, $index] = npcFieldSheetSession();
    $row = findFieldSheetRow($session, $index);
    $labels = array_column($session->readNpc('test-map', $index)['rows'], 'label');

    expect($row)->toMatchArray(['kind' => 'reference', 'reference' => 'png_assets', 'value' => '',
            'media' => ['kind' => 'image', 'root' => 'assets'], 'noneLabel' => 'None: the glyph shows'])
        ->and(array_search('Field Sheet', $labels, true))->toBeGreaterThan(array_search('Sprite', $labels, true));
});

it('sets and clears the sheet as one undo step each, keeping the terminal sprite', function () {
    [$session, $index] = npcFieldSheetSession();
    $sprite = $session->readNpc('test-map', $index)['npc']['sprite'];
    $key = findFieldSheetRow($session, $index)['key'];

    $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $key, 'Graphics/Characters/$Guard.png');
    expect(findFieldSheetRow($session, $index)['value'])->toBe('Graphics/Characters/$Guard.png')
        ->and($session->readNpc('test-map', $index)['npc']['sprite'])->toBe($sprite);
    $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $key, '');
    expect(findFieldSheetRow($session, $index)['value'])->toBe('');
    $session->undo();
    expect(findFieldSheetRow($session, $index)['value'])->toBe('Graphics/Characters/$Guard.png');
});

it('refuses a picture the game could not read as a character sheet, changing nothing', function () {
    [$session, $index] = npcFieldSheetSession();
    $key = findFieldSheetRow($session, $index)['key'];

    expect(fn() => $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $key, 'Graphics/Characters/Odd.png'))
        ->toThrow(SessionRefusal::class)
        ->and(fn() => $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $key, 'Graphics/Characters/Missing.png'))
        ->toThrow(SessionRefusal::class)
        ->and(findFieldSheetRow($session, $index)['value'])->toBe('');
});

it('authors sheet index and graphical layer through actual session history and source-preserving save reopen', function () {
    [$root, $path, $session] = createNpcSheetAuthoringProject();
    $source = file_get_contents($path);
    $before = require $path;
    $map = loadLayeredMap($root);
    $terminal = $map->renderPreview(20, 10);
    $dictionary = require $root . '/assets/Maps/collisions.php';
    $collision = \Ichiloto\Engine\Field\MapCollisionResolver::resolveLayers($map->getLayerSet(), $dictionary);
    $grids = sourceHashTree($root . '/assets/Maps/test-map/layers');
    $row = getNpcSheetSettingRow($session, 'index');
    expect($row)->toMatchArray(['kind' => 'options', 'options' => ['0', '1', '2', '3', '4', '5', '6', '7'], 'value' => '2'])
        ->and(getNpcSheetSettingRow($session, 'layer'))->toMatchArray(['kind' => 'integer', 'value' => '100']);
    setNpcSheetSetting($session, 'index', '6');
    expect(getNpcSheetSettingRow($session, 'sheet')['imagePreview']['frames'][0]['sourceRect'])
        ->toBe(['x' => 28, 'y' => 16, 'width' => 4, 'height' => 4]);
    setNpcSheetSetting($session, 'layer', '999');
    expect(file_get_contents($path))->toBe($source);
    $session->undo();
    expect(getNpcSheetSettingRow($session, 'layer')['value'])->toBe('100');
    $session->undo();
    expect(getNpcSheetSettingRow($session, 'index')['value'])->toBe('2');
    $session->redo();
    $session->redo();
    expect(setNpcSheetSetting($session, 'index', '6')['changed'])->toBeFalse();
    $session->saveMap('test-map');
    expect(file_get_contents($path))->toBe(str_replace(["'index' => 2", "'layer' => 100"], ["'index' => 6", "'layer' => 999"], $source));
    $saved = require $path;
    expect($saved['npcs'][0]['sprites2d'])->toBe(['sheet' => 'Graphics/Characters/People.png', 'index' => 6, 'layer' => 999]);
    unset($saved['npcs'][0]['sprites2d'], $before['npcs'][0]['sprites2d']);
    expect($saved)->toBe($before)
        ->and(sourceHashTree($root . '/assets/Maps/test-map/layers'))->toBe($grids);
    $map = loadLayeredMap($root);
    expect($map->renderPreview(20, 10))->toBe($terminal)
        ->and(\Ichiloto\Engine\Field\MapCollisionResolver::resolveLayers($map->getLayerSet(), $dictionary))->toBe($collision);
    $reopened = EditorSession::open($root);
    expect(getNpcSheetSettingRow($reopened, 'index')['value'])->toBe('6')
        ->and(getNpcSheetSettingRow($reopened, 'layer')['value'])->toBe('999')
        ->and(getNpcSheetSettingRow($reopened, 'sheet')['imagePreview'])->toBe(getNpcSheetSettingRow($session, 'sheet')['imagePreview']);
    $inspector = new NpcInspector(ProjectWorkspace::fromProject($root)->getMapByIndex(0));
    expect(json_encode($inspector->getFields(0)))->not->toContain('sprites2d', 'imagePreview');
});

it('removes preview and dependent controls on clear and restores them with their exact settings on undo', function () {
    [$root, $path, $session] = createNpcSheetAuthoringProject();
    $sheet = getNpcSheetSettingRow($session, 'sheet');
    $indexKey = getNpcSheetSettingRow($session, 'index')['key'];
    $layerKey = getNpcSheetSettingRow($session, 'layer')['key'];
    setNpcSheetSetting($session, 'sheet', '');
    expect(getNpcSheetSettingRow($session, 'sheet'))->not->toHaveKey('imagePreview')
        ->and(getNpcSheetSettingRow($session, 'index'))->toBeNull()
        ->and(getNpcSheetSettingRow($session, 'layer'))->toBeNull();
    $source = sourceHashTree($root);
    foreach ([$indexKey, $layerKey] as $key) {
        expect(fn() => $session->applyNpc('test-map', $session->readMap('test-map')['revision'], 0, $key, '0'))
            ->toThrow(SessionRefusal::class);
    }
    expect(sourceHashTree($root))->toBe($source);
    $session->undo();
    expect(getNpcSheetSettingRow($session, 'sheet'))->toBe($sheet)
        ->and(getNpcSheetSettingRow($session, 'index')['value'])->toBe('2')
        ->and(getNpcSheetSettingRow($session, 'layer')['value'])->toBe('100');
    $session->redo();
    $session->saveMap('test-map');
    expect((require $path)['npcs'][0])->not->toHaveKey('sprites2d');
    $session->undo();
    $session->saveMap('test-map');
    expect(getNpcSheetSettingRow(EditorSession::open($root), 'sheet'))->toBe($sheet);
});

it('constrains single-character selection and refuses invalid values without touching source state or history', function (string $asset, int $index, string $field, string $value) {
    [$root, $path, $session] = createNpcSheetAuthoringProject($asset, $index);
    $before = $session->readNpc('test-map', 0);
    $files = sourceHashTree($root);
    expect(getNpcSheetSettingRow($session, 'index')['options'])->toBe($asset === '$Guard.png' ? ['0'] : ['0', '1', '2', '3', '4', '5', '6', '7'])
        ->and(fn() => setNpcSheetSetting($session, $field, $value))->toThrow(SessionRefusal::class)
        ->and($session->readNpc('test-map', 0))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([])
        ->and($session->undo()['label'])->toBeNull()
        ->and(sourceHashTree($root))->toBe($files);
})->with([
    ['$Guard.png', 0, 'index', '1'], ['People.png', 2, 'index', '8'], ['People.png', 2, 'index', '-1'],
    ['People.png', 2, 'index', '1.5'], ['People.png', 2, 'index', ''],
    ['People.png', 2, 'layer', '-1'], ['People.png', 2, 'layer', '1000'],
    ['People.png', 2, 'layer', '2147483648'], ['People.png', 2, 'layer', 'no'],
]);

it('never silently resets the current index or layer when replacing a sheet', function () {
    [$root, $path, $session] = createNpcSheetAuthoringProject();
    $before = $session->readNpc('test-map', 0);
    expect(fn() => setNpcSheetSetting($session, 'sheet', 'Graphics/Characters/$Guard.png'))->toThrow(SessionRefusal::class)
        ->and($session->readNpc('test-map', 0))->toBe($before);
    setNpcSheetSetting($session, 'index', '0');
    setNpcSheetSetting($session, 'sheet', 'Graphics/Characters/$Guard.png');
    expect(getNpcSheetSettingRow($session, 'index')['options'])->toBe(['0'])
        ->and(getNpcSheetSettingRow($session, 'layer')['value'])->toBe('100');
});

it('reads fresh current sheet dimensions and reports unavailable previews without mutating NPC data', function (string $replacement) {
    [$root, $path, $session] = createNpcSheetAuthoringProject();
    $original = getNpcSheetSettingRow($session, 'sheet');
    $npc = $session->readNpc('test-map', 0)['npc'];
    $png = $root . '/assets/Graphics/Characters/People.png';
    if ($replacement === 'valid') {
        writeTilesetTestPng($png, 96, 64);
    } elseif ($replacement === 'uneven') {
        writeTilesetTestPng($png, 50, 50);
    } elseif ($replacement === 'malformed') {
        file_put_contents($png, 'not a PNG');
    } else {
        unlink($png);
    }
    $files = sourceHashTree($root);
    $preview = getNpcSheetSettingRow($session, 'sheet')['imagePreview'];
    if ($replacement === 'valid') {
        expect($preview)->not->toHaveKey('issue')
            ->and($preview['frames'][0]['sourceRect'])->toBe(['x' => 56, 'y' => 0, 'width' => 8, 'height' => 8]);
    } else {
        expect($preview['frames'])->toBe([])->and($preview['issue'])->not->toBeEmpty();
        expect(fn() => setNpcSheetSetting($session, 'layer', '101'))->toThrow(SessionRefusal::class);
    }
    expect($session->readNpc('test-map', 0)['npc'])->toBe($npc)
        ->and(getNpcSheetSettingRow($session, 'index')['value'])->toBe('2')
        ->and(getNpcSheetSettingRow($session, 'sheet')['value'])->toBe($original['value'])
        ->and($session->undo()['label'])->toBeNull()->and(sourceHashTree($root))->toBe($files);
})->with(['valid', 'uneven', 'malformed', 'missing']);

it('refuses stale revisions stale external source and uneditable sheet source before mutation', function (string $mode) {
    [$root, $path, $session] = createNpcSheetAuthoringProject();
    $key = getNpcSheetSettingRow($session, 'index')['key'];
    $revision = $session->readMap('test-map')['revision'];
    if ($mode === 'revision') {
        setNpcSheetSetting($session, 'layer', '101');
    } elseif ($mode === 'external') {
        file_put_contents($path, file_get_contents($path) . "\n// Other author.\n");
    } else {
        file_put_contents($path, str_replace("'index' => 2", "'index' => abs(2)", file_get_contents($path)));
        $session = EditorSession::open($root);
        $revision = $session->readMap('test-map')['revision'];
    }
    $files = sourceHashTree($root);
    expect(fn() => $session->applyNpc('test-map', $revision, 0, $key, '6'))->toThrow(SessionRefusal::class)
        ->and(sourceHashTree($root))->toBe($files);
    if ($mode !== 'external') {
        expect(getNpcSheetSettingRow($session, 'index')['value'])->toBe('2');
    }
    expect($session->undo()['label'])->toBe($mode === 'revision' ? 'NPC field sheet layer' : null);
})->with(['revision', 'external', 'expression']);
