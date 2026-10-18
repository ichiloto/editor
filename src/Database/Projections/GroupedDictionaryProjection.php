<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\OwnsFileKeys;
use Ichiloto\Editor\Database\RecordProjection;

/**
 * Several dictionaries of one kind of record, keyed by stable id, edited as one list: a dialogue catalogue's actor
 * identities and its non-actor identities. Each record reads with its key as its identity field and the group it
 * came from as its group field, and writes back under that key in its group's dictionary, so changing the group
 * moves the record. Ids are unique across the groups, as the runtime requires. The keys this category does not own
 * are written back exactly as they were read.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class GroupedDictionaryProjection implements RecordProjection, OwnsFileKeys
{
    /**
     * @param array<string, string> $groups Each file key holding a dictionary, with the group value its records carry.
     * @param string $identityKey The record field the dictionary key is read into and written from.
     * @param string $groupKey The record field naming the group a record belongs to.
     * @param array<string, mixed> $fileKeys Keys a file must hold that its records do not, such as a schema id,
     *     written when the file has none of its own; an authored value is never replaced.
     */
    public function __construct(private array $groups, private string $identityKey = 'id', private string $groupKey = 'group',
        private array $fileKeys = [])
    {
    }

    /** @inheritDoc */
    public function listOwnedKeys(): array
    {
        return array_keys($this->groups);
    }

    /** @inheritDoc */
    public function read(array $whole): array
    {
        $rows = [];
        foreach ($this->groups as $key => $group) {
            foreach (is_array($whole[$key] ?? null) ? $whole[$key] : [] as $id => $entry) {
                $rows[] = [$this->identityKey => (string) $id, $this->groupKey => $group, ...(is_array($entry) ? $entry : [])];
            }
        }

        return $rows;
    }

    /** @inheritDoc */
    public function write(array $whole, array $rows): array
    {
        $dictionaries = array_fill_keys(array_keys($this->groups), []);
        foreach ($rows as $row) {
            $key = array_search($row[$this->groupKey] ?? null, $this->groups, true);
            $key = is_string($key) ? $key : array_key_first($this->groups);
            $id = (string) ($row[$this->identityKey] ?? '');
            unset($row[$this->identityKey], $row[$this->groupKey]);
            $dictionaries[$key][$id] = $row;
        }
        $written = $whole;
        foreach ($dictionaries as $key => $dictionary) {
            if ($dictionary !== []) {
                $written[$key] = $dictionary;
            } elseif (is_array($whole[$key] ?? null) && $whole[$key] !== []) {
                // A group this write empties goes, so moving its last record away and back leaves the file as it was;
                // one the file holds empty stays as authored.
                unset($written[$key]);
            }
        }
        if ($written === $whole) {
            return $whole;
        }

        return [...$this->fileKeys, ...$written];
    }

    /** @inheritDoc */
    public function preservationIssue(mixed $whole): ?string
    {
        if (! is_array($whole)) {
            return sprintf('returns %s, not an array', get_debug_type($whole));
        }
        $seen = [];
        foreach (array_keys($this->groups) as $key) {
            if (! array_key_exists($key, $whole)) {
                continue;
            }
            $dictionary = $whole[$key];
            if (! is_array($dictionary) || ($dictionary !== [] && array_is_list($dictionary))) {
                return sprintf('field "%s" is %s, not a dictionary keyed by id', $key, is_array($dictionary) ? 'an ordered list' : get_debug_type($dictionary));
            }
            foreach ($dictionary as $id => $entry) {
                if (! is_string($id) || ! is_array($entry) || array_key_exists($this->identityKey, $entry) || array_key_exists($this->groupKey, $entry)) {
                    return sprintf('field "%s" entry "%s" is not a record the editor can preserve under its key', $key, $id);
                }
                if (isset($seen[$id])) {
                    return sprintf('names "%s" in both "%s" and "%s"', $id, $seen[$id], $key);
                }
                $seen[$id] = $key;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     *
     * Records are written grouped by their dictionary, so an order across groups is not the file's.
     */
    public function ordersRecords(): bool
    {
        return false;
    }
}
