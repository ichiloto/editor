<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use ReflectionClass;
use ReflectionMethod;
use ReflectionException;
use RuntimeException;
use UnitEnum;

/**
 * Renders PHP values back into authorable `return ...;` source.
 *
 * The exporter deliberately understands three things and nothing else:
 * scalars, arrays, and enum cases. Enums round-trip as their fully-qualified
 * `\Vendor\Enum::CASE` form, which is exactly what `var_export()` emits and
 * what the engine's config and data files already contain. Anything else —
 * a `new Item(...)`, a closure, an arbitrary object — is *not* exportable,
 * and callers are expected to ask before they write.
 */
final class PhpValueExporter
{
    /**
     * Returns whether a value can be written back out losslessly.
     *
     * @param mixed $value The value to probe.
     * @return bool
     */
    public static function isExportable(mixed $value): bool
    {
        return self::findUnexportable($value) === null;
    }

    /**
     * Returns a human-readable reason the value cannot be exported.
     *
     * The reason names the offending path and type so the editor's status
     * line can tell an author *why* a category is read-only instead of
     * silently refusing.
     *
     * @param mixed $value The value to probe.
     * @return string|null The reason, or null when the value is exportable.
     */
    public static function describeUnexportable(mixed $value): ?string
    {
        $found = self::findUnexportable($value);

        if ($found === null) {
            return null;
        }

        [$path, $description] = $found;

        return $path === ''
            ? sprintf('the payload is %s', $description)
            : sprintf('%s is %s', $path, $description);
    }

    /**
     * Returns whether a value contains a non-enum object anywhere.
     *
     * @param mixed $value The value to probe.
     * @return bool
     */
    public static function containsObject(mixed $value): bool
    {
        return self::findUnexportableClass($value) !== null;
    }

    /**
     * Returns the short class name of the first non-enum object found.
     *
     * @param mixed $value The value to probe.
     * @return string|null The class name, or null when nothing objects.
     */
    public static function findUnexportableClass(mixed $value): ?string
    {
        $found = self::findUnexportable($value);

        if ($found === null || $found[2] === null) {
            return null;
        }

        $position = strrpos($found[2], '\\');

        return $position === false ? $found[2] : substr($found[2], $position + 1);
    }

    /**
     * Exports a value using short-array syntax and FQCN enum references.
     *
     * @param mixed $value The value to export.
     * @param int $indentLevel The indentation depth.
     * @return string
     */
    public static function export(mixed $value, int $indentLevel = 0): string
    {
        if ($value instanceof UnitEnum) {
            return '\\' . $value::class . '::' . $value->name;
        }

        if (is_object($value)) {
            return self::exportObject($value, $indentLevel);
        }

        if (! is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('  ', $indentLevel);
        $nextIndent = str_repeat('  ', $indentLevel + 1);
        $isList = array_is_list($value);
        $lines = ['['];

        foreach ($value as $key => $item) {
            $exportedItem = self::export($item, $indentLevel + 1);

            if ($isList) {
                $lines[] = "{$nextIndent}{$exportedItem},";
                continue;
            }

            $exportedKey = var_export($key, true);
            $lines[] = "{$nextIndent}{$exportedKey} => {$exportedItem},";
        }

        $lines[] = "{$indent}]";

        return implode("\n", $lines);
    }

    /**
     * Exports an object as the constructor call that rebuilds it.
     *
     * Data files like `items.php` are authored as `new Item(...)` rather than
     * arrays, and an author editing one expects it to stay that way. Named
     * arguments are used throughout, so the file reads as what it is and a
     * later change to the constructor's parameter order cannot silently
     * reorder anyone's data.
     *
     * @param object $value The object.
     * @param int $indentLevel The indentation depth.
     * @return string The constructor call.
     */
    private static function exportObject(object $value, int $indentLevel): string
    {
        $arguments = self::constructorArguments($value);

        if ($arguments === null) {
            // Unreachable for a value that passed the exportability probe;
            // kept honest for callers that skipped it.
            throw new RuntimeException(sprintf('%s cannot be rebuilt from its properties.', $value::class));
        }

        if ($arguments === []) {
            return sprintf('new \\%s()', $value::class);
        }

        $indent = str_repeat('  ', $indentLevel);
        $nextIndent = str_repeat('  ', $indentLevel + 1);
        $lines = [sprintf('new \\%s(', $value::class)];

        foreach ($arguments as $name => $argument) {
            $lines[] = sprintf('%s%s: %s,', $nextIndent, $name, self::export($argument, $indentLevel + 1));
        }

        $lines[] = "{$indent})";

        return implode("\n", $lines);
    }

    /**
     * Reads back the arguments an object was built with.
     *
     * A parameter is only recoverable when the object kept it under the same
     * name, which promoted properties guarantee and hand-written constructors
     * usually honour. Anything else cannot be rebuilt, and the file that holds
     * it stays read-only rather than being rewritten into something else.
     *
     * @param object $value The object.
     * @return array<string, mixed>|null The arguments, or null when it cannot be rebuilt.
     */
    public static function constructorArguments(object $value): ?array
    {
        $reflection = new ReflectionClass($value);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            // Nothing to rebuild it from. An object carrying state with no
            // constructor to put it back would be exported as an empty shell,
            // silently losing what it held.
            return self::propertyNames($value) === [] ? [] : null;
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if ($parameter->isVariadic() || ! $reflection->hasProperty($name)) {
                return null;
            }

            $property = $reflection->getProperty($name);

            if (! $property->isInitialized($value)) {
                return null;
            }

            $arguments[$name] = $property->getValue($value);
        }

        $arguments = self::withoutDefaults($constructor, $arguments);

        // A property set outside the constructor cannot be put back by
        // calling it, so anything holding one is not rebuildable either.
        foreach (self::propertyNames($value) as $property) {
            if (! $reflection->hasProperty($property)) {
                return null;
            }
        }

        return $arguments;
    }

    /**
     * Drops the arguments that are already the constructor's defaults.
     *
     * A rewritten file should read like the one an author wrote, not like a
     * dump of every parameter a class happens to take. Everything is written
     * as a named argument, so any default can be left out wherever it sits.
     *
     * @param ReflectionMethod $constructor The constructor.
     * @param array<string, mixed> $arguments The arguments read back.
     * @return array<string, mixed> The arguments worth writing.
     */
    private static function withoutDefaults(ReflectionMethod $constructor, array $arguments): array
    {
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (! array_key_exists($name, $arguments) || ! $parameter->isDefaultValueAvailable()) {
                continue;
            }

            try {
                $default = $parameter->getDefaultValue();
            } catch (ReflectionException) {
                continue;
            }

            if (self::export($arguments[$name]) === self::export($default)) {
                unset($arguments[$name]);
            }
        }

        return $arguments;
    }

    /**
     * Returns the names of the properties an object actually carries.
     *
     * @param object $value The object.
     * @return string[] The property names.
     */
    private static function propertyNames(object $value): array
    {
        return array_keys(get_object_vars($value));
    }

    /**
     * Walks a value and returns the first unexportable leaf it finds.
     *
     * @param mixed $value The value to probe.
     * @param string $path The dotted path walked so far.
     * @return array{0: string, 1: string, 2: string|null}|null The path, a type description, and the offending class.
     */
    private static function findUnexportable(mixed $value, string $path = ''): ?array
    {
        if ($value instanceof UnitEnum || $value === null || is_scalar($value)) {
            return null;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                $found = self::findUnexportable($item, $childPath);

                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }

        if (is_object($value)) {
            $arguments = self::constructorArguments($value);

            if ($arguments === null) {
                return [$path, sprintf('a %s object that cannot be rebuilt from its properties', $value::class), $value::class];
            }

            foreach ($arguments as $name => $argument) {
                $found = self::findUnexportable($argument, $path === '' ? $name : "{$path}.{$name}");

                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }

        return [$path, sprintf('a %s value', get_debug_type($value)), null];
    }
}
