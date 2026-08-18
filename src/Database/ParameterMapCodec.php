<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * A map of project-owned parameters, on one line, reversibly.
 *
 * A special property's parameters and a permanent grant's metadata are
 * `array<string, mixed>` to the runtime: what goes in them is the project's
 * business. Editing them as `name=value` text is the natural surface, and
 * the naive version of it silently destroys legal values -- `Blood, Oath`
 * becomes two keys, `  spaced  ` loses its spaces, and the string `true`
 * comes back a boolean.
 *
 * So the grammar is explicit. A value is written bare only when it reads
 * back as itself; anything else is quoted, and inside quotes a quote and a
 * backslash are escaped. A quoted value is always a string, which is what
 * distinguishes the string `true` from the boolean, and `"007"` from a
 * number that happens to start with a zero.
 *
 * What the line cannot carry -- a nested list, a map -- is not shown and not
 * touched. What it cannot parse is refused with a reason rather than
 * repaired.
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

            $pairs[] = sprintf('%s=%s', self::quoteName(strval($name)), self::quoteValue($value));
        }

        return implode(', ', $pairs);
    }

    /**
     * Reads a line back into typed parameters.
     *
     * @param string $line The line.
     * @return array<string, scalar> The parameters.
     * @throws ParameterMapSyntaxError When the line is not one this can read.
     */
    public static function decode(string $line): array
    {
        $parameters = [];
        $length = mb_strlen($line);
        $offset = 0;

        while ($offset < $length) {
            $offset = self::skipSpace($line, $offset, $length);

            if ($offset >= $length) {
                break;
            }

            [$name, $offset] = self::readToken($line, $offset, $length, '=');
            $name = trim($name);

            if ($name === '') {
                throw new ParameterMapSyntaxError('A parameter has no name.');
            }

            if (array_key_exists($name, $parameters)) {
                throw new ParameterMapSyntaxError(sprintf('The parameter "%s" is named twice.', $name));
            }

            $offset = self::skipSpace($line, $offset, $length);

            if ($offset >= $length || mb_substr($line, $offset, 1) !== '=') {
                throw new ParameterMapSyntaxError(sprintf('The parameter "%s" has no value. Write name=value.', $name));
            }

            $offset = self::skipSpace($line, $offset + 1, $length);
            [$value, $offset, $wasQuoted] = self::readValue($line, $offset, $length);
            $parameters[$name] = $wasQuoted ? $value : self::typed(rtrim($value));
            $offset = self::skipSpace($line, $offset, $length);

            if ($offset < $length) {
                if (mb_substr($line, $offset, 1) !== ',') {
                    throw new ParameterMapSyntaxError(sprintf(
                        'Unexpected "%s" after the parameter "%s". Separate parameters with a comma, and quote a value containing one.',
                        mb_substr($line, $offset, 1),
                        $name,
                    ));
                }

                $offset++;
            }
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
     * Returns a name as it must be written to read back as itself.
     */
    private static function quoteName(string $name): string
    {
        return $name === trim($name)
            && ! str_contains($name, ',')
            && ! str_contains($name, '=')
            && ! str_contains($name, '"')
            && $name !== ''
                ? $name
                : self::quoted($name);
    }

    /**
     * Returns a value as it must be written to read back as itself.
     */
    private static function quoteValue(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return strval($value);
        }

        // A string that would read back as something other than itself --
        // a boolean, a number, or a second parameter -- is quoted.
        return $value === ''
            || $value !== trim($value)
            || in_array($value, ['true', 'false'], true)
            || self::typed($value) !== $value
            || str_contains($value, ',')
            || str_contains($value, '=')
            || str_contains($value, '"')
            || str_contains($value, '\\')
                ? self::quoted($value)
                : $value;
    }

    /**
     * Returns a string in quotes, with quotes and backslashes escaped.
     */
    private static function quoted(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Reads up to a delimiter, honouring quotes.
     *
     * @return array{0: string, 1: int} The token and the new offset.
     */
    private static function readToken(string $line, int $offset, int $length, string $delimiter): array
    {
        if (mb_substr($line, $offset, 1) === '"') {
            [$value, $offset] = self::readQuoted($line, $offset, $length);

            return [$value, $offset];
        }

        $token = '';

        while ($offset < $length) {
            $character = mb_substr($line, $offset, 1);

            if ($character === $delimiter || $character === ',') {
                break;
            }

            $token .= $character;
            $offset++;
        }

        return [$token, $offset];
    }

    /**
     * Reads one value, saying whether it was quoted.
     *
     * @return array{0: string, 1: int, 2: bool} The value, the offset, and whether it was quoted.
     */
    private static function readValue(string $line, int $offset, int $length): array
    {
        if ($offset < $length && mb_substr($line, $offset, 1) === '"') {
            [$value, $offset] = self::readQuoted($line, $offset, $length);

            return [$value, $offset, true];
        }

        [$value, $offset] = self::readToken($line, $offset, $length, "\0");

        return [$value, $offset, false];
    }

    /**
     * Reads a quoted string, unescaping as it goes.
     *
     * @return array{0: string, 1: int} The string and the new offset.
     */
    private static function readQuoted(string $line, int $offset, int $length): array
    {
        $offset++;
        $value = '';

        while ($offset < $length) {
            $character = mb_substr($line, $offset, 1);

            if ($character === '\\') {
                $next = mb_substr($line, $offset + 1, 1);

                if ($next === '') {
                    throw new ParameterMapSyntaxError('A quoted value ends with a stray backslash.');
                }

                $value .= $next;
                $offset += 2;

                continue;
            }

            if ($character === '"') {
                return [$value, $offset + 1];
            }

            $value .= $character;
            $offset++;
        }

        throw new ParameterMapSyntaxError('A quoted value is never closed.');
    }

    /**
     * Skips spaces between tokens.
     */
    private static function skipSpace(string $line, int $offset, int $length): int
    {
        while ($offset < $length && mb_substr($line, $offset, 1) === ' ') {
            $offset++;
        }

        return $offset;
    }

    /**
     * Returns the value an unquoted token means.
     *
     * @return scalar The typed value.
     */
    private static function typed(string $value): string|int|float|bool
    {
        return match (true) {
            $value === 'true' => true,
            $value === 'false' => false,
            // A leading zero is an identifier, not a quantity.
            preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1 => intval($value),
            preg_match('/^-?(0|[1-9][0-9]*)\.[0-9]+$/', $value) === 1 => floatval($value),
            default => $value,
        };
    }
}
