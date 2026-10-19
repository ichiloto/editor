<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Field\MapLandmarkCatalog;

it('describes an event from its own minimap row, as one undo step each way, and saves literal source', function () {
    [$root, $file, $session] = createLandmarkProject();
    $before = file_get_contents($file);
    $row = findLandmarkRow($session, ['target' => 'event-landmark', 'marker' => 'T'], 'T');
    expect($row['kind'])->toBe('options')
        ->and($row['options'])->toBe(['undescribed', 'passage', 'entrance', 'exit'])
        ->and($row['value'])->toBe('undescribed');
    // A dialogue is no route: it can only be a building or a landmark.
    expect(findLandmarkRow($session, ['target' => 'event-landmark', 'marker' => 'E'], 'E')['options'])->toBe(['undescribed', 'building', 'landmark']);

    applyLandmarkRow($session, ['target' => 'event-landmark', 'marker' => 'T'], 'exit', 'T');
    expect(readLandmarkEntries($session))->toBe([['id' => 'landmark-1', 'kind' => 'exit', 'anchor' => ['event' => 'T']]])
        ->and(file_get_contents($file))->toBe($before);
    $session->undo();
    expect(readLandmarkEntries($session))->toBe([]);
    $session->redo();

    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'name', 'index' => 0], 'Harbour Gate');
    $session->saveMap('test-map');
    $saved = file_get_contents($file);
    expect($saved)->toContain('// Map-owned presentation stays authored.', "'landmarks' =>", "'Harbour Gate'");
    $reopened = Ichiloto\Editor\Field\MapLandmarks::readMap(getSessionMap(EditorSession::open($root)))->entries;
    expect($reopened[0]['name'])->toBe(['message' => MapLandmarkCatalog::getNameMessageKey('test-map', 'landmark-1'), 'default' => 'Harbour Gate']);

    // Choosing the first option again removes the description; the route is an unnamed marker once more.
    applyLandmarkRow($session, ['target' => 'event-landmark', 'marker' => 'T'], 'undescribed', 'T');
    expect(readLandmarkEntries($session))->toBe([]);
    expect(getSessionMap($session)->hasMapDataField(['landmarks']))->toBeFalse();
});

it('offers only unclaimed anchors and the kinds an anchor may be, keeping the id a save remembers', function () {
    [, , $session] = createLandmarkProject();
    $heading = findLandmarkRow($session, ['target' => 'map-landmarks']);
    $session->addInspectorListEntry('test-map', $session->readMap('test-map')['revision'], $heading['key']);
    $session->addInspectorListEntry('test-map', $session->readMap('test-map')['revision'], $heading['key']);
    // Events first, in map order, each starting as what its marker already is.
    expect(readLandmarkEntries($session))->toBe([
        ['id' => 'landmark-1', 'kind' => 'passage', 'anchor' => ['event' => 'T']],
        ['id' => 'landmark-2', 'kind' => 'landmark', 'anchor' => ['event' => 'E']],
    ]);
    $anchor = findLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'anchor', 'index' => 1]);
    expect($anchor['options'])->toBe(['event:E', 'object:old-tower'])
        ->and($anchor['optionLabels'])->toBe(['Event E · Dialogue', 'World object old-tower']);
    expect(findLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'kind', 'index' => 0])['options'])->toBe(['passage', 'entrance', 'exit']);

    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'kind', 'index' => 1], 'building');
    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'anchor', 'index' => 1], 'object:old-tower');
    expect(readLandmarkEntries($session)[1])->toBe(['id' => 'landmark-2', 'kind' => 'building', 'anchor' => ['object' => 'old-tower']]);

    // A transfer is a route, so moving a building onto one makes it the passage that marker already was.
    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'anchor', 'index' => 0], 'event:E');
    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'anchor', 'index' => 1], 'event:T');
    expect(readLandmarkEntries($session))->toBe([
        ['id' => 'landmark-1', 'kind' => 'landmark', 'anchor' => ['event' => 'E']],
        ['id' => 'landmark-2', 'kind' => 'passage', 'anchor' => ['event' => 'T']],
    ]);
    expect(fn() => applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'kind', 'index' => 1], 'building'))
        ->toThrow(SessionRefusal::class);
    expect(findLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'anchor', 'index' => 0])['options'])->not->toContain('event:T');

    // An object anchor's own id becomes the landmark id when it is a valid one.
    $remove = findLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'kind', 'index' => 1]);
    $session->removeInspectorListEntry('test-map', $session->readMap('test-map')['revision'], $remove['key']);
    $session->addInspectorListEntry('test-map', $session->readMap('test-map')['revision'], findLandmarkRow($session, ['target' => 'map-landmarks'])['key']);
    $session->addInspectorListEntry('test-map', $session->readMap('test-map')['revision'], findLandmarkRow($session, ['target' => 'map-landmarks'])['key']);
    expect(array_column(readLandmarkEntries($session), 'id'))->toBe(['landmark-1', 'landmark-2', 'old-tower']);
    expect(fn() => $session->addInspectorListEntry('test-map', $session->readMap('test-map')['revision'],
        findLandmarkRow($session, ['target' => 'map-landmarks'])['key']))->toThrow(SessionRefusal::class, 'already anchors a landmark');
    expect(findLandmarkRow($session, ['target' => 'map-landmarks', 'index' => 0])['kind'])->toBe('info');
});

it('keeps discovery separate from names: secrets say nothing of approach, empty conditions are removed', function () {
    [, , $session] = createLandmarkProject();
    applyLandmarkRow($session, ['target' => 'event-landmark', 'marker' => 'E'], 'building', 'E');
    $key = static fn(string $field): array => ['target' => 'map-landmarks', 'field' => $field, 'index' => 0];

    applyLandmarkRow($session, $key('approachDistance'), '5');
    expect(readLandmarkEntries($session)[0]['approachDistance'])->toBe(5);
    expect(fn() => applyLandmarkRow($session, $key('approachDistance'), '65'))->toThrow(SessionRefusal::class, '0 to 64 cells');
    applyLandmarkRow($session, $key('approach'), 'false');
    expect(readLandmarkEntries($session)[0])->toHaveKey('approachDistance', null);
    applyLandmarkRow($session, $key('approach'), 'true');
    expect(readLandmarkEntries($session)[0])->not->toHaveKey('approachDistance');
    expect(findLandmarkRow($session, $key('approachDistance'))['value'])->toBe('(engine default: 3)');

    applyLandmarkRow($session, $key('approachDistance'), '2');
    applyLandmarkRow($session, $key('secret'), 'true');
    expect(readLandmarkEntries($session)[0])->toMatchArray(['secret' => true])->not->toHaveKey('approachDistance');
    $rows = $session->readInspector('test-map')['rows'];
    expect(array_filter($rows, static fn(array $row): bool => in_array($row['key']['field'] ?? null, ['approach', 'approachDistance'], true)))->toBe([]);

    applyLandmarkRow($session, $key('revealConditions'), 'event:heard_of_tower');
    expect(readLandmarkEntries($session)[0]['revealConditions'])->toBe([['type' => 'event', 'name' => 'heard_of_tower']]);
    applyLandmarkRow($session, $key('revealConditions'), '');
    expect(readLandmarkEntries($session)[0])->not->toHaveKey('revealConditions');

    applyLandmarkRow($session, $key('name'), 'Old Tower');
    applyLandmarkRow($session, $key('nameMessage'), 'maps.harbour.tower');
    applyLandmarkRow($session, $key('name'), 'The Old Tower');
    expect(readLandmarkEntries($session)[0]['name'])->toBe(['message' => 'maps.harbour.tower', 'default' => 'The Old Tower']);
    expect(fn() => applyLandmarkRow($session, $key('nameMessage'), ' '))->toThrow(SessionRefusal::class, 'needs a translation key');
    applyLandmarkRow($session, $key('name'), '');
    expect(readLandmarkEntries($session)[0])->not->toHaveKey('name');
});

it('refuses to delete or retype an anchor a landmark describes, until the landmark goes', function () {
    [, , $session] = createLandmarkProject();
    applyLandmarkRow($session, ['target' => 'event-landmark', 'marker' => 'T'], 'exit', 'T');
    $revision = $session->readMap('test-map')['revision'];
    expect(fn() => $session->deleteEvent('test-map', $revision, 'T'))->toThrow(SessionRefusal::class, 'landmark landmark-1');
    $type = findLandmarkRow($session, ['target' => 'event-type', 'marker' => 'T'], 'T');
    expect(fn() => $session->applyInspector('test-map', $revision, $type['key'], 'Dialogue'))->toThrow(SessionRefusal::class, 'exit landmark landmark-1');

    $heading = findLandmarkRow($session, ['target' => 'map-landmarks']);
    $session->addInspectorListEntry('test-map', $session->readMap('test-map')['revision'], $heading['key']);
    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'anchor', 'index' => 1], 'object:old-tower');
    expect(fn() => $session->deleteWorldObject('test-map', $session->readMap('test-map')['revision'], 'old-tower'))
        ->toThrow(SessionRefusal::class, 'test-map landmark landmark-2');

    foreach ([1, 0] as $index) {
        $session->removeInspectorListEntry('test-map', $session->readMap('test-map')['revision'],
            findLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'kind', 'index' => $index])['key']);
    }
    $session->deleteWorldObject('test-map', $session->readMap('test-map')['revision'], 'old-tower');
    $session->deleteEvent('test-map', $session->readMap('test-map')['revision'], 'T');
    expect(getSessionMap($session)->getEventDefinition('T'))->toBeNull();
});

it('follows an anchor that moves without copying its cells, on ragged maps too', function () {
    [, , $session] = createLandmarkProject(ragged: true);
    applyLandmarkRow($session, ['target' => 'event-landmark', 'marker' => 'E'], 'landmark', 'E');
    $session->moveEvent('test-map', $session->readMap('test-map')['revision'], 'E', -1, 0);
    $entries = readLandmarkEntries($session);
    expect($entries)->toBe([['id' => 'landmark-1', 'kind' => 'landmark', 'anchor' => ['event' => 'E']]]);
    $catalog = Ichiloto\Editor\Field\MapLandmarks::validateEntries(getSessionMap($session), $entries);
    expect($catalog->landmarks[0]->area->cells)->toBe([[2, 0]]);
});

it('reports declarations the Engine refuses, and still lets an author repair them', function () {
    [, , $session] = createLandmarkProject("'landmarks' => [['id' => 'gate', 'kind' => 'building', 'anchor' => ['event' => 'T']]],");
    $map = getSessionMap($session);
    expect(mapValidatorMessages($map, ['test-map' => $map]))->toContain('Minimap landmarks: A transfer landmark must use passage, entrance or exit so ordinary routes remain visible.');
    expect(findLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'kind', 'index' => 0])['options'])->toBe(['passage', 'entrance', 'exit']);
    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'kind', 'index' => 0], 'entrance');
    expect(mapValidatorMessages($map, ['test-map' => $map]))->not->toContain('Minimap landmarks: A transfer landmark must use passage, entrance or exit so ordinary routes remain visible.');
});

it('describes a selected world object from its own inspector, through the same rows', function () {
    [, , $session] = createLandmarkProject();
    $view = $session->readWorldObject('test-map', 'old-tower');
    $row = array_find($view['rows'], static fn(array $row): bool => ($row['key']['target'] ?? null) === 'object-landmark');
    expect($row['options'])->toBe(['undescribed', 'building', 'landmark'])
        ->and($row['optionLabels'][0])->toBe('Not on the minimap');

    $session->applyWorldObject('test-map', $view['revision'], 'old-tower', $row['key'], 'building');
    expect(readLandmarkEntries($session))->toBe([['id' => 'old-tower', 'kind' => 'building', 'anchor' => ['object' => 'old-tower']]]);
    $view = $session->readWorldObject('test-map', 'old-tower');
    expect(array_find($view['rows'], static fn(array $row): bool => $row['label'] === 'Landmark')['value'])->toBe('old-tower · unnamed');
    expect(fn() => $session->applyWorldObject('test-map', $view['revision'], 'old-tower', $row['key'], 'exit'))
        ->toThrow(SessionRefusal::class);
    $session->undo();
    expect(readLandmarkEntries($session))->toBe([]);
});
