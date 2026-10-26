<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use InvalidArgumentException;

/**
 * Rows or columns removed from one map: `$count` lines from line `$at` on
 * `$axis` (`y` for rows, `x` for columns). Everything beyond them moves back
 * by the count and the map shrinks by it. Nothing authored may stand on a
 * removed line; the planner refuses the removal until it is moved.
 *
 * A saved player position on a removed line lands on `$landing`, a cell in
 * the map after the removal, which the author confirms in the review.
 *
 * @package Ichiloto\Editor\Maps
 */
final class LineRemoval implements MapLineEdit
{
    /**
     * @param array{int, int}|null $landing Where saves standing on a removed line land, after the removal.
     */
    public function __construct(
        public readonly string $mapId,
        public readonly string $axis,
        public readonly int $at,
        public readonly int $count,
        public readonly ?array $landing = null,
    ) {
        if ($axis !== 'x' && $axis !== 'y') {
            throw new InvalidArgumentException("Remove rows (y) or columns (x), not '{$axis}'.");
        }
        if ($at < 0 || $count < 1) {
            throw new InvalidArgumentException('Remove at least one line at a non-negative position.');
        }
        if ($landing !== null && (! array_is_list($landing) || count($landing) !== 2 || ! is_int($landing[0]) || ! is_int($landing[1])
            || $landing[0] < 0 || $landing[1] < 0)) {
            throw new InvalidArgumentException('The landing for saves on removed lines must be a cell [x, y] with non-negative whole numbers.');
        }
    }

    /** @param array{int, int} $landing */
    public function withLanding(array $landing): self
    {
        return new self($this->mapId, $this->axis, $this->at, $this->count, $landing);
    }

    public string $verb {
        get => 'Remove';
    }

    public string $noun {
        get => ($this->axis === 'y' ? 'row' : 'column') . ($this->count === 1 ? '' : 's');
    }

    public string $description {
        get => sprintf('%d %s from %s %d of %s', $this->count, $this->noun,
            $this->axis === 'y' ? 'row' : 'column', $this->at + 1, $this->mapId);
    }

    /** Whether a coordinate on the removal axis is on a removed line. */
    public function isRemoved(int $value): bool
    {
        return $value >= $this->at && $value < $this->at + $this->count;
    }

    public function getShiftedCoordinate(int $value): ?int
    {
        if ($this->isRemoved($value)) {
            return null;
        }

        return $value >= $this->at ? $value - $this->count : $value;
    }

    /**
     * One beyond the removed lines moves back, one straddling them shrinks
     * by the lines it loses, one before them stays.
     *
     * @return array{int, int}|null
     */
    public function getShiftedSpan(int $start, int $size): ?array
    {
        $end = $start + $size;
        $lost = max(0, min($end, $this->at + $this->count) - max($start, $this->at));
        if ($lost >= $size) {
            return null;
        }
        $newStart = $start < $this->at ? $start : max($this->at, $start - $this->count);

        return [$newStart, $size - $lost];
    }

    /** @return array{mapRemovals: list<array{map: string, axis: string, at: int, count: int, to: array{int, int}}>} */
    public function getManifestEdits(): array
    {
        if ($this->landing === null) {
            throw new InvalidArgumentException('Choose where saves on the removed lines land before planning the save step.');
        }

        return ['mapRemovals' => [['map' => $this->mapId, 'axis' => $this->axis, 'at' => $this->at, 'count' => $this->count, 'to' => $this->landing]]];
    }
}
