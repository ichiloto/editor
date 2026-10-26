<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\BattleArenaFields;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Field\MapAreaAuthoring;
use Ichiloto\Editor\Field\MapEncounters;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Random battles drawn where they start: a map's arena rules pick the arena
 * for an area or an encounter terrain, and an arena names the variant drawn
 * for each terrain. Both are graphical presentation, authored in the GUI
 * editor and kept as written by the terminal editor; validation lists every
 * arena and terrain a battle meets without a variant. Synthetic fixtures only
 * ({@see terrainKindProject()}).
 */

/**
 * The kind project, its map painted meadow (`.`) and grove (`♣`) with a path cell, a `glade` area over its
 * right half, wolves met anywhere, and arenas as data: a region arena drawing its grove on the woods.
 *
 * @return array{string, EditorSession}
 */
function createTerrainArenaProject(array $encounters = []): array
{
    $root = terrainKindProject(".♣.=\n♣...");
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    $block = ['troops' => ['Wolves' => 1], 'rate' => 12, 'battleArena' => 'arena.region', ...$encounters];
    file_put_contents($file, str_replace("'tileset' => 'home',", "'tileset' => 'home', 'terrainKinds' => true, 'areas' => "
        . var_export([['id' => 'glade', 'area' => ['x' => 2, 'y' => 0, 'width' => 2, 'height' => 2]]], true)
        . ", 'encounters' => " . var_export($block, true) . ',', (string) file_get_contents($file)));
    @mkdir($root . '/assets/Data/Presentation', 0o777, true);
    file_put_contents($root . '/assets/Data/Presentation/battle.php', <<<'PHP'
<?php
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;

return new BattlePresentationCatalog(arenas: [], actors: [], enemies: [],
    ui: new BattleCanvasLayout(1350, 720, partySlots: [new BattleStageSlot(1050, 180, 180, 260)]));
PHP);
    file_put_contents($root . '/assets/Data/Presentation/arenas.php', <<<'PHP'
<?php

// Each region and what it draws on each terrain.
return [
    'arenas' => [
        'arena.region' => ['name' => 'Region', 'background' => ['image' => 'Graphics/Battlebacks/Region.png'],
            'variants' => ['grove' => 'arena.woods']],
        'arena.woods' => ['name' => 'Woods', 'background' => ['image' => 'Graphics/Battlebacks/Woods.png']],
        'arena.glade' => ['name' => 'Glade', 'background' => ['image' => 'Graphics/Battlebacks/Glade.png']],
    ],
];
PHP);
    foreach (['Region', 'Woods', 'Glade'] as $image) {
        writeTilesetTestPng($root . "/assets/Graphics/Battlebacks/{$image}.png", 8, 8);
    }

    return [$root, EditorSession::open($root)];
}

/** @return list<string> The validation messages of a severity for the test map. */
function terrainArenaIssues(EditorSession $session, string $severity): array
{
    return array_values(array_column(array_filter($session->validateProject(['test-map'])['issues'],
        static fn(array $issue): bool => $issue['severity'] === $severity), 'message'));
}

it('reads and writes a map\'s arena rules, keeping what each carries and following an area\'s rename', function () {
    $encounters = MapEncounters::of(['troops' => ['Wolves' => 1], 'battleArenas' => [
        ['area' => 'glade', 'battleArena' => 'arena.glade', 'note' => 'kept'],
        ['terrain' => 'grove', 'battleArena' => 'arena.woods'],
    ]]);

    expect($encounters->arenaRules())->toHaveCount(2)
        ->and($encounters->arenaRulesInArea('glade'))->toBe([0])
        ->and($encounters->withArenaRuleAt(0, 'terrain', 'meadow')['battleArenas'][0])
        ->toBe(['area' => 'glade', 'battleArena' => 'arena.glade', 'note' => 'kept', 'terrain' => 'meadow'])
        ->and($encounters->withAreaRenamed('glade', 'clearing')['battleArenas'][0]['area'])->toBe('clearing')
        ->and($encounters->withArenaRuleAdded(null, 'meadow', 'arena.region', 0)['battleArenas'][1])
        ->toBe(['terrain' => 'meadow', 'battleArena' => 'arena.region'])
        ->and(MapEncounters::of(['troops' => ['Wolves' => 1], 'battleArenas' => [['battleArena' => 'arena.glade']]])
            ->withArenaRuleRemovedAt(0))->not->toHaveKey('battleArenas')
        ->and(MapEncounters::of(['troops' => ['Wolves' => 1], 'battleArenas' => ['terrain' => 'grove']])->unsupportedReason())
        ->toContain('battleArenas are not a list');
});

it('authors arena rules in the graphical inspector from the map\'s own areas and terrains', function () {
    [$root, $session] = createTerrainArenaProject();
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    $heading = findLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'arenaRules']);
    $session->addInspectorListEntry('test-map', $session->readMap('test-map')['revision'], $heading['key']);

    expect(findLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'ruleTerrain', 'index' => 0]))
        ->toMatchArray(['value' => 'meadow', 'options' => ['(any terrain)', 'meadow', 'grove']])
        ->and(findLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'ruleArea', 'index' => 0]))
        ->toMatchArray(['value' => '(whole map)', 'options' => ['(whole map)', 'glade']]);

    applyLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'ruleArea', 'index' => 0], 'glade');
    applyLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'ruleTerrain', 'index' => 0], '(any terrain)');
    applyLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'ruleArena', 'index' => 0], 'arena.glade');

    expect(fn() => applyLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'ruleArea', 'index' => 0], '(whole map)'))
        ->toThrow(SessionRefusal::class, 'an area, a terrain or both')
        ->and(fn() => applyLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'ruleTerrain', 'index' => 0], 'swamp'))
        ->toThrow(SessionRefusal::class, 'swamp');
    $session->saveMap('test-map');

    expect((require $file)['encounters']['battleArenas'])->toEqual([['area' => 'glade', 'battleArena' => 'arena.glade']])
        ->and(fn() => new MapAreaAuthoring(ProjectWorkspace::fromProject($root)->maps[0])->deleteArea('glade'))
        ->toThrow(\Ichiloto\Editor\Field\MapAreaRefusal::class, 'test-map battle arena rule 1');
});

it('keeps arena rules in the terminal editor without offering them', function () {
    [$root] = createTerrainArenaProject(['battleArenas' => [['terrain' => 'grove', 'battleArena' => 'arena.woods']]]);
    $workspace = ProjectWorkspace::fromProject($root);
    $map = $workspace->maps[0];
    $terminal = new MapInspector(new ReferenceCatalog($workspace, $map));
    $fields = $terminal->getMapFields($map);

    expect(array_filter($fields, static fn(array $field): bool => str_starts_with((string) ($field['field'] ?? ''), 'rule')))->toBe([]);
    $terminal->apply($map, array_find($fields, static fn(array $field): bool => ($field['field'] ?? null) === 'weight'), '3');

    expect($map->getMapDataField(['encounters', 'battleArenas']))->toBe([['terrain' => 'grove', 'battleArena' => 'arena.woods']]);
});

it('authors an arena\'s terrain variants from the project\'s encounter terrains and arenas', function () {
    [$root, $session] = createTerrainArenaProject();
    $path = $root . '/assets/Data/Presentation/arenas.php';
    $row = static fn(string $field): array => array_find($session->readDatabaseRecord(BattleArenaFields::CATEGORY, 0)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field) ?? throw new RuntimeException("No row {$field}.");

    expect($session->listReferences('test-map', ReferenceCatalog::ENCOUNTER_TERRAINS))
        ->toBe([['value' => 'meadow', 'label' => 'meadow'], ['value' => 'grove', 'label' => 'grove']])
        ->and($row('terrainVariant0Terrain')['value'])->toBe('grove');

    $session->addDatabaseItem(BattleArenaFields::CATEGORY, 0, $row('terrainVariant0Terrain')['key']);
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, $row('terrainVariant1Terrain')['key'], 'meadow');
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, $row('terrainVariant1Arena')['key'], 'arena.region');
    $session->saveDatabase(BattleArenaFields::CATEGORY);

    expect((require $path)['arenas']['arena.region']['variants'])->toBe(['grove' => 'arena.woods', 'meadow' => 'arena.region'])
        ->and((string) file_get_contents($path))->toContain('// Each region and what it draws on each terrain.');

    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, $row('terrainVariant1Arena')['key'], 'arena.gone');
    expect(fn() => $session->saveDatabase(BattleArenaFields::CATEGORY))->toThrow(SessionRefusal::class);
});

it('lists every arena and terrain a map\'s battles meet without a variant, and stops once art covers it', function () {
    [$root] = createTerrainArenaProject(['battleArenas' => [['area' => 'glade', 'battleArena' => 'arena.glade']]]);
    $warnings = static fn(): string => implode("\n", terrainArenaIssues(EditorSession::open($root), 'warning'));

    // The fixture's buildings wall off two cells; the region draws its grove on the woods.
    expect($warnings())->toContain('Battles on the arena "arena.region" start on meadow (map, 1 cell)')
        ->toContain('Battles on the arena "arena.glade" start on meadow (area glade, 2 cells)')
        ->not->toContain('start on grove');

    // The region's own picture is its meadow, so it names itself.
    $path = $root . '/assets/Data/Presentation/arenas.php';
    file_put_contents($path, str_replace("'variants' => ['grove' => 'arena.woods']", "'variants' => ['grove' => 'arena.woods', 'meadow' => 'arena.region']",
        (string) file_get_contents($path)));

    expect($warnings())->not->toContain('"arena.region" start on')
        ->toContain('Battles on the arena "arena.glade" start on meadow');
});

it('reports arena rules the Engine leaves out', function () {
    [, $session] = createTerrainArenaProject(['battleArenas' => [
        ['area' => 'nowhere', 'battleArena' => 'arena.glade'],
        ['terrain' => 'grove', 'battleArena' => 'arena.moon'],
    ]]);
    $errors = implode("\n", terrainArenaIssues($session, 'error'));

    expect($errors)->toContain('Battle arena rule 1 names map area "nowhere", which the map does not declare')
        ->toContain('Battle arena rule 2 names the arena "arena.moon", which the battle presentation does not declare.');
});
