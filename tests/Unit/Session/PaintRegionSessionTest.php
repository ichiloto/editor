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

it('paints one cell per grapheme, so combining marks and emoji sequences save and reload at the map width', function () {
    $root = layeredMapProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $buildings = array_find($map['layers'], static fn(array $layer): bool => $layer['name'] === 'buildings')['id'];
    $accented = "e\u{0301}";
    $astronaut = "\u{1F469}\u{200D}\u{1F680}";

    $painted = $session->paintRegion('test-map', $map['revision'], $buildings, 1, 0, [$accented . $astronaut, $astronaut . $accented]);

    expect($painted['status'])->toBe('applied')
        ->and($painted['changed'])->toBe(4);
    $session->saveMap('test-map');
    $saved = Ichiloto\Engine\Field\MapGridSource::readFile($root . '/assets/Maps/test-map/layers/04.buildings.map.php');
    expect($saved)->toBe(' ' . $accented . $astronaut . " \n " . $astronaut . $accented . ' ');

    $reloaded = EditorSession::open($root)->readMap('test-map');
    $rows = array_find($reloaded['layers'], static fn(array $layer): bool => $layer['name'] === 'buildings')['rows'];
    expect([$reloaded['width'], $reloaded['height']])->toBe([4, 2])
        ->and($rows)->toBe([[' ', $accented, $astronaut, ' '], [' ', $astronaut, $accented, ' ']]);
});

it('refuses a row that is not valid UTF-8, painting none of the region', function () {
    $session = EditorSession::open(layeredMapProject());
    $map = $session->readMap('test-map');
    $buildings = array_find($map['layers'], static fn(array $layer): bool => $layer['name'] === 'buildings')['id'];

    expect(fn() => $session->paintRegion('test-map', $map['revision'], $buildings, 0, 0, ['##', "#\xC3"]))
        ->toThrow(SessionRefusal::class, 'Row 2 is not valid UTF-8; nothing was painted.')
        ->and($session->listUnsavedChanges())->toBe([]);
});
