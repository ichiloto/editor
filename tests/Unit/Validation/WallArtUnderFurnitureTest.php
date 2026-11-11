<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\MapGraphicsValidator;

/**
 * Wall art presents the walls the terminal layers make. A wall tile under
 * furniture shows the furniture standing on a wall, so validation warns and
 * names the cells. Wall art on walkable floor is the Engine's passage
 * agreement rule's to report (a walk-through). Collision is never read
 * from the art. Synthetic tilesets and maps only: tile 6 of the B sheet
 * stands for a raised wall tile, as the tileset's shadows name it.
 */

/** @return list<string> The wall-under-furniture warnings for the layered test map, with the cells they carry. */
function findWallUnderFurniture(string $root): array
{
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);

    return array_values(array_map(static fn(Issue $issue): string => "{$issue->severity->value}: {$issue->message} "
        . json_encode($issue->cells), array_filter(MapGraphicsValidator::validateCoverage($map),
        static fn(Issue $issue): bool => $issue->code === 'graphics.wall-under-furniture')));
}

/**
 * The layered test map (terrain `.` under buildings " /  " over " xx ")
 * with a bed played by `x`, a wall face played by `/`, a glyph-less wall
 * cap, and a walls tile layer whose tiles the tileset's shadows name raised.
 */
function wallArtFurnitureProject(): string
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: [
        'bed' => ['name' => 'Bed', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['decor' => ['5']]],
        'wall-face' => ['name' => 'Wall face', 'layer' => 'buildings', 'glyphs' => ['/'], 'tiles' => ['walls' => ['6']]],
        // A cap the wall's tile also draws, laid without a glyph.
        'wall-cap' => ['name' => 'Wall cap (visual)', 'layer' => 'buildings', 'glyphs' => [' '], 'tiles' => ['walls' => ['6']]],
    ], shadows: ['casters' => [6], 'layers' => ['walls'], 'width' => 0.5, 'opacity' => 0.5]);
    writeTileLayer($root . '/assets/Maps/test-map', '02.decor.tiles.php', "0 0 0 0\n0 5 5 0");

    return $root;
}

it('warns for wall tiles under furniture, naming every cell, however a glyph-less piece draws them', function () {
    $root = wallArtFurnitureProject();
    // The wall's face stands on its / cell; wall tiles also stand under both bed cells.
    writeTileLayer($root . '/assets/Maps/test-map', '03.walls.tiles.php', "0 6 0 0\n0 6 6 0");

    expect(findWallUnderFurniture($root))->toBe(['warning: 2 cells on the walls tile layer have a wall tile under furniture the terminal'
        . ' layers place there, so the furniture stands on a wall in the graphical field: (1, 1), (2, 1). [[1,1],[2,1]]']);
});

it('is satisfied once the furniture stands on floor, and never judges a wall glyph its own wall art presents', function () {
    $root = wallArtFurnitureProject();
    writeTileLayer($root . '/assets/Maps/test-map', '03.walls.tiles.php', "0 6 0 0\n0 0 0 0");

    expect(findWallUnderFurniture($root))->toBe([]);
});

it('judges only the tile layers the tileset names raised', function () {
    $root = wallArtFurnitureProject();
    // The same tile on a layer the shadows do not name is flat art, such as a rug under the bed.
    writeTileLayer($root . '/assets/Maps/test-map', '03.rugs.tiles.php', "0 0 0 0\n0 6 6 0");

    expect(findWallUnderFurniture($root))->toBe([]);
});
