<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Source;

/** Byte ranges of a JSON value and, for objects, each key and its colon. */
final readonly class JsonSourceValue
{
    /**
     * @param array<array-key, self> $children
     * @param array<array-key, int> $memberStarts
     */
    public function __construct(
        public int $start,
        public int $end,
        public mixed $value,
        public array $children = [],
        public array $memberStarts = [],
        public bool $object = false,
    ) {
    }
}
