<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

/**
 * The command palette model: a fuzzy-filtered, selectable list of runnable
 * items (maps, database categories, event markers, tools, actions).
 *
 * The palette owns query/selection state and the ranking algorithm; the
 * Editor owns which items exist and how the overlay is painted.
 */
final class CommandPalette
{
    /**
     * @var PaletteItem[] The full item list for this session.
     */
    private array $items = [];
    /**
     * The current fuzzy query.
     */
    public private(set) string $query = '';
    /**
     * The selected row inside the filtered list.
     */
    public private(set) int $selectedIndex = 0;

    /**
     * Begins a palette session over the given items.
     *
     * @param PaletteItem[] $items The runnable items.
     * @return void
     */
    public function open(array $items): void
    {
        $this->items = array_values($items);
        $this->query = '';
        $this->selectedIndex = 0;
    }

    /**
     * Ends the palette session.
     *
     * @return void
     */
    public function close(): void
    {
        $this->items = [];
        $this->query = '';
        $this->selectedIndex = 0;
    }

    /**
     * Appends one typed symbol to the query and resets the selection.
     *
     * @param string $symbol The typed symbol.
     * @return void
     */
    public function type(string $symbol): void
    {
        $this->query .= $symbol;
        $this->selectedIndex = 0;
    }

    /**
     * Deletes the last query symbol and resets the selection.
     *
     * @return void
     */
    public function backspace(): void
    {
        if ($this->query === '') {
            return;
        }

        $this->query = mb_substr($this->query, 0, mb_strlen($this->query) - 1);
        $this->selectedIndex = 0;
    }

    /**
     * Moves the selection inside the filtered list.
     *
     * @param int $step The selection step.
     * @return void
     */
    public function moveSelection(int $step): void
    {
        $count = count($this->filteredItems());

        if ($count === 0) {
            $this->selectedIndex = 0;
            return;
        }

        $this->selectedIndex = max(0, min($count - 1, $this->selectedIndex + $step));
    }

    /**
     * Returns the items matching the current query, best match first.
     *
     * @return PaletteItem[]
     */
    public function filteredItems(): array
    {
        return self::filter($this->items, $this->query);
    }

    /**
     * Returns the currently selected filtered item.
     *
     * @return PaletteItem|null
     */
    public function selectedItem(): ?PaletteItem
    {
        $filtered = $this->filteredItems();

        return $filtered[max(0, min(count($filtered) - 1, $this->selectedIndex))] ?? null;
    }

    /**
     * Fuzzy-filters items against a query.
     *
     * Matching is case-insensitive: a direct substring outranks a scattered
     * subsequence, earlier matches outrank later ones, tighter subsequences
     * outrank spread-out ones, and ties keep their original order.
     *
     * @param PaletteItem[] $items The items to filter.
     * @param string $query The fuzzy query.
     * @return PaletteItem[]
     */
    public static function filter(array $items, string $query): array
    {
        $query = mb_strtolower(trim($query));

        if ($query === '') {
            return array_values($items);
        }

        $scored = [];

        foreach (array_values($items) as $index => $item) {
            $score = self::score(mb_strtolower($item->label), $query);

            if ($score === null) {
                continue;
            }

            $scored[] = ['score' => $score, 'index' => $index, 'item' => $item];
        }

        usort(
            $scored,
            static fn(array $left, array $right): int => [$left['score'], $left['index']] <=> [$right['score'], $right['index']],
        );

        return array_map(static fn(array $entry): PaletteItem => $entry['item'], $scored);
    }

    /**
     * Scores one lowercased label against a lowercased query.
     *
     * @param string $label The candidate label.
     * @param string $query The query.
     * @return int|null The rank (lower is better), or null when unmatched.
     */
    private static function score(string $label, string $query): ?int
    {
        $substringPosition = mb_strpos($label, $query);

        if ($substringPosition !== false) {
            return $substringPosition;
        }

        // Subsequence match: every query symbol appears in order.
        $searchFrom = 0;
        $firstMatch = null;
        $lastMatch = null;
        $queryLength = mb_strlen($query);

        for ($queryIndex = 0; $queryIndex < $queryLength; $queryIndex++) {
            $position = mb_strpos($label, mb_substr($query, $queryIndex, 1), $searchFrom);

            if ($position === false) {
                return null;
            }

            $firstMatch ??= $position;
            $lastMatch = $position;
            $searchFrom = $position + 1;
        }

        $spread = ($lastMatch ?? 0) - ($firstMatch ?? 0) - max(0, $queryLength - 1);

        return 1000 + (($firstMatch ?? 0) * 10) + $spread;
    }
}
