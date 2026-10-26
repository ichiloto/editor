<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use InvalidArgumentException;

/**
 * The one-line form world conditions and world writes are edited in: entries
 * separated by `;`, each made of parts separated by `:`. The Engine accepts
 * any non-empty name, colons included (a completion flag such as
 * `deckers:won`), so a name or value is written with its `\`, `:` and `;`
 * escaped by a backslash, and the line splits only on unescaped separators.
 * {@see ConditionCodec} and {@see WorldWriteCodec} read their types' parts
 * from it.
 */
final class WorldStateLine
{
    private function __construct()
    {
    }

    /**
     * Splits a line into its entries, each still escaped, at every unescaped `;`.
     *
     * @return list<string>
     */
    public static function splitEntries(string $line): array
    {
        return self::splitAt($line, ';');
    }

    /**
     * Splits one entry into its parts at every unescaped `:`, each trimmed and
     * unescaped.
     *
     * @return list<string>
     * @throws InvalidArgumentException When a backslash escapes nothing it may.
     */
    public static function splitParts(string $entry): array
    {
        return array_map(static fn(string $part): string => self::unescape(trim($part)), self::splitAt($entry, ':'));
    }

    /** Writes one part with its `\`, `:` and `;` escaped, so it reads back whole. */
    public static function escape(string $part): string
    {
        return strtr($part, ['\\' => '\\\\', ':' => '\\:', ';' => '\\;']);
    }

    /**
     * Joins parts into one entry, each escaped.
     *
     * @param list<string> $parts
     */
    public static function joinParts(array $parts): string
    {
        return implode(':', array_map(self::escape(...), $parts));
    }

    /** @return list<string> */
    private static function splitAt(string $text, string $separator): array
    {
        $pieces = [];
        $current = '';
        $length = strlen($text);
        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];
            if ($character === '\\' && $index + 1 < $length) {
                // An escaped character stays escaped for the part to unescape.
                $current .= $character . $text[++$index];
                continue;
            }
            if ($character === $separator) {
                $pieces[] = $current;
                $current = '';
                continue;
            }
            $current .= $character;
        }
        $pieces[] = $current;

        return $pieces;
    }

    /** @throws InvalidArgumentException When a backslash escapes nothing it may. */
    private static function unescape(string $part): string
    {
        return (string) preg_replace_callback('/\\\\(.?)/s', static function (array $match): string {
            if (! in_array($match[1], ['\\', ':', ';'], true)) {
                throw new InvalidArgumentException(sprintf('"\\%s" escapes nothing; write \\: for a colon, \\; for a semicolon and \\\\ for a backslash.', $match[1]));
            }

            return $match[1];
        }, $part);
    }
}
