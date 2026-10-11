<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\Enumerations\CollisionType;

/**
 * The tree brush plants whole trees over a drawn region as one undo step:
 * trunks become solid in the map's own collision, never on events, NPCs or
 * walls, and never where they would cut a walkable area in two; the terminal
 * sees the same trees on the brush's canopy layer. The fixture is
 * {@see createTreeBrushProject()}.
 */

/** @return array{EditorSession, \Ichiloto\Editor\ProjectMap} */
function openTreeBrushSession(bool $declared = true): array
{
    $session = EditorSession::open(createTreeBrushProject($declared));

    return [$session, getPhysicalOccupancyMap($session)];
}

/** @return list<array{0: int, 1: int}> Every solid cell inside the border. */
function listInnerSolidCells(\Ichiloto\Editor\ProjectMap $map): array
{
    $cells = [];
    foreach ($map->getResolvedCollisionMap() as $y => $row) {
        foreach ($row as $x => $value) {
            if ($x > 0 && $y > 0 && $x < 11 && $y < 7 && $value !== CollisionType::NONE->value) {
                $cells[] = [$x, $y];
            }
        }
    }

    return $cells;
}

/** Whether every walkable cell reaches every other one, moving in four directions. */
function isWalkableConnected(\Ichiloto\Editor\ProjectMap $map): bool
{
    $grid = $map->getResolvedCollisionMap();
    $open = [];
    foreach ($grid as $y => $row) {
        foreach ($row as $x => $value) {
            if ($value === CollisionType::NONE->value) {
                $open["{$x},{$y}"] = [$x, $y];
            }
        }
    }
    $start = array_key_first($open);
    $seen = [$start => true];
    $queue = [$open[$start]];
    while ($queue !== []) {
        [$x, $y] = array_shift($queue);
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $key = ($x + $dx) . ',' . ($y + $dy);
            if (isset($open[$key]) && ! isset($seen[$key])) {
                $seen[$key] = true;
                $queue[] = $open[$key];
            }
        }
    }

    return count($seen) === count($open);
}

it('offers only placeable images with a trunk stamp as tree types', function () {
    [$session] = openTreeBrushSession();

    expect($session->listTreeTypes('test-map')['types'])->toBe(['oak' => 'Oak', 'pair' => 'Pair']);
});

it('plants trees over the region with solid trunks, and draws the same forest again as nothing new', function () {
    [$session, $map] = openTreeBrushSession();
    $revision = $session->readMap('test-map')['revision'];

    $drawn = $session->drawTrees('test-map', $revision, listRectangleCells(1, 1, 10, 6), ['oak', 'pair']);
    $objects = $session->readMap('test-map')['worldObjects'];
    $trunks = listInnerSolidCells($map);

    expect($drawn['planted'])->toBeGreaterThan(2)
        ->and(count($objects))->toBe($drawn['planted'])
        ->and(array_unique(array_column($objects, 'resource')))->toEqualCanonicalizing(['oak', 'pair'])
        // Only trunks and the inner wall are solid; no trunk on the event, the NPC or the wall's gap.
        ->and($trunks)->not->toContain([9, 2], [2, 2], [6, 6])
        ->and(isWalkableConnected($map))->toBeTrue();
    foreach ($objects as $object) {
        expect($map->getResolvedCollisionMap()[$object['anchor']['y']][$object['anchor']['x']])->toBe(CollisionType::SOLID->value);
    }

    expect($session->drawTrees('test-map', $drawn['revision'], listRectangleCells(1, 1, 10, 6), ['oak', 'pair'])['planted'])->toBe(0);
});

it('grows the same forest from the same region on another copy of the map', function () {
    $draw = static function (): array {
        [$session] = openTreeBrushSession();
        $session->drawTrees('test-map', $session->readMap('test-map')['revision'], listRectangleCells(1, 1, 10, 6), ['oak', 'pair']);

        return array_map(static fn(array $object): array => [$object['resource'], $object['anchor']], $session->readMap('test-map')['worldObjects']);
    };

    expect($draw())->toBe($draw());
});

it('draws the trees for the terminal on its own canopy decoration layer', function () {
    [$session, $map] = openTreeBrushSession();
    $session->drawTrees('test-map', $session->readMap('test-map')['revision'], listRectangleCells(2, 4, 4, 6), ['oak']);
    $object = $session->readMap('test-map')['worldObjects'][0];
    [$x, $y] = [$object['anchor']['x'], $object['anchor']['y']];
    $layer = array_find($map->getLayers(), static fn(array $layer): bool => $layer['name'] === 'canopy');

    expect($layer['decoration'])->toBeTrue()
        ->and($map->getLayerSymbol($layer['id'], $x, $y))->toBe('|')
        ->and([$map->getLayerSymbol($layer['id'], $x - 1, $y - 1), $map->getLayerSymbol($layer['id'], $x, $y - 1), $map->getLayerSymbol($layer['id'], $x + 1, $y - 1)])
        ->toBe(['(', ')', ')'])
        ->and($map->getLayerColor($layer['id'], $x, $y - 1))->toBe('bright-green')
        // The terrain the player walks on is untouched.
        ->and($map->getLayerSymbol($map->getBaseLayerId(), $x, $y))->toBe('.');
});

it('keeps the only gap through a wall open', function () {
    [$session, $map] = openTreeBrushSession();
    $session->drawTrees('test-map', $session->readMap('test-map')['revision'], [[6, 6], [5, 6], [7, 6]], ['oak']);

    expect(isWalkableConnected($map))->toBeTrue()
        ->and($map->getResolvedCollisionMap()[6][6])->toBe(CollisionType::NONE->value);
});

it('counts grass as walkable ground, so a trunk never closes a path through it', function () {
    // Two rooms joined only by grass, floor and grass at y 3.
    $session = EditorSession::open(createTreeBrushProject(true, ['############', '#...###....#', '#...###....#', '#...g.g....#',
        '#...###....#', '#...###....#', '#...###....#', '############']));
    $map = getPhysicalOccupancyMap($session);

    $drawn = $session->drawTrees('test-map', $session->readMap('test-map')['revision'], [[4, 3], [5, 3], [6, 3]], ['oak']);

    // The floor cell between the grass would cut the rooms apart; the grass itself is no place for a trunk.
    expect($drawn['planted'])->toBe(0)
        ->and(array_slice($map->getResolvedCollisionMap()[3], 4, 3))
        ->toBe([CollisionType::ENCOUNTER->value, CollisionType::NONE->value, CollisionType::ENCOUNTER->value]);
});

it('erases the trees standing in a region, clearing their trunks and crowns, and undo brings them back', function () {
    [$session, $map] = openTreeBrushSession();
    $drawn = $session->drawTrees('test-map', $session->readMap('test-map')['revision'], listRectangleCells(1, 1, 10, 6), ['oak']);
    $layer = array_find($map->getLayers(), static fn(array $layer): bool => $layer['name'] === 'canopy')['id'];
    $before = $session->readMap('test-map')['worldObjects'];

    $erased = $session->drawTrees('test-map', $drawn['revision'], listRectangleCells(1, 1, 5, 6), [], true);
    $left = $session->readMap('test-map')['worldObjects'];

    expect($erased['removed'])->toBeGreaterThan(0)
        ->and(array_filter($left, static fn(array $object): bool => $object['anchor']['x'] <= 5))->toBe([])
        ->and(array_filter(listInnerSolidCells($map), static fn(array $cell): bool => $cell[0] <= 5))->toBe([]);
    for ($y = 0; $y < 8; $y++) {
        expect($map->getLayerSymbol($layer, 1, $y))->toBe(' ');
    }

    $session->undo();
    expect($session->readMap('test-map')['worldObjects'])->toBe($before);
});

it('refuses on a map without its own collision, and a drawing past the world-object limit', function () {
    [$session] = openTreeBrushSession(false);
    expect(fn() => $session->drawTrees('test-map', $session->readMap('test-map')['revision'], listRectangleCells(1, 1, 10, 6), ['oak']))
        ->toThrow(SessionRefusal::class, 'Separate collision first');

    [$session, $map] = openTreeBrushSession();
    $map->setMapDataField(['worldObjects'], array_map(static fn(int $n): array => ['id' => "stone-{$n}", 'anchor' => ['x' => 1, 'y' => 1],
        'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => null], range(1, 127)));
    expect(fn() => $session->drawTrees('test-map', $session->readMap('test-map')['revision'], listRectangleCells(1, 1, 10, 6), ['oak']))
        ->toThrow(SessionRefusal::class, 'only 1 still fit');
});

it('refuses a type that is not a tree', function () {
    [$session] = openTreeBrushSession();

    expect(fn() => $session->drawTrees('test-map', $session->readMap('test-map')['revision'], [[3, 3]], ['counter']))
        ->toThrow(SessionRefusal::class, "'counter' is not a tree type");
});

it('draws and erases trees over the terminal canvas selection, one undo step each', function () {
    $editor = deletionEditor(createTreeBrushProject());
    setEditorProperty($editor, 'focusedPane', 'canvas');
    $map = static fn() => getEditorProperty($editor, 'workspace')->getMapByIndex(0);
    expect($map()->mapId)->toBe('test-map');
    $count = static fn(): int => count($map()->hasMapDataField(['worldObjects']) ? $map()->getMapDataField(['worldObjects']) : []);

    setEditorProperty($editor, 'canvasSelection', ['x' => 1, 'y' => 1, 'width' => 10, 'height' => 6]);
    openMapLinePaletteItem($editor, 'Map: Draw trees in the selection');
    $planted = $count();
    expect($planted)->toBeGreaterThan(2)
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain("Planted {$planted} trees");

    openMapLinePaletteItem($editor, 'Map: Erase trees in the selection');
    expect($count())->toBe(0);
    pressKeys($editor, "\x1a");
    expect($count())->toBe($planted);

    setEditorProperty($editor, 'canvasSelection', null);
    openMapLinePaletteItem($editor, 'Map: Draw trees in the selection');
    expect(getEditorProperty($editor, 'statusMessage'))->toContain('Select a region of the canvas first')
        ->and($count())->toBe($planted);
});
