<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\History\TracksPersistedState;

use Throwable;

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
    use TracksPersistedState;

    /**
     * @param array<string, mixed>|object $payload The record payload.
     * @param bool $isDirty Whether the record holds unsaved edits.
     * @param string|null $sourcePath The file backing this record, for one-file-per-record categories.
     * @param string $recordId The record identity, when it comes from the filename rather than the payload.
     * @param PhpDataFile|null $file The loaded source file, for one-file-per-record categories.
     */
    public function __construct(
        private array|object $payload,
        bool $isDirty = false,
        public readonly ?string $sourcePath = null,
        public readonly string $recordId = '',
        public readonly ?PhpDataFile $file = null,
    ) {
        if (! $isDirty) {
            $this->captureBaseline();
        }
    }

    /**
     * Returns whether this record can be edited at all.
     *
     * @return bool
     */
    public function isEditable(): bool
    {
        // One source of truth: a record is editable exactly when there is no
        // honest reason it is not.
        return $this->getReadOnlyReason() === null;
    }

    /**
     * Returns why this record cannot be edited, when it cannot.
     *
     * @return string|null
     */
    public function getReadOnlyReason(): ?string
    {
        if (is_object($this->payload) && PhpValueExporter::constructorArguments($this->payload) === null) {
            return sprintf(
                'this entry is a %s object that cannot be rebuilt from its properties',
                self::shortClassName($this->payload::class)
            );
        }

        return $this->file?->readOnlyReason;
    }

    /**
     * @inheritDoc
     */
    protected function buildPersistedPayload(): string
    {
        if (! PhpValueExporter::isExportable($this->payload)) {
            // An unexportable record is read-only, so its fingerprint only
            // has to be stable, never written; an exporter refusal must not
            // take the whole category down with it.
            try {
                return serialize($this->payload);
            } catch (Throwable) {
                return sprintf('unexportable:%s', get_debug_type($this->payload));
            }
        }

        return PhpValueExporter::export($this->payload);
    }

    /**
     * Clears the dirty marker after a successful save.
     *
     * @return void
     */
    public function markClean(): void
    {
        $this->captureBaseline();
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
        if (is_object($this->payload)) {
            $this->setOnObject($key, $value);

            return;
        }

        if (! is_array($this->payload)) {
            return;
        }

        // get() reads dotted paths; set() writes them, so a schema field
        // like wanderArea.width lands inside wanderArea rather than as a
        // flat key the runtime would never read.
        $this->payload = self::withPathValue($this->payload, explode('.', $key), $value);
        $this->touchState();
    }

    /**
     * Returns an array with one dotted-path value replaced, removed on null.
     *
     * A parent left empty by a removal is removed too, so clearing the last
     * wander bound leaves no `wanderArea => []` behind for the runtime to
     * read as a one-tile area.
     *
     * @param array<string|int, mixed> $target The array.
     * @param string[] $segments The path.
     * @param mixed $value The value; null removes.
     * @return array<string|int, mixed> The rewritten array.
     */
    private static function withPathValue(array $target, array $segments, mixed $value): array
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return $target;
        }

        $key = is_numeric($segment) ? intval($segment) : $segment;

        if ($segments === []) {
            if ($value === null) {
                unset($target[$key]);
            } else {
                $target[$key] = $value;
            }

            return $target;
        }

        $child = is_array($target[$key] ?? null) ? $target[$key] : [];
        $child = self::withPathValue($child, $segments, $value);

        if ($child === []) {
            unset($target[$key]);
        } else {
            $target[$key] = $child;
        }

        return $target;
    }

    /**
     * Changes one of the arguments an object entry was built with.
     *
     * An entry authored as `new Item(...)` is edited by rebuilding it: the
     * arguments are read back, one is replaced, and a fresh instance takes its
     * place. Anything the constructor derives is derived again, so the entry
     * stays exactly what the data file would have produced.
     *
     * @param string $key The constructor argument to change.
     * @param mixed $value The new value.
     * @return void
     */
    private function setOnObject(string $key, mixed $value): void
    {
        $payload = $this->payload;

        if (! is_object($payload)) {
            return;
        }

        try {
            $rebuilt = self::rebuildWith($payload, $key, $value);
        } catch (Throwable) {
            // The constructor rejected it (a type, a range). The entry keeps
            // what it had rather than becoming half-edited.
            return;
        }

        if ($rebuilt === null) {
            return;
        }

        $this->payload = $rebuilt;
        $this->touchState();
    }

    /**
     * Returns a copy of an object with one of its values changed.
     *
     * A dotted key reaches inside a nested value the way the settings pane
     * shows it (`stats.attack`), so the nested object is rebuilt first and the
     * outer one rebuilt around it.
     *
     * @param object $object The object to rebuild.
     * @param string $key The key, which may be dotted.
     * @param mixed $value The new value.
     * @return object|null The rebuilt object, or null when the key names
     * nothing this object was built with.
     */
    private static function rebuildWith(object $object, string $key, mixed $value): ?object
    {
        $arguments = PhpValueExporter::constructorArguments($object);

        if ($arguments === null) {
            return null;
        }

        [$head, $rest] = array_pad(explode('.', $key, 2), 2, null);

        if (! array_key_exists($head, $arguments)) {
            // Read-back trims arguments sitting at their defaults, but a
            // trimmed argument is still the constructor's to set: without
            // this, a fresh entry's Quantity -- or anything else left at its
            // default -- silently refused every edit.
            if (! self::constructorTakes($object, $head)) {
                return null;
            }

            $arguments[$head] = $rest === null ? $value : self::currentPropertyValue($object, $head);
        }

        if ($rest === null) {
            $arguments[$head] = $value;

            return new ($object::class)(...$arguments);
        }

        $nested = $arguments[$head];

        if (is_object($nested)) {
            $rebuiltNested = self::rebuildWith($nested, $rest, $value);

            if ($rebuiltNested === null) {
                return null;
            }

            $arguments[$head] = $rebuiltNested;

            return new ($object::class)(...$arguments);
        }

        if ($nested === null) {
            // The argument is absent and holds a structured value: the first
            // authored key creates it, rather than the edit being refused.
            $arguments[$head] = self::withArrayValue([], explode('.', $rest), $value);

            return new ($object::class)(...$arguments);
        }

        if (! is_array($nested)) {
            return null;
        }

        $arguments[$head] = self::withArrayValue($nested, explode('.', $rest), $value);

        return new ($object::class)(...$arguments);
    }

    /**
     * Determines whether an object's constructor takes a parameter.
     *
     * @param object $object The object.
     * @param string $name The parameter name.
     * @return bool True when it does.
     */
    private static function constructorTakes(object $object, string $name): bool
    {
        $constructor = new \ReflectionClass($object)->getConstructor();

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads the value an object currently holds for a promoted parameter.
     *
     * @param object $object The object.
     * @param string $name The property name.
     * @return mixed The value.
     */
    private static function currentPropertyValue(object $object, string $name): mixed
    {
        $reflection = new \ReflectionClass($object);

        if (! $reflection->hasProperty($name)) {
            return null;
        }

        return $reflection->getProperty($name)->getValue($object);
    }

    /**
     * Returns a copy of an array with one nested value changed.
     *
     * @param array<mixed> $array The array.
     * @param string[] $path The remaining key segments.
     * @param mixed $value The new value.
     * @return array<mixed> The updated array.
     */
    private static function withArrayValue(array $array, array $path, mixed $value): array
    {
        $key = array_shift($path);

        if ($key === null) {
            return $array;
        }

        if ($path === []) {
            $array[$key] = $value;

            return $array;
        }

        $nested = $array[$key] ?? [];
        $array[$key] = is_array($nested) ? self::withArrayValue($nested, $path, $value) : $nested;

        return $array;
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
