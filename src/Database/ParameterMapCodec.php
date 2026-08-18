<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * A map of project-owned parameters, on one editable line.
 *
 * A special property is `['type' => ..., 'parameters' => [...]]`, and what
 * goes in the parameters is the project's business: the runtime carries them
 * and hands them to whatever reads that kind of property. An editor that
 * offers only the type makes half of the contract unauthorable; one that
 * invented a schema for the parameters would be deciding what a project is
 * allowed to say.
 *
 * So the parameters are edited as `name=value` pairs and typed on the way
 * back: a whole number stays a whole number, a decimal stays a decimal,
 * true and false stay booleans, and everything else is text. A value the
 * line cannot hold -- a nested list, a map -- is not shown and not touched,
 * because losing what an author wrote is worse than not editing it here.
 *
 * @package Ichiloto\Editor\Database
 */
final class ParameterMapCodec
{
    /**
     * Renders the scalar parameters of a map onto one line.
     *
     * @param array<string, mixed> $parameters The parameters.
     * @return string The line.
     */
    public static function encode(array $parameters): string
    {
        $pairs = [];

        foreach ($parameters as $name => $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $pairs[] = sprintf('%s=%s', $name, is_bool($value) ? ($value ? 'true' : 'false') : strval($value));
        }

        return implode(', ', $pairs);
    }

    /**
     * Reads a line back into typed parameters.
     *
     * @param string $line The line.
     * @return array<string, scalar> The parameters.
     */
    public static function decode(string $line): array
    {
        $parameters = [];

        foreach (explode(',', $line) as $pair) {
            if (trim($pair) === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $name = trim($parts[0]);

            if ($name === '') {
                continue;
            }

            $parameters[$name] = self::typed(trim($parts[1] ?? ''));
        }

        return $parameters;
    }

    /**
     * Returns a map with authored scalars replaced and everything the line
     * cannot carry kept exactly as it was.
     *
     * @param array<string, mixed> $existing The parameters as stored.
     * @param array<string, scalar> $authored The parameters read off the line.
     * @return array<string, mixed> The merged parameters.
     */
    public static function merge(array $existing, array $authored): array
    {
        $merged = $authored;

        foreach ($existing as $name => $value) {
            if (! is_scalar($value) && ! array_key_exists($name, $authored)) {
                $merged[$name] = $value;
            }
        }

        return $merged;
    }

    /**
     * Returns the value a written parameter means.
     *
     * @param string $value The written value.
     * @return scalar The typed value.
     */
    private static function typed(string $value): string|int|float|bool
    {
        return match (true) {
            $value === 'true' => true,
            $value === 'false' => false,
            // A number the author wrote as a number: kept as one, so the
            // runtime is handed what the file said rather than a string of
            // it. Anything with a leading zero stays text, because that is
            // an identifier, not a quantity.
            preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1 => intval($value),
            preg_match('/^-?(0|[1-9][0-9]*)\.[0-9]+$/', $value) === 1 => floatval($value),
            default => $value,
        };
    }
}
