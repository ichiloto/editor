<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\MapRoleFields;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\MapTilesetRoleCheck;
use Ichiloto\Engine\Field\MapRoleCatalog;

/** Makes a map role on the Map Roles page and sets its rows; returns its index. */
function createMapRole(EditorSession $session, string $name, ?string $leadsTo = null): int
{
    $index = $session->createDatabaseRecord(MapRoleFields::CATEGORY)['index'];
    $set = static function (string $field, string $value) use ($session, $index): void {
        $row = array_find($session->readDatabaseRecord(MapRoleFields::CATEGORY, $index)['rows'],
            static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
        $session->applyDatabaseRecord(MapRoleFields::CATEGORY, $index, $row['key'], $value);
    };
    $set('id', strtolower($name));
    $set('name.default', $name);
    if ($leadsTo !== null) { $set('leadsTo', $leadsTo); }

    return $index;
}

function setMapRole(EditorSession $session, string $mapId, string $role): array
{
    return applyLandmarkRow($session, ['target' => MapInspector::ROLE_TARGET], $role, null, $mapId);
}

/** @return list<array<string, mixed>> The project's issues with this code. */
function findRoleIssues(EditorSession $session, string $code): array
{
    return array_values(array_filter($session->validateProject()['issues'], static fn(array $issue): bool => $issue['code'] === $code));
}

it('refuses a transfer from a map whose role leads only to some roles to a map of another role', function () {
    // world's transfer T leads into test-map.
    [$root, , $session] = createAtlasProject();
    createMapRole($session, 'Overworld');
    createMapRole($session, 'Outside');
    createMapRole($session, 'Interior');
    $overworld = 0;
    $row = array_find($session->readDatabaseRecord(MapRoleFields::CATEGORY, $overworld)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'leadsTo');
    $session->applyDatabaseRecord(MapRoleFields::CATEGORY, $overworld, $row['key'], 'overworld, outside');

    setMapRole($session, 'world', 'overworld');
    setMapRole($session, 'test-map', 'interior');
    $refused = findRoleIssues($session, 'map.role-destination');
    expect($refused)->toHaveCount(1)
        ->and($refused[0]['severity'])->toBe('error')
        ->and($refused[0]['map'])->toBe('world')
        ->and($refused[0]['message'])->toContain('Event T', 'test-map', 'Interior', 'Overworld or Outside');

    // An outside map is a place an overworld leads to.
    setMapRole($session, 'test-map', 'outside');
    expect(findRoleIssues($session, 'map.role-destination'))->toBe([]);

    // A destination without a role cannot be checked, and says so without refusing.
    setMapRole($session, 'test-map', '');
    expect(getSessionMap($session)->hasMapDataField([MapRoleCatalog::MAP_KEY]))->toBeFalse();
    $unchecked = array_values(array_filter($session->validateProject()['issues'],
        static fn(array $issue): bool => $issue['map'] === 'world' && str_contains($issue['message'], 'has no role')));
    expect($unchecked)->toHaveCount(1)->and($unchecked[0]['severity'])->toBe('warning');

    // A role leading anywhere checks nothing.
    setMapRole($session, 'test-map', 'interior');
    setMapRole($session, 'world', 'outside');
    expect(findRoleIssues($session, 'map.role-destination'))->toBe([]);

    $session->saveDatabase(MapRoleFields::CATEGORY);
    $roles = MapRoleCatalog::load($root . '/assets');
    expect($roles->getIds())->toBe(['overworld', 'outside', 'interior'])
        ->and($roles->getDestinationRoles('overworld'))->toBe(['overworld', 'outside'])
        ->and($roles->getDestinationRoles('interior'))->toBeNull()
        ->and($roles->getName('interior'))->toBe('Interior');
});

it('chooses a map role from the project\'s roles, refusing one it does not have, as one undo step', function () {
    [, , $session] = createAtlasProject();
    createMapRole($session, 'Interior');
    $row = findLandmarkRow($session, ['target' => MapInspector::ROLE_TARGET]);
    expect($row['value'])->toBe(MapInspector::MAP_ROLE_NONE)->and($row['label'])->toBe('Role');

    expect(fn() => setMapRole($session, 'test-map', 'castle'))->toThrow(SessionRefusal::class, 'castle is not a map role');
    setMapRole($session, 'test-map', 'interior');
    expect(getSessionMap($session)->getMapDataField([MapRoleCatalog::MAP_KEY]))->toBe('interior')
        ->and(findLandmarkRow($session, ['target' => MapInspector::ROLE_TARGET])['value'])->toBe('Interior (interior)');
    $session->undo();
    expect(getSessionMap($session)->hasMapDataField([MapRoleCatalog::MAP_KEY]))->toBeFalse();
    $session->redo();

    // A role stays while a map is of it.
    expect(fn() => $session->deleteDatabaseRecord(MapRoleFields::CATEGORY, 0))->toThrow(SessionRefusal::class, 'map test-map');
});

it('reports a map naming a role the project does not have', function () {
    [$root, , $session] = createAtlasProject();
    $file = $root . '/assets/Maps/world/world.data.php';
    file_put_contents($file, str_replace("'name' => 'World',", "'name' => 'World', 'role' => 'castle',", (string) file_get_contents($file)));
    $session = EditorSession::open($root);

    $issues = findRoleIssues($session, 'map.role');
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['severity'])->toBe('error')
        ->and($issues[0]['message'])->toContain('castle is not a map role');
});

it('holds edge triggers to the same destinations as doors', function () {
    [$root] = createAtlasProject();
    file_put_contents($root . '/assets/' . MapRoleCatalog::FILE, '<?php return ' . var_export(['schema' => MapRoleCatalog::SCHEMA, 'roles' => [
        'region' => ['name' => ['message' => 'map_roles.region.name', 'default' => 'Region'], 'leadsTo' => ['region']],
        'room' => ['name' => ['message' => 'map_roles.room.name', 'default' => 'Room']],
    ]], true) . ';');
    $world = $root . '/assets/Maps/world/world.data.php';
    $source = (string) file_get_contents($world);
    // The door now leads back onto the region itself; only the edge leaves it.
    $source = str_replace("'destinationMap' => 'test-map'", "'destinationMap' => 'world'", $source);
    file_put_contents($world, str_replace("'name' => 'World',", "'name' => 'World', 'role' => 'region', 'triggers' => [['id' => 'east-edge',"
        . " 'destinationMap' => 'test-map', 'trigger_area' => ['x' => 0, 'y' => 1, 'width' => 1, 'height' => 1]]],", $source));
    $map = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($map, str_replace("'name' => 'Test Map',", "'name' => 'Test Map', 'role' => 'room',", (string) file_get_contents($map)));

    $issues = findRoleIssues(EditorSession::open($root), 'map.role-destination');
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['message'])->toContain('Edge trigger east-edge', 'test-map', 'Room', 'lead only to Region maps');
});

it('holds each map to a tileset drawn for its role, as the tileset authors its roles', function () {
    // world and test-map both name the tileset home.
    [$root, , $session] = createAtlasProject();
    createMapRole($session, 'Overworld');
    createMapRole($session, 'Outside');
    setMapRole($session, 'world', 'overworld');
    setMapRole($session, 'test-map', 'outside');
    $drawnFor = static function (string $value) use ($session): void {
        $row = array_find($session->readDatabaseRecord('tilesets', 0)['rows'], static fn(array $row): bool => $row['label'] === 'Drawn For');
        expect($row)->not->toBeNull();
        $session->applyDatabaseRecord('tilesets', 0, $row['key'], $value);
    };
    // A tileset that names no roles suits any map.
    expect(findRoleIssues($session, MapTilesetRoleCheck::CODE))->toBe([]);

    $drawnFor('overworld');
    $refused = findRoleIssues($session, MapTilesetRoleCheck::CODE);
    expect($refused)->toHaveCount(1)
        ->and($refused[0]['severity'])->toBe('error')
        ->and($refused[0]['map'])->toBe('test-map')
        ->and($refused[0]['message'])->toContain('Home', 'drawn for Overworld maps', 'it is Outside');

    // Drawn for both, it suits both; a map without a role cannot be checked, and says so without refusing.
    $drawnFor('overworld, outside');
    expect(findRoleIssues($session, MapTilesetRoleCheck::CODE))->toBe([]);
    $drawnFor('overworld');
    setMapRole($session, 'test-map', '');
    expect(findRoleIssues($session, MapTilesetRoleCheck::CODE))->toBe([]);
    $unchecked = array_values(array_filter($session->validateProject()['issues'],
        static fn(array $issue): bool => $issue['map'] === 'test-map' && str_contains($issue['message'], 'drawn for Overworld maps, but it has no role')));
    expect($unchecked)->toHaveCount(1)->and($unchecked[0]['severity'])->toBe('warning');

    // A role a tileset is drawn for stays while it is; the saved tileset is the Engine's to read.
    $session->saveDatabase(MapRoleFields::CATEGORY);
    $session->saveDatabase('tilesets');
    expect(Ichiloto\Engine\Rendering\Tilesets\Tileset::load($root . '/assets', 'home')->roles)->toBe(['overworld']);
    expect(fn() => $session->deleteDatabaseRecord(MapRoleFields::CATEGORY, 0))->toThrow(SessionRefusal::class, 'tileset home');
});

it('reports a tileset drawn for a role the project does not have', function () {
    [$root] = createAtlasProject();
    writeTestTileset($root);
    $file = $root . '/assets/Data/Tilesets/home.php';
    file_put_contents($file, str_replace("'name' => 'Home',", "'name' => 'Home', 'roles' => ['castle'],", (string) file_get_contents($file)));
    $session = EditorSession::open($root);
    createMapRole($session, 'Outside');

    $issues = findRoleIssues($session, 'tileset.roles');
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['severity'])->toBe('error')
        ->and($issues[0]['message'])->toContain('Tileset Home is drawn for castle, which is not a map role');
});
