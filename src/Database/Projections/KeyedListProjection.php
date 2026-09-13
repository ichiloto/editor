<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;

/**
 * One list inside a file that holds several.
 *
 * A knowledge catalogue returns its subjects, its reports, its record types
 * and its enemy mappings from one file. Each list is edited as its own
 * category, and the keys a category does not own are written back exactly as
 * they were read.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class KeyedListProjection implements RecordProjection
{
    /**
     * @param string $key The key holding this category's list.
     */
    public function __construct(private string $key)
    {
    }

    /**
     * @inheritDoc
     */
    public function read(array $whole): array
    {
        $list = $whole[$this->key] ?? null;

        return is_array($list) ? array_values($list) : [];
    }

    /**
     * @inheritDoc
     */
    public function write(array $whole, array $rows): array
    {
        $whole[$this->key] = $rows;

        return $whole;
    }

    /**
     * Explains why rewriting this projection would change authored shape.
     */
    public function preservationIssue(mixed $whole): ?string
    {
        if (! is_array($whole)) {
            return sprintf('returns %s, not an array', get_debug_type($whole));
        }

        if (! array_key_exists($this->key, $whole)) {
            return null;
        }

        $list = $whole[$this->key];

        if (! is_array($list) || ! array_is_list($list)) {
            return sprintf(
                'field "%s" is %s, not an ordered list',
                $this->key,
                is_array($list) ? 'a keyed array' : get_debug_type($list),
            );
        }

        foreach ($list as $index => $entry) {
            if (! is_array($entry)) {
                return sprintf(
                    'field "%s" entry %d is %s, which the editor cannot preserve',
                    $this->key,
                    $index + 1,
                    get_debug_type($entry),
                );
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     *
     * The list is written exactly as given, so record order is the file's.
     */
    public function ordersRecords(): bool
    {
        return true;
    }
}
