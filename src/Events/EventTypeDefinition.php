<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

/**
 * Defines a selectable event type for the editor.
 */
final class EventTypeDefinition
{
    /**
     * @param array<string, mixed> $defaultData
     * @param array<string, mixed> $defaultDefinitionFields Root fields added
     * beside `class` and `data` when a new event of this type is created.
     */
    public function __construct(
        public readonly string $label,
        public readonly string $className,
        public readonly string $description,
        public readonly array $defaultData,
        public readonly array $defaultDefinitionFields = [],
    ) {
    }
}
