<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\PlaceTypeFields;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\PlaceTypeCatalog;
use Ichiloto\Engine\Field\Validation\MapPlaceIconRule;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

/**
 * A synthetic world: test-map (4 x 2, tileset home) whose entrance E at 1,1 leads into the map `site`, under a 2x2
 * walled settlement stamped at 0,0 whose way in is its bottom-right cell; the tileset also has a 1x2 hut whose way
 * in is its bottom cell. No place types or icons are authored yet.
 */
function createPlaceIconProject(array $icons = []): string
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: [
        'walls' => ['name' => 'Walls', 'layer' => 'buildings', 'glyphs' => ['#^', '#∩'], 'tiles' => ['decor' => ['1 2', '9 10']]],
        'hut' => ['name' => 'Hut', 'layer' => 'buildings', 'glyphs' => ['^', '∩'], 'tiles' => ['decor' => ['3', '11']]],
    ]);
    if ($icons !== []) {
        $path = $root . '/assets/Data/Tilesets/home.php';
        $data = require $path;
        file_put_contents($path, "<?php\n\nreturn " . var_export($data + [Tileset::PLACE_ICONS => $icons], true) . ";\n");
    }
    $maps = $root . '/assets/Maps';
    copyDirectoryRecursively($maps . '/test-map', $maps . '/site');
    rename($maps . '/site/test-map.data.php', $maps . '/site/site.data.php');
    rename($maps . '/site/test-map.event.php', $maps . '/site/site.event.php');
    file_put_contents($maps . '/site/site.data.php', str_replace("'name' => 'Test Map'", "'name' => 'Site'",
        (string) file_get_contents($maps . '/site/site.data.php')));
    $world = $maps . '/test-map';
    file_put_contents($world . '/layers/04.buildings.map.php', MapGridSource::buildSource("#^  \n#∩  ", 'AUTHORED'));
    writeTileLayer($world, '02.decor.tiles.php', "1 2 0 0\n9 10 0 0");
    file_put_contents($world . '/test-map.event.php', MapGridSource::buildSource("    \n E  ", 'EVENTS'));
    file_put_contents($world . '/test-map.data.php', str_replace("'events' => [],", <<<'PHP'
'events' => [
        'E' => ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger',
            'data' => ['destinationMap' => 'site', 'spawnPoint' => ['x' => 1, 'y' => 0]]],
    ],
PHP, (string) file_get_contents($world . '/test-map.data.php')));

    return $root;
}

/** Makes a place type on the Place Types page; returns its index. */
function createPlaceType(EditorSession $session, string $name): int
{
    $index = $session->createDatabaseRecord(PlaceTypeFields::CATEGORY)['index'];
    foreach (['id' => strtolower($name), 'name.default' => $name] as $field => $value) {
        $row = array_find($session->readDatabaseRecord(PlaceTypeFields::CATEGORY, $index)['rows'],
            static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
        $session->applyDatabaseRecord(PlaceTypeFields::CATEGORY, $index, $row['key'], $value);
    }

    return $index;
}

function setPlaceType(EditorSession $session, string $mapId, string $type): array
{
    return applyLandmarkRow($session, ['target' => MapInspector::PLACE_TYPE_TARGET], $type, null, $mapId);
}

/** @return list<array<string, mixed>> The project's issues with this code. */
function findPlaceIssues(EditorSession $session, string $code): array
{
    return array_values(array_filter($session->validateProject()['issues'], static fn(array $issue): bool => $issue['code'] === $code));
}

/** @return array<string, array<string, mixed>> The tileset record's rows by field id. */
function readPlaceIconRows(EditorSession $session): array
{
    $rows = [];
    foreach ($session->readDatabaseRecord('tilesets', 0)['rows'] as $row) {
        $rows[is_string($row['key']['field'] ?? null) ? $row['key']['field'] : json_encode($row['key'] ?? null)] = $row;
    }

    return $rows;
}

it('chooses a map\'s place type from the project\'s types, refusing one it does not have, as one undo step', function () {
    $root = createPlaceIconProject();
    $session = EditorSession::open($root);
    createPlaceType($session, 'Outpost');
    $row = findLandmarkRow($session, ['target' => MapInspector::PLACE_TYPE_TARGET], null, 'site');
    expect($row['value'])->toBe(MapInspector::PLACE_TYPE_NONE)
        ->and($row['label'])->toBe('Place Type')
        ->and($row['reference'])->toBe(PlaceTypeFields::CATEGORY);

    expect(fn() => setPlaceType($session, 'site', 'castle'))->toThrow(SessionRefusal::class, 'castle is not a place type');
    setPlaceType($session, 'site', 'outpost');
    expect(getSessionMap($session, 'site')->getMapDataField([PlaceTypeCatalog::MAP_KEY]))->toBe('outpost')
        ->and(findLandmarkRow($session, ['target' => MapInspector::PLACE_TYPE_TARGET], null, 'site')['value'])->toBe('Outpost (outpost)');
    $session->undo();
    expect(getSessionMap($session, 'site')->hasMapDataField([PlaceTypeCatalog::MAP_KEY]))->toBeFalse();
    $session->redo();

    // A type stays while a map is of it.
    expect(fn() => $session->deleteDatabaseRecord(PlaceTypeFields::CATEGORY, 0))->toThrow(SessionRefusal::class, 'map site');
    $session->saveDatabase(PlaceTypeFields::CATEGORY);
    expect(PlaceTypeCatalog::load($root . '/assets')->getName('outpost'))->toBe('Outpost');
});

it('gives a place type its icon in Database > Tilesets, choosing among the tileset\'s whole pieces', function () {
    $root = createPlaceIconProject();
    $session = EditorSession::open($root);
    createPlaceType($session, 'Outpost');
    $session->saveDatabase(PlaceTypeFields::CATEGORY);

    $heading = array_find($session->readDatabaseRecord('tilesets', 0)['rows'],
        static fn(array $row): bool => ($row['label'] ?? null) === 'Place Icons' || str_starts_with((string) ($row['label'] ?? ''), 'Place Icons'));
    expect($heading)->not->toBeNull();
    $session->addDatabaseItem('tilesets', 0, $heading['key']);
    $rows = readPlaceIconRows($session);
    expect($rows['placeIcon0Piece']['options'])->toBe(['', 'walls', 'hut'])
        ->and($rows['placeIcon0Type']['reference'])->toBe(PlaceTypeFields::CATEGORY);
    $session->applyDatabaseRecord('tilesets', 0, $rows['placeIcon0Type']['key'], 'outpost');
    $session->applyDatabaseRecord('tilesets', 0, readPlaceIconRows($session)['placeIcon0Piece']['key'], 'hut');
    $session->saveDatabase('tilesets');

    expect(Tileset::load($root . '/assets', 'home')->placeIcons)->toBe(['outpost' => 'hut']);
    // A type stays while a tileset gives it an icon.
    expect(fn() => $session->deleteDatabaseRecord(PlaceTypeFields::CATEGORY, 0))->toThrow(SessionRefusal::class, 'tileset home');
});

it('warns where an entrance\'s icon is not its place type\'s, and follows the type as it changes', function () {
    $session = EditorSession::open(createPlaceIconProject(['town' => 'walls', 'outpost' => 'hut']));
    createPlaceType($session, 'Town');
    createPlaceType($session, 'Outpost');

    // A walled settlement over the way into a single outpost.
    setPlaceType($session, 'site', 'outpost');
    $wrong = findPlaceIssues($session, MapPlaceIconRule::ICON);
    expect($wrong)->toHaveCount(1)
        ->and($wrong[0]['severity'])->toBe('warning')
        ->and($wrong[0]['map'])->toBe('test-map')
        ->and($wrong[0]['cells'])->toBe([[1, 1]])
        ->and($wrong[0]['message'])->toBe("Entrance 'E' leads to site, a place of type Outpost, whose icon is Hut; the Walls stands over it.");

    // The same icon over the way into a town shows its type.
    setPlaceType($session, 'site', 'town');
    expect(findPlaceIssues($session, MapPlaceIconRule::ICON))->toBe([]);

    // An icon over the way into a map naming no type claims what cannot be checked.
    setPlaceType($session, 'site', '');
    $untyped = findPlaceIssues($session, MapPlaceIconRule::UNTYPED);
    expect($untyped)->toHaveCount(1)->and($untyped[0]['message'])->toContain('shows the Walls icon', 'names no place type');
});

it('reports a map naming a place type the project does not have', function () {
    $root = createPlaceIconProject();
    $file = $root . '/assets/Maps/site/site.data.php';
    file_put_contents($file, str_replace("'name' => 'Site',", "'name' => 'Site', 'placeType' => 'castle',", (string) file_get_contents($file)));

    $issues = findPlaceIssues(EditorSession::open($root), 'map.place-type');
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['severity'])->toBe('error')
        ->and($issues[0]['message'])->toContain('castle is not a place type');
});
