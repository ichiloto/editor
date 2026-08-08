<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

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
            return [$path, sprintf('a %s object', $value::class), $value::class];
        }

        return [$path, sprintf('a %s value', get_debug_type($value)), null];
    }
}
