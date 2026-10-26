<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Engine\Field\MapGraphics;

/**
 * Keeps a map's tile layer settings (`'tileLayers' => ['floor' => ['offset' =>
 * [0, -0.5], 'movesWith' => 'buildings']]`, {@see MapGraphics::SETTINGS_KEY})
 * naming the layers the map has while its tile and gameplay layers are
 * renamed and removed, and sets one layer's settings.
 *
 * Each change returns the settings unchanged when it touches nothing, so an
 * authored value is never rewritten for nothing. A layer whose settings
 * become empty loses its entry, as the Engine refuses an empty one, and
 * settings without entries become null, which removes the key. Settings that
 * are not a map of layer names are returned as they are: validation reports
 * them ({@see MapGraphics::readLayerOffsets()}), and only the Engine's readers
 * decide what is valid.
 */
final class TileLayerSettings
{
    private const string OFFSET_KEY = 'offset';

    private function __construct()
    {
    }

    /**
     * Moves a tile layer's settings to its new name, last: the map's data
     * source keeps the keys it keeps in order and appends new ones, and the
     * Engine reads the settings by name, never by position.
     */
    public static function renameLayer(mixed $settings, string $name, string $newName): mixed
    {
        if (! is_array($settings) || ! array_key_exists($name, $settings) || $name === $newName) {
            return $settings;
        }
        $layer = $settings[$name];
        unset($settings[$name]);
        $settings[$newName] = $layer;

        return $settings;
    }

    /** Drops a removed tile layer's settings. */
    public static function removeLayer(mixed $settings, string $name): mixed
    {
        if (! is_array($settings) || ! array_key_exists($name, $settings)) {
            return $settings;
        }
        unset($settings[$name]);

        return self::normalize($settings);
    }

    /** Makes the tile layers that move with a renamed gameplay layer name it by its new name. */
    public static function renameOwner(mixed $settings, string $owner, string $newOwner): mixed
    {
        return self::updateMovesWith($settings, $owner, $newOwner);
    }

    /** Makes the tile layers that moved with a gameplay layer that is gone, or now decoration, move with none. */
    public static function removeOwner(mixed $settings, string $owner): mixed
    {
        return self::updateMovesWith($settings, $owner, null);
    }

    /**
     * Sets one tile layer's offset across and down, in field cells, and the
     * gameplay layer it moves with, or none. No offset (0, 0) and no layer
     * leave their keys out. An offset equal to the authored one keeps the
     * authored numbers.
     *
     * @param array<int, mixed> $offset Across and down.
     */
    public static function setLayer(mixed $settings, string $name, array $offset, ?string $movesWith): mixed
    {
        if ($settings !== null && ! is_array($settings)) {
            return $settings;
        }
        $layer = $settings[$name] ?? [];
        if (! is_array($layer)) {
            $layer = [];
        }
        $zero = array_is_list($offset) && count($offset) === 2
            && array_all($offset, static fn(mixed $value): bool => (is_int($value) || is_float($value)) && (float) $value === 0.0);
        if ($zero) {
            unset($layer[self::OFFSET_KEY]);
        } elseif (! self::isSameOffset($layer[self::OFFSET_KEY] ?? null, $offset)) {
            $layer[self::OFFSET_KEY] = array_map(static fn(mixed $value): mixed =>
                (is_int($value) || is_float($value)) && (float) $value === 0.0 ? 0 : $value, $offset);
        }
        if ($movesWith === null) {
            unset($layer[MapGraphics::MOVES_WITH_KEY]);
        } else {
            $layer[MapGraphics::MOVES_WITH_KEY] = $movesWith;
        }
        $next = $settings ?? [];
        if ($layer === []) {
            unset($next[$name]);
        } else {
            $next[$name] = $layer;
        }
        if ($next === ($settings ?? [])) {
            return $settings;
        }

        return self::normalize($next);
    }

    private static function updateMovesWith(mixed $settings, string $owner, ?string $newOwner): mixed
    {
        if (! is_array($settings)) {
            return $settings;
        }
        $next = $settings;
        foreach ($settings as $name => $layer) {
            if (! is_array($layer) || ($layer[MapGraphics::MOVES_WITH_KEY] ?? null) !== $owner) {
                continue;
            }
            if ($newOwner === null) {
                unset($layer[MapGraphics::MOVES_WITH_KEY]);
            } else {
                $layer[MapGraphics::MOVES_WITH_KEY] = $newOwner;
            }
            if ($layer === []) {
                unset($next[$name]);
            } else {
                $next[$name] = $layer;
            }
        }

        return $next === $settings ? $settings : self::normalize($next);
    }

    /** Whether two offsets are the same numbers, so the authored one can stay as written. */
    private static function isSameOffset(mixed $authored, array $offset): bool
    {
        if (! is_array($authored) || ! array_is_list($authored) || count($authored) !== count($offset)) {
            return false;
        }
        foreach ($authored as $index => $value) {
            $other = $offset[$index] ?? null;
            if (! (is_int($value) || is_float($value)) || ! (is_int($other) || is_float($other)) || (float) $value !== (float) $other) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $settings */
    private static function normalize(array $settings): ?array
    {
        return $settings === [] ? null : $settings;
    }
}
