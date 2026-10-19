<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use InvalidArgumentException;

/** Renderer-neutral physical painting; glyph and graphical tool behavior stays separate. */
final class PhysicalOccupancyEditor
{
    /** @return list<array{value: int, label: string}> */
    public static function getCollisionChoices(): array
    {
        return array_values(array_map(static fn(CollisionType $type): array => [
            'value' => $type->value,
            'label' => ucwords(strtolower(str_replace('_', ' ', $type->name))),
        ], array_filter(CollisionType::cases(), static fn(CollisionType $type): bool => $type !== CollisionType::PASS_THROUGH)));
    }

    /** @return array{rows: int[][], types: list<array{value: int, label: string}>, issue: ?string} */
    public static function readOccupancy(ProjectMap $map): array
    {
        $types = self::getCollisionChoices();
        try {
            if ($map->getGridSourceIssue() !== null) {
                throw new MapSourceRefusal($map->getGridSourceIssue());
            }
            return ['rows' => $map->getResolvedCollisionMap(), 'types' => $types, 'issue' => null];
        } catch (InvalidArgumentException | MapSourceRefusal $error) {
            return ['rows' => [], 'types' => $types, 'issue' => $error->getMessage()];
        }
    }

    /**
     * Applies one already-validated stroke and returns its unrecorded history entry.
     * History restores only occupancy, through the same source-preserving writer.
     *
     * @param list<array{0: int, 1: int}> $cells
     * @return array{changed: int, command: ?Command}
     */
    public static function applyPaint(ProjectMap $map, array $cells, int $collision, string $label = 'Paint collision'): array
    {
        $type = CollisionType::tryFrom($collision);
        if ($type === null || $type === CollisionType::PASS_THROUGH) {
            throw new MapSourceRefusal('Choose a final CollisionType value. Nothing was changed.');
        }
        $path = [MapPhysicalOccupancy::DATA_KEY];
        $before = $map->getMapDataField($path);
        $changed = $map->paintPhysicalOccupancy($cells, $type);
        $after = $map->getMapDataField($path);

        return ['changed' => $changed, 'command' => $changed === 0 ? null : new GenericCommand($label,
            static fn() => $map->setMapDataField($path, $after),
            static fn() => $map->setMapDataField($path, $before),
        )];
    }

    /** @return list<list<int|null>>|null */
    public static function exportRecipe(TilesetPiece $piece): ?array
    {
        return $piece->occupancy === null ? null : array_map(static fn(array $row): array =>
            array_map(static fn(?CollisionType $type): ?int => $type?->value, $row), $piece->occupancy);
    }

    /**
     * Preview and commit use exactly the same authored mask. Transparent mask
     * cells never acquire collision from artwork or terminal glyphs.
     * @return list<array{0: int, 1: int, 2: CollisionType}>
     */
    public static function getPieceWrites(ProjectMap $map, TilesetPiece $piece, int $x, int $y): array
    {
        $map->assertSourcesUnchanged();
        if ($piece->occupancy === null) {
            throw new MapSourceRefusal('This piece has no physical footprint. Define and save one in its tileset first. Nothing was changed.');
        }
        if (! $map->hasMapDataField([MapPhysicalOccupancy::DATA_KEY])) {
            throw new MapSourceRefusal('Physical occupancy must be explicitly migrated before stamping. Nothing was changed.');
        }
        $rows = $map->getResolvedCollisionMap();
        if (! isset($rows[$y][$x])) {
            throw new MapSourceRefusal("Physical origin {$x}, {$y} is outside the map. Nothing was changed.");
        }
        $writes = [];
        foreach ($piece->occupancy as $dy => $row) {
            foreach ($row as $dx => $type) {
                if ($type === null) {
                    continue;
                }
                if (! isset($rows[$y + $dy][$x + $dx])) {
                    throw new MapSourceRefusal('The whole physical footprint must fit the map. Nothing was changed.');
                }
                $writes[] = [$x + $dx, $y + $dy, $type];
            }
        }

        return $writes;
    }

    /** @return array{changed: int, command: ?Command} */
    public static function applyPiece(ProjectMap $map, TilesetPiece $piece, int $x, int $y): array
    {
        $writes = self::getPieceWrites($map, $piece, $x, $y);
        $path = [MapPhysicalOccupancy::DATA_KEY];
        $before = $map->getMapDataField($path);
        $changed = $map->writePhysicalOccupancy($writes);
        $after = $map->getMapDataField($path);

        return ['changed' => $changed, 'command' => $changed === 0 ? null : new GenericCommand('Stamp physical footprint',
            static fn() => $map->setMapDataField($path, $after),
            static fn() => $map->setMapDataField($path, $before),
        )];
    }

    /** @return list<array{0: int, 1: int}> */
    public static function getFillRegion(ProjectMap $map, int $x, int $y): array
    {
        $map->assertSourcesUnchanged();
        $occupancy = self::readOccupancy($map);
        if ($occupancy['issue'] !== null) {
            throw new MapSourceRefusal($occupancy['issue']);
        }
        $rows = $occupancy['rows'];
        if (! isset($rows[$y][$x])) {
            throw new MapSourceRefusal("Physical cell {$x}, {$y} is outside the map.");
        }
        // A missing ragged cell has no collision identity, so fill cannot cross it.
        $identityAt = static fn(int $x, int $y): string => isset($rows[$y][$x]) ? (string) $rows[$y][$x] : 'missing';

        return array_map(static fn(array $cell): array => [$cell['x'], $cell['y']],
            ToolGeometry::floodFill($identityAt, $map->getWidth(), $map->getHeight(), $x, $y));
    }
}
