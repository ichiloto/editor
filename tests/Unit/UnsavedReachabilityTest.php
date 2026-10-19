<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;

/** A one-room project the game can walk: a corridor with the sample chest E at its end, entered at its start. */
function unsavedReachabilityProject(): string
{
    $root = makeTemporaryProject('ichiloto-unsaved-reach-');
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n"
        . "return ['#' => CollisionType::SOLID, ' ' => CollisionType::NONE];\n");
    file_put_contents($root . '/assets/Data/system.php', '<?php return ' . var_export(['startingPositions' => ['player' => [
        'destinationMap' => 'test-map', 'spawnPoint' => ['x' => 1, 'y' => 1], 'spawnSprite' => ['South'],
    ]]], true) . ';');
    file_put_contents($root . '/assets/Maps/test-map/test-map.map.php', "<?php\n\nreturn <<<'ICHILOTO_MAP'\n#######\n#     #\n#######\nICHILOTO_MAP;\n");
    file_put_contents($root . '/assets/Maps/test-map/test-map.event.php', "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n       \n     E \n       \nICHILOTO_EVENT_MAP;\n");

    return $root;
}

it('judges reachability by the unsaved map, as saving would leave it, writing nothing of the project', function () {
    $root = unsavedReachabilityProject();
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);
    $unreachable = static fn(): array => array_values(array_filter(array_column($session->validateProject(['test-map'])['issues'], 'message'),
        static fn(string $message): bool => str_contains($message, 'can be reached')));
    expect($unreachable())->toBe([]);

    // A wall across the corridor, not yet saved, cuts the chest off.
    $map = $session->readMap('test-map');
    $session->paint('test-map', $map['revision'], $map['baseLayer'], [[3, 1]], '#');

    expect($unreachable())->toBe(['No cell of event E (ChestEventTrigger) at (5, 1) can be reached, so it never fires.'])
        // Judged in memory: nothing of the project is written or copied.
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});

it('judges floor left untiled by an unsaved edit before it is saved', function () {
    $root = unsavedReachabilityProject();
    writeTestTileset($root);
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("'triggers' => [],", "'triggers' => [], 'tileset' => 'home',", (string) file_get_contents($data)));
    writeTileLayer($root . '/assets/Maps/test-map', '01.floor.tiles.php', implode("\n", array_fill(0, 3, implode(' ', array_fill(0, 7, '2816')))));
    $session = EditorSession::open($root);
    $untiled = static fn(): array => array_values(array_filter(array_column($session->validateProject(['test-map'])['issues'], 'message'),
        static fn(string $message): bool => str_contains($message, 'shows the background')));
    expect($untiled())->toBe([]);

    $session->paintTiles('test-map', $session->readMap('test-map')['revision'], 'floor', [[2, 1]], 0);

    expect($untiled())->toBe(['1 cell the player can reach has no tile, so the field shows the background there: (2, 1).']);
});
