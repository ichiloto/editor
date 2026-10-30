<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\BattleArenaFields;
use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
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

it('previews a ground edge as it is dragged, read as authored ground, without writing it', function () {
    [, $path, $session] = createBattleArenaProject();
    $before = (string) file_get_contents($path);
    $dragged = $session->readArenaPreview(0, ground: ['top' => 420]);

    // The troop stands on the dragged ground; the arena itself is unchanged, saved and unsaved.
    expect($dragged['ground']['top'])->toBe(420.0)
        ->and(array_map(static fn(array $member): float => $member['battler']['ground']['y'], $dragged['members']))->each->toBeGreaterThanOrEqual(420.0)
        ->and($session->readArenaPreview(0)['ground']['top'])->toBe(300.0)
        ->and($session->listUnsavedChanges())->toBe([])
        ->and((string) file_get_contents($path))->toBe($before)
        // A ground the battle would refuse is refused here too.
        ->and(fn() => $session->readArenaPreview(0, ground: ['top' => 650]))->toThrow(SessionRefusal::class);
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

it('previews a footing as its corner is dragged, read as authored footing, without writing it', function () {
    [, $path, $session] = createBattleArenaProject();
    $before = (string) file_get_contents($path);
    $water = [['x' => 0, 'y' => 300], ['x' => 300, 'y' => 300], ['x' => 300, 'y' => 600], ['x' => 0, 'y' => 600]];
    $dragged = $session->readArenaPreview(0, footing: ['land' => [['x' => 500, 'y' => 300], ['x' => 1350, 'y' => 300], ['x' => 1350, 'y' => 600], ['x' => 500, 'y' => 600]], 'water' => $water]);

    expect($dragged['footing']['land'][0])->toBe(['x' => 500.0, 'y' => 300.0])
        ->and(array_map(static fn(array $member): float => $member['battler']['ground']['x'], $dragged['members']))->each->toBeGreaterThanOrEqual(500.0)
        ->and($session->readArenaPreview(0)['footing']['land'])->toBe([])
        ->and($session->listUnsavedChanges())->toBe([])
        ->and((string) file_get_contents($path))->toBe($before)
        ->and(fn() => $session->readArenaPreview(0, footing: ['land' => [['x' => 1, 'y' => 1]]]))->toThrow(SessionRefusal::class);
});

/** A synthetic measured scale: the horizon at 100, the shared body reference kept at 500, 100 pixels per unit there. */
const ARENA_MEASURED_SCALE = ['horizonY' => 100, 'referenceY' => 500, 'pixelsPerUnit' => 100];

it('sets an arena\'s measured scale as one step, writes it as authored, and undoes and redoes it exactly', function () {
    [, $path, $session] = createBattleArenaProject();
    $original = (string) file_get_contents($path);
    $scale = [...ARENA_MEASURED_SCALE, 'vanishingX' => 675];
    expect(findArenaRow($session, 0, 'ground.scaleReference.horizonY')['value'])->toBe('(no measured scale)')
        ->and($session->setArenaDepthModel(0, scaleReference: $scale)['changed'])->toBeTrue()
        ->and($session->setArenaDepthModel(0, scaleReference: $scale)['changed'])->toBeFalse()
        ->and(findArenaRow($session, 0, 'ground.scaleReference.referenceY')['value'])->toBe('500')
        ->and(findArenaRow($session, 0, 'ground.scaleReference.vanishingX')['value'])->toBe('675')
        ->and($session->readArenaPreview(0)['ground']['scaleReference'])
        ->toEqual(['horizonY' => 100.0, 'referenceY' => 500.0, 'pixelsPerUnit' => 100.0, 'vanishingX' => 675.0]);

    // Undone before a save, the arena is exactly as read, with nothing to write.
    expect($session->undo()['label'])->toBe('Use measured scale')
        ->and($session->listUnsavedChanges())->toBe([]);
    $session->redo();
    $session->saveDatabase(BattleArenaFields::CATEGORY);
    $measured = (string) file_get_contents($path);
    expect((require $path)['arenas']['arena.road']['ground'])->toBe(['top' => 300, 'scaleReference' => $scale])
        ->and($measured)->toContain('// Each background and where its ground begins.');

    // Undone after a save, the arena's authored values return; redone, the measured scale is written again.
    $session->undo();
    $session->saveDatabase(BattleArenaFields::CATEGORY);
    expect(require $path)->toBe(eval('?>' . $original));
    $session->redo();
    $session->saveDatabase(BattleArenaFields::CATEGORY);
    expect(require $path)->toBe(eval('?>' . $measured));
});

it('switches between the far edge scale and a measured scale only explicitly, each switch one undo step', function () {
    [, $path, $session] = createBattleArenaProject();
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, findArenaRow($session, 0, 'ground.perspective')['key'], '0.8');

    // A measured value typed beside the far edge scale would leave both: refused, naming the switch.
    expect(fn() => $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0,
        findArenaRow($session, 0, 'ground.scaleReference.horizonY')['key'], '100'))
        ->toThrow(SessionRefusal::class, 'Use Measured Scale');

    $session->setArenaDepthModel(0, scaleReference: ARENA_MEASURED_SCALE);
    expect($session->readArenaPreview(0)['ground'])->not->toHaveKey('perspective')
        ->and(findArenaRow($session, 0, 'ground.perspective')['value'])->toBe('1 (no perspective)')
        ->and(fn() => $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, findArenaRow($session, 0, 'ground.perspective')['key'], '0.9'))
        ->toThrow(SessionRefusal::class, 'Use Far Edge Scale');

    $session->undo();
    expect($session->readArenaPreview(0)['ground'])->toMatchArray(['perspective' => 0.8])
        ->and($session->readArenaPreview(0)['ground'])->not->toHaveKey('scaleReference');

    $session->redo();
    $session->setArenaDepthModel(0, perspective: 0.75);
    $session->saveDatabase(BattleArenaFields::CATEGORY);
    expect((require $path)['arenas']['arena.road']['ground'])->toBe(['top' => 300, 'perspective' => 0.75]);

    // Neither model leaves the arena on the theme's.
    $session->setArenaDepthModel(0);
    $session->saveDatabase(BattleArenaFields::CATEGORY);
    expect((require $path)['arenas']['arena.road']['ground'])->toBe(['top' => 300]);
});

it('refuses a measured value typed alone before a measured scale starts, and edits one value of a measured scale', function () {
    [, , $session] = createBattleArenaProject();
    expect(fn() => $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0,
        findArenaRow($session, 0, 'ground.scaleReference.horizonY')['key'], '100'))
        ->toThrow(SessionRefusal::class, 'together')
        ->and($session->listUnsavedChanges())->toBe([]);

    $session->setArenaDepthModel(0, scaleReference: ARENA_MEASURED_SCALE);
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, findArenaRow($session, 0, 'ground.scaleReference.pixelsPerUnit')['key'], '120');
    expect($session->readArenaPreview(0)['ground']['scaleReference']['pixelsPerUnit'])->toBe(120.0)
        // The Engine's own reading refuses a value it cannot project.
        ->and(fn() => $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0,
            findArenaRow($session, 0, 'ground.scaleReference.referenceY')['key'], '50'))
        ->toThrow(SessionRefusal::class, 'below its horizon');
});

it('refuses a measured scale the battle could not stand battlers on, before anything is recorded', function () {
    [, $path, $session] = createBattleArenaProject();
    $file = (string) file_get_contents($path);

    expect(fn() => $session->setArenaDepthModel(0, perspective: 0.8, scaleReference: ARENA_MEASURED_SCALE))
        ->toThrow(SessionRefusal::class, 'either perspective or a measured scaleReference')
        ->and(fn() => $session->setArenaDepthModel(0, scaleReference: ['horizonY' => 100, 'referenceY' => 500]))
        ->toThrow(SessionRefusal::class, 'ground.scaleReference')
        // The road's ground starts at 300: a horizon below it leaves no ground to stand on.
        ->and(fn() => $session->setArenaDepthModel(0, scaleReference: [...ARENA_MEASURED_SCALE, 'horizonY' => 350, 'referenceY' => 550]))
        ->toThrow(SessionRefusal::class, 'below the horizon')
        ->and($session->listUnsavedChanges())->toBe([])
        ->and($session->undo()['label'])->toBeNull()
        ->and((string) file_get_contents($path))->toBe($file);
});

it('previews a trial measured scale in place of the arena\'s far edge scale, and a dragged horizon over its measured scale, writing nothing', function () {
    [, $path, $session] = createBattleArenaProject();
    $session->applyDatabaseRecord(BattleArenaFields::CATEGORY, 0, findArenaRow($session, 0, 'ground.perspective')['key'], '1');
    $session->saveDatabase(BattleArenaFields::CATEGORY);
    $file = (string) file_get_contents($path);

    $trial = $session->readArenaPreview(0, ground: ['scaleReference' => ARENA_MEASURED_SCALE]);
    expect($trial['ground'])->not->toHaveKey('perspective')
        ->and($trial['ground']['scaleReference']['referenceY'])->toBe(500.0)
        // The arena's own model is answered beside a trial, so its switch stands where the arena does.
        ->and($trial['depthModel'])->toBe('farEdge')
        ->and($session->readArenaPreview(0)['ground'])->toMatchArray(['perspective' => 1.0])
        ->and($session->listUnsavedChanges())->toBe([])
        ->and((string) file_get_contents($path))->toBe($file);

    $session->setArenaDepthModel(0, scaleReference: ARENA_MEASURED_SCALE);
    expect($session->readArenaPreview(0, ground: ['scaleReference' => ['horizonY' => 150]])['ground']['scaleReference'])
        ->toEqual(['horizonY' => 150.0, 'referenceY' => 500.0, 'pixelsPerUnit' => 100.0])
        ->and($session->readArenaPreview(0)['depthModel'])->toBe('measured');
    $session->setArenaDepthModel(0);
    expect($session->readArenaPreview(0)['depthModel'])->toBe('theme');
});

it('stands a physical-height guide at the reference row, chosen rows and every battler\'s feet by the arena\'s measured scale', function () {
    [, , $session] = createBattleArenaProject();
    $session->setArenaDepthModel(0, scaleReference: [...ARENA_MEASURED_SCALE, 'vanishingX' => 700]);
    $preview = $session->readArenaPreview(0, guide: ['height' => 1.8, 'rows' => [['y' => 250, 'label' => 'Stage front'], ['y' => 80, 'x' => 40],
        ['y' => 300, 'label' => 'On the stage', 'elevation' => 0.5]]]);
    $guide = $preview['guide'];
    $placements = $guide['placements'];

    expect($guide)->toMatchArray(['height' => 1.8, 'source' => 'request', 'horizonY' => 100.0, 'referenceY' => 500.0,
            'pixelsPerUnit' => 100.0, 'vanishingX' => 700.0])
        ->and($placements[0])->toMatchArray(['role' => 'reference', 'x' => 700.0, 'y' => 500.0])
        ->and($placements[0]['height'])->toEqualWithDelta(180.0, 1e-9)
        ->and($placements[1])->toMatchArray(['role' => 'row', 'label' => 'Stage front', 'y' => 250.0, 'feetY' => 250.0])
        ->and($placements[1]['height'])->toEqualWithDelta(180.0 * 150 / 400, 1e-9)
        // A row at or above the horizon has no projection, and says why.
        ->and($placements[2])->toMatchArray(['role' => 'row', 'label' => 'Row 2', 'x' => 40.0, 'height' => null])
        ->and($placements[2]['issue'])->toContain('below its horizon')
        // On a raised surface the figure keeps its floor row's scale, its feet lifted by the surface's projected height.
        ->and($placements[3])->toMatchArray(['role' => 'row', 'label' => 'On the stage', 'y' => 300.0, 'elevation' => 0.5])
        ->and($placements[3]['height'])->toEqualWithDelta(180.0 * 200 / 400, 1e-9)
        ->and($placements[3]['feetY'])->toEqualWithDelta(300 - 50.0 * 200 / 400, 1e-9);

    $battlers = array_values(array_filter($placements, static fn(array $placement): bool => in_array($placement['role'], ['party', 'enemy'], true)));
    $stood = [...$preview['party'], ...array_column($preview['members'], 'battler')];
    expect($battlers)->toHaveCount(count($stood));
    foreach ($battlers as $position => $placement) {
        expect($placement['y'])->toBe($stood[$position]['ground']['y'])
            ->and($placement['height'])->toEqualWithDelta(180.0 * ($placement['y'] - 100) / 400, 1e-9);
    }
});

it('takes the guide height from the project\'s editor preference, and has no physical guide without a measured scale', function () {
    [$root, , $session] = createBattleArenaProject();
    expect($session->readArenaPreview(0)['guide'])->toBeNull();

    $config = json_decode((string) file_get_contents($root . '/ichiloto.json'), true);
    file_put_contents($root . '/ichiloto.json', json_encode([...$config, 'editor' => ['battleScaleGuide' => ['height' => 1.8]]]));
    $guide = $session->readArenaPreview(0)['guide'];
    expect($guide)->toMatchArray(['height' => 1.8, 'source' => 'project', 'placements' => []])
        ->and($guide['issue'])->toContain('no measured scale');

    $session->setArenaDepthModel(0, scaleReference: ARENA_MEASURED_SCALE);
    expect($session->readArenaPreview(0)['guide']['placements'][0]['height'])->toEqualWithDelta(180.0, 1e-9)
        ->and(fn() => $session->readArenaPreview(0, guide: ['height' => -1]))->toThrow(SessionRefusal::class, 'finite and positive')
        ->and(fn() => $session->readArenaPreview(0, guide: ['rows' => [['x' => 4]]]))->toThrow(SessionRefusal::class, 'canvas y');
});

it('scripts an arena\'s depth scale model and its guide through the session host', function () {
    [$root, $path] = createBattleArenaProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]));
    $request(1, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);

    expect($request(2, 'arenas.setDepthModel', ['index' => 0, 'scaleReference' => ARENA_MEASURED_SCALE])['result']['changed'])->toBeTrue()
        ->and($request(3, 'arenas.preview', ['index' => 0, 'guide' => ['height' => 1.8, 'rows' => [['y' => 250, 'label' => 'Stage front']]]])
            ['result']['guide']['placements'][1]['height'])->toEqualWithDelta(67.5, 1e-9)
        ->and($request(4, 'arenas.setDepthModel', ['index' => 0, 'perspective' => 'far'])['error']['message'])->toContain('"perspective" must be a number');
    $request(5, 'database.save', ['category' => BattleArenaFields::CATEGORY]);

    expect((require $path)['arenas']['arena.road']['ground'])->toBe(['top' => 300, 'scaleReference' => ARENA_MEASURED_SCALE]);
});
