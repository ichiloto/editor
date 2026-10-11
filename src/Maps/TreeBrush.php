<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Field\WorldObjectAuthoring;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Field\WorldObjectDefinition;
use Ichiloto\Engine\Field\WorldObjectResource;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use InvalidArgumentException;
use Throwable;

/**
 * Draws a forest of whole trees over a region, or erases the trees in it, as
 * one undo step.
 *
 * A tree is a placed field resource that stands on the ground: one image with
 * an occupancy stamp, its trunk. Drawing lays out the chosen types over the
 * region in an order fixed by the map and cell, so drawing the same region
 * again plants nothing new, and a region beside existing trees fills the gaps
 * between them instead of crowding them. A trunk goes only on passable ground
 * that no event, NPC or other trunk claims, and never where it would cut a
 * walkable area in two: every walkable cell the player could reach before
 * stays reachable. The map's own declared collision makes each trunk solid;
 * the image never does.
 *
 * The terminal sees the same forest on the `canopy` decoration layer, which
 * the brush owns: bright green `(` `)` crowns narrowing upwards over gray `|`
 * trunks, redrawn wherever trees are drawn or erased. The graphical field
 * leaves decoration layers out, so the crowns never hide the images.
 *
 * @package Ichiloto\Editor\Maps
 */
final class TreeBrush
{
    public const string CANOPY_LAYER = 'canopy';
    private const string CROWN_STYLE = '<fg=bright-green>';
    private const string TRUNK_STYLE = '<fg=gray>';

    /** @var array<string, WorldObjectResource> Tree types by id. */
    private array $types;

    private function __construct(private readonly ProjectMap $map, FieldPresentationCatalog $catalog)
    {
        $this->types = array_filter($catalog->resources, self::isTree(...));
    }

    /**
     * The project's tree types: placeable images with a ground footprint.
     *
     * @return array<string, string> Names by id.
     */
    public static function listTypes(ProjectMap $map): array
    {
        return array_map(static fn(WorldObjectResource $type): string => $type->name,
            array_filter(self::loadCatalog($map)->resources, self::isTree(...)));
    }

    /**
     * Plants the chosen tree types over the region's cells.
     *
     * @param list<string> $types Tree type ids to vary between.
     * @param list<array{0: int, 1: int}> $region
     * @return array{command: ?Command, planted: int, removed: int}
     * @throws MapSourceRefusal When the map cannot take trees or a type is unknown; nothing is changed.
     */
    public static function draw(ProjectMap $map, array $types, array $region): array
    {
        $brush = new self($map, self::loadCatalog($map));
        $chosen = $brush->requireTypes($types);

        return $brush->apply('Draw trees', static fn(array &$entries, array &$solid) => $brush->plant($chosen, $region, $entries, $solid));
    }

    /**
     * Removes every tree standing in the region, clearing its trunk's collision.
     *
     * @param list<array{0: int, 1: int}> $region
     * @return array{command: ?Command, planted: int, removed: int}
     * @throws MapSourceRefusal When the map cannot take trees; nothing is changed.
     */
    public static function erase(ProjectMap $map, array $region): array
    {
        $brush = new self($map, self::loadCatalog($map));

        return $brush->apply('Erase trees', static fn(array &$entries, array &$solid) => $brush->fell($region, $entries, $solid));
    }

    private static function isTree(WorldObjectResource $resource): bool
    {
        return $resource->sprites instanceof GraphicalSpriteDefinition && $resource->sign === null && $resource->occupancyStamp !== [];
    }

    /** The image's width in map cells. */
    private static function getCellsWide(WorldObjectResource $type): int
    {
        return max(1, intdiv($type->sprites->width, FieldViewport::TILE_SIZE));
    }

    /** The image's height in map cells. */
    private static function getCellsTall(WorldObjectResource $type): int
    {
        return max(1, intdiv($type->sprites->height, FieldViewport::TILE_SIZE));
    }

    private static function loadCatalog(ProjectMap $map): FieldPresentationCatalog
    {
        try {
            return FieldPresentationCatalog::load($map->getAssetRoot());
        } catch (Throwable $error) {
            throw new MapSourceRefusal('The field resources cannot be read, so no tree can be drawn: ' . $error->getMessage(), previous: $error);
        }
    }

    /** @return list<WorldObjectResource> */
    private function requireTypes(array $ids): array
    {
        if ($ids === []) {
            throw new MapSourceRefusal('Choose at least one tree type to draw. Nothing was changed.');
        }
        return array_map(fn(string $id): WorldObjectResource => $this->types[$id]
            ?? throw new MapSourceRefusal("'{$id}' is not a tree type: a tree is a field resource image with a trunk stamp. Nothing was changed."),
            array_values(array_unique($ids)));
    }

    /**
     * Runs one change over the map's world objects and solid trunk cells, then
     * redraws the terminal canopy, recording everything as one step.
     *
     * @param callable(array, array): array{0: int, 1: int} $change Mutates the entries and solid writes; returns planted and removed counts.
     * @return array{command: ?Command, planted: int, removed: int}
     */
    private function apply(string $label, callable $change): array
    {
        $map = $this->map;
        $map->assertSourcesUnchanged();
        if (! $map->hasMapDataField([MapPhysicalOccupancy::DATA_KEY])) {
            throw new MapSourceRefusal('Trees need the map\'s own collision so their trunks can be solid. Separate collision first, then draw. Nothing was changed.');
        }
        $entries = $map->hasMapDataField([WorldObjectDefinition::DATA_KEY]) ? $map->getMapDataField([WorldObjectDefinition::DATA_KEY]) : [];
        $writes = [];
        [$planted, $removed] = $change($entries, $writes);
        if ($planted === 0 && $removed === 0) {
            return ['command' => null, 'planted' => 0, 'removed' => 0];
        }
        if (count($entries) > WorldObjectDefinition::MAX_OBJECTS) {
            $room = WorldObjectDefinition::MAX_OBJECTS - (count($entries) - $planted);
            throw new MapSourceRefusal(sprintf('A map holds at most %d world objects; this drawing needs %d more and only %d still fit. Draw a smaller region. Nothing was changed.',
                WorldObjectDefinition::MAX_OBJECTS, $planted, max(0, $room)));
        }

        try {
            WorldObjectAuthoring::validateEntries($map, $entries, false);
        } catch (InvalidArgumentException $invalid) {
            throw new MapSourceRefusal($invalid->getMessage() . ' Nothing was changed.', previous: $invalid);
        }

        $before = $map->captureLayerSnapshot();
        try {
            if (! in_array(self::CANOPY_LAYER, array_column($map->getLayers(), 'name'), true)) {
                $map->createLayer(self::CANOPY_LAYER, true);
            }
            $map->setMapDataField([WorldObjectDefinition::DATA_KEY], $entries);
            $map->writePhysicalOccupancy($writes);
            $this->drawCanopy($entries);
        } catch (Throwable $error) {
            $map->restoreLayerSnapshot($before);
            throw $error instanceof MapSourceRefusal ? $error : new MapSourceRefusal($error->getMessage() . ' Nothing was changed.', previous: $error);
        }
        $after = $map->captureLayerSnapshot();

        return ['command' => new GenericCommand($label,
            static fn() => $map->restoreLayerSnapshot($after),
            static fn() => $map->restoreLayerSnapshot($before)), 'planted' => $planted, 'removed' => $removed];
    }

    /**
     * @param list<WorldObjectResource> $types
     * @param list<array{0: int, 1: int}> $region
     * @return array{0: int, 1: int}
     */
    private function plant(array $types, array $region, array &$entries, array &$writes): array
    {
        $inRegion = [];
        foreach ($region as [$x, $y]) {
            $inRegion[$y][$x] = true;
        }
        $passable = $this->readPassable();
        $claimed = $this->readClaimedCells($entries);
        $anchors = $this->readTreeAnchors($entries);
        $planted = 0;
        foreach ($this->orderCells($region) as [$x, $y]) {
            $type = $types[$this->hash($x, $y, 'type') % count($types)];
            if (! $this->canPlant($type, $x, $y, $inRegion, $passable, $claimed, $anchors)) {
                continue;
            }
            foreach ($type->occupancyStamp as $cell) {
                [$cx, $cy] = [$x + $cell['x'], $y + $cell['y']];
                $writes[] = [$cx, $cy, $cell['type']];
                $claimed[$cy][$cx] = true;
                if ($cell['type'] !== CollisionType::NONE) {
                    unset($passable[$cy][$cx]);
                }
            }
            $anchors[] = [$x, $y, $type];
            $entries[] = ['id' => $this->findUnusedId($entries, $type->id), 'resource' => $type->id, 'anchor' => ['x' => $x, 'y' => $y]];
            $planted++;
        }

        return [$planted, 0];
    }

    /**
     * @param list<array{0: int, 1: int}> $region
     * @return array{0: int, 1: int}
     */
    private function fell(array $region, array &$entries, array &$writes): array
    {
        $inRegion = [];
        foreach ($region as [$x, $y]) {
            $inRegion[$y][$x] = true;
        }
        $kept = $felled = [];
        foreach ($entries as $entry) {
            $type = $this->types[$entry['resource'] ?? ''] ?? null;
            $at = $entry['anchor'] ?? [];
            if ($type !== null && isset($inRegion[$at['y'] ?? -1][$at['x'] ?? -1])) {
                $felled[] = [$at['x'], $at['y'], $type];
            } else {
                $kept[] = $entry;
            }
        }
        if ($felled === []) {
            return [0, 0];
        }
        // A trunk cell another standing tree still stamps keeps its collision.
        $stillStamped = [];
        foreach ($this->readTreeAnchors($kept) as [$x, $y, $type]) {
            foreach ($type->occupancyStamp as $cell) {
                $stillStamped[$y + $cell['y']][$x + $cell['x']] = true;
            }
        }
        foreach ($felled as [$x, $y, $type]) {
            foreach ($type->occupancyStamp as $cell) {
                [$cx, $cy] = [$x + $cell['x'], $y + $cell['y']];
                if (! isset($stillStamped[$cy][$cx])) {
                    $writes[] = [$cx, $cy, CollisionType::NONE];
                }
            }
        }
        $entries = $kept;

        return [0, count($felled)];
    }

    /**
     * Whether a tree fits with its anchor here: every stamped cell inside the
     * region and on passable ground nothing claims, room from other trunks,
     * and no solid cell cutting a walkable area in two.
     *
     * @param array<int, array<int, true>> $inRegion
     * @param array<int, array<int, true>> $passable
     * @param array<int, array<int, true>> $claimed
     * @param list<array{0: int, 1: int, 2: WorldObjectResource}> $anchors
     */
    private function canPlant(WorldObjectResource $type, int $x, int $y, array $inRegion, array $passable, array $claimed, array $anchors): bool
    {
        foreach ($type->occupancyStamp as $cell) {
            [$cx, $cy] = [$x + $cell['x'], $y + $cell['y']];
            if (! isset($inRegion[$cy][$cx], $passable[$cy][$cx]) || isset($claimed[$cy][$cx])) {
                return false;
            }
        }
        foreach ($anchors as [$ax, $ay, $other]) {
            // Crowns may overlap, as in a forest; trunks keep half a crown apart.
            $reachX = intdiv(max(self::getCellsWide($type), self::getCellsWide($other)), 2);
            $reachY = max(1, intdiv(max(self::getCellsTall($type), self::getCellsTall($other)), 3));
            if (abs($ax - $x) <= $reachX && abs($ay - $y) <= $reachY) {
                return false;
            }
        }
        $walkable = $passable;
        foreach ($type->occupancyStamp as $cell) {
            if ($cell['type'] === CollisionType::NONE) {
                continue;
            }
            [$cx, $cy] = [$x + $cell['x'], $y + $cell['y']];
            if (! self::isSimple($walkable, $cx, $cy)) {
                return false;
            }
            unset($walkable[$cy][$cx]);
        }

        return true;
    }

    /**
     * Whether making a walkable cell solid keeps the walkable cells around it
     * connected: its walkable side neighbours must still reach each other
     * through the eight cells around it. Then any path through it can step
     * around it, so no walkable area is cut in two.
     *
     * @param array<int, array<int, true>> $walkable
     */
    private static function isSimple(array $walkable, int $x, int $y): bool
    {
        $open = [];
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if (($dx !== 0 || $dy !== 0) && isset($walkable[$y + $dy][$x + $dx])) {
                    $open["{$dx},{$dy}"] = [$dx, $dy];
                }
            }
        }
        $sides = array_values(array_filter([[0, -1], [1, 0], [0, 1], [-1, 0]], static fn(array $side): bool => isset($open["{$side[0]},{$side[1]}"])));
        if (count($sides) < 2) {
            return true;
        }
        $seen = ["{$sides[0][0]},{$sides[0][1]}" => true];
        $queue = [$sides[0]];
        while ($queue !== []) {
            [$cx, $cy] = array_shift($queue);
            foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$sx, $sy]) {
                $key = ($cx + $sx) . ',' . ($cy + $sy);
                if (isset($open[$key]) && ! isset($seen[$key])) {
                    $seen[$key] = true;
                    $queue[] = $open[$key];
                }
            }
        }
        foreach ($sides as [$sx, $sy]) {
            if (! isset($seen["{$sx},{$sy}"])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, array<int, true>> Cells the player can stand on today. */
    private function readPassable(): array
    {
        $passable = [];
        foreach ($this->map->getResolvedCollisionMap() as $y => $row) {
            foreach ($row as $x => $value) {
                if ($value === CollisionType::NONE->value) {
                    $passable[$y][$x] = true;
                }
            }
        }

        return $passable;
    }

    /**
     * Cells no trunk may take: events, NPCs and every standing tree's stamp.
     *
     * @return array<int, array<int, true>>
     */
    private function readClaimedCells(array $entries): array
    {
        $claimed = [];
        for ($y = 0, $height = $this->map->getHeight(); $y < $height; $y++) {
            for ($x = 0, $width = $this->map->getWidth(); $x < $width; $x++) {
                if ($this->map->hasLayerCell(MapLayers::EVENT, $x, $y) && $this->map->getEventMarkerAt($x, $y) !== null) {
                    $claimed[$y][$x] = true;
                }
            }
        }
        foreach ((array) ($this->map->getEditableData()['npcs'] ?? []) as $npc) {
            if (is_int($npc['x'] ?? null) && is_int($npc['y'] ?? null)) {
                $claimed[$npc['y']][$npc['x']] = true;
            }
        }
        foreach ($this->readTreeAnchors($entries) as [$x, $y, $type]) {
            foreach ($type->occupancyStamp as $cell) {
                $claimed[$y + $cell['y']][$x + $cell['x']] = true;
            }
        }

        return $claimed;
    }

    /** @return list<array{0: int, 1: int, 2: WorldObjectResource}> Every standing tree: its anchor and type. */
    private function readTreeAnchors(array $entries): array
    {
        $anchors = [];
        foreach ($entries as $entry) {
            $type = $this->types[$entry['resource'] ?? ''] ?? null;
            if ($type !== null && is_int($entry['anchor']['x'] ?? null) && is_int($entry['anchor']['y'] ?? null)) {
                $anchors[] = [$entry['anchor']['x'], $entry['anchor']['y'], $type];
            }
        }

        return $anchors;
    }

    /**
     * The region's cells in the order trees are tried: fixed by the map and
     * cell, so the same region always grows the same forest.
     *
     * @param list<array{0: int, 1: int}> $region
     * @return list<array{0: int, 1: int}>
     */
    private function orderCells(array $region): array
    {
        $cells = array_values(array_unique(array_map(static fn(array $cell): string => $cell[0] . ',' . $cell[1], $region)));
        $cells = array_map(static fn(string $key): array => array_map('intval', explode(',', $key)), $cells);
        usort($cells, fn(array $a, array $b): int => [$this->hash($a[0], $a[1], 'order'), $a[1], $a[0]] <=> [$this->hash($b[0], $b[1], 'order'), $b[1], $b[0]]);

        return $cells;
    }

    private function hash(int $x, int $y, string $purpose): int
    {
        return hexdec(substr(hash('xxh32', "{$this->map->mapId}:{$x}:{$y}:{$purpose}"), 0, 7));
    }

    private function findUnusedId(array $entries, string $stem): string
    {
        $ids = array_flip(array_column($entries, 'id'));
        for ($number = 1; isset($ids[$stem . '-' . $number]); $number++) {
        }

        return $stem . '-' . $number;
    }

    /**
     * Redraws the canopy layer from the standing trees: each crown spans the
     * image's width in the row above its trunk and narrows evenly to one or two
     * cells at the top of the image, and its trunk cells read `|`.
     */
    private function drawCanopy(array $entries): void
    {
        $glyphs = [];
        foreach ($this->readTreeAnchors($entries) as [$x, $y, $type]) {
            $width = self::getCellsWide($type);
            $left = $x - (int) round($type->pivot->x * $width - 0.5);
            $rows = self::getCellsTall($type) - 1;
            $narrowest = intdiv($width + 1, 2) - 1;
            for ($row = 1; $row <= $rows; $row++) {
                $inset = intdiv(($row - 1) * $narrowest, max(1, $rows - 1));
                $span = $width - 2 * $inset;
                for ($i = 0; $i < $span; $i++) {
                    $glyphs[$y - $row][$left + $inset + $i] ??= $i < intdiv($span, 2) ? '(' : ')';
                }
            }
        }
        foreach ($this->readTreeAnchors($entries) as [$x, $y, $type]) {
            foreach ($type->occupancyStamp as $cell) {
                if ($cell['type'] !== CollisionType::NONE) {
                    $glyphs[$y + $cell['y']][$x + $cell['x']] = '|';
                }
            }
        }
        $layer = $this->findCanopyLayerId();
        for ($y = 0, $height = $this->map->getHeight(); $y < $height; $y++) {
            for ($x = 0, $width = $this->map->getWidth(); $x < $width; $x++) {
                if (! $this->map->hasLayerCell($layer, $x, $y)) {
                    continue;
                }
                $glyph = $glyphs[$y][$x] ?? ' ';
                [$prefix, $suffix] = match ($glyph) {
                    ' ' => ['', ''],
                    '|' => [self::TRUNK_STYLE, '</>'],
                    default => [self::CROWN_STYLE, '</>'],
                };
                if ($this->map->getLayerSymbol($layer, $x, $y) !== $glyph || $this->map->getLayerCellStyle($layer, $x, $y) !== ['prefix' => $prefix, 'suffix' => $suffix]) {
                    $this->map->setLayerCell($layer, $x, $y, $glyph, $prefix, $suffix);
                }
            }
        }
    }

    private function findCanopyLayerId(): string
    {
        foreach ($this->map->getLayers() as $layer) {
            if ($layer['name'] === self::CANOPY_LAYER) {
                return $layer['id'];
            }
        }
        throw new MapSourceRefusal('The canopy layer is missing. Nothing was changed.');
    }
}
