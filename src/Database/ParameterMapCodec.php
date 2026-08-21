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
 * becomes two keys, `  spaced  ` loses its spaces, the string `true` comes
 * back a boolean, and the float `1.0` comes back the integer `1`.
 *
 * So the grammar is explicit, and this is the whole of it.
 *
 * A line is parameters separated by commas; a parameter is a name, `=`, and
 * a value. Spaces around names, values and commas mean nothing. A name or a
 * value is written bare or quoted.
 *
 * Bare, a token means what it says: `true` and `false` are booleans; `INF`,
 * `-INF` and `NAN` are the floats that cannot be written as digits; digits
 * with an optional sign are an integer, unless they start with a zero they
 * do not need, which makes them an identifier such as `007`; digits with a
 * fraction, an exponent, or both are a float, `1.0` and `1.0E+20` and
 * `-0.0` included; anything else is the string it spells. A bare token may
 * not contain a comma, an equals sign, a quote or a backslash.
 *
 * Quoted, a token is always the string between the quotes, with exactly two
 * escapes inside it: `\"` for a quote and `\\` for a backslash. Any other
 * backslash is a mistake and is refused, never quietly dropped. Leading and
 * trailing spaces inside quotes are part of the string, for a name as much
 * as for a value.
 *
 * Writing, a name or a value is left bare only when reading it bare gives
 * back exactly what was written -- same characters, same type. Everything
 * else is quoted: the string `true`, the string `1.0`, an empty string, a
 * name with a space at either end. Floats are spelled the way PHP itself
 * spells them for a round trip, so `1.0` stays a float and `1.0E+20` stays
 * finite and exact.
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

            [$name, $offset, $nameWasQuoted] = self::readName($line, $offset, $length);

            if (! $nameWasQuoted) {
                $name = trim($name, ' ');
            }

            if ($name === '' && ! $nameWasQuoted) {
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
            [$value, $offset, $valueWasQuoted] = self::readValue($line, $offset, $length, $name);
            $parameters[$name] = $valueWasQuoted ? $value : self::typed(rtrim($value, ' '));
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
        return $name !== '' && $name === trim($name, ' ') && ! self::hasReservedCharacter($name)
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

        if (is_int($value)) {
            return strval($value);
        }

        if (is_float($value)) {
            // PHP's own round-trip spelling: `1.0` keeps its point, a large or
            // small value keeps its exponent, and every digit that tells one
            // float from the next is written.
            return var_export($value, true);
        }

        // A string is written bare only when reading it bare gives back the
        // same string: not a reserved word, not a number's spelling, not
        // empty, not padded, and none of the characters the grammar uses.
        return $value !== ''
            && $value === trim($value, ' ')
            && ! self::hasReservedCharacter($value)
            && self::typed($value) === $value
                ? $value
                : self::quoted($value);
    }

    /**
     * Returns whether a token holds a character the grammar itself uses.
     */
    private static function hasReservedCharacter(string $token): bool
    {
        return str_contains($token, ',')
            || str_contains($token, '=')
            || str_contains($token, '"')
            || str_contains($token, '\\');
    }

    /**
     * Returns a string in quotes, with quotes and backslashes escaped.
     */
    private static function quoted(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Reads a parameter's name: a quoted string, or a bare token up to the
     * equals sign.
     *
     * @return array{0: string, 1: int, 2: bool} The name, the new offset, and whether it was quoted.
     */
    private static function readName(string $line, int $offset, int $length): array
    {
        if (mb_substr($line, $offset, 1) === '"') {
            [$name, $offset] = self::readQuoted($line, $offset, $length);

            return [$name, $offset, true];
        }

        [$name, $offset] = self::readBare($line, $offset, $length, ['=', ','], 'name');

        return [$name, $offset, false];
    }

    /**
     * Reads a parameter's value: a quoted string, or a bare token up to the
     * next comma.
     *
     * @return array{0: string, 1: int, 2: bool} The value, the new offset, and whether it was quoted.
     */
    private static function readValue(string $line, int $offset, int $length, string $name): array
    {
        if ($offset < $length && mb_substr($line, $offset, 1) === '"') {
            [$value, $offset] = self::readQuoted($line, $offset, $length);

            return [$value, $offset, true];
        }

        [$value, $offset] = self::readBare($line, $offset, $length, [','], sprintf('value of "%s"', $name));

        return [$value, $offset, false];
    }

    /**
     * Reads a bare token up to one of the delimiters, refusing the
     * characters a bare token may not hold.
     *
     * @param string[] $delimiters The characters that end the token.
     * @return array{0: string, 1: int} The token and the new offset.
     */
    private static function readBare(string $line, int $offset, int $length, array $delimiters, string $what): array
    {
        $token = '';

        while ($offset < $length) {
            $character = mb_substr($line, $offset, 1);

            if (in_array($character, $delimiters, true)) {
                break;
            }

            if ($character === '"' || $character === '\\' || $character === '=') {
                throw new ParameterMapSyntaxError(sprintf(
                    'Unexpected %s in the %s. Quote a %s that contains a quote, a backslash or an equals sign.',
                    $character === '"' ? 'quote' : ($character === '\\' ? 'backslash' : 'equals sign'),
                    $what,
                    str_starts_with($what, 'value') ? 'value' : 'name',
                ));
            }

            $token .= $character;
            $offset++;
        }

        return [$token, $offset];
    }

    /**
     * Reads a quoted string, unescaping exactly the two escapes the grammar
     * has.
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

                if ($next !== '"' && $next !== '\\') {
                    throw new ParameterMapSyntaxError(sprintf(
                        'Unknown escape \\%s in a quoted value. Only \\" and \\\\ are escapes; write a backslash as \\\\.',
                        $next,
                    ));
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
     * Returns the value a bare token means.
     *
     * @return scalar The typed value.
     */
    private static function typed(string $value): string|int|float|bool
    {
        return match (true) {
            $value === 'true' => true,
            $value === 'false' => false,
            $value === 'INF' => INF,
            $value === '-INF' => -INF,
            $value === 'NAN' => NAN,
            // A leading zero is an identifier, not a quantity.
            preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1 => intval($value),
            preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+([eE][+-]?[0-9]+)?|[eE][+-]?[0-9]+)$/', $value) === 1 => floatval($value),
            default => $value,
        };
    }
}
