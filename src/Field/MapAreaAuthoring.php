<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Field\MapAreaCatalog;
use InvalidArgumentException;

/**
 * Authoring a map's named areas: logical territory the map owns, with no art,
 * collision or discovery of its own, that events bind to as their subject and
 * landmarks anchor to.
 *
 * The map's `areas` list holds `['id' => ..., 'area' => ...]` entries, the
 * area in the Engine's CellArea shape: exact `cells`, or `x`, `y`, `width`
 * and `height`. Every edit is checked by the Engine's own reader
 * ({@see MapAreaCatalog}) against the map's physical grid before it lands,
 * and is one undo step. Renaming an area rewrites the events and landmarks
 * that name it in the same step; deleting one they name is refused, naming
 * them.
 *
 * @package Ichiloto\Editor\Field
 */
final readonly class MapAreaAuthoring
{
    /** The map key holding the declarations. */
    public const string KEY = 'areas';

    /** The event subject kind that names an area, one of {@see \Ichiloto\Engine\Events\MapEventDefinition::SUBJECT_KINDS}. */
    public const string SUBJECT_KIND = 'area';

    /** The landmark anchor kind that names an area, one of the Engine's landmark anchor kinds. */
    public const string ANCHOR_KIND = 'area';

    public function __construct(private ProjectMap $map)
    {
    }

    /**
     * The map's area declarations as authored, unsaved edits included.
     *
     * @return list<array<string, mixed>>
     * @throws MapAreaRefusal When the authored block is not a list of declarations.
     */
    public static function readEntries(ProjectMap $map): array
    {
        $entries = $map->getMapDataField([self::KEY]) ?? [];
        if (! is_array($entries) || ! array_is_list($entries) || array_any($entries, static fn($entry): bool => ! is_array($entry))) {
            throw new MapAreaRefusal(sprintf('%s: areas must be a list of declarations; it cannot be edited as areas.', $map->mapId));
        }

        return $entries;
    }

    /**
     * The map's areas as the Engine reads them: each id with its cells.
     *
     * @throws MapAreaRefusal With the Engine's reason when the map would not load them.
     */
    public static function readCatalog(ProjectMap $map): MapAreaCatalog
    {
        return self::readCatalogOf($map, self::readEntries($map));
    }

    /**
     * The areas as an author reads them: each id, its shape as written, and
     * the cells it covers.
     *
     * @return list<array{id: string, area: array<string, mixed>, cells: list<array{int, int}>}>
     */
    public static function describeAreas(ProjectMap $map): array
    {
        $catalog = self::readCatalog($map);
        $described = [];
        foreach (self::readEntries($map) as $entry) {
            $id = (string) $entry['id'];
            $described[] = ['id' => $id, 'area' => (array) $entry['area'],
                'cells' => array_map(static fn(array $cell): array => [$cell[0], $cell[1]], $catalog->getArea($id)->cells)];
        }

        return $described;
    }

    /**
     * Declares a new area. The shape is the Engine's CellArea shape.
     *
     * @param array<string, mixed> $area
     * @throws MapAreaRefusal When the id is taken or not an area id, or the area is empty, malformed or off the map.
     */
    public function createArea(string $id, array $area): Command
    {
        $entries = self::readEntries($this->map);
        if (self::findIndex($entries, $id) !== null) {
            throw new MapAreaRefusal(sprintf('%s already has an area %s.', $this->map->mapId, $id));
        }
        $entries[] = ['id' => $id, 'area' => $area];

        return $this->record('Create map area', fn() => $this->writeEntries($entries))
            ?? throw new MapAreaRefusal(sprintf('Area %s could not be created.', $id));
    }

    /**
     * Gives an area a new shape. Null when it already had it.
     *
     * @param array<string, mixed> $area
     * @throws MapAreaRefusal When there is no such area, or the area is empty, malformed or off the map.
     */
    public function setArea(string $id, array $area): ?Command
    {
        $entries = self::readEntries($this->map);
        $index = $this->requireIndex($entries, $id);
        $entries[$index]['area'] = $area;

        return $this->record('Reshape map area', fn() => $this->writeEntries($entries));
    }

    /**
     * Gives an area a new id, and every event and landmark naming it the new
     * id, as one undo step. Null when the id is unchanged.
     *
     * @throws MapAreaRefusal When there is no such area, or the new id is taken or not an area id.
     */
    public function renameArea(string $id, string $to): ?Command
    {
        if ($to === $id) {
            return null;
        }
        $entries = self::readEntries($this->map);
        $index = $this->requireIndex($entries, $id);
        if (self::findIndex($entries, $to) !== null) {
            throw new MapAreaRefusal(sprintf('%s already has an area %s.', $this->map->mapId, $to));
        }
        $entries[$index]['id'] = $to;

        return $this->record('Rename map area', function () use ($entries, $id, $to): void {
            $this->writeEntries($entries);
            foreach ($this->map->getEventDefinitions() as $marker => $definition) {
                if (self::namesArea(is_array($definition) ? $definition : [], $id)) {
                    $this->map->setEventField((string) $marker, ['subject', 'id'], $to);
                }
            }
            $landmarks = $this->map->getMapDataField([MapLandmarks::KEY]);
            if (is_array($landmarks)) {
                foreach ($landmarks as $index => $landmark) {
                    if (is_array($landmark) && ($landmark['anchor'] ?? null) === [self::ANCHOR_KIND => $id]) {
                        $landmarks[$index]['anchor'] = [self::ANCHOR_KIND => $to];
                    }
                }
                $this->map->setMapDataField([MapLandmarks::KEY], $landmarks);
            }
        });
    }

    /**
     * Removes an area nothing names.
     *
     * @throws MapAreaRefusal When there is no such area, or an event or landmark names it.
     */
    public function deleteArea(string $id): Command
    {
        $entries = self::readEntries($this->map);
        array_splice($entries, $this->requireIndex($entries, $id), 1);
        $references = self::describeReferences($this->map, $id);
        if ($references !== []) {
            throw new MapAreaRefusal(sprintf("Area %s is named by %s; change those first.\n%s", $id, implode(', ', $references),
                implode("\n", array_map(static fn(string $reference): string => '- ' . $reference, $references))));
        }

        return $this->record('Delete map area', fn() => $this->writeEntries($entries))
            ?? throw new MapAreaRefusal(sprintf('Area %s could not be deleted.', $id));
    }

    /**
     * What names an area: events bound to it as their subject, and landmarks
     * anchored to it.
     *
     * @return list<string>
     */
    public static function describeReferences(ProjectMap $map, string $id): array
    {
        $found = [];
        foreach ($map->getEventDefinitions() as $marker => $definition) {
            if (self::namesArea(is_array($definition) ? $definition : [], $id)) {
                $found[] = sprintf('%s event %s', $map->mapId, $marker);
            }
        }

        return [...$found, ...MapLandmarks::describeAnchorReferences($map, [self::ANCHOR_KIND => $id])];
    }

    /** Whether an event definition is bound to the area as its subject. */
    public static function namesArea(array $definition, string $id): bool
    {
        $subject = $definition['subject'] ?? null;

        return is_array($subject) && ($subject['kind'] ?? null) === self::SUBJECT_KIND && ($subject['id'] ?? null) === $id;
    }

    /**
     * Writes the declarations once the Engine accepts them; an empty list
     * removes the block.
     *
     * @param list<array<string, mixed>> $entries
     * @throws MapAreaRefusal With the Engine's reason when the map would not load them.
     */
    private function writeEntries(array $entries): void
    {
        self::readCatalogOf($this->map, $entries);
        $this->map->setMapDataField([self::KEY], $entries === [] ? null : $entries);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @throws MapAreaRefusal
     */
    private static function readCatalogOf(ProjectMap $map, array $entries): MapAreaCatalog
    {
        try {
            return MapAreaCatalog::readMap([self::KEY => $entries], $map->getLayerSet()->getComposedGrid());
        } catch (InvalidArgumentException $error) {
            throw new MapAreaRefusal(sprintf('%s: %s', $map->mapId, $error->getMessage()), previous: $error);
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private static function findIndex(array $entries, string $id): ?int
    {
        foreach ($entries as $index => $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @throws MapAreaRefusal
     */
    private function requireIndex(array $entries, string $id): int
    {
        return self::findIndex($entries, $id) ?? throw new MapAreaRefusal(sprintf('%s has no area %s.', $this->map->mapId, $id));
    }

    /**
     * Runs an edit of the map's areas, events and landmarks, and returns the
     * command that redoes and undoes exactly it. Null when it changed
     * nothing. An edit that throws has changed nothing.
     *
     * @param callable(): void $edit
     */
    private function record(string $label, callable $edit): ?Command
    {
        $map = $this->map;
        $keys = [self::KEY, 'events', MapLandmarks::KEY];
        $capture = static fn(): array => array_combine($keys, array_map(
            static fn(string $key): array => [$map->hasMapDataField([$key]), $map->getMapDataField([$key])], $keys));
        $restore = static function (array $state) use ($map): void {
            foreach ($state as $key => [$held, $value]) {
                if ($map->getMapDataField([$key]) !== $value || $map->hasMapDataField([$key]) !== $held) {
                    $map->setMapDataField([$key], $held ? $value : null);
                }
            }
        };
        $before = $capture();

        try {
            $edit();
        } catch (\Throwable $failure) {
            $restore($before);

            throw $failure;
        }

        $after = $capture();
        if ($after === $before) {
            return null;
        }

        return new GenericCommand($label, static fn() => $restore($after), static fn() => $restore($before));
    }

    /**
     * The cells an area shape covers, for an interface drawing a selection.
     *
     * @param array<string, mixed> $area
     * @return list<array{int, int}>
     */
    public static function readCells(array $area): array
    {
        return array_map(static fn(array $cell): array => [$cell[0], $cell[1]], CellArea::fromArray($area, 'Map area')->cells);
    }
}
