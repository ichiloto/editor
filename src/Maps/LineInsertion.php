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
final class LineInsertion implements MapLineEdit
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

    public string $verb {
        get => 'Insert';
    }

    public string $noun {
        get => ($this->axis === 'y' ? 'row' : 'column') . ($this->count === 1 ? '' : 's');
    }

    public string $description {
        get => sprintf('%d %s %s %s %d of %s', $this->count, $this->noun,
            $this->axis === 'y' ? 'above' : 'left of', $this->axis === 'y' ? 'row' : 'column', $this->at + 1, $this->mapId);
    }

    public function getShiftedCoordinate(int $value): int
    {
        return $value >= $this->at ? $value + $this->count : $value;
    }

    /**
     * One starting at or beyond the line moves, one straddling it stretches,
     * one before it stays.
     *
     * @return array{int, int}
     */
    public function getShiftedSpan(int $start, int $size): array
    {
        if ($start >= $this->at) {
            return [$start + $this->count, $size];
        }

        return $start + $size > $this->at ? [$start, $size + $this->count] : [$start, $size];
    }

    /** @return array{mapShifts: list<array{map: string, axis: string, at: int, by: int}>} */
    public function getManifestEdits(): array
    {
        return ['mapShifts' => [['map' => $this->mapId, 'axis' => $this->axis, 'at' => $this->at, 'by' => $this->count]]];
    }
}
