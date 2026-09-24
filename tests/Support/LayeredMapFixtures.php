<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Field\MapGridSource;

function layeredMapProject(bool $ragged = false): string
{
    $root = makeTemporaryProject('ichiloto-layered-editor-');
    $directory = $root . '/assets/Maps/test-map';
    unlink($directory . '/test-map.map.php');
    mkdir($directory . '/layers');
    $terrain = "<fg=green>....</>\n<fg=red>..</><fg=red>..</>";
    $building = " /  \n xx ";
    $decoration = "d   \n   d";
    $events = "   E\n    ";
    if ($ragged) {
        $terrain = "....\n..";
        $building = " /  \nx ";
        $decoration = "d   \n d";
        $events = "   E\n  ";
    }
    foreach (['01.terrain.map.php' => $terrain, '04.buildings.map.php' => $building, '07.detail.deco.php' => $decoration] as $file => $text) {
        $source = MapGridSource::buildSource($text, 'AUTHORED', '// keep ' . $file . "\n");
        file_put_contents($directory . '/layers/' . $file, $source);
    }
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource($events, 'EVENTS'));
    file_put_contents($directory . '/test-map.data.php', <<<'SOURCE'
<?php
// Authored metadata remains authored.
return [
    'name' => 'Test Map', 'region' => '', 'events' => [],
    'tiles2d' => [
        'asset' => 'Graphics/Tilesets/shared.png',
        'layers' => [
            // Facade crop table.
            'buildings' => ['symbols' => ['x' => ['x' => 0, 'y' => 0, 'width' => 16, 'height' => 16]]],
            'detail' => ['asset' => 'Graphics/Tilesets/detail.png', 'symbols' => ['d' => ['x' => 16, 'y' => 0, 'width' => 16, 'height' => 16]]],
        ],
    ],
];
SOURCE);
    return $root;
}

function loadLayeredMap(string $root): ProjectMap
{
    return ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/test-map');
}

function layeredCanvasEditor(?string $root = null): array
{
    $root ??= layeredMapProject();
    $editor = deletionEditor($root);
    setEditorProperty($editor, 'focusedPane', 'canvas');
    return [$editor, getEditorProperty($editor, 'workspace')->getMapByIndex(0), $root];
}
