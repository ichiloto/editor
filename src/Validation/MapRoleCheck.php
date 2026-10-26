<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapRoleCatalog;
use Ichiloto\Engine\Field\MapRoute;
use Ichiloto\Engine\Field\MapRouteGraph;
use Ichiloto\Engine\Field\MapSourceReader;
use InvalidArgumentException;
use Throwable;

/**
 * Holds every way off a map to its role's destinations, as the project's Map Roles author them: a map whose role
 * leads only to some roles (an overworld leading to outside maps and dungeons) may not lead to a map of another
 * (an interior). The ways are the Engine's own routes ({@see MapRouteGraph::readMap()}): transfer events, edge
 * triggers and reusable scripted transports, from events and NPC pages alike. A map naming a role the project does
 * not have is an error; a destination without a role cannot be checked, which is said. Doors to maps that do not
 * exist and unreadable maps are left to the checks that own them.
 */
final class MapRoleCheck
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
            $role = MapRoleCatalog::readMapRole($map->getEditableData());
        } catch (InvalidArgumentException $error) {
            return [Issue::error($map->mapId, 'Role: ' . $error->getMessage(), 'Choose its role on the Role row.', 'map.role')];
        }
        if ($role === null) {
            return [];
        }
        try {
            $roles = $map->loadMapRoles();
        } catch (InvalidArgumentException $error) {
            return [Issue::warning($map->mapId, 'Its role was not checked: the map roles cannot be read: ' . $error->getMessage())];
        }
        if (! $roles->hasRole($role)) {
            return [Issue::error($map->mapId, sprintf('Its role %s is not a map role.', $role),
                'Choose one on the Role row, or make it on the Map Roles page.', 'map.role')];
        }
        $allowed = $roles->getDestinationRoles($role);
        if ($allowed === null) {
            return [];
        }

        $issues = [];
        foreach (self::readRoutes($map) as $route) {
            $destination = $mapsById[$route->destination] ?? null;
            if (! $destination instanceof ProjectMap) {
                continue;
            }
            try {
                $destinationRole = MapRoleCatalog::readMapRole($destination->getEditableData());
            } catch (InvalidArgumentException) {
                continue; // Reported on its own map.
            }
            $way = self::describeWay($route);
            if ($destinationRole === null) {
                $issues[] = Issue::warning($map->mapId, sprintf('%s leads to %s, which has no role, so it was not checked against %s.',
                    $way, $route->destination, $roles->getName($role)),
                    'Give that map its role on its Role row.');
                continue;
            }
            if (! $roles->hasRole($destinationRole) || $roles->allowsDestination($role, $destinationRole)) {
                continue;
            }
            $issues[] = Issue::error($map->mapId, sprintf('%s leads to %s, whose role is %s; %s maps lead only to %s maps.',
                $way, $route->destination, $roles->getName($destinationRole), $roles->getName($role),
                self::listNames($roles, $allowed)),
                'Lead it to a map of one of those roles, such as an outside map whose door enters this one.', 'map.role-destination');
        }

        return $issues;
    }

    /** @return list<MapRoute> The map's routes as the Engine reads them; none when its source cannot be read. */
    private static function readRoutes(ProjectMap $map): array
    {
        try {
            $source = MapSourceReader::readDeclarations($map->mapId, $map->getEditableData(), $map->getLayerSet(),
                $map->getEventLayerCells());
            $categories = null;
            try {
                $categories = $map->loadMapCategories();
            } catch (InvalidArgumentException) {
                // Landmarks only name routes; the place categories are checked where they are authored.
            }

            return MapRouteGraph::readMap($map->mapId, $source['data'], $map->getEventLayerCells(), $map->getAssetRoot(), $categories);
        } catch (Throwable) {
            return [];
        }
    }

    private static function describeWay(MapRoute $route): string
    {
        return match (true) {
            str_starts_with($route->marker, '@legacy:') => sprintf('Edge trigger %s', substr($route->marker, strlen('@legacy:'))),
            $route->npcId !== null => sprintf('NPC %s', $route->npcId),
            default => sprintf('Event %s', $route->marker),
        };
    }

    /** @param list<string> $ids */
    private static function listNames(MapRoleCatalog $roles, array $ids): string
    {
        $names = array_map($roles->getName(...), $ids);
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' or ' . $last;
    }
}
