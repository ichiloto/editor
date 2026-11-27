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
     * @param array<string, mixed> $optionalData Data keys an event of this
     * type may hold and the Engine reads as unset when absent, with the
     * value an inspector shows for one that is absent. They are offered on
     * every such event and written only when an author sets one; clearing
     * one removes it again. A key may name a nested key with dots
     * (`confirmDialogue.kind`), offered when the data holds its parent.
     */
    public function __construct(
        public readonly string $label,
        public readonly string $className,
        public readonly string $description,
        public readonly array $defaultData,
        public readonly array $defaultDefinitionFields = [],
        public readonly array $optionalData = [],
    ) {
    }

    /**
     * The data with each optional key it lacks added as it reads when absent, for an inspector to offer.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function withAbsentOptionalData(array $data): array
    {
        foreach ($this->optionalData as $key => $absent) {
            $segments = explode('.', (string) $key);
            $leaf = array_pop($segments);
            $target = &$data;
            foreach ($segments as $segment) {
                if (! is_array($target[$segment] ?? null)) { unset($target); continue 2; }
                $target = &$target[$segment];
            }
            if (! array_key_exists($leaf, $target)) { $target[$leaf] = $absent; }
            unset($target);
        }

        return $data;
    }

    /**
     * The data without the optional keys that still read as absent, so that only what an author set is written.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function withoutAbsentOptionalData(array $data): array
    {
        foreach ($this->optionalData as $key => $absent) {
            $segments = explode('.', (string) $key);
            $leaf = array_pop($segments);
            $target = &$data;
            foreach ($segments as $segment) {
                if (! is_array($target[$segment] ?? null)) { unset($target); continue 2; }
                $target = &$target[$segment];
            }
            $value = $target[$leaf] ?? null;
            if (array_key_exists($leaf, $target) && ($value === $absent
                || (is_string($value) && is_string($absent) && trim($value) === trim($absent)))) {
                unset($target[$leaf]);
            }
            unset($target);
        }

        return $data;
    }

    /**
     * Whether a data path, below `data`, names one of this type's optional keys.
     *
     * @param list<string|int> $path
     */
    public function isOptionalDataPath(array $path): bool
    {
        return array_key_exists(implode('.', $path), $this->optionalData);
    }

    /**
     * The definition an event of this type has. An event already of this
     * type keeps everything it holds and gains any default it lacks. Any
     * other event starts its data again from the defaults, since another
     * type's data means nothing to this one, and keeps its root fields
     * (conditions, writes, its cue, facing and engagement), which every
     * trigger reads whatever its class.
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

        if (! is_array($current)) {
            return $defaults;
        }

        return ($current['class'] ?? null) === $this->className
            ? array_replace_recursive($defaults, $current)
            : [...$defaults, ...array_diff_key($current, ['class' => true, 'data' => true])];
    }
}
