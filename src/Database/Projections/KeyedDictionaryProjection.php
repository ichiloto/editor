<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;

/**
 * One dictionary inside a file that holds several, its records keyed by their
 * stable id.
 *
 * The field presentation catalogue keys its named resources by id beside its
 * cues and action prompt. Each record reads with its key as its identity
 * field, and writes back under that key, in record order; the keys this
 * category does not own are written back exactly as they were read.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class KeyedDictionaryProjection implements RecordProjection
{
    /**
     * @param string $key The key holding this category's dictionary.
     * @param string $identityKey The record field the dictionary key is read into and written from.
     */
    public function __construct(private string $key, private string $identityKey = 'id')
    {
    }

    /**
     * @inheritDoc
     */
    public function read(array $whole): array
    {
        $dictionary = $whole[$this->key] ?? null;
        if (! is_array($dictionary)) {
            return [];
        }

        $rows = [];
        foreach ($dictionary as $id => $entry) {
            $rows[] = [$this->identityKey => (string) $id, ...(is_array($entry) ? $entry : [])];
        }

        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function write(array $whole, array $rows): array
    {
        $dictionary = [];
        foreach ($rows as $row) {
            $id = (string) ($row[$this->identityKey] ?? '');
            unset($row[$this->identityKey]);
            $dictionary[$id] = $row;
        }
        if ($dictionary === [] && ! array_key_exists($this->key, $whole)) {
            return $whole;
        }
        $whole[$this->key] = $dictionary;

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

        $dictionary = $whole[$this->key];
        if (! is_array($dictionary) || ($dictionary !== [] && array_is_list($dictionary))) {
            return sprintf('field "%s" is %s, not a dictionary keyed by id', $this->key, is_array($dictionary) ? 'an ordered list' : get_debug_type($dictionary));
        }

        foreach ($dictionary as $id => $entry) {
            if (! is_string($id) || ! is_array($entry) || array_key_exists($this->identityKey, $entry)) {
                return sprintf('field "%s" entry "%s" is not a record the editor can preserve under its key', $this->key, $id);
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     *
     * The dictionary is written in record order, so record order is the file's.
     */
    public function ordersRecords(): bool
    {
        return true;
    }
}
