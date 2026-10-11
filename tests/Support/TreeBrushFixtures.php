<?php

declare(strict_types=1);

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * A synthetic 12 x 8 layered field `test-map` for the tree brush, with its own
 * declared collision: a solid border, an inner wall at x 6 from y 1 to 5 whose
 * only gap is (6, 6), an event `E` at (9, 2) and an NPC at (2, 2). Two tree
 * types: `oak` (3 x 4, one trunk) and `pair` (4 x 5, two trunks), beside a
 * `counter` resource with no trunk stamp, which is not a tree.
 *
 * @param bool $declared Whether the map declares its own collision.
 */
function createTreeBrushProject(bool $declared = true): string
{
    $root = makeTemporaryProject('editor-tree-brush-');
    $directory = $root . '/assets/Maps/test-map';
    foreach (glob($directory . '/*') ?: [] as $file) {
        is_file($file) && unlink($file);
    }
    mkdir($directory . '/layers', 0o777, true);
    $rows = ['############', '#..........#', '#.....#....#', '#.....#....#', '#.....#....#', '#.....#....#', '#..........#', '############'];
    file_put_contents($directory . '/layers/01.terrain.map.php', MapGridSource::buildSource(implode("\n", $rows), 'MAP'));
    $events = array_fill(0, 8, str_repeat(' ', 12));
    $events[2][9] = 'E';
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource(implode("\n", $events), 'EVENTS'));
    $occupancy = array_map(static fn(string $row): array => array_map(
        static fn(string $glyph): CollisionType => $glyph === '#' ? CollisionType::SOLID : CollisionType::NONE, str_split($row)), $rows);
    $data = ['name' => 'Forest', 'region' => '', 'events' => ['E' => ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
        'data' => ['mode' => 'action', 'reusable' => true, 'script' => [['type' => 'show_text', 'text' => 'Hello']]]]],
        'npcs' => [['id' => 'ranger', 'name' => 'Ranger', 'sprite' => 'r', 'x' => 2, 'y' => 2]]];
    if ($declared) {
        $data['occupancy'] = $occupancy;
    }
    file_put_contents($directory . '/test-map.data.php', '<?php return ' . var_export($data, true) . ';');

    writeTilesetTestPng($root . '/assets/Graphics/Objects/Oak.png', 144, 192);
    writeTilesetTestPng($root . '/assets/Graphics/Objects/Pair.png', 192, 240);
    writeTilesetTestPng($root . '/assets/Graphics/Objects/Counter.png', 48, 48);
    is_dir($root . '/assets/Data/Presentation') || mkdir($root . '/assets/Data/Presentation', 0o777, true);
    $solid = '\\Ichiloto\\Engine\\Events\\Enumerations\\CollisionType::SOLID';
    file_put_contents($root . '/assets/Data/Presentation/field.php', <<<PHP
<?php

return ['resources' => [
    'oak' => ['name' => 'Oak', 'pivot' => ['x' => 0.5, 'y' => 0.9375],
        'sprites2d' => ['asset' => 'Graphics/Objects/Oak.png', 'layer' => 100, 'cells' => ['width' => 3, 'height' => 4]],
        'occupancyStamp' => [['x' => 0, 'y' => 0, 'type' => {$solid}]]],
    'pair' => ['name' => 'Pair', 'pivot' => ['x' => 0.375, 'y' => 0.95],
        'sprites2d' => ['asset' => 'Graphics/Objects/Pair.png', 'layer' => 100, 'cells' => ['width' => 4, 'height' => 5]],
        'occupancyStamp' => [['x' => 0, 'y' => 0, 'type' => {$solid}], ['x' => 2, 'y' => 0, 'type' => {$solid}]]],
    'counter' => ['name' => 'Counter', 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => ['asset' => 'Graphics/Objects/Counter.png']],
]];
PHP);

    return $root;
}

/** @return list<array{0: int, 1: int}> Every cell of a rectangle. */
function listRectangleCells(int $x0, int $y0, int $x1, int $y1): array
{
    $cells = [];
    for ($y = $y0; $y <= $y1; $y++) {
        for ($x = $x0; $x <= $x1; $x++) {
            $cells[] = [$x, $y];
        }
    }

    return $cells;
}
