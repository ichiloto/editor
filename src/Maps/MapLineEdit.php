<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

/**
 * Rows or columns inserted into or removed from one map, from line `$at` on
 * `$axis` (`y` for rows, `x` for columns). Everything in that map's space at
 * or beyond the line moves; on a removal, what stands on a removed line has
 * nowhere to go, so the edit names it instead of moving it.
 *
 * @package Ichiloto\Editor\Maps
 */
interface MapLineEdit
{
    public string $mapId { get; }

    public string $axis { get; }

    public int $at { get; }

    public int $count { get; }

    /** `Insert` or `Remove`. */
    public string $verb { get; }

    /** `rows` or `columns`, singular for one. */
    public string $noun { get; }

    /** What changes where, in words: 3 rows above row 5 of town. */
    public string $description { get; }

    /** Where a coordinate on the edit's axis moves to, or null when its line is removed. */
    public function getShiftedCoordinate(int $value): ?int;

    /**
     * Where a span on the edit's axis moves to, or null when every line it
     * covers is removed.
     *
     * @return array{int, int}|null The new start and size.
     */
    public function getShiftedSpan(int $start, int $size): ?array;

    /**
     * The declared save edits a save compatibility step applies so saved
     * player positions follow the edit, by manifest key.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function getManifestEdits(): array;
}
