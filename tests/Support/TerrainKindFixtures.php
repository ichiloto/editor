<?php

declare(strict_types=1);

use Ichiloto\Engine\Field\MapGridSource;

/**
 * The graphics test map ({@see mapGraphicsProject()}) whose tileset declares
 * synthetic terrain kinds on its terrain layer: meadow and path are ground
 * kinds on the `floor` tile layer, grove a feature on `canopy` standing on
 * meadow, peaks a solid feature awaiting its art.
 *
 * @param string|null $terrain Replaces the terrain layer's rows.
 */
function terrainKindProject(?string $terrain = null): string
{
    $root = mapGraphicsProject();
    writeTestTileset($root);
    $path = $root . '/assets/Data/Tilesets/home.php';
    $data = require $path;
    $data['kinds'] = [
        'meadow' => ['name' => 'Meadow', 'layer' => 'terrain', 'glyph' => '<fg=green>.</>', 'art' => ['layer' => 'floor', 'tile' => 2816],
            'passage' => 'walk', 'encounters' => 'meadow', 'atlas' => 'ground'],
        'path' => ['name' => 'Path', 'layer' => 'terrain', 'glyph' => '<fg=yellow>=</>', 'art' => ['layer' => 'floor', 'tile' => 2864],
            'passage' => 'walk', 'atlas' => 'road'],
        'grove' => ['name' => 'Grove', 'layer' => 'terrain', 'glyph' => '<fg=green>♣</>', 'ground' => 'meadow',
            'art' => ['layer' => 'canopy', 'tile' => 2912], 'passage' => 'walk', 'encounters' => 'grove', 'atlas' => 'vegetation'],
        'peaks' => ['name' => 'Peaks', 'layer' => 'terrain', 'glyph' => '<fg=#a0522d>^</>', 'ground' => 'meadow',
            'art' => ['awaiting' => true], 'passage' => 'solid', 'atlas' => 'highland'],
    ];
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    if ($terrain !== null) {
        file_put_contents($root . '/assets/Maps/test-map/layers/01.terrain.map.php', MapGridSource::buildSource($terrain, 'AUTHORED'));
    }

    return $root;
}

