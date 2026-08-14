<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Building an elemental affinity list a part at a time.
 *
 * The wire form is one line -- `Fire: 0.5; Water: -1` -- compact to store and
 * unforgiving to type: the element has to be one the project defines and the
 * multiplier has to mean what the engine expects. This holds the same map as
 * rows an author moves through, the element picked and the effect cycled, so
 * the line is only ever written by the codec.
 *
 * Nothing here draws, so the behaviour can be exercised without a terminal.
 *
 * @package Ichiloto\Editor\Database
 */
final class AffinityEditor
{
    /**
     * @var array<int, array{element: string, multiplier: float}> The rows.
     */
    private array $rows = [];
    private bool $isOpen = false;
    private string $fieldId = '';
    private string $label = '';
    private int $selectedIndex = 0;
    /**
     * @var string[] The elements the project defines.
     */
    private array $elements = [];

    /**
     * Opens the editor on a field's affinities.
     *
     * @param string $fieldId The field being edited.
     * @param string $label What to call it.
     * @param array<string, mixed> $affinities The map as stored.
     * @param string[] $elements The elements the project defines.
     * @return void
     */
    public function open(string $fieldId, string $label, array $affinities, array $elements): void
    {
        $this->isOpen = true;
        $this->fieldId = $fieldId;
        $this->label = $label;
        $this->elements = array_values(array_filter(
            array_map(strval(...), $elements),
            static fn(string $element): bool => trim($element) !== ''
        ));
        $this->rows = [];

        foreach ($affinities as $element => $multiplier) {
            $this->rows[] = ['element' => strval($element), 'multiplier' => floatval($multiplier)];
        }

        $this->selectedIndex = 0;
    }

    /**
     * Closes the editor.
     *
     * @return void
     */
    public function close(): void
    {
        $this->isOpen = false;
        $this->fieldId = '';
        $this->label = '';
        $this->rows = [];
        $this->elements = [];
        $this->selectedIndex = 0;
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

    /**
     * @return string[] The elements the project defines.
     */
    public function elements(): array
    {
        return $this->elements;
    }

    /**
     * Returns the affinities as they stand.
     *
     * @return array<string, float> The map.
     */
    public function affinities(): array
    {
        $map = [];

        foreach ($this->rows as $row) {
            if (trim($row['element']) !== '') {
                $map[$row['element']] = $row['multiplier'];
            }
        }

        return $map;
    }

    /**
     * Returns the affinities in the form the data file stores.
     *
     * @return string The encoded line.
     */
    public function encoded(): string
    {
        return ElementAffinityCodec::encodeAll($this->affinities());
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function selectedIndex(): int
    {
        return min($this->selectedIndex, max(0, $this->count() - 1));
    }

    /**
     * Returns the row the cursor is on.
     *
     * @return array{element: string, multiplier: float}|null The row.
     */
    public function selected(): ?array
    {
        return $this->rows[$this->selectedIndex()] ?? null;
    }

    /**
     * Moves the cursor, wrapping at the ends.
     *
     * @param int $delta How far to move.
     * @return void
     */
    public function move(int $delta): void
    {
        if ($this->count() === 0) {
            $this->selectedIndex = 0;

            return;
        }

        $this->selectedIndex = (($this->selectedIndex() + $delta) % $this->count() + $this->count()) % $this->count();
    }

    /**
     * Adds a row below the cursor and moves onto it.
     *
     * The first project element the list does not name yet is the starting
     * point, so adding twice does not silently shadow the same element.
     *
     * @return void
     */
    public function add(): void
    {
        $named = array_map(static fn(array $row): string => mb_strtolower($row['element']), $this->rows);
        $element = $this->elements[0] ?? '';

        foreach ($this->elements as $candidate) {
            if (! in_array(mb_strtolower($candidate), $named, true)) {
                $element = $candidate;
                break;
            }
        }

        $position = $this->count() === 0 ? 0 : $this->selectedIndex() + 1;
        array_splice($this->rows, $position, 0, [[
            'element' => $element,
            'multiplier' => ElementAffinityCodec::EFFECTS['Resist'],
        ]]);
        $this->selectedIndex = $position;
    }

    /**
     * Removes the row under the cursor.
     *
     * @return void
     */
    public function remove(): void
    {
        if ($this->count() === 0) {
            return;
        }

        array_splice($this->rows, $this->selectedIndex(), 1);
        $this->selectedIndex = max(0, min($this->selectedIndex, $this->count() - 1));
    }

    /**
     * Sets what element the selected row wards.
     *
     * @param string $element The element.
     * @return void
     */
    public function setElement(string $element): void
    {
        $row = $this->selected();

        if ($row === null) {
            return;
        }

        $row['element'] = $element;
        $this->rows[$this->selectedIndex()] = $row;
    }

    /**
     * Cycles the selected row's effect through the named multipliers.
     *
     * @param int $step Which way to cycle.
     * @return void
     */
    public function cycleEffect(int $step): void
    {
        $row = $this->selected();

        if ($row === null) {
            return;
        }

        $row['multiplier'] = ElementAffinityCodec::cycle($row['multiplier'], $step);
        $this->rows[$this->selectedIndex()] = $row;
    }

    /**
     * Returns one readable row per affinity.
     *
     * @return string[] The rows.
     */
    public function describeRows(): array
    {
        return array_map(
            static fn(array $row): string => sprintf(
                '%s — %s',
                $row['element'] === '' ? '(no element)' : $row['element'],
                ElementAffinityCodec::describe($row['multiplier']),
            ),
            $this->rows,
        );
    }
}
