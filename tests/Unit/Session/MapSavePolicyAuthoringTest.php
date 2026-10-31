<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Field\MapSavePolicy;

it('chooses the Engine save rule through session history and source-preserving save', function () {
    [$root, $file, $session] = createLandmarkProject();
    $key = ['target' => 'map-data', 'path' => ['savePolicy']];
    $row = findLandmarkRow($session, $key);
    expect($row['label'])->toBe('Saving')->and($row['value'])->toBe('save-points')
        ->and($row['options'])->toBe(array_column(MapSavePolicy::cases(), 'value'));
    $before = require $file;
    expect(getSessionMap($session)->hasMapDataField(['savePolicy']))->toBeFalse();
    applyLandmarkRow($session, $key, 'anywhere');
    expect(MapSavePolicy::readMap(['savePolicy' => getSessionMap($session)->getMapDataField(['savePolicy'])]))
        ->toBe(MapSavePolicy::ANYWHERE);
    $session->undo();
    expect(getSessionMap($session)->hasMapDataField(['savePolicy']))->toBeFalse();
    $session->redo();
    $session->saveMap('test-map');
    $after = require $file;
    expect($after['savePolicy'])->toBe('anywhere');
    unset($after['savePolicy']);
    expect($after)->toBe($before);
    $reloaded = EditorSession::open($root);
    expect(findLandmarkRow($reloaded, $key)['value'])->toBe('anywhere');
    applyLandmarkRow($reloaded, $key, 'save-points');
    $reloaded->saveMap('test-map');
    expect(MapSavePolicy::readMap(require $file))->toBe(MapSavePolicy::SAVE_POINTS);
});

it('refuses values outside the shared save-rule choices without changing source', function (string $value) {
    [, $file, $session] = createLandmarkProject();
    $before = file_get_contents($file);
    expect(fn() => applyLandmarkRow($session, ['target' => 'map-data', 'path' => ['savePolicy']], $value))
        ->toThrow(SessionRefusal::class);
    expect(getSessionMap($session)->hasMapDataField(['savePolicy']))->toBeFalse()
        ->and(file_get_contents($file))->toBe($before);
})->with(['', 'Overworld', 'ANYWHERE', 'false']);

it('offers the same gameplay save choices in graphical and Terminal inspectors', function () {
    [$root, , $session] = createLandmarkProject();
    $map = getSessionMap($session);
    $references = new ReferenceCatalog(\Ichiloto\Editor\ProjectWorkspace::fromProject($root), $map);
    $rows = [];
    foreach ([false, true] as $graphical) {
        $rows[] = array_find((new MapInspector($references, graphical: $graphical))->getMapFields($map),
            static fn(array $row): bool => ($row['path'] ?? null) === ['savePolicy']);
    }
    expect($rows[0])->not->toBeNull()->and($rows[0])->toBe($rows[1]);
});
