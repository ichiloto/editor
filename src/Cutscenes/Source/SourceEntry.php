<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Source;

/**
 * One entry of an authored array literal: an optional key, its value, and
 * exactly the bytes they occupy together with the entry's own separator.
 *
 * @package Ichiloto\Editor\Cutscenes\Source
 */
final readonly class SourceEntry
{
    /**
     * @param int|string|null $key The key as PHP reads it, or null for a
     *   list entry (or an entry whose key could not be read; see $keyIsOpaque).
     * @param SourceNode $value The value.
     * @param int $start The offset of the entry's first byte (key or value).
     * @param int $end The offset just past the value's last byte.
     * @param int $separatorEnd The offset just past the entry's trailing comma,
     *   or equal to $end when the entry has none.
     * @param bool $keyIsOpaque Whether an explicit key was present but unreadable.
     */
    public function __construct(
        public int|string|null $key,
        public SourceNode $value,
        public int $start,
        public int $end,
        public int $separatorEnd,
        public bool $keyIsOpaque = false,
    ) {
    }
}
