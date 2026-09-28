<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use InvalidArgumentException;

/**
 * Blank rows or columns inserted into one map: `$count` lines before line
 * `$at` on `$axis` (`y` for rows, `x` for columns). Everything in that map's
 * space at or beyond the line moves by the count; the map grows by it.
 *
 * @package Ichiloto\Editor\Maps
 */
final class LineInsertion
{
    public function __construct(
        public readonly string $mapId,
        public readonly string $axis,
        public readonly int $at,
        public readonly int $count,
    ) {
        if ($axis !== 'x' && $axis !== 'y') {
            throw new InvalidArgumentException("Insert rows (y) or columns (x), not '{$axis}'.");
        }
        if ($at < 0 || $count < 1) {
            throw new InvalidArgumentException('Insert at least one line at a non-negative position.');
        }
    }

    /** `rows` or `columns`, singular for one. */
    public string $noun {
        get => ($this->axis === 'y' ? 'row' : 'column') . ($this->count === 1 ? '' : 's');
    }

    /** What is inserted where, in words: 3 rows above row 5 of town. */
    public string $description {
        get => sprintf('%d %s %s %s %d of %s', $this->count, $this->noun,
            $this->axis === 'y' ? 'above' : 'left of', $this->axis === 'y' ? 'row' : 'column', $this->at + 1, $this->mapId);
    }

    /** Where a coordinate on the insertion axis moves to. */
    public function getShiftedCoordinate(int $value): int
    {
        return $value >= $this->at ? $value + $this->count : $value;
    }

    /**
     * Where a span on the insertion axis moves to: one starting at or beyond
     * the line moves, one straddling it stretches, one before it stays.
     *
     * @return array{int, int} The new start and size.
     */
    public function getShiftedSpan(int $start, int $size): array
    {
        if ($start >= $this->at) {
            return [$start + $this->count, $size];
        }

        return $start + $size > $this->at ? [$start, $size + $this->count] : [$start, $size];
    }

    /**
     * The declarative save migration step entry the Engine applies to a
     * saved player position.
     *
     * @return array{map: string, axis: string, at: int, by: int}
     */
    public function getManifestEntry(): array
    {
        return ['map' => $this->mapId, 'axis' => $this->axis, 'at' => $this->at, 'by' => $this->count];
    }
}
