<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

it('paints a rectangle of glyphs from its rows as one undo step, spaces included', function () {
    $root = layeredMapProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $buildings = array_find($map['layers'], static fn(array $layer): bool => $layer['name'] === 'buildings')['id'];

    $painted = $session->paintRegion('test-map', $map['revision'], $buildings, 1, 0, ['#+', ' #']);

    expect($painted['status'])->toBe('applied')
        ->and($painted['changed'])->toBe(4);
    $session->saveMap('test-map');
    expect(Ichiloto\Engine\Field\MapGridSource::readFile($root . '/assets/Maps/test-map/layers/04.buildings.map.php'))->toBe(" #+ \n  # ");

    $session->undo();
    $session->saveMap('test-map');
    expect(Ichiloto\Engine\Field\MapGridSource::readFile($root . '/assets/Maps/test-map/layers/04.buildings.map.php'))->toBe(" /  \n xx ");
});

it('refuses a region that leaves the map, painting none of it', function () {
    $session = EditorSession::open(layeredMapProject());
    $map = $session->readMap('test-map');
    $buildings = array_find($map['layers'], static fn(array $layer): bool => $layer['name'] === 'buildings')['id'];

    expect(fn() => $session->paintRegion('test-map', $map['revision'], $buildings, 2, 1, ['###']))
        ->toThrow(SessionRefusal::class, 'Row 1 reaches 4, 1, outside test-map (4 x 2); nothing was painted.')
        ->and($session->listUnsavedChanges())->toBe([]);
});
