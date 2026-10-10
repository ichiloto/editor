<?php

declare(strict_types=1);

use Ichiloto\Editor\Events\EventAuthoring;
use Ichiloto\Editor\Events\EventMarkers;
use Ichiloto\Editor\Events\EventRefusal;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Events\Triggers\DialogueEventTrigger;

/**
 * An event's two halves, its painted marker and its definition, authored
 * together: created, retyped, moved, reshaped and deleted as one undo step
 * each, and refused without a trace when the map cannot take the edit.
 */

/** A writable copy of the synthetic fixture map: 12x5, one chest event. */
function authoringMap(): ProjectMap
{
    $workspace = ProjectWorkspace::fromProject(makeTemporaryProject('ichiloto-event-authoring-'));

    return $workspace->maps[array_search('test-map', $workspace->mapIds, true)];
}

/** Every cell of the event layer, so a refusal can be shown to change nothing. */
function eventLayerOf(ProjectMap $map): array
{
    $rows = [];
    for ($y = 0; $y < $map->getHeight(); $y++) {
        for ($x = 0; $x < $map->getWidth(); $x++) {
            $rows[$y][$x] = $map->getEventSymbol($x, $y);
        }
    }

    return [$rows, $map->getEventDefinitions()];
}

/** A free 2x1 run of event cells, found rather than assumed. */
function freeEventCells(ProjectMap $map, int $count = 2): array
{
    for ($y = 0; $y < $map->getHeight(); $y++) {
        for ($x = 0; $x + $count <= $map->getWidth(); $x++) {
            $cells = array_map(static fn(int $offset): array => [$x + $offset, $y], range(0, $count - 1));
            if (array_all($cells, static fn(array $cell): bool => $map->getEventMarkerAt($cell[0], $cell[1]) === null)) {
                return $cells;
            }
        }
    }

    throw new RuntimeException('The fixture map has no free event cells.');
}

it('creates an event at cells with a type as one undo step, under a marker no event claims', function () {
    $map = authoringMap();
    $before = eventLayerOf($map);
    $cells = freeEventCells($map);
    $dialogue = EventTypeCatalog::findByLabel('Dialogue');
    $expected = EventMarkers::findFreeMarker(EventMarkers::findUsedMarkers($map));

    $created = EventAuthoring::createEvent($map, $cells, $dialogue);
    $marker = $created['marker'];

    expect($marker)->toBe($expected)
        ->and($map->getEventArea($marker)?->cells)->toBe($cells)
        ->and($map->getEventDefinition($marker))->toBe($dialogue->buildDefinition(null))
        ->and($created['command']->label)->toBe('Event create');

    $created['command']->undo();
    expect(eventLayerOf($map))->toBe($before)
        ->and($map->isDirty())->toBeFalse();

    $created['command']->execute();
    expect($map->getEventArea($marker)?->cells)->toBe($cells)
        ->and($map->getEventDefinition($marker)['class'] ?? null)->toBe(DialogueEventTrigger::class);
});

it('refuses an event on cells another event holds, off the map, or under a marker that cannot be used, changing nothing', function () {
    $map = authoringMap();
    [$placed] = array_keys($map->getEventDefinitions());
    [$x, $y] = $map->getEventArea((string) $placed)->cells[0];
    $type = EventTypeCatalog::at(0);
    $before = eventLayerOf($map);
    $revision = $map->stateVersion();

    expect(fn() => EventAuthoring::createEvent($map, [[$x, $y]], $type))->toThrow(EventRefusal::class, "Marker {$placed} already holds")
        ->and(fn() => EventAuthoring::createEvent($map, [[$map->getWidth(), 0]], $type))->toThrow(EventRefusal::class, 'is outside')
        ->and(fn() => EventAuthoring::createEvent($map, [], $type))->toThrow(EventRefusal::class, 'at least one cell')
        ->and(fn() => EventAuthoring::createEvent($map, freeEventCells($map), $type, (string) $placed))->toThrow(EventRefusal::class, 'already an event')
        ->and(fn() => EventAuthoring::createEvent($map, freeEventCells($map), $type, '7'))->toThrow(EventRefusal::class, 'is a digit')
        ->and(fn() => EventAuthoring::createEvent($map, freeEventCells($map), $type, 'AB'))->toThrow(EventRefusal::class, 'one terminal cell')
        ->and(eventLayerOf($map))->toBe($before)
        ->and($map->stateVersion())->toBe($revision);
});

it('treats a painted marker without a definition, and a definition without cells, as taken', function () {
    $map = authoringMap();
    [$x, $y] = freeEventCells($map, 1)[0];
    $map->setEventSymbol($x, $y, '@');

    expect(EventMarkers::findUsedMarkers($map))->toContain('@', ...array_map(strval(...), array_keys($map->getEventDefinitions())))
        ->and(EventMarkers::findFreeMarker(EventMarkers::findUsedMarkers($map)))->toBe('!')
        ->and(EventMarkers::findFreeMarker(str_split(EventMarkers::CANDIDATES)))->toBeNull();
});

it('retypes an event, keeping what it holds when the type is unchanged, and undoes to the old definition', function () {
    $map = authoringMap();
    [$marker] = array_map(strval(...), array_keys($map->getEventDefinitions()));
    $original = $map->getEventDefinition($marker);
    $current = EventTypeCatalog::at(EventTypeCatalog::indexOfClass($original['class']));

    $same = EventAuthoring::setEventType($map, $marker, $current);
    // Defaults follow the type, while authored keys retain their values and relative order.
    expect($map->getEventDefinition($marker))->toEqual($current->buildDefinition($original))
        ->and(array_intersect_key($map->getEventDefinition($marker)['data'], $original['data']))->toBe($original['data']);
    $same?->undo();
    expect($map->getEventDefinition($marker))->toBe($original);

    $dialogue = EventTypeCatalog::findByLabel('Dialogue');
    $command = EventAuthoring::setEventType($map, $marker, $dialogue);
    expect($map->getEventDefinition($marker))->toBe($dialogue->buildDefinition(null))
        ->and(EventAuthoring::setEventType($map, $marker, $dialogue))->toBeNull();
    $command->undo();
    expect($map->getEventDefinition($marker))->toBe($original)
        ->and(fn() => EventAuthoring::setEventType($map, 'Q', $dialogue))->toThrow(EventRefusal::class, 'has no event Q');
});

it('deletes an event\'s cells and definition together and undoes both, back to the authored order', function () {
    $map = authoringMap();
    $created = EventAuthoring::createEvent($map, freeEventCells($map), EventTypeCatalog::at(0));
    [$first] = array_map(strval(...), array_keys($map->getEventDefinitions()));
    $before = eventLayerOf($map);

    $command = EventAuthoring::deleteEvent($map, $first);
    expect($map->getEventDefinition($first))->toBeNull()
        ->and($map->getEventArea($first))->toBeNull()
        ->and($map->getEventDefinition($created['marker']))->not->toBeNull();

    $command->undo();
    // The deleted event comes back where it was, ahead of the newer one.
    expect(eventLayerOf($map))->toBe($before)
        ->and(array_map(strval(...), array_keys($map->getEventDefinitions())))->toBe([$first, $created['marker']]);

    // A marker painted without a definition is deleted the same way.
    [$x, $y] = freeEventCells($map, 1)[0];
    $map->setEventSymbol($x, $y, 'Q');
    EventAuthoring::deleteEvent($map, 'Q');
    expect($map->getEventMarkerAt($x, $y))->toBeNull()
        ->and(fn() => EventAuthoring::deleteEvent($map, 'Q'))->toThrow(EventRefusal::class, 'has no event Q');
});

it('moves and reshapes an event, refusing to leave the map or cover another event instead of clamping or overwriting', function () {
    $map = authoringMap();
    [$marker] = array_map(strval(...), array_keys($map->getEventDefinitions()));
    $bounds = $map->getEventBounds($marker);
    // A second event directly to the right of the first.
    $neighbour = EventAuthoring::createEvent($map, [[$bounds['x'] + $bounds['width'], $bounds['y']]], EventTypeCatalog::at(0))['marker'];
    $before = eventLayerOf($map);

    expect(fn() => EventAuthoring::moveEvent($map, $marker, 1, 0))->toThrow(EventRefusal::class, "would cover marker {$neighbour}")
        ->and(fn() => EventAuthoring::moveEvent($map, $marker, -($bounds['x'] + 1), 0))->toThrow(EventRefusal::class, 'would leave the map')
        ->and(fn() => EventAuthoring::setEventBounds($map, $marker, $bounds['x'], $bounds['y'], $bounds['width'] + 1, 1))
            ->toThrow(EventRefusal::class, "would cover marker {$neighbour}")
        ->and(fn() => EventAuthoring::setEventBounds($map, $marker, $bounds['x'], $bounds['y'], 1, $map->getHeight()))
            ->toThrow(EventRefusal::class, 'would leave the map')
        ->and(fn() => EventAuthoring::setEventBounds($map, $marker, $bounds['x'], $bounds['y'], 0, 1))->toThrow(EventRefusal::class, 'at least 1x1')
        ->and(eventLayerOf($map))->toBe($before)
        ->and(EventAuthoring::moveEvent($map, $marker, 0, 0))->toBeNull();

    $move = EventAuthoring::moveEvent($map, $marker, 0, 1);
    expect($map->getEventBounds($marker))->toBe([...$bounds, 'y' => $bounds['y'] + 1])
        ->and($move->label)->toBe('Event move');
    $move->undo();
    expect(eventLayerOf($map))->toBe($before);

    $grow = EventAuthoring::setEventBounds($map, $marker, $bounds['x'], $bounds['y'], 1, 2);
    expect($map->getEventBounds($marker))->toBe([...$bounds, 'width' => 1, 'height' => 2])
        ->and($map->getEventArea($neighbour))->not->toBeNull();
    $grow->undo();
    expect(eventLayerOf($map))->toBe($before);
});

it('refuses every event edit on a read-only map before anything changes', function () {
    $root = makeTemporaryProject('ichiloto-event-readonly-');
    file_put_contents($root . '/assets/Maps/test-map/test-map.map.php', "<?php\nreturn 'not a nowdoc';\n");
    $map = ProjectWorkspace::fromProject($root)->maps[0];
    $marker = '@';

    expect($map->getGridSourceIssue())->not->toBeNull()
        ->and(fn() => EventAuthoring::createEvent($map, [[0, 0]], EventTypeCatalog::at(0)))->toThrow(MapSourceRefusal::class, 'read-only')
        ->and(fn() => EventAuthoring::deleteEvent($map, $marker))->toThrow(MapSourceRefusal::class, 'read-only')
        ->and(fn() => EventAuthoring::moveEvent($map, $marker, 0, 1))->toThrow(MapSourceRefusal::class, 'read-only')
        ->and($map->isDirty())->toBeFalse();
});
