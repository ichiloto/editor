<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use UnitEnum;

/**
 * One Database entry, backed either by its raw authored array or — for
 * categories the editor only browses — by the engine object the data file
 * constructed.
 *
 * Array payloads are kept verbatim, so keys the editor never shows (an item's
 * `effects`, a state's unused `icon`) round-trip untouched.
 */
final class ProjectRecord
{
    /**
     * @param array<string, mixed>|object $payload The record payload.
     * @param bool $isDirty Whether the record holds unsaved edits.
     * @param string|null $sourcePath The file backing this record, for one-file-per-record categories.
     * @param string $recordId The record identity, when it comes from the filename rather than the payload.
     * @param PhpDataFile|null $file The loaded source file, for one-file-per-record categories.
     */
    public function __construct(
        private array|object $payload,
        private bool $isDirty = false,
        public readonly ?string $sourcePath = null,
        public readonly string $recordId = '',
        public readonly ?PhpDataFile $file = null,
    ) {
    }

    /**
     * Returns whether this record can be edited at all.
     *
     * @return bool
     */
    public function isEditable(): bool
    {
        return is_array($this->payload) && ($this->file === null || $this->file->isEditable());
    }

    /**
     * Returns why this record cannot be edited, when it cannot.
     *
     * @return string|null
     */
    public function getReadOnlyReason(): ?string
    {
        if (is_object($this->payload)) {
            return sprintf('this entry is a %s object built by the data file', self::shortClassName($this->payload::class));
        }

        return $this->file?->readOnlyReason;
    }

    public function isDirty(): bool
    {
        return $this->isDirty;
    }

    /**
     * Clears the dirty marker after a successful save.
     *
     * @return void
     */
    public function markClean(): void
    {
        $this->isDirty = false;
    }

    /**
     * Reads a value by key, following dots into nested arrays and objects.
     *
     * @param string $key The key or dotted path.
     * @return mixed
     */
    public function get(string $key): mixed
    {
        $current = $this->payload;

        foreach (explode('.', $key) as $segment) {
            if (is_array($current)) {
                if (! array_key_exists($segment, $current)) {
                    return null;
                }

                $current = $current[$segment];
                continue;
            }

            if (is_object($current) && isset($current->{$segment})) {
                $current = $current->{$segment};
                continue;
            }

            return null;
        }

        return $current;
    }

    /**
     * Reads a value and renders it for display.
     *
     * @param string $key The key or dotted path.
     * @return string
     */
    public function getDisplayValue(string $key): string
    {
        return self::stringify($this->get($key));
    }

    /**
     * Sets a top-level key, or removes it when the value is null.
     *
     * @param string $key The payload key.
     * @param mixed $value The value; null removes the key.
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        if (! is_array($this->payload)) {
            return;
        }

        if ($value === null) {
            unset($this->payload[$key]);
        } else {
            $this->payload[$key] = $value;
        }

        $this->isDirty = true;
    }

    /**
     * Returns a nested sub-list (objectives, beats, members, commands).
     *
     * @param string $key The payload key holding the list.
     * @return array<int, array<string, mixed>>
     */
    public function getSubList(string $key): array
    {
        $list = $this->get($key);

        return is_array($list) ? array_values(array_filter($list, is_array(...))) : [];
    }

    /**
     * Replaces a nested sub-list.
     *
     * @param string $key The payload key holding the list.
     * @param array<int, array<string, mixed>> $list The new list.
     * @return void
     */
    public function setSubList(string $key, array $list): void
    {
        $this->set($key, array_values($list));
    }

    /**
     * Returns the record payload.
     *
     * @return array<string, mixed>|object
     */
    public function toArray(): array|object
    {
        return $this->payload;
    }

    /**
     * Renders any leaf value as a settings-pane string.
     *
     * @param mixed $value The value to render.
     * @return string
     */
    public static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof UnitEnum) {
            return property_exists($value, 'value') ? strval($value->value) : $value->name;
        }

        if (is_array($value)) {
            if ($value === []) {
                return '';
            }

            if (array_is_list($value) && ! array_filter($value, static fn(mixed $item): bool => is_array($item) || is_object($item))) {
                return implode(', ', array_map(self::stringify(...), $value));
            }

            return sprintf('(%d)', count($value));
        }

        if (is_object($value)) {
            return self::shortClassName($value::class);
        }

        return strval($value);
    }

    /**
     * Returns a class name without its namespace.
     *
     * @param string $className The fully-qualified class name.
     * @return string
     */
    private static function shortClassName(string $className): string
    {
        $position = strrpos($className, '\\');

        return $position === false ? $className : substr($className, $position + 1);
    }
}
