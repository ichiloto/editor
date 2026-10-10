<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Closure;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;

/**
 * Edits a map's layers the one way every interface does: its glyph layers
 * (gameplay and decoration, in `layers/`) and its graphical tile layers (in
 * `graphics/`), each change one undo step.
 *
 * Every edit returns `command`, the command that undoes and redoes it,
 * already applied and unrecorded, for the caller's history (null when nothing
 * changed); `layer`, the layer the edit leaves to work on (a glyph layer's id,
 * or a tile layer's name); and `question`, which is not null when the edit
 * would change the map's collisions: nothing changes then until the edit is
 * made again confirmed. Refusals are thrown as {@see MapSourceRefusal}; nothing
 * here prompts, draws or reports a status. Changes are staged in the map and
 * written by its save, in one transaction.
 *
 * @phpstan-type LayerEdit array{command: ?Command, layer: ?string, question: ?string}
 */
final class LayerEditor
{
    /** Moves a layer to the next order drawn over it. */
    public const string ABOVE = 'above';
    /** Moves a layer to the next order drawn under it. */
    public const string BELOW = 'below';

    private function __construct()
    {
    }

    /**
     * Captures existing occupancy independently, without changing any rendered layer.
     * @return LayerEdit
     */
    public static function migratePhysicalOccupancy(ProjectMap $map): array
    {
        return self::describeEdit(self::record($map, 'Physical occupancy migration',
            static fn() => $map->migratePhysicalOccupancy()), null);
    }

    /**
     * Adds an empty glyph layer, gameplay or decoration, at the next order.
     *
     * @return LayerEdit
     * @throws MapSourceRefusal When the name is invalid or taken, or no order is free.
     */
    public static function createLayer(ProjectMap $map, string $name, bool $decoration = false): array
    {
        $id = '';
        $command = self::record($map, 'Layer create', static function () use ($map, $name, $decoration, &$id): void {
            $id = $map->createLayer($name, $decoration);
        });

        return self::describeEdit($command, $id);
    }

    /**
     * Renames a glyph layer. Collision lookup uses the layer name, so a
     * rename that changes collisions is asked about first.
     *
     * @return LayerEdit
     * @throws MapSourceRefusal When the rename is refused.
     */
    public static function renameLayer(ProjectMap $map, string $id, string $name, bool $confirmCollisionChange = false): array
    {
        $changed = $confirmCollisionChange ? 0 : $map->countRenameCollisionChanges($id, $name);
        if ($changed > 0) {
            return self::askCollisionChange(sprintf('Rename %s to %s', self::findLayerName($map, $id), $name), $changed);
        }

        return self::describeEdit(self::record($map, 'Layer rename',
            static fn() => $map->renameLayer($id, $name, true)), $id);
    }

    /**
     * Moves a glyph layer to an order; a layer holding that order takes
     * this layer's order. The topmost gameplay glyph sets a cell's collision,
     * so a move that changes collisions is asked about first.
     *
     * @return LayerEdit
     * @throws MapSourceRefusal When the move is refused.
     */
    public static function moveLayer(ProjectMap $map, string $id, int $order, bool $confirmCollisionChange = false): array
    {
        $changed = $confirmCollisionChange ? 0 : $map->countMoveCollisionChanges($id, $order);
        if ($changed > 0) {
            return self::askCollisionChange(sprintf('Moving %s to order %02d', self::findLayerName($map, $id), $order), $changed);
        }

        return self::describeEdit(self::record($map, 'Layer reorder',
            static fn() => $map->moveLayer($id, $order, true)), $id);
    }

    /**
     * The order a glyph layer moves to one step above or below: the order of
     * the next layer, gameplay or decoration, drawn over or under it.
     *
     * @throws MapSourceRefusal When the direction is unknown, there is no such layer, or no layer is there.
     */
    public static function findAdjacentLayerOrder(ProjectMap $map, string $id, string $direction): int
    {
        $layers = array_values(array_filter($map->getLayers(), static fn(array $layer): bool => $layer['id'] !== MapLayers::EVENT));
        $index = array_search($id, array_column($layers, 'id'), true);
        if ($index === false) {
            throw new MapSourceRefusal("{$map->mapId} has no layer {$id} to reorder.");
        }

        return self::findAdjacentOrder(array_column($layers, 'order'), $index, $direction, (string) $layers[$index]['name']);
    }

    /**
     * Makes a glyph layer decoration, drawn without collision, or gameplay.
     * A change that changes collisions is asked about first.
     *
     * @return LayerEdit
     * @throws MapSourceRefusal When the change is refused, such as for the last gameplay layer.
     */
    public static function setLayerDecoration(ProjectMap $map, string $id, bool $decoration, bool $confirmCollisionChange = false): array
    {
        $changed = $confirmCollisionChange ? 0 : $map->countDecorationCollisionChanges($id, $decoration);
        if ($changed > 0) {
            return self::askCollisionChange(sprintf('Making %s %s', self::findLayerName($map, $id), $decoration ? 'decoration' : 'gameplay'), $changed);
        }

        return self::describeEdit(self::record($map, 'Layer decoration',
            static fn() => $map->setLayerDecoration($id, $decoration, true)), $id);
    }

    /**
     * Removes a glyph layer and its cells. A removal that changes collisions
     * is asked about first; the layer left to work on is the map's base layer.
     *
     * @return LayerEdit
     * @throws MapSourceRefusal When the removal is refused, such as for the last gameplay layer.
     */
    public static function removeLayer(ProjectMap $map, string $id, bool $confirmCollisionChange = false): array
    {
        $changed = $confirmCollisionChange ? 0 : $map->countRemovalCollisionChanges($id);
        if ($changed > 0) {
            return self::askCollisionChange(sprintf('Removing %s', self::findLayerName($map, $id)), $changed);
        }
        $command = self::record($map, 'Layer remove', static fn() => $map->removeLayer($id));

        return self::describeEdit($command, $map->getBaseLayerId());
    }

    /**
     * Adds an empty tile layer, placed among the tile layers as one a piece
     * names is.
     *
     * @return LayerEdit
     * @throws MapSourceRefusal When the name is invalid or taken, or the map has no room for it.
     */
    public static function createTileLayer(ProjectMap $map, string $name): array
    {
        return self::describeEdit(self::record($map, 'Tile layer create', static fn() => $map->createTileLayer($name)), $name);
    }

    /**
     * Renames a tile layer and its settings.
     *
     * @return LayerEdit
     * @throws MapSourceRefusal When there is no such layer, or the new name is invalid or taken.
     */
    public static function renameTileLayer(ProjectMap $map, string $name, string $newName): array
    {
        return self::describeEdit(self::record($map, 'Tile layer rename', static fn() => $map->renameTileLayer($name, $newName)), $newName);
    }

    /**
     * Moves a tile layer to a drawing order; a tile layer holding that order
     * takes this layer's order.
     *
     * @return LayerEdit
     * @throws MapSourceRefusal When there is no such layer or the order is not 00-99.
     */
    public static function moveTileLayer(ProjectMap $map, string $name, int $order): array
    {
        return self::describeEdit(self::record($map, 'Tile layer reorder', static fn() => $map->moveTileLayer($name, $order)), $name);
    }

    /**
     * The order a tile layer moves to one step above or below: the order of
     * the next tile layer drawn over or under it.
     *
     * @throws MapSourceRefusal When the direction is unknown, there is no such layer, or no layer is there.
     */
    public static function findAdjacentTileLayerOrder(ProjectMap $map, string $name, string $direction): int
    {
        $layers = $map->describeTileLayers()['layers'];
        $index = array_search($name, array_column($layers, 'name'), true);
        if ($index === false) {
            throw new MapSourceRefusal("There is no tile layer {$name}. Nothing was changed.");
        }

        return self::findAdjacentOrder(array_column($layers, 'order'), $index, $direction, $name);
    }

    /**
     * Removes a tile layer, its tiles and its settings.
     *
     * @return LayerEdit
     * @throws MapSourceRefusal When there is no such layer.
     */
    public static function removeTileLayer(ProjectMap $map, string $name): array
    {
        return self::describeEdit(self::record($map, 'Tile layer remove', static fn() => $map->removeTileLayer($name)), null);
    }

    /**
     * Sets a tile layer's offset across and down, in field cells, and the
     * gameplay layer its tiles move with, or none.
     *
     * @param array<int, mixed> $offset
     * @return LayerEdit
     * @throws MapSourceRefusal When there is no such layer, or the Engine would refuse the settings.
     */
    public static function setTileLayerSettings(ProjectMap $map, string $name, array $offset, ?string $movesWith): array
    {
        return self::describeEdit(self::record($map, 'Tile layer settings',
            static fn() => $map->setTileLayerSettings($name, $offset, $movesWith)), $name);
    }

    /**
     * Applies a change and returns the command that restores the layers and
     * data on either side of it, or null when it changed nothing.
     */
    private static function record(ProjectMap $map, string $label, Closure $change): ?Command
    {
        $before = $map->captureLayerSnapshot();
        $change();
        $after = $map->captureLayerSnapshot();
        if ($after === $before) {
            return null;
        }

        return new GenericCommand($label, static fn() => $map->restoreLayerSnapshot($after),
            static fn() => $map->restoreLayerSnapshot($before));
    }

    /** @return LayerEdit */
    private static function describeEdit(?Command $command, ?string $layer): array
    {
        return ['command' => $command, 'layer' => $layer, 'question' => null];
    }

    /** @return LayerEdit */
    private static function askCollisionChange(string $action, int $changed): array
    {
        return ['command' => null, 'layer' => null, 'question' => sprintf(
            '%s changes collision at %d cell(s). Shared collisions.php stays unchanged.', $action, $changed)];
    }

    private static function findLayerName(ProjectMap $map, string $id): string
    {
        return (string) (array_find($map->getLayers(), static fn(array $layer): bool => $layer['id'] === $id)['name'] ?? $id);
    }

    /**
     * @param list<int> $orders Orders in drawing order.
     * @throws MapSourceRefusal When the direction is unknown or no layer is there.
     */
    private static function findAdjacentOrder(array $orders, int $index, string $direction, string $name): int
    {
        $step = match ($direction) {
            self::ABOVE => 1,
            self::BELOW => -1,
            default => throw new MapSourceRefusal(sprintf("Move a layer '%s' or '%s', not '%s'.", self::ABOVE, self::BELOW, $direction)),
        };

        return $orders[$index + $step] ?? throw new MapSourceRefusal(sprintf('%s is already the %s layer.', $name,
            $step > 0 ? 'top' : 'bottom'));
    }
}
