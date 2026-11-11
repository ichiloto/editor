<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Core\PlayStatistic;
use InvalidArgumentException;

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
        'map_event' => ['Map Event', 'maps'],
        'world_ability' => ['World Power', ReferenceCatalog::WORLD_ABILITIES],
        'assigned_summons' => ['Summons Assigned In', ReferenceCatalog::ASSIGNED_SUMMON_SCOPES],
        'present_actor' => ['Party Member', 'actor_ids'],
        'present_party_size' => ['Members Present In', ReferenceCatalog::PRESENT_PARTY_SCOPES],
        'play_statistic' => ['Play Statistic', ReferenceCatalog::PLAY_STATISTICS],
    ];

    /** What a type's typed value is called, for the types that compare with one. */
    private const array VALUE_LABELS = [
        'variable' => 'Value',
        'play_statistic' => 'Count',
    ];

    /**
     * What a type starts with when chosen, where the Engine requires more
     * than a name the author types: an assigned summons condition counts
     * within the first scope the Engine declares, and needs at least one; a
     * play statistic starts on the first counter the Engine declares, at
     * least one.
     *
     * @return array<string, mixed>
     */
    private static function getTypeDefaults(string $type): array
    {
        return match ($type) {
            'assigned_summons' => ['name' => \Ichiloto\Engine\Core\AssignedSummonScope::values()[0], 'quantity' => 1],
            'present_party_size' => ['name' => \Ichiloto\Engine\Core\PresentPartyScope::values()[0], 'quantity' => 1],
            'play_statistic' => ['name' => PlayStatistic::values()[0], 'op' => self::PLAY_STATISTIC_DEFAULT_OPERATION,
                'value' => self::PLAY_STATISTIC_DEFAULT_COUNT],
            default => [],
        };
    }

    /** The quest statuses a condition can require; the first is the default. */
    public const array QUEST_STATUSES = ['completed', 'active'];

    /** How a variable condition compares; the first is the default. */
    public const array VARIABLE_OPERATIONS = ['==', '!=', '>', '>=', '<', '<='];

    /** How a new play statistic condition compares, and with what: at least one. */
    private const string PLAY_STATISTIC_DEFAULT_OPERATION = '>=';
    private const int PLAY_STATISTIC_DEFAULT_COUNT = 1;

    /**
     * @var array<int, array<string, mixed>> The conditions being edited.
     */
    private array $conditions = [];

    /**
     * The condition vocabulary for an interface that builds conditions a
     * part at a time without this editor's cursor: each type, what its name
     * is called and which reference category lists names (null for a name
     * the author invents), and the extras it carries, each with its choices
     * or kind and its default. Every condition can be negated.
     *
     * @return list<array{type: string, label: string, nameCategory: ?string, negatable: bool, extras: list<array<string, mixed>>}>
     */
    public static function getGrammar(): array
    {
        $choices = static fn(array $values, array $labels): array => array_map(
            static fn(mixed $value, string $label): array => ['value' => $value, 'label' => $label], $values, $labels);
        $extras = [
            'quest' => [['key' => 'status', 'label' => 'Status', 'kind' => 'options', 'default' => self::QUEST_STATUSES[0],
                'options' => $choices(self::QUEST_STATUSES, ['is completed', 'is active'])]],
            'switch' => [['key' => 'value', 'label' => 'Is', 'kind' => 'options', 'default' => true,
                'options' => $choices([true, false], ['on', 'off'])]],
            'variable' => [
                ['key' => 'op', 'label' => 'Compare', 'kind' => 'options', 'default' => self::VARIABLE_OPERATIONS[0],
                    'options' => $choices(self::VARIABLE_OPERATIONS, self::VARIABLE_OPERATIONS)],
                ['key' => 'value', 'label' => 'Value', 'kind' => 'text', 'default' => 0],
            ],
            // A counter compares with a whole count from zero up.
            'play_statistic' => [
                ['key' => 'op', 'label' => 'Compare', 'kind' => 'options', 'default' => self::PLAY_STATISTIC_DEFAULT_OPERATION,
                    'options' => $choices(self::VARIABLE_OPERATIONS, self::VARIABLE_OPERATIONS)],
                ['key' => 'value', 'label' => self::VALUE_LABELS['play_statistic'], 'kind' => 'integer', 'default' => self::PLAY_STATISTIC_DEFAULT_COUNT],
            ],
            'item' => [['key' => 'quantity', 'label' => 'Quantity', 'kind' => 'integer', 'default' => 1]],
            'key_item' => [['key' => 'quantity', 'label' => 'Quantity', 'kind' => 'integer', 'default' => 1]],
            // Always written: the Engine requires it.
            'assigned_summons' => [['key' => 'quantity', 'label' => 'At Least', 'kind' => 'integer', 'default' => 1]],
            'present_party_size' => [['key' => 'quantity', 'label' => 'Exactly', 'kind' => 'integer', 'default' => 1]],
            'map_event' => [['key' => 'marker', 'label' => 'Event', 'kind' => 'text', 'default' => 'A',
                // Chosen from the events of the map the condition names.
                'category' => 'map_event_markers', 'mapFrom' => 'name']],
        ];

        return array_map(static fn(string $type): array => [
            'type' => $type,
            'label' => self::NAMES[$type][0] ?? ucfirst($type),
            'nameCategory' => self::NAMES[$type][1] ?? null,
            'negatable' => true,
            'extras' => $extras[$type] ?? [],
        ], ConditionCodec::getLeafTypes());
    }

    /** Whether a condition is the Engine's any-of group rather than one named condition. */
    public static function isGroup(array $condition): bool
    {
        return strval($condition['type'] ?? '') === ConditionCodec::GROUP_TYPE;
    }
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

        // A group's members are edited in its line; cycling it to one condition would drop them.
        if ($condition === null || self::isGroup($condition)) {
            return;
        }

        $types = ConditionCodec::getLeafTypes();
        $index = array_search(strval($condition['type'] ?? ''), $types, true);
        $index = is_int($index) ? $index : 0;
        $type = $types[(($index + $step) % count($types) + count($types)) % count($types)];

        $rebuilt = ['type' => $type, 'name' => strval($condition['name'] ?? ''), ...self::getTypeDefaults($type)];

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
                $statuses = self::QUEST_STATUSES;
                $index = array_search(strval($condition['status'] ?? $statuses[0]), $statuses, true);
                $condition['status'] = $statuses[(((is_int($index) ? $index : 0) + $step) % count($statuses) + count($statuses)) % count($statuses)];
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
            case 'assigned_summons':
            case 'present_party_size':
                // Required, so it stays written down to one.
                $condition['quantity'] = max(1, intval($condition['quantity'] ?? 1) + $step);
                break;
            case 'variable':
            case 'play_statistic':
                $operations = self::VARIABLE_OPERATIONS;
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

        if ($condition === null || self::isGroup($condition)) {
            return;
        }

        $condition['name'] = $name;
        $this->conditions[$this->selectedIndex()] = $condition;
    }

    /**
     * Sets which event a map event condition waits on, chosen from the events
     * of the map it names.
     *
     * @param string $marker The event's marker.
     * @return void
     */
    public function setMarker(string $marker): void
    {
        $condition = $this->selected();

        if ($condition === null || strval($condition['type'] ?? '') !== 'map_event' || trim($marker) === '') {
            return;
        }

        $condition['marker'] = trim($marker);
        $this->conditions[$this->selectedIndex()] = $condition;
    }

    /**
     * What the selected condition's typed value is called, or null when its
     * type compares with none: a variable's value, a play statistic's count.
     */
    public function getValueLabel(): ?string
    {
        return self::VALUE_LABELS[strval(($this->selected() ?? [])['type'] ?? '')] ?? null;
    }

    /**
     * Sets the value the selected condition compares with, read as the
     * condition line reads it: a variable's number or text, a play
     * statistic's whole count from zero up.
     *
     * @param string $value The value as typed.
     * @return void
     * @throws InvalidArgumentException When a play statistic's count is not a whole number from zero up; nothing changes.
     */
    public function setValue(string $value): void
    {
        $condition = $this->selected();
        $type = strval(($condition ?? [])['type'] ?? '');

        if ($condition === null || ! array_key_exists($type, self::VALUE_LABELS)) {
            return;
        }

        $value = trim($value);
        if ($type === 'play_statistic') {
            if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                throw new InvalidArgumentException(sprintf('A count is a whole number from zero up, not "%s".', $value));
            }
            $condition['value'] = (int) $value;
        } else {
            $condition['value'] = is_numeric($value) ? $value + 0 : $value;
        }
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
        if (self::isGroup($condition)) {
            $members = array_map(self::describe(...), array_values(array_filter((array) ($condition['conditions'] ?? []), is_array(...))));

            return sprintf('%sANY OF (%s)', ($condition['negate'] ?? false) === true ? 'NOT ' : '', implode('; ', $members));
        }
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
            'map_event' => sprintf('event %s is done', strval($condition['marker'] ?? '')),
            'world_ability' => 'is unlocked',
            'assigned_summons' => sprintf('are at least %d', intval($condition['quantity'] ?? 0)),
            'present_actor' => 'is present',
            'present_party_size' => sprintf('number exactly %d', intval($condition['quantity'] ?? 0)),
            'play_statistic' => sprintf('%s %s', strval($condition['op'] ?? '=='), strval($condition['value'] ?? '')),
            default => '',
        };
        if ($type === 'play_statistic') {
            // A counter reads by the Engine's own label.
            $name = PlayStatistic::tryFrom(trim($name))?->getLabel() ?? $name;
        }

        $prefix = ($condition['negate'] ?? false) === true ? 'NOT ' : '';

        return trim(sprintf('%s%s %s %s', $prefix, $label, $name, $detail));
    }
}
