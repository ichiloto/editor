<?php

declare(strict_types=1);

use Ichiloto\Editor\Assets\PngPixels;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * A synthetic town for making a building the way a game maker does: a map
 * with terrain and buildings layers and its own collision, an interior to
 * walk into, and a tileset whose C sheet has art only in its first cell.
 * Sheets use 2-pixel tiles; nothing here is production art.
 */
function createBuildingProject(): string
{
    $root = makeTemporaryProject('editor-building-');
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\n\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n\n"
        . "return ['.' => CollisionType::NONE, '#' => CollisionType::SOLID, '⊓' => CollisionType::SOLID];\n");
    writeBuildingMap($root, 'town', 8, 6, 'Town');
    writeBuildingMap($root, 'inside', 4, 3, 'Inside');
    $sheets = $root . '/assets/Graphics/Tilesets';
    mkdir($sheets, 0o777, true);
    file_put_contents($sheets . '/Town_B.png', PngPixels::createBlank(32, 32)->encode());
    $c = PngPixels::createBlank(32, 32);
    $c->setPixel(0, 0, [200, 10, 10, 255]);
    file_put_contents($sheets . '/Town_C.png', $c->encode());
    mkdir($root . '/assets/Data/Tilesets', 0o777, true);
    file_put_contents($root . '/assets/Data/Tilesets/town.php', "<?php\n\n// The town's art.\nreturn "
        . var_export(['name' => 'Town', 'sheets' => ['B' => 'Graphics/Tilesets/Town_B.png', 'C' => 'Graphics/Tilesets/Town_C.png']], true) . ";\n");

    return $root;
}

/** A map of open ground with an empty buildings layer, its own collision and the town tileset. */
function writeBuildingMap(string $root, string $id, int $width, int $height, string $name): void
{
    $directory = $root . '/assets/Maps/' . $id;
    mkdir($directory . '/layers', 0o777, true);
    $rows = static fn(string $cell): string => implode("\n", array_fill(0, $height, str_repeat($cell, $width)));
    file_put_contents($directory . '/layers/01.terrain.map.php', MapGridSource::buildSource($rows('.'), 'MAP'));
    file_put_contents($directory . '/layers/02.buildings.map.php', MapGridSource::buildSource($rows(' '), 'MAP'));
    file_put_contents($directory . "/{$id}.event.php", MapGridSource::buildSource($rows(' '), 'EVENT'));
    file_put_contents($directory . "/{$id}.data.php", '<?php return ' . var_export(['name' => $name, 'region' => '', 'events' => [],
        'tileset' => 'town', 'occupancy' => array_fill(0, $height, array_fill(0, $width, CollisionType::NONE))], true) . ';');
}

/**
 * The building's art as it arrives from an artist, outside the project: a
 * small image of 3 x 2 cells, each a colour of its own.
 */
function writeIncomingBuildingArt(string $directory): string
{
    if (! is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }
    $art = PngPixels::createBlank(6, 4);
    foreach ([[0, 0], [1, 0], [2, 0], [0, 1], [1, 1], [2, 1]] as $index => [$column, $row]) {
        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 2; $x++) {
                $art->setPixel($column * 2 + $x, $row * 2 + $y, [40 * $index, 100, 180 - $index * 20, 255 - $index]);
            }
        }
    }
    $path = $directory . '/Hut.png';
    file_put_contents($path, $art->encode());

    return $path;
}

/** The session host as the GUI editor speaks to it, over its own requests. */
function createBuildingRpc(string $root): Closure
{
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    expect($request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');

    return $request;
}

/** A request's answer, failing the test with the refusal when there is one. */
function expectAnswer(array $reply): array
{
    expect($reply)->toHaveKey('result', message: json_encode($reply['error'] ?? $reply));

    return $reply['result'];
}

/** A refused request's message. */
function expectRefusal(array $reply): string
{
    expect($reply)->toHaveKey('error');

    return (string) $reply['error']['message'];
}
