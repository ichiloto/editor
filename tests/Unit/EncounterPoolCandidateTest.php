<?php
use Ichiloto\Editor\Field\MapEncounters;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\{ProjectValidator, Severity};

it('retains shared encounter settings when the last unscoped troop is removed', function () {
    $pools = [['area' => 'yard', 'troops' => ['Yard Rats' => 5]]];
    $source = MapEncounters::of(['troops' => ['Wanderer' => 1], 'pools' => $pools, 'rate' => 28, 'tiles' => 'any',
        'battleArena' => 'arena.map', 'battleArenas' => [['area' => 'yard', 'battleArena' => 'arena.yard']]]);
    $withoutFlat = $source->withTroopRemovedAt(0);
    expect($withoutFlat)->not->toHaveKey('troops')
        ->and($withoutFlat['pools'])->toBe($pools)
        ->and($withoutFlat['rate'])->toBe(28)
        ->and($withoutFlat['tiles'])->toBe('any')
        ->and($withoutFlat['battleArena'])->toBe('arena.map')
        ->and($withoutFlat['battleArenas'])->toBe([['area' => 'yard', 'battleArena' => 'arena.yard']])
        ->and(MapEncounters::of($withoutFlat)->withRate(31)['rate'])->toBe(31);
});

it('tracks an encounter pool as an area dependency and preserves it on a real save', function () {
    [, $file, $session] = createLandmarkProject(<<<'MAP'
'areas' => [['id' => 'yard', 'area' => ['x' => 0, 'y' => 1, 'width' => 2, 'height' => 1]]],
'encounters' => ['pools' => [['area' => 'yard', 'troops' => ['Yard Rats' => 5]]], 'rate' => 28],
MAP);
    expect($session->readAreas('test-map')['areas'][0]['references'])->toBe(['test-map encounter pool 1'])
        ->and(fn() => $session->deleteArea('test-map', $session->readMap('test-map')['revision'], 'yard'))->toThrow(SessionRefusal::class, 'encounter pool 1');
    $session->renameArea('test-map', $session->readMap('test-map')['revision'], 'yard', 'court');
    applyLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'rate'], '31');
    $session->saveMap('test-map');
    $saved = require $file;
    expect($saved['areas'][0]['id'])->toBe('court')
        ->and($saved['encounters'])->toBe(['pools' => [['area' => 'court', 'troops' => ['Yard Rats' => 5]]], 'rate' => 31]);
    $session->undo();
    $session->undo();
    expect(getSessionMap($session)->getMapDataField(['encounters', 'pools', 0, 'area']))->toBe('yard');
});

it('reports invalid scoped references through the normal map validator', function () {
    [$root] = createLandmarkProject("'encounters' => ['pools' => [['area' => 'missing', 'troops' => ['Rats' => 1]]]],");
    $errors = array_column(array_filter(new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn($issue): bool => $issue->severity === Severity::ERROR && $issue->where === 'test-map'), 'message');
    expect($errors)->toContain('Encounter pool 1: area must name a declared map area.');
});

it('adds, edits, orders and removes pools and troops through the shared inspector', function () {
    [$root, $file] = createLandmarkProject(<<<'MAP'
'areas' => [
  ['id' => 'yard', 'area' => ['x' => 0, 'y' => 1, 'width' => 2, 'height' => 1]],
  ['id' => 'lane', 'area' => ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 1]],
],
'encounters' => ['troops' => ['Rats' => 2], 'rate' => 28],
MAP);
    $data = $root . '/assets/Data';
    if (!is_dir($data)) { mkdir($data, 0700, true); }
    file_put_contents($data . '/troops.php', "<?php return [['name' => 'Rats', 'enemies' => []], ['name' => 'Bats', 'enemies' => []]];");
    $session = \Ichiloto\Editor\Session\EditorSession::open($root);
    $key = static fn(array $fields): array => ['target' => 'map-encounter-pools', ...$fields];
    $revision = static fn(): int => $session->readMap('test-map')['revision'];
    $add = static fn(array $fields): array => $session->addInspectorListEntry('test-map', $revision(), findLandmarkRow($session, $key($fields))['key']);
    $remove = static fn(array $fields): array => $session->removeInspectorListEntry('test-map', $revision(), findLandmarkRow($session, $key($fields))['key']);
    $apply = static fn(array $fields, string $value): array => applyLandmarkRow($session, $key($fields), $value);

    $add(['field' => 'pools']);
    $add(['field' => 'pools']);
    expect(findLandmarkRow($session, $key(['field' => 'area', 'index' => 1]))['options'])->toContain('yard', 'lane');
    $apply(['field' => 'area', 'index' => 1], 'lane');
    $add(['field' => 'troops', 'index' => 1]);
    $apply(['field' => 'weight', 'index' => 1, 'path' => ['Bats']], '7');
    expect(fn() => $apply(['field' => 'troop', 'index' => 1, 'path' => ['Bats']], 'Rats'))
        ->toThrow(SessionRefusal::class, 'does not already appear');
    expect(fn() => $apply(['field' => 'area', 'index' => 1], '(whole map)'))
        ->toThrow(SessionRefusal::class, 'needs an area or an encounter terrain');
    $apply(['field' => 'order', 'index' => 1], '1');
    $session->saveMap('test-map');
    expect((require $file)['encounters'])->toBe([
        'troops' => ['Rats' => 2], 'rate' => 28, 'pools' => [
            ['area' => 'lane', 'troops' => ['Rats' => 1, 'Bats' => 7]],
            ['area' => 'yard', 'troops' => ['Rats' => 1]],
        ],
    ]);
    $remove(['field' => 'troop', 'index' => 0, 'path' => ['Bats']]);
    $remove(['field' => 'order', 'index' => 1]);
    $session->saveMap('test-map');
    expect((require $file)['encounters']['pools'])->toBe([['area' => 'lane', 'troops' => ['Rats' => 1]]]);
});

it('authors a terrain-only pool with a graphical arena and preserves it in terminal inspection', function () {
    $root = terrainKindProject();
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = (string) file_get_contents($file);
    file_put_contents($file, str_replace("'tileset' => 'home',",
        "'tileset' => 'home', 'terrainKinds' => true, 'encounters' => ['troops' => ['Pair' => 2], 'rate' => 12],", $source));
    $battleProject = troopFormationProject();
    $presentation = '/assets/Data/Presentation';
    mkdir($root . $presentation, 0700, true);
    copy($battleProject . $presentation . '/battle.php', $root . $presentation . '/battle.php');
    copy($battleProject . '/assets/Data/troops.php', $root . '/assets/Data/troops.php');
    $session = \Ichiloto\Editor\Session\EditorSession::open($root);
    $key = static fn(array $fields): array => ['target' => 'map-encounter-pools', ...$fields];
    $read = $session->readInspector('test-map');
    $session->addInspectorListEntry('test-map', $read['revision'], findLandmarkRow($session, $key(['field' => 'pools']))['key']);
    expect(findLandmarkRow($session, $key(['field' => 'terrain', 'index' => 0]))['options'])->toContain('meadow', 'grove');
    applyLandmarkRow($session, $key(['field' => 'terrain', 'index' => 0]), 'grove');
    $arena = findLandmarkRow($session, $key(['field' => 'arena', 'index' => 0]));
    $name = $arena['key']['path'][0];
    applyLandmarkRow($session, $key(['field' => 'arena', 'index' => 0, 'path' => [$name]]), 'arena.cave');
    $session->saveMap('test-map');
    expect((require $file)['encounters']['pools'])->toBe([[
        'terrain' => 'grove', 'troops' => [$name => ['weight' => 1, 'battleArena' => 'arena.cave']],
    ]]);

    $workspace = ProjectWorkspace::fromProject($root);
    $map = $workspace->maps[0];
    $terminal = new \Ichiloto\Editor\Inspector\MapInspector(new \Ichiloto\Editor\Database\ReferenceCatalog($workspace, $map));
    $fields = array_filter($terminal->getMapFields($map), static fn(array $field): bool => ($field['target'] ?? null) === 'map-encounter-pools');
    expect(array_column($fields, 'field'))->not->toContain('arena');
    $weight = array_find($fields, static fn(array $field): bool => $field['field'] === 'weight');
    $terminal->apply($map, $weight, '6');
    expect($map->getMapDataField(['encounters', 'pools', 0, 'troops', $name]))->toBe(['weight' => 6, 'battleArena' => 'arena.cave']);
});

it('reports only unused pools by their original authored priority', function () {
    [$root] = createLandmarkProject(<<<'MAP'
'occupancy' => ['packedRows' => ['0010', '0000']],
'areas' => [
  ['id' => 'first', 'area' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]],
  ['id' => 'second', 'area' => ['x' => 0, 'y' => 0, 'width' => 3, 'height' => 1]],
],
'encounters' => ['tiles' => 'any', 'pools' => [
  ['area' => 'missing', 'troops' => ['Rats' => 1]],
  ['area' => 'first', 'troops' => ['Rats' => 1]],
  ['area' => 'first', 'troops' => ['Bats' => 1]],
  ['area' => 'second', 'troops' => ['Rats' => 1]],
]],
MAP);
    $session = \Ichiloto\Editor\Session\EditorSession::open($root);
    $warnings = array_column(array_filter($session->validateProject(['test-map'])['issues'],
        static fn(array $issue): bool => $issue['severity'] === 'warning'
            && str_contains($issue['message'], 'supplies no encounter-eligible cell')), 'message');
    expect($warnings)->toBe(['Encounter pool 3 supplies no encounter-eligible cell.']);
});

it('counts only traversable cells enabled by the encounter tile mode', function (string $mode, array $unused) {
    $areas = \Ichiloto\Engine\Field\MapAreaCatalog::readMap(['areas' => [
        ['id' => 'floor', 'area' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]],
        ['id' => 'danger', 'area' => ['x' => 1, 'y' => 0, 'width' => 1, 'height' => 1]],
        ['id' => 'wall', 'area' => ['x' => 2, 'y' => 0, 'width' => 1, 'height' => 1]],
    ]], [[0, 0, 0]]);
    $table = \Ichiloto\Engine\Field\EncounterTable::readBlock(['tiles' => $mode, 'pools' => array_map(
        static fn(string $area): array => ['area' => $area, 'troops' => ['Rats' => 1]], ['floor', 'danger', 'wall'])], $areas);
    $tile = \Ichiloto\Engine\Events\Enumerations\CollisionType::class;
    expect($table->findUnusedPoolNumbers([[$tile::NONE->value, $tile::ENCOUNTER->value, $tile::SOLID->value]]))->toBe($unused);
})->with([
    'danger tiles only' => ['encounter', [1, 3]],
    'any traversable tile' => ['any', [3]],
]);
