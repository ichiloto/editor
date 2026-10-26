<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * The cells a graphical editor's brushes paint, worked out by the session the
 * way the terminal canvas works them out: a widened brush stroke, lines and
 * rectangles between two corners, and flood fills of a glyph or tile region.
 * On the 4 x 2 graphics test map the buildings layer (`map:4`) holds ` /  `
 * over ` xx `.
 */

function canvasToolSession(): EditorSession
{
    $root = mapGraphicsProject();
    writeTestTileset($root);

    return EditorSession::open($root);
}

/** @return list<string> Cells as "x,y", sorted, so the order a shape lists them in does not matter. */
function listShapeCells(array $answer): array
{
    $cells = array_map(static fn(array $cell): string => "{$cell[0]},{$cell[1]}", $answer['cells']);
    sort($cells);

    return $cells;
}

it('widens a brush stroke by the brush and keeps it on the map', function () {
    $session = canvasToolSession();

    expect(listShapeCells($session->getToolShape('test-map', 'brush', [0, 0], [0, 0], 3)))->toBe(['0,0', '0,1', '1,0', '1,1'])
        ->and(listShapeCells($session->getToolShape('test-map', 'brush', [0, 0], [0, 0], 1, [[0, 0], [1, 0], [2, 0]])))->toBe(['0,0', '1,0', '2,0'])
        ->and(listShapeCells($session->getToolShape('test-map', 'brush', [0, 0], [0, 0], 2, [[2, 0]])))->toBe(['2,0', '2,1', '3,0', '3,1']);
});

it('draws lines and rectangles between two corners, a filled rectangle never widened past them', function () {
    $session = canvasToolSession();

    expect(listShapeCells($session->getToolShape('test-map', 'line', [0, 0], [3, 0], 1)))->toBe(['0,0', '1,0', '2,0', '3,0'])
        ->and(listShapeCells($session->getToolShape('test-map', 'rectangle', [3, 1], [0, 0], 1)))->toHaveCount(8)
        ->and(listShapeCells($session->getToolShape('test-map', 'filled_rectangle', [1, 0], [2, 1], 5)))->toBe(['1,0', '1,1', '2,0', '2,1']);
});

it('refuses a tool that does not paint and a brush width the editors do not offer', function () {
    $session = canvasToolSession();

    expect(fn() => $session->getToolShape('test-map', 'select', [0, 0], [1, 1], 1))->toThrow(SessionRefusal::class, 'not a painting tool')
        ->and(fn() => $session->getToolShape('test-map', 'brush', [0, 0], [0, 0], 4))->toThrow(SessionRefusal::class, 'cells wide');
});

it('fills the region of one glyph, or of one tile counting an autotile as its kind', function () {
    $session = canvasToolSession();

    expect(listShapeCells($session->getFillRegion('test-map', 2, 1, 'map:4', null)))->toBe(['1,1', '2,1'])
        // A tile layer the map lacks is empty everywhere.
        ->and(listShapeCells($session->getFillRegion('test-map', 0, 0, null, 'ground')))->toHaveCount(8);

    // Two shapes of one autotile kind, and a plain tile beside them.
    $session->stampTiles('test-map', $session->readMap('test-map')['revision'], 'ground', [[0, 0, 2048], [1, 0, 2049], [2, 0, 42]]);
    expect(listShapeCells($session->getFillRegion('test-map', 0, 0, null, 'ground')))->toBe(['0,0', '1,0'])
        ->and(fn() => $session->getFillRegion('test-map', 0, 0, null, null))->toThrow(SessionRefusal::class, 'Name the glyph layer or the tile layer');
});
