<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\PlaceTypeCatalog;
use Ichiloto\Engine\Field\Validation\MapEventResolver;
use Ichiloto\Engine\Field\Validation\MapPlaceIconRule;
use Ichiloto\Engine\Field\Validation\MapValidationIssue;
use InvalidArgumentException;
use Throwable;

/**
 * Holds a map's place type to the project's Place Types, and every entrance on a map whose tileset gives place
 * icons, such as a world map, to the icon of its destination's type: the Engine's {@see MapPlaceIconRule}, read
 * against the maps as the workspace holds them, unsaved edits included. A map naming a type the project does not
 * have is an error; an icon that does not show its place's type is a warning, as the map still plays. Doors to maps
 * that do not exist and unreadable maps are left to the checks that own them.
 */
final class PlaceIconCheck
{
    private function __construct()
    {
    }

    /**
     * @param array<string, ProjectMap> $mapsById Every workspace map keyed by map id.
     * @return list<Issue>
     */
    public static function validate(ProjectMap $map, array $mapsById): array
    {
        try {
            $type = PlaceTypeCatalog::readMapPlaceType($map->getEditableData());
        } catch (InvalidArgumentException $error) {
            return [Issue::error($map->mapId, 'Place type: ' . $error->getMessage(), 'Choose its type on the Place Type row.', 'map.place-type')];
        }
        try {
            $types = $map->loadPlaceTypes();
        } catch (InvalidArgumentException $error) {
            return [Issue::warning($map->mapId, 'Its place type and icons were not checked: the place types cannot be read: ' . $error->getMessage())];
        }
        $issues = [];
        if ($type !== null && ! $types->hasType($type)) {
            $issues[] = Issue::error($map->mapId, sprintf('Its place type %s is not a place type.', $type),
                'Choose one on the Place Type row, or make it on the Place Types page.', 'map.place-type');
        }

        return [...$issues, ...self::findIconIssues($map, $mapsById, $types)];
    }

    /**
     * The Engine's findings for the map's entrances, worded for an author.
     *
     * @param array<string, ProjectMap> $mapsById
     * @return list<Issue>
     */
    private static function findIconIssues(ProjectMap $map, array $mapsById, PlaceTypeCatalog $types): array
    {
        try {
            // Only a map whose tileset gives place icons shows them; the rest are not read further.
            if (($map->loadTileset()?->placeIcons ?? []) === [] || ($graphics = $map->loadGraphics()) === null) {
                return [];
            }
            $events = MapEventResolver::inspect($map->getEditableData(), $map->getEventLayerCells(), $map->mapId)->events;
            $found = MapPlaceIconRule::inspect($map->mapId, $events, $map->getLayerSet(), $graphics, $types,
                self::readPlaceTypes($mapsById));
        } catch (Throwable) {
            return []; // The map's own reading checks say why it cannot be read.
        }

        return array_map(static fn(MapValidationIssue $issue): Issue => new Issue(Severity::WARNING,
            $issue->marker === null ? $map->mapId : sprintf('%s event %s', $map->mapId, $issue->marker),
            $issue->message, $issue->hint, $issue->rule, $map->mapId, $issue->cells), $found);
    }

    /**
     * Every readable map's place type by map id; a map whose type cannot be read is left out, as its own check
     * reports it.
     *
     * @param array<string, ProjectMap> $mapsById
     * @return array<string, ?string>
     */
    private static function readPlaceTypes(array $mapsById): array
    {
        $types = [];
        foreach ($mapsById as $id => $map) {
            try {
                $types[$id] = PlaceTypeCatalog::readMapPlaceType($map->getEditableData());
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $types;
    }
}
