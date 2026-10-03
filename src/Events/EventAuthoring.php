<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;

/**
 * An event's life on a map as every editor interface authors it: created at
 * cells with a type, retyped, moved, reshaped and deleted.
 *
 * An event is two halves the engine only loads together: its marker painted
 * on the event layer and its definition in the map's data. Each edit here
 * changes both as one undo step and leaves the map exactly as it was when
 * it is refused. Every edit is applied before its command is returned; the
 * caller records the command and reports what it likes.
 */
final class EventAuthoring
{
    /**
     * Places a new event of a type on cells: its marker painted there and
     * its definition written, as one undo step.
     *
     * @param list<array{0: int, 1: int}> $cells The cells it triggers on, as [x, y].
     * @param string|null $marker The marker to give it; null takes the first free one ({@see EventMarkers}).
     * @return array{marker: string, command: Command}
     * @throws EventRefusal When the cells or marker cannot be used.
     * @throws MapSourceRefusal When the map is read-only or its data cannot take the definition.
     */
    public static function createEvent(ProjectMap $map, array $cells, EventTypeDefinition $type, ?string $marker = null): array
    {
        self::assertEditable($map);

        if ($cells === []) {
            throw new EventRefusal('Choose at least one cell for the event.');
        }

        $used = EventMarkers::findUsedMarkers($map);

        if ($marker === null) {
            $marker = EventMarkers::findFreeMarker($used)
                ?? throw new EventRefusal(sprintf('%s has no free event marker left.', $map->mapId));
        } elseif (($reason = EventMarkers::findInvalidReason($marker)) !== null) {
            throw new EventRefusal($reason);
        } elseif (in_array($marker, $used, true)) {
            throw new EventRefusal(sprintf('Marker %s is already an event on %s.', $marker, $map->mapId));
        }

        foreach ($cells as [$x, $y]) {
            if (! $map->hasLayerCell(MapLayers::EVENT, $x, $y)) {
                throw new EventRefusal(sprintf('(%d, %d) is outside %s.', $x, $y, $map->mapId));
            }

            $occupant = $map->getEventMarkerAt($x, $y);

            if ($occupant !== null) {
                throw new EventRefusal(sprintf('Marker %s already holds (%d, %d).', $occupant, $x, $y));
            }
        }

        $command = self::recordEdit($map, 'Event create', static function () use ($map, $cells, $type, $marker): void {
            // The definition first: it is the half the data source can
            // refuse, and refusing it leaves nothing painted.
            $map->setEventDefinition($marker, $type->buildDefinition(null));

            foreach ($cells as [$x, $y]) {
                $map->setEventSymbol($x, $y, $marker);
            }
        });

        return ['marker' => $marker, 'command' => $command ?? throw new EventRefusal('The event could not be placed.')];
    }

    /**
     * Makes an event the given type ({@see EventTypeDefinition::buildDefinition()}).
     * Null when it already was exactly that.
     *
     * @throws EventRefusal When the map has no such event.
     * @throws MapSourceRefusal When the map's data cannot take the definition.
     */
    public static function setEventType(ProjectMap $map, string $marker, EventTypeDefinition $type): ?Command
    {
        self::assertEditable($map);
        self::assertEventExists($map, $marker);
        $current = $map->getEventDefinition($marker);
        $definition = $type->buildDefinition($current);

        if ($definition === $current) {
            return null;
        }

        $map->setEventDefinition($marker, $definition);

        return new GenericCommand(
            'Event type change',
            static fn() => $map->setEventDefinition($marker, $definition),
            static function () use ($map, $marker, $current): void {
                if (is_array($current)) {
                    $map->setEventDefinition($marker, $current);
                    return;
                }

                $map->removeEventDefinition($marker);
            },
        );
    }

    /**
     * Deletes an event: its cells cleared and its definition removed, as one
     * undo step. A marker painted without a definition, or defined without
     * cells, is deleted the same way.
     *
     * @throws EventRefusal When the map has no such event.
     * @throws MapSourceRefusal When the map is read-only or its data cannot drop the definition.
     */
    public static function deleteEvent(ProjectMap $map, string $marker): Command
    {
        self::assertEditable($map);
        self::assertEventExists($map, $marker);

        return self::recordEdit($map, 'Event delete', static function () use ($map, $marker): void {
            $map->removeEventDefinition($marker);

            foreach ($map->getEventArea($marker)?->cells ?? [] as [$x, $y]) {
                $map->setEventSymbol($x, $y, ' ');
            }
        }) ?? throw new EventRefusal(sprintf('Event %s could not be deleted.', $marker));
    }

    /**
     * Moves every cell of an event by an offset, keeping its shape. Null
     * when the offset is nothing.
     *
     * @throws EventRefusal When a cell would leave the map or cover another event.
     */
    public static function moveEvent(ProjectMap $map, string $marker, int $deltaX, int $deltaY, string $label = 'Event move'): ?Command
    {
        self::assertEditable($map);

        if ($deltaX === 0 && $deltaY === 0) {
            return null;
        }

        return self::recordEdit($map, $label, static function () use ($map, $marker, $deltaX, $deltaY): void {
            $refusal = $map->moveEventCells($marker, $deltaX, $deltaY);

            if ($refusal !== null) {
                throw new EventRefusal($refusal);
            }
        });
    }

    /**
     * Repaints an event as exactly a rectangle. Null when it already was.
     *
     * @throws EventRefusal When the event is not placed, or the rectangle is empty, leaves the map or covers another event.
     */
    public static function setEventBounds(ProjectMap $map, string $marker, int $x, int $y, int $width, int $height,
        string $label = 'Event bounds edit'): ?Command
    {
        self::assertEditable($map);

        if ($map->getEventArea($marker) === null) {
            throw new EventRefusal(sprintf('Marker %s is not placed.', $marker));
        }

        return self::recordEdit($map, $label, static function () use ($map, $marker, $x, $y, $width, $height): void {
            $refusal = $map->setEventBounds($marker, $x, $y, $width, $height);

            if ($refusal !== null) {
                throw new EventRefusal($refusal);
            }
        });
    }

    /**
     * Refuses an edit to a map whose grids cannot be saved, before anything
     * else is asked of it.
     *
     * @throws MapSourceRefusal
     */
    private static function assertEditable(ProjectMap $map): void
    {
        if ($map->getGridSourceIssue() !== null) {
            throw new MapSourceRefusal(sprintf('%s is read-only: %s', $map->mapId, $map->getGridSourceIssue()));
        }
    }

    /** @throws EventRefusal When the marker is neither placed nor defined. */
    private static function assertEventExists(ProjectMap $map, string $marker): void
    {
        if ($map->getEventDefinition($marker) === null && $map->getEventArea($marker) === null) {
            throw new EventRefusal(sprintf('%s has no event %s.', $map->mapId, $marker));
        }
    }

    /**
     * Runs an edit and returns the command that redoes and undoes exactly
     * what it changed: the event layer cells and the map's events. Null when
     * it changed nothing. An edit that throws must have changed nothing.
     *
     * @param callable(): void $edit
     */
    private static function recordEdit(ProjectMap $map, string $label, callable $edit): ?Command
    {
        $before = self::captureEventSymbols($map);
        $hadEvents = $map->hasMapDataField(['events']);
        $eventsBefore = $map->getMapDataField(['events']);

        $edit();

        $cells = [];
        foreach (self::captureEventSymbols($map) as $y => $row) {
            foreach ($row as $x => $symbol) {
                if ($symbol !== $before[$y][$x]) {
                    $cells[] = [$x, $y, $before[$y][$x], $symbol];
                }
            }
        }
        $hasEvents = $map->hasMapDataField(['events']);
        $eventsAfter = $map->getMapDataField(['events']);

        if ($cells === [] && $hasEvents === $hadEvents && $eventsAfter === $eventsBefore) {
            return null;
        }

        return new GenericCommand(
            $label,
            static function () use ($map, $cells, $hasEvents, $eventsAfter): void {
                $map->setMapDataField(['events'], $hasEvents ? $eventsAfter : null);
                foreach ($cells as [$x, $y, , $symbol]) {
                    $map->setEventSymbol($x, $y, $symbol);
                }
            },
            static function () use ($map, $cells, $hadEvents, $eventsBefore): void {
                $map->setMapDataField(['events'], $hadEvents ? $eventsBefore : null);
                foreach ($cells as [$x, $y, $symbol]) {
                    $map->setEventSymbol($x, $y, $symbol);
                }
            },
        );
    }

    /** @return list<list<string>> The event layer's symbols, row by row. */
    private static function captureEventSymbols(ProjectMap $map): array
    {
        $rows = [];
        for ($y = 0; $y < $map->getHeight(); $y++) {
            $row = [];
            for ($x = 0; $x < $map->getWidth(); $x++) {
                $row[] = $map->getEventSymbol($x, $y);
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
