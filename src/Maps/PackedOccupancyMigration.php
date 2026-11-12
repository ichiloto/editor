<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use RuntimeException;

/** An explicit, reversible source encoding change with no physical edits. */
final class PackedOccupancyMigration
{
    public static function plan(ProjectMap $map, string $root): ?SourceSetPlan
    {
        $map->assertSourcesUnchanged();
        $data = $map->data;
        $key = MapPhysicalOccupancy::DATA_KEY;
        if (!array_key_exists($key, $data)) {
            throw new MapSourceRefusal('Separate collision before packing the map occupancy.');
        }
        $layers = $map->getLayerSet();
        $occupancy = new MapPhysicalOccupancy($data[$key], $layers, $map->mapId . ' occupancy');
        $packed = $occupancy->exportPackedDeclaration();
        if ($data[$key] === $packed) {
            return null;
        }

        $layerDirectory = $map->directory . '/' . MapLayerSource::DIRECTORY;
        $paths = static fn(): array => [$map->dataPath, ...(is_dir($layerDirectory)
            ? (glob($layerDirectory . '/*.{map,deco}.php', GLOB_BRACE) ?: [])
            : [$map->mapPath])];
        $watched = [];
        foreach ($paths() as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                throw new MapSourceRefusal("Could not read {$path} for the occupancy migration.");
            }
            $watched[$path] = $source;
        }
        $path = $map->dataPath;
        $document = PhpArraySourceDocument::parse($watched[$path]);
        $proposal = ArraySourceWriter::replaceLiteralEnumRows($document, [$key], $occupancy->exportDeclaration(), $packed)->source;
        $after = $data;
        $after[$key] = $packed;
        $map->assertSourcesUnchanged();

        return new SourceSetPlan($root, $watched, [$path => $watched[$path]], [$path => $proposal],
            [$path => PhpDataFile::getComparableValue($data)], [$path => PhpDataFile::getComparableValue($after)], $paths,
            static function (string $destination, string $staged) use ($root, $layers, $occupancy, $key): mixed {
                $value = PhpDataFile::evaluateIsolated($staged, $root);
                if (!is_array($value) || (new MapPhysicalOccupancy($value[$key] ?? null, $layers))->collisionGrid !== $occupancy->collisionGrid) {
                    throw new RuntimeException("{$destination} would change physical occupancy.");
                }
                return PhpDataFile::getComparableValue($value);
            },
            'packed occupancy migration', 'Map data and logical layers');
    }
}
