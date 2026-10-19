<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Engine\Field\MapGraphics;
use InvalidArgumentException;

/**
 * Where a map lives. A map's stable id is its folder under `assets/Maps`, read by the Engine from where its data
 * file sits (`happyville/inn/front/front.data.php` is `happyville/inn/front`), so choosing an id is choosing a
 * folder. A map may live inside another map's folder, as a building's rooms live inside it, or inside a folder that
 * is no map at all.
 *
 * An id an author chooses is checked here before anything is written: each segment is the form a map name slugs
 * to (lowercase letters and digits joined by single hyphens), so no id climbs out of the maps folder, hides as a
 * dot folder or needs quoting where doors name it; nothing may already stand at its folder; and a map folder's own
 * member folders are never taken for a nested map.
 */
final class MapFolder
{
    /** One segment of a map id: the form {@see \Ichiloto\Editor\ProjectMap::slugify()} gives a name. */
    public const string SEGMENT_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /** The folders a map keeps its own members in, which no map nested directly inside it may be called. */
    public const array MEMBER_DIRECTORIES = ['layers', MapGraphics::DIRECTORY];

    private function __construct() {}

    /**
     * An id as an author gave it, refused unless every segment is a map folder name.
     *
     * @throws InvalidArgumentException With what is wrong with it.
     */
    public static function requireAuthoredId(string $mapId): string
    {
        if (trim($mapId) === '') {
            throw new InvalidArgumentException('A map id cannot be empty.');
        }
        foreach (explode('/', $mapId) as $segment) {
            if (preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Map id "%s" is not a folder path under assets/Maps: each part between slashes is lowercase letters and digits joined by single hyphens, such as "town/inn/cellar".',
                    $mapId,
                ));
            }
        }

        return $mapId;
    }

    /**
     * The folder a new map with this id would be written to, once nothing stands in its way.
     *
     * @param string $mapsRoot The project's maps folder.
     * @return string The folder, which does not exist yet.
     * @throws InvalidArgumentException When the id is not a map folder path, or the folder is taken.
     */
    public static function requireFreeFolder(string $mapsRoot, string $mapId): string
    {
        $segments = explode('/', self::requireAuthoredId($mapId));
        $mapsRoot = rtrim($mapsRoot, DIRECTORY_SEPARATOR);
        $parent = $mapsRoot;
        foreach ($segments as $segment) {
            if (in_array($segment, self::MEMBER_DIRECTORIES, true) && self::holdsMap($parent)) {
                throw new InvalidArgumentException(sprintf(
                    'Map id "%s" would put a map in the %s folder of the map at %s, where that map keeps its own files.',
                    $mapId, $segment, substr($parent, strlen($mapsRoot) + 1),
                ));
            }
            $parent .= DIRECTORY_SEPARATOR . $segment;
            if (file_exists($parent) && ! is_dir($parent)) {
                throw new InvalidArgumentException(substr($parent, strlen($mapsRoot) + 1) === $mapId
                    ? sprintf('Map path %s is a file, not a folder a map can be written to.', $mapId)
                    : sprintf('Map id "%s" passes through a file, %s.', $mapId, substr($parent, strlen($mapsRoot) + 1)));
            }
        }
        if (is_dir($parent)) {
            throw new InvalidArgumentException(sprintf('Map path %s already exists.', $mapId));
        }

        return $parent;
    }

    /** Whether a folder is a map's: it holds the data file named after it. */
    private static function holdsMap(string $directory): bool
    {
        return is_file($directory . DIRECTORY_SEPARATOR . basename($directory) . '.data.php');
    }
}
