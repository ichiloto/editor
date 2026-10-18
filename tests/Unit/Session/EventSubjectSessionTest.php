<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Field\Validation\MapEventResolver;

/**
 * The graphics test map with lockers, a world object covering the building
 * glyphs at (1, 1) and (2, 1), and an inspection event E painted apart from
 * them at (3, 0).
 */
function eventSubjectProject(): string
{
    $root = mapGraphicsProject();
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($file, str_replace("'events' => [],", "'events' => [
        'E' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\DialogueEventTrigger', 'data' => ['dialogue' => [['name' => '', 'text' => 'Lockers.']]]],
    ], 'worldObjects' => [
        ['id' => 'lockers', 'anchor' => ['x' => 1, 'y' => 1], 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => null,
            'covers' => ['layer' => 'buildings', 'cells' => [[1, 1], [2, 1]], 'tileLayers' => []]],
    ],", (string) file_get_contents($file)));

    return $root;
}

/** Where the Engine resolves an event's area on the map as it stands in the session. @return list<array{int, int}> */
function readResolvedEventCells(EditorSession $session, string $marker): array
{
    $map = getSessionMap($session);
    $resolution = MapEventResolver::inspect($map->getEditableData(), $map->getEventLayerCells(), 'test-map');
    $event = array_find($resolution->events, static fn(array $event): bool => ($event['marker'] ?? null) === $marker);

    return $event === null ? [] : \Ichiloto\Engine\Core\CellArea::fromArray($event['area'], 'area')->cells;
}

it('binds an event to the world object it describes, so the event moves with the object', function () {
    $root = eventSubjectProject();
    $session = EditorSession::open($root);

    $bound = $session->setEventSubject('test-map', $session->readMap('test-map')['revision'], 'E', 'lockers');

    expect($bound['changed'])->toBeTrue()
        ->and(getSessionMap($session)->getEventArea('E'))->toBeNull()
        ->and(getSessionMap($session)->getEventDefinition('E')['subject'])->toBe(['kind' => 'world_object', 'id' => 'lockers'])
        ->and(readResolvedEventCells($session, 'E'))->toBe([[1, 1], [2, 1]]);

    $session->moveWorldObject('test-map', $session->readMap('test-map')['revision'], 'lockers', 1, 0);
    expect(readResolvedEventCells($session, 'E'))->toBe([[2, 1], [3, 1]]);

    $session->saveMap('test-map');
    $saved = EditorSession::open($root);
    expect(getSessionMap($saved)->getEventDefinition('E')['subject'])->toBe(['kind' => 'world_object', 'id' => 'lockers'])
        ->and(array_filter($saved->validateProject(['test-map'])['issues'], static fn(array $issue): bool => str_starts_with((string) $issue['code'], 'event.')))
        ->toBe([]);
});

it('refuses to move a bound event apart from its subject, or to delete a subject an event names', function () {
    $session = EditorSession::open(eventSubjectProject());
    $session->setEventSubject('test-map', $session->readMap('test-map')['revision'], 'E', 'lockers');
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->moveEvent('test-map', $revision, 'E', 1, 0))
        ->toThrow(SessionRefusal::class, 'Event E moves with its subject lockers; move the world object, or unbind the event first.')
        ->and(fn() => $session->setEventBounds('test-map', $revision, 'E', 0, 0, 1, 1))->toThrow(SessionRefusal::class, 'moves with its subject')
        ->and(fn() => $session->deleteWorldObject('test-map', $revision, 'lockers'))->toThrow(SessionRefusal::class, 'test-map event E')
        ->and(fn() => $session->setEventSubject('test-map', $revision, 'E', 'no-such-object'))
            ->toThrow(SessionRefusal::class, 'test-map has no world object no-such-object.');
});

it('unbinds an event onto cells of its own, and undo puts the subject back', function () {
    $session = EditorSession::open(eventSubjectProject());
    $session->setEventSubject('test-map', $session->readMap('test-map')['revision'], 'E', 'lockers');

    $session->setEventSubject('test-map', $session->readMap('test-map')['revision'], 'E', null, [[0, 0]]);
    expect(getSessionMap($session)->getEventDefinition('E'))->not->toHaveKey('subject')
        ->and(readResolvedEventCells($session, 'E'))->toBe([[0, 0]])
        ->and(fn() => $session->setEventSubject('test-map', $session->readMap('test-map')['revision'], 'E', null, [[1, 0]]))
            ->toThrow(SessionRefusal::class, 'Event E has no subject to unbind.');

    $session->undo();
    expect(getSessionMap($session)->getEventDefinition('E')['subject']['id'])->toBe('lockers')
        ->and(getSessionMap($session)->getEventArea('E'))->toBeNull();
});

it('binds an event through a batch and writes it once the map is valid', function () {
    $root = eventSubjectProject();

    $outcome = ChangeBatch::apply($root, [
        ['method' => 'event.subject', 'params' => ['map' => 'test-map', 'marker' => 'E', 'subject' => 'lockers']],
        ['method' => 'worldObjects.move', 'params' => ['map' => 'test-map', 'id' => 'lockers', 'dx' => 1, 'dy' => 0]],
    ]);

    expect($outcome['status'])->toBe('saved')
        ->and((require $root . '/assets/Maps/test-map/test-map.data.php')['events']['E']['subject'])->toBe(['kind' => 'world_object', 'id' => 'lockers'])
        ->and(array_filter($outcome['issues'], static fn(array $issue): bool => str_starts_with((string) $issue['code'], 'event.')))->toBe([]);
});
