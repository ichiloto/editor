<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Choosing what a reference field points at.
 *
 * Holds only what choosing needs: the values on offer, what is typed into the
 * filter, and where the cursor is. Nothing here draws, so the behaviour can be
 * exercised without a terminal.
 *
 * @package Ichiloto\Editor\Database
 */
final class ReferencePicker
{
    /**
     * @var string[] Everything the reference could be set to.
     */
    private array $values = [];
    /**
     * @var string What the author has typed to narrow the list.
     */
    private string $filter = '';
    /**
     * @var int Where the cursor sits in the filtered list.
     */
    private int $selectedIndex = 0;
    private bool $isOpen = false;
    private string $category = '';
    private string $fieldId = '';
    private string $label = '';

    /**
     * Opens the picker on a field.
     *
     * @param string $fieldId The field being set.
     * @param string $label What to call it.
     * @param string $category The kind of reference.
     * @param string[] $values What it may be set to.
     * @param string $current What it is set to now, which is where the cursor starts.
     * @return bool True when there was something to choose from.
     */
    public function open(string $fieldId, string $label, string $category, array $values, string $current = ''): bool
    {
        $values = array_values(array_unique(array_filter(
            array_map(strval(...), $values),
            static fn(string $value): bool => trim($value) !== ''
        )));

        if ($values === []) {
            return false;
        }

        $this->isOpen = true;
        $this->fieldId = $fieldId;
        $this->label = $label;
        $this->category = $category;
        $this->values = $values;
        $this->filter = '';
        $this->selectedIndex = max(0, array_search($current, $values, true) ?: 0);

        return true;
    }

    /**
     * Closes the picker.
     *
     * @return void
     */
    public function close(): void
    {
        $this->isOpen = false;
        $this->values = [];
        $this->filter = '';
        $this->selectedIndex = 0;
        $this->fieldId = '';
        $this->label = '';
        $this->category = '';
    }

    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    public function fieldId(): string
    {
        return $this->fieldId;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function filter(): string
    {
        return $this->filter;
    }

    /**
     * Returns the values the filter leaves.
     *
     * @return string[] The matching values.
     */
    public function matches(): array
    {
        if ($this->filter === '') {
            return $this->values;
        }

        return array_values(array_filter(
            $this->values,
            fn(string $value): bool => mb_stripos($value, $this->filter) !== false
        ));
    }

    /**
     * Returns where the cursor sits among the matches.
     *
     * @return int The index.
     */
    public function selectedIndex(): int
    {
        return min($this->selectedIndex, max(0, count($this->matches()) - 1));
    }

    /**
     * Returns what the cursor is on.
     *
     * @return string|null The value, or null when the filter matches nothing.
     */
    public function selected(): ?string
    {
        return $this->matches()[$this->selectedIndex()] ?? null;
    }

    /**
     * Moves the cursor, wrapping at the ends.
     *
     * @param int $delta How far to move.
     * @return void
     */
    public function move(int $delta): void
    {
        $count = count($this->matches());

        if ($count === 0) {
            $this->selectedIndex = 0;

            return;
        }

        $this->selectedIndex = (($this->selectedIndex() + $delta) % $count + $count) % $count;
    }

    /**
     * Narrows the list by what is typed.
     *
     * @param string $filter The text typed so far.
     * @return void
     */
    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->selectedIndex = 0;
    }

    /**
     * Adds one typed character to the filter.
     *
     * @param string $character The character.
     * @return void
     */
    public function type(string $character): void
    {
        $this->setFilter($this->filter . $character);
    }

    /**
     * Removes the last character from the filter.
     *
     * @return void
     */
    public function backspace(): void
    {
        $this->setFilter(mb_substr($this->filter, 0, max(0, mb_strlen($this->filter) - 1)));
    }
}
