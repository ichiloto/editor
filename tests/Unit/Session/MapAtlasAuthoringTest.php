<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\MapCategoryFields;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\MapValidator;
use Ichiloto\Engine\Field\MapCategoryCatalog;
use Ichiloto\Engine\Field\MapLandmarkCategory;

/** The atlas row editing this field on a map, read fresh. */
function findAtlasRow($session, string $field, string $mapId = 'test-map'): array
{
    return findLandmarkRow($session, ['target' => 'map-atlas', 'field' => $field], null, $mapId);
}

it('places a local map under the global map by an entry chosen from its landmarks, as one undo step', function () {
    [, $file, $session] = createAtlasProject();
    $before = file_get_contents($file);
    $scope = findAtlasRow($session, 'scope');
    expect($scope['value'])->toBe('local')->and($scope['options'])->toBe(['local', 'global']);
    $parent = findAtlasRow($session, 'parent');
    // The world's gate leads here, so it is offered first and said so.
    expect($parent['options'])->toBe(['', 'world#town-gate'])
        ->and($parent['optionLabels'][1])->toBe('World · town-gate');

    applyLandmarkRow($session, ['target' => 'map-atlas', 'field' => 'parent'], 'world#town-gate');
    expect(getSessionMap($session)->getMapDataField(['atlas']))->toBe(['parent' => ['map' => 'world', 'landmark' => 'town-gate']])
        ->and(file_get_contents($file))->toBe($before);
    $global = array_find($session->readInspector('test-map')['rows'], static fn(array $row): bool => $row['label'] === 'Global Map');
    expect($global['value'])->toBe('world at town-gate');
    $session->undo();
    expect(getSessionMap($session)->hasMapDataField(['atlas']))->toBeFalse();
    $session->redo();
    $session->saveMap('test-map');
    expect(file_get_contents($file))->toContain('// Map-owned presentation stays authored.', "'atlas' =>", "'town-gate'");

    // Becoming global drops the parent; becoming local again says nothing, as a map that never chose.
    applyLandmarkRow($session, ['target' => 'map-atlas', 'field' => 'scope'], 'global');
    expect(getSessionMap($session)->getMapDataField(['atlas']))->toBe(['scope' => 'global']);
    applyLandmarkRow($session, ['target' => 'map-atlas', 'field' => 'scope'], 'local');
    expect(getSessionMap($session)->hasMapDataField(['atlas']))->toBeFalse();
});

it('refuses a parent chain the Engine would refuse, and keeps entries other maps name', function () {
    [, , $session] = createAtlasProject();
    applyLandmarkRow($session, ['target' => 'map-atlas', 'field' => 'parent'], 'world#town-gate');
    applyLandmarkRow($session, ['target' => 'event-landmark', 'marker' => 'T'], 'passage', 'T');
    applyLandmarkRow($session, ['target' => 'map-atlas', 'field' => 'scope'], 'local', null, 'world');
    expect(fn() => applyLandmarkRow($session, ['target' => 'map-atlas', 'field' => 'parent'], 'test-map#landmark-1', null, 'world'))
        ->toThrow(SessionRefusal::class, 'cycle');
    applyLandmarkRow($session, ['target' => 'map-atlas', 'field' => 'scope'], 'global', null, 'world');

    // The world's gate is test-map's entry: it stays until test-map chooses another.
    expect(fn() => applyLandmarkRow($session, ['target' => 'event-landmark', 'marker' => 'T'], 'undescribed', 'T', 'world'))
        ->toThrow(SessionRefusal::class, 'the atlas parent of test-map (entry town-gate)');
    $heading = findLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'kind', 'index' => 0], null, 'world');
    expect(fn() => $session->removeInspectorListEntry('world', $session->readMap('world')['revision'], $heading['key']))
        ->toThrow(SessionRefusal::class, 'the atlas parent of test-map');
    expect($session->deleteMap('world')['references'])->toContain('the atlas parent of test-map (entry town-gate)');

    applyLandmarkRow($session, ['target' => 'map-atlas', 'field' => 'parent'], '');
    $session->removeInspectorListEntry('world', $session->readMap('world')['revision'], $heading['key']);
    expect(readLandmarkEntries($session, 'world'))->toBe([]);
});

it('reports an atlas whose parent no longer exists', function () {
    [, , $session] = createAtlasProject("'atlas' => ['parent' => ['map' => 'world', 'landmark' => 'old-gate']],");
    $map = getSessionMap($session);
    $problems = MapValidator::validate($map, ['test-map' => $map, 'world' => getSessionMap($session, 'world')], mapValidatorInventory());
    expect($problems)->toContain('Atlas: Atlas parent landmark does not exist on its referenced map.');
    expect(findAtlasRow($session, 'parent')['optionLabels'])->toContain('world#old-gate · no longer declared');
});

it('chooses a landmark category from the Engine generics and the project\'s own, kept while used', function () {
    [$root, , $session] = createAtlasProject();
    applyLandmarkRow($session, ['target' => 'event-landmark', 'marker' => 'E'], 'building', 'E');
    $category = findLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'category', 'index' => 0]);
    expect($category['options'])->toBe(['', ...array_column(MapLandmarkCategory::cases(), 'value')]);
    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'category', 'index' => 0], 'inn');
    expect(readLandmarkEntries($session)[0]['category'])->toBe('inn');

    // A project category, created on its own page and usable before it is saved.
    $index = $session->createDatabaseRecord(MapCategoryFields::CATEGORY)['index'];
    $setCategory = static function (string $field, string $value) use ($session, $index): void {
        $row = array_find($session->readDatabaseRecord(MapCategoryFields::CATEGORY, $index)['rows'],
            static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
        $session->applyDatabaseRecord(MapCategoryFields::CATEGORY, $index, $row['key'], $value);
    };
    expect(fn() => $setCategory('id', 'inn'))->toThrow(SessionRefusal::class, 'not inn, shop, home or organization');
    $setCategory('id', 'bard-school');
    $setCategory('name.default', 'Bard School');
    $setCategory('base', 'organization');
    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'category', 'index' => 0], 'bard-school');
    expect(readLandmarkEntries($session)[0]['category'])->toBe('bard-school');
    expect(fn() => $session->deleteDatabaseRecord(MapCategoryFields::CATEGORY, $index))
        ->toThrow(SessionRefusal::class, 'landmark landmark-1 on test-map');
    expect(fn() => $setCategory('id', 'music-school'))->toThrow(SessionRefusal::class, 'its id stays');

    $session->saveDatabase(MapCategoryFields::CATEGORY);
    $catalog = MapCategoryCatalog::load($root . '/assets');
    expect($catalog->getIds())->toContain('bard-school')
        ->and($catalog->getBase('bard-school'))->toBe(MapLandmarkCategory::ORGANIZATION)
        ->and($catalog->categories['bard-school']['name'])->toBe(['message' => MapCategoryCatalog::getNameMessageKey('bard-school'), 'default' => 'Bard School'])
        ->and((require $root . '/assets/' . MapCategoryCatalog::FILE)['schema'])->toBe(MapCategoryCatalog::SCHEMA);

    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'category', 'index' => 0], '');
    expect(readLandmarkEntries($session)[0])->not->toHaveKey('category');
    $session->deleteDatabaseRecord(MapCategoryFields::CATEGORY, $index);
});

it('chooses how the Terminal draws a map, saying nothing when it is the Engine\'s default for the scope', function () {
    [, , $session] = createAtlasProject();
    $view = findAtlasRow($session, 'terminalView');
    expect($view['options'])->toBe(Ichiloto\Engine\Field\MapAtlasDefinition::TERMINAL_VIEWS)
        ->and($view['value'])->toBe(Ichiloto\Editor\Field\MapAtlas::readTerminalView([]));
    $other = array_values(array_diff(Ichiloto\Engine\Field\MapAtlasDefinition::TERMINAL_VIEWS, [$view['value']]))[0];
    applyLandmarkRow($session, ['target' => 'map-atlas', 'field' => 'terminalView'], $other);
    expect(getSessionMap($session)->getMapDataField(['atlas']))->toBe(['terminalView' => $other]);
    applyLandmarkRow($session, ['target' => 'map-atlas', 'field' => 'terminalView'], $view['value']);
    expect(getSessionMap($session)->hasMapDataField(['atlas']))->toBeFalse();
    // A global map reads the Engine's own default for global maps.
    expect(findAtlasRow($session, 'terminalView', 'world')['value'])->toBe(Ichiloto\Editor\Field\MapAtlas::readTerminalView(['scope' => 'global']));
    $session->undo();
    expect(getSessionMap($session)->getMapDataField(['atlas']))->toBe(['terminalView' => $other]);
});

/** The terrain row editing this field at exactly this layer and glyph path. */
function findTerrainRow($session, string $field, array $path): array
{
    return array_find($session->readInspector('test-map')['rows'], static fn(array $row): bool => ($row['key'] ?? null)
        == ['target' => 'map-terrain', 'field' => $field, 'path' => $path]) ?? throw new RuntimeException('No terrain row ' . json_encode($path));
}

it('classifies a map\'s own layer glyphs as terrain for its atlas, never copying a grid', function () {
    [, $file, $session] = createAtlasProject();
    $terrainRow = static fn(string $field, array $path): array => findTerrainRow($session, $field, $path);
    $apply = static fn(string $field, array $path, string $value): array => $session->applyInspector('test-map',
        $session->readMap('test-map')['revision'], findTerrainRow($session, $field, $path)['key'], $value);

    $dots = $terrainRow('symbol', ['terrain', '.']);
    expect($dots['label'])->toBe("'.' · 8 cells")
        ->and($dots['options'])->toBe(['', ...Ichiloto\Engine\Field\MapAtlasTerrain::ROLES]);
    expect($terrainRow('symbol', ['buildings', ' '])['label'])->toBe('(blank) · 5 cells');

    $apply('symbol', ['terrain', '.'], 'ground');
    $apply('default', ['buildings'], 'building');
    $apply('symbol', ['buildings', '/'], 'boundary');
    expect(getSessionMap($session)->getMapDataField(['atlas', 'terrain']))->toBe([
        'terrain' => ['symbols' => ['.' => 'ground']],
        'buildings' => ['default' => 'building', 'symbols' => ['/' => 'boundary']],
    ]);
    expect(fn() => $apply('symbol', ['terrain', '.'], 'lava'))->toThrow(SessionRefusal::class);

    $session->saveMap('test-map');
    $map = getSessionMap($session);
    $catalog = Ichiloto\Engine\Field\MapAtlasTerrain::readMap(require $file, $map->getLayerSet());
    expect($catalog->getRole(1, 1))->toBe('building')->and($catalog->getRole(1, 0))->toBe('boundary')
        ->and(file_get_contents($file))->toContain('// Map-owned presentation stays authored.');

    // Clearing every classification removes the terrain, then the empty atlas.
    $apply('symbol', ['buildings', '/'], '');
    $apply('default', ['buildings'], '');
    $apply('symbol', ['terrain', '.'], '');
    expect(getSessionMap($session)->hasMapDataField(['atlas']))->toBeFalse();
    $session->undo();
    expect(getSessionMap($session)->getMapDataField(['atlas', 'terrain']))->toBe(['terrain' => ['symbols' => ['.' => 'ground']]]);
});

it('shows terrain bound to a layer the map no longer has, and clears it', function () {
    [, , $session] = createAtlasProject("'atlas' => ['terrain' => ['old' => ['default' => 'water']]],");
    $rows = $session->readInspector('test-map')['rows'];
    expect(array_column(array_filter($rows, static fn(array $row): bool => $row['label'] === '! Problem'), 'value'))
        ->toContain('Atlas terrain references missing source layer old.');
    $session->applyInspector('test-map', $session->readMap('test-map')['revision'], findTerrainRow($session, 'layer', ['old'])['key'], '');
    expect(getSessionMap($session)->hasMapDataField(['atlas']))->toBeFalse();
});
