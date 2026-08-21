<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

/**
 * The incremental `/` filter shared by the Assets list, the Database lists,
 * and the picker dialogs.
 *
 * The filter owns nothing but the query and whether the caret is capturing
 * keystrokes; ranking is delegated to {@see CommandPalette::filterLabels()},
 * so the editor has exactly one search behaviour.
 */
final class ListFilter
{
    /**
     * The live query. A closed filter with a non-empty query keeps filtering
     * — typing `/` then Enter narrows a list and returns navigation to the
     * arrow keys, exactly like a browser find-as-you-type.
     */
    public private(set) string $query = '';
    /**
     * Whether keystrokes are currently being captured into the query.
     */
    public private(set) bool $isCapturing = false;

    /**
     * Opens the filter caret, preserving any query already narrowing the list.
     *
     * @return void
     */
    public function open(): void
    {
        $this->isCapturing = true;
    }

    /**
     * Closes the caret but keeps the list narrowed.
     *
     * @return void
     */
    public function commit(): void
    {
        $this->isCapturing = false;
    }

    /**
     * Clears the query and closes the caret.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->query = '';
        $this->isCapturing = false;
    }

    /**
     * Appends one typed symbol to the query.
     *
     * @param string $symbol The typed symbol.
     * @return void
     */
    public function type(string $symbol): void
    {
        $this->query .= $symbol;
    }

    /**
     * Deletes the last query symbol.
     *
     * @return void
     */
    public function backspace(): void
    {
        if ($this->query === '') {
            return;
        }

        $this->query = mb_substr($this->query, 0, mb_strlen($this->query) - 1);
    }

    /**
     * Returns whether the filter is narrowing anything.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->isCapturing || $this->query !== '';
    }

    /**
     * Returns the keys of the entries matching the query, best match first.
     *
     * @param array<array-key, string> $labels The candidate labels keyed by identity.
     * @return array<int, array-key>
     */
    public function apply(array $labels): array
    {
        return CommandPalette::filterLabels($labels, $this->query);
    }

    /**
     * Returns the caret/summary suffix for a pane title.
     *
     * @return string
     */
    public function describe(): string
    {
        if (! $this->isActive()) {
            return '';
        }

        return sprintf(' /%s%s', $this->query, $this->isCapturing ? '_' : '');
    }
}
