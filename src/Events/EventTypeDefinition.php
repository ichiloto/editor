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

    /**
     * The definition an event of this type has. An event already of this
     * type keeps everything it holds and gains any default it lacks; any
     * other event starts again from the defaults, since another type's data
     * means nothing to this one.
     *
     * @param array<string, mixed>|null $current The event's definition now, if it has one.
     * @return array<string, mixed>
     */
    public function buildDefinition(?array $current): array
    {
        $defaults = [
            'class' => $this->className,
            'data' => $this->defaultData,
            ...$this->defaultDefinitionFields,
        ];

        return is_array($current) && ($current['class'] ?? null) === $this->className
            ? array_replace_recursive($defaults, $current)
            : $defaults;
    }
}
