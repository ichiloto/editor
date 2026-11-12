<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Source;

/**
 * One value in an authored PHP array file, with the bytes it occupies.
 *
 * A node is an array literal, a scalar literal, a reference to a variable
 * assigned earlier in the file, or an expression the editor reads but does
 * not rewrite. Its span is exact, so a change to one node touches only the
 * bytes of that node.
 *
 * @package Ichiloto\Editor\Cutscenes\Source
 */
final readonly class SourceNode
{
    public const string ARRAY = 'array';
    public const string SCALAR = 'scalar';
    public const string VARIABLE = 'variable';
    public const string EXPRESSION = 'expression';

    /**
     * @param string $kind One of the kind constants.
     * @param int $start The offset of the node's first byte.
     * @param int $end The offset just past the node's last byte.
     * @param SourceEntry[] $entries The array's entries, in source order, for an array node.
     * @param bool $isList Whether an array node has no explicit keys at all.
     * @param bool $hasOpaqueKey Whether an array node holds an entry whose key the parser could not read, or a spread.
     * @param string|null $variable The variable name, without `$`, for a variable node.
     * @param int|null $bodyStart The offset just past `[` or `array(`, for an array node.
     * @param int|null $bodyEnd The offset of the closing `]` or `)`, for an array node.
     */
    public function __construct(
        public string $kind,
        public int $start,
        public int $end,
        public array $entries = [],
        public bool $isList = true,
        public bool $hasOpaqueKey = false,
        public ?string $variable = null,
        public ?int $bodyStart = null,
        public ?int $bodyEnd = null,
    ) {
    }

    /**
     * Returns the entry holding a key, or the entry at a list position. A
     * list with a spread has no position the file can address.
     *
     * @param int|string $key The key, or the position for a list.
     * @return SourceEntry|null The entry.
     */
    public function entryFor(int|string $key): ?SourceEntry
    {
        if ($this->isList) {
            return is_int($key) && ! $this->hasOpaqueKey ? ($this->entries[$key] ?? null) : null;
        }

        foreach ($this->entries as $entry) {
            if ($entry->key === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Returns the positions of the array's spread entries, in source order.
     *
     * @return list<int>
     */
    public function findSpreadPositions(): array
    {
        return array_keys(array_filter($this->entries, static fn(SourceEntry $entry): bool => $entry->isSpread));
    }

    /**
     * Returns whether every entry but its spreads is one the file can name:
     * a literal key in a keyed array, a plain value in a list. Such an array
     * is opaque only because of what its spreads add, so entries written
     * outside them stay the file's to edit.
     */
    public function canAddressAroundSpreads(): bool
    {
        $spreads = 0;
        $keyed = false;
        $positional = false;

        foreach ($this->entries as $entry) {
            if ($entry->isSpread) {
                $spreads++;
            } elseif ($entry->keyIsOpaque) {
                return false;
            } elseif ($entry->key === null) {
                $positional = true;
            } else {
                $keyed = true;
            }
        }

        return $spreads > 0 && ! ($keyed && $positional);
    }
}
