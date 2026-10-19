<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;

/**
 * A file that is one record: `system.php` returns the project's system
 * settings as a single map, edited as the category's only record. It is
 * never created, duplicated, moved or deleted, only edited.
 *
 * Categories that share such a file each own some of its top-level keys
 * (System its start and battle settings, Types its elements). A category
 * reads only the keys it owns and writes only those back, so a save never
 * carries its older reading of another category's keys, or of keys no
 * category edits, over the file as it is now.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class WholeFileProjection implements RecordProjection
{
    /**
     * @param list<string> $keys The top-level keys this category owns.
     */
    public function __construct(public array $keys)
    {
    }

    /**
     * @inheritDoc
     */
    public function read(array $whole): array
    {
        return [array_intersect_key($whole, array_flip($this->keys))];
    }

    /**
     * @inheritDoc
     */
    public function write(array $whole, array $rows): array
    {
        $record = $rows[0] ?? null;

        if (! is_array($record)) {
            return $whole;
        }

        foreach ($this->keys as $key) {
            if (array_key_exists($key, $record)) {
                $whole[$key] = $record[$key];
            } else {
                unset($whole[$key]);
            }
        }

        return $whole;
    }

    /**
     * @inheritDoc
     */
    public function preservationIssue(mixed $whole): ?string
    {
        return is_array($whole) && ! array_is_list($whole) || $whole === []
            ? null
            : sprintf('returns %s, not one map of settings', is_array($whole) ? 'a list' : get_debug_type($whole));
    }

    /**
     * @inheritDoc
     */
    public function ordersRecords(): bool
    {
        return false;
    }

    /**
     * The key of a field or list path this projection would not read or
     * write, or null when it owns every one.
     *
     * @param list<string> $paths Dotted field and list paths.
     */
    public function findUnownedKey(array $paths): ?string
    {
        foreach ($paths as $path) {
            $key = explode('.', $path, 2)[0];

            if (! in_array($key, $this->keys, true)) {
                return $key;
            }
        }

        return null;
    }
}
