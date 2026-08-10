<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Turning a name into the id a project stores.
 *
 * An id is written into triggers, conditions and prerequisites, so it has to
 * be stable, readable and free of anything that would need quoting. Authors
 * should not have to invent one, nor keep two spellings of the same quest in
 * their head.
 *
 * @package Ichiloto\Editor\Database
 */
final class Slug
{
    /**
     * Returns the id form of a name.
     *
     * @param string $name The name as written.
     * @return string The id, or an empty string when the name has nothing an
     *   id can be made from.
     */
    public static function of(string $name): string
    {
        $slug = mb_strtolower(trim($name));

        // Accented letters carry meaning in a name and none in an id, so they
        // become their plain form rather than disappearing.
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);

        if (is_string($transliterated)) {
            $slug = $transliterated;
        }

        // An apostrophe is not a word break, and BSD's transliteration leaves
        // its own quote marks behind: "Café" arrives here as "Caf'e".
        $slug = (string) preg_replace('/[\'"`^~]+/', '', $slug);
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $slug));

        return trim($slug, '-');
    }

    /**
     * Returns an id like the name's that nothing else is using.
     *
     * @param string $name The name as written.
     * @param string[] $taken The ids already in use.
     * @param string $fallback What to call it when the name yields nothing.
     * @return string The unique id.
     */
    public static function unique(string $name, array $taken, string $fallback = 'untitled'): string
    {
        $base = self::of($name);
        $base = $base === '' ? $fallback : $base;
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $taken, true)) {
            $candidate = sprintf('%s-%d', $base, $suffix);
            $suffix++;
        }

        return $candidate;
    }
}
