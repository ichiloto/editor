<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\MapGraphicsValidator;

/**
 * A world object that draws covers its glyph cells in the graphical field, as
 * the Engine's presentation applies its coverage, so validation and the GUI's
 * untiled count no longer report those glyphs. One whose art cannot be drawn
 * leaves them showing, as the field does. Synthetic project and art only.
 */

/** @return array{string, array{x: int, y: int, glyph: string, layer: string}} A project and one glyph cell it shows, now covered by a placed resource. */
function createCoveredGlyphProject(): array
{
    $root = mapGraphicsProject();
    // An untiled floor cell, so its terrain glyph shows in the graphical field until something covers it.
    writeTileLayer($root . '/assets/Maps/test-map', '01.floor.tiles.php', "0 2816 2816 2816\n2816 2816 2816 2816");
    $cell = MapGraphicsValidator::listShownGlyphCells(ProjectWorkspace::fromProject($root)->getMapByIndex(0))[0]
        ?? throw new RuntimeException('The fixture shows no glyph to cover.');
    writeTilesetTestPng($root . '/assets/Graphics/Props/Counter.png', 12, 16);
    is_dir($root . '/assets/Data/Presentation') || mkdir($root . '/assets/Data/Presentation', 0o777, true);
    file_put_contents($root . '/assets/Data/Presentation/field.php', "<?php\n\nreturn ['resources' => ['counter' => ['name' => 'Counter',"
        . " 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => ['asset' => 'Graphics/Props/Counter.png']]]];\n");
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($file, str_replace("'events' => [],", sprintf("'events' => [], 'worldObjects' => [['id' => 'counter-1', 'resource' => 'counter',"
        . " 'anchor' => ['x' => %d, 'y' => %d], 'covers' => ['layer' => '%s', 'cells' => [[%d, %d]], 'tileLayers' => []]]],",
        $cell['x'], $cell['y'], $cell['layer'], $cell['x'], $cell['y']), (string) file_get_contents($file)));

    return [$root, $cell];
}

/** @return list<array{int, int, string}> */
function listShownCells(string $root): array
{
    return array_map(static fn(array $cell): array => [$cell['x'], $cell['y'], $cell['layer']],
        MapGraphicsValidator::listShownGlyphCells(ProjectWorkspace::fromProject($root)->getMapByIndex(0)));
}

it('stops reporting a glyph a drawn world object covers', function () {
    [$root, $cell] = createCoveredGlyphProject();

    expect(listShownCells($root))->not->toContain([$cell['x'], $cell['y'], $cell['layer']]);
});

it('keeps reporting a covered glyph while the object\'s art cannot be drawn, as the field shows it', function () {
    [$root, $cell] = createCoveredGlyphProject();
    unlink($root . '/assets/Graphics/Props/Counter.png');

    expect(listShownCells($root))->toContain([$cell['x'], $cell['y'], $cell['layer']]);
});
