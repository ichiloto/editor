<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\BattleArenaFields;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;

/**
 * The graphical editor authors battle arenas as data: each background and the
 * ground its picture shows, which every troop's formation stands on. Synthetic
 * project only.
 */

/** @return array{string, string, EditorSession} The troop formation project with its arenas moved to data. */
function createBattleArenaProject(): array
{
    $root = troopFormationProject();
    $battle = $root . '/assets/Data/Presentation/battle.php';
    file_put_contents($battle, str_replace(
        ["    arenas: ['arena.road' => \$arena('Road', 'Road.png'), 'arena.cave' => \$arena('Cave', 'Cave.png')],\n", "    defaultArena: 'arena.cave',\n"],
        ["    arenas: [],\n", ''], (string) file_get_contents($battle)));
    $path = $root . '/assets/Data/Presentation/arenas.php';
    file_put_contents($path, <<<'PHP_SOURCE'
<?php

// Each background and where its ground begins.
return [
    'default' => 'arena.cave',
    'arenas' => [
        'arena.road' => ['name' => 'Road', 'background' => ['image' => 'Graphics/Battlebacks/Road.png'], 'ground' => ['top' => 300]],
        'arena.cave' => ['name' => 'Cave', 'background' => ['image' => 'Graphics/Battlebacks/Cave.png']],
    ],
];
PHP_SOURCE);

    return [$root, $path, EditorSession::open($root)];
}

function findArenaRow(EditorSession $session, int $index, string $field): ?array
{
    return array_find($session->readDatabaseRecord(BattleArenaFields::CATEGORY, $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
}

it('lists battle arenas as a graphical category and reads each arena\'s background and ground', function () {
    [, , $session] = createBattleArenaProject();
    $keys = array_map(static fn($category): string => $category->key, DatabaseCatalog::getGraphicalCategories());

    expect($keys)->toContain(BattleArenaFields::CATEGORY, BattleArenaFields::DEFAULT_CATEGORY)
        ->and(array_map(static fn($category): string => $category->key, DatabaseCatalog::all()))->not->toContain(BattleArenaFields::CATEGORY)
        ->and(findArenaRow($session, 0, 'ground.top')['value'])->toBe('300')
        ->and(findArenaRow($session, 1, 'ground.top')['value'])->toBe('(the battle theme\'s)')
        ->and($session->readTroopFormation(0)['arena'])->toBe('arena.cave')
        ->and($session->readTroopFormation(0, 'arena.road')['ground'])->toMatchArray(['top' => 300.0, 'bottom' => 600.0]);
});

it('marks where an arena\'s ground begins as one step, keeps the file\'s comments and default, and stands troops on it', function () {
    [, $path, $session] = createBattleArenaProject();
    $before = $session->readTroopFormation(0, 'arena.cave')['members'][0]['battler']['ground'];
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 1, findArenaRow($session, 1, 'ground.top')['key'], '250');
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 1, findArenaRow($session, 1, 'ground.perspective')['key'], '0.9');
    $session->saveDatabase(BattleArenaFields::CATEGORY);
    $written = (string) file_get_contents($path);

    expect($written)->toContain('// Each background and where its ground begins.', "'default' => 'arena.cave'")
        ->and((require $path)['arenas']['arena.cave']['ground'])->toBe(['top' => 250.0, 'perspective' => 0.9])
        // A placed member keeps its depth; perspective scales it by how far back it stands.
        ->and($session->readTroopFormation(0, 'arena.cave')['members'][0]['battler']['ground'])->toEqual($before)
        ->and($session->readTroopFormation(0, 'arena.cave')['ground'])->toMatchArray(['top' => 250.0, 'perspective' => 0.9]);
});

it('refuses a ground the battle could not stand battlers on, before anything is written', function () {
    [, $path, $session] = createBattleArenaProject();
    $file = (string) file_get_contents($path);
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, findArenaRow($session, 0, 'ground.bottom')['key'], '200');

    expect(fn() => $session->saveDatabase(BattleArenaFields::CATEGORY))->toThrow(SessionRefusal::class, 'arenas.arena.road.ground')
        ->and(file_get_contents($path))->toBe($file);
});

it('adds an arena on the project\'s first image under the id it is created with, and offers it to every arena picker', function () {
    [$root, $path, $session] = createBattleArenaProject();
    $index = $session->createDatabaseRecord(BattleArenaFields::CATEGORY, 'arena.marsh-edge')['index'];
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, $index, findArenaRow($session, $index, 'name')['key'], 'Marsh Edge');
    $session->saveDatabase(BattleArenaFields::CATEGORY);

    expect((require $path)['arenas']['arena.marsh-edge'])->toBe(['name' => 'Marsh Edge', 'background' => ['image' => 'Graphics/Battlebacks/Cave.png']])
        ->and(findArenaRow($session, $index, 'id'))->toMatchArray(['value' => 'arena.marsh-edge', 'kind' => 'info'])
        ->and(array_column(EditorSession::open($root)->readTroopFormation(0)['arenas'], 'id'))
        ->toBe(['arena.road', 'arena.cave', 'arena.marsh-edge']);
});

it('previews an arena with its ground, the starting party and a troop standing on it, unsaved edits included', function () {
    [, , $session] = createBattleArenaProject();
    $preview = $session->readArenaPreview(0);

    expect($preview['arena'])->toBe('arena.road')
        ->and($preview['ground'])->toMatchArray(['top' => 300.0, 'bottom' => 600.0])
        ->and($preview['troop'])->toBe(0)->and($preview['troops'])->toBe(['Pair'])
        ->and($preview['party'][0]['ground']['y'])->toBeLessThanOrEqual(600.0)
        ->and(array_map(static fn(array $member): float => $member['battler']['ground']['y'], $preview['members']))
        ->each->toBeGreaterThanOrEqual(300.0);

    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, findArenaRow($session, 0, 'ground.top')['key'], '350');
    expect($session->readArenaPreview(0)['ground']['top'])->toBe(350.0)
        ->and(fn() => $session->readArenaPreview(7))->toThrow(SessionRefusal::class);
});

it('sets an arena\'s land footing as one step, stands the troop on it, and lists its points as rows', function () {
    [, $path, $session] = createBattleArenaProject();
    $road = [['x' => 400, 'y' => 300], ['x' => 1350, 'y' => 300], ['x' => 1350, 'y' => 600], ['x' => 600.5, 'y' => 600]];
    expect($session->setArenaFooting(0, EnemyHabitat::LAND, $road)['changed'])->toBeTrue()
        ->and($session->setArenaFooting(0, EnemyHabitat::LAND, $road)['changed'])->toBeFalse();
    $preview = $session->readArenaPreview(0);

    expect($preview['footing']['land'])->toBe([['x' => 400.0, 'y' => 300.0], ['x' => 1350.0, 'y' => 300.0],
            ['x' => 1350.0, 'y' => 600.0], ['x' => 600.5, 'y' => 600.0]])
        ->and($preview['footing']['water'])->toBe([])
        ->and(findArenaRow($session, 0, 'landFootingList')['value'])->toBe('4')
        ->and(findArenaRow($session, 0, 'landFooting3X')['value'])->toBe('600.5')
        ->and(array_map(static fn(array $member): float => $member['battler']['ground']['x'], $preview['members']))
        ->each->toBeGreaterThanOrEqual(400.0);

    $session->saveDatabase(BattleArenaFields::CATEGORY);
    expect((require $path)['arenas']['arena.road']['footing'])->toBe(['land' => $road])
        ->and((string) file_get_contents($path))->toContain('// Each background and where its ground begins.');

    // A point typed on its row is the same footing, read the same way.
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, findArenaRow($session, 0, 'landFooting0X')['key'], '420');
    expect($session->readArenaPreview(0)['footing']['land'][0])->toBe(['x' => 420.0, 'y' => 300.0]);
    $session->undo();
    $session->undo();
    expect($session->readArenaPreview(0)['footing']['land'])->toBe([]);
});

it('refuses a footing that is not a polygon or is for the air, and removes the footing when given no points', function () {
    [, $path, $session] = createBattleArenaProject();
    expect(fn() => $session->setArenaFooting(0, EnemyHabitat::WATER, [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]]))
        ->toThrow(SessionRefusal::class, 'footing')
        ->and(fn() => $session->setArenaFooting(0, EnemyHabitat::WATER, [['x' => 1, 'y' => 'low'], ['x' => 2, 'y' => 2], ['x' => 3, 'y' => 1]]))
        ->toThrow(SessionRefusal::class, 'Water Footing[0]')
        ->and(fn() => $session->setArenaFooting(0, EnemyHabitat::AIR, [['x' => 1, 'y' => 1], ['x' => 9, 'y' => 1], ['x' => 9, 'y' => 9]]))
        ->toThrow(SessionRefusal::class, 'air battlers stand over either');
    $session->setArenaFooting(0, EnemyHabitat::WATER, [['x' => 0, 'y' => 300], ['x' => 600, 'y' => 300], ['x' => 600, 'y' => 600]]);
    $session->setArenaFooting(0, EnemyHabitat::WATER, []);
    $session->saveDatabase(BattleArenaFields::CATEGORY);

    expect((require $path)['arenas']['arena.road'])->not->toHaveKey('footing');
});

it('stands an enemy where its habitat lives, authored on its record', function () {
    [$root] = createBattleArenaProject();
    writeRegularBatEnemy($root);
    $session = EditorSession::open($root);
    $water = [['x' => 0, 'y' => 300], ['x' => 300, 'y' => 300], ['x' => 300, 'y' => 600], ['x' => 0, 'y' => 600]];
    $session->setArenaFooting(0, EnemyHabitat::WATER, $water);
    $session->setArenaFooting(0, EnemyHabitat::LAND, [['x' => 300, 'y' => 300], ['x' => 1350, 'y' => 300], ['x' => 1350, 'y' => 600], ['x' => 300, 'y' => 600]]);
    $habitat = array_find($session->readDatabaseRecord('enemies', 0)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'habitat');
    expect($habitat['value'])->toBe('land')
        ->and(array_map(static fn(array $member): float => $member['battler']['ground']['x'], $session->readArenaPreview(0)['members']))
        ->each->toBeGreaterThanOrEqual(300.0);

    $session->applyDatabaseRecord('enemies', 0, $habitat['key'], 'water');
    expect(array_map(static fn(array $member): float => $member['battler']['ground']['x'], $session->readArenaPreview(0)['members']))
        ->each->toBeLessThanOrEqual(300.0);
});

it('sets an arena\'s light on battlers\' shadows, refusing a colour the battle cannot read before writing', function () {
    [, $path, $session] = createBattleArenaProject();
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, findArenaRow($session, 0, 'shadow.strength')['key'], '1.5');
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, findArenaRow($session, 0, 'shadow.color')['key'], '#201008');
    $session->saveDatabase(BattleArenaFields::CATEGORY);
    expect((require $path)['arenas']['arena.road']['shadow'])->toBe(['strength' => 1.5, 'color' => '#201008']);

    $file = (string) file_get_contents($path);
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, findArenaRow($session, 0, 'shadow.color')['key'], 'brown');
    expect(fn() => $session->saveDatabase(BattleArenaFields::CATEGORY))->toThrow(SessionRefusal::class, 'arenas.arena.road.shadow.color')
        ->and(file_get_contents($path))->toBe($file);
});
