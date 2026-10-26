<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\Events\EventAuthoring;
use Ichiloto\Editor\Events\EventRefusal;
use Ichiloto\Editor\Field\MapAreaAuthoring;
use Ichiloto\Editor\Field\MapAreaRefusal;
use Ichiloto\Editor\Field\WorldObjectAuthoring;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Core\CellArea;
use InvalidArgumentException;

/**
 * The inspector rows for a map's named areas, the same rows in the Terminal
 * and in a graphical editor: the list of areas, each renamed and deleted
 * here, and on each event the subject it is bound to, chosen among the map's
 * world objects and areas, unsaved ones included. An area's cells are chosen
 * on the map itself, by each editor's own selection: keyboard cells and
 * rectangles in the Terminal, painting in a graphical editor.
 *
 * @package Ichiloto\Editor\Inspector
 */
final readonly class MapAreaFields
{
    /** Rows of the map's area list. */
    public const string TARGET = 'map-areas';
    /** An event's subject row. */
    public const string SUBJECT_TARGET = 'event-subject';
    /** The field of an area's id row. */
    public const string ID_FIELD = 'id';
    /** The subject row's value for an event triggered by its own painted cells. */
    public const string UNBOUND = '';

    /** @return list<array<string, mixed>> */
    public static function describeMapFields(ProjectMap $map): array
    {
        try {
            $areas = MapAreaAuthoring::describeAreas($map);
        } catch (MapAreaRefusal $error) {
            return [['label' => 'Areas', 'value' => '', 'editable' => false],
                ['label' => '  ! Read-only', 'value' => $error->getMessage(), 'editable' => false]];
        }
        $fields = [['label' => sprintf('Areas · %d', count($areas)), 'value' => count($areas) === 0 ? 'Select cells on the map to make one' : '',
            'editable' => false, 'target' => self::TARGET]];
        foreach ($areas as $index => $area) {
            // Entries are removed here and made on the map, from selected cells.
            $key = ['target' => self::TARGET, 'index' => $index, 'areaList' => ['index' => $index], 'listAddsOnMap' => true];
            $references = MapAreaAuthoring::describeReferences($map, $area['id']);
            array_push($fields,
                ['label' => '  Area', 'value' => $area['id'], 'control' => new InputControl(InputControlType::TEXT, $area['id']),
                    'field' => self::ID_FIELD, ...$key],
                ['label' => '    Cells', 'value' => self::describeCells($area['area'], $area['cells']), 'editable' => false, 'field' => 'cells',
                    'areaCells' => $area['cells'], ...$key],
                ['label' => '    Named By', 'value' => $references === [] ? 'nothing' : implode('; ', $references), 'editable' => false,
                    'field' => 'references', ...$key],
            );
        }

        return $fields;
    }

    /**
     * An event's subject row: its own painted cells, or a world object or
     * area of the map whose cells it covers. An event without a definition
     * has nothing to bind.
     *
     * @return list<array<string, mixed>>
     */
    public static function describeSubjectFields(ProjectMap $map, string $marker): array
    {
        if ($map->getEventDefinition($marker) === null) {
            return [];
        }
        $subject = $map->getEventDefinition($marker)['subject'] ?? null;
        $current = is_array($subject) && is_string($subject['kind'] ?? null) && is_string($subject['id'] ?? null)
            ? self::encodeSubject($subject['kind'], $subject['id']) : self::UNBOUND;
        $choices = [['label' => 'Its own cells', 'value' => self::UNBOUND, 'description' => 'It triggers where its marker is painted.']];
        try {
            foreach (WorldObjectAuthoring::readEntries($map) as $entry) {
                if (is_string($entry['id'] ?? null)) {
                    $choices[] = ['label' => 'World object ' . $entry['id'], 'value' => self::encodeSubject(WorldObjectAuthoring::SUBJECT_KIND, $entry['id']),
                        'description' => 'It moves with the object.'];
                }
            }
            foreach (MapAreaAuthoring::readEntries($map) as $entry) {
                if (is_string($entry['id'] ?? null)) {
                    $choices[] = ['label' => 'Area ' . $entry['id'], 'value' => self::encodeSubject(MapAreaAuthoring::SUBJECT_KIND, $entry['id']),
                        'description' => 'It covers the area\'s cells.'];
                }
            }
        } catch (InvalidArgumentException|MapAreaRefusal $error) {
            return [['label' => 'Bound To', 'value' => '! ' . $error->getMessage(), 'editable' => false]];
        }

        return [['label' => 'Bound To', 'value' => $current, 'editable' => true, 'choices' => $choices,
            'options' => array_column($choices, 'value'), 'target' => self::SUBJECT_TARGET, 'marker' => $marker]];
    }

    /**
     * Applies one row's edit: an area's new id, which its events and
     * landmarks follow, or an event's subject. Choosing its own cells unbinds
     * an event onto the cells its subject gave it. Null when nothing changed.
     *
     * @param array<string, mixed> $field
     * @throws InspectorRefusal When the edit cannot be made.
     */
    public static function applyValue(ProjectMap $map, array $field, string $value): ?Command
    {
        try {
            if (($field['target'] ?? null) === self::SUBJECT_TARGET) {
                return self::applySubject($map, (string) $field['marker'], $value);
            }
            $area = self::readArea($map, $field);

            return ($field['field'] ?? null) === self::ID_FIELD ? new MapAreaAuthoring($map)->renameArea($area, trim($value)) : null;
        } catch (MapAreaRefusal|EventRefusal $refusal) {
            throw new InspectorRefusal($refusal->getMessage(), previous: $refusal);
        }
    }

    /**
     * Deletes the area a row belongs to.
     *
     * @param array<string, mixed> $field
     * @throws InspectorRefusal When something names the area.
     */
    public static function removeEntry(ProjectMap $map, array $field): InspectorListEdit
    {
        try {
            $id = self::readArea($map, $field);

            return new InspectorListEdit(new MapAreaAuthoring($map)->deleteArea($id), sprintf('Deleted area %s.', $id));
        } catch (MapAreaRefusal $refusal) {
            throw new InspectorRefusal($refusal->getMessage(), previous: $refusal);
        }
    }

    /** A subject choice's value: its kind, a colon, then its id. */
    public static function encodeSubject(string $kind, string $id): string
    {
        return $kind . ':' . $id;
    }

    /**
     * What an author reads for an area's cells: a rectangle by its corner and
     * size, exact cells by their count and how many places they fall in.
     *
     * @param array<string, mixed> $shape
     * @param list<array{int, int}> $cells
     */
    public static function describeCells(array $shape, array $cells): string
    {
        if (! array_key_exists('cells', $shape)) {
            return sprintf('x %d, y %d · %d x %d', $shape['x'] ?? 0, $shape['y'] ?? 0, $shape['width'] ?? 0, $shape['height'] ?? 0);
        }
        $pieces = count(CellArea::fromCells($cells)->findPieces());

        return sprintf('%d %s in %d %s', count($cells), count($cells) === 1 ? 'cell' : 'cells', $pieces, $pieces === 1 ? 'place' : 'places');
    }

    /**
     * @throws MapAreaRefusal|EventRefusal
     */
    private static function applySubject(ProjectMap $map, string $marker, string $value): ?Command
    {
        if ($value === self::UNBOUND) {
            if (! array_key_exists('subject', $map->getEventDefinition($marker) ?? [])) {
                return null;
            }
            $cells = $map->getEventTriggerArea($marker)?->cells
                ?? throw new EventRefusal(sprintf('Event %s has no cells of its subject to keep; paint its marker, then unbind it.', $marker));

            return EventAuthoring::unbindEventSubject($map, $marker, $cells);
        }
        [$kind, $id] = array_pad(explode(':', $value, 2), 2, '');

        return EventAuthoring::bindEventSubject($map, $marker, $kind, $id);
    }

    /**
     * @param array<string, mixed> $field
     * @throws MapAreaRefusal When the row's area is gone.
     */
    private static function readArea(ProjectMap $map, array $field): string
    {
        $entries = MapAreaAuthoring::readEntries($map);
        $index = (int) ($field['areaList']['index'] ?? $field['index'] ?? -1);

        return is_string($entries[$index]['id'] ?? null) ? $entries[$index]['id']
            : throw new MapAreaRefusal('That area is no longer on the map; read the map again.');
    }
}
