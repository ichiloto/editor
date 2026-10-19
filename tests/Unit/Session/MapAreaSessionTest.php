<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\MapAreaAuthoring;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\MapEventDefinition;
use Ichiloto\Engine\Field\MapLandmarkCatalog;

/**
 * Named map areas through the session: logical territory the map owns,
 * declared, reshaped, renamed and deleted as undo steps, events bound to them
 * as their subject and landmarks anchored to them, written as authored. The
 * fixture is {@see createLandmarkProject()}: a 4 x 2 map whose transfer T and
 * dialogue E are painted at (0, 0), (3, 0) and (3, 1).
 */

/** The session's areas, as ids and cells. @return array<string, list<array{int, int}>> */
function readSessionAreaCells(EditorSession $session): array
{
    return array_column(array_map(static fn(array $area): array => ['id' => $area['id'], 'cells' => $area['cells']],
        $session->readAreas('test-map')['areas']), 'cells', 'id');
}

/** The map's areas as its data file holds them. */
function readSavedAreas(string $file): mixed
{
    return (require $file)['areas'] ?? null;
}

it('names the subject and anchor kinds the Engine reads', function () {
    expect(MapEventDefinition::SUBJECT_KINDS)->toContain(MapAreaAuthoring::SUBJECT_KIND)
        ->and(MapLandmarkCatalog::ANCHOR_KINDS)->toContain(MapAreaAuthoring::ANCHOR_KIND);
});

it('creates, reshapes, renames and deletes areas as undo steps, and saves them as written', function () {
    [, $file, $session] = createLandmarkProject();
    $revision = static fn(): int => $session->readMap('test-map')['revision'];

    $session->createArea('test-map', $revision(), 'yard', ['x' => 0, 'y' => 1, 'width' => 2, 'height' => 1]);
    $session->createArea('test-map', $revision(), 'porch', ['cells' => [[1, 0], [2, 0]]]);
    expect(readSessionAreaCells($session))->toBe(['yard' => [[0, 1], [1, 1]], 'porch' => [[1, 0], [2, 0]]])
        ->and($session->readMap('test-map')['areas'][0])->toMatchArray(['id' => 'yard', 'references' => []]);

    $session->setArea('test-map', $revision(), 'porch', ['cells' => [[1, 0]]]);
    $session->renameArea('test-map', $revision(), 'yard', 'garden');
    expect(readSessionAreaCells($session))->toBe(['garden' => [[0, 1], [1, 1]], 'porch' => [[1, 0]]]);

    $session->deleteArea('test-map', $revision(), 'porch');
    $session->saveMap('test-map');
    expect(readSavedAreas($file))->toBe([['id' => 'garden', 'area' => ['x' => 0, 'y' => 1, 'width' => 2, 'height' => 1]]])
        ->and(readSessionAreaCells(EditorSession::open(dirname($file, 4))))->toBe(['garden' => [[0, 1], [1, 1]]]);

    // Undo walks back each step; the last one removes the block.
    expect($session->undo()['label'])->toBe('Delete map area')
        ->and(array_keys(readSessionAreaCells($session)))->toBe(['garden', 'porch']);
    $session->undo();
    $session->undo();
    $session->undo();
    $session->undo();
    expect(readSessionAreaCells($session))->toBe([])
        ->and(getSessionMap($session)->hasMapDataField(['areas']))->toBeFalse();
});

it('refuses an area the map would not load, changing nothing', function (string $id, array $area, string $reason, bool $badShape) {
    [, , $session] = createLandmarkProject();
    $session->createArea('test-map', $session->readMap('test-map')['revision'], 'yard', ['cells' => [[0, 1]]]);
    $before = $session->readAreas('test-map');

    expect(fn() => $session->createArea('test-map', $before['revision'], $id, $area))->toThrow(SessionRefusal::class, $reason);
    if ($badShape) {
        expect(fn() => $session->setArea('test-map', $before['revision'], 'yard', $area))->toThrow(SessionRefusal::class, $reason);
    }
    expect($session->readAreas('test-map'))->toBe($before);
})->with([
    'a taken id' => ['yard', ['cells' => [[1, 1]]], 'already has an area yard', false],
    'an id that is not one' => ['Big Yard', ['cells' => [[1, 1]]], 'unique stable lowercase ids', false],
    'off the map' => ['far', ['x' => 3, 'y' => 1, 'width' => 2, 'height' => 1], 'outside the physical map', true],
    'no cells' => ['empty', ['cells' => []], 'test-map', true],
    'a repeated cell' => ['twice', ['cells' => [[1, 1], [1, 1]]], 'duplicate cells', true],
]);

it('renames an area with the events and landmarks naming it, and refuses to delete it while they do', function () {
    [, $file, $session] = createLandmarkProject("'landmarks' => [['id' => 'yard-sign', 'kind' => 'landmark', 'anchor' => ['area' => 'yard']]],");
    $revision = static fn(): int => $session->readMap('test-map')['revision'];
    $session->createArea('test-map', $revision(), 'yard', ['x' => 0, 'y' => 1, 'width' => 2, 'height' => 1]);
    $session->setEventSubject('test-map', $revision(), 'E', ['kind' => 'area', 'id' => 'yard']);

    expect(getSessionMap($session)->getEventTriggerArea('E')?->cells)->toBe([[0, 1], [1, 1]])
        ->and($session->readAreas('test-map')['areas'][0]['references'])->toBe(['test-map event E', 'test-map landmark yard-sign (landmark)'])
        ->and(fn() => $session->deleteArea('test-map', $revision(), 'yard'))
            ->toThrow(SessionRefusal::class, "Area yard is named by test-map event E, test-map landmark yard-sign (landmark); change those first.")
        ->and(fn() => $session->moveEvent('test-map', $revision(), 'E', 1, 0))->toThrow(SessionRefusal::class, 'Event E covers area yard');

    $session->renameArea('test-map', $revision(), 'yard', 'garden');
    expect(getSessionMap($session)->getEventDefinition('E')['subject'])->toBe(['kind' => 'area', 'id' => 'garden'])
        ->and(readLandmarkEntries($session)[0]['anchor'])->toBe(['area' => 'garden'])
        ->and(findLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'anchor', 'index' => 0])['options'])->toContain('area:garden');

    $session->saveMap('test-map');
    $saved = require $file;
    expect($saved['events']['E']['subject'])->toBe(['kind' => 'area', 'id' => 'garden'])
        ->and($saved['landmarks'][0]['anchor'])->toBe(['area' => 'garden'])
        ->and(array_filter($session->validateProject(['test-map'])['issues'], static fn(array $issue): bool => $issue['severity'] === 'error'))->toBe([]);

    $session->undo();
    expect(getSessionMap($session)->getEventDefinition('E')['subject'])->toBe(['kind' => 'area', 'id' => 'yard'])
        ->and(readLandmarkEntries($session)[0]['anchor'])->toBe(['area' => 'yard']);
});

it('binds an event to an area made in the same change set, and writes both together', function () {
    [, $file, $session] = createLandmarkProject();
    $session->beginChanges();
    $revision = static fn(): int => $session->readMap('test-map')['revision'];
    $session->createArea('test-map', $revision(), 'yard', ['cells' => [[0, 1], [1, 1]]]);
    $session->setEventSubject('test-map', $revision(), 'E', ['kind' => 'area', 'id' => 'yard']);
    $cells = getSessionMap($session)->getEventTriggerArea('E')?->cells;

    $outcome = $session->commitChanges();

    $saved = require $file;
    expect($cells)->toBe([[0, 1], [1, 1]])
        ->and($outcome['status'])->toBe('saved', json_encode($outcome['issues']))
        ->and($saved['areas'])->toBe([['id' => 'yard', 'area' => ['cells' => [[0, 1], [1, 1]]]]])
        ->and($saved['events']['E']['subject'])->toBe(['kind' => 'area', 'id' => 'yard']);
});

it('refuses a subject naming no area, or a kind the Engine does not read', function () {
    [, , $session] = createLandmarkProject();
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->setEventSubject('test-map', $revision, 'E', ['kind' => 'area', 'id' => 'nowhere']))
        ->toThrow(SessionRefusal::class, 'test-map has no area nowhere.')
        ->and(fn() => $session->setEventSubject('test-map', $revision, 'E', ['kind' => 'npc', 'id' => 'keeper']))
        ->toThrow(SessionRefusal::class, 'An event subject is one of world_object, area, not npc.');
});

it('moves area cells with inserted lines, and stops a removal through an area\'s cell', function () {
    [$root, $file, $session] = createLandmarkProject();
    $revision = static fn(): int => $session->readMap('test-map')['revision'];
    $session->createArea('test-map', $revision(), 'yard', ['x' => 0, 'y' => 1, 'width' => 2, 'height' => 1]);
    $session->createArea('test-map', $revision(), 'porch', ['cells' => [[1, 1], [2, 0]]]);
    $session->saveMap('test-map');

    $question = $session->insertMapLines('test-map', $revision(), 'y', 1, 2);
    $session->insertMapLines('test-map', $question['revision'], 'y', 1, 2, 'write', $question['confirm']);

    expect(readSavedAreas($file))->toBe([
        ['id' => 'yard', 'area' => ['x' => 0, 'y' => 3, 'width' => 2, 'height' => 1]],
        ['id' => 'porch', 'area' => ['cells' => [[1, 3], [2, 0]]]],
    ]);

    // Column 2 holds only porch's cell.
    expect(fn() => $session->removeMapLines('test-map', $session->readMap('test-map')['revision'], 'x', 2, 1))
        ->toThrow(SessionRefusal::class, 'areas.1.area.cells.1.0 (area porch has a cell on a removed line)');
});

it('renames and deletes areas, and binds an event to one, through the rows both editors share', function () {
    [, , $session] = createLandmarkProject();
    $revision = static fn(): int => $session->readMap('test-map')['revision'];
    $session->createArea('test-map', $revision(), 'yard', ['x' => 0, 'y' => 1, 'width' => 2, 'height' => 1]);
    $session->createArea('test-map', $revision(), 'porch', ['cells' => [[1, 0]]]);
    $areaRow = static fn(int $index, string $field): array => findLandmarkRow($session, ['target' => 'map-areas', 'field' => $field, 'index' => $index]);

    expect($areaRow(0, 'id'))->toMatchArray(['kind' => 'text', 'raw' => 'yard', 'depth' => 1])
        ->and($areaRow(1, 'cells')['value'])->toBe('1 cell in 1 place');

    $session->applyInspector('test-map', $revision(), $areaRow(0, 'id')['key'], 'garden');
    $subject = findLandmarkRow($session, ['target' => 'event-subject', 'marker' => 'E'], 'E');
    expect($subject['options'])->toBe(['', 'world_object:old-tower', 'area:garden', 'area:porch'])
        ->and($subject['optionLabels'])->toBe(['Its own cells', 'World object old-tower', 'Area garden', 'Area porch']);

    $session->applyInspector('test-map', $revision(), $subject['key'], 'area:garden');
    expect(getSessionMap($session)->getEventTriggerArea('E')?->cells)->toBe([[0, 1], [1, 1]])
        ->and(fn() => $session->removeInspectorListEntry('test-map', $revision(), $areaRow(0, 'cells')['key']))
            ->toThrow(SessionRefusal::class, 'Area garden is named by test-map event E');

    // Its own cells again: it keeps triggering where the area put it.
    $session->applyInspector('test-map', $revision(), $subject['key'], '');
    expect(getSessionMap($session)->getEventDefinition('E'))->not->toHaveKey('subject')
        ->and(getSessionMap($session)->getEventArea('E')?->cells)->toBe([[0, 1], [1, 1]]);

    $removed = $session->removeInspectorListEntry('test-map', $revision(), $areaRow(1, 'id')['key']);
    expect($removed['message'])->toBe('Deleted area porch.')
        ->and(array_column($session->readAreas('test-map')['areas'], 'id'))->toBe(['garden'])
        ->and(fn() => $session->addInspectorListEntry('test-map', $revision(), findLandmarkRow($session, ['target' => 'map-areas'])['key']))
            ->toThrow(SessionRefusal::class, 'Select cells on the map, then make an area of them.');
});

it('makes an area of selected cells under the first free id, writing a filled rectangle as one', function () {
    [, , $session] = createLandmarkProject();
    $revision = static fn(): int => $session->readMap('test-map')['revision'];

    $first = $session->createArea('test-map', $revision(), null, EditorSession::readAreaShape(['cells' => [[1, 1], [0, 1], [0, 0], [1, 0]]]));
    $second = $session->createArea('test-map', $revision(), null, EditorSession::readAreaShape(['cells' => [[2, 0], [3, 1]]]));

    expect([$first['id'], $second['id']])->toBe(['area-1', 'area-2'])
        ->and(array_column($session->readAreas('test-map')['areas'], 'area', 'id'))->toBe([
            'area-1' => ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2],
            'area-2' => ['cells' => [[2, 0], [3, 1]]],
        ])
        ->and(fn() => EditorSession::readAreaShape(['cells' => 'everywhere']))->toThrow(SessionRefusal::class, 'Name the area');
});
