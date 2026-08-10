<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Building a condition list a part at a time.
 *
 * The wire form is one line -- `quest:breakfast-duty:active; !switch:door` --
 * which is compact to store and unforgiving to type: the type has to be one
 * the evaluator knows, the name has to match a record, and the extras differ
 * per type. This holds the same list as rows an author moves through, so each
 * part is chosen rather than spelled and the line is only ever written by the
 * codec.
 *
 * Nothing here draws, so the behaviour can be exercised without a terminal.
 *
 * @package Ichiloto\Editor\Database
 */
final class ConditionEditor
{
    /**
     * What each condition type calls the thing it names, and which kind of
     * reference that is. A null category is a name the author invents: a
     * switch, a story event, or a variable is declared nowhere.
     */
    private const array NAMES = [
        'quest' => ['Quest', 'quests'],
        'switch' => ['Switch', null],
        'event' => ['Story Event', null],
        'variable' => ['Variable', null],
        'item' => ['Item', 'inventory'],
        'key_item' => ['Key Item', 'inventory'],
    ];

    /**
     * @var array<int, array<string, mixed>> The conditions being edited.
     */
    private array $conditions = [];
    private bool $isOpen = false;
    private string $fieldId = '';
    private string $label = '';
    private int $selectedIndex = 0;

    /**
     * Opens the editor on a field's conditions.
     *
     * @param string $fieldId The field being edited.
     * @param string $label What to call it.
     * @param array<int, mixed> $conditions The conditions as stored.
     * @return void
     */
    public function open(string $fieldId, string $label, array $conditions): void
    {
        $this->isOpen = true;
        $this->fieldId = $fieldId;
        $this->label = $label;
        $this->conditions = array_values(array_filter($conditions, is_array(...)));
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
        $this->conditions = [];
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
     * Returns the conditions as they stand.
     *
     * @return array<int, array<string, mixed>> The conditions.
     */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /**
     * Returns the conditions in the form the data file stores.
     *
     * @return string The encoded line.
     */
    public function encoded(): string
    {
        return ConditionCodec::encodeAll($this->conditions);
    }

    public function count(): int
    {
        return count($this->conditions);
    }

    public function selectedIndex(): int
    {
        return min($this->selectedIndex, max(0, $this->count() - 1));
    }

    /**
     * Returns the condition the cursor is on.
     *
     * @return array<string, mixed>|null The condition, or null when the list is empty.
     */
    public function selected(): ?array
    {
        return $this->conditions[$this->selectedIndex()] ?? null;
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
     * Adds a condition below the cursor and moves onto it.
     *
     * @return void
     */
    public function add(): void
    {
        $position = $this->count() === 0 ? 0 : $this->selectedIndex() + 1;

        array_splice($this->conditions, $position, 0, [['type' => 'switch', 'name' => '']]);
        $this->selectedIndex = $position;
    }

    /**
     * Removes the condition under the cursor.
     *
     * @return void
     */
    public function remove(): void
    {
        if ($this->count() === 0) {
            return;
        }

        array_splice($this->conditions, $this->selectedIndex(), 1);
        $this->selectedIndex = max(0, min($this->selectedIndex, $this->count() - 1));
    }

    /**
     * Flips whether the condition has to hold or has to not hold.
     *
     * @return void
     */
    public function toggleNegate(): void
    {
        $condition = $this->selected();

        if ($condition === null) {
            return;
        }

        if (($condition['negate'] ?? false) === true) {
            unset($condition['negate']);
        } else {
            $condition['negate'] = true;
        }

        $this->conditions[$this->selectedIndex()] = $condition;
    }

    /**
     * Cycles the condition's type.
     *
     * The extras belong to the type, so they are rebuilt as the type's own
     * defaults rather than carried across to a type that has no use for them.
     *
     * @param int $step Which way to cycle.
     * @return void
     */
    public function cycleType(int $step): void
    {
        $condition = $this->selected();

        if ($condition === null) {
            return;
        }

        $types = ConditionCodec::types();
        $index = array_search(strval($condition['type'] ?? ''), $types, true);
        $index = is_int($index) ? $index : 0;
        $type = $types[(($index + $step) % count($types) + count($types)) % count($types)];

        $rebuilt = ['type' => $type, 'name' => strval($condition['name'] ?? '')];

        if (($condition['negate'] ?? false) === true) {
            $rebuilt['negate'] = true;
        }

        $this->conditions[$this->selectedIndex()] = $rebuilt;
    }

    /**
     * Cycles whatever the condition's type carries beyond a name.
     *
     * @param int $step Which way to cycle.
     * @return void
     */
    public function cycleExtra(int $step): void
    {
        $condition = $this->selected();

        if ($condition === null) {
            return;
        }

        switch (strval($condition['type'] ?? '')) {
            case 'quest':
                $statuses = ['completed', 'active'];
                $index = array_search(strval($condition['status'] ?? 'completed'), $statuses, true);
                $condition['status'] = $statuses[(((is_int($index) ? $index : 0) + $step) % 2 + 2) % 2];
                break;
            case 'switch':
                $condition['value'] = ($condition['value'] ?? true) === false;

                if ($condition['value'] === true) {
                    // True is the default the wire form leaves out.
                    unset($condition['value']);
                }

                break;
            case 'item':
                $quantity = max(1, intval($condition['quantity'] ?? 1) + $step);

                if ($quantity > 1) {
                    $condition['quantity'] = $quantity;
                } else {
                    unset($condition['quantity']);
                }

                break;
            case 'variable':
                $operations = ['==', '!=', '>', '>=', '<', '<='];
                $index = array_search(strval($condition['op'] ?? '=='), $operations, true);
                $index = is_int($index) ? $index : 0;
                $condition['op'] = $operations[(($index + $step) % count($operations) + count($operations)) % count($operations)];
                break;
            default:
                return;
        }

        $this->conditions[$this->selectedIndex()] = $condition;
    }

    /**
     * Sets what the condition names.
     *
     * @param string $name The name.
     * @return void
     */
    public function setName(string $name): void
    {
        $condition = $this->selected();

        if ($condition === null) {
            return;
        }

        $condition['name'] = $name;
        $this->conditions[$this->selectedIndex()] = $condition;
    }

    /**
     * Sets a variable condition's value.
     *
     * @param string $value The value as typed.
     * @return void
     */
    public function setValue(string $value): void
    {
        $condition = $this->selected();

        if ($condition === null || strval($condition['type'] ?? '') !== 'variable') {
            return;
        }

        $condition['value'] = is_numeric($value) ? intval($value) : $value;
        $this->conditions[$this->selectedIndex()] = $condition;
    }

    /**
     * Returns what the selected condition's name refers to.
     *
     * @return array{label: string, category: string|null}|null The name's
     *   label and the kind of reference it is, or null when nothing is selected.
     */
    public function nameReference(): ?array
    {
        $condition = $this->selected();

        if ($condition === null) {
            return null;
        }

        [$label, $category] = self::NAMES[strval($condition['type'] ?? '')] ?? ['Name', null];

        return ['label' => $label, 'category' => $category];
    }

    /**
     * Returns one readable row per condition.
     *
     * @return string[] The rows.
     */
    public function rows(): array
    {
        return array_map(self::describe(...), $this->conditions);
    }

    /**
     * Describes one condition in words rather than in the wire form.
     *
     * @param array<string, mixed> $condition The condition.
     * @return string The description.
     */
    public static function describe(array $condition): string
    {
        $type = strval($condition['type'] ?? '');
        $name = strval($condition['name'] ?? '');
        $name = $name === '' ? '(unnamed)' : $name;
        [$label] = self::NAMES[$type] ?? ['Name', null];

        $detail = match ($type) {
            'quest' => sprintf('is %s', strval($condition['status'] ?? 'completed')),
            'switch' => ($condition['value'] ?? true) === false ? 'is off' : 'is on',
            'item', 'key_item' => intval($condition['quantity'] ?? 1) > 1
                ? sprintf('x%d is held', intval($condition['quantity']))
                : 'is held',
            'variable' => sprintf('%s %s', strval($condition['op'] ?? '=='), strval($condition['value'] ?? 0)),
            'event' => 'happened',
            default => '',
        };

        $prefix = ($condition['negate'] ?? false) === true ? 'NOT ' : '';

        return trim(sprintf('%s%s %s %s', $prefix, $label, $name, $detail));
    }
}
