<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapRoleCatalog;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use InvalidArgumentException;

/**
 * Holds each map to a tileset drawn for its role, as Database > Tilesets authors it. A tileset whose art is drawn at
 * one scale names the map roles it is for (a world map's tileset names the overworld, whose hills and mountains are
 * icons the player walks across); a map of another role that names it reads at the wrong scale, its walkable relief
 * drawn as solid ground beside the player. A tileset that names no roles suits any map. A map without a role cannot be
 * checked, which is said. An unreadable tileset or role is left to the checks that own them, and a tileset naming a
 * role the project does not have is reported on the tileset ({@see self::validateTileset()}).
 */
final class MapTilesetRoleCheck
{
    public const string CODE = 'map.tileset-role';

    private function __construct()
    {
    }

    /** @return list<Issue> */
    public static function validate(ProjectMap $map): array
    {
        try {
            $tileset = $map->loadTileset();
            $role = MapRoleCatalog::readMapRole($map->getEditableData());
        } catch (InvalidArgumentException) {
            return [];
        }
        if ($tileset?->roles === null) {
            return [];
        }
        $roles = self::loadRoles($map);
        if ($role === null) {
            return [Issue::warning($map->mapId, sprintf('Its kind %s is drawn for %s maps, but it has no role, so it was not checked.',
                $tileset->name, self::listNames($roles, $tileset->roles)), 'Give it its role on its Role row.')];
        }
        if ($tileset->isDrawnFor($role)) {
            return [];
        }

        return [Issue::error($map->mapId, sprintf('Its kind %s is drawn for %s maps; it is %s, so its art reads at the wrong scale.',
            $tileset->name, self::listNames($roles, $tileset->roles), self::readName($roles, $role)),
            sprintf('Build it with a kind drawn for %s maps on its Kind row, or give it the role its art is drawn for.', self::readName($roles, $role)),
            self::CODE)];
    }

    /**
     * The roles a tileset names that the project does not have: each would hold no map, so it is refused where the
     * tileset is authored.
     *
     * @return list<Issue>
     */
    public static function validateTileset(Tileset $tileset, MapRoleCatalog $roles, string $where): array
    {
        $unknown = array_values(array_filter($tileset->roles ?? [], static fn(string $role): bool => ! $roles->hasRole($role)));

        return $unknown === [] ? [] : [Issue::error($where, sprintf('Tileset %s is drawn for %s, which %s not a map role.', $tileset->name,
            implode(', ', $unknown), count($unknown) === 1 ? 'is' : 'are'),
            'Choose its roles from the Map Roles page in Database > Tilesets, or make the role there.', 'tileset.roles')];
    }

    private static function loadRoles(ProjectMap $map): ?MapRoleCatalog
    {
        try {
            return $map->loadMapRoles();
        } catch (InvalidArgumentException) {
            return null; // Reported where the roles are read; the ids still name them.
        }
    }

    private static function readName(?MapRoleCatalog $roles, string $id): string
    {
        return $roles !== null && $roles->hasRole($id) ? $roles->getName($id) : $id;
    }

    /** @param list<string> $ids */
    private static function listNames(?MapRoleCatalog $roles, array $ids): string
    {
        $names = array_map(static fn(string $id): string => self::readName($roles, $id), $ids);
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' or ' . $last;
    }
}
