<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\Enumerations\CollisionType;

/**
 * A world object's resource stamps its footprint (a tree's trunk) into the
 * map's own declared collision whenever the object is made, moved, changed
 * or deleted through world-object authoring, as the tree brush does when it
 * plants: the stamp is an authoring recipe the Engine never reads at run
 * time, so a moved tree must not leave an invisible wall behind or arrive
 * without one. The fixture is {@see createTreeBrushProject()}: a 12 x 8 map
 * with a solid border, an inner wall at x 6 and an `oak` (one trunk), a
 * `pair` (two trunks) and a trunkless `counter`.
 */

function readFootprintCell(EditorSession $session, int $x, int $y): int
{
    return getPhysicalOccupancyMap($session)->getResolvedCollisionMap()[$y][$x];
}

function createFootprintObject(EditorSession $session, string $id, int $x, int $y, string $resource): void
{
    $session->createWorldObject('test-map', $session->readMap('test-map')['revision'], $id, $x, $y, $resource);
}

it('stamps a made object\'s footprint and moves it with the object as one undo step', function () {
    $session = EditorSession::open(createTreeBrushProject());
    createFootprintObject($session, 'oak-1', 3, 4, 'oak');
    expect(readFootprintCell($session, 3, 4))->toBe(CollisionType::SOLID->value);

    $session->moveWorldObject('test-map', $session->readMap('test-map')['revision'], 'oak-1', 1, 0);
    expect(readFootprintCell($session, 3, 4))->toBe(CollisionType::NONE->value)
        ->and(readFootprintCell($session, 4, 4))->toBe(CollisionType::SOLID->value);

    $session->undo();
    expect(readFootprintCell($session, 3, 4))->toBe(CollisionType::SOLID->value)
        ->and(readFootprintCell($session, 4, 4))->toBe(CollisionType::NONE->value);
    $session->redo();
    expect(readFootprintCell($session, 3, 4))->toBe(CollisionType::NONE->value)
        ->and(readFootprintCell($session, 4, 4))->toBe(CollisionType::SOLID->value);
});

it('gives the floor back when an object is deleted, unless another object still stamps it', function () {
    $session = EditorSession::open(createTreeBrushProject());
    createFootprintObject($session, 'pair-1', 2, 4, 'pair');
    expect(readFootprintCell($session, 2, 4))->toBe(CollisionType::SOLID->value)
        ->and(readFootprintCell($session, 4, 4))->toBe(CollisionType::SOLID->value);

    $session->deleteWorldObject('test-map', $session->readMap('test-map')['revision'], 'pair-1');
    expect(readFootprintCell($session, 2, 4))->toBe(CollisionType::NONE->value)
        ->and(readFootprintCell($session, 4, 4))->toBe(CollisionType::NONE->value);
});

it('refuses a footprint on anything but plain floor, changing nothing', function (int $dx, int $dy, string $held) {
    $session = EditorSession::open(createTreeBrushProject(rows: ['############', '#..........#', '#.....#....#', '#.....#....#',
        '#....g#....#', '#.....#....#', '#..........#', '############']));
    createFootprintObject($session, 'oak-1', 4, 3, 'oak');
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->moveWorldObject('test-map', $revision, 'oak-1', $dx, $dy))
        ->toThrow(SessionRefusal::class, "which is {$held}, not plain floor")
        ->and(readFootprintCell($session, 4, 3))->toBe(CollisionType::SOLID->value)
        ->and(array_column($session->readMap('test-map')['worldObjects'], 'anchor', 'id')['oak-1'])->toBe(['x' => 4, 'y' => 3]);
})->with([
    'a wall' => [2, 0, 'SOLID'],
    'encounter grass' => [1, 1, 'ENCOUNTER'],
]);

it('leaves collision alone for objects without a footprint and maps that resolve collision from their glyphs', function (bool $declared, string $resource) {
    $session = EditorSession::open(createTreeBrushProject($declared));
    $before = getPhysicalOccupancyMap($session)->getResolvedCollisionMap();
    createFootprintObject($session, 'object-1', 3, 4, $resource);
    $session->moveWorldObject('test-map', $session->readMap('test-map')['revision'], 'object-1', 1, 0);

    expect(getPhysicalOccupancyMap($session)->getResolvedCollisionMap())->toBe($before);
})->with([
    'a trunkless counter' => [true, 'counter'],
    'an undeclared map' => [false, 'oak'],
]);

it('writes the moved footprint to the map file when saved', function () {
    $root = createTreeBrushProject();
    $session = EditorSession::open($root);
    createFootprintObject($session, 'oak-1', 3, 4, 'oak');
    $session->moveWorldObject('test-map', $session->readMap('test-map')['revision'], 'oak-1', 0, 1);
    $session->saveMap('test-map');

    $reopened = EditorSession::open($root);
    expect(readFootprintCell($reopened, 3, 4))->toBe(CollisionType::NONE->value)
        ->and(readFootprintCell($reopened, 3, 5))->toBe(CollisionType::SOLID->value);
});
